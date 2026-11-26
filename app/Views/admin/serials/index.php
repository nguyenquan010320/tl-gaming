<?php
/**
 * Giao diện Danh sách Serial / Máy cũ Quản trị (`app/Views/admin/serials/index.php`).
 *
 * Vai trò:
 * - Hiển thị bảng quản lý từng máy tính cũ có serial (Số serial, Sản phẩm/SKU, Ngoại quan, Pin,
 *   Giá nhập, Trạng thái, Kiểm định gần nhất, Khả dụng bán).
 * - Hỗ trợ tìm kiếm và lọc theo sản phẩm, trạng thái, ngoại quan, kết quả kiểm định + phân trang server.
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<int, array<string, mixed>> $units */
/** @var array<int, array<string, mixed>> $serialProducts */
/** @var int $totalItems */
/** @var array<string, mixed> $pagination */
/** @var array<string, string> $filters */

$statusLabels = [
    'available' => ['text' => 'Sẵn sàng bán', 'class' => 'adm-badge-active'],
    'reserved'  => ['text' => 'Đang giữ đơn', 'class' => 'adm-badge-warn'],
    'sold'      => ['text' => 'Đã bán', 'class' => 'adm-badge-hidden'],
    'repairing' => ['text' => 'Đang sửa chữa', 'class' => 'adm-badge-warn'],
    'returned'  => ['text' => 'Khách trả lại', 'class' => 'adm-badge-danger'],
];

$inspectionLabels = [
    'passed'           => ['text' => 'Đạt chuẩn 18 bước', 'class' => 'adm-badge-active'],
    'conditional_pass' => ['text' => 'Đạt có điều kiện', 'class' => 'adm-badge-warn'],
    'failed'           => ['text' => 'Không đạt', 'class' => 'adm-badge-danger'],
    'none'             => ['text' => 'Chưa kiểm định', 'class' => 'adm-badge-hidden'],
];
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Quản lý Serial &amp; Kiểm định</h1>
        <p class="admin-page-subtitle">
            Tổng cộng <strong><?= View::e((string) $totalItems) ?></strong> máy đơn lẻ khớp bộ lọc.
            Chỉ các máy <strong>Sẵn sàng bán</strong> và không bị đánh dấu <strong>Không đạt</strong> ở lần kiểm định gần nhất mới khả dụng cho Checkout.
        </p>
    </div>
    <a href="<?= View::e(View::url('/admin/serial/them' . (!empty($filters['san-pham']) ? '?san-pham=' . urlencode($filters['san-pham']) : ''))) ?>"
       class="adm-btn adm-btn-primary">
        + Thêm máy / Serial
    </a>
</div>

