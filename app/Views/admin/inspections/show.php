<?php
/**
 * Giao diện Chi tiết Biên bản Kiểm định 18 bước (`app/Views/admin/inspections/show.php`).
 *
 * Vai trò:
 * - Hiển thị thông tin biên bản, kỹ thuật viên thực hiện, kết luận tổng thể và đầy đủ 18 bước kiểm tra.
 * - Chỉ đọc (append-only, không cho chỉnh sửa/xóa biên bản đã lưu).
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<string, mixed> $inspection */

$unitId = (int) $inspection['unit_id'];
$items  = is_array($inspection['items'] ?? null) ? $inspection['items'] : [];

$overallLabels = [
    'passed'           => ['text' => 'Đạt chuẩn 18 bước', 'class' => 'adm-badge-active'],
    'conditional_pass' => ['text' => 'Đạt có điều kiện', 'class' => 'adm-badge-warn'],
    'failed'           => ['text' => 'Không đạt', 'class' => 'adm-badge-danger'],
];

$stepResultLabels = [
    'pass'           => ['text' => 'Đạt', 'class' => 'adm-badge-active'],
    'warning'        => ['text' => 'Cảnh báo', 'class' => 'adm-badge-warn'],
    'fail'           => ['text' => 'Lỗi', 'class' => 'adm-badge-danger'],
    'not_applicable' => ['text' => 'N/A', 'class' => 'adm-badge-hidden'],
];

$oKey  = (string) ($inspection['checklist_overall_result'] ?? 'passed');
$oMeta = $overallLabels[$oKey] ?? ['text' => $oKey, 'class' => 'adm-badge-hidden'];
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Biên bản kiểm định #<?= View::e((string) $inspection['id']) ?></h1>
        <p class="admin-page-subtitle">
            Serial: <code class="adm-serial-code"><?= View::e((string) ($inspection['serial_number'] ?? '')) ?></code>
            — Sản phẩm: <strong><?= View::e((string) ($inspection['product_name'] ?? '')) ?></strong>
        </p>
    </div>
    <div class="adm-action-group">
        <a href="<?= View::e(View::url('/admin/serial/' . $unitId . '/kiem-dinh/them')) ?>" class="adm-btn adm-btn-primary">
            + Lập biên bản mới
        </a>
        <a href="<?= View::e(View::url('/admin/serial/' . $unitId . '/kiem-dinh')) ?>" class="adm-btn adm-btn-secondary">
            Lịch sử kiểm định
        </a>
        <a href="<?= View::e(View::url('/admin/serial/' . $unitId)) ?>" class="adm-btn adm-btn-secondary">
            ← Chi tiết Serial
        </a>
    </div>
</div>

<div class="admin-card adm-insp-summary-card">
    <dl class="adm-kv-list adm-kv-horizontal">
        <div class="adm-kv-row">
            <dt>Kết luận tổng thể</dt>
            <dd><span class="adm-badge <?= View::e($oMeta['class']) ?>"><?= View::e($oMeta['text']) ?></span></dd>
        </div>
        <div class="adm-kv-row">
            <dt>Kỹ thuật viên</dt>
            <dd>
                <strong><?= View::e((string) ($inspection['inspector_name'] ?? 'Admin')) ?></strong>
                <?php if (!empty($inspection['inspector_email'])): ?>
                    <span class="adm-sub-meta">(<?= View::e((string) $inspection['inspector_email']) ?>)</span>
                <?php endif; ?>
            </dd>
        </div>
        <div class="adm-kv-row">
            <dt>Thời gian kiểm định</dt>
            <dd><?= View::e(date('d/m/Y H:i:s', strtotime((string) $inspection['inspection_date']))) ?></dd>
        </div>
        <div class="adm-kv-row">
            <dt>Ghi chú tổng thể</dt>
            <dd><?= trim((string) ($inspection['notes'] ?? '')) !== '' ? nl2br(View::e((string) $inspection['notes'])) : '<span class="adm-sub-meta">Không có ghi chú</span>' ?></dd>
        </div>
    </dl>
</div>

<div class="admin-card">
    <h2 class="adm-card-title">Chi tiết 18 bước kiểm tra kỹ thuật</h2>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 72px;">Bước</th>
                    <th style="width: 220px;">Nhóm kiểm tra</th>
                    <th>Hạng mục</th>
                    <th style="width: 140px;">Kết quả</th>
                    <th>Ghi chú kỹ thuật</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $rKey  = (string) ($item['result'] ?? 'pass');
                    $rMeta = $stepResultLabels[$rKey] ?? ['text' => $rKey, 'class' => 'adm-badge-hidden'];
                    ?>
                    <tr>
                        <td><strong>#<?= View::e((string) $item['step_number']) ?></strong></td>
                        <td><span class="adm-sub-meta"><?= View::e((string) $item['group_name']) ?></span></td>
                        <td><?= View::e((string) $item['item_name']) ?></td>
                        <td>
                            <span class="adm-badge <?= View::e($rMeta['class']) ?>">
                                <?= View::e($rMeta['text']) ?>
                            </span>
                        </td>
                        <td>
                            <?= trim((string) ($item['notes'] ?? '')) !== '' ? View::e((string) $item['notes']) : '<span class="adm-sub-meta">—</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
