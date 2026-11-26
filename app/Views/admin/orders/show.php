<?php
/**
 * Giao diện Chi tiết & Xử lý Đơn hàng Admin (`app/Views/admin/orders/show.php`) — Prompt 19.
 *
 * Hiển thị:
 * - Mã đơn, ngày đặt, trạng thái, thông tin người nhận đầy đủ, hình thức giao nhận, ghi chú.
 * - Bảng sản phẩm snapshot (SKU, tên, đơn giá snapshot, số lượng, serial gắn đơn).
 * - Tạm tính, voucher, giảm giá, phí giao hàng, tổng tiền, trạng thái thanh toán & hoàn tiền thủ công.
 * - Các thao tác State Machine hợp lệ (POST + CSRF) và nhật ký audit `order_status_logs`.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;
use App\Models\AdminOrderModel;

/** @var array<string, mixed> $order */

$orderId     = (int) $order['id'];
$status      = (string) $order['status'];
$items          = is_array($order['items'] ?? null) ? $order['items'] : [];
$payment        = is_array($order['payment'] ?? null) ? $order['payment'] : null;
$logs           = is_array($order['logs'] ?? null) ? $order['logs'] : [];
$isPickup       = !empty($order['is_pickup']);
$deliveryLabel  = (string) ($order['delivery_method_label'] ?? AdminOrderModel::getDeliveryMethodLabel($order));
$csrfToken      = Csrf::token();

$orderStatusBadges = [
    'pending'   => ['text' => 'Chờ xác nhận', 'class' => 'adm-badge-warn'],
    'confirmed' => ['text' => 'Đã xác nhận', 'class' => 'adm-badge-active'],
    'shipping'  => ['text' => 'Đang giao / Xử lý', 'class' => 'adm-badge-warn'],
    'completed' => ['text' => 'Hoàn tất', 'class' => 'adm-badge-active'],
    'cancelled' => ['text' => 'Đã hủy', 'class' => 'adm-badge-danger'],
];
$stBadge = $orderStatusBadges[$status] ?? ['text' => $status, 'class' => 'adm-badge-hidden'];

$payStatus     = (string) ($payment['status'] ?? 'pending');
$payMethod     = (string) ($payment['payment_method'] ?? 'cod');
$payAmount     = (int) ($payment['amount'] ?? $order['final_amount']);
$refundStatus  = (string) ($payment['refund_status'] ?? 'none');
$payStatusText = AdminOrderModel::PAYMENT_STATUS_LABELS[$payStatus] ?? $payStatus;
$payMethodText = $payMethod === 'bank_transfer' ? 'Chuyển khoản ngân hàng' : 'Thanh toán khi nhận hàng (COD)';
$payBadgeClass = match ($payStatus) {
    'completed' => 'adm-badge-active',
    'failed'    => 'adm-badge-danger',
    'refunded'  => 'adm-badge-hidden',
    default     => 'adm-badge-warn',
};

$actionLabels = [
    'confirm'         => 'Xác nhận đơn hàng',
    'confirm_payment' => 'Xác nhận thanh toán',
    'ship'            => 'Chuyển giao hàng / xử lý',
    'complete'        => 'Hoàn tất đơn hàng',
    'cancel'          => 'Hủy đơn & hoàn tồn',
    'mark_refunded'   => 'Ghi nhận hoàn tiền',
];
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">
            Đơn hàng <?= View::e((string) $order['order_code']) ?>
            <span class="adm-badge <?= View::e($stBadge['class']) ?>" style="vertical-align: middle; margin-left: 8px;">
                <?= View::e($stBadge['text']) ?>
            </span>
        </h1>
        <p class="admin-page-subtitle">
            Ngày đặt: <strong><?= View::e(date('d/m/Y H:i:s', strtotime((string) $order['created_at']))) ?></strong>
            · Hình thức nhận: <strong><?= View::e($deliveryLabel) ?></strong>
        </p>
    </div>
    <a href="<?= View::e(View::url('/admin/don-hang')) ?>" class="adm-btn adm-btn-secondary">← Danh sách đơn hàng</a>
</div>

