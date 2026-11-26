<?php
/**
 * Front Controller duy nhất của hệ thống Website bán máy tính cũ TL Gaming (`public/index.php`).
 *
 * Vai trò kiến trúc:
 * - Là điểm vào (Entry Point) duy nhất tiếp nhận toàn bộ các HTTP Request từ trình duyệt người dùng.
 * - Đăng ký cơ chế tự động nạp lớp (Autoloader chuẩn PSR-4 cho không gian tên `App\`).
 * - Nạp cấu hình ứng dụng từ `config/app.php` (đọc biến môi trường từ `.env` bên ngoài `public/`).
 * - Kích hoạt bộ xử lý lỗi tập trung `ErrorHandler` theo môi trường `development` hoặc `production`.
 * - Khởi tạo phiên làm việc (Session) với các cờ bảo mật Cookie (`HttpOnly`, `SameSite=Lax`, `Strict Mode`).
 * - Khởi tạo CSRF Token bảo vệ các biểu mẫu thay đổi dữ liệu.
 * - Chuyển giao yêu cầu cho `Router` phân tích URL, kiểm tra `Middleware` và điều phối tới `Controller`.
 */

declare(strict_types=1);

// 1. Đăng ký hàm tự động nạp lớp (Autoloader) cho không gian tên `App\` trỏ vào thư mục `app/`
spl_autoload_register(static function (string $className): void {
    $prefix  = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';

    if (!str_starts_with($className, $prefix)) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $filePath      = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($filePath)) {
        require_once $filePath;
    }
});

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ErrorHandler;
use App\Core\Router;

// 2. Nạp cấu hình ứng dụng và thiết lập múi giờ chuẩn Việt Nam
/** @var array<string, mixed> $appConfig */
$appConfig = require dirname(__DIR__) . '/config/app.php';
date_default_timezone_set((string) ($appConfig['timezone'] ?? 'Asia/Ho_Chi_Minh'));

// 3. Đăng ký bộ xử lý lỗi và ngoại lệ toàn cục
ErrorHandler::register((bool) ($appConfig['debug'] ?? false));

// 4. Khởi tạo Session an toàn và sinh sẵn CSRF Token cho phiên làm việc
/** @var array<string, mixed> $sessionConfig */
$sessionConfig = is_array($appConfig['session'] ?? null) ? $appConfig['session'] : [];
Auth::startSession($sessionConfig);
Csrf::token();

// 4b. Nếu phiên đang đăng nhập: đối chiếu lại với bảng `users` ở mỗi request để thu hồi ngay
//     tài khoản bị khóa/xóa và cập nhật vai trò thực tế (admin bị hạ quyền sẽ mất quyền lập tức).
if (isset($_SESSION['auth_user'])) {
    Auth::revalidateForRequest();
}

// 5. Khởi tạo Router, nạp bảng định tuyến `routes/web.php` và điều phối Request
$router = new Router();
require dirname(__DIR__) . '/routes/web.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri    = $_SERVER['REQUEST_URI'] ?? '/';

$router->dispatch($requestMethod, $requestUri);