<div class="admin-card">
    <form class="admin-filter-bar adm-serial-filter-bar" method="GET" action="<?= View::e(View::url('/admin/serial')) ?>">
        <div class="adm-field">
            <label for="serialSearch">Tìm kiếm (Serial / Tên / SKU)</label>
            <input type="text"
                   id="serialSearch"
                   name="q"
                   class="adm-input"
                   value="<?= View::e($filters['q'] ?? '') ?>"
                   placeholder="Nhập số serial, SKU hoặc tên máy...">
        </div>

        <div class="adm-field">
            <label for="serialProductFilter">Sản phẩm (Serial)</label>
            <select id="serialProductFilter" name="san-pham" class="adm-select">
                <option value="">Tất cả sản phẩm serial</option>
                <?php foreach ($serialProducts as $sp): ?>
                    <?php $spId = (string) ($sp['id'] ?? ''); ?>
                    <option value="<?= View::e($spId) ?>" <?= ($filters['san-pham'] ?? '') === $spId ? 'selected' : '' ?>>
                        <?= View::e((string) $sp['name']) ?> (<?= View::e((string) $sp['sku']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="adm-field">
            <label for="serialStatusFilter">Trạng thái máy</label>
            <select id="serialStatusFilter" name="trang-thai" class="adm-select">
                <option value="">Tất cả trạng thái</option>
                <?php foreach ($statusLabels as $key => $meta): ?>
                    <option value="<?= View::e($key) ?>" <?= ($filters['trang-thai'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= View::e($meta['text']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="adm-field">
            <label for="serialGradeFilter">Ngoại quan</label>
            <select id="serialGradeFilter" name="ngoai-quan" class="adm-select">
                <option value="">Tất cả hạng</option>
                <?php foreach (['Grade A', 'Grade B', 'Grade C'] as $g): ?>
                    <option value="<?= View::e($g) ?>" <?= ($filters['ngoai-quan'] ?? '') === $g ? 'selected' : '' ?>>
                        <?= View::e($g) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="adm-field">
            <label for="serialInspFilter">Kiểm định gần nhất</label>
            <select id="serialInspFilter" name="kiem-dinh" class="adm-select">
                <option value="">Tất cả</option>
                <?php foreach ($inspectionLabels as $iKey => $iMeta): ?>
                    <option value="<?= View::e($iKey) ?>" <?= ($filters['kiem-dinh'] ?? '') === $iKey ? 'selected' : '' ?>>
                        <?= View::e($iMeta['text']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="adm-filter-actions">
            <button type="submit" class="adm-btn adm-btn-primary">Lọc</button>
            <a href="<?= View::e(View::url('/admin/serial')) ?>" class="adm-btn adm-btn-secondary">Đặt lại</a>
        </div>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Số Serial</th>
                    <th>Sản phẩm</th>
                    <th>Ngoại quan &amp; Pin</th>
                    <th>Giá nhập</th>
                    <th>Trạng thái</th>
                    <th>Kiểm định gần nhất</th>
                    <th>Bán ra</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($units === []): ?>
                    <tr>
                        <td colspan="8" class="adm-empty-cell">
                            Chưa có đơn vị serial nào khớp bộ lọc hiện tại.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($units as $u): ?>
                        <?php
                        $uId      = (int) $u['id'];
                        $stKey    = (string) ($u['status'] ?? 'available');
                        $stMeta   = $statusLabels[$stKey] ?? ['text' => $stKey, 'class' => 'adm-badge-hidden'];
                        $inspKey  = (string) ($u['latest_inspection_result'] ?? 'none');
                        $inspMeta = $inspectionLabels[$inspKey] ?? $inspectionLabels['none'];
                        $sellable = !empty($u['is_sellable']);
                        ?>
                        <tr>
                            <td>
                                <a href="<?= View::e(View::url('/admin/serial/' . $uId)) ?>" class="adm-serial-code">
                                    <?= View::e((string) $u['serial_number']) ?>
                                </a>
                                <div class="adm-sub-meta">ID #<?= View::e((string) $uId) ?></div>
                            </td>
                            <td>
                                <div class="adm-prod-name"><?= View::e((string) ($u['product_name'] ?? '')) ?></div>
                                <div class="adm-sub-meta">SKU: <?= View::e((string) ($u['product_sku'] ?? '')) ?></div>
                            </td>
                            <td>
                                <span class="adm-badge adm-badge-info"><?= View::e((string) ($u['cosmetic_grade'] ?? '')) ?></span>
                                <div class="adm-sub-meta">
                                    Pin: <?= $u['battery_health'] !== null ? View::e((string) $u['battery_health'] . '%') : 'Không áp dụng' ?>
                                </div>
                            </td>
                            <td>
                                <strong><?= View::e(number_format((float) ($u['import_price'] ?? 0), 0, ',', '.')) ?>đ</strong>
                            </td>
                            <td>
                                <span class="adm-badge <?= View::e($stMeta['class']) ?>">
                                    <?= View::e($stMeta['text']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="adm-badge <?= View::e($inspMeta['class']) ?>">
                                    <?= View::e($inspMeta['text']) ?>
                                </span>
                                <?php if (!empty($u['latest_inspection_date'])): ?>
                                    <div class="adm-sub-meta">
                                        <?= View::e(date('d/m/Y H:i', strtotime((string) $u['latest_inspection_date']))) ?>
                                        (<?= View::e((string) ($u['inspections_count'] ?? 0)) ?> lần)
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sellable): ?>
                                    <span class="adm-badge adm-badge-active">Khả dụng</span>
                                <?php else: ?>
                                    <span class="adm-badge adm-badge-hidden">Chặn bán</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="adm-action-group">
                                    <a href="<?= View::e(View::url('/admin/serial/' . $uId)) ?>"
                                       class="adm-btn adm-btn-secondary adm-btn-sm">
                                        Chi tiết
                                    </a>
                                    <a href="<?= View::e(View::url('/admin/serial/' . $uId . '/sua')) ?>"
                                       class="adm-btn adm-btn-secondary adm-btn-sm">
                                        Sửa
                                    </a>
                                    <a href="<?= View::e(View::url('/admin/serial/' . $uId . '/kiem-dinh/them')) ?>"
                                       class="adm-btn adm-btn-primary adm-btn-sm">
                                        Kiểm định
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
        <div class="admin-pagination">
            <span>
                Trang <?= View::e((string) $pagination['current_page']) ?> / <?= View::e((string) $pagination['total_pages']) ?>
            </span>
            <div class="admin-pagination-links">
                <?php if (!empty($pagination['prev_url'])): ?>
                    <a href="<?= View::e((string) $pagination['prev_url']) ?>" class="adm-page-link">← Trước</a>
                <?php endif; ?>
                <?php foreach ($pagination['pages'] ?? [] as $pItem): ?>
                    <a href="<?= View::e((string) $pItem['url']) ?>"
                       class="adm-page-link <?= !empty($pItem['is_current']) ? 'is-active' : '' ?>">
                        <?= View::e((string) $pItem['number']) ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!empty($pagination['next_url'])): ?>
                    <a href="<?= View::e((string) $pagination['next_url']) ?>" class="adm-page-link">Sau →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
