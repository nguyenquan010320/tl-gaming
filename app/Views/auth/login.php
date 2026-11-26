<?php
/**
 * Giao diện Đăng nhập & Đăng ký Tài khoản (`app/Views/auth/login.php`) theo thiết kế Google Stitch — TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Cung cấp giao diện Tài khoản đồng bộ hoàn toàn với hệ màu Clinical Light Retail + Deep Navy Header của Google Stitch:
 *   + Breadcrumb: `Trang chủ / Tài khoản thành viên`
 *   + Bố cục 2 cột trong thẻ lớn (`stitch-auth-shell`):
 *     * Cột trái (5 cột - Nền Deep Navy `#141C2B`): Giới thiệu quyền lợi thành viên TL Gaming (Tra cứu bảo hành theo Serial,
 *       Nhận mã giảm giá Voucher độc quyền, Theo dõi trạng thái đơn hàng & biên bản kiểm định 18 bước).
 *     * Cột phải (7 cột - Nền trắng `#FFFFFF`): Thanh chuyển đổi 2 Tab (`Đăng nhập tài khoản` / `Đăng ký thành viên mới`),
 *       biểu mẫu có nhúng `Csrf::field()`, nút ẩn/hiện mật khẩu và thông báo trạng thái rõ ràng.
 */

declare(strict_types=1);

use App\Core\ContactSettings;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

/** @var string $activeTab Tab đang hiển thị ('login' | 'register') do AuthController truyền sang */
/** @var array<int, string> $errors Danh sách lỗi xác thực/kiểm tra biểu mẫu (tiếng Việt) */
/** @var array<string, string> $old Giá trị người dùng đã nhập (không bao gồm mật khẩu) */
$activeTab  = (isset($activeTab) && $activeTab === 'register') ? 'register' : 'login';
$errors     = is_array($errors ?? null) ? $errors : [];
$old        = is_array($old ?? null) ? $old : [];
$isLoginTab = $activeTab === 'login';

$authHotline      = ContactSettings::configuration()['hotline'];
$authHotlineDisp  = $authHotline['display'];
$authHotlineHours = $authHotline['hours'];

// Lấy thông báo một lần (flash) như "Đăng ký thành công" / "Vui lòng đăng nhập" để hiển thị ngay trong khung form
$flashMessages = Flash::pull();
?>

<!-- =======================================================================
     1. BREADCRUMB TRANG ĐĂNG NHẬP & ĐĂNG KÝ
     ======================================================================= -->
<section class="page-banner auth-breadcrumb-banner">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <ol class="breadcrumb-list">
                <li class="breadcrumb-item">
                    <a href="<?= View::e(View::url('/')) ?>">🏠 Trang chủ</a>
                </li>
                <li class="breadcrumb-item">
                    <span aria-current="page">Tài khoản thành viên (Đăng nhập / Đăng ký)</span>
                </li>
            </ol>
        </nav>
    </div>
</section>

<!-- =======================================================================
     2. KHUNG ĐĂNG NHẬP / ĐĂNG KÝ 2 CỘT CHUẨN GOOGLE STITCH
     ======================================================================= -->
