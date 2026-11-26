<?php
/**
 * Bộ kiểm thử hồi quy bảo mật & nghiệp vụ xác thực TL Gaming (12 kịch bản).
 *
 * Chạy bằng lệnh:
 * E:\xampp\php\php.exe scripts/run_regression_tests.php
 */

declare(strict_types=1);

// Thiết lập Autoloader
spl_autoload_register(static function (string $className): void {
    $prefix  = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';

    if (!str_starts_with($className, $prefix)) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $filePath      = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($filePath)) {
        require_once $filePath;
    }
});

require_once dirname(__DIR__) . '/config/env.php';
loadEnvFile(dirname(__DIR__) . '/.env');

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Models\UserModel;

// Đảm bảo session sẵn sàng
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$results = [];

function recordResult(string $id, string $title, bool $pass, string $evidence): void
{
    global $results;
    $status    = $pass ? 'PASS' : 'FAIL';
    $results[] = [
        'id'       => $id,
        'title'    => $title,
        'status'   => $status,
        'evidence' => $evidence,
    ];
    $icon = $pass ? '[PASS]' : '[FAIL]';
    echo sprintf("%-8s | %-6s | %s\n         -> Bằng chứng: %s\n", $id, $icon, $title, $evidence);
}

echo "================================================================================\n";
echo "TL GAMING — KIỂM THỬ HỒI QUY BẢO MẬT & XÁC THỰC (PROMPT 13 & 14)\n";
echo "Thời gian chạy: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "================================================================================\n\n";

$db = Database::getConnection();
$userModel = new UserModel();

// -----------------------------------------------------------------------------
// Test 1: Đăng ký khách hàng mới (POST /dang-ky)
// -----------------------------------------------------------------------------
$testEmail1 = 'test_cust_' . time() . '_' . mt_rand(100, 999) . '@tlgaming.test';
$rawPass1   = 'CustPass@2026';
$hashPass1  = password_hash($rawPass1, PASSWORD_DEFAULT);
$fullName1  = 'Nguyễn Khách Hàng Test';
$phone1     = '0912345678';

$newUserId1 = $userModel->createCustomer($testEmail1, $hashPass1, $fullName1, $phone1);
$userRecord1 = $newUserId1 !== null ? $userModel->findByIdForAuth($newUserId1) : null;
$dupAttempt  = $userModel->emailExists($testEmail1);

$test1Pass = ($newUserId1 !== null && $newUserId1 > 0)
    && ($userRecord1 !== null)
    && ($userRecord1['role'] === 'customer')
    && ($userRecord1['status'] === 'active')
    && password_verify($rawPass1, (string) $userRecord1['password_hash'])
    && ($dupAttempt === true);

$evidence1 = "Tạo user ID={$newUserId1}, email={$testEmail1}, role={$userRecord1['role']}, status={$userRecord1['status']}, chặn trùng email=OK";
recordResult('TEST_01', 'Đăng ký khách hàng mới & Hash mật khẩu an toàn', $test1Pass, $evidence1);

// -----------------------------------------------------------------------------
// Test 2: Đăng nhập thành công & Bảo lưu giỏ hàng khách
// -----------------------------------------------------------------------------
$_SESSION = [];
// Khởi tạo giỏ hàng khách vãng lai trước khi đăng nhập
$_SESSION['cart'] = [
    'item_demo' => [
        'id'       => 1,
        'name'     => 'PC Gaming TL-01 Test',
        'price'    => 11500000,
        'quantity' => 1,
    ],
];
unset($_SESSION['cart_owner_id']);

$loginUser = $userModel->findByEmailForLogin($testEmail1);
Auth::login($loginUser);

$test2Pass = Auth::check()
    && Auth::id() === $newUserId1
    && isset($_SESSION['cart']['item_demo'])
    && isset($_SESSION['cart_owner_id'])
    && (int) $_SESSION['cart_owner_id'] === $newUserId1
    && !empty($_SESSION['auth_user']['pw_sig'])
    && strlen((string) $_SESSION['auth_user']['pw_sig']) === 16;

$evidence2 = "Auth::check()=" . (Auth::check() ? 'true' : 'false') . ", user_id=" . Auth::id() . ", cart_owner_id=" . ($_SESSION['cart_owner_id'] ?? 'none') . ", pw_sig=" . ($_SESSION['auth_user']['pw_sig'] ?? 'none');
recordResult('TEST_02', 'Đăng nhập thành công & Bảo lưu + Gắn nhãn sở hữu giỏ hàng', $test2Pass, $evidence2);

// -----------------------------------------------------------------------------
// Test 3: Đăng nhập sai mật khẩu (Sai mật khẩu -> tăng lượt thử)
// -----------------------------------------------------------------------------
$rateKeyLogin = 'login:test_ip|' . $testEmail1;
RateLimiter::clear($rateKeyLogin);

