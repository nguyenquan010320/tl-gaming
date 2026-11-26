<?php
/**
 * Component Thẻ Sản Phẩm thống nhất toàn website (`app/Views/partials/product_card.php`) — TL Gaming.
 *
 * Vai trò:
 * - Chuẩn hóa 100% bố cục Product Card cho Trang chủ (Nổi bật, PC Gaming, Laptop Gaming, Linh kiện & Màn hình),
 *   Trang Danh sách sản phẩm (`/san-pham`), Kết quả tìm kiếm và Sản phẩm tương tự (`/san-pham/{slug}`).
 * - Thứ tự chuẩn 8 thành phần: Ảnh (aspect-ratio 16:11) -> Badge -> Tên (2 dòng) -> 3 thông số kỹ thuật chính ->
 *   Giá bán (đỏ) & Giá cũ (gạch ngang) -> Tồn kho & Bảo hành -> Nút hành động căn thẳng đáy card bằng Flexbox.
 * - Chỉ sử dụng dữ liệu thực tế từ MySQL, tuyệt đối không bịa thông số hay đánh giá giả; thoát ký tự XSS qua `View::e()`.
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<string, mixed> $cardProduct Dữ liệu sản phẩm từ ProductModel */
/** @var string $cardCtaLabel Nhãn nút hành động chính (mặc định: "Xem chi tiết") */
/** @var string $cardHeadingTag Thẻ tiêu đề ('h2' hoặc 'h3', mặc định: 'h3') */
/** @var bool $cardShowSpecs Có hiển thị danh sách thông số chính hay không (mặc định: true) */

$prod            = $cardProduct ?? [];
$headingTag      = (isset($cardHeadingTag) && in_array($cardHeadingTag, ['h2', 'h3'], true)) ? $cardHeadingTag : 'h3';
$showSpecs       = isset($cardShowSpecs) ? (bool) $cardShowSpecs : true;
$ctaLabel        = isset($cardCtaLabel) && trim((string) $cardCtaLabel) !== '' ? (string) $cardCtaLabel : 'Xem chi tiết';
$fallbackImgUrl  = View::url('/assets/images/placeholder-product.svg');

$isInStock        = !empty($prod['is_in_stock']);
$hasValidDiscount = !empty($prod['has_valid_discount']);
$discountPercent  = (int) ($prod['discount_percent'] ?? 0);
$hasInspection    = !empty($prod['has_verified_inspection']);
$hasSerials       = ((int) ($prod['has_serials'] ?? 1)) === 1;
$cardCategorySlug     = (string) ($prod['category_slug'] ?? '');
$categoryName     = (string) ($prod['category_name'] ?? 'Sản phẩm');
$productName      = (string) ($prod['name'] ?? '');
$productUrl       = View::url('/san-pham/' . rawurlencode((string) ($prod['slug'] ?? '')));
$thumbUrl         = View::imageUrl((string) ($prod['thumbnail'] ?? ''));
$price            = (int) ($prod['price'] ?? 0);
$originalPrice    = !empty($prod['original_price']) ? (int) $prod['original_price'] : null;
$stockQty         = max(0, (int) ($prod['stock_quantity'] ?? 0));
$warrantyMonths   = max(0, (int) ($prod['warranty_months'] ?? 6));
$rawSpecs         = is_array($prod['specs'] ?? null) ? $prod['specs'] : [];

