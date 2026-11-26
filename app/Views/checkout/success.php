<?php
/**
 * Trang Đặt hàng thành công (`app/Views/checkout/success.php`) — TL Gaming.
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<string, mixed> $order */
/** @var array<string, mixed> $bankConfig */

$items   = is_array($order['items'] ?? null) ? $order['items'] : [];
$payment = is_array($order['payment'] ?? null) ? $order['payment'] : [];
$payMethod = (string) ($payment['payment_method'] ?? 'cod');
$payStatus = (string) ($payment['status'] ?? 'pending');
?>

<section class="page-banner checkout-steps-banner">
    <div class="container">
        <ol class="stitch-steps-bar" aria-label="Tiến trình đặt hàng">
            <li class="stitch-step is-done">
                <span class="step-num">✓</span>
                <span class="step-label">Bước 1: Giỏ hàng</span>
            </li>
            <li class="stitch-step is-done">
                <span class="step-num">✓</span>
                <span class="step-label">Bước 2: Giao hàng &amp; Thanh toán</span>
            </li>
            <li class="stitch-step is-active">
                <span class="step-num">3</span>
                <span class="step-label">Bước 3: Hoàn tất đơn hàng</span>
            </li>
        </ol>
    </div>
</section>

<section class="section-block checkout-main-section">
    <div class="container">
        <div class="card order-success-card">
            <div class="order-success-hero">
                <div class="success-check-circle" aria-hidden="true">✓</div>
                <h1>Đặt hàng thành công!</h1>
                <p>Cảm ơn bạn đã tin tưởng TL Gaming. Đơn hàng của bạn đã được ghi nhận vào hệ thống và đang chờ xác nhận.</p>
                <div class="order-code-pill">
                    Mã đơn hàng: <strong><?= View::e((string) $order['order_code']) ?></strong>
                </div>
            </div>

            <div class="order-success-grid">
                <div class="order-info-box">
                    <h3>Thông tin người nhận</h3>
                    <p><strong>Người nhận:</strong> <?= View::e((string) $order['customer_name']) ?></p>
                    <p><strong>Số điện thoại:</strong> <?= View::e((string) $order['customer_phone']) ?></p>
                    <p><strong>Email:</strong> <?= View::e((string) $order['customer_email']) ?></p>
                    <p>
                        <strong>Địa chỉ:</strong>
                        <?= View::e((string) $order['shipping_address']) ?>
                        <?php if (!empty($order['shipping_district'])): ?>
                            , <?= View::e((string) $order['shipping_district']) ?>
                        <?php endif; ?>
                        , <?= View::e((string) $order['shipping_city']) ?>
                    </p>
                    <?php if (!empty($order['customer_note'])): ?>
                        <p><strong>Ghi chú:</strong> <?= View::e((string) $order['customer_note']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="order-info-box">
                    <h3>Thanh toán &amp; Trạng thái</h3>
                    <p>
                        <strong>Trạng thái đơn:</strong>
                        <span class="status-pill status-pending">Chờ xác nhận (<?= View::e((string) $order['status']) ?>)</span>
                    </p>
                    <p>
                        <strong>Hình thức thanh toán:</strong>
                        <?= $payMethod === 'bank_transfer' ? 'Chuyển khoản ngân hàng' : 'Thanh toán khi nhận hàng (COD)' ?>
                    </p>
                    <p>
                        <strong>Trạng thái thanh toán:</strong>
                        <span class="status-pill status-pending">Chưa thanh toán (<?= View::e($payStatus) ?>)</span>
                    </p>

                    <?php if ($payMethod === 'bank_transfer'): ?>
                        <div class="bank-transfer-box">
                            <p style="margin: 0 0 6px;"><strong>Hướng dẫn chuyển khoản:</strong></p>
                            <p style="margin: 0 0 6px;">
                                Nội dung chuyển khoản: <code>Thanh toan don hang <?= View::e((string) $order['order_code']) ?></code>
                            </p>
                            <?php if (!empty($bankConfig['bank_name']) && !empty($bankConfig['account_number'])): ?>
                                <p style="margin: 0;">
                                    Ngân hàng: <strong><?= View::e((string) $bankConfig['bank_name']) ?></strong><br>
                                    Số tài khoản: <strong><?= View::e((string) $bankConfig['account_number']) ?></strong>
                                    <?php if (!empty($bankConfig['account_holder'])): ?>
                                        <br>Chủ tài khoản: <strong><?= View::e((string) $bankConfig['account_holder']) ?></strong>
                                    <?php endif; ?>
                                </p>
                            <?php else: ?>
                                <p style="margin: 0; color: #64748b;">
                                    Thông tin số tài khoản ngân hàng chưa được cấu hình trực tiếp trên website. Nhân viên TL Gaming sẽ liên hệ xác nhận thông tin chuyển khoản theo mã đơn <strong><?= View::e((string) $order['order_code']) ?></strong>.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="order-items-table-wrap">
                <h3>Chi tiết sản phẩm đã đặt</h3>
                <table class="order-items-table">
                    <thead>
                        <tr>
                            <th>Sản phẩm</th>
                            <th>Mã SKU / Serial giữ máy</th>
                            <th>Đơn giá</th>
                            <th>SL</th>
                            <th>Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= View::e((string) $item['product_name']) ?></strong>
                                    <div style="font-size: 12px; color: #64748b;"><?= View::e((string) $item['warranty_period']) ?></div>
                                </td>
                                <td>
                                    <div>SKU: <?= View::e((string) $item['product_sku']) ?></div>
                                    <?php if (!empty($item['unit_serial_number'])): ?>
                                        <div class="serial-chip">Serial: <?= View::e((string) $item['unit_serial_number']) ?></div>
                                    <?php else: ?>
                                        <div style="font-size: 12px; color: #64748b;">Quản lý theo số lượng</div>
                                    <?php endif; ?>
                                </td>
                                <td><?= View::e(View::formatVnd((int) $item['price'])) ?></td>
                                <td><?= View::e((string) $item['quantity']) ?></td>
                                <td><strong><?= View::e(View::formatVnd((int) $item['subtotal'])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="order-totals-box">
                <div><span>Tạm tính:</span> <strong><?= View::e(View::formatVnd((int) $order['total_product_amount'])) ?></strong></div>
                <div>
                    <span>Giảm giá voucher<?= !empty($order['coupon_code']) ? ' (' . View::e((string) $order['coupon_code']) . ')' : '' ?>:</span>
                    <strong class="text-rose">-<?= View::e(View::formatVnd((int) $order['discount_amount'])) ?></strong>
                </div>
                <div><span>Phí vận chuyển:</span> <strong><?= View::e(View::formatVnd((int) $order['shipping_fee'])) ?></strong></div>
                <div class="order-grand-total"><span>Tổng thanh toán:</span> <strong><?= View::e(View::formatVnd((int) $order['final_amount'])) ?></strong></div>
            </div>

            <div class="order-success-actions">
                <a href="<?= View::e(View::url('/tra-cuu-don-hang')) ?>" class="btn btn-outline">Tra cứu đơn hàng</a>
                <a href="<?= View::e(View::url('/san-pham')) ?>" class="btn btn-primary">Tiếp tục mua sắm</a>
            </div>
        </div>
    </div>
</section>
