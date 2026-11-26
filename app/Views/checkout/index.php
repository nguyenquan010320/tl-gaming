<?php
/**
 * Giao diện Thanh toán đơn hàng (`app/Views/checkout/index.php`) theo thiết kế Google Stitch — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Hiển thị biểu mẫu thông tin người nhận, hình thức nhận hàng (Giao tận nơi / Nhận tại showroom),
 *   phương thức thanh toán (COD / Chuyển khoản ngân hàng) và tóm tắt đơn hàng từ Server.
 * - Mọi giá sản phẩm, giảm giá voucher, phí vận chuyển và tổng thanh toán đều do Server tính toán;
 *   JavaScript chỉ hỗ trợ gọi API xem trước (`/thanh-toan/xem-truoc`) và bảo vệ chống bấm đúp.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

/** @var array<string, mixed> $cart */
/** @var array<string, mixed> $old */
/** @var array<string, array<string, string>> $showrooms */
/** @var array<string, mixed> $shippingConfig */
/** @var array<string, mixed> $bankConfig */

$items          = is_array($cart['items'] ?? null) ? $cart['items'] : [];
$totalQuantity  = (int) ($cart['total_quantity'] ?? 0);
$subtotal       = (int) ($subtotal ?? ($cart['subtotal'] ?? 0));
$shippingFee    = (int) ($shippingFee ?? 0);
$discountAmount = (int) ($discountAmount ?? 0);
$finalAmount    = (int) ($finalAmount ?? max(0, $subtotal + $shippingFee - $discountAmount));
$deliveryMethod = (string) ($old['delivery_method'] ?? 'delivery');
if ($deliveryMethod === 'pickup' && $showrooms === []) {
    $deliveryMethod = 'delivery';
}
$bankTransferEnabled = !empty($bankTransferEnabled)
    && !empty($bankConfig['bank_name'])
    && !empty($bankConfig['account_number'])
    && !empty($bankConfig['account_holder']);
$paymentMethod  = (string) ($old['payment_method'] ?? 'cod');
if ($paymentMethod === 'bank_transfer' && !$bankTransferEnabled) {
    $paymentMethod = 'cod';
}
$selectedShowroom = (string) ($old['showroom_id'] ?? 'hn');
$couponCode     = (string) ($old['coupon_code'] ?? '');
$idempotencyKey = (string) ($idempotencyKey ?? '');
$freeThreshold  = (int) ($shippingConfig['free_threshold'] ?? 10000000);
?>

<!-- Thanh tiến trình 3 bước chuẩn Google Stitch -->
<section class="page-banner checkout-steps-banner">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                <li class="breadcrumb-item"><a href="<?= View::e(View::url('/')) ?>">🏠 Trang chủ</a></li>
                <li class="breadcrumb-item"><a href="<?= View::e(View::url('/gio-hang')) ?>">Giỏ hàng</a></li>
                <li class="breadcrumb-item"><span aria-current="page">Thanh toán đơn hàng</span></li>
            </ol>
        </nav>

        <div class="stitch-checkout-header">
            <div>
                <h1 class="page-title" style="margin-bottom: 4px;">Thanh toán đơn hàng</h1>
                <p style="color: #64748b; font-size: 14px; margin: 0;">Kiểm tra thông tin nhận hàng và xác nhận đơn với tồn kho thực tế từ TL Gaming.</p>
            </div>
            <a href="<?= View::e(View::url('/gio-hang')) ?>" class="stitch-continue-link">&larr; Quay lại giỏ hàng</a>
        </div>

        <ol class="stitch-steps-bar" aria-label="Tiến trình đặt hàng">
            <li class="stitch-step is-done">
                <span class="step-num">✓</span>
                <span class="step-label">Bước 1: Giỏ hàng</span>
            </li>
            <li class="stitch-step is-active">
                <span class="step-num">2</span>
                <span class="step-label">Bước 2: Giao hàng &amp; Thanh toán</span>
            </li>
            <li class="stitch-step">
                <span class="step-num">3</span>
                <span class="step-label">Bước 3: Hoàn tất đơn hàng</span>
            </li>
        </ol>
    </div>
</section>

