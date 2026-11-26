<?php
/**
 * Lớp quản lý kết nối Cơ sở dữ liệu MySQL thông qua PDO (Singleton Pattern).
 *
 * Vai trò:
 * - Thiết lập và duy trì duy nhất một kết nối PDO tới MySQL trong suốt vòng đời của một HTTP Request.
 * - Cưỡng bức sử dụng bộ mã `utf8mb4` để lưu trữ đầy đủ tiếng Việt có dấu và ký tự đặc biệt.
 * - Bật `PDO::ERRMODE_EXCEPTION` để chuyển mọi lỗi SQL thành ngoại lệ giúp ErrorHandler xử lý tập trung.
 * - Tắt `PDO::ATTR_EMULATE_PREPARES` để sử dụng Native Prepared Statements của MySQL 8, chống SQL Injection triệt để.
 * - Bảo mật: Khi xảy ra lỗi kết nối, tuyệt đối không tiết lộ mật khẩu hoặc thông tin tài khoản CSDL ra ngoài.
 */

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    /**
     * Thể hiện PDO duy nhất được tái sử dụng trong suốt một Request.
     */
    private static ?PDO $instance = null;

    /**
     * Ngăn khởi tạo trực tiếp từ bên ngoài bằng toán tử `new`.
     */
    private function __construct()
    {
    }

    /**
     * Ngăn nhân bản đối tượng kết nối.
     */
    private function __clone()
    {
    }

    /**
     * Lấy đối tượng kết nối PDO tới MySQL (khởi tạo nếu chưa tồn tại).
     *
     * @return PDO Đối tượng PDO đã cấu hình chuẩn utf8mb4 và Native Prepared Statements.
     * @throws RuntimeException Khi không thể kết nối tới máy chủ MySQL.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/config/database.php';

        $host      = (string) ($config['host'] ?? '127.0.0.1');
        $port      = (int) ($config['port'] ?? 3306);
        $database  = (string) ($config['database'] ?? 'tl_gaming');
        $username  = (string) ($config['username'] ?? 'root');
        $password  = (string) ($config['password'] ?? '');
        $charset   = (string) ($config['charset'] ?? 'utf8mb4');
        $collation = (string) ($config['collation'] ?? 'utf8mb4_unicode_ci');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        // Tương thích đa phiên bản PHP 8.2 -> 8.5+:
        // Từ PHP 8.4/8.5, hằng số PDO::MYSQL_ATTR_INIT_COMMAND chuyển sang Pdo\Mysql::ATTR_INIT_COMMAND.
        // Kiểm tra sự tồn tại của lớp Pdo\Mysql giúp tránh lỗi E_DEPRECATED trên PHP 8.5 khi ErrorHandler đang bật.
        $initCommandAttr = class_exists(\Pdo\Mysql::class)
            ? \Pdo\Mysql::ATTR_INIT_COMMAND
            : PDO::MYSQL_ATTR_INIT_COMMAND;

        $options = [
            // Ném ngoại lệ PDOException khi có lỗi truy vấn SQL
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Mặc định trả về mảng kết hợp (associative array) khớp tên cột trong bảng
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Tắt mô phỏng Prepared Statement ở tầng PHP, dùng Prepared Statement thật của MySQL Server
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Giữ nguyên kiểu số nguyên / số thực từ MySQL khi driver hỗ trợ
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // Thiết lập bộ mã ký tự và bảng đối chiếu ngay khi mở phiên kết nối
            $initCommandAttr             => sprintf('SET NAMES %s COLLATE %s', $charset, $collation),
        ];

        try {
            self::$instance = new PDO($dsn, $username, $password, $options);
            // Đồng bộ múi giờ MySQL/MariaDB về +07:00 (Asia/Ho_Chi_Minh) khớp tuyệt đối với PHP
            self::$instance->exec("SET time_zone = '+07:00'");
            return self::$instance;
        } catch (PDOException $e) {
            // Ghi nhật ký lỗi nội bộ phục vụ kiểm tra kỹ thuật (tuyệt đối không ghi mật khẩu vào log)
            error_log(sprintf(
                '[TL Gaming Database] Lỗi kết nối MySQL (Host: %s, Port: %d, DB: %s, Code: %s)',
                $host,
                $port,
                $database,
                (string) $e->getCode()
            ));

            // Bọc ngoại lệ để không bao giờ làm lộ thông tin nhạy cảm (username/password) trong Stack Trace
            throw new RuntimeException(
                'Không thể kết nối tới cơ sở dữ liệu MySQL. Vui lòng kiểm tra dịch vụ MySQL trên XAMPP và cấu hình trong file .env.',
                (int) $e->getCode()
            );
        }
    }

    /**
     * Kiểm tra độc lập trạng thái kết nối PDO tới MySQL (tách biệt hoàn toàn với các truy vấn bảng nghiệp vụ).
     *
     * @return bool Trả về true nếu kết nối PDO tới cơ sở dữ liệu `tl_gaming` hoạt động bình thường.
     */
    public static function isConnected(): bool
    {
        try {
            self::getConnection();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
