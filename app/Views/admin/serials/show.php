<?php
/**
 * Giao diện Chi tiết Máy Serial & Lịch sử Kiểm định (`app/Views/admin/serials/show.php`).
 *
 * Vai trò:
 * - Hiển thị đầy đủ thông số định danh một máy đơn lẻ, trạng thái bán ra (đồng bộ Checkout) và lịch sử kiểm định 18 bước.
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<string, mixed> $unit */
/** @var array<int, array<string, mixed>> $inspections */

$unitId = (int) $unit['id'];

$statusLabels = [
    'available' => ['text' => 'Sẵn sàng bán', 'class' => 'adm-badge-active'],
    'reserved'  => ['text' => 'Đang giữ đơn', 'class' => 'adm-badge-warn'],
    'sold'      => ['text' => 'Đã bán', 'class' => 'adm-badge-hidden'],
    'repairing' => ['text' => 'Đang sửa chữa', 'class' => 'adm-badge-warn'],
    'returned'  => ['text' => 'Khách trả lại', 'class' => 'adm-badge-danger'],
];

$inspLabels = [
    'passed'           => ['text' => 'Đạt chuẩn 18 bước', 'class' => 'adm-badge-active'],
    'conditional_pass' => ['text' => 'Đạt có điều kiện', 'class' => 'adm-badge-warn'],
    'failed'           => ['text' => 'Không đạt', 'class' => 'adm-badge-danger'],
    'none'             => ['text' => 'Chưa kiểm định', 'class' => 'adm-badge-hidden'],
];

$stKey    = (string) ($unit['status'] ?? 'available');
$stMeta   = $statusLabels[$stKey] ?? ['text' => $stKey, 'class' => 'adm-badge-hidden'];
$inspKey  = (string) ($unit['latest_inspection_result'] ?? 'none');
$inspMeta = $inspLabels[$inspKey] ?? $inspLabels['none'];
$sellable = !empty($unit['is_sellable']);
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Serial: <?= View::e((string) $unit['serial_number']) ?></h1>
        <p class="admin-page-subtitle">
            Sản phẩm: <strong><?= View::e((string) ($unit['product_name'] ?? '')) ?></strong>
            (SKU: <?= View::e((string) ($unit['product_sku'] ?? '')) ?>)
        </p>
    </div>
    <div class="adm-action-group">
        <a href="<?= View::e(View::url('/admin/serial/' . $unitId . '/kiem-dinh/them')) ?>" class="adm-btn adm-btn-primary">
            + Lập biên bản 18 bước
        </a>
        <a href="<?= View::e(View::url('/admin/serial/' . $unitId . '/sua')) ?>" class="adm-btn adm-btn-secondary">
            Sửa thông tin
        </a>
        <a href="<?= View::e(View::url('/admin/serial')) ?>" class="adm-btn adm-btn-secondary">
            ← Danh sách Serial
        </a>
    </div>
</div>

