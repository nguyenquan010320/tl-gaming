<?php
/**
 * Controller điều khiển phân hệ Giỏ hàng (`CartController`) — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Điều phối hiển thị trang giỏ hàng (`GET /gio-hang`), thêm sản phẩm (`POST /gio-hang/them`),
 *   cập nhật số lượng (`POST /gio-hang/cap-nhat`), xóa sản phẩm (`POST /gio-hang/xoa`),
 *   làm trống giỏ (`POST /gio-hang/xoa-tat-ca`) và mua ngay (`POST /gio-hang/mua-ngay`).
 * - Phân lập an toàn giữa Khách vãng lai (Session Cart) và Thành viên đã đăng nhập (MySQL Cart).
 * - Tất cả các route thay đổi trạng thái đều được bảo vệ bởi `CsrfMiddleware` chống tấn công CSRF.
 * - Kiểm tra chặt chẽ tồn kho khả dụng phía Server; tuyệt đối không tin tưởng giá cả, số lượng,
 *   tổng tiền hay cờ giảm giá gửi lên từ Client / JavaScript.
 * - Hỗ trợ cả 2 phương thức phản hồi: Phản hồi JSON (cho Fetch/AJAX hiện đại) và
 *   Chuyển hướng HTTP Redirect kèm thông báo Flash (cho biểu mẫu HTML tiêu chuẩn).
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Models\CartModel;
use App\Models\CategoryModel;
use App\Models\ProductModel;
use Throwable;

class CartController extends Controller
{
    private CartModel $cartModel;
    private ProductModel $productModel;

    public function __construct()
    {
        $this->cartModel    = new CartModel();
        $this->productModel = new ProductModel();
    }

    /**
     * Hiển thị trang Giỏ hàng (`GET /gio-hang`) theo thiết kế chính thức Google Stitch.
     *
     * @return void
     */
    public function index(): void
    {
        $headerMenuItems = [];

        if (Database::isConnected()) {
            try {
                $categoryModel   = new CategoryModel();
                $headerMenuItems = $categoryModel->buildHeaderMenuItems($categoryModel->getActiveCategories(includeProductCounts: false));
            } catch (Throwable $e) {
                error_log('[CartController] Lỗi nạp danh mục: ' . $e->getMessage());
            }
        }

        $userId      = Auth::check() ? (int) Auth::id() : null;
        $sessionCart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];

        // Tự động đồng bộ lại giỏ hàng tạm (nếu lần đăng nhập trước đó gặp lỗi khi hợp nhất)
        if ($userId !== null) {
            $syncResult = $this->cartModel->syncPendingGuestCartIfNeeded($userId);
            if ($syncResult['synced']) {
                if ($syncResult['merged_count'] > 0) {
                    Flash::add('info', "Đã đồng bộ lại {$syncResult['merged_count']} sản phẩm từ giỏ hàng tạm vào tài khoản của bạn.");
                }
                if ($syncResult['adjusted_count'] > 0) {
                    Flash::add('warning', "Có {$syncResult['adjusted_count']} sản phẩm được điều chỉnh số lượng theo tồn kho khả dụng.");
                }
                if ($syncResult['skipped_count'] > 0) {
                    Flash::add('warning', "Có {$syncResult['skipped_count']} sản phẩm tạm không còn khả dụng nên đã được loại bỏ.");
                }
            }
        }

        $pendingCart = ($userId !== null) ? Auth::getPendingGuestCart($userId) : [];

        try {
            $cartData = $this->cartModel->getDetailedCart($userId, $sessionCart, $pendingCart);
        } catch (Throwable $e) {
            error_log('[CartController] Lỗi đọc chi tiết giỏ hàng: ' . $e->getMessage());
            $cartData = [
                'items'            => [],
                'subtotal'         => 0,
                'discount'         => 0,
                'total'            => 0,
                'total_quantity'   => 0,
                'distinct_count'   => 0,
                'adjustments'      => [],
                'has_pending_sync' => !empty($pendingCart),
                'is_empty'         => true,
            ];
        }

        // Cảnh báo nếu giỏ tạm vẫn chưa thể đồng bộ vào MySQL lúc này
        if (!empty($cartData['has_pending_sync'])) {
            Flash::add('warning', 'Các sản phẩm tạm từ trước khi đăng nhập đang được hiển thị và sẽ tự động đồng bộ vào tài khoản khi hệ thống lưu trữ ổn định.');
        }

        // Đẩy các thông báo điều chỉnh tồn kho vào Flash Message nếu có
        if (!empty($cartData['adjustments'])) {
            foreach ($cartData['adjustments'] as $notice) {
                Flash::add('warning', $notice);
            }
        }

        // Nếu client yêu cầu JSON (AJAX)
        if ($this->isAjaxRequest()) {
            $this->json([
                'success'       => true,
                'cart'          => $cartData,
                'headerCount'   => CartModel::getCurrentTotalQuantity(),
            ]);
        }

        $this->render('cart/index', [
            'pageTitle'       => 'Giỏ hàng của bạn | TL Gaming',
            'currentRoute'    => '/gio-hang',
            'headerMenuItems' => $headerMenuItems,
            'cart'            => $cartData,
            'cartItemCount'   => $cartData['total_quantity'],
        ]);
    }

    /**
     * Thêm sản phẩm vào giỏ hàng (`POST /gio-hang/them`).
     *
     * @return void
     */
    public function add(): void
    {
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $quantity  = isset($_POST['quantity']) ? max(1, min(99, (int) $_POST['quantity'])) : 1;

        if ($productId <= 0) {
            $this->respondError('Sản phẩm không hợp lệ.', 400);
        }

        $product = $this->productModel->findPublishedById($productId);
        if ($product === null) {
            $this->respondError('Sản phẩm không tồn tại hoặc đã ngừng kinh doanh.', 404);
        }

        $maxStock = (int) ($product['purchasable_quantity'] ?? 0);
        if ($maxStock <= 0) {
            $this->respondError('Sản phẩm hiện đã hết hàng.', 400);
        }

        try {
            if (Auth::check()) {
                // Lưu vào CSDL MySQL
                $userId = (int) Auth::id();
                $result = $this->cartModel->addItem($userId, $productId, $quantity, $maxStock);
            } else {
                // Lưu vào Session của khách vãng lai
                if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }
                $currentQty = (int) ($_SESSION['cart'][$productId] ?? 0);
                if ($currentQty >= $maxStock) {
                    $this->respondError("Sản phẩm trong giỏ đã đạt mức tối đa theo tồn kho khả dụng ({$maxStock}).", 400);
                }
                $targetQty  = $currentQty + $quantity;
                $finalQty   = min($targetQty, $maxStock);
                $_SESSION['cart'][$productId] = $finalQty;

                $msg = $finalQty < $targetQty
                    ? "Đã thêm vào giỏ (đạt mức tồn kho khả dụng tối đa: {$maxStock})."
                    : "Đã thêm sản phẩm vào giỏ hàng thành công.";

                $result = ['success' => true, 'quantity' => $finalQty, 'message' => $msg];
            }

            if (!$result['success']) {
                $this->respondError($result['message'], 400);
            }

            $totalBadge = CartModel::getCurrentTotalQuantity();

            if ($this->isAjaxRequest()) {
                $this->json([
                    'success'     => true,
                    'message'     => $result['message'],
                    'cartCount'   => $totalBadge,
                    'quantity'    => $result['quantity'],
                ]);
            }

            Flash::add('success', $result['message']);
            $this->redirect('/gio-hang');
        } catch (Throwable $e) {
            error_log('[CartController] Lỗi thêm vào giỏ: ' . $e->getMessage());
            $this->respondError('Không thể thêm sản phẩm vào giỏ lúc này. Vui lòng thử lại.', 500);
        }
    }

    /**
     * Cập nhật số lượng sản phẩm trong giỏ (`POST /gio-hang/cap-nhat`).
     * Kiểm tra sản phẩm phải có trong giỏ, chặn số lượng <= 0 hoặc vượt quá tồn kho khả dụng.
     *
     * @return void
     */
    public function update(): void
    {
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $quantity  = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 0;

        if ($productId <= 0) {
            $this->respondError('Dữ liệu sản phẩm không hợp lệ.', 400);
        }

        // Chặn số lượng <= 0 theo đúng nghiệp vụ (muốn xóa phải gọi /gio-hang/xoa)
        if ($quantity <= 0) {
            $this->respondError('Số lượng sản phẩm phải lớn hơn 0. Nếu muốn bỏ sản phẩm, vui lòng bấm nút xóa.', 400);
        }

        if ($quantity > 99) {
            $this->respondError('Số lượng mỗi sản phẩm tối đa là 99.', 400);
        }

        $product = $this->productModel->findPublishedById($productId);
        if ($product === null) {
            $this->doRemoveItem($productId);
            $this->respondError('Sản phẩm không còn kinh doanh.', 404);
        }

        $maxStock = (int) ($product['purchasable_quantity'] ?? 0);
        if ($maxStock <= 0) {
            $this->respondError('Sản phẩm hiện đã hết hàng, không thể cập nhật số lượng.', 400);
        }

        try {
            if (Auth::check()) {
                $userId = (int) Auth::id();
                $result = $this->cartModel->updateQuantity($userId, $productId, $quantity, $maxStock);
                if (!$result['success']) {
                    $this->respondError($result['message'], 400);
                }
            } else {
                // Kiểm tra sản phẩm thực sự tồn tại trong giỏ Session trước khi cập nhật
                if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart']) || !array_key_exists($productId, $_SESSION['cart'])) {
                    $this->respondError('Sản phẩm không có trong giỏ hàng của bạn.', 400);
                }

                // Chặn vượt quá tồn kho khả dụng từ phía server
                if ($quantity > $maxStock) {
                    $this->respondError("Số lượng yêu cầu ({$quantity}) vượt quá tồn kho khả dụng ({$maxStock}).", 400);
                }

                $_SESSION['cart'][$productId] = $quantity;
                $result = [
                    'success'  => true,
                    'quantity' => $quantity,
                    'message'  => 'Đã cập nhật số lượng thành công.',
                ];
            }

            if ($this->isAjaxRequest()) {
                $userId      = Auth::check() ? (int) Auth::id() : null;
                $sessionCart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
                $pendingCart = $userId !== null ? Auth::getPendingGuestCart($userId) : [];
                $cartData    = $this->cartModel->getDetailedCart($userId, $sessionCart, $pendingCart);

                $this->json([
                    'success'   => true,
                    'message'   => $result['message'],
                    'quantity'  => $result['quantity'],
                    'cart'      => $cartData,
                    'cartCount' => CartModel::getCurrentTotalQuantity(),
                ]);
            }

            Flash::add('success', $result['message']);
            $this->redirect('/gio-hang');
        } catch (Throwable $e) {
            error_log('[CartController] Lỗi cập nhật giỏ hàng: ' . $e->getMessage());
            $this->respondError('Không thể cập nhật số lượng lúc này.', 500);
        }
    }

    /**
     * Xóa một sản phẩm khỏi giỏ hàng (`POST /gio-hang/xoa`).
     *
     * @return void
     */
    public function remove(): void
    {
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

        if ($productId <= 0) {
            $this->respondError('Mã sản phẩm không hợp lệ.', 400);
        }

        try {
            $this->doRemoveItem($productId);

            if ($this->isAjaxRequest()) {
                $userId      = Auth::check() ? (int) Auth::id() : null;
                $sessionCart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
                $pendingCart = $userId !== null ? Auth::getPendingGuestCart($userId) : [];
                $cartData    = $this->cartModel->getDetailedCart($userId, $sessionCart, $pendingCart);

                $this->json([
                    'success'   => true,
                    'message'   => 'Đã xóa sản phẩm khỏi giỏ hàng.',
                    'cart'      => $cartData,
                    'cartCount' => CartModel::getCurrentTotalQuantity(),
                ]);
            }

            Flash::add('success', 'Đã xóa sản phẩm khỏi giỏ hàng.');
            $this->redirect('/gio-hang');
        } catch (Throwable $e) {
            error_log('[CartController] Lỗi xóa sản phẩm: ' . $e->getMessage());
            $this->respondError('Không thể xóa sản phẩm khỏi giỏ hàng lúc này.', 500);
        }
    }

    /**
     * Xóa toàn bộ sản phẩm trong giỏ hàng (`POST /gio-hang/xoa-tat-ca`).
     *
     * @return void
     */
    public function clear(): void
    {
        try {
            if (Auth::check()) {
                $userId = (int) Auth::id();
                $this->cartModel->syncPendingGuestCartIfNeeded($userId);
                $this->cartModel->clearCart($userId);
                Auth::clearPendingGuestCart();
            } else {
                $_SESSION['cart'] = [];
            }

            if ($this->isAjaxRequest()) {
                $this->json([
                    'success'   => true,
                    'message'   => 'Đã làm trống giỏ hàng thành công.',
                    'cartCount' => 0,
                ]);
            }

            Flash::add('success', 'Đã làm trống giỏ hàng thành công.');
            $this->redirect('/gio-hang');
        } catch (Throwable $e) {
            error_log('[CartController] Lỗi làm trống giỏ: ' . $e->getMessage());
            $this->respondError('Không thể làm trống giỏ hàng lúc này.', 500);
        }
    }

    /**
     * Mua ngay (`POST /gio-hang/mua-ngay`): Xác thực sản phẩm, kiểm tra tồn kho,
     * đưa vào giỏ hàng rồi chuyển hướng thẳng sang trang `/gio-hang` nếu thành công.
     * Nếu thất bại (đã đạt tồn kho tối đa), hiển thị đúng thông báo lỗi và không báo thành công.
     * Tuyệt đối chưa tạo đơn hàng hay thanh toán ở bước này.
     *
     * @return void
     */
    public function buyNow(): void
    {
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $rawQty    = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1;

        if ($productId <= 0) {
            Flash::add('error', 'Sản phẩm chọn mua không hợp lệ.');
            $this->redirect('/san-pham');
        }

        if ($rawQty <= 0 || $rawQty > 99) {
            Flash::add('error', 'Số lượng sản phẩm phải từ 1 đến 99.');
            $this->redirect('/san-pham');
        }

        $quantity = $rawQty;

        $product = $this->productModel->findPublishedById($productId);
        if ($product === null) {
            Flash::add('error', 'Sản phẩm không tồn tại hoặc đã ngừng kinh doanh.');
            $this->redirect('/san-pham');
        }

        $maxStock = (int) ($product['purchasable_quantity'] ?? 0);
        if ($maxStock <= 0) {
            Flash::add('error', 'Sản phẩm hiện đã hết hàng.');
            $this->redirect('/san-pham/' . $product['slug']);
        }

        try {
            if (Auth::check()) {
                $userId = (int) Auth::id();
                $result = $this->cartModel->addItem($userId, $productId, $quantity, $maxStock);

                if (!$result['success']) {
                    Flash::add('error', $result['message']);
                    $this->redirect('/san-pham/' . $product['slug']);
                }

                $successMsg = $result['message'];
            } else {
                if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }

                $currentQty = (int) ($_SESSION['cart'][$productId] ?? 0);
                if ($currentQty >= $maxStock) {
                    Flash::add('error', "Sản phẩm trong giỏ đã đạt mức tối đa theo tồn kho khả dụng ({$maxStock}).");
                    $this->redirect('/san-pham/' . $product['slug']);
                }

                $targetQty = $currentQty + $quantity;
                $finalQty  = min($targetQty, $maxStock);
                $_SESSION['cart'][$productId] = $finalQty;

                $successMsg = $finalQty < $targetQty
                    ? "Đã thêm \"{$product['name']}\" vào giỏ (đạt mức tồn kho khả dụng tối đa: {$maxStock})."
                    : "Đã thêm \"{$product['name']}\" vào giỏ hàng.";
            }

            Flash::add('success', $successMsg);
            $this->redirect('/gio-hang');
        } catch (Throwable $e) {
            error_log('[CartController] Lỗi mua ngay: ' . $e->getMessage());
            Flash::add('error', 'Có lỗi xảy ra khi xử lý mua ngay. Vui lòng thử lại.');
            $this->redirect('/san-pham/' . $product['slug']);
        }
    }

    // =========================================================================
    // HÀM HỖ TRỢ NỘI BỘ (HELPER METHODS)
    // =========================================================================

    /**
     * Xóa một sản phẩm khỏi kho lưu trữ tương ứng (Session hoặc MySQL).
     */
    private function doRemoveItem(int $productId): void
    {
        if (Auth::check()) {
            $userId = (int) Auth::id();
            $this->cartModel->removeItem($userId, $productId);
            $pending = Auth::getPendingGuestCart($userId);
            if (isset($pending[$productId])) {
                unset($pending[$productId]);
                Auth::setPendingGuestCart($userId, $pending);
            }
        } else {
            unset($_SESSION['cart'][$productId]);
        }
    }

    /**
     * Kiểm tra xem HTTP Request hiện tại có phải là yêu cầu AJAX / JSON hay không.
     */
    private function isAjaxRequest(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $acceptHeader  = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');

        return $requestedWith === 'xmlhttprequest' || str_contains($acceptHeader, 'application/json');
    }

    /**
     * Trả về thông báo lỗi phù hợp với loại yêu cầu (JSON nếu là AJAX, Flash + Redirect nếu là Form thông thường).
     */
    private function respondError(string $message, int $statusCode = 400): never
    {
        if ($this->isAjaxRequest()) {
            $this->json(['success' => false, 'message' => $message], $statusCode);
        }

        Flash::add('error', $message);
        $this->redirect('/gio-hang');
    }
}
