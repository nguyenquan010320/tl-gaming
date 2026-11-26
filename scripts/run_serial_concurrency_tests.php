<?php
/**
 * Bộ kiểm thử Tương tranh, Khóa (Lock Ordering) & Deadlock Retry (Prompt 18.2) cho TL Gaming.
 * Chạy trên CSDL cô lập `tl_gaming_test` (bắt buộc cờ `--confirm-test-db`).
 *
 * Đặc điểm:
 * - Dùng 2 kết nối PDO độc lập ($pdoA, $pdoB) để kiểm tra khóa lạc quan, lock wait giữa 2 kết nối thật và retry.
 * - Dùng `proc_open` để chạy nhiều tiến trình PHP song song thực sự (overlapping processes).
 * - Chỉ sử dụng đúng ENUM schema:
 *   + `product_units.status`: available | reserved | sold | repairing | returned
 *   + `product_units.cosmetic_grade`: Grade A | Grade B | Grade C
 *   + `order_items.product_unit_id`
 * - Tuyệt đối không chạm vào CSDL vận hành `tl_gaming`.
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

use App\Core\CheckoutException;
use App\Core\Model;
use App\Models\CartModel;
use App\Models\InspectionModel;
use App\Models\OrderModel;
use App\Models\ProductUnitModel;

const TEST_DB_NAME = 'tl_gaming_test';

// Ép toàn bộ cấu hình DB sang `tl_gaming_test`, tuyệt đối không dùng `tl_gaming`
putenv('DB_DATABASE=' . TEST_DB_NAME);
$_ENV['DB_DATABASE']    = TEST_DB_NAME;
$_SERVER['DB_DATABASE'] = TEST_DB_NAME;

$args = $argv ?? [];

/**
 * Tạo một kết nối PDO độc lập tới `tl_gaming_test`.
 */
function createIndependentTestPdo(string $dbName): PDO
{
    if ($dbName !== TEST_DB_NAME) {
        throw new RuntimeException("Từ chối kết nối ngoài CSDL test: {$dbName}");
    }

    $host    = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
    $port    = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
    $user    = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root';
    $pass    = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';
    $charset = $_ENV['DB_CHARSET'] ?? getenv('DB_CHARSET') ?: 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
    return $pdo;
}

/**
 * Tạo đủ 18 bước kiểm định đạt chuẩn theo đúng chữ ký `InspectionModel::createInspection()`.
 *
 * @return array<int, array<string, string>>
 */
function buildPassing18Items(): array
{
    $items = [];
    foreach (InspectionModel::OFFICIAL_18_STEPS as $stepNumber => $stepMeta) {
        $items[] = [
            'step_number' => (string) $stepNumber,
            'result'      => 'pass',
            'notes'       => 'Đạt chuẩn kiểm định concurrency test (' . $stepMeta['item_name'] . ')',
        ];
    }
    return $items;
}

// ============================================================================
// CHẾ ĐỘ WORKER CON (chạy song song qua proc_open)
// ============================================================================
foreach ($args as $arg) {
    if (str_starts_with($arg, '--worker=')) {
        $workerTask = substr($arg, 9);
        $payloadRaw = stream_get_contents(STDIN);
        $payload    = json_decode($payloadRaw ?: '{}', true);
        if (!is_array($payload)) {
            echo json_encode(['ok' => false, 'type' => 'invalid_payload', 'message' => 'Payload JSON không hợp lệ']);
            exit(1);
        }

        // Barrier đồng bộ thời điểm bắt đầu giữa các tiến trình con
        if (!empty($payload['start_at_us'])) {
            $nowUs = (int) (microtime(true) * 1_000_000);
            $wait  = (int) $payload['start_at_us'] - $nowUs;
            if ($wait > 0 && $wait < 5_000_000) {
                usleep($wait);
            }
        }

        try {
            $pdo = createIndependentTestPdo(TEST_DB_NAME);

            if ($workerTask === 'checkout') {
                if (session_status() === PHP_SESSION_NONE) {
                    session_id('cc-worker-' . bin2hex(random_bytes(8)));
                    session_start();
                }
                $token      = OrderModel::issueIdempotencyToken(null);
                $orderModel = new OrderModel($pdo);
                $productId  = (int) $payload['product_id'];
                $input      = [
                    'customer_name'     => (string) ($payload['customer_name'] ?? 'Khách Concurrency'),
                    'customer_email'    => (string) ($payload['customer_email'] ?? 'cc@tlgaming.test'),
                    'customer_phone'    => (string) ($payload['customer_phone'] ?? '0909123456'),
                    'shipping_city'     => 'TP. Hồ Chí Minh',
                    'shipping_district' => 'Quận 1',
                    'shipping_address'  => '123 Đường Test Concurrency',
                    'customer_note'     => 'Concurrency checkout worker',
                    'delivery_method'   => 'delivery',
                    'payment_method'    => 'cod',
                    'coupon_code'       => '',
                ];
                $res = $orderModel->createOrderFromCheckout(
                    $input,
                    null,
                    [$productId => 1],
                    [],
                    $token
                );
                echo json_encode(['ok' => true, 'type' => 'success', 'data' => $res]);
                exit(0);
            }

            if ($workerTask === 'inspect') {
                $inspModel = new InspectionModel($pdo);
                $res = $inspModel->createInspection(
                    (int) $payload['unit_id'],
                    (int) ($payload['inspector_id'] ?? 1),
                    (string) ($payload['overall_result'] ?? 'passed'),
                    'Concurrency inspection worker',
                    buildPassing18Items(),
                    !empty($payload['mark_available'])
                );
                echo json_encode(['ok' => true, 'type' => 'success', 'data' => $res]);
                exit(0);
            }

            if ($workerTask === 'update-unit') {
                $unitModel = new ProductUnitModel($pdo);
                $unitModel->updateUnit((int) $payload['unit_id'], $payload['data']);
                echo json_encode(['ok' => true, 'type' => 'success']);
                exit(0);
            }

            if ($workerTask === 'create-unit') {
                $unitModel = new ProductUnitModel($pdo);
                $unitId = $unitModel->createUnit($payload['data']);
                echo json_encode(['ok' => true, 'type' => 'success', 'unit_id' => $unitId]);
                exit(0);
            }

            echo json_encode(['ok' => false, 'type' => 'unknown_worker', 'message' => $workerTask]);
            exit(1);
        } catch (CheckoutException | DomainException $e) {
            // Lỗi nghiệp vụ dự kiến (hết hàng, xung đột row_version, trạng thái không hợp lệ)
            echo json_encode(['ok' => false, 'type' => 'business', 'message' => $e->getMessage()]);
            exit(0);
        } catch (Throwable $e) {
            // Lỗi hệ thống thực sự (SQL error, deadlock chưa xử lý, fatal...)
            echo json_encode(['ok' => false, 'type' => 'system', 'message' => $e->getMessage()]);
            exit(1);
        }
    }
}

