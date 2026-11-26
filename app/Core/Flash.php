<?php
/**
 * Lớp Flash — thông báo một lần (flash message) lưu trong Session.
 *
 * Vai trò trong kiến trúc MVC:
 * - Controller gọi `Flash::add()` trước khi chuyển hướng (mô hình Post/Redirect/Get).
 * - View gọi `Flash::pull()` để lấy và XÓA thông báo ngay sau khi hiển thị, nên thông báo chỉ xuất hiện đúng một lần.
 * - Nội dung thông báo do hệ thống tự viết; View vẫn phải escape bằng `View::e()` khi in ra.
 */

declare(strict_types=1);

namespace App\Core;

final class Flash
{
    /**
     * Khóa lưu danh sách thông báo trong `$_SESSION`.
     */
    private const KEY = '_flash_messages';

    /**
     * Thêm một thông báo vào hàng đợi của phiên hiện tại.
     *
     * @param string $type    Loại thông báo: 'success', 'error' hoặc 'info'.
     * @param string $message Nội dung tiếng Việt hiển thị cho người dùng.
     * @return void
     */
    public static function add(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Lấy toàn bộ thông báo đang chờ và xóa chúng khỏi Session.
     *
     * @return array<int, array{type: string, message: string}> Danh sách thông báo (rỗng nếu không có).
     */
    public static function pull(): array
    {
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);
        return is_array($messages) ? $messages : [];
    }
}
