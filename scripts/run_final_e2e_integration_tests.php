<?php
/**
 * Bộ kiểm thử Tích hợp End-to-End (E2E HTTP + Session + CSRF) cho toàn hệ thống TL Gaming.
 * Chạy trên CSDL cô lập `tl_gaming_test` (bắt buộc `--confirm-test-db`), tuyệt đối không chạm `tl_gaming`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../app/Core/Database.php';

const E2E_TEST_DB = 'tl_gaming_test';

putenv('DB_DATABASE=' . E2E_TEST_DB);
$_ENV['DB_DATABASE']    = E2E_TEST_DB;
$_SERVER['DB_DATABASE'] = E2E_TEST_DB;

$args = $argv ?? [];
if (!in_array('--confirm-test-db', $args, true)) {
    fwrite(STDERR, "Từ chối chạy: Bắt buộc truyền `--confirm-test-db`.\n");
    exit(1);
}
if ((string) ($_ENV['DB_DATABASE'] ?? '') !== E2E_TEST_DB || E2E_TEST_DB === 'tl_gaming') {
    fwrite(STDERR, "AN TOÀN DB: Chỉ cho phép chạy trên `tl_gaming_test`.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$host        = (string) env('DB_HOST', '127.0.0.1');
$port        = (int) env('DB_PORT', '3306');
$username    = (string) env('DB_USERNAME', 'root');
$password    = (string) env('DB_PASSWORD', '');

// Reset sạch DB test từ schema + seed
$rootPdo = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$rootPdo->exec('DROP DATABASE IF EXISTS `' . E2E_TEST_DB . '`');
$rootPdo->exec('CREATE DATABASE `' . E2E_TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$schemaSql = str_replace('`tl_gaming`', '`' . E2E_TEST_DB . '`', (string) file_get_contents($projectRoot . '/database/tl_gaming.sql'));
$seedSql   = str_replace('`tl_gaming`', '`' . E2E_TEST_DB . '`', (string) file_get_contents($projectRoot . '/database/seed_tl_gaming.sql'));

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
fwrite($resetPipes[0], 'USE `' . E2E_TEST_DB . "`;\n" . $schemaSql . "\n" . $seedSql);
fclose($resetPipes[0]);
stream_get_contents($resetPipes[1]);
$resetErr = stream_get_contents($resetPipes[2]);
fclose($resetPipes[1]);
fclose($resetPipes[2]);
if (proc_close($resetProc) !== 0) {
    fwrite(STDERR, "Lỗi nạp DB test: {$resetErr}\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname=" . E2E_TEST_DB . ';charset=utf8mb4',
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec("SET time_zone = '+07:00'");

$results = [];
function recordE2E(string $code, string $name, string $status, string $evidence = ''): void
{
    global $results;
    $results[] = ['code' => $code, 'name' => $name, 'status' => $status, 'evidence' => $evidence];
    echo sprintf("%-7s| %-7s | %s\n", $code, $status, $name);
    if ($evidence !== '') {
        echo "       -> Bằng chứng: {$evidence}\n";
    }
}

function startE2EServer(string $projectRoot): array
{
    $svrPort    = random_int(19700, 19940);
    $phpBin     = PHP_BINARY ?: 'E:\\xampp\\php\\php.exe';
    $routerFile = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
    $docRoot    = $projectRoot . DIRECTORY_SEPARATOR . 'public';
    $env                = getenv();
    $env['DB_DATABASE'] = E2E_TEST_DB;
    $env['APP_ENV']     = 'testing';
    $env['TL_SHOWROOM_HN_ADDRESS'] = '123 Lê Thanh Nghị, Hai Bà Trưng, Hà Nội';

    $nullDev = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd     = sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s"', $phpBin, $svrPort, $docRoot, $routerFile);
    $proc    = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $nullDev, 'w'], 2 => ['file', $nullDev, 'w']], $pipes, $projectRoot, $env);
    $baseUrl = "http://127.0.0.1:{$svrPort}";
    for ($i = 0; $i < 40; $i++) {
        usleep(100_000);
        $ch = curl_init($baseUrl . '/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code > 0) {
            return ['proc' => $proc, 'pipes' => $pipes, 'baseUrl' => $baseUrl];
        }
    }
    proc_terminate($proc);
    throw new RuntimeException('PHP E2E server không phản hồi.');
}

function e2eHttp(string $method, string $url, string $cookieJar, mixed $postFields = null, array $headers = []): array
{
    $ch   = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
    ];
    if ($headers !== []) {
        $opts[CURLOPT_HTTPHEADER] = $headers;
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

    $hdrStr = substr($raw, 0, $headerSize);
    $body   = substr($raw, $headerSize);
    $loc    = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $hdrStr, $m)) {
        $loc = trim($m[1]);
    }
    return ['status' => $status, 'location' => $loc, 'body' => $body];
}

function e2eCsrf(string $html): string
{
    if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function e2eIdemKey(string $html): string
{
    if (preg_match('/name="idempotency_key"\s+value="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

echo "================================================================================\n";
echo "TL GAMING — KIỂM THỬ TÍCH HỢP END-TO-END HTTP TOÀN HỆ THỐNG\n";
echo "CSDL test: " . E2E_TEST_DB . "\n";
echo "================================================================================\n\n";

$server  = startE2EServer($projectRoot);
$baseUrl = $server['baseUrl'];
$tmpDir  = sys_get_temp_dir();
$custJar = tempnam($tmpDir, 'e2e_c_');
$gJar    = tempnam($tmpDir, 'e2e_g_');
$admJar  = tempnam($tmpDir, 'e2e_a_');

try {
    // Đảm bảo tồn kho sản phẩm số lượng (ID=14) đủ lớn để test
    $qtyProd = $pdo->query('SELECT id, slug FROM products WHERE has_serials = 0 ORDER BY id ASC LIMIT 1')->fetch();
    $qtyId   = (int) $qtyProd['id'];
    $pdo->prepare('UPDATE products SET stock_quantity = 50 WHERE id = :pid')->execute([':pid' => $qtyId]);

    $serProd = $pdo->query('SELECT id, slug FROM products WHERE has_serials = 1 ORDER BY id ASC LIMIT 1')->fetch();

    // ========================================================================
    // PHẦN 2: HÀNH TRÌNH END-TO-END KHÁCH HÀNG (HTTP + Session + CSRF)
    // ========================================================================
    $rHome   = e2eHttp('GET', $baseUrl . '/', $custJar);
    $rShop   = e2eHttp('GET', $baseUrl . '/san-pham?q=RTX', $custJar);
    $rDetail = e2eHttp('GET', $baseUrl . '/san-pham/' . $serProd['slug'], $custJar);
    $csrfC   = e2eCsrf($rHome['body']);
    recordE2E('E2E-C1', 'Khách truy cập Trang chủ, Tìm kiếm/Lọc & Chi tiết sản phẩm (HTTP 200)', ($rHome['status'] === 200 && $rShop['status'] === 200 && $rDetail['status'] === 200) ? 'PASS' : 'FAIL');

    // Thêm giỏ, sửa số lượng, xóa sản phẩm rồi thêm lại để hợp nhất khi đăng nhập
    $rAdd1 = e2eHttp('POST', $baseUrl . '/gio-hang/them', $custJar, ['_csrf_token' => $csrfC, 'product_id' => $qtyId, 'quantity' => 2], ['X-Requested-With: XMLHttpRequest']);
    $rUpd1 = e2eHttp('POST', $baseUrl . '/gio-hang/cap-nhat', $custJar, ['_csrf_token' => $csrfC, 'product_id' => $qtyId, 'quantity' => 3], ['X-Requested-With: XMLHttpRequest']);
    $rDel1 = e2eHttp('POST', $baseUrl . '/gio-hang/xoa', $custJar, ['_csrf_token' => $csrfC, 'product_id' => $qtyId], ['X-Requested-With: XMLHttpRequest']);
    $rAdd2 = e2eHttp('POST', $baseUrl . '/gio-hang/them', $custJar, ['_csrf_token' => $csrfC, 'product_id' => $qtyId, 'quantity' => 2], ['X-Requested-With: XMLHttpRequest']);
    recordE2E('E2E-C2', 'Giỏ hàng Guest: thêm, sửa SL, xóa & thêm lại qua HTTP + CSRF', ($rAdd1['status'] === 200 && $rUpd1['status'] === 200 && $rDel1['status'] === 200 && $rAdd2['status'] === 200) ? 'PASS' : 'FAIL');

    // Đăng ký tài khoản mới -> đăng nhập -> hợp nhất giỏ
    $rLoginPg = e2eHttp('GET', $baseUrl . '/dang-nhap', $custJar);
    $csrfReg  = e2eCsrf($rLoginPg['body']);
    $custMail = 'e2e_user_' . time() . '@tlgaming.test';
    $rReg     = e2eHttp('POST', $baseUrl . '/dang-ky', $custJar, [
        '_csrf_token'           => $csrfReg,
        'full_name'             => 'Nguyễn E2E Khách',
        'email'                 => $custMail,
        'phone'                 => '0912345678',
        'password'              => 'Customer@123',
        'password_confirmation' => 'Customer@123',
        'agree_terms'           => '1',
    ]);
    // Đăng nhập tài khoản vừa đăng ký để kích hoạt hợp nhất giỏ hàng Session -> MySQL
    $rLoginAfterReg = e2eHttp('GET', $baseUrl . '/dang-nhap', $custJar);
    $rDoLogin       = e2eHttp('POST', $baseUrl . '/dang-nhap', $custJar, [
        '_csrf_token' => e2eCsrf($rLoginAfterReg['body']),
        'email'       => $custMail,
        'password'    => 'Customer@123',
    ]);
    $newCustId = (int) $pdo->query("SELECT id FROM users WHERE email = '{$custMail}'")->fetchColumn();
    $cartPg    = e2eHttp('GET', $baseUrl . '/gio-hang', $custJar);
    $dbCartQty = (int) $pdo->query("SELECT COALESCE(SUM(ci.quantity),0) FROM cart_items ci INNER JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = {$newCustId}")->fetchColumn();
    recordE2E('E2E-C3', 'Đăng ký tài khoản mới, đăng nhập & tự động hợp nhất giỏ hàng vào MySQL', ($rReg['status'] === 302 && $rDoLogin['status'] === 302 && $newCustId > 0 && $dbCartQty === 2 && $cartPg['status'] === 200) ? 'PASS' : 'FAIL', "uid={$newCustId}, cartQty={$dbCartQty}");

    // Mở Checkout, xem trước với voucher, đặt đơn COD + thử double submit idempotency + xem thành công + tra cứu đơn
    $rCkPg   = e2eHttp('GET', $baseUrl . '/thanh-toan', $custJar);
    $csrfCk  = e2eCsrf($rCkPg['body']);
    $idemKey = e2eIdemKey($rCkPg['body']);
    $ckPost  = [
        '_csrf_token'      => $csrfCk,
        'idempotency_key'  => $idemKey,
        'customer_name'    => 'Nguyễn E2E Khách',
        'customer_phone'   => '0912345678',
        'customer_email'   => $custMail,
        'delivery_method'  => 'delivery',
        'shipping_city'    => 'Hà Nội',
        'shipping_district'=> 'Đống Đa',
        'shipping_ward'    => 'Láng Hạ',
        'shipping_address' => '100 Thái Hà',
        'payment_method'   => 'cod',
        'coupon_code'      => '',
        'agree_policy'     => '1',
    ];
    $rPlace1 = e2eHttp('POST', $baseUrl . '/thanh-toan', $custJar, $ckPost);
    $rPlace2 = e2eHttp('POST', $baseUrl . '/thanh-toan', $custJar, $ckPost); // Double submit
    $ordCode = '';
    if (preg_match('#/dat-hang/thanh-cong/([A-Z0-9-]+)#', $rPlace1['location'], $m)) {
        $ordCode = $m[1];
    }
    $rSuccess = $ordCode !== '' ? e2eHttp('GET', $baseUrl . '/dat-hang/thanh-cong/' . $ordCode, $custJar) : ['status' => 0, 'body' => ''];
    $ordCntForUser = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE user_id = {$newCustId}")->fetchColumn();

    // Tra cứu đơn hợp lệ
    $rLkPg = e2eHttp('GET', $baseUrl . '/tra-cuu-don-hang', $custJar);
    $rLk   = e2eHttp('POST', $baseUrl . '/tra-cuu-don-hang', $custJar, [
        '_csrf_token' => e2eCsrf($rLkPg['body']),
        'order_code'  => $ordCode,
        'contact'     => '0912345678',
    ]);
    // Đăng xuất
    $rLogout = e2eHttp('POST', $baseUrl . '/dang-xuat', $custJar, ['_csrf_token' => e2eCsrf($rLkPg['body'])]);
    recordE2E('E2E-C4', 'Checkout COD + Idempotency (1 đơn duy nhất) + Trang thành công + Tra cứu + Đăng xuất', ($ordCode !== '' && $ordCntForUser === 1 && $rSuccess['status'] === 200 && str_contains($rLk['body'], $ordCode) && $rLogout['status'] === 302) ? 'PASS' : 'FAIL', "order={$ordCode}, cnt={$ordCntForUser}");

    // Guest Checkout độc lập
    $rGHome = e2eHttp('GET', $baseUrl . '/', $gJar);
    $csrfG  = e2eCsrf($rGHome['body']);
    e2eHttp('POST', $baseUrl . '/gio-hang/them', $gJar, ['_csrf_token' => $csrfG, 'product_id' => $qtyId, 'quantity' => 1], ['X-Requested-With: XMLHttpRequest']);
    $rGCk = e2eHttp('GET', $baseUrl . '/thanh-toan', $gJar);
    $rGPlace = e2eHttp('POST', $baseUrl . '/thanh-toan', $gJar, [
        '_csrf_token'       => e2eCsrf($rGCk['body']),
        'idempotency_key'   => e2eIdemKey($rGCk['body']),
        'customer_name'     => 'Khách Vãng Lai E2E',
        'customer_phone'    => '0988776655',
        'customer_email'    => 'guest_e2e@tlgaming.test',
        'delivery_method'   => 'pickup',
        'showroom_id'       => 'hn',
        'payment_method'    => 'cod',
        'agree_policy'      => '1',
    ]);
    $guestOrd = $pdo->query("SELECT id, user_id, delivery_method FROM orders WHERE customer_email = 'guest_e2e@tlgaming.test' ORDER BY id DESC LIMIT 1")->fetch();
    recordE2E('E2E-C5', 'Guest Checkout nhận tại showroom thành công (user_id=NULL, delivery_method=pickup)', ($rGPlace['status'] === 302 && $guestOrd && $guestOrd['user_id'] === null && $guestOrd['delivery_method'] === 'pickup') ? 'PASS' : 'FAIL');

    // ========================================================================
    // PHẦN 3: HÀNH TRÌNH END-TO-END ADMIN (HTTP + Session + CSRF)
    // ========================================================================
    $admPass = password_hash('Admin@123', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at) VALUES ('Admin E2E', 'admin_e2e@tlgaming.test', '0900009999', :p, 'admin', 'active', NOW())")->execute([':p' => $admPass]);

    $rAdmLoginPg = e2eHttp('GET', $baseUrl . '/dang-nhap', $admJar);
    e2eHttp('POST', $baseUrl . '/dang-nhap', $admJar, [
        '_csrf_token' => e2eCsrf($rAdmLoginPg['body']),
        'email'       => 'admin_e2e@tlgaming.test',
        'password'    => 'Admin@123',
    ]);
    $rAdmDash = e2eHttp('GET', $baseUrl . '/admin', $admJar);
    $csrfAdm  = e2eCsrf($rAdmDash['body']);
    recordE2E('E2E-A1', 'Admin đăng nhập & truy cập Dashboard HTTP 200', ($rAdmDash['status'] === 200 && $csrfAdm !== '') ? 'PASS' : 'FAIL');

    // Tạo danh mục -> Tạo sản phẩm serial -> Nhập serial -> Kiểm định 18 bước đạt chuẩn
    e2eHttp('POST', $baseUrl . '/admin/danh-muc/them', $admJar, [
        '_csrf_token'   => $csrfAdm,
        'name'          => 'PC E2E Custom',
        'slug'          => 'pc-e2e-custom',
        'parent_id'     => '',
        'description'   => 'Danh mục E2E',
        'display_order' => '1',
        'is_active'     => '1',
    ]);
    $catId = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'pc-e2e-custom'")->fetchColumn();

    e2eHttp('POST', $baseUrl . '/admin/san-pham/them', $admJar, [
        '_csrf_token'        => $csrfAdm,
        'category_id'        => (string) $catId,
        'name'               => 'PC Gaming E2E RTX 4060',
        'slug'               => 'pc-gaming-e2e-rtx-4060',
        'sku'                => 'SKU-E2E-4060',
        'price'              => '15000000',
        'original_price'     => '17000000',
        'warranty_months'    => '12',
        'condition_overview' => 'Like New 99%',
        'has_serials'        => '1',
        'stock_quantity'     => '0',
        'status'             => 'published',
        'spec_group'         => ['Cấu hình'],
        'spec_name'          => ['GPU'],
        'spec_value'         => ['RTX 4060 8GB'],
        'spec_order'         => ['1'],
    ]);
    $newProdId = (int) $pdo->query("SELECT id FROM products WHERE sku = 'SKU-E2E-4060'")->fetchColumn();

    e2eHttp('POST', $baseUrl . '/admin/serial/them', $admJar, [
        '_csrf_token'    => $csrfAdm,
        'product_id'     => (string) $newProdId,
        'serial_number'  => 'SN-E2E-4060-001',
        'cosmetic_grade' => 'Grade A',
        'status'         => 'repairing',
        'import_price'   => '12000000',
    ]);
    $unitId = (int) $pdo->query("SELECT id FROM product_units WHERE serial_number = 'SN-E2E-4060-001'")->fetchColumn();

    $inspPost = [
        '_csrf_token'              => $csrfAdm,
        'overall_result'           => 'passed',
        'notes'                    => 'Đạt chuẩn 18 bước E2E',
        'mark_available_if_passed' => '1',
        'steps'                    => [],
    ];
    for ($s = 1; $s <= 18; $s++) {
        $inspPost['steps'][$s] = ['result' => 'pass', 'note' => 'OK'];
    }
    e2eHttp('POST', $baseUrl . "/admin/serial/{$unitId}/kiem-dinh/them", $admJar, $inspPost);
    $unitAfterInsp  = $pdo->query("SELECT status FROM product_units WHERE id = {$unitId}")->fetchColumn();
    $stockAfterInsp = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$newProdId}")->fetchColumn();
    recordE2E('E2E-A2', 'Admin tạo Danh mục -> Sản phẩm Serial -> Nhập Serial -> Kiểm định 18 bước (available, stock=1)', ($catId > 0 && $newProdId > 0 && $unitId > 0 && $unitAfterInsp === 'available' && $stockAfterInsp === 1) ? 'PASS' : 'FAIL');

    // Guest đặt mua chính chiếc máy đó -> available -> reserved -> Admin xác nhận -> thu tiền -> giao hàng -> hoàn tất -> sold
    e2eHttp('POST', $baseUrl . '/gio-hang/them', $gJar, ['_csrf_token' => $csrfG, 'product_id' => $newProdId, 'quantity' => 1], ['X-Requested-With: XMLHttpRequest']);
    $rGCk2 = e2eHttp('GET', $baseUrl . '/thanh-toan', $gJar);
    $rGPl2 = e2eHttp('POST', $baseUrl . '/thanh-toan', $gJar, [
        '_csrf_token'       => e2eCsrf($rGCk2['body']),
        'idempotency_key'   => e2eIdemKey($rGCk2['body']),
        'customer_name'     => 'Người Mua Máy E2E',
        'customer_phone'    => '0933445566',
        'customer_email'    => 'buyer_e2e@tlgaming.test',
        'delivery_method'   => 'delivery',
        'shipping_city'     => 'Hà Nội',
        'shipping_district' => 'Cầu Giấy',
        'shipping_address'  => '88 Xuân Thủy',
        'payment_method'    => 'cod',
        'agree_policy'      => '1',
    ]);
    $serOrder = $pdo->query("SELECT id, final_amount FROM orders WHERE customer_email = 'buyer_e2e@tlgaming.test' ORDER BY id DESC LIMIT 1")->fetch();
    $serOrdId = (int) $serOrder['id'];
    $stRes    = (string) $pdo->query("SELECT status FROM product_units WHERE id = {$unitId}")->fetchColumn();

    e2eHttp('POST', $baseUrl . "/admin/don-hang/{$serOrdId}/xac-nhan", $admJar, ['_csrf_token' => $csrfAdm]);
    e2eHttp('POST', $baseUrl . "/admin/don-hang/{$serOrdId}/xac-nhan-thanh-toan", $admJar, ['_csrf_token' => $csrfAdm, 'transaction_code' => 'COD-E2E-01', 'payment_note' => 'Đã thu đủ']);
    e2eHttp('POST', $baseUrl . "/admin/don-hang/{$serOrdId}/giao-hang", $admJar, ['_csrf_token' => $csrfAdm, 'action_note' => 'Đã giao']);
    e2eHttp('POST', $baseUrl . "/admin/don-hang/{$serOrdId}/hoan-tat", $admJar, ['_csrf_token' => $csrfAdm, 'action_note' => 'Hoàn tất']);

    $stSold     = (string) $pdo->query("SELECT status FROM product_units WHERE id = {$unitId}")->fetchColumn();
    $ordFinalSt = (string) $pdo->query("SELECT status FROM orders WHERE id = {$serOrdId}")->fetchColumn();
    $rDashAfter = e2eHttp('GET', $baseUrl . '/admin?ky=today', $admJar);
    $todayRev   = (int) $pdo->query("SELECT COALESCE(SUM(final_amount), 0) FROM orders WHERE status = 'completed' AND created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
    $revText    = number_format($todayRev, 0, ',', '.') . 'đ';
    recordE2E('E2E-A3', 'Mua máy serial (available->reserved) -> Admin xác nhận/thu tiền/giao/hoàn tất (reserved->sold) & lên Dashboard', ($stRes === 'reserved' && $stSold === 'sold' && $ordFinalSt === 'completed' && $rDashAfter['status'] === 200 && str_contains($rDashAfter['body'], $revText)) ? 'PASS' : 'FAIL', "res={$stRes}, sold={$stSold}, ord={$ordFinalSt}, rev={$revText}");

    // Tạo voucher -> áp dụng -> hủy đơn kiểm tra hoàn tồn + hoàn voucher + audit log -> tắt voucher -> Checkout từ chối
    e2eHttp('POST', $baseUrl . '/admin/voucher/them', $admJar, [
        '_csrf_token'         => $csrfAdm,
        'code'                => 'E2E_SAVE100K',
        'description'         => 'Voucher E2E',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '100000',
        'max_discount_amount' => '',
        'min_order_amount'    => '0',
        'usage_limit'         => '5',
        'per_user_limit'      => '2',
        'start_date'          => date('Y-m-d\TH:i', strtotime('-1 hour')),
        'end_date'            => date('Y-m-d\TH:i', strtotime('+7 days')),
        'is_active'           => '1',
    ]);
    $vE2eId = (int) $pdo->query("SELECT id FROM coupons WHERE code = 'E2E_SAVE100K'")->fetchColumn();

    // Guest đặt đơn có voucher rồi Admin hủy -> kiểm tra hoàn tồn, hoàn voucher & audit log
    $qtyBefore = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyId}")->fetchColumn();
    e2eHttp('POST', $baseUrl . '/gio-hang/them', $gJar, ['_csrf_token' => $csrfG, 'product_id' => $qtyId, 'quantity' => 2], ['X-Requested-With: XMLHttpRequest']);
    $rGCk3 = e2eHttp('GET', $baseUrl . '/thanh-toan', $gJar);
    e2eHttp('POST', $baseUrl . '/thanh-toan', $gJar, [
        '_csrf_token'       => e2eCsrf($rGCk3['body']),
        'idempotency_key'   => e2eIdemKey($rGCk3['body']),
        'customer_name'     => 'Hủy Đơn E2E',
        'customer_phone'    => '0977665544',
        'customer_email'    => 'cancel_e2e@tlgaming.test',
        'delivery_method'   => 'delivery',
        'shipping_city'     => 'Hà Nội',
        'shipping_district' => 'Ba Đình',
        'shipping_address'  => '12 Kim Mã',
        'payment_method'    => 'cod',
        'coupon_code'       => 'E2E_SAVE100K',
        'agree_policy'      => '1',
    ]);
    $cancOrdId  = (int) $pdo->query("SELECT id FROM orders WHERE customer_email = 'cancel_e2e@tlgaming.test' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $vUsedMid   = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = {$vE2eId}")->fetchColumn();
    e2eHttp('POST', $baseUrl . "/admin/don-hang/{$cancOrdId}/huy", $admJar, ['_csrf_token' => $csrfAdm, 'cancel_reason' => 'Khách đổi ý E2E']);
    $qtyAfter   = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$qtyId}")->fetchColumn();
    $vUsedAfter = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = {$vE2eId}")->fetchColumn();
    $auditCnt   = (int) $pdo->query("SELECT COUNT(*) FROM order_status_logs WHERE order_id = {$cancOrdId} AND action_type = 'cancel'")->fetchColumn();

    // Tắt voucher + Khóa/mở khóa tài khoản khách
    e2eHttp('POST', $baseUrl . "/admin/voucher/{$vE2eId}/tat", $admJar, ['_csrf_token' => $csrfAdm]);
    $vOffSt = (int) $pdo->query("SELECT is_active FROM coupons WHERE id = {$vE2eId}")->fetchColumn();
    e2eHttp('POST', $baseUrl . "/admin/khach-hang/{$newCustId}/khoa", $admJar, ['_csrf_token' => $csrfAdm]);
    $stLocked = (string) $pdo->query("SELECT status FROM users WHERE id = {$newCustId}")->fetchColumn();
    e2eHttp('POST', $baseUrl . "/admin/khach-hang/{$newCustId}/mo-khoa", $admJar, ['_csrf_token' => $csrfAdm]);
    $stUnlocked = (string) $pdo->query("SELECT status FROM users WHERE id = {$newCustId}")->fetchColumn();

    recordE2E('E2E-A4', 'Hủy đơn hoàn tồn kho & hoàn voucher + Audit log + Tắt voucher + Khóa/Mở khóa khách', ($qtyAfter === $qtyBefore && $vUsedMid === 1 && $vUsedAfter === 0 && $auditCnt === 1 && $vOffSt === 0 && $stLocked === 'locked' && $stUnlocked === 'active') ? 'PASS' : 'FAIL', "qty={$qtyBefore}->{$qtyAfter}, vMid={$vUsedMid}, vAfter={$vUsedAfter}, audit={$auditCnt}, vOff={$vOffSt}, lock={$stLocked}->{$stUnlocked}");
} finally {
    if (is_resource($server['proc'] ?? null)) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
    foreach ([$custJar, $gJar, $admJar] as $jar) {
        if (is_string($jar) && file_exists($jar)) {
            @unlink($jar);
        }
    }
}

$failed = array_filter($results, static fn(array $r): bool => $r['status'] !== 'PASS');
echo "\n================================================================================\n";
echo 'TỔNG KẾT E2E INTEGRATION TESTS: PASS=' . (count($results) - count($failed)) . ' | FAIL=' . count($failed) . ' (Tổng ' . count($results) . ")\n";
echo "================================================================================\n";
exit(count($failed) === 0 ? 0 : 1);
