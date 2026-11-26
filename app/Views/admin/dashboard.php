<?php
/**
 * Giao diện Tổng quan Kinh doanh & Phân tích Doanh thu Admin (`app/Views/admin/dashboard.php`) — Prompt 21.
 *
 * Bố cục 7 khu vực theo chuẩn Admin Layout Google Stitch:
 * 1. Header + Bộ lọc thời gian (`today`, `7d`, `30d`, `month`, `year`, `custom`) + Nút làm mới.
 * 2. KPI Cards (4 KPI chính + 4 KPI bổ sung từ dữ liệu MySQL thực).
 * 3. Biểu đồ doanh thu đơn hoàn tất (SVG/Canvas vẽ bằng JS thuần, không dùng thư viện ngoài).
 * 4. Phân bố trạng thái đơn hàng theo 5 ENUM thực tế.
 * 5. Top sản phẩm bán chạy theo snapshot `order_items` (chỉ đơn `completed`).
 * 6. Đơn hàng gần đây (tối đa 10 đơn, không lộ SĐT/email).
 * 7. Cảnh báo vận hành kiểm chứng được từ CSDL.
 */

declare(strict_types=1);

use App\Core\View;
use App\Models\AdminOrderModel;

/** @var array<string, mixed> $range */
/** @var array<string, int> $kpi */
/** @var array{points: array<int, array<string, mixed>>, has_data: bool} $revenueSeries */
/** @var array<string, int> $statusDist */
/** @var array<int, array<string, mixed>> $topProducts */
/** @var array<int, array<string, mixed>> $recentOrders */
/** @var array<string, int> $alerts */
/** @var string $generatedAt */

$fmtMoney = static fn(int $amount): string => number_format($amount, 0, ',', '.') . 'đ';
$preset   = (string) ($range['preset'] ?? '30d');

$orderBadges = [
    'pending'   => ['text' => 'Chờ xác nhận', 'class' => 'adm-badge-warn', 'color' => '#d97706'],
    'confirmed' => ['text' => 'Đã xác nhận', 'class' => 'adm-badge-active', 'color' => '#2563eb'],
    'shipping'  => ['text' => 'Đang giao', 'class' => 'adm-badge-warn', 'color' => '#7c3aed'],
    'completed' => ['text' => 'Hoàn tất', 'class' => 'adm-badge-active', 'color' => '#16a34a'],
    'cancelled' => ['text' => 'Đã hủy', 'class' => 'adm-badge-danger', 'color' => '#dc2626'],
];

$totalDistOrders = array_sum($statusDist);
$chartPayload    = json_encode([
    'points'      => $revenueSeries['points'],
    'has_data'    => $revenueSeries['has_data'],
    'granularity' => $range['granularity'] ?? 'day',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!-- Khu vực 1: Header & Bộ lọc thời gian -->
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Tổng quan kinh doanh</h1>
        <p class="admin-page-subtitle">
            Kỳ thống kê: <strong><?= View::e((string) $range['label']) ?></strong>
            · Cập nhật lúc: <strong><?= View::e($generatedAt) ?></strong> (Asia/Ho_Chi_Minh)
        </p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="<?= View::e(View::url('/admin?' . http_build_query(array_filter([
            'ky'       => $preset,
            'tu-ngay'  => $preset === 'custom' ? ($range['date_from'] ?? '') : '',
            'den-ngay' => $preset === 'custom' ? ($range['date_to'] ?? '') : '',
        ])))) ?>" class="adm-btn adm-btn-secondary">↻ Làm mới thống kê</a>
        <a href="<?= View::e(View::url('/admin/don-hang')) ?>" class="adm-btn adm-btn-primary">Quản lý đơn hàng</a>
    </div>
</div>

