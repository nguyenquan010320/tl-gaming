<?php
/**
 * Giao diện Danh sách Khách hàng Admin (`app/Views/admin/customers/index.php`) — Prompt 20.
 *
 * Chỉ hiển thị người dùng `role = 'customer'`. Tổng chi tiêu tính từ các đơn `completed` gắn đúng `orders.user_id`.
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<int, array<string, mixed>> $customers */
/** @var array<string, string> $filters */
/** @var string $sort */
/** @var array<string, int> $pagination */

$totalItems = (int) ($pagination['total_items'] ?? 0);
$page       = (int) ($pagination['page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);

$buildQueryUrl = static function (int $targetPage) use ($filters, $sort): string {
    $params = array_filter([
        'q'          => $filters['keyword'] ?? '',
        'trang-thai' => $filters['status'] ?? '',
        'tu-ngay'    => $filters['date_from'] ?? '',
        'den-ngay'   => $filters['date_to'] ?? '',
        'sap-xep'    => $sort !== 'newest' ? $sort : '',
        'trang'      => $targetPage > 1 ? (string) $targetPage : '',
    ], static fn($v): bool => $v !== '');

    return View::url('/admin/khach-hang' . ($params !== [] ? '?' . http_build_query($params) : ''));
};
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Quản lý khách hàng</h1>
        <p class="admin-page-subtitle">
            Tổng cộng <strong><?= View::e((string) $totalItems) ?></strong> tài khoản khách hàng.
            Tổng chi tiêu chỉ tính trên các đơn hàng <strong>Hoàn tất</strong> thuộc tài khoản (không gộp đơn vãng lai hay đơn hủy).
        </p>
    </div>
</div>

<div class="admin-card">
    <form class="admin-filter-bar adm-order-filter-bar" method="GET" action="<?= View::e(View::url('/admin/khach-hang')) ?>">
        <div class="adm-field">
            <label for="custSearch">Tìm kiếm (Tên / Email / SĐT)</label>
            <input type="text"
                   id="custSearch"
                   name="q"
                   class="adm-input"
                   value="<?= View::e($filters['keyword'] ?? '') ?>"
                   placeholder="Nhập họ tên, email hoặc SĐT...">
        </div>

        <div class="adm-field">
            <label for="custStatus">Trạng thái tài khoản</label>
            <select id="custStatus" name="trang-thai" class="adm-select">
                <option value="">Tất cả</option>
                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Đang hoạt động</option>
                <option value="locked" <?= ($filters['status'] ?? '') === 'locked' ? 'selected' : '' ?>>Đã khóa</option>
            </select>
        </div>

        <div class="adm-field">
            <label for="custFrom">Đăng ký từ ngày</label>
            <input type="date" id="custFrom" name="tu-ngay" class="adm-input" value="<?= View::e($filters['date_from'] ?? '') ?>">
        </div>

        <div class="adm-field">
            <label for="custTo">Đến ngày</label>
            <input type="date" id="custTo" name="den-ngay" class="adm-input" value="<?= View::e($filters['date_to'] ?? '') ?>">
        </div>

        <div class="adm-field">
            <label for="custSort">Sắp xếp</label>
            <select id="custSort" name="sap-xep" class="adm-select">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Mới đăng ký nhất</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Cũ nhất</option>
                <option value="spent_desc" <?= $sort === 'spent_desc' ? 'selected' : '' ?>>Chi tiêu cao nhất</option>
                <option value="orders_desc" <?= $sort === 'orders_desc' ? 'selected' : '' ?>>Nhiều đơn nhất</option>
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Tên A → Z</option>
            </select>
        </div>

        <div class="adm-filter-actions">
            <button type="submit" class="adm-btn adm-btn-primary">Lọc khách hàng</button>
            <a href="<?= View::e(View::url('/admin/khach-hang')) ?>" class="adm-btn adm-btn-secondary">Đặt lại</a>
        </div>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Khách hàng</th>
                    <th>Số điện thoại</th>
                    <th>Ngày đăng ký</th>
                    <th>Trạng thái</th>
                    <th>Số đơn</th>
                    <th>Tổng chi tiêu (Hoàn tất)</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($customers === []): ?>
                    <tr>
                        <td colspan="8" class="adm-table-empty">Không có khách hàng nào khớp bộ lọc.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $cust): ?>
                        <?php $isLocked = (string) $cust['status'] === 'locked'; ?>
                        <tr>
                            <td>#<?= View::e((string) $cust['id']) ?></td>
                            <td>
                                <div><strong><?= View::e((string) $cust['full_name']) ?></strong></div>
                                <div class="adm-cell-sub"><?= View::e((string) $cust['email']) ?></div>
                            </td>
                            <td><?= !empty($cust['phone']) ? View::e((string) $cust['phone']) : '<span class="adm-cell-sub">—</span>' ?></td>
                            <td><?= View::e(date('d/m/Y H:i', strtotime((string) $cust['created_at']))) ?></td>
                            <td>
                                <span class="adm-badge <?= $isLocked ? 'adm-badge-danger' : 'adm-badge-active' ?>">
                                    <?= $isLocked ? 'Đã khóa' : 'Hoạt động' ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= View::e((string) $cust['total_orders']) ?></strong>
                                <div class="adm-cell-sub">
                                    HT: <?= View::e((string) $cust['completed_orders']) ?> · Hủy: <?= View::e((string) $cust['cancelled_orders']) ?>
                                </div>
                            </td>
                            <td><strong><?= View::e(number_format((int) $cust['total_spent'], 0, ',', '.')) ?>đ</strong></td>
                            <td>
                                <a href="<?= View::e(View::url('/admin/khach-hang/' . (int) $cust['id'])) ?>"
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
