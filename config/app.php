<?php
/**
 * Cấu hình chung của ứng dụng TL Gaming.
 *
 * Vai trò:
 * - Tập trung các thiết lập cấp hệ thống như tên website, môi trường chạy (development/production),
 *   chế độ hiển thị lỗi (debug), múi giờ và thông số bảo mật Session Cookie.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';
loadEnvFile(dirname(__DIR__) . '/.env');

return [
    // Tên thương hiệu hiển thị trên tiêu đề trang và giao diện
    'name'     => (string) env('APP_NAME', 'TL Gaming - Máy Tính & Linh Kiện Cũ Kiểm Định'),

    // Môi trường vận hành: 'development' (hiển thị lỗi chi tiết để gỡ lỗi) hoặc 'production' (ẩn chi tiết lỗi hệ thống)
    'env'      => (string) env('APP_ENV', 'development'),

    // Bật/tắt chế độ gỡ lỗi chi tiết
    'debug'    => (bool) env('APP_DEBUG', true),

    // Địa chỉ URL gốc của hệ thống
    'url'      => rtrim((string) env('APP_URL', 'http://localhost'), '/'),

    // Múi giờ chuẩn Việt Nam phục vụ đồng bộ thời gian đơn hàng và thời hạn voucher
    'timezone' => (string) env('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'),

    // Cấu hình bảo mật Session Cookie chống tấn công XSS và CSRF
    'session'  => [
        'name'     => (string) env('SESSION_NAME', 'tlgaming_session'),
        'lifetime' => (int) env('SESSION_LIFETIME', 7200),
        'secure'   => (bool) env('SESSION_SECURE', false),
        'samesite' => (string) env('SESSION_SAMESITE', 'Lax'),
        'httponly' => true,
    ],
];
