<?php
/**
 * Giao diện Danh sách Quản lý Danh mục (`app/Views/admin/categories/index.php`).
 *
 * Vai trò trong kiến trúc MVC:
 * - Hiển thị bảng danh mục theo cấu trúc cây cha/con: ID, Tên + Slug, Danh mục cha,
 *   Số sản phẩm, Trạng thái (Hiển thị / Ẩn) và Thao tác (Sửa, Ẩn/Hiện bằng POST + CSRF).
 * - Hỗ trợ tìm kiếm theo từ khóa, lọc trạng thái và phân trang.
 * - Mọi dữ liệu động đi qua `View::e()` chống XSS; không có SQL trong View.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

/** @var array<int, array<string, mixed>> $categories */
/** @var int $totalItems */
/** @var string $keyword */
/** @var string $statusParam */
/** @var array<string, mixed> $pagination */
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Quản lý danh mục</h1>
        <p class="admin-page-subtitle">
            Quản lý cây danh mục sản phẩm tối đa <?= \App\Models\CategoryModel::MAX_DEPTH ?> cấp. Ưu tiên Ẩn danh mục thay vì xóa cứng để bảo toàn lịch sử sản phẩm.
        </p>
    </div>
    <a href="<?= View::e(View::url('/admin/danh-muc/them')) ?>" class="adm-btn adm-btn-primary">
        + Thêm danh mục
    </a>
</div>

<div class="admin-card">
    <!-- Thanh tìm kiếm & lọc trạng thái -->
    <div class="admin-toolbar">
        <form action="<?= View::e(View::url('/admin/danh-muc')) ?>" method="GET" class="admin-filter-form">
            <input type="text"
                   name="q"
                   class="adm-input adm-search-input"
                   value="<?= View::e($keyword) ?>"
                   placeholder="Tìm theo tên danh mục, slug hoặc danh mục cha...">
            <select name="trang-thai" class="adm-select" aria-label="Lọc theo trạng thái">
                <option value="" <?= $statusParam === '' ? 'selected' : '' ?>>Tất cả trạng thái</option>
                <option value="1" <?= $statusParam === '1' ? 'selected' : '' ?>>Đang hiển thị</option>
                <option value="0" <?= $statusParam === '0' ? 'selected' : '' ?>>Đang ẩn</option>
            </select>
            <button type="submit" class="adm-btn adm-btn-primary">Tìm kiếm</button>
            <?php if ($keyword !== '' || $statusParam !== ''): ?>
                <a href="<?= View::e(View::url('/admin/danh-muc')) ?>" class="adm-btn adm-btn-secondary">Xóa lọc</a>
            <?php endif; ?>
        </form>
        <div style="font-size: 13px; color: #64748b;">
            Tổng cộng: <strong><?= (int) $totalItems ?></strong> danh mục
        </div>
    </div>

    <!-- Bảng danh sách danh mục -->
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 68px;">ID</th>
                    <th>Tên danh mục &amp; Slug</th>
                    <th>Danh mục cha</th>
                    <th style="width: 120px;">Số sản phẩm</th>
                    <th style="width: 130px;">Trạng thái</th>
                    <th style="width: 185px;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($categories === []): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 32px; color: #64748b;">
                            Không tìm thấy danh mục nào phù hợp với điều kiện lọc.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <?php
                        $catId    = (int) $cat['id'];
                        $depth    = max(1, (int) ($cat['depth'] ?? 1));
                        $isActive = (int) ($cat['is_active'] ?? 0) === 1;
                        $directCnt = (int) ($cat['direct_product_count'] ?? 0);
                        $pubCnt    = (int) ($cat['published_product_count'] ?? 0);
                        $prefix    = $depth > 1 ? str_repeat('└─ ', $depth - 1) : '';
                        ?>
                        <tr>
                            <td><strong>#<?= $catId ?></strong></td>
                            <td>
                                <div class="cat-name-cell">
                                    <div class="cat-name-row">
                                        <?php if ($prefix !== ''): ?>
                                            <span class="cat-tree-prefix" aria-hidden="true"><?= View::e($prefix) ?></span>
                                        <?php endif; ?>
                                        <span><?= View::e((string) $cat['name']) ?></span>
                                    </div>
                                    <span class="cat-slug-meta">/<?= View::e((string) $cat['slug']) ?> · Thứ tự: <?= (int) ($cat['display_order'] ?? 0) ?></span>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($cat['parent_name'])): ?>
                                    <span><?= View::e((string) $cat['parent_name']) ?></span>
                                    <?php if ((int) ($cat['parent_is_active'] ?? 1) !== 1): ?>
                                        <span class="adm-badge adm-badge-hidden" style="margin-left: 4px;">Cha ẩn</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">Danh mục gốc</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= $directCnt ?></strong>
                                <span style="font-size: 12px; color: #64748b;">(<?= $pubCnt ?> đang bán)</span>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="adm-badge adm-badge-active">Đang hiển thị</span>
                                <?php else: ?>
                                    <span class="adm-badge adm-badge-hidden">Đang ẩn</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="adm-actions-cell">
                                    <a href="<?= View::e(View::url("/admin/danh-muc/{$catId}/sua")) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">
                                        Sửa
                                    </a>
                                    <?php if ($isActive): ?>
                                        <form action="<?= View::e(View::url("/admin/danh-muc/{$catId}/an")) ?>" method="POST" class="adm-inline-form">
                                            <input type="hidden" name="_csrf_token" value="<?= View::e(Csrf::token()) ?>">
                                            <button type="submit" class="adm-btn adm-btn-warn adm-btn-sm"
                                                    onclick="return confirm('Ẩn danh mục này sẽ đồng thời ẩn các danh mục con (nếu có) và ngừng hiển thị sản phẩm thuộc danh mục trên trang khách. Tiếp tục?');">
                                                Ẩn
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form action="<?= View::e(View::url("/admin/danh-muc/{$catId}/hien")) ?>" method="POST" class="adm-inline-form">
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

    <!-- Phân trang -->
    <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
        <div class="admin-pagination">
            <div>
                Hiển thị <?= (int) $pagination['from_item'] ?>–<?= (int) $pagination['to_item'] ?> / <?= (int) $pagination['total_items'] ?> danh mục
            </div>
            <div class="admin-page-links">
                <?php if (!empty($pagination['prev_url'])): ?>
                    <a href="<?= View::e(View::url((string) $pagination['prev_url'])) ?>" class="admin-page-link">« Trước</a>
                <?php endif; ?>
                <?php foreach ($pagination['pages'] as $pageItem): ?>
                    <a href="<?= View::e(View::url((string) $pageItem['url'])) ?>"
                       class="admin-page-link <?= !empty($pageItem['is_current']) ? 'is-current' : '' ?>">
                        <?= (int) $pageItem['number'] ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!empty($pagination['next_url'])): ?>
                    <a href="<?= View::e(View::url((string) $pagination['next_url'])) ?>" class="admin-page-link">Sau »</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
