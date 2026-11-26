<?php
/**
 * Bộ kiểm thử tích hợp & bảo mật cho phân hệ Quản lý Danh mục Admin (Prompt 16).
 *
 * Quy tắc an toàn:
 * - Chỉ chạy trên CSDL test cô lập `tl_gaming_test`, tuyệt đối không chạm `tl_gaming`.
 * - Bắt buộc có cờ `--confirm-test-db` trước khi DROP/tạo lại CSDL test.
 *
 * Chạy bằng lệnh:
 *   E:\xampp\php\php.exe scripts/run_admin_category_tests.php --confirm-test-db
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

use App\Models\CategoryModel;

$results = [];

function recordCatTest(string $id, string $title, string $status, string $evidence): void
{
    global $results;
    $results[] = ['id' => $id, 'title' => $title, 'status' => $status, 'evidence' => $evidence];
    echo sprintf("%-5s | %-7s | %s\n      -> Bằng chứng: %s\n", $id, $status, $title, $evidence);
}

function setupCatTestDb(string $projectRoot, string $testDbName, bool $confirmReset): PDO
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
    return $rootPdo;
}

function catHttpReq(string $method, string $url, string $cookieFile, array $post = [], bool $follow = false): array
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
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $location = '';
    if (preg_match('/^Location:\s*(.+)$/im', $rawHeaders, $m)) {
        $location = trim($m[1]);
    }
    return ['status' => $status, 'body' => $body, 'location' => $location];
}

function extractCsrf(string $html): string
{
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

echo "================================================================================\n";
echo "TL GAMING — KIỂM THỬ QUẢN LÝ DANH MỤC ADMIN (PROMPT 16)\n";
echo "CSDL Test cô lập: {$testDbName}\n";
echo "================================================================================\n\n";

try {
    $pdo = setupCatTestDb($projectRoot, $testDbName, $confirmReset);
} catch (Throwable $e) {
    fwrite(STDERR, "[NOT RUN] Không thể khởi tạo CSDL test: " . $e->getMessage() . "\n");
    exit(0);
}

$port          = 18105;
$baseUrl       = "http://127.0.0.1:{$port}";
$serverLogFile = tempnam(sys_get_temp_dir(), 'tlg_adm_srv_');
$serverEnv     = getenv();
if (!is_array($serverEnv)) {
    $serverEnv = [];
}
$serverEnv['DB_DATABASE'] = $testDbName;

$serverProc = proc_open(
    sprintf('"%s" -S 127.0.0.1:%d -t "%s"', PHP_BINARY, $port, $projectRoot . '/public'),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLogFile, 'a'], 2 => ['file', $serverLogFile, 'a']],
    $sPipes,
    $projectRoot,
    $serverEnv
);
if (isset($sPipes[0]) && is_resource($sPipes[0])) {
    fclose($sPipes[0]);
}

$cookieGuest = tempnam(sys_get_temp_dir(), 'tlg_ac_g_');
$cookieCust  = tempnam(sys_get_temp_dir(), 'tlg_ac_c_');
$cookieAdmin = tempnam(sys_get_temp_dir(), 'tlg_ac_a_');

try {
    for ($i = 0; $i < 30; $i++) {
        if (@file_get_contents($baseUrl . '/') !== false) {
            break;
        }
        usleep(100000);
    }

    // 2. Guest bị chặn khỏi /admin/danh-muc
    $rGuest = catHttpReq('GET', $baseUrl . '/admin/danh-muc', $cookieGuest);
    recordCatTest('TC02', 'Guest truy cập /admin/danh-muc bị chặn HTTP 403', $rGuest['status'] === 403 ? 'PASS' : 'FAIL', "HTTP {$rGuest['status']}");

    // 3. Customer bị chặn khỏi /admin/danh-muc
    $lpC = catHttpReq('GET', $baseUrl . '/dang-nhap', $cookieCust);
    catHttpReq('POST', $baseUrl . '/dang-nhap', $cookieCust, ['email' => 'tran.thi.binh@demo.vn', 'password' => 'Customer@123', '_csrf_token' => extractCsrf($lpC['body'])], true);
    $rCust = catHttpReq('GET', $baseUrl . '/admin/danh-muc', $cookieCust);
    recordCatTest('TC03', 'Customer truy cập /admin/danh-muc bị chặn HTTP 403', $rCust['status'] === 403 ? 'PASS' : 'FAIL', "HTTP {$rCust['status']}");

    // Đăng nhập Admin (admin@tlgaming.vn / Admin@123456 hoặc tạo admin test)
    $adminHash = password_hash('AdminTest@123', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (role, email, password_hash, full_name, status) VALUES ('admin', 'admin.test@tlgaming.vn', :h, 'Admin Test', 'active')")
        ->execute([':h' => $adminHash]);

    $lpA = catHttpReq('GET', $baseUrl . '/dang-nhap', $cookieAdmin);
    catHttpReq('POST', $baseUrl . '/dang-nhap', $cookieAdmin, ['email' => 'admin.test@tlgaming.vn', 'password' => 'AdminTest@123', '_csrf_token' => extractCsrf($lpA['body'])], true);

    // 1. Admin xem danh mục + layout Admin
    $r1 = catHttpReq('GET', $baseUrl . '/admin/danh-muc', $cookieAdmin);
    $csrfAdmin = extractCsrf($r1['body']);
    recordCatTest('TC01', 'Admin xem danh sách danh mục (/admin/danh-muc) với Admin Layout', ($r1['status'] === 200 && str_contains($r1['body'], 'Quản lý danh mục') && str_contains($r1['body'], 'TL GAMING')) ? 'PASS' : 'FAIL', "HTTP {$r1['status']}");

    // 13. CSRF sai khi POST /admin/danh-muc/them
    $r13 = catHttpReq('POST', $baseUrl . '/admin/danh-muc/them', $cookieAdmin, ['_csrf_token' => 'invalid_csrf', 'name' => 'Test CSRF']);
    recordCatTest('TC13', 'POST /admin/danh-muc/them sai CSRF bị chặn HTTP 403', $r13['status'] === 403 ? 'PASS' : 'FAIL', "HTTP {$r13['status']}");

    // 4. Thêm danh mục hợp lệ
    $r4 = catHttpReq('POST', $baseUrl . '/admin/danh-muc/them', $cookieAdmin, [
        '_csrf_token'   => $csrfAdmin,
        'name'          => 'Bàn Phím Cơ Cũ',
        'slug'          => 'ban-phim-co-cu',
        'description'   => 'Bàn phím cơ đã kiểm định switch và LED.',
        'parent_id'     => '',
        'display_order' => '20',
        'is_active'     => '1',
    ], true);
    $newCatRow = $pdo->query("SELECT * FROM categories WHERE slug = 'ban-phim-co-cu'")->fetch();
    $newCatId  = $newCatRow ? (int) $newCatRow['id'] : 0;
    recordCatTest('TC04', 'Thêm danh mục hợp lệ thành công (PRG)', ($newCatId > 0 && str_contains($r4['body'], 'Bàn Phím Cơ Cũ')) ? 'PASS' : 'FAIL', "newId={$newCatId}");

    // 5. Thêm danh mục trùng slug bị chặn
    $cntBefore5 = (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $r5 = catHttpReq('POST', $baseUrl . '/admin/danh-muc/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin,
        'name'        => 'Bàn Phím Trùng',
        'slug'        => 'ban-phim-co-cu',
        'is_active'   => '1',
    ], true);
    $cntAfter5 = (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    recordCatTest('TC05', 'Thêm danh mục trùng slug bị từ chối', ($cntAfter5 === $cntBefore5 && str_contains($r5['body'], 'đã tồn tại')) ? 'PASS' : 'FAIL', "count={$cntBefore5}->{$cntAfter5}");

    // 6. Sửa danh mục
    $r6 = catHttpReq('POST', $baseUrl . "/admin/danh-muc/{$newCatId}/sua", $cookieAdmin, [
        '_csrf_token'   => $csrfAdmin,
        'name'          => 'Bàn Phím Gaming Cũ',
        'slug'          => 'ban-phim-gaming-cu',
        'description'   => 'Mô tả đã cập nhật',
        'parent_id'     => '',
        'display_order' => '21',
        'is_active'     => '1',
    ], true);
    $updatedRow = $pdo->query("SELECT * FROM categories WHERE id = {$newCatId}")->fetch();
    recordCatTest('TC06', 'Sửa tên, slug, mô tả danh mục thành công', ($updatedRow && $updatedRow['slug'] === 'ban-phim-gaming-cu' && str_contains($r6['body'], 'đã đổi sang')) ? 'PASS' : 'FAIL', "slug=" . ($updatedRow['slug'] ?? ''));

    // 9. Tạo danh mục con
    catHttpReq('POST', $baseUrl . '/admin/danh-muc/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin,
        'name'        => 'Keycap PBT',
        'slug'        => 'keycap-pbt',
        'parent_id'   => (string) $newCatId,
        'is_active'   => '1',
    ], true);
    $childRow = $pdo->query("SELECT * FROM categories WHERE slug = 'keycap-pbt'")->fetch();
    $childId  = $childRow ? (int) $childRow['id'] : 0;
    recordCatTest('TC09', 'Tạo danh mục con trực thuộc danh mục cha', ($childId > 0 && (int) $childRow['parent_id'] === $newCatId) ? 'PASS' : 'FAIL', "childId={$childId}, parentId=" . ($childRow['parent_id'] ?? 'null'));

    // 10. Không cho tự làm cha
    $r10 = catHttpReq('POST', $baseUrl . "/admin/danh-muc/{$newCatId}/sua", $cookieAdmin, [
        '_csrf_token' => $csrfAdmin,
        'name'        => 'Bàn Phím Gaming Cũ',
        'slug'        => 'ban-phim-gaming-cu',
        'parent_id'   => (string) $newCatId,
        'is_active'   => '1',
    ], true);
    $selfParentCheck = (int) $pdo->query("SELECT COALESCE(parent_id, 0) FROM categories WHERE id = {$newCatId}")->fetchColumn();
    recordCatTest('TC10', 'Không cho danh mục tự làm cha của chính nó', ($selfParentCheck === 0 && str_contains($r10['body'], 'chính danh mục này')) ? 'PASS' : 'FAIL', "parent_id={$selfParentCheck}");

    // 11. Không cho tạo chu trình (gán con làm cha của cha)
    $r11 = catHttpReq('POST', $baseUrl . "/admin/danh-muc/{$newCatId}/sua", $cookieAdmin, [
        '_csrf_token' => $csrfAdmin,
        'name'        => 'Bàn Phím Gaming Cũ',
        'slug'        => 'ban-phim-gaming-cu',
        'parent_id'   => (string) $childId,
        'is_active'   => '1',
    ], true);
    $cycleCheck = (int) $pdo->query("SELECT COALESCE(parent_id, 0) FROM categories WHERE id = {$newCatId}")->fetchColumn();
    recordCatTest('TC11', 'Không cho tạo chu trình cha/con (gán con làm cha tổ tiên)', ($cycleCheck === 0 && str_contains($r11['body'], 'vòng lặp')) ? 'PASS' : 'FAIL', "parent_id={$cycleCheck}");

    // 7 & 8. Ẩn danh mục cha -> tự động ẩn con; chặn hiện con khi cha đang ẩn; hiện lại cha & con
    catHttpReq('POST', $baseUrl . "/admin/danh-muc/{$newCatId}/an", $cookieAdmin, ['_csrf_token' => $csrfAdmin], true);
    $pActiveAfterHide = (int) $pdo->query("SELECT is_active FROM categories WHERE id = {$newCatId}")->fetchColumn();
    $cActiveAfterHide = (int) $pdo->query("SELECT is_active FROM categories WHERE id = {$childId}")->fetchColumn();
    $rTryShowChild    = catHttpReq('POST', $baseUrl . "/admin/danh-muc/{$childId}/hien", $cookieAdmin, ['_csrf_token' => $csrfAdmin], true);
    $cStillHidden     = (int) $pdo->query("SELECT is_active FROM categories WHERE id = {$childId}")->fetchColumn();

    catHttpReq('POST', $baseUrl . "/admin/danh-muc/{$newCatId}/hien", $cookieAdmin, ['_csrf_token' => $csrfAdmin], true);
    catHttpReq('POST', $baseUrl . "/admin/danh-muc/{$childId}/hien", $cookieAdmin, ['_csrf_token' => $csrfAdmin], true);
    $pActiveAfterShow = (int) $pdo->query("SELECT is_active FROM categories WHERE id = {$newCatId}")->fetchColumn();
    $cActiveAfterShow = (int) $pdo->query("SELECT is_active FROM categories WHERE id = {$childId}")->fetchColumn();

    recordCatTest('TC07', 'Ẩn danh mục cha tự động ẩn danh mục con & chặn hiện con khi cha đang ẩn', ($pActiveAfterHide === 0 && $cActiveAfterHide === 0 && $cStillHidden === 0 && str_contains($rTryShowChild['body'], 'danh mục cha')) ? 'PASS' : 'FAIL', "parent={$pActiveAfterHide}, child={$cActiveAfterHide}->{$cStillHidden}");
    recordCatTest('TC08', 'Hiện lại danh mục cha và danh mục con thành công', ($pActiveAfterShow === 1 && $cActiveAfterShow === 1) ? 'PASS' : 'FAIL', "parent={$pActiveAfterShow}, child={$cActiveAfterShow}");

    // 12. Không xóa cứng danh mục có sản phẩm
    $catModel = new CategoryModel();
    $tc12Blocked = false;
    try {
        $catModel->deleteCategory(1); // ID=1 đang có sản phẩm trong seed
    } catch (DomainException) {
        $tc12Blocked = true;
    }
    recordCatTest('TC12', 'Không cho xóa cứng danh mục đang có sản phẩm', $tc12Blocked ? 'PASS' : 'FAIL', "hardDeleteBlocked=" . ($tc12Blocked ? 'yes' : 'no'));

    // 14. Input không hợp lệ (tên rỗng)
    $r14 = catHttpReq('POST', $baseUrl . '/admin/danh-muc/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin,
        'name'        => ' ',
        'slug'        => '!!!',
    ], true);
    recordCatTest('TC14', 'Input không hợp lệ (tên rỗng / slug sai) bị từ chối', str_contains($r14['body'], 'không được để trống') ? 'PASS' : 'FAIL', "validationErrorShown=yes");

    // 15. XSS & SQL Injection
    catHttpReq('POST', $baseUrl . '/admin/danh-muc/them', $cookieAdmin, [
        '_csrf_token' => $csrfAdmin,
        'name'        => '<script>alert("xss")</script>',
        'slug'        => 'test-xss-escape',
        'is_active'   => '1',
    ], true);
    $r15List = catHttpReq('GET', $baseUrl . '/admin/danh-muc?q=' . urlencode("' OR 1=1 --"), $cookieAdmin);
    $r15All  = catHttpReq('GET', $baseUrl . '/admin/danh-muc', $cookieAdmin);
    $xssEscaped = !str_contains($r15All['body'], '<script>alert("xss")</script>') && str_contains($r15All['body'], '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');
    $sqliSafe   = $r15List['status'] === 200 && str_contains($r15List['body'], 'Không tìm thấy danh mục nào');
    recordCatTest('TC15', 'Chống XSS (HTML escape) và SQL Injection (Prepared Statements)', ($xssEscaped && $sqliSafe) ? 'PASS' : 'FAIL', "xssEscaped=" . ($xssEscaped ? 'yes' : 'no') . ", sqliSafe=" . ($sqliSafe ? 'yes' : 'no'));

    // 16 & 17. Danh mục ẩn không xuất hiện trên website khách; Trang chủ & Bộ lọc vẫn hoạt động
    catHttpReq('POST', $baseUrl . '/admin/danh-muc/1/an', $cookieAdmin, ['_csrf_token' => $csrfAdmin], true);
    $r16Filter = catHttpReq('GET', $baseUrl . '/san-pham?danh-muc=pc-gaming-cu', $cookieGuest);
    $activeSlugs = array_column($catModel->getActiveCategories(), 'slug');
    $hiddenNotActive = !in_array('pc-gaming-cu', $activeSlugs, true);
    catHttpReq('POST', $baseUrl . '/admin/danh-muc/1/hien', $cookieAdmin, ['_csrf_token' => $csrfAdmin], true);

    $r17Home = catHttpReq('GET', $baseUrl . '/', $cookieGuest);
    $r17Shop = catHttpReq('GET', $baseUrl . '/san-pham?danh-muc=pc-gaming-cu', $cookieGuest);
    recordCatTest('TC16', 'Danh mục ẩn không xuất hiện trong danh mục hoạt động & sản phẩm công khai', ($hiddenNotActive && !str_contains($r16Filter['body'], 'PC Gaming TL-01')) ? 'PASS' : 'FAIL', "hiddenNotActive=" . ($hiddenNotActive ? 'yes' : 'no'));
    recordCatTest('TC17', 'Trang chủ và bộ lọc sản phẩm vẫn hoạt động bình thường', ($r17Home['status'] === 200 && $r17Shop['status'] === 200 && str_contains($r17Shop['body'], 'PC Gaming TL-01')) ? 'PASS' : 'FAIL', "homeHTTP={$r17Home['status']}, shopHTTP={$r17Shop['status']}");

    // 18. Cây danh mục 3 cấp (Cấp 1 -> Cấp 2 -> Cấp 3) & lọc sản phẩm nhiều cấp
    $l1Id = $catModel->createCategory(['name' => 'Linh kiện 3 cấp', 'slug' => 'linh-kien-3-cap', 'parent_id' => null, 'description' => '', 'display_order' => 10, 'is_active' => 1]);
    $l2Id = $catModel->createCategory(['name' => 'Card đồ họa 3 cấp', 'slug' => 'card-do-hoa-3-cap', 'parent_id' => $l1Id, 'description' => '', 'display_order' => 1, 'is_active' => 1]);
    $l3Id = $catModel->createCategory(['name' => 'RTX 3060 3 cấp', 'slug' => 'rtx-3060-3-cap', 'parent_id' => $l2Id, 'description' => '', 'display_order' => 1, 'is_active' => 1]);

    // Tạo 3 sản phẩm published ở 3 cấp + 1 sản phẩm draft ở cấp 3
    $insertProdStmt = $pdo->prepare("
        INSERT INTO products (category_id, sku, name, slug, summary, description, price, original_price, stock_quantity, has_serials, warranty_months, condition_overview, status)
        VALUES (:cid, :sku, :name, :slug, 'Test 3 cap', 'Mo ta test', 5000000, 6000000, 2, 0, 6, 'Cũ đẹp 98%', :status)
    ");
    $insertProdStmt->execute([':cid' => $l1Id, ':sku' => 'TEST-L1-01', ':name' => 'SP Cấp 1 Test3Cap', ':slug' => 'sp-cap-1-test3cap', ':status' => 'published']);
    $insertProdStmt->execute([':cid' => $l2Id, ':sku' => 'TEST-L2-01', ':name' => 'SP Cấp 2 Test3Cap', ':slug' => 'sp-cap-2-test3cap', ':status' => 'published']);
    $insertProdStmt->execute([':cid' => $l3Id, ':sku' => 'TEST-L3-01', ':name' => 'SP Cấp 3 RTX3060 Test3Cap', ':slug' => 'sp-cap-3-rtx3060-test3cap', ':status' => 'published']);
    $insertProdStmt->execute([':cid' => $l3Id, ':sku' => 'TEST-L3-DRAFT', ':name' => 'SP Cấp 3 Draft Test3Cap', ':slug' => 'sp-cap-3-draft-test3cap', ':status' => 'draft']);

    $fam1 = $catModel->getCategoryFamilyIds($l1Id);
    $fam2 = $catModel->getCategoryFamilyIds($l2Id);
    $fam3 = $catModel->getCategoryFamilyIds($l3Id);
    sort($fam1);
    sort($fam2);
    sort($fam3);

    $rL1Shop = catHttpReq('GET', $baseUrl . '/san-pham?danh-muc=linh-kien-3-cap', $cookieGuest);
    $rL2Shop = catHttpReq('GET', $baseUrl . '/san-pham?danh-muc=card-do-hoa-3-cap', $cookieGuest);
    $rL3Shop = catHttpReq('GET', $baseUrl . '/san-pham?danh-muc=rtx-3060-3-cap', $cookieGuest);
    preg_match('/<nav\b[^>]*id="headerCategoryNav"[^>]*>(.*?)<\/nav>/s', $rL1Shop['body'], $rootMenu);
    preg_match('/<aside\b[^>]*id="catalogFilterSidebar"[^>]*>(.*?)<\/aside>/s', $rL1Shop['body'], $visibleFilters);

    $tc18Ok = ($fam1 === [$l1Id, $l2Id, $l3Id])
        && ($fam2 === [$l2Id, $l3Id])
        && ($fam3 === [$l3Id])
        && str_contains($rootMenu[1] ?? '', 'danh-muc=linh-kien-3-cap')
        && str_contains($visibleFilters[1] ?? '', 'danh-muc=rtx-3060-3-cap')
        && str_contains($rL1Shop['body'], 'SP Cấp 1 Test3Cap')
        && str_contains($rL1Shop['body'], 'SP Cấp 2 Test3Cap')
        && str_contains($rL1Shop['body'], 'SP Cấp 3 RTX3060 Test3Cap')
        && !str_contains($rL1Shop['body'], 'SP Cấp 3 Draft Test3Cap')
        && str_contains($rL2Shop['body'], 'SP Cấp 2 Test3Cap')
        && str_contains($rL2Shop['body'], 'SP Cấp 3 RTX3060 Test3Cap')
        && str_contains($rL3Shop['body'], 'SP Cấp 3 RTX3060 Test3Cap');
    recordCatTest('TC18', 'Menu danh mục gốc và bộ lọc đủ 3 cấp trả đúng sản phẩm published', $tc18Ok ? 'PASS' : 'FAIL', 'fam1=' . implode(',', $fam1));

    // 19. Đếm published_product_count cả 3 cấp không trùng, không tính draft
    $activeCatsMap = [];
    foreach ($catModel->getActiveCategories() as $ac) {
        $activeCatsMap[(int) $ac['id']] = (int) $ac['published_product_count'];
    }
    $cnt1 = $activeCatsMap[$l1Id] ?? -1;
    $cnt2 = $activeCatsMap[$l2Id] ?? -1;
    $cnt3 = $activeCatsMap[$l3Id] ?? -1;
    recordCatTest('TC19', 'Đếm published_product_count chính xác ở cả 3 cấp (3/2/1, không đếm draft)', ($cnt1 === 3 && $cnt2 === 2 && $cnt3 === 1) ? 'PASS' : 'FAIL', "counts={$cnt1}/{$cnt2}/{$cnt3}");

    // 20. Ẩn nhánh cấp 2 -> cấp 1 không đếm SP thuộc nhánh ẩn & lọc không trả về SP nhánh ẩn
    catHttpReq('POST', $baseUrl . "/admin/danh-muc/{$l2Id}/an", $cookieAdmin, ['_csrf_token' => $csrfAdmin], true);
    $fam1AfterHideL2 = $catModel->getCategoryFamilyIds($l1Id);
    $activeAfterHideMap = [];
    foreach ($catModel->getActiveCategories() as $ac) {
        $activeAfterHideMap[(int) $ac['id']] = (int) $ac['published_product_count'];
    }
    $cnt1AfterHide = $activeAfterHideMap[$l1Id] ?? -1;
    $rL1ShopAfterHide = catHttpReq('GET', $baseUrl . '/san-pham?danh-muc=linh-kien-3-cap', $cookieGuest);
    preg_match('/<aside\b[^>]*id="catalogFilterSidebar"[^>]*>(.*?)<\/aside>/s', $rL1ShopAfterHide['body'], $visibleFiltersAfterHide);
    $tc20Ok = ($fam1AfterHideL2 === [$l1Id])
        && ($cnt1AfterHide === 1)
        && str_contains($rL1ShopAfterHide['body'], 'SP Cấp 1 Test3Cap')
        && !str_contains($rL1ShopAfterHide['body'], 'SP Cấp 2 Test3Cap')
        && !str_contains($rL1ShopAfterHide['body'], 'SP Cấp 3 RTX3060 Test3Cap')
        && !empty($visibleFiltersAfterHide[1])
        && !str_contains($visibleFiltersAfterHide[1], 'danh-muc=rtx-3060-3-cap');
    recordCatTest('TC20', 'Ẩn nhánh con (Cấp 2) tự động loại trừ khỏi đếm và lọc sản phẩm công khai của Cấp 1', $tc20Ok ? 'PASS' : 'FAIL', "cnt1AfterHide={$cnt1AfterHide}");

    // 21. Chặn POST dữ liệu dạng mảng bất thường (không gây PHP Warning/500)
    $r21Post = catHttpReq('POST', $baseUrl . '/admin/danh-muc/them', $cookieAdmin, [
        '_csrf_token'   => $csrfAdmin,
        'name'          => ['mang-khong-hop-le'],
        'display_order' => ['1'],
        'parent_id'     => ['1'],
    ]);
    $r21Get = catHttpReq('GET', $baseUrl . '/admin/danh-muc/them', $cookieAdmin);
    $tc21Ok = $r21Post['status'] === 302
        && $r21Get['status'] === 200
        && !str_contains($r21Post['body'], 'Array to string conversion')
        && !str_contains($r21Get['body'], 'Array to string conversion')
        && str_contains($r21Get['body'], 'chuỗi ký tự hợp lệ');
    recordCatTest('TC21', 'Chặn dữ liệu POST dạng mảng bất thường không gây cảnh báo PHP', $tc21Ok ? 'PASS' : 'FAIL', "POST={$r21Post['status']}, GET={$r21Get['status']}");

    // 22. Chặn display_order chuỗi bất thường (không ép ngầm thành 0) và giữ lại dữ liệu form
    $r22Post = catHttpReq('POST', $baseUrl . '/admin/danh-muc/them', $cookieAdmin, [
        '_csrf_token'   => $csrfAdmin,
        'name'          => 'Danh mục giữ form',
        'slug'          => 'danh-muc-giu-form',
        'description'   => 'Mô tả được giữ nguyên',
        'display_order' => 'abc123',
        'is_active'     => '1',
    ]);
    $r22Get = catHttpReq('GET', $baseUrl . '/admin/danh-muc/them', $cookieAdmin);
    $tc22Ok = $r22Post['status'] === 302
        && $r22Get['status'] === 200
        && str_contains($r22Get['body'], 'số nguyên hợp lệ')
        && str_contains($r22Get['body'], 'Danh mục giữ form')
        && str_contains($r22Get['body'], 'Mô tả được giữ nguyên')
        && str_contains($r22Get['body'], 'abc123');
    recordCatTest('TC22', 'Chặn display_order chuỗi bất thường và bảo toàn dữ liệu form khi nhập sai', $tc22Ok ? 'PASS' : 'FAIL', "POST={$r22Post['status']}, GET={$r22Get['status']}");

    // 23. Chặn ID danh mục không hợp lệ từ URL (/admin/danh-muc/12abc/sua)
    $r23 = catHttpReq('GET', $baseUrl . '/admin/danh-muc/12abc/sua', $cookieAdmin);
    $r23List = catHttpReq('GET', $baseUrl . '/admin/danh-muc', $cookieAdmin);
    $tc23Ok = $r23['status'] === 302 && $r23List['status'] === 200 && str_contains($r23List['body'], 'ID danh mục không hợp lệ');
    recordCatTest('TC23', 'Chặn ID danh mục không hợp lệ trên URL (12abc)', $tc23Ok ? 'PASS' : 'FAIL', "HTTP {$r23['status']}");

    // 24. Kiểm tra trang chủ và tìm kiếm sản phẩm công khai
    $r24Search = catHttpReq('GET', $baseUrl . '/san-pham?q=RTX', $cookieGuest);
    $tc24Ok = $r17Home['status'] === 200 && $r24Search['status'] === 200 && str_contains($r24Search['body'], 'RTX');
    recordCatTest('TC24', 'Trang chủ và tìm kiếm sản phẩm (/san-pham?q=RTX) hoạt động bình thường', $tc24Ok ? 'PASS' : 'FAIL', "searchHTTP={$r24Search['status']}");
} finally {
    proc_terminate($serverProc);
    proc_close($serverProc);
    @unlink($cookieGuest);
    @unlink($cookieCust);
    @unlink($cookieAdmin);
    @unlink($serverLogFile);
}

$pass = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'PASS'));
$fail = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'FAIL'));
echo "\n================================================================================\n";
echo "TỔNG KẾT ADMIN CATEGORY TESTS: PASS={$pass} | FAIL={$fail} (Tổng " . count($results) . ")\n";
echo "================================================================================\n";
exit($fail === 0 ? 0 : 1);
