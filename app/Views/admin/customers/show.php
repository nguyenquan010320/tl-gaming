<?php
/**
 * Giao diện Chi tiết Khách hàng & Lịch sử đơn hàng (`app/Views/admin/customers/show.php`) — Prompt 20.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;
use App\Models\AdminOrderModel;

/** @var array<string, mixed> $customer */
/** @var array<int, array<string, mixed>> $orders */
/** @var array<string, int> $pagination */

$customerId = (int) $customer['id'];
$isLocked   = (string) $customer['status'] === 'locked';
$csrfToken  = Csrf::token();
$page       = (int) ($pagination['page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);

$orderBadges = [
    'pending'   => ['text' => 'Chờ xác nhận', 'class' => 'adm-badge-warn'],
    'confirmed' => ['text' => 'Đã xác nhận', 'class' => 'adm-badge-active'],
    'shipping'  => ['text' => 'Đang giao / Xử lý', 'class' => 'adm-badge-warn'],
    'completed' => ['text' => 'Hoàn tất', 'class' => 'adm-badge-active'],
    'cancelled' => ['text' => 'Đã hủy', 'class' => 'adm-badge-danger'],
];
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">
            <?= View::e((string) $customer['full_name']) ?>
            <span class="adm-badge <?= $isLocked ? 'adm-badge-danger' : 'adm-badge-active' ?>" style="vertical-align: middle; margin-left: 8px;">
                <?= $isLocked ? 'Đã khóa' : 'Đang hoạt động' ?>
            </span>
        </h1>
        <p class="admin-page-subtitle">
            Khách hàng #<?= View::e((string) $customerId) ?> · Đăng ký: <strong><?= View::e(date('d/m/Y H:i', strtotime((string) $customer['created_at']))) ?></strong>
        </p>
    </div>
    <a href="<?= View::e(View::url('/admin/khach-hang')) ?>" class="adm-btn adm-btn-secondary">← Danh sách khách hàng</a>
</div>

<div class="adm-order-detail-grid">
    <div class="adm-order-main-col">
        <div class="admin-card">
            <h2 class="adm-card-title">Thông tin tài khoản khách hàng</h2>
            <div class="adm-kv-grid">
                <div>
                    <span class="adm-kv-label">Họ và tên</span>
                    <div class="adm-kv-value"><strong><?= View::e((string) $customer['full_name']) ?></strong></div>
                </div>
                <div>
                    <span class="adm-kv-label">Email đăng nhập</span>
                    <div class="adm-kv-value"><?= View::e((string) $customer['email']) ?></div>
                </div>
                <div>
                    <span class="adm-kv-label">Số điện thoại</span>
                    <div class="adm-kv-value"><?= !empty($customer['phone']) ? View::e((string) $customer['phone']) : '—' ?></div>
                </div>
                <div>
                    <span class="adm-kv-label">Địa chỉ mặc định</span>
                    <div class="adm-kv-value"><?= !empty($customer['address']) ? View::e((string) $customer['address']) : '—' ?></div>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <h2 class="adm-card-title">Lịch sử đơn hàng của tài khoản (không gồm đơn vãng lai)</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Mã đơn</th>
                            <th>Ngày đặt</th>
                            <th>Tổng tiền</th>
                            <th>Thanh toán</th>
                            <th>Trạng thái</th>
                            <th>Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orders === []): ?>
                            <tr>
                                <td colspan="6" class="adm-table-empty">Tài khoản này chưa có đơn hàng nào.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $ord): ?>
                                <?php
                                $badge    = $orderBadges[$ord['status']] ?? ['text' => (string) $ord['status'], 'class' => 'adm-badge-hidden'];
                                $payLabel = AdminOrderModel::PAYMENT_STATUS_LABELS[$ord['payment_status'] ?? 'pending'] ?? 'Chưa thanh toán';
                                ?>
                                <tr>
                                    <td><strong><?= View::e((string) $ord['order_code']) ?></strong></td>
                                    <td><?= View::e(date('d/m/Y H:i', strtotime((string) $ord['created_at']))) ?></td>
                                    <td><strong><?= View::e(number_format((int) $ord['final_amount'], 0, ',', '.')) ?>đ</strong></td>
                                    <td><?= View::e($payLabel) ?></td>
                                    <td><span class="adm-badge <?= View::e($badge['class']) ?>"><?= View::e($badge['text']) ?></span></td>
                                    <td>
                                        <a href="<?= View::e(View::url('/admin/don-hang/' . (int) $ord['id'])) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">
                                            Xem đơn
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
                        <a href="<?= View::e(View::url('/admin/khach-hang/' . $customerId . '?trang=' . ($page - 1))) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">← Trang trước</a>
                    <?php endif; ?>
                    <span class="adm-pagination-info">Trang <?= View::e((string) $page) ?> / <?= View::e((string) $totalPages) ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= View::e(View::url('/admin/khach-hang/' . $customerId . '?trang=' . ($page + 1))) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">Trang sau →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="adm-order-side-col">
        <div class="admin-card">
            <h2 class="adm-card-title">Thống kê mua hàng</h2>
            <div class="adm-kv-stack">
                <div>
                    <span class="adm-kv-label">Tổng số đơn đã đặt</span>
                    <div class="adm-kv-value"><strong><?= View::e((string) $customer['total_orders']) ?></strong> đơn</div>
                </div>
                <div>
                    <span class="adm-kv-label">Đơn hoàn thành</span>
                    <div class="adm-kv-value"><strong><?= View::e((string) $customer['completed_orders']) ?></strong> đơn</div>
                </div>
                <div>
                    <span class="adm-kv-label">Đơn bị hủy</span>
                    <div class="adm-kv-value"><strong><?= View::e((string) $customer['cancelled_orders']) ?></strong> đơn</div>
                </div>
                <div>
                    <span class="adm-kv-label">Tổng chi tiêu (chỉ tính đơn Hoàn tất)</span>
                    <div class="adm-kv-value" style="font-size: 1.15rem; color: #b91c1c;">
                        <strong><?= View::e(number_format((int) $customer['total_spent'], 0, ',', '.')) ?>đ</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <h2 class="adm-card-title">Quản lý trạng thái tài khoản</h2>
            <?php if ($isLocked): ?>
                <p class="adm-cell-sub" style="margin-bottom: 12px;">
                    Tài khoản hiện đang bị khóa. Mở khóa sẽ cho phép khách hàng đăng nhập trở lại; toàn bộ lịch sử đơn hàng vẫn nguyên vẹn.
                </p>
                <form method="POST"
                      action="<?= View::e(View::url('/admin/khach-hang/' . $customerId . '/mo-khoa')) ?>"
                      onsubmit="return confirm('Xác nhận mở khóa tài khoản khách hàng này?');">
                    <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                    <button type="submit" class="adm-btn adm-btn-primary adm-btn-block">✓ Mở khóa tài khoản</button>
                </form>
            <?php else: ?>
                <p class="adm-cell-sub" style="margin-bottom: 12px;">
                    Khóa tài khoản sẽ từ chối ngay các phiên đăng nhập tiếp theo qua <code>Auth::revalidate()</code>, nhưng KHÔNG xóa đơn hàng hay thanh toán.
                </p>
                <form method="POST"
                      action="<?= View::e(View::url('/admin/khach-hang/' . $customerId . '/khoa')) ?>"
                      onsubmit="return confirm('Xác nhận khóa tài khoản khách hàng này?');">
                    <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                    <button type="submit" class="adm-btn adm-btn-danger adm-btn-block">🔒 Khóa tài khoản</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
