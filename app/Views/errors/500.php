<?php
/**
 * Giao diện báo lỗi 500 Internal Server Error (`app/Views/errors/500.php`).
 *
 * Vai trò:
 * - Hiển thị khi có ngoại lệ hệ thống được bắt bởi `ErrorHandler`.
 * - Chỉ hiển thị thông điệp kỹ thuật khi `APP_DEBUG=true` (development); ẩn toàn bộ chi tiết nội bộ khi ở `production`.
 */

declare(strict_types=1);

use App\Core\View;

/** @var bool $debug */
/** @var string|null $message */
/** @var string|null $file */
/** @var int|null $line */
?>
<section class="section-block error-section">
    <div class="container error-container">
        <div class="card error-card">
            <span class="error-code">500</span>
            <h1 class="error-title">Sự Cố Kỹ Thuật Máy Chủ</h1>
            <p class="error-message"><?= View::e($message ?? 'Đã xảy ra lỗi trong quá trình xử lý yêu cầu.') ?></p>
            <?php if (!empty($debug) && !empty($file)): ?>
                <p class="error-debug">Vị trí phát sinh (Chế độ Development): <code><?= View::e($file) ?>:<?= View::e((string) $line) ?></code></p>
            <?php endif; ?>
            <div class="error-actions">
                <a href="<?= View::e(View::url('/')) ?>" class="btn btn-primary">Quay về Trang chủ</a>
            </div>
        </div>
    </div>
</section>
