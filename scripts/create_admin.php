<?php
/**
 * Script CLI khởi tạo tài khoản QUẢN TRỊ VIÊN (Admin) — chỉ chạy bằng dòng lệnh, không có route web.
 *
 * Cách dùng (trong thư mục gốc dự án, XAMPP Windows):
 *   E:\xampp\php\php.exe scripts\create_admin.php
 *
 * Bảo mật:
 * - Từ chối chạy nếu không phải môi trường CLI (không thể gọi qua trình duyệt).
 * - Mật khẩu được nhập tương tác (không hiển thị khi gõ), KHÔNG hard-code, không ghi ra log hay màn hình.
 * - Mật khẩu được băm bằng `password_hash()`; chỉ lưu `password_hash` vào bảng `users` với role = 'admin'.
 * - Dùng chung cấu hình kết nối từ `.env` (không in thông tin kết nối).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Script này chỉ được chạy bằng dòng lệnh (CLI).\n");
}

spl_autoload_register(static function (string $className): void {
    $prefix = 'App\\';
    if (!str_starts_with($className, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($className, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use App\Models\UserModel;

/**
 * Đọc một dòng nhập từ bàn phím.
 *
 * @param string $label Dòng nhắc hiển thị cho người dùng.
 * @return string Chuỗi đã trim.
 */
function promptLine(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

/**
 * Đọc mật khẩu mà không hiển thị ký tự đang gõ (Windows dùng PowerShell SecureString; Linux/macOS dùng `stty -echo`).
 *
 * @param string $label Dòng nhắc hiển thị cho người dùng.
 * @return string Mật khẩu người dùng nhập (không trim để giữ nguyên ký tự).
 */
function promptSecret(string $label): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString \'' . str_replace("'", "''", $label) . '\'; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        return rtrim((string) shell_exec($cmd), "\r\n");
    }
    echo $label;
    shell_exec('stty -echo');
    $value = (string) fgets(STDIN);
    shell_exec('stty echo');
    echo "\n";
    return rtrim($value, "\r\n");
}

echo "=== TL GAMING — KHỞI TẠO TÀI KHOẢN ADMIN ===\n";

$fullName = promptLine('Họ và tên Admin: ');
$email    = mb_strtolower(promptLine('Email đăng nhập: '));

if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
    exit("Lỗi: Họ và tên phải từ 2 đến 100 ký tự.\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
    exit("Lỗi: Email không hợp lệ.\n");
}

$password = promptSecret('Mật khẩu (tối thiểu 12 ký tự, có chữ và số): ');
$confirm  = promptSecret('Nhập lại mật khẩu: ');

if ($password !== $confirm) {
    exit("Lỗi: Hai lần nhập mật khẩu không khớp.\n");
}
if (strlen($password) < 12 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
    exit("Lỗi: Mật khẩu Admin cần 12–72 ký tự và gồm cả chữ lẫn số.\n");
}

try {
    $model = new UserModel();
    if ($model->emailExists($email)) {
        exit("Lỗi: Email này đã tồn tại trong bảng users. Không tạo thêm.\n");
    }
    $newId = $model->createAdmin($email, password_hash($password, PASSWORD_DEFAULT), $fullName);
} catch (Throwable $e) {
    error_log('[TL Gaming create_admin] ' . $e->getMessage());
    exit("Lỗi: Không thể kết nối hoặc ghi cơ sở dữ liệu. Kiểm tra MySQL và file .env.\n");
}

if ($newId === null) {
    exit("Lỗi: Email này đã tồn tại. Không tạo thêm.\n");
}

echo "Đã tạo tài khoản Admin thành công (ID: {$newId}, Email: {$email}).\n";
