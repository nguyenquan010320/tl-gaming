<?php
/**
 * Lớp Auth phụ trách quản lý Phiên đăng nhập (Session) và kiểm tra Phân quyền (Role-Based Access Control).
 *
 * Vai trò bảo mật cốt lõi:
 * - Khởi tạo PHP Session với các cờ bảo mật Cookie nghiêm ngặt (`HttpOnly`, `SameSite=Lax`, `use_strict_mode=1`).
 * - Mọi thông tin định danh người dùng (`id`, `role`, `email`, `full_name`) BẮT BUỘC chỉ được đọc từ
 *   `$_SESSION['auth_user']` trên máy chủ sau khi đã xác thực.
 * - Tuyệt đối KHÔNG BAO GIỜ đọc `user_id`, `role` hay cờ `is_admin` từ `$_GET`, `$_POST`, `$_REQUEST` hoặc `$_COOKIE`
 *   để tránh lỗ hổng leo thang đặc quyền (Privilege Escalation).
 * - Ở bước khung MVC hiện tại, lớp cung cấp đầy đủ nền tảng kiểm tra phiên và vai trò (`customer` / `admin`).
 */

declare(strict_types=1);

namespace App\Core;

class Auth
{
    /**
     * Khóa lưu thông tin người dùng đã xác thực trong mảng `$_SESSION`.
     */
    private const SESSION_KEY = 'auth_user';
    private static ?bool $requestSessionValid = null;

    /**
     * Khởi động Session an toàn nếu chưa được mở.
     * Cấu hình Cookie phù hợp cho cả môi trường Localhost (HTTP) và Production (HTTPS).
     *
     * @param array<string, mixed> $sessionConfig Mảng cấu hình session từ `config/app.php`.
     * @return void
     */
    public static function startSession(array $sessionConfig = []): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Không khởi tạo session cookie nếu đang chạy từ CLI
        if (PHP_SAPI === 'cli') {
            return;
        }

        $sessionName = (string) ($sessionConfig['name'] ?? 'tlgaming_session');
        $lifetime    = (int) ($sessionConfig['lifetime'] ?? 7200);
        $isHttps     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
        $secure      = (bool) ($sessionConfig['secure'] ?? false) || $isHttps;
        $sameSite    = (string) ($sessionConfig['samesite'] ?? 'Lax');

