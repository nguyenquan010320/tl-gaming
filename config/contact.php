<?php
/**
 * Cấu hình thông tin liên hệ tập trung (`config/contact.php`) — TL Gaming.
 *
 * Vai trò:
 * - Tập trung duy nhất thông tin Hotline, Email hỗ trợ, Zalo, Facebook và Messenger cho toàn bộ Storefront
 *   (Header, Footer, Mobile Drawer, Bottom Navigation và Floating Contact Widget).
 * - Tuyệt đối không tự tạo số điện thoại hay tài khoản mạng xã hội giả.
 * - Khi `show_unconfigured_channels = true`, các icon kênh chưa cấu hình vẫn hiển thị đẹp trên Floating Contact Widget
 *   dưới dạng nút an toàn (`<button type="button">`) và hiện thông báo nhẹ: "Kênh liên hệ đang được cập nhật."
 * - Thông tin đã lưu trong quản trị được ưu tiên. Các kênh còn lại dùng cấu hình `.env`.
 */

declare(strict_types=1);

use App\Core\ContactSettings;

require_once __DIR__ . '/env.php';
require_once dirname(__DIR__) . '/app/Core/JsonSettingsFile.php';
require_once dirname(__DIR__) . '/app/Core/ContactSettings.php';
loadEnvFile(dirname(__DIR__) . '/.env');
$saved = ContactSettings::read();

$hotlineDisplay = trim((string) env('TL_HOTLINE_DISPLAY', '1900 8868'));
$hotlineRaw     = trim((string) env('TL_HOTLINE_TEL', $hotlineDisplay));
$hotlineTel     = preg_replace('/[^0-9+]/', '', $hotlineRaw) ?? '';
$hotlineHours   = trim((string) env('TL_HOTLINE_HOURS', '08:30 – 21:00'));
$supportEmail   = $saved['support_email'] ?? trim((string) env('TL_SUPPORT_EMAIL', ''));
if (filter_var($supportEmail, FILTER_VALIDATE_EMAIL) === false) {
    $supportEmail = 'hotro@tlgaming.vn';
}

$hotlineDisplay = $hotlineDisplay !== '' ? $hotlineDisplay : '1900 8868';
$savedHotline = $saved['hotline'] ?? '';
$savedDialNumber = ContactSettings::dialNumber($savedHotline);
if ($savedDialNumber !== null) {
    if ($savedHotline !== $hotlineDisplay || $hotlineTel === '') {
        $hotlineTel = $savedDialNumber;
    }
    $hotlineDisplay = $savedHotline;
}

$zaloUrlInput   = $saved['zalo_url'] ?? trim((string) env('TL_ZALO_URL', ''));
$zaloPhoneInput = preg_replace('/[^0-9]/', '', trim((string) env('TL_ZALO_PHONE', ''))) ?? '';
$zaloHref       = '';
if (ContactSettings::isSocialUrl('zalo', $zaloUrlInput)) {
    $zaloHref = $zaloUrlInput;
} elseif (!isset($saved['zalo_url']) && strlen($zaloPhoneInput) >= 9 && strlen($zaloPhoneInput) <= 15) {
    $zaloHref = 'https://zalo.me/' . $zaloPhoneInput;
}

$facebookInput = $saved['facebook_url'] ?? trim((string) env('TL_FACEBOOK_URL', ''));
$facebookHref  = ContactSettings::isSocialUrl('facebook', $facebookInput) ? $facebookInput : '';

$messengerInput = $saved['messenger_url'] ?? trim((string) env('TL_MESSENGER_URL', ''));
$messengerHref  = ContactSettings::isSocialUrl('messenger', $messengerInput) ? $messengerInput : '';

// Hiển thị icon ngay cả khi chưa cấu hình thông tin thật (an toàn, không link giả)
$showUnconfigured = filter_var(env('TL_SHOW_UNCONFIGURED_CHANNELS', 'true'), FILTER_VALIDATE_BOOLEAN);

return [
    'show_unconfigured_channels' => $showUnconfigured,

    'showroom' => [
        'address' => $saved['showroom_address'] ?? 'Số 6 Ngõ 126 Khuất Duy Tiến, Thanh Xuân, Hà Nội',
        'hours'   => $saved['opening_hours'] ?? '08:30 - 21:30 (Cả CN & ngày lễ)',
    ],
    'hotline' => [
        'enabled' => ($hotlineTel !== ''),
        'display' => $hotlineDisplay,
        'tel'     => $hotlineTel !== '' ? $hotlineTel : '19008868',
        'href'    => 'tel:' . ($hotlineTel !== '' ? $hotlineTel : '19008868'),
        'hours'   => $hotlineHours !== '' ? $hotlineHours : '08:30 – 21:00',
        'label'   => 'Hotline ' . $hotlineDisplay,
    ],
    'email' => [
        'address' => $supportEmail,
        'href'    => 'mailto:' . str_replace('%40', '@', rawurlencode($supportEmail)),
    ],
    'zalo' => [
        'enabled' => ($zaloHref !== ''),
        'href'    => $zaloHref,
        'label'   => 'Chat Zalo',
    ],
    'facebook' => [
        'enabled' => ($facebookHref !== ''),
        'href'    => $facebookHref,
        'label'   => 'Fanpage Facebook',
    ],
    'messenger' => [
        'enabled' => ($messengerHref !== ''),
        'href'    => $messengerHref,
        'label'   => 'Nhắn tin Messenger',
    ],
];
