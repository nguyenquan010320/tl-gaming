<?php
declare(strict_types=1);

use App\Core\View;

$catalogUrl = '/san-pham';
$bannerButtonUrl = str_starts_with($homeBanner['button_url'], '/')
    ? View::url($homeBanner['button_url'])
    : $homeBanner['button_url'];
$categoryLinks = array_slice($headerMenuItems ?? [], 0, 6);
$inspectionGroups = $inspectionOverview['groups'] ?? [];
$verifiedRecords = $inspectionOverview['verified_records'] ?? [];
$budgetLinks = [
    ['Dưới 5 triệu', 'gia-den=4999999'],
    ['5–10 triệu', 'gia-tu=5000000&gia-den=9999999'],
    ['10–15 triệu', 'gia-tu=10000000&gia-den=14999999'],
    ['15–20 triệu', 'gia-tu=15000000&gia-den=19999999'],
    ['Từ 20 triệu', 'gia-tu=20000000'],
];
$productSections = [
    ['san-pham-noi-bat', 'Sản phẩm nổi bật', $catalogUrl, $featuredProducts ?? []],
    ['phan-khu-laptop-gaming', 'Laptop Gaming cũ', $catalogUrl . '?danh-muc=laptop-gaming-cu', $laptopProducts ?? []],
    ['phan-khu-pc-gaming', 'PC Gaming cũ', $catalogUrl . '?danh-muc=pc-gaming-cu', $pcProducts ?? []],
    ['phan-khu-linh-kien', 'Linh kiện và màn hình', $catalogUrl . '?danh-muc=linh-kien-cu', $componentProducts ?? []],
];
?>
<div class="store-home container">
    <div class="store-masthead">
        <?php if ($categoryLinks !== []): ?>
            <nav class="store-directory" aria-label="Danh mục mua sắm">
                <h2>Danh mục sản phẩm</h2>
                <?php foreach ($categoryLinks as $item): ?>
                    <a href="<?= View::e(View::url((string) $item['url'])) ?>">
                        <span><?= View::e((string) $item['label']) ?></span><span aria-hidden="true">›</span>
                    </a>
                <?php endforeach; ?>
                <a class="store-directory-all" href="<?= View::e(View::url($catalogUrl)) ?>">
                    Tất cả sản phẩm <span aria-hidden="true">→</span>
                </a>
            </nav>
        <?php endif; ?>

        <section class="store-hero">
            <div class="store-hero-copy">
                <p class="store-hero-kicker">Kiểm định kỹ thuật 18 bước</p>
                <h1><?= View::e($homeBanner['title']) ?></h1>
                <?php if ($homeBanner['description'] !== ''): ?>
                    <p><?= View::e($homeBanner['description']) ?></p>
                <?php endif; ?>
                <div class="store-hero-actions">
                    <a class="btn btn-primary store-hero-cta" href="<?= View::e($bannerButtonUrl) ?>">
                        Xem sản phẩm <span aria-hidden="true">→</span>
                    </a>
                    <a class="store-hero-proof" href="#quy-trinh-kiem-dinh">Quy trình kiểm định</a>
                </div>
            </div>
            <div class="store-hero-media" aria-hidden="true">
                <img src="<?= View::e(View::url($homeBanner['image'])) ?>" alt="" fetchpriority="high">
            </div>
        </section>
    </div>

    <section class="store-services" id="cam-ket-cua-hang" aria-label="Thông tin mua hàng">
        <a href="#quy-trinh-kiem-dinh">
            <span class="store-service-mark" aria-hidden="true">✓</span>
            <span><strong>Kiểm định 18 bước</strong><small>Xem biên bản theo serial</small></span>
        </a>
        <a href="<?= View::e(View::url($catalogUrl)) ?>">
            <span class="store-service-mark" aria-hidden="true">↺</span>
            <span><strong>Bảo hành theo sản phẩm</strong><small>Thời hạn ghi trên từng sản phẩm</small></span>
        </a>
        <a href="<?= View::e(View::url('/tra-cuu-don-hang')) ?>">
            <span class="store-service-mark" aria-hidden="true">↗</span>
            <span><strong>Tra cứu đơn hàng</strong><small>Theo dõi bằng mã đơn và liên hệ</small></span>
        </a>
    </section>

    <section class="store-budget" aria-labelledby="storeBudgetTitle">
        <h2 id="storeBudgetTitle">Mua theo ngân sách</h2>
        <ul>
            <?php foreach ($budgetLinks as [$label, $query]): ?>
                <li>
                    <a href="<?= View::e(View::url($catalogUrl . '?' . $query)) ?>">
                        <?= View::e($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php foreach ($productSections as [$sectionId, $sectionTitle, $sectionUrl, $products]): ?>
        <?php if ($products === [] && $sectionId !== 'san-pham-noi-bat') { continue; } ?>
        <section class="store-shelf" id="<?= View::e($sectionId) ?>" aria-labelledby="<?= View::e($sectionId . '-title') ?>">
            <div class="store-section-heading">
                <h2 id="<?= View::e($sectionId . '-title') ?>"><?= View::e($sectionTitle) ?></h2>
                <a href="<?= View::e(View::url($sectionUrl)) ?>">Xem tất cả <span aria-hidden="true">→</span></a>
            </div>
            <?php if ($products !== []): ?>
                <div class="product-grid tlg-unified-grid store-shelf-grid">
                    <?php foreach (array_slice($products, 0, 4) as $cardProduct): ?>
                        <?php
                        $cardHeadingTag = 'h3';
                        $cardCtaLabel = 'Xem chi tiết';
                        require dirname(__DIR__) . '/partials/product_card.php';
                        ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state" role="status">
                    <p><?= empty($dbConnected) || !empty($productQueryError)
                        ? 'Chưa thể tải sản phẩm. Vui lòng thử lại sau.'
                        : 'Hiện chưa có sản phẩm nổi bật đang mở bán.' ?></p>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <section class="store-inspection" id="quy-trinh-kiem-dinh" aria-labelledby="storeInspectionTitle">
        <div class="store-section-heading">
            <h2 id="storeInspectionTitle">Quy trình kiểm định 18 bước</h2>
            <span><?= (int) ($inspectionOverview['total_inspected_units'] ?? 0) ?> máy có biên bản</span>
        </div>
        <?php if ($inspectionGroups !== []): ?>
            <div class="store-inspection-groups">
                <?php foreach ($inspectionGroups as $group): ?>
                    <details>
                        <summary><?= View::e((string) $group['group_name']) ?></summary>
                        <ul>
                            <?php foreach ($group['steps'] as $step): ?>
                                <li><?= View::e((string) $step['item_name']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($verifiedRecords !== []): ?>
            <div class="store-serials">
                <strong>Hồ sơ theo serial</strong>
                <ul>
                    <?php foreach (array_slice($verifiedRecords, 0, 6) as $record): ?>
                        <li>
                            <a href="<?= View::e(View::url('/san-pham/' . rawurlencode((string) $record['product_slug']) . '#tab-panel-inspection')) ?>"
                               title="<?= View::e((string) $record['product_name']) ?>">
                                <?= View::e((string) $record['serial_number']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>
</div>