<div class="admin-card" style="margin-bottom: 20px;">
    <form method="GET" action="<?= View::e(View::url('/admin')) ?>" class="adm-dash-filter-form" id="dashFilterForm">
        <div class="adm-dash-presets">
            <?php
            $presetButtons = [
                'today' => 'Hôm nay',
                '7d'    => '7 ngày',
                '30d'   => '30 ngày',
                'month' => 'Tháng này',
                'year'  => 'Năm nay',
            ];
            foreach ($presetButtons as $key => $label):
            ?>
                <a href="<?= View::e(View::url('/admin?ky=' . $key)) ?>"
                   class="adm-btn adm-btn-sm <?= $preset === $key ? 'adm-btn-primary' : 'adm-btn-secondary' ?>">
                    <?= View::e($label) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="adm-dash-custom-dates">
            <input type="hidden" name="ky" value="custom">
            <label for="dashFrom" class="adm-dash-date-label">Từ ngày</label>
            <input type="date" id="dashFrom" name="tu-ngay" class="adm-input adm-input-sm" value="<?= View::e((string) $range['date_from']) ?>" required>
            <label for="dashTo" class="adm-dash-date-label">Đến ngày</label>
            <input type="date" id="dashTo" name="den-ngay" class="adm-input adm-input-sm" value="<?= View::e((string) $range['date_to']) ?>" required>
            <button type="submit" class="adm-btn adm-btn-primary adm-btn-sm">Xem khoảng chọn</button>
        </div>
    </form>
</div>

<!-- Khu vực 2: KPI Cards (4 KPI chính + 4 KPI bổ sung) -->
<div class="adm-kpi-grid">
    <div class="admin-card adm-kpi-card">
        <span class="adm-kpi-label">Doanh thu đơn hoàn tất</span>
        <div class="adm-kpi-value is-revenue"><?= View::e($fmtMoney($kpi['completed_revenue'])) ?></div>
        <div class="adm-kpi-sub">Chỉ tính đơn <code>completed</code> theo ngày đặt trong kỳ</div>
    </div>

    <div class="admin-card adm-kpi-card">
        <span class="adm-kpi-label">Đơn hàng hoàn tất</span>
        <div class="adm-kpi-value"><?= View::e((string) $kpi['completed_orders']) ?></div>
        <div class="adm-kpi-sub">Tổng đơn trong kỳ: <?= View::e((string) $kpi['total_orders_in_range']) ?> · Hủy: <?= View::e((string) $kpi['cancelled_orders']) ?></div>
    </div>

    <div class="admin-card adm-kpi-card">
        <span class="adm-kpi-label">Đơn chờ xác nhận (hiện tại)</span>
        <div class="adm-kpi-value <?= $kpi['current_pending_orders'] > 0 ? 'is-warn' : '' ?>">
            <?= View::e((string) $kpi['current_pending_orders']) ?>
        </div>
        <div class="adm-kpi-sub">Phát sinh trong kỳ: <?= View::e((string) $kpi['pending_orders_in_range']) ?> đơn pending</div>
    </div>

    <div class="admin-card adm-kpi-card">
        <span class="adm-kpi-label">Tổng tài khoản khách hàng</span>
        <div class="adm-kpi-value"><?= View::e((string) $kpi['total_customers']) ?></div>
        <div class="adm-kpi-sub">Mới trong kỳ: +<?= View::e((string) $kpi['new_customers_in_range']) ?> · Có đơn hoàn tất: <?= View::e((string) $kpi['active_buying_customers']) ?></div>
    </div>

    <div class="admin-card adm-kpi-card">
        <span class="adm-kpi-label">Giá trị đơn trung bình (AOV)</span>
        <div class="adm-kpi-value">
            <?= $kpi['completed_orders'] > 0 ? View::e($fmtMoney($kpi['aov'])) : 'Chưa có dữ liệu' ?>
        </div>
        <div class="adm-kpi-sub">SUM(final_amount completed) / COUNT(completed)</div>
    </div>

    <div class="admin-card adm-kpi-card">
        <span class="adm-kpi-label">Tiền đã xác nhận thu</span>
        <div class="adm-kpi-value"><?= View::e($fmtMoney($kpi['confirmed_cash_collected'])) ?></div>
        <div class="adm-kpi-sub">Theo <code>payments.confirmed_at</code> (<?= View::e((string) $kpi['confirmed_payment_orders']) ?> đơn)</div>
    </div>

    <div class="admin-card adm-kpi-card">
        <span class="adm-kpi-label">Sản phẩm đang bán / Máy đạt chuẩn</span>
        <div class="adm-kpi-value"><?= View::e((string) $kpi['published_products']) ?> SP</div>
        <div class="adm-kpi-sub">Hết hàng: <?= View::e((string) $kpi['out_of_stock_products']) ?> · Máy serial đạt 18 bước: <?= View::e((string) $kpi['sellable_serial_units']) ?></div>
    </div>

    <div class="admin-card adm-kpi-card">
        <span class="adm-kpi-label">Cần hoàn tiền thủ công</span>
        <div class="adm-kpi-value <?= $kpi['manual_refund_orders'] > 0 ? 'is-danger' : '' ?>">
            <?= View::e($fmtMoney($kpi['manual_refund_amount'])) ?>
        </div>
        <div class="adm-kpi-sub"><?= View::e((string) $kpi['manual_refund_orders']) ?> đơn đã thu tiền bị hủy (chưa hoàn)</div>
    </div>
