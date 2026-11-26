<?php
/**
 * Giao diện Giỏ hàng (`app/Views/cart/index.php`) theo thiết kế chính thức Google Stitch — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Hiển thị giỏ hàng thực tế từ cơ sở dữ liệu MySQL (cho tài khoản) hoặc Session (cho khách vãng lai).
 * - Tuyệt đối không hiển thị dữ liệu demo tĩnh khi giỏ hàng trống.
 * - Toàn bộ đơn giá, số lượng, thành tiền và tạm tính được lấy trực tiếp từ bảng `products` trên Server.
 * - Khống chế số lượng tối đa theo tồn kho khả dụng (`purchasable_quantity`).
 * - Hỗ trợ cả 2 cơ chế tương tác: Fetch/AJAX mượt mà không tải lại trang và Form Fallback chuẩn HTML5.
 */

declare(strict_types=1);

use App\Core\ContactSettings;
use App\Core\Csrf;
use App\Core\View;

/** @var array<string, mixed> $cart */
$cartData      = is_array($cart ?? null) ? $cart : [];
$items         = is_array($cartData['items'] ?? null) ? $cartData['items'] : [];
$totalQuantity = (int) ($cartData['total_quantity'] ?? 0);
$distinctCount = (int) ($cartData['distinct_count'] ?? count($items));
$subTotal      = (int) ($cartData['subtotal'] ?? 0);
$discount      = (int) ($cartData['discount'] ?? 0);
$finalTotal    = (int) ($cartData['total'] ?? $subTotal);
$isEmpty       = empty($items) || !empty($cartData['is_empty']);
?>

<!-- =======================================================================
     1. BREADCRUMB & TIÊU ĐỀ TRANG GIỎ HÀNG CHUẨN GOOGLE STITCH
     ======================================================================= -->
<section class="page-banner cart-page-header">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                <li class="breadcrumb-item">
                    <a href="<?= View::e(View::url('/')) ?>">🏠 Trang chủ</a>
                </li>
                <li class="breadcrumb-item">
                    <span aria-current="page">Giỏ hàng của bạn</span>
                </li>
            </ol>
        </nav>

        <div class="stitch-cart-title-row">
            <div class="cart-title-left">
                <h1 class="page-title" style="margin-bottom: 0;">Giỏ hàng của bạn</h1>
                <span class="cart-count-badge" id="cartHeaderCountBadge">(<?= View::e((string) $totalQuantity) ?> dàn máy)</span>
            </div>
            <a href="<?= View::e(View::url('/san-pham')) ?>" class="stitch-continue-link">
                &larr; Tiếp tục mua hàng
            </a>
        </div>

        <!-- Thanh Đặc quyền khách hàng nền xanh nhạt chuẩn ảnh Google Stitch -->
        <div class="stitch-cart-privilege-banner">
            <div class="privilege-left">
                <span class="privilege-shield" aria-hidden="true">🛡️</span>
                <p>
                    <strong>Đặc quyền khách hàng TL GAMING:</strong>
                    Miễn phí vệ sinh tra keo tản nhiệt tiêu chuẩn &amp; kiểm tra phần cứng 18 bước trực tiếp theo mã Serial trước khi bàn giao.
                </p>
            </div>
            <span class="privilege-right-tag">🏅 Bảo hành 3 – 12 tháng minh bạch</span>
        </div>
    </div>
</section>

<!-- =======================================================================
     2. BỐ CỤC 12 CỘT GIỎ HÀNG CHUẨN STITCH (TRÁI 8 CỘT SẢN PHẨM + PHẢI 4 CỘT TÓM TẮT)
     ======================================================================= -->
