<?php
/**
 * Thành phần liên hệ nổi dùng chung cho Storefront (`app/Views/partials/floating_contact_widget.php`) — TL Gaming.
 *
 * Vai trò:
 * - Hiển thị đầy đủ 5 nút SVG đồng nhất: Zalo (#0068FF), Facebook (#1877F2), Messenger, Hotline và Trở về đầu trang.
 * - Đọc toàn bộ thông tin liên hệ tập trung từ `config/contact.php`.
 * - Khi một kênh đã cấu hình URL/SĐT hợp lệ: xuất thẻ `<a>` hoạt động trực tiếp (kèm `rel="noopener noreferrer"`).
 * - Khi một kênh chưa có thông tin thật và `show_unconfigured_channels = true`: vẫn hiển thị icon bằng `<button type="button">`
 *   (không dùng `href="#"`), bấm vào hiện thông báo nhẹ: "Kênh liên hệ đang được cập nhật.".
 * - Desktop (> 768px): cột icon tròn 50px bên phải kèm tooltip.
 * - Mobile (<= 768px): thu gọn thành 1 nút chính phía trên Bottom Nav / Sticky Buy Bar, bấm bung đủ 4 kênh liên hệ.
 * - Ẩn hoàn toàn trên trang Checkout (`/thanh-toan`) và không bao gồm trong Admin.
 */

declare(strict_types=1);

use App\Core\View;

/** @var array<string, mixed> $contactConfig */
$showUnconfigured = !empty($contactConfig['show_unconfigured_channels']);
$hotlineCfg       = is_array($contactConfig['hotline'] ?? null) ? $contactConfig['hotline'] : [];
$zaloCfg          = is_array($contactConfig['zalo'] ?? null) ? $contactConfig['zalo'] : [];
$facebookCfg      = is_array($contactConfig['facebook'] ?? null) ? $contactConfig['facebook'] : [];
$messengerCfg     = is_array($contactConfig['messenger'] ?? null) ? $contactConfig['messenger'] : [];

$zaloEnabled      = !empty($zaloCfg['enabled']) && !empty($zaloCfg['href']);
$facebookEnabled  = !empty($facebookCfg['enabled']) && !empty($facebookCfg['href']);
$messengerEnabled = !empty($messengerCfg['enabled']) && !empty($messengerCfg['href']);
$hotlineEnabled   = !empty($hotlineCfg['enabled']) && !empty($hotlineCfg['href']);

