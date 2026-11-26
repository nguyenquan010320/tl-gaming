<?php
/**
 * Bộ kiểm thử Giỏ hàng TL Gaming (Unit + Integration + HTTP Test) — Cô lập CSDL an toàn.
 *
 * Quy tắc an toàn bắt buộc:
 * - Tuyệt đối KHÔNG tác động đến CSDL chính `tl_gaming`.
 * - Chỉ sử dụng CSDL test có hậu tố `_test` (mặc định `tl_gaming_test`).
 * - Chỉ được phép DROP/tạo lại CSDL test khi có cờ `--confirm-test-db` hoặc biến môi trường
 *   `TL_ALLOW_TEST_DB_RESET=1`. Nếu không có xác nhận hoặc thiếu MySQL/cURL -> đánh dấu NOT RUN.
 * - Không ghi thông tin bí mật (mật khẩu CSDL, nội dung .env) ra stdout hoặc log.
 *
 * Cách chạy chuẩn:
 *   $env:TL_ALLOW_TEST_DB_RESET='1'; E:\xampp\php\php.exe scripts/run_cart_tests.php --confirm-test-db
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

// Đọc tham số CLI (--test-db=..., --confirm-test-db)
$cliArgs       = array_slice($argv ?? [], 1);
$confirmReset  = in_array('--confirm-test-db', $cliArgs, true) || getenv('TL_ALLOW_TEST_DB_RESET') === '1';
$testDbName    = 'tl_gaming_test';

foreach ($cliArgs as $arg) {
    if (str_starts_with($arg, '--test-db=')) {
        $testDbName = trim(substr($arg, strlen('--test-db=')));
    }
}

// Chặn tuyệt đối mọi tên CSDL không phải môi trường test
$forbiddenDbs = ['tl_gaming', 'mysql', 'information_schema', 'performance_schema', 'sys'];
if (
    $testDbName === ''
    || in_array(strtolower($testDbName), $forbiddenDbs, true)
    || !preg_match('/^[a-zA-Z0-9_]+_test$/', $testDbName)
) {
    fwrite(STDERR, "[ABORT] Tên CSDL test không hợp lệ hoặc không an toàn (bắt buộc kết thúc bằng '_test').\n");
    exit(1);
}

// Ép biến môi trường DB_DATABASE sang CSDL test trước khi nạp .env
putenv("DB_DATABASE={$testDbName}");
$_ENV['DB_DATABASE']    = $testDbName;
$_SERVER['DB_DATABASE'] = $testDbName;

spl_autoload_register(static function (string $className) use ($projectRoot): void {
    $prefix  = 'App\\';
    $baseDir = $projectRoot . '/app/';
    if (!str_starts_with($className, $prefix)) {
        return;
    }
    $relativeClass = substr($className, strlen($prefix));
    $filePath      = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($filePath)) {
        require_once $filePath;
    }
});

require_once $projectRoot . '/config/env.php';
loadEnvFile($projectRoot . '/.env');

use App\Core\Auth;
use App\Models\CartModel;

$results = [];

function recordResult(string $type, string $id, string $title, string $status, string $evidence): void
{
    global $results;
    $results[] = [
        'type'     => $type,
        'id'       => $id,
        'title'    => $title,
        'status'   => $status, // PASS | FAIL | NOT RUN
        'evidence' => $evidence,
    ];
    echo sprintf("[%-11s] %-5s | %-7s | %s\n              -> Bằng chứng: %s\n", $type, $id, $status, $title, $evidence);
}

/**
 * Khởi tạo CSDL test cô lập an toàn (không tự DROP nếu chưa có xác nhận).
 */