        // Bật chế độ Strict Mode để từ chối Session ID chưa được khởi tạo bởi Server (chống Session Fixation)
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_name($sessionName);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax',
        ]);

        session_start();
    }

    /**
     * Kiểm tra xem người dùng hiện tại đã đăng nhập hợp lệ trong Session hay chưa.
     *
     * @return bool True nếu đã đăng nhập, False nếu là khách vãng lai (Guest).
     */
    public static function check(): bool
    {
        $user = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($user)
            && isset($user['id'], $user['role'], $user['status'])
            && (int) $user['id'] > 0
            && $user['status'] === 'active';
    }

    /**
     * Lấy thông tin người dùng đang đăng nhập từ Session đã xác thực.
     *
     * @return array<string, mixed>|null Mảng thông tin người dùng hoặc null nếu chưa đăng nhập.
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        /** @var array<string, mixed> $user */
        $user = $_SESSION[self::SESSION_KEY];
        return $user;
    }

    /**
     * Lấy khóa chính `id` của người dùng đang đăng nhập từ Session.
     *
     * @return int|null ID người dùng hoặc null nếu là khách vãng lai.
     */
    public static function id(): ?int
    {
        $user = self::user();
        return $user !== null ? (int) $user['id'] : null;
    }

    /**
     * Lấy vai trò (`customer` hoặc `admin`) của người dùng hiện tại từ Session.
     *
     * @return string|null Vai trò người dùng hoặc null nếu chưa đăng nhập.
     */
    public static function role(): ?string
    {
        $user = self::user();
        return $user !== null ? (string) $user['role'] : null;
    }

    /**
     * Kiểm tra người dùng hiện tại có phải là Quản trị viên (`role === 'admin'`) hợp lệ hay không.
     *
     * @return bool True nếu người dùng đã đăng nhập và có vai trò 'admin'.
     */
    public static function isAdmin(): bool
    {
        return self::check() && self::role() === 'admin';
    }

    /**
     * Thiết lập phiên đăng nhập sau khi mật khẩu đã được xác thực thành công.
     *
     * Bảo mật & Xử lý Giỏ hàng:
     * - Lưu giữ an toàn giỏ hàng của khách vãng lai (nếu có) trước khi tạo phiên mới để khách không bị mất sản phẩm.
     * - Cấp Session ID mới (`session_regenerate_id(true)`) và hủy ID cũ để chống Session Fixation.
     * - Hủy CSRF token cũ và sinh token mới cho phiên đăng nhập.
     * - Gán nhãn sở hữu giỏ hàng theo `user_id` để phân lập, ngăn chặn tài khoản B truy cập giỏ hàng của tài khoản A.
     * - Lưu chữ ký mật khẩu `pw_sig` để kiểm tra và thu hồi các phiên đăng nhập khác khi tài khoản đổi mật khẩu.
     *
     * @param array<string, mixed> $user Bản ghi người dùng từ `UserModel` (đã xác thực).
     * @return void
     */
    public static function login(array $user): void
    {
        self::$requestSessionValid = null;
        // 1. Tạm lưu giỏ hàng của khách vãng lai (nếu có) trước khi tạo Session ID mới
        $existingCart = $_SESSION['cart'] ?? ($_SESSION['guest_cart'] ?? null);

        // 2. Cấp lại Session ID mới để triệt tiêu lỗ hổng Session Fixation
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        unset($_SESSION[Csrf::TOKEN_KEY]);

        // 3. Khởi tạo thông tin định danh phiên đăng nhập (chỉ lưu trường tối thiểu, không lưu mật khẩu thô hay hash hoàn chỉnh)
        $pwHash = (string) ($user['password_hash'] ?? '');
        $_SESSION[self::SESSION_KEY] = [
            'id'        => (int) $user['id'],
            'email'     => (string) $user['email'],
            'full_name' => (string) $user['full_name'],
            'role'      => (string) $user['role'],
            'status'    => (string) $user['status'],
            'pw_sig'    => substr(hash('sha256', $pwHash), 0, 16),
        ];

        // 4. Khôi phục giỏ hàng khách vào phiên tài khoản và gắn nhãn quyền sở hữu user_id
        if ($existingCart !== null && is_array($existingCart) && $existingCart !== []) {
            $_SESSION['cart']          = $existingCart;
            $_SESSION['cart_owner_id'] = (int) $user['id'];
            unset($_SESSION['guest_cart']);
        } elseif (isset($_SESSION['cart_owner_id']) && (int) $_SESSION['cart_owner_id'] !== (int) $user['id']) {
            // Cách ly an toàn: nếu trong session còn sót giỏ hàng của tài khoản khác -> xóa ngay lập tức
            unset($_SESSION['cart'], $_SESSION['cart_owner_id']);
        }

        Csrf::token();
    }

    /**
     * Kết thúc phiên đăng nhập:
     * - Xóa sạch dữ liệu Session trên máy chủ (bao gồm thông tin cá nhân và giỏ hàng của tài khoản).
     * - Hủy hoàn toàn Session ID cũ và xóa cookie phiên của trình duyệt.
     * - Khởi tạo phiên khách vãng lai mới hoàn toàn sạch sẽ: tuyệt đối KHÔNG sao chép giỏ hàng
     *   hoặc thông tin cá nhân của tài khoản vừa thoát sang phiên khách vãng lai, bảo vệ quyền riêng tư
     *   khi sử dụng chung máy tính.
     *
     * @return void
     */
    public static function logout(): void
    {
        self::$requestSessionValid = null;
        unset(
            $_SESSION[self::SESSION_KEY],
            $_SESSION['cart'],
            $_SESSION['cart_owner_id'],
            $_SESSION['guest_cart'],
            $_SESSION['pending_guest_cart'],
            $_SESSION['pending_guest_cart_owner_id']
        );
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            if (!headers_sent()) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?: 'Lax',
                ]);
                session_destroy();
                session_id('');
                session_start();
                session_regenerate_id(true);
            }
        }

        unset($_SESSION[self::SESSION_KEY]);
        Csrf::token();
    }

    /** Dùng chung kết quả đối chiếu cho front controller và middleware trong cùng request. */
    public static function revalidateForRequest(): bool
    {
        return self::$requestSessionValid ??= self::revalidate();
    }

    /**
     * Đối chiếu lại phiên đăng nhập với dữ liệu thực tế trong CSDL ở mỗi request.
     *
     * Mục đích:
     * 1. Khi tài khoản bị khóa (`status = 'locked'`) hoặc bị xóa: thu hồi phiên ngay lập tức.
     * 2. Khi vai trò thay đổi (`role` hạ từ admin xuống customer): cập nhật quyền ngay.
     * 3. Khi mật khẩu bị đổi từ một thiết bị/phiên khác: chữ ký `pw_sig` trong session không còn
     *    khớp với hash mới trong CSDL -> thu hồi toàn bộ các phiên cũ trên thiết bị khác!
     *
     * @return bool True nếu phiên còn hợp lệ; False nếu tài khoản bị khóa/đổi mật khẩu/không tồn tại.
     */
    public static function revalidate(): bool
    {
        $sessionUser = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($sessionUser) || (int) ($sessionUser['id'] ?? 0) <= 0) {
            return false;
        }

        try {
            $dbUser = (new \App\Models\UserModel())->findByIdForAuth((int) $sessionUser['id']);
        } catch (\Throwable $e) {
            error_log('[TL Gaming Auth] Không xác minh được phiên với CSDL: ' . $e->getMessage());
            return false;
        }

        if ($dbUser === null || $dbUser['status'] !== 'active') {
            // Thu hồi phiên: tài khoản đã bị khóa hoặc không còn tồn tại
            self::logout();
            $hotline = ContactSettings::configuration()['hotline']['display'];
            Flash::add('error', "Phiên đăng nhập đã bị thu hồi vì tài khoản không còn hoạt động. Vui lòng liên hệ Hotline {$hotline}.");
            return false;
        }

        // Kiểm tra chữ ký mật khẩu: phát hiện mật khẩu đã bị đổi từ một phiên đăng nhập khác
        $currentDbSig = substr(hash('sha256', (string) ($dbUser['password_hash'] ?? '')), 0, 16);
        $sessionSig   = (string) ($sessionUser['pw_sig'] ?? '');

        if ($sessionSig !== '' && !hash_equals($sessionSig, $currentDbSig)) {
            // Thu hồi ngay lập tức phiên cũ trên thiết bị này vì tài khoản đã đổi mật khẩu
            self::logout();
            Flash::add('error', 'Mật khẩu tài khoản đã được thay đổi từ phiên làm việc khác. Vui lòng đăng nhập lại.');
            return false;
        }

        // Đồng bộ vai trò, trạng thái, họ tên và chữ ký mật khẩu mới nhất từ CSDL
        $_SESSION[self::SESSION_KEY]['role']      = (string) $dbUser['role'];
        $_SESSION[self::SESSION_KEY]['status']    = (string) $dbUser['status'];
        $_SESSION[self::SESSION_KEY]['full_name'] = (string) $dbUser['full_name'];
        $_SESSION[self::SESSION_KEY]['email']     = (string) $dbUser['email'];
        $_SESSION[self::SESSION_KEY]['pw_sig']    = $currentDbSig;

        return true;
    }

    /**
     * Cập nhật họ tên hiển thị trong Session sau khi người dùng sửa hồ sơ.
     *
     * @param string $fullName Họ tên mới đã được kiểm tra hợp lệ.
     * @return void
     */
    public static function updateSessionName(string $fullName): void
    {
        if (isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['full_name'] = $fullName;
        }
    }

    /**
     * Cập nhật chữ ký mật khẩu mới cho phiên hiện tại sau khi đổi mật khẩu thành công,
     * đồng thời cấp lại Session ID mới. Các phiên đăng nhập trên thiết bị khác sẽ bị thu hồi.
     *
     * @param string $newPasswordHash Chuỗi băm mật khẩu mới từ password_hash().
     * @return void
     */
    public static function updatePasswordSignature(string $newPasswordHash): void
    {
        self::$requestSessionValid = null;
        if (isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['pw_sig'] = substr(hash('sha256', $newPasswordHash), 0, 16);
        }
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
    }

    /**
     * Lấy giỏ hàng trong Session an toàn cho Prompt 14.
     * Nếu tài khoản đã đăng nhập thì chỉ lấy giỏ hàng thuộc về tài khoản đó;
     * nếu phát hiện giỏ hàng thuộc tài khoản khác (do rò rỉ phiên) thì tự động cách ly và xóa sạch.
     *
     * @return array<string, mixed> Dữ liệu giỏ hàng.
     */
    public static function getCart(): array
    {
        $cart = $_SESSION['cart'] ?? [];
        if (!is_array($cart)) {
            return [];
        }

        if (self::check()) {
            $currentUserId = (int) self::id();
            $cartOwnerId   = isset($_SESSION['cart_owner_id']) ? (int) $_SESSION['cart_owner_id'] : null;
            if ($cartOwnerId !== null && $cartOwnerId !== $currentUserId) {
                // Cách ly bảo mật: giỏ hàng trong phiên không khớp với tài khoản hiện tại -> xóa ngay
                unset($_SESSION['cart'], $_SESSION['cart_owner_id']);
                return [];
            }
        }

        return $cart;
    }

    /**
     * Cập nhật giỏ hàng vào Session kèm gắn nhãn quyền sở hữu tài khoản (hoặc khách vãng lai).
     *
     * @param array<string, mixed> $cart Mảng giỏ hàng cần lưu.
     * @return void
     */
    public static function setCart(array $cart): void
    {
        $_SESSION['cart'] = $cart;
        if (self::check()) {
            $_SESSION['cart_owner_id'] = (int) self::id();
        } else {
            unset($_SESSION['cart_owner_id']);
        }
    }

    /**
     * Lưu giỏ hàng tạm đang chờ đồng bộ lại vào MySQL cho đúng tài khoản vừa đăng nhập.
     *
     * @param int                  $userId ID tài khoản sở hữu giỏ tạm.
     * @param array<int|string, mixed> $cart   Dữ liệu giỏ hàng tạm [product_id => quantity].
     * @return void
     */
    public static function setPendingGuestCart(int $userId, array $cart): void
    {
        if ($userId <= 0 || $cart === []) {
            self::clearPendingGuestCart();
            return;
        }

        $_SESSION['pending_guest_cart']          = $cart;
        $_SESSION['pending_guest_cart_owner_id'] = $userId;
    }

    /**
     * Lấy giỏ hàng tạm đang chờ đồng bộ của tài khoản hiện tại.
     * Nếu phát hiện chủ sở hữu không khớp với tài khoản đang đăng nhập -> xóa ngay để chống rò rỉ.
     *
     * @param int $userId ID tài khoản hiện tại.
     * @return array<int|string, mixed>
     */
    public static function getPendingGuestCart(int $userId): array
    {
        $pending = $_SESSION['pending_guest_cart'] ?? [];
        $ownerId = isset($_SESSION['pending_guest_cart_owner_id'])
            ? (int) $_SESSION['pending_guest_cart_owner_id']
            : null;

        if (!is_array($pending) || $pending === [] || $ownerId === null || $ownerId !== $userId) {
            if ($pending !== [] && $ownerId !== $userId) {
                self::clearPendingGuestCart();
            }
            return [];
        }

        return $pending;
    }

    /**
     * Xóa giỏ hàng tạm chờ đồng bộ sau khi đã chuyển thành công vào MySQL.
     *
     * @return void
     */
    public static function clearPendingGuestCart(): void
    {
        unset($_SESSION['pending_guest_cart'], $_SESSION['pending_guest_cart_owner_id']);
    }

    /**
     * Cấp Session ID mới nhưng giữ nguyên đăng nhập.
     *
     * @return void
     */
    public static function regenerateSession(): void
    {
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
    }
}
