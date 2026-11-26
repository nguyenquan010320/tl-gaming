<?php
/**
 * Công cụ CLI kiểm tra kết nối Cơ sở dữ liệu MySQL (PDO Health Check).
 *
 * Vai trò:
 * - Chỉ cho phép thực thi từ dòng lệnh (CLI) để kiểm tra kết nối tới CSDL `tl_gaming`,
 *   phiên bản MySQL/MariaDB, bảng mã utf8mb4 và số lượng bảng hiện có.
 * - Tuyệt đối không in mật khẩu hoặc chuỗi DSN chứa thông tin đăng nhập nhạy cảm ra màn hình.
 *
 * Cách chạy:
 *   E:\xampp\php\php.exe config/check_db.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden: Công cụ kiểm tra kết nối CSDL chỉ được phép chạy từ giao diện dòng lệnh (CLI).');
}

require_once dirname(__DIR__) . '/app/Core/Database.php';

use App\Core\Database;

echo "======================================================================\n";
echo "  KIỂM TRA KẾT NỐI CƠ SỞ DỮ LIỆU MYSQL (PDO) — TL GAMING\n";
echo "======================================================================\n";

try {
    $pdo = Database::getConnection();

    // Kiểm tra thông tin phiên bản máy chủ, tên CSDL hiện hành và bộ mã ký tự
    $meta = $pdo->query("
        SELECT VERSION() AS server_version,
               DATABASE() AS current_db,
               @@character_set_connection AS charset_conn,
               @@collation_connection AS collation_conn
    ")->fetch();

    // Đếm tổng số bảng cơ sở trong schema hiện tại
    $stmtTables = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    ");
    $stmtTables->execute();
    $tableCount = (int) $stmtTables->fetchColumn();

    echo "[TRẠNG THÁI] : KẾT NỐI THÀNH CÔNG (PASS)\n";
    echo "[CSDL HIỆN TẠI]: " . ($meta['current_db'] ?? 'N/A') . "\n";
    echo "[PHIÊN BẢN SQL]: " . ($meta['server_version'] ?? 'N/A') . "\n";
    echo "[BẢNG MÃ KẾT NỐI]: " . ($meta['charset_conn'] ?? 'N/A') . " (" . ($meta['collation_conn'] ?? 'N/A') . ")\n";
    echo "[SỐ BẢNG HIỆN CÓ]: {$tableCount} bảng\n";
    echo "======================================================================\n";
    exit(0);
} catch (Throwable $e) {
    echo "[TRẠNG THÁI] : KẾT NỐI THẤT BẠI (FAIL)\n";
    echo "[LÝ DO LỖI]  : " . $e->getMessage() . "\n";
    echo "======================================================================\n";
    exit(1);
}
