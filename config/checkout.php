<?php
/**
 * Cấu hình nghiệp vụ Thanh toán (Checkout) tập trung cho TL Gaming.
 *
 * Lưu ý minh bạch:
 * - Bảng cấu hình phí vận chuyển chưa có trong schema CSDL hiện tại, do đó các mức phí
 *   giao hàng dưới đây là CẤU HÌNH DEMO tập trung tại Server (tuyệt đối không lấy phí từ Client).
 * - Địa chỉ Showroom và thông tin tài khoản ngân hàng chỉ được kích hoạt khi cấu hình
 *   tường minh qua biến môi trường `.env`; tuyệt đối không tự bịa số tài khoản hay địa chỉ giả.
 */

declare(strict_types=1);

$showrooms = [];

$hnAddress = trim((string) env('TL_SHOWROOM_HN_ADDRESS', ''));
if ($hnAddress !== '') {
    $showrooms['hn'] = [
        'id'       => 'hn',
        'name'     => trim((string) env('TL_SHOWROOM_HN_NAME', 'Showroom Hà Nội')),
        'address'  => $hnAddress,
        'city'     => trim((string) env('TL_SHOWROOM_HN_CITY', 'Hà Nội')),
        'district' => trim((string) env('TL_SHOWROOM_HN_DISTRICT', '')),
    ];
}

$hcmAddress = trim((string) env('TL_SHOWROOM_HCM_ADDRESS', ''));
if ($hcmAddress !== '') {
    $showrooms['hcm'] = [
        'id'       => 'hcm',
        'name'     => trim((string) env('TL_SHOWROOM_HCM_NAME', 'Showroom TP.HCM')),
        'address'  => $hcmAddress,
        'city'     => trim((string) env('TL_SHOWROOM_HCM_CITY', 'TP. Hồ Chí Minh')),
        'district' => trim((string) env('TL_SHOWROOM_HCM_DISTRICT', '')),
    ];
}

$bankName      = trim((string) env('TL_BANK_NAME', ''));
$accountNumber = trim((string) env('TL_BANK_ACCOUNT_NUMBER', ''));
$accountHolder = trim((string) env('TL_BANK_ACCOUNT_HOLDER', ''));

return [
    // Chính sách phí vận chuyển DEMO (đơn vị: VND nguyên)
    'shipping' => [
        'is_demo_policy' => true,
        'free_threshold' => 10000000, // Miễn phí giao hàng cho đơn từ 10.000.000₫ (demo)
        'inner_city_fee' => 30000,    // Phí giao nội thành Hà Nội / TP.HCM (demo)
        'province_fee'   => 50000,    // Phí giao các tỉnh/thành khác (demo)
        'inner_cities'   => ['Hà Nội', 'TP. Hồ Chí Minh', 'Hồ Chí Minh'],
        'pickup_fee'     => 0,        // Nhận tại showroom miễn phí vận chuyển
    ],

    // Danh sách Showroom thực tế (chỉ hiển thị hình thức nhận tại showroom khi có cấu hình .env)
    'showrooms' => $showrooms,

    // Thông tin chuyển khoản ngân hàng cấu hình từ Server (đọc từ .env; tắt bank_transfer nếu thiếu)
    'bank_transfer' => [
        'enabled'        => ($bankName !== '' && $accountNumber !== '' && $accountHolder !== ''),
        'bank_name'      => $bankName,
        'account_number' => $accountNumber,
        'account_holder' => $accountHolder,
    ],
];
