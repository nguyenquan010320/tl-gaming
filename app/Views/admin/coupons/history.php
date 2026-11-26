<?php
/**
 * Giao diện Lịch sử sử dụng Voucher (`app/Views/admin/coupons/history.php`) — Prompt 20.
 *
 * Chỉ đọc (Read-only), phân biệt rõ:
 * - `usage_count` đang tính vào giới hạn
 * - Tổng lượt từng phát sinh
 * - Lượt đã hoàn khi hủy đơn (`cancelled`)
 * - Tổng tiền giảm chỉ tính trên các lượt `used` (không cộng nhầm `cancelled`).
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<string, mixed> $coupon */
/** @var array<string, int> $summary */
/** @var array<int, array<string, mixed>> $rows */
/** @var array<string, int> $pagination */

$couponId   = (int) $coupon['id'];
$page       = (int) ($pagination['page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Lịch sử sử dụng voucher <code><?= View::e((string) $coupon['code']) ?></code></h1>
        <p class="admin-page-subtitle">
            Dữ liệu đối soát chỉ đọc từ bảng <code>coupon_usages</code> liên kết <code>orders</code>.
        </p>
    </div>
    <div style="display:flex; gap:8px;">
        <a href="<?= View::e(View::url('/admin/voucher/' . $couponId . '/sua')) ?>" class="adm-btn adm-btn-secondary">Sửa voucher</a>
        <a href="<?= View::e(View::url('/admin/voucher')) ?>" class="adm-btn adm-btn-secondary">← Danh sách voucher</a>
    </div>
</div>

<div class="admin-card" style="margin-bottom: 20px;">
    <div class="adm-kv-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
        <div>
            <span class="adm-kv-label">Đang tính vào giới hạn (usage_count)</span>
            <div class="adm-kv-value" style="font-size: 1.15rem;"><strong><?= View::e((string) $coupon['usage_count']) ?></strong> lượt</div>
        </div>
        <div>
            <span class="adm-kv-label">Tổng lượt từng phát sinh</span>
            <div class="adm-kv-value" style="font-size: 1.15rem;"><strong><?= View::e((string) $summary['total_recorded']) ?></strong> lượt</div>
        </div>
        <div>
            <span class="adm-kv-label">Đang hiệu lực (used) / Đã hoàn (cancelled)</span>
            <div class="adm-kv-value" style="font-size: 1.15rem;">
                <strong><?= View::e((string) $summary['active_used']) ?></strong> /
                <span style="color:#b91c1c;"><strong><?= View::e((string) $summary['cancelled_refunded']) ?></strong></span>
            </div>
        </div>
        <div>
            <span class="adm-kv-label">Tổng tiền giảm thực tế (chỉ tính used)</span>
            <div class="adm-kv-value" style="font-size: 1.15rem; color: #b91c1c;">
                <strong><?= View::e(number_format((int) $summary['total_active_discount'], 0, ',', '.')) ?>đ</strong>
            </div>
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Mã đơn hàng</th>
                    <th>Ngày đặt hàng</th>
                    <th>Ngày áp dụng</th>
                    <th>Khách hàng</th>
                    <th>Số tiền giảm</th>
                    <th>Trạng thái lượt dùng</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="6" class="adm-table-empty">Voucher này chưa phát sinh lượt sử dụng nào.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $isCancelled = (string) $row['status'] === 'cancelled'; ?>
                        <tr>
                            <td>
                                <a href="<?= View::e(View::url('/admin/don-hang/' . (int) $row['order_id'])) ?>" class="adm-order-code-link">
                                    <strong><?= View::e((string) $row['order_code']) ?></strong>
                                </a>
                            </td>
                            <td><?= View::e(date('d/m/Y H:i', strtotime((string) $row['order_created_at']))) ?></td>
                            <td><?= View::e(date('d/m/Y H:i', strtotime((string) $row['used_at']))) ?></td>
                            <td>
                                <?php if (!empty($row['user_id'])): ?>
                                    <a href="<?= View::e(View::url('/admin/khach-hang/' . (int) $row['user_id'])) ?>">
                                        <strong><?= View::e((string) ($row['account_full_name'] ?: $row['customer_name'])) ?></strong>
                                    </a>
                                    <div class="adm-cell-sub">Tài khoản #<?= View::e((string) $row['user_id']) ?> · <?= View::e((string) $row['customer_email']) ?></div>
                                <?php else: ?>
                                    <strong><?= View::e((string) $row['customer_name']) ?></strong>
                                    <div class="adm-cell-sub">Khách vãng lai · <?= View::e((string) $row['customer_email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= View::e(number_format((int) $row['discount_applied'], 0, ',', '.')) ?>đ</strong></td>
                            <td>
                                <span class="adm-badge <?= $isCancelled ? 'adm-badge-danger' : 'adm-badge-active' ?>">
                                    <?= $isCancelled ? 'Đã hoàn (cancelled)' : 'Đang tính (used)' ?>
                                </span>
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
                <a href="<?= View::e(View::url('/admin/voucher/' . $couponId . '/lich-su?trang=' . ($page - 1))) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">← Trang trước</a>
            <?php endif; ?>
            <span class="adm-pagination-info">Trang <?= View::e((string) $page) ?> / <?= View::e((string) $totalPages) ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="<?= View::e(View::url('/admin/voucher/' . $couponId . '/lich-su?trang=' . ($page + 1))) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">Trang sau →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
