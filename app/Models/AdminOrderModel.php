<?php
/**
 * Model Quản lý Đơn hàng Admin (`app/Models/AdminOrderModel.php`) — Prompt 19.
 *
 * Vai trò trong kiến trúc MVC:
 * - Cung cấp danh sách phân trang phía server, tìm kiếm, lọc, xem chi tiết đơn hàng snapshot.
 * - Thực thi State Machine vòng đời đơn hàng theo đúng ENUM schema `orders.status`:
 *   `pending` -> `confirmed` -> `shipping` -> `completed` (và nhánh `cancelled` từ `pending`/`confirmed`).
 * - Quản lý xác nhận thanh toán COD/Chuyển khoản tách biệt với trạng thái đơn hàng.
 * - Thực thi nghiệp vụ Hủy đơn & Hoàn tồn kho (số lượng + serial theo chuẩn kiểm định 18 bước),
 *   hoàn lượt voucher an toàn và đánh dấu hoàn tiền thủ công khi đơn đã thu tiền (> 0đ).
 * - Ghi nhận đầy đủ nhật ký audit (`order_status_logs`) và bảo vệ đồng thời bằng
 *   `SELECT ... FOR UPDATE` + `Model::runTransactionWithDeadlockRetry()`.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DomainException;
use PDO;

class AdminOrderModel extends Model
{
    protected string $table = 'orders';

    /**
     * Giới hạn tối đa của cột `products.stock_quantity` (`INT UNSIGNED` trong MySQL/MariaDB).
     */
    private const MAX_STOCK_INT_UNSIGNED = 4294967295;

    /**
     * Danh sách trạng thái hợp lệ theo ENUM của bảng `orders.status`.
     */
    public const ALLOWED_ORDER_STATUSES = ['pending', 'confirmed', 'shipping', 'completed', 'cancelled'];

    /**
     * Danh sách trạng thái thanh toán hợp lệ theo ENUM của bảng `payments.status`.
     */
    public const ALLOWED_PAYMENT_STATUSES = ['pending', 'completed', 'failed', 'refunded'];

    /**
     * Danh sách phương thức thanh toán hợp lệ theo ENUM của bảng `payments.payment_method`.
     */
    public const ALLOWED_PAYMENT_METHODS = ['cod', 'bank_transfer'];

    /**
     * Bảng chuyển trạng thái hợp lệ (State Machine tập trung).
     * Tuyệt đối không cho nhảy cóc, không cho hủy đơn đang giao/đã hoàn tất, không cho mở lại đơn đã hủy.
     */
    public const ALLOWED_TRANSITIONS = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['shipping', 'cancelled'],
        'shipping'  => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    /**
     * Nhãn tiếng Việt cho trạng thái đơn hàng.
     */
    public const ORDER_STATUS_LABELS = [
        'pending'   => 'Chờ xác nhận',
        'confirmed' => 'Đã xác nhận',
        'shipping'  => 'Đang giao / Xử lý',
        'completed' => 'Hoàn tất',
        'cancelled' => 'Đã hủy',
    ];

    /**
     * Nhãn tiếng Việt cho trạng thái thanh toán.
     */
    public const PAYMENT_STATUS_LABELS = [
        'pending'   => 'Chưa thanh toán',
        'completed' => 'Đã thanh toán',
        'failed'    => 'Thất bại',
        'refunded'  => 'Đã hoàn tiền',
    ];

    /**
     * Kiểm tra chuyển trạng thái đơn hàng có hợp lệ theo State Machine hay không.
     *
     * @param string $fromStatus Trạng thái hiện tại trong CSDL.
     * @param string $toStatus   Trạng thái đích mong muốn.
     * @return void
     *
     * @throws DomainException Khi chuyển trạng thái không hợp lệ.
     */
    public static function assertTransitionAllowed(string $fromStatus, string $toStatus): void
    {
        if (!in_array($fromStatus, self::ALLOWED_ORDER_STATUSES, true)) {
            throw new DomainException("Trạng thái đơn hàng hiện tại không hợp lệ: {$fromStatus}.");
        }
        $allowedNext = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
        if (!in_array($toStatus, $allowedNext, true)) {
            $fromLabel = self::ORDER_STATUS_LABELS[$fromStatus] ?? $fromStatus;
            $toLabel   = self::ORDER_STATUS_LABELS[$toStatus] ?? $toStatus;
            throw new DomainException("Không thể chuyển đơn hàng từ trạng thái \"{$fromLabel}\" sang \"{$toLabel}\".");
        }
    }

    /**
     * Xác định hình thức nhận hàng chuẩn xác (`delivery` | `pickup` | `unknown`).
     *
     * Quy tắc:
     * - Ưu tiên giá trị cột `orders.delivery_method` nếu đã có (`pickup` hoặc `delivery`).
     * - Đối với đơn cũ trước migration (`delivery_method` = NULL): không tự suy diễn chắc chắn
     *   khi thiếu thông tin; chỉ coi là `pickup` nếu địa chỉ bắt đầu bằng `Nhận tại ` VÀ phí ship = 0;
     *   các trường hợp đơn cũ không đủ cơ sở sẽ trả về `unknown` để hiển thị trung thực.
     *
     * @param array<string, mixed> $order
     * @return string
     */
    public static function resolveDeliveryMethod(array $order): string
    {
        $explicit = trim((string) ($order['delivery_method'] ?? ''));
        if ($explicit === 'pickup' || $explicit === 'delivery') {
            return $explicit;
        }

        $addr        = trim((string) ($order['shipping_address'] ?? ''));
        $shippingFee = isset($order['shipping_fee']) ? (int) $order['shipping_fee'] : -1;
        if (str_starts_with($addr, 'Nhận tại ') && $shippingFee === 0) {
            return 'pickup';
        }

        return 'unknown';
    }

    /**
     * Nhãn tiếng Việt cho hình thức nhận hàng của đơn.
     *
     * @param array<string, mixed> $order
     * @return string
     */
    public static function getDeliveryMethodLabel(array $order): string
    {
        return match (self::resolveDeliveryMethod($order)) {
            'pickup'   => 'Tại showroom',
            'delivery' => 'Giao tận nơi',
            default    => 'Chưa rõ (đơn cũ)',
        };
    }

    /**
     * Xác định đơn hàng có phải nhận tại showroom hay không.
     *
     * @param array<string, mixed> $order
     * @return bool
     */
    public static function isPickupOrder(array $order): bool
    {
        return self::resolveDeliveryMethod($order) === 'pickup';
    }

    /**
     * Che bớt số điện thoại ở danh sách tổng hợp; SĐT đầy đủ chỉ hiển thị ở trang chi tiết.
     */
    public static function maskPhone(string $phone): string
    {
        $clean = trim($phone);
        $len   = strlen($clean);
        if ($len <= 6) {
            return $clean;
        }
        return substr($clean, 0, 3) . str_repeat('*', $len - 6) . substr($clean, -3);
    }

    /**
     * Đếm tổng số đơn hàng khớp bộ lọc để phân trang phía server.
     *
     * @param array<string, mixed> $filters
     * @return int
     */
    public function countAdminOrders(array $filters = []): int
    {
        [$whereSql, $bindings] = $this->buildAdminWhere($filters);
        $sql = "
            SELECT COUNT(*)
            FROM orders o
            LEFT JOIN payments pay ON pay.id = (
                SELECT p2.id
                FROM payments p2
                WHERE p2.order_id = o.id
                ORDER BY p2.id DESC
                LIMIT 1
            )
            WHERE {$whereSql}
        ";
        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $key => $bind) {
            $stmt->bindValue($key, $bind['val'], $bind['type']);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lấy danh sách đơn hàng Admin có lọc, sắp xếp whitelist và phân trang server.
     *
     * @param array<string, mixed> $filters
     * @param string               $sort
     * @param int                  $limit
     * @param int                  $offset
     * @return array<int, array<string, mixed>>
     */
    public function getAdminOrders(array $filters = [], string $sort = 'newest', int $limit = 15, int $offset = 0): array
    {
        [$whereSql, $bindings] = $this->buildAdminWhere($filters);

        $orderBy = match ($sort) {
            'oldest'      => 'o.created_at ASC, o.id ASC',
            'amount_desc' => 'o.final_amount DESC, o.id DESC',
            'amount_asc'  => 'o.final_amount ASC, o.id DESC',
            default       => 'o.created_at DESC, o.id DESC',
        };

        $sql = "
            SELECT
                o.id,
                o.order_code,
                o.user_id,
                o.customer_name,
                o.customer_phone,
                o.shipping_address,
                o.delivery_method,
                o.total_product_amount,
                o.shipping_fee,
                o.discount_amount,
                o.final_amount,
                o.status,
                o.created_at,
                pay.payment_method,
                pay.status AS payment_status,
                pay.refund_status,
                pay.amount AS payment_amount
            FROM orders o
            LEFT JOIN payments pay ON pay.id = (
                SELECT p2.id
                FROM payments p2
                WHERE p2.order_id = o.id
                ORDER BY p2.id DESC
                LIMIT 1
            )
            WHERE {$whereSql}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $key => $bind) {
            $stmt->bindValue($key, $bind['val'], $bind['type']);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['delivery_method_resolved'] = self::resolveDeliveryMethod($row);
            $row['delivery_method_label']    = self::getDeliveryMethodLabel($row);
            $row['is_pickup']                = ($row['delivery_method_resolved'] === 'pickup');
            $row['customer_phone_masked']    = self::maskPhone((string) ($row['customer_phone'] ?? ''));
        }
        unset($row);

        return $rows;
    }

    /**
     * Lấy chi tiết đầy đủ một đơn hàng theo ID (kèm items, serial snapshot, payment, coupon, audit logs).
     *
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public function getOrderDetail(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT
                o.*,
                c.code AS coupon_code,
                c.description AS coupon_description,
                u.full_name AS account_full_name,
                u.email AS account_email
            FROM orders o
            LEFT JOIN coupons c ON c.id = o.coupon_id
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }

        $itemStmt = $this->db->prepare("
            SELECT
                oi.*,
                p.has_serials,
                p.slug AS product_slug,
                pu.status AS current_unit_status,
                pu.cosmetic_grade AS unit_cosmetic_grade
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN product_units pu ON pu.id = oi.product_unit_id
            WHERE oi.order_id = :order_id
            ORDER BY oi.id ASC
        ");
        $itemStmt->execute([':order_id' => $orderId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $payStmt = $this->db->prepare("
            SELECT
                pay.*,
                adm.full_name AS confirmed_by_name,
                adm.email AS confirmed_by_email
            FROM payments pay
            LEFT JOIN users adm ON adm.id = pay.confirmed_by_user_id
            WHERE pay.order_id = :order_id
            ORDER BY pay.id DESC
            LIMIT 1
        ");
        $payStmt->execute([':order_id' => $orderId]);
        $payment = $payStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $logStmt = $this->db->prepare("
            SELECT
                l.*,
                adm.full_name AS actor_name,
                adm.email AS actor_email
            FROM order_status_logs l
            LEFT JOIN users adm ON adm.id = l.actor_user_id
            WHERE l.order_id = :order_id
            ORDER BY l.created_at DESC, l.id DESC
        ");
        $logStmt->execute([':order_id' => $orderId]);
        $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        $order['delivery_method_resolved'] = self::resolveDeliveryMethod($order);
        $order['delivery_method_label']    = self::getDeliveryMethodLabel($order);
        $order['is_pickup']                = ($order['delivery_method_resolved'] === 'pickup');
        $order['items']                    = $items;
        $order['payment']   = $payment;
        $order['logs']      = $logs;

        return $order;
    }

    /**
     * Xác nhận đơn hàng (`pending` -> `confirmed`).
     *
     * Quy tắc nghiệp vụ:
     * - Khóa dòng đơn hàng (`FOR UPDATE`) trong transaction có deadlock retry.
     * - Kiểm tra lại toàn bộ serial đã giữ (`product_units`) vẫn tồn tại, khớp sản phẩm và đang `reserved`.
     * - Không trừ tồn kho lần 2, không áp dụng lại voucher, không tạo lại payments,
     *   và tuyệt đối không tự động đánh dấu đã thanh toán (`payments.status` giữ nguyên).
     *
     * @param int $orderId ID đơn hàng.
     * @param int $adminId ID Admin thực hiện.
     * @return array{order_code: string, old_status: string, new_status: string}
     *
     * @throws DomainException
     */
    public function confirmOrder(int $orderId, int $adminId): array
    {
        if ($orderId <= 0 || $adminId <= 0) {
            throw new DomainException('Thông tin đơn hàng hoặc quản trị viên không hợp lệ.');
        }

        return $this->runTransactionWithDeadlockRetry(function () use ($orderId, $adminId): array {
            $order     = $this->lockOrderForUpdate($orderId);
            $oldStatus = (string) $order['status'];
            self::assertTransitionAllowed($oldStatus, 'confirmed');

            // Kiểm tra các dòng sản phẩm và serial đã giữ cho đơn còn hợp lệ
            $items = $this->fetchOrderItems($orderId);
            if ($items === []) {
                throw new DomainException('Đơn hàng không có dòng sản phẩm nào, không thể xác nhận.');
            }

            $unitIds = $this->extractUnitIds($items);
            if ($unitIds !== []) {
                $lockedUnits = $this->lockUnitsByIdsForUpdate($unitIds);
                foreach ($items as $item) {
                    $uId = $item['product_unit_id'] !== null ? (int) $item['product_unit_id'] : 0;
                    if ($uId > 0) {
                        $unit = $lockedUnits[$uId] ?? null;
                        if (!$unit || (int) $unit['product_id'] !== (int) $item['product_id']) {
                            throw new DomainException("Serial #{$uId} không khớp với sản phẩm trong đơn hàng.");
                        }
                        if ((string) $unit['status'] !== 'reserved') {
                            throw new DomainException(
                                "Máy serial \"{$unit['serial_number']}\" hiện có trạng thái \"{$unit['status']}\", không còn được giữ (reserved) cho đơn này."
                            );
                        }
                    }
                }
            }

            $upd = $this->db->prepare("
                UPDATE orders
                SET status = 'confirmed', updated_at = NOW()
                WHERE id = :id AND status = 'pending'
            ");
            $upd->execute([':id' => $orderId]);
            if ($upd->rowCount() !== 1) {
                throw new DomainException('Đơn hàng vừa được cập nhật bởi phiên làm việc khác. Vui lòng tải lại trang.');
            }

            $this->insertAuditLog(
                $orderId,
                'confirm',
                $oldStatus,
                'confirmed',
                $adminId,
                null,
                'Admin xác nhận đơn hàng (không thay đổi tồn kho hay trạng thái thanh toán).'
            );

            return [
                'order_code' => (string) $order['order_code'],
                'old_status' => $oldStatus,
                'new_status' => 'confirmed',
            ];
        });
    }

    /**
     * Xác nhận thanh toán thủ công (`payments.status`: `pending` -> `completed`).
     *
     * Quy tắc nghiệp vụ:
     * - Tách biệt với trạng thái đơn hàng; chặn nếu đơn đã `cancelled`.
     * - Không cho xác nhận trùng nếu `payments.status` đã là `completed` hoặc `refunded`.
     * - Số tiền luôn lấy từ `payments.amount` đã lưu khi Checkout (hỗ trợ cả đơn 0đ).
     * - Đối với chuyển khoản có số tiền > 0đ, bắt buộc nhập mã giao dịch / tham chiếu đối soát thực tế.
     *
     * @param int    $orderId         ID đơn hàng.
     * @param int    $adminId         ID Admin xác nhận thu tiền / đối soát.
     * @param string $transactionCode Mã giao dịch ngân hàng hoặc biên lai thu tiền COD.
     * @param string $note            Ghi chú đối soát (tùy chọn).
     * @return array{order_code: string, payment_method: string, amount: int}
     *
     * @throws DomainException
     */
    public function confirmPayment(int $orderId, int $adminId, string $transactionCode = '', string $note = ''): array
    {
        if ($orderId <= 0 || $adminId <= 0) {
            throw new DomainException('Thông tin đơn hàng hoặc quản trị viên không hợp lệ.');
        }

        $cleanTxCode = trim($transactionCode);
        $cleanNote   = trim($note);
        if (mb_strlen($cleanTxCode, 'UTF-8') > 100) {
            throw new DomainException('Mã tham chiếu giao dịch tối đa 100 ký tự.');
        }
        if (mb_strlen($cleanNote, 'UTF-8') > 255) {
            throw new DomainException('Ghi chú xác nhận thanh toán tối đa 255 ký tự.');
        }

        return $this->runTransactionWithDeadlockRetry(function () use ($orderId, $adminId, $cleanTxCode, $cleanNote): array {
            $order = $this->lockOrderForUpdate($orderId);
            if ((string) $order['status'] === 'cancelled') {
                throw new DomainException('Đơn hàng đã hủy, không thể xác nhận thanh toán.');
            }

            $payment = $this->lockPaymentForUpdate($orderId);
            if ($payment === null) {
                throw new DomainException('Không tìm thấy bản ghi thanh toán của đơn hàng.');
            }

            $payStatus = (string) $payment['status'];
            if ($payStatus === 'completed') {
                throw new DomainException('Thanh toán của đơn hàng này đã được xác nhận trước đó (không thể xác nhận 2 lần).');
            }
            if ($payStatus !== 'pending') {
                throw new DomainException("Không thể xác nhận thanh toán ở trạng thái \"{$payStatus}\".");
            }

            $method = (string) $payment['payment_method'];
            $amount = (int) $payment['amount'];

            if ($method === 'bank_transfer' && $amount > 0 && $cleanTxCode === '') {
                throw new DomainException('Đơn chuyển khoản có số tiền > 0đ bắt buộc nhập mã giao dịch ngân hàng đã đối soát.');
            }

            $upd = $this->db->prepare("
                UPDATE payments
                SET status = 'completed',
                    transaction_code = :tx_code,
                    confirmed_by_user_id = :admin_id,
                    confirmed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :pay_id AND status = 'pending'
            ");
            $upd->bindValue(':tx_code', $cleanTxCode !== '' ? $cleanTxCode : ($payment['transaction_code'] ?: null), $cleanTxCode !== '' || !empty($payment['transaction_code']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $upd->bindValue(':admin_id', $adminId, PDO::PARAM_INT);
            $upd->bindValue(':pay_id', (int) $payment['id'], PDO::PARAM_INT);
            $upd->execute();

            if ($upd->rowCount() !== 1) {
                throw new DomainException('Trạng thái thanh toán vừa thay đổi ở một phiên khác. Vui lòng tải lại.');
            }

            $logNote = sprintf(
                'Xác nhận thanh toán %s: %sđ%s%s',
                $method === 'bank_transfer' ? 'Chuyển khoản' : 'COD',
                number_format($amount, 0, ',', '.'),
                $cleanTxCode !== '' ? " | Mã GD: {$cleanTxCode}" : '',
                $cleanNote !== '' ? " | Ghi chú: {$cleanNote}" : ''
            );

            $this->insertAuditLog(
                $orderId,
                'confirm_payment',
                "payment:{$payStatus}",
                'payment:completed',
                $adminId,
                null,
                mb_substr($logNote, 0, 500, 'UTF-8')
            );

            return [
                'order_code'     => (string) $order['order_code'],
                'payment_method' => $method,
                'amount'         => $amount,
            ];
        });
    }

    /**
     * Chuyển đơn sang trạng thái Đang giao hàng / Xử lý nhận tại showroom (`confirmed` -> `shipping`).
     *
     * @param int    $orderId ID đơn hàng.
     * @param int    $adminId ID Admin thực hiện.
     * @param string $note    Ghi chú bàn giao vận chuyển / chuẩn bị tại showroom.
     * @return array{order_code: string, old_status: string, new_status: string}
     *
     * @throws DomainException
     */
    public function shipOrder(int $orderId, int $adminId, string $note = ''): array
    {
        if ($orderId <= 0 || $adminId <= 0) {
            throw new DomainException('Thông tin đơn hàng hoặc quản trị viên không hợp lệ.');
        }

        $cleanNote = trim($note);
        return $this->runTransactionWithDeadlockRetry(function () use ($orderId, $adminId, $cleanNote): array {
            $order     = $this->lockOrderForUpdate($orderId);
            $oldStatus = (string) $order['status'];
            self::assertTransitionAllowed($oldStatus, 'shipping');

            $upd = $this->db->prepare("
                UPDATE orders
                SET status = 'shipping', updated_at = NOW()
                WHERE id = :id AND status = 'confirmed'
            ");
            $upd->execute([':id' => $orderId]);
            if ($upd->rowCount() !== 1) {
                throw new DomainException('Đơn hàng vừa được cập nhật bởi phiên khác. Vui lòng tải lại trang.');
            }

            $defaultNote = self::isPickupOrder($order)
                ? 'Đã chuẩn bị máy sẵn sàng giao cho khách tại showroom.'
                : 'Đã bàn giao đơn hàng cho bộ phận giao hàng.';

            $this->insertAuditLog(
                $orderId,
                'ship',
                $oldStatus,
                'shipping',
                $adminId,
                null,
                mb_substr($cleanNote !== '' ? $cleanNote : $defaultNote, 0, 500, 'UTF-8')
            );

            return [
                'order_code' => (string) $order['order_code'],
                'old_status' => $oldStatus,
                'new_status' => 'shipping',
            ];
        });
    }

    /**
     * Hoàn tất đơn hàng (`shipping` -> `completed`).
     *
     * Quy tắc nghiệp vụ & khóa đồng thời:
     * - Khóa `products (id ASC) FOR UPDATE` -> `orders FOR UPDATE` -> `product_units (id ASC) FOR UPDATE` -> `payments FOR UPDATE`.
     * - Bắt buộc đơn hàng đã có `payments.status === 'completed'` (COD phải xác nhận đã thu tiền,
     *   Chuyển khoản phải đối soát xong trước khi hoàn tất đơn).
     * - Chuyển toàn bộ serial thuộc đơn từ `reserved` -> `sold` và tăng `row_version`.
     * - Không trừ `products.stock_quantity` lần hai và không sửa snapshot trong `order_items`.
     *
     * @param int    $orderId ID đơn hàng.
     * @param int    $adminId ID Admin thực hiện.
     * @param string $note    Ghi chú hoàn tất.
     * @return array{order_code: string, sold_serials: int}
     *
     * @throws DomainException
     */
    public function completeOrder(int $orderId, int $adminId, string $note = ''): array
    {
        if ($orderId <= 0 || $adminId <= 0) {
            throw new DomainException('Thông tin đơn hàng hoặc quản trị viên không hợp lệ.');
        }

        $cleanNote = trim($note);

        return $this->runTransactionWithDeadlockRetry(function () use ($orderId, $adminId, $cleanNote): array {
            // Đọc trước danh sách product_id để khóa products theo thứ tự id ASC (thống nhất với Checkout)
            $preProductIds = $this->fetchDistinctProductIdsNonLocking($orderId);
            if ($preProductIds !== []) {
                $this->lockProductsByIdsForUpdate($preProductIds);
            }

            $order     = $this->lockOrderForUpdate($orderId);
            $oldStatus = (string) $order['status'];
            self::assertTransitionAllowed($oldStatus, 'completed');

            $payment = $this->lockPaymentForUpdate($orderId);
            if ($payment === null || (string) $payment['status'] !== 'completed') {
                throw new DomainException(
                    'Chưa thể hoàn tất đơn hàng khi thanh toán chưa được xác nhận đã thu tiền/đối soát (payments.status = completed).'
                );
            }

            $items   = $this->fetchOrderItems($orderId);
            $unitIds = $this->extractUnitIds($items);
            $soldCnt = 0;

            if ($unitIds !== []) {
                $lockedUnits = $this->lockUnitsByIdsForUpdate($unitIds);
                $updUnitStmt = $this->db->prepare("
                    UPDATE product_units
                    SET status = 'sold',
                        row_version = row_version + 1,
                        updated_at = NOW()
                    WHERE id = :uid AND status = 'reserved'
                ");

                foreach ($items as $item) {
                    $uId = $item['product_unit_id'] !== null ? (int) $item['product_unit_id'] : 0;
                    if ($uId <= 0) {
                        continue;
                    }
                    $unit = $lockedUnits[$uId] ?? null;
                    if (!$unit || (int) $unit['product_id'] !== (int) $item['product_id']) {
                        throw new DomainException("Serial #{$uId} không khớp với sản phẩm của đơn hàng.");
                    }
                    if ((string) $unit['status'] !== 'reserved') {
                        throw new DomainException(
                            "Serial \"{$unit['serial_number']}\" hiện đang ở trạng thái \"{$unit['status']}\", không hợp lệ để xuất bán (sold)."
                        );
                    }

                    $updUnitStmt->execute([':uid' => $uId]);
                    if ($updUnitStmt->rowCount() !== 1) {
                        throw new DomainException("Không thể chuyển serial \"{$unit['serial_number']}\" sang đã bán.");
                    }
                    $soldCnt++;
                }
            }

            $updOrder = $this->db->prepare("
                UPDATE orders
                SET status = 'completed', updated_at = NOW()
                WHERE id = :id AND status = 'shipping'
            ");
            $updOrder->execute([':id' => $orderId]);
            if ($updOrder->rowCount() !== 1) {
                throw new DomainException('Đơn hàng vừa được cập nhật bởi phiên khác. Vui lòng tải lại trang.');
            }

            $this->insertAuditLog(
                $orderId,
                'complete',
                $oldStatus,
                'completed',
                $adminId,
                null,
                mb_substr(
                    $cleanNote !== '' ? $cleanNote : "Hoàn tất đơn hàng, chuyển {$soldCnt} serial từ reserved sang sold.",
                    0,
                    500,
                    'UTF-8'
                )
            );

            return [
                'order_code'   => (string) $order['order_code'],
                'sold_serials' => $soldCnt,
            ];
        });
    }

    /**
     * Hủy đơn hàng chưa giao/chưa hoàn tất (`pending` hoặc `confirmed` -> `cancelled`),
     * đồng thời hoàn tồn kho số lượng, giải phóng serial đúng chuẩn kiểm định 18 bước,
     * hoàn lượt voucher an toàn và đánh dấu hoàn tiền thủ công nếu đơn đã thu tiền (> 0đ).
     *
     * Thứ tự khóa chống deadlock (khớp Checkout & Serial Admin):
     *   `products (id ASC FOR UPDATE)` -> `orders (FOR UPDATE)` -> `product_units (id ASC FOR UPDATE)`
     *   -> `payments (FOR UPDATE)` -> `coupons (FOR UPDATE)` -> `coupon_usages (FOR UPDATE)`.
     *
     * @param int    $orderId ID đơn hàng.
     * @param int    $adminId ID Admin thực hiện hủy.
     * @param string $reason  Lý do hủy bắt buộc.
     * @return array{order_code: string, restored_qty_units: int, released_serials: int, voucher_restored: bool, manual_refund_required: bool}
     *
     * @throws DomainException
     */
    public function cancelOrder(int $orderId, int $adminId, string $reason): array
    {
        if ($orderId <= 0 || $adminId <= 0) {
            throw new DomainException('Thông tin đơn hàng hoặc quản trị viên không hợp lệ.');
        }

        $cleanReason = trim($reason);
        if ($cleanReason === '' || mb_strlen($cleanReason, 'UTF-8') < 3) {
            throw new DomainException('Vui lòng nhập lý do hủy đơn hàng (tối thiểu 3 ký tự).');
        }
        if (mb_strlen($cleanReason, 'UTF-8') > 255) {
            throw new DomainException('Lý do hủy đơn hàng tối đa 255 ký tự.');
        }

        return $this->runTransactionWithDeadlockRetry(function () use ($orderId, $adminId, $cleanReason): array {
            // 1. Đọc trước product_id (non-locking) để khóa products theo thứ tự id ASC trước khi khóa product_units
            $preProductIds = $this->fetchDistinctProductIdsNonLocking($orderId);
            $lockedProds   = $preProductIds !== [] ? $this->lockProductsByIdsForUpdate($preProductIds) : [];

            // 2. Khóa đơn hàng FOR UPDATE và kiểm tra State Machine
            $order     = $this->lockOrderForUpdate($orderId);
            $oldStatus = (string) $order['status'];
            self::assertTransitionAllowed($oldStatus, 'cancelled');

            // 3. Đọc order_items và đối chiếu chặt chẽ với tập products đã khóa
            $items = $this->fetchOrderItems($orderId);
            if ($items === []) {
                throw new DomainException('Đơn hàng không có dòng sản phẩm nào để đối chiếu hoàn tồn kho. Đã hủy thao tác.');
            }
            $actualPids = array_values(array_unique(array_map(static fn(array $r): int => (int) $r['product_id'], $items)));
            sort($actualPids, SORT_NUMERIC);
            if ($actualPids !== $preProductIds) {
                $missing = array_diff($actualPids, array_keys($lockedProds));
                if ($missing !== []) {
                    $lockedProds += $this->lockProductsByIdsForUpdate($missing);
                }
            }

            // 4. Khóa tất cả product_units liên quan theo id ASC
            $unitIds     = $this->extractUnitIds($items);
            $lockedUnits = $unitIds !== [] ? $this->lockUnitsByIdsForUpdate($unitIds) : [];

            $restoredQtyUnits    = 0;
            $releasedSerials     = 0;
            $serialProductSyncId = [];

            $updQtyProdStmt = $this->db->prepare("
                UPDATE products
                SET stock_quantity = stock_quantity + :qty,
                    updated_at = NOW()
                WHERE id = :pid AND has_serials = 0
            ");

            $updUnitStmt = $this->db->prepare("
                UPDATE product_units
                SET status = :new_status,
                    row_version = row_version + 1,
                    updated_at = NOW()
                WHERE id = :uid AND status = 'reserved'
            ");

            foreach ($items as $item) {
                $pid = (int) ($item['product_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                $uId = $item['product_unit_id'] !== null ? (int) $item['product_unit_id'] : 0;

                if ($pid <= 0 || $qty <= 0) {
                    throw new DomainException('Dữ liệu dòng sản phẩm trong đơn hàng không hợp lệ (product_id hoặc quantity <= 0). Đã rollback.');
                }

                $prodRow = $lockedProds[$pid] ?? null;
                if ($prodRow === null) {
                    throw new DomainException("Không tìm thấy sản phẩm #{$pid} để hoàn tồn kho. Đã rollback.");
                }
                $isSerialProduct = (int) $prodRow['has_serials'] === 1;

                if ($uId > 0) {
                    if (!$isSerialProduct) {
                        throw new DomainException("Sai lệch loại quản lý tồn kho: sản phẩm #{$pid} là hàng số lượng nhưng dòng đơn lại gắn serial #{$uId}.");
                    }

                    $unit = $lockedUnits[$uId] ?? null;
                    if (!$unit || (int) $unit['product_id'] !== $pid) {
                        throw new DomainException("Serial #{$uId} không tồn tại hoặc không thuộc sản phẩm #{$pid} trong đơn hàng.");
                    }
                    if ((string) $unit['status'] !== 'reserved') {
                        // Tuyệt đối không tự động hoàn máy đã sold hoặc trạng thái khác bằng thao tác hủy thường
                        throw new DomainException(
                            "Serial \"{$unit['serial_number']}\" đang ở trạng thái \"{$unit['status']}\", không thể giải phóng qua thao tác hủy đơn."
                        );
                    }

                    // Chỉ cho về `available` nếu biên bản kiểm định mới nhất sau mốc hoàn trả đạt `passed` đủ 18 bước
                    $targetUnitStatus = $this->isUnitSafeToResell($unit) ? 'available' : 'repairing';
                    $updUnitStmt->execute([
                        ':new_status' => $targetUnitStatus,
                        ':uid'        => $uId,
                    ]);
                    if ($updUnitStmt->rowCount() !== 1) {
                        throw new DomainException("Không thể giải phóng serial \"{$unit['serial_number']}\" khi hủy đơn.");
                    }

                    $releasedSerials++;
                    $serialProductSyncId[$pid] = true;
                } else {
                    if ($isSerialProduct) {
                        throw new DomainException("Sai lệch loại quản lý tồn kho: sản phẩm #{$pid} quản lý theo Serial nhưng dòng đơn thiếu product_unit_id.");
                    }

                    // Kiểm tra chống tràn số nguyên và vượt giới hạn INT UNSIGNED khi cộng hoàn tồn kho
                    $currentStock = (int) $prodRow['stock_quantity'];
                    if ($qty > (self::MAX_STOCK_INT_UNSIGNED - $currentStock)) {
                        throw new DomainException("Hoàn tồn kho cho sản phẩm #{$pid} vượt quá giới hạn lưu trữ cho phép. Đã rollback.");
                    }

                    // Sản phẩm quản lý theo số lượng (has_serials = 0): hoàn đúng số lượng đã trừ khi Checkout
                    $updQtyProdStmt->execute([':qty' => $qty, ':pid' => $pid]);
                    if ($updQtyProdStmt->rowCount() !== 1) {
                        throw new DomainException("Không thể cập nhật hoàn tồn kho cho sản phẩm #{$pid}. Đã rollback.");
                    }
                    $restoredQtyUnits += $qty;
                }
            }

            // Đồng bộ cache tồn kho cho từng sản phẩm has_serials = 1 theo id ASC
            $syncIds = array_keys($serialProductSyncId);
            sort($syncIds, SORT_NUMERIC);
            foreach ($syncIds as $syncPid) {
                ProductUnitModel::syncProductStockCache($this->db, (int) $syncPid);
            }

            // 5. Hoàn lượt voucher an toàn (không xóa lịch sử coupon_usages, không che giấu sai lệch usage_count)
            $voucherRestored = false;
            $couponId        = $order['coupon_id'] !== null ? (int) $order['coupon_id'] : 0;
            if ($couponId > 0) {
                $lockCoupon = $this->db->prepare('SELECT id, usage_count FROM coupons WHERE id = :cid LIMIT 1 FOR UPDATE');
                $lockCoupon->execute([':cid' => $couponId]);
                $lockedCoupon = $lockCoupon->fetch(PDO::FETCH_ASSOC);
                if (!$lockedCoupon) {
                    throw new DomainException("Không tìm thấy mã voucher #{$couponId} để đối soát hoàn lượt.");
                }

                $lockUsage = $this->db->prepare("
                    SELECT id
                    FROM coupon_usages
                    WHERE order_id = :oid AND coupon_id = :cid AND status = 'used'
                    FOR UPDATE
                ");
                $lockUsage->execute([':oid' => $orderId, ':cid' => $couponId]);
                $usageRows      = $lockUsage->fetchAll(PDO::FETCH_ASSOC);
                $expectedCancel = count($usageRows);

                if ($expectedCancel > 0) {
                    $currentUsageCount = (int) $lockedCoupon['usage_count'];
                    if ($currentUsageCount < $expectedCancel) {
                        error_log(sprintf(
                            '[AdminOrderModel::cancelOrder] Sai lệch đối soát voucher: order_id=%d, coupon_id=%d, coupons.usage_count=%d, coupon_usages(used)=%d',
                            $orderId,
                            $couponId,
                            $currentUsageCount,
                            $expectedCancel
                        ));
                        throw new DomainException(
                            'Dữ liệu lượt sử dụng voucher không nhất quán (usage_count nhỏ hơn số lượt cần hoàn). Đã rollback để kiểm tra đối soát.'
                        );
                    }

                    $cancelUsageStmt = $this->db->prepare("
                        UPDATE coupon_usages
                        SET status = 'cancelled'
                        WHERE order_id = :oid AND coupon_id = :cid AND status = 'used'
                    ");
                    $cancelUsageStmt->execute([':oid' => $orderId, ':cid' => $couponId]);
                    if ($cancelUsageStmt->rowCount() !== $expectedCancel) {
                        throw new DomainException('Không thể cập nhật đầy đủ trạng thái hoàn lượt trong coupon_usages. Đã rollback.');
                    }

                    $decCoupon = $this->db->prepare("
                        UPDATE coupons
                        SET usage_count = usage_count - :cnt,
                            updated_at = NOW()
                        WHERE id = :cid AND usage_count >= :cnt_check
                    ");
                    $decCoupon->execute([
                        ':cnt'       => $expectedCancel,
                        ':cid'       => $couponId,
                        ':cnt_check' => $expectedCancel,
                    ]);
                    if ($decCoupon->rowCount() !== 1) {
                        throw new DomainException('Bộ đếm lượt sử dụng voucher không đủ để hoàn lượt. Đã rollback.');
                    }
                    $voucherRestored = true;
                }
            }

            // 6. Xử lý trạng thái thanh toán khi hủy:
            //    Nếu đã thu tiền (> 0đ) -> giữ nguyên payments.status = 'completed' (KHÔNG giả lập 'refunded'),
            //    đồng thời bật cờ `refund_status = 'manual_required'` để kế toán/Admin xử lý hoàn tiền thủ công.
            $manualRefundRequired = false;
            $payment              = $this->lockPaymentForUpdate($orderId);
            if ($payment !== null && (string) $payment['status'] === 'completed' && (int) $payment['amount'] > 0) {
                $manualRefundRequired = true;
                $updPay               = $this->db->prepare("
                    UPDATE payments
                    SET refund_status = 'manual_required',
                        refund_note = :rnote,
                        refund_requested_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :pid AND refund_status = 'none'
                ");
                $updPay->execute([
                    ':rnote' => mb_substr("Đơn hủy bởi Admin ({$cleanReason}) — cần hoàn tiền thủ công cho khách.", 0, 255, 'UTF-8'),
                    ':pid'   => (int) $payment['id'],
                ]);
            }

            // 7. Cập nhật trạng thái đơn sang `cancelled`
            $updOrder = $this->db->prepare("
                UPDATE orders
                SET status = 'cancelled',
                    cancel_reason = :reason,
                    cancelled_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id AND status IN ('pending', 'confirmed')
            ");
            $updOrder->execute([
                ':reason' => $cleanReason,
                ':id'     => $orderId,
            ]);
            if ($updOrder->rowCount() !== 1) {
                throw new DomainException('Đơn hàng vừa bị thay đổi bởi một phiên khác. Không thể hủy.');
            }

            $summaryNote = sprintf(
                'Hoàn tồn số lượng: %d | Giải phóng serial: %d | Hoàn lượt voucher: %s | Hoàn tiền thủ công: %s',
                $restoredQtyUnits,
                $releasedSerials,
                $voucherRestored ? 'Có' : 'Không',
                $manualRefundRequired ? 'Cần xử lý thủ công' : 'Không'
            );

            $this->insertAuditLog(
                $orderId,
                'cancel',
                $oldStatus,
                'cancelled',
                $adminId,
                $cleanReason,
                $summaryNote
            );

            return [
                'order_code'             => (string) $order['order_code'],
                'restored_qty_units'     => $restoredQtyUnits,
                'released_serials'       => $releasedSerials,
                'voucher_restored'       => $voucherRestored,
                'manual_refund_required' => $manualRefundRequired,
            ];
        });
    }

    /**
     * Kiểm tra một serial có đủ điều kiện kiểm định hiện tại để đưa về `available` khi hủy đơn hay không.
     * Đồng bộ tuyệt đối với `ProductUnitModel::sellableUnitWhereSql()`:
     * biên bản mới nhất sau mốc `returned_baseline_inspection_id` phải là `passed` và đủ 18 bước.
     *
     * @param array<string, mixed> $unit
     * @return bool
     */
    private function isUnitSafeToResell(array $unit): bool
    {
        $unitId     = (int) $unit['id'];
        $baselineId = $unit['returned_baseline_inspection_id'] !== null ? (int) $unit['returned_baseline_inspection_id'] : 0;

        $stmt = $this->db->prepare("
            SELECT
                pi.id,
                pi.checklist_overall_result,
                (SELECT COUNT(*) FROM inspection_items ii WHERE ii.inspection_id = pi.id) AS step_count
            FROM product_inspections pi
            WHERE pi.unit_id = :uid
              AND (:baseline = 0 OR pi.id > :baseline_cmp)
            ORDER BY pi.inspection_date DESC, pi.id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':uid'          => $unitId,
            ':baseline'     => $baselineId,
            ':baseline_cmp' => $baselineId,
        ]);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$latest) {
            return false;
        }

        return (string) $latest['checklist_overall_result'] === 'passed' && (int) $latest['step_count'] === 18;
    }

    /**
     * Khóa bản ghi `orders` theo ID bằng `SELECT ... FOR UPDATE`.
     *
     * @return array<string, mixed>
     * @throws DomainException
     */
    private function lockOrderForUpdate(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new DomainException('Không tìm thấy đơn hàng yêu cầu.');
        }
        return $order;
    }

    /**
     * Khóa bản ghi `payments` mới nhất của đơn hàng bằng `SELECT ... FOR UPDATE`.
     *
     * @return array<string, mixed>|null
     */
    private function lockPaymentForUpdate(int $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE order_id = :oid ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([':oid' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Lấy danh sách `order_items` thuộc đơn hàng.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrderItems(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = :oid ORDER BY id ASC');
        $stmt->execute([':oid' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Đọc danh sách product_id duy nhất của đơn (non-locking, đã sắp tăng dần) để khóa `products` trước.
     *
     * @return array<int, int>
     */
    private function fetchDistinctProductIdsNonLocking(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT product_id FROM order_items WHERE order_id = :oid ORDER BY product_id ASC');
        $stmt->execute([':oid' => $orderId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Khóa danh sách `products` theo thứ tự `id ASC FOR UPDATE`.
     *
     * @param array<int, int> $productIds
     * @return array<int, array<string, mixed>> Map theo `id`.
     */
    private function lockProductsByIdsForUpdate(array $productIds): array
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
        if ($clean === []) {
            return [];
        }
        sort($clean, SORT_NUMERIC);

        $placeholders = implode(', ', array_fill(0, count($clean), '?'));
        $stmt         = $this->db->prepare("SELECT id, has_serials, stock_quantity FROM products WHERE id IN ({$placeholders}) ORDER BY id ASC FOR UPDATE");
        $stmt->execute($clean);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['id']] = $row;
        }
        return $map;
    }

    /**
     * Trích xuất danh sách `product_unit_id` hợp lệ từ `order_items`.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, int>
     */
    private function extractUnitIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $uId = $item['product_unit_id'] !== null ? (int) $item['product_unit_id'] : 0;
            if ($uId > 0) {
                $ids[] = $uId;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Khóa các bản ghi `product_units` theo `id ASC FOR UPDATE`.
     *
     * @param array<int, int> $unitIds
     * @return array<int, array<string, mixed>> Map theo `id`.
     */
    private function lockUnitsByIdsForUpdate(array $unitIds): array
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $unitIds), static fn(int $id): bool => $id > 0)));
        if ($clean === []) {
            return [];
        }
        sort($clean, SORT_NUMERIC);

        $placeholders = implode(', ', array_fill(0, count($clean), '?'));
        $stmt         = $this->db->prepare("
            SELECT id, product_id, serial_number, status, row_version, returned_baseline_inspection_id
            FROM product_units
            WHERE id IN ({$placeholders})
            ORDER BY id ASC
            FOR UPDATE
        ");
        $stmt->execute($clean);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['id']] = $row;
        }
        return $map;
    }

    /**
     * Ghi nhận dấu vết thao tác vào bảng `order_status_logs` trong cùng Transaction.
     */
    private function insertAuditLog(
        int $orderId,
        string $actionType,
        ?string $oldStatus,
        ?string $newStatus,
        int $actorUserId,
        ?string $reason = null,
        ?string $note = null
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO order_status_logs (
                order_id, action_type, old_status, new_status, actor_user_id, reason, note, created_at
            ) VALUES (
                :order_id, :action_type, :old_status, :new_status, :actor_user_id, :reason, :note, NOW()
            )
        ");
        $stmt->execute([
            ':order_id'      => $orderId,
            ':action_type'   => $actionType,
            ':old_status'    => $oldStatus,
            ':new_status'    => $newStatus,
            ':actor_user_id' => $actorUserId,
            ':reason'        => $reason,
            ':note'          => $note,
        ]);
    }

    /**
     * Xây dựng mệnh đề WHERE an toàn (PDO Prepared Statements) cho trang danh sách đơn hàng.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, array{val: mixed, type: int}>}
     */
    private function buildAdminWhere(array $filters): array
    {
        $conditions = ['1 = 1'];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $likeVal = '%' . $escaped . '%';
            $conditions[] = '(o.order_code LIKE :kw_code OR o.customer_name LIKE :kw_name)';
            $bindings[':kw_code'] = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_name'] = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::ALLOWED_ORDER_STATUSES, true)) {
            $conditions[]        = 'o.status = :status';
            $bindings[':status'] = ['val' => $status, 'type' => PDO::PARAM_STR];
        }

        $payMethod = trim((string) ($filters['payment_method'] ?? ''));
        if ($payMethod !== '' && in_array($payMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
            $conditions[]            = 'pay.payment_method = :pay_method';
            $bindings[':pay_method'] = ['val' => $payMethod, 'type' => PDO::PARAM_STR];
        }

        $payStatus = trim((string) ($filters['payment_status'] ?? ''));
        if ($payStatus === 'manual_refund_required') {
            $conditions[] = "pay.refund_status = 'manual_required'";
        } elseif ($payStatus !== '' && in_array($payStatus, self::ALLOWED_PAYMENT_STATUSES, true)) {
            $conditions[]            = 'pay.status = :pay_status';
            $bindings[':pay_status'] = ['val' => $payStatus, 'type' => PDO::PARAM_STR];
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $conditions[]           = 'o.created_at >= :date_from';
            $bindings[':date_from'] = ['val' => $dateFrom . ' 00:00:00', 'type' => PDO::PARAM_STR];
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $conditions[]         = 'o.created_at <= :date_to';
            $bindings[':date_to'] = ['val' => $dateTo . ' 23:59:59', 'type' => PDO::PARAM_STR];
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
