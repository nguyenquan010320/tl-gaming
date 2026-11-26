<?php
/**
 * Giao diện Đổi mật khẩu (`GET /doi-mat-khau`) — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Chỉ hiển thị biểu mẫu và thông báo lỗi do `AuthController` truyền sang; không truy vấn SQL.
 * - Không bao giờ điền lại (echo) mật khẩu vào ô nhập sau khi có lỗi.
 * - Người dùng cần nhập đúng mật khẩu hiện tại thì server mới chấp nhận mật khẩu mới.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

/** @var array<int, string> $errors Danh sách lỗi kiểm tra biểu mẫu */
$errors        = is_array($errors ?? null) ? $errors : [];
$flashMessages = Flash::pull();
?>
<section class="page-banner auth-breadcrumb-banner">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                <li class="breadcrumb-item"><a href="<?= View::e(View::url('/')) ?>">🏠 Trang chủ</a></li>
                <li class="breadcrumb-item"><a href="<?= View::e(View::url('/tai-khoan')) ?>">Tài khoản của tôi</a></li>
                <li class="breadcrumb-item"><span aria-current="page">Đổi mật khẩu</span></li>
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
                <h1 class="auth-form-heading">Đổi mật khẩu</h1>
                <p class="auth-form-sub">Mật khẩu mới tối thiểu 8 ký tự, gồm cả chữ cái và chữ số.</p>

                <form action="<?= View::e(View::url('/doi-mat-khau')) ?>" method="POST" class="stitch-auth-form" id="changePasswordForm">
                    <?= Csrf::field() ?>

                    <div class="form-group">
                        <label for="currentPassword" class="form-label">Mật khẩu hiện tại <span class="text-rose">*</span></label>
                        <input type="password" id="currentPassword" name="current_password" class="form-control" required autocomplete="current-password">
                    </div>

                    <div class="auth-grid-2">
                        <div class="form-group">
                            <label for="newPassword" class="form-label">Mật khẩu mới <span class="text-rose">*</span></label>
                            <input type="password" id="newPassword" name="new_password" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label for="newPasswordConfirm" class="form-label">Nhập lại mật khẩu mới <span class="text-rose">*</span></label>
                            <input type="password" id="newPasswordConfirm" name="new_password_confirmation" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password">
                        </div>
                    </div>

                    <div class="account-actions">
                        <button type="submit" class="btn btn-primary">Cập nhật mật khẩu</button>
                        <a href="<?= View::e(View::url('/tai-khoan')) ?>" class="btn btn-outline">Quay lại</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
