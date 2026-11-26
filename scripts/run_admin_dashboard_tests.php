<?php
/**
 * Bộ kiểm thử Tích hợp Dashboard Kinh doanh & Phân tích Doanh thu Admin (Prompt 21) cho TL Gaming.
 * Chạy trên CSDL cô lập `tl_gaming_test` (bắt buộc `--confirm-test-db`), không tác động `tl_gaming`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Core/CheckoutException.php';
require_once __DIR__ . '/../app/Models/ProductUnitModel.php';
require_once __DIR__ . '/../app/Models/InspectionModel.php';
require_once __DIR__ . '/../app/Models/AdminCouponModel.php';
require_once __DIR__ . '/../app/Models/AdminDashboardModel.php';

use App\Models\AdminCouponModel;
use App\Models\AdminDashboardModel;
use App\Models\InspectionModel;

const DASH_TEST_DB = 'tl_gaming_test';

putenv('DB_DATABASE=' . DASH_TEST_DB);
$_ENV['DB_DATABASE']    = DASH_TEST_DB;
$_SERVER['DB_DATABASE'] = DASH_TEST_DB;

$args = $argv ?? [];
if (!in_array('--confirm-test-db', $args, true)) {
    fwrite(STDERR, "Từ chối chạy: Bắt buộc cờ `--confirm-test-db`.\n");
    exit(1);
}
if ((string) ($_ENV['DB_DATABASE'] ?? '') !== DASH_TEST_DB || DASH_TEST_DB === 'tl_gaming') {
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
$rootPdo->exec('DROP DATABASE IF EXISTS `' . DASH_TEST_DB . '`');
$rootPdo->exec('CREATE DATABASE `' . DASH_TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$schemaSql = str_replace('`tl_gaming`', '`' . DASH_TEST_DB . '`', (string) file_get_contents($projectRoot . '/database/tl_gaming.sql'));
$seedSql   = str_replace('`tl_gaming`', '`' . DASH_TEST_DB . '`', (string) file_get_contents($projectRoot . '/database/seed_tl_gaming.sql'));

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
fwrite($resetPipes[0], 'USE `' . DASH_TEST_DB . "`;\n" . $schemaSql . "\n" . $seedSql);
fclose($resetPipes[0]);
stream_get_contents($resetPipes[1]);
$resetErr = stream_get_contents($resetPipes[2]);
fclose($resetPipes[1]);
fclose($resetPipes[2]);
if (proc_close($resetProc) !== 0) {
    fwrite(STDERR, "Lỗi reset DB test: {$resetErr}\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname=" . DASH_TEST_DB . ';charset=utf8mb4',
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$results = [];
function recordD21(string $code, string $name, string $status, string $evidence = ''): void
{
    global $results;
    $results[] = ['code' => $code, 'name' => $name, 'status' => $status, 'evidence' => $evidence];
    echo sprintf("%-6s| %-7s | %s\n", $code, $status, $name);
    if ($evidence !== '') {
        echo "      -> Bằng chứng: {$evidence}\n";
    }
}

function startD21Server(string $projectRoot): array
{
    $svrPort    = random_int(19600, 19899);
    $phpBin     = PHP_BINARY ?: 'E:\\xampp\\php\\php.exe';
    $routerFile = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
    $docRoot    = $projectRoot . DIRECTORY_SEPARATOR . 'public';
    $env        = getenv();
    $env['DB_DATABASE'] = DASH_TEST_DB;
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
    throw new RuntimeException('PHP test server không phản hồi.');
}

function httpReq21(string $method, string $url, ?string $cookieJar = null, mixed $postFields = null): array
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

    return [
        'status' => $status,
        'body'   => substr($raw, $headerSize),
    ];
}

function getCsrf21(string $html): string
{
    if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function login21(string $baseUrl, string $cookieJar, string $email, string $password): bool
{
    $p    = httpReq21('GET', $baseUrl . '/dang-nhap', $cookieJar);
    $csrf = getCsrf21($p['body']);
    $r    = httpReq21('POST', $baseUrl . '/dang-nhap', $cookieJar, [
        '_csrf_token' => $csrf,
        'email'       => $email,
        'password'    => $password,
    ]);
    return $r['status'] === 302;
}

echo "================================================================================\n";
echo "TL GAMING — KIỂM THỬ ADMIN BUSINESS DASHBOARD & ANALYTICS (PROMPT 21)\n";
echo "CSDL test: " . DASH_TEST_DB . "\n";
echo "================================================================================\n\n";

$dashModel = new AdminDashboardModel($pdo);
$server    = startD21Server($projectRoot);
$baseUrl   = $server['baseUrl'];

$tmpDir   = sys_get_temp_dir();
$guestJar = tempnam($tmpDir, 'd21_g_');
$custJar  = tempnam($tmpDir, 'd21_c_');
$adminJar = tempnam($tmpDir, 'd21_a_');

try {
    $passAdmin = password_hash('Admin@123', PASSWORD_BCRYPT);
    $passCust  = password_hash('Customer@123', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at) VALUES ('Admin 21', 'admin21@tlgaming.test', '0900000021', :p1, 'admin', 'active', NOW()), ('Cust 21', 'cust21@tlgaming.test', '0900000022', :p2, 'customer', 'active', NOW())")
        ->execute([':p1' => $passAdmin, ':p2' => $passCust]);
    $custId = (int) $pdo->query("SELECT id FROM users WHERE email = 'cust21@tlgaming.test'")->fetchColumn();

    login21($baseUrl, $custJar, 'cust21@tlgaming.test', 'Customer@123');
    login21($baseUrl, $adminJar, 'admin21@tlgaming.test', 'Admin@123');

    // D01-D03: RBAC Guest/Customer/Admin
    $rG = httpReq21('GET', $baseUrl . '/admin', $guestJar);
    $rC = httpReq21('GET', $baseUrl . '/admin', $custJar);
    $rA = httpReq21('GET', $baseUrl . '/admin', $adminJar);
    recordD21('D01', 'Guest và Customer bị chặn HTTP 403 khi truy cập /admin', ($rG['status'] === 403 && $rC['status'] === 403) ? 'PASS' : 'FAIL');
    recordD21('D03', 'Admin truy cập Dashboard /admin HTTP 200 OK', ($rA['status'] === 200 && str_contains($rA['body'], 'Tổng quan kinh doanh')) ? 'PASS' : 'FAIL');

    // D04 & D24: CSDL chưa có đơn (xóa sạch orders/payments/order_items trên DB test)
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE order_status_logs');
    $pdo->exec('TRUNCATE TABLE coupon_usages');
    $pdo->exec('TRUNCATE TABLE payments');
    $pdo->exec('TRUNCATE TABLE order_items');
    $pdo->exec('TRUNCATE TABLE orders');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $range30 = AdminDashboardModel::resolveDateRange('30d');
    $emptyKpi = $dashModel->getKpiSummary($range30['start_at'], $range30['end_at']);
    $emptySer = $dashModel->getRevenueSeries($range30['start_at'], $range30['end_at'], 'day');
    recordD21('D04', 'CSDL chưa có đơn: doanh thu=0, AOV=0 (không chia cho 0), biểu đồ has_data=false và đủ 30 mốc', ($emptyKpi['completed_revenue'] === 0 && $emptyKpi['aov'] === 0 && !$emptySer['has_data'] && count($emptySer['points']) === 30) ? 'PASS' : 'FAIL', 'points=' . count($emptySer['points']));

    // Chuẩn bị dữ liệu đơn hàng đa dạng:
    // - Đơn 1 (completed, user=$custId, 2 order_items -> kiểm tra không nhân bản doanh thu; 2 payments -> kiểm tra không nhân bản tiền thu)
    // - Đơn 2 (completed, Guest, 0đ -> kiểm tra đơn 0đ & không gộp Guest vào Customer)
    // - Đơn 3 (pending -> không tính doanh thu completed)
    // - Đơn 4 (cancelled + payments completed + refund_status=manual_required -> không tính doanh thu, lên cảnh báo hoàn tiền)
    $prodA = (int) $pdo->query('SELECT id FROM products WHERE has_serials = 0 ORDER BY id ASC LIMIT 1')->fetchColumn();
    $prodB = (int) $pdo->query('SELECT id FROM products WHERE has_serials = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
    $nowDt = date('Y-m-d H:i:s');

    // Đơn 1: completed, final_amount = 3.000.000, có 2 dòng order_items (1.000.000 + 2.000.000) và 2 dòng payments completed
    $pdo->prepare("INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, delivery_method, total_product_amount, shipping_fee, discount_amount, final_amount, status, created_at) VALUES ('TLG-D21-001', :uid, 'Cust 21', 'cust21@tlgaming.test', '0900000022', 'HN', 'Hà Nội', 'delivery', 3000000, 0, 0, 3000000, 'completed', :dt)")
        ->execute([':uid' => $custId, ':dt' => $nowDt]);
    $o1 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, product_sku, price, quantity, subtotal, warranty_period) VALUES (:oid, :pa, 'Snapshot SP A', 'SKU-A', 500000, 2, 1000000, '12T'), (:oid2, :pb, 'Snapshot SP B', 'SKU-B', 2000000, 1, 2000000, '12T')")
        ->execute([':oid' => $o1, ':pa' => $prodA, ':oid2' => $o1, ':pb' => $prodB]);
    // 2 bản ghi payments completed trên cùng 1 đơn (đảm bảo chỉ tính 1 lần, không nhân đôi thành 6.000.000)
    $pdo->prepare("INSERT INTO payments (order_id, payment_method, amount, status, confirmed_at, created_at) VALUES (:oid1, 'bank_transfer', 3000000, 'completed', :dt1, :dt1b), (:oid2, 'bank_transfer', 3000000, 'completed', :dt2, :dt2b)")
        ->execute([':oid1' => $o1, ':dt1' => $nowDt, ':dt1b' => $nowDt, ':oid2' => $o1, ':dt2' => $nowDt, ':dt2b' => $nowDt]);

    // Đơn 2: completed, Guest (user_id = NULL, trùng email/SĐT), final_amount = 0đ
    $pdo->prepare("INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, delivery_method, total_product_amount, shipping_fee, discount_amount, final_amount, status, created_at) VALUES ('TLG-D21-002', NULL, 'Guest 0d', 'cust21@tlgaming.test', '0900000022', 'HN', 'Hà Nội', 'pickup', 500000, 0, 500000, 0, 'completed', :dt)")
        ->execute([':dt' => $nowDt]);
    $o2 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, product_sku, price, quantity, subtotal, warranty_period) VALUES (:oid, :pa, 'Snapshot SP A', 'SKU-A', 500000, 1, 500000, '12T')")
        ->execute([':oid' => $o2, ':pa' => $prodA]);
    $pdo->prepare("INSERT INTO payments (order_id, payment_method, amount, status, confirmed_at, created_at) VALUES (:oid, 'cod', 0, 'completed', :dt1, :dt2)")
        ->execute([':oid' => $o2, ':dt1' => $nowDt, ':dt2' => $nowDt]);

    // Đơn 3: pending (5.000.000) -> không vào doanh thu completed
    $pdo->prepare("INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, delivery_method, total_product_amount, shipping_fee, discount_amount, final_amount, status, created_at) VALUES ('TLG-D21-003', NULL, 'Khách Pending', 'p@test.vn', '0911223344', 'HN', 'Hà Nội', 'delivery', 5000000, 0, 0, 5000000, 'pending', :dt)")
        ->execute([':dt' => $nowDt]);
    $o3 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payments (order_id, payment_method, amount, status, created_at) VALUES (:oid, 'cod', 5000000, 'pending', :dt)")
        ->execute([':oid' => $o3, ':dt' => $nowDt]);

    // Đơn 4: cancelled (4.000.000, đã thu tiền cần hoàn thủ công)
    $pdo->prepare("INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, delivery_method, total_product_amount, shipping_fee, discount_amount, final_amount, status, cancel_reason, cancelled_at, created_at) VALUES ('TLG-D21-004', NULL, 'Khách Hủy', 'c@test.vn', '0911223355', 'HN', 'Hà Nội', 'delivery', 4000000, 0, 0, 4000000, 'cancelled', 'Hủy test', :dt1, :dt2)")
        ->execute([':dt1' => $nowDt, ':dt2' => $nowDt]);
    $o4 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, product_sku, price, quantity, subtotal, warranty_period) VALUES (:oid, :pa, 'Snapshot SP A', 'SKU-A', 400000, 10, 4000000, '12T')")
        ->execute([':oid' => $o4, ':pa' => $prodA]);
    $pdo->prepare("INSERT INTO payments (order_id, payment_method, amount, status, refund_status, confirmed_at, created_at) VALUES (:oid, 'bank_transfer', 4000000, 'completed', 'manual_required', :dt1, :dt2)")
        ->execute([':oid' => $o4, ':dt1' => $nowDt, ':dt2' => $nowDt]);

    // D05-D11: Doanh thu, không cộng pending/cancelled, đơn 0đ, chống nhân bản items & payments, AOV, không gộp Guest
    $kpi = $dashModel->getKpiSummary($range30['start_at'], $range30['end_at']);
    $kpiOk = $kpi['completed_revenue'] === 3000000
        && $kpi['completed_orders'] === 2
        && $kpi['aov'] === 1500000
        && $kpi['confirmed_cash_collected'] === 7000000 // o1(3tr, không nhân đôi) + o2(0) + o4(4tr đã thu trước khi hủy)
        && $kpi['manual_refund_orders'] === 1
        && $kpi['manual_refund_amount'] === 4000000
        && $kpi['active_buying_customers'] === 1; // Chỉ tính $custId, không gộp Guest o2
    recordD21('D05', 'Doanh thu=3tr (2 đơn completed gồm đơn 0đ), AOV=1.5tr, không nhân bản items/payments, không gộp Guest', $kpiOk ? 'PASS' : 'FAIL', json_encode($kpi));

    // D12-D15: Biểu đồ ngày/tháng, lọc năm, lọc custom & chặn ngày bất thường
    $yearRange = AdminDashboardModel::resolveDateRange('year');
    $yearSer   = $dashModel->getRevenueSeries($yearRange['start_at'], $yearRange['end_at'], $yearRange['granularity']);
    $badRange1 = AdminDashboardModel::resolveDateRange('custom', '2026-11-20', '2026-11-10'); // end < start
    $badRange2 = AdminDashboardModel::resolveDateRange('custom', '2024-01-01', '2026-11-20'); // > 366 ngày
    $badRange3 = AdminDashboardModel::resolveDateRange('custom', 'not-a-date', '2026-11-20');
    recordD21('D13', 'Biểu đồ năm đủ 12 tháng & chặn ngày tùy chọn bất thường (rơi về 30d + báo lỗi)', (count($yearSer['points']) === 12 && $badRange1['error'] !== '' && $badRange2['error'] !== '' && $badRange3['error'] !== '') ? 'PASS' : 'FAIL');

    // D16-D18: Phân bố trạng thái & Top sản phẩm bán chạy theo snapshot (không tính đơn cancelled)
    $dist = $dashModel->getOrderStatusDistribution($range30['start_at'], $range30['end_at']);
    $top  = $dashModel->getTopSellingProducts($range30['start_at'], $range30['end_at'], 5);
    // SP A: o1 (2) + o2 (1) = 3 (KHÔNG cộng 10 từ o4 cancelled!), snapshot_revenue = 1.000.000 + 500.000 = 1.500.000
    $topOk = count($top) === 2
        && (int) $top[0]['product_id'] === $prodA
        && (int) $top[0]['sold_quantity'] === 3
        && (int) $top[0]['snapshot_revenue'] === 1500000;
    recordD21('D16', 'Phân bố trạng thái đơn & Top sản phẩm bán chạy đúng snapshot (không tính đơn cancelled)', ($dist['completed'] === 2 && $dist['pending'] === 1 && $dist['cancelled'] === 1 && $topOk) ? 'PASS' : 'FAIL');

    // D20-D23: Đơn hàng gần đây không lộ email/SĐT, cảnh báo vận hành & serial đạt chuẩn 18 bước
    $recent = $dashModel->getRecentOrders(10);
    $alerts = $dashModel->getOperationalAlerts();
    $rDash  = httpReq21('GET', $baseUrl . '/admin?ky=30d', $adminJar);
    $noPiiLeak = !str_contains($rDash['body'], '0911223344') && !str_contains($rDash['body'], 'p@test.vn');
    $alertsOk  = $alerts['pending_orders'] === 1 && $alerts['manual_refund_orders'] === 1 && $alerts['manual_refund_amount'] === 4000000;
    recordD21('D20', 'Đơn gần đây đúng thứ tự, không lộ SĐT/email trên Dashboard & cảnh báo vận hành chính xác', (count($recent) === 4 && $recent[0]['order_code'] === 'TLG-D21-004' && $noPiiLeak && $alertsOk) ? 'PASS' : 'FAIL');

    // D23: Thêm 1 máy serial đạt 18 bước passed -> sellable_serial_units tăng đúng 1
    $beforeSellable = $dashModel->getKpiSummary($range30['start_at'], $range30['end_at'])['sellable_serial_units'];
    $pdo->prepare("INSERT INTO product_units (product_id, serial_number, status, cosmetic_grade, import_price, row_version, imported_at) VALUES (:pid, 'SN-D21-PASS', 'repairing', 'Grade A', 10000000, 1, NOW())")->execute([':pid' => $prodB]);
    $newUnitId = (int) $pdo->lastInsertId();
    $items18   = [];
    foreach (InspectionModel::OFFICIAL_18_STEPS as $stepNum => $meta) {
        $items18[] = ['step_number' => (string) $stepNum, 'result' => 'pass', 'notes' => 'OK'];
    }
    (new InspectionModel($pdo))->createInspection($newUnitId, 1, 'passed', 'D21 test', $items18, true);
    $afterSellable = $dashModel->getKpiSummary($range30['start_at'], $range30['end_at'])['sellable_serial_units'];
    recordD21('D23', 'Chỉ số máy serial đủ điều kiện bán khớp chuẩn kiểm định 18 bước', ($afterSellable === $beforeSellable + 1) ? 'PASS' : 'FAIL', "{$beforeSellable}->{$afterSellable}");

    // D28 (Mục IV): Test HTTP tạo & sửa voucher THIẾU is_active phải bị từ chối và KHÔNG tự tắt voucher đang bật
    $cpModel = new AdminCouponModel($pdo);
    $vId     = $cpModel->createCoupon([
        'code'                => 'D21_KEEP_ON',
        'description'         => 'Giữ nguyên bật khi thiếu is_active',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '50000',
        'max_discount_amount' => '',
        'min_order_amount'    => '0',
        'usage_limit'         => '10',
        'per_user_limit'      => '1',
        'start_date'          => date('Y-m-d H:i:s', strtotime('-1 day')),
        'end_date'            => date('Y-m-d H:i:s', strtotime('+7 days')),
        'is_active'           => '1',
    ]);
    $rCpForm = httpReq21('GET', $baseUrl . '/admin/voucher/' . $vId . '/sua', $adminJar);
    $csrf    = getCsrf21($rCpForm['body']);

    // POST tạo mới thiếu is_active -> bị từ chối (không chèn vào DB)
    $rCreateMiss = httpReq21('POST', $baseUrl . '/admin/voucher/them', $adminJar, [
        '_csrf_token'         => $csrf,
        'code'                => 'D21_MISSING_ACT',
        'description'         => 'Thiếu is_active',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '50000',
        'max_discount_amount' => '',
        'min_order_amount'    => '0',
        'usage_limit'         => '10',
        'per_user_limit'      => '1',
        'start_date'          => date('Y-m-d\TH:i', strtotime('-1 day')),
        'end_date'            => date('Y-m-d\TH:i', strtotime('+7 days')),
    ]);
    $createdExists = (int) $pdo->query("SELECT COUNT(*) FROM coupons WHERE code = 'D21_MISSING_ACT'")->fetchColumn();

    // POST sửa D21_KEEP_ON thiếu is_active -> bị từ chối, giữ nguyên is_active = 1
    $rEditMiss = httpReq21('POST', $baseUrl . '/admin/voucher/' . $vId . '/sua', $adminJar, [
        '_csrf_token'         => $csrf,
        'code'                => 'D21_KEEP_ON',
        'description'         => 'Thử sửa thiếu is_active',
        'discount_type'       => 'fixed_amount',
        'discount_value'      => '50000',
        'max_discount_amount' => '',
        'min_order_amount'    => '0',
        'usage_limit'         => '10',
        'per_user_limit'      => '1',
        'start_date'          => date('Y-m-d\TH:i', strtotime('-1 day')),
        'end_date'            => date('Y-m-d\TH:i', strtotime('+7 days')),
    ]);
    $stillOn = (int) $pdo->query("SELECT is_active FROM coupons WHERE id = {$vId}")->fetchColumn();
    recordD21('D28', 'HTTP POST tạo/sửa voucher thiếu is_active bị từ chối & không tự tắt voucher đang bật', ($rCreateMiss['status'] === 302 && $createdExists === 0 && $rEditMiss['status'] === 302 && $stillOn === 1) ? 'PASS' : 'FAIL', "created={$createdExists}, still_on={$stillOn}");
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
echo 'TỔNG KẾT ADMIN DASHBOARD TESTS: PASS=' . (count($results) - count($failed)) . ' | FAIL=' . count($failed) . ' (Tổng ' . count($results) . ")\n";
echo "================================================================================\n";
exit(count($failed) === 0 ? 0 : 1);
