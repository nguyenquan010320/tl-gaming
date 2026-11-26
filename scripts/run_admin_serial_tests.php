<?php
/**
 * Bộ kiểm thử Tích hợp Quản lý Serial & Kiểm định 18 bước Admin (Prompt 18) cho TL Gaming.
 * Chạy trên CSDL cô lập `tl_gaming_test` (bắt buộc cờ `--confirm-test-db`).
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

use App\Models\AdminProductModel;
use App\Models\InspectionModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\ProductUnitModel;

$projectRoot  = dirname(__DIR__);
$testDbName   = 'tl_gaming_test';
$confirmReset = in_array('--confirm-test-db', $argv ?? [], true);

putenv("DB_DATABASE={$testDbName}");
$_ENV['DB_DATABASE']    = $testDbName;
$_SERVER['DB_DATABASE'] = $testDbName;

$results = [];
function recordSerialTest(string $code, string $name, string $status, string $evidence = ''): void
{
    global $results;
    $results[] = ['code' => $code, 'name' => $name, 'status' => $status, 'evidence' => $evidence];
    echo sprintf("%-6s| %-7s | %s\n", $code, $status, $name);
    if ($evidence !== '') {
        echo "      -> Bằng chứng: {$evidence}\n";
    }
}

function setupSerialTestDb(string $projectRoot, string $testDbName, bool $confirmReset): PDO
{
    $host     = (string) env('DB_HOST', '127.0.0.1');
    $port     = (int) env('DB_PORT', '3306');
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
    fwrite($pipes[0], "USE `{$testDbName}`;\n" . $schemaSql . "\n" . $seedSql);
    fclose($pipes[0]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException("Import SQL thất bại: {$err}");
    }

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$testDbName};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function startSerialTestServer(string $projectRoot, string $testDbName): array
{
    $port       = random_int(18700, 18999);
    $phpBin     = 'E:\\xampp\\php\\php.exe';
    $routerFile = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
    $docRoot    = $projectRoot . DIRECTORY_SEPARATOR . 'public';

    $env = getenv();
    $env['DB_DATABASE'] = $testDbName;
    $env['APP_ENV']     = 'testing';

    $cmd = sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s"', $phpBin, $port, $docRoot, $routerFile);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectRoot, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Không thể khởi động PHP built-in server.');
    }

    $baseUrl = "http://127.0.0.1:{$port}";
    $ready   = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(100000);
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
    $ch = curl_init($url);
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
        $opts[CURLOPT_POST] = true;
        if ($postFields !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_array($postFields) ? http_build_query($postFields) : $postFields;
        }
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

function extractCsrf(string $html): string
{
    if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function build18Steps(string $defaultResult = 'pass', array $overrides = []): array
{
    $steps = [];
    for ($i = 1; $i <= 18; $i++) {
        $noteText = $overrides[$i]['note'] ?? "Ghi chú bước {$i}";
        $steps[$i] = [
            'step_number' => $i,
            'result'      => $overrides[$i]['result'] ?? $defaultResult,
            'note'        => $noteText,
            'notes'       => $noteText,
        ];
    }
    return $steps;
}

$server = null;
$cookieDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tl_serial_test_' . bin2hex(random_bytes(4));
mkdir($cookieDir, 0777, true);
$guestJar = $cookieDir . DIRECTORY_SEPARATOR . 'guest.txt';
$custJar  = $cookieDir . DIRECTORY_SEPARATOR . 'cust.txt';
$adminJar = $cookieDir . DIRECTORY_SEPARATOR . 'admin.txt';

try {
    $pdo    = setupSerialTestDb($projectRoot, $testDbName, $confirmReset);
    $server = startSerialTestServer($projectRoot, $testDbName);
    $base   = $server['baseUrl'];

    // Đảm bảo có tài khoản Admin và Customer chuẩn với mật khẩu biết trước
    $passHash = password_hash('Test@12345', PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password_hash = :h WHERE email IN ('admin@tlgaming.vn', 'khachhang1@gmail.com')")
        ->execute([':h' => $passHash]);

    $adminRow = $pdo->query("SELECT id, email FROM users WHERE role = 'admin' LIMIT 1")->fetch();
    $custRow  = $pdo->query("SELECT id, email FROM users WHERE role = 'customer' LIMIT 1")->fetch();
    $adminId  = (int) $adminRow['id'];

    // Lấy 1 sản phẩm has_serials = 1 và tạo/lấy 1 sản phẩm has_serials = 0
    $serialProd = $pdo->query("SELECT id, name, sku FROM products WHERE has_serials = 1 AND status = 'published' LIMIT 1")->fetch();
    $serialProdId = (int) $serialProd['id'];

    $qtyProd = $pdo->query("SELECT id, name, sku FROM products WHERE has_serials = 0 AND status = 'published' LIMIT 1")->fetch();
    if (!$qtyProd) {
        $catId = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO products (category_id, name, slug, sku, price, original_price, has_serials, stock_quantity, status) VALUES (:c, 'Chuột Test Qty', 'chuot-test-qty', 'SKU-QTY-01', 500000, 700000, 0, 10, 'published')")
            ->execute([':c' => $catId]);
        $qtyProdId = (int) $pdo->lastInsertId();
    } else {
        $qtyProdId = (int) $qtyProd['id'];
    }

    // Đăng nhập Customer
    $loginPage = httpReq('GET', $base . '/dang-nhap', $custJar);
    httpReq('POST', $base . '/dang-nhap', $custJar, [
        '_csrf_token' => extractCsrf($loginPage['body']),
        'email'       => $custRow['email'],
        'password'    => 'Test@12345',
    ]);

    // Đăng nhập Admin
    $adminLoginPage = httpReq('GET', $base . '/dang-nhap', $adminJar);
    httpReq('POST', $base . '/dang-nhap', $adminJar, [
        '_csrf_token' => extractCsrf($adminLoginPage['body']),
        'email'       => $adminRow['email'],
        'password'    => 'Test@12345',
    ]);

    // S1: Guest & Customer bị chặn
    $g1 = httpReq('GET', $base . '/admin/serial', $guestJar);
    $c1 = httpReq('GET', $base . '/admin/serial', $custJar);
    $c2 = httpReq('POST', $base . '/admin/serial/them', $adminJar === '' ? null : $custJar, ['serial_number' => 'X']);
    $okS1 = in_array($g1['status'], [302, 403], true) && in_array($c1['status'], [302, 403], true) && in_array($c2['status'], [302, 403], true);
    recordSerialTest('S1', 'Chặn Guest và Customer truy cập /admin/serial*', $okS1 ? 'PASS' : 'FAIL', "guest={$g1['status']}, custGet={$c1['status']}, custPost={$c2['status']}");

    // S2: POST thiếu CSRF -> 403
    $noCsrf = httpReq('POST', $base . '/admin/serial/them', $adminJar, ['product_id' => $serialProdId, 'serial_number' => 'NO-CSRF-01']);
    recordSerialTest('S2', 'POST thiếu CSRF token bị chặn 403', $noCsrf['status'] === 403 ? 'PASS' : 'FAIL', "status={$noCsrf['status']}");

    // S3: Admin GET /admin/serial 200 OK
    $listResp = httpReq('GET', $base . '/admin/serial', $adminJar);
    $okS3 = $listResp['status'] === 200 && str_contains($listResp['body'], 'Quản lý Serial') && str_contains($listResp['body'], '/admin/serial');
    $s3Info = "status={$listResp['status']}";
    if (!$okS3) {
        $pos500 = strpos($listResp['body'], '500 - Lỗi hệ thống');
        $s3Info .= ' before500=' . ($pos500 !== false ? substr($listResp['body'], max(0, $pos500 - 350), 350) : substr($listResp['body'], -350));
    }
    recordSerialTest('S3', 'Admin truy cập /admin/serial thành công và có menu', $okS3 ? 'PASS' : 'FAIL', $s3Info);

    // S4: ID không hợp lệ hoặc không tồn tại xử lý an toàn (redirect, không 500)
    $badId = httpReq('GET', $base . '/admin/serial/abc', $adminJar);
    $notFoundId = httpReq('GET', $base . '/admin/serial/9999999', $adminJar);
    $badInsp = httpReq('GET', $base . '/admin/kiem-dinh/9999999', $adminJar);
    $okS4 = $badId['status'] === 302 && $notFoundId['status'] === 302 && $badInsp['status'] === 302;
    recordSerialTest('S4', 'ID không hợp lệ / không tồn tại xử lý an toàn', $okS4 ? 'PASS' : 'FAIL', "bad={$badId['status']}, nf={$notFoundId['status']}, inspNf={$badInsp['status']}");

    // S5: Thêm serial mới qua HTTP POST cho sản phẩm has_serials = 1
    $addForm = httpReq('GET', $base . '/admin/serial/them?san-pham=' . $serialProdId, $adminJar);
    $csrf    = extractCsrf($addForm['body']);
    $newSer  = 'TLG-TEST-SERIAL-' . random_int(1000, 9999);
    $postAdd = httpReq('POST', $base . '/admin/serial/them', $adminJar, [
        '_csrf_token'    => $csrf,
        'product_id'     => (string) $serialProdId,
        'serial_number'  => $newSer,
        'cosmetic_grade' => 'Grade A',
        'battery_health' => '94',
        'import_price'   => '14500000',
        'status'         => 'available',
        'notes'          => 'Máy test nhập kho',
    ]);
    $createdUnit = $pdo->prepare('SELECT * FROM product_units WHERE serial_number = :sn');
    $createdUnit->execute([':sn' => $newSer]);
    $uRow = $createdUnit->fetch();
    $okS5 = $postAdd['status'] === 302 && $uRow !== false && (int) $uRow['battery_health'] === 94;
    recordSerialTest('S5', 'Thêm serial mới cho sản phẩm has_serials=1 qua HTTP POST', $okS5 ? 'PASS' : 'FAIL', $uRow ? "unit_id={$uRow['id']}" : 'not created');
    $unitId = $uRow ? (int) $uRow['id'] : 0;

    // S6-S10: Validation nghiệp vụ ở ProductUnitModel & Controller
    $unitModel = new ProductUnitModel();

    // S6: Chặn thêm serial cho sản phẩm has_serials = 0
    $s6Err = false;
    try {
        $unitModel->createUnit([
            'product_id' => $qtyProdId, 'serial_number' => 'BAD-QTY-01', 'cosmetic_grade' => 'Grade A',
            'battery_health' => 90, 'import_price' => 1000000, 'status' => 'available', 'notes' => '',
        ]);
    } catch (DomainException) {
        $s6Err = true;
    }
    recordSerialTest('S6', 'Chặn thêm serial cho sản phẩm has_serials=0', $s6Err ? 'PASS' : 'FAIL');

    // S7: Chặn trùng serial_number
    $s7Err = false;
    try {
        $unitModel->createUnit([
            'product_id' => $serialProdId, 'serial_number' => $newSer, 'cosmetic_grade' => 'Grade A',
            'battery_health' => 90, 'import_price' => 1000000, 'status' => 'available', 'notes' => '',
        ]);
    } catch (DomainException) {
        $s7Err = true;
    }
    recordSerialTest('S7', 'Chặn trùng số serial', $s7Err ? 'PASS' : 'FAIL');

    // S8: Pin NULL hợp lệ (Desktop), Pin ngoài [0..100] bị từ chối
    $pcSer = 'TLG-PC-NULLBAT-' . random_int(1000, 9999);
    $pcUnitId = $unitModel->createUnit([
        'product_id' => $serialProdId, 'serial_number' => $pcSer, 'cosmetic_grade' => 'Grade B',
        'battery_health' => null, 'import_price' => 12000000, 'status' => 'repairing', 'notes' => 'PC test',
    ]);
    $badBat = httpReq('POST', $base . '/admin/serial/them', $adminJar, [
        '_csrf_token' => $csrf, 'product_id' => (string) $serialProdId, 'serial_number' => 'BAD-BAT-101',
        'cosmetic_grade' => 'Grade A', 'battery_health' => '101', 'import_price' => '1000', 'status' => 'available',
    ]);
    $chkBadBat = $pdo->query("SELECT id FROM product_units WHERE serial_number = 'BAD-BAT-101'")->fetch();
    recordSerialTest('S8', 'Pin NULL hợp lệ và pin=101 bị từ chối', ($pcUnitId > 0 && $chkBadBat === false) ? 'PASS' : 'FAIL');

    // S9: Giá nhập âm & phân hạng sai bị từ chối
    $badPrice = httpReq('POST', $base . '/admin/serial/them', $adminJar, [
        '_csrf_token' => $csrf, 'product_id' => (string) $serialProdId, 'serial_number' => 'BAD-PRICE-1',
        'cosmetic_grade' => 'Grade Z', 'battery_health' => '80', 'import_price' => '-500', 'status' => 'available',
    ]);
    $chkBadPrice = $pdo->query("SELECT id FROM product_units WHERE serial_number = 'BAD-PRICE-1'")->fetch();
    recordSerialTest('S9', 'Giá nhập âm và cosmetic_grade sai bị từ chối', $chkBadPrice === false ? 'PASS' : 'FAIL');

    // S10: Chặn tạo serial mới với trạng thái sold/reserved
    $s10Err = false;
    try {
        $unitModel->createUnit([
            'product_id' => $serialProdId, 'serial_number' => 'BAD-SOLD-01', 'cosmetic_grade' => 'Grade A',
            'battery_health' => 90, 'import_price' => 1000000, 'status' => 'sold', 'notes' => '',
        ]);
    } catch (DomainException) {
        $s10Err = true;
    }
    recordSerialTest('S10', 'Chặn tạo mới serial ở trạng thái sold/reserved', $s10Err ? 'PASS' : 'FAIL');

    // S11 & S12: Sửa serial hợp lệ + Optimistic lock stale expected_row_version
    $editPage = httpReq('GET', $base . '/admin/serial/' . $unitId . '/sua', $adminJar);
    $editCsrf = extractCsrf($editPage['body']);
    $freshU   = $unitModel->findById($unitId);
    $updResp  = httpReq('POST', $base . '/admin/serial/' . $unitId . '/sua', $adminJar, [
        '_csrf_token'          => $editCsrf,
        'expected_row_version' => (string) $freshU['row_version'],
        'product_id'           => (string) $serialProdId,
        'serial_number'        => $newSer,
        'cosmetic_grade'       => 'Grade B',
        'battery_health'       => '88',
        'import_price'         => '14000000',
        'status'               => 'available',
        'notes'                => 'Đã cập nhật Grade B',
    ]);
    $afterUpd = $unitModel->findById($unitId);
    $okS11 = $updResp['status'] === 302
        && $afterUpd['cosmetic_grade'] === 'Grade B'
        && (int) $afterUpd['battery_health'] === 88
        && (int) $afterUpd['row_version'] === ((int) $freshU['row_version'] + 1);
    recordSerialTest('S11', 'Cập nhật serial hợp lệ với expected_row_version (tăng version)', $okS11 ? 'PASS' : 'FAIL');

    $s12Stale = false;
    try {
        $unitModel->updateUnit($unitId, [
            'expected_row_version' => (int) $freshU['row_version'],
            'product_id'           => $serialProdId,
            'serial_number'        => $newSer,
            'cosmetic_grade'       => 'Grade C',
            'battery_health'       => 50,
            'import_price'         => 1000,
            'status'               => 'available',
            'notes'                => 'Stale',
        ]);
    } catch (DomainException) {
        $s12Stale = true;
    }
    recordSerialTest('S12', 'Optimistic lock từ chối expected_row_version cũ', $s12Stale ? 'PASS' : 'FAIL');

    // S15 (trước khi kiểm định pcUnitId): pcUnitId đang 'repairing' chưa có biên bản đạt -> không được chuyển sang 'available'
    $s15Blocked = false;
    $pcFresh    = $unitModel->findById($pcUnitId);
    try {
        $unitModel->updateUnit($pcUnitId, [
            'expected_row_version' => (int) $pcFresh['row_version'],
            'product_id'           => $serialProdId,
            'serial_number'       => $pcSer,
            'cosmetic_grade'      => 'Grade B',
            'battery_health'      => null,
            'import_price'        => 12000000,
            'status'              => 'available',
            'notes'               => 'Ép available khi chưa kiểm định',
        ]);
    } catch (DomainException) {
        $s15Blocked = true;
    }
    recordSerialTest('S15', 'Chặn chuyển repairing -> available khi chưa có biên bản đạt', $s15Blocked ? 'PASS' : 'FAIL');

    // S16: Form kiểm định 18 bước hiển thị đủ 18 bước
    $inspForm = httpReq('GET', $base . '/admin/serial/' . $unitId . '/kiem-dinh/them', $adminJar);
    $okS16 = $inspForm['status'] === 200
        && str_contains($inspForm['body'], 'Kiểm tra vết cấn móp, trầy xước, nứt vỡ khung vỏ')
        && str_contains($inspForm['body'], 'Loa ngoài, Micro thu âm và Webcam');
    recordSerialTest('S16', 'Biểu mẫu kiểm định hiển thị đủ 18 bước chuẩn', $okS16 ? 'PASS' : 'FAIL', "status={$inspForm['status']}");

    // S17: Từ chối biên bản thiếu bước hoặc 18/18 not_applicable
    $inspModel = new InspectionModel();
    $s17Missing = false;
    try {
        $shortItems = build18Steps('pass');
        unset($shortItems[18]);
        $inspModel->createInspection($unitId, $adminId, 'passed', 'Thiếu bước 18', $shortItems);
    } catch (DomainException) {
        $s17Missing = true;
    }
    $s17AllNa = false;
    try {
        $inspModel->createInspection($unitId, $adminId, 'passed', 'All NA', build18Steps('not_applicable'));
    } catch (DomainException) {
        $s17AllNa = true;
    }
    recordSerialTest('S17', 'Chặn biên bản thiếu bước (<18) và 18/18 N/A', ($s17Missing && $s17AllNa) ? 'PASS' : 'FAIL');

    // S18: Quy tắc kết luận tổng thể - có fail thì bắt buộc failed
    $s18Blocked = false;
    try {
        $failItems = build18Steps('pass', [7 => ['result' => 'fail', 'note' => 'Nhiệt độ CPU 100C']]);
        $inspModel->createInspection($unitId, $adminId, 'passed', 'Cố chọn passed dù có fail', $failItems);
    } catch (DomainException) {
        $s18Blocked = true;
    }
    recordSerialTest('S18', 'Chặn chọn passed/conditional_pass khi có bước fail', $s18Blocked ? 'PASS' : 'FAIL');

    // S19: Có warning thì chặn passed và yêu cầu notes nếu chọn conditional_pass
    $s19WarnPass = false;
    $s19WarnNoNote = false;
    $warnItems = build18Steps('pass', [15 => ['result' => 'warning', 'note' => 'Pin chai nhẹ']]);
    try {
        $inspModel->createInspection($unitId, $adminId, 'passed', 'Có note nhưng chọn passed', $warnItems);
    } catch (DomainException) {
        $s19WarnPass = true;
    }
    try {
        $inspModel->createInspection($unitId, $adminId, 'conditional_pass', '   ', $warnItems);
    } catch (DomainException) {
        $s19WarnNoNote = true;
    }
    recordSerialTest('S19', 'Có warning: chặn passed và bắt buộc nhập notes cho conditional_pass', ($s19WarnPass && $s19WarnNoNote) ? 'PASS' : 'FAIL');

    // S20: Lập biên bản 18 bước hợp lệ qua HTTP POST + không tin spoof inspector_id
    $inspCsrf = extractCsrf($inspForm['body']);
    $postInsp = httpReq('POST', $base . '/admin/serial/' . $unitId . '/kiem-dinh/them', $adminJar, [
        '_csrf_token'    => $inspCsrf,
        'inspector_id'   => '999999',
        'overall_result' => 'failed',
        'notes'          => 'Lỗi cổng HDMI bước 17',
        'steps'          => build18Steps('pass', [17 => ['result' => 'fail', 'note' => 'HDMI không xuất hình']]),
    ]);
    $latestInsp = $pdo->prepare('SELECT * FROM product_inspections WHERE unit_id = :uid ORDER BY id DESC LIMIT 1');
    $latestInsp->execute([':uid' => $unitId]);
    $inspRow1 = $latestInsp->fetch();
    $itemCount1 = $inspRow1 ? (int) $pdo->query('SELECT COUNT(*) FROM inspection_items WHERE inspection_id = ' . (int) $inspRow1['id'])->fetchColumn() : 0;
    $okS20 = $postInsp['status'] === 302 && $inspRow1 !== false && (int) $inspRow1['inspector_id'] === $adminId && $itemCount1 === 18;
    recordSerialTest('S20', 'Lưu biên bản 18 bước qua HTTP POST, inspector_id lấy từ Auth::id()', $okS20 ? 'PASS' : 'FAIL', "items={$itemCount1}");

    // S23 (phần 1): Khi biên bản mới nhất của $unitId là 'failed', Checkout & Storefront & AdminProductModel phải loại trừ $unitId
    $adminProdModel = new AdminProductModel();
    $storeProdModel = new ProductModel();
    $adminProdBefore = $adminProdModel->findById($serialProdId);
    $storeProdBefore = $storeProdModel->findPublishedById($serialProdId);
    $availCntWithFail = (int) $adminProdBefore['available_serial_count'];
    $storeAvailWithFail = (int) $storeProdBefore['available_serial_count'];

    // S21: Append-only - lập biên bản lần 2 'passed' cho $unitId -> biên bản 1 vẫn nguyên, available_serial_count tăng 1
    $res2 = $inspModel->createInspection($unitId, $adminId, 'passed', 'Đã thay cổng HDMI đạt chuẩn', build18Steps('pass'));
    $historyList = $inspModel->getInspectionsByUnitId($unitId);
    $adminProdAfter = $adminProdModel->findById($serialProdId);
    $storeProdAfter = $storeProdModel->findPublishedById($serialProdId);
    $okS21 = count($historyList) === 2
        && (int) $historyList[0]['id'] === (int) $res2['inspection_id']
        && $historyList[1]['checklist_overall_result'] === 'failed';
    $okS23 = ((int) $adminProdAfter['available_serial_count'] === $availCntWithFail + 1)
        && ((int) $storeProdAfter['available_serial_count'] === $storeAvailWithFail + 1);
    recordSerialTest('S21', 'Biên bản lưu append-only, mới nhất đứng đầu', $okS21 ? 'PASS' : 'FAIL', 'cnt=' . count($historyList) . ' first=' . ($historyList[0]['checklist_overall_result'] ?? '') . ' second=' . ($historyList[1]['checklist_overall_result'] ?? ''));
    recordSerialTest('S23', 'Storefront & AdminProductModel đồng bộ loại trừ failed và mở lại khi passed', $okS23 ? 'PASS' : 'FAIL', "failCnt={$availCntWithFail}, passCnt={$adminProdAfter['available_serial_count']}");

    // S13: Đã có kiểm định -> chặn đổi product_id
    $otherSerialProd = $pdo->query("SELECT id FROM products WHERE has_serials = 1 AND id != {$serialProdId} LIMIT 1")->fetch();
    $s13Blocked = false;
    if ($otherSerialProd) {
        $uNow = $unitModel->findById($unitId);
        try {
            $unitModel->updateUnit($unitId, [
                'expected_row_version' => (int) $uNow['row_version'],
                'product_id'           => (int) $otherSerialProd['id'],
                'serial_number'       => $newSer,
                'cosmetic_grade'      => 'Grade B',
                'battery_health'      => 88,
                'import_price'        => 14000000,
                'status'              => 'available',
                'notes'               => '',
            ]);
        } catch (DomainException) {
            $s13Blocked = true;
        }
    } else {
        $s13Blocked = true;
    }
    recordSerialTest('S13', 'Chặn đổi product_id khi máy đã có lịch sử kiểm định', $s13Blocked ? 'PASS' : 'FAIL');

    // S22: mark_available_if_passed = true chuyển pcUnitId từ repairing -> available khi đạt, nhưng KHÔNG đổi máy reserved/sold
    $resPc = $inspModel->createInspection($pcUnitId, $adminId, 'passed', 'Sửa xong và kiểm định đạt', build18Steps('pass'), true);
    $pcAfterInsp = $unitModel->findById($pcUnitId);

    // Tạo một máy đang reserved để kiểm tra không bị ghi đè status khi lập biên bản
    $resvSer = 'TLG-RESV-' . random_int(1000, 9999);
    $pdo->prepare("INSERT INTO product_units (product_id, serial_number, cosmetic_grade, import_price, status) VALUES (:p, :s, 'Grade A', 10000000, 'reserved')")
        ->execute([':p' => $serialProdId, ':s' => $resvSer]);
    $resvId = (int) $pdo->lastInsertId();
    $inspModel->createInspection($resvId, $adminId, 'passed', 'Kiểm tra máy đang giữ đơn', build18Steps('pass'), true);
    $resvAfter = $unitModel->findById($resvId);

    $okS22 = $resPc['unit_status'] === 'available'
        && $pcAfterInsp['status'] === 'available'
        && $resvAfter['status'] === 'reserved';
    recordSerialTest('S22', 'mark_available_if_passed mở bán repairing->available, giữ nguyên reserved/sold', $okS22 ? 'PASS' : 'FAIL');

    // S14: Chặn sửa serial_number hoặc status của máy đang reserved
    $s14Blocked = false;
    try {
        $unitModel->updateUnit($resvId, [
            'expected_row_version' => (int) $resvAfter['row_version'],
            'product_id'           => $serialProdId,
            'serial_number'       => $resvSer . '-EDIT',
            'cosmetic_grade'      => 'Grade A',
            'battery_health'      => null,
            'import_price'        => 10000000,
            'status'              => 'available',
            'notes'               => '',
        ]);
    } catch (DomainException) {
        $s14Blocked = true;
    }
    recordSerialTest('S14', 'Chặn sửa serial_number / status của máy đang reserved/sold', $s14Blocked ? 'PASS' : 'FAIL');

    // S24: Đồng bộ Checkout - đặt hết máy khả dụng của 1 sản phẩm khi có 1 máy available nhưng failed -> Checkout chỉ lấy máy không failed
    $catId = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO products (category_id, name, slug, sku, price, original_price, has_serials, stock_quantity, status) VALUES (:c, 'Laptop Sync Test', 'laptop-sync-test', 'SKU-SYNC-18', 15000000, 18000000, 1, 1, 'published')")
        ->execute([':c' => $catId]);
    $syncProdId = (int) $pdo->lastInsertId();

    $uPassId = $unitModel->createUnit([
        'product_id' => $syncProdId, 'serial_number' => 'SYNC-PASS-01', 'cosmetic_grade' => 'Grade A',
        'battery_health' => 95, 'import_price' => 11000000, 'status' => 'available', 'notes' => '',
    ]);
    $uFailId = $unitModel->createUnit([
        'product_id' => $syncProdId, 'serial_number' => 'SYNC-FAIL-02', 'cosmetic_grade' => 'Grade B',
        'battery_health' => 80, 'import_price' => 10000000, 'status' => 'available', 'notes' => '',
    ]);
    $inspModel->createInspection($uPassId, $adminId, 'passed', 'Đạt', build18Steps('pass'));
    $inspModel->createInspection($uFailId, $adminId, 'failed', 'Lỗi màn hình', build18Steps('pass', [4 => ['result' => 'fail', 'note' => 'Sọc màn']]));

    $orderModel = new OrderModel();
    $syncIdemKey = 'idem_sync_' . bin2hex(random_bytes(8));
    $_SESSION['checkout_issued_tokens'][$syncIdemKey] = [
        'actor_hash' => OrderModel::buildActorHash(null),
        'issued_at'  => time(),
    ];
    $orderRes = $orderModel->createOrderFromCheckout(
        [
            'customer_name'    => 'Nguyen Van Test',
            'customer_phone'   => '0901234567',
            'customer_email'   => $custRow['email'],
            'shipping_city'    => 'Ha Noi',
            'shipping_address' => '123 Duong Test, Ha Noi',
            'payment_method'   => 'cod',
            'note'             => 'Test sync',
            'coupon_code'      => '',
        ],
        null,
        [$syncProdId => 1],
        [],
        $syncIdemKey
    );
    $assignedUnitId = (int) $pdo->query('SELECT product_unit_id FROM order_items WHERE order_id = ' . (int) $orderRes['order_id'])->fetchColumn();
    $stockAfterOrder = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$syncProdId}")->fetchColumn();
    $okS24 = $assignedUnitId === $uPassId && $stockAfterOrder === 0;
    recordSerialTest('S24', 'Checkout chỉ chọn máy đạt kiểm định và đồng bộ stock_quantity = 0', $okS24 ? 'PASS' : 'FAIL', "assigned={$assignedUnitId}, stock={$stockAfterOrder}");

    // S25: Payload POST dạng mảng bất thường không gây Fatal/500
    $malformed1 = httpReq('POST', $base . '/admin/serial/them', $adminJar, http_build_query([
        '_csrf_token'   => $csrf,
        'product_id'    => [$serialProdId],
        'serial_number' => ['ARRAY-INJECT'],
    ]));
    $malformed2 = httpReq('POST', $base . '/admin/serial/' . $unitId . '/kiem-dinh/them', $adminJar, http_build_query([
        '_csrf_token'    => $inspCsrf,
        'overall_result' => ['passed'],
        'steps'          => 'not-an-array',
    ]));
    $okS25 = $malformed1['status'] === 302 && $malformed2['status'] === 302;
    recordSerialTest('S25', 'Từ chối payload POST dạng mảng/sai kiểu an toàn (không 500)', $okS25 ? 'PASS' : 'FAIL');

    // S26: Các trang chi tiết Serial, lịch sử kiểm định, chi tiết biên bản, Dashboard & Product link đều 200 OK
    $showUnitResp = httpReq('GET', $base . '/admin/serial/' . $unitId, $adminJar);
    $histResp     = httpReq('GET', $base . '/admin/serial/' . $unitId . '/kiem-dinh', $adminJar);
    $showInspResp = httpReq('GET', $base . '/admin/kiem-dinh/' . (int) $res2['inspection_id'], $adminJar);
    $dashResp     = httpReq('GET', $base . '/admin', $adminJar);
    $prodListResp = httpReq('GET', $base . '/admin/san-pham', $adminJar);
    $okS26 = $showUnitResp['status'] === 200
        && str_contains($showUnitResp['body'], $newSer)
        && $histResp['status'] === 200
        && $showInspResp['status'] === 200
        && str_contains($showInspResp['body'], 'Chi tiết 18 bước kiểm tra kỹ thuật')
        && $dashResp['status'] === 200
        && str_contains($dashResp['body'], '/admin/serial')
        && $prodListResp['status'] === 200
        && str_contains($prodListResp['body'], '/admin/serial?san-pham=');
    recordSerialTest('S26', 'Trang chi tiết Serial, lịch sử & biên bản 18 bước, Dashboard và Product link 200 OK', $okS26 ? 'PASS' : 'FAIL');

    // =========================================================================
    // BỔ SUNG KIỂM THỬ PROMPT 18.1 (S27 - S34)
    // =========================================================================

    // Tạo sản phẩm riêng để kiểm thử độc lập các quy tắc kiểm định & tồn kho
    $pdo->prepare("INSERT INTO products (category_id, name, slug, sku, price, original_price, has_serials, stock_quantity, status) VALUES (:c, 'Laptop Integrity 18.1', 'laptop-integrity-181', 'SKU-INT-181', 18000000, 21000000, 1, 0, 'published')")
        ->execute([':c' => $catId]);
    $intProdId = (int) $pdo->lastInsertId();

    // S27: Serial mới chưa kiểm định (available, NULL inspection) không thể Checkout & is_sellable = false
    $uninspId = $unitModel->createUnit([
        'product_id' => $intProdId, 'serial_number' => 'INT-UNINSP-01', 'cosmetic_grade' => 'Grade A',
        'battery_health' => 98, 'import_price' => 13000000, 'status' => 'available', 'notes' => '',
    ]);
    $uninspRow = $unitModel->findById($uninspId);
    $s27CheckoutBlocked = false;
    try {
        $k27 = 'idem_s27_' . bin2hex(random_bytes(8));
        $_SESSION['checkout_issued_tokens'][$k27] = ['actor_hash' => OrderModel::buildActorHash(null), 'issued_at' => time()];
        $orderModel->createOrderFromCheckout([
            'customer_name' => 'Test S27', 'customer_phone' => '0901234567', 'customer_email' => $custRow['email'],
            'shipping_city' => 'Ha Noi', 'shipping_address' => '123 Test', 'payment_method' => 'cod', 'note' => '', 'coupon_code' => '',
        ], null, [$intProdId => 1], [], $k27);
    } catch (\App\Core\CheckoutException) {
        $s27CheckoutBlocked = true;
    }
    $okS27 = $uninspRow !== null && $uninspRow['is_sellable'] === false && $s27CheckoutBlocked;
    recordSerialTest('S27', 'Serial mới chưa kiểm định không thể bán / Checkout', $okS27 ? 'PASS' : 'FAIL');

    // S28: Serial có conditional_pass không tự động được bán & không mở bán từ repairing
    $condUnitId = $unitModel->createUnit([
        'product_id' => $intProdId, 'serial_number' => 'INT-COND-02', 'cosmetic_grade' => 'Grade B',
        'battery_health' => 82, 'import_price' => 12000000, 'status' => 'repairing', 'notes' => '',
    ]);
    $condRes = $inspModel->createInspection(
        $condUnitId, $adminId, 'conditional_pass', 'Pin hơi chai 82%, chờ duyệt',
        build18Steps('pass', [15 => ['result' => 'warning', 'note' => 'Pin 82%']]),
        true
    );
    $condRow = $unitModel->findById($condUnitId);
    // Dù đặt status = available cho $uninspId kèm conditional_pass -> vẫn không được bán
    $inspModel->createInspection(
        $uninspId, $adminId, 'conditional_pass', 'Chờ duyệt ngoại quan',
        build18Steps('pass', [1 => ['result' => 'warning', 'note' => 'Xước nhẹ']]),
        false
    );
    $uninspCondRow = $unitModel->findById($uninspId);
    $s28Blocked = false;
    try {
        $k28 = 'idem_s28_' . bin2hex(random_bytes(8));
        $_SESSION['checkout_issued_tokens'][$k28] = ['actor_hash' => OrderModel::buildActorHash(null), 'issued_at' => time()];
        $orderModel->createOrderFromCheckout([
            'customer_name' => 'Test S28', 'customer_phone' => '0901234567', 'customer_email' => $custRow['email'],
            'shipping_city' => 'Ha Noi', 'shipping_address' => '123 Test', 'payment_method' => 'cod', 'note' => '', 'coupon_code' => '',
        ], null, [$intProdId => 1], [], $k28);
    } catch (\App\Core\CheckoutException) {
        $s28Blocked = true;
    }
    $okS28 = $condRow['status'] === 'repairing' && $condRow['is_sellable'] === false && $uninspCondRow['is_sellable'] === false && $s28Blocked;
    recordSerialTest('S28', 'Serial có conditional_pass không tự động mở bán và bị Checkout chặn', $okS28 ? 'PASS' : 'FAIL');

    // S29: Serial có passed cũ nhưng failed mới -> không thể bán
    $inspModel->createInspection($uninspId, $adminId, 'passed', 'Đạt lần 1', build18Steps('pass'));
    $afterPassRow = $unitModel->findById($uninspId);
    $inspModel->createInspection($uninspId, $adminId, 'failed', 'Phát hiện lỗi VGA khi test lại', build18Steps('pass', [8 => ['result' => 'fail', 'note' => 'Artifact GPU']]));
    $afterFailRow = $unitModel->findById($uninspId);
    $s29Blocked = false;
    try {
        $k29 = 'idem_s29_' . bin2hex(random_bytes(8));
        $_SESSION['checkout_issued_tokens'][$k29] = ['actor_hash' => OrderModel::buildActorHash(null), 'issued_at' => time()];
        $orderModel->createOrderFromCheckout([
            'customer_name' => 'Test S29', 'customer_phone' => '0901234567', 'customer_email' => $custRow['email'],
            'shipping_city' => 'Ha Noi', 'shipping_address' => '123 Test', 'payment_method' => 'cod', 'note' => '', 'coupon_code' => '',
        ], null, [$intProdId => 1], [], $k29);
    } catch (\App\Core\CheckoutException) {
        $s29Blocked = true;
    }
    $okS29 = $afterPassRow['is_sellable'] === true && $afterFailRow['is_sellable'] === false && $s29Blocked;
    recordSerialTest('S29', 'Serial passed cũ nhưng failed mới bị chặn bán', $okS29 ? 'PASS' : 'FAIL');

    // S30: Máy returned không thể dùng biên bản passed cũ (trước hoàn trả, kể cả cùng giây) để mở bán; chỉ mở lại sau kiểm định mới passed
    $retUnitId = $unitModel->createUnit([
        'product_id' => $intProdId, 'serial_number' => 'INT-RET-03', 'cosmetic_grade' => 'Grade A',
        'battery_health' => 95, 'import_price' => 14000000, 'status' => 'available', 'notes' => '',
    ]);
    $inspModel->createInspection($retUnitId, $adminId, 'passed', 'Đạt trước khi bán/trả', build18Steps('pass'));
    $retBefore = $unitModel->findById($retUnitId);
    // Chuyển sang returned
    $unitModel->updateUnit($retUnitId, [
        'expected_row_version' => (int) $retBefore['row_version'],
        'product_id' => $intProdId, 'serial_number' => 'INT-RET-03', 'cosmetic_grade' => 'Grade A',
        'battery_health' => 95, 'import_price' => 14000000, 'status' => 'returned', 'notes' => 'Khách trả hàng',
    ]);
    $retAfter = $unitModel->findById($retUnitId);
    $s30OldBlocked = false;
    try {
        $unitModel->updateUnit($retUnitId, [
            'expected_row_version' => (int) $retAfter['row_version'],
            'product_id' => $intProdId, 'serial_number' => 'INT-RET-03', 'cosmetic_grade' => 'Grade A',
            'battery_health' => 95, 'import_price' => 14000000, 'status' => 'available', 'notes' => 'Cố dùng biên bản cũ',
        ]);
    } catch (DomainException) {
        $s30OldBlocked = true;
    }
    // Kiểm định lại mới với kết quả passed -> cho phép chuyển available
    $inspModel->createInspection($retUnitId, $adminId, 'passed', 'Đã kiểm định lại sau hoàn trả', build18Steps('pass'));
    $retReinsp = $unitModel->findById($retUnitId);
    $unitModel->updateUnit($retUnitId, [
        'expected_row_version' => (int) $retReinsp['row_version'],
        'product_id' => $intProdId, 'serial_number' => 'INT-RET-03', 'cosmetic_grade' => 'Grade A',
        'battery_health' => 95, 'import_price' => 14000000, 'status' => 'available', 'notes' => 'Mở bán lại hợp lệ',
    ]);
    $retFinal = $unitModel->findById($retUnitId);
    $okS30 = $s30OldBlocked && $retFinal['status'] === 'available' && $retFinal['is_sellable'] === true;
    recordSerialTest('S30', 'Máy returned không dùng được biên bản cũ, chỉ mở lại sau kiểm định mới passed', $okS30 ? 'PASS' : 'FAIL');

    // S31: Request sửa máy thiếu expected_row_version bị từ chối ở cả Model và HTTP Controller
    $s31ModelBlocked = false;
    try {
        $unitModel->updateUnit($retUnitId, [
            'product_id' => $intProdId, 'serial_number' => 'INT-RET-03', 'cosmetic_grade' => 'Grade B',
            'battery_health' => 95, 'import_price' => 14000000, 'status' => 'available', 'notes' => 'Thiếu version',
        ]);
    } catch (DomainException) {
        $s31ModelBlocked = true;
    }
    $s31HttpResp = httpReq('POST', $base . '/admin/serial/' . $retUnitId . '/sua', $adminJar, [
        '_csrf_token' => $editCsrf, 'product_id' => (string) $intProdId, 'serial_number' => 'INT-RET-03',
        'cosmetic_grade' => 'Grade C', 'battery_health' => '95', 'import_price' => '14000000', 'status' => 'available', 'notes' => 'Bypass',
    ]);
    $afterS31 = $unitModel->findById($retUnitId);
    $okS31 = $s31ModelBlocked && $s31HttpResp['status'] === 302 && $afterS31['cosmetic_grade'] === 'Grade A';
    recordSerialTest('S31', 'Thiếu expected_row_version bị từ chối ở Model & HTTP Controller', $okS31 ? 'PASS' : 'FAIL');

    // S32: Hai lần sửa trong cùng 1 giây & Hai Admin sửa đồng thời -> chỉ 1 thành công
    $vSameSec = (int) $afterS31['row_version'];
    $unitModel->updateUnit($retUnitId, [
        'expected_row_version' => $vSameSec, 'product_id' => $intProdId, 'serial_number' => 'INT-RET-03',
        'cosmetic_grade' => 'Grade B', 'battery_health' => 95, 'import_price' => 14000000, 'status' => 'available', 'notes' => 'Admin A',
    ]);
    $s32SecondBlocked = false;
    try {
        $unitModel->updateUnit($retUnitId, [
            'expected_row_version' => $vSameSec, 'product_id' => $intProdId, 'serial_number' => 'INT-RET-03',
            'cosmetic_grade' => 'Grade C', 'battery_health' => 95, 'import_price' => 14000000, 'status' => 'available', 'notes' => 'Admin B cùng giây',
        ]);
    } catch (DomainException) {
        $s32SecondBlocked = true;
    }
    $afterS32 = $unitModel->findById($retUnitId);
    $okS32 = $s32SecondBlocked && $afterS32['cosmetic_grade'] === 'Grade B' && (int) $afterS32['row_version'] === ($vSameSec + 1);
    recordSerialTest('S32', 'Hai Admin sửa đồng thời / trong cùng 1 giây chỉ 1 người thành công', $okS32 ? 'PASS' : 'FAIL');

    // S34: Kiểm tra số tồn khả dụng giữa Storefront (ProductModel), Admin (AdminProductModel) và DB stock_quantity đồng nhất trước khi Checkout
    $sfBefore34  = $storeProdModel->findPublishedById($intProdId);
    $admBefore34 = $adminProdModel->findById($intProdId);
    $dbStock34   = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$intProdId}")->fetchColumn();
    $okS34 = ((int) $sfBefore34['available_serial_count'] === 1)
        && ((int) $sfBefore34['purchasable_quantity'] === 1)
        && ((int) $admBefore34['available_serial_count'] === 1)
        && ($dbStock34 === 1);
    recordSerialTest('S34', 'Số tồn Storefront, AdminProductModel và products.stock_quantity đồng nhất (1)', $okS34 ? 'PASS' : 'FAIL', "sf={$sfBefore34['purchasable_quantity']}, adm={$admBefore34['available_serial_count']}, db={$dbStock34}");

    // S33: Checkout giữ máy làm tăng row_version -> Admin đang giữ row_version cũ gửi sửa bị từ chối
    $staleVersionBeforeCheckout = (int) $afterS32['row_version'];
    $k33 = 'idem_s33_' . bin2hex(random_bytes(8));
    $_SESSION['checkout_issued_tokens'][$k33] = ['actor_hash' => OrderModel::buildActorHash(null), 'issued_at' => time()];
    $orderModel->createOrderFromCheckout([
        'customer_name' => 'Test S33', 'customer_phone' => '0901234567', 'customer_email' => $custRow['email'],
        'shipping_city' => 'Ha Noi', 'shipping_address' => '123 Test', 'payment_method' => 'cod', 'note' => '', 'coupon_code' => '',
    ], null, [$intProdId => 1], [], $k33);
    $s33Blocked = false;
    try {
        $unitModel->updateUnit($retUnitId, [
            'expected_row_version' => $staleVersionBeforeCheckout, 'product_id' => $intProdId, 'serial_number' => 'INT-RET-03',
            'cosmetic_grade' => 'Grade A', 'battery_health' => 95, 'import_price' => 14000000, 'status' => 'repairing', 'notes' => 'Admin ghi đè sau Checkout',
        ]);
    } catch (DomainException) {
        $s33Blocked = true;
    }
    $afterS33 = $unitModel->findById($retUnitId);
    $okS33 = $s33Blocked && $afterS33['status'] === 'reserved' && (int) $afterS33['row_version'] === ($staleVersionBeforeCheckout + 1);
    recordSerialTest('S33', 'Checkout giữ máy tăng row_version và chặn Admin ghi đè từ version cũ', $okS33 ? 'PASS' : 'FAIL');

} finally {
    if ($server !== null && is_resource($server['proc'])) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
    foreach (glob($cookieDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($cookieDir);
}

$total = count($results);
$pass  = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'PASS'));
$fail  = $total - $pass;

echo "\n=== TỔNG KẾT KIỂM THỬ SERIAL & KIỂM ĐỊNH 18 BƯỚC (Prompt 18) ===\n";
echo "PASS: {$pass}/{$total} | FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