// Chọn lọc tối đa 3 thông số quan trọng nhất (ưu tiên CPU, VGA/GPU, RAM, SSD, Màn hình) từ dữ liệu thật
$displaySpecs = [];
if ($showSpecs && $rawSpecs !== []) {
    $priorityOrder = ['cpu', 'vga', 'gpu', 'card', 'ram', 'ssd', 'ổ cứng', 'màn hình', 'tần số', 'chip', 'main'];
    $scored = [];
    foreach ($rawSpecs as $idx => $sp) {
        $sName  = trim((string) ($sp['spec_name'] ?? ''));
        $sVal   = trim((string) ($sp['spec_value'] ?? ''));
        if ($sName === '' || $sVal === '') {
            continue;
        }
        $lowerName = mb_strtolower($sName, 'UTF-8');
        $score = 100 + $idx;
        foreach ($priorityOrder as $pIdx => $kw) {
            if (str_contains($lowerName, $kw)) {
                $score = $pIdx;
                break;
            }
        }
        $scored[] = ['score' => $score, 'name' => $sName, 'val' => $sVal];
    }
    usort($scored, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
    $displaySpecs = array_slice($scored, 0, 3);
}
?>
<article class="card product-card tlg-product-card" data-category-slug="<?= View::e($cardCategorySlug) ?>">
    <!-- 1 & 2. Khung ảnh chuẩn tỷ lệ 16:11 + Badge giảm giá / kiểm định -->
    <a href="<?= View::e($productUrl) ?>" class="tlg-card-thumb" aria-label="Xem chi tiết <?= View::e($productName) ?>">
        <img src="<?= View::e($thumbUrl) ?>"
             data-fallback-src="<?= View::e($fallbackImgUrl) ?>"
             alt="<?= View::e($productName) ?>"
             class="product-thumb-img tlg-card-img"
             loading="lazy">
        <div class="tlg-card-badges">
            <?php if ($hasValidDiscount && $discountPercent > 0): ?>
                <span class="tlg-badge tlg-badge-discount">-<?= View::e((string) $discountPercent) ?>%</span>
            <?php else: ?>
                <span></span>
            <?php endif; ?>

            <?php if (!$isInStock): ?>
                <span class="tlg-badge tlg-badge-soldout">Hết hàng</span>
            <?php elseif ($hasInspection): ?>
                <span class="tlg-badge tlg-badge-verified" title="Đã hoàn tất biên bản kiểm định phần cứng 18 bước">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>Kiểm định 18B</span>
                </span>
            <?php elseif ($hasSerials): ?>
                <span class="tlg-badge tlg-badge-neutral">Theo Serial</span>
            <?php else: ?>
                <span class="tlg-badge tlg-badge-neutral">BH <?= View::e((string) $warrantyMonths) ?>T</span>
            <?php endif; ?>
        </div>
    </a>

    <!-- 3 & 4. Thân thẻ: Danh mục, Tên sản phẩm (tối đa 2 dòng), Tối đa 3 thông số thực tế -->
    <div class="tlg-card-body">
        <span class="tlg-card-category"><?= View::e($categoryName) ?></span>

        <<?= $headingTag ?> class="tlg-card-title">
            <a href="<?= View::e($productUrl) ?>" title="<?= View::e($productName) ?>">
                <?= View::e($productName) ?>
            </a>
        </<?= $headingTag ?>>

        <?php if ($displaySpecs !== []): ?>
            <ul class="tlg-card-specs" aria-label="Thông số chính">
                <?php foreach ($displaySpecs as $spItem): ?>
                    <li class="tlg-spec-line">
                        <span class="tlg-spec-k"><?= View::e($spItem['name']) ?>:</span>
                        <span class="tlg-spec-v"><?= View::e($spItem['val']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php elseif (!empty($prod['condition_overview'])): ?>
            <p class="tlg-card-note"><?= View::e((string) $prod['condition_overview']) ?></p>
        <?php endif; ?>
    </div>

    <!-- 5, 6 & 7. Chân thẻ căn đáy: Giá đỏ + Giá cũ gạch ngang + Tồn kho/Bảo hành + Nút hành động -->
    <div class="tlg-card-footer">
        <div class="tlg-card-price-row">
            <span class="tlg-price-current"><?= View::e(View::formatVnd($price)) ?></span>
            <?php if ($hasValidDiscount && $originalPrice !== null): ?>
                <span class="tlg-price-old"><?= View::e(View::formatVnd($originalPrice)) ?></span>
            <?php endif; ?>
        </div>

        <div class="tlg-card-meta-row">
            <span class="tlg-meta-pill tlg-meta-warranty">BH <?= View::e((string) $warrantyMonths) ?> tháng</span>
            <span class="tlg-meta-pill <?= $isInStock ? 'tlg-meta-instock' : 'tlg-meta-outstock' ?>">
                <?= $isInStock ? ('Còn ' . View::e((string) $stockQty) . ' SP') : 'Tạm hết' ?>
            </span>
        </div>

        <?php if ($isInStock): ?>
            <a href="<?= View::e($productUrl) ?>" class="btn btn-primary tlg-card-cta">
                <?= View::e($ctaLabel) ?> <span aria-hidden="true">→</span>
            </a>
        <?php else: ?>
            <a href="<?= View::e($productUrl) ?>" class="btn btn-outline tlg-card-cta tlg-card-cta-muted">
                Xem thông tin
            </a>
        <?php endif; ?>
    </div>
</article>
