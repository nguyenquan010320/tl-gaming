<?php
/**
 * AuthController — điều khiển Đăng ký, Đăng nhập, Đăng xuất, Hồ sơ khách hàng và Đổi mật khẩu.
 *
 * Vai trò trong kiến trúc MVC:
 * - Controller điều phối: đọc dữ liệu POST, kiểm tra hợp lệ, gọi `UserModel` (truy vấn PDO) và `Auth` (Session),
 *   sau đó render View hoặc chuyển hướng. Không viết SQL trong lớp này.
 * - Mọi route POST đều đi qua `CsrfMiddleware` (khai báo trong `routes/web.php`).
 * - Danh tính người dùng (`id`, `role`) CHỈ lấy từ Session qua `Auth`; không bao giờ đọc từ GET/POST.
 * - Thông báo lỗi đăng nhập dùng chung một câu để không tiết lộ email có tồn tại hay không.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ContactSettings;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Models\CategoryModel;
use App\Models\UserModel;
use Throwable;

class AuthController extends Controller
{
    /**
     * Chuỗi băm giả dùng khi email không tồn tại: vẫn chạy `password_verify()` để thời gian phản hồi
     * gần như giống nhau, tránh để kẻ tấn công dò email qua chênh lệch thời gian (timing attack).
     */
    private const DUMMY_HASH = '$2y$12$AXSTt4fo0nFpyEG3GZOTa.YQpq50dbhz9sCb0Lbr1B66uGjWvOw3q';

    /** Số lần đăng nhập sai tối đa cho mỗi cặp (IP + email) trong cửa sổ thời gian. */
    private const MAX_LOGIN_ATTEMPTS = 5;

    /** Số lần đăng nhập sai tối đa cho mỗi IP (chặn dò nhiều email khác nhau). */
    private const MAX_LOGIN_ATTEMPTS_PER_IP = 20;

    /** Cửa sổ giới hạn thử đăng nhập: 15 phút (giây). */
    private const LOGIN_WINDOW = 900;

    // =====================================================================
    // ĐĂNG NHẬP / ĐĂNG KÝ / ĐĂNG XUẤT
    // =====================================================================

    /**
     * Hiển thị trang Đăng nhập & Đăng ký (`GET /dang-nhap`).
     * Người đã đăng nhập được chuyển thẳng tới trang phù hợp với vai trò.
     *
     * @return void
     */
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect(Auth::isAdmin() ? '/admin' : '/tai-khoan');
        }
        $activeTab = ($_GET['tab'] ?? null) === 'dang-ky' ? 'register' : 'login';
        $this->renderAuthPage($activeTab, [], []);
    }

    /**
     * Xử lý đăng nhập (`POST /dang-nhap`).
     *
     * Quy trình: chuẩn hóa dữ liệu → kiểm tra giới hạn thử → tìm user theo email → `password_verify()`
     * → kiểm tra trạng thái → tạo Session mới (`Auth::login`, có `session_regenerate_id`).
     *
     * @return void
     */
    public function login(): void
    {
        if (Auth::check()) {
            $this->redirect(Auth::isAdmin() ? '/admin' : '/tai-khoan');
        }

        $email    = mb_strtolower($this->postString('email'));
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $old      = ['login_email' => $email];

        if ($email === '' || $password === '') {
            $this->renderAuthPage('login', ['Vui lòng nhập đầy đủ email và mật khẩu.'], $old);
            return;
        }

        // Giới hạn thử: theo cặp IP+email (chống đoán mật khẩu) và theo IP (chống quét nhiều email)
        $ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $keyEmail = 'login:' . $ip . '|' . $email;
        $keyIp    = 'login-ip:' . $ip;

        if (
            RateLimiter::tooManyAttempts($keyEmail, self::MAX_LOGIN_ATTEMPTS, self::LOGIN_WINDOW)
            || RateLimiter::tooManyAttempts($keyIp, self::MAX_LOGIN_ATTEMPTS_PER_IP, self::LOGIN_WINDOW)
        ) {
            $wait = max(
                RateLimiter::availableIn($keyEmail, self::LOGIN_WINDOW),
                RateLimiter::availableIn($keyIp, self::LOGIN_WINDOW)
            );
            http_response_code(429);
            $this->renderAuthPage('login', [
                'Bạn đã thử đăng nhập quá nhiều lần. Vui lòng thử lại sau ' . (int) ceil($wait / 60) . ' phút.',
            ], $old);
            return;
        }

        try {
            $userModel = new UserModel();
            $user      = $userModel->findByEmailForLogin($email);

            // Luôn chạy password_verify (kể cả khi không có user) để thời gian xử lý đồng đều
            $hash       = $user !== null ? (string) $user['password_hash'] : self::DUMMY_HASH;
            $passwordOk = password_verify($password, $hash) && $user !== null;

            if (!$passwordOk) {
                RateLimiter::hit($keyEmail, self::LOGIN_WINDOW);
                RateLimiter::hit($keyIp, self::LOGIN_WINDOW);
                // Thông báo chung: không cho biết email có tồn tại hay không
                $this->renderAuthPage('login', ['Email hoặc mật khẩu không chính xác.'], $old);
                return;
            }

            // Chỉ khi đã nhập ĐÚNG mật khẩu mới thông báo tình trạng khóa
            if ($user['status'] !== 'active') {
                $hotline = ContactSettings::configuration()['hotline']['display'];
                $this->renderAuthPage('login', [
                    "Tài khoản của bạn hiện đang bị khóa. Vui lòng liên hệ Hotline {$hotline} để được hỗ trợ.",
                ], $old);
                return;
            }

            // Nâng cấp thuật toán băm nếu cấu hình PHP đã thay đổi (cost/thuật toán mới)
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $userModel->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
            }
        } catch (Throwable $e) {
            error_log('[TL Gaming AuthController] Lỗi đăng nhập: ' . $e->getMessage());
            $this->renderAuthPage('login', ['Hệ thống đang tạm thời gián đoạn. Vui lòng thử lại sau ít phút.'], $old);
            return;
        }

        // Chỉ lấy giỏ hàng Session của khách vãng lai thực thụ (chưa từng gắn cart_owner_id của tài khoản nào khác)
        $cartOwnerId = isset($_SESSION['cart_owner_id']) ? (int) $_SESSION['cart_owner_id'] : null;
        $guestCart   = ($cartOwnerId === null)
            ? ($_SESSION['cart'] ?? ($_SESSION['guest_cart'] ?? []))
            : [];

        RateLimiter::clear($keyEmail);
        Auth::login($user);

        // Xóa khe giỏ khách vãng lai thô sau khi đã tách an toàn; nếu hợp nhất lỗi sẽ lưu vào pending_guest_cart gắn user_id
        unset($_SESSION['cart'], $_SESSION['guest_cart'], $_SESSION['cart_owner_id']);

        // Hợp nhất giỏ hàng Session của khách vào giỏ hàng CSDL của tài khoản
        if (!empty($guestCart) && is_array($guestCart)) {
            try {
                $cartModel   = new \App\Models\CartModel();
                $mergeResult = $cartModel->mergeGuestCartIntoUserCart((int) $user['id'], $guestCart);

                // Chỉ xóa giỏ tạm sau khi transaction hợp nhất vào MySQL đã thành công hoàn toàn
                Auth::clearPendingGuestCart();

                if ($mergeResult['merged_count'] > 0) {
                    Flash::add('info', "Đã đồng bộ {$mergeResult['merged_count']} sản phẩm từ giỏ hàng trước đó vào tài khoản của bạn.");
                }
                if ($mergeResult['adjusted_count'] > 0) {
                    Flash::add('warning', "Có {$mergeResult['adjusted_count']} sản phẩm đã được điều chỉnh số lượng theo tồn kho khả dụng hiện tại.");
                }
                if ($mergeResult['skipped_count'] > 0) {
                    Flash::add('warning', "Có {$mergeResult['skipped_count']} sản phẩm tạm không còn khả dụng (hết hàng hoặc ngừng kinh doanh) nên không được đưa vào giỏ.");
                }
            } catch (Throwable $e) {
                error_log('[TL Gaming AuthController] Lỗi hợp nhất giỏ khi đăng nhập: ' . $e->getMessage());
                // Lưu giỏ tạm gắn chặt với user_id vừa đăng nhập để hiển thị và tự đồng bộ lại khi MySQL ổn định
                Auth::setPendingGuestCart((int) $user['id'], $guestCart);
                Flash::add('warning', 'Không thể đồng bộ giỏ hàng tạm thời vào tài khoản lúc này. Các sản phẩm tạm vẫn được giữ lại để hiển thị và đồng bộ lại sau.');
            }
        }

        Flash::add('success', 'Đăng nhập thành công. Xin chào ' . $user['full_name'] . '!');
        $this->redirect($user['role'] === 'admin' ? '/admin' : '/');
    }

    /**
     * Xử lý đăng ký khách hàng mới (`POST /dang-ky`).
     * Vai trò luôn là 'customer' (hàm `UserModel::createCustomer` không nhận tham số role).
     *
     * @return void
     */
    public function register(): void
    {
        if (Auth::check()) {
            $this->redirect('/tai-khoan');
        }

        $fullName        = $this->postString('full_name');
        $email           = mb_strtolower($this->postString('email'));
        $phoneRaw        = $this->postString('phone');
        $password        = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $passwordConfirm = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation'])
            ? $_POST['password_confirmation'] : '';

        $old    = ['reg_full_name' => $fullName, 'reg_email' => $email, 'reg_phone' => $phoneRaw];
        $errors = [];

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            $errors[] = 'Họ và tên phải từ 2 đến 100 ký tự.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            $errors[] = 'Địa chỉ email không hợp lệ.';
        }
        $phone = $this->normalizePhone($phoneRaw);
        if ($phone === null) {
            $errors[] = 'Số điện thoại không hợp lệ (ví dụ: 0912345678).';
        }
        $errors = array_merge($errors, $this->validateNewPassword($password));
        if ($password !== $passwordConfirm) {
            $errors[] = 'Mật khẩu nhập lại không khớp.';
        }
        if (empty($_POST['agree_terms'])) {
            $errors[] = 'Bạn cần đồng ý với Chính sách bảo hành & Quy định kiểm định.';
        }

        if ($errors !== []) {
            http_response_code(422);
            $this->renderAuthPage('register', $errors, $old);
            return;
        }

        try {
            $userModel = new UserModel();
            // Phản hồi sớm nếu email đã có; ràng buộc UNIQUE ở MySQL vẫn là chốt chặn cuối cùng
            $newId = $userModel->emailExists($email)
                ? null
                : $userModel->createCustomer($email, password_hash($password, PASSWORD_DEFAULT), $fullName, $phone);
        } catch (Throwable $e) {
            error_log('[TL Gaming AuthController] Lỗi đăng ký: ' . $e->getMessage());
            $this->renderAuthPage('register', ['Hệ thống đang tạm thời gián đoạn. Vui lòng thử lại sau ít phút.'], $old);
            return;
        }

        if ($newId === null) {
            http_response_code(409);
            $this->renderAuthPage('register', ['Email này đã được đăng ký. Vui lòng đăng nhập hoặc dùng email khác.'], $old);
            return;
        }

        // Quy trình rõ ràng: đăng ký xong chuyển về trang đăng nhập để người dùng tự đăng nhập
        Flash::add('success', 'Đăng ký tài khoản thành công. Vui lòng đăng nhập để tiếp tục.');
        $this->redirect('/dang-nhap');
    }

    /**
     * Đăng xuất (`POST /dang-xuat`, đã kiểm tra CSRF bởi middleware). Không hỗ trợ GET.
     *
     * @return void
     */
    public function logout(): void
    {
        Auth::logout();
        Flash::add('success', 'Bạn đã đăng xuất thành công.');
        $this->redirect('/');
    }

    // =====================================================================
    // HỒ SƠ KHÁCH HÀNG & ĐỔI MẬT KHẨU (yêu cầu AuthMiddleware)
    // =====================================================================

    /**
     * Hiển thị trang hồ sơ cá nhân (`GET /tai-khoan`).
     *
     * @return void
     */
    public function showProfile(): void
    {
        $this->renderAccountPage('auth/profile', 'Tài khoản của tôi | TL Gaming', '/tai-khoan', [
            'profile' => $this->currentUserRecord(),
            'errors'  => [],
            'old'     => [],
        ]);
    }

    /**
     * Cập nhật hồ sơ (`POST /tai-khoan`). Chỉ cập nhật bản ghi có `id` lấy từ Session.
     * Các trường `role`, `status`, `email`, `id` gửi kèm trong POST (nếu có) bị bỏ qua hoàn toàn.
     *
     * @return void
     */
    public function updateProfile(): void
    {
        $userId   = (int) Auth::id();
        $fullName = $this->postString('full_name');
        $phoneRaw = $this->postString('phone');
        $address  = $this->postString('address');
        $errors   = [];

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            $errors[] = 'Họ và tên phải từ 2 đến 100 ký tự.';
        }
        $phone = $phoneRaw === '' ? null : $this->normalizePhone($phoneRaw);
        if ($phoneRaw !== '' && $phone === null) {
            $errors[] = 'Số điện thoại không hợp lệ (ví dụ: 0912345678).';
        }
        if (mb_strlen($address) > 255) {
            $errors[] = 'Địa chỉ không được vượt quá 255 ký tự.';
        }

        if ($errors !== []) {
            http_response_code(422);
            $this->renderAccountPage('auth/profile', 'Tài khoản của tôi | TL Gaming', '/tai-khoan', [
                'profile' => $this->currentUserRecord(),
                'errors'  => $errors,
                'old'     => ['full_name' => $fullName, 'phone' => $phoneRaw, 'address' => $address],
            ]);
            return;
        }

        try {
            (new UserModel())->updateProfile($userId, $fullName, $phone, $address === '' ? null : $address);
        } catch (Throwable $e) {
            error_log('[TL Gaming AuthController] Lỗi cập nhật hồ sơ: ' . $e->getMessage());
            Flash::add('error', 'Không thể cập nhật hồ sơ lúc này. Vui lòng thử lại sau.');
            $this->redirect('/tai-khoan');
        }

        Auth::updateSessionName($fullName);
        Flash::add('success', 'Cập nhật thông tin tài khoản thành công.');
        $this->redirect('/tai-khoan');
    }

    /**
     * Hiển thị biểu mẫu đổi mật khẩu (`GET /doi-mat-khau`).
     *
     * @return void
     */
    public function showChangePassword(): void
    {
        $this->renderAccountPage('auth/change_password', 'Đổi mật khẩu | TL Gaming', '/doi-mat-khau', [
            'errors' => [],
        ]);
    }

    /**
     * Xử lý đổi mật khẩu (`POST /doi-mat-khau`): xác minh mật khẩu cũ bằng `password_verify()`,
     * giới hạn số lần nhập sai, băm mật khẩu mới bằng `password_hash()` và cấp Session ID mới.
     *
     * @return void
     */
    public function changePassword(): void
    {
        $userId  = (int) Auth::id();
        $current = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
        $new     = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
        $confirm = isset($_POST['new_password_confirmation']) && is_string($_POST['new_password_confirmation'])
            ? $_POST['new_password_confirmation'] : '';

        $rateKey = 'chpw:' . $userId;
        $errors  = [];

        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_LOGIN_ATTEMPTS, self::LOGIN_WINDOW)) {
            http_response_code(429);
            $errors[] = 'Bạn đã nhập sai mật khẩu hiện tại quá nhiều lần. Vui lòng thử lại sau ít phút.';
            $this->renderAccountPage('auth/change_password', 'Đổi mật khẩu | TL Gaming', '/doi-mat-khau', ['errors' => $errors]);
            return;
        }

        try {
            $userModel   = new UserModel();
            $currentHash = $userModel->getPasswordHash($userId);

            if ($currentHash === null || $current === '' || !password_verify($current, $currentHash)) {
                RateLimiter::hit($rateKey, self::LOGIN_WINDOW);
                $errors[] = 'Mật khẩu hiện tại không chính xác.';
            }

            $errors = array_merge($errors, $this->validateNewPassword($new));
            if ($new !== $confirm) {
                $errors[] = 'Mật khẩu mới nhập lại không khớp.';
            }
            if ($errors === [] && $current === $new) {
                $errors[] = 'Mật khẩu mới phải khác mật khẩu hiện tại.';
            }

            if ($errors !== []) {
                http_response_code(422);
                $this->renderAccountPage('auth/change_password', 'Đổi mật khẩu | TL Gaming', '/doi-mat-khau', ['errors' => $errors]);
                return;
            }

            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $userModel->updatePasswordHash($userId, $newHash);
        } catch (Throwable $e) {
            error_log('[TL Gaming AuthController] Lỗi đổi mật khẩu: ' . $e->getMessage());
            Flash::add('error', 'Không thể đổi mật khẩu lúc này. Vui lòng thử lại sau.');
            $this->redirect('/doi-mat-khau');
        }

        RateLimiter::clear($rateKey);
        // Cập nhật chữ ký mật khẩu mới cho phiên hiện tại để phiên này tiếp tục hoạt động với Session ID mới,
        // trong khi toàn bộ các phiên khác trên thiết bị/trình duyệt khác mang chữ ký cũ sẽ bị Auth::revalidate() thu hồi an toàn
        Auth::updatePasswordSignature($newHash);
        Flash::add('success', 'Đổi mật khẩu thành công. Các phiên đăng nhập trên thiết bị khác đã được thu hồi an toàn.');
        $this->redirect('/tai-khoan');
    }

    // =====================================================================
    // HÀM HỖ TRỢ NỘI BỘ
    // =====================================================================

    /**
     * Đọc một trường POST dạng chuỗi và cắt khoảng trắng hai đầu; trả về '' nếu thiếu hoặc là mảng.
     *
     * @param string $key Tên trường trong `$_POST`.
     * @return string Giá trị đã trim.
     */
    private function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Chuẩn hóa số điện thoại Việt Nam: bỏ khoảng trắng/dấu chấm/gạch nối, đổi +84 thành 0,
     * và chỉ chấp nhận 10 chữ số bắt đầu bằng 0.
     *
     * @param string $raw Chuỗi người dùng nhập.
     * @return string|null Số điện thoại chuẩn hóa, hoặc null nếu không hợp lệ.
     */
    private function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/[\s.\-]/', '', $raw) ?? '';
        if (str_starts_with($digits, '+84')) {
            $digits = '0' . substr($digits, 3);
        }
        return preg_match('/^0\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Kiểm tra chính sách mật khẩu mới: 8–72 ký tự (72 là giới hạn của bcrypt), có cả chữ và số.
     *
     * @param string $password Mật khẩu cần kiểm tra.
     * @return array<int, string> Danh sách lỗi tiếng Việt (rỗng nếu hợp lệ).
     */
    private function validateNewPassword(string $password): array
    {
        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Mật khẩu phải có tối thiểu 8 ký tự.';
        } elseif (strlen($password) > 72) {
            $errors[] = 'Mật khẩu không được vượt quá 72 ký tự.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Mật khẩu phải bao gồm cả chữ cái và chữ số.';
        }
        return $errors;
    }

    /**
     * Lấy bản ghi người dùng hiện tại từ CSDL theo `id` trong Session.
     *
     * @return array<string, mixed> Bản ghi người dùng (mảng rỗng nếu không đọc được).
     */
    private function currentUserRecord(): array
    {
        try {
            return (new UserModel())->findById((int) Auth::id()) ?? [];
        } catch (Throwable $e) {
            error_log('[TL Gaming AuthController] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Nạp menu danh mục Header (dùng chung cho mọi trang tài khoản).
     *
     * @return array<int, array<string, mixed>> Danh sách mục menu (rỗng nếu lỗi CSDL).
     */
    private function loadHeaderMenu(): array
    {
        if (!Database::isConnected()) {
            return [];
        }
        try {
            $categoryModel = new CategoryModel();
            return $categoryModel->buildHeaderMenuItems($categoryModel->getActiveCategories(includeProductCounts: false));
        } catch (Throwable $e) {
            error_log('[TL Gaming AuthController] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Render trang Đăng nhập/Đăng ký với danh sách lỗi và dữ liệu nhập lại (không bao giờ nhập lại mật khẩu).
     *
     * @param string                $activeTab Tab hiển thị: 'login' hoặc 'register'.
     * @param array<int, string>    $errors    Danh sách lỗi tiếng Việt.
     * @param array<string, string> $old       Giá trị cũ của các ô nhập (để người dùng không phải gõ lại).
     * @return void
     */
    private function renderAuthPage(string $activeTab, array $errors, array $old): void
    {
        $this->render('auth/login', [
            'pageTitle'       => 'Đăng nhập & Đăng ký Tài khoản | TL Gaming',
            'currentRoute'    => '/dang-nhap',
            'headerMenuItems' => $this->loadHeaderMenu(),
            'activeTab'       => $activeTab,
            'errors'          => $errors,
            'old'             => $old,
        ]);
    }

    /**
     * Render các trang tài khoản (hồ sơ, đổi mật khẩu) với dữ liệu bổ sung.
     *
     * @param string               $view  Đường dẫn View.
     * @param string               $title Tiêu đề trang.
     * @param string               $route Route hiện tại.
     * @param array<string, mixed> $extra Dữ liệu riêng của từng trang.
     * @return void
     */
    private function renderAccountPage(string $view, string $title, string $route, array $extra): void
    {
        $this->render($view, array_merge([
            'pageTitle'       => $title,
            'currentRoute'    => $route,
            'headerMenuItems' => $this->loadHeaderMenu(),
        ], $extra));
    }
}
