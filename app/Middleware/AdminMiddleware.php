<?php
/**
 * Middleware bảo vệ các tuyến đường Quản trị viên (`/admin`).
 *
 * Vai trò bảo mật:
 * - Chặn mọi truy cập trái phép vào khu vực quản trị trước khi Controller được khởi tạo.
 * - Chỉ tin cậy danh tính và vai trò (`role === 'admin'`) lưu trong `$_SESSION['auth_user']` trên máy chủ;
 *   tuyệt đối phớt lờ mọi tham số `user_id`, `role`, `is_admin` truyền từ URL (`$_GET`) hoặc biểu mẫu (`$_POST`).
 * - Khi khách vãng lai (chưa đăng nhập) hoặc tài khoản khách hàng (`role === 'customer'`) truy cập `/admin`,
 *   Middleware trả về mã trạng thái HTTP 403 Forbidden kèm giao diện cảnh báo và liên kết tới `/dang-nhap`.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\View;

class AdminMiddleware
{
    /**
     * Kiểm tra quyền truy cập Admin trước khi cho phép đi tiếp vào Controller.
     *
     * @return void
     */
    public function handle(): void
    {
        // Kiểm tra vai trò trong Session, sau đó đối chiếu lại CSDL để phát hiện tài khoản bị khóa / hạ quyền
        if (Auth::revalidateForRequest() && Auth::isAdmin()) {
            return;
        }

        http_response_code(403);
        View::render('errors/403', [
            'pageTitle'       => '403 - Từ chối truy cập khu vực Quản trị | TL Gaming',
            'isAuthenticated' => Auth::check(),
        ]);
        exit;
    }
}
