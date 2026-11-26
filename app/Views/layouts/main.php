<?php
/**
 * Layout dùng chung (`app/Views/layouts/main.php`) cho hệ thống Website bán máy tính cũ TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Hiển thị điều hướng, thông tin liên hệ và phần chân trang cho cửa hàng.
 * - Mọi dữ liệu động xuất ra giao diện đều đi qua `View::e()` (`htmlspecialchars`) để chống lỗ hổng XSS.
 * - Tuyệt đối không thực hiện truy vấn SQL trong View và không hiển thị thông tin cấu hình nội bộ.
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\ContactSettings;
use App\Core\Flash;
use App\Core\View;

$currentRoute = (string) ($currentRoute ?? '/');
$authUser     = Auth::user();
$searchVal    = isset($searchQuery) ? (string) $searchQuery : trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$currentCat   = isset($categorySlug) ? (string) $categorySlug : trim(is_string($_GET['danh-muc'] ?? null) ? $_GET['danh-muc'] : '');
$navCategories = $headerMenuItems ?? [];
// Nạp cấu hình thông tin liên hệ dùng chung (Header, Footer, Drawer, Bottom Nav & Floating Contact Widget)
$contactConfig       = ContactSettings::configuration();
$contactHotlineDisp  = $contactConfig['hotline']['display'];
$contactHotlineHref  = $contactConfig['hotline']['href'];
$contactHotlineHours = $contactConfig['hotline']['hours'];
$contactEmailAddr    = $contactConfig['email']['address'];
$contactEmailHref    = $contactConfig['email']['href'];
$showroomDisplayAddress = $contactConfig['showroom']['address'];
$showroomDisplayHours   = $contactConfig['showroom']['hours'];
$showroomDeliveryLabel  = 'Giao hỏa tốc 1–2h nội thành';

$cartTotalQty = isset($cartItemCount)
    ? (int) $cartItemCount
    : \App\Models\CartModel::getCurrentTotalQuantity();
$isCheckoutRoute      = ($currentRoute === '/thanh-toan');
$isProductDetailRoute = ($storePage ?? null) === 'product-detail';
$bodyClasses          = ['storefront'];
if ($authUser !== null) {
    $bodyClasses[] = 'storefront-signed-in';
}
if ($currentRoute === '/gio-hang') {
    $bodyClasses[] = 'route-cart';
}
$showroomLocations    = (require dirname(__DIR__, 3) . '/config/checkout.php')['showrooms'];
if ($isCheckoutRoute) {
    $bodyClasses[] = 'route-checkout';
}
if ($isProductDetailRoute) {
    $bodyClasses[] = 'route-product-detail';
}
$bodyClassAttr = $bodyClasses !== [] ? ' class="' . View::e(implode(' ', $bodyClasses)) . '"' : '';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= View::e(\App\Core\Csrf::token()) ?>">
    <meta name="base-url" content="<?= View::e(View::url('')) ?>">
    <title><?= View::e($pageTitle ?? 'TL Gaming — Hệ Thống Máy Tính & Linh Kiện Cũ Kiểm Định 18 Bước') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= View::e(View::url('/assets/css/style.css')) ?>?v=11">
    <link rel="stylesheet" href="<?= View::e(View::url('/assets/css/storefront.css')) ?>?v=13">
</head>
<body<?= $bodyClassAttr ?>>
    <svg class="store-contact-icons" width="0" height="0" aria-hidden="true">
        <symbol id="store-contact-pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 10c0 5.25-8 11-8 11s-8-5.75-8-11a8 8 0 1 1 16 0z"></path>
            <circle cx="12" cy="10" r="2.5"></circle>
        </symbol>
        <symbol id="store-contact-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 7v5l3 2"></path>
        </symbol>
        <symbol id="store-contact-phone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
        </symbol>
    </svg>
    <a href="#store-content" class="store-skip-link">Đến nội dung chính</a>
    <header class="site-header">
        <nav class="store-utility" aria-label="Tiện ích cửa hàng">
            <div class="container store-utility-inner">
                <div class="store-utility-meta">
                    <span class="store-utility-showroom">
                        <svg class="store-utility-icon" width="14" height="14" aria-hidden="true">
                            <use href="#store-contact-pin"></use>
                        </svg>
                        <span><strong>Showroom:</strong> <?= View::e($showroomDisplayAddress) ?></span>
                    </span>
                    <span class="store-utility-hours">
                        <svg class="store-utility-icon" width="14" height="14" aria-hidden="true">
                            <use href="#store-contact-clock"></use>
                        </svg>
                        <span>Mở cửa: <?= View::e($showroomDisplayHours) ?></span>
                    </span>
                    <span class="store-utility-delivery">
                        <svg class="store-utility-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 6h12v10H3zM15 9h3l3 3v4h-6"></path>
                            <circle cx="7" cy="17" r="2"></circle><circle cx="18" cy="17" r="2"></circle>
                        </svg>
                        <span><?= View::e($showroomDeliveryLabel) ?></span>
                    </span>
                </div>
                <div class="store-utility-actions">
                    <a href="<?= View::e(View::url('/#quy-trinh-kiem-dinh')) ?>" class="store-utility-inspection">
                        <svg class="store-utility-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 22s7-4 7-10V6l-7-3-7 3v6c0 6 7 10 7 10z"></path>
                            <path d="m9 12 2 2 4-4"></path>
                        </svg>
                        <span>Quy trình kiểm định</span>
                    </a>
                    <a href="<?= View::e(View::url('/tra-cuu-don-hang')) ?>" class="store-order-link" <?= $currentRoute === '/tra-cuu-don-hang' ? 'aria-current="page"' : '' ?>>
                        <svg class="store-utility-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M6 3h12a1 1 0 0 1 1 1v17l-3.5-2-3.5 2-3.5-2L5 21V4a1 1 0 0 1 1-1z"></path>
                            <path d="M9 8h6m-6 4h6"></path>
                        </svg>
                        <span>Tra cứu đơn hàng</span>
                    </a>
                    <?php if (!empty($contactConfig['hotline']['enabled'])): ?>
                        <a href="<?= View::e($contactHotlineHref) ?>" class="store-utility-hotline">
                            <svg class="store-utility-icon" width="14" height="14" aria-hidden="true">
                                <use href="#store-contact-phone"></use>
                            </svg>
                            <span>Hotline <strong><?= View::e($contactHotlineDisp) ?></strong></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        <!-- Header gọn cho điện thoại và máy tính bảng -->
        <div class="m-header-shell">
            <!-- Hàng 1: Hamburger + Logo TL GAMING + Icon Giỏ hàng + Icon Tài khoản (54px) -->
            <div class="m-header-top">
                <div class="m-header-left">
                    <button type="button"
                            class="m-icon-btn m-hamburger-btn"
                            id="mobileMenuBtn"
                            aria-label="Mở menu danh mục sản phẩm"
                            aria-expanded="false"
                            aria-controls="mobileCategoryDrawer">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="18" x2="21" y2="18"></line>
                        </svg>
                    </button>

                    <a href="<?= View::e(View::url('/')) ?>" class="m-brand-link" aria-label="Trang chủ TL Gaming">
                        <span class="m-brand-badge" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="6" y1="12" x2="10" y2="12"></line>
                                <line x1="8" y1="10" x2="8" y2="14"></line>
                                <line x1="15" y1="13" x2="15.01" y2="13"></line>
                                <line x1="18" y1="11" x2="18.01" y2="11"></line>
                                <rect x="2" y="6" width="20" height="12" rx="4"></rect>
                            </svg>
                        </span>
                        <span class="m-brand-name">TL GAMING</span>
                    </a>
                </div>

                <div class="m-header-right">
                    <a href="<?= View::e(View::url('/tra-cuu-don-hang')) ?>"
                       class="m-icon-btn m-order-link" aria-label="Tra cứu đơn hàng" <?= $currentRoute === '/tra-cuu-don-hang' ? 'aria-current="page"' : '' ?>>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M6 3h12a1 1 0 0 1 1 1v17l-3.5-2-3.5 2-3.5-2L5 21V4a1 1 0 0 1 1-1z"></path>
                            <path d="M9 8h6m-6 4h6"></path>
                        </svg>
                        <span class="m-order-short" aria-hidden="true">Tra cứu</span>
                        <span class="m-order-full" aria-hidden="true">Tra cứu đơn hàng</span>
                    </a>
                    <a href="<?= View::e(View::url('/gio-hang')) ?>"
                       class="m-icon-btn m-cart-icon-btn <?= $currentRoute === '/gio-hang' ? 'is-active' : '' ?>"
                       aria-label="Giỏ hàng của bạn">
                        <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="9" cy="21" r="1"></circle>
                            <circle cx="20" cy="21" r="1"></circle>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                        </svg>
                        <span class="m-cart-badge js-cart-count-badge" id="mobileHeaderCartBadge"><?= View::e((string) $cartTotalQty) ?></span>
                    </a>

                    <a href="<?= View::e(View::url($authUser !== null ? '/tai-khoan' : '/dang-nhap')) ?>"
                       class="m-icon-btn m-account-icon-btn <?= ($currentRoute === '/tai-khoan' || $currentRoute === '/dang-nhap' || $currentRoute === '/dang-ky') ? 'is-active' : '' ?>"
                       aria-label="<?= $authUser !== null ? 'Tài khoản của bạn' : 'Đăng nhập tài khoản' ?>">
                        <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Hàng 2: Thanh tìm kiếm toàn chiều rộng (44px) -->
            <div class="m-header-search-wrap">
                <form action="<?= View::e(View::url('/san-pham')) ?>" method="GET" class="m-search-form" id="mobileHeaderSearchForm" role="search">
                    <span class="m-search-icon" aria-hidden="true">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </span>
                    <input type="search"
                           name="q"
                           class="m-search-input"
                           placeholder="Bạn cần tìm sản phẩm gì?"
                           value="<?= View::e($searchVal) ?>"
                           aria-label="Bạn cần tìm sản phẩm gì?">
                    <button type="submit" class="m-search-submit" aria-label="Tìm kiếm sản phẩm">Tìm</button>
                </form>
            </div>
        </div>

        <div class="container header-main-row">
            <a href="<?= View::e(View::url('/')) ?>" class="brand-logo" aria-label="Trang chủ TL Gaming">
                <span class="logo-icon-box" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="6" y1="12" x2="10" y2="12"></line>
                        <line x1="8" y1="10" x2="8" y2="14"></line>
                        <line x1="15" y1="13" x2="15.01" y2="13"></line>
                        <line x1="18" y1="11" x2="18.01" y2="11"></line>
                        <rect x="2" y="6" width="20" height="12" rx="4"></rect>
                    </svg>
                </span>
                <span class="logo-text-group">
                    <span class="logo-title-row">
                        <span class="logo-brand">TL <span>GAMING</span></span>
                    </span>
                    <span class="logo-sub">Máy tính &amp; linh kiện cũ</span>
                </span>
            </a>

            <!-- Thanh tìm kiếm trung tâm kèm từ khóa gợi ý nhanh trên 1 dòng -->
            <div class="header-search-col">
                <form action="<?= View::e(View::url('/san-pham')) ?>" method="GET" class="header-search-form" id="headerSearchForm" role="search">
                    <span class="search-leading-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </span>
                    <input type="search"
                           name="q"
                           id="headerSearchInput"
                           class="header-search-input"
                           placeholder="Tìm laptop, PC hoặc linh kiện…"
                           value="<?= View::e($searchVal) ?>"
                           aria-label="Tìm kiếm máy tính và linh kiện cũ">
                    <button type="submit" class="header-search-btn">Tìm kiếm</button>
                </form>
                <div class="header-quick-keywords" aria-label="Từ khóa tìm kiếm phổ biến">
                    <span>Gợi ý:</span>
                    <a href="<?= View::e(View::url('/san-pham?danh-muc=laptop-gaming-cu')) ?>">Laptop gaming</a>
                    <a href="<?= View::e(View::url('/san-pham?danh-muc=pc-gaming-cu')) ?>">PC gaming</a>
                    <a href="<?= View::e(View::url('/san-pham?gia-den=9999999')) ?>">Dưới 10 triệu</a>
                    <a href="<?= View::e(View::url('/san-pham?q=RTX+3060')) ?>" class="header-keyword-extra">RTX 3060</a>
                    <a href="<?= View::e(View::url('/san-pham?danh-muc=man-hinh-cu')) ?>" class="header-keyword-extra">Màn hình</a>
                </div>
            </div>

            <!-- Tài khoản và giỏ hàng -->
            <div class="header-actions">
                <a href="<?= View::e(View::url('/gio-hang')) ?>"
                   class="header-cart-btn <?= $currentRoute === '/gio-hang' ? 'is-active' : '' ?>"
                   aria-label="Giỏ hàng của bạn">
                    <span class="cart-icon" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="9" cy="21" r="1"></circle>
                            <circle cx="20" cy="21" r="1"></circle>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                        </svg>
                    </span>
                    <span class="cart-label">Giỏ hàng</span>
                    <span class="cart-count-badge js-cart-count-badge" id="headerCartCountBadge"><?= View::e((string) $cartTotalQty) ?></span>
                </a>

                <?php if ($authUser !== null): ?>
                    <!-- Đã đăng nhập: Tài khoản + (Admin) Dashboard + Đăng xuất (POST kèm CSRF, không dùng GET) -->
                    <a href="<?= View::e(View::url('/tai-khoan')) ?>"
                       class="header-action-btn <?= $currentRoute === '/tai-khoan' ? 'is-active' : '' ?>"
                       title="Tài khoản của bạn">
                        <span class="action-icon" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <span class="action-text">
                            <small>Xin chào</small>
                            <strong class="header-user-name"><?= View::e((string) ($authUser['full_name'] ?? 'Thành viên')) ?></strong>
                        </span>
                    </a>
                    <?php if (Auth::isAdmin()): ?>
                        <a href="<?= View::e(View::url('/admin')) ?>"
                           class="header-action-btn <?= $currentRoute === '/admin' ? 'is-active' : '' ?>"
                           title="Bảng điều khiển Quản trị">
                            <span class="action-text">
                                <small>Quản trị</small>
                                <strong>Dashboard</strong>
                            </span>
                        </a>
                    <?php endif; ?>
                    <form action="<?= View::e(View::url('/dang-xuat')) ?>" method="POST" class="header-logout-form">
                        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="header-action-btn header-logout-btn" title="Đăng xuất khỏi hệ thống">
                            <span class="action-text">
                                <small>Thoát</small>
                                <strong>Đăng xuất</strong>
                            </span>
                        </button>
                    </form>
                <?php else: ?>
                    <a href="<?= View::e(View::url('/dang-nhap')) ?>" class="header-auth-link">Đăng nhập</a>
                    <a href="<?= View::e(View::url('/dang-nhap?tab=dang-ky')) ?>" class="header-auth-link header-register-link">Đăng ký</a>
                <?php endif; ?>

                <button type="button"
                        class="mobile-menu-toggle"
                        id="desktopMobileMenuFallbackBtn"
                        aria-label="Mở danh mục sản phẩm"
                        aria-expanded="false"
                        aria-controls="headerCategoryNav">
                    ☰ Danh mục
                </button>
            </div>
        </div>

        <!-- Điều hướng cửa hàng -->
        <div class="category-bar">
            <div class="container category-bar-inner">
                <nav class="category-nav" id="headerCategoryNav" aria-label="Menu danh mục sản phẩm">
                    <a href="<?= View::e(View::url('/san-pham')) ?>"
                       class="cat-nav-link store-nav-catalog"
                       <?= $currentRoute === '/san-pham' && !$isProductDetailRoute && $currentCat === '' ? 'aria-current="page"' : '' ?>>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                            <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                            <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                            <rect x="14" y="14" width="7" height="7" rx="1"></rect>
                        </svg>
                        Danh mục sản phẩm
                    </a>
                    <a href="<?= View::e(View::url('/#san-pham-noi-bat')) ?>" class="cat-nav-link store-nav-featured">Nổi bật</a>

                    <?php foreach ($navCategories as $catItem): ?>
                        <?php
                        $itemSlug = (string) ($catItem['slug'] ?? '');
                        $itemUrl  = (string) ($catItem['url'] ?? ('/san-pham?danh-muc=' . rawurlencode($itemSlug)));
                        $isActive = $itemSlug !== '' && $currentCat === strtolower($itemSlug);
                        ?>
                        <a href="<?= View::e(View::url($itemUrl)) ?>"
                           class="cat-nav-link <?= $isActive ? 'is-active' : '' ?>"
                           <?= $isActive && !$isProductDetailRoute ? 'aria-current="page"' : '' ?>>
                            <?= View::e((string) $catItem['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>
    </header>

    <!-- B. NỘI DUNG CHÍNH DO VIEW CON CUNG CẤP -->
    <main class="site-main" id="store-content" tabindex="-1">
        <?php
        // Thông báo một lần còn sót (ví dụ "Đăng nhập thành công", "Đã đăng xuất") — các trang tài khoản đã tự hiển thị và xóa trước đó
        foreach (Flash::pull() as $layoutFlash):
            $layoutFlashType = $layoutFlash['type'] === 'success' ? 'success' : ($layoutFlash['type'] === 'info' ? 'info' : 'error');
            ?>
            <div class="container">
                <div class="auth-alert auth-alert-<?= View::e($layoutFlashType) ?> auth-alert-global" role="status">
                    <?= View::e($layoutFlash['message']) ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?= $content ?? '' ?>
    </main>

    <!-- C. FOOTER 4 CỘT NỀN TRẮNG CHUẨN THIẾT KẾ GOOGLE STITCH (ẢNH A) -->
    <footer class="site-footer" id="lien-he-showroom">
        <div class="container">
            <div class="footer-grid">
                <!-- Cột 1: Thương hiệu TL GAMING + CŨ ZIN 100% & Hệ thống Showroom -->
                <div class="footer-col">
                    <div class="footer-brand-heading-row">
                        <span class="footer-brand-name">TL GAMING</span>
                    </div>
                    <p class="footer-desc">
                        PC, laptop gaming và linh kiện cũ. Xem tình trạng thực tế và thông tin bảo hành trên từng sản phẩm.
                    </p>
                    <ul class="footer-contact-list">
                        <li>
                            <svg class="footer-contact-icon" width="14" height="14" aria-hidden="true">
                                <use href="#store-contact-phone"></use>
                            </svg>
                            <div>
                                <strong>Hotline hỗ trợ:</strong> <a href="<?= View::e($contactHotlineHref) ?>"><?= View::e($contactHotlineDisp) ?></a> – <a href="<?= View::e($contactEmailHref) ?>"><?= View::e($contactEmailAddr) ?></a>
                            </div>
                        </li>
                        <li>
                            <svg class="footer-contact-icon" width="14" height="14" aria-hidden="true">
                                <use href="#store-contact-clock"></use>
                            </svg>
                            <div>
                                <strong>Mở cửa:</strong> <?= View::e($showroomDisplayHours) ?>
                            </div>
                        </li>
                        <li>
                            <svg class="footer-contact-icon" width="14" height="14" aria-hidden="true">
                                <use href="#store-contact-pin"></use>
                            </svg>
                            <span><strong>Showroom:</strong> <?= View::e($showroomDisplayAddress) ?></span>
                        </li>
                        <?php foreach ($showroomLocations as $location): ?>
                            <?php if ($location['address'] === $showroomDisplayAddress) continue; ?>
                            <li>
                                <svg class="footer-contact-icon" width="14" height="14" aria-hidden="true">
                                    <use href="#store-contact-pin"></use>
                                </svg>
                                <span><strong><?= View::e($location['name']) ?></strong> · <?= View::e($location['address']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Cột 2: Chính Sách & Quy Định (Accordion trên Mobile, mở sẵn trên Desktop) -->
                <div class="footer-col footer-acc-col">
                    <h4 class="footer-subheading">
                        <button type="button"
                                class="footer-acc-trigger"
                                aria-expanded="false"
                                aria-controls="footerAccPolicy">
                            <span>Hỗ trợ mua hàng</span>
                            <svg class="footer-acc-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                    </h4>
                    <div class="footer-acc-panel" id="footerAccPolicy">
                        <ul class="footer-links">
                            <li><a href="<?= View::e(View::url('/#quy-trinh-kiem-dinh')) ?>">Quy trình kiểm định</a></li>
                            <li><a href="<?= View::e(View::url('/tra-cuu-don-hang')) ?>">Tra cứu đơn hàng</a></li>
                            <li><a href="<?= View::e(View::url('/gio-hang')) ?>">Giỏ hàng của bạn</a></li>
                            <li><a href="<?= View::e(View::url($authUser !== null ? '/tai-khoan' : '/dang-nhap')) ?>">Tài khoản</a></li>
                        </ul>
                    </div>
                </div>

                <!-- Cột 3: Danh Mục Sản Phẩm (Accordion trên Mobile, mở sẵn trên Desktop) -->
                <div class="footer-col footer-acc-col">
                    <h4 class="footer-subheading">
                        <button type="button"
                                class="footer-acc-trigger"
                                aria-expanded="false"
                                aria-controls="footerAccCatalog">
                            <span>Danh Mục Sản Phẩm</span>
                            <svg class="footer-acc-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                    </h4>
                    <div class="footer-acc-panel" id="footerAccCatalog">
                        <ul class="footer-links">
                            <?php foreach ($navCategories as $item): ?>
                                <li><a href="<?= View::e(View::url((string) $item['url'])) ?>"><?= View::e((string) $item['label']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <!-- Cột 4: Đối Tác Thanh Toán & Chứng Nhận (Accordion trên Mobile, mở sẵn trên Desktop) -->
                <div class="footer-col footer-acc-col">
                    <h4 class="footer-subheading">
                        <button type="button"
                                class="footer-acc-trigger"
                                aria-expanded="false"
                                aria-controls="footerAccPayment">
                            <span>Thanh toán</span>
                            <svg class="footer-acc-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                    </h4>
                    <div class="footer-acc-panel" id="footerAccPayment">
                        <p class="footer-desc" style="margin-bottom: 12px;">
                            Có thể thanh toán khi nhận hàng. Các phương thức khả dụng hiển thị khi đặt hàng.
                        </p>

                        <div class="footer-cert-box">
                            <span class="cert-icon" aria-hidden="true">✓</span>
                            <div>
                                <strong>ĐÃ KIỂM ĐỊNH KỸ THUẬT 18 BƯỚC</strong>
                                <span>Xem hồ sơ kiểm định trên trang sản phẩm</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> <strong>TL Gaming</strong></p>
                <a href="<?= View::e($contactEmailHref) ?>"><?= View::e($contactEmailAddr) ?></a>
            </div>
        </div>
    </footer>

    <!-- D. DRAWER DANH MỤC MOBILE (TRƯỢT TỪ TRÁI, LẤY DANH MỤC THỰC TẾ TỪ MYSQL) -->
    <div class="m-drawer-backdrop" id="mobileDrawerBackdrop" hidden></div>
    <aside class="m-category-drawer" id="mobileCategoryDrawer" aria-label="Menu danh mục sản phẩm" aria-hidden="true" inert>
        <div class="m-drawer-head">
            <a href="<?= View::e(View::url('/')) ?>" class="m-brand-link">
                <span class="m-brand-badge" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="6" y1="12" x2="10" y2="12"></line>
                        <line x1="8" y1="10" x2="8" y2="14"></line>
                        <line x1="15" y1="13" x2="15.01" y2="13"></line>
                        <line x1="18" y1="11" x2="18.01" y2="11"></line>
                        <rect x="2" y="6" width="20" height="12" rx="4"></rect>
                    </svg>
                </span>
                <span class="m-brand-name">TL GAMING</span>
            </a>
            <button type="button" class="m-drawer-close" id="mobileDrawerCloseBtn" aria-label="Đóng menu danh mục">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>

        <nav class="m-drawer-body" aria-label="Danh mục chính">
            <div class="m-drawer-showroom">
                <p><strong>Showroom:</strong> <?= View::e($showroomDisplayAddress) ?></p>
                <p>Mở cửa: <?= View::e($showroomDisplayHours) ?></p>
                <p><?= View::e($showroomDeliveryLabel) ?></p>
            </div>
            <div class="m-drawer-section-label">Danh mục sản phẩm</div>
            <ul class="m-drawer-list">
                <li>
                    <a href="<?= View::e(View::url('/san-pham')) ?>" class="m-drawer-link <?= ($currentRoute === '/san-pham' && $currentCat === '') ? 'is-active' : '' ?>">
                        <span>Tất cả sản phẩm</span>
                        <span class="m-drawer-chevron" aria-hidden="true">&rsaquo;</span>
                    </a>
                </li>
                <?php foreach ($navCategories as $catItem): ?>
                    <?php
                    $mSlug = (string) ($catItem['slug'] ?? '');
                    $mUrl  = (string) ($catItem['url'] ?? ('/san-pham?danh-muc=' . rawurlencode($mSlug)));
                    $mAct  = ($mSlug !== '' && $currentCat === strtolower($mSlug));
                    ?>
                    <li>
                        <a href="<?= View::e(View::url($mUrl)) ?>" class="m-drawer-link <?= $mAct ? 'is-active' : '' ?>">
                            <span><?= View::e((string) $catItem['label']) ?></span>
                            <span class="m-drawer-chevron" aria-hidden="true">&rsaquo;</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="m-drawer-section-label">Tiện ích &amp; Tài khoản</div>
            <ul class="m-drawer-list">
                <li>
                    <a href="<?= View::e(View::url('/tra-cuu-don-hang')) ?>" class="m-drawer-link <?= $currentRoute === '/tra-cuu-don-hang' ? 'is-active' : '' ?>">
                        <span>Tra cứu đơn hàng</span>
                        <span class="m-drawer-chevron" aria-hidden="true">&rsaquo;</span>
                    </a>
                </li>
                <li>
                    <a href="<?= View::e(View::url('/#quy-trinh-kiem-dinh')) ?>" class="m-drawer-link">
                        <span>Quy trình kiểm định 18 bước</span>
                        <span class="m-drawer-chevron" aria-hidden="true">&rsaquo;</span>
                    </a>
                </li>
                <?php if ($authUser !== null): ?>
                    <li>
                        <a href="<?= View::e(View::url('/tai-khoan')) ?>" class="m-drawer-link <?= $currentRoute === '/tai-khoan' ? 'is-active' : '' ?>">
                            <span>Tài khoản: <?= View::e((string) ($authUser['full_name'] ?? 'Thành viên')) ?></span>
                            <span class="m-drawer-chevron" aria-hidden="true">&rsaquo;</span>
                        </a>
                    </li>
                    <?php if (Auth::isAdmin()): ?>
                        <li>
                            <a href="<?= View::e(View::url('/admin')) ?>" class="m-drawer-link">
                                <span>Bảng điều khiển Admin</span>
                                <span class="m-drawer-chevron" aria-hidden="true">&rsaquo;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php else: ?>
                    <li>
                        <a href="<?= View::e(View::url('/dang-nhap')) ?>" class="m-drawer-link <?= $currentRoute === '/dang-nhap' ? 'is-active' : '' ?>">
                            <span>Đăng nhập / Đăng ký</span>
                            <span class="m-drawer-chevron" aria-hidden="true">&rsaquo;</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <?php if ($authUser !== null): ?>
                <form action="<?= View::e(View::url('/dang-xuat')) ?>" method="POST" class="m-drawer-logout-form">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="m-drawer-logout-btn">Đăng xuất tài khoản</button>
                </form>
            <?php endif; ?>
        </nav>

        <div class="m-drawer-foot">
            <a href="<?= View::e($contactHotlineHref) ?>" class="m-drawer-hotline-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                </svg>
                <span>Hotline: <?= View::e($contactHotlineDisp) ?> (<?= View::e($contactHotlineHours) ?>)</span>
            </a>
        </div>
    </aside>

    <!-- E. THANH ĐIỀU HƯỚNG DƯỚI CỐ ĐỊNH TRÊN MOBILE (BOTTOM NAVIGATION BAR — 5 MỤC) -->
    <?php if (!$isCheckoutRoute): ?>
        <nav class="m-bottom-nav" id="mobileBottomNav" aria-label="Điều hướng nhanh di động">
            <a href="<?= View::e(View::url('/')) ?>"
               class="mbn-item <?= $currentRoute === '/' ? 'is-active' : '' ?>">
                <span class="mbn-icon" aria-hidden="true">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                </span>
                <span class="mbn-label">Trang chủ</span>
            </a>

            <button type="button"
                    class="mbn-item <?= ($currentRoute === '/san-pham') ? 'is-active' : '' ?>"
                    data-open-mobile-drawer="true"
                    aria-expanded="false"
                    aria-controls="mobileCategoryDrawer">
                <span class="mbn-icon" aria-hidden="true">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                        <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                        <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                        <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                    </svg>
                </span>
                <span class="mbn-label">Danh mục</span>
            </button>

            <a href="<?= View::e(View::url('/#san-pham-noi-bat')) ?>"
               class="mbn-item">
                <span class="mbn-icon" aria-hidden="true">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                        <line x1="7" y1="7" x2="7.01" y2="7"></line>
                    </svg>
                </span>
                <span class="mbn-label">Nổi bật</span>
            </a>

            <a href="<?= View::e(View::url('/gio-hang')) ?>"
               class="mbn-item <?= $currentRoute === '/gio-hang' ? 'is-active' : '' ?>">
                <span class="mbn-icon mbn-icon-cart" aria-hidden="true">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="21" r="1"></circle>
                        <circle cx="20" cy="21" r="1"></circle>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                    </svg>
                    <span class="mbn-cart-badge js-cart-count-badge" id="mobileBottomCartBadge"><?= View::e((string) $cartTotalQty) ?></span>
                </span>
                <span class="mbn-label">Giỏ hàng</span>
            </a>

            <a href="<?= View::e($contactHotlineHref) ?>" class="mbn-item mbn-item-call">
                <span class="mbn-icon mbn-icon-call" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                </span>
                <span class="mbn-label">Gọi ngay</span>
            </a>
        </nav>
    <?php endif; ?>

    <!-- F. CỤM LIÊN HỆ NỔI (ZALO / MESSENGER / HOTLINE / BACK TO TOP) DÙNG CHUNG STOREFRONT -->
    <?php require dirname(__DIR__) . '/partials/floating_contact_widget.php'; ?>

    <script src="<?= View::e(View::url('/assets/js/app.js')) ?>?v=12"></script>
</body>
</html>