// ============================================================================
// CHẾ ĐỘ CHÍNH: XÁC MINH AN TOÀN DB TEST & CHẠY BỘ KIỂM THỬ
// ============================================================================
if (!in_array('--confirm-test-db', $args, true)) {
    fwrite(STDERR, "Từ chối chạy: Bắt buộc truyền cờ `--confirm-test-db` để xác nhận chạy trên `" . TEST_DB_NAME . "`.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$activeEnvDb = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');
if ($activeEnvDb !== TEST_DB_NAME || TEST_DB_NAME === 'tl_gaming') {
    fwrite(STDERR, "AN TOÀN DB: Từ chối reset vì DB mục tiêu không phải `tl_gaming_test` (hiện tại: {$activeEnvDb}).\n");
    exit(1);
}

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

// Xác nhận tên DB mục tiêu trước khi DROP/CREATE, tuyệt đối không tác động `tl_gaming`
$dbCheckStmt = $rootPdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db');
$dbCheckStmt->execute([':db' => TEST_DB_NAME]);
$testDbExists = $dbCheckStmt->fetchColumn() !== false;

if ($testDbExists) {
    $rootPdo->exec('DROP DATABASE `' . TEST_DB_NAME . '`');
}
$rootPdo->exec('CREATE DATABASE `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$schemaSql = str_replace('`tl_gaming`', '`' . TEST_DB_NAME . '`', (string) file_get_contents($projectRoot . '/database/tl_gaming.sql'));
$seedSql   = str_replace('`tl_gaming`', '`' . TEST_DB_NAME . '`', (string) file_get_contents($projectRoot . '/database/seed_tl_gaming.sql'));

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
if (!is_resource($resetProc)) {
    fwrite(STDERR, "Lỗi: Không thể khởi tạo tiến trình mysql để nạp `" . TEST_DB_NAME . "`.\n");
    exit(1);
}
fwrite($resetPipes[0], 'USE `' . TEST_DB_NAME . "`;\n" . $schemaSql . "\n" . $seedSql);
fclose($resetPipes[0]);
$resetStdout = stream_get_contents($resetPipes[1]);
$resetStderr = stream_get_contents($resetPipes[2]);
fclose($resetPipes[1]);
fclose($resetPipes[2]);
$resetCode = proc_close($resetProc);
if ($resetCode !== 0) {
    fwrite(STDERR, "Lỗi: Không thể nạp CSDL test `" . TEST_DB_NAME . "`: {$resetStderr} {$resetStdout}\n");
    exit(1);
}

$results = [];
function recordCcTest(string $code, string $name, string $status, string $evidence = ''): void
{
    global $results;
    $results[] = ['code' => $code, 'name' => $name, 'status' => $status, 'evidence' => $evidence];
    echo sprintf("%-6s| %-7s | %s\n", $code, $status, $name);
    if ($evidence !== '') {
        echo "      -> Bằng chứng: {$evidence}\n";
    }
}

/**
 * Chạy song song N worker con bằng proc_open với barrier đồng bộ thời gian bắt đầu.
 *
 * @param array<int, array{task: string, payload: array<string, mixed>}> $jobs
 * @return array<int, array<string, mixed>>
 */
function runParallelWorkers(array $jobs): array
{
    $phpBin     = PHP_BINARY ?: 'E:\\xampp\\php\\php.exe';
    $scriptPath = __FILE__;
    $startAtUs  = (int) (microtime(true) * 1_000_000) + 250_000;
    $procs      = [];

    foreach ($jobs as $idx => $job) {
        $cmd = sprintf('"%s" "%s" --worker=%s', $phpBin, $scriptPath, $job['task']);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
        if (!is_resource($proc)) {
            throw new RuntimeException("Không thể khởi tạo worker #{$idx}");
        }

        $payload                = $job['payload'];
        $payload['start_at_us'] = $startAtUs;
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);

        $procs[$idx] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    $outputs = [];
    foreach ($procs as $idx => $item) {
        $stdout   = stream_get_contents($item['stdout']);
        $stderr   = stream_get_contents($item['stderr']);
        fclose($item['stdout']);
        fclose($item['stderr']);
        $exitCode = proc_close($item['proc']);

        $decoded = json_decode(trim((string) $stdout), true);
        if (!is_array($decoded)) {
            $decoded = [
                'ok'        => false,
                'type'      => 'raw_error',
                'exit_code' => $exitCode,
                'stdout'    => $stdout,
                'stderr'    => $stderr,
            ];
        } else {
            $decoded['exit_code'] = $exitCode;
            if ($stderr !== '') {
                $decoded['stderr'] = $stderr;
            }
        }
        $outputs[$idx] = $decoded;
    }

    return $outputs;
}

/**
 * Kiểm tra tồn kho cache (`products.stock_quantity`) khớp tuyệt đối với `sellableUnitWhereSql()`.
 */
function assertProductStockMatchesSellable(PDO $pdo, int $productId): array
{
    $sellableWhere = ProductUnitModel::sellableUnitWhereSql('pu');
    $stmtP = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :pid');
    $stmtP->execute([':pid' => $productId]);
    $cached = (int) $stmtP->fetchColumn();

    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = :pid AND {$sellableWhere}");
    $stmtC->execute([':pid' => $productId]);
    $actual = (int) $stmtC->fetchColumn();

    return ['cached' => $cached, 'actual' => $actual, 'match' => $cached === $actual];
}

/**
 * Tạo nhanh 1 serial mới đúng ENUM schema trong DB test (`repairing` hoặc `available`, `Grade A/B/C`).
 */
function insertTestSerial(PDO $pdo, int $productId, string $serial, string $status = 'repairing', string $grade = 'Grade A'): int
{
    $stmt = $pdo->prepare("
        INSERT INTO product_units (product_id, serial_number, status, cosmetic_grade, import_price, row_version, imported_at)
        VALUES (:pid, :serial, :status, :grade, 10000000, 1, NOW())
    ");
    $stmt->execute([
        ':pid'    => $productId,
        ':serial' => $serial,
        ':status' => $status,
        ':grade'  => $grade,
    ]);
    return (int) $pdo->lastInsertId();
}

echo "=== BỘ KIỂM THỬ TƯƠNG TRANH & DEADLOCK RETRY (PROMPT 18.2) ===\n";
echo "CSDL test (an toàn, tách biệt khỏi tl_gaming): " . TEST_DB_NAME . "\n\n";

$pdoA = createIndependentTestPdo(TEST_DB_NAME);
$pdoB = createIndependentTestPdo(TEST_DB_NAME);

$serialProducts = $pdoA->query('SELECT id, sku, stock_quantity FROM products WHERE has_serials = 1 ORDER BY id ASC LIMIT 4')->fetchAll();
$prod1 = (int) $serialProducts[0]['id'];
$prod2 = (int) $serialProducts[1]['id'];
$prod3 = (int) $serialProducts[2]['id'];
$prod4 = (int) $serialProducts[3]['id'];

// ----------------------------------------------------------------------------
// CC-01: Checkout vs Admin Inspection song song trên cùng 1 sản phẩm
// - Tạo sẵn 1 máy sellable (status=available + 18 bước passed) và 1 máy repairing trên prod1
// ----------------------------------------------------------------------------
$cc01UnitSellable = insertTestSerial($pdoA, $prod1, 'SN-CC01-SELLABLE', 'repairing', 'Grade A');
(new InspectionModel($pdoA))->createInspection($cc01UnitSellable, 1, 'passed', 'CC-01 setup sellable', buildPassing18Items(), true);
$cc01UnitRepairing = insertTestSerial($pdoA, $prod1, 'SN-CC01-REPAIRING', 'repairing', 'Grade A');
$cc01Before        = assertProductStockMatchesSellable($pdoA, $prod1);
$cc01Workers       = runParallelWorkers([
    [
        'task'    => 'checkout',
        'payload' => [
            'product_id'      => $prod1,
            'idempotency_key' => 'cc01-checkout-' . bin2hex(random_bytes(8)),
        ],
    ],
    [
        'task'    => 'inspect',
        'payload' => [
            'unit_id'        => $cc01UnitRepairing,
            'inspector_id'   => 1,
            'overall_result' => 'passed',
            'mark_available' => true,
        ],
    ],
]);
$cc01After = assertProductStockMatchesSellable($pdoA, $prod1);
$cc01Pass  = $cc01Workers[0]['ok'] && $cc01Workers[1]['ok'] && $cc01After['match'] && $cc01After['actual'] === $cc01Before['actual'];
recordCcTest(
    'CC-01',
    'Checkout vs Admin kiểm định song song trên cùng sản phẩm (khóa products -> product_units)',
    $cc01Pass ? 'PASS' : 'FAIL',
    sprintf('before=%d, after_cache=%d, after_actual=%d', $cc01Before['actual'], $cc01After['cached'], $cc01After['actual'])
);

// ----------------------------------------------------------------------------
// CC-02: Admin sửa serial (chuyển repairing) vs Checkout reserve song song
// - Cô lập P3 chỉ còn đúng 1 máy sellable (status=available + 18 bước passed)
// ----------------------------------------------------------------------------
$cc02UnitId = insertTestSerial($pdoA, $prod3, 'SN-CC02-RACE', 'repairing', 'Grade A');
(new InspectionModel($pdoA))->createInspection($cc02UnitId, 1, 'passed', 'CC-02 setup', buildPassing18Items(), true);
$pdoA->prepare("UPDATE product_units SET status = 'repairing' WHERE product_id = :pid AND id <> :uid")->execute([':pid' => $prod3, ':uid' => $cc02UnitId]);
(new ProductUnitModel($pdoA))->reconcileAllSerialStockCache(true);
$cc02UnitVer = (int) ((new ProductUnitModel($pdoA))->findById($cc02UnitId)['row_version']);

$cc02Workers = runParallelWorkers([
    [
        'task'    => 'update-unit',
        'payload' => [
            'unit_id' => $cc02UnitId,
            'data'    => [
                'product_id'           => $prod3,
                'serial_number'        => 'SN-CC02-RACE',
                'status'               => 'repairing',
                'cosmetic_grade'       => 'Grade A',
                'battery_health'       => null,
                'import_price'         => 10000000,
                'expected_row_version' => $cc02UnitVer,
            ],
        ],
    ],
    [
        'task'    => 'checkout',
        'payload' => [
            'product_id'      => $prod3,
            'idempotency_key' => 'cc02-checkout-' . bin2hex(random_bytes(8)),
        ],
    ],
]);
$cc02After  = assertProductStockMatchesSellable($pdoA, $prod3);
$cc02SysErr = ($cc02Workers[0]['type'] === 'system' || $cc02Workers[1]['type'] === 'system');
$cc02OneWon = ($cc02Workers[0]['ok'] xor $cc02Workers[1]['ok']);
recordCcTest(
    'CC-02',
    'Admin sửa serial (repairing) vs Checkout giữ máy song song (chỉ 1 bên thắng, không lỗi hệ thống)',
    (!$cc02SysErr && $cc02OneWon && $cc02After['match'] && $cc02After['actual'] === 0) ? 'PASS' : 'FAIL',
    sprintf(
        'admin=%s (%s), checkout=%s (%s), after_cache=%d, after_actual=%d',
        $cc02Workers[0]['ok'] ? 'ok' : $cc02Workers[0]['type'],
        $cc02Workers[0]['message'] ?? 'ok',
        $cc02Workers[1]['ok'] ? 'ok' : $cc02Workers[1]['type'],
        $cc02Workers[1]['message'] ?? 'ok',
        $cc02After['cached'],
        $cc02After['actual']
    )
);

// ----------------------------------------------------------------------------
// CC-03: 2 Admin cùng sửa 1 serial đồng thời qua 2 kết nối PDO độc lập ($pdoA, $pdoB)
// ----------------------------------------------------------------------------
$cc03UnitId = insertTestSerial($pdoA, $prod2, 'SN-CC03-OPTIMISTIC', 'repairing', 'Grade B');
$unitModelA = new ProductUnitModel($pdoA);
$unitModelB = new ProductUnitModel($pdoB);

$cc03Read = $unitModelA->findById($cc03UnitId);
$cc03Ver  = (int) $cc03Read['row_version'];

$unitModelA->updateUnit($cc03UnitId, [
    'product_id'           => $prod2,
    'serial_number'        => 'SN-CC03-OPTIMISTIC',
    'status'               => 'repairing',
    'cosmetic_grade'       => 'Grade A',
    'battery_health'       => null,
    'import_price'         => 10000000,
    'expected_row_version' => $cc03Ver,
]);

$cc03Blocked = false;
$cc03ErrMsg  = '';
try {
    $unitModelB->updateUnit($cc03UnitId, [
        'product_id'           => $prod2,
        'serial_number'        => 'SN-CC03-OPTIMISTIC',
        'status'               => 'repairing',
        'cosmetic_grade'       => 'Grade C',
        'battery_health'       => null,
        'import_price'         => 10000000,
        'expected_row_version' => $cc03Ver, // Stale version
    ]);
} catch (DomainException $e) {
    $cc03Blocked = true;
    $cc03ErrMsg  = $e->getMessage();
}
$cc03Final = $unitModelA->findById($cc03UnitId);
$cc03Pass  = $cc03Blocked
    && $cc03Final['cosmetic_grade'] === 'Grade A'
    && $cc03Final['status'] === 'repairing'
    && (int) $cc03Final['row_version'] === ($cc03Ver + 1);
recordCcTest(
    'CC-03',
    '2 Admin cùng sửa 1 serial qua 2 PDO độc lập (row_version chống ghi đè)',
    $cc03Pass ? 'PASS' : 'FAIL',
    "blocked=" . ($cc03Blocked ? '1' : '0') . ", grade={$cc03Final['cosmetic_grade']}, row_version={$cc03Final['row_version']}, msg={$cc03ErrMsg}"
);

// ----------------------------------------------------------------------------
// CC-04: 2 Admin cùng tạo kiểm định cho CÙNG 1 máy đồng thời (proc_open)
// ----------------------------------------------------------------------------
$cc04UnitId  = insertTestSerial($pdoA, $prod2, 'SN-CC04-DUAL-INSP', 'repairing', 'Grade A');
$cc04Before  = assertProductStockMatchesSellable($pdoA, $prod2);
$cc04Workers = runParallelWorkers([
    [
        'task'    => 'inspect',
        'payload' => ['unit_id' => $cc04UnitId, 'inspector_id' => 1, 'overall_result' => 'passed', 'mark_available' => true],
    ],
    [
        'task'    => 'inspect',
        'payload' => ['unit_id' => $cc04UnitId, 'inspector_id' => 1, 'overall_result' => 'passed', 'mark_available' => true],
    ],
]);
$cc04After   = assertProductStockMatchesSellable($pdoA, $prod2);
$cc04UnitRow = (new ProductUnitModel($pdoA))->findById($cc04UnitId);
$cc04Pass    = $cc04Workers[0]['ok'] && $cc04Workers[1]['ok']
    && $cc04After['match']
    && $cc04After['actual'] === ($cc04Before['actual'] + 1)
    && $cc04UnitRow['status'] === 'available';
recordCcTest(
    'CC-04',
    '2 Admin cùng kiểm định 1 máy đồng thời (không nhân đôi stock_quantity)',
    $cc04Pass ? 'PASS' : 'FAIL',
    sprintf('before=%d, after_cache=%d, after_actual=%d, unit_status=%s', $cc04Before['actual'], $cc04After['cached'], $cc04After['actual'], $cc04UnitRow['status'])
);

// ----------------------------------------------------------------------------
// CC-05: Thêm serial ở sản phẩm A trong khi đang kiểm định sản phẩm B (proc_open)
// ----------------------------------------------------------------------------
$cc05UnitP4   = insertTestSerial($pdoA, $prod4, 'SN-CC05-P4', 'repairing', 'Grade A');
$cc05P4Before = assertProductStockMatchesSellable($pdoA, $prod4);
$cc05Workers  = runParallelWorkers([
    [
        'task'    => 'create-unit',
        'payload' => [
            'data' => [
                'product_id'     => $prod3,
                'serial_number'  => 'SN-CC05-NEW-P3',
                'status'         => 'repairing',
                'cosmetic_grade' => 'Grade A',
                'battery_health' => null,
                'import_price'   => 9500000,
            ],
        ],
    ],
    [
        'task'    => 'inspect',
        'payload' => [
            'unit_id'        => $cc05UnitP4,
            'inspector_id'   => 1,
            'overall_result' => 'passed',
            'mark_available' => true,
        ],
    ],
]);
$cc05P3After = assertProductStockMatchesSellable($pdoA, $prod3);
$cc05P4After = assertProductStockMatchesSellable($pdoA, $prod4);
$cc05Pass    = $cc05Workers[0]['ok'] && $cc05Workers[1]['ok']
    && $cc05P3After['match']
    && $cc05P4After['match']
    && $cc05P4After['actual'] === ($cc05P4Before['actual'] + 1);
recordCcTest(
    'CC-05',
    'Thêm serial cho sản phẩm A đồng thời kiểm định sản phẩm B',
    $cc05Pass ? 'PASS' : 'FAIL',
    sprintf('P3(cache=%d,actual=%d), P4(before=%d,after_cache=%d,after_actual=%d)', $cc05P3After['cached'], $cc05P3After['actual'], $cc05P4Before['actual'], $cc05P4After['cached'], $cc05P4After['actual'])
);

// ----------------------------------------------------------------------------
// CC-06: 2 transaction cập nhật tồn kho cache của CÙNG 1 sản phẩm đồng thời
// ----------------------------------------------------------------------------
$cc06U1      = insertTestSerial($pdoA, $prod3, 'SN-CC06-U1', 'repairing', 'Grade A');
$cc06U2      = insertTestSerial($pdoA, $prod3, 'SN-CC06-U2', 'repairing', 'Grade B');
$cc06Before  = assertProductStockMatchesSellable($pdoA, $prod3);
$cc06Workers = runParallelWorkers([
    [
        'task'    => 'inspect',
        'payload' => ['unit_id' => $cc06U1, 'inspector_id' => 1, 'overall_result' => 'passed', 'mark_available' => true],
    ],
    [
        'task'    => 'inspect',
        'payload' => ['unit_id' => $cc06U2, 'inspector_id' => 1, 'overall_result' => 'passed', 'mark_available' => true],
    ],
]);
$cc06After = assertProductStockMatchesSellable($pdoA, $prod3);
$cc06Pass  = $cc06Workers[0]['ok'] && $cc06Workers[1]['ok']
    && $cc06After['match']
    && $cc06After['actual'] === ($cc06Before['actual'] + 2);
recordCcTest(
    'CC-06',
    '2 transaction cập nhật tồn kho cache cùng sản phẩm đồng thời (không lost update)',
    $cc06Pass ? 'PASS' : 'FAIL',
    sprintf('before=%d, after_cache=%d, after_actual=%d', $cc06Before['actual'], $cc06After['cached'], $cc06After['actual'])
);

// ----------------------------------------------------------------------------
// CC-07: 2 luồng chuyển product_id ngược hướng nhau đồng thời (prod1 -> prod2 và prod2 -> prod1)
// - Chuẩn bị 2 máy CHƯA có lịch sử kiểm định & đơn hàng (hợp lệ để chuyển product_id)
// ----------------------------------------------------------------------------
$cc07Ua   = insertTestSerial($pdoA, $prod1, 'SN-CC07-A-TO-B', 'repairing', 'Grade A');
$cc07Ub   = insertTestSerial($pdoA, $prod2, 'SN-CC07-B-TO-A', 'repairing', 'Grade B');
$cc07RowA = (new ProductUnitModel($pdoA))->findById($cc07Ua);
$cc07RowB = (new ProductUnitModel($pdoA))->findById($cc07Ub);

$cc07Workers = runParallelWorkers([
    [
        'task'    => 'update-unit',
        'payload' => [
            'unit_id' => $cc07Ua,
            'data'    => [
                'product_id'           => $prod2,
                'serial_number'        => 'SN-CC07-A-TO-B',
                'status'               => 'repairing',
                'cosmetic_grade'       => 'Grade A',
                'battery_health'       => null,
                'import_price'         => 10000000,
                'expected_row_version' => (int) $cc07RowA['row_version'],
            ],
        ],
    ],
    [
        'task'    => 'update-unit',
        'payload' => [
            'unit_id' => $cc07Ub,
            'data'    => [
                'product_id'           => $prod1,
                'serial_number'        => 'SN-CC07-B-TO-A',
                'status'               => 'repairing',
                'cosmetic_grade'       => 'Grade B',
                'battery_health'       => null,
                'import_price'         => 10000000,
                'expected_row_version' => (int) $cc07RowB['row_version'],
            ],
        ],
    ],
]);
$cc07AfterA  = (new ProductUnitModel($pdoA))->findById($cc07Ua);
$cc07AfterB  = (new ProductUnitModel($pdoA))->findById($cc07Ub);
$cc07P1After = assertProductStockMatchesSellable($pdoA, $prod1);
$cc07P2After = assertProductStockMatchesSellable($pdoA, $prod2);
$cc07Pass    = $cc07Workers[0]['ok']
    && $cc07Workers[1]['ok']
    && (int) $cc07AfterA['product_id'] === $prod2
    && (int) $cc07AfterB['product_id'] === $prod1
    && $cc07P1After['match']
    && $cc07P2After['match'];
recordCcTest(
    'CC-07',
    '2 luồng chuyển máy chưa kiểm định ngược hướng đồng thời (khóa products.id ASC chống deadlock)',
    $cc07Pass ? 'PASS' : 'FAIL',
    sprintf(
        'w0=%s, w1=%s, Ua_prod=%d, Ub_prod=%d, P1(cache=%d,actual=%d), P2(cache=%d,actual=%d)',
        $cc07Workers[0]['ok'] ? 'ok' : ($cc07Workers[0]['message'] ?? 'fail'),
        $cc07Workers[1]['ok'] ? 'ok' : ($cc07Workers[1]['message'] ?? 'fail'),
        (int) $cc07AfterA['product_id'],
        (int) $cc07AfterB['product_id'],
        $cc07P1After['cached'],
        $cc07P1After['actual'],
        $cc07P2After['cached'],
        $cc07P2After['actual']
    )
);

// ----------------------------------------------------------------------------
// CC-08: Kiểm thử retry bằng ngoại lệ PDOException 40001/1213 MÔ PHỎNG
// (Ghi chú rõ: đây là kiểm thử cơ chế rollback + retry bằng lỗi mô phỏng, KHÔNG phải deadlock InnoDB thật)
// ----------------------------------------------------------------------------
$cc08Model = new class($pdoA) extends Model {
    public int $attempts = 0;
    public function runSimulatedDeadlockRetry(int $productId): bool
    {
        return $this->runTransactionWithDeadlockRetry(function () use ($productId): bool {
            $this->attempts++;
            $stmt = $this->db->prepare("
                INSERT INTO product_units (product_id, serial_number, status, cosmetic_grade, import_price, row_version, imported_at)
                VALUES (:pid, :sn, 'repairing', 'Grade A', 1000000, 1, NOW())
            ");
            $stmt->execute([
                ':pid' => $productId,
                ':sn'  => 'SN-CC08-RETRY-' . $this->attempts,
            ]);

            if ($this->attempts === 1) {
                $ex = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 40001);
                $ex->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
                throw $ex;
            }
            return true;
        }, 3);
    }
};
$cc08Ok   = $cc08Model->runSimulatedDeadlockRetry($prod1);
$cc08Rows = $pdoA->query("SELECT serial_number FROM product_units WHERE serial_number LIKE 'SN-CC08-RETRY-%'")->fetchAll(PDO::FETCH_COLUMN);
$cc08Pass = $cc08Ok && $cc08Model->attempts === 2 && $cc08Rows === ['SN-CC08-RETRY-2'];
recordCcTest(
    'CC-08',
    'Retry bằng PDOException 40001/1213 mô phỏng: rollback lần 1, retry lần 2 thành công không trùng',
    $cc08Pass ? 'PASS' : 'FAIL',
    'attempts=' . $cc08Model->attempts . ', committed_rows=' . json_encode($cc08Rows) . ' (simulated exception, not real InnoDB deadlock)'
);

// ----------------------------------------------------------------------------
// CC-09: 5 tiến trình Checkout đua mua CÙNG 1 máy sellable duy nhất (proc_open)
// ----------------------------------------------------------------------------
$pdoA->prepare("UPDATE product_units SET status = 'repairing' WHERE product_id = :pid")->execute([':pid' => $prod4]);
$cc09Unit = insertTestSerial($pdoA, $prod4, 'SN-CC09-LAST-ONE', 'repairing', 'Grade A');
(new InspectionModel($pdoA))->createInspection($cc09Unit, 1, 'passed', 'CC-09 setup', buildPassing18Items(), true);
(new ProductUnitModel($pdoA))->reconcileAllSerialStockCache(true);

$cc09Jobs = [];
for ($i = 1; $i <= 5; $i++) {
    $cc09Jobs[] = [
        'task'    => 'checkout',
        'payload' => [
            'product_id'      => $prod4,
            'customer_name'   => "Khách Race {$i}",
            'idempotency_key' => "cc09-race-{$i}-" . bin2hex(random_bytes(8)),
        ],
    ];
}
$cc09Workers = runParallelWorkers($cc09Jobs);
$cc09Success = 0;
$cc09BizFail = 0;
$cc09SysFail = 0;
foreach ($cc09Workers as $w) {
    if (!empty($w['ok'])) {
        $cc09Success++;
    } elseif (($w['type'] ?? '') === 'business') {
        $cc09BizFail++;
    } else {
        $cc09SysFail++;
    }
}
$cc09SoldCount = (int) $pdoA->query("SELECT COUNT(*) FROM order_items WHERE product_unit_id = {$cc09Unit}")->fetchColumn();
$cc09After     = assertProductStockMatchesSellable($pdoA, $prod4);
$cc09Pass      = ($cc09Success === 1 && $cc09BizFail === 4 && $cc09SysFail === 0 && $cc09SoldCount === 1 && $cc09After['match'] && $cc09After['actual'] === 0);
recordCcTest(
    'CC-09',
    '5 tiến trình Checkout đua mua cùng 1 máy cuối cùng (chỉ 1 đơn thành công, kiểm tra order_items.product_unit_id)',
    $cc09Pass ? 'PASS' : 'FAIL',
    "success={$cc09Success}, business_reject={$cc09BizFail}, sys_err={$cc09SysFail}, order_items_for_unit={$cc09SoldCount}, cache={$cc09After['cached']}"
);

// ----------------------------------------------------------------------------
// CC-10: Script sync_serial_stock_cache.php (--dry-run vs --apply) không chạm has_serials = 0
// ----------------------------------------------------------------------------
$nonSerialRow = $pdoA->query('SELECT id, stock_quantity FROM products WHERE has_serials = 0 ORDER BY id ASC LIMIT 1')->fetch();
$nonSerialId  = (int) $nonSerialRow['id'];
$nonSerialQty = (int) $nonSerialRow['stock_quantity'];
$pdoA->prepare('UPDATE products SET stock_quantity = 999 WHERE id = :pid')->execute([':pid' => $prod1]);
$pdoA->prepare('UPDATE products SET stock_quantity = 777 WHERE id = :pid')->execute([':pid' => $nonSerialId]);

$unitModelSync = new ProductUnitModel($pdoA);
$unitModelSync->reconcileAllSerialStockCache(false);
$p1AfterDry    = (int) $pdoA->query("SELECT stock_quantity FROM products WHERE id = {$prod1}")->fetchColumn();
$unitModelSync->reconcileAllSerialStockCache(true);
$p1AfterApply  = assertProductStockMatchesSellable($pdoA, $prod1);
$nonAfterApply = (int) $pdoA->query("SELECT stock_quantity FROM products WHERE id = {$nonSerialId}")->fetchColumn();
$pdoA->prepare('UPDATE products SET stock_quantity = :qty WHERE id = :pid')->execute([':qty' => $nonSerialQty, ':pid' => $nonSerialId]);

$cc10Pass = ($p1AfterDry === 999) && $p1AfterApply['match'] && ($nonAfterApply === 777);
recordCcTest(
    'CC-10',
    'Đối soát tồn kho cache (--dry-run không ghi, --apply sửa đúng has_serials=1, không chạm has_serials=0)',
    $cc10Pass ? 'PASS' : 'FAIL',
    "after_dry={$p1AfterDry}, after_apply_cache={$p1AfterApply['cached']}, after_apply_actual={$p1AfterApply['actual']}, non_serial={$nonAfterApply}"
);

// ----------------------------------------------------------------------------
// CC-11: Bằng chứng thực tế 2 kết nối PDO ($pdoA, $pdoB) giữ khóa & chờ tuần tự hóa trên InnoDB
// - $pdoA khóa FOR UPDATE dòng products của $prod1, sau đó tiến trình con hoặc $pdoB thực hiện
//   tác vụ kiểm định trên $prod1 phải chờ $pdoA commit rồi mới hoàn tất sạch, không mất cập nhật.
// ----------------------------------------------------------------------------
$cc11Unit = insertTestSerial($pdoA, $prod1, 'SN-CC11-2CONN', 'repairing', 'Grade A');
$cc11Before = assertProductStockMatchesSellable($pdoA, $prod1);

$pdoA->beginTransaction();
$lockP1 = $pdoA->prepare('SELECT id FROM products WHERE id = :pid FOR UPDATE');
$lockP1->execute([':pid' => $prod1]);
$lockP1->fetch();

// Khởi chạy 1 tiến trình con kiểm định ngay khi $pdoA đang giữ khóa FOR UPDATE trên products($prod1)
$phpBin = PHP_BINARY ?: 'E:\\xampp\\php\\php.exe';
$proc11 = proc_open(
    sprintf('"%s" "%s" --worker=inspect', $phpBin, __FILE__),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes11,
    dirname(__DIR__)
);
fwrite($pipes11[0], json_encode([
    'unit_id'        => $cc11Unit,
    'inspector_id'   => 1,
    'overall_result' => 'passed',
    'mark_available' => true,
]));
fclose($pipes11[0]);

// Giữ khóa 300ms trên $pdoA để chắc chắn worker B chạm khóa InnoDB thật rồi mới commit $pdoA
usleep(300_000);
$pdoA->commit();

$out11  = stream_get_contents($pipes11[1]);
$err11  = stream_get_contents($pipes11[2]);
fclose($pipes11[1]);
fclose($pipes11[2]);
$code11 = proc_close($proc11);
$res11  = json_decode(trim((string) $out11), true);
$cc11After = assertProductStockMatchesSellable($pdoA, $prod1);
$cc11Pass  = ($code11 === 0 && !empty($res11['ok']) && $cc11After['match'] && $cc11After['actual'] === ($cc11Before['actual'] + 1));
recordCcTest(
    'CC-11',
    '2 kết nối InnoDB thật ($pdoA giữ khóa FOR UPDATE -> worker B chờ commit rồi hoàn tất)',
    $cc11Pass ? 'PASS' : 'FAIL',
    sprintf('worker_ok=%s, before=%d, after_cache=%d, after_actual=%d', !empty($res11['ok']) ? '1' : '0', $cc11Before['actual'], $cc11After['cached'], $cc11After['actual'])
);

// Tổng kết
$failed = array_filter($results, static fn(array $r): bool => $r['status'] !== 'PASS');
echo "\n=== KẾT QUẢ SUITE CONCURRENCY: " . (count($results) - count($failed)) . '/' . count($results) . " PASS ===\n";
exit(count($failed) === 0 ? 0 : 1);
