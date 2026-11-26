<?php
/**
 * Model Quản lý Khách hàng Admin (`app/Models/AdminCustomerModel.php`) — Prompt 20.
 *
 * Vai trò & bảo mật:
 * - Chỉ truy vấn tài khoản khách hàng (`users.role = 'customer'`), không trộn Admin.
 * - Tuyệt đối không trả `password_hash` hay thông tin phiên/token.
 * - Thống kê số đơn và tổng chi tiêu chỉ dựa trên `orders.user_id = users.id`:
 *   + Không tự ý gộp đơn khách vãng lai (`orders.user_id IS NULL`) dù trùng email/SĐT.
 *   + Tổng chi tiêu chỉ tính trên đơn có `orders.status = 'completed'`, không cộng đơn `cancelled`.
 * - Khóa/mở khóa tài khoản chạy trong Transaction có `SELECT ... FOR UPDATE` + `runTransactionWithDeadlockRetry()`,
 *   không cho Admin tự khóa chính mình, không đổi `role` hay mật khẩu, không xóa lịch sử mua hàng.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DomainException;
use PDO;

class AdminCustomerModel extends Model
{
    protected string $table = 'users';

    public const ALLOWED_STATUSES = ['active', 'locked'];

    /**
     * Đếm tổng số tài khoản khách hàng khớp bộ lọc để phân trang server.
     *
     * @param array<string, mixed> $filters
     * @return int
     */
    public function countAdminCustomers(array $filters = []): int
    {
        [$whereSql, $bindings] = $this->buildAdminWhere($filters);
        $sql  = "SELECT COUNT(*) FROM users u WHERE {$whereSql}";
        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $key => $bind) {
            $stmt->bindValue($key, $bind['val'], $bind['type']);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lấy danh sách khách hàng (`role = 'customer'`) kèm số đơn và tổng chi tiêu từ đơn hoàn tất.
     *
     * @param array<string, mixed> $filters
     * @param string               $sort
     * @param int                  $limit
     * @param int                  $offset
     * @return array<int, array<string, mixed>>
     */
    public function getAdminCustomers(array $filters = [], string $sort = 'newest', int $limit = 15, int $offset = 0): array
    {
        [$whereSql, $bindings] = $this->buildAdminWhere($filters);

        $orderBy = match ($sort) {
            'oldest'      => 'u.created_at ASC, u.id ASC',
            'spent_desc'  => 'total_spent DESC, u.id DESC',
            'orders_desc' => 'total_orders DESC, u.id DESC',
            'name_asc'    => 'u.full_name ASC, u.id ASC',
            default       => 'u.created_at DESC, u.id DESC',
        };

        $sql = "
            SELECT
                u.id,
                u.email,
                u.full_name,
                u.phone,
                u.status,
                u.created_at,
                COALESCE(ord.total_orders, 0) AS total_orders,
                COALESCE(ord.completed_orders, 0) AS completed_orders,
                COALESCE(ord.cancelled_orders, 0) AS cancelled_orders,
                COALESCE(ord.total_spent, 0) AS total_spent
            FROM users u
            LEFT JOIN (
                SELECT
                    user_id,
                    COUNT(*) AS total_orders,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                    SUM(CASE WHEN status = 'completed' THEN final_amount ELSE 0 END) AS total_spent
                FROM orders
                WHERE user_id IS NOT NULL
                GROUP BY user_id
            ) ord ON ord.user_id = u.id
            WHERE {$whereSql}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $key => $bind) {
            $stmt->bindValue($key, $bind['val'], $bind['type']);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lấy thông tin chi tiết 1 khách hàng (`role = 'customer'`) kèm thống kê đơn hàng.
     *
     * @param int $customerId
     * @return array<string, mixed>|null
     */
    public function getCustomerDetail(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT
                u.id,
                u.role,
                u.email,
                u.full_name,
                u.phone,
                u.address,
                u.status,
                u.created_at,
                u.updated_at,
                COALESCE(ord.total_orders, 0) AS total_orders,
                COALESCE(ord.completed_orders, 0) AS completed_orders,
                COALESCE(ord.cancelled_orders, 0) AS cancelled_orders,
                COALESCE(ord.total_spent, 0) AS total_spent
            FROM users u
            LEFT JOIN (
                SELECT
                    user_id,
                    COUNT(*) AS total_orders,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                    SUM(CASE WHEN status = 'completed' THEN final_amount ELSE 0 END) AS total_spent
                FROM orders
                WHERE user_id = :ord_uid
                GROUP BY user_id
            ) ord ON ord.user_id = u.id
            WHERE u.id = :id AND u.role = 'customer'
            LIMIT 1
        ");
        $stmt->execute([
            ':ord_uid' => $customerId,
            ':id'      => $customerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Đếm tổng số đơn hàng gắn trực tiếp với tài khoản khách hàng (`orders.user_id = :uid`).
     */
    public function countCustomerOrders(int $customerId): int
    {
        if ($customerId <= 0) {
            return 0;
        }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM orders WHERE user_id = :uid');
        $stmt->execute([':uid' => $customerId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lấy danh sách đơn hàng của riêng tài khoản khách hàng (không gộp đơn vãng lai), có phân trang server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCustomerOrders(int $customerId, int $limit = 10, int $offset = 0): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT
                o.id,
                o.order_code,
                o.final_amount,
                o.discount_amount,
                o.status,
                o.created_at,
                pay.payment_method,
                pay.status AS payment_status
            FROM orders o
            LEFT JOIN payments pay ON pay.id = (
                SELECT p2.id
                FROM payments p2
                WHERE p2.order_id = o.id
                ORDER BY p2.id DESC
                LIMIT 1
            )
            WHERE o.user_id = :uid
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':uid', $customerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Khóa hoặc mở khóa tài khoản khách hàng (`active` <-> `locked`) trong Transaction có `FOR UPDATE`.
     *
     * Quy tắc bảo mật:
     * - Chỉ cho phép tác động lên tài khoản có `role = 'customer'`.
     * - Không cho Admin tự khóa tài khoản của chính mình.
     * - Không thay đổi `role`, mật khẩu, đơn hàng, giỏ hàng hoặc thanh toán.
     * - Sau khi chuyển sang `locked`, `Auth::revalidate()` sẽ lập tức thu hồi mọi phiên đang đăng nhập của khách.
     *
     * @param int    $customerId   ID khách hàng.
     * @param string $targetStatus Trạng thái đích (`locked` hoặc `active`).
     * @param int    $adminId      ID Admin thực hiện thao tác.
     * @return array{id: int, full_name: string, email: string, old_status: string, new_status: string}
     *
     * @throws DomainException
     */
    public function updateCustomerStatus(int $customerId, string $targetStatus, int $adminId): array
    {
        if ($customerId <= 0 || $adminId <= 0) {
            throw new DomainException('Thông tin khách hàng hoặc quản trị viên không hợp lệ.');
        }
        if (!in_array($targetStatus, self::ALLOWED_STATUSES, true)) {
            throw new DomainException('Trạng thái tài khoản yêu cầu không hợp lệ.');
        }
        if ($customerId === $adminId) {
            throw new DomainException('Quản trị viên không thể tự khóa hoặc thao tác trên tài khoản của chính mình.');
        }

        return $this->runTransactionWithDeadlockRetry(function () use ($customerId, $targetStatus): array {
            $lockStmt = $this->db->prepare(
                'SELECT id, role, email, full_name, status FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $lockStmt->execute([':id' => $customerId]);
            $user = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new DomainException('Không tìm thấy tài khoản khách hàng yêu cầu.');
            }
            if ((string) $user['role'] !== 'customer') {
                throw new DomainException('Không được phép khóa hoặc mở khóa tài khoản Quản trị viên qua mục Khách hàng.');
            }

            $oldStatus = (string) $user['status'];
            if ($oldStatus === $targetStatus) {
                $msg = $targetStatus === 'locked'
                    ? 'Tài khoản khách hàng này hiện đã ở trạng thái bị khóa.'
                    : 'Tài khoản khách hàng này hiện đang hoạt động bình thường.';
                throw new DomainException($msg);
            }

            $updStmt = $this->db->prepare("
                UPDATE users
                SET status = :new_status,
                    updated_at = NOW()
                WHERE id = :id AND role = 'customer' AND status = :old_status
            ");
            $updStmt->execute([
                ':new_status' => $targetStatus,
                ':id'         => $customerId,
                ':old_status' => $oldStatus,
            ]);

            if ($updStmt->rowCount() !== 1) {
                throw new DomainException('Trạng thái tài khoản vừa thay đổi ở một phiên khác. Vui lòng tải lại trang.');
            }

            return [
                'id'         => $customerId,
                'full_name'  => (string) $user['full_name'],
                'email'      => (string) $user['email'],
                'old_status' => $oldStatus,
                'new_status' => $targetStatus,
            ];
        });
    }

    /**
     * Xây dựng điều kiện WHERE an toàn (PDO Prepared Statements) cho danh sách khách hàng.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, array{val: mixed, type: int}>}
     */
    private function buildAdminWhere(array $filters): array
    {
        $conditions = ["u.role = 'customer'"];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $likeVal = '%' . $escaped . '%';
            $conditions[] = '(u.full_name LIKE :kw_name OR u.email LIKE :kw_email OR u.phone LIKE :kw_phone)';
            $bindings[':kw_name']  = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_email'] = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_phone'] = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::ALLOWED_STATUSES, true)) {
            $conditions[]        = 'u.status = :status';
            $bindings[':status'] = ['val' => $status, 'type' => PDO::PARAM_STR];
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $conditions[]           = 'u.created_at >= :date_from';
            $bindings[':date_from'] = ['val' => $dateFrom . ' 00:00:00', 'type' => PDO::PARAM_STR];
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $conditions[]         = 'u.created_at <= :date_to';
            $bindings[':date_to'] = ['val' => $dateTo . ' 23:59:59', 'type' => PDO::PARAM_STR];
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
