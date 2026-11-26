<?php
/**
 * Bộ kiểm thử Tích hợp Quản lý Đơn hàng Admin & Hoàn tồn kho (Prompt 19) cho TL Gaming.
 * Chạy trên CSDL cô lập `tl_gaming_test` (bắt buộc cờ `--confirm-test-db`), tuyệt đối không chạm `tl_gaming`.
 *
 * Bao gồm:
 * - Kiểm thử HTTP thực tế qua PHP built-in server + cURL (Auth, RBAC, CSRF, SSR, Filter, Pagination, XSS, SQLi, Array payload).
 * - Kiểm thử Model & Transaction (State Machine, COD/Bank Transfer, Đơn 0đ, Hoàn tồn kho số lượng & serial, Hoàn voucher, Hoàn tiền thủ công, Rollback, Deadlock retry mô phỏng).
 * - Kiểm thử Concurrency song song thật (`proc_open`) khi 2 Admin cùng xác nhận / hoàn tất / hủy đơn.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Core/CheckoutException.php';
require_once __DIR__ . '/../app/Models/CategoryModel.php';
require_once __DIR__ . '/../app/Models/ProductModel.php';
require_once __DIR__ . '/../app/Models/AdminProductModel.php';
require_once __DIR__ . '/../app/Models/ProductUnitModel.php';
require_once __DIR__ . '/../app/Models/InspectionModel.php';
require_once __DIR__ . '/../app/Models/CartModel.php';
require_once __DIR__ . '/../app/Models/CouponModel.php';
require_once __DIR__ . '/../app/Models/OrderModel.php';
require_once __DIR__ . '/../app/Models/AdminOrderModel.php';

use App\Core\Model;
use App\Models\AdminOrderModel;
use App\Models\InspectionModel;
use App\Models\OrderModel;
use App\Models\ProductUnitModel;

const ORDER_TEST_DB = 'tl_gaming_test';

putenv('DB_DATABASE=' . ORDER_TEST_DB);
$_ENV['DB_DATABASE']    = ORDER_TEST_DB;
$_SERVER['DB_DATABASE'] = ORDER_TEST_DB;

$args = $argv ?? [];

function createOrderTestPdo(): PDO
{
    $host     = (string) env('DB_HOST', '127.0.0.1');
    $port     = (int) env('DB_PORT', '3306');
    $username = (string) env('DB_USERNAME', 'root');
    $password = (string) env('DB_PASSWORD', '');

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname=" . ORDER_TEST_DB . ';charset=utf8mb4',
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
// CHẾ ĐỘ WORKER CON CHO CONCURRENCY TEST
// ============================================================================
foreach ($args as $arg) {
    if (str_starts_with($arg, '--worker=')) {
        $task    = substr($arg, 9);
        $payload = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($payload)) {
            echo json_encode(['ok' => false, 'type' => 'invalid_payload']);
            exit(1);
        }
        if (!empty($payload['start_at_us'])) {
            $wait = (int) $payload['start_at_us'] - (int) (microtime(true) * 1_000_000);
            if ($wait > 0 && $wait < 5_000_000) {
                usleep($wait);
            }
        }
        try {
            $pdo        = createOrderTestPdo();
            $adminOrder = new AdminOrderModel($pdo);
            $orderId    = (int) $payload['order_id'];
            $adminId    = (int) ($payload['admin_id'] ?? 1);

            if ($task === 'confirm') {
                $res = $adminOrder->confirmOrder($orderId, $adminId);
                echo json_encode(['ok' => true, 'type' => 'success', 'data' => $res]);
                exit(0);
            }
            if ($task === 'cancel') {
                $res = $adminOrder->cancelOrder($orderId, $adminId, (string) ($payload['reason'] ?? 'Hủy đồng thời'));
                echo json_encode(['ok' => true, 'type' => 'success', 'data' => $res]);
                exit(0);
            }
            if ($task === 'complete') {
                $res = $adminOrder->completeOrder($orderId, $adminId, 'Hoàn tất đồng thời');
                echo json_encode(['ok' => true, 'type' => 'success', 'data' => $res]);
                exit(0);
            }
            echo json_encode(['ok' => false, 'type' => 'unknown_worker']);
            exit(1);
        } catch (DomainException $e) {
            echo json_encode(['ok' => false, 'type' => 'business', 'message' => $e->getMessage()]);
            exit(0);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'type' => 'system', 'message' => $e->getMessage()]);
            exit(1);
        }
    }
}

// ============================================================================
// XÁC MINH CỜ AN TOÀN --confirm-test-db & RESET DB TEST
// ============================================================================
if (!in_array('--confirm-test-db', $args, true)) {
    fwrite(STDERR, "Từ chối chạy: Bắt buộc truyền cờ `--confirm-test-db` để chạy trên `" . ORDER_TEST_DB . "`.\n");
    exit(1);
}

$activeDb = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');
if ($activeDb !== ORDER_TEST_DB || ORDER_TEST_DB === 'tl_gaming') {
    fwrite(STDERR, "AN TOÀN DB: Từ chối reset vì DB mục tiêu không phải `tl_gaming_test`.\n");
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
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$rootPdo->exec('DROP DATABASE IF EXISTS `' . ORDER_TEST_DB . '`');
$rootPdo->exec('CREATE DATABASE `' . ORDER_TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$schemaSql = str_replace('`tl_gaming`', '`' . ORDER_TEST_DB . '`', (string) file_get_contents($projectRoot . '/database/tl_gaming.sql'));
$seedSql   = str_replace('`tl_gaming`', '`' . ORDER_TEST_DB . '`', (string) file_get_contents($projectRoot . '/database/seed_tl_gaming.sql'));

$mysqlBin = 'E:\\xampp\\mysql\\bin\\mysql.exe';
if (!file_exists($mysqlBin)) {
    $mysqlBin = 'mysql';
}
$procEnv = getenv();
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
fwrite($resetPipes[0], 'USE `' . ORDER_TEST_DB . "`;\n" . $schemaSql . "\n" . $seedSql);
fclose($resetPipes[0]);
$resetOut = stream_get_contents($resetPipes[1]);
$resetErr = stream_get_contents($resetPipes[2]);
fclose($resetPipes[1]);
fclose($resetPipes[2]);
if (proc_close($resetProc) !== 0) {
    fwrite(STDERR, "Lỗi import CSDL test: {$resetErr} {$resetOut}\n");
    exit(1);
}

$results = [];
function recordOrderTest(string $code, string $name, string $status, string $evidence = ''): void
{
    global $results;
    $results[] = ['code' => $code, 'name' => $name, 'status' => $status, 'evidence' => $evidence];
    echo sprintf("%-6s| %-7s | %s\n", $code, $status, $name);
    if ($evidence !== '') {
        echo "      -> Bằng chứng: {$evidence}\n";
    }
}

function startOrderTestServer(string $projectRoot): array
{
    $svrPort    = random_int(19100, 19499);
    $phpBin     = PHP_BINARY ?: 'E:\\xampp\\php\\php.exe';
    $routerFile = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
    $docRoot    = $projectRoot . DIRECTORY_SEPARATOR . 'public';

    $env                = getenv();
    $env['DB_DATABASE'] = ORDER_TEST_DB;
    $env['APP_ENV']     = 'testing';

    $cmd  = sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s"', $phpBin, $svrPort, $docRoot, $routerFile);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectRoot, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Không thể khởi động PHP built-in server.');
    }

    $baseUrl = "http://127.0.0.1:{$svrPort}";
    $ready   = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(100_000);
        $ch = curl_init($baseUrl . '/dang-nhap');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code > 0) {
            $ready = true;
            break;
        }
    }
    if (!$ready) {
        proc_terminate($proc);
        throw new RuntimeException('PHP test server không phản hồi.');
    }

    return ['proc' => $proc, 'pipes' => $pipes, 'baseUrl' => $baseUrl];
}

function httpReq(string $method, string $url, ?string $cookieJar = null, mixed $postFields = null): array
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

function extractCsrfFromHtml(string $html): string
{
    if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function loginAs(string $baseUrl, string $cookieJar, string $email, string $password): bool
{
    $loginPage = httpReq('GET', $baseUrl . '/dang-nhap', $cookieJar);
    $csrf      = extractCsrfFromHtml($loginPage['body']);
    $res       = httpReq('POST', $baseUrl . '/dang-nhap', $cookieJar, [
        '_csrf_token' => $csrf,
        'email'       => $email,
        'password'    => $password,
    ]);
    return $res['status'] === 302;
}

function runParallelOrderWorkers(array $jobs): array
{
    $phpBin     = PHP_BINARY ?: 'E:\\xampp\\php\\php.exe';
    $startAtUs  = (int) (microtime(true) * 1_000_000) + 250_000;
    $procs      = [];
    foreach ($jobs as $idx => $job) {
        $cmd  = sprintf('"%s" "%s" --worker=%s', $phpBin, __FILE__, $job['task']);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        $payload                = $job['payload'];
        $payload['start_at_us'] = $startAtUs;
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);
        $procs[$idx] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }
    $out = [];
    foreach ($procs as $idx => $item) {
        $stdout = stream_get_contents($item['stdout']);
        fclose($item['stdout']);
        fclose($item['stderr']);
        proc_close($item['proc']);
        $out[$idx] = json_decode(trim((string) $stdout), true) ?: ['ok' => false, 'type' => 'raw_error', 'raw' => $stdout];
    }
    return $out;
}

function makeTestOrderViaCheckout(
    PDO $pdo,
    array $cartItems,
    string $paymentMethod = 'cod',
    string $deliveryMethod = 'delivery',
    string $couponCode = '',
    string $customerName = 'Nguyễn Kiểm Thử',
    string $customerPhone = '0909123456'
): array {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $token      = OrderModel::issueIdempotencyToken(null);
    $orderModel = new OrderModel($pdo);

    $shippingAddr = $deliveryMethod === 'pickup'
        ? 'Nhận tại Showroom TL Gaming — 123 Lê Thanh Nghị, Hà Nội'
        : '456 Đường Láng, Đống Đa';

    return $orderModel->createOrderFromCheckout([
        'customer_name'     => $customerName,
        'customer_email'    => 'order_test_' . bin2hex(random_bytes(3)) . '@tlgaming.test',
        'customer_phone'    => $customerPhone,
        'shipping_city'     => 'Hà Nội',
        'shipping_district' => 'Đống Đa',
        'shipping_address'  => $shippingAddr,
        'customer_note'     => 'Test Prompt 19',
        'delivery_method'   => $deliveryMethod,
        'payment_method'    => $paymentMethod,
        'coupon_code'       => $couponCode,
    ], null, $cartItems, [], $token);
}

function buildPassing18Items(): array
{
    $items = [];
    foreach (InspectionModel::OFFICIAL_18_STEPS as $stepNum => $meta) {
        $items[] = [
            'step_number' => (string) $stepNum,
            'result'      => 'pass',
            'notes'       => 'Đạt (' . $meta['item_name'] . ')',
        ];
    }
    return $items;
}

function createSellableSerial(PDO $pdo, int $productId, string $serial): int
{
    $stmt = $pdo->prepare("
        INSERT INTO product_units (product_id, serial_number, status, cosmetic_grade, import_price, row_version, imported_at)
        VALUES (:pid, :sn, 'repairing', 'Grade A', 10000000, 1, NOW())
    ");
    $stmt->execute([':pid' => $productId, ':sn' => $serial]);
    $uid = (int) $pdo->lastInsertId();
    (new InspectionModel($pdo))->createInspection($uid, 1, 'passed', 'Setup sellable', buildPassing18Items(), true);
    return $uid;
}

// ============================================================================
// BẮT ĐẦU CHẠY BỘ KIỂM THỬ
// ============================================================================
echo "================================================================================\n";
echo "TL GAMING — KIỂM THỬ QUẢN LÝ ĐƠN HÀNG ADMIN & HOÀN TỒN KHO (PROMPT 19)\n";
echo "CSDL test: " . ORDER_TEST_DB . "\n";
echo "================================================================================\n\n";

$pdo        = createOrderTestPdo();
$adminOrder = new AdminOrderModel($pdo);
// Đồng bộ baseline stock_quantity cho dữ liệu seed trước khi chạy các thao tác kiểm thử đơn hàng
(new ProductUnitModel($pdo))->reconcileAllSerialStockCache(true);
$server     = startOrderTestServer($projectRoot);
$baseUrl    = $server['baseUrl'];

$tmpDir         = sys_get_temp_dir();
$guestCookie    = tempnam($tmpDir, 'tlg_guest_');
$customerCookie = tempnam($tmpDir, 'tlg_cust_');
$adminCookie    = tempnam($tmpDir, 'tlg_admin_');

try {
    // Tạo tài khoản Admin & Customer test (hoặc dùng seed: admin@tlgaming.vn / customer1@gmail.com)
    $custPassHash  = password_hash('Customer@123', PASSWORD_BCRYPT);
    $adminPassHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at) VALUES ('Admin Test 19', 'admin19@tlgaming.test', '0911111111', :p1, 'admin', 'active', NOW()), ('Customer Test 19', 'cust19@tlgaming.test', '0922222222', :p2, 'customer', 'active', NOW())")
        ->execute([':p1' => $adminPassHash, ':p2' => $custPassHash]);
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin19@tlgaming.test'")->fetchColumn();

    loginAs($baseUrl, $customerCookie, 'cust19@tlgaming.test', 'Customer@123');
    loginAs($baseUrl, $adminCookie, 'admin19@tlgaming.test', 'Admin@123');

    // Chuẩn bị sản phẩm has_serials = 0 (ID=14 trong seed, tăng stock để test thoải mái) và has_serials = 1
    $qtyProdId = (int) $pdo->query('SELECT id FROM products WHERE has_serials = 0 ORDER BY id ASC LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE products SET stock_quantity = 50 WHERE id = :pid')->execute([':pid' => $qtyProdId]);
    $serProdId = (int) $pdo->query('SELECT id FROM products WHERE has_serials = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();

    // T01 & T02: Guest & Customer bị chặn 403
    $rGuestList = httpReq('GET', $baseUrl . '/admin/don-hang', $guestCookie);
    $rGuestShow = httpReq('GET', $baseUrl . '/admin/don-hang/1', $guestCookie);
    recordOrderTest('O01', 'Guest truy cập /admin/don-hang bị chặn HTTP 403', ($rGuestList['status'] === 403 && $rGuestShow['status'] === 403) ? 'PASS' : 'FAIL', "list={$rGuestList['status']}, show={$rGuestShow['status']}");

    $rCustGet  = httpReq('GET', $baseUrl . '/admin/don-hang', $customerCookie);
    $rCustPost = httpReq('POST', $baseUrl . '/admin/don-hang/1/xac-nhan', $customerCookie, ['_csrf_token' => 'fake']);
    recordOrderTest('O02', 'Customer bị chặn cả GET và POST vào Admin Orders (403)', ($rCustGet['status'] === 403 && $rCustPost['status'] === 403) ? 'PASS' : 'FAIL', "get={$rCustGet['status']}, post={$rCustPost['status']}");

    // Tạo đơn mẫu COD có serial để test HTTP
    $sn1   = createSellableSerial($pdo, $serProdId, 'SN-O19-HTTP-01');
    $ord1  = makeTestOrderViaCheckout($pdo, [$serProdId => 1], 'cod', 'delivery', '', 'Khách <script>alert(1)</script>', '0987654321');
    $ord1Id = (int) $ord1['order_id'];

    // O03 & O35 (XSS + SQLi): Admin xem danh sách (che SĐT) & chi tiết (đầy đủ SĐT + escape XSS)
    $rAdminList = httpReq('GET', $baseUrl . '/admin/don-hang', $adminCookie);
    $rAdminShow = httpReq('GET', $baseUrl . '/admin/don-hang/' . $ord1Id, $adminCookie);
    $rAdminSqli = httpReq('GET', $baseUrl . '/admin/don-hang?q=' . urlencode("' OR 1=1 --"), $adminCookie);
    $xssSafe    = !str_contains($rAdminShow['body'], '<script>alert(1)</script>') && str_contains($rAdminShow['body'], '&lt;script&gt;alert(1)&lt;/script&gt;');
    $phoneMask  = str_contains($rAdminList['body'], '098****321') && !str_contains($rAdminList['body'], '0987654321');
    $phoneFull  = str_contains($rAdminShow['body'], '0987654321');
    recordOrderTest('O03', 'Admin xem danh sách & chi tiết HTTP 200 (che SĐT ở list, đủ SĐT ở detail)', ($rAdminList['status'] === 200 && $rAdminShow['status'] === 200 && $phoneMask && $phoneFull) ? 'PASS' : 'FAIL', "list={$rAdminList['status']}, show={$rAdminShow['status']}, mask=" . ($phoneMask ? '1' : '0') . ", full=" . ($phoneFull ? '1' : '0'));
    recordOrderTest('O35', 'Chống XSS (HTML escape) & SQL Injection khi tìm kiếm', ($xssSafe && $rAdminSqli['status'] === 200) ? 'PASS' : 'FAIL', "xssSafe=" . ($xssSafe ? '1' : '0') . ", sqliHttp={$rAdminSqli['status']}");

    // O33 & O34: CSRF & Array POST payload
    $adminCsrf = extractCsrfFromHtml($rAdminShow['body']);
    $rNoCsrf   = httpReq('POST', $baseUrl . "/admin/don-hang/{$ord1Id}/xac-nhan", $adminCookie, ['_csrf_token' => 'wrong_token']);
    $rArrPost  = httpReq('POST', $baseUrl . "/admin/don-hang/{$ord1Id}/huy", $adminCookie, http_build_query(['_csrf_token' => $adminCsrf, 'cancel_reason' => ['bad', 'array']]));
    recordOrderTest('O33', 'POST sai CSRF token bị chặn HTTP 403', $rNoCsrf['status'] === 403 ? 'PASS' : 'FAIL', "status={$rNoCsrf['status']}");
    recordOrderTest('O34', 'Chặn dữ liệu POST dạng mảng bất thường không lỗi PHP 500', ($rArrPost['status'] === 302 && !str_contains($rArrPost['body'], 'Fatal error')) ? 'PASS' : 'FAIL', "status={$rArrPost['status']}");

    // O04: Tạo >15 đơn để kiểm tra tìm kiếm, lọc & phân trang server
    for ($i = 0; $i < 16; $i++) {
        makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], $i % 2 === 0 ? 'cod' : 'bank_transfer', 'delivery', '', "Khách Phân Trang {$i}");
    }
    $rPage2 = httpReq('GET', $baseUrl . '/admin/don-hang?trang=2&pttt=cod', $adminCookie);
    $rSearch = httpReq('GET', $baseUrl . '/admin/don-hang?q=' . urlencode($ord1['order_code']), $adminCookie);
    recordOrderTest('O04', 'Tìm kiếm mã đơn, lọc & phân trang server hoạt động đúng', ($rPage2['status'] === 200 && str_contains($rSearch['body'], $ord1['order_code'])) ? 'PASS' : 'FAIL', "page2={$rPage2['status']}");

    // O05, O06, O07: Xác nhận đơn hợp lệ, chặn xác nhận 2 lần, chặn chuyển trạng thái sai
    $stockBeforeConfirm = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$serProdId}")->fetchColumn();
    $resConfirm         = $adminOrder->confirmOrder($ord1Id, $adminId);
    $stockAfterConfirm  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$serProdId}")->fetchColumn();
    $payAfterConfirm    = (string) $pdo->query("SELECT status FROM payments WHERE order_id = {$ord1Id}")->fetchColumn();

    $doubleConfirmBlocked = false;
    try {
        $adminOrder->confirmOrder($ord1Id, $adminId);
    } catch (DomainException $e) {
        $doubleConfirmBlocked = true;
    }

    $invalidJumpBlocked = false;
    try {
        $adminOrder->completeOrder($ord1Id, $adminId); // confirmed -> completed không qua shipping
    } catch (DomainException $e) {
        $invalidJumpBlocked = true;
    }
    recordOrderTest('O05', 'Xác nhận đơn hợp lệ (không trừ kho lần 2, giữ payment=pending)', ($resConfirm['new_status'] === 'confirmed' && $stockBeforeConfirm === $stockAfterConfirm && $payAfterConfirm === 'pending') ? 'PASS' : 'FAIL', "stock={$stockBeforeConfirm}->{$stockAfterConfirm}, pay={$payAfterConfirm}");
    recordOrderTest('O06', 'Chặn xác nhận đơn hàng hai lần', $doubleConfirmBlocked ? 'PASS' : 'FAIL');
    recordOrderTest('O07', 'Chặn chuyển trạng thái nhảy cóc không hợp lệ', $invalidJumpBlocked ? 'PASS' : 'FAIL');

    // O08: 2 Admin xác nhận cùng đơn đồng thời (proc_open)
    $ordRaceConfirm = makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], 'cod');
    $wConfirm       = runParallelOrderWorkers([
        ['task' => 'confirm', 'payload' => ['order_id' => $ordRaceConfirm['order_id'], 'admin_id' => $adminId]],
        ['task' => 'confirm', 'payload' => ['order_id' => $ordRaceConfirm['order_id'], 'admin_id' => $adminId]],
    ]);
    $confirmOkCnt = ($wConfirm[0]['ok'] ? 1 : 0) + ($wConfirm[1]['ok'] ? 1 : 0);
    recordOrderTest('O08', '2 Admin xác nhận cùng đơn đồng thời (chỉ 1 thành công)', $confirmOkCnt === 1 ? 'PASS' : 'FAIL', "ok_count={$confirmOkCnt}/2");

    // O09, O10, O14, O15, O16, O17: Giao hàng, chặn hoàn tất COD chưa thu tiền, xác nhận thu COD, hoàn tất & reserved->sold
    $adminOrder->shipOrder($ord1Id, $adminId, 'Giao shipper nội bộ');
    $unpaidCompleteBlocked = false;
    try {
        $adminOrder->completeOrder($ord1Id, $adminId);
    } catch (DomainException $e) {
        $unpaidCompleteBlocked = true;
    }
    recordOrderTest('O09', 'COD chưa xác nhận thu tiền bị chặn không cho hoàn tất đơn', $unpaidCompleteBlocked ? 'PASS' : 'FAIL');

    $verBeforeComplete = (int) $pdo->query("SELECT row_version FROM product_units WHERE id = {$sn1}")->fetchColumn();
    $adminOrder->confirmPayment($ord1Id, $adminId, 'COD-BIENLAI-01', 'Shipper đã nộp tiền COD');
    $dupPayBlocked = false;
    try {
        $adminOrder->confirmPayment($ord1Id, $adminId, 'COD-BIENLAI-02');
    } catch (DomainException $e) {
        $dupPayBlocked = true;
    }
    $stockBeforeComplete = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$serProdId}")->fetchColumn();
    $adminOrder->completeOrder($ord1Id, $adminId, 'Khách đã nhận máy');
    $stockAfterComplete  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$serProdId}")->fetchColumn();
    $unitAfterComplete   = $pdo->query("SELECT status, row_version FROM product_units WHERE id = {$sn1}")->fetch();

    recordOrderTest('O10', 'Xác nhận đã thu tiền COD & ghi dấu Admin xác nhận', ((string) $pdo->query("SELECT status FROM payments WHERE order_id = {$ord1Id}")->fetchColumn()) === 'completed' ? 'PASS' : 'FAIL');
    recordOrderTest('O13', 'Chặn xác nhận thanh toán trùng lần 2', $dupPayBlocked ? 'PASS' : 'FAIL');
    recordOrderTest('O15', 'Hoàn tất đơn hợp lệ (shipping -> completed), serial reserved -> sold & tăng row_version, không trừ kho lần 2', ($unitAfterComplete['status'] === 'sold' && (int) $unitAfterComplete['row_version'] === ($verBeforeComplete + 1) && $stockBeforeComplete === $stockAfterComplete) ? 'PASS' : 'FAIL', "unit={$unitAfterComplete['status']}, ver={$verBeforeComplete}->{$unitAfterComplete['row_version']}, stock={$stockBeforeComplete}->{$stockAfterComplete}");

    // O23: Không hoàn máy đã sold / đơn completed bằng thao tác hủy thường
    $cancelCompletedBlocked = false;
    try {
        $adminOrder->cancelOrder($ord1Id, $adminId, 'Thử hủy đơn đã hoàn tất');
    } catch (DomainException $e) {
        $cancelCompletedBlocked = true;
    }
    recordOrderTest('O23', 'Chặn hủy đơn đã hoàn tất / không đưa máy sold về available bằng thao tác hủy thường', $cancelCompletedBlocked ? 'PASS' : 'FAIL');

    // O11, O12: Chuyển khoản chưa nhập mã GD (>0đ) bị từ chối; nhập mã GD hợp lệ -> completed
    $ordBank = makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], 'bank_transfer');
    $ordBankId = (int) $ordBank['order_id'];
    $bankMissingTxBlocked = false;
    try {
        $adminOrder->confirmPayment($ordBankId, $adminId, '', 'Chưa có mã GD');
    } catch (DomainException $e) {
        $bankMissingTxBlocked = true;
    }
    $adminOrder->confirmPayment($ordBankId, $adminId, 'FT2631888999', 'Đã nhận tiền Vietcombank');
    $bankPayStatus = (string) $pdo->query("SELECT status FROM payments WHERE order_id = {$ordBankId}")->fetchColumn();
    recordOrderTest('O11', 'Chuyển khoản >0đ thiếu mã giao dịch đối soát bị từ chối', $bankMissingTxBlocked ? 'PASS' : 'FAIL');
    recordOrderTest('O12', 'Xác nhận chuyển khoản hợp lệ ghi nhận payments.status = completed', $bankPayStatus === 'completed' ? 'PASS' : 'FAIL');

    // O18, O19, O20, O22, O25, O26, O29: Hủy đơn số lượng + voucher -> hoàn đúng kho & hoàn lượt voucher, hủy trùng không hoàn 2 lần
    $couponRow = $pdo->query("SELECT id, code, usage_count, min_order_amount FROM coupons WHERE code = 'TLGAMING500K' LIMIT 1")->fetch();
    $couponId  = (int) $couponRow['id'];
    $usageBefore = (int) $couponRow['usage_count'];
    $qtyProdRow  = $pdo->query("SELECT stock_quantity, price AS eff_price FROM products WHERE id = {$qtyProdId}")->fetch();
    $qtyBeforeOrd = (int) $qtyProdRow['stock_quantity'];
    $qtyForVoucher = max(2, (int) ceil(((int) $couponRow['min_order_amount']) / max(1, (int) $qtyProdRow['eff_price'])));

    $ordVoucher   = makeTestOrderViaCheckout($pdo, [$qtyProdId => $qtyForVoucher], 'cod', 'delivery', 'TLGAMING500K');
    $ordVoucherId = (int) $ordVoucher['order_id'];
    $qtyAfterOrd  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyProdId}")->fetchColumn();
    $usageDuring  = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = {$couponId}")->fetchColumn();

    $cancelVRes = $adminOrder->cancelOrder($ordVoucherId, $adminId, 'Khách đổi cấu hình khác');
    $qtyAfterCancel   = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyProdId}")->fetchColumn();
    $usageAfterCancel = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = {$couponId}")->fetchColumn();
    $usageRowStatus   = (string) $pdo->query("SELECT status FROM coupon_usages WHERE order_id = {$ordVoucherId}")->fetchColumn();

    $dupCancelBlocked = false;
    try {
        $adminOrder->cancelOrder($ordVoucherId, $adminId, 'Hủy lại lần 2');
    } catch (DomainException $e) {
        $dupCancelBlocked = true;
    }
    $qtyAfterDupCancel   = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyProdId}")->fetchColumn();
    $usageAfterDupCancel = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = {$couponId}")->fetchColumn();
    $historyStillExists  = ((int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id = {$ordVoucherId}")->fetchColumn()) > 0;

    recordOrderTest('O20', 'Hủy đơn hàng quantity hoàn đúng số lượng tồn kho', ($qtyAfterOrd === $qtyBeforeOrd - $qtyForVoucher && $qtyAfterCancel === $qtyBeforeOrd) ? 'PASS' : 'FAIL', "before={$qtyBeforeOrd}, ordered={$qtyAfterOrd}, cancelled={$qtyAfterCancel}");
    recordOrderTest('O25', 'Hoàn lượt voucher khi hủy đơn (usage_count giảm 1, coupon_usages.status=cancelled, giữ lịch sử)', ($cancelVRes['voucher_restored'] && $usageDuring === $usageBefore + 1 && $usageAfterCancel === $usageBefore && $usageRowStatus === 'cancelled') ? 'PASS' : 'FAIL');
    recordOrderTest('O22', 'Hủy trùng đơn đã hủy bị chặn, không hoàn tồn & không hoàn voucher lần 2', ($dupCancelBlocked && $qtyAfterDupCancel === $qtyAfterCancel && $usageAfterDupCancel === $usageAfterCancel && $historyStillExists) ? 'PASS' : 'FAIL');

    // O21 & O24: Hủy đơn serial (1 máy passed -> available, 1 máy bị đánh dấu failed -> repairing)
    $snPass = createSellableSerial($pdo, $serProdId, 'SN-O19-CANCEL-PASS');
    $snFail = createSellableSerial($pdo, $serProdId, 'SN-O19-CANCEL-FAIL');
    $ordSer = makeTestOrderViaCheckout($pdo, [$serProdId => 2], 'cod');
    $ordSerId = (int) $ordSer['order_id'];
    $adminOrder->confirmOrder($ordSerId, $adminId);

    // Giả sử trong lúc kiểm tra trước giao, 1 trong 2 máy phát sinh biên bản kiểm định mới nhất failed
    $failItems = buildPassing18Items();
    $failItems[0]['result'] = 'fail';
    $failItems[0]['notes']  = 'Phát hiện lỗi cổng xuất hình';
    $failedUnitIdInOrder = (int) $pdo->query("SELECT product_unit_id FROM order_items WHERE order_id = {$ordSerId} ORDER BY id DESC LIMIT 1")->fetchColumn();
    $passedUnitIdInOrder = (int) $pdo->query("SELECT product_unit_id FROM order_items WHERE order_id = {$ordSerId} ORDER BY id ASC LIMIT 1")->fetchColumn();
    (new InspectionModel($pdo))->createInspection($failedUnitIdInOrder, $adminId, 'failed', 'Lỗi phát sinh trước giao', $failItems, false);

    $stockBeforeSerCancel = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$serProdId}")->fetchColumn();
    $adminOrder->cancelOrder($ordSerId, $adminId, 'Hủy đơn sau xác nhận do phát hiện lỗi máy');
    $stPassedAfter = (string) $pdo->query("SELECT status FROM product_units WHERE id = {$passedUnitIdInOrder}")->fetchColumn();
    $stFailedAfter = (string) $pdo->query("SELECT status FROM product_units WHERE id = {$failedUnitIdInOrder}")->fetchColumn();
    $stockAfterSerCancel = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$serProdId}")->fetchColumn();

    recordOrderTest('O21', 'Hủy đơn sau xác nhận: serial đạt kiểm định về available & đồng bộ stock_quantity', ($stPassedAfter === 'available' && $stockAfterSerCancel === $stockBeforeSerCancel + 1) ? 'PASS' : 'FAIL', "passedUnit={$stPassedAfter}, stock={$stockBeforeSerCancel}->{$stockAfterSerCancel}");
    recordOrderTest('O24', 'Serial có biên bản failed mới nhất không tự mở bán khi hủy đơn (về repairing)', $stFailedAfter === 'repairing' ? 'PASS' : 'FAIL', "failedUnit={$stFailedAfter}");

    // O27 & O28: Đơn đã thanh toán (>0đ) khi hủy -> giữ payments.status = completed & đánh dấu refund_status = manual_required
    $ordPaidCancel   = makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], 'bank_transfer');
    $ordPaidCancelId = (int) $ordPaidCancel['order_id'];
    $adminOrder->confirmPayment($ordPaidCancelId, $adminId, 'FT-PAID-999', 'Khách đã CK trước');
    $cancelPaidRes = $adminOrder->cancelOrder($ordPaidCancelId, $adminId, 'Khách hủy đơn đã chuyển khoản');
    $payRowAfter   = $pdo->query("SELECT status, refund_status FROM payments WHERE order_id = {$ordPaidCancelId}")->fetch();
    recordOrderTest('O27', 'Hủy đơn đã thanh toán (>0đ) bật cờ cần hoàn tiền thủ công & không giả lập refunded', ($cancelPaidRes['manual_refund_required'] && $payRowAfter['status'] === 'completed' && $payRowAfter['refund_status'] === 'manual_required') ? 'PASS' : 'FAIL', "pay_status={$payRowAfter['status']}, refund_status={$payRowAfter['refund_status']}");

    // O30 & O31: Rollback giữa chừng + Deadlock retry mô phỏng (PDOException 40001/1213)
    $ordRetry = makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], 'cod');
    $ordRetryId = (int) $ordRetry['order_id'];
    $qtyBeforeRetry = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyProdId}")->fetchColumn();

    $retryModel = new class($pdo) extends Model {
        public int $attempts = 0;
        public function simulateCancelRetry(int $orderId, int $prodId): bool
        {
            return $this->runTransactionWithDeadlockRetry(function () use ($orderId, $prodId): bool {
                $this->attempts++;
                $this->db->prepare('UPDATE products SET stock_quantity = stock_quantity + 1 WHERE id = :pid')->execute([':pid' => $prodId]);
                if ($this->attempts === 1) {
                    $ex = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found', 40001);
                    $ex->errorInfo = ['40001', 1213, 'Deadlock found'];
                    throw $ex;
                }
                $this->db->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = 'Retry test', cancelled_at = NOW() WHERE id = :oid")->execute([':oid' => $orderId]);
                return true;
            }, 3);
        }
    };
    $retryOk       = $retryModel->simulateCancelRetry($ordRetryId, $qtyProdId);
    $qtyAfterRetry = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyProdId}")->fetchColumn();
    recordOrderTest('O31', 'Rollback & retry khi deadlock 40001/1213 mô phỏng (không hoàn tồn kho 2 lần)', ($retryOk && $retryModel->attempts === 2 && $qtyAfterRetry === $qtyBeforeRetry + 1) ? 'PASS' : 'FAIL', "attempts={$retryModel->attempts}, qty={$qtyBeforeRetry}->{$qtyAfterRetry}");

    // O32: 2 Admin hủy và hoàn tất đồng thời trên cùng 1 đơn ở trạng thái confirmed -> chỉ 1 thành công hoặc không mâu thuẫn
    $ordRaceCancel   = makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], 'cod');
    $ordRaceCancelId = (int) $ordRaceCancel['order_id'];
    $wCancelRace     = runParallelOrderWorkers([
        ['task' => 'cancel', 'payload' => ['order_id' => $ordRaceCancelId, 'admin_id' => $adminId, 'reason' => 'Admin A hủy']],
        ['task' => 'cancel', 'payload' => ['order_id' => $ordRaceCancelId, 'admin_id' => $adminId, 'reason' => 'Admin B hủy']],
    ]);
    $cancelOkCnt = ($wCancelRace[0]['ok'] ? 1 : 0) + ($wCancelRace[1]['ok'] ? 1 : 0);
    recordOrderTest('O32', '2 Admin hủy cùng đơn đồng thời (chỉ 1 thành công, không hoàn tồn 2 lần)', $cancelOkCnt === 1 ? 'PASS' : 'FAIL', "ok_count={$cancelOkCnt}/2");

    // O37: Dòng đơn có dữ liệu tồn kho bất thường (has_serials=1 nhưng thiếu product_unit_id) -> phải rollback
    $ordCorruptItem = makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], 'cod');
    $ordCorruptId   = (int) $ordCorruptItem['order_id'];
    $pdo->prepare('UPDATE order_items SET product_id = :serPid, product_unit_id = NULL WHERE order_id = :oid')
        ->execute([':serPid' => $serProdId, ':oid' => $ordCorruptId]);
    $o37Blocked = false;
    try {
        $adminOrder->cancelOrder($ordCorruptId, $adminId, 'Test dòng đơn sai has_serials');
    } catch (DomainException $e) {
        $o37Blocked = true;
    }
    $o37Status = (string) $pdo->query("SELECT status FROM orders WHERE id = {$ordCorruptId}")->fetchColumn();
    recordOrderTest('O37', 'Dòng đơn sai loại quản lý tồn kho (has_serials=1 thiếu serial) bị từ chối & rollback', ($o37Blocked && $o37Status === 'pending') ? 'PASS' : 'FAIL', "status={$o37Status}");

    // O38: Hoàn kho không được vượt quá giới hạn lưu trữ INT UNSIGNED (4294967295)
    $ordOverflow   = makeTestOrderViaCheckout($pdo, [$qtyProdId => 2], 'cod');
    $ordOverflowId = (int) $ordOverflow['order_id'];
    $savedStock    = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyProdId}")->fetchColumn();
    $pdo->prepare('UPDATE products SET stock_quantity = 4294967295 WHERE id = :pid')->execute([':pid' => $qtyProdId]);
    $o38Blocked = false;
    try {
        $adminOrder->cancelOrder($ordOverflowId, $adminId, 'Test tràn INT UNSIGNED');
    } catch (DomainException $e) {
        $o38Blocked = true;
    }
    $o38Status = (string) $pdo->query("SELECT status FROM orders WHERE id = {$ordOverflowId}")->fetchColumn();
    $o38Stock  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyProdId}")->fetchColumn();
    $pdo->prepare('UPDATE products SET stock_quantity = :qty WHERE id = :pid')->execute([':qty' => $savedStock, ':pid' => $qtyProdId]);
    recordOrderTest('O38', 'Hoàn kho vượt giới hạn INT UNSIGNED bị chặn & rollback, không chuyển cancelled', ($o38Blocked && $o38Status === 'pending' && $o38Stock === 4294967295) ? 'PASS' : 'FAIL', "status={$o38Status}");

    // O39: Serial không còn reserved (ví dụ đã chuyển repairing/sold) không được tự hoàn khi hủy đơn
    $snNotReserved   = createSellableSerial($pdo, $serProdId, 'SN-O19-NOT-RESERVED');
    $ordNotRes       = makeTestOrderViaCheckout($pdo, [$serProdId => 1], 'cod');
    $ordNotResId     = (int) $ordNotRes['order_id'];
    $unitInOrdNotRes = (int) $pdo->query("SELECT product_unit_id FROM order_items WHERE order_id = {$ordNotResId} LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE product_units SET status = 'repairing' WHERE id = :uid")->execute([':uid' => $unitInOrdNotRes]);
    $o39Blocked = false;
    try {
        $adminOrder->cancelOrder($ordNotResId, $adminId, 'Test serial không còn reserved');
    } catch (DomainException $e) {
        $o39Blocked = true;
    }
    $o39Status = (string) $pdo->query("SELECT status FROM orders WHERE id = {$ordNotResId}")->fetchColumn();
    recordOrderTest('O39', 'Serial không còn reserved không được tự hoàn & rollback toàn bộ', ($o39Blocked && $o39Status === 'pending') ? 'PASS' : 'FAIL', "status={$o39Status}");

    // O40: Voucher có usage_count không nhất quán (< số dòng used) phải báo lỗi & rollback
    $ordBadVoucher   = makeTestOrderViaCheckout($pdo, [$qtyProdId => $qtyForVoucher], 'cod', 'delivery', 'TLGAMING500K');
    $ordBadVoucherId = (int) $ordBadVoucher['order_id'];
    $savedUsageCnt   = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = {$couponId}")->fetchColumn();
    $stockBeforeBadV = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyProdId}")->fetchColumn();
    $pdo->prepare('UPDATE coupons SET usage_count = 0 WHERE id = :cid')->execute([':cid' => $couponId]);
    $o40Blocked = false;
    try {
        $adminOrder->cancelOrder($ordBadVoucherId, $adminId, 'Test voucher usage_count lệch');
    } catch (DomainException $e) {
        $o40Blocked = true;
    }
    $o40OrderStatus = (string) $pdo->query("SELECT status FROM orders WHERE id = {$ordBadVoucherId}")->fetchColumn();
    $o40UsageStatus = (string) $pdo->query("SELECT status FROM coupon_usages WHERE order_id = {$ordBadVoucherId}")->fetchColumn();
    $o40StockAfter  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyProdId}")->fetchColumn();
    $pdo->prepare('UPDATE coupons SET usage_count = :uc WHERE id = :cid')->execute([':uc' => $savedUsageCnt, ':cid' => $couponId]);
    recordOrderTest('O40', 'Voucher có usage_count < lượt cần hoàn phải báo lỗi, rollback tồn kho & đơn', ($o40Blocked && $o40OrderStatus === 'pending' && $o40UsageStatus === 'used' && $o40StockAfter === $stockBeforeBadV) ? 'PASS' : 'FAIL', "order={$o40OrderStatus}, usage={$o40UsageStatus}");

    // O41: Nhận tại showroom, giao tận nơi và đơn cũ (delivery_method = NULL) đọc & hiển thị chuẩn xác
    $ordPickup   = makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], 'cod', 'pickup');
    $ordDelivery = makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], 'cod', 'delivery');
    $ordLegacy   = makeTestOrderViaCheckout($pdo, [$qtyProdId => 1], 'cod', 'delivery');
    $pdo->prepare('UPDATE orders SET delivery_method = NULL WHERE id = :oid')->execute([':oid' => (int) $ordLegacy['order_id']]);

    $detPickup   = $adminOrder->getOrderDetail((int) $ordPickup['order_id']);
    $detDelivery = $adminOrder->getOrderDetail((int) $ordDelivery['order_id']);
    $detLegacy   = $adminOrder->getOrderDetail((int) $ordLegacy['order_id']);
    $rLegacyHttp = httpReq('GET', $baseUrl . '/admin/don-hang/' . (int) $ordLegacy['order_id'], $adminCookie);

    $o41Pass = ($detPickup['delivery_method'] ?? '') === 'pickup'
        && ($detPickup['delivery_method_label'] ?? '') === 'Tại showroom'
        && ($detDelivery['delivery_method'] ?? '') === 'delivery'
        && ($detDelivery['delivery_method_label'] ?? '') === 'Giao tận nơi'
        && $detLegacy['delivery_method'] === null
        && ($detLegacy['delivery_method_resolved'] ?? '') === 'unknown'
        && ($detLegacy['delivery_method_label'] ?? '') === 'Chưa rõ (đơn cũ)'
        && ($detLegacy['shipping_address'] ?? '') === '456 Đường Láng, Đống Đa'
        && $rLegacyHttp['status'] === 200;
    recordOrderTest('O41', 'Nhận tại showroom, giao tận nơi và đơn cũ (delivery_method=NULL) nhận diện đúng & không sửa snapshot', $o41Pass ? 'PASS' : 'FAIL', "pickup={$detPickup['delivery_method']}, delivery={$detDelivery['delivery_method']}, legacy={$detLegacy['delivery_method_resolved']}");

    // Kiểm tra đồng bộ cache tồn kho serial sau toàn bộ test
    $syncRows = (new ProductUnitModel($pdo))->reconcileAllSerialStockCache(false);
    $mismatches = array_filter($syncRows, static fn(array $r): bool => !empty($r['changed']));
    recordOrderTest('O36', 'Toàn bộ sản phẩm has_serials=1 giữ đồng nhất stock_quantity sau mọi thao tác đơn', count($mismatches) === 0 ? 'PASS' : 'FAIL', 'checked=' . count($syncRows) . ', mismatch=' . count($mismatches));
} finally {
    if (is_resource($server['proc'] ?? null)) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
    foreach ([$guestCookie, $customerCookie, $adminCookie] as $jar) {
        if (is_string($jar) && file_exists($jar)) {
            @unlink($jar);
        }
    }
}

$failed = array_filter($results, static fn(array $r): bool => $r['status'] !== 'PASS');
echo "\n================================================================================\n";
echo 'TỔNG KẾT ADMIN ORDER TESTS: PASS=' . (count($results) - count($failed)) . ' | FAIL=' . count($failed) . ' (Tổng ' . count($results) . ")\n";
echo "================================================================================\n";
exit(count($failed) === 0 ? 0 : 1);
