<?php
/**
 * Lớp Csrf phụ trách sinh và xác minh mã thông báo chống giả mạo yêu cầu liên trang (CSRF Token).
 *
 * Vai trò bảo mật:
 * - Sinh mã ngẫu nhiên mật mã học 64 ký tự hex (`random_bytes(32)`) và lưu vào `$_SESSION['_csrf_token']`.
 * - Cung cấp phương thức `Csrf::field()` để nhúng trường `<input type="hidden" name="_csrf_token" ...>` vào các biểu mẫu POST.
 * - Cung cấp phương thức `Csrf::verify()` sử dụng hàm so sánh thời gian hằng số `hash_equals()` để chống tấn công Timing Attack
 *   trên mọi yêu cầu làm thay đổi dữ liệu (POST).
 */

declare(strict_types=1);

namespace App\Core;

class Csrf
{
    /**
     * Tên khóa lưu trữ CSRF token trong Session và trong biểu mẫu POST.
     */
    public const TOKEN_KEY = '_csrf_token';

    /**
     * Lấy CSRF token hiện tại trong Session (hoặc tạo mới nếu chưa có).
     *
     * @return string Chuỗi token 64 ký tự hex.
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::TOKEN_KEY]) || !is_string($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Sinh thẻ HTML `<input type="hidden">` chứa CSRF token để đặt trong biểu mẫu `<form method="POST">`.
     *
     * @return string Chuỗi thẻ input ẩn đã được escape an toàn.
     */
    public static function field(): string
    {
        $token = View::e(self::token());
        return '<input type="hidden" name="' . self::TOKEN_KEY . '" value="' . $token . '">';
    }

    /**
     * Xác minh CSRF token gửi lên từ biểu mẫu POST hoặc HTTP Header `X-CSRF-Token`.
     *
     * @param string|null $submittedToken Token nhận được từ request (nếu null sẽ tự đọc từ `$_POST` hoặc Header).
     * @return bool                       True nếu token hợp lệ, False nếu thiếu hoặc không khớp.
     */
    public static function verify(?string $submittedToken = null): bool
    {
        $sessionToken = $_SESSION[self::TOKEN_KEY] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        if ($submittedToken === null) {
            $submittedToken = $_POST[self::TOKEN_KEY]
                ?? $_SERVER['HTTP_X_CSRF_TOKEN']
                ?? '';
        }

        if (!is_string($submittedToken) || $submittedToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }
}