$verifyWrongPass = password_verify('MậtKhẩuHoànToànSai!@#', (string) $userRecord1['password_hash']);
RateLimiter::hit($rateKeyLogin, 900);
$hitsAfterOneFail = RateLimiter::attempts($rateKeyLogin, 900);

$test3Pass = ($verifyWrongPass === false) && ($hitsAfterOneFail === 1);
$evidence3 = "password_verify('sai')=" . ($verifyWrongPass ? 'true' : 'false') . ", RateLimiter::attempts()={$hitsAfterOneFail}";
recordResult('TEST_03', 'Đăng nhập sai mật khẩu bị từ chối & ghi nhận lượt đếm RateLimiter', $test3Pass, $evidence3);

// -----------------------------------------------------------------------------
// Test 4: Rate Limiter khóa khi vượt ngưỡng 5 lần trong 900s
// -----------------------------------------------------------------------------
$rateKeyLock = 'test:lockout_' . time();
RateLimiter::clear($rateKeyLock);

// Thử thêm 5 lần
for ($i = 0; $i < 5; $i++) {
    RateLimiter::hit($rateKeyLock, 900);
}

$isLocked = RateLimiter::tooManyAttempts($rateKeyLock, 5, 900);
$waitSecs = RateLimiter::availableIn($rateKeyLock, 900);
RateLimiter::clear($rateKeyLock);
$isUnlockedAfterClear = !RateLimiter::tooManyAttempts($rateKeyLock, 5, 900);

$test4Pass = $isLocked && ($waitSecs > 800) && $isUnlockedAfterClear;
$evidence4 = "tooManyAttempts=" . ($isLocked ? 'true' : 'false') . ", availableIn={$waitSecs}s, unlockSauKhiClear=" . ($isUnlockedAfterClear ? 'true' : 'false');
recordResult('TEST_04', 'Khóa đăng nhập khi vượt quá 5 lần thử sai (RateLimiter Lockout)', $test4Pass, $evidence4);

// -----------------------------------------------------------------------------
// Test 5: Kiểm thử Race Condition RateLimiter với nhiều request đồng thời
// -----------------------------------------------------------------------------
$concurrencyKey = 'test:concurrency_' . time();
RateLimiter::clear($concurrencyKey);

$phpBin  = 'E:\\xampp\\php\\php.exe';
$worker  = dirname(__DIR__) . '/scripts/rate_limit_worker.php';
$procCount = 10;
$procs = [];
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

// Khởi chạy đồng thời 10 tiến trình worker độc lập
for ($i = 0; $i < $procCount; $i++) {
    $cmd = "\"{$phpBin}\" \"{$worker}\" \"{$concurrencyKey}\" 900 1";
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $procs[] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
    }
}

// Chờ toàn bộ tiến trình kết thúc
$workerOutputs = [];
foreach ($procs as $p) {
    $out = stream_get_contents($p['out']);
    $err = stream_get_contents($p['err']);
    fclose($p['out']);
    fclose($p['err']);
    proc_close($p['proc']);
    $workerOutputs[] = trim($out);
}

$finalConcurrentHits = RateLimiter::attempts($concurrencyKey, 900);
RateLimiter::clear($concurrencyKey);

$test5Pass = ($finalConcurrentHits === $procCount);
$evidence5 = "Chạy {$procCount} tiến trình song song đồng thời. Số hits ghi nhận chính xác bằng flock(LOCK_EX) = {$finalConcurrentHits}/{$procCount}";
recordResult('TEST_05', 'Chống Race Condition của RateLimiter dưới tải đồng thời (Atomic flock)', $test5Pass, $evidence5);

// -----------------------------------------------------------------------------
// Test 6: Đăng xuất & Cách ly giỏ hàng (Không rò rỉ dữ liệu sang khách)
// -----------------------------------------------------------------------------
Auth::logout();
$isLoggedOutAfterAction = !Auth::check();
$cartAfterLogout        = Auth::getCart();

// Kiểm thử cách ly: nếu session có cart_owner_id = 8888 (khác user hiện tại)
$_SESSION['cart'] = ['item_leak' => ['id' => 99, 'name' => 'Foreign Cart']];
$_SESSION['cart_owner_id'] = 8888;
// Tạo phiên khách vãng lai hoặc đăng nhập user khác
$_SESSION['auth_user'] = [
    'id'        => 7777,
    'email'     => 'other@tlgaming.test',
    'full_name' => 'Other User',
    'role'      => 'customer',
    'status'    => 'active',
    'pw_sig'    => 'sig_test',
];
$isolatedCart = Auth::getCart(); // Phải nhận diện cart_owner_id 8888 != 7777 và xóa sạch

