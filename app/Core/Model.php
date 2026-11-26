<?php
/**
 * Lớp Model nền tảng (Base Model) cho toàn bộ các Model nghiệp vụ của TL Gaming.
 *
 * Vai trò:
 * - Cung cấp kết nối PDO dùng chung thông qua `Database::getConnection()`.
 * - Cung cấp các phương thức trợ giúp truy vấn an toàn bằng Prepared Statement (`fetchAll`, `fetchOne`, `fetchColumn`).
 * - Hỗ trợ quản lý Transaction (`beginTransaction`, `commit`, `rollBack`) cho các nghiệp vụ quan trọng
 *   như đặt hàng khóa serial (`SELECT ... FOR UPDATE`), áp dụng voucher và hủy đơn hoàn kho.
 */

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

abstract class Model
{
    /**
     * Đối tượng kết nối PDO làm việc với cơ sở dữ liệu.
     */
    protected PDO $db;

    /**
     * Tên bảng chính trong cơ sở dữ liệu ứng với Model con.
     */
    protected string $table = '';

    /**
     * Khởi tạo Model và gắn kết nối PDO.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->db = $pdo ?? Database::getConnection();
    }

    /**
     * Chuẩn bị và thực thi câu lệnh SQL với tham số truyền qua Prepared Statement.
     *
     * @param string               $sql    Câu lệnh SQL có sử dụng placeholder (:param hoặc ?).
     * @param array<int|string, mixed> $params Mảng giá trị truyền vào placeholder.
     * @return PDOStatement                Đối tượng kết quả thực thi.
     */
    protected function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Truy vấn và trả về danh sách nhiều bản ghi dưới dạng mảng kết hợp.
     *
     * @param string               $sql    Câu lệnh SELECT.
     * @param array<int|string, mixed> $params Tham số ràng buộc cho câu lệnh.
     * @return array<int, array<string, mixed>> Danh sách các dòng kết quả.
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Truy vấn và trả về một bản ghi duy nhất (hoặc null nếu không tìm thấy).
     *
     * @param string               $sql    Câu lệnh SELECT.
     * @param array<int|string, mixed> $params Tham số ràng buộc cho câu lệnh.
     * @return array<string, mixed>|null   Bản ghi tìm thấy hoặc null.
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Truy vấn và trả về giá trị của cột đầu tiên trên dòng đầu tiên (ví dụ: COUNT(*), SUM(...)).
     *
     * @param string               $sql    Câu lệnh SELECT.
     * @param array<int|string, mixed> $params Tham số ràng buộc cho câu lệnh.
     * @return mixed                       Giá trị cột đầu tiên hoặc false nếu không có dòng nào.
     */
    protected function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * Nhận biết ngoại lệ deadlock hoặc lock wait timeout của MySQL/MariaDB
     * (SQLSTATE `40001` hoặc mã lỗi driver `1213` / `1205`).
     */
    public static function isDeadlockException(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }

        $sqlState   = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

        return $sqlState === '40001' || $driverCode === 1213 || $driverCode === 1205;
    }

    /**
     * Thực thi toàn bộ một transaction với cơ chế retry có giới hạn khi gặp deadlock (`40001` / `1213` / `1205`).
     *
     * Quy tắc an toàn:
     * - Chỉ retry toàn bộ transaction sau khi đã `rollBack()` sạch sẽ; không bao giờ retry một câu SQL lẻ.
     * - Nếu đang ở trong transaction cha (`inTransaction() === true`), gọi thẳng `$callback()` để tránh transaction lồng nhau.
     *
     * @template T
     * @param callable(): T $callback
     * @param int           $maxAttempts Số lần thử tối đa (mặc định 3).
     * @return T
     */
    protected function runTransactionWithDeadlockRetry(callable $callback, int $maxAttempts = 3): mixed
    {
        $attempts = max(1, $maxAttempts);

        if ($this->db->inTransaction()) {
            return $callback();
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->db->beginTransaction();
            try {
                $result = $callback();
                $this->db->commit();
                return $result;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                if (self::isDeadlockException($e) && $attempt < $attempts) {
                    error_log(sprintf(
                        '[TL Gaming DB] Phát hiện deadlock/lock timeout (lần %d/%d), thử lại toàn bộ transaction: %s',
                        $attempt,
                        $attempts,
                        $e->getMessage()
                    ));
                    usleep(random_int(15000, 60000));
                    continue;
                }

                throw $e;
            }
        }

        throw new PDOException('Không thể hoàn tất transaction sau số lần thử lại tối đa.');
    }
}
