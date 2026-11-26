<?php
/**
 * Controller điều phối quy trình Thanh toán (Checkout), Tạo đơn hàng và Tra cứu đơn — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Tiếp nhận yêu cầu mở trang thanh toán (`GET /thanh-toan`), xem trước tổng tiền/voucher (`POST /thanh-toan/xem-truoc`),
 *   xác nhận đặt hàng (`POST /thanh-toan`), xem kết quả đơn (`GET /dat-hang/thanh-cong/{order_code}`)
 *   và tra cứu đơn hàng bảo mật (`GET/POST /tra-cuu-don-hang`).
 * - Tuyệt đối không tin tưởng giá sản phẩm, phí vận chuyển, giảm giá, tổng tiền, `user_id` hay `payment_status` từ Client.
 * - Mọi route POST đều được bảo vệ bởi `CsrfMiddleware`; các thao tác tạo đơn chống double-submit qua `idempotency_key`.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\CheckoutException;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Models\CartModel;
use App\Models\CategoryModel;
use App\Models\CouponModel;
use App\Models\OrderModel;
use App\Models\UserModel;
use Throwable;

class CheckoutController extends Controller
{
    private CartModel $cartModel;
    private OrderModel $orderModel;
    private CouponModel $couponModel;

    public function __construct()
    {
        $this->cartModel   = new CartModel();
        $this->orderModel  = new OrderModel();
        $this->couponModel = new CouponModel();
    }

    /**
     * Hiển thị trang Thanh toán (`GET /thanh-toan`) theo giao diện Google Stitch.
     *
     * @return void
     */
    public function showCheckout(): void
    {
        $userId = Auth::check() ? (int) Auth::id() : null;

        // Xử lý pending_guest_cart nếu trước đó đăng nhập gặp lỗi hợp nhất
        if ($userId !== null) {
            $syncResult = $this->cartModel->syncPendingGuestCartIfNeeded($userId);
            if ($syncResult['synced'] && $syncResult['merged_count'] > 0) {
                Flash::add('info', "Đã đồng bộ lại {$syncResult['merged_count']} sản phẩm từ giỏ hàng tạm vào tài khoản.");
            }
        }

        $sessionCart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
        $pendingCart = $userId !== null ? Auth::getPendingGuestCart($userId) : [];

        try {
            $cartData = $this->cartModel->getDetailedCart($userId, $sessionCart, $pendingCart);
        } catch (Throwable $e) {
            error_log('[CheckoutController] Lỗi đọc giỏ hàng khi mở checkout: ' . $e->getMessage());
            Flash::add('error', 'Không thể tải thông tin giỏ hàng lúc này. Vui lòng thử lại.');
            $this->redirect('/gio-hang');
        }

        if (!empty($cartData['adjustments'])) {
            foreach ($cartData['adjustments'] as $notice) {
                Flash::add('warning', $notice);
            }
        }

        // Không cho phép mở Checkout nếu giỏ hàng rỗng
        if (!empty($cartData['is_empty']) || empty($cartData['items'])) {
            Flash::add('warning', 'Giỏ hàng của bạn đang trống. Vui lòng chọn sản phẩm trước khi thanh toán.');
            $this->redirect('/gio-hang');
        }

        /** @var array<string, mixed> $checkoutCfg */
        $checkoutCfg = require dirname(__DIR__, 2) . '/config/checkout.php';

        // Khởi tạo dữ liệu mặc định cho form người nhận (ưu tiên old input nếu vừa lỗi validate)
        $old = is_array($_SESSION['checkout_old'] ?? null) ? $_SESSION['checkout_old'] : [];
        unset($_SESSION['checkout_old']);

        if ($old === [] && $userId !== null) {
            try {
                $userModel = new UserModel();
                $userRow   = $userModel->findById($userId);
                if ($userRow !== null) {
                    $old = [
                        'customer_name'    => (string) ($userRow['full_name'] ?? ''),
                        'customer_phone'   => (string) ($userRow['phone'] ?? ''),
                        'customer_email'   => (string) ($userRow['email'] ?? ''),
                        'shipping_address' => (string) ($userRow['address'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
                error_log('[CheckoutController] Lỗi nạp thông tin tài khoản: ' . $e->getMessage());
            }
        }

        // Sinh idempotency key được Server cấp phát và gắn với tác nhân hiện tại
        $actorHash    = OrderModel::buildActorHash($userId);
        $currentToken = is_string($_SESSION['checkout_form_token'] ?? null) ? $_SESSION['checkout_form_token'] : '';
        $issuedMeta   = $_SESSION['checkout_issued_tokens'][$currentToken] ?? null;
        if ($currentToken === '' || !is_array($issuedMeta) || !hash_equals((string) ($issuedMeta['actor_hash'] ?? ''), $actorHash)) {
            $_SESSION['checkout_form_token'] = OrderModel::issueIdempotencyToken($userId);
        }
        $idempotencyKey = (string) $_SESSION['checkout_form_token'];

        $showrooms           = is_array($checkoutCfg['showrooms'] ?? null) ? $checkoutCfg['showrooms'] : [];
        $bankCfg             = is_array($checkoutCfg['bank_transfer'] ?? null) ? $checkoutCfg['bank_transfer'] : [];
        $bankTransferEnabled = !empty($bankCfg['enabled']);

        $deliveryMethod = (string) ($old['delivery_method'] ?? 'delivery');
        if ($deliveryMethod === 'pickup' && $showrooms === []) {
            $deliveryMethod = 'delivery';
        }
        $shippingCity   = (string) ($old['shipping_city'] ?? 'Hà Nội');
        $subtotal       = (int) ($cartData['subtotal'] ?? 0);
        $shippingFee    = CouponModel::calculateShippingFee($deliveryMethod, $shippingCity, $subtotal);

        $couponCode     = strtoupper(trim((string) ($old['coupon_code'] ?? '')));
        $discountAmount = 0;
        $couponNotice   = '';
        if ($couponCode !== '') {
            $cRes = $this->couponModel->validateCouponForCheckout(
                $couponCode,
                $subtotal,
                $userId,
                (string) ($old['customer_email'] ?? ''),
                (string) ($old['customer_phone'] ?? ''),
                false
            );
            if ($cRes['valid']) {
                $discountAmount = (int) $cRes['discount_amount'];
                $couponNotice   = $cRes['message'];
            }
        }

        $finalAmount = max(0, $subtotal + $shippingFee - $discountAmount);

        $this->render('checkout/index', [
            'pageTitle'           => 'Thanh toán đơn hàng | TL Gaming',
            'currentRoute'        => '/thanh-toan',
            'headerMenuItems'     => $this->loadHeaderMenuItems(),
            'cart'                => $cartData,
            'old'                 => $old,
            'showrooms'           => $showrooms,
            'shippingConfig'      => $checkoutCfg['shipping'] ?? [],
            'bankConfig'          => $bankCfg,
            'bankTransferEnabled' => $bankTransferEnabled,
            'idempotencyKey'      => $idempotencyKey,
            'subtotal'            => $subtotal,
            'shippingFee'         => $shippingFee,
            'discountAmount'      => $discountAmount,
            'finalAmount'         => $finalAmount,
            'couponNotice'        => $couponNotice,
        ]);
    }

    /**
     * API nội bộ tính lại Tạm tính, Giảm giá Voucher, Phí vận chuyển và Tổng cộng trên Server
     * khi người dùng đổi hình thức giao nhận hoặc thử mã voucher (`POST /thanh-toan/xem-truoc`).
     *
     * @return void
     */
    public function previewSummary(): void
    {
        $userId = Auth::check() ? (int) Auth::id() : null;
        if ($userId !== null) {
            $this->cartModel->syncPendingGuestCartIfNeeded($userId);
        }

        $sessionCart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
        $pendingCart = $userId !== null ? Auth::getPendingGuestCart($userId) : [];
        $cartData    = $this->cartModel->getDetailedCart($userId, $sessionCart, $pendingCart);

        if (!empty($cartData['is_empty'])) {
            $this->json(['success' => false, 'message' => 'Giỏ hàng hiện đang trống.'], 400);
        }

        $deliveryMethod = (string) ($_POST['delivery_method'] ?? 'delivery');
        if (!in_array($deliveryMethod, ['delivery', 'pickup'], true)) {
            $deliveryMethod = 'delivery';
        }

        $shippingCity  = trim((string) ($_POST['shipping_city'] ?? ''));
        $couponCode    = strtoupper(trim((string) ($_POST['coupon_code'] ?? '')));
        $customerEmail = mb_strtolower(trim((string) ($_POST['customer_email'] ?? '')), 'UTF-8');
        $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));

        $subtotal       = (int) ($cartData['subtotal'] ?? 0);
        $shippingFee    = CouponModel::calculateShippingFee($deliveryMethod, $shippingCity, $subtotal);
        $discountAmount = 0;
        $couponValid    = true;
        $couponMessage  = '';

        if ($couponCode !== '') {
            $cRes           = $this->couponModel->validateCouponForCheckout($couponCode, $subtotal, $userId, $customerEmail, $customerPhone, false);
            $couponValid    = $cRes['valid'];
            $couponMessage  = $cRes['message'];
            $discountAmount = $cRes['valid'] ? (int) $cRes['discount_amount'] : 0;
        }

        $finalAmount = max(0, $subtotal + $shippingFee - $discountAmount);

        $this->json([
            'success'         => true,
            'coupon_valid'    => $couponValid,
            'coupon_message'  => $couponMessage,
            'subtotal'        => $subtotal,
            'shipping_fee'    => $shippingFee,
            'discount_amount' => $discountAmount,
            'final_amount'    => $finalAmount,
        ]);
    }

    /**
     * Xác nhận đặt hàng (`POST /thanh-toan`):
     * Kiểm tra dữ liệu người nhận, kiểm tra đồng bộ giỏ, gọi Transaction tạo đơn,
     * chống double-submit và chuyển hướng sang trang đặt hàng thành công.
     *
     * @return void
     */
    public function placeOrder(): void
    {
        $userId = Auth::check() ? (int) Auth::id() : null;
        if ($userId !== null) {
            $this->cartModel->syncPendingGuestCartIfNeeded($userId);
        }

        /** @var array<string, mixed> $checkoutCfg */
        $checkoutCfg         = require dirname(__DIR__, 2) . '/config/checkout.php';
        $showrooms           = is_array($checkoutCfg['showrooms'] ?? null) ? $checkoutCfg['showrooms'] : [];
        $bankCfg             = is_array($checkoutCfg['bank_transfer'] ?? null) ? $checkoutCfg['bank_transfer'] : [];
        $bankTransferEnabled = !empty($bankCfg['enabled']);

        $customerName   = trim((string) ($_POST['customer_name'] ?? ''));
        $customerPhone  = preg_replace('/\s+/', '', trim((string) ($_POST['customer_phone'] ?? ''))) ?? '';
        $customerEmail  = mb_strtolower(trim((string) ($_POST['customer_email'] ?? '')), 'UTF-8');
        $deliveryMethod = (string) ($_POST['delivery_method'] ?? 'delivery');
        $showroomId     = (string) ($_POST['showroom_id'] ?? 'hn');
        $shippingCity   = trim((string) ($_POST['shipping_city'] ?? ''));
        $shippingDist   = trim((string) ($_POST['shipping_district'] ?? ''));
        $shippingWard   = trim((string) ($_POST['shipping_ward'] ?? ''));
        $shippingStreet = trim((string) ($_POST['shipping_address'] ?? ''));
        $customerNote   = trim((string) ($_POST['customer_note'] ?? ''));
        $paymentMethod  = (string) ($_POST['payment_method'] ?? 'cod');
        $couponCode     = strtoupper(trim((string) ($_POST['coupon_code'] ?? '')));
        $agreePolicy    = isset($_POST['agree_policy']) && (string) $_POST['agree_policy'] === '1';
        $idempotencyKey = trim((string) ($_POST['idempotency_key'] ?? ''));

        $oldInput = [
            'customer_name'     => $customerName,
            'customer_phone'    => $customerPhone,
            'customer_email'    => $customerEmail,
            'delivery_method'   => $deliveryMethod,
            'showroom_id'       => $showroomId,
            'shipping_city'     => $shippingCity,
            'shipping_district' => $shippingDist,
            'shipping_ward'     => $shippingWard,
            'shipping_address'  => $shippingStreet,
            'customer_note'     => $customerNote,
            'payment_method'    => $paymentMethod,
            'coupon_code'       => $couponCode,
        ];

        $errors = [];

        if ($customerName === '' || mb_strlen($customerName, 'UTF-8') < 2 || mb_strlen($customerName, 'UTF-8') > 100) {
            $errors[] = 'Vui lòng nhập họ tên người nhận hợp lệ (từ 2 đến 100 ký tự).';
        }
        if (!preg_match('/^(0|\+84)[0-9]{8,10}$/', $customerPhone)) {
            $errors[] = 'Số điện thoại người nhận không hợp lệ (ví dụ: 0912345678).';
        }
        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Địa chỉ email nhận thông tin đơn hàng không hợp lệ.';
        }
        if (!in_array($deliveryMethod, ['delivery', 'pickup'], true)) {
            $errors[] = 'Hình thức nhận hàng không hợp lệ.';
        } elseif ($deliveryMethod === 'pickup' && $showrooms === []) {
            $errors[] = 'Hình thức nhận tại showroom hiện chưa khả dụng. Vui lòng chọn Giao hàng tận nơi.';
        }
        if (!in_array($paymentMethod, ['cod', 'bank_transfer'], true)) {
            $errors[] = 'Phương thức thanh toán không hợp lệ.';
        } elseif ($paymentMethod === 'bank_transfer' && !$bankTransferEnabled) {
            $errors[] = 'Phương thức chuyển khoản ngân hàng hiện chưa được cấu hình. Vui lòng chọn Thanh toán khi nhận hàng (COD).';
        }
        if (!$agreePolicy) {
            $errors[] = 'Vui lòng tích chọn đồng ý với chính sách kiểm hàng và bảo hành của TL Gaming.';
        }

        $finalCity     = '';
        $finalDistrict = '';
        $finalAddress  = '';

        if ($deliveryMethod === 'pickup') {
            $chosenShowroom = $showrooms[$showroomId] ?? reset($showrooms);
            if (!is_array($chosenShowroom)) {
                $errors[] = 'Không tìm thấy thông tin showroom nhận hàng.';
            } else {
                $finalCity     = (string) ($chosenShowroom['city'] ?? 'Hà Nội');
                $finalDistrict = (string) ($chosenShowroom['district'] ?? '');
                $finalAddress  = 'Nhận tại ' . (string) ($chosenShowroom['address'] ?? 'Showroom TL Gaming');
            }
        } else {
            if ($shippingCity === '') {
                $errors[] = 'Vui lòng nhập Tỉnh / Thành phố nhận hàng.';
            }
            if ($shippingDist === '') {
                $errors[] = 'Vui lòng nhập Quận / Huyện nhận hàng.';
            }
            if ($shippingStreet === '' || mb_strlen($shippingStreet, 'UTF-8') < 4) {
                $errors[] = 'Vui lòng nhập địa chỉ nhận hàng chi tiết.';
            }
            $finalCity     = $shippingCity;
            $finalDistrict = $shippingDist;
            $finalAddress  = $shippingWard !== '' ? ($shippingStreet . ', ' . $shippingWard) : $shippingStreet;
        }

        if ($errors !== []) {
            $_SESSION['checkout_old'] = $oldInput;
            Flash::add('error', $errors[0]);
            $this->redirect('/thanh-toan');
        }

        $sessionCart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
        $pendingCart = $userId !== null ? Auth::getPendingGuestCart($userId) : [];

        $orderInput = [
            'customer_name'         => $customerName,
            'customer_email'        => $customerEmail,
            'customer_phone'        => $customerPhone,
            'delivery_method'       => $deliveryMethod,
            'shipping_city'         => $finalCity,
            'shipping_district'     => $finalDistrict,
            'shipping_address'      => $finalAddress,
            'customer_note'         => mb_substr($customerNote, 0, 500, 'UTF-8'),
            'payment_method'        => $paymentMethod,
            'coupon_code'           => $couponCode,
            'expected_subtotal'     => isset($_POST['expected_subtotal']) && $_POST['expected_subtotal'] !== '' ? (int) $_POST['expected_subtotal'] : null,
            'expected_final_amount' => isset($_POST['expected_final_amount']) && $_POST['expected_final_amount'] !== '' ? (int) $_POST['expected_final_amount'] : null,
        ];

        try {
            $result = $this->orderModel->createOrderFromCheckout(
                $orderInput,
                $userId,
                $sessionCart,
                $pendingCart,
                $idempotencyKey
            );

            unset($_SESSION['checkout_old']);
            // Sinh token mới cho lần checkout tiếp theo (bản ghi idempotency cũ đã lưu bền vững trong MySQL)
            $_SESSION['checkout_form_token'] = OrderModel::issueIdempotencyToken($userId);

            if (!empty($result['is_duplicate'])) {
                Flash::add('info', 'Yêu cầu đặt hàng đã được ghi nhận trước đó, không tạo đơn trùng.');
            } else {
                Flash::add('success', "Đặt hàng thành công! Mã đơn hàng của bạn là {$result['order_code']}.");
            }

            $this->redirect('/dat-hang/thanh-cong/' . rawurlencode($result['order_code']));
        } catch (CheckoutException $e) {
            // Lỗi nghiệp vụ rõ ràng (tồn kho, voucher, giá thay đổi, token không hợp lệ): hiển thị cho người dùng
            $_SESSION['checkout_old'] = $oldInput;
            Flash::add('error', $e->getMessage());
            $this->redirect('/thanh-toan');
        } catch (Throwable $e) {
            // Lỗi kỹ thuật / SQL / hệ thống: chỉ ghi log nội bộ an toàn, tuyệt đối không lộ chi tiết SQL/path ra giao diện
            error_log('[CheckoutController] Lỗi kỹ thuật khi tạo đơn hàng: ' . $e->getMessage());
            $_SESSION['checkout_old'] = $oldInput;
            Flash::add('error', 'Hệ thống đang gặp sự cố tạm thời khi xử lý đơn hàng. Giỏ hàng của bạn vẫn được giữ nguyên, vui lòng thử lại.');
            $this->redirect('/thanh-toan');
        }
    }

    /**
     * Hiển thị trang Đặt hàng thành công (`GET /dat-hang/thanh-cong/{order_code}`).
     * Kiểm soát quyền truy cập nghiêm ngặt (chống IDOR).
     *
     * @param string $order_code Mã đơn hàng trên URL.
     * @return void
     */
    public function orderSuccess(string $order_code): void
    {
        $order = $this->orderModel->findOrderDetailsByCode($order_code);
        if ($order === null) {
            http_response_code(404);
            $this->render('errors/404', [
                'pageTitle'       => 'Không tìm thấy đơn hàng | TL Gaming',
                'headerMenuItems' => $this->loadHeaderMenuItems(),
            ]);
            return;
        }

        $userId  = Auth::check() ? (int) Auth::id() : null;
        $isAdmin = Auth::isAdmin();

        if (!$this->orderModel->canViewOrderInCurrentSession($order, $userId, $isAdmin)) {
            http_response_code(403);
            Flash::add('error', 'Bạn không có quyền xem đơn hàng này. Vui lòng tra cứu và xác minh thông tin người nhận.');
            $this->redirect('/tra-cuu-don-hang');
        }

        /** @var array<string, mixed> $checkoutCfg */
        $checkoutCfg = require dirname(__DIR__, 2) . '/config/checkout.php';

        $this->render('checkout/success', [
            'pageTitle'       => 'Đặt hàng thành công ' . $order['order_code'] . ' | TL Gaming',
            'currentRoute'    => '/dat-hang/thanh-cong',
            'headerMenuItems' => $this->loadHeaderMenuItems(),
            'order'           => $order,
            'bankConfig'      => $checkoutCfg['bank_transfer'] ?? [],
        ]);
    }

    /**
     * Hiển thị trang Tra cứu đơn hàng (`GET /tra-cuu-don-hang`).
     *
     * @return void
     */
    public function showLookup(): void
    {
        $this->render('checkout/lookup', [
            'pageTitle'       => 'Tra cứu đơn hàng | TL Gaming',
            'currentRoute'    => '/tra-cuu-don-hang',
            'headerMenuItems' => $this->loadHeaderMenuItems(),
            'order'           => null,
            'lookupCode'      => '',
            'lookupError'     => '',
        ]);
    }

    /**
     * Xử lý Tra cứu đơn hàng (`POST /tra-cuu-don-hang`).
     * Bắt buộc khớp mã đơn VÀ số điện thoại hoặc email người nhận; không đưa SĐT/email lên URL.
     *
     * @return void
     */
    public function lookup(): void
    {
        $orderCode = strtoupper(trim((string) ($_POST['order_code'] ?? '')));
        $contact   = trim((string) ($_POST['verification_contact'] ?? ''));

        $clientIp    = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ipLimitKey  = 'checkout_lookup_ip:' . $clientIp;
        $sessLimitKey = 'checkout_lookup_sess:' . (session_id() ?: $clientIp);
        $maxAttempts = 5;
        $windowSec   = 300;

        if (
            RateLimiter::tooManyAttempts($ipLimitKey, $maxAttempts, $windowSec)
            || RateLimiter::tooManyAttempts($sessLimitKey, $maxAttempts, $windowSec)
        ) {
            $waitSec = max(
                RateLimiter::availableIn($ipLimitKey, $windowSec),
                RateLimiter::availableIn($sessLimitKey, $windowSec)
            );
            $waitMin = (int) ceil($waitSec / 60);
            http_response_code(429);
            $this->render('checkout/lookup', [
                'pageTitle'       => 'Tra cứu đơn hàng | TL Gaming',
                'currentRoute'    => '/tra-cuu-don-hang',
                'headerMenuItems' => $this->loadHeaderMenuItems(),
                'order'           => null,
                'lookupCode'      => $orderCode,
                'lookupError'     => "Bạn đã tra cứu sai quá nhiều lần. Vui lòng thử lại sau khoảng {$waitMin} phút.",
            ]);
            return;
        }

        if ($orderCode === '' || $contact === '') {
            $this->render('checkout/lookup', [
                'pageTitle'       => 'Tra cứu đơn hàng | TL Gaming',
                'currentRoute'    => '/tra-cuu-don-hang',
                'headerMenuItems' => $this->loadHeaderMenuItems(),
                'order'           => null,
                'lookupCode'      => $orderCode,
                'lookupError'     => 'Vui lòng nhập đầy đủ mã đơn hàng và số điện thoại hoặc email đặt hàng.',
            ]);
            return;
        }

        $order = $this->orderModel->lookupOrderWithVerification($orderCode, $contact);
        if ($order === null) {
            // Ghi nhận lượt tra cứu thất bại theo IP và Session (không theo order_code để chống đổi mã né giới hạn)
            RateLimiter::hit($ipLimitKey, $windowSec);
            RateLimiter::hit($sessLimitKey, $windowSec);
            // Không ghi SĐT/Email đầy đủ vào log và không tiết lộ mã đơn có tồn tại hay không
            error_log('[CheckoutController] Tra cứu đơn hàng không khớp cho IP hash: ' . hash('sha256', $clientIp));

            $this->render('checkout/lookup', [
                'pageTitle'       => 'Tra cứu đơn hàng | TL Gaming',
                'currentRoute'    => '/tra-cuu-don-hang',
                'headerMenuItems' => $this->loadHeaderMenuItems(),
                'order'           => null,
                'lookupCode'      => $orderCode,
                'lookupError'     => 'Không tìm thấy đơn hàng hoặc thông tin xác minh không chính xác.',
            ]);
            return;
        }

        RateLimiter::clear($ipLimitKey);
        RateLimiter::clear($sessLimitKey);

        // Nếu đơn thuộc về một tài khoản khác và người đang đăng nhập không phải chủ đơn / admin -> không tự cấp quyền xem URL chi tiết
        $currentUserId = Auth::check() ? (int) Auth::id() : null;
        $orderOwnerId  = $order['user_id'] !== null ? (int) $order['user_id'] : null;
        if ($orderOwnerId === null || ($currentUserId !== null && $currentUserId === $orderOwnerId) || Auth::isAdmin()) {
            if (!isset($_SESSION['verified_lookup_orders']) || !is_array($_SESSION['verified_lookup_orders'])) {
                $_SESSION['verified_lookup_orders'] = [];
            }
            $_SESSION['verified_lookup_orders'][$order['order_code']] = time();
        }

        /** @var array<string, mixed> $checkoutCfg */
        $checkoutCfg = require dirname(__DIR__, 2) . '/config/checkout.php';

        $this->render('checkout/lookup', [
            'pageTitle'       => 'Kết quả tra cứu ' . $order['order_code'] . ' | TL Gaming',
            'currentRoute'    => '/tra-cuu-don-hang',
            'headerMenuItems' => $this->loadHeaderMenuItems(),
            'order'           => $order,
            'lookupCode'      => $orderCode,
            'lookupError'     => '',
            'bankConfig'      => $checkoutCfg['bank_transfer'] ?? [],
        ]);
    }

    /**
     * Nạp danh mục cho Header dùng chung.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadHeaderMenuItems(): array
    {
        if (!Database::isConnected()) {
            return [];
        }

        try {
            $categoryModel = new CategoryModel();
            return $categoryModel->buildHeaderMenuItems($categoryModel->getActiveCategories(includeProductCounts: false));
        } catch (Throwable $e) {
            error_log('[CheckoutController] Lỗi nạp menu danh mục: ' . $e->getMessage());
            return [];
        }
    }
}
