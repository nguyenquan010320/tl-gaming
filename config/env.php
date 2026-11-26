<?php
/**
 * Bộ nạp biến môi trường từ file `.env` thuần PHP (không dùng thư viện ngoài).
 *
 * Vai trò:
 * - Đọc file `.env` nằm ở thư mục gốc dự án (bên ngoài `public/`) để nạp thông tin cấu hình
 *   nhạy cảm như tài khoản cơ sở dữ liệu, môi trường thực thi (development / production).
 * - Đảm bảo thông tin đăng nhập MySQL không bao giờ bị hard-code trong thư mục công khai `public/`.
 */

declare(strict_types=1);

if (!function_exists('envRegistry')) {
    /**
     * Kho lưu trữ biến môi trường nội bộ trong bộ nhớ PHP của từng Request.
     *
     * Giải thích cơ chế:
     * - Trên XAMPP Windows (Apache mod_php), cấu hình `variables_order` trong `php.ini` thường đặt là `GPCS`
     *   (bỏ qua `E`), khiến mảng siêu toàn cục `$_ENV` có thể không được khởi tạo tự động hoặc hàm `getenv()`
     *   bị ảnh hưởng giữa các luồng (Thread-Safe).
     * - Hàm này duy trì một mảng tĩnh nội bộ kết hợp với `$_ENV` và `$_SERVER` để đảm bảo đọc/ghi biến `.env`
     *   luôn chính xác 100% trên cả CLI và Apache.
     *
     * @param string|null $setKey   Tên biến cần lưu (hoặc null nếu chỉ đọc).
     * @param string|null $setValue Giá trị biến cần lưu.
     * @return array<string, string> Bản đồ toàn bộ các biến đã nạp từ `.env`.
     */
    function envRegistry(?string $setKey = null, ?string $setValue = null): array
    {
        /** @var array<string, string> $loaded */
        static $loaded = [];

        if ($setKey !== null && $setValue !== null) {
            $loaded[$setKey] = $setValue;
        }

        return $loaded;
    }
}

if (!function_exists('loadEnvFile')) {
    /**
     * Đọc và phân tích cú pháp file `.env` theo từng dòng `KEY=VALUE`.
     *
     * @param string $filePath Đường dẫn tuyệt đối tới file `.env`.
     * @return void
     */
    function loadEnvFile(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        $registry = envRegistry();

        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Bỏ qua dòng trống hoặc dòng chú thích bắt đầu bằng dấu #
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key   = trim($parts[0]);
            $value = trim($parts[1]);

            // Loại bỏ cặp dấu nháy đơn hoặc nháy kép bao ngoài giá trị nếu có
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '' && !array_key_exists($key, $registry)) {
                // Ưu tiên biến môi trường đã thiết lập sẵn từ hệ điều hành / tiến trình (ví dụ: môi trường test)
                $existing = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
                if ($existing !== false && $existing !== null && $existing !== '') {
                    envRegistry($key, (string) $existing);
                    continue;
                }

                envRegistry($key, $value);
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

if (!function_exists('env')) {
    /**
     * Lấy giá trị biến môi trường đã nạp và chuyển đổi kiểu dữ liệu cơ bản (bool, null).
     *
     * @param string $key     Tên biến môi trường cần lấy (ví dụ: 'DB_HOST', 'APP_DEBUG').
     * @param mixed  $default Giá trị mặc định trả về nếu biến môi trường chưa được thiết lập.
     * @return mixed          Giá trị biến môi trường sau khi chuẩn hóa kiểu.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $registry = envRegistry();
        $val      = $registry[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($val === false || $val === null) {
            return $default;
        }

        if (!is_string($val)) {
            return $val;
        }

        $lower = strtolower($val);
        return match ($lower) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $val,
        };
    }
}