<section class="section-block cart-main-section">
    <div class="container">
        <!-- Token CSRF dùng chung cho các request Fetch/AJAX trên trang giỏ -->
        <input type="hidden" id="cartGlobalCsrfToken" value="<?= View::e(Csrf::token()) ?>">

        <div class="stitch-cart-grid">
            <!-- CỘT TRÁI (8 CỘT): DANH SÁCH SẢN PHẨM TRONG GIỎ & DỊCH VỤ ĐẶC QUYỀN -->
            <div class="stitch-cart-left">
                <?php if (!$isEmpty): ?>
                    <?php if (!empty($cartData['has_pending_sync'])): ?>
                        <div class="cart-pending-sync-banner" role="status" style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 14px;">
                            <strong>Đang giữ giỏ hàng tạm:</strong> Một số sản phẩm bạn thêm trước khi đăng nhập đang được giữ lại an toàn và sẽ tự động đồng bộ vào tài khoản khi hệ thống lưu trữ ổn định.
                        </div>
                    <?php endif; ?>

                    <!-- Thanh tóm tắt số lượng sản phẩm & Xóa tất cả -->
                    <div class="card stitch-cart-select-bar">
                        <div class="cart-checkbox-label">
                            <strong>Sản phẩm trong giỏ (<span id="selectedItemsCount"><?= View::e((string) $totalQuantity) ?></span> sản phẩm)</strong>
                        </div>
                        <form id="cartClearAllForm" action="<?= View::e(View::url('/gio-hang/xoa-tat-ca')) ?>" method="POST" style="margin: 0;">
                            <?= Csrf::field() ?>
                            <button type="button" class="cart-clear-btn" id="cartClearAllBtn">
                                🗑️ Xóa tất cả
                            </button>
                        </form>
                    </div>

                    <!-- Danh sách thẻ sản phẩm thực tế trong giỏ -->
                    <div class="stitch-cart-items-stack" id="cartItemsList">
                        <?php foreach ($items as $item): ?>
                            <?php
                            $pId          = (int) $item['id'];
                            $pName        = (string) $item['name'];
                            $pSlug        = (string) $item['slug'];
                            $pSku         = (string) $item['sku'];
                            $pUrl         = View::url('/san-pham/' . rawurlencode($pSlug));
                            $pThumb       = View::imageUrl((string) $item['thumbnail']);
                            $pPrice       = (int) $item['price'];
                            $hasDiscount  = !empty($item['has_valid_discount']);
                            $pOldPrice    = $hasDiscount && !empty($item['original_price']) ? (int) $item['original_price'] : null;
                            $discountPct  = (int) ($item['discount_percent'] ?? 0);
                            $condition    = (string) ($item['condition_overview'] ?? 'Tuyển chọn 99%');
                            $warranty     = (int) ($item['warranty_months'] ?? 12);
                            $hasInsp      = !empty($item['has_verified_inspection']);
                            $maxStock     = max(1, (int) ($item['stock_quantity'] ?? 1));
                            $qty          = (int) ($item['quantity'] ?? 1);
                            $itemSubtotal = (int) ($item['item_subtotal'] ?? ($pPrice * $qty));
                            ?>
                            <article class="card stitch-cart-item-card"
                                     id="cartItemCard_<?= View::e((string) $pId) ?>"
                                     data-product-id="<?= View::e((string) $pId) ?>"
                                     data-unit-price="<?= View::e((string) $pPrice) ?>">
                                <a href="<?= View::e($pUrl) ?>" class="cart-item-thumb-wrap">
                                    <?php if ($hasDiscount && $discountPct > 0): ?>
                                        <span class="badge-discount cart-item-discount">-<?= View::e((string) $discountPct) ?>%</span>
                                    <?php endif; ?>
                                    <img src="<?= View::e($pThumb) ?>"
                                         alt="<?= View::e($pName) ?>"
                                         class="product-thumb-img">
                                </a>

                                <div class="cart-item-info-col">
                                    <div class="cart-item-top-row">
                                        <div class="cart-item-pills">
                                            <span class="cart-cond-pill">Tình trạng: <?= View::e($condition) ?></span>
                                            <span class="cart-insp-pill">
                                                <?= $hasInsp ? '✓ Đã kiểm định 18 bước' : 'Mã SKU: ' . View::e($pSku) ?>
                                            </span>
                                        </div>

                                        <!-- Nút xóa sản phẩm -->
                                        <form action="<?= View::e(View::url('/gio-hang/xoa')) ?>" method="POST" class="cart-item-delete-form" style="margin: 0;">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="product_id" value="<?= View::e((string) $pId) ?>">
                                            <button type="button"
                                                    class="cart-remove-item-btn cart-item-delete-btn"
                                                    data-product-id="<?= View::e((string) $pId) ?>"
                                                    title="Xóa sản phẩm khỏi giỏ"
                                                    aria-label="Xóa <?= View::e($pName) ?>">
                                                🗑️
                                            </button>
                                        </form>
                                    </div>

                                    <h2 class="cart-item-title">
                                        <a href="<?= View::e($pUrl) ?>"><?= View::e($pName) ?></a>
                                    </h2>

                                    <!-- Hộp dịch vụ đi kèm thực tế của cửa hàng -->
                                    <div class="cart-item-perk-box">
                                        <span aria-hidden="true">🛠️</span>
                                        <span>Đi kèm gói vệ sinh tiêu chuẩn, tra keo tản nhiệt MX-4 &amp; đồng kiểm theo đúng mã Serial xuất kho</span>
                                    </div>

                                    <div class="cart-item-service-row">
                                        <span class="service-label">✓ Chế độ bảo hành:</span>
                                        <span class="service-pill">Bảo hành <?= View::e((string) $warranty) ?> tháng tiêu chuẩn (Đã bao gồm)</span>
                                    </div>

                                    <div class="cart-item-bottom-row">
                                        <div class="price-group">
                                            <span class="price-current cart-item-price-display" id="itemSubtotal_<?= View::e((string) $pId) ?>">
                                                <?= View::e(View::formatVnd($pPrice)) ?>
                                            </span>
                                            <?php if ($pOldPrice !== null): ?>
                                                <span class="price-old"><?= View::e(View::formatVnd($pOldPrice)) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Bộ tăng giảm số lượng -->
                                        <div class="cart-item-qty-group">
                                            <span class="cart-qty-label">Số lượng:</span>
                                            <div class="qty-stepper cart-qty-stepper">
                                                <button type="button"
                                                        class="qty-step-btn cart-qty-minus"
                                                        data-product-id="<?= View::e((string) $pId) ?>"
                                                        aria-label="Giảm số lượng">&minus;</button>
                                                <input type="number"
                                                       class="qty-number-input cart-qty-input"
                                                       data-product-id="<?= View::e((string) $pId) ?>"
                                                       value="<?= View::e((string) $qty) ?>"
                                                       min="1"
                                                       max="<?= View::e((string) $maxStock) ?>"
                                                       aria-label="Số lượng">
                                                <button type="button"
                                                        class="qty-step-btn cart-qty-plus"
                                                        data-product-id="<?= View::e((string) $pId) ?>"
                                                        aria-label="Tăng số lượng">+</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <!-- Trạng thái giỏ hàng trống (Không hiển thị bất kỳ sản phẩm demo nào) -->
                    <div class="empty-state" style="background: #fff; border: 1px solid var(--border-light, #e2e8f0); border-radius: 12px; padding: 48px 24px; text-align: center; margin-bottom: 24px;">
                        <div style="font-size: 48px; margin-bottom: 16px;">🛒</div>
                        <h2 class="empty-state-title" style="font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 8px;">Giỏ hàng của bạn đang trống</h2>
                        <p style="color: #64748b; font-size: 14px; max-width: 480px; margin: 0 auto 24px;">
                            Bạn chưa có sản phẩm nào trong giỏ hàng. Hãy khám phá ngay các cấu hình PC Gaming, Laptop và Linh kiện cũ nguyên bản đã qua kiểm định 18 bước nghiêm ngặt!
                        </p>
                        <div class="empty-state-actions">
                            <a href="<?= View::e(View::url('/san-pham')) ?>" class="btn btn-primary" style="padding: 10px 24px; font-weight: 600;">
                                Khám phá sản phẩm ngay
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Hàng nút Tiếp tục mua hàng & Ghi chú bảo mật chuẩn Stitch -->
                <div class="stitch-cart-footer-actions">
                    <a href="<?= View::e(View::url('/san-pham')) ?>" class="btn btn-outline">
                        &larr; Tiếp tục mua hàng
                    </a>
                    <span class="cart-ssl-note">
                        🔒 Giao dịch thanh toán được kiểm tra tồn kho và tính tiền bảo mật 100% trên Server
                    </span>
                </div>

                <!-- Khối Dịch vụ & Đặc quyền khi mua máy tại TL GAMING chuẩn ảnh Stitch -->
                <div class="stitch-cart-privilege-box">
                    <div class="privilege-box-head">
                        <div class="privilege-head-title">
                            <span aria-hidden="true">🏅</span>
                            <h3>Dịch vụ &amp; Đặc quyền khi mua máy tính tại TL GAMING</h3>
                        </div>
                        <span class="gold-commit-pill">Cam kết vàng</span>
                    </div>

                    <div class="privilege-cards-grid">
                        <div class="privilege-mini-card">
                            <div class="priv-mini-icon" aria-hidden="true">⚙️</div>
                            <div>
                                <h4>Cài đặt Windows &amp; Game theo yêu cầu</h4>
                                <p>Tối ưu driver, kích hoạt XMP, cài sẵn tựa game theo mong muốn hoàn toàn miễn phí.</p>
                            </div>
                        </div>

                        <div class="privilege-mini-card">
                            <div class="priv-mini-icon" aria-hidden="true">🚚</div>
                            <div>
                                <h4>Đóng thùng xốp 3 lớp chống sốc</h4>
                                <p>Bọc bóng khí chuyên dụng, chèn chống xệ card màn hình khi giao toàn quốc an toàn tuyệt đối.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CỘT PHẢI (4 CỘT): TÓM TẮT ĐƠN HÀNG, VOUCHER & CAM KẾT DỊCH VỤ -->
            <div class="stitch-cart-right">
                <div class="card stitch-cart-summary-card">
                    <div class="summary-card-head">
                        <h2 class="summary-card-title">Tóm tắt đơn hàng</h2>
                        <span class="summary-selected-text">
                            <span id="summarySelectedCount"><?= View::e((string) $totalQuantity) ?></span> sản phẩm trong giỏ
                        </span>
                    </div>

                    <!-- Khu vực nhập Mã giảm giá / Voucher chuẩn ảnh Stitch (Không giả lập giảm giá ảo) -->
                    <div class="stitch-voucher-box">
                        <label for="cartVoucherInput" class="voucher-label">
                            🎟️ Mã giảm giá / Voucher TL GAMING
                        </label>
                        <div class="voucher-input-row">
                            <input type="text"
                                   id="cartVoucherInput"
                                   class="form-control voucher-input"
                                   value=""
                                   placeholder="Nhập mã voucher (VD: TLGAMING500K)...">
                            <button type="button" class="btn btn-navy-dark" id="cartApplyVoucherBtn">
                                Áp dụng
                            </button>
                        </div>
                        <div class="voucher-chips-row">
                            <button type="button" class="voucher-chip-tag" data-code="TLGAMING500K">
                                TLGAMING500K (Đơn từ 10Tr)
                            </button>
                            <button type="button" class="voucher-chip-tag" data-code="WELCOME10">
                                WELCOME10 (Đơn từ 5Tr)
                            </button>
                        </div>
                    </div>

                    <!-- Bảng tính Tạm tính, Giảm giá, Vận chuyển & Tổng cộng chuẩn Server -->
                    <div class="summary-calc-rows">
                        <div class="summary-calc-line">
                            <span>Tạm tính (<span id="calcItemCount"><?= View::e((string) $totalQuantity) ?></span> sản phẩm)</span>
                            <strong id="cartSubtotalDisplay"><?= View::e(View::formatVnd($subTotal)) ?></strong>
                        </div>
                        <div class="summary-calc-line summary-discount-line">
                            <span>🏷️ Giảm giá voucher</span>
                            <strong class="text-rose" id="cartDiscountDisplay">
                                <?= $discount > 0 ? ('-' . View::e(View::formatVnd($discount))) : 'Chưa áp dụng' ?>
                            </strong>
                        </div>
                        <div class="summary-calc-line">
                            <span>Phí vận chuyển</span>
                            <strong>Tính ở bước thanh toán</strong>
                        </div>
                        <div class="summary-calc-line">
                            <span>Dịch vụ cài đặt Windows &amp; Game</span>
                            <strong>Hỗ trợ theo yêu cầu</strong>
                        </div>
                    </div>

                    <div class="summary-total-row">
                        <span class="total-label">TỔNG CỘNG</span>
                        <div class="total-val-col">
                            <strong class="total-price-red" id="cartFinalTotalDisplay">
                                <?= View::e(View::formatVnd($finalTotal)) ?>
                            </strong>
                            <span class="total-vat-note">Đã bao gồm VAT &amp; Tem bảo hành 1 đổi 1</span>
                        </div>
                    </div>

                    <button type="button"
                            class="btn btn-primary btn-block btn-lg cart-checkout-btn <?= $isEmpty ? 'btn-disabled' : '' ?>"
                            id="cartProceedCheckoutBtn"
                            <?= $isEmpty ? 'disabled aria-disabled="true"' : '' ?>>
                        TIẾN HÀNH THANH TOÁN &rarr;
                    </button>

                    <p class="cart-installment-note">
                        💬 Xác nhận giao hàng &amp; thanh toán ở bước đặt hàng tiếp theo
                    </p>

                    <!-- Hộp thông báo trạng thái khi tương tác trên trang Giỏ hàng -->
                    <div class="cart-dev-notice" id="cartPageActionNotice" role="status" aria-live="polite" hidden style="margin-top: 12px;">
                        <strong>Thông báo:</strong>
                        <span id="cartPageActionNoticeText"></span>
                    </div>
                </div>

                <!-- Thẻ Cam kết dịch vụ tại TL GAMING bên dưới Tóm tắt đơn hàng chuẩn Stitch -->
                <div class="card stitch-cart-commit-card">
                    <h3 class="commit-card-heading">✓ CAM KẾT DỊCH VỤ TẠI TL GAMING</h3>
                    <ul class="commit-card-list">
                        <li>
                            <span class="commit-list-icon" aria-hidden="true">📦</span>
                            <div>
                                <strong>Kiểm tra hàng trước khi thanh toán</strong>
                                <p>Đồng kiểm linh kiện, cắm nguồn test hiệu năng trực tiếp khi nhận hàng trước khi thanh toán tiền mặt hoặc quẹt thẻ.</p>
                            </div>
                        </li>
                        <li>
                            <span class="commit-list-icon" aria-hidden="true">🔄</span>
                            <div>
                                <strong>Đổi trả 7 ngày không cần lý do</strong>
                                <p>Lỗi là đổi mới tương đương ngay lập tức, không chờ đợi thẩm định lòng vòng.</p>
                            </div>
                        </li>
                        <li>
                            <span class="commit-list-icon" aria-hidden="true">🎧</span>
                            <div>
                                <strong>Hotline kỹ thuật trực 24/7</strong>
                                <?php $supportHotline = ContactSettings::configuration()['hotline']; ?>
                                <p>Hỗ trợ Ultraview cài game, driver từ xa trọn đời máy: <a href="<?= View::e($supportHotline['href']) ?>"><strong><?= View::e($supportHotline['display']) ?></strong></a>.</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>
