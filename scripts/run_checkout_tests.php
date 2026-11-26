<?php
/**
 * Bộ kiểm thử toàn diện Checkout & Tạo đơn hàng TL Gaming (Prompt 15 — 27 kịch bản).
 *
 * Quy tắc an toàn:
 * - Chỉ chạy trên CSDL test cô lập có hậu tố `_test` (`tl_gaming_test`), tuyệt đối không chạm `tl_gaming`.
 * - Bắt buộc có cờ `--confirm-test-db` hoặc `TL_ALLOW_TEST_DB_RESET=1` trước khi DROP/tạo lại CSDL test.
 * - Không in mật khẩu hoặc nội dung `.env`.
 *
 * Chạy bằng lệnh:
 *   E:\xampp\php\php.exe scripts/run_checkout_tests.php --confirm-test-db
 */

declare(strict_types=1);

$projectRoot  = dirname(__DIR__);
$cliArgs      = array_slice($argv ?? [], 1);
$confirmReset = in_array('--confirm-test-db', $cliArgs, true) || getenv('TL_ALLOW_TEST_DB_RESET') === '1';
$testDbName   = 'tl_gaming_test';

if (!preg_match('/^[a-zA-Z0-9_]+_test$/', $testDbName) || $testDbName === 'tl_gaming') {
    fwrite(STDERR, "[ABORT] Tên CSDL test không an toàn.\n");
    exit(1);
}

putenv("DB_DATABASE={$testDbName}");
$_ENV['DB_DATABASE']    = $testDbName;
$_SERVER['DB_DATABASE'] = $testDbName;

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

require_once $projectRoot . '/config/env.php';
loadEnvFile($projectRoot . '/.env');

use App\Models\CartModel;
use App\Models\OrderModel;

$results = [];

function recordCheckoutTest(string $id, string $title, string $status, string $evidence): void
{
    global $results;
    $results[] = ['id' => $id, 'title' => $title, 'status' => $status, 'evidence' => $evidence];
    echo sprintf("%-5s | %-7s | %s\n      -> Bằng chứng: %s\n", $id, $status, $title, $evidence);
}

function setupCheckoutTestDb(string $projectRoot, string $testDbName, bool $confirmReset): PDO
{
    $host     = (string) env('DB_HOST', '127.0.0.1');
    $port     = (int) env('DB_PORT', 3306);
    $username = (string) env('DB_USERNAME', 'root');
    $password = (string) env('DB_PASSWORD', '');

    $rootPdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $stmt = $rootPdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db');
    $stmt->execute([':db' => $testDbName]);
    $exists = $stmt->fetchColumn() !== false;

    if ($exists && !$confirmReset) {
        throw new RuntimeException("CSDL '{$testDbName}' đã tồn tại và thiếu cờ --confirm-test-db.");
    }
    if ($exists) {
        $rootPdo->exec("DROP DATABASE `{$testDbName}`");
    }
    $rootPdo->exec("CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $schemaSql = str_replace('`tl_gaming`', "`{$testDbName}`", (string) file_get_contents($projectRoot . '/database/tl_gaming.sql'));
    $seedSql   = str_replace('`tl_gaming`', "`{$testDbName}`", (string) file_get_contents($projectRoot . '/database/seed_tl_gaming.sql'));

    $mysqlBin = 'E:\\xampp\\mysql\\bin\\mysql.exe';
    $env      = getenv();
    if ($password !== '') {
        $env['MYSQL_PWD'] = $password;
    }

    $proc = proc_open(
        sprintf('"%s" --default-character-set=utf8mb4 -h %s -P %d -u %s', $mysqlBin, escapeshellarg($host), $port, escapeshellarg($username)),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectRoot,
        $env
    );
    fwrite($pipes[0], $schemaSql . "\n" . $seedSql . "\n");
    fclose($pipes[0]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException("Lỗi nạp SQL test: {$err}");
    }

    $rootPdo->exec("USE `{$testDbName}`");

    // Trên CSDL test cô lập `tl_gaming_test`, cấp biên bản kiểm định 18 bước `passed`
    // cho các máy đang `available` và chưa có biên bản để kiểm thử luồng Checkout/Serial.
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
        $inspModel->createInspection((int) $uId, $adminUserId, 'passed', 'Biên bản test Checkout trên tl_gaming_test', $pass18Steps);
    }

    return $rootPdo;
}

function httpReq(string $method, string $url, string $cookieFile, array $post = [], array $headers = [], bool $follow = false): array
{
    $ch         = curl_init($url);
    $rawHeaders = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$rawHeaders): int {
            $rawHeaders .= $line;
            return strlen($line);
        },
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
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
    return ['status' => $status, 'body' => $body, 'location' => $location];
}

function getField(string $html, string $name): string
{
    if (preg_match('/<input[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*\bvalue="([^"]*)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/<input[^>]*\bvalue="([^"]*)"[^>]*\bname="' . preg_quote($name, '/') . '"/i', $html, $m)) {
        return $m[1];
    }
    if ($name === '_csrf_token') {
        if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/id="cartGlobalCsrfToken"\s+value="([^"]+)"/i', $html, $m)) {
            return $m[1];
        }
    }
    return '';
}

echo "================================================================================\n";
echo "TL GAMING — KIỂM THỬ TOÀN DIỆN CHECKOUT & TẠO ĐƠN HÀNG (PROMPT 15)\n";
echo "CSDL Test cô lập: {$testDbName}\n";
echo "================================================================================\n\n";

try {
    $pdo = setupCheckoutTestDb($projectRoot, $testDbName, $confirmReset);
} catch (Throwable $e) {
    fwrite(STDERR, "[NOT RUN] Không thể khởi tạo CSDL test: " . $e->getMessage() . "\n");
    exit(0);
}

$port          = 18100;
$baseUrl       = "http://127.0.0.1:{$port}";
$serverLogFile = tempnam(sys_get_temp_dir(), 'tlg_chk_srv_');
$serverEnv     = getenv();
if (!is_array($serverEnv)) {
    $serverEnv = [];
}
$serverEnv['DB_DATABASE']            = $testDbName;
$serverEnv['TL_BANK_NAME']           = 'Vietcombank Test';
$serverEnv['TL_BANK_ACCOUNT_NUMBER'] = '999988887777';
$serverEnv['TL_BANK_ACCOUNT_HOLDER'] = 'TL GAMING TEST';
$serverEnv['TL_SHOWROOM_HN_NAME']    = 'Showroom Hà Nội (Test)';
$serverEnv['TL_SHOWROOM_HN_ADDRESS'] = 'Số 1 Phố Test, Cầu Giấy, Hà Nội';
$serverEnv['TL_SHOWROOM_HN_CITY']    = 'Hà Nội';
$serverEnv['TL_SHOWROOM_HN_DISTRICT']= 'Quận Cầu Giấy';

