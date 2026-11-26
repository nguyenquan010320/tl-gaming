<?php
/**
 * Biểu mẫu Thêm / Sửa Máy Serial (`app/Views/admin/serials/form.php`).
 *
 * Vai trò:
 * - Thêm máy cũ mới cho sản phẩm có `has_serials = 1`.
 * - Sửa thông tin ngoại quan, pin, giá nhập, trạng thái và ghi chú với `expected_updated_at` chống ghi đè.
 * - Hiển thị cảnh báo rõ ràng khi máy đang `reserved`/`sold` hoặc đã phát sinh đơn hàng / kiểm định.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;
use App\Models\ProductUnitModel;

/** @var bool $isEdit */
/** @var array<string, mixed> $unit */
/** @var array<string, mixed>|null $existingUnit */
/** @var array<int, array<string, mixed>> $serialProducts */
/** @var string $formAction */

$existing      = $existingUnit ?? $unit;
$hasOrders     = $isEdit && (int) ($existing['order_items_count'] ?? 0) > 0;
$hasInsp       = $isEdit && (int) ($existing['inspections_count'] ?? 0) > 0;
$lockedStatus  = $isEdit && in_array((string) ($existing['status'] ?? ''), ['reserved', 'sold'], true);
$lockProduct   = $isEdit && ($hasOrders || $hasInsp);
$lockSerial    = $isEdit && ($hasOrders || $lockedStatus);

$statusChoices = $isEdit ? [
    'available' => 'Sẵn sàng bán (available)',
    'reserved'  => 'Đang giữ đơn (reserved)',
    'sold'      => 'Đã bán (sold)',
    'repairing' => 'Đang sửa chữa (repairing)',
    'returned'  => 'Khách trả lại (returned)',
] : [
    'available' => 'Sẵn sàng bán (available)',
    'repairing' => 'Đang sửa chữa (repairing)',
];
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">
            <?= $isEdit ? 'Chỉnh sửa máy / Serial: ' . View::e((string) ($existing['serial_number'] ?? '')) : 'Thêm máy / Serial mới' ?>
        </h1>
        <p class="admin-page-subtitle">
            Chỉ áp dụng cho sản phẩm quản lý tồn kho theo từng số Serial (`has_serials = 1`).
        </p>
    </div>
    <div class="adm-action-group">
        <?php if ($isEdit): ?>
            <a href="<?= View::e(View::url('/admin/serial/' . (int) $unit['id'])) ?>" class="adm-btn adm-btn-secondary">
                ← Xem chi tiết
            </a>
        <?php endif; ?>
        <a href="<?= View::e(View::url('/admin/serial')) ?>" class="adm-btn adm-btn-secondary">
            Danh sách Serial
        </a>
    </div>
</div>

