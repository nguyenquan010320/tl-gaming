<?php
/**
 * Giao diện Danh sách Sản phẩm Quản trị (`app/Views/admin/products/index.php`).
 *
 * Vai trò:
 * - Hiển thị bảng sản phẩm theo thiết kế Google Stitch Admin (ảnh đại diện, ID/SKU, tên, danh mục,
 *   giá bán/giá gốc, loại quản lý tồn kho, số lượng khả dụng, trạng thái, ngày tạo/cập nhật).
 * - Hỗ trợ tìm kiếm (tên, SKU), lọc danh mục, trạng thái, loại tồn kho, sắp xếp whitelist và phân trang server.
 * - Mọi thao tác Ẩn/Hiện dùng form POST + CSRF token.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

/** @var array<int, array<string, mixed>> $products */
/** @var array<int, array<string, mixed>> $categories */
/** @var int $totalItems */
/** @var array<string, mixed> $pagination */
/** @var array<string, string> $filters */

$statusLabels = [
    'published'    => ['text' => 'Đang bán', 'class' => 'adm-badge-active'],
    'draft'        => ['text' => 'Bản nháp', 'class' => 'adm-badge-hidden'],
    'out_of_stock' => ['text' => 'Hết hàng', 'class' => 'adm-badge-warn'],
    'archived'     => ['text' => 'Đang ẩn', 'class' => 'adm-badge-hidden'],
];
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Quản lý sản phẩm</h1>
        <p class="admin-page-subtitle">
            Tổng cộng <strong><?= View::e((string) $totalItems) ?></strong> sản phẩm khớp bộ lọc.
            Phân tách rõ hàng quản lý theo Serial và hàng theo số lượng.
        </p>
    </div>
    <a href="<?= View::e(View::url('/admin/san-pham/them')) ?>" class="adm-btn adm-btn-primary">
        + Thêm sản phẩm
    </a>
</div>