<section class="section-block checkout-main-section">
    <div class="container">
        <form id="stitchCheckoutForm" action="<?= View::e(View::url('/thanh-toan')) ?>" method="POST" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="idempotency_key" value="<?= View::e($idempotencyKey) ?>">
            <input type="hidden" name="expected_subtotal" id="checkoutExpectedSubtotal" value="<?= View::e((string) $subtotal) ?>">
            <input type="hidden" name="expected_final_amount" id="checkoutExpectedFinalAmount" value="<?= View::e((string) $finalAmount) ?>">

            <div class="stitch-checkout-grid">
                <!-- CỘT TRÁI: THÔNG TIN NGƯỜI NHẬN, HÌNH THỨC NHẬN HÀNG, THANH TOÁN -->
                <div class="stitch-checkout-left">
                    <!-- 1. Thông tin người nhận & Hình thức nhận hàng -->
                    <div class="card stitch-checkout-card">
                        <div class="checkout-card-head">
                            <h2>1. Thông tin người nhận &amp; Hình thức nhận hàng</h2>
                        </div>

                        <!-- Chọn hình thức nhận hàng -->
                        <div class="delivery-method-tabs" role="radiogroup" aria-label="Hình thức nhận hàng">
                            <label class="delivery-tab-option <?= $deliveryMethod === 'delivery' ? 'is-selected' : '' ?>">
                                <input type="radio" name="delivery_method" value="delivery" <?= $deliveryMethod === 'delivery' ? 'checked' : '' ?>>
                                <span>🚚 Giao tận nơi</span>
                            </label>
                            <?php if ($showrooms !== []): ?>
                                <label class="delivery-tab-option <?= $deliveryMethod === 'pickup' ? 'is-selected' : '' ?>">
                                    <input type="radio" name="delivery_method" value="pickup" <?= $deliveryMethod === 'pickup' ? 'checked' : '' ?>>
                                    <span>🏬 Nhận tại Showroom (Miễn phí ship)</span>
                                </label>
                            <?php endif; ?>
                        </div>

                        <div class="checkout-form-grid">
                            <div class="form-group">
                                <label for="customer_name" class="form-label">Họ và tên người nhận <span class="req">*</span></label>
                                <input type="text" id="customer_name" name="customer_name" class="form-control" required
                                       value="<?= View::e((string) ($old['customer_name'] ?? '')) ?>"
                                       placeholder="Ví dụ: Nguyễn Văn An">
                            </div>

                            <div class="form-group">
                                <label for="customer_phone" class="form-label">Số điện thoại <span class="req">*</span></label>
                                <input type="tel" id="customer_phone" name="customer_phone" class="form-control" required
                                       value="<?= View::e((string) ($old['customer_phone'] ?? '')) ?>"
                                       placeholder="Ví dụ: 0912345678">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="customer_email" class="form-label">Email nhận thông tin đơn hàng &amp; bảo hành <span class="req">*</span></label>
                            <input type="email" id="customer_email" name="customer_email" class="form-control" required
                                   value="<?= View::e((string) ($old['customer_email'] ?? '')) ?>"
                                   placeholder="Ví dụ: an.nguyen@gmail.com">
                        </div>

                        <!-- Khối địa chỉ giao tận nơi -->
                        <div id="deliveryAddressFields" <?= $deliveryMethod === 'pickup' ? 'hidden' : '' ?>>
                            <div class="checkout-form-grid checkout-form-grid-3">
                                <div class="form-group">
                                    <label for="shipping_city" class="form-label">Tỉnh / Thành phố <span class="req">*</span></label>
                                    <input type="text" id="shipping_city" name="shipping_city" class="form-control"
                                           value="<?= View::e((string) ($old['shipping_city'] ?? 'Hà Nội')) ?>"
                                           placeholder="VD: Hà Nội, TP. Hồ Chí Minh">
                                </div>

                                <div class="form-group">
                                    <label for="shipping_district" class="form-label">Quận / Huyện <span class="req">*</span></label>
                                    <input type="text" id="shipping_district" name="shipping_district" class="form-control"
                                           value="<?= View::e((string) ($old['shipping_district'] ?? '')) ?>"
                                           placeholder="VD: Quận Cầu Giấy">
                                </div>

                                <div class="form-group">
                                    <label for="shipping_ward" class="form-label">Phường / Xã</label>
                                    <input type="text" id="shipping_ward" name="shipping_ward" class="form-control"
                                           value="<?= View::e((string) ($old['shipping_ward'] ?? '')) ?>"
                                           placeholder="VD: Phường Dịch Vọng">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="shipping_address" class="form-label">Địa chỉ chi tiết (Số nhà, tên đường...) <span class="req">*</span></label>
                                <input type="text" id="shipping_address" name="shipping_address" class="form-control"
                                       value="<?= View::e((string) ($old['shipping_address'] ?? '')) ?>"
                                       placeholder="Ví dụ: Số 18, Ngõ 86 Phố Duy Tân">
                            </div>
                        </div>

                        <!-- Khối chọn Showroom nhận hàng -->
                        <div id="pickupShowroomFields" <?= $deliveryMethod === 'pickup' ? '' : 'hidden' ?>>
                            <div class="form-group">
                                <label class="form-label">Chọn Showroom TL Gaming nhận máy trực tiếp <span class="req">*</span></label>
                                <div class="showroom-radio-list">
                                    <?php foreach ($showrooms as $srKey => $sr): ?>
                                        <label class="showroom-radio-item">
                                            <input type="radio" name="showroom_id" value="<?= View::e((string) $srKey) ?>"
                                                <?= $selectedShowroom === (string) $srKey ? 'checked' : '' ?>>
                                            <div>
                                                <strong><?= View::e((string) ($sr['name'] ?? '')) ?></strong>
                                                <span><?= View::e((string) ($sr['address'] ?? '')) ?></span>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="customer_note" class="form-label">Ghi chú đơn hàng &amp; yêu cầu kỹ thuật</label>
                            <textarea id="customer_note" name="customer_note" class="form-control" rows="2"
                                      placeholder="Ví dụ: Nhờ kỹ thuật kiểm tra kỹ cổng DP hoặc cài sẵn phần mềm theo nhu cầu..."><?= View::e((string) ($old['customer_note'] ?? '')) ?></textarea>
                        </div>
                    </div>

                    <!-- 2. Phương thức thanh toán -->
                    <div class="card stitch-checkout-card">
                        <div class="checkout-card-head">
                            <h2>2. Phương thức thanh toán</h2>
                        </div>

                        <div class="payment-methods-stack">
                            <label class="payment-method-card <?= $paymentMethod === 'cod' ? 'is-selected' : '' ?>">
                                <input type="radio" name="payment_method" value="cod" <?= $paymentMethod === 'cod' ? 'checked' : '' ?>>
                                <div class="payment-method-body">
                                    <div class="payment-method-title">
                                        <strong>Thanh toán khi nhận hàng (COD)</strong>
                                        <span class="payment-badge">Khuyên dùng</span>
                                    </div>
                                    <p>Kiểm tra ngoại hình, đối chiếu mã Serial và test máy trước khi thanh toán tiền mặt cho nhân viên giao hàng.</p>
                                </div>
                            </label>

                            <?php if ($bankTransferEnabled): ?>
                                <label class="payment-method-card <?= $paymentMethod === 'bank_transfer' ? 'is-selected' : '' ?>">
                                    <input type="radio" name="payment_method" value="bank_transfer" <?= $paymentMethod === 'bank_transfer' ? 'checked' : '' ?>>
                                    <div class="payment-method-body">
                                        <div class="payment-method-title">
                                            <strong>Chuyển khoản ngân hàng</strong>
                                            <span class="payment-badge payment-badge-neutral">Đối soát theo mã đơn</span>
                                        </div>
                                        <p>Đơn hàng được ghi nhận ở trạng thái chờ thanh toán. Sau khi đặt thành công, hệ thống hiển thị mã đơn để bạn ghi nội dung chuyển khoản.</p>
                                        <div class="bank-preview-note">
                                            Ngân hàng: <strong><?= View::e((string) $bankConfig['bank_name']) ?></strong> —
                                            STK: <strong><?= View::e((string) $bankConfig['account_number']) ?></strong>
                                            (<?= View::e((string) $bankConfig['account_holder']) ?>)
                                        </div>
                                    </div>
                                </label>
                            <?php else: ?>
                                <p class="field-hint">Hình thức chuyển khoản ngân hàng hiện chưa cấu hình tài khoản nhận tiền, vui lòng thanh toán khi nhận hàng (COD).</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 3. Đồng ý chính sách mua hàng -->
                    <div class="card stitch-checkout-card stitch-policy-card">
                        <label class="policy-agree-label">
                            <input type="checkbox" name="agree_policy" id="agreePolicyCheckbox" value="1" checked required>
                            <span>
                                Tôi đã đọc và đồng ý với <strong>Quy chế đồng kiểm tra máy cũ theo Serial</strong> và
                                <strong>Chính sách bảo hành minh bạch 3 – 12 tháng</strong> của TL Gaming.
                            </span>
                        </label>
                    </div>
                </div>

                <!-- CỘT PHẢI: TÓM TẮT ĐƠN HÀNG, VOUCHER, TÍNH TIỀN & XÁC NHẬN -->
                <div class="stitch-checkout-right">
                    <div class="card stitch-cart-summary-card">
                        <div class="summary-card-head">
                            <h2 class="summary-card-title">Đơn hàng của bạn</h2>
                            <span class="summary-selected-text"><?= View::e((string) $totalQuantity) ?> sản phẩm</span>
                        </div>

                        <!-- Danh sách sản phẩm tóm tắt -->
                        <div class="checkout-items-mini-list">
                            <?php foreach ($items as $item): ?>
                                <?php
                                $pThumb = View::imageUrl((string) ($item['thumbnail'] ?? ''));
                                $pQty   = (int) ($item['quantity'] ?? 1);
                                $pSub   = (int) ($item['item_subtotal'] ?? 0);
                                ?>
                                <div class="checkout-mini-item">
                                    <img src="<?= View::e($pThumb) ?>" alt="<?= View::e((string) ($item['name'] ?? '')) ?>" class="checkout-mini-thumb">
                                    <div class="checkout-mini-info">
                                        <div class="checkout-mini-name"><?= View::e((string) ($item['name'] ?? '')) ?></div>
                                        <div class="checkout-mini-meta">
                                            SKU: <?= View::e((string) ($item['sku'] ?? '')) ?> • SL: <strong><?= View::e((string) $pQty) ?></strong>
                                        </div>
                                    </div>
                                    <div class="checkout-mini-price"><?= View::e(View::formatVnd($pSub)) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Nhập Voucher kiểm tra trực tiếp trên Server -->
                        <div class="stitch-voucher-box" style="margin-top: 14px;">
                            <label for="checkoutCouponInput" class="voucher-label">🎟️ Mã giảm giá / Voucher TL GAMING</label>
                            <div class="voucher-input-row">
                                <input type="text"
                                       id="checkoutCouponInput"
                                       name="coupon_code"
                                       class="form-control voucher-input"
                                       value="<?= View::e($couponCode) ?>"
                                       placeholder="Nhập mã (VD: TLGAMING500K)...">
                                <button type="button" class="btn btn-navy-dark" id="checkoutApplyCouponBtn">Áp dụng</button>
                            </div>
                            <p id="checkoutCouponMessage" class="checkout-coupon-msg" style="margin: 6px 0 0; font-size: 13px; color: #059669;" <?= $couponNotice === '' ? 'hidden' : '' ?>>
                                <?= View::e($couponNotice) ?>
                            </p>
                        </div>

                        <!-- Bảng tính tiền từ Server -->
                        <div class="summary-calc-rows">
                            <div class="summary-calc-line">
                                <span>Tạm tính (<?= View::e((string) $totalQuantity) ?> sản phẩm)</span>
                                <strong id="checkoutSubtotalDisplay"><?= View::e(View::formatVnd($subtotal)) ?></strong>
                            </div>
                            <div class="summary-calc-line summary-discount-line">
                                <span>🏷️ Giảm giá voucher</span>
                                <strong class="text-rose" id="checkoutDiscountDisplay">
                                    <?= $discountAmount > 0 ? ('-' . View::e(View::formatVnd($discountAmount))) : '0 ₫' ?>
                                </strong>
                            </div>
                            <div class="summary-calc-line">
                                <span>Phí vận chuyển <em class="ship-fast-pill">Cấu hình demo</em></span>
                                <strong id="checkoutShippingDisplay">
                                    <?= $shippingFee === 0 ? 'Miễn phí (0 ₫)' : View::e(View::formatVnd($shippingFee)) ?>
                                </strong>
                            </div>
                        </div>

                        <p style="font-size: 12px; color: #64748b; margin: 4px 0 12px;">
                            Chính sách demo: Miễn phí giao hàng cho đơn từ <?= View::e(View::formatVnd($freeThreshold)) ?> hoặc khi nhận tại Showroom.
                        </p>

                        <div class="summary-total-row">
                            <span class="total-label">TỔNG THANH TOÁN</span>
                            <div class="total-val-col">
                                <strong class="total-price-red" id="checkoutFinalTotalDisplay">
                                    <?= View::e(View::formatVnd($finalAmount)) ?>
                                </strong>
                                <span class="total-vat-note">Giá trị tính toán &amp; xác nhận trực tiếp trên Server</span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block btn-lg cart-checkout-btn" id="checkoutSubmitBtn">
                            ✓ XÁC NHẬN ĐẶT HÀNG
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>