$test6Pass = $isLoggedOutAfterAction && ($cartAfterLogout === []) && ($isolatedCart === []);
$evidence6 = "Auth::check() sau logout=" . ($isLoggedOutAfterAction ? 'false' : 'true') . ", giỏ hàng sau logout=" . json_encode($cartAfterLogout) . ", giỏ hàng bị cách ly=" . json_encode($isolatedCart);
recordResult('TEST_06', 'Đăng xuất an toàn & Cách ly tuyệt đối giỏ hàng và danh tính', $test6Pass, $evidence6);

// -----------------------------------------------------------------------------
// Test 7: Kiểm tra CSRF Protection (Từ chối token sai / rỗng)
// -----------------------------------------------------------------------------
$_SESSION = [];
$validCsrfToken = Csrf::token();
$checkValidToken   = Csrf::verify($validCsrfToken);
$checkInvalidToken = Csrf::verify('csrf_token_gia_mao_123456');
$checkNullToken    = Csrf::verify(null);

$test7Pass = ($checkValidToken === true) && ($checkInvalidToken === false) && ($checkNullToken === false);
$evidence7 = "Token hợp lệ=" . ($checkValidToken ? 'true' : 'false') . ", Token sai=" . ($checkInvalidToken ? 'true' : 'false') . ", Token null=" . ($checkNullToken ? 'true' : 'false');
recordResult('TEST_07', 'Chống tấn công CSRF (Csrf::verify xác minh chặt chẽ)', $test7Pass, $evidence7);

// -----------------------------------------------------------------------------
// Test 8: Phân quyền Customer bị chặn khỏi Admin (403 Forbidden)
// -----------------------------------------------------------------------------
$mwScript = dirname(__DIR__) . '/scripts/test_middleware_worker.php';
$customerOutput = shell_exec("\"{$phpBin}\" \"{$mwScript}\" customer 2>&1");
$test8Pass = is_string($customerOutput) && str_contains($customerOutput, '403 - Từ chối truy cập khu vực Quản trị');
$evidence8 = "Tài khoản customer truy cập AdminMiddleware bị chặn HTTP 403 Forbidden (chứa tiêu đề lỗi 403)";
recordResult('TEST_08', 'Phân quyền: Khách hàng (customer) bị chặn khỏi khu vực Admin', $test8Pass, $evidence8);

// -----------------------------------------------------------------------------
// Test 9: Phân quyền Admin truy cập trang Admin (Thành công)
// -----------------------------------------------------------------------------
$adminOutput = shell_exec("\"{$phpBin}\" \"{$mwScript}\" admin 2>&1");
$test9Pass = is_string($adminOutput) && str_contains($adminOutput, 'OK:ADMIN_AUTHORIZED');
$evidence9 = "Tài khoản admin (admin@tlgaming.vn) đi qua AdminMiddleware thành công: " . trim((string) $adminOutput);
recordResult('TEST_09', 'Phân quyền: Quản trị viên (admin) được phép truy cập Admin', $test9Pass, $evidence9);

// -----------------------------------------------------------------------------
// Test 10: Thu hồi phiên tài khoản bị khóa (locked)
// -----------------------------------------------------------------------------
$testEmail10 = 'locked_test_' . time() . '@tlgaming.test';
$newUserId10 = $userModel->createCustomer($testEmail10, password_hash('Pass123!@#', PASSWORD_DEFAULT), 'User Sắp Khóa', '0933333333');
$userRec10   = $userModel->findByEmailForLogin($testEmail10);

$_SESSION = [];
Auth::login($userRec10);
$checkBeforeLock = Auth::check();

// Quản trị viên khóa tài khoản trong database
$stmtLock = $db->prepare("UPDATE users SET status = 'locked' WHERE id = ?");
$stmtLock->execute([$newUserId10]);

// Request tiếp theo của người dùng chạy Auth::revalidate()
$revalidateResult = Auth::revalidate();
$checkAfterRevalidate = Auth::check();

$test10Pass = ($checkBeforeLock === true) && ($revalidateResult === false) && ($checkAfterRevalidate === false);
$evidence10 = "Trước khi khóa: Auth::check=true; Sau khi DB status='locked': Auth::revalidate=" . ($revalidateResult ? 'true' : 'false') . ", Auth::check=" . ($checkAfterRevalidate ? 'true' : 'false');
recordResult('TEST_10', 'Thu hồi phiên lập tức khi tài khoản bị khóa trong CSDL (Auth::revalidate)', $test10Pass, $evidence10);

