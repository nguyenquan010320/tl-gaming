<?php
/**
 * UserModel — tầng truy cập dữ liệu bảng `users` (tài khoản khách hàng & quản trị viên).
 *
 * Vai trò trong kiến trúc MVC:
 * - Là nơi DUY NHẤT chứa câu lệnh SQL liên quan đến bảng `users`; Controller và View không viết SQL.
 * - Mọi truy vấn đều dùng PDO Prepared Statements (tham số ràng buộc), không nối chuỗi dữ liệu người dùng vào SQL.
 * - Tên cột bám đúng `database/tl_gaming.sql`:
 *   `id`, `role` ENUM('customer','admin'), `email` (UNIQUE `uq_users_email`), `password_hash`,
 *   `full_name`, `phone`, `address`, `status` ENUM('active','locked'), `created_at`, `updated_at`.
 * - Không có hàm nào cho phép đổi `role` từ dữ liệu người dùng: tài khoản đăng ký luôn là 'customer';
 *   tài khoản admin chỉ được tạo bằng script CLI `scripts/create_admin.php`.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDOException;

class UserModel extends Model
{
    /**
     * Tên bảng ứng với Model này.
     */
    protected string $table = 'users';

    /**
     * Danh sách cột được phép trả về (không bao giờ SELECT * để tránh lộ dữ liệu ngoài ý muốn).
     * `password_hash` chỉ được lấy trong các hàm cần xác thực mật khẩu.
     */
    private const PUBLIC_COLUMNS = 'id, role, email, full_name, phone, address, status, created_at, updated_at';

    /**
     * Tìm tài khoản theo email để xác thực đăng nhập (có kèm `password_hash`).
     *
     * @param string $email Email đã chuẩn hóa chữ thường.
     * @return array<string, mixed>|null Bản ghi người dùng hoặc null nếu không tồn tại.
     */
    public function findByEmailForLogin(string $email): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::PUBLIC_COLUMNS . ', password_hash FROM users WHERE email = :email LIMIT 1',
            ['email' => $email]
        );
    }

    /**
     * Tìm tài khoản theo khóa chính (không kèm `password_hash`).
     * Dùng để kiểm tra lại trạng thái/vai trò thực tế trong CSDL ở mỗi request đã đăng nhập.
     *
     * @param int $id Khóa chính người dùng (lấy từ Session, không bao giờ từ GET/POST).
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Tìm tài khoản theo ID kèm theo mật khẩu băm để phục vụ kiểm tra xác thực và thu hồi phiên (Auth::revalidate).
     *
     * @param int $id Khóa chính người dùng.
     * @return array<string, mixed>|null Bản ghi người dùng có kèm `password_hash`.
     */
    public function findByIdForAuth(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::PUBLIC_COLUMNS . ', password_hash FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Lấy mật khẩu băm của một tài khoản theo `id` để xác minh mật khẩu cũ khi đổi mật khẩu.
     *
     * @param int $id Khóa chính người dùng.
     * @return string|null Chuỗi băm hoặc null nếu không tìm thấy.
     */
    public function getPasswordHash(int $id): ?string
    {
        $hash = $this->fetchColumn('SELECT password_hash FROM users WHERE id = :id LIMIT 1', ['id' => $id]);
        return is_string($hash) ? $hash : null;
    }

    /**
     * Kiểm tra email đã tồn tại hay chưa (chỉ phục vụ phản hồi sớm cho người dùng;
     * ràng buộc chính vẫn là UNIQUE `uq_users_email` của MySQL để chống đăng ký đồng thời).
     *
     * @param string $email Email đã chuẩn hóa chữ thường.
     * @return bool True nếu đã tồn tại.
     */
    public function emailExists(string $email): bool
    {
        return (int) $this->fetchColumn('SELECT COUNT(*) FROM users WHERE email = :email', ['email' => $email]) > 0;
    }

    /**
     * Tạo tài khoản KHÁCH HÀNG mới.
     *
     * Bảo mật: vai trò bị gán cứng là 'customer' và trạng thái 'active' ngay trong SQL; hàm KHÔNG nhận
     * tham số role/status nên request từ trình duyệt không thể ép tạo tài khoản admin.
     *
     * @param string      $email        Email đã chuẩn hóa.
     * @param string      $passwordHash Chuỗi băm từ `password_hash()`.
     * @param string      $fullName     Họ và tên.
     * @param string|null $phone        Số điện thoại đã chuẩn hóa.
     * @return int|null ID tài khoản mới, hoặc null nếu email đã tồn tại (vi phạm UNIQUE).
     * @throws PDOException Với các lỗi CSDL khác ngoài trùng email.
     */
    public function createCustomer(string $email, string $passwordHash, string $fullName, ?string $phone): ?int
    {
        try {
            $this->query(
                "INSERT INTO users (role, email, password_hash, full_name, phone, status)
                 VALUES ('customer', :email, :password_hash, :full_name, :phone, 'active')",
                [
                    'email'         => $email,
                    'password_hash' => $passwordHash,
                    'full_name'     => $fullName,
                    'phone'         => $phone,
                ]
            );
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            // Mã lỗi SQLSTATE 23000 + mã MySQL 1062 = Duplicate entry (trùng khóa UNIQUE email)
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Tạo tài khoản ADMIN — chỉ được gọi từ script CLI `scripts/create_admin.php`, không có route web nào gọi hàm này.
     *
     * @param string $email        Email đã chuẩn hóa.
     * @param string $passwordHash Chuỗi băm mật khẩu.
     * @param string $fullName     Họ và tên.
     * @return int|null ID tài khoản mới hoặc null nếu email đã tồn tại.
     */
    public function createAdmin(string $email, string $passwordHash, string $fullName): ?int
    {
        try {
            $this->query(
                "INSERT INTO users (role, email, password_hash, full_name, status)
                 VALUES ('admin', :email, :password_hash, :full_name, 'active')",
                ['email' => $email, 'password_hash' => $passwordHash, 'full_name' => $fullName]
            );
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Cập nhật thông tin cá nhân của CHÍNH người dùng đang đăng nhập.
     * Câu lệnh chỉ chạm các cột `full_name`, `phone`, `address` và luôn khóa theo `id` lấy từ Session;
     * không thể sửa `role`, `status`, `email` hay tài khoản người khác.
     *
     * @param int         $id       ID người dùng hiện tại (từ Session).
     * @param string      $fullName Họ và tên mới.
     * @param string|null $phone    Số điện thoại mới.
     * @param string|null $address  Địa chỉ mới.
     * @return void
     */
    public function updateProfile(int $id, string $fullName, ?string $phone, ?string $address): void
    {
        $this->query(
            'UPDATE users SET full_name = :full_name, phone = :phone, address = :address WHERE id = :id',
            ['full_name' => $fullName, 'phone' => $phone, 'address' => $address, 'id' => $id]
        );
    }

    /**
     * Cập nhật mật khẩu băm mới cho tài khoản (đổi mật khẩu hoặc nâng cấp thuật toán băm).
     *
     * @param int    $id           ID người dùng.
     * @param string $passwordHash Chuỗi băm mới từ `password_hash()`.
     * @return void
     */
    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $this->query('UPDATE users SET password_hash = :hash WHERE id = :id', ['hash' => $passwordHash, 'id' => $id]);
    }
}
