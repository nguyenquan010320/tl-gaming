<?php
/**
 * Giao diện Hồ sơ khách hàng (`GET /tai-khoan`) — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Chỉ hiển thị dữ liệu do `AuthController::showProfile()` truyền sang; không truy vấn SQL.
 * - Email và vai trò chỉ hiển thị, không cho sửa. Biểu mẫu chỉ gửi họ tên, số điện thoại, địa chỉ;
 *   `id` của tài khoản được lấy từ Session phía server, không đọc từ biểu mẫu.
 * - Tái sử dụng hệ thống lớp CSS Google Stitch đã có (`stitch-auth-shell`, `form-control`, `btn`...).
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

/** @var array<string, mixed> $profile Bản ghi người dùng từ `UserModel::findById()` */
/** @var array<int, string> $errors Danh sách lỗi kiểm tra biểu mẫu */
/** @var array<string, string> $old Giá trị người dùng vừa nhập khi có lỗi */
$profile       = is_array($profile ?? null) ? $profile : [];
$errors        = is_array($errors ?? null) ? $errors : [];
$old           = is_array($old ?? null) ? $old : [];
$flashMessages = Flash::pull();

$valName    = $old['full_name'] ?? (string) ($profile['full_name'] ?? '');
$valPhone   = $old['phone'] ?? (string) ($profile['phone'] ?? '');
$valAddress = $old['address'] ?? (string) ($profile['address'] ?? '');
?>
<section class="page-banner auth-breadcrumb-banner">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                <li class="breadcrumb-item"><a href="<?= View::e(View::url('/')) ?>">🏠 Trang chủ</a></li>
                <li class="breadcrumb-item"><span aria-current="page">Tài khoản của tôi</span></li>
            </ol>
        </nav>
    </div>
</section>

<section class="section-block auth-main-section">
    <div class="container">
        <div class="account-shell">
            <?php foreach ($flashMessages as $flash): ?>
                <div class="auth-alert auth-alert-<?= View::e($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'info' ? 'info' : 'error')) ?>" role="status">
                    <?= View::e($flash['message']) ?>
                </div>
            <?php endforeach; ?>
            <?php if ($errors !== []): ?>
                <div class="auth-alert auth-alert-error" role="alert">
                    <strong>Vui lòng kiểm tra lại:</strong>
                    <ul>
                        <?php foreach ($errors as $errorMsg): ?>
                            <li><?= View::e((string) $errorMsg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card account-card">
                <h1 class="auth-form-heading">Thông tin tài khoản</h1>
                <p class="auth-form-sub">
                    Vai trò: <strong><?= View::e(Auth::isAdmin() ? 'Quản trị viên' : 'Khách hàng') ?></strong>
                    <?php if (!empty($profile['created_at'])): ?>
                        • Tham gia: <?= View::e(date('d/m/Y', strtotime((string) $profile['created_at']) ?: 0)) ?>
                    <?php endif; ?>
                </p>

                <form action="<?= View::e(View::url('/tai-khoan')) ?>" method="POST" class="stitch-auth-form" id="profileForm">
                    <?= Csrf::field() ?>

                    <div class="form-group">
                        <label for="profileEmail" class="form-label">Email đăng nhập (không thể thay đổi)</label>
                        <input type="email" id="profileEmail" class="form-control" value="<?= View::e((string) ($profile['email'] ?? '')) ?>" disabled>
                    </div>

                    <div class="auth-grid-2">
                        <div class="form-group">
                            <label for="profileName" class="form-label">Họ và tên <span class="text-rose">*</span></label>
                            <input type="text" id="profileName" name="full_name" class="form-control" value="<?= View::e($valName) ?>" maxlength="100" required autocomplete="name">
                        </div>
                        <div class="form-group">
                            <label for="profilePhone" class="form-label">Số điện thoại</label>
                            <input type="tel" id="profilePhone" name="phone" class="form-control" value="<?= View::e($valPhone) ?>" maxlength="20" autocomplete="tel">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="profileAddress" class="form-label">Địa chỉ mặc định</label>
                        <input type="text" id="profileAddress" name="address" class="form-control" value="<?= View::e($valAddress) ?>" maxlength="255" autocomplete="street-address">
                    </div>

                    <div class="account-actions">
                        <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
                        <a href="<?= View::e(View::url('/doi-mat-khau')) ?>" class="btn btn-outline">Đổi mật khẩu</a>
                        <?php if (Auth::isAdmin()): ?>
                            <a href="<?= View::e(View::url('/admin')) ?>" class="btn btn-outline">Vào trang Quản trị</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
