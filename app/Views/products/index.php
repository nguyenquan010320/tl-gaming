<?php
/**
 * Giao diện Trang Danh sách Sản phẩm hoàn chỉnh (`app/Views/products/index.php`) — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Chuyển đổi chính xác theo bản thiết kế chính thức Google Stitch (`danh_s_ch_s_n_ph_m_tl_gaming`).
 * - Chỉ nhận dữ liệu đã được `ProductController::index()` kiểm tra, chuẩn hóa và truy vấn từ `ProductModel` / `CategoryModel`.
 * - Tuyệt đối không thực hiện câu lệnh SQL trực tiếp trong View.
 * - Mọi chuỗi văn bản hiển thị ra HTML đều đi qua `View::e()` (`htmlspecialchars`) để chống tấn công XSS.
 * - Giữ nguyên toàn bộ chức năng tìm kiếm (`q`), lọc danh mục (`danh-muc`), khoảng giá (`gia-tu`, `gia-den`),
 *   trạng thái tồn kho (`ton-kho`), sắp xếp (`sap-xep`) và phân trang (`page`).
 */

declare(strict_types=1);

use App\Core\View;

/** @var string $pageHeading */
/** @var array<int, array<string, mixed>> $categories */
/** @var array<int, array<string, mixed>> $products */
/** @var array<string, mixed>|null $activeCategory */
/** @var array<string, mixed>|null $parentCategory */
/** @var string $searchQuery */
/** @var string $categorySlug */
/** @var int|null $minPrice */
/** @var int|null $maxPrice */
/** @var string $stockStatus */
/** @var array<string, string|null> $filterParams */
/** @var array<string, mixed> $pagination */
/** @var bool $hasActiveFilters */
/** @var bool $dbConnected */
/** @var bool $productQueryError */

$totalItems  = (int) ($pagination['total_items'] ?? 0);
$totalPages  = (int) ($pagination['total_pages'] ?? 1);

$filterUrl = static function (array $changes) use ($filterParams): string {
    $query = http_build_query(array_replace($filterParams, $changes), '', '&', PHP_QUERY_RFC3986);
    return View::url('/san-pham' . ($query !== '' ? '?' . $query : ''));
};

$priceRanges = [
    ['Tất cả', null, null],
    ['≤ 5 triệu', null, 5000000],
    ['5 – 10 triệu', 5000000, 10000000],
    ['10 – 15 triệu', 10000000, 15000000],
    ['15 – 20 triệu', 15000000, 20000000],
    ['≥ 20 triệu', 20000000, null],
];

// Giữ thứ tự danh mục của quản trị và hiển thị cả danh mục cấp 3.
$categoriesByParent = [];
foreach ($categories as $cat) {
    $categoriesByParent[(int) ($cat['parent_id'] ?? 0)][] = $cat;
}
$renderCategoryBranch = static function (int $parentId) use (&$renderCategoryBranch, $categoriesByParent, $filterUrl, $categorySlug): void {
    foreach ($categoriesByParent[$parentId] ?? [] as $category):
        $id = (int) $category['id'];
        $slug = (string) $category['slug'];
        ?>
        <a href="<?= View::e($filterUrl(['danh-muc' => $slug])) ?>"
           class="filter-option"<?= $categorySlug === strtolower($slug) ? ' aria-current="true"' : '' ?>>
            <span><?= View::e((string) $category['name']) ?></span>
            <span class="filter-option-count"><?= (int) ($category['published_product_count'] ?? 0) ?></span>
        </a>
        <?php if (isset($categoriesByParent[$id])): ?>
            <div class="filter-option-list filter-subcategory-group">
                <?php $renderCategoryBranch($id); ?>
            </div>
        <?php endif; ?>
    <?php endforeach;
};
?>

<!-- =======================================================================
     1. BREADCRUMB & BANNER TIÊU ĐỀ DANH MỤC CHUẨN GOOGLE STITCH
     ======================================================================= -->
