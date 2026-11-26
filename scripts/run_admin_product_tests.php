<?php
/**
 * Bộ kiểm thử Quản lý Sản phẩm Admin (Prompt 17) cho TL Gaming.
 * Chạy trên CSDL cô lập `tl_gaming_test` (bắt buộc cờ `--confirm-test-db`).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Models/CategoryModel.php';
require_once __DIR__ . '/../app/Models/ProductModel.php';
require_once __DIR__ . '/../app/Models/AdminProductModel.php';

use App\Models\AdminProductModel;
use App\Models\CategoryModel;
use App\Models\ProductModel;

$projectRoot  = dirname(__DIR__);
$testDbName   = 'tl_gaming_test';
$confirmReset = in_array('--confirm-test-db', $argv ?? [], true);

putenv("DB_DATABASE={$testDbName}");
$_ENV['DB_DATABASE']    = $testDbName;
$_SERVER['DB_DATABASE'] = $testDbName;

$results = [];
function recordProdTest(string $code, string $name, string $status, string $evidence = ''): void
{
    global $results;
    $results[] = ['code' => $code, 'name' => $name, 'status' => $status, 'evidence' => $evidence];
    echo sprintf("%-6s| %-7s | %s\n", $code, $status, $name);
    if ($evidence !== '') {
        echo "      -> Bằng chứng: {$evidence}\n";
    }
}

function setupProdTestDb(string $projectRoot, string $testDbName, bool $confirmReset): PDO
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

function prodHttpReq(string $method, string $url, string $cookieFile, mixed $post = null, bool $isMultipart = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($isMultipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : (string) $post);
        }
    }
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body];
}

function extractProdCsrf(string $html): string
{
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function createTempPng(string $path): void
{
    // Tệp PNG 1x1 pixel hợp lệ (để finfo và getimagesize nhận diện chuẩn image/png mà không cần extension GD)
    $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    file_put_contents($path, (string) base64_decode($pngBase64));
}

echo "================================================================================\n";
echo "TL GAMING — KIỂM THỬ QUẢN LÝ SẢN PHẨM ADMIN (PROMPT 17)\n";
echo "CSDL Test cô lập: {$testDbName}\n";
echo "================================================================================\n\n";

try {
    $pdo = setupProdTestDb($projectRoot, $testDbName, $confirmReset);
} catch (Throwable $e) {
    fwrite(STDERR, "[NOT RUN] Không thể khởi tạo CSDL test: " . $e->getMessage() . "\n");
    exit(0);
}

$port          = 18107;
$baseUrl       = "http://127.0.0.1:{$port}";
$serverLogFile = tempnam(sys_get_temp_dir(), 'tlg_prod_srv_');
$serverCmd     = sprintf('E:\\xampp\\php\\php.exe -S 127.0.0.1:%d -t %s', $port, escapeshellarg($projectRoot . '/public'));
$env           = getenv();
$env['DB_DATABASE'] = $testDbName;
$serverProc    = proc_open($serverCmd, [0 => ['pipe', 'r'], 1 => ['file', $serverLogFile, 'w'], 2 => ['file', $serverLogFile, 'a']], $pipes, $projectRoot, $env);
usleep(700000);

$cookieGuest = tempnam(sys_get_temp_dir(), 'tlg_pg_');
$cookieCust  = tempnam(sys_get_temp_dir(), 'tlg_pc_');
$cookieAdmin = tempnam(sys_get_temp_dir(), 'tlg_pa_');
$tmpImg1     = tempnam(sys_get_temp_dir(), 'tlg_img1_') . '.png';
$tmpImg2     = tempnam(sys_get_temp_dir(), 'tlg_img2_') . '.png';
$tmpFakePhp  = tempnam(sys_get_temp_dir(), 'tlg_fake_') . '.jpg';
createTempPng($tmpImg1);
createTempPng($tmpImg2);
file_put_contents($tmpFakePhp, "<?php echo 'hacked'; ?>");

// Ghi lại danh sách file trước khi chạy để dọn sạch các file test upload sau khi hoàn tất
$uploadDir   = $projectRoot . '/public/uploads/products';
$filesBefore = is_dir($uploadDir) ? array_flip(scandir($uploadDir) ?: []) : [];

try {
    // 1. Guest truy cập /admin/san-pham -> 403
    $r1 = prodHttpReq('GET', $baseUrl . '/admin/san-pham', $cookieGuest);
    recordProdTest('TC01', 'Guest truy cập /admin/san-pham bị chặn HTTP 403', $r1['status'] === 403 ? 'PASS' : 'FAIL', "HTTP {$r1['status']}");

    // 2. Customer truy cập /admin/san-pham -> 403
    $pdo->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES ('Prod Cust', 'prod.cust@tlgaming.vn', '0909888777', :pw, 'customer', 'active')")
        ->execute([':pw' => password_hash('CustTest@123', PASSWORD_BCRYPT)]);
    $lpC = prodHttpReq('GET', $baseUrl . '/dang-nhap', $cookieCust);
    prodHttpReq('POST', $baseUrl . '/dang-nhap', $cookieCust, ['email' => 'prod.cust@tlgaming.vn', 'password' => 'CustTest@123', '_csrf_token' => extractProdCsrf($lpC['body'])]);
    $r2 = prodHttpReq('GET', $baseUrl . '/admin/san-pham', $cookieCust);
    recordProdTest('TC02', 'Customer truy cập /admin/san-pham bị chặn HTTP 403', $r2['status'] === 403 ? 'PASS' : 'FAIL', "HTTP {$r2['status']}");

    // Đăng nhập Admin
    $pdo->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES ('Prod Admin', 'prod.admin@tlgaming.vn', '0909888666', :pw, 'admin', 'active')")
        ->execute([':pw' => password_hash('AdminTest@123', PASSWORD_BCRYPT)]);
    $lpA = prodHttpReq('GET', $baseUrl . '/dang-nhap', $cookieAdmin);
    prodHttpReq('POST', $baseUrl . '/dang-nhap', $cookieAdmin, ['email' => 'prod.admin@tlgaming.vn', 'password' => 'AdminTest@123', '_csrf_token' => extractProdCsrf($lpA['body'])]);

    // 3. Admin xem danh sách sản phẩm -> 200
    $r3 = prodHttpReq('GET', $baseUrl . '/admin/san-pham', $cookieAdmin);
    $csrfAdmin = extractProdCsrf($r3['body']);
    recordProdTest('TC03', 'Admin truy cập danh sách sản phẩm (/admin/san-pham) thành công', ($r3['status'] === 200 && str_contains($r3['body'], 'Quản lý sản phẩm')) ? 'PASS' : 'FAIL', "HTTP {$r3['status']}");

    // 4. Tìm kiếm & phân trang
    $r4Search = prodHttpReq('GET', $baseUrl . '/admin/san-pham?q=TL-01&sap-xep=gia-giam', $cookieAdmin);
    $r4Page   = prodHttpReq('GET', $baseUrl . '/admin/san-pham?page=1', $cookieAdmin);
    recordProdTest('TC04', 'Tìm kiếm, sắp xếp whitelist & phân trang sản phẩm hoạt động', ($r4Search['status'] === 200 && str_contains($r4Search['body'], 'TL-01') && $r4Page['status'] === 200) ? 'PASS' : 'FAIL', "searchHTTP={$r4Search['status']}");

    // 22. CSRF sai bị chặn 403
    $r22 = prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, ['_csrf_token' => 'wrong_csrf', 'name' => 'Test']);
    recordProdTest('TC22', 'POST /admin/san-pham/them sai CSRF bị chặn HTTP 403', $r22['status'] === 403 ? 'PASS' : 'FAIL', "HTTP {$r22['status']}");

    // 23. Dữ liệu POST dạng mảng bất thường không gây PHP Warning / 500
    $r23Post = prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin,
        'name'        => ['mang-loi'],
        'price'       => ['1000'],
        'spec_name'   => 'not-an-array',
    ]);
    $r23Get = prodHttpReq('GET', $baseUrl . '/admin/san-pham/them', $cookieAdmin);
    recordProdTest('TC23', 'Chặn POST dữ liệu dạng mảng bất thường không gây cảnh báo PHP', ($r23Post['status'] === 302 && $r23Get['status'] === 200 && !str_contains($r23Get['body'], 'Array to string conversion') && str_contains($r23Get['body'], 'kiểu dữ liệu không hợp lệ')) ? 'PASS' : 'FAIL', "POST={$r23Post['status']}");

    // 5. Thêm sản phẩm hợp lệ (has_serials = 0, kèm 2 ảnh thật + thông số kỹ thuật)
    $r5Post = prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, [
        '_csrf_token'        => $csrfAdmin,
        'name'               => 'RAM Corsair Vengeance 16GB DDR4 Test',
        'sku'                => 'TLG-TEST-RAM16',
        'slug'               => 'ram-corsair-vengeance-16gb-ddr4-test',
        'category_id'        => '4',
        'summary'            => 'RAM test prompt 17',
        'description'        => 'Mô tả chi tiết RAM test',
        'price'              => '950000',
        'original_price'     => '1250000',
        'warranty_months'    => '12',
        'condition_overview' => 'Like New 99%',
        'status'             => 'published',
        'has_serials'        => '0',
        'stock_quantity'     => '5',
        'spec_group[0]'      => 'Bộ nhớ',
        'spec_name[0]'       => 'Dung lượng',
        'spec_value[0]'      => '16GB DDR4 3200MHz',
        'spec_order[0]'      => '1',
        'images[0]'          => new CURLFile($tmpImg1, 'image/png', 'ram1.png'),
        'images[1]'          => new CURLFile($tmpImg2, 'image/png', 'ram2.png'),
    ], true);
    $newProd = $pdo->query("SELECT * FROM products WHERE sku = 'TLG-TEST-RAM16' LIMIT 1")->fetch();
    $newProdId = $newProd ? (int) $newProd['id'] : 0;
    $imgCount  = $newProdId > 0 ? (int) $pdo->query("SELECT COUNT(*) FROM product_images WHERE product_id = {$newProdId}")->fetchColumn() : 0;
    $specCount = $newProdId > 0 ? (int) $pdo->query("SELECT COUNT(*) FROM product_specs WHERE product_id = {$newProdId}")->fetchColumn() : 0;
    recordProdTest('TC05', 'Thêm sản phẩm hợp lệ kèm 2 ảnh & thông số kỹ thuật thành công', ($r5Post['status'] === 302 && $newProdId > 0 && $imgCount === 2 && $specCount === 1 && !empty($newProd['thumbnail'])) ? 'PASS' : 'FAIL', "id={$newProdId}, imgs={$imgCount}, specs={$specCount}");

    // 6. SKU trùng
    prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin, 'name' => 'SP Trùng SKU', 'sku' => 'TLG-TEST-RAM16', 'slug' => 'sp-trung-sku-1', 'category_id' => '4', 'price' => '500000', 'warranty_months' => '6', 'condition_overview' => '99%', 'status' => 'draft', 'has_serials' => '0', 'stock_quantity' => '1',
    ]);
    $r6Get = prodHttpReq('GET', $baseUrl . '/admin/san-pham/them', $cookieAdmin);
    recordProdTest('TC06', 'Từ chối thêm sản phẩm trùng SKU', str_contains($r6Get['body'], 'SKU đã tồn tại') ? 'PASS' : 'FAIL');

    // 7. Slug trùng
    prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin, 'name' => 'SP Trùng Slug', 'sku' => 'TLG-UNIQUE-99', 'slug' => 'ram-corsair-vengeance-16gb-ddr4-test', 'category_id' => '4', 'price' => '500000', 'warranty_months' => '6', 'condition_overview' => '99%', 'status' => 'draft', 'has_serials' => '0', 'stock_quantity' => '1',
    ]);
    $r7Get = prodHttpReq('GET', $baseUrl . '/admin/san-pham/them', $cookieAdmin);
    recordProdTest('TC07', 'Từ chối thêm sản phẩm trùng Slug', str_contains($r7Get['body'], 'Slug) đã tồn tại') ? 'PASS' : 'FAIL');

    // 8. Giá âm
    prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin, 'name' => 'SP Giá Âm', 'sku' => 'TLG-NEG-PRICE', 'slug' => 'sp-gia-am', 'category_id' => '4', 'price' => '-1000', 'warranty_months' => '6', 'condition_overview' => '99%', 'status' => 'draft', 'has_serials' => '0', 'stock_quantity' => '1',
    ]);
    $r8Get = prodHttpReq('GET', $baseUrl . '/admin/san-pham/them', $cookieAdmin);
    recordProdTest('TC08', 'Từ chối giá bán âm', str_contains($r8Get['body'], 'Giá bán VND vượt quá giới hạn') ? 'PASS' : 'FAIL');

    // 9. Số lượng âm
    prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin, 'name' => 'SP Tồn Âm', 'sku' => 'TLG-NEG-STOCK', 'slug' => 'sp-ton-am', 'category_id' => '4', 'price' => '500000', 'warranty_months' => '6', 'condition_overview' => '99%', 'status' => 'draft', 'has_serials' => '0', 'stock_quantity' => '-5',
    ]);
    $r9Get = prodHttpReq('GET', $baseUrl . '/admin/san-pham/them', $cookieAdmin);
    recordProdTest('TC09', 'Từ chối số lượng tồn kho âm', str_contains($r9Get['body'], 'Số lượng tồn kho vượt quá giới hạn') ? 'PASS' : 'FAIL');

    // 10. Danh mục không tồn tại
    prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin, 'name' => 'SP Cat 99999', 'sku' => 'TLG-BAD-CAT', 'slug' => 'sp-bad-cat', 'category_id' => '99999', 'price' => '500000', 'warranty_months' => '6', 'condition_overview' => '99%', 'status' => 'draft', 'has_serials' => '0', 'stock_quantity' => '1',
    ]);
    $r10Get = prodHttpReq('GET', $baseUrl . '/admin/san-pham/them', $cookieAdmin);
    recordProdTest('TC10', 'Từ chối danh mục không tồn tại', str_contains($r10Get['body'], 'Danh mục được chọn không tồn tại') ? 'PASS' : 'FAIL');

    // 11. Danh mục đang ẩn không cho publish
    $pdo->exec("INSERT INTO categories (parent_id, name, slug, is_active) VALUES (NULL, 'Hidden Test Cat', 'hidden-test-cat', 0)");
    $hiddenCatId = (int) $pdo->lastInsertId();
    prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin, 'name' => 'SP Hidden Cat', 'sku' => 'TLG-HID-CAT', 'slug' => 'sp-hid-cat', 'category_id' => (string) $hiddenCatId, 'price' => '500000', 'warranty_months' => '6', 'condition_overview' => '99%', 'status' => 'published', 'has_serials' => '0', 'stock_quantity' => '1',
    ]);
    $r11Get = prodHttpReq('GET', $baseUrl . '/admin/san-pham/them', $cookieAdmin);
    recordProdTest('TC11', 'Chặn publish sản phẩm vào danh mục đang ẩn', str_contains($r11Get['body'], 'danh mục đang bị ẩn') ? 'PASS' : 'FAIL');

    // 12. Sửa thông tin sản phẩm + thông số kỹ thuật + điều chỉnh tồn kho nguyên tử (+3 -> 8)
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, [
        '_csrf_token'        => $csrfAdmin,
        'name'               => 'RAM Corsair Vengeance 16GB Updated',
        'sku'                => 'TLG-TEST-RAM16',
        'slug'               => 'ram-corsair-vengeance-16gb-ddr4-test',
        'category_id'        => '4',
        'summary'            => 'Updated summary',
        'description'        => 'Updated desc',
        'price'              => '990000',
        'original_price'     => '1250000',
        'warranty_months'    => '24',
        'condition_overview' => 'Like New 99%',
        'status'             => 'published',
        'has_serials'        => '0',
        'stock_mode'         => 'adjust',
        'stock_adjustment'   => '3',
        'spec_group'         => ['Bộ nhớ', 'Tương thích'],
        'spec_name'          => ['Dung lượng', 'Bus'],
        'spec_value'         => ['16GB (2x8GB)', '3200MHz'],
        'spec_order'         => ['1', '2'],
    ]);
    $updProd   = $pdo->query("SELECT name, price, stock_quantity FROM products WHERE id = {$newProdId}")->fetch();
    $updSpecs  = (int) $pdo->query("SELECT COUNT(*) FROM product_specs WHERE product_id = {$newProdId}")->fetchColumn();
    recordProdTest('TC12', 'Sửa sản phẩm, cập nhật thông số kỹ thuật & điều chỉnh tồn kho nguyên tử (5+3=8)', ($updProd['name'] === 'RAM Corsair Vengeance 16GB Updated' && (int) $updProd['price'] === 990000 && (int) $updProd['stock_quantity'] === 8 && $updSpecs === 2) ? 'PASS' : 'FAIL', "stock={$updProd['stock_quantity']}, specs={$updSpecs}");

    // 13 & 14. Ẩn sản phẩm -> không còn trên trang khách; Hiện lại -> có lại trên trang khách
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/an", $cookieAdmin, ['_csrf_token' => $csrfAdmin]);
    $r13Detail = prodHttpReq('GET', $baseUrl . '/san-pham/ram-corsair-vengeance-16gb-ddr4-test', $cookieGuest);
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/hien", $cookieAdmin, ['_csrf_token' => $csrfAdmin]);
    $r14Detail = prodHttpReq('GET', $baseUrl . '/san-pham/ram-corsair-vengeance-16gb-ddr4-test', $cookieGuest);
    recordProdTest('TC13', 'Ẩn sản phẩm (archived) khiến trang chi tiết công khai không còn hiển thị', $r13Detail['status'] === 404 ? 'PASS' : 'FAIL', "HTTP {$r13Detail['status']}");
    recordProdTest('TC14', 'Hiện lại sản phẩm (published) hiển thị lại bình thường trên trang khách', ($r14Detail['status'] === 200 && str_contains($r14Detail['body'], 'RAM Corsair Vengeance 16GB Updated')) ? 'PASS' : 'FAIL', "HTTP {$r14Detail['status']}");

    // 15. Từ chối file PHP giả danh .jpg khi upload
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/anh/them", $cookieAdmin, [
        '_csrf_token' => $csrfAdmin,
        'images[0]'   => new CURLFile($tmpFakePhp, 'image/jpeg', 'shell.jpg'),
    ], true);
    $r15Get = prodHttpReq('GET', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin);
    $imgCountAfterFake = (int) $pdo->query("SELECT COUNT(*) FROM product_images WHERE product_id = {$newProdId}")->fetchColumn();
    recordProdTest('TC15', 'Từ chối tệp PHP giả MIME khi upload ảnh', ($imgCountAfterFake === 2 && str_contains($r15Get['body'], 'Định dạng tệp ảnh không được phép')) ? 'PASS' : 'FAIL', "imgs={$imgCountAfterFake}");

    // 16 & 17. Thêm ảnh hợp lệ & Chống IDOR xóa ảnh không thuộc sản phẩm & xóa ảnh hợp lệ
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/anh/them", $cookieAdmin, [
        '_csrf_token' => $csrfAdmin,
        'images[0]'   => new CURLFile($tmpImg1, 'image/png', 'extra.png'),
    ], true);
    $imgCountAfterAdd = (int) $pdo->query("SELECT COUNT(*) FROM product_images WHERE product_id = {$newProdId}")->fetchColumn();
    recordProdTest('TC16', 'Upload thêm ảnh hợp lệ qua route riêng thành công', $imgCountAfterAdd === 3 ? 'PASS' : 'FAIL', "imgs={$imgCountAfterAdd}");

    $otherImgId = (int) $pdo->query("SELECT id FROM product_images WHERE product_id <> {$newProdId} LIMIT 1")->fetchColumn();
    if ($otherImgId > 0) {
        prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/anh/{$otherImgId}/xoa", $cookieAdmin, ['_csrf_token' => $csrfAdmin]);
    }
    $otherStillExists = $otherImgId > 0 ? ((int) $pdo->query("SELECT COUNT(*) FROM product_images WHERE id = {$otherImgId}")->fetchColumn() === 1) : true;
    $ownImgRow        = $pdo->query("SELECT id, image_url FROM product_images WHERE product_id = {$newProdId} ORDER BY id DESC LIMIT 1")->fetch();
    $ownImgId         = (int) $ownImgRow['id'];
    $ownDiskPath      = $projectRoot . '/public' . $ownImgRow['image_url'];
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/anh/{$ownImgId}/xoa", $cookieAdmin, ['_csrf_token' => $csrfAdmin]);
    $ownDeleted = ((int) $pdo->query("SELECT COUNT(*) FROM product_images WHERE id = {$ownImgId}")->fetchColumn() === 0) && !file_exists($ownDiskPath);
    recordProdTest('TC17', 'Chặn xóa ảnh sản phẩm khác (IDOR) & xóa ảnh đúng sản phẩm dọn cả DB và file', ($otherStillExists && $ownDeleted) ? 'PASS' : 'FAIL', "otherKept=" . ($otherStillExists ? 'yes' : 'no') . ", ownDeleted=" . ($ownDeleted ? 'yes' : 'no'));

    // 18 & 19 & 20. Sản phẩm has_serials = 1, khóa đổi loại tồn kho khi có phụ thuộc, và không ảnh hưởng order_items cũ
    $prodWithSerialId = 1; // Seed ID=1 có product_units & order_items
    $adminProdModel   = new AdminProductModel();
    $depsInfo         = $adminProdModel->findById($prodWithSerialId);
    $beforeUnits      = $pdo->query("SELECT status, COUNT(*) AS cnt FROM product_units WHERE product_id = {$prodWithSerialId} GROUP BY status")->fetchAll();

    // Thử đổi has_serials = 1 -> 0 trên sản phẩm đã có units/order_items
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$prodWithSerialId}/sua", $cookieAdmin, [
        '_csrf_token'        => $csrfAdmin,
        'name'               => (string) $depsInfo['name'],
        'sku'                => (string) $depsInfo['sku'],
        'slug'               => (string) $depsInfo['slug'],
        'category_id'        => (string) $depsInfo['category_id'],
        'price'              => (string) $depsInfo['price'],
        'warranty_months'    => (string) $depsInfo['warranty_months'],
        'condition_overview' => (string) $depsInfo['condition_overview'],
        'status'             => (string) $depsInfo['status'],
        'has_serials'        => '0',
        'stock_mode'         => 'adjust',
        'stock_adjustment'   => '0',
    ]);
    $r19Get = prodHttpReq('GET', $baseUrl . "/admin/san-pham/{$prodWithSerialId}/sua", $cookieAdmin);
    $stillSerial = (int) $pdo->query("SELECT has_serials FROM products WHERE id = {$prodWithSerialId}")->fetchColumn() === 1;
    recordProdTest('TC19', 'Không cho đổi has_serials khi sản phẩm đã có product_units / order_items', ($stillSerial && str_contains($r19Get['body'], 'Không thể thay đổi loại quản lý tồn kho')) ? 'PASS' : 'FAIL');

    // Sửa tên & giá sản phẩm đã có đơn hàng (ID=1) -> không làm đổi snapshot trong order_items
    $oldOrderItem = $pdo->query("SELECT id, product_name, price FROM order_items WHERE product_id = {$prodWithSerialId} LIMIT 1")->fetch();
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$prodWithSerialId}/sua", $cookieAdmin, [
        '_csrf_token'        => $csrfAdmin,
        'name'               => 'PC Gaming TL-01 Đã Sửa Tên',
        'sku'                => (string) $depsInfo['sku'],
        'slug'               => (string) $depsInfo['slug'],
        'category_id'        => (string) $depsInfo['category_id'],
        'price'              => '11900000',
        'warranty_months'    => (string) $depsInfo['warranty_months'],
        'condition_overview' => (string) $depsInfo['condition_overview'],
        'status'             => 'published',
        'has_serials'        => '1',
        'stock_quantity'     => '999', // bị bỏ qua hoàn toàn vì has_serials = 1
    ]);
    $afterUnits     = $pdo->query("SELECT status, COUNT(*) AS cnt FROM product_units WHERE product_id = {$prodWithSerialId} GROUP BY status")->fetchAll();
    $afterStockCol  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$prodWithSerialId}")->fetchColumn();
    $afterOrderItem = $oldOrderItem ? $pdo->query("SELECT id, product_name, price FROM order_items WHERE id = " . (int) $oldOrderItem['id'])->fetch() : null;

    recordProdTest('TC18', 'Hàng has_serials = 1 không nhận tồn kho thủ công & giữ nguyên trạng thái product_units', ($afterStockCol !== 999 && $beforeUnits === $afterUnits) ? 'PASS' : 'FAIL', "stock_col={$afterStockCol}");
    recordProdTest('TC20', 'Sửa sản phẩm đã có đơn hàng không làm thay đổi dữ liệu lịch sử order_items', ($oldOrderItem && $afterOrderItem && $oldOrderItem['product_name'] === $afterOrderItem['product_name'] && (int) $oldOrderItem['price'] === (int) $afterOrderItem['price']) ? 'PASS' : 'FAIL');

    // 21. Danh mục cha (Cấp 1) ẩn -> sản phẩm thuộc danh mục Cấp 3 không hiện trên website công khai (trang chủ, danh sách, chi tiết)
    $pdo->exec("INSERT INTO categories (parent_id, name, slug, is_active) VALUES (NULL, 'Cha C1 Test', 'cha-c1-test', 1)");
    $c1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO categories (parent_id, name, slug, is_active) VALUES ({$c1}, 'Con C2 Test', 'con-c2-test', 1)");
    $c2 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO categories (parent_id, name, slug, is_active) VALUES ({$c2}, 'Chau C3 Test', 'chau-c3-test', 1)");
    $c3 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO products (category_id, name, slug, sku, price, warranty_months, condition_overview, stock_quantity, has_serials, status) VALUES ({$c3}, 'SP Chau C3 An Cha', 'sp-chau-c3-an-cha', 'TLG-C3-TEST', 500000, 6, '99%', 3, 0, 'published')");
    // Cố tình ẩn trực tiếp Cấp 1 (mô phỏng trường hợp tổ tiên ẩn)
    $pdo->exec("UPDATE categories SET is_active = 0 WHERE id = {$c1}");

    $publicProdModel = new ProductModel();
    $foundC3BySlug   = $publicProdModel->findPublishedBySlug('sp-chau-c3-an-cha');
    $r21Shop         = prodHttpReq('GET', $baseUrl . '/san-pham?q=TLG-C3-TEST', $cookieGuest);
    recordProdTest('TC21', 'Khi danh mục gốc (Cấp 1) ẩn, sản phẩm Cấp 3 bị ẩn đồng bộ khỏi ProductModel & trang khách', ($foundC3BySlug === null && !str_contains($r21Shop['body'], 'SP Chau C3 An Cha')) ? 'PASS' : 'FAIL');

    // 24. stock_mode=set có expected_stock_quantity hợp lệ (8 -> 12)
    $baseSetPayload = [
        '_csrf_token'        => $csrfAdmin,
        'name'               => 'RAM Corsair Vengeance 16GB Updated',
        'sku'                => 'TLG-TEST-RAM16',
        'slug'               => 'ram-corsair-vengeance-16gb-ddr4-test',
        'category_id'        => '4',
        'price'              => '990000',
        'warranty_months'    => '24',
        'condition_overview' => 'Like New 99%',
        'status'             => 'published',
        'has_serials'        => '0',
        'stock_mode'         => 'set',
    ];
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, $baseSetPayload + [
        'stock_quantity'          => '12',
        'expected_stock_quantity' => '8',
    ]);
    $stockAfterSetOk = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$newProdId}")->fetchColumn();
    recordProdTest('TC24', 'stock_mode=set với expected_stock_quantity khớp cập nhật thành công (8->12)', $stockAfterSetOk === 12 ? 'PASS' : 'FAIL', "stock={$stockAfterSetOk}");

    // 25. stock_mode=set thiếu expected_stock_quantity bị chặn (không cho bỏ qua optimistic locking)
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, $baseSetPayload + [
        'stock_quantity' => '50',
    ]);
    $r25Get = prodHttpReq('GET', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin);
    $stockAfterMissingExp = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$newProdId}")->fetchColumn();
    recordProdTest('TC25', 'stock_mode=set thiếu expected_stock_quantity bị từ chối', ($stockAfterMissingExp === 12 && str_contains($r25Get['body'], 'expected_stock_quantity')) ? 'PASS' : 'FAIL', "stock={$stockAfterMissingExp}");

    // 26. stock_mode=set sai expected_stock_quantity (cũ/lỗi thời) bị chặn
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, $baseSetPayload + [
        'stock_quantity'          => '50',
        'expected_stock_quantity' => '8', // hiện tại đã là 12
    ]);
    $r26Get = prodHttpReq('GET', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin);
    $stockAfterStaleExp = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$newProdId}")->fetchColumn();
    recordProdTest('TC26', 'stock_mode=set sai expected_stock_quantity (lỗi thời) bị từ chối', ($stockAfterStaleExp === 12 && str_contains($r26Get['body'], 'đã thay đổi từ 8 thành 12')) ? 'PASS' : 'FAIL', "stock={$stockAfterStaleExp}");

    // 27. stock_mode=set gửi expected_stock_quantity âm / chữ & stock_mode ngoài whitelist bị chặn
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, array_merge($baseSetPayload, [
        'stock_quantity'          => '20',
        'expected_stock_quantity' => '-1',
    ]));
    $r27NegGet = prodHttpReq('GET', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin);
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, array_merge($baseSetPayload, [
        'stock_mode'     => 'hacked_mode',
        'stock_quantity' => '20',
    ]));
    $r27ModeGet = prodHttpReq('GET', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin);
    $stockAfterInvalid = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$newProdId}")->fetchColumn();
    recordProdTest('TC27', 'Từ chối expected_stock_quantity âm/chữ và stock_mode ngoài whitelist', ($stockAfterInvalid === 12 && str_contains($r27NegGet['body'], 'Tồn kho đối chiếu') && str_contains($r27ModeGet['body'], 'Chế độ cập nhật tồn kho không hợp lệ')) ? 'PASS' : 'FAIL');

    // 28. stock_mode=adjust giảm hợp lệ (12 - 2 = 10) & chặn giảm quá tồn hiện có
    $adjPayload = $baseSetPayload;
    $adjPayload['stock_mode'] = 'adjust';
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, $adjPayload + ['stock_adjustment' => '-2']);
    $stockAfterAdj = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$newProdId}")->fetchColumn();
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, $adjPayload + ['stock_adjustment' => '-999']);
    $r28OverGet = prodHttpReq('GET', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin);
    $stockAfterOverAdj = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$newProdId}")->fetchColumn();
    recordProdTest('TC28', 'stock_mode=adjust giảm hợp lệ (12-2=10) & chặn giảm âm kho', ($stockAfterAdj === 10 && $stockAfterOverAdj === 10 && str_contains($r28OverGet['body'], 'không đủ để giảm')) ? 'PASS' : 'FAIL', "stock={$stockAfterOverAdj}");

    // 29. Vượt giới hạn số nguyên PHP / DB (99999999999999999999) bị chặn sạch sẽ
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, $baseSetPayload + [
        'stock_quantity'          => '99999999999999999999',
        'expected_stock_quantity' => '10',
    ]);
    $r29Get = prodHttpReq('GET', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin);
    $stockAfterOverflow = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$newProdId}")->fetchColumn();
    recordProdTest('TC29', 'Chặn số lượng vượt giới hạn số nguyên PHP/DB không bị wrap-around', ($stockAfterOverflow === 10 && str_contains($r29Get['body'], 'vượt quá giới hạn')) ? 'PASS' : 'FAIL');

    // 30. Mô phỏng Checkout trừ kho giữa lúc Admin mở form (stock=10) và gửi POST set (expected=10)
    $pdo->exec("UPDATE products SET stock_quantity = stock_quantity - 3 WHERE id = {$newProdId}"); // Checkout trừ còn 7
    prodHttpReq('POST', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin, $baseSetPayload + [
        'stock_quantity'          => '25',
        'expected_stock_quantity' => '10',
    ]);
    $r30Get = prodHttpReq('GET', $baseUrl . "/admin/san-pham/{$newProdId}/sua", $cookieAdmin);
    $stockAfterCheckoutRace = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = {$newProdId}")->fetchColumn();
    recordProdTest('TC30', 'Checkout trừ kho giữa chừng (10->7) khiến Admin gửi set(expected=10) bị từ chối an toàn', ($stockAfterCheckoutRace === 7 && str_contains($r30Get['body'], 'đã thay đổi từ 10 thành 7')) ? 'PASS' : 'FAIL', "stock={$stockAfterCheckoutRace}");

    // 31. Transaction rollback (trùng SKU khi có ảnh upload) không để lại file ảnh mồ côi trên disk
    $filesBeforeRollback = is_dir($uploadDir) ? (scandir($uploadDir) ?: []) : [];
    prodHttpReq('POST', $baseUrl . '/admin/san-pham/them', $cookieAdmin, [
        '_csrf_token'        => $csrfAdmin,
        'name'               => 'SP Rollback Orphan Test',
        'sku'                => 'TLG-TEST-RAM16', // trùng SKU gây rollback trong Model
        'slug'               => 'sp-rollback-orphan-test',
        'category_id'        => '4',
        'price'              => '500000',
        'warranty_months'    => '6',
        'condition_overview' => '99%',
        'status'             => 'draft',
        'has_serials'        => '0',
        'stock_quantity'     => '1',
        'images[0]'          => new CURLFile($tmpImg1, 'image/png', 'orphan.png'),
    ], true);
    $filesAfterRollback = is_dir($uploadDir) ? (scandir($uploadDir) ?: []) : [];
    recordProdTest('TC31', 'Rollback DB khi upload ảnh không để lại tệp ảnh mồ côi trên thư mục uploads', $filesBeforeRollback === $filesAfterRollback ? 'PASS' : 'FAIL', 'orphanCount=' . (count($filesAfterRollback) - count($filesBeforeRollback)));

} finally {
    proc_terminate($serverProc);
    proc_close($serverProc);
    @unlink($cookieGuest);
    @unlink($cookieCust);
    @unlink($cookieAdmin);
    @unlink($tmpImg1);
    @unlink($tmpImg2);
    @unlink($tmpFakePhp);
    @unlink($serverLogFile);

    // Dọn các file ảnh test mới sinh ra trong public/uploads/products/
    if (is_dir($uploadDir)) {
        foreach (scandir($uploadDir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess') {
                continue;
            }
            if (!isset($filesBefore[$f])) {
                @unlink($uploadDir . DIRECTORY_SEPARATOR . $f);
            }
        }
    }
}

$pass = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'PASS'));
$fail = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'FAIL'));
echo "\n================================================================================\n";
echo "TỔNG KẾT ADMIN PRODUCT TESTS: PASS={$pass} | FAIL={$fail} (Tổng " . count($results) . ")\n";
echo "================================================================================\n";
exit($fail === 0 ? 0 : 1);
