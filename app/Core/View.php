<?php
/**
 * Lớp View phụ trách hiển thị giao diện HTML, lồng ghép Layout chung và chống tấn công XSS.
 *
 * Vai trò:
 * - Tách biệt hoàn toàn tầng hiển thị (Presentation) khỏi tầng điều khiển (Controller) và dữ liệu (Model).
 * - Nạp tệp giao diện con (`app/Views/...`) và đặt vào bên trong bộ khung Layout dùng chung (`app/Views/layouts/main.php`).
 * - Cung cấp hàm tĩnh `View::e()` để mã hóa ký tự HTML đặc biệt (HTML Escaping) trước khi xuất ra trình duyệt,
 *   ngăn chặn triệt để lỗ hổng Cross-Site Scripting (XSS).
 * - Cung cấp các hàm tiện ích định dạng tiền tệ VND nguyên (`View::formatVnd()`) và tạo đường dẫn URL chuẩn.
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class View
{
    /**
     * Đường dẫn gốc tới thư mục chứa các tệp giao diện `app/Views`.
     */
    private static string $viewsBasePath = '';

    /**
     * Lấy đường dẫn gốc tới thư mục `app/Views`.
     *
     * @return string Đường dẫn tuyệt đối đến `app/Views`.
     */
    private static function getBasePath(): string
    {
        if (self::$viewsBasePath === '') {
            self::$viewsBasePath = dirname(__DIR__) . '/Views';
        }
        return self::$viewsBasePath;
    }

    /**
     * Kết xuất (render) một tệp View và nhúng vào Layout chung.
     *
     * @param string               $viewPath Đường dẫn tương đối của View trong `app/Views` (ví dụ: 'home/index').
     * @param array<string, mixed> $data     Mảng dữ liệu truyền từ Controller sang View.
     * @param string|null          $layout   Tên layout trong `app/Views/layouts` (truyền null nếu không dùng layout).
     * @return void
     */
    public static function render(string $viewPath, array $data = [], ?string $layout = 'main'): void
    {
        $viewFile = self::getBasePath() . '/' . trim($viewPath, '/') . '.php';
        if (!is_file($viewFile)) {
            throw new RuntimeException("Không tìm thấy tệp giao diện View: {$viewPath}");
        }

        // Giải nén mảng dữ liệu với cờ EXTR_SKIP để không ghi đè các biến cục bộ của hàm render
        extract($data, EXTR_SKIP);

        // Bắt đầu bộ đệm đầu ra (Output Buffering) để thu thập nội dung của View con
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        // Nếu không sử dụng Layout thì xuất thẳng nội dung View
        if ($layout === null || $layout === '') {
            echo $content;
            return;
        }

        $layoutFile = self::getBasePath() . '/layouts/' . trim($layout, '/') . '.php';
        if (!is_file($layoutFile)) {
            throw new RuntimeException("Không tìm thấy tệp bố cục Layout: {$layout}");
        }

        require $layoutFile;
    }

    /**
     * Escape dữ liệu đầu ra an toàn chống tấn công XSS (Cross-Site Scripting).
     * Mọi chuỗi văn bản động hiển thị trong View bắt buộc đi qua hàm này.
     *
     * @param string|int|float|null $value Giá trị cần hiển thị ra HTML.
     * @return string                      Chuỗi đã được mã hóa an toàn với UTF-8.
     */
    public static function e(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Định dạng số tiền nguyên Việt Nam Đồng (BIGINT UNSIGNED) sang chuỗi dễ đọc (ví dụ: 14.900.000 ₫).
     *
     * @param int|string $amountVnd Số tiền nguyên VND.
     * @return string               Chuỗi tiền tệ định dạng chuẩn Việt Nam.
     */
    public static function formatVnd(int|string $amountVnd): string
    {
        $number = (int) $amountVnd;
        return number_format($number, 0, ',', '.') . ' ₫';
    }

    /**
     * Tạo đường dẫn URL nội bộ tương thích cả khi chạy bằng VirtualHost (`public/`) hoặc thư mục con XAMPP.
     *
     * @param string $path Đường dẫn tương đối (ví dụ: '/san-pham', '/assets/css/style.css').
     * @return string      Đường dẫn URL chuẩn hóa.
     */
    public static function url(string $path = '/'): string
    {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        // Chuẩn hóa dấu '\' từ dirname() trên Windows khi chạy ở root '/' (vd: VirtualHost hoặc php -S)
        $baseDir    = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        // Nếu chạy qua thư mục con trên XAMPP (ví dụ /tl-gaming/public/index.php), giữ tiền tố thư mục
        if ($baseDir === '' || $baseDir === '.') {
            $baseDir = '';
        }

        $normalizedPath = '/' . ltrim($path, '/');
        if ($normalizedPath === '/') {
            return $baseDir !== '' ? $baseDir . '/' : '/';
        }

        return $baseDir . $normalizedPath;
    }

    /**
     * Tạo đường dẫn URL an toàn cho hình ảnh sản phẩm từ CSDL, tự động thay thế bằng ảnh mặc định
     * nếu đường dẫn trống hoặc tệp vật lý chưa tồn tại trong thư mục `public/`.
     *
     * @param string|null $imagePath Đường dẫn lưu trong cột `thumbnail` hoặc `image_url` (ví dụ: '/uploads/products/pc-gaming-01.webp').
     * @param string      $fallback  Đường dẫn ảnh dự phòng trong `public/`.
     * @return string                Đường dẫn URL hợp lệ để gán vào thuộc tính `src` của thẻ `<img>`.
     */
    public static function imageUrl(?string $imagePath, string $fallback = '/assets/images/placeholder-product.svg'): string
    {
        $cleanPath = trim((string) $imagePath);
        if ($cleanPath === '') {
            return self::url($fallback);
        }

        // Nếu là đường dẫn nội bộ bắt đầu bằng '/', kiểm tra tệp tồn tại thực tế trong thư mục public/
        if (!str_starts_with($cleanPath, 'http://') && !str_starts_with($cleanPath, 'https://')) {
            $relativePublicPath = '/' . ltrim($cleanPath, '/');
            $publicRoot         = dirname(__DIR__, 2) . '/public';
            if (is_file($publicRoot . $relativePublicPath)) {
                return self::url($relativePublicPath);
            }
            return self::url($fallback);
        }

        return $cleanPath;
    }
}
