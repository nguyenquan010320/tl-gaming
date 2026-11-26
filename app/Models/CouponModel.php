<?php
/**
 * Model quản lý Mã giảm giá (`coupons`) và Nhật ký sử dụng (`coupon_usages`) — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Xác thực điều kiện áp dụng voucher hoàn toàn trên Server (trạng thái `is_active`,
 *   khoảng thời gian `start_date`..`end_date`, giá trị đơn tối thiểu `min_order_amount`,
 *   tổng lượt dùng `usage_limit`/`usage_count`, giới hạn mỗi khách `per_user_limit`).
 * - Hỗ trợ khóa bản ghi (`SELECT ... FOR UPDATE`) trong Transaction đặt hàng để ngăn
 *   hai khách hàng tranh chấp lượt voucher cuối cùng cùng một thời điểm.
 * - Ghi nhận lượt dùng vào `coupon_usages` và tăng `coupons.usage_count` trong cùng Transaction.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class CouponModel extends Model
{
    protected string $table = 'coupons';

    /**
     * Tính toán phí vận chuyển trên Server dựa theo cấu hình tập trung `config/checkout.php`.
     *
     * @param string $deliveryMethod 'delivery' (giao tận nơi) hoặc 'pickup' (nhận tại showroom).
     * @param string $city           Tỉnh/Thành phố người nhận.
     * @param int    $subtotal       Tổng tiền hàng (VND).
     * @return int                   Phí vận chuyển (VND nguyên, >= 0).
     */
    public static function calculateShippingFee(string $deliveryMethod, string $city, int $subtotal): int
    {
        /** @var array<string, mixed> $config */
        $config   = require dirname(__DIR__, 2) . '/config/checkout.php';
        $shipping = $config['shipping'] ?? [];

        if ($deliveryMethod === 'pickup') {
            return (int) ($shipping['pickup_fee'] ?? 0);
        }

        $freeThreshold = (int) ($shipping['free_threshold'] ?? 10000000);
        if ($subtotal >= $freeThreshold) {
            return 0;
        }

        $innerCities = is_array($shipping['inner_cities'] ?? null) ? $shipping['inner_cities'] : [];
        $normCity    = mb_strtolower(trim($city), 'UTF-8');
        foreach ($innerCities as $inner) {
            if ($normCity !== '' && mb_strtolower(trim((string) $inner), 'UTF-8') === $normCity) {
                return (int) ($shipping['inner_city_fee'] ?? 30000);
            }
        }

        return (int) ($shipping['province_fee'] ?? 50000);
    }

    /**
     * Kiểm tra tính hợp lệ và tính số tiền giảm của mã voucher trên Server.
     *
     * Lưu ý về Guest Checkout:
     * - Khách vãng lai không có `user_id` nên giới hạn `per_user_limit` được kiểm soát
     *   thông qua `customer_email` hoặc `customer_phone` trong bảng `coupon_usages`.
     *
     * @param string   $rawCode       Mã voucher khách nhập.
     * @param int      $subtotal      Tổng tiền hàng trước giảm giá (VND).
     * @param int|null $userId        ID người dùng nếu đã đăng nhập, null nếu là khách.
     * @param string   $customerEmail Email người nhận (đã chuẩn hóa lowercase).
     * @param string   $customerPhone Số điện thoại người nhận (đã chuẩn hóa).
     * @param bool     $forUpdate     True nếu chạy trong Transaction đặt hàng cần khóa dòng `FOR UPDATE`.
     * @return array{
     *     valid: bool,
     *     message: string,
     *     coupon: array<string, mixed>|null,
     *     discount_amount: int
     * }
     */
    public function validateCouponForCheckout(
        string $rawCode,
        int $subtotal,
        ?int $userId,
        string $customerEmail,
        string $customerPhone,
        bool $forUpdate = false
    ): array {
        $code = strtoupper(trim($rawCode));
        if ($code === '') {
            return ['valid' => false, 'message' => 'Vui lòng nhập mã voucher.', 'coupon' => null, 'discount_amount' => 0];
        }

        if ($subtotal <= 0) {
            return ['valid' => false, 'message' => 'Giá trị đơn hàng không hợp lệ để áp dụng voucher.', 'coupon' => null, 'discount_amount' => 0];
        }

        // Khóa dòng voucher nếu đang trong Transaction xác nhận đơn hàng để chống vượt usage_limit
        $sql = "SELECT * FROM {$this->table} WHERE code = :code LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $coupon = $this->fetchOne($sql, [':code' => $code]);

        if ($coupon === null) {
            return ['valid' => false, 'message' => "Mã voucher \"{$code}\" không tồn tại.", 'coupon' => null, 'discount_amount' => 0];
        }

        if ((int) ($coupon['is_active'] ?? 0) !== 1) {
            return ['valid' => false, 'message' => "Mã voucher \"{$code}\" hiện đang tạm ngưng áp dụng.", 'coupon' => null, 'discount_amount' => 0];
        }

        // Kiểm tra thời gian hiệu lực theo thời điểm hiện tại của Server
        $nowTs   = time();
        $startTs = strtotime((string) $coupon['start_date']);
        $endTs   = strtotime((string) $coupon['end_date']);

        if ($startTs !== false && $nowTs < $startTs) {
            return ['valid' => false, 'message' => "Mã voucher \"{$code}\" chưa đến thời gian áp dụng.", 'coupon' => null, 'discount_amount' => 0];
        }

        if ($endTs !== false && $nowTs > $endTs) {
            return ['valid' => false, 'message' => "Mã voucher \"{$code}\" đã hết hạn sử dụng.", 'coupon' => null, 'discount_amount' => 0];
        }

        // Kiểm tra giá trị đơn tối thiểu
        $minOrder = (int) ($coupon['min_order_amount'] ?? 0);
        if ($subtotal < $minOrder) {
            $minText = number_format($minOrder, 0, ',', '.') . '₫';
            return ['valid' => false, 'message' => "Mã \"{$code}\" chỉ áp dụng cho đơn hàng từ {$minText} trở lên.", 'coupon' => null, 'discount_amount' => 0];
        }

        // Kiểm tra tổng lượt dùng toàn hệ thống (`usage_limit = 0` là không giới hạn)
        $usageLimit = (int) ($coupon['usage_limit'] ?? 0);
        $usageCount = (int) ($coupon['usage_count'] ?? 0);
        if ($usageLimit > 0 && $usageCount >= $usageLimit) {
            return ['valid' => false, 'message' => "Mã voucher \"{$code}\" đã hết lượt sử dụng.", 'coupon' => null, 'discount_amount' => 0];
        }

        // Kiểm tra giới hạn số lần mỗi khách hàng (`per_user_limit`)
        $perUserLimit = max(1, (int) ($coupon['per_user_limit'] ?? 1));
        $couponId     = (int) $coupon['id'];

        $conditions = [];
        $params     = [':coupon_id' => $couponId];

        if ($userId !== null && $userId > 0) {
            $conditions[]      = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        if ($customerEmail !== '') {
            $conditions[]             = 'customer_email = :customer_email';
            $params[':customer_email'] = mb_strtolower($customerEmail, 'UTF-8');
        }
        if ($customerPhone !== '') {
            $conditions[]             = 'customer_phone = :customer_phone';
            $params[':customer_phone'] = $customerPhone;
        }

        if ($conditions !== []) {
            $whereIdentity = '(' . implode(' OR ', $conditions) . ')';
            $usedSql       = "
                SELECT COUNT(*)
                FROM coupon_usages
                WHERE coupon_id = :coupon_id
                  AND status = 'used'
                  AND {$whereIdentity}
            " . ($forUpdate ? ' FOR UPDATE' : '');
            $usedByCustomer = (int) $this->fetchColumn($usedSql, $params);

            if ($usedByCustomer >= $perUserLimit) {
                return [
                    'valid'           => false,
                    'message'         => "Bạn đã sử dụng hết lượt áp dụng cho mã voucher \"{$code}\".",
                    'coupon'          => null,
                    'discount_amount' => 0,
                ];
            }
        }

        // Tính số tiền giảm (số nguyên VND), tuyệt đối không vượt quá tiền hàng (`subtotal`)
        $discountType  = (string) ($coupon['discount_type'] ?? 'fixed_amount');
        $discountValue = (int) ($coupon['discount_value'] ?? 0);
        $maxDiscount   = $coupon['max_discount_amount'] !== null ? (int) $coupon['max_discount_amount'] : null;

        if ($discountType === 'percentage') {
            $discountAmount = (int) floor(($subtotal * $discountValue) / 100);
            if ($maxDiscount !== null && $maxDiscount > 0) {
                $discountAmount = min($discountAmount, $maxDiscount);
            }
        } else {
            $discountAmount = $discountValue;
        }

        $discountAmount = max(0, min($discountAmount, $subtotal));

        return [
            'valid'           => true,
            'message'         => "Áp dụng mã \"{$code}\" thành công.",
            'coupon'          => $coupon,
            'discount_amount' => $discountAmount,
        ];
    }

    /**
     * Tăng `coupons.usage_count` có điều kiện và ghi nhật ký vào `coupon_usages`
     * ngay bên trong Transaction đặt hàng.
     *
     * @param int      $couponId        ID mã giảm giá.
     * @param int      $orderId         ID đơn hàng vừa tạo.
     * @param int|null $userId          ID người dùng (null nếu khách vãng lai).
     * @param string   $customerEmail   Email người nhận.
     * @param string   $customerPhone   Số điện thoại người nhận.
     * @param int      $discountApplied Số tiền thực tế đã giảm (VND).
     * @return void
     */
    public function recordUsage(
        int $couponId,
        int $orderId,
        ?int $userId,
        string $customerEmail,
        string $customerPhone,
        int $discountApplied
    ): void {
        // Cập nhật có điều kiện để bảo đảm không bao giờ vượt quá usage_limit
        $updStmt = $this->db->prepare("
            UPDATE {$this->table}
            SET usage_count = usage_count + 1, updated_at = NOW()
            WHERE id = :id
              AND is_active = 1
              AND (usage_limit = 0 OR usage_count < usage_limit)
        ");
        $updStmt->execute([':id' => $couponId]);

        if ($updStmt->rowCount() !== 1) {
            throw new \App\Core\CheckoutException('Mã giảm giá đã hết lượt sử dụng trong lúc xử lý đơn hàng.');
        }

        $insStmt = $this->db->prepare("
            INSERT INTO coupon_usages (
                coupon_id, order_id, user_id, customer_email, customer_phone, discount_applied, used_at, status
            ) VALUES (
                :coupon_id, :order_id, :user_id, :customer_email, :customer_phone, :discount_applied, NOW(), 'used'
            )
        ");
        $insStmt->execute([
            ':coupon_id'        => $couponId,
            ':order_id'         => $orderId,
            ':user_id'          => $userId,
            ':customer_email'   => mb_strtolower(trim($customerEmail), 'UTF-8'),
            ':customer_phone'   => trim($customerPhone),
            ':discount_applied' => max(0, $discountApplied),
        ]);
    }
}