<?php if ($isEdit && ($lockedStatus || $hasOrders || $hasInsp)): ?>
    <div class="adm-alert-box adm-alert-warn">
        <strong>Ràng buộc nghiệp vụ đang áp dụng:</strong>
        <ul>
            <?php if ($lockProduct): ?>
                <li>Máy đã có biên bản kiểm định hoặc đơn hàng nên không thể đổi sang sản phẩm khác.</li>
            <?php endif; ?>
            <?php if ($lockSerial): ?>
                <li>Máy đang ở trạng thái <code><?= View::e((string) $existing['status']) ?></code> hoặc đã phát sinh đơn hàng nên không được đổi Số Serial hoặc tự ý thay đổi trạng thái bán.</li>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="admin-card">
    <form method="POST" action="<?= View::e(View::url($formAction)) ?>" class="adm-form-grid">
        <input type="hidden" name="_csrf_token" value="<?= View::e(Csrf::token()) ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="expected_row_version" value="<?= View::e((string) ($unit['row_version'] ?? '1')) ?>">
        <?php endif; ?>

        <div class="adm-field adm-field-full">
            <label for="unitProductId">Sản phẩm áp dụng <span class="adm-req">*</span></label>
            <?php if ($lockProduct): ?>
                <input type="hidden" name="product_id" value="<?= View::e((string) ($existing['product_id'] ?? '')) ?>">
            <?php endif; ?>
            <select id="unitProductId"
                    name="<?= $lockProduct ? '_product_id_display' : 'product_id' ?>"
                    class="adm-select"
                    <?= $lockProduct ? 'disabled' : 'required' ?>>
                <option value="">-- Chọn sản phẩm quản lý theo Serial --</option>
                <?php foreach ($serialProducts as $sp): ?>
                    <?php $spId = (string) ($sp['id'] ?? ''); ?>
                    <option value="<?= View::e($spId) ?>" <?= (string) ($unit['product_id'] ?? '') === $spId ? 'selected' : '' ?>>
                        <?= View::e((string) $sp['name']) ?> (SKU: <?= View::e((string) $sp['sku']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="adm-field">
            <label for="unitSerialNumber">Số Serial (duy nhất) <span class="adm-req">*</span></label>
            <?php if ($lockSerial): ?>
                <input type="hidden" name="serial_number" value="<?= View::e((string) ($existing['serial_number'] ?? '')) ?>">
            <?php endif; ?>
            <input type="text"
                   id="unitSerialNumber"
                   name="<?= $lockSerial ? '_serial_display' : 'serial_number' ?>"
                   class="adm-input"
                   maxlength="120"
                   value="<?= View::e((string) ($unit['serial_number'] ?? '')) ?>"
                   placeholder="VD: TLG-LEG5-0099"
                   <?= $lockSerial ? 'readonly' : 'required' ?>>
        </div>

        <div class="adm-field">
            <label for="unitCosmeticGrade">Phân hạng ngoại quan <span class="adm-req">*</span></label>
            <select id="unitCosmeticGrade" name="cosmetic_grade" class="adm-select" required>
                <?php foreach (ProductUnitModel::ALLOWED_GRADES as $grade): ?>
                    <option value="<?= View::e($grade) ?>" <?= (string) ($unit['cosmetic_grade'] ?? 'Grade A') === $grade ? 'selected' : '' ?>>
                        <?= View::e($grade) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="adm-field">
            <label for="unitBatteryHealth">Tình trạng Pin (%)</label>
            <input type="number"
                   id="unitBatteryHealth"
                   name="battery_health"
                   class="adm-input"
                   min="0"
                   max="100"
                   step="1"
                   value="<?= View::e((string) ($unit['battery_health'] ?? '')) ?>"
                   placeholder="Để trống nếu là PC Desktop">
        </div>

        <div class="adm-field">
            <label for="unitImportPrice">Giá nhập (VNĐ) <span class="adm-req">*</span></label>
            <input type="number"
                   id="unitImportPrice"
                   name="import_price"
                   class="adm-input"
                   min="0"
                   max="100000000000"
                   step="1"
                   value="<?= View::e((string) ($unit['import_price'] ?? '0')) ?>"
                   required>
        </div>

        <div class="adm-field">
            <label for="unitStatus">Trạng thái máy <span class="adm-req">*</span></label>
            <?php if ($lockSerial): ?>
                <input type="hidden" name="status" value="<?= View::e((string) ($existing['status'] ?? 'available')) ?>">
            <?php endif; ?>
            <select id="unitStatus"
                    name="<?= $lockSerial ? '_status_display' : 'status' ?>"
                    class="adm-select"
                    <?= $lockSerial ? 'disabled' : 'required' ?>>
                <?php foreach ($statusChoices as $sKey => $sLabel): ?>
                    <option value="<?= View::e($sKey) ?>" <?= (string) ($unit['status'] ?? 'available') === $sKey ? 'selected' : '' ?>>
                        <?= View::e($sLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($isEdit): ?>
                <small class="adm-help-text">
                    Lưu ý: Nếu máy đang <code>repairing</code> hoặc <code>returned</code>, cần có biên bản kiểm định mới nhất đạt chuẩn trước khi chuyển về <code>available</code>.
                </small>
            <?php endif; ?>
        </div>

        <div class="adm-field adm-field-full">
            <label for="unitNotes">Ghi chú kỹ thuật / Tình trạng thực tế</label>
            <textarea id="unitNotes"
                      name="notes"
                      class="adm-textarea"
                      rows="4"
                      maxlength="2000"
                      placeholder="Ghi chú xước dăm, phụ kiện đi kèm, tình trạng bảo hành hãng..."><?= View::e((string) ($unit['notes'] ?? '')) ?></textarea>
        </div>

        <div class="adm-form-actions adm-field-full">
            <button type="submit" class="adm-btn adm-btn-primary">
                <?= $isEdit ? 'Lưu cập nhật Serial' : 'Tạo máy / Serial mới' ?>
            </button>
            <a href="<?= View::e(View::url($isEdit ? '/admin/serial/' . (int) $unit['id'] : '/admin/serial')) ?>"
               class="adm-btn adm-btn-secondary">
                Hủy bỏ
            </a>
        </div>
    </form>
</div>