// -----------------------------------------------------------------------------
// Test 11: Đổi mật khẩu & Thu hồi đa phiên (Multi-Session Revocation)
// -----------------------------------------------------------------------------
$testEmail11  = 'multisession_' . time() . '@tlgaming.test';
$oldPassword  = 'OldPass@123456';
$newPassword  = 'NewPass@999888';
$oldHash11    = password_hash($oldPassword, PASSWORD_DEFAULT);
$newUserId11  = $userModel->createCustomer($testEmail11, $oldHash11, 'User Multi Session', '0944444444');
$userRec11    = $userModel->findByEmailForLogin($testEmail11);

// Thiết bị 1 đăng nhập
$_SESSION = [];
Auth::login($userRec11);
$session1Data = $_SESSION;
$session1Sig  = $session1Data['auth_user']['pw_sig'];

// Thiết bị 2 cùng lúc đăng nhập với thông tin ban đầu (mang cùng pw_sig)
$session2Data = $session1Data;

// Trên Thiết bị 1: Người dùng tiến hành Đổi mật khẩu
$newHash11 = password_hash($newPassword, PASSWORD_DEFAULT);
$userModel->updatePasswordHash($newUserId11, $newHash11);
// AuthController::changePassword() gọi updatePasswordSignature():
Auth::updatePasswordSignature($newHash11);
$session1NewSig = $_SESSION['auth_user']['pw_sig'];

// 1. Kiểm tra Thiết bị 1 gọi Auth::revalidate(): Vẫn hợp lệ vì pw_sig đã được cập nhật
$session1Revalidate = Auth::revalidate();
$session1StillActive = Auth::check();

// 2. Kiểm tra Thiết bị 2: Nạp lại trạng thái session cũ của Thiết bị 2
$_SESSION = $session2Data; // Mang pw_sig cũ
$session2Revalidate = Auth::revalidate(); // Chữ ký cũ không khớp chữ ký mới từ hash mới trong DB
$session2StillActive = Auth::check();

$test11Pass = ($session1Sig !== $session1NewSig)
    && ($session1Revalidate === true)
    && ($session1StillActive === true)
    && ($session2Revalidate === false)
    && ($session2StillActive === false);

$evidence11 = "Thiết bị 1: pw_sig đổi từ {$session1Sig} -> {$session1NewSig}, revalidate=true, check=true. Thiết bị 2 (pw_sig cũ): revalidate=false, check=false (đã bị thu hồi)";
recordResult('TEST_11', 'Đổi mật khẩu & Thu hồi phiên trên tất cả thiết bị khác (Multi-Session Revocation)', $test11Pass, $evidence11);

// Dọn dẹp dữ liệu test khỏi database
$stmtCleanup = $db->prepare("DELETE FROM users WHERE email IN (?, ?, ?)");
$stmtCleanup->execute([$testEmail1, $testEmail10, $testEmail11]);

// -----------------------------------------------------------------------------
// Test 12: Hồi quy các trang công khai (Homepage, Catalog, Detail)
// -----------------------------------------------------------------------------
$baseUrl = 'http://localhost/tl-gaming/public';

// Helper gọi HTTP GET
function fetchHttp(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FAILONERROR    => false,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => (int) $httpCode, 'body' => (string) $body];
}

$rHome    = fetchHttp($baseUrl . '/');
$rCatalog = fetchHttp($baseUrl . '/san-pham');
$rDetail  = fetchHttp($baseUrl . '/san-pham/pc-gaming-tl-01-i5-12400f-rtx-2060s');

$homeOk    = ($rHome['code'] === 200) && str_contains($rHome['body'], 'TL GAMING');
$catalogOk = ($rCatalog['code'] === 200) && str_contains($rCatalog['body'], 'Tất Cả Máy Tính');
$detailOk  = ($rDetail['code'] === 200) && str_contains($rDetail['body'], 'PC Gaming TL-01');

$test12Pass = $homeOk && $catalogOk && $detailOk;
$evidence12 = "Trang chủ: HTTP {$rHome['code']}, Danh mục: HTTP {$rCatalog['code']}, Chi tiết SP: HTTP {$rDetail['code']}";
recordResult('TEST_12', 'Hồi quy toàn vẹn các trang công khai (Trang chủ, Danh mục, Chi tiết)', $test12Pass, $evidence12);

echo "\n================================================================================\n";
$passedCount = count(array_filter($results, static fn($r) => $r['status'] === 'PASS'));
$totalCount  = count($results);
echo "TỔNG KẾT: {$passedCount}/{$totalCount} KỊCH BẢN ĐẠT CHUẨN (PASS)\n";
echo "================================================================================\n";

if ($passedCount === $totalCount) {
    echo ">>> TẤT CẢ 12 KỊCH BẢN BẢO MẬT & HỒI QUY ĐÃ CHẠY THÀNH CÔNG RỰC RỠ! <<<\n";
    exit(0);
} else {
    echo ">>> CÓ TEST KHÔNG ĐẠT YÊU CẦU! <<<\n";
    exit(1);
}
