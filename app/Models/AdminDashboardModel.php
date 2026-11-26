<?php
/**
 * Model Thống kê & Phân tích Kinh doanh Admin (`app/Models/AdminDashboardModel.php`) — Prompt 21.
 *
 * Nguyên tắc kế toán & hiệu năng:
 * - Chỉ thực hiện truy vấn ĐỌC (read-only), tuyệt đối không làm thay đổi dữ liệu CSDL.
 * - Mọi bộ lọc ngày đều dùng nửa khoảng mở `[start, end)` theo múi giờ `Asia/Ho_Chi_Minh`.
 * - Không JOIN `orders` với `order_items` và `payments` cùng lúc rồi SUM (chống nhân bản số liệu).
 * - Doanh thu đơn hoàn tất tính trên `orders.status = 'completed'` bằng `SUM(orders.final_amount)`
 *   phân kỳ theo `orders.created_at` (ngày đặt đơn).
 * - Tiền đã xác nhận thu tính trên bản ghi `payments` mới nhất có `status = 'completed'` của từng đơn
 *   (chống cộng trùng khi 1 đơn có nhiều bản ghi `payments`), phân kỳ theo `COALESCE(confirmed_at, created_at)`.
 * - Sản phẩm bán chạy tính từ snapshot `order_items.quantity` và `order_items.subtotal` của đơn `completed`,
 *   không dùng `products.price` hiện tại và không tính đơn `cancelled`.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

class AdminDashboardModel extends Model
{
    protected string $table = 'orders';

    public const ALLOWED_PRESETS = ['today', '7d', '30d', 'month', 'year', 'custom'];

    /**
     * Số ngày tối đa cho khoảng lọc tùy chọn để đảm bảo hiệu năng truy vấn.
     */
    private const MAX_CUSTOM_DAYS = 366;

    /**
     * Phân tích và chuẩn hóa bộ lọc thời gian từ tham số GET thành nửa khoảng mở `[start, end)`.
     *
     * @param string $presetRaw   Preset (`today`, `7d`, `30d`, `month`, `year`, `custom`).
     * @param string $dateFromRaw Ngày bắt đầu tùy chọn (`Y-m-d`).
     * @param string $dateToRaw   Ngày kết thúc tùy chọn (`Y-m-d`).
     * @return array{
     *   preset: string,
     *   date_from: string,
     *   date_to: string,
     *   start_at: string,
     *   end_at: string,
     *   granularity: string,
     *   label: string,
     *   error: string
     * }
     */
    public static function resolveDateRange(string $presetRaw, string $dateFromRaw = '', string $dateToRaw = ''): array
    {
        $tz    = new DateTimeZone('Asia/Ho_Chi_Minh');
        $now   = new DateTimeImmutable('now', $tz);
        $today = $now->setTime(0, 0, 0);

        $preset = in_array($presetRaw, self::ALLOWED_PRESETS, true) ? $presetRaw : '30d';
        $error  = '';

        if ($preset === 'custom') {
            $startDt = DateTimeImmutable::createFromFormat('!Y-m-d', trim($dateFromRaw), $tz);
            $endDt   = DateTimeImmutable::createFromFormat('!Y-m-d', trim($dateToRaw), $tz);

            $startValid = $startDt !== false && $startDt->format('Y-m-d') === trim($dateFromRaw);
            $endValid   = $endDt !== false && $endDt->format('Y-m-d') === trim($dateToRaw);

            if (!$startValid || !$endValid) {
                $error  = 'Ngày bắt đầu hoặc ngày kết thúc không hợp lệ (định dạng chuẩn YYYY-MM-DD). Đã chuyển về 30 ngày gần nhất.';
                $preset = '30d';
            } elseif ($endDt < $startDt) {
                $error  = 'Ngày kết thúc không được nhỏ hơn ngày bắt đầu. Đã chuyển về 30 ngày gần nhất.';
                $preset = '30d';
            } else {
                $diffDays = (int) $startDt->diff($endDt)->days + 1;
                if ($diffDays > self::MAX_CUSTOM_DAYS) {
                    $error  = 'Khoảng thời gian tùy chọn tối đa 366 ngày để đảm bảo hiệu năng. Đã chuyển về 30 ngày gần nhất.';
                    $preset = '30d';
                } else {
                    $endExclusive = $endDt->modify('+1 day');
                    $granularity  = $diffDays > 92 ? 'month' : 'day';
                    return [
                        'preset'      => 'custom',
                        'date_from'   => $startDt->format('Y-m-d'),
                        'date_to'     => $endDt->format('Y-m-d'),
                        'start_at'    => $startDt->format('Y-m-d H:i:s'),
                        'end_at'      => $endExclusive->format('Y-m-d H:i:s'),
                        'granularity' => $granularity,
                        'label'       => $startDt->format('d/m/Y') . ' – ' . $endDt->format('d/m/Y'),
                        'error'       => '',
                    ];
                }
            }
        }

        if ($preset === 'today') {
            $startDt      = $today;
            $endExclusive = $today->modify('+1 day');
            $endInclusive = $today;
            $granularity  = 'day';
            $label        = 'Hôm nay (' . $today->format('d/m/Y') . ')';
        } elseif ($preset === '7d') {
            $startDt      = $today->modify('-6 days');
            $endExclusive = $today->modify('+1 day');
            $endInclusive = $today;
            $granularity  = 'day';
            $label        = '7 ngày gần nhất';
        } elseif ($preset === 'month') {
            $startDt      = $today->modify('first day of this month');
            $endExclusive = $startDt->modify('+1 month');
            $endInclusive = $endExclusive->modify('-1 day');
            $granularity  = 'day';
            $label        = 'Tháng ' . $today->format('m/Y');
        } elseif ($preset === 'year') {
            $startDt      = $today->setDate((int) $today->format('Y'), 1, 1);
            $endExclusive = $startDt->modify('+1 year');
            $endInclusive = $endExclusive->modify('-1 day');
            $granularity  = 'month';
            $label        = 'Năm ' . $today->format('Y');
        } else {
            // Mặc định 30 ngày gần nhất
            $preset       = '30d';
            $startDt      = $today->modify('-29 days');
            $endExclusive = $today->modify('+1 day');
            $endInclusive = $today;
            $granularity  = 'day';
            $label        = '30 ngày gần nhất';
        }

        return [
            'preset'      => $preset,
            'date_from'   => $startDt->format('Y-m-d'),
            'date_to'     => $endInclusive->format('Y-m-d'),
            'start_at'    => $startDt->format('Y-m-d H:i:s'),
            'end_at'      => $endExclusive->format('Y-m-d H:i:s'),
            'granularity' => $granularity,
            'label'       => $label,
            'error'       => $error,
        ];
    }

    /**
     * Tổng hợp toàn bộ chỉ số KPI chính & bổ sung cho Dashboard (không nhân bản dòng do JOIN).
     *
     * @param string $startAt Mốc bắt đầu (`Y-m-d H:i:s`, inclusive).
     * @param string $endAt   Mốc kết thúc (`Y-m-d H:i:s`, exclusive).
     * @return array<string, int>
     */
    public function getKpiSummary(string $startAt, string $endAt): array
    {
        // 1. Thống kê đơn hàng & doanh thu hoàn tất (trực tiếp trên bảng orders, không JOIN order_items/payments)
        $ordStmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total_orders_in_range,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_orders_in_range,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_orders,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN final_amount ELSE 0 END), 0) AS completed_revenue,
                COUNT(DISTINCT CASE WHEN status = 'completed' AND user_id IS NOT NULL THEN user_id ELSE NULL END) AS active_buying_customers
            FROM orders
            WHERE created_at >= :start_at AND created_at < :end_at
        ");
        $ordStmt->execute([':start_at' => $startAt, ':end_at' => $endAt]);
        $ordRow = $ordStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $completedOrders  = (int) ($ordRow['completed_orders'] ?? 0);
        $completedRevenue = (int) ($ordRow['completed_revenue'] ?? 0);
        $aov              = $completedOrders > 0 ? (int) round($completedRevenue / $completedOrders) : 0;

        // 2. Tiền đã xác nhận thu trong kỳ (chỉ lấy bản ghi `payments` completed mới nhất mỗi đơn -> chống cộng trùng)
        $payStmt = $this->db->prepare("
            SELECT
                COUNT(*) AS confirmed_payment_orders,
                COALESCE(SUM(p.amount), 0) AS confirmed_cash_collected
            FROM payments p
            INNER JOIN (
                SELECT order_id, MAX(id) AS latest_completed_id
                FROM payments
                WHERE status = 'completed'
                GROUP BY order_id
            ) lp ON lp.latest_completed_id = p.id
            WHERE COALESCE(p.confirmed_at, p.created_at) >= :start_at
              AND COALESCE(p.confirmed_at, p.created_at) < :end_at
        ");
        $payStmt->execute([':start_at' => $startAt, ':end_at' => $endAt]);
        $payRow = $payStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 3. Khoản cần hoàn tiền thủ công hiện hành (trên bản ghi payment mới nhất của mỗi đơn)
        $refundRow = $this->db->query("
            SELECT
                COUNT(*) AS manual_refund_orders,
                COALESCE(SUM(p.amount), 0) AS manual_refund_amount
            FROM payments p
            INNER JOIN (
                SELECT order_id, MAX(id) AS latest_id
                FROM payments
                GROUP BY order_id
            ) lp ON lp.latest_id = p.id
            WHERE p.refund_status = 'manual_required'
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        // 4. Tổng đơn đang chờ xử lý hiện tại trên toàn hệ thống (`pending`)
        $currentPending = (int) $this->db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();

        // 5. Thống kê khách hàng (`role = 'customer'`, không gộp Guest)
        $custStmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total_customers,
                COALESCE(SUM(CASE WHEN created_at >= :start_at AND created_at < :end_at THEN 1 ELSE 0 END), 0) AS new_customers_in_range
            FROM users
            WHERE role = 'customer'
        ");
        $custStmt->execute([':start_at' => $startAt, ':end_at' => $endAt]);
        $custRow = $custStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 6. Thống kê sản phẩm & số máy vật lý serial đủ điều kiện bán (18 bước passed)
        $prodRow = $this->db->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END), 0) AS published_products,
                COALESCE(SUM(CASE WHEN status = 'out_of_stock' THEN 1 ELSE 0 END), 0) AS out_of_stock_products
            FROM products
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $sellableWhere = ProductUnitModel::sellableUnitWhereSql('pu');
        $sellableUnits = (int) $this->db->query("
            SELECT COUNT(*)
            FROM product_units pu
            INNER JOIN products p ON p.id = pu.product_id AND p.has_serials = 1
            WHERE {$sellableWhere}
        ")->fetchColumn();

        return [
            'completed_revenue'        => $completedRevenue,
            'completed_orders'         => $completedOrders,
            'cancelled_orders'         => (int) ($ordRow['cancelled_orders'] ?? 0),
            'total_orders_in_range'    => (int) ($ordRow['total_orders_in_range'] ?? 0),
            'pending_orders_in_range'  => (int) ($ordRow['pending_orders_in_range'] ?? 0),
            'current_pending_orders'   => $currentPending,
            'aov'                      => $aov,
            'confirmed_cash_collected' => (int) ($payRow['confirmed_cash_collected'] ?? 0),
            'confirmed_payment_orders' => (int) ($payRow['confirmed_payment_orders'] ?? 0),
            'manual_refund_orders'     => (int) ($refundRow['manual_refund_orders'] ?? 0),
            'manual_refund_amount'     => (int) ($refundRow['manual_refund_amount'] ?? 0),
            'total_customers'          => (int) ($custRow['total_customers'] ?? 0),
            'new_customers_in_range'   => (int) ($custRow['new_customers_in_range'] ?? 0),
            'active_buying_customers'  => (int) ($ordRow['active_buying_customers'] ?? 0),
            'published_products'       => (int) ($prodRow['published_products'] ?? 0),
            'out_of_stock_products'    => (int) ($prodRow['out_of_stock_products'] ?? 0),
            'sellable_serial_units'    => $sellableUnits,
        ];
    }

    /**
     * Tổng hợp chuỗi doanh thu đơn hoàn tất theo ngày hoặc theo tháng, điền đủ mốc 0đ.
     *
     * @param string $startAt     Mốc bắt đầu (`Y-m-d H:i:s`).
     * @param string $endAt       Mốc kết thúc (`Y-m-d H:i:s`, exclusive).
     * @param string $granularity `'day'` hoặc `'month'`.
     * @return array{points: array<int, array{bucket: string, label: string, revenue: int, orders: int}>, has_data: bool}
     */
    public function getRevenueSeries(string $startAt, string $endAt, string $granularity = 'day'): array
    {
        $isMonth   = $granularity === 'month';
        $bucketSql = $isMonth ? "DATE_FORMAT(created_at, '%Y-%m')" : "DATE_FORMAT(created_at, '%Y-%m-%d')";

        $stmt = $this->db->prepare("
            SELECT
                {$bucketSql} AS bucket,
                COUNT(*) AS completed_orders,
                COALESCE(SUM(final_amount), 0) AS revenue
            FROM orders
            WHERE status = 'completed'
              AND created_at >= :start_at
              AND created_at < :end_at
            GROUP BY bucket
            ORDER BY bucket ASC
        ");
        $stmt->execute([':start_at' => $startAt, ':end_at' => $endAt]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['bucket']] = [
                'revenue' => (int) $row['revenue'],
                'orders'  => (int) $row['completed_orders'],
            ];
        }

        $tz     = new DateTimeZone('Asia/Ho_Chi_Minh');
        $cursor = new DateTimeImmutable($startAt, $tz);
        $endDt  = new DateTimeImmutable($endAt, $tz);

        $points  = [];
        $hasData = false;

        if ($isMonth) {
            $cursor = $cursor->modify('first day of this month')->setTime(0, 0, 0);
            while ($cursor < $endDt) {
                $key   = $cursor->format('Y-m');
                $label = 'T' . $cursor->format('m/Y');
                $rev   = $map[$key]['revenue'] ?? 0;
                $cnt   = $map[$key]['orders'] ?? 0;
                if ($rev > 0 || $cnt > 0) {
                    $hasData = true;
                }
                $points[] = ['bucket' => $key, 'label' => $label, 'revenue' => $rev, 'orders' => $cnt];
                $cursor   = $cursor->modify('+1 month');
            }
        } else {
            $cursor = $cursor->setTime(0, 0, 0);
            while ($cursor < $endDt) {
                $key   = $cursor->format('Y-m-d');
                $label = $cursor->format('d/m');
                $rev   = $map[$key]['revenue'] ?? 0;
                $cnt   = $map[$key]['orders'] ?? 0;
                if ($rev > 0 || $cnt > 0) {
                    $hasData = true;
                }
                $points[] = ['bucket' => $key, 'label' => $label, 'revenue' => $rev, 'orders' => $cnt];
                $cursor   = $cursor->modify('+1 day');
            }
        }

        return [
            'points'   => $points,
            'has_data' => $hasData,
        ];
    }

    /**
     * Phân bố số lượng đơn hàng theo 5 trạng thái chuẩn ENUM trong khoảng thời gian chọn.
     *
     * @return array<string, int>
     */
    public function getOrderStatusDistribution(string $startAt, string $endAt): array
    {
        $dist = [
            'pending'   => 0,
            'confirmed' => 0,
            'shipping'  => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];

        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) AS cnt
            FROM orders
            WHERE created_at >= :start_at AND created_at < :end_at
            GROUP BY status
        ");
        $stmt->execute([':start_at' => $startAt, ':end_at' => $endAt]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $st = (string) $row['status'];
            if (array_key_exists($st, $dist)) {
                $dist[$st] = (int) $row['cnt'];
            }
        }

        return $dist;
    }

    /**
     * Top sản phẩm bán chạy theo snapshot `order_items` trong các đơn `completed` thuộc kỳ.
     * Không tính đơn `cancelled` và không dùng `products.price` hiện tại.
     *
     * @param int $limit Số lượng sản phẩm tối đa (mặc định 5).
     * @return array<int, array<string, mixed>>
     */
    public function getTopSellingProducts(string $startAt, string $endAt, int $limit = 5): array
    {
        $stmt = $this->db->prepare("
            SELECT
                oi.product_id,
                MAX(oi.product_sku) AS product_sku,
                MAX(oi.product_name) AS product_name,
                MAX(p.thumbnail) AS thumbnail,
                SUM(oi.quantity) AS sold_quantity,
                SUM(oi.subtotal) AS snapshot_revenue
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE o.status = 'completed'
              AND o.created_at >= :start_at
              AND o.created_at < :end_at
            GROUP BY oi.product_id
            ORDER BY sold_quantity DESC, snapshot_revenue DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':start_at', $startAt, PDO::PARAM_STR);
        $stmt->bindValue(':end_at', $endAt, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, min(10, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lấy tối đa 10 đơn hàng gần đây nhất (không trả email/SĐT đầy đủ ra Dashboard).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentOrders(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT
                o.id,
                o.order_code,
                o.customer_name,
                o.final_amount,
                o.status,
                o.created_at
            FROM orders o
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', max(1, min(10, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Các cảnh báo vận hành thực tế kiểm chứng được từ CSDL (không tạo thông báo giả).
     *
     * @return array<string, int>
     */
    public function getOperationalAlerts(): array
    {
        $pendingOrders = (int) $this->db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();

        $pendingPayments = (int) $this->db->query("
            SELECT COUNT(*)
            FROM payments p
            INNER JOIN (
                SELECT order_id, MAX(id) AS latest_id
                FROM payments
                GROUP BY order_id
            ) lp ON lp.latest_id = p.id
            INNER JOIN orders o ON o.id = p.order_id
            WHERE o.status <> 'cancelled'
              AND p.status = 'pending'
        ")->fetchColumn();

        $manualRefundRow = $this->db->query("
            SELECT
                COUNT(*) AS cnt,
                COALESCE(SUM(p.amount), 0) AS total_amount
            FROM payments p
            INNER JOIN (
                SELECT order_id, MAX(id) AS latest_id
                FROM payments
                GROUP BY order_id
            ) lp ON lp.latest_id = p.id
            WHERE p.refund_status = 'manual_required'
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $sellableWhere = ProductUnitModel::sellableUnitWhereSql('pu');
        $zeroStockPublished = (int) $this->db->query("
            SELECT COUNT(*)
            FROM products p
            WHERE p.status = 'published'
              AND (
                  (p.has_serials = 0 AND p.stock_quantity = 0)
                  OR (
                      p.has_serials = 1
                      AND NOT EXISTS (
                          SELECT 1
                          FROM product_units pu
                          WHERE pu.product_id = p.id
                            AND {$sellableWhere}
                      )
                  )
              )
        ")->fetchColumn();

        $repairingUnits = (int) $this->db->query("SELECT COUNT(*) FROM product_units WHERE status = 'repairing'")->fetchColumn();

        return [
            'pending_orders'       => $pendingOrders,
            'pending_payments'     => $pendingPayments,
            'manual_refund_orders' => (int) ($manualRefundRow['cnt'] ?? 0),
            'manual_refund_amount' => (int) ($manualRefundRow['total_amount'] ?? 0),
            'zero_stock_published' => $zeroStockPublished,
            'repairing_units'      => $repairingUnits,
        ];
    }
}
