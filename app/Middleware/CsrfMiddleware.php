<?php
/**
 * Middleware kiểm tra mã bảo mật CSRF trên các yêu cầu thay đổi dữ liệu (POST).
 *
 * Vai trò bảo mật:
 * - Xác minh trường `_csrf_token` gửi lên từ biểu mẫu khớp với token lưu trong Session của người dùng.
 * - Ngăn chặn tấn công Cross-Site Request Forgery (CSRF) khi thực hiện đăng nhập, thêm giỏ hàng, đặt đơn hoặc cập nhật quản trị.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\View;

class CsrfMiddleware
{
    /**
     * Thực hiện kiểm tra CSRF token đối với phương thức POST.
     *
     * @return void
     */
    public function handle(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'POST') {
            return;
        }

        if (Csrf::verify()) {
            return;
        }

        http_response_code(403);
        View::render('errors/403', [
            'pageTitle'       => '403 - Phiên biểu mẫu không hợp lệ (CSRF) | TL Gaming',
            'isAuthenticated' => false,
            'customMessage'   => 'Yêu cầu gửi biểu mẫu bị từ chối do thiếu hoặc sai mã bảo mật CSRF. Vui lòng tải lại trang và thử lại.',
        ]);
        exit;
    }
}
