<?php
/**
 * Layout Quản trị dùng chung (`app/Views/layouts/admin.php`) theo thiết kế Google Stitch TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Cung cấp bộ khung dùng chung cho toàn bộ phân hệ Admin (Tổng quan, Danh mục và các Prompt 17–21).
 * - Bao gồm Sidebar xanh navy đậm, thông tin Admin + nút Đăng xuất POST (CSRF), Header + Breadcrumb,
 *   Flash message và khu vực nội dung chính nền sáng.
 * - Các mục menu chưa triển khai được gắn nhãn trạng thái "Sắp triển khai", tuyệt đối không giả lập chức năng.
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$currentRoute = (string) ($currentRoute ?? '/admin');
$adminUser    = $adminUser ?? Auth::user();
$breadcrumbs  = is_array($breadcrumbs ?? null) ? $breadcrumbs : [
    ['label' => 'Tổng quan', 'url' => '/admin'],
];
$flashMessages = Flash::pull();

$menuItems = [
    ['label' => 'Tổng quan', 'icon' => '📊', 'url' => '/admin', 'match' => 'exact', 'ready' => true],
    ['label' => 'Quản lý danh mục', 'icon' => '🗂️', 'url' => '/admin/danh-muc', 'match' => 'prefix', 'ready' => true],
    ['label' => 'Quản lý sản phẩm', 'icon' => '💻', 'url' => '/admin/san-pham', 'match' => 'prefix', 'ready' => true],
    ['label' => 'Quản lý Serial & Kiểm định', 'icon' => '🔬', 'url' => '/admin/serial', 'match' => 'prefix', 'ready' => true],
    ['label' => 'Quản lý đơn hàng', 'icon' => '🧾', 'url' => '/admin/don-hang', 'match' => 'prefix', 'ready' => true],
    ['label' => 'Quản lý khách hàng', 'icon' => '👥', 'url' => '/admin/khach-hang', 'match' => 'prefix', 'ready' => true],
    ['label' => 'Quản lý voucher', 'icon' => '🎟️', 'url' => '/admin/voucher', 'match' => 'prefix', 'ready' => true],
    ['label' => 'Thống kê doanh thu', 'icon' => '📈', 'url' => '/admin?ky=year', 'match' => 'exact', 'ready' => true],
    ['label' => 'Thông tin liên hệ', 'icon' => '⚙️', 'url' => '/admin/cai-dat/lien-he', 'match' => 'exact', 'ready' => true],
    ['label' => 'Banner trang chủ', 'icon' => '🖼️', 'url' => '/admin/cai-dat/banner-trang-chu', 'match' => 'exact', 'ready' => true],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= View::e(Csrf::token()) ?>">
    <title><?= View::e($pageTitle ?? 'Quản trị hệ thống | TL Gaming') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= View::e(View::url('/assets/css/admin.css')) ?>?v=3">
</head>
<body class="admin-body">
<div class="admin-shell">
    <!-- Sidebar Xanh Navy Đậm -->
    <aside class="admin-sidebar" id="adminSidebar" aria-label="Menu quản trị">
        <a href="<?= View::e(View::url('/admin')) ?>" class="admin-brand">
            <span class="admin-brand-mark" aria-hidden="true">TL</span>
            <span class="admin-brand-text">
                <strong>TL GAMING</strong>
                <span>Hệ thống Quản trị</span>
            </span>
        </a>

        <nav class="admin-nav">
            <span class="admin-nav-label">Điều hướng quản trị</span>
            <?php foreach ($menuItems as $item): ?>
                <?php if ($item['ready'] && $item['url'] !== null): ?>
                    <?php
                    $isActive = $item['match'] === 'exact'
                        ? ($currentRoute === $item['url'])
                        : str_starts_with($currentRoute, (string) $item['url']);
                    ?>
                    <a href="<?= View::e(View::url((string) $item['url'])) ?>"
                       class="admin-nav-link <?= $isActive ? 'is-active' : '' ?>">
                        <span class="admin-nav-left">
                            <span aria-hidden="true"><?= $item['icon'] ?></span>
                            <span><?= View::e($item['label']) ?></span>
                        </span>
                    </a>
                <?php else: ?>
                    <span class="admin-nav-link is-upcoming" title="Phân hệ đang chờ triển khai ở giai đoạn tiếp theo">
                        <span class="admin-nav-left">
                            <span aria-hidden="true"><?= $item['icon'] ?></span>
                            <span><?= View::e($item['label']) ?></span>
                        </span>
                        <span class="admin-nav-badge">Sắp có</span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="admin-sidebar-footer">
            <div class="admin-user-box">
                <div class="admin-user-name"><?= View::e((string) ($adminUser['full_name'] ?? 'Quản trị viên')) ?></div>
                <div class="admin-user-role">
                    <?= View::e((string) ($adminUser['email'] ?? '')) ?> · <?= View::e((string) ($adminUser['role'] ?? 'admin')) ?>
                </div>
            </div>
            <div class="admin-footer-actions">
                <a href="<?= View::e(View::url('/')) ?>" class="admin-btn-ghost-dark">Trang chủ</a>
                <form action="<?= View::e(View::url('/dang-xuat')) ?>" method="POST" class="admin-logout-form">
                    <input type="hidden" name="_csrf_token" value="<?= View::e(Csrf::token()) ?>">
                    <button type="submit" class="admin-btn-logout">Đăng xuất</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>

    <!-- Khu vực nội dung chính nền sáng -->
    <div class="admin-main">
        <header class="admin-topbar">
            <div class="admin-topbar-left">
                <button type="button" class="admin-menu-toggle" id="adminMenuToggle"
                        aria-label="Mở menu quản trị" aria-expanded="false" aria-controls="adminSidebar">
                    ☰
                </button>
                <nav class="admin-breadcrumb" aria-label="Breadcrumb">
                    <a href="<?= View::e(View::url('/admin')) ?>">Quản trị</a>
                    <?php foreach ($breadcrumbs as $idx => $crumb): ?>
                        <span aria-hidden="true">/</span>
                        <?php if (!empty($crumb['url']) && $idx < count($breadcrumbs) - 1): ?>
                            <a href="<?= View::e(View::url((string) $crumb['url'])) ?>"><?= View::e((string) $crumb['label']) ?></a>
                        <?php else: ?>
                            <span class="admin-breadcrumb-current"><?= View::e((string) $crumb['label']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="admin-topbar-right">
                <a href="<?= View::e(View::url('/san-pham')) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">Xem cửa hàng</a>
            </div>
        </header>

        <main class="admin-content">
            <?php if ($flashMessages !== []): ?>
                <div class="admin-flash-stack" role="status" aria-live="polite">
                    <?php foreach ($flashMessages as $msg): ?>
                        <?php
                        $type  = (string) ($msg['type'] ?? 'info');
                        $class = match ($type) {
                            'success' => 'admin-alert-success',
                            'error'   => 'admin-alert-error',
                            'warning' => 'admin-alert-warning',
                            default   => 'admin-alert-info',
                        };
                        ?>
                        <div class="admin-alert <?= $class ?>">
                            <?= View::e((string) ($msg['message'] ?? '')) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= View::e(View::url('/assets/js/admin.js')) ?>?v=1"></script>
</body>
</html>
