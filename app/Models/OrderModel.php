<?php
/**
 * Model quản lý Đơn hàng (`orders`, `order_items`, `payments`) — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Thực thi quy trình tạo đơn hàng nguyên tử (Atomic Checkout) trong PDO Transaction:
 *   khóa sản phẩm/serial bằng `SELECT ... FOR UPDATE`, trừ kho hoặc giữ máy `reserved`,
 *   áp dụng voucher, tạo `orders`, `order_items`, `payments` (`pending`) và `coupon_usages`.
 * - Chống double-submit bằng khóa idempotency kết hợp `flock` và phiên làm việc.
 * - Cung cấp truy vấn xem chi tiết đơn hàng và tra cứu đơn hàng có kiểm soát quyền sở hữu (chống IDOR).
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\CheckoutException;
use App\Core\Model;
use PDO;
use RuntimeException;
use Throwable;

class OrderModel extends Model
{
    protected string $table = 'orders';

    /**
     * Tính mã băm tác nhân đặt hàng (gắn với tài khoản đăng nhập hoặc phiên khách vãng lai).
     *
     * @param int|null $userId ID tài khoản đăng nhập (null nếu khách vãng lai).
     * @return string Mã băm SHA-256.
     */
    public static function buildActorHash(?int $userId): string
    {
        $actor = ($userId !== null && $userId > 0)
            ? ('user:' . $userId)
            : ('guest:' . (session_id() ?: 'cli'));

        return hash('sha256', $actor);
    }

    /**
     * Cấp phát mã xác thực Idempotency hợp lệ trên Server và gắn với tác nhân hiện tại.
     *
     * @param int|null $userId ID tài khoản đăng nhập (null nếu khách vãng lai).
     * @return string Token 32 ký tự hex.
     */
    public static function issueIdempotencyToken(?int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        if (!isset($_SESSION['checkout_issued_tokens']) || !is_array($_SESSION['checkout_issued_tokens'])) {
            $_SESSION['checkout_issued_tokens'] = [];
        }

        // Giữ tối đa 20 token gần nhất trong phiên để tránh phình session
        if (count($_SESSION['checkout_issued_tokens']) >= 20) {
            $_SESSION['checkout_issued_tokens'] = array_slice($_SESSION['checkout_issued_tokens'], -19, null, true);
        }

        $_SESSION['checkout_issued_tokens'][$token] = [
            'actor_hash' => self::buildActorHash($userId),
            'issued_at'  => time(),
        ];

        return $token;
    }

    /**
     * Tạo đơn hàng mới từ giỏ hàng hiện tại trong một MySQL Transaction chặt chẽ.
     *
     * Các bước bảo vệ đồng thời & toàn vẹn dữ liệu:
     * 1. Khóa file idempotency bằng `flock` để ngăn 2 request POST trùng lặp chạy song song.
     * 2. Mở Transaction (`beginTransaction`) và khóa dòng giỏ hàng/sản phẩm theo thứ tự `product_id ASC`.
     * 3. Kiểm tra lại trạng thái `published`, danh mục hoạt động, giá bán hiện tại và tồn kho/serial ngay trong Transaction.
     * 4. Nếu `has_serials = 1`: khóa các bản ghi `product_units` (`status = 'available'`, không có biên bản `failed`)
     *    bằng `FOR UPDATE`, chuyển sang `reserved` bằng `UPDATE` có điều kiện và tách mỗi máy thành 1 dòng `order_items` (`quantity = 1`).
     * 5. Nếu `has_serials = 0`: trừ `products.stock_quantity` bằng `UPDATE` có điều kiện (`stock_quantity >= qty`).
     * 6. Khóa và xác thực voucher bằng `FOR UPDATE` trước khi ghi `coupon_usages`.
     * 7. Tạo `payments` luôn ở trạng thái `'pending'` (không nhận trạng thái thanh toán từ client).
     * 8. Chỉ xóa giỏ hàng SAU KHI `commit()` thành công; nếu có lỗi thì `rollBack()` toàn bộ và giữ nguyên giỏ.
     *
     * @param array<string, mixed>     $input            Dữ liệu người nhận & tùy chọn checkout đã chuẩn hóa.
     * @param int|null                 $userId           ID tài khoản đăng nhập (null nếu khách vãng lai).
     * @param array<int|string, mixed> $sessionCart      Giỏ hàng Session (cho khách vãng lai).
     * @param array<int|string, mixed> $pendingGuestCart Giỏ hàng tạm chưa đồng bộ (nếu có).
     * @param string                   $idempotencyKey   Khóa chống gửi trùng một lần.
     * @return array{order_code: string, order_id: int, final_amount: int, is_duplicate: bool}
     */
    public function createOrderFromCheckout(
        array $input,
        ?int $userId,
        array $sessionCart,
        array $pendingGuestCart,
        string $idempotencyKey
    ): array {
        $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $idempotencyKey) ?? '';
        if ($cleanKey === '' || strlen($cleanKey) < 16) {
            throw new CheckoutException('Thiếu hoặc sai định dạng mã xác thực phiên đặt hàng (Idempotency Key). Vui lòng tải lại trang và thử lại.');
        }

        $actorHash = self::buildActorHash($userId);

        // Khóa file theo phiên + idempotency key bằng flock() để chặn 2 request POST trùng lặp chạy đồng thời.
        // Nếu không lấy được khóa (lỗi ổ đĩa / quyền ghi), tuyệt đối KHÔNG đi tiếp như thể khóa thành công.
        $lockHandle = $this->acquireIdempotencyLock($cleanKey);

        try {
            $cartModel   = new CartModel($this->db);
            $couponModel = new CouponModel($this->db);

            $customerName   = trim((string) ($input['customer_name'] ?? ''));
            $customerEmail  = mb_strtolower(trim((string) ($input['customer_email'] ?? '')), 'UTF-8');
            $customerPhone  = trim((string) ($input['customer_phone'] ?? ''));
            $shippingCity   = trim((string) ($input['shipping_city'] ?? ''));
            $shippingDist   = trim((string) ($input['shipping_district'] ?? ''));
            $shippingAddr   = trim((string) ($input['shipping_address'] ?? ''));
            $customerNote   = trim((string) ($input['customer_note'] ?? ''));
            $deliveryMethod = (string) ($input['delivery_method'] ?? 'delivery');
            $paymentMethod  = (string) ($input['payment_method'] ?? 'cod');
            $couponCode     = strtoupper(trim((string) ($input['coupon_code'] ?? '')));

            $expectedSubtotal    = isset($input['expected_subtotal']) ? (int) $input['expected_subtotal'] : null;
            $expectedFinalAmount = isset($input['expected_final_amount']) ? (int) $input['expected_final_amount'] : null;

            if (!in_array($paymentMethod, ['cod', 'bank_transfer'], true)) {
                throw new CheckoutException('Phương thức thanh toán không hợp lệ.');
            }
            if (!in_array($deliveryMethod, ['delivery', 'pickup'], true)) {
                throw new CheckoutException('Hình thức nhận hàng không hợp lệ.');
            }

            // Kiểm tra khóa idempotency bền vững trong MySQL trước khi mở giao dịch mới
            $existingIdem = $this->fetchOne(
                'SELECT id, actor_hash, payload_hash, order_id, order_code, final_amount, status FROM checkout_idempotency WHERE idempotency_key = :key LIMIT 1',
                [':key' => $cleanKey]
            );

            $rawItemsPreview = $this->collectRawCartItemsSnapshot($cartModel, $userId, $sessionCart, $pendingGuestCart);
            ksort($rawItemsPreview, SORT_NUMERIC);
            $payloadMeta = [
                'customer_email'  => $customerEmail,
                'customer_phone'  => $customerPhone,
                'delivery_method' => $deliveryMethod,
                'shipping_city'   => $shippingCity,
                'shipping_addr'   => $shippingAddr,
                'payment_method'  => $paymentMethod,
                'coupon_code'     => $couponCode,
            ];

            if ($existingIdem !== null && (string) ($existingIdem['status'] ?? '') === 'completed' && !empty($existingIdem['order_code'])) {
                // Retry hợp lệ: xác minh chặt chẽ tác nhân + payload, tuyệt đối không dọn giỏ mới của người dùng
                return $this->resolveCompletedIdempotencyRecord(
                    $existingIdem,
                    $actorHash,
                    $rawItemsPreview,
                    $payloadMeta
                );
            }

            // Nếu chưa có bản ghi completed trong MySQL -> bắt buộc token phải do Server cấp phát hợp lệ trong phiên hiện tại
            $issuedTokens = $_SESSION['checkout_issued_tokens'] ?? [];
            $issuedMeta   = is_array($issuedTokens) ? ($issuedTokens[$cleanKey] ?? null) : null;
            if (!is_array($issuedMeta) || empty($issuedMeta['actor_hash']) || !hash_equals((string) $issuedMeta['actor_hash'], $actorHash)) {
                throw new CheckoutException('Mã xác thực phiên đặt hàng không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang thanh toán.');
            }

            $txResult = $this->runTransactionWithDeadlockRetry(function () use (
                $cleanKey,
                $actorHash,
                $rawItemsPreview,
                $payloadMeta,
                $cartModel,
                $couponModel,
                $userId,
                $sessionCart,
                $pendingGuestCart,
                $customerName,
                $customerEmail,
                $customerPhone,
                $shippingCity,
                $shippingDist,
                $shippingAddr,
                $deliveryMethod,
                $paymentMethod,
                $customerNote,
                $couponCode,
                $expectedSubtotal,
                $expectedFinalAmount
            ): array {
                // Khóa hoặc chèn bản ghi idempotency ngay trong Transaction (UNIQUE KEY chống trùng tuyệt đối)
                $idemStmt = $this->db->prepare(
                    'SELECT id, actor_hash, payload_hash, order_id, order_code, final_amount, status FROM checkout_idempotency WHERE idempotency_key = :key LIMIT 1 FOR UPDATE'
                );
                $idemStmt->execute([':key' => $cleanKey]);
                $lockedIdem = $idemStmt->fetch(PDO::FETCH_ASSOC);

                if (is_array($lockedIdem) && (string) ($lockedIdem['status'] ?? '') === 'completed' && !empty($lockedIdem['order_code'])) {
                    // Dùng cùng một quy tắc xác minh actor_hash và payload_hash như nhánh ngoài Transaction
                    return [
                        'duplicate_result' => $this->resolveCompletedIdempotencyRecord(
                            $lockedIdem,
                            $actorHash,
                            $rawItemsPreview,
                            $payloadMeta
                        ),
                    ];
                }

                // 1. Đọc giỏ hàng thực tế ngay bên trong Transaction
                $rawItems = $this->collectRawCartItemsInTransaction($cartModel, $userId, $sessionCart, $pendingGuestCart);
                if ($rawItems === []) {
                    throw new CheckoutException('Giỏ hàng của bạn đang trống, không thể tạo đơn hàng.');
                }

                // Sắp xếp theo product_id tăng dần để khóa dòng nhất quán, tránh deadlock chéo
                ksort($rawItems, SORT_NUMERIC);
                $txPayloadHash = $this->buildCheckoutPayloadHash($rawItems, $payloadMeta);

                if (!$lockedIdem) {
                    $insIdem = $this->db->prepare("
                        INSERT INTO checkout_idempotency (idempotency_key, actor_hash, payload_hash, status, created_at, updated_at)
                        VALUES (:key, :actor_hash, :payload_hash, 'processing', NOW(), NOW())
                    ");
                    $insIdem->execute([
                        ':key'          => $cleanKey,
                        ':actor_hash'   => $actorHash,
                        ':payload_hash' => $txPayloadHash,
                    ]);
                }

                $totalProductAmount = 0;
                $orderItemRows      = [];
                $reservedUnits      = [];

                foreach ($rawItems as $productId => $qty) {
                    if ($productId <= 0 || $qty <= 0 || $qty > 99) {
                        throw new CheckoutException('Số lượng sản phẩm trong giỏ không hợp lệ.');
                    }

                    // 2. Khóa dòng sản phẩm bằng FOR UPDATE để đọc giá, trạng thái & tồn kho mới nhất (kiểm tra đủ 3 cấp danh mục)
                    $pStmt = $this->db->prepare("
                        SELECT p.id, p.name, p.sku, p.price, p.warranty_months, p.stock_quantity,
                               p.has_serials, p.status,
                               (
                                   c.is_active = 1
                                   AND (
                                       c.parent_id IS NULL
                                       OR (
                                           c_p1.id IS NOT NULL
                                           AND c_p1.is_active = 1
                                           AND (c_p1.parent_id IS NULL OR (c_p2.id IS NOT NULL AND c_p2.is_active = 1))
                                       )
                                   )
                               ) AS category_active
                        FROM products p
                        INNER JOIN categories c ON p.category_id = c.id
                        LEFT JOIN categories c_p1 ON c.parent_id = c_p1.id
                        LEFT JOIN categories c_p2 ON c_p1.parent_id = c_p2.id
                        WHERE p.id = :id
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $pStmt->execute([':id' => $productId]);
                    $product = $pStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$product) {
                        throw new CheckoutException('Một sản phẩm trong giỏ không còn tồn tại.');
                    }

                    if ((string) $product['status'] !== 'published' || (int) $product['category_active'] !== 1) {
                        throw new CheckoutException("Sản phẩm \"{$product['name']}\" hiện đã ngừng kinh doanh hoặc đang tạm ẩn.");
                    }

                    $unitPrice      = (int) $product['price'];
                    $warrantyMonths = (int) ($product['warranty_months'] ?? 12);
                    $warrantyText   = $warrantyMonths > 0 ? "Bảo hành {$warrantyMonths} tháng" : 'Theo tiêu chuẩn hãng';
                    $hasSerials     = ((int) ($product['has_serials'] ?? 1)) === 1;

                    if ($hasSerials) {
                        // 3a. Sản phẩm quản lý theo Serial: khóa đúng N máy đủ tiêu chuẩn bán (status = 'available',
                        // có biên bản 18 bước mới nhất đạt 'passed', không cho phép máy chưa kiểm định / conditional_pass / failed).
                        if (!class_exists(ProductUnitModel::class)) {
                            require_once __DIR__ . '/ProductUnitModel.php';
                        }
                        $sellableWhere = ProductUnitModel::sellableUnitWhereSql('pu');
                        $limitSql      = (int) $qty;
                        $uStmt         = $this->db->prepare("
                            SELECT pu.id, pu.serial_number
                            FROM product_units pu
                            WHERE pu.product_id = :product_id
                              AND {$sellableWhere}
                            ORDER BY pu.id ASC
                            LIMIT {$limitSql}
                            FOR UPDATE
                        ");
                        $uStmt->execute([':product_id' => $productId]);
                        $units = $uStmt->fetchAll(PDO::FETCH_ASSOC);

                        if (count($units) < $qty) {
                            $avail = count($units);
                            throw new CheckoutException("Sản phẩm \"{$product['name']}\" chỉ còn {$avail} máy sẵn sàng bán (yêu cầu {$qty}).");
                        }

                        foreach ($units as $unit) {
                            $unitId = (int) $unit['id'];
                            $updUnit = $this->db->prepare("
                                UPDATE product_units
                                SET status = 'reserved', row_version = row_version + 1, updated_at = NOW()
                                WHERE id = :unit_id AND status = 'available'
                            ");
                            $updUnit->execute([':unit_id' => $unitId]);

                            if ($updUnit->rowCount() !== 1) {
                                throw new CheckoutException("Không thể giữ máy Serial {$unit['serial_number']} do vừa có khách hàng khác đặt trước.");
                            }

                            $reservedUnits[] = $unitId;
                            $orderItemRows[] = [
                                'product_id'         => $productId,
                                'product_unit_id'    => $unitId,
                                'product_name'       => (string) $product['name'],
                                'product_sku'        => (string) $product['sku'],
                                'unit_serial_number' => (string) $unit['serial_number'],
                                'price'              => $unitPrice,
                                'quantity'           => 1,
                                'subtotal'           => $unitPrice,
                                'warranty_period'    => $warrantyText,
                            ];
                        }

                        // Đồng bộ cache stock_quantity theo đúng quy tắc máy đủ tiêu chuẩn bán
                        ProductUnitModel::syncProductStockCache($this->db, $productId);
                    } else {
                        // 3b. Sản phẩm quản lý theo số lượng: trừ kho có điều kiện, kiểm tra không âm
                        $currentStock = (int) $product['stock_quantity'];
                        if ($currentStock < $qty) {
                            throw new CheckoutException("Sản phẩm \"{$product['name']}\" chỉ còn {$currentStock} sản phẩm trong kho.");
                        }

                        $updProd = $this->db->prepare("
                            UPDATE products
                            SET stock_quantity = stock_quantity - :qty, updated_at = NOW()
                            WHERE id = :id AND status = 'published' AND stock_quantity >= :qty_check
                        ");
                        $updProd->execute([
                            ':qty'       => $qty,
                            ':id'        => $productId,
                            ':qty_check' => $qty,
                        ]);

                        if ($updProd->rowCount() !== 1) {
                            throw new CheckoutException("Tồn kho sản phẩm \"{$product['name']}\" vừa thay đổi, vui lòng kiểm tra lại.");
                        }

                        $orderItemRows[] = [
                            'product_id'         => $productId,
                            'product_unit_id'    => null,
                            'product_name'       => (string) $product['name'],
                            'product_sku'        => (string) $product['sku'],
                            'unit_serial_number' => null,
                            'price'              => $unitPrice,
                            'quantity'           => $qty,
                            'subtotal'           => $unitPrice * $qty,
                            'warranty_period'    => $warrantyText,
                        ];
                    }

                    $totalProductAmount += ($unitPrice * $qty);
                }

                // 4. Tính phí vận chuyển & xác thực voucher trong Transaction (khóa FOR UPDATE)
                $shippingFee    = CouponModel::calculateShippingFee($deliveryMethod, $shippingCity, $totalProductAmount);
                $discountAmount = 0;
                $couponId       = null;

                if ($couponCode !== '') {
                    $couponCheck = $couponModel->validateCouponForCheckout(
                        $couponCode,
                        $totalProductAmount,
                        $userId,
                        $customerEmail,
                        $customerPhone,
                        true
                    );

                    if (!$couponCheck['valid'] || $couponCheck['coupon'] === null) {
                        throw new CheckoutException($couponCheck['message']);
                    }

                    $couponId       = (int) $couponCheck['coupon']['id'];
                    $discountAmount = (int) $couponCheck['discount_amount'];
                }

                $discountAmount = max(0, min($discountAmount, $totalProductAmount));
                $finalAmount    = $totalProductAmount + $shippingFee - $discountAmount;

                if ($finalAmount < 0) {
                    throw new CheckoutException('Tổng tiền thanh toán không hợp lệ.');
                }

                // 5. Nếu giá hoặc tổng tiền thay đổi giữa lúc mở Checkout và lúc bấm Xác nhận -> báo khách xem lại
                if (
                    ($expectedSubtotal !== null && $expectedSubtotal !== $totalProductAmount)
                    || ($expectedFinalAmount !== null && $expectedFinalAmount !== $finalAmount)
                ) {
                    throw new CheckoutException(
                        'Giá sản phẩm hoặc tổng tiền thanh toán vừa thay đổi theo dữ liệu mới nhất. Vui lòng kiểm tra lại đơn hàng trước khi xác nhận.'
                    );
                }

                // 6. Tạo bản ghi `orders` với mã đơn hàng duy nhất
                $orderCode = $this->generateUniqueOrderCode();
                $ordStmt   = $this->db->prepare("
                    INSERT INTO orders (
                        order_code, user_id, customer_name, customer_email, customer_phone,
                        shipping_address, shipping_city, shipping_district, delivery_method, customer_note,
                        total_product_amount, shipping_fee, discount_amount, final_amount,
                        coupon_id, status, created_at, updated_at
                    ) VALUES (
                        :order_code, :user_id, :customer_name, :customer_email, :customer_phone,
                        :shipping_address, :shipping_city, :shipping_district, :delivery_method, :customer_note,
                        :total_product_amount, :shipping_fee, :discount_amount, :final_amount,
                        :coupon_id, 'pending', NOW(), NOW()
                    )
                ");
                $ordStmt->execute([
                    ':order_code'           => $orderCode,
                    ':user_id'              => $userId,
                    ':customer_name'        => $customerName,
                    ':customer_email'       => $customerEmail,
                    ':customer_phone'       => $customerPhone,
                    ':shipping_address'     => $shippingAddr,
                    ':shipping_city'        => $shippingCity,
                    ':shipping_district'    => $shippingDist !== '' ? $shippingDist : null,
                    ':delivery_method'      => $deliveryMethod,
                    ':customer_note'        => $customerNote !== '' ? $customerNote : null,
                    ':total_product_amount' => $totalProductAmount,
                    ':shipping_fee'         => $shippingFee,
                    ':discount_amount'      => $discountAmount,
                    ':final_amount'         => $finalAmount,
                    ':coupon_id'            => $couponId,
                ]);
                $orderId = (int) $this->db->lastInsertId();

                // 7. Tạo các dòng chi tiết `order_items` (lưu snapshot tại thời điểm mua)
                $itemStmt = $this->db->prepare("
                    INSERT INTO order_items (
                        order_id, product_id, product_unit_id, product_name, product_sku,
                        unit_serial_number, price, quantity, subtotal, warranty_period, created_at
                    ) VALUES (
                        :order_id, :product_id, :product_unit_id, :product_name, :product_sku,
                        :unit_serial_number, :price, :quantity, :subtotal, :warranty_period, NOW()
                    )
                ");
                foreach ($orderItemRows as $row) {
                    $itemStmt->execute([
                        ':order_id'           => $orderId,
                        ':product_id'         => $row['product_id'],
                        ':product_unit_id'    => $row['product_unit_id'],
                        ':product_name'       => $row['product_name'],
                        ':product_sku'        => $row['product_sku'],
                        ':unit_serial_number' => $row['unit_serial_number'],
                        ':price'              => $row['price'],
                        ':quantity'           => $row['quantity'],
                        ':subtotal'           => $row['subtotal'],
                        ':warranty_period'    => $row['warranty_period'],
                    ]);
                }

                // 8. Ghi nhận lượt dùng voucher trong cùng Transaction (nếu có)
                if ($couponId !== null) {
                    $couponModel->recordUsage($couponId, $orderId, $userId, $customerEmail, $customerPhone, $discountAmount);
                }

                // 9. Tạo bản ghi `payments` luôn ở trạng thái `pending` (hỗ trợ cả đơn 0đ, tuyệt đối không đánh dấu đã trả tiền nếu chưa có giao dịch thực tế)
                /** @var array<string, mixed> $checkoutCfg */
                $checkoutCfg = require dirname(__DIR__, 2) . '/config/checkout.php';
                $bankCfg     = $checkoutCfg['bank_transfer'] ?? [];
                $bankName    = $paymentMethod === 'bank_transfer' && !empty($bankCfg['bank_name']) ? (string) $bankCfg['bank_name'] : null;
                $acctNum     = $paymentMethod === 'bank_transfer' && !empty($bankCfg['account_number']) ? (string) $bankCfg['account_number'] : null;
                $xferNote    = $paymentMethod === 'bank_transfer' ? "Thanh toan don hang {$orderCode}" : null;

                $payAmount = $finalAmount; // Cho phép 0đ theo ràng buộc CHECK (amount >= 0) sau migration
                $payStmt   = $this->db->prepare("
                    INSERT INTO payments (
                        order_id, payment_method, amount, status, bank_name, account_number, transfer_note, created_at, updated_at
                    ) VALUES (
                        :order_id, :payment_method, :amount, 'pending', :bank_name, :account_number, :transfer_note, NOW(), NOW()
                    )
                ");
                $payStmt->execute([
                    ':order_id'       => $orderId,
                    ':payment_method' => $paymentMethod,
                    ':amount'         => $payAmount,
                    ':bank_name'      => $bankName,
                    ':account_number' => $acctNum,
                    ':transfer_note'  => $xferNote,
                ]);

                // 10. Cập nhật khóa idempotency thành `completed` ngay trong cùng Transaction
                $updIdem = $this->db->prepare("
                    UPDATE checkout_idempotency
                    SET order_id = :order_id,
                        order_code = :order_code,
                        final_amount = :final_amount,
                        status = 'completed',
                        updated_at = NOW()
                    WHERE idempotency_key = :key
                ");
                $updIdem->execute([
                    ':order_id'     => $orderId,
                    ':order_code'   => $orderCode,
                    ':final_amount' => $finalAmount,
                    ':key'          => $cleanKey,
                ]);

                // 11. Trả kết quả để runTransactionWithDeadlockRetry commit an toàn (tự retry nếu deadlock 40001/1213/1205)
                return [
                    'order_code'   => $orderCode,
                    'order_id'     => $orderId,
                    'final_amount' => $finalAmount,
                    'raw_items'    => $rawItems,
                ];
            });

            if (isset($txResult['duplicate_result'])) {
                return $txResult['duplicate_result'];
            }

            $orderCode   = (string) $txResult['order_code'];
            $orderId     = (int) $txResult['order_id'];
            $finalAmount = (int) $txResult['final_amount'];
            $rawItems    = (array) $txResult['raw_items'];

            // 12. CHỈ DỌN ĐÚNG SỐ LƯỢNG ĐÃ ĐẶT SAU KHI COMMIT THÀNH CÔNG (bảo toàn sản phẩm/số lượng mới thêm)
            $this->cleanupCartAfterCommit($cartModel, $userId, $rawItems);
            $this->rememberRecentOrderInSession($orderCode);

            return [
                'order_code'   => $orderCode,
                'order_id'     => $orderId,
                'final_amount' => $finalAmount,
                'is_duplicate' => false,
            ];
        } finally {
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    /**
     * Lấy chi tiết đơn hàng đầy đủ theo mã đơn `order_code` (kèm items, payment và coupon code).
     *
     * @param string $orderCode Mã đơn hàng.
     * @return array<string, mixed>|null
     */
    public function findOrderDetailsByCode(string $orderCode): ?array
    {
        $code = strtoupper(trim($orderCode));
        if ($code === '') {
            return null;
        }

        $order = $this->fetchOne("
            SELECT o.*, c.code AS coupon_code
            FROM orders o
            LEFT JOIN coupons c ON o.coupon_id = c.id
            WHERE o.order_code = :order_code
            LIMIT 1
        ", [':order_code' => $code]);

        if ($order === null) {
            return null;
        }

        $orderId = (int) $order['id'];

        $items = $this->fetchAll("
            SELECT oi.*, p.slug AS product_slug, p.thumbnail AS product_thumbnail
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = :order_id
            ORDER BY oi.id ASC
        ", [':order_id' => $orderId]);

        $payment = $this->fetchOne("
            SELECT *
            FROM payments
            WHERE order_id = :order_id
            ORDER BY id DESC
            LIMIT 1
        ", [':order_id' => $orderId]);

        $order['items']   = $items;
        $order['payment'] = $payment;

        return $order;
    }

    /**
     * Kiểm tra quyền xem trang kết quả đơn hàng (Chống IDOR).
     *
     * @param array<string, mixed> $order   Bản ghi đơn hàng.
     * @param int|null             $userId  ID người dùng hiện tại (null nếu guest).
     * @param bool                 $isAdmin True nếu là Admin.
     * @return bool
     */
    public function canViewOrderInCurrentSession(array $order, ?int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        $orderCode   = (string) ($order['order_code'] ?? '');
        $orderUserId = $order['user_id'] !== null ? (int) $order['user_id'] : null;

        // Tài khoản đã đăng nhập: bắt buộc trùng user_id sở hữu đơn
        if ($userId !== null && $userId > 0) {
            if ($orderUserId === $userId) {
                return true;
            }
            return false;
        }

        // Khách vãng lai: chỉ xem được đơn vừa đặt trong phiên hiện tại hoặc đã xác minh qua tra cứu
        $recentOrders   = $_SESSION['recent_orders'] ?? [];
        $verifiedLookup = $_SESSION['verified_lookup_orders'] ?? [];

        return ($orderUserId === null && isset($recentOrders[$orderCode]))
            || isset($verifiedLookup[$orderCode]);
    }

    /**
     * Tra cứu đơn hàng an toàn qua `POST /tra-cuu-don-hang`:
     * Yêu cầu khớp chính xác mã đơn VÀ (số điện thoại HOẶC email người nhận).
     *
     * @param string $orderCode Mã đơn hàng.
     * @param string $contact   Số điện thoại hoặc Email xác minh.
     * @return array<string, mixed>|null
     */
    public function lookupOrderWithVerification(string $orderCode, string $contact): ?array
    {
        $cleanCode    = strtoupper(trim($orderCode));
        $cleanContact = trim($contact);

        if ($cleanCode === '' || $cleanContact === '') {
            return null;
        }

        $order = $this->findOrderDetailsByCode($cleanCode);
        if ($order === null) {
            return null;
        }

        $normInputPhone = preg_replace('/\s+/', '', $cleanContact) ?? '';
        $normOrderPhone = preg_replace('/\s+/', '', (string) ($order['customer_phone'] ?? '')) ?? '';
        $normInputEmail = mb_strtolower($cleanContact, 'UTF-8');
        $normOrderEmail = mb_strtolower(trim((string) ($order['customer_email'] ?? '')), 'UTF-8');

        $phoneMatch = ($normInputPhone !== '' && hash_equals($normOrderPhone, $normInputPhone));
        $emailMatch = ($normInputEmail !== '' && hash_equals($normOrderEmail, $normInputEmail));

        if (!$phoneMatch && !$emailMatch) {
            return null;
        }

        return $order;
    }

    /**
     * Thu thập các mặt hàng thực tế từ giỏ hàng ngay trong Transaction.
     *
     * @return array<int, int> Mảng [product_id => quantity].
     */
    private function collectRawCartItemsInTransaction(
        CartModel $cartModel,
        ?int $userId,
        array $sessionCart,
        array $pendingGuestCart
    ): array {
        $rawItems = [];

        if ($userId !== null && $userId > 0) {
            $cartId = $cartModel->getOrCreateCartId($userId);
            // Khóa dòng giỏ hàng cha trong Transaction
            $this->fetchOne('SELECT id FROM carts WHERE id = :id FOR UPDATE', [':id' => $cartId]);

            $dbRows = $this->fetchAll(
                'SELECT product_id, quantity FROM cart_items WHERE cart_id = :cart_id ORDER BY product_id ASC FOR UPDATE',
                [':cart_id' => $cartId]
            );
            foreach ($dbRows as $row) {
                $pId = (int) $row['product_id'];
                $qty = (int) $row['quantity'];
                if ($pId > 0 && $qty > 0) {
                    $rawItems[$pId] = $qty;
                }
            }

            // Gộp cả giỏ tạm chưa đồng bộ nếu có (tuyệt đối không âm thầm bỏ qua sản phẩm)
            foreach ($pendingGuestCart as $k => $v) {
                $pId = is_array($v) && isset($v['product_id']) ? (int) $v['product_id'] : (int) $k;
                $qty = is_array($v) && isset($v['quantity']) ? (int) $v['quantity'] : (int) $v;
                if ($pId > 0 && $qty > 0) {
                    $rawItems[$pId] = ($rawItems[$pId] ?? 0) + $qty;
                }
            }
        } else {
            foreach ($sessionCart as $k => $v) {
                $pId = is_array($v) && isset($v['product_id']) ? (int) $v['product_id'] : (int) $k;
                $qty = is_array($v) && isset($v['quantity']) ? (int) $v['quantity'] : (int) $v;
                if ($pId > 0 && $qty > 0) {
                    $rawItems[$pId] = ($rawItems[$pId] ?? 0) + $qty;
                }
            }
        }

        return $rawItems;
    }

    /**
     * Sinh mã đơn hàng duy nhất theo định dạng `TLG-YYYYMMDD-XXXXXX`.
     */
    private function generateUniqueOrderCode(): string
    {
        $prefix = 'TLG-' . date('Ymd') . '-';
        for ($i = 0; $i < 8; $i++) {
            $suffix = strtoupper(bin2hex(random_bytes(3)));
            $code   = $prefix . $suffix;
            $exists = $this->fetchColumn('SELECT id FROM orders WHERE order_code = :code LIMIT 1', [':code' => $code]);
            if ($exists === false || $exists === null) {
                return $code;
            }
        }

        return $prefix . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Đọc nhanh danh sách sản phẩm hiện tại trong giỏ (không khóa dòng) để tính payload hash idempotency.
     *
     * @return array<int, int>
     */
    private function collectRawCartItemsSnapshot(
        CartModel $cartModel,
        ?int $userId,
        array $sessionCart,
        array $pendingGuestCart
    ): array {
        $rawItems = [];

        if ($userId !== null && $userId > 0) {
            $cartId = $cartModel->getOrCreateCartId($userId);
            $dbRows = $this->fetchAll(
                'SELECT product_id, quantity FROM cart_items WHERE cart_id = :cart_id ORDER BY product_id ASC',
                [':cart_id' => $cartId]
            );
            foreach ($dbRows as $row) {
                $pId = (int) $row['product_id'];
                $qty = (int) $row['quantity'];
                if ($pId > 0 && $qty > 0) {
                    $rawItems[$pId] = $qty;
                }
            }
            foreach ($pendingGuestCart as $k => $v) {
                $pId = is_array($v) && isset($v['product_id']) ? (int) $v['product_id'] : (int) $k;
                $qty = is_array($v) && isset($v['quantity']) ? (int) $v['quantity'] : (int) $v;
                if ($pId > 0 && $qty > 0) {
                    $rawItems[$pId] = ($rawItems[$pId] ?? 0) + $qty;
                }
            }
        } else {
            foreach ($sessionCart as $k => $v) {
                $pId = is_array($v) && isset($v['product_id']) ? (int) $v['product_id'] : (int) $k;
                $qty = is_array($v) && isset($v['quantity']) ? (int) $v['quantity'] : (int) $v;
                if ($pId > 0 && $qty > 0) {
                    $rawItems[$pId] = ($rawItems[$pId] ?? 0) + $qty;
                }
            }
        }

        return $rawItems;
    }

    /**
     * Tạo mã băm SHA-256 chuẩn hóa từ giỏ hàng và thông tin đặt đơn.
     *
     * @param array<int, int>      $items
     * @param array<string, mixed> $meta
     */
    private function buildCheckoutPayloadHash(array $items, array $meta): string
    {
        ksort($items, SORT_NUMERIC);
        ksort($meta);
        return hash('sha256', (string) json_encode([
            'items' => $items,
            'meta'  => $meta,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Đối chiếu thông tin form gửi lại với đơn hàng đã commit (khi giỏ đã được dọn sạch).
     */
    private function doesFormMatchExistingOrder(
        int $orderId,
        string $customerEmail,
        string $customerPhone,
        string $paymentMethod,
        string $couponCode,
        string $shippingAddr
    ): bool {
        if ($orderId <= 0) {
            return false;
        }

        $row = $this->fetchOne("
            SELECT o.customer_email, o.customer_phone, o.shipping_address,
                   c.code AS coupon_code, p.payment_method
            FROM orders o
            LEFT JOIN coupons c ON o.coupon_id = c.id
            LEFT JOIN payments p ON p.order_id = o.id
            WHERE o.id = :id
            ORDER BY p.id DESC
            LIMIT 1
        ", [':id' => $orderId]);

        if ($row === null) {
            return false;
        }

        return mb_strtolower(trim((string) $row['customer_email']), 'UTF-8') === $customerEmail
            && trim((string) $row['customer_phone']) === $customerPhone
            && trim((string) $row['shipping_address']) === $shippingAddr
            && (string) ($row['payment_method'] ?? '') === $paymentMethod
            && strtoupper(trim((string) ($row['coupon_code'] ?? ''))) === $couponCode;
    }

    /**
     * Xác minh bản ghi idempotency đã `completed` theo đúng tác nhân (`actor_hash`) và nội dung đơn (`payload_hash`).
     * Dùng thống nhất cho cả nhánh kiểm tra ngoài Transaction và nhánh sau `SELECT ... FOR UPDATE` trong Transaction.
     * Tuyệt đối không dọn giỏ hàng ở nhánh retry để tránh mất sản phẩm mới thêm sau khi đơn cũ đã commit.
     *
     * @param array<string, mixed> $record           Bản ghi `checkout_idempotency` từ MySQL.
     * @param string               $actorHash        Mã băm tác nhân hiện tại.
     * @param array<int, int>      $rawItemsSnapshot Ảnh chụp giỏ hàng hiện tại `[product_id => quantity]`.
     * @param array<string, mixed> $payloadMeta      Thông tin người nhận, giao nhận, thanh toán và voucher.
     * @return array{order_code: string, order_id: int, final_amount: int, is_duplicate: bool}
     */
    private function resolveCompletedIdempotencyRecord(
        array $record,
        string $actorHash,
        array $rawItemsSnapshot,
        array $payloadMeta
    ): array {
        if (!hash_equals((string) ($record['actor_hash'] ?? ''), $actorHash)) {
            throw new CheckoutException('Mã xác thực đơn hàng không thuộc phiên hoặc tài khoản hiện tại.');
        }

        $payloadHash         = $this->buildCheckoutPayloadHash($rawItemsSnapshot, $payloadMeta);
        $storedPayloadHash   = (string) ($record['payload_hash'] ?? '');
        $matchesFullPayload  = hash_equals($storedPayloadHash, $payloadHash);
        $matchesAfterCleanup = ($rawItemsSnapshot === []) && $this->doesFormMatchExistingOrder(
            (int) ($record['order_id'] ?? 0),
            (string) ($payloadMeta['customer_email'] ?? ''),
            (string) ($payloadMeta['customer_phone'] ?? ''),
            (string) ($payloadMeta['payment_method'] ?? ''),
            (string) ($payloadMeta['coupon_code'] ?? ''),
            (string) ($payloadMeta['shipping_addr'] ?? '')
        );

        if (!$matchesFullPayload && !$matchesAfterCleanup) {
            throw new CheckoutException('Mã xác thực đơn hàng đã được sử dụng cho một giỏ hàng hoặc thông tin đặt hàng khác. Vui lòng tải lại trang thanh toán.');
        }

        $orderCode = (string) $record['order_code'];
        $this->rememberRecentOrderInSession($orderCode);

        return [
            'order_code'   => $orderCode,
            'order_id'     => (int) ($record['order_id'] ?? 0),
            'final_amount' => (int) ($record['final_amount'] ?? 0),
            'is_duplicate' => true,
        ];
    }

    /**
     * Dọn giỏ hàng an toàn SAU KHI Transaction đặt hàng đã commit thành công:
     * - Chỉ trừ đúng số lượng từng `product_id` thực sự nằm trong đơn vừa commit (`$committedItems`).
     * - Không xóa sản phẩm mới thêm hoặc phần số lượng tăng thêm đồng thời ở phiên/tab khác.
     * - Hỗ trợ cả giỏ Session (khách vãng lai) và giỏ MySQL + `pending_guest_cart` (tài khoản đăng nhập).
     *
     * @param CartModel       $cartModel      Model giỏ hàng.
     * @param int|null        $userId         ID tài khoản đăng nhập (null nếu khách vãng lai).
     * @param array<int, int> $committedItems Danh sách `[product_id => quantity]` đã commit vào đơn.
     * @return void
     */
    private function cleanupCartAfterCommit(CartModel $cartModel, ?int $userId, array $committedItems): void
    {
        if ($committedItems === []) {
            return;
        }

        if ($userId !== null && $userId > 0) {
            // 1. Trừ phần số lượng tương ứng trong pending_guest_cart của đúng user_id (nếu có)
            $remainingForDb = [];
            $pendingItems   = Auth::getPendingGuestCart($userId);

            foreach ($committedItems as $productId => $committedQty) {
                $qtyToDeduct = (int) $committedQty;
                if ($productId <= 0 || $qtyToDeduct <= 0) {
                    continue;
                }

                if ($pendingItems !== [] && isset($pendingItems[$productId])) {
                    $pendingQty = is_array($pendingItems[$productId])
                        ? (int) ($pendingItems[$productId]['quantity'] ?? 0)
                        : (int) $pendingItems[$productId];

                    if ($pendingQty > 0) {
                        $usedFromPending = min($pendingQty, $qtyToDeduct);
                        $leftPending     = $pendingQty - $usedFromPending;
                        $qtyToDeduct    -= $usedFromPending;

                        if ($leftPending > 0) {
                            $pendingItems[$productId] = is_array($pendingItems[$productId])
                                ? array_merge($pendingItems[$productId], ['quantity' => $leftPending])
                                : $leftPending;
                        } else {
                            unset($pendingItems[$productId]);
                        }
                    }
                }

                if ($qtyToDeduct > 0) {
                    $remainingForDb[$productId] = $qtyToDeduct;
                }
            }

            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['pending_guest_cart']) && is_array($_SESSION['pending_guest_cart'])) {
                if ($pendingItems === []) {
                    Auth::clearPendingGuestCart();
                } else {
                    $_SESSION['pending_guest_cart']['items'] = $pendingItems;
                }
            }

            // 2. Trừ chính xác số lượng còn lại trong MySQL `cart_items` bằng Transaction + FOR UPDATE
            if ($remainingForDb !== []) {
                try {
                    $cartId = $cartModel->getOrCreateCartId($userId);
                    $this->db->beginTransaction();

                    $selStmt = $this->db->prepare(
                        'SELECT id, quantity FROM cart_items WHERE cart_id = :cart_id AND product_id = :product_id LIMIT 1 FOR UPDATE'
                    );
                    $updStmt = $this->db->prepare(
                        'UPDATE cart_items SET quantity = :qty, updated_at = NOW() WHERE id = :id'
                    );
                    $delStmt = $this->db->prepare(
                        'DELETE FROM cart_items WHERE id = :id'
                    );

                    foreach ($remainingForDb as $productId => $deductQty) {
                        $selStmt->execute([':cart_id' => $cartId, ':product_id' => $productId]);
                        $row = $selStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$row) {
                            continue;
                        }

                        $newQty = (int) $row['quantity'] - $deductQty;
                        if ($newQty > 0) {
                            $updStmt->execute([':qty' => $newQty, ':id' => (int) $row['id']]);
                        } else {
                            $delStmt->execute([':id' => (int) $row['id']]);
                        }
                    }

                    $this->db->commit();
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    error_log('[OrderModel] Lỗi dọn từng phần giỏ MySQL sau khi đặt đơn: ' . $e->getMessage());
                }
            }
            return;
        }

        // Khách vãng lai: chỉ trừ đúng số lượng đã commit khỏi $_SESSION['cart'] và $_SESSION['guest_cart']
        if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
            foreach ($committedItems as $productId => $committedQty) {
                if (!isset($_SESSION['cart'][$productId])) {
                    continue;
                }
                $entry   = $_SESSION['cart'][$productId];
                $curQty  = is_array($entry) ? (int) ($entry['quantity'] ?? 0) : (int) $entry;
                $leftQty = $curQty - (int) $committedQty;

                if ($leftQty > 0) {
                    $_SESSION['cart'][$productId] = is_array($entry)
                        ? array_merge($entry, ['quantity' => $leftQty])
                        : $leftQty;
                } else {
                    unset($_SESSION['cart'][$productId]);
                }
            }
        }

        if (isset($_SESSION['guest_cart']) && is_array($_SESSION['guest_cart'])) {
            foreach ($committedItems as $productId => $committedQty) {
                if (!isset($_SESSION['guest_cart'][$productId])) {
                    continue;
                }
                $entry   = $_SESSION['guest_cart'][$productId];
                $curQty  = is_array($entry) ? (int) ($entry['quantity'] ?? 0) : (int) $entry;
                $leftQty = $curQty - (int) $committedQty;

                if ($leftQty > 0) {
                    $_SESSION['guest_cart'][$productId] = is_array($entry)
                        ? array_merge($entry, ['quantity' => $leftQty])
                        : $leftQty;
                } else {
                    unset($_SESSION['guest_cart'][$productId]);
                }
            }
            if ($_SESSION['guest_cart'] === []) {
                unset($_SESSION['guest_cart']);
            }
        }

        if (empty($_SESSION['cart'])) {
            unset($_SESSION['cart_owner_id']);
        }
    }

    /**
     * Ghi nhận mã đơn vừa đặt vào session hiện tại để cho phép xem trang xác nhận.
     */
    private function rememberRecentOrderInSession(string $orderCode): void
    {
        if (!isset($_SESSION['recent_orders']) || !is_array($_SESSION['recent_orders'])) {
            $_SESSION['recent_orders'] = [];
        }
        $_SESSION['recent_orders'][$orderCode] = time();
    }

    /**
     * Khóa tệp idempotency bằng `flock(LOCK_EX)` để chống double-submit đồng thời.
     * Nếu không thể tạo/mở tệp hoặc flock thất bại -> ném ngoại lệ ngay, không âm thầm đi tiếp.
     *
     * @return resource
     */
    private function acquireIdempotencyLock(string $idempotencyKey)
    {
        $lockDir = dirname(__DIR__, 2) . '/storage/locks';
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            throw new RuntimeException('Không thể khởi tạo thư mục khóa xử lý đơn hàng.');
        }

        $sessId   = session_id() ?: 'cli';
        $lockFile = $lockDir . '/checkout_' . hash('sha256', $sessId . '|' . $idempotencyKey) . '.lock';
        $handle   = @fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Không thể mở tệp khóa xử lý đơn hàng đồng thời.');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Không thể thiết lập khóa độc quyền xử lý đơn hàng.');
        }

        return $handle;
    }
}
