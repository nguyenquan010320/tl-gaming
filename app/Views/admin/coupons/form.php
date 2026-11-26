<?php
/**
 * Biểu mẫu Thêm / Sửa Voucher Admin (`app/Views/admin/coupons/form.php`) — Prompt 20.
 *
 * Khi voucher đã phát sinh sử dụng (`$hasHistory === true`), các trường nhạy cảm
 * (`code`, `discount_type`, `discount_value`, `max_discount_amount`) bị khóa cả ở UI lẫn Server.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

/** @var string $mode `create` | `edit` */
/** @var array<string, mixed>|null $coupon */
/** @var array<string, string> $old */
/** @var bool $hasHistory */

$isEdit     = $mode === 'edit' && is_array($coupon);
$actionUrl  = $isEdit ? View::url('/admin/voucher/' . (int) $coupon['id'] . '/sua') : View::url('/admin/voucher/them');
$csrfToken  = Csrf::token();

$val = static function (string $key, string $default = '') use ($old, $coupon, $isEdit): string {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($isEdit && array_key_exists($key, $coupon) && $coupon[$key] !== null) {
        return (string) $coupon[$key];
    }
    return $default;
};

$fmtDatetimeLocal = static function (string $raw): string {
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d\TH:i', $ts) : $raw;
};

$discountType = $val('discount_type', 'fixed_amount');
$isActiveVal  = $val('is_active', '1');
$startDefault = $isEdit ? $fmtDatetimeLocal($val('start_date')) : date('Y-m-d\TH:i');
$endDefault   = $isEdit ? $fmtDatetimeLocal($val('end_date')) : date('Y-m-d\TH:i', strtotime('+30 days'));
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title"><?= $isEdit ? 'Chỉnh sửa voucher ' . View::e((string) $coupon['code']) : 'Thêm voucher mới' ?></h1>
        <p class="admin-page-subtitle">
            <?= $isEdit
                ? 'Chỉnh sửa điều kiện áp dụng voucher. Không cho phép sửa trực tiếp bộ đếm usage_count.'
                : 'Tạo mã giảm giá mới có hiệu lực ngay với quy trình Checkout của cửa hàng.' ?>
        </p>
    </div>
    <div style="display:flex; gap:8px;">
        <?php if ($isEdit): ?>
            <a href="<?= View::e(View::url('/admin/voucher/' . (int) $coupon['id'] . '/lich-su')) ?>" class="adm-btn adm-btn-secondary">Xem lịch sử dùng</a>
        <?php endif; ?>
        <a href="<?= View::e(View::url('/admin/voucher')) ?>" class="adm-btn adm-btn-secondary">← Danh sách voucher</a>
    </div>
</div>

<?php if ($isEdit && $hasHistory): ?>
    <div class="admin-alert admin-alert-warning" style="margin-bottom: 16px;">
        <strong>Voucher đã có lịch sử sử dụng (usage_count = <?= View::e((string) $coupon['usage_count']) ?>):</strong>
        Hệ thống khóa các trường <code>code</code>, <code>discount_type</code>, <code>discount_value</code>, <code>max_discount_amount</code> để bảo toàn đối soát đơn hàng cũ.
        Bạn vẫn có thể sửa mô tả, đơn tối thiểu, thời gian hiệu lực, trạng thái bật/tắt hoặc tăng giới hạn lượt dùng (không nhỏ hơn <?= View::e((string) $coupon['usage_count']) ?>).
    </div>
<?php endif; ?>