</div>

<!-- Khu vực 3 & 4: Biểu đồ doanh thu & Phân bố trạng thái đơn -->
<div class="adm-dash-two-col">
    <div class="admin-card">
        <div class="adm-card-head-row">
            <div>
                <h2 class="adm-card-title" style="margin-bottom:2px;">Biểu đồ doanh thu đơn hàng hoàn tất</h2>
                <p class="adm-cell-sub">
                    Đơn vị: VND · Gom theo <?= ($range['granularity'] ?? 'day') === 'month' ? 'tháng' : 'ngày' ?> đặt đơn (<code>orders.created_at</code>)
                </p>
            </div>
        </div>
        <div class="adm-chart-box" id="revenueChartContainer" data-revenue-series="<?= View::e((string) $chartPayload) ?>">
            <?php if (!$revenueSeries['has_data']): ?>
                <div class="adm-chart-empty">
                    Chưa có dữ liệu doanh thu đơn hàng hoàn tất trong khoảng thời gian đã chọn.
                </div>
            <?php else: ?>
                <canvas id="revenueChartCanvas" height="260" aria-label="Biểu đồ doanh thu đơn hoàn tất"></canvas>
                <div id="revenueChartTooltip" class="adm-chart-tooltip" hidden></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="admin-card">
        <h2 class="adm-card-title">Phân bố trạng thái đơn hàng (trong kỳ)</h2>
        <?php if ($totalDistOrders === 0): ?>
            <p class="adm-cell-sub">Chưa có đơn hàng nào được tạo trong khoảng thời gian đã chọn.</p>
        <?php else: ?>
            <div class="adm-status-bars">
                <?php foreach ($orderBadges as $stKey => $meta): ?>
                    <?php
                    $cnt = (int) ($statusDist[$stKey] ?? 0);
                    $pct = $totalDistOrders > 0 ? round(($cnt / $totalDistOrders) * 100, 1) : 0;
                    ?>
                    <div class="adm-status-bar-item">
                        <div class="adm-status-bar-head">
                            <span><?= View::e($meta['text']) ?></span>
                            <strong><?= View::e((string) $cnt) ?> (<?= View::e((string) $pct) ?>%)</strong>
                        </div>
                        <div class="adm-status-bar-track">
                            <div class="adm-status-bar-fill" style="width: <?= View::e((string) $pct) ?>%; background: <?= View::e($meta['color']) ?>;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Khu vực 7: Cảnh báo quản trị -->
        <hr class="adm-divider">
        <h2 class="adm-card-title">Cảnh báo vận hành cần chú ý</h2>
        <ul class="adm-alert-list">
            <li>
                <a href="<?= View::e(View::url('/admin/don-hang?trang-thai=pending')) ?>">
                    <span>Đơn <code>pending</code> chưa xác nhận</span>
                    <strong class="adm-badge <?= $alerts['pending_orders'] > 0 ? 'adm-badge-warn' : 'adm-badge-hidden' ?>"><?= View::e((string) $alerts['pending_orders']) ?></strong>
                </a>
            </li>
            <li>
                <a href="<?= View::e(View::url('/admin/don-hang?tt-thanh-toan=pending')) ?>">
                    <span>Đơn chưa đối soát thanh toán</span>
                    <strong class="adm-badge <?= $alerts['pending_payments'] > 0 ? 'adm-badge-warn' : 'adm-badge-hidden' ?>"><?= View::e((string) $alerts['pending_payments']) ?></strong>
                </a>
            </li>
            <li>
                <a href="<?= View::e(View::url('/admin/don-hang?tt-thanh-toan=manual_refund_required')) ?>">
                    <span>Khoản cần hoàn tiền thủ công</span>
                    <strong class="adm-badge <?= $alerts['manual_refund_orders'] > 0 ? 'adm-badge-danger' : 'adm-badge-hidden' ?>">
                        <?= View::e((string) $alerts['manual_refund_orders']) ?> (<?= View::e($fmtMoney($alerts['manual_refund_amount'])) ?>)
                    </strong>
                </a>
            </li>
            <li>
                <a href="<?= View::e(View::url('/admin/san-pham')) ?>">
                    <span>Sản phẩm đang bật nhưng 0 tồn đủ chuẩn bán</span>
                    <strong class="adm-badge <?= $alerts['zero_stock_published'] > 0 ? 'adm-badge-warn' : 'adm-badge-hidden' ?>"><?= View::e((string) $alerts['zero_stock_published']) ?></strong>
                </a>
            </li>
            <li>
                <a href="<?= View::e(View::url('/admin/serial?trang-thai=repairing')) ?>">
                    <span>Máy vật lý đang ở trạng thái <code>repairing</code></span>
                    <strong class="adm-badge <?= $alerts['repairing_units'] > 0 ? 'adm-badge-warn' : 'adm-badge-hidden' ?>"><?= View::e((string) $alerts['repairing_units']) ?></strong>
                </a>
            </li>
        </ul>
    </div>
