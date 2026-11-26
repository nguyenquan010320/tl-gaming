<?php
/**
 * Bộ kiểm thử Tích hợp Quản lý Khách hàng & Voucher Admin (Prompt 20) cho TL Gaming.
 * Chạy trên CSDL cô lập `tl_gaming_test` (bắt buộc `--confirm-test-db`), không tác động `tl_gaming`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Core/CheckoutException.php';
require_once __DIR__ . '/../app/Models/CategoryModel.php';
require_once __DIR__ . '/../app/Models/ProductModel.php';
require_once __DIR__ . '/../app/Models/ProductUnitModel.php';
require_once __DIR__ . '/../app/Models/InspectionModel.php';
require_once __DIR__ . '/../app/Models/CartModel.php';
require_once __DIR__ . '/../app/Models/CouponModel.php';
require_once __DIR__ . '/../app/Models/OrderModel.php';
require_once __DIR__ . '/../app/Models/AdminOrderModel.php';
require_once __DIR__ . '/../app/Models/AdminCustomerModel.php';
require_once __DIR__ . '/../app/Models/AdminCouponModel.php';

use App\Core\CheckoutException;
use App\Models\AdminCouponModel;
use App\Models\AdminCustomerModel;
use App\Models\AdminOrderModel;
use App\Models\OrderModel;

const CC20_TEST_DB = 'tl_gaming_test';

putenv('DB_DATABASE=' . CC20_TEST_DB);
$_ENV['DB_DATABASE']    = CC20_TEST_DB;
$_SERVER['DB_DATABASE'] = CC20_TEST_DB;

$args = $argv ?? [];

function createCc20Pdo(): PDO
{
    $host     = (string) env('DB_HOST', '127.0.0.1');
    $port     = (int) env('DB_PORT', '3306');
    $username = (string) env('DB_USERNAME', 'root');
    $password = (string) env('DB_PASSWORD', '');

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname=" . CC20_TEST_DB . ';charset=utf8mb4',
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
    return $pdo;
}

// ============================================================================
// WORKER CON CHO CONCURRENCY TRANH LƯỢT VOUCHER CUỐI
// ============================================================================
foreach ($args as $arg) {
    if (str_starts_with($arg, '--worker=checkout-voucher')) {
        $payload = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($payload)) {
            echo json_encode(['ok' => false, 'type' => 'invalid']);
            exit(1);
        }
        if (!empty($payload['start_at_us'])) {
            $wait = (int) $payload['start_at_us'] - (int) (microtime(true) * 1_000_000);
            if ($wait > 0 && $wait < 5_000_000) {
                usleep($wait);
            }
        }
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_id('v-race-' . bin2hex(random_bytes(8)));
                session_start();
            }
            $pdo        = createCc20Pdo();
            $token      = OrderModel::issueIdempotencyToken(null);
            $orderModel = new OrderModel($pdo);
            $res        = $orderModel->createOrderFromCheckout([
                'customer_name'     => (string) $payload['name'],
                'customer_email'    => (string) $payload['email'],
                'customer_phone'    => (string) $payload['phone'],
                'shipping_city'     => 'Hà Nội',
                'shipping_district' => 'Đống Đa',
                'shipping_address'  => '123 Đường Race Voucher',
                'customer_note'     => 'Race voucher',
                'delivery_method'   => 'delivery',
                'payment_method'    => 'cod',
                'coupon_code'       => (string) $payload['coupon_code'],
            ], null, [(int) $payload['product_id'] => 1], [], $token);
            echo json_encode(['ok' => true, 'type' => 'success', 'data' => $res]);
            exit(0);
        } catch (CheckoutException | DomainException $e) {
            echo json_encode(['ok' => false, 'type' => 'business', 'message' => $e->getMessage()]);
            exit(0);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'type' => 'system', 'message' => $e->getMessage()]);
            exit(1);
        }
    }
}

// ============================================================================
// XÁC MINH AN TOÀN DB TEST & RESET
// ============================================================================
if (!in_array('--confirm-test-db', $args, true)) {
    fwrite(STDERR, "Từ chối chạy: Bắt buộc cờ `--confirm-test-db`.\n");
    exit(1);
}
if ((string) ($_ENV['DB_DATABASE'] ?? '') !== CC20_TEST_DB || CC20_TEST_DB === 'tl_gaming') {
    fwrite(STDERR, "AN TOÀN DB: Chỉ cho phép chạy trên `tl_gaming_test`.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$host        = (string) env('DB_HOST', '127.0.0.1');
$port        = (int) env('DB_PORT', '3306');
$username    = (string) env('DB_USERNAME', 'root');
$password    = (string) env('DB_PASSWORD', '');

$rootPdo = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$rootPdo->exec('DROP DATABASE IF EXISTS `' . CC20_TEST_DB . '`');
$rootPdo->exec('CREATE DATABASE `' . CC20_TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$schemaSql = str_replace('`tl_gaming`', '`' . CC20_TEST_DB . '`', (string) file_get_contents($projectRoot . '/database/tl_gaming.sql'));
$seedSql   = str_replace('`tl_gaming`', '`' . CC20_TEST_DB . '`', (string) file_get_contents($projectRoot . '/database/seed_tl_gaming.sql'));

$mysqlBin = file_exists('E:\\xampp\\mysql\\bin\\mysql.exe') ? 'E:\\xampp\\mysql\\bin\\mysql.exe' : 'mysql';
$procEnv  = getenv();
if ($password !== '') {
    $procEnv['MYSQL_PWD'] = $password;
}
$resetProc = proc_open(
    sprintf('"%s" --default-character-set=utf8mb4 -h %s -P %d -u %s', $mysqlBin, escapeshellarg($host), $port, escapeshellarg($username)),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $resetPipes,
    $projectRoot,
    $procEnv
);
fwrite($resetPipes[0], 'USE `' . CC20_TEST_DB . "`;\n" . $schemaSql . "\n" . $seedSql);
fclose($resetPipes[0]);
stream_get_contents($resetPipes[1]);
$resetErr = stream_get_contents($resetPipes[2]);
fclose($resetPipes[1]);
fclose($resetPipes[2]);
if (proc_close($resetProc) !== 0) {
    fwrite(STDERR, "Lỗi reset DB test: {$resetErr}\n");
    exit(1);
}

$results = [];
function recordT20(string $code, string $name, string $status, string $evidence = ''): void
{
    global $results;
    $results[] = ['code' => $code, 'name' => $name, 'status' => $status, 'evidence' => $evidence];
    echo sprintf("%-6s| %-7s | %s\n", $code, $status, $name);
    if ($evidence !== '') {
        echo "      -> Bằng chứng: {$evidence}\n";
    }
}

function startT20Server(string $projectRoot): array
{
    $svrPort    = random_int(19500, 19899);
    $phpBin     = PHP_BINARY ?: 'E:\\xampp\\php\\php.exe';
    $routerFile = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
    $docRoot    = $projectRoot . DIRECTORY_SEPARATOR . 'public';
    $env        = getenv();
    $env['DB_DATABASE'] = CC20_TEST_DB;
    $env['APP_ENV']     = 'testing';

    $cmd  = sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s"', $phpBin, $svrPort, $docRoot, $routerFile);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectRoot, $env);
    $baseUrl = "http://127.0.0.1:{$svrPort}";
    for ($i = 0; $i < 40; $i++) {
        usleep(100_000);
        $ch = curl_init($baseUrl . '/dang-nhap');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code > 0) {
            return ['proc' => $proc, 'pipes' => $pipes, 'baseUrl' => $baseUrl];
        }
    }
    proc_terminate($proc);
    throw new RuntimeException('PHP test server không khởi động được.');
}

function httpReq20(string $method, string $url, ?string $cookieJar = null, mixed $postFields = null): array
{
    $ch   = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
    ];
    if ($cookieJar !== null) {
        $opts[CURLOPT_COOKIEJAR]  = $cookieJar;
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = is_array($postFields) ? http_build_query($postFields) : (string) $postFields;
    }
    curl_setopt_array($ch, $opts);
    $raw        = (string) curl_exec($ch);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body    = substr($raw, $headerSize);
    $loc     = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        $loc = trim($m[1]);
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'location' => $loc];
}

function getCsrf20(string $html): string
{
    if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function login20(string $baseUrl, string $cookieJar, string $email, string $password): bool
{
    $p    = httpReq20('GET', $baseUrl . '/dang-nhap', $cookieJar);
    $csrf = getCsrf20($p['body']);
    $r    = httpReq20('POST', $baseUrl . '/dang-nhap', $cookieJar, [
        '_csrf_token' => $csrf,
        'email'       => $email,
        'password'    => $password,
    ]);
    return $r['status'] === 302;
}

function checkoutOrder20(PDO $pdo, ?int $userId, int $prodId, int $qty, string $couponCode, string $email, string $phone, string $name = 'Khách Test 20'): array
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $token      = OrderModel::issueIdempotencyToken($userId);
    $orderModel = new OrderModel($pdo);
    $sessionCart = $userId === null ? [$prodId => $qty] : [];
    if ($userId !== null) {
        // Thêm trực tiếp vào giỏ DB của user để Checkout đọc đúng
        $cartModel = new \App\Models\CartModel($pdo);
        $cartId    = $cartModel->getOrCreateCartId($userId);
        $pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cid')->execute([':cid' => $cartId]);
        $pdo->prepare('INSERT INTO cart_items (cart_id, product_id, quantity, created_at, updated_at) VALUES (:cid, :pid, :qty, NOW(), NOW())')
            ->execute([':cid' => $cartId, ':pid' => $prodId, ':qty' => $qty]);
    }
    return $orderModel->createOrderFromCheckout([
        'customer_name'     => $name,
        'customer_email'    => $email,
        'customer_phone'    => $phone,
        'shipping_city'     => 'Hà Nội',
        'shipping_district' => 'Đống Đa',
        'shipping_address'  => '123 Đường Test 20',
        'customer_note'     => '',
        'delivery_method'   => 'delivery',
        'payment_method'    => 'cod',
        'coupon_code'       => $couponCode,
    ], $userId, $sessionCart, [], $token);
}

echo "================================================================================\n";
echo "TL GAMING — KIỂM THỬ QUẢN LÝ KHÁCH HÀNG & VOUCHER ADMIN (PROMPT 20)\n";
echo "CSDL test: " . CC20_TEST_DB . "\n";
echo "================================================================================\n\n";

$pdo           = createCc20Pdo();
$customerModel = new AdminCustomerModel($pdo);
$couponModel   = new AdminCouponModel($pdo);
$adminOrder    = new AdminOrderModel($pdo);
$server        = startT20Server($projectRoot);
$baseUrl       = $server['baseUrl'];

$tmpDir       = sys_get_temp_dir();
$guestJar     = tempnam($tmpDir, 't20_guest_');
$custJar      = tempnam($tmpDir, 't20_cust_');
$adminJar     = tempnam($tmpDir, 't20_admin_');

try {
    $passAdmin = password_hash('Admin@123', PASSWORD_BCRYPT);
    $passCust  = password_hash('Customer@123', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at) VALUES ('Admin 20', 'admin20@tlgaming.test', '0900000001', :p1, 'admin', 'active', NOW()), ('Khách <script>x</script>', 'cust20@tlgaming.test', '0900000002', :p2, 'customer', 'active', NOW())")
        ->execute([':p1' => $passAdmin, ':p2' => $passCust]);
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin20@tlgaming.test'")->fetchColumn();
    $custId  = (int) $pdo->query("SELECT id FROM users WHERE email = 'cust20@tlgaming.test'")->fetchColumn();

    login20($baseUrl, $custJar, 'cust20@tlgaming.test', 'Customer@123');
    login20($baseUrl, $adminJar, 'admin20@tlgaming.test', 'Admin@123');

    $qtyProdId = (int) $pdo->query('SELECT id FROM products WHERE has_serials = 0 ORDER BY id ASC LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE products SET stock_quantity = 100 WHERE id = :pid')->execute([':pid' => $qtyProdId]);

    // 1. Guest/Customer bị chặn khỏi /admin/khach-hang và /admin/voucher
    $r1 = httpReq20('GET', $baseUrl . '/admin/khach-hang', $guestJar);
    $r2 = httpReq20('GET', $baseUrl . '/admin/voucher', $guestJar);
    $r3 = httpReq20('GET', $baseUrl . '/admin/khach-hang', $custJar);
    $r4 = httpReq20('GET', $baseUrl . '/admin/voucher', $custJar);
    recordT20('C01', 'Guest và Customer bị chặn HTTP 403 khỏi Admin Customers & Coupons', ($r1['status'] === 403 && $r2['status'] === 403 && $r3['status'] === 403 && $r4['status'] === 403) ? 'PASS' : 'FAIL');

    // 2-3. Admin xem danh sách, tìm kiếm, lọc, phân trang & không trộn Admin
    $rCustList     = httpReq20('GET', $baseUrl . '/admin/khach-hang?q=' . urlencode('cust20@tlgaming.test'), $adminJar);
    $adminInSearch = $customerModel->getAdminCustomers(['keyword' => 'admin20@tlgaming.test']);
    $noAdminInCust = ($adminInSearch === []) && str_contains($rCustList['body'], 'cust20@tlgaming.test');
    recordT20('C02', 'Admin xem danh sách khách hàng HTTP 200, tìm kiếm đúng & không trộn Admin', ($rCustList['status'] === 200 && $noAdminInCust) ? 'PASS' : 'FAIL');

    // 4-6. Tạo 1 đơn completed + 1 đơn cancelled cho custId + 1 đơn Guest trùng email/SĐT
    $oDone = checkoutOrder20($pdo, $custId, $qtyProdId, 2, '', 'cust20@tlgaming.test', '0900000002');
    $adminOrder->confirmOrder((int) $oDone['order_id'], $adminId);
    $adminOrder->shipOrder((int) $oDone['order_id'], $adminId);
    $adminOrder->confirmPayment((int) $oDone['order_id'], $adminId, 'COD-20');
    $adminOrder->completeOrder((int) $oDone['order_id'], $adminId);

    $oCanc = checkoutOrder20($pdo, $custId, $qtyProdId, 1, '', 'cust20@tlgaming.test', '0900000002');
    $adminOrder->cancelOrder((int) $oCanc['order_id'], $adminId, 'Khách hủy test 20');

    // Đơn Guest trùng email/SĐT -> TUYỆT ĐỐI không được gộp vào tài khoản custId
    $oGuest = checkoutOrder20($pdo, null, $qtyProdId, 3, '', 'cust20@tlgaming.test', '0900000002');
    $adminOrder->confirmOrder((int) $oGuest['order_id'], $adminId);
    $adminOrder->shipOrder((int) $oGuest['order_id'], $adminId);
    $adminOrder->confirmPayment((int) $oGuest['order_id'], $adminId, 'COD-GUEST');
    $adminOrder->completeOrder((int) $oGuest['order_id'], $adminId);

    $detail = $customerModel->getCustomerDetail($custId);
    $rCustShow = httpReq20('GET', $baseUrl . '/admin/khach-hang/' . $custId, $adminJar);
    $cStatsOk = (int) $detail['total_orders'] === 2
        && (int) $detail['completed_orders'] === 1
        && (int) $detail['cancelled_orders'] === 1
        && (int) $detail['total_spent'] === (int) $oDone['final_amount'];
    $xssCustSafe = !str_contains($rCustShow['body'], '<script>x</script>') && str_contains($rCustShow['body'], '&lt;script&gt;x&lt;/script&gt;');
    recordT20('C05', 'Thống kê số đơn & chi tiêu chính xác (chỉ đơn completed, không cộng đơn hủy & không gộp Guest)', $cStatsOk ? 'PASS' : 'FAIL', "orders={$detail['total_orders']}, spent={$detail['total_spent']}/{$oDone['final_amount']}");

    // 7-10. Khóa tài khoản, phiên khách bị Auth::revalidate từ chối, mở khóa, chặn khóa Admin/đổi role
    $csrfAdmin = getCsrf20($rCustShow['body']);
    $rLock = httpReq20('POST', $baseUrl . '/admin/khach-hang/' . $custId . '/khoa', $adminJar, [
        '_csrf_token' => $csrfAdmin,
        'role'        => 'admin', // Thử mass-assignment
        'password'    => 'hacked',
    ]);
    $custRowLocked = $pdo->query("SELECT role, status, password_hash FROM users WHERE id = {$custId}")->fetch();
    // Khách đã đăng nhập trước đó thử truy cập /tai-khoan -> bị Auth::revalidate đá về /dang-nhap (302)
    $rCustAfterLock = httpReq20('GET', $baseUrl . '/tai-khoan', $custJar);
    $rUnlock = httpReq20('POST', $baseUrl . '/admin/khach-hang/' . $custId . '/mo-khoa', $adminJar, ['_csrf_token' => $csrfAdmin]);
    $custRowUnlocked = $pdo->query("SELECT status FROM users WHERE id = {$custId}")->fetchColumn();

    $selfLockBlocked = false;
    try {
        $customerModel->updateCustomerStatus($adminId, 'locked', $adminId);
    } catch (DomainException $e) {
        $selfLockBlocked = true;
    }

    recordT20('C07', 'Khóa tài khoản thành công, phiên khách bị từ chối ngay (Auth::revalidate), mở khóa lại bình thường', ($rLock['status'] === 302 && $custRowLocked['status'] === 'locked' && $rCustAfterLock['status'] === 302 && $custRowUnlocked === 'active') ? 'PASS' : 'FAIL', "afterLockHttp={$rCustAfterLock['status']}, unlocked={$custRowUnlocked}");
    recordT20('C10', 'Không đổi role/password qua POST & chặn Admin tự khóa mình', ($custRowLocked['role'] === 'customer' && $custRowLocked['password_hash'] === $passCust && $selfLockBlocked) ? 'PASS' : 'FAIL');

    // 11-16. Tạo voucher hợp lệ & các validation chặn lỗi
    $nowStart = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $nowEnd   = date('Y-m-d H:i:s', strtotime('+7 days'));
    $vId = $couponModel->createCoupon([
        'code'                => 'test20_ok',
        'description'         => 'Voucher test 20',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '100000',
        'max_discount_amount' => '',
        'min_order_amount'    => '500000',
        'usage_limit'         => '5',
        'per_user_limit'      => '2',
        'start_date'          => $nowStart,
        'end_date'            => $nowEnd,
        'is_active'           => '1',
        'usage_count'         => '999', // Phải bị bỏ qua
    ]);
    $vRow = $couponModel->findById($vId);
    recordT20('V11', 'Tạo voucher hợp lệ (code tự viết hoa, usage_count luôn = 0)', ($vRow && $vRow['code'] === 'TEST20_OK' && (int) $vRow['usage_count'] === 0) ? 'PASS' : 'FAIL');

    $badCases = [
        'dup'     => ['code' => 'TEST20_OK', 'discount_type' => 'fixed_amount', 'discount_value' => '50000', 'min_order_amount' => '0', 'usage_limit' => '0', 'per_user_limit' => '1', 'start_date' => $nowStart, 'end_date' => $nowEnd, 'is_active' => '1'],
        'pct101'  => ['code' => 'PCT101', 'discount_type' => 'percentage', 'discount_value' => '101', 'min_order_amount' => '0', 'usage_limit' => '0', 'per_user_limit' => '1', 'start_date' => $nowStart, 'end_date' => $nowEnd, 'is_active' => '1'],
        'val0'    => ['code' => 'VAL0', 'discount_type' => 'fixed_amount', 'discount_value' => '0', 'min_order_amount' => '0', 'usage_limit' => '0', 'per_user_limit' => '1', 'start_date' => $nowStart, 'end_date' => $nowEnd, 'is_active' => '1'],
        'badDate' => ['code' => 'BADDATE', 'discount_type' => 'fixed_amount', 'discount_value' => '50000', 'min_order_amount' => '0', 'usage_limit' => '0', 'per_user_limit' => '1', 'start_date' => $nowEnd, 'end_date' => $nowStart, 'is_active' => '1'],
        'per0'    => ['code' => 'PER0', 'discount_type' => 'fixed_amount', 'discount_value' => '50000', 'min_order_amount' => '0', 'usage_limit' => '0', 'per_user_limit' => '0', 'start_date' => $nowStart, 'end_date' => $nowEnd, 'is_active' => '1'],
    ];
    $allBadBlocked = true;
    foreach ($badCases as $k => $p) {
        try {
            $couponModel->createCoupon($p);
            $allBadBlocked = false;
        } catch (DomainException $e) {
            // Expected
        }
    }
    recordT20('V12', 'Chặn mã trùng, % > 100, tiền <= 0, end < start, per_user_limit < 1', $allBadBlocked ? 'PASS' : 'FAIL');

    // 17. Sửa voucher chưa dùng (được sửa code & discount_value)
    $couponModel->updateCoupon($vId, [
        'code'                => 'TEST20_RENAMED',
        'description'         => 'Đã sửa khi chưa dùng',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '150000',
        'max_discount_amount' => '',
        'min_order_amount'    => '500000',
        'usage_limit'         => '5',
        'per_user_limit'      => '2',
        'start_date'          => $nowStart,
        'end_date'            => $nowEnd,
        'is_active'           => '1',
    ]);
    $vRenamed = $couponModel->findById($vId);
    recordT20('V17', 'Sửa voucher chưa từng dùng thành công (đổi code & giá trị giảm)', ($vRenamed['code'] === 'TEST20_RENAMED' && (int) $vRenamed['discount_value'] === 150000) ? 'PASS' : 'FAIL');

    // 18-26. Áp dụng voucher tại Checkout -> usage_count tăng 1 -> chặn sửa trường nhạy cảm -> tắt voucher -> hủy đơn hoàn lượt -> lịch sử chuẩn
    $oUseV = checkoutOrder20($pdo, null, $qtyProdId, 2, 'TEST20_RENAMED', 'v1@tlgaming.test', '0912345001');
    $vUsed = $couponModel->findById($vId);

    $sensBlocked = false;
    try {
        $couponModel->updateCoupon($vId, [
            'code'                => 'HACK_CODE',
            'description'         => 'Thử đổi code sau khi đã dùng',
            'discount_type'       => 'fixed_amount',
            'discount_value'      => '150000',
            'max_discount_amount' => '',
            'min_order_amount'    => '500000',
            'usage_limit'         => '5',
            'per_user_limit'      => '2',
            'start_date'          => $nowStart,
            'end_date'            => $nowEnd,
            'is_active'           => '1',
        ]);
    } catch (DomainException $e) {
        $sensBlocked = true;
    }

    // Tắt voucher -> Checkout phải từ chối
    $couponModel->toggleCouponActive($vId, 0);
    $disabledRejected = false;
    try {
        checkoutOrder20($pdo, null, $qtyProdId, 2, 'TEST20_RENAMED', 'v2@tlgaming.test', '0912345002');
    } catch (CheckoutException $e) {
        $disabledRejected = true;
    }
    $couponModel->toggleCouponActive($vId, 1);

    // Hủy đơn đã dùng voucher -> hoàn lượt đúng 1 lần -> lịch sử hiển thị 1 total, 0 active, 1 cancelled
    $adminOrder->cancelOrder((int) $oUseV['order_id'], $adminId, 'Hủy đơn kiểm tra hoàn voucher');
    $vAfterCancel = $couponModel->findById($vId);
    $hist         = $couponModel->getCouponUsageHistory($vId, 15, 0);

    recordT20('V18', 'Chặn sửa trường nhạy cảm (code/loại/giá trị) của voucher đã phát sinh sử dụng', $sensBlocked ? 'PASS' : 'FAIL');
    recordT20('V20', 'Tắt voucher -> Checkout từ chối áp dụng ngay lập tức', $disabledRejected ? 'PASS' : 'FAIL');
    recordT20('V24', 'Checkout ghi lượt voucher = 1, hủy đơn hoàn về 0, lịch sử phân biệt rõ used/cancelled', ((int) $vUsed['usage_count'] === 1 && (int) $vAfterCancel['usage_count'] === 0 && $hist['summary']['total_recorded'] === 1 && $hist['summary']['cancelled_refunded'] === 1 && $hist['summary']['total_active_discount'] === 0) ? 'PASS' : 'FAIL');

    // 21. Voucher hết hạn bị Checkout từ chối
    $vExpId = $couponModel->createCoupon([
        'code'                => 'TEST20_EXPIRED',
        'description'         => 'Đã hết hạn',
        'discount_type'       => 'percentage',
        'discount_value'      => '10',
        'max_discount_amount' => '100000',
        'min_order_amount'    => '0',
        'usage_limit'         => '10',
        'per_user_limit'      => '1',
        'start_date'          => date('Y-m-d H:i:s', strtotime('-5 days')),
        'end_date'            => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'is_active'           => '1',
    ]);
    $expRejected = false;
    try {
        checkoutOrder20($pdo, null, $qtyProdId, 1, 'TEST20_EXPIRED', 'vexp@tlgaming.test', '0912345003');
    } catch (CheckoutException $e) {
        $expRejected = true;
    }
    recordT20('V21', 'Voucher hết hạn bị Checkout từ chối', $expRejected ? 'PASS' : 'FAIL');

    // 22-23. Hai khách tranh lượt voucher cuối cùng đồng thời (usage_limit = 1, proc_open)
    $vLastId = $couponModel->createCoupon([
        'code'                => 'TEST20_LAST1',
        'description'         => 'Chỉ còn đúng 1 lượt',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '50000',
        'max_discount_amount' => '',
        'min_order_amount'    => '0',
        'usage_limit'         => '1',
        'per_user_limit'      => '1',
        'start_date'          => $nowStart,
        'end_date'            => $nowEnd,
        'is_active'           => '1',
    ]);
    $phpBin    = PHP_BINARY ?: 'E:\\xampp\\php\\php.exe';
    $startAtUs = (int) (microtime(true) * 1_000_000) + 250_000;
    $procs     = [];
    for ($i = 1; $i <= 2; $i++) {
        $proc = proc_open(sprintf('"%s" "%s" --worker=checkout-voucher', $phpBin, __FILE__), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectRoot);
        fwrite($pipes[0], json_encode([
            'start_at_us' => $startAtUs,
            'product_id'  => $qtyProdId,
            'coupon_code' => 'TEST20_LAST1',
            'name'        => "Race {$i}",
            'email'       => "race{$i}@tlgaming.test",
            'phone'       => "091234509{$i}",
        ]));
        fclose($pipes[0]);
        $procs[] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }
    $raceOk = 0;
    $raceSys = 0;
    foreach ($procs as $p) {
        $out = json_decode(trim((string) stream_get_contents($p['stdout'])), true);
        fclose($p['stdout']);
        fclose($p['stderr']);
        proc_close($p['proc']);
        if (!empty($out['ok'])) {
            $raceOk++;
        } elseif (($out['type'] ?? '') === 'system') {
            $raceSys++;
        }
    }
    $lastUsageCnt = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = {$vLastId}")->fetchColumn();
    recordT20('V23', '2 khách tranh lượt voucher cuối đồng thời: chỉ 1 thành công, usage_count = 1', ($raceOk === 1 && $raceSys === 0 && $lastUsageCnt === 1) ? 'PASS' : 'FAIL', "ok={$raceOk}/2, sys={$raceSys}, usage={$lastUsageCnt}");

    // 28-29. Chặn CSRF, POST dạng mảng bất thường, XSS & SQLi
    $rVList   = httpReq20('GET', $baseUrl . '/admin/voucher?q=' . urlencode("' OR 1=1 --"), $adminJar);
    $rVHist   = httpReq20('GET', $baseUrl . '/admin/voucher/' . $vId . '/lich-su', $adminJar);
    $rNoCsrf  = httpReq20('POST', $baseUrl . '/admin/voucher/them', $adminJar, ['_csrf_token' => 'invalid', 'code' => 'ABC']);
    $rArrPost = httpReq20('POST', $baseUrl . '/admin/voucher/them', $adminJar, http_build_query(['_csrf_token' => $csrfAdmin, 'code' => ['arr']]));
    recordT20('S28', 'Chặn CSRF (403) và dữ liệu POST mảng bất thường (302 an toàn, không 500)', ($rNoCsrf['status'] === 403 && $rArrPost['status'] === 302) ? 'PASS' : 'FAIL');
    recordT20('S29', 'Chặn XSS (HTML escape) & SQL Injection ở Customers/Coupons + trang lịch sử 200 OK', ($xssCustSafe && $rVList['status'] === 200 && $rVHist['status'] === 200) ? 'PASS' : 'FAIL');

    // 30-31 (Prompt 20.1): Kiểm tra chặt validation is_active ('0' / '1' hợp lệ; từ chối 'abc', '-1', mảng)
    $vInactiveId = $couponModel->createCoupon([
        'code'                => 'TEST201_OFF',
        'description'         => 'Voucher khởi tạo ở trạng thái tắt',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '50000',
        'max_discount_amount' => '',
        'min_order_amount'    => '0',
        'usage_limit'         => '10',
        'per_user_limit'      => '1',
        'start_date'          => $nowStart,
        'end_date'            => $nowEnd,
        'is_active'           => '0',
    ]);
    $vInactiveRow     = $couponModel->findById($vInactiveId);
    $offCheckoutBlock = false;
    try {
        checkoutOrder20($pdo, null, $qtyProdId, 1, 'TEST201_OFF', 'off@tlgaming.test', '0912345088');
    } catch (CheckoutException $e) {
        $offCheckoutBlock = true;
    }
    recordT20('V30', 'Tạo voucher is_active="0" thành công và bị Checkout từ chối', ($vInactiveRow && (int) $vInactiveRow['is_active'] === 0 && $offCheckoutBlock) ? 'PASS' : 'FAIL');

    // Từ chối 'abc', '-1', mảng ở Model & HTTP POST, không vô tình bật voucher đang tắt
    $badActiveValues  = ['abc', '-1', ['1']];
    $allBadActBlocked = true;
    foreach ($badActiveValues as $idx => $badAct) {
        try {
            $couponModel->createCoupon([
                'code'                => 'TEST201_BAD_' . $idx,
                'description'         => 'Invalid is_active',
                'discount_type'       => 'fixed_amount',
                'discount_value'      => '50000',
                'max_discount_amount' => '',
                'min_order_amount'    => '0',
                'usage_limit'         => '10',
                'per_user_limit'      => '1',
                'start_date'          => $nowStart,
                'end_date'            => $nowEnd,
                'is_active'           => $badAct,
            ]);
            $allBadActBlocked = false;
        } catch (DomainException $e) {
            // Expected
        }
    }
    // Thử gửi HTTP POST sửa voucher TEST201_OFF với is_active='abc' và is_active[]='1'
    $rBadEdit1 = httpReq20('POST', $baseUrl . '/admin/voucher/' . $vInactiveId . '/sua', $adminJar, [
        '_csrf_token'         => $csrfAdmin,
        'code'                => 'TEST201_OFF',
        'description'         => 'Try bad active',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '50000',
        'max_discount_amount' => '',
        'min_order_amount'    => '0',
        'usage_limit'         => '10',
        'per_user_limit'      => '1',
        'start_date'          => $nowStart,
        'end_date'            => $nowEnd,
        'is_active'           => 'abc',
    ]);
    $rBadEdit2 = httpReq20('POST', $baseUrl . '/admin/voucher/' . $vInactiveId . '/sua', $adminJar, http_build_query([
        '_csrf_token'         => $csrfAdmin,
        'code'                => 'TEST201_OFF',
        'description'         => 'Try array active',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '50000',
        'max_discount_amount' => '',
        'min_order_amount'    => '0',
        'usage_limit'         => '10',
        'per_user_limit'      => '1',
        'start_date'          => $nowStart,
        'end_date'            => $nowEnd,
        'is_active'           => ['1'],
    ]));
    $stillOffAfterBad = (int) $pdo->query("SELECT is_active FROM coupons WHERE id = {$vInactiveId}")->fetchColumn();
    recordT20('V31', 'Từ chối is_active="abc", "-1", mảng & không vô tình bật voucher đang tắt', ($allBadActBlocked && $rBadEdit1['status'] === 302 && $rBadEdit2['status'] === 302 && $stillOffAfterBad === 0) ? 'PASS' : 'FAIL', "still_active={$stillOffAfterBad}");
} finally {
    if (is_resource($server['proc'] ?? null)) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
    foreach ([$guestJar, $custJar, $adminJar] as $jar) {
        if (is_string($jar) && file_exists($jar)) {
            @unlink($jar);
        }
    }
}

$failed = array_filter($results, static fn(array $r): bool => $r['status'] !== 'PASS');
echo "\n================================================================================\n";
echo 'TỔNG KẾT CUSTOMER & COUPON TESTS: PASS=' . (count($results) - count($failed)) . ' | FAIL=' . count($failed) . ' (Tổng ' . count($results) . ")\n";
echo "================================================================================\n";
exit(count($failed) === 0 ? 0 : 1);
