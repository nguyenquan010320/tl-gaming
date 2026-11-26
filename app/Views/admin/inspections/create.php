<?php
/**
 * Biểu mẫu Lập Biên bản Kiểm định Kỹ thuật 18 bước (`app/Views/admin/inspections/create.php`).
 *
 * Vai trò:
 * - Hiển thị đầy đủ 18 bước kiểm định chuẩn của TL Gaming phân theo từng nhóm linh kiện.
 * - Hỗ trợ nút đánh dấu nhanh "Tất cả Đạt", tự động gợi ý kết luận tổng thể (server vẫn kiểm tra cứng).
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

/** @var array<string, mixed> $unit */
/** @var array<int, array<string, mixed>> $checklist */
/** @var array<string, mixed> $old */
/** @var string $formAction */

$unitId      = (int) $unit['id'];
$unitStatus  = (string) ($unit['status'] ?? 'available');
$canMarkAvail = in_array($unitStatus, ['repairing', 'returned'], true);
$oldSteps    = is_array($old['steps'] ?? null) ? $old['steps'] : [];
$oldOverall  = (string) ($old['overall_result'] ?? 'passed');
$oldNotes    = (string) ($old['notes'] ?? '');
$oldMarkAvail = ($old['mark_available_if_passed'] ?? '0') === '1';

// Gom 18 bước theo nhóm để hiển thị trực quan
$groupedChecklist = [];
foreach ($checklist as $item) {
    $grp = (string) $item['group_name'];
    $groupedChecklist[$grp][] = $item;
}
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Lập biên bản kiểm định 18 bước</h1>
        <p class="admin-page-subtitle">
            Serial: <code class="adm-serial-code"><?= View::e((string) $unit['serial_number']) ?></code>
            — Sản phẩm: <strong><?= View::e((string) ($unit['product_name'] ?? '')) ?></strong>
            — Trạng thái hiện tại: <strong><?= View::e($unitStatus) ?></strong>
        </p>
    </div>
    <div class="adm-action-group">
        <a href="<?= View::e(View::url('/admin/serial/' . $unitId . '/kiem-dinh')) ?>" class="adm-btn adm-btn-secondary">
            Lịch sử kiểm định
        </a>
        <a href="<?= View::e(View::url('/admin/serial/' . $unitId)) ?>" class="adm-btn adm-btn-secondary">
            ← Chi tiết Serial
        </a>
    </div>
</div>

<?php if (in_array($unitStatus, ['reserved', 'sold'], true)): ?>
    <div class="adm-alert-box adm-alert-warn">
        <strong>Lưu ý trạng thái đơn hàng:</strong>
        Máy đang ở trạng thái <code><?= View::e($unitStatus) ?></code>. Biên bản kiểm định mới sẽ được lưu vào lịch sử nhưng <strong>không làm thay đổi</strong> trạng thái đơn hàng hiện tại của máy.
    </div>
<?php endif; ?>

