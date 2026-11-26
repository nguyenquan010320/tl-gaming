<?php
/**
 * Cấu hình kết nối Cơ sở dữ liệu MySQL 8.0 / MariaDB thông qua PDO.
 *
 * Vai trò:
 * - Cung cấp thông số kết nối đến cơ sở dữ liệu `tl_gaming` đã được định nghĩa trong `database/tl_gaming.sql`.
 * - Mọi thông tin nhạy cảm (username, password, host) được đọc từ biến môi trường `.env` bên ngoài `public/`.
 * - Tuyệt đối không tự tạo lại bảng, không tự nạp seed hoặc reset dữ liệu trong quá trình vận hành.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';
loadEnvFile(dirname(__DIR__) . '/.env');

return [
    'driver'    => (string) env('DB_CONNECTION', 'mysql'),
    'host'      => (string) env('DB_HOST', '127.0.0.1'),
    'port'      => (int) env('DB_PORT', 3306),
    'database'  => (string) env('DB_DATABASE', 'tl_gaming'),
    'username'  => (string) env('DB_USERNAME', 'root'),
    'password'  => (string) env('DB_PASSWORD', ''),
    'charset'   => (string) env('DB_CHARSET', 'utf8mb4'),
    'collation' => (string) env('DB_COLLATION', 'utf8mb4_unicode_ci'),
];
