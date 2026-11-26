<?php
/**
 * Giao diện Danh sách Đơn hàng Quản trị (`app/Views/admin/orders/index.php`) — Prompt 19.
 *
 * Hiển thị:
 * - Mã đơn, ngày đặt, tên khách hàng, SĐT (che bớt ở danh sách, đầy đủ tại chi tiết),
 *   tổng tiền, hình thức nhận hàng, phương thức thanh toán, trạng thái thanh toán, trạng thái đơn, nút xem chi tiết.
 * - Tìm kiếm mã đơn / tên khách hàng, lọc khoảng ngày, lọc trạng thái đơn, lọc COD/Chuyển khoản,
 *   lọc trạng thái thanh toán (kể cả cần hoàn tiền thủ công), sắp xếp whitelist và phân trang server.
 */

declare(strict_types=1);

use App\Core\View;
use App\Models\AdminOrderModel;

/** @var array<int, array<string, mixed>> $orders */
/** @var array<string, string> $filters */
/** @var string $sort */
/** @var array<string, int> $pagination */

$orderStatusBadges = [
    'pending'   => ['text' => 'Chờ xác nhận', 'class' => 'adm-badge-warn'],
    'confirmed' => ['text' => 'Đã xác nhận', 'class' => 'adm-badge-active'],
    'shipping'  => ['text' => 'Đang giao / Xử lý', 'class' => 'adm-badge-warn'],
    'completed' => ['text' => 'Hoàn tất', 'class' => 'adm-badge-active'],
    'cancelled' => ['text' => 'Đã hủy', 'class' => 'adm-badge-danger'],
];

$paymentStatusBadges = [
    'pending'   => ['text' => 'Chưa thanh toán', 'class' => 'adm-badge-warn'],
    'completed' => ['text' => 'Đã thanh toán', 'class' => 'adm-badge-active'],
    'failed'    => ['text' => 'Thất bại', 'class' => 'adm-badge-danger'],
    'refunded'  => ['text' => 'Đã hoàn tiền', 'class' => 'adm-badge-hidden'],
];