<div class="admin-card">
    <form method="POST" action="<?= View::e($actionUrl) ?>" class="adm-form-grid">
        <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">

        <div class="adm-field">
            <label for="cpCode">Mã voucher (viết hoa, không dấu) *</label>
            <input type="text"
                   id="cpCode"
                   name="code"
                   class="adm-input"
                   required
                   maxlength="50"
                   value="<?= View::e($val('code')) ?>"
                   <?= ($isEdit && $hasHistory) ? 'readonly' : '' ?>
                   placeholder="Ví dụ: TLGAMING200K">
        </div>

        <div class="adm-field">
            <label for="cpDesc">Mô tả chương trình</label>
            <input type="text"
                   id="cpDesc"
                   name="description"
                   class="adm-input"
                   maxlength="255"
                   value="<?= View::e($val('description')) ?>"
                   placeholder="Giảm 200.000đ cho đơn PC Gaming từ 8 triệu">
        </div>

        <div class="adm-field">
            <label for="cpDiscountType">Loại giảm giá *</label>
            <?php if ($isEdit && $hasHistory): ?>
                <input type="hidden" name="discount_type" value="<?= View::e($discountType) ?>">
                <input type="text" class="adm-input" readonly value="<?= $discountType === 'percentage' ? 'Phần trăm (%)' : 'Số tiền cố định (VND)' ?>">
            <?php else: ?>
                <select id="cpDiscountType" name="discount_type" class="adm-select">
                    <option value="fixed_amount" <?= $discountType === 'fixed_amount' ? 'selected' : '' ?>>Số tiền cố định (VND)</option>
                    <option value="percentage" <?= $discountType === 'percentage' ? 'selected' : '' ?>>Phần trăm (%)</option>
                </select>
            <?php endif; ?>
        </div>

        <div class="adm-field">
            <label for="cpDiscountValue">Giá trị giảm (1–100 nếu %, hoặc VND > 0) *</label>
            <input type="number"
                   id="cpDiscountValue"
                   name="discount_value"
                   class="adm-input"
                   required
                   min="1"
                   value="<?= View::e($val('discount_value')) ?>"
                   <?= ($isEdit && $hasHistory) ? 'readonly' : '' ?>
                   placeholder="Ví dụ: 500000 hoặc 10">
        </div>

        <div class="adm-field">
            <label for="cpMaxDiscount">Giảm tối đa (VND, chỉ dùng khi giảm theo %)</label>
            <input type="number"
                   id="cpMaxDiscount"
                   name="max_discount_amount"
                   class="adm-input"
                   min="1"
                   value="<?= View::e($val('max_discount_amount')) ?>"
                   <?= ($isEdit && $hasHistory) ? 'readonly' : '' ?>
                   placeholder="Để trống nếu không giới hạn trần giảm">
        </div>

        <div class="adm-field">
            <label for="cpMinOrder">Giá trị đơn hàng tối thiểu (VND) *</label>
            <input type="number"
                   id="cpMinOrder"
                   name="min_order_amount"
                   class="adm-input"
                   required
                   min="0"
                   value="<?= View::e($val('min_order_amount', '0')) ?>">
        </div>

        <div class="adm-field">
            <label for="cpUsageLimit">Tổng lượt sử dụng tối đa (0 = không giới hạn) *</label>
            <input type="number"
                   id="cpUsageLimit"
                   name="usage_limit"
                   class="adm-input"
                   required
                   min="0"
                   value="<?= View::e($val('usage_limit', '0')) ?>">
        </div>

        <div class="adm-field">
            <label for="cpPerUser">Giới hạn mỗi khách (>= 1) *</label>
            <input type="number"
                   id="cpPerUser"
                   name="per_user_limit"
                   class="adm-input"
                   required
                   min="1"
                   value="<?= View::e($val('per_user_limit', '1')) ?>">
        </div>

        <div class="adm-field">
            <label for="cpStartDate">Thời điểm bắt đầu *</label>
            <input type="datetime-local"
                   id="cpStartDate"
                   name="start_date"
                   class="adm-input"
                   required
                   value="<?= View::e($fmtDatetimeLocal($val('start_date', $startDefault))) ?>">
        </div>

        <div class="adm-field">
            <label for="cpEndDate">Thời điểm kết thúc *</label>
            <input type="datetime-local"
                   id="cpEndDate"
                   name="end_date"
                   class="adm-input"
                   required
                   value="<?= View::e($fmtDatetimeLocal($val('end_date', $endDefault))) ?>">
        </div>

        <div class="adm-field">
            <label for="cpIsActive">Trạng thái kích hoạt</label>
            <select id="cpIsActive" name="is_active" class="adm-select">
                <option value="1" <?= $isActiveVal === '1' ? 'selected' : '' ?>>Đang bật (Cho phép áp dụng)</option>
                <option value="0" <?= $isActiveVal === '0' ? 'selected' : '' ?>>Tạm tắt</option>
            </select>
        </div>

        <?php if ($isEdit): ?>
            <div class="adm-field">
                <label>Lượt đang ghi nhận (chỉ đọc)</label>
                <input type="text" class="adm-input" readonly value="<?= View::e((string) $coupon['usage_count']) ?> lượt">
            </div>
        <?php endif; ?>

        <div style="grid-column: 1 / -1; display: flex; gap: 10px; margin-top: 8px;">
            <button type="submit" class="adm-btn adm-btn-primary">
                <?= $isEdit ? 'Lưu thay đổi voucher' : 'Tạo voucher mới' ?>
            </button>
            <a href="<?= View::e(View::url('/admin/voucher')) ?>" class="adm-btn adm-btn-secondary">Hủy</a>
        </div>
    </form>
</div>