<section class="page-banner catalog-banner">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                <li class="breadcrumb-item">
                    <a href="<?= View::e(View::url('/')) ?>">Trang chủ</a>
                </li>
                <li class="breadcrumb-item">
                    <?php if ($activeCategory !== null || $searchQuery !== '' || $categorySlug !== ''): ?>
                        <a href="<?= View::e(View::url('/san-pham')) ?>">Danh mục sản phẩm</a>
                    <?php else: ?>
                        <span aria-current="page">Danh mục sản phẩm</span>
                    <?php endif; ?>
                </li>
                <?php if ($parentCategory !== null): ?>
                    <li class="breadcrumb-item">
                        <a href="<?= View::e(View::url('/san-pham?danh-muc=' . rawurlencode((string) $parentCategory['slug']))) ?>">
                            <?= View::e((string) $parentCategory['name']) ?>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if ($activeCategory !== null): ?>
                    <li class="breadcrumb-item">
                        <span aria-current="page"><?= View::e((string) $activeCategory['name']) ?></span>
                    </li>
                <?php elseif ($categorySlug !== ''): ?>
                    <li class="breadcrumb-item">
                        <span aria-current="page">Danh mục: <?= View::e($categorySlug) ?></span>
                    </li>
                <?php endif; ?>
                <?php if ($searchQuery !== ''): ?>
                    <li class="breadcrumb-item">
                        <span aria-current="page">Tìm kiếm: &ldquo;<?= View::e($searchQuery) ?>&rdquo;</span>
                    </li>
                <?php endif; ?>
            </ol>
        </nav>

        <div class="store-catalog-heading">
            <div>
                <h1 class="page-title"><?= View::e($pageHeading ?? 'Danh sách sản phẩm') ?></h1>
                <?php if ($activeCategory !== null && !empty($activeCategory['description'])): ?>
                    <p class="page-subtitle"><?= View::e((string) $activeCategory['description']) ?></p>
                <?php endif; ?>
            </div>
            <span class="store-results-count"><strong><?= $totalItems ?></strong> sản phẩm</span>
        </div>
    </div>
</section>

<!-- =======================================================================
     2. BỐ CỤC 12 CỘT CHUẨN STITCH: SIDEBAR BỘ LỌC (3 CỘT) & DANH SÁCH (9 CỘT)
     ======================================================================= -->
