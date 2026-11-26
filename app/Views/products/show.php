<?php
/**
 * Giao diện Trang Chi tiết Sản phẩm hoàn chỉnh (`app/Views/products/show.php`) — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Chuyển đổi chính xác theo bản thiết kế chính thức Google Stitch (`chi_ti_t_s_n_ph_m_tl_gaming`).
 * - Chỉ nhận dữ liệu đã được `ProductController::show()` truy vấn từ `ProductModel` và `CategoryModel`.
 * - Tuyệt đối không thực hiện truy vấn SQL trong View; mọi dữ liệu hiển thị đều đi qua `View::e()` (`htmlspecialchars`).
 * - Tuân thủ nghiêm ngặt quy tắc trung thực dữ liệu:
 *   + Không tự tạo thông số FPS giả lập hoặc quà tặng không có thật.
 *   + Tách biệt chính xác biên bản kiểm định (`product_inspections` / `inspection_items`) theo từng `product_units` (mã Serial).
 *   + Không tạo đánh giá khách hàng giả lập.
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<string, mixed> $product */
/** @var array<int, array{id: int, image_url: string, alt_text: string, is_primary: int}> $galleryImages */
/** @var array<int, array{id: int, group_name: string, spec_name: string, spec_value: string, sort_order: int}> $specs */
/** @var array<int, array<string, mixed>> $units */
/** @var array<int, array<string, mixed>> $similarProducts */

$storePage          = 'product-detail';
$productId          = (int) ($product['id'] ?? 0);
$productName        = (string) ($product['name'] ?? '');
$productSku         = (string) ($product['sku'] ?? '');
$categoryName       = (string) ($product['category_name'] ?? 'Danh mục');
$categorySlug       = (string) ($product['category_slug'] ?? '');
$parentCategoryName = (string) ($product['parent_category_name'] ?? '');
$parentCategorySlug = (string) ($product['parent_category_slug'] ?? '');
$price              = (int) ($product['price'] ?? 0);
$originalPrice      = isset($product['original_price']) && $product['original_price'] !== null
    ? (int) $product['original_price']
    : null;
$hasValidDiscount   = !empty($product['has_valid_discount']);
$discountPercent    = (int) ($product['discount_percent'] ?? 0);
$discountSaving     = (int) ($product['discount_saving'] ?? 0);
$warrantyMonths     = (int) ($product['warranty_months'] ?? 0);
$conditionOverview  = (string) ($product['condition_overview'] ?? '');
$hasSerials         = ((int) ($product['has_serials'] ?? 1)) === 1;
$stockQuantity      = max(0, (int) ($product['stock_quantity'] ?? 0));
$purchasableQty     = max(0, (int) ($product['purchasable_quantity'] ?? $stockQuantity));
$isInStock          = !empty($product['is_in_stock']) && $purchasableQty > 0;
$summaryText        = trim((string) ($product['summary'] ?? ''));
$descriptionText    = trim((string) ($product['description'] ?? ''));

// Đếm số cá thể máy (product_units) thực sự có biên bản kiểm định hợp lệ và số cá thể sẵn sàng bán
$inspectedUnitsCount = 0;
$availableUnitsCount = 0;
foreach ($units as $uItem) {
    if (!empty($uItem['has_valid_inspection'])) {
        $inspectedUnitsCount++;
    }
    if (($uItem['status'] ?? '') === 'available') {
        $availableUnitsCount++;
    }
}

// Chuẩn bị danh sách ảnh gallery đã qua View::imageUrl()
$resolvedGallery = [];
foreach ($galleryImages as $gImg) {
    $resolvedGallery[] = [
        'url' => View::imageUrl((string) ($gImg['image_url'] ?? '')),
        'alt' => (string) ($gImg['alt_text'] ?? $productName),
    ];
}
if ($resolvedGallery === []) {
    $resolvedGallery[] = [
        'url' => View::imageUrl(''),
        'alt' => $productName !== '' ? $productName : 'Hình ảnh sản phẩm TL Gaming',
    ];
}
$firstImage = $resolvedGallery[0];
?>

<!-- =======================================================================
     1. BREADCRUMB: TRANG CHỦ / SẢN PHẨM / DANH MỤC / TÊN SẢN PHẨM
     ======================================================================= -->
<section class="page-banner detail-breadcrumb-bar">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                <li class="breadcrumb-item">
                    <a href="<?= View::e(View::url('/')) ?>">Trang chủ</a>
                </li>
                <li class="breadcrumb-item">
                    <a href="<?= View::e(View::url('/san-pham')) ?>">Sản phẩm</a>
                </li>
                <?php if ($parentCategoryName !== '' && $parentCategorySlug !== ''): ?>
                    <li class="breadcrumb-item">
                        <a href="<?= View::e(View::url('/san-pham?danh-muc=' . rawurlencode($parentCategorySlug))) ?>">
                            <?= View::e($parentCategoryName) ?>
                        </a>
                    </li>
                <?php endif; ?>
                <li class="breadcrumb-item">
                    <a href="<?= View::e(View::url('/san-pham?danh-muc=' . rawurlencode($categorySlug))) ?>">
                        <?= View::e($categoryName) ?>
                    </a>
                </li>
                <li class="breadcrumb-item">
                    <span aria-current="page"><?= View::e($productName) ?></span>
                </li>
            </ol>
        </nav>
    </div>