function setupIsolatedTestDatabase(string $projectRoot, string $testDbName, bool $confirmReset): PDO
{
    $host     = (string) env('DB_HOST', '127.0.0.1');
    $port     = (int) env('DB_PORT', 3306);
    $username = (string) env('DB_USERNAME', 'root');
    $password = (string) env('DB_PASSWORD', '');

    $rootPdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $existsStmt = $rootPdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db');
    $existsStmt->execute([':db' => $testDbName]);
    $dbExists = $existsStmt->fetchColumn() !== false;

    if ($dbExists && !$confirmReset) {
        throw new RuntimeException(
            "CSDL '{$testDbName}' đã tồn tại và thiếu cờ xác nhận (--confirm-test-db hoặc TL_ALLOW_TEST_DB_RESET=1). Từ chối tự ý DROP DATABASE."
        );
    }

    if ($dbExists) {
        $rootPdo->exec("DROP DATABASE `{$testDbName}`");
    }
    $rootPdo->exec("CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $schemaSql = file_get_contents($projectRoot . '/database/tl_gaming.sql');
    $seedSql   = file_get_contents($projectRoot . '/database/seed_tl_gaming.sql');
    if ($schemaSql === false || $seedSql === false) {
        throw new RuntimeException('Không thể đọc file SQL trong thư mục database/.');
    }

    $schemaSql = str_replace('`tl_gaming`', "`{$testDbName}`", $schemaSql);
    $seedSql   = str_replace('`tl_gaming`', "`{$testDbName}`", $seedSql);

    $mysqlBin = 'E:\\xampp\\mysql\\bin\\mysql.exe';
    if (!is_file($mysqlBin)) {
        throw new RuntimeException("Không tìm thấy mysql client tại {$mysqlBin}.");
    }

    $env = getenv();
    if ($password !== '') {
        $env['MYSQL_PWD'] = $password;
    }

    $mysqlCmd = sprintf(
        '"%s" --default-character-set=utf8mb4 -h %s -P %d -u %s',
        $mysqlBin,
        escapeshellarg($host),
        $port,
        escapeshellarg($username)
    );

    $proc = proc_open(
        $mysqlCmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $mPipes,
        $projectRoot,
        $env
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('Không thể khởi chạy mysql.exe để nạp CSDL test.');
    }

    fwrite($mPipes[0], $schemaSql . "\n" . $seedSql . "\n");
    fclose($mPipes[0]);
    $stdout = stream_get_contents($mPipes[1]);
    $stderr = stream_get_contents($mPipes[2]);
    fclose($mPipes[1]);
    fclose($mPipes[2]);
    $exitCode = proc_close($proc);

    if ($exitCode !== 0) {
        throw new RuntimeException("Lỗi nạp CSDL test (exit {$exitCode}): {$stderr} {$stdout}");
    }

    $rootPdo->exec("USE `{$testDbName}`");

    // Trên CSDL test cô lập `tl_gaming_test`, cấp biên bản kiểm định 18 bước `passed`
    // cho các máy đang `available` và chưa có biên bản để kiểm thử luồng Giỏ hàng/Serial.
    require_once $projectRoot . '/app/Models/InspectionModel.php';
    $inspModel   = new \App\Models\InspectionModel();
    $adminUserId = (int) ($rootPdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
    $uninspUnits = $rootPdo->query("
        SELECT pu.id
        FROM product_units pu
        WHERE pu.status = 'available'
          AND NOT EXISTS (SELECT 1 FROM product_inspections pi WHERE pi.unit_id = pu.id)
    ")->fetchAll(PDO::FETCH_COLUMN);
    $pass18Steps = [];
    for ($s = 1; $s <= 18; $s++) {
        $pass18Steps[$s] = ['step_number' => $s, 'result' => 'pass', 'notes' => 'Test setup pass'];
    }
    foreach ($uninspUnits as $uId) {
        $inspModel->createInspection((int) $uId, $adminUserId, 'passed', 'Biên bản test Giỏ hàng trên tl_gaming_test', $pass18Steps);
    }

    return $rootPdo;
}

/**
 * Gửi HTTP Request thật bằng cURL kèm Cookie Jar.
 *
 * @return array{status: int, headers: string, body: string, location: string}
 */
function httpRequest(
    string $method,
    string $url,
    string $cookieFile,
    array $postFields = [],
    array $headers = [],
    bool $followLocation = false
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Không thể khởi tạo cURL.');
    }

    $rawHeaders = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => $followLocation,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$rawHeaders): int {
            $rawHeaders .= $headerLine;
            return strlen($headerLine);
        },
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    if ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $location = '';
    if (preg_match('/^Location:\s*(.+)$/im', $rawHeaders, $m)) {
        $location = trim($m[1]);
    }

    return [
        'status'   => $status,
        'headers'  => $rawHeaders,
        'body'     => $body,
        'location' => $location,
    ];
}

function extractCsrfToken(string $html): string
{
    if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/id="cartGlobalCsrfToken"\s+value="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

echo "================================================================================\n";
echo "TL GAMING — KIỂM THỬ GIỎ HÀNG (UNIT + INTEGRATION + HTTP TEST)\n";
echo "CSDL Test cô lập: {$testDbName} (Xác nhận reset: " . ($confirmReset ? 'YES' : 'NO') . ")\n";
echo "Thời gian chạy  : " . date('Y-m-d H:i:s') . "\n";
echo "================================================================================\n\n";

// -----------------------------------------------------------------------------
// UNIT TEST (Không phụ thuộc MySQL / HTTP)
// -----------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    @session_start();
}
$_SESSION = [];
Auth::setPendingGuestCart(2, [1 => 1, 2 => 1]);
$u1OwnerMatch    = Auth::getPendingGuestCart(2) === [1 => 1, 2 => 1];
$u1OwnerMismatch = Auth::getPendingGuestCart(3) === [];
$u1ClearedAfter  = ($_SESSION['pending_guest_cart'] ?? null) === null;
recordResult(
    'UNIT',
    'U1',
    'Cô lập pending_guest_cart theo user_id (chống rò rỉ tài khoản A -> B)',
    ($u1OwnerMatch && $u1OwnerMismatch && $u1ClearedAfter) ? 'PASS' : 'FAIL',
    "owner2Match=" . ($u1OwnerMatch ? 'yes' : 'no') . ", owner3Blocked=" . ($u1OwnerMismatch ? 'yes' : 'no')
);

// -----------------------------------------------------------------------------
// KIỂM TRA MÔI TRƯỜNG MYSQL & CURL TRƯỚC KHI CHẠY INTEGRATION / HTTP TEST
// -----------------------------------------------------------------------------
$envReady = true;
$envError = '';
$pdo      = null;

if (!extension_loaded('curl')) {
    $envReady = false;
    $envError = 'Thiếu PHP extension curl.';
} else {
    try {
        $pdo = setupIsolatedTestDatabase($projectRoot, $testDbName, $confirmReset);
    } catch (Throwable $e) {
        $envReady = false;
        $envError = $e->getMessage();
    }
}

if (!$envReady || $pdo === null) {
    $skippedIds = [
        ['HTTP', 'H1', 'Guest GET /gio-hang trống & không còn checkbox chọn từng phần'],
        ['HTTP', 'H2', 'POST /gio-hang/them sai CSRF bị chặn HTTP 403'],
        ['HTTP', 'H3', 'Guest POST /gio-hang/them (AJAX) thêm sản phẩm ID=2'],
        ['HTTP', 'H4', 'POST /gio-hang/cap-nhat sản phẩm không có trong giỏ bị từ chối'],
        ['HTTP', 'H5', 'POST /gio-hang/cap-nhat chặn quantity=0 và âm'],
        ['HTTP', 'H6', 'POST /gio-hang/cap-nhat vượt tồn kho bị chặn từ server'],
        ['HTTP', 'H7', 'POST /gio-hang/mua-ngay đạt max tồn & không tạo đơn hàng'],
        ['HTTP', 'H8', 'Đăng nhập gặp lỗi hợp nhất MySQL -> giữ giỏ tạm, hiển thị & tự đồng bộ lại không trùng'],
        ['HTTP', 'H9', 'Hợp nhất giỏ xử lý SP vượt tồn kho & SP ẩn/hết hàng'],
        ['HTTP', 'H10', 'Account A đăng xuất -> Account B đăng nhập cùng trình duyệt không rò rỉ'],
        ['HTTP', 'H11', 'Account A xóa SP & xóa tất cả đồng bộ MySQL'],
        ['INTEGRATION', 'I1', 'Kiểm thử đồng thời 5 tiến trình song song addItem() (FOR UPDATE)'],
        ['INTEGRATION', 'I2', 'Giỏ hàng không làm thay đổi products.stock_quantity và product_units.status'],
    ];
    foreach ($skippedIds as [$type, $id, $title]) {
        recordResult($type, $id, $title, 'NOT RUN', $envError);
    }
    exit(0);
}

// Khởi chạy PHP built-in server trên cổng riêng cho HTTP test
$port          = 18099;
$baseUrl       = "http://127.0.0.1:{$port}";
$docRoot       = $projectRoot . '/public';
$serverLogFile = tempnam(sys_get_temp_dir(), 'tlg_srv_');
$cmd           = sprintf('"%s" -S 127.0.0.1:%d -t "%s"', PHP_BINARY, $port, $docRoot);
$spec          = [
    0 => ['pipe', 'r'],
    1 => ['file', $serverLogFile, 'a'],
    2 => ['file', $serverLogFile, 'a'],
];
$serverProc = proc_open($cmd, $spec, $pipes, $projectRoot, null);
if (!is_resource($serverProc)) {
    fwrite(STDERR, "Không thể khởi chạy PHP built-in test server.\n");
    exit(1);
}
if (isset($pipes[0]) && is_resource($pipes[0])) {
    fclose($pipes[0]);
}

$cookieGuest = tempnam(sys_get_temp_dir(), 'tlg_guest_');

try {
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        if (@file_get_contents($baseUrl . '/gio-hang') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('Test server không phản hồi sau 3 giây.');
    }

    // H1: Guest GET /gio-hang trống
    $res1      = httpRequest('GET', $baseUrl . '/gio-hang', $cookieGuest);
    $csrfGuest = extractCsrfToken($res1['body']);
    $h1Pass    = ($res1['status'] === 200)
        && str_contains($res1['body'], 'Giỏ hàng của bạn đang trống')
        && !str_contains($res1['body'], 'cart-item-checkbox')
        && !str_contains($res1['body'], 'cartSelectAllCheckbox')
        && ($csrfGuest !== '');
    recordResult('HTTP', 'H1', 'Guest GET /gio-hang trống & không còn checkbox chọn từng phần', $h1Pass ? 'PASS' : 'FAIL', "HTTP {$res1['status']}, CSRF length=" . strlen($csrfGuest));

    // H2: POST /gio-hang/them sai CSRF -> 403
    $res2 = httpRequest(
        'POST',
        $baseUrl . '/gio-hang/them',
        $cookieGuest,
        ['product_id' => 2, 'quantity' => 1, '_csrf_token' => 'invalid_token'],
        ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']
    );
    recordResult('HTTP', 'H2', 'POST /gio-hang/them sai CSRF bị chặn HTTP 403', $res2['status'] === 403 ? 'PASS' : 'FAIL', "HTTP {$res2['status']}");

    // H3: Guest POST /gio-hang/them (AJAX)
    $res3  = httpRequest(
        'POST',
        $baseUrl . '/gio-hang/them',
        $cookieGuest,
        ['product_id' => 2, 'quantity' => 1, '_csrf_token' => $csrfGuest],
        ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']
    );
    $json3 = json_decode($res3['body'], true) ?: [];
    $h3Pass = ($res3['status'] === 200) && (($json3['success'] ?? false) === true) && (($json3['cartCount'] ?? 0) === 1);
    recordResult('HTTP', 'H3', 'Guest POST /gio-hang/them (AJAX) thêm sản phẩm ID=2', $h3Pass ? 'PASS' : 'FAIL', "HTTP {$res3['status']}, cartCount=" . ($json3['cartCount'] ?? 'null'));

    // H4: Update sản phẩm không có trong giỏ -> 400
    $res4  = httpRequest(
        'POST',
        $baseUrl . '/gio-hang/cap-nhat',
        $cookieGuest,
        ['product_id' => 1, 'quantity' => 1, '_csrf_token' => $csrfGuest],
        ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']
    );
    $json4 = json_decode($res4['body'], true) ?: [];
    $h4Pass = ($res4['status'] === 400) && (($json4['success'] ?? true) === false);
    recordResult('HTTP', 'H4', 'POST /gio-hang/cap-nhat sản phẩm không có trong giỏ bị từ chối', $h4Pass ? 'PASS' : 'FAIL', "HTTP {$res4['status']}");

    // H5: Update quantity=0 và âm -> 400
    $res5a     = httpRequest('POST', $baseUrl . '/gio-hang/cap-nhat', $cookieGuest, ['product_id' => 2, 'quantity' => 0, '_csrf_token' => $csrfGuest], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
    $res5b     = httpRequest('POST', $baseUrl . '/gio-hang/cap-nhat', $cookieGuest, ['product_id' => 2, 'quantity' => -3, '_csrf_token' => $csrfGuest], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
    $res5Check = httpRequest('GET', $baseUrl . '/gio-hang', $cookieGuest);
    $h5Pass    = ($res5a['status'] === 400) && ($res5b['status'] === 400) && str_contains($res5Check['body'], 'cartItemCard_2');
    recordResult('HTTP', 'H5', 'POST /gio-hang/cap-nhat chặn quantity=0 và âm, không xóa item', $h5Pass ? 'PASS' : 'FAIL', "qty=0 HTTP {$res5a['status']}, qty=-3 HTTP {$res5b['status']}");

    // H6: Update vượt tồn kho -> 400
    $res6  = httpRequest('POST', $baseUrl . '/gio-hang/cap-nhat', $cookieGuest, ['product_id' => 2, 'quantity' => 5, '_csrf_token' => $csrfGuest], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
    $json6 = json_decode($res6['body'], true) ?: [];
    $h6Pass = ($res6['status'] === 400) && (($json6['success'] ?? true) === false);
    recordResult('HTTP', 'H6', 'POST /gio-hang/cap-nhat vượt tồn kho bị chặn từ server', $h6Pass ? 'PASS' : 'FAIL', "HTTP {$res6['status']}");

    // H7: Mua ngay lần 1 OK, lần 2 đạt max tồn -> lỗi, không tạo đơn
    $ordersBefore = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $res7a        = httpRequest('POST', $baseUrl . '/gio-hang/mua-ngay', $cookieGuest, ['product_id' => 1, 'quantity' => 1, '_csrf_token' => $csrfGuest], [], false);
    $cart7a       = httpRequest('GET', $baseUrl . '/gio-hang', $cookieGuest);
    $res7b        = httpRequest('POST', $baseUrl . '/gio-hang/mua-ngay', $cookieGuest, ['product_id' => 1, 'quantity' => 1, '_csrf_token' => $csrfGuest], [], false);
    $slugPart     = basename((string) parse_url($res7b['location'], PHP_URL_PATH));
    $res7bPage    = httpRequest('GET', $baseUrl . '/san-pham/' . $slugPart, $cookieGuest);
    $ordersAfter  = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $h7Pass       = str_contains($res7a['location'], '/gio-hang')
        && str_contains($cart7a['body'], 'cartItemCard_1')
        && str_contains($res7b['location'], '/san-pham/')
        && str_contains($res7bPage['body'], 'đạt mức tối đa')
        && ($ordersAfter === $ordersBefore);
    recordResult('HTTP', 'H7', 'POST /gio-hang/mua-ngay kiểm tra đạt max tồn & không tạo đơn hàng', $h7Pass ? 'PASS' : 'FAIL', "orders={$ordersBefore}->{$ordersAfter}");

    // H8: MÔ PHỎNG LỖI HỢP NHẤT CÓ KIỂM SOÁT BẰNG MYSQL TRIGGER (KHÔNG BACKDOOR PRODUCTION)
    //     1. Tạo trigger chặn INSERT vào cart_items trên tl_gaming_test
    //     2. Guest (có p1=1, p2=1) đăng nhập Account A -> merge lỗi -> MySQL vẫn 0 dòng
    //     3. GET /gio-hang vẫn HIỂN THỊ p1 & p2 từ pending_guest_cart
    //     4. Gỡ trigger -> GET /gio-hang tự đồng bộ lại vào MySQL -> F5 không cộng trùng!
    $pdo->exec("DROP TRIGGER IF EXISTS `tg_test_block_cart_insert`");
    $pdo->exec("
        CREATE TRIGGER `tg_test_block_cart_insert`
        BEFORE INSERT ON `cart_items`
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated DB merge failure';
        END
    ");

    $dbCountBeforeLogin = (int) $pdo->query('SELECT COUNT(*) FROM cart_items ci JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = 2')->fetchColumn();

    $loginPage = httpRequest('GET', $baseUrl . '/dang-nhap', $cookieGuest);
    $csrfLogin = extractCsrfToken($loginPage['body']);
    httpRequest(
        'POST',
        $baseUrl . '/dang-nhap',
        $cookieGuest,
        ['email' => 'nguyen.van.an@demo.vn', 'password' => 'Customer@123', '_csrf_token' => $csrfLogin],
        [],
        true
    );

    $dbCountWhileFailed = (int) $pdo->query('SELECT COUNT(*) FROM cart_items ci JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = 2')->fetchColumn();
    $cartWhileFailed    = httpRequest('GET', $baseUrl . '/gio-hang', $cookieGuest);
    $visibleWhileFailed = str_contains($cartWhileFailed['body'], 'cartItemCard_1')
        && str_contains($cartWhileFailed['body'], 'cartItemCard_2')
        && str_contains($cartWhileFailed['body'], 'Đang giữ giỏ hàng tạm');

    // Khôi phục MySQL bình thường (gỡ trigger) -> tự động đồng bộ lại khi vào /gio-hang
    $pdo->exec("DROP TRIGGER IF EXISTS `tg_test_block_cart_insert`");
    $cartResync1 = httpRequest('GET', $baseUrl . '/gio-hang', $cookieGuest);
    $cartResync2 = httpRequest('GET', $baseUrl . '/gio-hang', $cookieGuest); // F5 lần 2 kiểm tra không cộng trùng

    $dbQtyP1 = (int) $pdo->query('SELECT ci.quantity FROM cart_items ci JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = 2 AND ci.product_id = 1')->fetchColumn();
    $dbQtyP2 = (int) $pdo->query('SELECT ci.quantity FROM cart_items ci JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = 2 AND ci.product_id = 2')->fetchColumn();

    $h8Pass = ($dbCountWhileFailed === $dbCountBeforeLogin)
        && $visibleWhileFailed
        && !str_contains($cartResync2['body'], 'Đang giữ giỏ hàng tạm')
        && ($dbQtyP1 === 1 && $dbQtyP2 === 1);
    recordResult(
        'HTTP',
        'H8',
        'Lỗi hợp nhất khi đăng nhập -> giữ & hiển thị giỏ tạm, tự đồng bộ lại không cộng trùng',
        $h8Pass ? 'PASS' : 'FAIL',
        "whileFailedDb={$dbCountBeforeLogin}->{$dbCountWhileFailed}, visible=" . ($visibleWhileFailed ? 'yes' : 'no') . ", afterResyncQty(p1={$dbQtyP1}, p2={$dbQtyP2})"
    );

    // H9: Xử lý vượt tồn kho & sản phẩm ẩn/hết hàng khi hợp nhất giỏ
    $cartModel = new CartModel();
    $cartModel->clearCart(2);
    $cartModel->addItem(2, 14, 7, 10); // Account A đã có 7 sản phẩm ID=14 (tồn 10)
    $mergeCapResult = $cartModel->mergeGuestCartIntoUserCart(2, [14 => 5, 999999 => 2]);
    $dbQtyP14       = (int) $pdo->query('SELECT ci.quantity FROM cart_items ci JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = 2 AND ci.product_id = 14')->fetchColumn();
    $h9Pass         = ($dbQtyP14 === 10) && ($mergeCapResult['adjusted_count'] === 1) && ($mergeCapResult['skipped_count'] === 1);
    recordResult(
        'HTTP',
        'H9',
        'Hợp nhất giỏ chặn vượt tồn kho (7+5->10) & bỏ qua SP không hợp lệ/hết hàng',
        $h9Pass ? 'PASS' : 'FAIL',
        "finalP14={$dbQtyP14}, adjusted={$mergeCapResult['adjusted_count']}, skipped={$mergeCapResult['skipped_count']}"
    );

    // H10: Account A đăng xuất -> Account B đăng nhập cùng trình duyệt -> không rò rỉ giỏ
    $csrfA = extractCsrfToken($cartResync2['body']);
    httpRequest('POST', $baseUrl . '/dang-xuat', $cookieGuest, ['_csrf_token' => $csrfA], [], true);
    $loginPageB = httpRequest('GET', $baseUrl . '/dang-nhap', $cookieGuest);
    httpRequest(
        'POST',
        $baseUrl . '/dang-nhap',
        $cookieGuest,
        ['email' => 'tran.thi.binh@demo.vn', 'password' => 'Customer@123', '_csrf_token' => extractCsrfToken($loginPageB['body'])],
        [],
        true
    );
    $cartB    = httpRequest('GET', $baseUrl . '/gio-hang', $cookieGuest);
    $dbCountB = (int) $pdo->query('SELECT COALESCE(SUM(ci.quantity), 0) FROM cart_items ci JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = 3')->fetchColumn();
    $h10Pass  = str_contains($cartB['body'], 'Giỏ hàng của bạn đang trống') && ($dbCountB === 0);
    recordResult('HTTP', 'H10', 'Account A đăng xuất -> Account B đăng nhập cùng trình duyệt không rò rỉ', $h10Pass ? 'PASS' : 'FAIL', "Account B cart qty={$dbCountB}");

    // H11: Account A xóa SP & xóa tất cả đồng bộ MySQL
    httpRequest('POST', $baseUrl . '/dang-xuat', $cookieGuest, ['_csrf_token' => extractCsrfToken($cartB['body'])], [], true);
    $loginPageA2 = httpRequest('GET', $baseUrl . '/dang-nhap', $cookieGuest);
    httpRequest('POST', $baseUrl . '/dang-nhap', $cookieGuest, ['email' => 'nguyen.van.an@demo.vn', 'password' => 'Customer@123', '_csrf_token' => extractCsrfToken($loginPageA2['body'])], [], true);
    $cartA2 = httpRequest('GET', $baseUrl . '/gio-hang', $cookieGuest);
    $csrfA2 = extractCsrfToken($cartA2['body']);
    $resDel = httpRequest('POST', $baseUrl . '/gio-hang/xoa', $cookieGuest, ['product_id' => 1, '_csrf_token' => $csrfA2], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
    $resClr = httpRequest('POST', $baseUrl . '/gio-hang/xoa-tat-ca', $cookieGuest, ['_csrf_token' => $csrfA2], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
    $dbQtyAfterClear = (int) $pdo->query('SELECT COALESCE(SUM(ci.quantity), 0) FROM cart_items ci JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = 2')->fetchColumn();
    $h11Pass = ($resDel['status'] === 200) && ($resClr['status'] === 200) && ($dbQtyAfterClear === 0);
    recordResult('HTTP', 'H11', 'Account A xóa SP & xóa tất cả đồng bộ MySQL', $h11Pass ? 'PASS' : 'FAIL', "del={$resDel['status']}, clr={$resClr['status']}, dbQty={$dbQtyAfterClear}");

    // I1: Concurrency 5 tiến trình song song addItem()
    $cartModel->clearCart(2);
    $workerFile = tempnam(sys_get_temp_dir(), 'tlg_worker_') . '.php';
    $workerCode = <<<'PHP'
<?php
declare(strict_types=1);
$testDbName  = $argv[1];
$projectRoot = $argv[2];
putenv("DB_DATABASE={$testDbName}");
$_ENV['DB_DATABASE']    = $testDbName;
$_SERVER['DB_DATABASE'] = $testDbName;
require_once $projectRoot . '/config/env.php';
loadEnvFile($projectRoot . '/.env');
spl_autoload_register(static function (string $className) use ($projectRoot): void {
    $prefix  = 'App\\';
    $baseDir = $projectRoot . '/app/';
    if (!str_starts_with($className, $prefix)) {
        return;
    }
    $filePath = $baseDir . str_replace('\\', '/', substr($className, strlen($prefix))) . '.php';
    if (is_file($filePath)) {
        require_once $filePath;
    }
});
$m = new App\Models\CartModel();
$r = $m->addItem(2, 14, 2, 10);
echo json_encode($r);
PHP;
    file_put_contents($workerFile, $workerCode);

    $workers = [];
    $wPipes  = [];
    for ($w = 0; $w < 5; $w++) {
        $workers[$w] = proc_open(
            sprintf('"%s" "%s" %s "%s"', PHP_BINARY, $workerFile, $testDbName, $projectRoot),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $wPipes[$w]
        );
    }
    $workerOk = 0;
    for ($w = 0; $w < 5; $w++) {
        $out = stream_get_contents($wPipes[$w][1]);
        fclose($wPipes[$w][1]);
        fclose($wPipes[$w][2]);
        $exit    = proc_close($workers[$w]);
        $decoded = json_decode((string) $out, true);
        if ($exit === 0 && is_array($decoded) && ($decoded['success'] ?? false) === true) {
            $workerOk++;
        }
    }
    @unlink($workerFile);
    $concurrentQty = (int) $pdo->query('SELECT COALESCE(SUM(ci.quantity), 0) FROM cart_items ci JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = 2 AND ci.product_id = 14')->fetchColumn();
    recordResult('INTEGRATION', 'I1', 'Kiểm thử đồng thời 5 tiến trình song song addItem() (FOR UPDATE)', ($workerOk === 5 && $concurrentQty === 10) ? 'PASS' : 'FAIL', "workerOk={$workerOk}/5, finalQty={$concurrentQty}/10");

    // I2: Xác minh tồn kho & serial không bị thay đổi bởi giỏ hàng
    $stockP1    = (int) $pdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
    $availUnits = (int) $pdo->query("SELECT COUNT(*) FROM product_units WHERE product_id = 1 AND status = 'available'")->fetchColumn();
    recordResult('INTEGRATION', 'I2', 'Giỏ hàng không làm thay đổi products.stock_quantity và product_units.status', ($stockP1 === 1 && $availUnits === 1) ? 'PASS' : 'FAIL', "product_1 stock={$stockP1}, available_units={$availUnits}");
} finally {
    if ($pdo instanceof PDO) {
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS `tg_test_block_cart_insert`");
        } catch (Throwable) {
        }
    }
    proc_terminate($serverProc);
    proc_close($serverProc);
    @unlink($cookieGuest);
    @unlink($serverLogFile);
}

$passCount   = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'PASS'));
$failCount   = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'FAIL'));
$notRunCount = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'NOT RUN'));
$total       = count($results);

echo "\n================================================================================\n";
echo "TỔNG KẾT: PASS={$passCount} | FAIL={$failCount} | NOT RUN={$notRunCount} (Tổng {$total})\n";
echo "================================================================================\n";

exit($failCount === 0 ? 0 : 1);
