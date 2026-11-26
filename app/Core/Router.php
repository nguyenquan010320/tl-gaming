<?php
/**
 * Lớp Router phụ trách định tuyến yêu cầu HTTP (GET / POST) tới đúng Controller và Action.
 *
 * Vai trò:
 * - Đăng ký các đường dẫn tĩnh và đường dẫn động có tham số (ví dụ: `/san-pham/{slug}`).
 * - Cho phép gắn danh sách Middleware kiểm soát bảo mật trước khi gọi Controller (ví dụ: `AdminMiddleware`, `CsrfMiddleware`).
 * - Tự động chuẩn hóa đường dẫn URI để hoạt động ổn định cả khi chạy với VirtualHost (`DocumentRoot = public/`)
 *   hoặc khi chạy trong thư mục con của XAMPP (`/tl-gaming/public/` hoặc `/tl-gaming/`).
 * - Trả về mã HTTP 404 cùng trang giao diện `errors/404` khi người dùng truy cập URL không tồn tại.
 */

declare(strict_types=1);

namespace App\Core;

class Router
{
    /**
     * Danh sách các route đã đăng ký.
     *
     * @var array<int, array{
     *     method: string,
     *     path: string,
     *     regex: string,
     *     handler: array{0: class-string, 1: string}|callable,
     *     middlewares: array<int, class-string>
     * }>
     */
    private array $routes = [];

    /**
     * Đăng ký một route xử lý phương thức HTTP GET.
     *
     * @param string                                   $path        Đường dẫn URL (ví dụ: '/', '/san-pham', '/admin').
     * @param array{0: class-string, 1: string}|callable $handler     Mảng [ControllerClass::class, 'methodName'] hoặc hàm xử lý.
     * @param array<int, class-string>                 $middlewares Danh sách lớp Middleware chạy trước Controller.
     * @return void
     */
    public function get(string $path, array|callable $handler, array $middlewares = []): void
    {
        $this->addRoute('GET', $path, $handler, $middlewares);
    }

    /**
     * Đăng ký một route xử lý phương thức HTTP POST (mặc định gắn sẵn CsrfMiddleware để bảo vệ dữ liệu).
     *
     * @param string                                   $path        Đường dẫn URL.
     * @param array{0: class-string, 1: string}|callable $handler     Mảng [ControllerClass::class, 'methodName'] hoặc hàm xử lý.
     * @param array<int, class-string>                 $middlewares Danh sách lớp Middleware bổ sung.
     * @return void
     */
    public function post(string $path, array|callable $handler, array $middlewares = []): void
    {
        $this->addRoute('POST', $path, $handler, $middlewares);
    }

    /**
     * Lưu cấu hình route và biên dịch đường dẫn chứa tham số `{param}` thành biểu thức chính quy (Regex).
     *
     * @param string                                   $method      Phương thức HTTP ('GET' hoặc 'POST').
     * @param string                                   $path        Mẫu đường dẫn URL.
     * @param array{0: class-string, 1: string}|callable $handler     Bộ xử lý đích.
     * @param array<int, class-string>                 $middlewares Danh sách Middleware.
     * @return void
     */
    private function addRoute(string $method, string $path, array|callable $handler, array $middlewares): void
    {
        $normalizedPath = $this->normalizePath($path);

        // Chuyển đổi tham số động dạng {slug} hoặc {id} thành nhóm bắt có đặt tên (Named Capturing Group)
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $matches): string => '(?P<' . $matches[1] . '>[^/]+)',
            $normalizedPath
        );

        $regex = '#^' . $pattern . '$#u';

        $this->routes[] = [
            'method'      => strtoupper($method),
            'path'        => $normalizedPath,
            'regex'       => $regex,
            'handler'     => $handler,
            'middlewares' => $middlewares,
        ];
    }

    /**
     * Phân tích HTTP Request hiện tại, đối chiếu với bảng định tuyến và gọi Controller tương ứng.
     *
     * @param string $requestMethod Phương thức HTTP thực tế (GET, POST...).
     * @param string $requestUri    Chuỗi REQUEST_URI nguyên bản từ máy chủ web.
     * @return void
     */
    public function dispatch(string $requestMethod, string $requestUri): void
    {
        $method = strtoupper($requestMethod);
        $path   = $this->extractCleanPath($requestUri);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches)) {
                // Lọc lấy các tham số URL có tên (Named Parameters) để truyền vào phương thức Controller
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                // 1. Thực thi lần lượt các Middleware được gắn trên route
                foreach ($route['middlewares'] as $middlewareClass) {
                    $middleware = new $middlewareClass();
                    if (method_exists($middleware, 'handle')) {
                        $middleware->handle();
                    }
                }

                // 2. Khởi tạo Controller và gọi Action tương ứng
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$controllerClass, $action] = $handler;
                    $controller = new $controllerClass();
                    call_user_func_array([$controller, $action], $params);
                    return;
                }

                call_user_func_array($handler, $params);
                return;
            }
        }

        // Nếu không khớp bất kỳ route nào -> Trả về trang lỗi 404 Not Found
        $this->handleNotFound($path);
    }

    /**
     * Tách lấy đường dẫn URI sạch (loại bỏ Query String và tiền tố thư mục con nếu chạy trong htdocs/tl-gaming).
     *
     * @param string $requestUri Chuỗi `$_SERVER['REQUEST_URI']`.
     * @return string            Đường dẫn chuẩn hóa bắt đầu bằng dấu `/` (ví dụ: `/`, `/san-pham`, `/admin`).
     */
    private function extractCleanPath(string $requestUri): string
    {
        $parsedPath = parse_url($requestUri, PHP_URL_PATH);
        $rawPath    = is_string($parsedPath) ? rawurldecode($parsedPath) : '/';

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir  = rtrim(dirname($scriptName), '/');

        // Nếu truy cập qua thư mục con (ví dụ /tl-gaming/public/san-pham hoặc /tl-gaming/san-pham), cắt bỏ tiền tố thư mục
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($rawPath, $scriptDir)) {
            $rawPath = substr($rawPath, strlen($scriptDir));
        } else {
            $projectRootDir = rtrim(dirname($scriptDir), '/');
            if ($projectRootDir !== '' && $projectRootDir !== '/' && str_starts_with($rawPath, $projectRootDir)) {
                $rawPath = substr($rawPath, strlen($projectRootDir));
            }
        }

        return $this->normalizePath($rawPath);
    }

    /**
     * Chuẩn hóa đường dẫn luôn bắt đầu bằng `/` và không có dấu `/` dư ở cuối (trừ trang gốc `/`).
     *
     * @param string $path Đường dẫn đầu vào.
     * @return string      Đường dẫn đã chuẩn hóa.
     */
    private function normalizePath(string $path): string
    {
        $trimmed = '/' . trim($path, '/');
        return $trimmed === '//' ? '/' : $trimmed;
    }

    /**
     * Xử lý trường hợp đường dẫn không tồn tại (HTTP 404 Not Found).
     *
     * @param string $requestedPath Đường dẫn người dùng vừa yêu cầu.
     * @return void
     */
    private function handleNotFound(string $requestedPath): void
    {
        http_response_code(404);
        View::render('errors/404', [
            'pageTitle'     => '404 - Không tìm thấy trang | TL Gaming',
            'requestedPath' => $requestedPath,
        ]);
    }
}
