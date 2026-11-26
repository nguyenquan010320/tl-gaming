<?php
/**
 * Giao diện báo lỗi 403 Forbidden (`app/Views/errors/403.php`).
 *
 * Vai trò:
 * - Hiển thị khi khách vãng lai (chưa đăng nhập) hoặc tài khoản `customer` cố gắng truy cập `/admin`,
 *   hoặc khi biểu mẫu POST thiếu/sai mã bảo mật CSRF.
 */

declare(strict_types=1);

use App\Core\View;

/** @var bool $isAuthenticated */
/** @var string|null $customMessage */
?>
<section class="section-block error-section">
    <div class="container error-container">
        <div class="card error-card">
            <span class="error-code">403</span>
            <h1 class="error-title">Từ Chối Quyền Truy Cập (Forbidden)</h1>
            <p class="error-message">
                <?php if (!empty($customMessage)): ?>
                    <?= View::e($customMessage) ?>
                <?php elseif (empty($isAuthenticated)): ?>
                    Khu vực Quản trị (<code>/admin</code>) yêu cầu đăng nhập bằng tài khoản Quản trị viên (<code>role = 'admin'</code>) đã được xác thực trong Session. Khách vãng lai không được phép truy cập.
                <?php else: ?>
                    Tài khoản hiện tại của bạn không có quyền Quản trị viên (<code>admin</code>) để truy cập trang này.
                <?php endif; ?>
            </p>
            <div class="error-actions">
                <a href="<?= View::e(View::url('/dang-nhap')) ?>" class="btn btn-primary">Đi tới Trang Đăng nhập</a>
                <a href="<?= View::e(View::url('/')) ?>" class="btn btn-outline">Về Trang chủ</a>
            </div>
        </div>
    </div>
</section>