<div class="admin-card">
    <form class="admin-filter-bar" method="GET" action="<?= View::e(View::url('/admin/san-pham')) ?>">
        <div class="adm-field">
            <label for="prodSearch">Tìm kiếm (Tên / SKU)</label>
            <input type="text"
                   id="prodSearch"
                   name="q"
                   class="adm-input"
                   value="<?= View::e($filters['q'] ?? '') ?>"
                   placeholder="Nhập tên sản phẩm hoặc mã SKU...">
        </div>

        <div class="adm-field">
            <label for="prodCatFilter">Danh mục</label>
            <select id="prodCatFilter" name="danh-muc" class="adm-select">
                <option value="">Tất cả danh mục</option>
                <?php foreach ($categories as $cat): ?>
                    <?php
                    $cId    = (string) $cat['id'];
                    $depth  = max(1, (int) ($cat['depth'] ?? 1));
                    $indent = $depth > 1 ? str_repeat('— ', $depth - 1) : '';
                    ?>
                    <option value="<?= View::e($cId) ?>" <?= ($filters['danh-muc'] ?? '') === $cId ? 'selected' : '' ?>>
                        <?= View::e($indent . (string) $cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="adm-field">
            <label for="prodStatusFilter">Trạng thái</label>
            <select id="prodStatusFilter" name="trang-thai" class="adm-select">
                <option value="">Tất cả trạng thái</option>
                <option value="published" <?= ($filters['trang-thai'] ?? '') === 'published' ? 'selected' : '' ?>>Đang bán (published)</option>
                <option value="draft" <?= ($filters['trang-thai'] ?? '') === 'draft' ? 'selected' : '' ?>>Bản nháp (draft)</option>
                <option value="out_of_stock" <?= ($filters['trang-thai'] ?? '') === 'out_of_stock' ? 'selected' : '' ?>>Hết hàng (out_of_stock)</option>
                <option value="archived" <?= ($filters['trang-thai'] ?? '') === 'archived' ? 'selected' : '' ?>>Đang ẩn (archived)</option>
            </select>
        </div>

        <div class="adm-field">
            <label for="prodStockType">Loại tồn kho</label>
            <select id="prodStockType" name="loai-kho" class="adm-select">
                <option value="">Tất cả loại kho</option>
                <option value="serial" <?= ($filters['loai-kho'] ?? '') === 'serial' ? 'selected' : '' ?>>Theo Serial</option>
                <option value="quantity" <?= ($filters['loai-kho'] ?? '') === 'quantity' ? 'selected' : '' ?>>Theo Số lượng</option>
            </select>
        </div>

        <div class="adm-field">
            <label for="prodSort">Sắp xếp</label>
            <select id="prodSort" name="sap-xep" class="adm-select">
                <option value="moi-nhat" <?= ($filters['sap-xep'] ?? 'moi-nhat') === 'moi-nhat' ? 'selected' : '' ?>>Mới nhất</option>
                <option value="cap-nhat" <?= ($filters['sap-xep'] ?? '') === 'cap-nhat' ? 'selected' : '' ?>>Cập nhật gần nhất</option>
                <option value="gia-tang" <?= ($filters['sap-xep'] ?? '') === 'gia-tang' ? 'selected' : '' ?>>Giá bán tăng dần</option>
                <option value="gia-giam" <?= ($filters['sap-xep'] ?? '') === 'gia-giam' ? 'selected' : '' ?>>Giá bán giảm dần</option>
                <option value="ten-az" <?= ($filters['sap-xep'] ?? '') === 'ten-az' ? 'selected' : '' ?>>Tên A-Z</option>
                <option value="cu-nhat" <?= ($filters['sap-xep'] ?? '') === 'cu-nhat' ? 'selected' : '' ?>>Cũ nhất</option>
            </select>
        </div>

        <div class="adm-filter-actions">
            <button type="submit" class="adm-btn adm-btn-primary">Lọc</button>
            <a href="<?= View::e(View::url('/admin/san-pham')) ?>" class="adm-btn adm-btn-secondary">Đặt lại</a>
        </div>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Ảnh</th>
                    <th>ID / SKU</th>
                    <th>Tên sản phẩm</th>
                    <th>Danh mục</th>
                    <th>Giá bán / Giá gốc</th>
                    <th>Loại tồn kho</th>
                    <th>Khả dụng</th>
                    <th>Trạng thái</th>
                    <th>Ngày tạo / Cập nhật</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($products === []): ?>
                    <tr>
                        <td colspan="10" class="admin-empty-cell">
                            Không tìm thấy sản phẩm nào phù hợp với điều kiện lọc hiện tại.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $prod): ?>
                        <?php
                        $pId         = (int) $prod['id'];
                        $hasSerials  = ((int) ($prod['has_serials'] ?? 1)) === 1;
                        $availStock  = $hasSerials
                            ? (int) ($prod['available_serial_count'] ?? 0)
                            : (int) ($prod['stock_quantity'] ?? 0);
                        $statusKey   = (string) ($prod['status'] ?? 'draft');
                        $statusMeta  = $statusLabels[$statusKey] ?? ['text' => $statusKey, 'class' => 'adm-badge-hidden'];
                        $thumbUrl    = View::imageUrl((string) ($prod['thumbnail'] ?? ''));
                        $origPrice   = $prod['original_price'] !== null ? (int) $prod['original_price'] : null;
                        $catHidden   = ((int) ($prod['category_is_active'] ?? 1)) !== 1;
                        ?>
                        <tr>
                            <td>
                                <img src="<?= View::e($thumbUrl) ?>"
                                     alt="<?= View::e((string) $prod['name']) ?>"
                                     class="adm-prod-thumb">
                            </td>
                            <td>
                                <strong>#<?= $pId ?></strong><br>
                                <code class="adm-slug-code"><?= View::e((string) $prod['sku']) ?></code>
                            </td>
                            <td>
                                <div class="adm-cat-name"><?= View::e((string) $prod['name']) ?></div>
                                <div class="adm-prod-meta-sub">
                                    BH: <?= View::e((string) ($prod['warranty_months'] ?? 0)) ?>T · <?= View::e((string) ($prod['condition_overview'] ?? '')) ?>
                                </div>
                            </td>
                            <td>
                                <span><?= View::e((string) ($prod['category_name'] ?? '—')) ?></span>
                                <?php if ($catHidden): ?>
                                    <br><span class="adm-badge adm-badge-hidden" style="margin-top:4px;">Danh mục ẩn</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong class="adm-price-main"><?= View::e(View::formatVnd((int) $prod['price'])) ?></strong>
                                <?php if ($origPrice !== null && $origPrice > (int) $prod['price']): ?>
                                    <br><span class="adm-price-orig"><?= View::e(View::formatVnd($origPrice)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasSerials): ?>
                                    <span class="adm-badge adm-badge-serial">Theo Serial</span>
                                <?php else: ?>
                                    <span class="adm-badge adm-badge-qty">Số lượng</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= View::e((string) $availStock) ?></strong>
                                <?php if ($hasSerials): ?>
                                    <span class="adm-prod-meta-sub" style="display:block;">
                                        Tổng <?= View::e((string) ($prod['total_units_count'] ?? 0)) ?> máy
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="adm-badge <?= View::e($statusMeta['class']) ?>">
                                    <?= View::e($statusMeta['text']) ?>
                                </span>
                            </td>
                            <td class="adm-date-cell">
                                <div>Tạo: <?= View::e(date('d/m/Y', strtotime((string) $prod['created_at']))) ?></div>
                                <div>Sửa: <?= View::e(date('d/m/Y H:i', strtotime((string) $prod['updated_at']))) ?></div>
                            </td>
                            <td>
                                <div class="adm-action-group">
                                    <?php if ($hasSerials): ?>
                                        <a href="<?= View::e(View::url("/admin/serial?san-pham={$pId}")) ?>"
                                           class="adm-btn adm-btn-secondary adm-btn-sm">
                                            Serial
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= View::e(View::url("/admin/san-pham/{$pId}/sua")) ?>"
                                       class="adm-btn adm-btn-secondary adm-btn-sm">
                                        Sửa
                                    </a>

                                    <?php if ($statusKey !== 'archived'): ?>
                                        <form method="POST"
                                              action="<?= View::e(View::url("/admin/san-pham/{$pId}/an")) ?>"
                                              class="adm-inline-form">
                                            <input type="hidden" name="_csrf_token" value="<?= View::e(Csrf::token()) ?>">
                                            <button type="submit"
                                                    class="adm-btn adm-btn-danger adm-btn-sm"
                                                    onclick="return confirm('Ẩn sản phẩm này khỏi trang cửa hàng công khai?');">
                                                Ẩn
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST"
                                              action="<?= View::e(View::url("/admin/san-pham/{$pId}/hien")) ?>"
                                              class="adm-inline-form">
                                            <input type="hidden" name="_csrf_token" value="<?= View::e(Csrf::token()) ?>">
                                            <button type="submit" class="adm-btn adm-btn-success adm-btn-sm">
                                                Hiện
                                            </button>
                                        </form>
                                    <?php endif; ?>
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
                    <a href="<?= View::e(View::url((string) $pagination['prev_url'])) ?>" class="adm-page-link">← Trước</a>
                <?php endif; ?>
                <?php foreach ($pagination['pages'] ?? [] as $pItem): ?>
                    <a href="<?= View::e(View::url((string) $pItem['url'])) ?>"
                       class="adm-page-link <?= !empty($pItem['active']) ? 'is-active' : '' ?>">
                        <?= View::e((string) $pItem['page']) ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!empty($pagination['next_url'])): ?>
                    <a href="<?= View::e(View::url((string) $pagination['next_url'])) ?>" class="adm-page-link">Sau →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