<div class="adm-detail-grid">
    <div class="admin-card">
        <h2 class="adm-card-title">Thông tin đơn vị máy</h2>
        <dl class="adm-kv-list">
            <div class="adm-kv-row">
                <dt>Mã Serial</dt>
                <dd><code class="adm-serial-code"><?= View::e((string) $unit['serial_number']) ?></code></dd>
            </div>
            <div class="adm-kv-row">
                <dt>Sản phẩm</dt>
                <dd>
                    <a href="<?= View::e(View::url('/admin/san-pham/' . (int) $unit['product_id'] . '/sua')) ?>">
                        <?= View::e((string) ($unit['product_name'] ?? '')) ?>
                    </a>
                </dd>
            </div>
            <div class="adm-kv-row">
                <dt>Trạng thái kho</dt>
                <dd><span class="adm-badge <?= View::e($stMeta['class']) ?>"><?= View::e($stMeta['text']) ?></span></dd>
            </div>
            <div class="adm-kv-row">
                <dt>Điều kiện bán Checkout</dt>
                <dd>
                    <?php if ($sellable): ?>
                        <span class="adm-badge adm-badge-active">Khả dụng bán ngay</span>
                    <?php else: ?>
                        <span class="adm-badge adm-badge-danger">Không khả dụng (<?= View::e($stKey === 'available' ? 'Kiểm định không đạt' : $stMeta['text']) ?>)</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div class="adm-kv-row">
                <dt>Phân hạng ngoại quan</dt>
                <dd><span class="adm-badge adm-badge-info"><?= View::e((string) ($unit['cosmetic_grade'] ?? '')) ?></span></dd>
            </div>
            <div class="adm-kv-row">
                <dt>Tình trạng Pin</dt>
                <dd><?= $unit['battery_health'] !== null ? View::e((string) $unit['battery_health'] . '%') : 'Không áp dụng (PC Desktop)' ?></dd>
            </div>
            <div class="adm-kv-row">
                <dt>Giá nhập</dt>
                <dd><strong><?= View::e(number_format((float) ($unit['import_price'] ?? 0), 0, ',', '.')) ?>đ</strong></dd>
            </div>
            <div class="adm-kv-row">
                <dt>Kiểm định gần nhất</dt>
                <dd><span class="adm-badge <?= View::e($inspMeta['class']) ?>"><?= View::e($inspMeta['text']) ?></span></dd>
            </div>
            <div class="adm-kv-row">
                <dt>Đơn hàng liên quan</dt>
                <dd><?= View::e((string) ((int) ($unit['order_items_count'] ?? 0))) ?> lượt gắn đơn</dd>
            </div>
            <div class="adm-kv-row">
                <dt>Cập nhật cuối</dt>
                <dd><?= View::e(date('d/m/Y H:i:s', strtotime((string) ($unit['updated_at'] ?? 'now')))) ?></dd>
            </div>
            <div class="adm-kv-row">
                <dt>Ghi chú</dt>
                <dd><?= trim((string) ($unit['notes'] ?? '')) !== '' ? nl2br(View::e((string) $unit['notes'])) : '<span class="adm-sub-meta">Không có ghi chú</span>' ?></dd>
            </div>
        </dl>
    </div>

    <div class="admin-card">
        <div class="adm-card-head">
            <h2 class="adm-card-title">Lịch sử kiểm định kỹ thuật (<?= View::e((string) count($inspections)) ?>)</h2>
            <a href="<?= View::e(View::url('/admin/serial/' . $unitId . '/kiem-dinh')) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">
                Xem toàn bộ lịch sử
            </a>
        </div>

        <?php if ($inspections === []): ?>
            <p class="adm-empty-cell">
                Máy này chưa có biên bản kiểm định 18 bước nào.
                <a href="<?= View::e(View::url('/admin/serial/' . $unitId . '/kiem-dinh/them')) ?>">Lập biên bản đầu tiên</a>.
            </p>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Mã BB</th>
                            <th>Ngày kiểm định</th>
                            <th>Kỹ thuật viên</th>
                            <th>Kết luận</th>
                            <th>Thống kê bước</th>
                            <th>Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inspections as $insp): ?>
                            <?php
                            $iId   = (int) $insp['id'];
                            $oKey  = (string) ($insp['checklist_overall_result'] ?? 'passed');
                            $oMeta = $inspLabels[$oKey] ?? $inspLabels['none'];
                            ?>
                            <tr>
                                <td><strong>#<?= View::e((string) $iId) ?></strong></td>
                                <td><?= View::e(date('d/m/Y H:i', strtotime((string) $insp['inspection_date']))) ?></td>
                                <td><?= View::e((string) ($insp['inspector_name'] ?? 'Admin')) ?></td>
                                <td>
                                    <span class="adm-badge <?= View::e($oMeta['class']) ?>">
                                        <?= View::e($oMeta['text']) ?>
                                    </span>
                                </td>
                                <td class="adm-sub-meta">
                                    Đạt: <?= View::e((string) ($insp['pass_count'] ?? 0)) ?> |
                                    Cảnh báo: <?= View::e((string) ($insp['warning_count'] ?? 0)) ?> |
                                    Lỗi: <?= View::e((string) ($insp['fail_count'] ?? 0)) ?>
                                </td>
                                <td>
                                    <a href="<?= View::e(View::url('/admin/kiem-dinh/' . $iId)) ?>"
                                       class="adm-btn adm-btn-secondary adm-btn-sm">
                                        Xem 18 bước
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