</section>

<!-- =======================================================================
     2. KHỐI THÔNG TIN CHÍNH CHUẨN STITCH (THẺ TRẮNG 12 CỘT: TRÁI 5 CỘT GALLERY + PHẢI 7 CỘT MUA HÀNG)
     ======================================================================= -->
<section class="section-block detail-main-section">
    <div class="container">
        <div class="stitch-detail-main-card">
            <div class="product-detail-hero-grid">
                <!-- CỘT TRÁI (5 CỘT): GALLERY HÌNH ẢNH + THUMBNAIL + HỘP CAM KẾT KIỂM ĐỊNH -->
                <div class="product-gallery-col">
                    <div class="main-image-stage" id="mainImageStage">
                        <div class="main-image-frame">
                            <img src="<?= View::e($firstImage['url']) ?>"
                                 alt="<?= View::e($firstImage['alt']) ?>"
                                 id="mainProductImage"
                                 class="main-product-image"
                                 data-current-index="0">

                            <div class="product-badges-overlay">
                                <div class="stitch-gallery-badges-col">
                                    <?php if ($hasValidDiscount && $discountPercent > 0): ?>
                                        <span class="badge-discount">GIẢM -<?= View::e((string) $discountPercent) ?>%</span>
                                    <?php endif; ?>
                                    <span class="badge-cyan-warranty">BẢO HÀNH <?= View::e((string) $warrantyMonths) ?> THÁNG</span>
                                </div>

                                <?php if (!$isInStock): ?>
                                    <span class="badge-out-of-stock">Hết hàng</span>
                                <?php elseif ($inspectedUnitsCount > 0): ?>
                                    <span class="badge-inspected">
                                        ✓ Đã kiểm định (<?= View::e((string) $inspectedUnitsCount) ?> máy)
                                    </span>
                                <?php else: ?>
                                    <span class="badge-serial-track">Chưa có biên bản kiểm định</span>
                                <?php endif; ?>
                            </div>

                            <button type="button"
                                    class="lightbox-trigger-btn"
                                    id="openLightboxBtn"
                                    aria-label="Phóng to hình ảnh sản phẩm">
                                🔍 Phóng to ảnh (<?= count($resolvedGallery) ?>)
                            </button>
                        </div>

                        <p class="main-image-caption" id="mainImageCaption">
                            <?= View::e($firstImage['alt']) ?>
                        </p>

                        <!-- Danh sách ảnh thu nhỏ (Thumbnails) từ bảng product_images -->
                        <div class="product-thumb-strip" id="productThumbList" role="region" aria-label="Danh sách ảnh thu nhỏ của sản phẩm">
                            <?php foreach ($resolvedGallery as $idx => $imgItem): ?>
                                <button type="button"
                                        class="thumb-item-btn <?= $idx === 0 ? 'is-active' : '' ?>"
                                        data-index="<?= View::e((string) $idx) ?>"
                                        data-full-src="<?= View::e($imgItem['url']) ?>"
                                        data-alt="<?= View::e($imgItem['alt']) ?>"
                                        aria-label="Xem ảnh <?= View::e((string) ($idx + 1)) ?>: <?= View::e($imgItem['alt']) ?>"
                                        aria-pressed="<?= $idx === 0 ? 'true' : 'false' ?>">
                                    <img src="<?= View::e($imgItem['url']) ?>"
                                         alt="<?= View::e($imgItem['alt']) ?>"
                                         class="thumb-item-img"
                                         <?= $idx > 0 ? 'loading="lazy"' : '' ?>>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Hộp cam kết chất lượng nền xanh nhạt dưới Gallery chuẩn Stitch -->
                    <div class="stitch-gallery-guarantee-box">
                        <span class="guarantee-icon" aria-hidden="true">🛡️</span>
                        <div>
                            <h3 class="guarantee-title">Cam kết chất lượng TL Gaming</h3>
                            <p class="guarantee-desc">
                                Sản phẩm thuộc danh mục <strong><?= View::e($categoryName) ?></strong>, áp dụng chính sách bảo hành <strong><?= View::e((string) $warrantyMonths) ?> tháng</strong>.
                                <?php if ($inspectedUnitsCount > 0): ?>
                                    Hiện có <strong><?= View::e((string) $inspectedUnitsCount) ?>/<?= View::e((string) count($units)) ?></strong> cá thể máy đã hoàn tất biên bản kiểm định phần cứng 18 bước.
                                <?php else: ?>
                                    Trạng thái kiểm định được công khai trung thực theo từng mã Serial thực tế bên dưới.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- CỘT PHẢI (7 CỘT): THÔNG TIN SẢN PHẨM, TÓM TẮT CẤU HÌNH, SERIAL & ĐẶT MUA -->
                <div class="product-summary-col">
                    <!-- Hàng nhãn định danh SKU, Danh mục & Trạng thái kiểm định -->
                    <div class="detail-meta-top">
                        <span class="detail-sku-tag">MÃ SKU: <code><?= View::e($productSku) ?></code></span>
                        <a href="<?= View::e(View::url('/san-pham?danh-muc=' . rawurlencode($categorySlug))) ?>" class="badge-category">
                            <?= View::e($categoryName) ?>
                        </a>
                        <?php if ($inspectedUnitsCount > 0): ?>
                            <span class="status-badge status-ok">
                                ✓ ĐÃ KIỂM ĐỊNH (<?= View::e((string) $inspectedUnitsCount) ?>/<?= View::e((string) count($units)) ?> CÁ THỂ CÓ BIÊN BẢN)
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-warn">CHƯA CÓ BIÊN BẢN KIỂM ĐỊNH</span>
                        <?php endif; ?>
                    </div>

                    <h1 class="detail-product-title"><?= View::e($productName) ?></h1>

                    <!-- Thanh tình trạng ngoại hình & tồn kho khả dụng chuẩn Stitch -->
                    <div class="stitch-condition-bar">
                        <div class="condition-bar-left">
                            <span aria-hidden="true">✨</span>
                            <span>Tình trạng máy: <strong><?= View::e($conditionOverview) ?></strong></span>
                        </div>
                        <span class="condition-bar-divider">|</span>
                        <div class="condition-bar-right">
                            <?php if ($isInStock): ?>
                                <span class="stock-dot-online"></span>
                                <strong class="text-emerald">Sẵn hàng giao ngay (<?= View::e((string) $purchasableQty) ?> khả dụng)</strong>
                            <?php else: ?>
                                <span class="stock-dot-offline"></span>
                                <strong class="text-rose">0 (Tạm hết hàng)</strong>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Khối Giá bán màu đỏ #BA1A1A + Giá gốc gạch ngang + Hộp bảo hành chuẩn Stitch -->
                    <div class="detail-price-panel">
                        <div class="detail-price-left">
                            <span class="detail-price-label">Giá bán</span>
                            <div class="detail-price-main">
                                <span class="detail-price-current"><?= View::e(View::formatVnd($price)) ?></span>
                                <?php if ($hasValidDiscount && $originalPrice !== null): ?>
                                    <span class="detail-price-old"><?= View::e(View::formatVnd($originalPrice)) ?></span>
                                    <span class="badge-discount">
                                        Tiết kiệm <?= View::e(View::formatVnd($discountSaving)) ?> (-<?= View::e((string) $discountPercent) ?>%)
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="stitch-warranty-badge-box">
                            <span class="warranty-box-label">CHẾ ĐỘ BẢO HÀNH</span>
                            <strong class="warranty-box-val">Bảo hành <?= View::e((string) $warrantyMonths) ?> tháng</strong>
                            <?php if ($hasSerials): ?>
                                <span class="warranty-box-sub">Đối soát theo mã serial</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="purchase-action-box">
                        <div class="quantity-control-row">
                            <label for="orderQtyInput" class="qty-label">Số lượng</label>
                            <div class="qty-stepper">
                                <button type="button"
                                        class="qty-step-btn"
                                        id="qtyMinusBtn"
                                        aria-label="Giảm số lượng"
                                        <?= !$isInStock ? 'disabled aria-disabled="true"' : '' ?>>
                                    &minus;
                                </button>
                                <input type="number"
                                       id="orderQtyInput"
                                       class="qty-number-input"
                                       min="1"
                                       max="<?= View::e((string) max(1, $purchasableQty)) ?>"
                                       data-max-stock="<?= View::e((string) $purchasableQty) ?>"
                                       value="<?= $isInStock ? '1' : '0' ?>"
                                       aria-label="Số lượng sản phẩm"
                                       <?= !$isInStock ? 'disabled aria-disabled="true"' : '' ?>>
                                <button type="button"
                                        class="qty-step-btn"
                                        id="qtyPlusBtn"
                                        aria-label="Tăng số lượng"
                                        <?= (!$isInStock || $purchasableQty <= 1) ? 'disabled aria-disabled="true"' : '' ?>>
                                    +
                                </button>
                            </div>
                            <span class="qty-stock-hint" id="qtyStockHint">
                                <?php if ($isInStock): ?>
                                    (Tối đa <?= View::e((string) $purchasableQty) ?> theo tồn kho khả dụng)
                                <?php else: ?>
                                    (Sản phẩm hiện đã hết hàng)
                                <?php endif; ?>
                            </span>
                        </div>

                        <form id="productAddToCartForm" action="<?= View::e(View::url('/gio-hang/them')) ?>" method="POST" style="margin: 0;">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="product_id" value="<?= View::e((string) $product['id']) ?>">
                            <input type="hidden" name="quantity" id="hiddenFormQty" value="<?= $isInStock ? '1' : '0' ?>">

                            <div class="purchase-buttons-row">
                                <?php if ($isInStock): ?>
                                    <button type="submit"
                                            class="btn btn-stitch-outline-lg btn-buy-action"
                                            id="addToCartBtn"
                                            data-product-id="<?= View::e((string) $product['id']) ?>"
                                            data-action-type="add-to-cart">
                                        Thêm vào giỏ
                                    </button>
                                    <button type="button"
                                            class="btn btn-primary btn-buy-action"
                                            id="buyNowBtn"
                                            data-product-id="<?= View::e((string) $product['id']) ?>"
                                            data-action-type="buy-now">
                                        Mua ngay
                                    </button>
                                <?php else: ?>
                                    <button type="button"
                                            class="btn btn-disabled btn-buy-action"
                                            id="addToCartBtn"
                                            disabled
                                            aria-disabled="true">
                                        Tạm hết hàng
                                    </button>
                                    <button type="button"
                                            class="btn btn-disabled btn-buy-action"
                                            id="buyNowBtn"
                                            disabled
                                            aria-disabled="true">
                                        Mua ngay
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>

                        <!-- Hộp thông báo phản hồi khi thêm giỏ hàng / mua ngay -->
                        <div class="cart-dev-notice" id="cartDevNotice" role="status" aria-live="polite" hidden>
                            <strong>Thông báo giỏ hàng:</strong>
                            <span id="cartDevNoticeText"></span>
                        </div>
                    </div>

                    <!-- Hộp Chính sách & Dịch vụ đi kèm thực tế (Không tạo quà tặng giả) -->
                    <div class="stitch-service-policy-box">
                        <div class="service-policy-head">
                            <span aria-hidden="true">🛠️</span>
                            <h2>Chính Sách &amp; Dịch Vụ Đi Kèm Tại Cửa Hàng</h2>
                        </div>
                        <ul class="service-policy-list">
                            <li>
                                <span class="service-check" aria-hidden="true">✓</span>
                                <span><strong>Tóm tắt sản phẩm:</strong> <?= View::e($summaryText !== '' ? $summaryText : $conditionOverview) ?></span>
                            </li>
                            <li>
                                <span class="service-check" aria-hidden="true">✓</span>
                                <span>Đã vệ sinh tiêu chuẩn, tra keo tản nhiệt và kiểm tra độ ổn định trước khi xuất kho.</span>
                            </li>
                            <li>
                                <span class="service-check" aria-hidden="true">✓</span>
                                <span>Hỗ trợ thanh toán <strong>COD (Đồng kiểm khi nhận hàng)</strong> hoặc <strong>Chuyển khoản ngân hàng</strong>.</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Lưới Tóm tắt "Cấu hình linh kiện" 2 cột chuẩn Stitch (Lấy từ bảng product_specs) -->
                    <?php if ($specs !== []): ?>
                        <div class="stitch-spec-summary-section">
                            <div class="spec-summary-head">
                                <h2 class="detail-sub-heading">Cấu hình linh kiện chính</h2>
                                <a href="#tab-section-anchor" class="view-inspection-anchor" data-open-tab="specs">
                                    Xem đầy đủ <?= count($specs) ?> thông số &rarr;
                                </a>
                            </div>
                            <div class="stitch-detail-spec-grid">
                                <?php foreach (array_slice($specs, 0, 6) as $sItem): ?>
                                    <div class="detail-spec-card">
                                        <span class="detail-spec-name"><?= View::e((string) $sItem['spec_name']) ?></span>
                                        <strong class="detail-spec-val"><?= View::e((string) $sItem['spec_value']) ?></strong>
                                        <span class="detail-spec-group"><?= View::e((string) $sItem['group_name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Danh sách cá thể máy theo Serial (Nếu sản phẩm quản lý theo Serial riêng) -->
                    <?php if ($hasSerials && $units !== []): ?>
                        <div class="detail-units-box">
                            <div class="units-box-header">
                                <h2 class="detail-sub-heading">
                                    Danh sách máy theo số Serial (<?= count($units) ?> máy trong hồ sơ)
                                </h2>
                                <a href="#tab-section-anchor" class="view-inspection-anchor" data-open-tab="inspection">
                                    Xem biên bản 18 bước &rarr;
                                </a>
                            </div>
                            <div class="unit-cards-mini-list">
                                <?php foreach ($units as $uRow): ?>
                                    <?php
                                    $uSerial   = (string) ($uRow['serial_number'] ?? '');
                                    $uGrade    = (string) ($uRow['cosmetic_grade'] ?? 'Grade A');
                                    $uBattery  = $uRow['battery_health'] ?? null;
                                    $uStatus   = (string) ($uRow['status'] ?? 'available');
                                    $uHasInsp  = !empty($uRow['has_valid_inspection']);
                                    $uDetail   = (string) ($uRow['hardware_condition_detail'] ?? '');
                                    $uStatusLabel = match ($uStatus) {
                                        'available' => 'Sẵn sàng bán',
                                        'reserved'  => 'Đang giữ chỗ đơn hàng',
                                        'sold'      => 'Đã xuất bán',
                                        'repairing' => 'Đang bảo dưỡng',
                                        default     => 'Không khả dụng',
                                    };
                                    $uStatusClass = $uStatus === 'available' ? 'in-stock' : 'out-stock';
                                    ?>
                                    <div class="unit-mini-card <?= $uStatus === 'available' ? 'is-available' : 'is-unavailable' ?>">
                                        <div class="unit-mini-top">
                                            <code><?= View::e($uSerial) ?></code>
                                            <span class="unit-grade-tag"><?= View::e($uGrade) ?></span>
                                            <?php if ($uBattery !== null): ?>
                                                <span class="unit-battery-tag">Pin <?= View::e((string) $uBattery) ?>%</span>
                                            <?php endif; ?>
                                            <span class="stock-pill <?= View::e($uStatusClass) ?>"><?= View::e($uStatusLabel) ?></span>
                                        </div>
                                        <p class="unit-mini-desc"><?= View::e($uDetail) ?></p>
                                        <div class="unit-mini-insp">
                                            <?php if ($uHasInsp && !empty($uRow['inspection'])): ?>
                                                <span class="unit-insp-ok">
                                                    ✓ Đã kiểm định ngày <?= View::e(substr((string) $uRow['inspection']['inspection_date'], 0, 10)) ?>
                                                    (<?= View::e((string) $uRow['inspection']['passed_steps_count']) ?>/<?= View::e((string) $uRow['inspection']['applicable_steps_count']) ?> bước đạt)
                                                </span>
                                            <?php else: ?>
                                                <span class="unit-insp-none">
                                                    ○ Chưa có biên bản kiểm định cho Serial này
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 3 Cam kết chân cột phải chuẩn thiết kế Google Stitch -->
                    <div class="stitch-detail-commitments-row">
                        <div class="detail-commit-item">
                            <span class="commit-item-icon" aria-hidden="true">✓</span>
                            <div>
                                <strong>Cam kết nguyên bản</strong>
                                <span>Đúng mô tả ngoại quan &amp; Serial</span>
                            </div>
                        </div>
                        <div class="detail-commit-item">
                            <span class="commit-item-icon" aria-hidden="true">🛡️</span>
                            <div>
                                <strong>Bảo hành <?= View::e((string) $warrantyMonths) ?> tháng</strong>
                                <span>Hỗ trợ kỹ thuật suốt quá trình dùng</span>
                            </div>
                        </div>
                        <div class="detail-commit-item">
                            <span class="commit-item-icon" aria-hidden="true">🚚</span>
                            <div>
                                <strong>Giao hàng an toàn</strong>
                                <span>Đóng gói chống sốc, đồng kiểm COD</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =======================================================================
     3. KHU VỰC 4 TAB THÔNG TIN CHI TIẾT (TRONG THẺ TRẮNG CHUẨN GOOGLE STITCH)
     ======================================================================= -->
<section class="section-block detail-tabs-section" id="tab-section-anchor">
    <div class="container">
        <div class="stitch-tabs-card">
            <!-- Thanh điều hướng 4 Tab chuẩn WAI-ARIA -->
            <div class="detail-tabs-nav" id="productDetailTabList" role="tablist" aria-label="Thông tin chi tiết sản phẩm">
                <button type="button"
                        class="detail-tab-btn is-active"
                        id="tab-btn-highlights"
                        role="tab"
                        aria-selected="true"
                        aria-controls="tab-panel-highlights"
                        tabindex="0"
                        data-tab-target="highlights">
                    Đặc điểm nổi bật &amp; Hiệu năng
                </button>
                <button type="button"
                        class="detail-tab-btn"
                        id="tab-btn-specs"
                        role="tab"
                        aria-selected="false"
                        aria-controls="tab-panel-specs"
                        tabindex="-1"
                        data-tab-target="specs">
                    Bảng thông số kỹ thuật chi tiết (<?= count($specs) ?>)
                </button>
                <button type="button"
                        class="detail-tab-btn"
                        id="tab-btn-inspection"
                        role="tab"
                        aria-selected="false"
                        aria-controls="tab-panel-inspection"
                        tabindex="-1"
                        data-tab-target="inspection">
                    Biên bản kiểm định linh kiện (<?= View::e((string) $inspectedUnitsCount) ?>)
                </button>
                <button type="button"
                        class="detail-tab-btn"
                        id="tab-btn-reviews"
                        role="tab"
                        aria-selected="false"
                        aria-controls="tab-panel-reviews"
                        tabindex="-1"
                        data-tab-target="reviews">
                    Đánh giá khách hàng
                </button>
            </div>

            <!-- TAB PANEL A: ĐẶC ĐIỂM NỔI BẬT & MÔ TẢ CHI TIẾT -->
            <div class="detail-tab-panel is-active"
                 id="tab-panel-highlights"
                 role="tabpanel"
                 aria-labelledby="tab-btn-highlights"
                 data-tab-panel="highlights">
                <h2 class="tab-panel-heading">Đặc Điểm Nổi Bật &amp; Đánh Giá Chi Tiết: <?= View::e($productName) ?></h2>

                <?php if ($summaryText !== ''): ?>
                    <p class="tab-lead-summary"><?= View::e($summaryText) ?></p>
                <?php endif; ?>

                <?php if ($descriptionText !== ''): ?>
                    <div class="tab-description-content">
                        <p><?= nl2br(View::e($descriptionText)) ?></p>
                    </div>
                <?php else: ?>
                    <p class="text-muted">Mô tả chi tiết bổ sung đang được cập nhật.</p>
                <?php endif; ?>

                <div class="tab-feature-boxes">
                    <div class="feature-highlight-item">
                        <h3>Tình trạng thực tế niêm yết</h3>
                        <p><?= View::e($conditionOverview) ?></p>
                    </div>
                    <div class="feature-highlight-item">
                        <h3>Chính sách bảo hành áp dụng</h3>
                        <p>Bảo hành phần cứng <strong><?= View::e((string) $warrantyMonths) ?> tháng</strong> trực tiếp tại TL Gaming, lưu trữ trên chi tiết đơn hàng.</p>
                    </div>
                    <div class="feature-highlight-item">
                        <h3>Quản lý tồn kho &amp; định danh</h3>
                        <p>
                            <?= $hasSerials
                                ? 'Sản phẩm được quản lý theo từng số Serial vật lý riêng biệt trong bảng product_units.'
                                : 'Sản phẩm phụ kiện/linh kiện được quản lý tồn kho theo số lượng.' ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- TAB PANEL B: BẢNG THÔNG SỐ KỸ THUẬT (TỪ BẢNG product_specs) -->
            <div class="detail-tab-panel"
                 id="tab-panel-specs"
                 role="tabpanel"
                 aria-labelledby="tab-btn-specs"
                 data-tab-panel="specs"
                 hidden>
                <h2 class="tab-panel-heading">Bảng Thông Số Kỹ Thuật Phần Cứng Chi Tiết</h2>
                <p class="tab-panel-sub">
                    Dữ liệu truy vấn trực tiếp từ bảng <code>product_specs</code>, sắp xếp theo cấu trúc phần cứng tiêu chuẩn (CPU, Mainboard, RAM, SSD/HDD, VGA, Nguồn PSU, Vỏ Case, Màn hình).
                </p>

                <?php if ($specs !== []): ?>
                    <div class="table-responsive">
                        <table class="specs-detail-table">
                            <thead>
                                <tr>
                                    <th style="width: 24%;">Nhóm thông số</th>
                                    <th style="width: 28%;">Hạng mục linh kiện</th>
                                    <th>Thông số kỹ thuật chi tiết</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($specs as $specRow): ?>
                                    <tr>
                                        <td class="spec-group-cell"><?= View::e($specRow['group_name']) ?></td>
                                        <th scope="row" class="spec-name-cell"><?= View::e($specRow['spec_name']) ?></th>
                                        <td class="spec-val-cell"><?= View::e($specRow['spec_value']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <h3 class="empty-state-title">Chưa có bảng thông số kỹ thuật chi tiết</h3>
                        <p>
                            Sản phẩm này hiện chưa có bản ghi tách rời trong bảng <code>product_specs</code>. Hệ thống giữ nguyên dữ liệu thực tế từ cơ sở dữ liệu và không tự tạo thông số giả lập. Quý khách vui lòng tham khảo cấu hình tóm tắt tại mục <strong>Đặc điểm nổi bật</strong>.
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB PANEL C: BIÊN BẢN KIỂM ĐỊNH LINH KIỆN (TÁCH BIỆT ĐÚNG TỪNG PRODUCT_UNIT) -->
            <div class="detail-tab-panel"
                 id="tab-panel-inspection"
                 role="tabpanel"
                 aria-labelledby="tab-btn-inspection"
                 data-tab-panel="inspection"
                 hidden>
                <h2 class="tab-panel-heading">Hồ Sơ Kiểm Định Máy Cũ Theo Từng Số Serial</h2>
                <p class="tab-panel-sub">
                    Mỗi biên bản kiểm định trong hệ thống được gắn trực tiếp với khóa ngoại <code>unit_id</code> (cá thể máy cụ thể). Hệ thống tuyệt đối <strong>không gán chung biên bản của một máy cho tất cả các máy khác</strong> cùng dòng sản phẩm.
                </p>

                <?php if ($units !== []): ?>
                    <div class="inspection-units-stack">
                        <?php foreach ($units as $unitRow): ?>
                            <?php
                            $uId       = (int) ($unitRow['id'] ?? 0);
                            $uSerial   = (string) ($unitRow['serial_number'] ?? '');
                            $uGrade    = (string) ($unitRow['cosmetic_grade'] ?? 'Grade A');
                            $uBattery  = $unitRow['battery_health'] ?? null;
                            $uStatus   = (string) ($unitRow['status'] ?? 'available');
                            $uCond     = (string) ($unitRow['hardware_condition_detail'] ?? '');
                            $uHasValid = !empty($unitRow['has_valid_inspection']);
                            $insp      = is_array($unitRow['inspection'] ?? null) ? $unitRow['inspection'] : null;

                            $uStatusText = match ($uStatus) {
                                'available' => 'Đang sẵn hàng tại kho (available)',
                                'reserved'  => 'Đang giữ chỗ cho đơn hàng (reserved)',
                                'sold'      => 'Đã xuất bán (sold)',
                                'repairing' => 'Đang bảo dưỡng kỹ thuật (repairing)',
                                default     => 'Đã hoàn trả (returned)',
                            };
                            ?>
                            <div class="unit-inspection-block" id="unit-inspection-<?= View::e((string) $uId) ?>">
                                <div class="unit-inspection-header">
                                    <div class="unit-id-meta">
                                        <span class="unit-serial-badge">Serial: <code><?= View::e($uSerial) ?></code></span>
                                        <span class="badge-category"><?= View::e($uGrade) ?></span>
                                        <?php if ($uBattery !== null): ?>
                                            <span class="badge-warranty">Sức khỏe Pin: <?= View::e((string) $uBattery) ?>%</span>
                                        <?php endif; ?>
                                        <span class="stock-pill <?= $uStatus === 'available' ? 'in-stock' : 'out-stock' ?>">
                                            <?= View::e($uStatusText) ?>
                                        </span>
                                    </div>

                                    <div>
                                        <?php if ($uHasValid && $insp !== null): ?>
                                            <span class="status-badge status-ok">
                                                ✓ Đã kiểm định (Biên bản #<?= View::e((string) $insp['id']) ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-warn">
                                                Chưa có biên bản kiểm định
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <p class="unit-hardware-note">
                                    <strong>Tình trạng phần cứng riêng của máy <code><?= View::e($uSerial) ?></code>:</strong>
                                    <?= View::e($uCond !== '' ? $uCond : 'Đang cập nhật mô tả chi tiết.') ?>
                                </p>

                                <?php if ($insp !== null): ?>
                                    <?php
                                    $overallRes   = (string) ($insp['checklist_overall_result'] ?? '');
                                    $overallLabel = match ($overallRes) {
                                        'passed'           => 'Đạt chuẩn xuất kho (Passed)',
                                        'conditional_pass' => 'Đạt có lưu ý ngoại quan / tuổi thọ linh kiện (Conditional Pass)',
                                        default            => 'Không đạt (Failed)',
                                    };
                                    $inspItems = is_array($insp['items'] ?? null) ? $insp['items'] : [];
                                    ?>
                                    <div class="inspection-summary-grid">
                                        <div>
                                            <span>Ngày kiểm định:</span>
                                            <strong><?= View::e((string) $insp['inspection_date']) ?></strong>
                                        </div>
                                        <div>
                                            <span>Kết luận tổng thể:</span>
                                            <strong><?= View::e($overallLabel) ?></strong>
                                        </div>
                                        <div>
                                            <span>Số hạng mục đạt:</span>
                                            <strong>
                                                <?= View::e((string) $insp['passed_steps_count']) ?>/<?= View::e((string) $insp['applicable_steps_count']) ?> bước áp dụng
                                                <?php if ((int) $insp['warning_steps_count'] > 0): ?>
                                                    (<?= View::e((string) $insp['warning_steps_count']) ?> bước lưu ý)
                                                <?php endif; ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <?php if (!empty($insp['notes'])): ?>
                                        <p class="inspection-overall-note">
                                            <strong>Ghi chú biên bản kiểm định:</strong> <?= View::e((string) $insp['notes']) ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if ($inspItems !== []): ?>
                                        <div class="table-responsive" style="margin-top: 14px;">
                                            <table class="inspection-table">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 80px;">Bước</th>
                                                        <th style="width: 22%;">Nhóm kiểm tra</th>
                                                        <th>Hạng mục kiểm định phần cứng</th>
                                                        <th style="width: 150px;">Kết quả</th>
                                                        <th>Ghi chú đo kiểm thực tế</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($inspItems as $stepItem): ?>
                                                        <?php
                                                        $stRes = (string) ($stepItem['result'] ?? '');
                                                        [$stLabel, $stClass] = match ($stRes) {
                                                            'pass'           => ['✓ Đạt (Pass)', 'status-ok'],
                                                            'warning'        => ['⚠ Lưu ý (Warning)', 'status-warn'],
                                                            'fail'           => ['✕ Không đạt (Fail)', 'out-stock'],
                                                            default          => ['— Không áp dụng', 'step-na-badge'],
                                                        };
                                                        ?>
                                                        <tr>
                                                            <td><strong>#<?= View::e((string) $stepItem['step_number']) ?></strong></td>
                                                            <td><?= View::e((string) $stepItem['group_name']) ?></td>
                                                            <td><?= View::e((string) $stepItem['item_name']) ?></td>
                                                            <td><span class="status-badge <?= View::e($stClass) ?>"><?= View::e($stLabel) ?></span></td>
                                                            <td><?= View::e((string) ($stepItem['notes'] ?? '')) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="unit-no-inspection-box">
                                        <strong>Chưa có biên bản kiểm định:</strong>
                                        Cá thể máy có số Serial <code><?= View::e($uSerial) ?></code> hiện chưa có biên bản kiểm định trong bảng <code>product_inspections</code>. Hệ thống giữ nguyên tính trung thực và không lấy biên bản của máy khác để hiển thị thay thế.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <h3 class="empty-state-title">Chưa có biên bản kiểm định</h3>
                        <p>
                            <?= $hasSerials
                                ? 'Sản phẩm này hiện chưa có cá thể máy hoặc biên bản kiểm định nào được lưu trữ trong cơ sở dữ liệu.'
                                : 'Đây là linh kiện/phụ kiện quản lý theo số lượng chung (has_serials = 0), không tách biên bản kiểm định theo từng số Serial riêng lẻ.' ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB PANEL D: ĐÁNH GIÁ KHÁCH HÀNG -->
            <div class="detail-tab-panel"
                 id="tab-panel-reviews"
                 role="tabpanel"
                 aria-labelledby="tab-btn-reviews"
                 data-tab-panel="reviews"
                 hidden>
                <h2 class="tab-panel-heading">Đánh Giá Từ Khách Hàng</h2>
                <div class="empty-state">
                    <h3 class="empty-state-title">Chức năng đánh giá đang phát triển</h3>
                    <p>
                        Hệ thống hiện chưa triển khai bảng lưu trữ đánh giá khách hàng trong cơ sở dữ liệu. TL Gaming tuyệt đối không tạo các bình luận hoặc điểm số đánh giá giả lập.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =======================================================================
     4. CẤU HÌNH TƯƠNG TỰ CÙNG PHÂN KHÚC (TỐI ĐA 4 SẢN PHẨM PUBLISHED CHUẨN STITCH)
     ======================================================================= -->
<section class="section-block similar-products-section">
    <div class="container">
        <div class="stitch-section-head">
            <div class="stitch-title-group">
                <span class="stitch-title-bar"></span>
                <div>
                    <h2 class="section-title">CẤU HÌNH TƯƠNG TỰ CÙNG PHÂN KHÚC</h2>
                    <p class="section-subtitle">
                        Các sản phẩm đang mở bán thuộc cùng danh mục <strong><?= View::e($categoryName) ?></strong> (ưu tiên sản phẩm còn hàng)
                    </p>
                </div>
            </div>
            <a href="<?= View::e(View::url('/san-pham?danh-muc=' . rawurlencode($categorySlug))) ?>" class="stitch-view-all-link">
                Xem tất cả trong <?= View::e($categoryName) ?> &rarr;
            </a>
        </div>

        <?php if ($similarProducts !== []): ?>
            <div class="product-grid stitch-grid-4 tlg-unified-grid">
                <?php foreach ($similarProducts as $simProd): ?>
                    <?php
                    $cardProduct    = $simProd;
                    $cardHeadingTag = 'h3';
                    $cardCtaLabel   = 'Xem cấu hình';
                    $cardShowSpecs  = true;
                    require dirname(__DIR__) . '/partials/product_card.php';
                    ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p>Hiện chưa có sản phẩm tương tự nào khác trong danh mục này.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- =======================================================================
     5. HỘP THOẠI PHÓNG TO ẢNH (LIGHTBOX MODAL THUẦN JAVASCRIPT)
     ======================================================================= -->
<div class="product-lightbox"
     id="productLightbox"
     role="dialog"
     aria-modal="true"
     aria-label="Phóng to hình ảnh sản phẩm"
     hidden>
    <div class="lightbox-backdrop" id="lightboxBackdrop"></div>
    <div class="lightbox-content">
        <button type="button" class="lightbox-close-btn" id="lightboxCloseBtn" aria-label="Đóng khung phóng to ảnh">
            ✕ Đóng
        </button>
        <div class="lightbox-stage">
            <button type="button" class="lightbox-nav-btn lightbox-prev" id="lightboxPrevBtn" aria-label="Ảnh trước">
                &#10094;
            </button>
            <img src="<?= View::e($firstImage['url']) ?>"
                 alt="<?= View::e($firstImage['alt']) ?>"
                 id="lightboxImage"
                 class="lightbox-main-img">
            <button type="button" class="lightbox-nav-btn lightbox-next" id="lightboxNextBtn" aria-label="Ảnh tiếp theo">
                &#10095;
            </button>
        </div>
        <p class="lightbox-caption" id="lightboxCaption"><?= View::e($firstImage['alt']) ?></p>
    </div>
</div>

<!-- =======================================================================
     6. THANH MUA HÀNG CỐ ĐỊNH TRÊN MOBILE (CHỈ HIỂN THỊ <= 768PX, THAY THẾ BOTTOM NAV ĐỂ KHÔNG CHỒNG NHAU)
     ======================================================================= -->
<div class="m-sticky-buy-bar" id="mobileStickyBuyBar" aria-label="Thao tác đặt mua nhanh trên di động">
    <a href="<?= View::e(View::url('/gio-hang')) ?>" class="m-sticky-cart-link" aria-label="Xem giỏ hàng">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="9" cy="21" r="1"></circle>
            <circle cx="20" cy="21" r="1"></circle>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
        </svg>
        <span>Giỏ hàng</span>
    </a>

    <?php if ($isInStock): ?>
        <button type="button" class="m-sticky-add-btn" id="mobileStickyAddCartBtn">
            Thêm vào giỏ
        </button>
        <button type="button" class="m-sticky-buy-btn" id="mobileStickyBuyNowBtn">
            Mua ngay
        </button>
    <?php else: ?>
        <button type="button" class="m-sticky-disabled-btn" disabled>
            Tạm hết hàng
        </button>
    <?php endif; ?>
</div>