$totalItems = (int) ($pagination['total_items'] ?? 0);
$page       = (int) ($pagination['page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);

$buildQueryUrl = static function (int $targetPage) use ($filters, $sort): string {
    $params = array_filter([
        'q'             => $filters['keyword'] ?? '',
        'trang-thai'    => $filters['status'] ?? '',
        'pttt'          => $filters['payment_method'] ?? '',
        'tt-thanh-toan' => $filters['payment_status'] ?? '',
        'tu-ngay'       => $filters['date_from'] ?? '',
        'den-ngay'      => $filters['date_to'] ?? '',
        'sap-xep'       => $sort !== 'newest' ? $sort : '',
        'trang'         => $targetPage > 1 ? (string) $targetPage : '',
    ], static fn($v): bool => $v !== '');

    return View::url('/admin/don-hang' . ($params !== [] ? '?' . http_build_query($params) : ''));
};
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Quản lý đơn hàng</h1>
        <p class="admin-page-subtitle">
            Tổng cộng <strong><?= View::e((string) $totalItems) ?></strong> đơn hàng khớp bộ lọc.
            Trạng thái đơn và trạng thái thanh toán được quản lý tách biệt theo quy trình đối soát chặt chẽ.
        </p>
    </div>
</div>

<div class="admin-card">
    <form class="admin-filter-bar adm-order-filter-bar" method="GET" action="<?= View::e(View::url('/admin/don-hang')) ?>">
        <div class="adm-field">
            <label for="orderSearch">Tìm kiếm (Mã đơn / Tên khách)</label>
            <input type="text"
                   id="orderSearch"
                   name="q"
                   class="adm-input"
                   value="<?= View::e($filters['keyword'] ?? '') ?>"
                   placeholder="Ví dụ: TLG-2026... hoặc Nguyễn Văn A">
        </div>

        <div class="adm-field">
            <label for="orderStatusFilter">Trạng thái đơn</label>
            <select id="orderStatusFilter" name="trang-thai" class="adm-select">
                <option value="">Tất cả trạng thái</option>
                <?php foreach (AdminOrderModel::ORDER_STATUS_LABELS as $stKey => $stLabel): ?>
                    <option value="<?= View::e($stKey) ?>" <?= ($filters['status'] ?? '') === $stKey ? 'selected' : '' ?>>
                        <?= View::e($stLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="adm-field">
            <label for="orderPayMethodFilter">Phương thức TT</label>
            <select id="orderPayMethodFilter" name="pttt" class="adm-select">
                <option value="">Tất cả phương thức</option>
                <option value="cod" <?= ($filters['payment_method'] ?? '') === 'cod' ? 'selected' : '' ?>>Thu hộ (COD)</option>
                <option value="bank_transfer" <?= ($filters['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Chuyển khoản</option>
            </select>
        </div>

        <div class="adm-field">
            <label for="orderPayStatusFilter">Trạng thái thanh toán</label>
            <select id="orderPayStatusFilter" name="tt-thanh-toan" class="adm-select">
                <option value="">Tất cả</option>
                <option value="pending" <?= ($filters['payment_status'] ?? '') === 'pending' ? 'selected' : '' ?>>Chưa thanh toán</option>
                <option value="completed" <?= ($filters['payment_status'] ?? '') === 'completed' ? 'selected' : '' ?>>Đã thanh toán</option>
                <option value="manual_refund_required" <?= ($filters['payment_status'] ?? '') === 'manual_refund_required' ? 'selected' : '' ?>>Cần hoàn tiền thủ công</option>
                <option value="failed" <?= ($filters['payment_status'] ?? '') === 'failed' ? 'selected' : '' ?>>Thất bại</option>
                <option value="refunded" <?= ($filters['payment_status'] ?? '') === 'refunded' ? 'selected' : '' ?>>Đã hoàn tiền</option>
            </select>
        </div>

        <div class="adm-field">
            <label for="orderDateFrom">Từ ngày</label>
            <input type="date" id="orderDateFrom" name="tu-ngay" class="adm-input" value="<?= View::e($filters['date_from'] ?? '') ?>">
        </div>

        <div class="adm-field">
            <label for="orderDateTo">Đến ngày</label>
            <input type="date" id="orderDateTo" name="den-ngay" class="adm-input" value="<?= View::e($filters['date_to'] ?? '') ?>">
        </div>

        <div class="adm-field">
            <label for="orderSort">Sắp xếp</label>
            <select id="orderSort" name="sap-xep" class="adm-select">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Mới nhất</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Cũ nhất</option>
                <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>Tổng tiền giảm dần</option>
                <option value="amount_asc" <?= $sort === 'amount_asc' ? 'selected' : '' ?>>Tổng tiền tăng dần</option>
            </select>
        </div>

        <div class="adm-filter-actions">
            <button type="submit" class="adm-btn adm-btn-primary">Lọc đơn hàng</button>
            <a href="<?= View::e(View::url('/admin/don-hang')) ?>" class="adm-btn adm-btn-secondary">Đặt lại</a>
        </div>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Mã đơn hàng</th>
                    <th>Ngày đặt</th>
                    <th>Khách hàng</th>
                    <th>Nhận hàng</th>
                    <th>Tổng tiền</th>
                    <th>Thanh toán</th>
                    <th>Trạng thái đơn</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($orders === []): ?>
                    <tr>
                        <td colspan="8" class="adm-table-empty">Không có đơn hàng nào khớp tiêu chí lọc.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $stBadge    = $orderStatusBadges[$order['status']] ?? ['text' => (string) $order['status'], 'class' => 'adm-badge-hidden'];
                        $payStatus  = (string) ($order['payment_status'] ?? 'pending');
                        $payBadge   = $paymentStatusBadges[$payStatus] ?? ['text' => $payStatus, 'class' => 'adm-badge-hidden'];
                        $needRefund = (string) ($order['refund_status'] ?? 'none') === 'manual_required';
                        $payMethodLabel         = ($order['payment_method'] ?? '') === 'bank_transfer' ? 'Chuyển khoản' : 'COD';
                        $deliveryMethodResolved = (string) ($order['delivery_method_resolved'] ?? AdminOrderModel::resolveDeliveryMethod($order));
                        $deliveryLabel          = (string) ($order['delivery_method_label'] ?? AdminOrderModel::getDeliveryMethodLabel($order));
                        $deliveryBadgeClass     = match ($deliveryMethodResolved) {
                            'pickup'   => 'adm-badge-active',
                            'delivery' => 'adm-badge-hidden',
                            default    => 'adm-badge-warn',
                        };
                        ?>
                        <tr>
                            <td>
                                <a href="<?= View::e(View::url('/admin/don-hang/' . (int) $order['id'])) ?>" class="adm-order-code-link">
                                    <strong><?= View::e((string) $order['order_code']) ?></strong>
                                </a>
                            </td>
                            <td><?= View::e(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></td>
                            <td>
                                <div><strong><?= View::e((string) $order['customer_name']) ?></strong></div>
                                <div class="adm-cell-sub"><?= View::e((string) $order['customer_phone_masked']) ?></div>
                            </td>
                            <td>
                                <span class="adm-badge <?= View::e($deliveryBadgeClass) ?>">
                                    <?= View::e($deliveryLabel) ?>
                                </span>
                            </td>
                            <td><strong><?= View::e(number_format((int) $order['final_amount'], 0, ',', '.')) ?>đ</strong></td>
                            <td>
                                <div><?= View::e($payMethodLabel) ?></div>
                                <span class="adm-badge <?= View::e($payBadge['class']) ?>"><?= View::e($payBadge['text']) ?></span>
                                <?php if ($needRefund): ?>
                                    <div style="margin-top: 4px;">
                                        <span class="adm-badge adm-badge-danger">Cần hoàn tiền thủ công</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="adm-badge <?= View::e($stBadge['class']) ?>">
                                    <?= View::e($stBadge['text']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= View::e(View::url('/admin/don-hang/' . (int) $order['id'])) ?>"
                                   class="adm-btn adm-btn-secondary adm-btn-sm">
                                    Xem chi tiết
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="admin-pagination">
            <?php if ($page > 1): ?>
                <a href="<?= View::e($buildQueryUrl($page - 1)) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">← Trang trước</a>
            <?php endif; ?>
            <span class="adm-pagination-info">Trang <?= View::e((string) $page) ?> / <?= View::e((string) $totalPages) ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="<?= View::e($buildQueryUrl($page + 1)) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">Trang sau →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