<section class="section-block catalog-section">
    <div class="container">
        <div class="catalog-layout">
            <!-- CỘT TRÁI: SIDEBAR BỘ LỌC CHUYÊN SÂU (NỀN TRẮNG STICKY) -->
            <aside class="catalog-sidebar" id="catalogFilterSidebar" aria-label="Bộ lọc sản phẩm">
                <div class="sidebar-mobile-header">
                    <h2 class="sidebar-heading">Lọc sản phẩm</h2>
                    <button type="button"
                            class="sidebar-close-btn"
                            id="mobileFilterCloseBtn"
                            aria-label="Đóng bộ lọc">
                        ✕ Đóng
                    </button>
                </div>

                <div class="filter-group" role="group" aria-labelledby="filterCategoryTitle">
                    <h3 class="filter-group-title" id="filterCategoryTitle">Danh mục sản phẩm</h3>
                    <div class="filter-option-list">
                        <a href="<?= View::e($filterUrl(['danh-muc' => null])) ?>"
                           class="filter-option"<?= $categorySlug === '' ? ' aria-current="true"' : '' ?>>
                            Tất cả danh mục
                        </a>

                        <?php $renderCategoryBranch(0); ?>
                    </div>
                </div>

                <div class="filter-group" role="group" aria-labelledby="filterPriceTitle">
                    <h3 class="filter-group-title" id="filterPriceTitle">Khoảng giá (VND)</h3>
                    <div class="quick-price-chips">
                        <?php foreach ($priceRanges as [$label, $from, $to]): ?>
                            <a href="<?= View::e($filterUrl(['gia-tu' => $from, 'gia-den' => $to])) ?>"
                               class="quick-price-link"<?= $minPrice === $from && $maxPrice === $to ? ' aria-current="true"' : '' ?>>
                                <?= View::e($label) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="filter-group" role="group" aria-labelledby="filterStockTitle">
                    <h3 class="filter-group-title" id="filterStockTitle">Tình trạng kho hàng</h3>
                    <div class="filter-option-list">
                        <a href="<?= View::e($filterUrl(['ton-kho' => null])) ?>"
                           class="filter-option"<?= $stockStatus === 'all' ? ' aria-current="true"' : '' ?>>
                            Tất cả sản phẩm
                        </a>
                        <a href="<?= View::e($filterUrl(['ton-kho' => 'in_stock'])) ?>"
                           class="filter-option"<?= $stockStatus === 'in_stock' ? ' aria-current="true"' : '' ?>>
                            Còn hàng sẵn giao ngay
                        </a>
                    </div>
                </div>
            </aside>

            <!-- CỘT PHẢI: LƯỚI SẢN PHẨM & PHÂN TRANG (ĐÃ LOẠI BỎ KHỐI THỐNG KÊ & SẮP XẾP THỪA) -->
            <div class="catalog-main">
                <!-- Nút mở bộ lọc dành riêng cho Tablet & Mobile (ẩn trên Desktop) -->
                <button type="button"
                        class="btn btn-outline btn-sm mobile-filter-trigger"
                        id="mobileFilterToggleBtn"
                        aria-controls="catalogFilterSidebar"
                        aria-expanded="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                    </svg>
                    <span>Lọc sản phẩm</span>
                </button>

                <!-- Thanh hiển thị các điều kiện lọc đang áp dụng -->
                <?php if ($hasActiveFilters): ?>
                    <div class="active-filters-bar">
                        <span class="active-filter-heading">Đang lọc theo:</span>
                        <?php if ($searchQuery !== ''): ?>
                            <a href="<?= View::e($filterUrl(['q' => null])) ?>" class="filter-chip filter-chip-remove"
                               aria-label="Bỏ lọc theo từ khóa <?= View::e($searchQuery) ?>">
                                Từ khóa: &ldquo;<?= View::e($searchQuery) ?>&rdquo; <span aria-hidden="true">×</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($activeCategory !== null): ?>
                            <span class="filter-chip">Danh mục: <?= View::e((string) $activeCategory['name']) ?></span>
                        <?php elseif ($categorySlug !== ''): ?>
                            <span class="filter-chip">Danh mục không tồn tại: <?= View::e($categorySlug) ?></span>
                        <?php endif; ?>
                        <?php if ($minPrice !== null || $maxPrice !== null): ?>
                            <span class="filter-chip">
                                Giá:
                                <?= $minPrice !== null ? View::e(View::formatVnd($minPrice)) : '0 ₫' ?>
                                &rarr;
                                <?= $maxPrice !== null ? View::e(View::formatVnd($maxPrice)) : 'Trở lên' ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($stockStatus === 'in_stock'): ?>
                            <span class="filter-chip">Trạng thái: Chỉ còn hàng</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Lưới hiển thị sản phẩm thống nhất toàn hệ thống (Tối đa 12 sản phẩm mỗi trang) -->
                <?php if (!empty($products)): ?>
                    <div class="product-grid catalog-product-grid tlg-unified-grid">
                        <?php foreach ($products as $prod): ?>
                            <?php
                            $cardProduct    = $prod;
                            $cardHeadingTag = 'h2';
                            $cardCtaLabel   = 'Xem chi tiết';
                            $cardShowSpecs  = true;
                            require dirname(__DIR__) . '/partials/product_card.php';
                            ?>
                        <?php endforeach; ?>
                    </div>

                    <!-- 3. THANH PHÂN TRANG (PAGINATION) -->
                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination-nav" aria-label="Phân trang danh sách sản phẩm">
                            <ul class="pagination-list">
                                <!-- Nút Trang trước -->
                                <li class="pagination-item">
                                    <?php if (!empty($pagination['has_prev']) && !empty($pagination['prev_url'])): ?>
                                        <a href="<?= View::e((string) $pagination['prev_url']) ?>" class="pagination-link" rel="prev">
                                            &laquo; Trước
                                        </a>
                                    <?php else: ?>
                                        <span class="pagination-link is-disabled" aria-disabled="true">&laquo; Trước</span>
                                    <?php endif; ?>
                                </li>

                                <!-- Danh sách các số trang -->
                                <?php foreach (($pagination['pages'] ?? []) as $pItem): ?>
                                    <li class="pagination-item">
                                        <?php if (!empty($pItem['is_current'])): ?>
                                            <span class="pagination-link is-active" aria-current="page">
                                                <?= View::e((string) $pItem['number']) ?>
                                            </span>
                                        <?php else: ?>
                                            <a href="<?= View::e((string) $pItem['url']) ?>" class="pagination-link">
                                                <?= View::e((string) $pItem['number']) ?>
                                            </a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>

                                <!-- Nút Trang sau -->
                                <li class="pagination-item">
                                    <?php if (!empty($pagination['has_next']) && !empty($pagination['next_url'])): ?>
                                        <a href="<?= View::e((string) $pagination['next_url']) ?>" class="pagination-link" rel="next">
                                            Sau &raquo;
                                        </a>
                                    <?php else: ?>
                                        <span class="pagination-link is-disabled" aria-disabled="true">Sau &raquo;</span>
                                    <?php endif; ?>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>

                <?php elseif (empty($dbConnected) || !empty($productQueryError)): ?>
                    <div class="empty-state">
                        <h3 class="empty-state-title">Tạm thời chưa thể tải danh sách sản phẩm</h3>
                        <p>Hệ thống đang đồng bộ dữ liệu kỹ thuật. Quý khách vui lòng thử lại sau ít phút.</p>
                        <div class="empty-state-actions">
                            <a href="<?= View::e(View::url('/san-pham')) ?>" class="btn btn-primary">Thử tải lại trang</a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Thông báo khi không tìm thấy sản phẩm nào khớp từ khóa / bộ lọc -->
                    <div class="empty-state">
                        <h3 class="empty-state-title">Không tìm thấy sản phẩm nào phù hợp</h3>
                        <p>
                            Rất tiếc, hiện không có sản phẩm đang mở bán nào khớp với
                            <?php if ($searchQuery !== ''): ?>
                                từ khóa <strong>&ldquo;<?= View::e($searchQuery) ?>&rdquo;</strong>
                            <?php else: ?>
                                bộ lọc bạn đã chọn
                            <?php endif; ?>.
                            Hãy thử chọn danh mục, khoảng giá hoặc tình trạng kho khác.
                        </p>
                        <div class="empty-state-actions">
                            <a href="<?= View::e(View::url('/san-pham')) ?>" class="btn btn-primary">
                                ← Quay lại danh sách tất cả sản phẩm
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</section>