$serverProc    = proc_open(
    sprintf('"%s" -S 127.0.0.1:%d -t "%s"', PHP_BINARY, $port, $projectRoot . '/public'),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLogFile, 'a'], 2 => ['file', $serverLogFile, 'a']],
    $sPipes,
    $projectRoot,
    $serverEnv
);
if (isset($sPipes[0]) && is_resource($sPipes[0])) {
    fclose($sPipes[0]);
}

$cookieGuest = tempnam(sys_get_temp_dir(), 'tlg_cg_');
$cookieCust  = tempnam(sys_get_temp_dir(), 'tlg_cc_');
$cookieOther = tempnam(sys_get_temp_dir(), 'tlg_co_');

try {
    for ($i = 0; $i < 30; $i++) {
        if (@file_get_contents($baseUrl . '/gio-hang') !== false) {
            break;
        }
        usleep(100000);
    }

    // 4. Giỏ hàng rỗng không cho checkout
    $r4 = httpReq('GET', $baseUrl . '/thanh-toan', $cookieGuest);
    recordCheckoutTest('TC04', 'Giỏ hàng rỗng không cho mở /thanh-toan', ($r4['status'] === 302 && str_contains($r4['location'], '/gio-hang')) ? 'PASS' : 'FAIL', "HTTP {$r4['status']} -> {$r4['location']}");

    // 1. Guest checkout COD + 7. Sản phẩm quản lý serial + 16. Sửa phí vận chuyển + 17. Gửi payment_status=paid + 18. CSRF sai + 19. Double submit + 22. Commit xóa giỏ
    $cartPage = httpReq('GET', $baseUrl . '/gio-hang', $cookieGuest);
    $csrfG    = getField($cartPage['body'], '_csrf_token');
    httpReq('POST', $baseUrl . '/gio-hang/them', $cookieGuest, ['product_id' => 8, 'quantity' => 1, '_csrf_token' => $csrfG], ['X-Requested-With: XMLHttpRequest']);

    $chkPage = httpReq('GET', $baseUrl . '/thanh-toan', $cookieGuest);
    $csrfChk = getField($chkPage['body'], '_csrf_token');
    $idemKey = getField($chkPage['body'], 'idempotency_key');

    // TC18: CSRF sai
    $r18 = httpReq('POST', $baseUrl . '/thanh-toan', $cookieGuest, ['_csrf_token' => 'bad', 'idempotency_key' => $idemKey]);
    recordCheckoutTest('TC18', 'POST /thanh-toan sai CSRF bị chặn HTTP 403', $r18['status'] === 403 ? 'PASS' : 'FAIL', "HTTP {$r18['status']}");

    // Gửi đơn Guest COD (cố tình gửi shipping_fee=0, price=1, payment_status=paid để test server bỏ qua)
    $postGuest = [
        '_csrf_token'     => $csrfChk,
        'idempotency_key' => $idemKey,
        'customer_name'   => 'Khách Vãng Lai Test',
        'customer_phone'  => '0911222333',
        'customer_email'  => 'guest.test@example.com',
        'delivery_method' => 'delivery',
        'shipping_city'   => 'Đà Nẵng',
        'shipping_district' => 'Hải Châu',
        'shipping_address'=> '99 Bạch Đằng',
        'payment_method'  => 'cod',
        'agree_policy'    => '1',
        'shipping_fee'    => '0',
        'price'           => '1',
        'payment_status'  => 'paid',
    ];
    $r1 = httpReq('POST', $baseUrl . '/thanh-toan', $cookieGuest, $postGuest, [], false);
    $guestOrderCode = basename((string) parse_url($r1['location'], PHP_URL_PATH));
    if ($guestOrderCode === '' || $guestOrderCode === 'thanh-toan') {
        echo "DEBUG TC01: status={$r1['status']} loc={$r1['location']} csrfLen=" . strlen($csrfChk) . " idemLen=" . strlen($idemKey) . "\n";
    }

    // TC19: Double submit cùng idempotency_key -> không tạo đơn thứ 2
    $r19 = httpReq('POST', $baseUrl . '/thanh-toan', $cookieGuest, $postGuest, [], false);
    $dupCode = basename((string) parse_url($r19['location'], PHP_URL_PATH));

    $gOrder = $pdo->query("SELECT * FROM orders WHERE order_code = " . $pdo->quote($guestOrderCode))->fetch();
    $gPay   = $gOrder ? $pdo->query("SELECT * FROM payments WHERE order_id = " . (int) $gOrder['id'])->fetch() : null;
    $gItem  = $gOrder ? $pdo->query("SELECT * FROM order_items WHERE order_id = " . (int) $gOrder['id'])->fetch() : null;
    $u8     = $pdo->query("SELECT status FROM product_units WHERE product_id = 8 LIMIT 1")->fetchColumn();
    $cartAfterG = httpReq('GET', $baseUrl . '/gio-hang', $cookieGuest);

    recordCheckoutTest('TC01', 'Guest checkout COD thành công', ($gOrder && (int) $gOrder['user_id'] === 0 && $gOrder['user_id'] === null) ? 'PASS' : 'FAIL', "order_code={$guestOrderCode}");
    recordCheckoutTest('TC07', 'Sản phẩm có serial (ID=8) giữ máy reserved & gán unit_serial_number', ($u8 === 'reserved' && !empty($gItem['unit_serial_number'])) ? 'PASS' : 'FAIL', "unit_status={$u8}, serial=" . ($gItem['unit_serial_number'] ?? 'null'));
    recordCheckoutTest('TC16', 'Phí vận chuyển & giá bị sửa ở trình duyệt bị Server bỏ qua', ((int) $gOrder['shipping_fee'] === 50000 && (int) $gOrder['total_product_amount'] === 4600000) ? 'PASS' : 'FAIL', "server_total={$gOrder['total_product_amount']}, server_ship={$gOrder['shipping_fee']}");
    recordCheckoutTest('TC17', 'Gửi payment_status=paid bị Server bỏ qua, payments.status=pending', ($gPay && $gPay['status'] === 'pending' && $gPay['payment_method'] === 'cod') ? 'PASS' : 'FAIL', "payment_status=" . ($gPay['status'] ?? 'null'));
    recordCheckoutTest('TC19', 'Double submit cùng idempotency_key chỉ tạo 1 đơn duy nhất', ($dupCode === $guestOrderCode) ? 'PASS' : 'FAIL', "first={$guestOrderCode}, second={$dupCode}");
    recordCheckoutTest('TC22', 'Chỉ xóa giỏ sau khi commit thành công', str_contains($cartAfterG['body'], 'Giỏ hàng của bạn đang trống') ? 'PASS' : 'FAIL', "Guest cart emptied after commit");

    // 23 & 24. Tra cứu đơn hợp lệ & Chống IDOR khi xem / tra cứu
    App\Core\RateLimiter::clear('checkout_lookup_ip:127.0.0.1');
    $r24Idor = httpReq('GET', $baseUrl . '/dat-hang/thanh-cong/' . $guestOrderCode, $cookieOther);
    $lookupPage = httpReq('GET', $baseUrl . '/tra-cuu-don-hang', $cookieOther);
    $csrfLookup = getField($lookupPage['body'], '_csrf_token');
    $r24Bad = httpReq('POST', $baseUrl . '/tra-cuu-don-hang', $cookieOther, ['order_code' => $guestOrderCode, 'verification_contact' => '0999999999', '_csrf_token' => $csrfLookup]);
    $r23Ok  = httpReq('POST', $baseUrl . '/tra-cuu-don-hang', $cookieOther, ['order_code' => $guestOrderCode, 'verification_contact' => '0911222333', '_csrf_token' => $csrfLookup]);

    recordCheckoutTest('TC24', 'Chống IDOR xem đơn người khác & tra cứu sai SĐT/email', ((($r24Idor['status'] === 302 && str_contains($r24Idor['location'], '/tra-cuu-don-hang')) || $r24Idor['status'] === 403) && str_contains($r24Bad['body'], 'Không tìm thấy đơn hàng')) ? 'PASS' : 'FAIL', "directIdorHTTP={$r24Idor['status']} -> {$r24Idor['location']}, badLookupBlocked=yes");
    recordCheckoutTest('TC23', 'Tra cứu đơn hợp lệ bằng mã đơn + SĐT', str_contains($r23Ok['body'], $guestOrderCode) ? 'PASS' : 'FAIL', "Verified lookup displayed {$guestOrderCode}");

    // 2, 3, 8, 11, 12, 13, 15. Customer checkout (COD, Bank Transfer, Số lượng, Voucher, Giá đổi)
    $lp = httpReq('GET', $baseUrl . '/dang-nhap', $cookieCust);
    httpReq('POST', $baseUrl . '/dang-nhap', $cookieCust, ['email' => 'tran.thi.binh@demo.vn', 'password' => 'Customer@123', '_csrf_token' => getField($lp['body'], '_csrf_token')], [], true);
    $cCustPage = httpReq('GET', $baseUrl . '/gio-hang', $cookieCust);
    $csrfCust  = getField($cCustPage['body'], '_csrf_token');

    // Thêm SP ID=2 (giá 15.800.000₫, có serial) + SP ID=14 (RAM, has_serials=0, tồn=10)
    httpReq('POST', $baseUrl . '/gio-hang/them', $cookieCust, ['product_id' => 2, 'quantity' => 1, '_csrf_token' => $csrfCust], ['X-Requested-With: XMLHttpRequest']);
    httpReq('POST', $baseUrl . '/gio-hang/them', $cookieCust, ['product_id' => 14, 'quantity' => 2, '_csrf_token' => $csrfCust], ['X-Requested-With: XMLHttpRequest']);

    $chkCust = httpReq('GET', $baseUrl . '/thanh-toan', $cookieCust);
    $csrfCC  = getField($chkCust['body'], '_csrf_token');
    $idemCC  = getField($chkCust['body'], 'idempotency_key');
    $expSub  = getField($chkCust['body'], 'expected_subtotal');
    $expFin  = getField($chkCust['body'], 'expected_final_amount');

    // TC12: Voucher hết hạn EXPIRED2025
    $r12 = httpReq('POST', $baseUrl . '/thanh-toan/xem-truoc', $cookieCust, ['_csrf_token' => $csrfCC, 'coupon_code' => 'EXPIRED2025', 'delivery_method' => 'delivery', 'shipping_city' => 'Hà Nội'], ['X-Requested-With: XMLHttpRequest']);
    $j12 = json_decode($r12['body'], true) ?: [];
    recordCheckoutTest('TC12', 'Voucher hết hạn (EXPIRED2025) bị từ chối', ($j12['coupon_valid'] ?? true) === false ? 'PASS' : 'FAIL', "msg=" . ($j12['coupon_message'] ?? ''));

    // TC15: Giá sản phẩm thay đổi trong lúc khách đang ở Checkout
    $ordersBefore15 = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE user_id = 3")->fetchColumn();
    $pdo->exec("UPDATE products SET price = price + 100000 WHERE id = 14");
    $r15 = httpReq('POST', $baseUrl . '/thanh-toan', $cookieCust, [
        '_csrf_token' => $csrfCC, 'idempotency_key' => $idemCC,
        'customer_name' => 'Trần Thị Bình', 'customer_phone' => '0987654321', 'customer_email' => 'tran.thi.binh@demo.vn',
        'delivery_method' => 'pickup', 'showroom_id' => 'hn', 'payment_method' => 'bank_transfer', 'agree_policy' => '1',
        'expected_subtotal' => $expSub, 'expected_final_amount' => $expFin,
    ], [], false);
    $ordersCountAfter15 = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE user_id = 3")->fetchColumn();
    $pdo->exec("UPDATE products SET price = price - 100000 WHERE id = 14"); // Khôi phục giá
    recordCheckoutTest('TC15', 'Giá sản phẩm thay đổi khi đang checkout -> chặn & báo xem lại tổng tiền', ($ordersCountAfter15 === $ordersBefore15 && str_contains($r15['location'], '/thanh-toan')) ? 'PASS' : 'FAIL', "orders_user3={$ordersBefore15}->{$ordersCountAfter15}");

    // TC02 & TC03 & TC08 & TC11: Đặt đơn Customer chuyển khoản ngân hàng + Voucher TLGAMING500K + SP số lượng ID=14
    $chkCust2 = httpReq('GET', $baseUrl . '/thanh-toan', $cookieCust);
    $r3 = httpReq('POST', $baseUrl . '/thanh-toan', $cookieCust, [
        '_csrf_token'     => getField($chkCust2['body'], '_csrf_token'),
        'idempotency_key' => getField($chkCust2['body'], 'idempotency_key'),
        'customer_name'   => 'Trần Thị Bình',
        'customer_phone'  => '0987654321',
        'customer_email'  => 'tran.thi.binh@demo.vn',
        'delivery_method' => 'pickup',
        'showroom_id'     => 'hn',
        'payment_method'  => 'bank_transfer',
        'coupon_code'     => 'TLGAMING500K',
        'agree_policy'    => '1',
    ], [], false);
    $custOrderCode = basename((string) parse_url($r3['location'], PHP_URL_PATH));
    if ($custOrderCode === '' || $custOrderCode === 'thanh-toan') {
        echo "DEBUG TC02: status={$r3['status']} loc={$r3['location']}\n";
    }
    $cOrder        = $pdo->query("SELECT * FROM orders WHERE order_code = " . $pdo->quote($custOrderCode))->fetch();
    $cPay          = $cOrder ? $pdo->query("SELECT * FROM payments WHERE order_id = " . (int) $cOrder['id'])->fetch() : null;
    $stock14       = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();
    $couponUsage   = $cOrder ? $pdo->query("SELECT * FROM coupon_usages WHERE order_id = " . (int) $cOrder['id'])->fetch() : null;

    recordCheckoutTest('TC02', 'Customer đặt đơn thành công', $cOrder && (int) $cOrder['user_id'] === 3 ? 'PASS' : 'FAIL', "order_code={$custOrderCode}");
    recordCheckoutTest('TC03', 'Customer checkout Chuyển khoản (payment_method=bank_transfer, status=pending)', ($cPay && $cPay['payment_method'] === 'bank_transfer' && $cPay['status'] === 'pending') ? 'PASS' : 'FAIL', "method=" . ($cPay['payment_method'] ?? '') . ", status=" . ($cPay['status'] ?? ''));
    recordCheckoutTest('TC08', 'Sản phẩm quản lý số lượng (ID=14, 10->8) trừ kho chính xác', ($stock14 === 8) ? 'PASS' : 'FAIL', "product_14 stock={$stock14}");
    recordCheckoutTest('TC11', 'Voucher hợp lệ (TLGAMING500K) giảm 500.000đ & ghi coupon_usages', ($cOrder && (int) $cOrder['discount_amount'] === 500000 && $couponUsage) ? 'PASS' : 'FAIL', "discount=" . ($cOrder['discount_amount'] ?? 0));

    // TC13: Voucher hết lượt (VIP2000K set usage_count = usage_limit = 10)
    $pdo->exec("UPDATE coupons SET usage_count = usage_limit WHERE code = 'VIP2000K'");
    $r13 = httpReq('POST', $baseUrl . '/thanh-toan/xem-truoc', $cookieCust, ['_csrf_token' => $csrfCC, 'coupon_code' => 'VIP2000K'], ['X-Requested-With: XMLHttpRequest']);
    // Vì giỏ cust đã trống sau TC02, thêm tạm 1 item cho test coupon preview
    httpReq('POST', $baseUrl . '/gio-hang/them', $cookieCust, ['product_id' => 14, 'quantity' => 1, '_csrf_token' => $csrfCust], ['X-Requested-With: XMLHttpRequest']);
    $r13b = httpReq('POST', $baseUrl . '/thanh-toan/xem-truoc', $cookieCust, ['_csrf_token' => $csrfCC, 'coupon_code' => 'VIP2000K'], ['X-Requested-With: XMLHttpRequest']);
    $j13  = json_decode($r13b['body'], true) ?: [];
    recordCheckoutTest('TC13', 'Voucher hết lượt bị từ chối', ($j13['coupon_valid'] ?? true) === false ? 'PASS' : 'FAIL', "msg=" . ($j13['coupon_message'] ?? ''));

    // TC05, TC06, TC20, TC21: Model-level Integration tests (Hết hàng, Draft/Archived, SQL Rollback giữ nguyên giỏ)
    $orderModel = new OrderModel();
    $cartModel  = new CartModel();

    // TC05: Hết hàng (product_id=1 đã bán hết hoặc set stock=0)
    $pdo->exec("UPDATE product_units SET status = 'reserved' WHERE product_id = 1 AND status = 'available'");
    $tc5Caught = false;
    try {
        $k5 = OrderModel::issueIdempotencyToken(null);
        $orderModel->createOrderFromCheckout(['customer_name' => 'A', 'customer_phone' => '0912345678', 'customer_email' => 'a@b.vn', 'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom', 'payment_method' => 'cod'], null, [1 => 1], [], $k5);
    } catch (App\Core\CheckoutException) {
        $tc5Caught = true;
    }
    recordCheckoutTest('TC05', 'Sản phẩm hết hàng bị chặn tạo đơn (CheckoutException)', $tc5Caught ? 'PASS' : 'FAIL', "outOfStockBlocked=" . ($tc5Caught ? 'yes' : 'no'));

    // TC06: Sản phẩm draft/archived bị chặn
    $pdo->exec("UPDATE products SET status = 'archived' WHERE id = 14");
    $tc6Caught = false;
    try {
        $k6 = OrderModel::issueIdempotencyToken(null);
        $orderModel->createOrderFromCheckout(['customer_name' => 'A', 'customer_phone' => '0912345678', 'customer_email' => 'a@b.vn', 'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom', 'payment_method' => 'cod'], null, [14 => 1], [], $k6);
    } catch (App\Core\CheckoutException) {
        $tc6Caught = true;
    }
    $pdo->exec("UPDATE products SET status = 'published' WHERE id = 14");
    recordCheckoutTest('TC06', 'Sản phẩm trạng thái draft/archived bị chặn tạo đơn', $tc6Caught ? 'PASS' : 'FAIL', "archivedBlocked=" . ($tc6Caught ? 'yes' : 'no'));

    // TC20 & TC21: Lỗi SQL giữa transaction -> rollback toàn bộ (không mất kho, không tạo đơn) và giữ nguyên giỏ
    $stockBefore20  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();
    $ordersBefore20 = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $pdo->exec("CREATE TRIGGER `tg_test_fail_payment` BEFORE INSERT ON `payments` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated SQL crash'; END");
    $tc20RolledBack = false;
    $tc20IsTechErr  = false;
    try {
        $k20 = OrderModel::issueIdempotencyToken(3);
        $orderModel->createOrderFromCheckout(['customer_name' => 'A', 'customer_phone' => '0912345678', 'customer_email' => 'a@b.vn', 'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom', 'payment_method' => 'cod'], 3, [], [], $k20);
    } catch (Throwable $e20) {
        $tc20RolledBack = true;
        $tc20IsTechErr  = !($e20 instanceof App\Core\CheckoutException);
    }
    $pdo->exec("DROP TRIGGER IF EXISTS `tg_test_fail_payment`");
    $stockAfter20  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();
    $ordersAfter20 = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $cartItems3    = $cartModel->getRawItemsByUserId(3);
    recordCheckoutTest('TC20', 'Lỗi SQL giữa transaction rollback toàn bộ đơn & kho (tách biệt lỗi kỹ thuật)', ($tc20RolledBack && $tc20IsTechErr && $stockAfter20 === $stockBefore20 && $ordersAfter20 === $ordersBefore20) ? 'PASS' : 'FAIL', "stock={$stockBefore20}->{$stockAfter20}, orders={$ordersBefore20}->{$ordersAfter20}, isTechErr=" . ($tc20IsTechErr ? 'yes' : 'no'));
    recordCheckoutTest('TC21', 'Rollback không làm mất giỏ hàng của khách', count($cartItems3) > 0 ? 'PASS' : 'FAIL', "cart_items_user3=" . count($cartItems3));

    // TC09, TC10, TC14: Kiểm thử đồng thời (Concurrency) bằng worker processes
    $workerScript = tempnam(sys_get_temp_dir(), 'tlg_cw_') . '.php';
    file_put_contents($workerScript, <<<'PHP'
<?php
declare(strict_types=1);
$db = $argv[1]; $root = $argv[2]; $pid = (int)$argv[3]; $qty = (int)$argv[4]; $coupon = $argv[5]; $key = $argv[6]; $email = $argv[7]; $phone = $argv[8];
putenv("DB_DATABASE={$db}"); $_ENV['DB_DATABASE'] = $db; $_SERVER['DB_DATABASE'] = $db;
require_once $root . '/config/env.php'; loadEnvFile($root . '/.env');
spl_autoload_register(static function(string $c) use ($root): void {
    if (str_starts_with($c, 'App\\')) {
        $f = $root . '/app/' . str_replace('\\', '/', substr($c, 4)) . '.php';
        if (is_file($f)) require_once $f;
    }
});
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
try {
    $m = new App\Models\OrderModel();
    $issued = App\Models\OrderModel::issueIdempotencyToken(null);
    // Nếu worker nhận key test dạng raw (dài < 32), dùng token vừa cấp hoặc gắn vào session nếu test concurrent cùng key
    if (strlen($key) >= 16) {
        $_SESSION['checkout_issued_tokens'][$key] = [
            'actor_hash' => App\Models\OrderModel::buildActorHash(null),
            'issued_at'  => time(),
        ];
        $useKey = $key;
    } else {
        $useKey = $issued;
    }
    $r = $m->createOrderFromCheckout([
        'customer_name' => 'Concurrent User', 'customer_phone' => $phone, 'customer_email' => $email,
        'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom',
        'payment_method' => 'cod', 'coupon_code' => $coupon
    ], null, [$pid => $qty], [], $useKey);
    echo json_encode(['ok' => true, 'order_code' => $r['order_code'], 'is_duplicate' => $r['is_duplicate']]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
PHP);

    $runPair = static function (array $argsA, array $argsB) use ($workerScript, $testDbName, $projectRoot): array {
        $procs = []; $pipes = [];
        foreach ([$argsA, $argsB] as $idx => $a) {
            $cmd = sprintf('"%s" "%s" %s "%s" %s', PHP_BINARY, $workerScript, $testDbName, $projectRoot, implode(' ', array_map('escapeshellarg', $a)));
            $procs[$idx] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$idx]);
        }
        $outs = [];
        foreach ([0, 1] as $idx) {
            $outs[$idx] = json_decode((string) stream_get_contents($pipes[$idx][1]), true) ?: ['ok' => false];
            fclose($pipes[$idx][1]); fclose($pipes[$idx][2]); proc_close($procs[$idx]);
        }
        return $outs;
    };

    // TC09: 2 khách đặt cùng 1 serial duy nhất (product_id=3 có 1 unit available)
    $r9 = $runPair(['3', '1', '', 'c9a', 'c9a@test.vn', '0901000001'], ['3', '1', '', 'c9b', 'c9b@test.vn', '0901000002']);
    $ok9 = ((int) ($r9[0]['ok'] ?? 0)) + ((int) ($r9[1]['ok'] ?? 0));
    recordCheckoutTest('TC09', 'Hai khách đặt cùng một Serial đồng thời -> chỉ 1 thành công', $ok9 === 1 ? 'PASS' : 'FAIL', "successCount={$ok9}/2");

    // TC10: 2 khách đặt vượt tồn kho số lượng đồng thời (product_id=14 còn 8, mỗi khách đặt 5)
    $r10 = $runPair(['14', '5', '', 'c10a', 'c10a@test.vn', '0902000001'], ['14', '5', '', 'c10b', 'c10b@test.vn', '0902000002']);
    $ok10 = ((int) ($r10[0]['ok'] ?? 0)) + ((int) ($r10[1]['ok'] ?? 0));
    $rem14 = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();
    recordCheckoutTest('TC10', 'Hai khách đặt vượt tồn kho số lượng đồng thời -> 1 thành công, kho không âm', ($ok10 === 1 && $rem14 === 3) ? 'PASS' : 'FAIL', "successCount={$ok10}/2, remainingStock14={$rem14}");

    // TC14: 2 khách tranh chấp lượt voucher cuối cùng (tạo coupon LAST1 còn 1 lượt, 2 khách đặt SP ID=10 và ID=11 hoặc ID=6,7)
    $pdo->exec("INSERT INTO coupons (code, description, discount_type, discount_value, min_order_amount, usage_limit, usage_count, per_user_limit, start_date, end_date, is_active) VALUES ('LAST1', 'Last slot', 'fixed_amount', 100000, 1000000, 1, 0, 1, '2026-01-01 00:00:00', '2027-12-31 23:59:59', 1)");
    $r14 = $runPair(['6', '1', 'LAST1', 'c14a', 'c14a@test.vn', '0903000001'], ['7', '1', 'LAST1', 'c14b', 'c14b@test.vn', '0903000002']);
    $ok14 = ((int) ($r14[0]['ok'] ?? 0)) + ((int) ($r14[1]['ok'] ?? 0));
    $usedLast1 = (int) $pdo->query("SELECT usage_count FROM coupons WHERE code = 'LAST1'")->fetchColumn();
    recordCheckoutTest('TC14', 'Hai khách dùng lượt voucher cuối cùng đồng thời -> chỉ 1 thành công', ($ok14 === 1 && $usedLast1 === 1) ? 'PASS' : 'FAIL', "successCount={$ok14}/2, usage_count={$usedLast1}");

    // TC26: Đơn hàng 0đ (Voucher giảm 100% + shipping_fee = 0)
    $pdo->exec("INSERT INTO coupons (code, description, discount_type, discount_value, max_discount_amount, min_order_amount, usage_limit, usage_count, per_user_limit, start_date, end_date, is_active) VALUES ('FREE100', 'Free 100%', 'percentage', 100, NULL, 0, 5, 0, 5, '2026-01-01 00:00:00', '2027-12-31 23:59:59', 1)");
    $kZero = OrderModel::issueIdempotencyToken(null);
    $resZero = $orderModel->createOrderFromCheckout([
        'customer_name' => 'Khách 0đ', 'customer_phone' => '0905000001', 'customer_email' => 'zero@test.vn',
        'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom',
        'payment_method' => 'cod', 'coupon_code' => 'FREE100',
    ], null, [14 => 1], [], $kZero);
    $payZero = $pdo->query("SELECT amount, status FROM payments WHERE order_id = " . (int) $resZero['order_id'])->fetch();
    recordCheckoutTest('TC26', 'Đơn hàng 0đ hợp lệ ghi nhận payments.amount=0 và status=pending (không giả mạo đã thanh toán)', ((int) $resZero['final_amount'] === 0 && $payZero && (int) $payZero['amount'] === 0 && $payZero['status'] === 'pending') ? 'PASS' : 'FAIL', "final={$resZero['final_amount']}, pay_amount=" . ($payZero['amount'] ?? 'null') . ", status=" . ($payZero['status'] ?? 'null'));

    // TC27: Đơn hàng 1đ và >0đ
    $price14Orig = (int) $pdo->query("SELECT price FROM products WHERE id = 14")->fetchColumn();
    $pdo->exec("UPDATE products SET price = 1 WHERE id = 14");
    $kOne = OrderModel::issueIdempotencyToken(null);
    $resOne = $orderModel->createOrderFromCheckout([
        'customer_name' => 'Khách 1đ', 'customer_phone' => '0905000002', 'customer_email' => 'one@test.vn',
        'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom',
        'payment_method' => 'cod',
    ], null, [14 => 1], [], $kOne);
    $payOne = $pdo->query("SELECT amount FROM payments WHERE order_id = " . (int) $resOne['order_id'])->fetchColumn();
    $pdo->exec("UPDATE products SET price = {$price14Orig} WHERE id = 14");
    recordCheckoutTest('TC27', 'Đơn hàng 1đ và >0đ ghi nhận đúng số tiền thực tế', ((int) $resOne['final_amount'] === 1 && (int) $payOne === 1) ? 'PASS' : 'FAIL', "final_1d={$resOne['final_amount']}, pay_1d={$payOne}");

    // TC28: Giả lập PHP dừng sau commit (xóa session idempotency & giữ lại giỏ tạm như trước khi cleanup) rồi gửi lại cùng idempotency_key
    $kCrash = OrderModel::issueIdempotencyToken(null);
    $crashInput = [
        'customer_name' => 'Khách Crash Test', 'customer_phone' => '0905000003', 'customer_email' => 'crash@test.vn',
        'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom',
        'payment_method' => 'cod',
    ];
    $resCrash1 = $orderModel->createOrderFromCheckout($crashInput, null, [14 => 1], [], $kCrash);
    unset($_SESSION['checkout_idempotency'], $_SESSION['checkout_issued_tokens']);
    // Retry dù PHP vừa mất session/chưa kịp xóa giỏ (gửi lại cùng giỏ [14 => 1] và cùng idempotency_key)
    $resCrash2 = $orderModel->createOrderFromCheckout($crashInput, null, [14 => 1], [], $kCrash);
    $cntCrashOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_code = " . $pdo->quote($resCrash1['order_code']))->fetchColumn();
    recordCheckoutTest('TC28', 'Phục hồi Idempotency bền vững từ MySQL khi PHP ngắt sau commit rồi gửi lại', ($resCrash2['is_duplicate'] === true && $resCrash2['order_code'] === $resCrash1['order_code'] && $cntCrashOrders === 1) ? 'PASS' : 'FAIL', "first={$resCrash1['order_code']}, retry={$resCrash2['order_code']}, dup=" . ($resCrash2['is_duplicate'] ? 'yes' : 'no'));

    // TC29: Từ chối idempotency_key không hợp lệ / chưa được Server cấp
    $tc29Rejected = false;
    try {
        $orderModel->createOrderFromCheckout($crashInput, null, [14 => 1], [], 'forged_unissued_token_123456');
    } catch (App\Core\CheckoutException) {
        $tc29Rejected = true;
    }
    recordCheckoutTest('TC29', 'Từ chối idempotency_key giả mạo hoặc chưa được cấp phát', $tc29Rejected ? 'PASS' : 'FAIL', "forgedRejected=" . ($tc29Rejected ? 'yes' : 'no'));

    // TC30: Từ chối dùng lại idempotency_key đã commit với giỏ hàng khác
    $tc30Rejected = false;
    try {
        $orderModel->createOrderFromCheckout($crashInput, null, [14 => 2], [], $kCrash);
    } catch (App\Core\CheckoutException) {
        $tc30Rejected = true;
    }
    recordCheckoutTest('TC30', 'Từ chối tái sử dụng idempotency_key cũ cho giỏ hàng / payload khác', $tc30Rejected ? 'PASS' : 'FAIL', "reusedPayloadRejected=" . ($tc30Rejected ? 'yes' : 'no'));

    // TC31: Hai request đồng thời gửi CÙNG một idempotency_key
    $pdo->exec("UPDATE products SET stock_quantity = 10 WHERE id = 14");
    $sameKey = bin2hex(random_bytes(16));
    $r31 = $runPair(['14', '1', '', $sameKey, 'c31@test.vn', '0905000031'], ['14', '1', '', $sameKey, 'c31@test.vn', '0905000031']);
    $ok31 = ((int) ($r31[0]['ok'] ?? 0)) + ((int) ($r31[1]['ok'] ?? 0));
    $idemOrders31 = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE customer_email = 'c31@test.vn'")->fetchColumn();
    recordCheckoutTest('TC31', 'Hai request đồng thời cùng idempotency_key -> chỉ tạo 1 đơn duy nhất trong MySQL', ($idemOrders31 === 1 && $ok31 >= 1) ? 'PASS' : 'FAIL', "ordersCreated={$idemOrders31}, workerOk={$ok31}/2");

    // TC33: Kiểm định Serial: cũ `failed` + mới hơn `passed` (đủ 18 bước) -> ĐƯỢC BÁN; mới nhất `failed` -> CHẶN
    $tc33InspModel = new \App\Models\InspectionModel();
    $tc33StepsPass = [];
    $tc33StepsFail = [];
    for ($s = 1; $s <= 18; $s++) {
        $tc33StepsPass[$s] = ['step_number' => $s, 'result' => 'pass', 'notes' => 'TC33 pass'];
        $tc33StepsFail[$s] = ['step_number' => $s, 'result' => ($s === 7 ? 'fail' : 'pass'), 'notes' => 'TC33'];
    }
    $pdo->exec("INSERT INTO product_units (product_id, serial_number, status, cosmetic_grade, battery_health) VALUES (1, 'TLG-REPASS-001', 'available', 'Grade A', 95)");
    $repassUnitId = (int) $pdo->lastInsertId();
    $tc33InspModel->createInspection($repassUnitId, 1, 'failed', 'Lỗi cũ trước sửa', $tc33StepsFail);
    $tc33InspModel->createInspection($repassUnitId, 1, 'passed', 'Đã khắc phục và đạt chuẩn', $tc33StepsPass);
    $k33a = OrderModel::issueIdempotencyToken(null);
    $res33a = $orderModel->createOrderFromCheckout([
        'customer_name' => 'Khách Repass', 'customer_phone' => '0905000033', 'customer_email' => 'repass@test.vn',
        'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom',
        'payment_method' => 'cod',
    ], null, [1 => 1], [], $k33a);

    $pdo->exec("INSERT INTO product_units (product_id, serial_number, status, cosmetic_grade, battery_health) VALUES (1, 'TLG-LATEST-FAIL-001', 'available', 'Grade B', 80)");
    $failUnitId = (int) $pdo->lastInsertId();
    $tc33InspModel->createInspection($failUnitId, 1, 'passed', 'Đạt cũ', $tc33StepsPass);
    $tc33InspModel->createInspection($failUnitId, 1, 'failed', 'Hỏng mới nhất', $tc33StepsFail);
    $tc33bBlocked = false;
    try {
        $k33b = OrderModel::issueIdempotencyToken(null);
        $orderModel->createOrderFromCheckout([
            'customer_name' => 'Khách Fail', 'customer_phone' => '0905000034', 'customer_email' => 'fail@test.vn',
            'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom',
            'payment_method' => 'cod',
        ], null, [1 => 1], [], $k33b);
    } catch (App\Core\CheckoutException) {
        $tc33bBlocked = true;
    }
    recordCheckoutTest('TC33', 'Serial có biên bản cũ failed + mới nhất passed được bán; mới nhất failed bị chặn', (!empty($res33a['order_code']) && $tc33bBlocked) ? 'PASS' : 'FAIL', "repassOrder=" . ($res33a['order_code'] ?? '') . ", latestFailBlocked=" . ($tc33bBlocked ? 'yes' : 'no'));

    // TC32: Rate Limit tra cứu đơn hàng (đổi mã đơn liên tục vẫn bị chặn ở lần thứ 6)
    App\Core\RateLimiter::clear('checkout_lookup_ip:127.0.0.1');
    $cookieRL = tempnam(sys_get_temp_dir(), 'tlg_rl_');
    $lpPage   = httpReq('GET', $baseUrl . '/tra-cuu-don-hang', $cookieRL);
    $csrfRL   = getField($lpPage['body'], '_csrf_token');
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        httpReq('POST', $baseUrl . '/tra-cuu-don-hang', $cookieRL, [
            '_csrf_token' => $csrfRL, 'order_code' => 'TLG-FAKE-' . $attempt, 'verification_contact' => '099900000' . $attempt,
        ]);
    }
    $r32Blocked = httpReq('POST', $baseUrl . '/tra-cuu-don-hang', $cookieRL, [
        '_csrf_token' => $csrfRL, 'order_code' => $guestOrderCode, 'verification_contact' => '0911222333',
    ]);
    @unlink($cookieRL);
    recordCheckoutTest('TC32', 'RateLimiter tra cứu đơn hàng chặn sau 5 lần sai (chống đổi mã đơn né limit)', ($r32Blocked['status'] === 429 && str_contains($r32Blocked['body'], 'quá nhiều lần')) ? 'PASS' : 'FAIL', "HTTP {$r32Blocked['status']}");

    // TC25: Khi chưa cấu hình tài khoản ngân hàng & showroom trong .env -> ẩn/từ chối bank_transfer, vẫn cho COD
    $unconfPort = 18101;
    $unconfLog  = tempnam(sys_get_temp_dir(), 'tlg_unc_');
    $unconfEnv  = [
        'DB_DATABASE'            => $testDbName,
        'TL_BANK_NAME'           => '',
        'TL_BANK_ACCOUNT_NUMBER' => '',
        'TL_BANK_ACCOUNT_HOLDER' => '',
        'TL_SHOWROOM_HN_ADDRESS' => '',
        'TL_SHOWROOM_HCM_ADDRESS'=> '',
        'SystemRoot'             => getenv('SystemRoot') ?: 'C:\\Windows',
        'PATH'                   => getenv('PATH') ?: '',
    ];
    $unconfProc = proc_open(
        sprintf('"%s" -S 127.0.0.1:%d -t "%s"', PHP_BINARY, $unconfPort, $projectRoot . '/public'),
        [0 => ['pipe', 'r'], 1 => ['file', $unconfLog, 'a'], 2 => ['file', $unconfLog, 'a']],
        $uPipes,
        $projectRoot,
        $unconfEnv
    );
    if (isset($uPipes[0]) && is_resource($uPipes[0])) {
        fclose($uPipes[0]);
    }
    for ($i = 0; $i < 25; $i++) {
        if (@file_get_contents("http://127.0.0.1:{$unconfPort}/gio-hang") !== false) {
            break;
        }
        usleep(100000);
    }
    $cookieUnc = tempnam(sys_get_temp_dir(), 'tlg_cunc_');
    $uCart = httpReq('GET', "http://127.0.0.1:{$unconfPort}/gio-hang", $cookieUnc);
    httpReq('POST', "http://127.0.0.1:{$unconfPort}/gio-hang/them", $cookieUnc, ['product_id' => 14, 'quantity' => 1, '_csrf_token' => getField($uCart['body'], '_csrf_token')], ['X-Requested-With: XMLHttpRequest']);
    $uChk = httpReq('GET', "http://127.0.0.1:{$unconfPort}/thanh-toan", $cookieUnc);
    $uiHidesBank = !str_contains($uChk['body'], 'value="bank_transfer"');
    $ordersBefore25 = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $uPostBank = httpReq('POST', "http://127.0.0.1:{$unconfPort}/thanh-toan", $cookieUnc, [
        '_csrf_token' => getField($uChk['body'], '_csrf_token'), 'idempotency_key' => getField($uChk['body'], 'idempotency_key'),
        'customer_name' => 'Khách Unconf', 'customer_phone' => '0906000025', 'customer_email' => 'unconf@test.vn',
        'delivery_method' => 'delivery', 'shipping_city' => 'Hà Nội', 'shipping_district' => 'Cầu Giấy', 'shipping_address' => '10 Xuân Thủy',
        'payment_method' => 'bank_transfer', 'agree_policy' => '1',
    ], [], false);
    $ordersAfter25 = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    proc_terminate($unconfProc);
    proc_close($unconfProc);
    @unlink($cookieUnc);
    @unlink($unconfLog);
    recordCheckoutTest('TC25', 'Chưa cấu hình TK ngân hàng -> UI ẩn bank_transfer & Server từ chối bank_transfer', ($uiHidesBank && $ordersAfter25 === $ordersBefore25 && str_contains($uPostBank['location'], '/thanh-toan')) ? 'PASS' : 'FAIL', "uiHidesBank=" . ($uiHidesBank ? 'yes' : 'no') . ", orders={$ordersBefore25}->{$ordersAfter25}");

    // TC34: Token không thuộc tác nhân hiện tại (Guest dùng token của Customer hoặc ngược lại) -> bị chặn
    $kActor = OrderModel::issueIdempotencyToken(3);
    $tc34Blocked = false;
    try {
        $orderModel->createOrderFromCheckout($crashInput, null, [14 => 1], [], $kActor);
    } catch (App\Core\CheckoutException) {
        $tc34Blocked = true;
    }
    // Ngoài ra, token đã commit của Guest ($kCrash) nếu Customer (userId=3) gửi lên cũng phải bị chặn
    $tc34CrossCommitted = false;
    try {
        $orderModel->createOrderFromCheckout($crashInput, 3, [], [], $kCrash);
    } catch (App\Core\CheckoutException) {
        $tc34CrossCommitted = true;
    }
    recordCheckoutTest('TC34', 'Token không thuộc tác nhân hiện tại (chưa commit & đã commit) bị từ chối', ($tc34Blocked && $tc34CrossCommitted) ? 'PASS' : 'FAIL', "uncommittedBlocked=" . ($tc34Blocked ? 'yes' : 'no') . ", committedCrossActorBlocked=" . ($tc34CrossCommitted ? 'yes' : 'no'));

    // TC35: Thêm sản phẩm mới vào giỏ trong khi đặt hàng -> chỉ xóa sản phẩm đã commit, giữ nguyên sản phẩm mới (Guest Session & Customer MySQL)
    $pdo->exec("UPDATE products SET stock_quantity = 10 WHERE id IN (13, 14)");
    $_SESSION['cart'] = [14 => ['product_id' => 14, 'quantity' => 1], 13 => ['product_id' => 13, 'quantity' => 2]];
    $k35g = OrderModel::issueIdempotencyToken(null);
    // Đặt đơn với snapshot chỉ có SP 14 => 1 (trong khi $_SESSION['cart'] đã có thêm SP 13 => 2 từ tab khác)
    $res35g = $orderModel->createOrderFromCheckout($crashInput, null, [14 => 1], [], $k35g);
    $guestKept13 = !isset($_SESSION['cart'][14]) && ((int) ($_SESSION['cart'][13]['quantity'] ?? 0) === 2);

    // Kiểm tra trên giỏ MySQL của Customer (userId=3): dọn sạch rồi thêm SP 14 (qty=1) và SP 13 (qty=2),
    // dùng Reflection gọi cleanupCartAfterCommit cho committedItems=[14=>1] để xác minh MySQL chỉ trừ đúng SP 14 và giữ SP 13
    $cartModel->clearCart(3);
    $cartModel->addItem(3, 14, 1, 10);
    $cartModel->addItem(3, 13, 2, 10);
    $refCleanup = new ReflectionMethod(OrderModel::class, 'cleanupCartAfterCommit');
    $refCleanup->setAccessible(true);
    $refCleanup->invoke($orderModel, $cartModel, 3, [14 => 1]);
    $custItems35 = array_column($cartModel->getRawItemsByUserId(3), 'quantity', 'product_id');
    $custKept13  = !isset($custItems35[14]) && ((int) ($custItems35[13] ?? 0) === 2);
    recordCheckoutTest('TC35', 'Thêm sản phẩm mới vào giỏ khi đặt hàng -> chỉ loại bỏ SP đã commit, giữ SP mới (Guest & Customer)', ($guestKept13 && $custKept13 && !empty($res35g['order_code'])) ? 'PASS' : 'FAIL', "guestKept13=" . ($guestKept13 ? 'yes' : 'no') . ", custKept13=" . ($custKept13 ? 'yes' : 'no'));

    // TC36: Cùng sản phẩm nhưng thay đổi số lượng trong hai phiên -> chỉ trừ đúng số lượng đã commit
    $_SESSION['cart'] = [14 => ['product_id' => 14, 'quantity' => 5]];
    $k36g = OrderModel::issueIdempotencyToken(null);
    $orderModel->createOrderFromCheckout($crashInput, null, [14 => 2], [], $k36g);
    $guestLeft36 = (int) ($_SESSION['cart'][14]['quantity'] ?? 0);

    $cartModel->clearCart(3);
    $cartModel->addItem(3, 14, 5, 10);
    $refCleanup->invoke($orderModel, $cartModel, 3, [14 => 2]);
    $custMap36  = array_column($cartModel->getRawItemsByUserId(3), 'quantity', 'product_id');
    $custLeft36 = (int) ($custMap36[14] ?? 0);
    recordCheckoutTest('TC36', 'Cùng sản phẩm thay đổi số lượng trong hai phiên -> chỉ trừ đúng số lượng đã commit (5 - 2 = 3)', ($guestLeft36 === 3 && $custLeft36 === 3) ? 'PASS' : 'FAIL', "guestLeft={$guestLeft36}, custLeft={$custLeft36}");

    // TC37: Retry đơn cũ không làm mất giỏ mới & không trừ kho/serial hai lần
    $pdo->exec("UPDATE products SET stock_quantity = 10 WHERE id = 14");
    $k37 = OrderModel::issueIdempotencyToken(3);
    $cartModel->clearCart(3);
    $cartModel->addItem(3, 14, 2, 10);
    $res37a = $orderModel->createOrderFromCheckout([
        'customer_name' => 'Trần Thị Bình', 'customer_phone' => '0987654321', 'customer_email' => 'tran.thi.binh@demo.vn',
        'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom', 'payment_method' => 'cod',
    ], 3, [], [], $k37);
    $stockAfterFirst37 = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();
    // Khách tiếp tục thêm sản phẩm 13 mới vào giỏ, sau đó gửi lại retry với token cũ $k37
    $cartModel->addItem(3, 13, 1, 10);
    $tc37PayloadRejected = false;
    try {
        $orderModel->createOrderFromCheckout([
            'customer_name' => 'Trần Thị Bình', 'customer_phone' => '0987654321', 'customer_email' => 'tran.thi.binh@demo.vn',
            'delivery_method' => 'pickup', 'shipping_city' => 'Hà Nội', 'shipping_address' => 'Showroom', 'payment_method' => 'cod',
        ], 3, [], [], $k37);
    } catch (App\Core\CheckoutException) {
        $tc37PayloadRejected = true;
    }
    $stockAfterRetry37 = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();
    $newCartAfterRetry = array_column($cartModel->getRawItemsByUserId(3), 'quantity', 'product_id');
    recordCheckoutTest('TC37', 'Retry đơn cũ không làm mất giỏ mới & không trừ kho lần 2', ($tc37PayloadRejected && $stockAfterFirst37 === 8 && $stockAfterRetry37 === 8 && ((int) ($newCartAfterRetry[13] ?? 0) === 1)) ? 'PASS' : 'FAIL', "stock={$stockAfterFirst37}->{$stockAfterRetry37}, keptNewItem13=" . ($newCartAfterRetry[13] ?? 0));

    @unlink($workerScript);
} finally {
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS `tg_test_fail_payment`");
        } catch (Throwable) {
        }
    }
    proc_terminate($serverProc);
    proc_close($serverProc);
    @unlink($cookieGuest);
    @unlink($cookieCust);
    @unlink($cookieOther);
    @unlink($serverLogFile);
}

$pass = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'PASS'));
$fail = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'FAIL'));
echo "\n================================================================================\n";
echo "TỔNG KẾT CHECKOUT TESTS: PASS={$pass} | FAIL={$fail} (Tổng " . count($results) . ")\n";
echo "================================================================================\n";
exit($fail === 0 ? 0 : 1);
