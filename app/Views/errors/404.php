<?php
/**
 * Giao diện báo lỗi 404 Not Found (`app/Views/errors/404.php`).
 *
 * Vai trò:
 * - Hiển thị khi người dùng truy cập một đường dẫn URL không tồn tại trong bảng định tuyến `routes/web.php`.
 */

declare(strict_types=1);

use App\Core\View;

/** @var string|null $requestedPath */
?>
<section class="section-block error-section">
    <div class="container error-container">
        <div class="card error-card">
            <span class="error-code">404</span>
            <h1 class="error-title">Không Tìm Thấy Trang Yêu Cầu</h1>
            <p class="error-message">
                Đường dẫn <code><?= View::e($requestedPath ?? '/') ?></code> không tồn tại hoặc đã được di chuyển trên hệ thống TL Gaming.
            </p>
            <div class="error-actions">
                <a href="<?= View::e(View::url('/')) ?>" class="btn btn-primary">Quay về Trang chủ</a>
                <a href="<?= View::e(View::url('/san-pham')) ?>" class="btn btn-outline">Xem Danh sách Sản phẩm</a>
            </div>
        </div>
    </div>
</section>
