<?php
/**
 * Giao diện Lịch sử Kiểm định Kỹ thuật 18 bước (`app/Views/admin/inspections/history.php`).
 *
 * Vai trò:
 * - Hiển thị toàn bộ các biên bản kiểm định của một đơn vị serial theo thứ tự mới nhất trước (append-only).
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<string, mixed> $unit */
/** @var array<int, array<string, mixed>> $inspections */

$unitId = (int) $unit['id'];

$inspLabels = [
    'passed'           => ['text' => 'Đạt chuẩn 18 bước', 'class' => 'adm-badge-active'],
    'conditional_pass' => ['text' => 'Đạt có điều kiện', 'class' => 'adm-badge-warn'],
    'failed'           => ['text' => 'Không đạt', 'class' => 'adm-badge-danger'],
];
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Lịch sử kiểm định: <?= View::e((string) $unit['serial_number']) ?></h1>
        <p class="admin-page-subtitle">
            Sản phẩm: <strong><?= View::e((string) ($unit['product_name'] ?? '')) ?></strong> — Lịch sử biên bản lưu vĩnh viễn, không ghi đè biên bản cũ.
        </p>
    </div>
    <div class="adm-action-group">
        <a href="<?= View::e(View::url('/admin/serial/' . $unitId . '/kiem-dinh/them')) ?>" class="adm-btn adm-btn-primary">
            + Lập biên bản mới
        </a>
        <a href="<?= View::e(View::url('/admin/serial/' . $unitId)) ?>" class="adm-btn adm-btn-secondary">
            ← Chi tiết Serial
        </a>
    </div>
</div>

<div class="admin-card">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Mã biên bản</th>
                    <th>Ngày kiểm định</th>
                    <th>Kỹ thuật viên</th>
                    <th>Kết luận</th>
                    <th>Thống kê 18 bước</th>
                    <th>Ghi chú tổng thể</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($inspections === []): ?>
                    <tr>
                        <td colspan="7" class="adm-empty-cell">
                            Chưa có biên bản kiểm định nào cho máy này.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inspections as $insp): ?>
                        <?php
                        $iId   = (int) $insp['id'];
                        $oKey  = (string) ($insp['checklist_overall_result'] ?? 'passed');
                        $oMeta = $inspLabels[$oKey] ?? ['text' => $oKey, 'class' => 'adm-badge-hidden'];
                        ?>
                        <tr>
                            <td><strong>#<?= View::e((string) $iId) ?></strong></td>
                            <td><?= View::e(date('d/m/Y H:i:s', strtotime((string) $insp['inspection_date']))) ?></td>
                            <td><?= View::e((string) ($insp['inspector_name'] ?? 'Admin')) ?></td>
                            <td>
                                <span class="adm-badge <?= View::e($oMeta['class']) ?>">
                                    <?= View::e($oMeta['text']) ?>
                                </span>
                            </td>
                            <td class="adm-sub-meta">
                                Đạt: <strong><?= View::e((string) ($insp['pass_count'] ?? 0)) ?></strong> |
                                Cảnh báo: <strong><?= View::e((string) ($insp['warning_count'] ?? 0)) ?></strong> |
                                Lỗi: <strong><?= View::e((string) ($insp['fail_count'] ?? 0)) ?></strong> |
                                N/A: <strong><?= View::e((string) ($insp['na_count'] ?? 0)) ?></strong>
                            </td>
                            <td><?= trim((string) ($insp['notes'] ?? '')) !== '' ? View::e((string) $insp['notes']) : '<span class="adm-sub-meta">—</span>' ?></td>
                            <td>
                                <a href="<?= View::e(View::url('/admin/kiem-dinh/' . $iId)) ?>"
                                   class="adm-btn adm-btn-secondary adm-btn-sm">
                                    Xem chi tiết
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