<?php if ($refundStatus === 'manual_required'): ?>
    <div class="admin-alert admin-alert-error" style="margin-bottom: 16px;">
        <strong>CẦN XỬ LÝ HOÀN TIỀN THỦ CÔNG:</strong>
        Đơn hàng này đã thu tiền (<?= View::e(number_format($payAmount, 0, ',', '.')) ?>đ) trước khi bị hủy.
        Hệ thống giữ nguyên <code>payments.status = completed</code> và chưa tự động hoàn tiền.
        <?php if (!empty($payment['refund_note'])): ?>
            Ghi chú: <?= View::e((string) $payment['refund_note']) ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="adm-order-detail-grid">
    <!-- Cột trái: Thông tin khách hàng, Sản phẩm snapshot & Audit -->
    <div class="adm-order-main-col">
        <div class="admin-card">
            <h2 class="adm-card-title">Thông tin người nhận &amp; Giao nhận</h2>
            <div class="adm-kv-grid">
                <div>
                    <span class="adm-kv-label">Người nhận</span>
                    <div class="adm-kv-value"><?= View::e((string) $order['customer_name']) ?></div>
                </div>
                <div>
                    <span class="adm-kv-label">Số điện thoại (đầy đủ)</span>
                    <div class="adm-kv-value"><strong><?= View::e((string) $order['customer_phone']) ?></strong></div>
                </div>
                <div>
                    <span class="adm-kv-label">Email nhận thông báo</span>
                    <div class="adm-kv-value"><?= View::e((string) $order['customer_email']) ?></div>
                </div>
                <div>
                    <span class="adm-kv-label">Tài khoản đặt</span>
                    <div class="adm-kv-value">
                        <?php if (!empty($order['user_id'])): ?>
                            <?= View::e((string) ($order['account_full_name'] ?? 'Khách hàng')) ?>
                            (<?= View::e((string) ($order['account_email'] ?? '')) ?>)
                        <?php else: ?>
                            Khách vãng lai (Guest)
                        <?php endif; ?>
                    </div>
                </div>
                <div class="adm-kv-span-2">
                    <span class="adm-kv-label"><?= $isPickup ? 'Địa điểm nhận tại showroom' : 'Địa chỉ giao hàng' ?></span>
                    <div class="adm-kv-value">
                        <?= View::e((string) $order['shipping_address']) ?>
                        <?php if (!empty($order['shipping_district'])): ?>
                            , <?= View::e((string) $order['shipping_district']) ?>
                        <?php endif; ?>
                        <?php if (!empty($order['shipping_city'])): ?>
                            , <?= View::e((string) $order['shipping_city']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="adm-kv-span-2">
                    <span class="adm-kv-label">Ghi chú của khách hàng</span>
                    <div class="adm-kv-value">
                        <?= !empty($order['customer_note']) ? View::e((string) $order['customer_note']) : '<span class="adm-cell-sub">Không có ghi chú</span>' ?>
                    </div>
                </div>
                <?php if ($status === 'cancelled' && !empty($order['cancel_reason'])): ?>
                    <div class="adm-kv-span-2">
                        <span class="adm-kv-label">Lý do hủy đơn (<?= View::e((string) $order['cancelled_at']) ?>)</span>
                        <div class="adm-kv-value" style="color: #b91c1c;">
                            <strong><?= View::e((string) $order['cancel_reason']) ?></strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-card">
            <h2 class="adm-card-title">Danh sách sản phẩm &amp; Serial (Snapshot tại thời điểm đặt)</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>SKU / Sản phẩm</th>
                            <th>Serial máy gắn đơn</th>
                            <th>Đơn giá snapshot</th>
                            <th>SL</th>
                            <th>Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <div><strong><?= View::e((string) $item['product_name']) ?></strong></div>
                                    <div class="adm-cell-sub">
                                        SKU: <?= View::e((string) $item['product_sku']) ?>
                                        · Bảo hành: <?= View::e((string) $item['warranty_period']) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($item['product_unit_id'])): ?>
                                        <div>
                                            <a href="<?= View::e(View::url('/admin/serial/' . (int) $item['product_unit_id'])) ?>">
                                                <strong><?= View::e((string) $item['unit_serial_number']) ?></strong>
                                            </a>
                                        </div>
                                        <div class="adm-cell-sub">
                                            Trạng thái máy hiện tại: <?= View::e((string) ($item['current_unit_status'] ?? '—')) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="adm-cell-sub">Quản lý theo số lượng</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= View::e(number_format((int) $item['price'], 0, ',', '.')) ?>đ</td>
                                <td><?= View::e((string) $item['quantity']) ?></td>
                                <td><strong><?= View::e(number_format((int) $item['subtotal'], 0, ',', '.')) ?>đ</strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="adm-order-totals">
                <div class="adm-total-row">
                    <span>Tạm tính tiền hàng:</span>
                    <strong><?= View::e(number_format((int) $order['total_product_amount'], 0, ',', '.')) ?>đ</strong>
                </div>
                <div class="adm-total-row">
                    <span>Voucher đã áp dụng:</span>
                    <span>
                        <?= !empty($order['coupon_code']) ? View::e((string) $order['coupon_code']) : 'Không sử dụng' ?>
                    </span>
                </div>
                <div class="adm-total-row">
                    <span>Số tiền giảm giá:</span>
                    <span>-<?= View::e(number_format((int) $order['discount_amount'], 0, ',', '.')) ?>đ</span>
                </div>
                <div class="adm-total-row">
                    <span>Phí giao hàng:</span>
                    <span><?= View::e(number_format((int) $order['shipping_fee'], 0, ',', '.')) ?>đ</span>
                </div>
                <div class="adm-total-row is-grand">
                    <span>Tổng tiền thanh toán:</span>
                    <strong><?= View::e(number_format((int) $order['final_amount'], 0, ',', '.')) ?>đ</strong>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <h2 class="adm-card-title">Lịch sử thao tác &amp; Truy vết Admin (Audit Log)</h2>
            <?php if ($logs === []): ?>
                <p class="adm-cell-sub">Chưa có thao tác quản trị nào được ghi nhận trên đơn hàng này.</p>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Thời điểm</th>
                                <th>Thao tác</th>
                                <th>Chuyển trạng thái</th>
                                <th>Admin thực hiện</th>
                                <th>Lý do / Ghi chú</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= View::e(date('d/m/Y H:i:s', strtotime((string) $log['created_at']))) ?></td>
                                    <td><strong><?= View::e($actionLabels[$log['action_type']] ?? (string) $log['action_type']) ?></strong></td>
                                    <td>
                                        <code><?= View::e((string) ($log['old_status'] ?? '—')) ?></code>
                                        →
                                        <code><?= View::e((string) ($log['new_status'] ?? '—')) ?></code>
                                    </td>
                                    <td>
                                        <?= View::e((string) ($log['actor_name'] ?? 'Hệ thống')) ?>
                                        <?php if (!empty($log['actor_email'])): ?>
                                            <div class="adm-cell-sub"><?= View::e((string) $log['actor_email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['reason'])): ?>
                                            <div><strong>Lý do:</strong> <?= View::e((string) $log['reason']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($log['note'])): ?>
                                            <div class="adm-cell-sub"><?= View::e((string) $log['note']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cột phải: Thanh toán & Thao tác State Machine -->
    <div class="adm-order-side-col">
        <div class="admin-card">
            <h2 class="adm-card-title">Thanh toán &amp; Đối soát</h2>
            <div class="adm-kv-stack">
                <div>
                    <span class="adm-kv-label">Phương thức thanh toán</span>
                    <div class="adm-kv-value"><strong><?= View::e($payMethodText) ?></strong></div>
                </div>
                <div>
                    <span class="adm-kv-label">Số tiền ghi nhận</span>
                    <div class="adm-kv-value"><strong><?= View::e(number_format($payAmount, 0, ',', '.')) ?>đ</strong></div>
                </div>
                <div>
                    <span class="adm-kv-label">Trạng thái thanh toán</span>
                    <div class="adm-kv-value">
                        <span class="adm-badge <?= View::e($payBadgeClass) ?>"><?= View::e($payStatusText) ?></span>
                        <?php if ($refundStatus === 'manual_required'): ?>
                            <span class="adm-badge adm-badge-danger" style="margin-left: 6px;">Cần hoàn tiền thủ công</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($payment['transaction_code'])): ?>
                    <div>
                        <span class="adm-kv-label">Mã giao dịch / Tham chiếu</span>
                        <div class="adm-kv-value"><code><?= View::e((string) $payment['transaction_code']) ?></code></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($payment['confirmed_at'])): ?>
                    <div>
                        <span class="adm-kv-label">Admin xác nhận thanh toán</span>
                        <div class="adm-kv-value">
                            <?= View::e((string) ($payment['confirmed_by_name'] ?? 'Admin')) ?>
                            (<?= View::e(date('d/m/Y H:i', strtotime((string) $payment['confirmed_at']))) ?>)
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($payStatus === 'pending' && $status !== 'cancelled'): ?>
                <hr class="adm-divider">
                <form method="POST" action="<?= View::e(View::url('/admin/don-hang/' . $orderId . '/xac-nhan-thanh-toan')) ?>" class="adm-action-form">
                    <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                    <div class="adm-field">
                        <label for="txCodeInput">
                            <?= $payMethod === 'bank_transfer' && $payAmount > 0
                                ? 'Mã giao dịch ngân hàng (bắt buộc)'
                                : 'Mã biên lai / tham chiếu (tùy chọn)' ?>
                        </label>
                        <input type="text"
                               id="txCodeInput"
                               name="transaction_code"
                               class="adm-input"
                               maxlength="100"
                               <?= ($payMethod === 'bank_transfer' && $payAmount > 0) ? 'required' : '' ?>
                               placeholder="Ví dụ: FT263180012345">
                    </div>
                    <div class="adm-field">
                        <label for="payNoteInput">Ghi chú đối soát</label>
                        <input type="text"
                               id="payNoteInput"
                               name="payment_note"
                               class="adm-input"
                               maxlength="255"
                               placeholder="Đã kiểm tra tiền về tài khoản / đã thu COD">
                    </div>
                    <button type="submit" class="adm-btn adm-btn-primary adm-btn-block">
                        Xác nhận đã thanh toán (<?= View::e(number_format($payAmount, 0, ',', '.')) ?>đ)
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="admin-card">
            <h2 class="adm-card-title">Xử lý trạng thái đơn hàng</h2>

            <?php if ($status === 'pending'): ?>
                <form method="POST" action="<?= View::e(View::url('/admin/don-hang/' . $orderId . '/xac-nhan')) ?>" class="adm-action-form">
                    <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                    <p class="adm-cell-sub" style="margin-bottom: 10px;">
                        Xác nhận đơn giữ nguyên tồn kho đã khóa lúc Checkout và không tự động đánh dấu đã thanh toán.
                    </p>
                    <button type="submit" class="adm-btn adm-btn-primary adm-btn-block">
                        ✓ Xác nhận đơn hàng
                    </button>
                </form>
            <?php elseif ($status === 'confirmed'): ?>
                <form method="POST" action="<?= View::e(View::url('/admin/don-hang/' . $orderId . '/giao-hang')) ?>" class="adm-action-form">
                    <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                    <div class="adm-field">
                        <label for="shipNoteInput">Ghi chú bàn giao / xử lý</label>
                        <input type="text" id="shipNoteInput" name="action_note" class="adm-input" maxlength="255"
                               placeholder="<?= $isPickup ? 'Đã chuẩn bị máy tại showroom' : 'Đã bàn giao cho đơn vị giao hàng' ?>">
                    </div>
                    <button type="submit" class="adm-btn adm-btn-primary adm-btn-block">
                        <?= $isPickup ? '📦 Chuẩn bị giao tại showroom' : '🚚 Chuyển sang Đang giao hàng' ?>
                    </button>
                </form>
            <?php elseif ($status === 'shipping'): ?>
                <form method="POST" action="<?= View::e(View::url('/admin/don-hang/' . $orderId . '/hoan-tat')) ?>" class="adm-action-form">
                    <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                    <?php if ($payStatus !== 'completed'): ?>
                        <div class="admin-alert admin-alert-warning" style="margin-bottom: 10px;">
                            Cần <strong>Xác nhận thanh toán</strong> (thu tiền COD / đối soát chuyển khoản) trước khi hoàn tất đơn.
                        </div>
                    <?php endif; ?>
                    <div class="adm-field">
                        <label for="completeNoteInput">Ghi chú hoàn tất</label>
                        <input type="text" id="completeNoteInput" name="action_note" class="adm-input" maxlength="255"
                               placeholder="Khách đã nhận máy và kiểm tra đầy đủ">
                    </div>
                    <button type="submit"
                            class="adm-btn adm-btn-primary adm-btn-block"
                            <?= $payStatus !== 'completed' ? 'disabled title="Vui lòng xác nhận thanh toán trước"' : '' ?>>
                        ✓ Hoàn tất đơn hàng (chuyển serial → sold)
                    </button>
                </form>
                <p class="adm-cell-sub" style="margin-top: 10px;">
                    Đơn đang giao không hỗ trợ hủy thường để hoàn tồn trực tiếp. Nếu khách không nhận hàng, cần quy trình trả hàng/nhập kho riêng.
                </p>
            <?php else: ?>
                <p class="adm-cell-sub">
                    Đơn hàng đang ở trạng thái cuối (<strong><?= View::e($stBadge['text']) ?></strong>). Không thể chuyển trạng thái hoặc hủy lại.
                </p>
            <?php endif; ?>

            <?php if (in_array($status, ['pending', 'confirmed'], true)): ?>
                <hr class="adm-divider">
                <form method="POST"
                      action="<?= View::e(View::url('/admin/don-hang/' . $orderId . '/huy')) ?>"
                      class="adm-action-form"
                      onsubmit="return confirm('Xác nhận hủy đơn hàng và hoàn tồn kho/voucher theo quy tắc?');">
                    <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                    <div class="adm-field">
                        <label for="cancelReasonInput">Lý do hủy đơn hàng (bắt buộc)</label>
                        <input type="text"
                               id="cancelReasonInput"
                               name="cancel_reason"
                               class="adm-input"
                               required
                               minlength="3"
                               maxlength="255"
                               placeholder="Ví dụ: Khách yêu cầu hủy / Không liên hệ được xác nhận">
                    </div>
                    <button type="submit" class="adm-btn adm-btn-danger adm-btn-block">
                        ✕ Hủy đơn hàng &amp; Hoàn tồn kho
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