$renderZalo      = $zaloEnabled || $showUnconfigured;
$renderFacebook  = $facebookEnabled || $showUnconfigured;
$renderMessenger = $messengerEnabled || $showUnconfigured;
$renderHotline   = $hotlineEnabled || $showUnconfigured;
?>
<div class="fcw-root" id="floatingContactWidget" data-expanded="false" aria-label="Kênh liên hệ nhanh TL Gaming">
    <!-- Thông báo nhẹ khi người dùng bấm vào kênh chưa cập nhật thông tin -->
    <div class="fcw-toast" id="fcwStatusToast" role="status" aria-live="polite" hidden></div>

    <!-- 1. Nút Trở về đầu trang (chỉ hiện khi cuộn xuống >= 320px) -->
    <button type="button"
            class="fcw-btn fcw-btn-top"
            id="backToTopBtn"
            aria-label="Trở về đầu trang"
            title="Trở về đầu trang"
            hidden>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="18 15 12 9 6 15"></polyline>
        </svg>
        <span class="fcw-tooltip" role="tooltip">Lên đầu trang</span>
    </button>

    <!-- 2. Cụm 4 kênh liên hệ: Zalo, Facebook, Messenger, Hotline -->
    <div class="fcw-channels" id="fcwChannelsPanel" role="group" aria-label="Các kênh hỗ trợ trực tuyến">
        <?php if ($renderZalo): ?>
            <?php if ($zaloEnabled): ?>
                <a href="<?= View::e((string) $zaloCfg['href']) ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="fcw-btn fcw-btn-zalo"
                   aria-label="Chat Zalo TL Gaming">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 2.5C6.75 2.5 2.5 6.38 2.5 11.17c0 2.74 1.39 5.18 3.56 6.77-.16 1.32-.66 2.53-1.22 3.38-.15.23.04.52.31.48 1.54-.23 3.2-.85 4.24-1.56.83.23 1.71.36 2.61.36 5.25 0 9.5-3.88 9.5-8.67S17.25 2.5 12 2.5z" fill="currentColor" opacity="0.2"/>
                        <text x="12" y="14.7" text-anchor="middle" fill="currentColor" font-size="7.6" font-weight="800" font-family="system-ui, Arial, sans-serif">Zalo</text>
                    </svg>
                    <span class="fcw-tooltip" role="tooltip">Chat Zalo</span>
                </a>
            <?php else: ?>
                <button type="button"
                        class="fcw-btn fcw-btn-zalo is-unconfigured"
                        data-unconfigured-channel="Zalo"
                        aria-label="Chat Zalo (đang cập nhật)">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 2.5C6.75 2.5 2.5 6.38 2.5 11.17c0 2.74 1.39 5.18 3.56 6.77-.16 1.32-.66 2.53-1.22 3.38-.15.23.04.52.31.48 1.54-.23 3.2-.85 4.24-1.56.83.23 1.71.36 2.61.36 5.25 0 9.5-3.88 9.5-8.67S17.25 2.5 12 2.5z" fill="currentColor" opacity="0.2"/>
                        <text x="12" y="14.7" text-anchor="middle" fill="currentColor" font-size="7.6" font-weight="800" font-family="system-ui, Arial, sans-serif">Zalo</text>
                    </svg>
                    <span class="fcw-tooltip" role="tooltip">Chat Zalo</span>
                </button>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($renderFacebook): ?>
            <?php if ($facebookEnabled): ?>
                <a href="<?= View::e((string) $facebookCfg['href']) ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="fcw-btn fcw-btn-facebook"
                   aria-label="Fanpage Facebook TL Gaming">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M14 13.5h2.5l1-4H14v-2c0-1.03 0-2 2-2h1.5V2.14c-.326-.043-1.557-.14-2.857-.14C11.928 2 10 3.657 10 6.7v2.8H7v4h3V22h4v-8.5z"/>
                    </svg>
                    <span class="fcw-tooltip" role="tooltip">Facebook</span>
                </a>
            <?php else: ?>
                <button type="button"
                        class="fcw-btn fcw-btn-facebook is-unconfigured"
                        data-unconfigured-channel="Facebook"
                        aria-label="Fanpage Facebook (đang cập nhật)">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M14 13.5h2.5l1-4H14v-2c0-1.03 0-2 2-2h1.5V2.14c-.326-.043-1.557-.14-2.857-.14C11.928 2 10 3.657 10 6.7v2.8H7v4h3V22h4v-8.5z"/>
                    </svg>
                    <span class="fcw-tooltip" role="tooltip">Facebook</span>
                </button>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($renderMessenger): ?>
            <?php if ($messengerEnabled): ?>
                <a href="<?= View::e((string) $messengerCfg['href']) ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="fcw-btn fcw-btn-messenger"
                   aria-label="Nhắn tin Messenger TL Gaming">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2C6.48 2 2 6.14 2 11.25c0 2.9 1.45 5.49 3.72 7.18V22l3.4-1.87c.9.25 1.87.38 2.88.38 5.52 0 10-4.14 10-9.26S17.52 2 12 2zm1.07 12.46l-2.55-2.72-4.97 2.72 5.47-5.8 2.61 2.72 4.91-2.72-5.47 5.8z"/>
                    </svg>
                    <span class="fcw-tooltip" role="tooltip">Messenger</span>
                </a>
            <?php else: ?>
                <button type="button"
                        class="fcw-btn fcw-btn-messenger is-unconfigured"
                        data-unconfigured-channel="Messenger"
                        aria-label="Nhắn tin Messenger (đang cập nhật)">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2C6.48 2 2 6.14 2 11.25c0 2.9 1.45 5.49 3.72 7.18V22l3.4-1.87c.9.25 1.87.38 2.88.38 5.52 0 10-4.14 10-9.26S17.52 2 12 2zm1.07 12.46l-2.55-2.72-4.97 2.72 5.47-5.8 2.61 2.72 4.91-2.72-5.47 5.8z"/>
                    </svg>
                    <span class="fcw-tooltip" role="tooltip">Messenger</span>
                </button>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($renderHotline): ?>
            <?php if ($hotlineEnabled): ?>
                <a href="<?= View::e((string) $hotlineCfg['href']) ?>"
                   class="fcw-btn fcw-btn-hotline fcw-pulse-primary"
                   aria-label="Gọi Hotline <?= View::e((string) ($hotlineCfg['display'] ?? '')) ?>">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                    <span class="fcw-tooltip" role="tooltip">Hotline <?= View::e((string) ($hotlineCfg['display'] ?? '')) ?></span>
                </a>
            <?php else: ?>
                <button type="button"
                        class="fcw-btn fcw-btn-hotline fcw-pulse-primary is-unconfigured"
                        data-unconfigured-channel="Hotline"
                        aria-label="Hotline (đang cập nhật)">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                    <span class="fcw-tooltip" role="tooltip">Hotline</span>
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- 3. Nút mở/đóng cụm liên hệ thu gọn trên Mobile (chỉ hiển thị <= 768px) -->
    <button type="button"
            class="fcw-btn fcw-mobile-toggle fcw-pulse-primary"
            id="fcwMobileToggleBtn"
            aria-label="Mở kênh liên hệ hỗ trợ"
            aria-expanded="false"
            aria-controls="fcwChannelsPanel">
        <span class="fcw-toggle-icon-open" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
            </svg>
        </span>
        <span class="fcw-toggle-icon-close" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </span>
    </button>
</div>