<form method="POST" action="<?= View::e(View::url($formAction)) ?>" id="inspection18Form">
    <input type="hidden" name="_csrf_token" value="<?= View::e(Csrf::token()) ?>">

    <div class="admin-card adm-insp-toolbar-card">
        <div class="adm-insp-toolbar">
            <div class="adm-insp-stats" id="inspLiveStats">
                <span class="adm-badge adm-badge-active">Đạt: <strong data-stat="pass">18</strong></span>
                <span class="adm-badge adm-badge-warn">Cảnh báo: <strong data-stat="warning">0</strong></span>
                <span class="adm-badge adm-badge-danger">Lỗi: <strong data-stat="fail">0</strong></span>
                <span class="adm-badge adm-badge-hidden">N/A: <strong data-stat="not_applicable">0</strong></span>
            </div>
            <div class="adm-action-group">
                <button type="button" class="adm-btn adm-btn-secondary adm-btn-sm" id="btnMarkAllPass">
                    Đánh dấu tất cả Đạt (Pass)
                </button>
            </div>
        </div>
    </div>

    <?php foreach ($groupedChecklist as $groupName => $groupSteps): ?>
        <div class="admin-card adm-insp-group-card">
            <h2 class="adm-card-title"><?= View::e($groupName) ?></h2>
            <div class="admin-table-wrap">
                <table class="admin-table adm-insp-table">
                    <thead>
                        <tr>
                            <th style="width: 68px;">Bước</th>
                            <th>Hạng mục kiểm tra</th>
                            <th style="width: 340px;">Kết quả</th>
                            <th>Ghi chú kỹ thuật bước này</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupSteps as $step): ?>
                            <?php
                            $sNum     = (int) $step['step_number'];
                            $savedRow = is_array($oldSteps[$sNum] ?? null) ? $oldSteps[$sNum] : [];
                            $sResult  = (string) ($savedRow['result'] ?? 'pass');
                            if (!in_array($sResult, ['pass', 'warning', 'fail', 'not_applicable'], true)) {
                                $sResult = 'pass';
                            }
                            $sNote = (string) ($savedRow['note'] ?? '');
                            ?>
                            <tr class="adm-insp-step-row" data-step="<?= View::e((string) $sNum) ?>">
                                <td><strong>#<?= View::e((string) $sNum) ?></strong></td>
                                <td><?= View::e((string) $step['item_name']) ?></td>
                                <td>
                                    <div class="adm-insp-radio-group">
                                        <label class="adm-insp-pill is-pass">
                                            <input type="radio"
                                                   name="steps[<?= View::e((string) $sNum) ?>][result]"
                                                   value="pass"
                                                   class="js-step-result"
                                                   <?= $sResult === 'pass' ? 'checked' : '' ?>>
                                            <span>Đạt</span>
                                        </label>
                                        <label class="adm-insp-pill is-warn">
                                            <input type="radio"
                                                   name="steps[<?= View::e((string) $sNum) ?>][result]"
                                                   value="warning"
                                                   class="js-step-result"
                                                   <?= $sResult === 'warning' ? 'checked' : '' ?>>
                                            <span>Cảnh báo</span>
                                        </label>
                                        <label class="adm-insp-pill is-fail">
                                            <input type="radio"
                                                   name="steps[<?= View::e((string) $sNum) ?>][result]"
                                                   value="fail"
                                                   class="js-step-result"
                                                   <?= $sResult === 'fail' ? 'checked' : '' ?>>
                                            <span>Lỗi</span>
                                        </label>
                                        <label class="adm-insp-pill is-na">
                                            <input type="radio"
                                                   name="steps[<?= View::e((string) $sNum) ?>][result]"
                                                   value="not_applicable"
                                                   class="js-step-result"
                                                   <?= $sResult === 'not_applicable' ? 'checked' : '' ?>>
                                            <span>N/A</span>
                                        </label>
                                    </div>
                                </td>
                                <td>
                                    <input type="text"
                                           name="steps[<?= View::e((string) $sNum) ?>][note]"
                                           class="adm-input adm-input-sm"
                                           maxlength="500"
                                           value="<?= View::e($sNote) ?>"
                                           placeholder="Thông số đo hoặc ghi chú (tùy chọn)">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="admin-card">
        <h2 class="adm-card-title">Kết luận tổng thể biên bản</h2>
        <div class="adm-form-grid">
            <div class="adm-field">
                <label for="overallResultSelect">Kết luận tổng thể <span class="adm-req">*</span></label>
                <select id="overallResultSelect" name="overall_result" class="adm-select" required>
                    <option value="passed" <?= $oldOverall === 'passed' ? 'selected' : '' ?>>
                        Đạt chuẩn 18 bước (passed)
                    </option>
                    <option value="conditional_pass" <?= $oldOverall === 'conditional_pass' ? 'selected' : '' ?>>
                        Đạt có điều kiện (conditional_pass)
                    </option>
                    <option value="failed" <?= $oldOverall === 'failed' ? 'selected' : '' ?>>
                        Không đạt (failed)
                    </option>
                </select>
                <small class="adm-help-text">
                    Quy tắc bắt buộc: Có bất kỳ bước <strong>Lỗi (fail)</strong> → bắt buộc <strong>Không đạt</strong>.
                    Có bước <strong>Cảnh báo (warning)</strong> → không thể chọn <strong>Đạt chuẩn</strong>, nếu chọn <strong>Đạt có điều kiện</strong> phải nhập ghi chú tổng thể.
                </small>
            </div>

            <?php if ($canMarkAvail): ?>
                <div class="adm-field">
                    <label>Cập nhật trạng thái bán</label>
                    <label class="adm-checkbox-row">
                        <input type="checkbox"
                               name="mark_available_if_passed"
                               value="1"
                               <?= $oldMarkAvail ? 'checked' : '' ?>>
                        <span>Chuyển máy từ <code><?= View::e($unitStatus) ?></code> sang <strong>Sẵn sàng bán (available)</strong> nếu biên bản đạt chuẩn</span>
                    </label>
                </div>
            <?php endif; ?>

            <div class="adm-field adm-field-full">
                <label for="inspectionNotes">Ghi chú tổng thể biên bản</label>
                <textarea id="inspectionNotes"
                          name="notes"
                          class="adm-textarea"
                          rows="3"
                          maxlength="2000"
                          placeholder="Tóm tắt tình trạng tổng thể, lưu ý cho bộ phận bán hàng / bảo hành..."><?= View::e($oldNotes) ?></textarea>
            </div>

            <div class="adm-form-actions adm-field-full">
                <button type="submit" class="adm-btn adm-btn-primary">
                    Lưu biên bản kiểm định 18 bước
                </button>
                <a href="<?= View::e(View::url('/admin/serial/' . $unitId)) ?>" class="adm-btn adm-btn-secondary">
                    Hủy bỏ
                </a>
            </div>
        </div>
    </div>
</form>
