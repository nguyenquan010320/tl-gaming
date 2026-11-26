<?php
/**
 * Middleware yêu cầu ĐÃ ĐĂNG NHẬP (khách hàng hoặc quản trị viên) — dùng cho hồ sơ cá nhân, đổi mật khẩu.
 *
 * Vai trò bảo mật:
 * - Danh tính chỉ lấy từ Session phía máy chủ, sau đó đối chiếu lại với bảng `users` (`Auth::revalidate()`)
 *   để thu hồi quyền ngay khi tài khoản bị khóa.
 * - Khách vãng lai bị chuyển hướng tới `/dang-nhap` kèm thông báo; không hiển thị bất kỳ dữ liệu cá nhân nào.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\View;

class AuthMiddleware
{
    /**
     * Chặn truy cập nếu chưa có phiên đăng nhập hợp lệ.
     *
     * @return void
     */
    public function handle(): void
    {
        if (Auth::check() && Auth::revalidateForRequest()) {
            return;
        }

        Flash::add('error', 'Vui lòng đăng nhập để truy cập chức năng này.');
        header('Location: ' . View::url('/dang-nhap'), true, 302);
        exit;
    }
}