</div>

<!-- Khu vực 5 & 6: Sản phẩm bán chạy & Đơn hàng gần đây -->
<div class="adm-dash-two-col">
    <div class="admin-card">
        <h2 class="adm-card-title">Top sản phẩm bán chạy (đơn hoàn tất trong kỳ)</h2>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Sản phẩm (Snapshot)</th>
                        <th>SL bán</th>
                        <th>Doanh thu snapshot</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($topProducts === []): ?>
                        <tr>
                            <td colspan="3" class="adm-table-empty">Chưa có sản phẩm bán ra từ đơn hoàn tất trong kỳ.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($topProducts as $tp): ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <?php if (!empty($tp['thumbnail'])): ?>
                                            <img src="<?= View::e(View::url('/' . ltrim((string) $tp['thumbnail'], '/'))) ?>"
                                                 alt=""
                                                 style="width:40px; height:40px; object-fit:cover; border-radius:6px; border:1px solid #e2e8f0; flex-shrink:0;">
                                        <?php endif; ?>
                                        <div>
                                            <div><strong><?= View::e((string) $tp['product_name']) ?></strong></div>
                                            <div class="adm-cell-sub">SKU: <?= View::e((string) $tp['product_sku']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><strong><?= View::e((string) $tp['sold_quantity']) ?></strong></td>
                                <td><strong><?= View::e($fmtMoney((int) $tp['snapshot_revenue'])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-card">
        <div class="adm-card-head-row" style="margin-bottom:10px;">
            <h2 class="adm-card-title" style="margin:0;">Đơn hàng gần đây (tối đa 10 đơn)</h2>
            <a href="<?= View::e(View::url('/admin/don-hang')) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">Xem tất cả</a>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Mã đơn</th>
                        <th>Ngày tạo</th>
                        <th>Khách hàng</th>
                        <th>Tổng tiền</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recentOrders === []): ?>
                        <tr>
                            <td colspan="5" class="adm-table-empty">Chưa có đơn hàng nào trong hệ thống.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentOrders as $ord): ?>
                            <?php $stMeta = $orderBadges[$ord['status']] ?? ['text' => (string) $ord['status'], 'class' => 'adm-badge-hidden']; ?>
                            <tr>
                                <td>
                                    <a href="<?= View::e(View::url('/admin/don-hang/' . (int) $ord['id'])) ?>" class="adm-order-code-link">
                                        <strong><?= View::e((string) $ord['order_code']) ?></strong>
                                    </a>
                                </td>
                                <td><?= View::e(date('d/m H:i', strtotime((string) $ord['created_at']))) ?></td>
                                <td><?= View::e((string) $ord['customer_name']) ?></td>
                                <td><strong><?= View::e($fmtMoney((int) $ord['final_amount'])) ?></strong></td>
                                <td><span class="adm-badge <?= View::e($stMeta['class']) ?>"><?= View::e($stMeta['text']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