<section class="section-block auth-main-section">
    <div class="container">
        <div class="stitch-auth-shell">
            <!-- CỘT TRÁI (5 CỘT): PANEL THƯƠNG HIỆU & ĐẶC QUYỀN THÀNH VIÊN NỀN DEEP NAVY #141C2B -->
            <div class="stitch-auth-aside">
                <div>
                    <div class="auth-aside-badge">
                        <span aria-hidden="true">🛡️</span>
                        <span>HỆ THỐNG TÀI KHOẢN TL GAMING</span>
                    </div>

                    <h1 class="auth-aside-title">
                        Quản Lý Đơn Hàng &amp; Bảo Hành Điện Tử Theo <span class="text-cyan-highlight">Mã Serial</span>
                    </h1>

                    <p class="auth-aside-desc">
                        Đăng nhập vào tài khoản <strong>TL GAMING</strong> để lưu trữ giỏ hàng xuyên suốt, áp dụng mã giảm giá (Voucher) dành riêng cho thành viên và tra cứu hồ sơ kiểm định 18 bước của từng máy tính đã mua.
                    </p>

                    <div class="auth-benefit-list">
                        <div class="auth-benefit-card">
                            <span class="benefit-icon" aria-hidden="true">🔬</span>
                            <div>
                                <h2>Tra cứu biên bản kiểm định 18 bước</h2>
                                <p>Xem lại toàn bộ thông số đo kiểm nhiệt độ, sức khỏe SSD/Pin và phân hạng ngoại hình gắn với số Serial máy của bạn.</p>
                            </div>
                        </div>

                        <div class="auth-benefit-card">
                            <span class="benefit-icon" aria-hidden="true">🎟️</span>
                            <div>
                                <h2>Nhận &amp; Áp dụng Voucher giảm giá</h2>
                                <p>Sử dụng các mã ưu đãi giảm trực tiếp khi mua PC Gaming, Laptop Gaming và nâng cấp linh kiện rời.</p>
                            </div>
                        </div>

                        <div class="auth-benefit-card">
                            <span class="benefit-icon" aria-hidden="true">🚚</span>
                            <div>
                                <h2>Theo dõi đơn hàng &amp; Bảo hành 3–12T</h2>
                                <p>Cập nhật tiến độ đóng gói chống sốc, giao nhận COD / Chuyển khoản và thời hạn bảo hành chính xác.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="auth-aside-footer">
                    <span>📞 Cần hỗ trợ tài khoản hoặc tra cứu bảo hành?</span>
                    <strong>Hotline Kỹ thuật: <?= View::e($authHotlineDisp) ?> (<?= View::e($authHotlineHours) ?>)</strong>
                </div>
            </div>

            <!-- CỘT PHẢI (7 CỘT): BIỂU MẪU ĐĂNG NHẬP & ĐĂNG KÝ NỀN TRẮNG #FFFFFF -->
            <div class="stitch-auth-form-col">
                <!-- Thông báo hệ thống (flash) và lỗi biểu mẫu do server trả về; luôn escape bằng View::e() -->
                <?php foreach ($flashMessages as $flash): ?>
                    <div class="auth-alert auth-alert-<?= View::e($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'info' ? 'info' : 'error')) ?>" role="status">
                        <?= View::e($flash['message']) ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($errors !== []): ?>
                    <div class="auth-alert auth-alert-error" role="alert">
                        <strong>Vui lòng kiểm tra lại:</strong>
                        <ul>
                            <?php foreach ($errors as $errorMsg): ?>
                                <li><?= View::e((string) $errorMsg) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Thanh chuyển đổi 2 Tab: Đăng nhập / Đăng ký -->
                <div class="stitch-auth-tabs" id="authTabList" role="tablist" aria-label="Chuyển đổi Đăng nhập và Đăng ký">
                    <button type="button"
                            class="auth-tab-btn<?= $isLoginTab ? ' is-active' : '' ?>"
                            id="auth-tab-btn-login"
                            role="tab"
                            aria-selected="<?= $isLoginTab ? 'true' : 'false' ?>"
                            aria-controls="auth-panel-login"
                            data-auth-tab="login">
                        🔑 Đăng nhập tài khoản
                    </button>
                    <button type="button"
                            class="auth-tab-btn<?= $isLoginTab ? '' : ' is-active' ?>"
                            id="auth-tab-btn-register"
                            role="tab"
                            aria-selected="<?= $isLoginTab ? 'false' : 'true' ?>"
                            aria-controls="auth-panel-register"
                            data-auth-tab="register">
                        ✨ Đăng ký thành viên mới
                    </button>
                </div>

                <!-- TAB PANEL 1: ĐĂNG NHẬP TÀI KHOẢN -->
                <div class="stitch-auth-panel<?= $isLoginTab ? ' is-active' : '' ?>"
                     id="auth-panel-login"
                     role="tabpanel"
                     aria-labelledby="auth-tab-btn-login"
                     <?= $isLoginTab ? '' : 'hidden' ?>>
                    <div class="auth-panel-header">
                        <h2 class="auth-form-heading">Chào mừng quay trở lại TL Gaming</h2>
                        <p class="auth-form-sub">Vui lòng nhập địa chỉ Email và mật khẩu đã đăng ký của bạn.</p>
                    </div>

                    <form action="<?= View::e(View::url('/dang-nhap')) ?>" method="POST" class="stitch-auth-form" id="stitchLoginForm">
                        <?= Csrf::field() ?>

                        <div class="form-group">
                            <label for="loginEmail" class="form-label">Địa chỉ Email đăng nhập <span class="text-rose">*</span></label>
                            <div class="stitch-input-icon-wrap">
                                <span class="input-prefix-icon" aria-hidden="true">✉️</span>
                                <input type="email"
                                       id="loginEmail"
                                       name="email"
                                       class="form-control stitch-icon-input"
                                       placeholder="VD: khachhang@tlgaming.vn"
                                       value="<?= View::e($old['login_email'] ?? '') ?>"
                                       maxlength="191"
                                       required
                                       autocomplete="email">
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="label-row-split">
                                <label for="loginPassword" class="form-label">Mật khẩu đăng nhập <span class="text-rose">*</span></label>
                                <a href="#authFormNotice" class="forgot-pass-link" id="forgotPassTrigger">Quên mật khẩu?</a>
                            </div>
                            <div class="stitch-input-icon-wrap">
                                <span class="input-prefix-icon" aria-hidden="true">🔒</span>
                                <input type="password"
                                       id="loginPassword"
                                       name="password"
                                       class="form-control stitch-icon-input"
                                       placeholder="Nhập mật khẩu của bạn..."
                                       required
                                       autocomplete="current-password">
                                <button type="button"
                                        class="toggle-password-btn"
                                        data-target-input="loginPassword"
                                        aria-label="Hiện hoặc ẩn mật khẩu">
                                    👁️
                                </button>
                            </div>
                        </div>

                        <div class="auth-remember-row">
                            <label class="cart-checkbox-label">
                                <input type="checkbox" name="remember" value="1" checked>
                                <span>Duy trì trạng thái đăng nhập trên thiết bị này</span>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block btn-lg auth-submit-btn">
                            ĐĂNG NHẬP HỆ THỐNG &rarr;
                        </button>
                    </form>

                    <div class="auth-switch-prompt">
                        <span>Bạn chưa có tài khoản tại TL Gaming?</span>
                        <button type="button" class="auth-link-btn" data-switch-auth="register">
                            Đăng ký tài khoản mới ngay &rarr;
                        </button>
                    </div>
                </div>

                <!-- TAB PANEL 2: ĐĂNG KÝ THÀNH VIÊN MỚI -->
                <div class="stitch-auth-panel<?= $isLoginTab ? '' : ' is-active' ?>"
                     id="auth-panel-register"
                     role="tabpanel"
                     aria-labelledby="auth-tab-btn-register"
                     <?= $isLoginTab ? 'hidden' : '' ?>>
                    <div class="auth-panel-header">
                        <h2 class="auth-form-heading">Tạo tài khoản khách hàng mới</h2>
                        <p class="auth-form-sub">Điền thông tin cá nhân để nhận phiếu bảo hành điện tử và theo dõi đơn hàng dễ dàng.</p>
                    </div>

                    <!-- Biểu mẫu đăng ký gửi POST /dang-ky; tài khoản mới luôn có vai trò khách hàng (do server quyết định) -->
                    <form action="<?= View::e(View::url('/dang-ky')) ?>" method="POST" class="stitch-auth-form" id="stitchRegisterForm">
                        <?= Csrf::field() ?>

                        <div class="auth-grid-2">
                            <div class="form-group">
                                <label for="regFullName" class="form-label">Họ và tên khách hàng <span class="text-rose">*</span></label>
                                <input type="text"
                                       id="regFullName"
                                       name="full_name"
                                       class="form-control"
                                       placeholder="VD: Nguyễn Văn An"
                                       value="<?= View::e($old['reg_full_name'] ?? '') ?>"
                                       maxlength="100"
                                       required
                                       autocomplete="name">
                            </div>

                            <div class="form-group">
                                <label for="regPhone" class="form-label">Số điện thoại liên hệ <span class="text-rose">*</span></label>
                                <input type="tel"
                                       id="regPhone"
                                       name="phone"
                                       class="form-control"
                                       placeholder="VD: 0912 345 678"
                                       value="<?= View::e($old['reg_phone'] ?? '') ?>"
                                       maxlength="20"
                                       required
                                       autocomplete="tel">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="regEmail" class="form-label">Địa chỉ Email nhận hóa đơn &amp; bảo hành <span class="text-rose">*</span></label>
                            <input type="email"
                                   id="regEmail"
                                   name="email"
                                   class="form-control"
                                   placeholder="VD: nguyenvanan@email.com"
                                   value="<?= View::e($old['reg_email'] ?? '') ?>"
                                   maxlength="191"
                                   required
                                   autocomplete="email">
                        </div>

                        <div class="auth-grid-2">
                            <div class="form-group">
                                <label for="regPassword" class="form-label">Mật khẩu (Tối thiểu 8 ký tự) <span class="text-rose">*</span></label>
                                <input type="password"
                                       id="regPassword"
                                       name="password"
                                       class="form-control"
                                       placeholder="••••••••"
                                       minlength="8"
                                       required
                                       autocomplete="new-password">
                            </div>

                            <div class="form-group">
                                <label for="regPasswordConfirm" class="form-label">Nhập lại mật khẩu <span class="text-rose">*</span></label>
                                <input type="password"
                                       id="regPasswordConfirm"
                                       name="password_confirmation"
                                       class="form-control"
                                       placeholder="••••••••"
                                       minlength="8"
                                       required
                                       autocomplete="new-password">
                            </div>
                        </div>

                        <div class="auth-remember-row">
                            <label class="cart-checkbox-label">
                                <input type="checkbox" name="agree_terms" value="1" checked required>
                                <span>Tôi đồng ý với <a href="<?= View::e(View::url('/#cam-ket-cua-hang')) ?>">Chính sách bảo hành &amp; Quy định kiểm định</a> của TL Gaming</span>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block btn-lg auth-submit-btn">
                            ĐĂNG KÝ TÀI KHOẢN MỚI &rarr;
                        </button>
                    </form>

                    <div class="auth-switch-prompt">
                        <span>Bạn đã có tài khoản thành viên?</span>
                        <button type="button" class="auth-link-btn" data-switch-auth="login">
                            &larr; Quay lại Đăng nhập
                        </button>
                    </div>
                </div>

                <!-- Hộp thông báo trạng thái hỗ trợ tài khoản -->
                <div class="cart-dev-notice" id="authFormNotice" role="status" aria-live="polite" hidden style="margin-top: 16px;">
                    <strong>Thông báo hệ thống:</strong>
                    <span id="authFormNoticeText">
                        Để đặt lại mật khẩu, vui lòng liên hệ Hotline <?= View::e($authHotlineDisp) ?> hoặc mang CMND/CCCD kèm số điện thoại mua hàng đến Showroom TL GAMING.
                    </span>
                </div>
            </div>
        </div>
    </div>
</section>
