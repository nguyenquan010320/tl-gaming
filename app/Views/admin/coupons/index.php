<?php
/**
 * Giao diện Danh sách Voucher Admin (`app/Views/admin/coupons/index.php`) — Prompt 20.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;
use App\Models\AdminCouponModel;

/** @var array<int, array<string, mixed>> $coupons */
/** @var array<string, string> $filters */
/** @var string $sort */
/** @var array<string, int> $pagination */

$csrfToken  = Csrf::token();
$totalItems = (int) ($pagination['total_items'] ?? 0);
$page       = (int) ($pagination['page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);

$buildQueryUrl = static function (int $targetPage) use ($filters, $sort): string {
    $params = array_filter([
        'q'          => $filters['keyword'] ?? '',
        'loai'       => $filters['discount_type'] ?? '',
        'trang-thai' => $filters['state'] ?? '',
        'sap-xep'    => $sort !== 'newest' ? $sort : '',
        'trang'      => $targetPage > 1 ? (string) $targetPage : '',
    ], static fn($v): bool => $v !== '');

    return View::url('/admin/voucher' . ($params !== [] ? '?' . http_build_query($params) : ''));
};
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Quản lý voucher</h1>
        <p class="admin-page-subtitle">
            Tổng cộng <strong><?= View::e((string) $totalItems) ?></strong> mã giảm giá.
            Mọi thay đổi đồng bộ trực tiếp với Checkout và đối soát hoàn lượt khi hủy đơn.
        </p>
    </div>
    <a href="<?= View::e(View::url('/admin/voucher/them')) ?>" class="adm-btn adm-btn-primary">+ Thêm voucher mới</a>
</div>

<div class="admin-card">
    <form class="admin-filter-bar adm-order-filter-bar" method="GET" action="<?= View::e(View::url('/admin/voucher')) ?>">
        <div class="adm-field">
            <label for="cpSearch">Tìm kiếm (Mã / Mô tả)</label>
            <input type="text"
                   id="cpSearch"
                   name="q"
                   class="adm-input"
                   value="<?= View::e($filters['keyword'] ?? '') ?>"
                   placeholder="Ví dụ: TLGAMING500K...">
        </div>

        <div class="adm-field">
            <label for="cpType">Loại giảm</label>
            <select id="cpType" name="loai" class="adm-select">
                <option value="">Tất cả loại</option>
                <option value="percentage" <?= ($filters['discount_type'] ?? '') === 'percentage' ? 'selected' : '' ?>>Phần trăm (%)</option>
                <option value="fixed_amount" <?= ($filters['discount_type'] ?? '') === 'fixed_amount' ? 'selected' : '' ?>>Số tiền cố định (VND)</option>
            </select>
        </div>

        <div class="adm-field">
            <label for="cpState">Hiệu lực thực tế</label>
            <select id="cpState" name="trang-thai" class="adm-select">
                <option value="">Tất cả trạng thái</option>
                <option value="active" <?= ($filters['state'] ?? '') === 'active' ? 'selected' : '' ?>>Đang áp dụng</option>
                <option value="disabled" <?= ($filters['state'] ?? '') === 'disabled' ? 'selected' : '' ?>>Đã tắt</option>
                <option value="upcoming" <?= ($filters['state'] ?? '') === 'upcoming' ? 'selected' : '' ?>>Chưa đến hạn</option>
                <option value="expired" <?= ($filters['state'] ?? '') === 'expired' ? 'selected' : '' ?>>Đã hết hạn</option>
                <option value="exhausted" <?= ($filters['state'] ?? '') === 'exhausted' ? 'selected' : '' ?>>Hết lượt</option>
            </select>
        </div>

        <div class="adm-field">
            <label for="cpSort">Sắp xếp</label>
            <select id="cpSort" name="sap-xep" class="adm-select">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Mới nhất</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Cũ nhất</option>
                <option value="code_asc" <?= $sort === 'code_asc' ? 'selected' : '' ?>>Mã A → Z</option>
                <option value="usage_desc" <?= $sort === 'usage_desc' ? 'selected' : '' ?>>Lượt dùng nhiều nhất</option>
            </select>
        </div>

        <div class="adm-filter-actions">
            <button type="submit" class="adm-btn adm-btn-primary">Lọc voucher</button>
            <a href="<?= View::e(View::url('/admin/voucher')) ?>" class="adm-btn adm-btn-secondary">Đặt lại</a>
        </div>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Mã voucher</th>
                    <th>Mức giảm</th>
                    <th>Đơn tối thiểu</th>
                    <th>Lượt dùng / Giới hạn</th>
                    <th>Thời gian hiệu lực</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($coupons === []): ?>
                    <tr>
                        <td colspan="7" class="adm-table-empty">Không có voucher nào khớp bộ lọc.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($coupons as $cp): ?>
                        <?php
                        $cpId      = (int) $cp['id'];
                        $isActive  = (int) $cp['is_active'] === 1;
                        $effStatus = (string) ($cp['effective_status'] ?? 'disabled');
                        $effLabel  = AdminCouponModel::getEffectiveStatusLabel($effStatus);
                        $effClass  = match ($effStatus) {
                            'active'   => 'adm-badge-active',
                            'upcoming' => 'adm-badge-warn',
                            default    => 'adm-badge-danger',
                        };
                        $isPercent = (string) $cp['discount_type'] === 'percentage';
                        $limitText = (int) $cp['usage_limit'] === 0 ? 'Không giới hạn' : number_format((int) $cp['usage_limit'], 0, ',', '.');
                        ?>
                        <tr>
                            <td>
                                <div><strong><code><?= View::e((string) $cp['code']) ?></code></strong></div>
                                <?php if (!empty($cp['description'])): ?>
                                    <div class="adm-cell-sub"><?= View::e((string) $cp['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isPercent): ?>
                                    <strong><?= View::e((string) $cp['discount_value']) ?>%</strong>
                                    <?php if (!empty($cp['max_discount_amount'])): ?>
                                        <div class="adm-cell-sub">Tối đa <?= View::e(number_format((int) $cp['max_discount_amount'], 0, ',', '.')) ?>đ</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <strong><?= View::e(number_format((int) $cp['discount_value'], 0, ',', '.')) ?>đ</strong>
                                    <div class="adm-cell-sub">Giảm cố định</div>
                                <?php endif; ?>
                            </td>
                            <td><?= View::e(number_format((int) $cp['min_order_amount'], 0, ',', '.')) ?>đ</td>
                            <td>
                                <strong><?= View::e((string) $cp['usage_count']) ?></strong> / <?= View::e($limitText) ?>
                                <div class="adm-cell-sub">Mỗi khách: <?= View::e((string) $cp['per_user_limit']) ?></div>
                            </td>
                            <td>
                                <div><?= View::e(date('d/m/Y H:i', strtotime((string) $cp['start_date']))) ?></div>
                                <div class="adm-cell-sub">→ <?= View::e(date('d/m/Y H:i', strtotime((string) $cp['end_date']))) ?></div>
                            </td>
                            <td>
                                <span class="adm-badge <?= View::e($effClass) ?>"><?= View::e($effLabel) ?></span>
                                <div class="adm-cell-sub"><?= $isActive ? 'Công tắc: Bật' : 'Công tắc: Tắt' ?></div>
                            </td>
                            <td>
                                <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                    <a href="<?= View::e(View::url('/admin/voucher/' . $cpId . '/sua')) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">Sửa</a>
                                    <a href="<?= View::e(View::url('/admin/voucher/' . $cpId . '/lich-su')) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">Lịch sử</a>
                                    <?php if ($isActive): ?>
                                        <form method="POST" action="<?= View::e(View::url('/admin/voucher/' . $cpId . '/tat')) ?>" style="display:inline;">
                                            <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                                            <button type="submit" class="adm-btn adm-btn-danger adm-btn-sm">Tắt</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="<?= View::e(View::url('/admin/voucher/' . $cpId . '/bat')) ?>" style="display:inline;">
                                            <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                                            <button type="submit" class="adm-btn adm-btn-primary adm-btn-sm">Bật</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
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
