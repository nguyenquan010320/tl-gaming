<?php
/**
 * Trang Tra cứu đơn hàng bảo mật (`app/Views/checkout/lookup.php`) — TL Gaming.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

$lookupCode  = (string) ($lookupCode ?? '');
$lookupError = (string) ($lookupError ?? '');
$order       = is_array($order ?? null) ? $order : null;
$bankConfig  = is_array($bankConfig ?? null) ? $bankConfig : [];
?>

<section class="page-banner cart-page-header">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                <li class="breadcrumb-item"><a href="<?= View::e(View::url('/')) ?>">🏠 Trang chủ</a></li>
                <li class="breadcrumb-item"><span aria-current="page">Tra cứu đơn hàng</span></li>
            </ol>
        </nav>
        <h1 class="page-title" style="margin-bottom: 4px;">Tra cứu đơn hàng</h1>
        <p style="color: #64748b; font-size: 14px; margin: 0;">Nhập mã đơn hàng kèm số điện thoại hoặc email đặt hàng để xác minh quyền xem.</p>
    </div>
</section>

<section class="section-block checkout-main-section">
    <div class="container">
        <div class="card lookup-form-card">
            <form action="<?= View::e(View::url('/tra-cuu-don-hang')) ?>" method="POST" class="lookup-form-grid">
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="lookup_order_code" class="form-label">Mã đơn hàng <span class="req">*</span></label>
                    <input type="text" id="lookup_order_code" name="order_code" class="form-control" required
                           value="<?= View::e($lookupCode) ?>" placeholder="Ví dụ: TLG-20261113-AB12CD">
                </div>
                <div class="form-group">
                    <label for="lookup_contact" class="form-label">Số điện thoại hoặc Email đặt hàng <span class="req">*</span></label>
                    <input type="text" id="lookup_contact" name="verification_contact" class="form-control" required
                           placeholder="Ví dụ: 0912345678 hoặc email@example.com">
                </div>
                <div class="form-group lookup-submit-col">
                    <button type="submit" class="btn btn-primary btn-block">Tra cứu đơn hàng</button>
                </div>
            </form>

            <?php if ($lookupError !== ''): ?>
                <div class="alert-box alert-error" style="margin-top: 16px; padding: 12px 16px; border-radius: 8px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;">
                    <?= View::e($lookupError) ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($order !== null): ?>
            <?php
            $items     = is_array($order['items'] ?? null) ? $order['items'] : [];
            $payment   = is_array($order['payment'] ?? null) ? $order['payment'] : [];
            $payMethod = (string) ($payment['payment_method'] ?? 'cod');
            $payStatus = (string) ($payment['status'] ?? 'pending');
            ?>
            <div class="card order-success-card" style="margin-top: 24px;">
                <div class="order-success-hero" style="padding-bottom: 16px;">
                    <h2>Thông tin đơn hàng: <?= View::e((string) $order['order_code']) ?></h2>
                    <p style="margin: 0; color: #64748b;">Ngày đặt: <?= View::e((string) $order['created_at']) ?></p>
                </div>

                <div class="order-success-grid">
                    <div class="order-info-box">
                        <h3>Người nhận &amp; Giao hàng</h3>
                        <p><strong>Người nhận:</strong> <?= View::e((string) $order['customer_name']) ?></p>
                        <p><strong>Địa chỉ:</strong> <?= View::e((string) $order['shipping_address']) ?>, <?= View::e((string) $order['shipping_city']) ?></p>
                    </div>
                    <div class="order-info-box">
                        <h3>Trạng thái xử lý</h3>
                        <p><strong>Trạng thái đơn:</strong> <span class="status-pill status-pending"><?= View::e((string) $order['status']) ?></span></p>
                        <p><strong>Thanh toán:</strong> <?= $payMethod === 'bank_transfer' ? 'Chuyển khoản ngân hàng' : 'COD' ?> — <strong><?= View::e($payStatus) ?></strong></p>
                    </div>
                </div>

                <div class="order-items-table-wrap">
                    <table class="order-items-table">
                        <thead>
                            <tr>
                                <th>Sản phẩm</th>
                                <th>SKU / Serial</th>
                                <th>Đơn giá</th>
                                <th>SL</th>
                                <th>Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><strong><?= View::e((string) $item['product_name']) ?></strong></td>
                                    <td>
                                        SKU: <?= View::e((string) $item['product_sku']) ?>
                                        <?php if (!empty($item['unit_serial_number'])): ?>
                                            <div class="serial-chip">Serial: <?= View::e((string) $item['unit_serial_number']) ?></div>
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
                    <div><span>Giảm giá:</span> <strong class="text-rose">-<?= View::e(View::formatVnd((int) $order['discount_amount'])) ?></strong></div>
                    <div><span>Phí vận chuyển:</span> <strong><?= View::e(View::formatVnd((int) $order['shipping_fee'])) ?></strong></div>
                    <div class="order-grand-total"><span>Tổng thanh toán:</span> <strong><?= View::e(View::formatVnd((int) $order['final_amount'])) ?></strong></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
