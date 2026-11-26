<?php
/**
 * Model Quản lý Voucher Admin (`app/Models/AdminCouponModel.php`) — Prompt 20.
 *
 * Vai trò & quy tắc nghiệp vụ:
 * - Quản lý CRUD voucher đồng bộ trực tiếp với Checkout (`CouponModel`) và Admin Orders (`AdminOrderModel`).
 * - Validation toàn diện phía server: mã duy nhất viết hoa, phần trăm 1–100, tiền cố định > 0,
 *   đơn tối thiểu >= 0, usage_limit >= 0 (0 = không giới hạn), per_user_limit >= 1, end_date >= start_date,
 *   chặn tràn số nguyên VND, không bao giờ cho client truyền hoặc sửa trực tiếp `usage_count`.
 * - Quy tắc sửa voucher an toàn:
 *   + Chưa từng phát sinh sử dụng (`usage_count = 0` và không có dòng trong `coupon_usages`/`orders`):
 *     được phép sửa toàn bộ các trường cấu hình hợp lệ (trừ `usage_count`).
 *   + Đã từng phát sinh sử dụng: KHÓA CỨNG `code`, `discount_type`, `discount_value`, `max_discount_amount`
 *     để bảo toàn đối soát lịch sử; chỉ cho chỉnh `description`, `min_order_amount`, `usage_limit`
 *     (bắt buộc `= 0` hoặc `>= usage_count`), `per_user_limit`, `start_date`, `end_date`, `is_active`.
 * - Không xóa cứng voucher đã xuất hiện trong `orders` hoặc `coupon_usages`.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DateTimeImmutable;
use DomainException;
use PDO;
use PDOException;

class AdminCouponModel extends Model
{
    protected string $table = 'coupons';

    public const ALLOWED_DISCOUNT_TYPES = ['percentage', 'fixed_amount'];
    public const ALLOWED_STATE_FILTERS  = ['active', 'disabled', 'expired', 'upcoming', 'exhausted'];

    /**
     * Giới hạn an toàn cho các giá trị tiền tệ VND và giới hạn lượt dùng (chống tràn số nguyên).
     */
    private const MAX_MONEY_VND  = 10000000000; // 10 tỷ VND
    private const MAX_USAGE_NUM  = 1000000000;

    /**
     * Tính trạng thái hiệu lực thực tế của voucher so với thời điểm hiện tại.
     *
     * @param array<string, mixed> $coupon
     * @return string `active` | `disabled` | `upcoming` | `expired` | `exhausted`
     */
    public static function resolveEffectiveStatus(array $coupon): string
    {
        if ((int) ($coupon['is_active'] ?? 0) !== 1) {
            return 'disabled';
        }

        $nowTs   = time();
        $startTs = strtotime((string) ($coupon['start_date'] ?? ''));
        $endTs   = strtotime((string) ($coupon['end_date'] ?? ''));

        if ($startTs !== false && $nowTs < $startTs) {
            return 'upcoming';
        }
        if ($endTs !== false && $nowTs > $endTs) {
            return 'expired';
        }

        $limit = (int) ($coupon['usage_limit'] ?? 0);
        $used  = (int) ($coupon['usage_count'] ?? 0);
        if ($limit > 0 && $used >= $limit) {
            return 'exhausted';
        }

        return 'active';
    }

    /**
     * Nhãn tiếng Việt cho trạng thái hiệu lực thực tế.
     */
    public static function getEffectiveStatusLabel(string $state): string
    {
        return match ($state) {
            'active'    => 'Đang áp dụng',
            'disabled'  => 'Đã tắt',
            'upcoming'  => 'Chưa đến hạn',
            'expired'   => 'Đã hết hạn',
            'exhausted' => 'Hết lượt',
            default     => $state,
        };
    }

    /**
     * Đếm tổng số voucher khớp bộ lọc để phân trang phía server.
     *
     * @param array<string, mixed> $filters
     * @return int
     */
    public function countAdminCoupons(array $filters = []): int
    {
        [$whereSql, $bindings] = $this->buildAdminWhere($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM coupons c WHERE {$whereSql}");
        foreach ($bindings as $key => $bind) {
            $stmt->bindValue($key, $bind['val'], $bind['type']);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lấy danh sách voucher Admin có lọc, sắp xếp whitelist và phân trang server.
     *
     * @param array<string, mixed> $filters
     * @param string               $sort
     * @param int                  $limit
     * @param int                  $offset
     * @return array<int, array<string, mixed>>
     */
    public function getAdminCoupons(array $filters = [], string $sort = 'newest', int $limit = 15, int $offset = 0): array
    {
        [$whereSql, $bindings] = $this->buildAdminWhere($filters);

        $orderBy = match ($sort) {
            'oldest'     => 'c.created_at ASC, c.id ASC',
            'code_asc'   => 'c.code ASC, c.id ASC',
            'usage_desc' => 'c.usage_count DESC, c.id DESC',
            default      => 'c.created_at DESC, c.id DESC',
        };

        $sql = "
            SELECT
                c.*,
                (SELECT COUNT(*) FROM coupon_usages cu WHERE cu.coupon_id = c.id) AS total_usage_records,
                (SELECT COUNT(*) FROM orders o WHERE o.coupon_id = c.id) AS linked_orders_count
            FROM coupons c
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
            $row['effective_status'] = self::resolveEffectiveStatus($row);
            $row['has_usage_history'] = ((int) $row['usage_count'] > 0)
                || ((int) $row['total_usage_records'] > 0)
                || ((int) $row['linked_orders_count'] > 0);
        }
        unset($row);

        return $rows;
    }

    /**
     * Tìm chi tiết một voucher theo ID kèm cờ `has_usage_history`.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT
                c.*,
                (SELECT COUNT(*) FROM coupon_usages cu WHERE cu.coupon_id = c.id) AS total_usage_records,
                (SELECT COUNT(*) FROM orders o WHERE o.coupon_id = c.id) AS linked_orders_count
            FROM coupons c
            WHERE c.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['effective_status']  = self::resolveEffectiveStatus($row);
        $row['has_usage_history'] = ((int) $row['usage_count'] > 0)
            || ((int) $row['total_usage_records'] > 0)
            || ((int) $row['linked_orders_count'] > 0);

        return $row;
    }

    /**
     * Tạo mới voucher sau khi kiểm tra toàn bộ quy tắc nghiệp vụ trong Transaction.
     * `usage_count` luôn khởi tạo bằng 0, bỏ qua mọi giá trị từ client.
     *
     * @param array<string, mixed> $input
     * @return int ID voucher mới tạo.
     *
     * @throws DomainException
     */
    public function createCoupon(array $input): int
    {
        $clean = $this->validateCouponPayload($input);

        return $this->runTransactionWithDeadlockRetry(function () use ($clean): int {
            $dupStmt = $this->db->prepare('SELECT id FROM coupons WHERE code = :code LIMIT 1 FOR UPDATE');
            $dupStmt->execute([':code' => $clean['code']]);
            if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new DomainException("Mã voucher \"{$clean['code']}\" đã tồn tại trong hệ thống.");
            }

            try {
                $stmt = $this->db->prepare("
                    INSERT INTO coupons (
                        code, description, discount_type, discount_value, max_discount_amount,
                        min_order_amount, usage_limit, usage_count, per_user_limit,
                        start_date, end_date, is_active, created_at, updated_at
                    ) VALUES (
                        :code, :description, :discount_type, :discount_value, :max_discount_amount,
                        :min_order_amount, :usage_limit, 0, :per_user_limit,
                        :start_date, :end_date, :is_active, NOW(), NOW()
                    )
                ");
                $stmt->bindValue(':code', $clean['code'], PDO::PARAM_STR);
                $stmt->bindValue(':description', $clean['description'] !== '' ? $clean['description'] : null, $clean['description'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':discount_type', $clean['discount_type'], PDO::PARAM_STR);
                $stmt->bindValue(':discount_value', $clean['discount_value'], PDO::PARAM_INT);
                $stmt->bindValue(':max_discount_amount', $clean['max_discount_amount'], $clean['max_discount_amount'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmt->bindValue(':min_order_amount', $clean['min_order_amount'], PDO::PARAM_INT);
                $stmt->bindValue(':usage_limit', $clean['usage_limit'], PDO::PARAM_INT);
                $stmt->bindValue(':per_user_limit', $clean['per_user_limit'], PDO::PARAM_INT);
                $stmt->bindValue(':start_date', $clean['start_date'], PDO::PARAM_STR);
                $stmt->bindValue(':end_date', $clean['end_date'], PDO::PARAM_STR);
                $stmt->bindValue(':is_active', $clean['is_active'], PDO::PARAM_INT);
                $stmt->execute();

                return (int) $this->db->lastInsertId();
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    throw new DomainException("Mã voucher \"{$clean['code']}\" đã tồn tại.");
                }
                throw $e;
            }
        });
    }

    /**
     * Cập nhật voucher an toàn với khóa dòng `SELECT ... FOR UPDATE`.
     *
     * Quy tắc bảo vệ lịch sử:
     * - Nếu voucher đã phát sinh sử dụng (`usage_count > 0` hoặc có dòng trong `coupon_usages`/`orders`):
     *   KHÓA CỨNG `code`, `discount_type`, `discount_value`, `max_discount_amount`.
     * - Không cho giảm `usage_limit` xuống thấp hơn `usage_count` (khi `usage_limit > 0`).
     * - Không bao giờ sửa `usage_count` qua hàm này.
     *
     * @param int                  $id
     * @param array<string, mixed> $input
     * @return void
     *
     * @throws DomainException
     */
    public function updateCoupon(int $id, array $input): void
    {
        if ($id <= 0) {
            throw new DomainException('ID voucher không hợp lệ.');
        }

        $this->runTransactionWithDeadlockRetry(function () use ($id, $input): void {
            $lockStmt = $this->db->prepare('SELECT * FROM coupons WHERE id = :id LIMIT 1 FOR UPDATE');
            $lockStmt->execute([':id' => $id]);
            $existing = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new DomainException('Không tìm thấy voucher cần cập nhật.');
            }

            $usageCount   = (int) $existing['usage_count'];
            $hasUsageRows = ((int) $this->fetchColumn('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = :cid', [':cid' => $id])) > 0;
            $hasOrderRows = ((int) $this->fetchColumn('SELECT COUNT(*) FROM orders WHERE coupon_id = :cid', [':cid' => $id])) > 0;
            $hasHistory   = $usageCount > 0 || $hasUsageRows || $hasOrderRows;

            // Nếu voucher đã có lịch sử sử dụng -> kiểm tra xem client có cố thay đổi trường nhạy cảm hay không
            if ($hasHistory) {
                $reqCode = strtoupper(trim((string) ($input['code'] ?? $existing['code'])));
                $reqType = trim((string) ($input['discount_type'] ?? $existing['discount_type']));
                $reqVal  = trim((string) ($input['discount_value'] ?? (string) $existing['discount_value']));
                $reqMax  = trim((string) ($input['max_discount_amount'] ?? ($existing['max_discount_amount'] !== null ? (string) $existing['max_discount_amount'] : '')));
                $curMax  = $existing['max_discount_amount'] !== null ? (string) $existing['max_discount_amount'] : '';

                if ($reqCode !== (string) $existing['code']) {
                    throw new DomainException('Voucher đã phát sinh lịch sử sử dụng, không được phép đổi mã voucher (code).');
                }
                if ($reqType !== (string) $existing['discount_type']) {
                    throw new DomainException('Voucher đã phát sinh lịch sử sử dụng, không được phép đổi loại giảm giá.');
                }
                if (!ctype_digit($reqVal) || (int) $reqVal !== (int) $existing['discount_value']) {
                    throw new DomainException('Voucher đã phát sinh lịch sử sử dụng, không được phép đổi giá trị giảm.');
                }
                if ($reqMax !== $curMax) {
                    throw new DomainException('Voucher đã phát sinh lịch sử sử dụng, không được phép đổi mức giảm tối đa.');
                }

                // Ép dùng giá trị gốc để validate các trường còn lại
                $input['code']                = (string) $existing['code'];
                $input['discount_type']       = (string) $existing['discount_type'];
                $input['discount_value']      = (string) $existing['discount_value'];
                $input['max_discount_amount'] = $curMax;
            }

            $clean = $this->validateCouponPayload($input);

            if ($clean['usage_limit'] > 0 && $clean['usage_limit'] < $usageCount) {
                throw new DomainException(
                    "Giới hạn tổng lượt dùng ({$clean['usage_limit']}) không thể nhỏ hơn số lượt đã sử dụng hiện tại ({$usageCount})."
                );
            }

            if ($clean['code'] !== (string) $existing['code']) {
                $dupStmt = $this->db->prepare('SELECT id FROM coupons WHERE code = :code AND id <> :id LIMIT 1 FOR UPDATE');
                $dupStmt->execute([':code' => $clean['code'], ':id' => $id]);
                if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new DomainException("Mã voucher \"{$clean['code']}\" đã tồn tại trong hệ thống.");
                }
            }

            $stmt = $this->db->prepare("
                UPDATE coupons
                SET code = :code,
                    description = :description,
                    discount_type = :discount_type,
                    discount_value = :discount_value,
                    max_discount_amount = :max_discount_amount,
                    min_order_amount = :min_order_amount,
                    usage_limit = :usage_limit,
                    per_user_limit = :per_user_limit,
                    start_date = :start_date,
                    end_date = :end_date,
                    is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->bindValue(':code', $clean['code'], PDO::PARAM_STR);
            $stmt->bindValue(':description', $clean['description'] !== '' ? $clean['description'] : null, $clean['description'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':discount_type', $clean['discount_type'], PDO::PARAM_STR);
            $stmt->bindValue(':discount_value', $clean['discount_value'], PDO::PARAM_INT);
            $stmt->bindValue(':max_discount_amount', $clean['max_discount_amount'], $clean['max_discount_amount'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':min_order_amount', $clean['min_order_amount'], PDO::PARAM_INT);
            $stmt->bindValue(':usage_limit', $clean['usage_limit'], PDO::PARAM_INT);
            $stmt->bindValue(':per_user_limit', $clean['per_user_limit'], PDO::PARAM_INT);
            $stmt->bindValue(':start_date', $clean['start_date'], PDO::PARAM_STR);
            $stmt->bindValue(':end_date', $clean['end_date'], PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $clean['is_active'], PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        });
    }

    /**
     * Bật hoặc tắt trạng thái hoạt động của voucher (`is_active`: 1 <-> 0) trong Transaction.
     *
     * @return array{id: int, code: string, is_active: int}
     * @throws DomainException
     */
    public function toggleCouponActive(int $id, int $targetActive): array
    {
        if ($id <= 0 || !in_array($targetActive, [0, 1], true)) {
            throw new DomainException('Tham số bật/tắt voucher không hợp lệ.');
        }

        return $this->runTransactionWithDeadlockRetry(function () use ($id, $targetActive): array {
            $stmt = $this->db->prepare('SELECT id, code, is_active FROM coupons WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$coupon) {
                throw new DomainException('Không tìm thấy voucher yêu cầu.');
            }

            if ((int) $coupon['is_active'] === $targetActive) {
                $msg = $targetActive === 1
                    ? "Voucher \"{$coupon['code']}\" hiện đã ở trạng thái đang bật."
                    : "Voucher \"{$coupon['code']}\" hiện đã ở trạng thái tạm tắt.";
                throw new DomainException($msg);
            }

            $upd = $this->db->prepare('UPDATE coupons SET is_active = :active, updated_at = NOW() WHERE id = :id');
            $upd->execute([':active' => $targetActive, ':id' => $id]);

            return [
                'id'        => $id,
                'code'      => (string) $coupon['code'],
                'is_active' => $targetActive,
            ];
        });
    }

    /**
     * Lấy thống kê đối soát và danh sách lịch sử sử dụng voucher từ `coupon_usages` + `orders` (phân trang server).
     *
     * Phân biệt rõ:
     * - `usage_count`: lượt đang tính vào giới hạn trên `coupons.usage_count`.
     * - `total_recorded`: tổng lượt từng phát sinh (`used` + `cancelled`).
     * - `active_used`: số lượt hiện tại `status = 'used'`.
     * - `cancelled_refunded`: số lượt đã hoàn khi hủy đơn (`status = 'cancelled'`).
     * - `total_active_discount`: tổng tiền giảm chỉ tính trên `status = 'used'`.
     *
     * @return array{summary: array<string, int>, rows: array<int, array<string, mixed>>, total_rows: int}
     */
    public function getCouponUsageHistory(int $couponId, int $limit = 15, int $offset = 0): array
    {
        $sumStmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total_recorded,
                COALESCE(SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END), 0) AS active_used,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_refunded,
                COALESCE(SUM(CASE WHEN status = 'used' THEN discount_applied ELSE 0 END), 0) AS total_active_discount
            FROM coupon_usages
            WHERE coupon_id = :cid
        ");
        $sumStmt->execute([':cid' => $couponId]);
        $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $listStmt = $this->db->prepare("
            SELECT
                cu.id,
                cu.order_id,
                cu.user_id,
                cu.customer_email,
                cu.customer_phone,
                cu.discount_applied,
                cu.used_at,
                cu.status,
                o.order_code,
                o.customer_name,
                o.status AS order_status,
                o.created_at AS order_created_at,
                u.full_name AS account_full_name
            FROM coupon_usages cu
            INNER JOIN orders o ON o.id = cu.order_id
            LEFT JOIN users u ON u.id = cu.user_id
            WHERE cu.coupon_id = :cid
            ORDER BY cu.used_at DESC, cu.id DESC
            LIMIT :limit OFFSET :offset
        ");
        $listStmt->bindValue(':cid', $couponId, PDO::PARAM_INT);
        $listStmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $listStmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $listStmt->execute();

        $totalRecorded = (int) ($sumRow['total_recorded'] ?? 0);

        return [
            'summary' => [
                'total_recorded'        => $totalRecorded,
                'active_used'           => (int) ($sumRow['active_used'] ?? 0),
                'cancelled_refunded'    => (int) ($sumRow['cancelled_refunded'] ?? 0),
                'total_active_discount' => (int) ($sumRow['total_active_discount'] ?? 0),
            ],
            'rows'       => $listStmt->fetchAll(PDO::FETCH_ASSOC),
            'total_rows' => $totalRecorded,
        ];
    }

    /**
     * Validate và chuẩn hóa dữ liệu đầu vào cho tạo/sửa voucher.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws DomainException
     */
    private function validateCouponPayload(array $input): array
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,50}$/', $code)) {
            throw new DomainException('Mã voucher chỉ gồm 3–50 ký tự chữ in hoa không dấu, số, gạch ngang (-) hoặc gạch dưới (_).');
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description, 'UTF-8') > 255) {
            throw new DomainException('Mô tả voucher tối đa 255 ký tự.');
        }

        $discountType = trim((string) ($input['discount_type'] ?? ''));
        if (!in_array($discountType, self::ALLOWED_DISCOUNT_TYPES, true)) {
            throw new DomainException('Loại giảm giá không hợp lệ (chỉ chấp nhận phần trăm hoặc số tiền cố định).');
        }

        $discountValue = $this->parseNonNegativeInt($input['discount_value'] ?? '', 'Giá trị giảm', 1, self::MAX_MONEY_VND);
        if ($discountType === 'percentage' && ($discountValue < 1 || $discountValue > 100)) {
            throw new DomainException('Giá trị giảm theo phần trăm phải nằm trong khoảng từ 1 đến 100.');
        }

        $maxDiscountRaw = trim((string) ($input['max_discount_amount'] ?? ''));
        $maxDiscount    = null;
        if ($discountType === 'percentage' && $maxDiscountRaw !== '') {
            $maxDiscount = $this->parseNonNegativeInt($maxDiscountRaw, 'Mức giảm tối đa', 1, self::MAX_MONEY_VND);
        }

        $minOrderAmount = $this->parseNonNegativeInt($input['min_order_amount'] ?? '0', 'Giá trị đơn hàng tối thiểu', 0, self::MAX_MONEY_VND);
        $usageLimit     = $this->parseNonNegativeInt($input['usage_limit'] ?? '0', 'Tổng lượt sử dụng tối đa', 0, self::MAX_USAGE_NUM);
        $perUserLimit   = $this->parseNonNegativeInt($input['per_user_limit'] ?? '1', 'Giới hạn mỗi khách', 1, self::MAX_USAGE_NUM);

        $startDate = $this->normalizeDateTime((string) ($input['start_date'] ?? ''), 'Thời điểm bắt đầu');
        $endDate   = $this->normalizeDateTime((string) ($input['end_date'] ?? ''), 'Thời điểm kết thúc');

        if (strtotime($endDate) < strtotime($startDate)) {
            throw new DomainException('Thời điểm kết thúc phải lớn hơn hoặc bằng thời điểm bắt đầu.');
        }

        // Chỉ chấp nhận chuỗi `'0'` hoặc `'1'`; tuyệt đối không ép kiểu lỏng bằng !empty()
        // để tránh giá trị bất thường ('abc', '-1', mảng...) vô tình kích hoạt voucher.
        if (!array_key_exists('is_active', $input) || !is_string($input['is_active'])) {
            throw new DomainException('Trạng thái kích hoạt voucher không hợp lệ (chỉ chấp nhận "0" hoặc "1").');
        }
        $isActiveRaw = trim($input['is_active']);
        if ($isActiveRaw !== '0' && $isActiveRaw !== '1') {
            throw new DomainException('Trạng thái kích hoạt voucher chỉ được là "0" (tạm tắt) hoặc "1" (đang bật).');
        }
        $isActive = $isActiveRaw === '1' ? 1 : 0;

        return [
            'code'                => $code,
            'description'         => $description,
            'discount_type'       => $discountType,
            'discount_value'      => $discountValue,
            'max_discount_amount' => $maxDiscount,
            'min_order_amount'    => $minOrderAmount,
            'usage_limit'         => $usageLimit,
            'per_user_limit'      => $perUserLimit,
            'start_date'          => $startDate,
            'end_date'            => $endDate,
            'is_active'           => $isActive,
        ];
    }

    /**
     * Parse số nguyên không âm an toàn, chống tràn số nguyên và chống chuỗi không hợp lệ.
     */
    private function parseNonNegativeInt(mixed $raw, string $fieldLabel, int $min, int $max): int
    {
        $str = trim((string) $raw);
        if ($str === '' || !ctype_digit($str)) {
            throw new DomainException("{$fieldLabel} phải là số nguyên không âm hợp lệ.");
        }
        if (strlen($str) > 15) {
            throw new DomainException("{$fieldLabel} vượt quá giới hạn lưu trữ cho phép.");
        }
        $val = (int) $str;
        if ($val < $min || $val > $max) {
            throw new DomainException("{$fieldLabel} phải nằm trong khoảng từ {$min} đến " . number_format($max, 0, ',', '.') . '.');
        }
        return $val;
    }

    /**
     * Chuẩn hóa chuỗi ngày giờ (`Y-m-d\TH:i` từ input datetime-local hoặc `Y-m-d H:i:s`) về `Y-m-d H:i:s`.
     */
    private function normalizeDateTime(string $raw, string $fieldLabel): string
    {
        $str = trim($raw);
        if ($str === '') {
            throw new DomainException("Vui lòng chọn {$fieldLabel}.");
        }

        $normalized = str_replace('T', ' ', $str);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
            $normalized .= ':00';
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized);
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException("{$fieldLabel} không đúng định dạng ngày giờ hợp lệ.");
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Xây dựng mệnh đề WHERE an toàn cho danh sách voucher Admin.
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
            $conditions[] = '(c.code LIKE :kw_code OR c.description LIKE :kw_desc)';
            $bindings[':kw_code'] = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_desc'] = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
        }

        $type = trim((string) ($filters['discount_type'] ?? ''));
        if ($type !== '' && in_array($type, self::ALLOWED_DISCOUNT_TYPES, true)) {
            $conditions[]       = 'c.discount_type = :dtype';
            $bindings[':dtype'] = ['val' => $type, 'type' => PDO::PARAM_STR];
        }

        $state = trim((string) ($filters['state'] ?? ''));
        if ($state === 'disabled') {
            $conditions[] = 'c.is_active = 0';
        } elseif ($state === 'upcoming') {
            $conditions[] = 'c.is_active = 1 AND c.start_date > NOW()';
        } elseif ($state === 'expired') {
            $conditions[] = 'c.is_active = 1 AND c.end_date < NOW()';
        } elseif ($state === 'exhausted') {
            $conditions[] = 'c.is_active = 1 AND c.start_date <= NOW() AND c.end_date >= NOW() AND c.usage_limit > 0 AND c.usage_count >= c.usage_limit';
        } elseif ($state === 'active') {
            $conditions[] = 'c.is_active = 1 AND c.start_date <= NOW() AND c.end_date >= NOW() AND (c.usage_limit = 0 OR c.usage_count < c.usage_limit)';
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
