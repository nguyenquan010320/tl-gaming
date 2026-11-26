<?php
/**
 * HỆ THỐNG KIỂM THỬ TỰ ĐỘNG VÀ ĐỐI SOÁT NGHIỆP VỤ CƠ SỞ DỮ LIỆU TOÀN DIỆN — TL GAMING
 * Công nghệ: PHP thuần, PDO, MySQL/InnoDB (Chuẩn bị trước khi triển khai MVC)
 *
 * Mục tiêu kiểm thử và hoàn thiện nghiệp vụ:
 * 1. Kiểm tra Voucher toàn diện:
 *    - Kiểm tra is_active = 1, thời điểm hiện tại nằm trong khoảng [start_date, end_date].
 *    - Kiểm tra min_order_amount, usage_limit và usage_count.
 *    - Thực thi per_user_limit cho khách đăng nhập (user_id) và khách vãng lai (định danh qua SĐT/email chuẩn hóa, ghi rõ hạn chế thực tế).
 *    - Không cho discount_amount > total_product_amount.
 *    - Giữ Transaction và SELECT ... FOR UPDATE chống tranh chấp đồng thời.
 * 2. Kiểm tra Tiền tệ & Chống giả mạo từ trình duyệt:
 *    - Kiểm tra quantity > 0, shipping_fee >= 0, final_amount >= 0.
 *    - Tính toàn bộ giá bán, thành tiền, giảm giá và tổng thanh toán trên Server từ MySQL (bỏ qua hoàn toàn giá/giảm giá/tổng tiền do Client gửi lên).
 *    - Chống tràn kiểu dữ liệu số nguyên (Integer Overflow / BIGINT UNSIGNED Underflow).
 * 3. Bảo mật Thanh toán & Phân biệt Hủy đơn / Hoàn tiền:
 *    - Không cho khách hàng truyền payment_status để tự xác nhận đã trả tiền; khi tạo đơn, payments.status luôn mặc định là 'pending'.
 *    - Chỉ quy trình Admin có phân quyền (role = 'admin', status = 'active') mới được chuyển payments.status sang 'completed'.
 *    - Giữ nguyên lịch sử giao dịch khi hủy đơn đã thanh toán (payments.status giữ 'completed'); tách biệt quy trình Hoàn tiền ('refunded').
 * 4. Quy trình Hủy đơn Nguyên tử & Idempotent:
 *    - Hỗ trợ cả has_serials = 1 và has_serials = 0, chặn hoàn kho 2 lần.
 *    - Nếu sản phẩm có serial mà không thể chuyển từ 'reserved' về 'available' -> ROLLBACK toàn bộ transaction và báo lỗi rõ ràng.
 *    - Nếu hoàn lượt voucher không thành công -> ROLLBACK toàn bộ transaction.
 *    - Không làm mất lịch sử order_items và payments.
 * 5. An toàn tuyệt đối cho CSDL chính (tl_gaming):
 *    - Toàn bộ quá trình kiểm thử chỉ thực thi trên CSDL độc lập `tl_gaming_audit_test`.
 *    - Tự động xuất và đồng bộ `audit_results.json` với bộ kiểm thử hiện tại.
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Ho_Chi_Minh');

/** Hạn mức tiền tệ tối đa an toàn (1.000 tỷ VND) để chống tràn số học trên cả PHP 64-bit và MySQL BIGINT UNSIGNED */
const MAX_SAFE_MONEY_VND = 1000000000000;
/** Số lượng tối đa cho phép trên một dòng đặt hàng để chống tràn phép nhân đơn giá * số lượng */
const MAX_ITEM_QUANTITY  = 10000;

$dbHost     = '127.0.0.1';
$dbPort     = '3306';
$dbName     = 'tl_gaming_audit_test';
$prodDbName = 'tl_gaming';
$dbUser     = 'root';
$dbPass     = '';

/**
 * Hàm lấy chuỗi thời gian chính xác đến mili-giây phục vụ ghi log kiểm thử đồng thời
 */
function nowMs(): string
{
    $mt = microtime(true);
    $ms = sprintf('%03d', (int) round(($mt - floor($mt)) * 1000));
    return date('H:i:s', (int) floor($mt)) . '.' . $ms;
}

/**
 * Hàm khởi tạo kết nối PDO độc lập tới cơ sở dữ liệu kiểm thử
 */
function createConnection(string $host, string $port, string $db, string $user, string $pass): PDO
{
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/**
 * Hàm chuẩn hóa số điện thoại (chỉ giữ chữ số) để đối soát giới hạn voucher khách vãng lai
 */
function normalizePhone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

/**
 * Hàm chuẩn hóa địa chỉ email (chữ thường, cắt khoảng trắng) để đối soát giới hạn voucher
 */
function normalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

// ============================================================================
// CHẾ ĐỘ WORKER TIẾN TRÌNH CON (PHỤC VỤ KIỂM THỬ 2 TIẾN TRÌNH CHẠY SONG SONG)
// ============================================================================
if (isset($argv[1]) && $argv[1] === '--worker-unit') {
    // Tham số: --worker-unit <worker_name> <unit_id> <hold_ms> <start_delay_ms>
    $workerName   = $argv[2] ?? 'W';
    $unitId       = (int) ($argv[3] ?? 5);
    $holdMs       = (int) ($argv[4] ?? 0);
    $startDelayMs = (int) ($argv[5] ?? 0);

    if ($startDelayMs > 0) {
        // Tiến trình B chờ một khoảng ngắn để đảm bảo Tiến trình A đã vào giao dịch và giữ khóa trước
        usleep($startDelayMs * 1000);
    }

    $pdo = createConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
    $connId = (int) $pdo->query("SELECT CONNECTION_ID()")->fetchColumn();
    $logs = [];

    try {
        $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Bắt đầu Transaction (BEGIN)";
        $pdo->beginTransaction();

        $tBeforeLock = microtime(true);
        $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Gửi lệnh SELECT ... FROM product_units WHERE id = {$unitId} FOR UPDATE";

        // Thực hiện khóa dòng độc quyền trên bảng product_units
        $stmtLock = $pdo->prepare("SELECT id, product_id, serial_number, status FROM product_units WHERE id = :uid FOR UPDATE");
        $stmtLock->execute([':uid' => $unitId]);
        $unit = $stmtLock->fetch();

        $waitMs = (int) round((microtime(true) - $tBeforeLock) * 1000);
        $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Đã giành được khóa dòng (Thời gian chờ khóa: {$waitMs} ms) | Trạng thái máy đọc được: " . ($unit['status'] ?? 'NULL');

        if ($holdMs > 0) {
            // Tiến trình A chủ động giữ khóa mở trong $holdMs mili-giây để Tiến trình B đâm vào khóa đang mở
            $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Đang giữ khóa mở trong {$holdMs} ms (chưa COMMIT)...";
            usleep($holdMs * 1000);
        }

        // Cập nhật có điều kiện status = 'available' để đảm bảo chỉ 1 giao dịch đổi trạng thái thành công
        $stmtUpd = $pdo->prepare("UPDATE product_units SET status = 'reserved' WHERE id = :uid AND status = 'available'");
        $stmtUpd->execute([':uid' => $unitId]);
        $affectedRows = $stmtUpd->rowCount();

        if ($affectedRows === 1) {
            // Lấy giá sản phẩm thực tế từ MySQL
            $stmtProd = $pdo->prepare("SELECT name, sku, price, warranty_months FROM products WHERE id = :pid FOR UPDATE");
            $stmtProd->execute([':pid' => $unit['product_id']]);
            $prod = $stmtProd->fetch();
            $price = (int) $prod['price'];

            // Giảm tồn kho có điều kiện chống âm tồn kho
            $stmtStock = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - 1 WHERE id = :pid AND stock_quantity >= 1");
            $stmtStock->execute([':pid' => $unit['product_id']]);
            if ($stmtStock->rowCount() !== 1) {
                throw new RuntimeException("Không thể trừ tồn kho cho sản phẩm ID={$unit['product_id']}.");
            }

            // Tạo đơn hàng và chi tiết đơn hàng cho tiến trình thắng cuộc
            $orderCode = "TLG-MP-{$workerName}-" . random_int(1000, 9999);
            $pdo->prepare("
                INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, total_product_amount, shipping_fee, discount_amount, final_amount, status)
                VALUES (:code, 2, :name, 'khach.songsong@demo.vn', '0911222333', 'Số 1 Đại Cồ Việt', 'Hà Nội', :total, 0, 0, :final, 'pending')
            ")->execute([
                ':code'  => $orderCode,
                ':name'  => "Khách Song Song {$workerName}",
                ':total' => $price,
                ':final' => $price,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, product_unit_id, product_name, product_sku, unit_serial_number, price, quantity, subtotal, warranty_period)
                VALUES (:oid, :pid, :uid, :pname, :psku, :sn, :price, 1, :sub, :war)
            ")->execute([
                ':oid'   => $orderId,
                ':pid'   => $unit['product_id'],
                ':uid'   => $unitId,
                ':pname' => $prod['name'],
                ':psku'  => $prod['sku'],
                ':sn'    => $unit['serial_number'],
                ':price' => $price,
                ':sub'   => $price,
                ':war'   => $prod['warranty_months'] . ' tháng',
            ]);

            // Luôn tạo bản ghi thanh toán mặc định là pending
            $pdo->prepare("
                INSERT INTO payments (order_id, payment_method, amount, status)
                VALUES (:oid, 'cod', :amt, 'pending')
            ")->execute([':oid' => $orderId, ':amt' => $price]);

            $pdo->commit();
            $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] UPDATE product_units thành công (rowCount = {$affectedRows}) -> COMMIT đơn {$orderCode} (ID={$orderId})";
            echo json_encode([
                'worker'        => $workerName,
                'conn_id'       => $connId,
                'success'       => true,
                'affected_rows' => $affectedRows,
                'wait_ms'       => $waitMs,
                'order_id'      => $orderId,
                'logs'          => $logs,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $pdo->rollBack();
            $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] UPDATE product_units thất bại (rowCount = {$affectedRows}, máy đã bị giữ chỗ bởi giao dịch khác) -> ROLLBACK";
            echo json_encode([
                'worker'        => $workerName,
                'conn_id'       => $connId,
                'success'       => false,
                'affected_rows' => $affectedRows,
                'wait_ms'       => $waitMs,
                'order_id'      => null,
                'logs'          => $logs,
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Lỗi ngoại lệ: " . $e->getMessage() . " -> ROLLBACK";
        echo json_encode([
            'worker'        => $workerName,
            'conn_id'       => $connId,
            'success'       => false,
            'affected_rows' => 0,
            'wait_ms'       => 0,
            'error'         => $e->getMessage(),
            'logs'          => $logs,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit(0);
}

if (isset($argv[1]) && $argv[1] === '--worker-voucher') {
    // Tham số: --worker-voucher <worker_name> <coupon_id> <hold_ms> <start_delay_ms>
    $workerName   = $argv[2] ?? 'V';
    $couponId     = (int) ($argv[3] ?? 1);
    $holdMs       = (int) ($argv[4] ?? 0);
    $startDelayMs = (int) ($argv[5] ?? 0);

    if ($startDelayMs > 0) {
        usleep($startDelayMs * 1000);
    }

    $pdo = createConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
    $connId = (int) $pdo->query("SELECT CONNECTION_ID()")->fetchColumn();
    $logs = [];

    try {
        $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Bắt đầu Transaction (BEGIN)";
        $pdo->beginTransaction();

        $tBeforeLock = microtime(true);
        $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Gửi lệnh SELECT ... FROM coupons WHERE id = {$couponId} FOR UPDATE";

        // Khóa dòng voucher cần áp dụng
        $stmtLock = $pdo->prepare("SELECT id, code, usage_limit, usage_count, is_active FROM coupons WHERE id = :cid FOR UPDATE");
        $stmtLock->execute([':cid' => $couponId]);
        $coupon = $stmtLock->fetch();

        $waitMs = (int) round((microtime(true) - $tBeforeLock) * 1000);
        $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Đã giành khóa coupon (Chờ khóa: {$waitMs} ms) | usage_count={$coupon['usage_count']}/{$coupon['usage_limit']}";

        if ($holdMs > 0) {
            $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Giữ khóa coupon mở trong {$holdMs} ms (chưa COMMIT)...";
            usleep($holdMs * 1000);
        }

        // Cập nhật nguyên tử có điều kiện kiểm tra is_active, hạn dùng và usage_count < usage_limit
        $stmtUpd = $pdo->prepare("
            UPDATE coupons
            SET usage_count = usage_count + 1
            WHERE id = :cid
              AND is_active = 1
              AND NOW() BETWEEN start_date AND end_date
              AND (usage_limit = 0 OR usage_count < usage_limit)
        ");
        $stmtUpd->execute([':cid' => $couponId]);
        $affectedRows = $stmtUpd->rowCount();

        if ($affectedRows === 1) {
            $orderCode = "TLG-VOU-{$workerName}-" . random_int(1000, 9999);
            $email = strtolower($workerName) . "@example.com";
            $phone = $workerName === 'A' ? '0977111222' : '0977333444';

            $pdo->prepare("
                INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, total_product_amount, shipping_fee, discount_amount, final_amount, coupon_id, status)
                VALUES (:code, NULL, :name, :email, :phone, 'Số 10 Nguyễn Trãi', 'Hà Nội', 15000000, 0, 500000, 14500000, :cid, 'pending')
            ")->execute([
                ':code'  => $orderCode,
                ':name'  => "Khách Săn Voucher {$workerName}",
                ':email' => $email,
                ':phone' => $phone,
                ':cid'   => $couponId,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO coupon_usages (coupon_id, order_id, user_id, customer_email, customer_phone, discount_applied, status)
                VALUES (:cid, :oid, NULL, :email, :phone, 500000, 'used')
            ")->execute([
                ':cid'   => $couponId,
                ':oid'   => $orderId,
                ':email' => $email,
                ':phone' => $phone,
            ]);

            $pdo->commit();
            $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Áp dụng voucher thành công (rowCount = 1) -> COMMIT đơn {$orderCode}";
            echo json_encode([
                'worker'        => $workerName,
                'conn_id'       => $connId,
                'success'       => true,
                'affected_rows' => $affectedRows,
                'wait_ms'       => $waitMs,
                'order_id'      => $orderId,
                'logs'          => $logs,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $pdo->rollBack();
            $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Hết lượt voucher (rowCount = 0, usage_count đã đạt giới hạn) -> ROLLBACK";
            echo json_encode([
                'worker'        => $workerName,
                'conn_id'       => $connId,
                'success'       => false,
                'affected_rows' => $affectedRows,
                'wait_ms'       => $waitMs,
                'order_id'      => null,
                'logs'          => $logs,
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $logs[] = "[" . nowMs() . "] [{$workerName} | Conn #{$connId}] Lỗi ngoại lệ: " . $e->getMessage() . " -> ROLLBACK";
        echo json_encode([
            'worker'        => $workerName,
            'conn_id'       => $connId,
            'success'       => false,
            'affected_rows' => 0,
            'wait_ms'       => 0,
            'error'         => $e->getMessage(),
            'logs'          => $logs,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit(0);
}

// ============================================================================
// HÀM KHỞI TẠO LẠI MÔI TRƯỜNG KIỂM THỬ ĐỘC LẬP (tl_gaming_audit_test)
// TUYỆT ĐỐI KHÔNG CHẠM VÀO CSDL CHÍNH (tl_gaming)
// ============================================================================
function resetTestDatabase(string $host, string $port, string $testDb, string $user, string $pass): void
{
    $pdoRoot = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdoRoot->exec("DROP DATABASE IF EXISTS `{$testDb}`");

    $schemaSql = file_get_contents(__DIR__ . '/database/tl_gaming.sql');
    $seedSql   = file_get_contents(__DIR__ . '/database/seed_tl_gaming.sql');

    // Thay thế tên CSDL sang tl_gaming_audit_test để nạp an toàn vào môi trường test
    $schemaTest = str_replace('`tl_gaming`', "`{$testDb}`", $schemaSql);
    $seedTest   = str_replace('`tl_gaming`', "`{$testDb}`", $seedSql);

    $tmpSchema = sys_get_temp_dir() . '/tl_gaming_test_schema.sql';
    $tmpSeed   = sys_get_temp_dir() . '/tl_gaming_test_seed.sql';
    file_put_contents($tmpSchema, $schemaTest);
    file_put_contents($tmpSeed, $seedTest);

    $mysqlBin  = 'E:\\xampp\\mysql\\bin\\mysql.exe';
    $cmdSchema = sprintf('cmd /c ""%s" -u %s --default-character-set=utf8mb4 < "%s""', $mysqlBin, $user, $tmpSchema);
    $cmdSeed   = sprintf('cmd /c ""%s" -u %s --default-character-set=utf8mb4 < "%s""', $mysqlBin, $user, $tmpSeed);

    exec($cmdSchema, $out1, $ret1);
    exec($cmdSeed, $out2, $ret2);

    @unlink($tmpSchema);
    @unlink($tmpSeed);

    if ($ret1 !== 0 || $ret2 !== 0) {
        throw new RuntimeException("Không thể nạp lại cơ sở dữ liệu kiểm thử {$testDb}. Mã trả về: schema={$ret1}, seed={$ret2}");
    }
}

/**
 * Chụp trạng thái số dòng các bảng trong CSDL chính (tl_gaming) để chứng minh không bị tác động
 */
function snapshotDatabaseCounts(PDO $pdo, string $dbName): array
{
    $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    $stmt->execute([':db' => $dbName]);
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach ($tables as $t) {
        $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `{$dbName}`.`{$t}`")->fetchColumn();
    }
    return $counts;
}

// ============================================================================
// CÁC HÀM NGHIỆP VỤ CHUẨN (ĐẶT ĐƠN, DUYỆT THANH TOÁN, HOÀN TIỀN & HỦY ĐƠN)
// ============================================================================

/**
 * Quy trình Đặt hàng chuẩn (Server-side Price Calculation + Voucher Validation + Payment Security)
 *
 * Bảo đảm toàn bộ các quy tắc nghiệp vụ:
 * - Kiểm tra quantity > 0, shipping_fee >= 0, final_amount >= 0, chống tràn số nguyên.
 * - Tuyệt đối không tin cậy giá bán, thành tiền, giảm giá hay tổng tiền gửi từ trình duyệt;
 *   tính toàn bộ từ bảng products và coupons trong MySQL.
 * - Kiểm tra Voucher: is_active = 1, NOW() BETWEEN start_date AND end_date, min_order_amount,
 *   usage_limit / usage_count, per_user_limit cho cả khách đăng nhập và khách vãng lai,
 *   và không cho phép discount_amount > total_product_amount.
 * - Bảo mật thanh toán: Cưỡng bức payments.status = 'pending' khi tạo đơn, phớt lờ mọi giá trị
 *   payment_status do khách hàng tự truyền lên.
 */
function placeOrderTransaction(PDO $pdo, array $orderData, array $items, ?int $couponId = null): array
{
    $pdo->beginTransaction();
    try {
        if (empty($items)) {
            throw new RuntimeException("Đơn hàng phải có ít nhất 1 sản phẩm.");
        }

        // 1. Kiểm tra phí vận chuyển hợp lệ (shipping_fee >= 0 và không vượt ngưỡng tràn số)
        $rawShippingFee = $orderData['shipping_fee'] ?? 0;
        if (!is_int($rawShippingFee) && !ctype_digit((string) $rawShippingFee) && !is_numeric($rawShippingFee)) {
            throw new RuntimeException("Phí vận chuyển không đúng định dạng số.");
        }
        $shippingFee = (int) $rawShippingFee;
        if ($shippingFee < 0) {
            throw new RuntimeException("Phí vận chuyển (shipping_fee = {$shippingFee}) không hợp lệ: không được nhỏ hơn 0.");
        }
        if ($shippingFee > MAX_SAFE_MONEY_VND) {
            throw new RuntimeException("Phí vận chuyển vượt ngưỡng an toàn cho phép (chống tràn kiểu dữ liệu).");
        }

        // Chuẩn hóa thông tin định danh khách hàng để kiểm soát đơn và voucher
        $userId        = isset($orderData['user_id']) && $orderData['user_id'] !== null ? (int) $orderData['user_id'] : null;
        $customerEmail = normalizeEmail((string) ($orderData['customer_email'] ?? 'guest@demo.vn'));
        $customerPhone = normalizePhone((string) ($orderData['customer_phone'] ?? ''));
        if ($customerEmail === '' || $customerPhone === '') {
            throw new RuntimeException("Thông tin email và số điện thoại khách hàng không được để trống.");
        }

        $totalProductAmount = 0;
        $preparedItems      = [];

        // 2. Duyệt từng mặt hàng, khóa dòng sản phẩm/serial và tính tiền 100% từ dữ liệu MySQL trên Server
        foreach ($items as $reqItem) {
            $productId = (int) ($reqItem['product_id'] ?? 0);
            $quantity  = (int) ($reqItem['quantity'] ?? 0);
            $unitId    = isset($reqItem['product_unit_id']) && $reqItem['product_unit_id'] !== null ? (int) $reqItem['product_unit_id'] : null;

            // Kiểm tra quantity > 0 và giới hạn chống tràn phép nhân
            if ($quantity <= 0) {
                throw new RuntimeException("Số lượng sản phẩm đặt mua (quantity = {$quantity}) bắt buộc phải lớn hơn 0.");
            }
            if ($quantity > MAX_ITEM_QUANTITY) {
                throw new RuntimeException("Số lượng đặt mua trên 1 dòng ({$quantity}) vượt quá giới hạn an toàn (" . MAX_ITEM_QUANTITY . ").");
            }

            // Khóa dòng sản phẩm trong MySQL để lấy giá gốc chính xác (bỏ qua mọi trường price/subtotal từ client)
            $stmtProd = $pdo->prepare("
                SELECT id, name, sku, price, warranty_months, stock_quantity, has_serials, status
                FROM products
                WHERE id = :pid
                FOR UPDATE
            ");
            $stmtProd->execute([':pid' => $productId]);
            $product = $stmtProd->fetch();

            if (!$product || $product['status'] === 'discontinued') {
                throw new RuntimeException("Sản phẩm ID={$productId} không tồn tại hoặc đã ngừng kinh doanh.");
            }

            $serverUnitPrice = (int) $product['price'];
            if ($serverUnitPrice < 0 || $serverUnitPrice > intdiv(MAX_SAFE_MONEY_VND, $quantity)) {
                throw new RuntimeException("Đơn giá sản phẩm ID={$productId} vượt ngưỡng an toàn số học (chống tràn số).");
            }

            if ((int) $product['has_serials'] === 1) {
                // Mặt hàng quản lý theo Serial riêng (has_serials = 1)
                if ($unitId === null || $quantity !== 1) {
                    throw new RuntimeException("Sản phẩm có serial (ID={$productId}) bắt buộc truyền product_unit_id và quantity = 1.");
                }

                // Khóa dòng máy vật lý bằng SELECT ... FOR UPDATE (Cơ chế chính chống bán trùng đồng thời)
                $stmtUnit = $pdo->prepare("
                    SELECT id, product_id, serial_number, status
                    FROM product_units
                    WHERE id = :uid AND product_id = :pid
                    FOR UPDATE
                ");
                $stmtUnit->execute([':uid' => $unitId, ':pid' => $productId]);
                $unit = $stmtUnit->fetch();

                if (!$unit || $unit['status'] !== 'available') {
                    throw new RuntimeException("Máy vật lý ID={$unitId} không thuộc sản phẩm ID={$productId} hoặc không còn ở trạng thái available.");
                }

                // Cập nhật trạng thái máy có điều kiện status = 'available'
                $stmtReserve = $pdo->prepare("UPDATE product_units SET status = 'reserved' WHERE id = :uid AND status = 'available'");
                $stmtReserve->execute([':uid' => $unitId]);
                if ($stmtReserve->rowCount() !== 1) {
                    throw new RuntimeException("Không thể giữ chỗ máy ID={$unitId} (rowCount = 0).");
                }

                // Trừ tồn kho sản phẩm có điều kiện chống âm (stock_quantity >= 1)
                $stmtDecStock = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - 1 WHERE id = :pid AND stock_quantity >= 1");
                $stmtDecStock->execute([':pid' => $productId]);
                if ($stmtDecStock->rowCount() !== 1) {
                    throw new RuntimeException("Tồn kho sản phẩm ID={$productId} không đủ để trừ (chống âm tồn kho).");
                }

                $subtotal = $serverUnitPrice;
                $serialSnapshot = $unit['serial_number'];
            } else {
                // Mặt hàng quản lý theo số lượng (has_serials = 0)
                if ($unitId !== null) {
                    throw new RuntimeException("Sản phẩm không có serial (ID={$productId}) phải có product_unit_id = NULL.");
                }

                // Trừ tồn kho theo số lượng đặt có điều kiện biên stock_quantity >= :qty2 (Chống âm tồn kho)
                $stmtDecStock = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - :qty1 WHERE id = :pid AND stock_quantity >= :qty2");
                $stmtDecStock->execute([':qty1' => $quantity, ':pid' => $productId, ':qty2' => $quantity]);
                if ($stmtDecStock->rowCount() !== 1) {
                    throw new RuntimeException("Số lượng đặt ({$quantity}) vượt quá tồn kho hiện tại ({$product['stock_quantity']}) của sản phẩm ID={$productId}.");
                }

                $subtotal = $serverUnitPrice * $quantity;
                $serialSnapshot = null;
            }

            if ($totalProductAmount > MAX_SAFE_MONEY_VND - $subtotal) {
                throw new RuntimeException("Tổng tiền sản phẩm vượt ngưỡng an toàn (chống tràn số nguyên).");
            }
            $totalProductAmount += $subtotal;

            $preparedItems[] = [
                'product_id'         => $productId,
                'product_unit_id'    => $unitId,
                'product_name'       => $product['name'],
                'product_sku'        => $product['sku'],
                'unit_serial_number' => $serialSnapshot,
                'price'              => $serverUnitPrice,
                'quantity'           => $quantity,
                'subtotal'           => $subtotal,
                'warranty_period'    => $product['warranty_months'] . ' tháng tại TL Gaming',
            ];
        }

        // 3. Kiểm tra và tính toán Voucher giảm giá trên Server (với khóa dòng SELECT ... FOR UPDATE)
        $discountAmount = 0;
        if ($couponId !== null) {
            // Đọc thông tin voucher kèm kiểm tra thời gian thực tế trực tiếp bằng đồng hồ MySQL NOW()
            $stmtCoupon = $pdo->prepare("
                SELECT *,
                       (NOW() >= start_date) AS is_started,
                       (NOW() <= end_date)   AS is_not_expired
                FROM coupons
                WHERE id = :cid
                FOR UPDATE
            ");
            $stmtCoupon->execute([':cid' => $couponId]);
            $coupon = $stmtCoupon->fetch();

            // 3.1. Kiểm tra tồn tại và is_active = 1
            if (!$coupon || (int) $coupon['is_active'] !== 1) {
                throw new RuntimeException("Voucher ID={$couponId} không tồn tại hoặc đang bị vô hiệu hóa (is_active = 0).");
            }

            // 3.2. Kiểm tra thời điểm hiện tại nằm trong khoảng [start_date, end_date]
            if ((int) $coupon['is_started'] !== 1) {
                throw new RuntimeException("Voucher {$coupon['code']} chưa đến thời gian có hiệu lực (bắt đầu từ {$coupon['start_date']}).");
            }
            if ((int) $coupon['is_not_expired'] !== 1) {
                throw new RuntimeException("Voucher {$coupon['code']} đã hết hạn sử dụng (hết hạn lúc {$coupon['end_date']}).");
            }

            // 3.3. Kiểm tra giá trị đơn hàng tối thiểu (min_order_amount)
            if ($totalProductAmount < (int) $coupon['min_order_amount']) {
                throw new RuntimeException("Đơn hàng ({$totalProductAmount} VND) chưa đạt giá trị tối thiểu ({$coupon['min_order_amount']} VND) của voucher {$coupon['code']}.");
            }

            // 3.4. Kiểm tra giới hạn tổng lượt sử dụng (usage_limit và usage_count)
            if ((int) $coupon['usage_limit'] > 0 && (int) $coupon['usage_count'] >= (int) $coupon['usage_limit']) {
                throw new RuntimeException("Voucher {$coupon['code']} đã hết lượt sử dụng toàn hệ thống ({$coupon['usage_count']}/{$coupon['usage_limit']}).");
            }

            // 3.5. Kiểm tra giới hạn sử dụng trên mỗi người dùng (per_user_limit)
            $perUserLimit = (int) $coupon['per_user_limit'];
            if ($perUserLimit > 0) {
                if ($userId !== null) {
                    // Khách hàng đã đăng nhập: Kiểm tra theo user_id HOẶC SĐT/Email chuẩn hóa (ngăn lách luật bằng cách đặt guest trước đó)
                    $stmtUserUsage = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM coupon_usages
                        WHERE coupon_id = :cid
                          AND status = 'used'
                          AND (user_id = :uid OR customer_phone = :phone OR LOWER(customer_email) = :email)
                        FOR UPDATE
                    ");
                    $stmtUserUsage->execute([
                        ':cid'   => $couponId,
                        ':uid'   => $userId,
                        ':phone' => $customerPhone,
                        ':email' => $customerEmail,
                    ]);
                    $usedCount = (int) $stmtUserUsage->fetchColumn();
                    if ($usedCount >= $perUserLimit) {
                        throw new RuntimeException("Tài khoản khách hàng (user_id={$userId}) đã sử dụng hết số lượt cho phép ({$usedCount}/{$perUserLimit}) của voucher {$coupon['code']}.");
                    }
                } else {
                    // Khách vãng lai (Guest Checkout): Định danh qua cặp customer_phone HOẶC customer_email đã chuẩn hóa.
                    // LƯU Ý HẠN CHẾ THỰC TẾ: Vì khách vãng lai không có user_id cố định, quy tắc này chặn được khách dùng lại
                    // cùng SĐT hoặc cùng Email, nhưng không chặn tuyệt đối được nếu khách cố tình đổi cả SIM SĐT mới lẫn Email mới.
                    $stmtGuestUsage = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM coupon_usages
                        WHERE coupon_id = :cid
                          AND status = 'used'
                          AND (customer_phone = :phone OR LOWER(customer_email) = :email)
                        FOR UPDATE
                    ");
                    $stmtGuestUsage->execute([
                        ':cid'   => $couponId,
                        ':phone' => $customerPhone,
                        ':email' => $customerEmail,
                    ]);
                    $usedGuestCount = (int) $stmtGuestUsage->fetchColumn();
                    if ($usedGuestCount >= $perUserLimit) {
                        throw new RuntimeException("Khách vãng lai (SĐT={$customerPhone} / Email={$customerEmail}) đã sử dụng hết số lượt cho phép ({$usedGuestCount}/{$perUserLimit}) của voucher {$coupon['code']}.");
                    }
                }
            }

            // 3.6. Tính số tiền được giảm giá trên Server và kiểm tra không cho discount_amount > total_product_amount
            if ($coupon['discount_type'] === 'fixed_amount') {
                $discountAmount = (int) $coupon['discount_value'];
            } else {
                $discountAmount = (int) floor($totalProductAmount * ((int) $coupon['discount_value']) / 100);
                if ($coupon['max_discount_amount'] !== null && $discountAmount > (int) $coupon['max_discount_amount']) {
                    $discountAmount = (int) $coupon['max_discount_amount'];
                }
            }

            if ($discountAmount > $totalProductAmount) {
                throw new RuntimeException("Số tiền giảm giá của voucher ({$discountAmount} VND) không được lớn hơn tổng tiền sản phẩm ({$totalProductAmount} VND).");
            }

            // 3.7. Tăng usage_count nguyên tử có điều kiện biên chống vượt usage_limit và kiểm tra thời hạn
            $stmtIncCoupon = $pdo->prepare("
                UPDATE coupons
                SET usage_count = usage_count + 1
                WHERE id = :cid
                  AND is_active = 1
                  AND NOW() BETWEEN start_date AND end_date
                  AND (usage_limit = 0 OR usage_count < usage_limit)
            ");
            $stmtIncCoupon->execute([':cid' => $couponId]);
            if ($stmtIncCoupon->rowCount() !== 1) {
                throw new RuntimeException("Không thể tăng usage_count cho voucher ID={$couponId} (đã hết lượt hoặc hết hạn).");
            }
        }

        // 4. Tính final_amount an toàn trên Server (trừ trước, cộng sau để tránh tràn số và đảm bảo final_amount >= 0)
        $netProductAmount = $totalProductAmount - $discountAmount;
        if ($netProductAmount < 0 || $netProductAmount > MAX_SAFE_MONEY_VND - $shippingFee) {
            throw new RuntimeException("Lỗi tràn giới hạn số tiền khi tính final_amount.");
        }
        $finalAmount = $netProductAmount + $shippingFee;
        if ($finalAmount < 0) {
            throw new RuntimeException("Tổng tiền thanh toán cuối cùng (final_amount = {$finalAmount}) không được nhỏ hơn 0.");
        }

        // 5. Tạo bản ghi đơn hàng trong bảng orders
        $stmtOrder = $pdo->prepare("
            INSERT INTO orders (
                order_code, user_id, customer_name, customer_email, customer_phone,
                shipping_address, shipping_city, shipping_district, customer_note,
                total_product_amount, shipping_fee, discount_amount, final_amount, coupon_id, status
            ) VALUES (
                :code, :uid, :name, :email, :phone,
                :addr, :city, :dist, :note,
                :total_prod, :ship, :disc, :final, :cid, 'pending'
            )
        ");
        $stmtOrder->execute([
            ':code'       => $orderData['order_code'],
            ':uid'        => $userId,
            ':name'       => trim((string) $orderData['customer_name']),
            ':email'      => $customerEmail,
            ':phone'      => $customerPhone,
            ':addr'       => trim((string) $orderData['shipping_address']),
            ':city'       => trim((string) ($orderData['shipping_city'] ?? 'Hà Nội')),
            ':dist'       => isset($orderData['shipping_district']) ? trim((string) $orderData['shipping_district']) : null,
            ':note'       => isset($orderData['customer_note']) ? trim((string) $orderData['customer_note']) : null,
            ':total_prod' => $totalProductAmount,
            ':ship'       => $shippingFee,
            ':disc'       => $discountAmount,
            ':final'      => $finalAmount,
            ':cid'        => $couponId,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        // 6. Tạo các dòng chi tiết đơn hàng (order_items) với giá Snapshot từ Server
        $stmtInsertItem = $pdo->prepare("
            INSERT INTO order_items (
                order_id, product_id, product_unit_id, product_name, product_sku,
                unit_serial_number, price, quantity, subtotal, warranty_period
            ) VALUES (
                :oid, :pid, :uid, :pname, :psku,
                :sn, :price, :qty, :sub, :warranty
            )
        ");
        foreach ($preparedItems as $pi) {
            $stmtInsertItem->execute([
                ':oid'      => $orderId,
                ':pid'      => $pi['product_id'],
                ':uid'      => $pi['product_unit_id'],
                ':pname'    => $pi['product_name'],
                ':psku'     => $pi['product_sku'],
                ':sn'       => $pi['unit_serial_number'],
                ':price'    => $pi['price'],
                ':qty'      => $pi['quantity'],
                ':sub'      => $pi['subtotal'],
                ':warranty' => $pi['warranty_period'],
            ]);
        }

        // 7. Nếu có áp dụng voucher, ghi nhận vào bảng coupon_usages với status = 'used'
        if ($couponId !== null) {
            $stmtUsage = $pdo->prepare("
                INSERT INTO coupon_usages (
                    coupon_id, order_id, user_id, customer_email, customer_phone, discount_applied, status
                ) VALUES (
                    :cid, :oid, :uid, :email, :phone, :disc, 'used'
                )
            ");
            $stmtUsage->execute([
                ':cid'   => $couponId,
                ':oid'   => $orderId,
                ':uid'   => $userId,
                ':email' => $customerEmail,
                ':phone' => $customerPhone,
                ':disc'  => $discountAmount,
            ]);
        }

        // 8. BẢO MẬT THANH TOÁN: Khi tạo đơn, payments.status BẮT BUỘC mặc định là 'pending'.
        // Tuyệt đối KHÔNG lấy $orderData['payment_status'] từ phía khách hàng gửi lên!
        $paymentMethod = $orderData['payment_method'] ?? 'cod';
        if (!in_array($paymentMethod, ['cod', 'bank_transfer'], true)) {
            throw new RuntimeException("Phương thức thanh toán ({$paymentMethod}) không hợp lệ.");
        }

        $stmtPay = $pdo->prepare("
            INSERT INTO payments (order_id, payment_method, amount, status)
            VALUES (:oid, :method, :amt, 'pending')
        ");
        $stmtPay->execute([
            ':oid'    => $orderId,
            ':method' => $paymentMethod,
            ':amt'    => $finalAmount,
        ]);

        $pdo->commit();
        return [
            'success'              => true,
            'order_id'             => $orderId,
            'total_product_amount' => $totalProductAmount,
            'discount_amount'      => $discountAmount,
            'shipping_fee'         => $shippingFee,
            'final_amount'         => $finalAmount,
            'payment_status'       => 'pending',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Quy trình Xác nhận Thanh toán dành riêng cho Admin có phân quyền
 * Chỉ tài khoản có role = 'admin' và status = 'active' mới được đổi payments.status từ 'pending' sang 'completed'.
 */
function confirmPaymentByAdminTransaction(PDO $pdo, int $orderId, int $actorUserId, string $transactionCode): array
{
    $pdo->beginTransaction();
    try {
        // 1. Kiểm tra phân quyền của người thực hiện (bắt buộc là Admin đang hoạt động)
        $stmtActor = $pdo->prepare("SELECT id, full_name, role, status FROM users WHERE id = :uid FOR UPDATE");
        $stmtActor->execute([':uid' => $actorUserId]);
        $actor = $stmtActor->fetch();

        if (!$actor || $actor['status'] !== 'active' || $actor['role'] !== 'admin') {
            throw new RuntimeException("Từ chối truy cập: Người dùng ID={$actorUserId} không có quyền Quản trị viên (admin) để xác nhận thanh toán.");
        }

        // 2. Khóa đơn hàng và bản ghi thanh toán
        $stmtOrder = $pdo->prepare("SELECT id, status, final_amount FROM orders WHERE id = :oid FOR UPDATE");
        $stmtOrder->execute([':oid' => $orderId]);
        $order = $stmtOrder->fetch();
        if (!$order || !in_array($order['status'], ['pending', 'confirmed'], true)) {
            throw new RuntimeException("Đơn hàng ID={$orderId} không tồn tại hoặc không ở trạng thái cho phép duyệt thanh toán.");
        }

        $stmtPay = $pdo->prepare("SELECT id, status, amount FROM payments WHERE order_id = :oid FOR UPDATE");
        $stmtPay->execute([':oid' => $orderId]);
        $payment = $stmtPay->fetch();
        if (!$payment || $payment['status'] !== 'pending') {
            throw new RuntimeException("Giao dịch thanh toán của đơn ID={$orderId} không ở trạng thái 'pending'.");
        }

        // 3. Cập nhật payments.status sang 'completed' và ghi nhận người duyệt
        $stmtUpdPay = $pdo->prepare("
            UPDATE payments
            SET status = 'completed',
                transaction_code = :tx,
                confirmed_by_user_id = :admin_id,
                confirmed_at = NOW()
            WHERE id = :pid AND status = 'pending'
        ");
        $stmtUpdPay->execute([
            ':tx'       => $transactionCode,
            ':admin_id' => $actorUserId,
            ':pid'      => $payment['id'],
        ]);
        if ($stmtUpdPay->rowCount() !== 1) {
            throw new RuntimeException("Không thể cập nhật trạng thái thanh toán sang 'completed'.");
        }

        // Đồng bộ trạng thái đơn hàng sang 'confirmed' nếu đang 'pending'
        if ($order['status'] === 'pending') {
            $pdo->prepare("UPDATE orders SET status = 'confirmed' WHERE id = :oid")->execute([':oid' => $orderId]);
        }

        $pdo->commit();
        return ['success' => true, 'payment_status' => 'completed', 'confirmed_by' => $actorUserId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Quy trình Hoàn tiền (Refund) tách biệt với Quy trình Hủy đơn
 * Chỉ áp dụng cho đơn hàng đã hủy (orders.status = 'cancelled') có giao dịch đã thu tiền (payments.status = 'completed')
 * và bắt buộc thực hiện bởi Admin có phân quyền.
 */
function refundPaymentByAdminTransaction(PDO $pdo, int $orderId, int $adminUserId, string $refundNote): array
{
    $pdo->beginTransaction();
    try {
        $stmtActor = $pdo->prepare("SELECT id, role, status FROM users WHERE id = :uid FOR UPDATE");
        $stmtActor->execute([':uid' => $adminUserId]);
        $actor = $stmtActor->fetch();
        if (!$actor || $actor['status'] !== 'active' || $actor['role'] !== 'admin') {
            throw new RuntimeException("Từ chối truy cập: Chỉ Admin mới có quyền xác nhận hoàn tiền (refund).");
        }

        $stmtOrder = $pdo->prepare("SELECT id, status FROM orders WHERE id = :oid FOR UPDATE");
        $stmtOrder->execute([':oid' => $orderId]);
        $order = $stmtOrder->fetch();
        if (!$order || $order['status'] !== 'cancelled') {
            throw new RuntimeException("Chỉ được phép hoàn tiền cho đơn hàng đã ở trạng thái 'cancelled'.");
        }

        $stmtPay = $pdo->prepare("SELECT id, status FROM payments WHERE order_id = :oid FOR UPDATE");
        $stmtPay->execute([':oid' => $orderId]);
        $payment = $stmtPay->fetch();
        if (!$payment || $payment['status'] !== 'completed') {
            throw new RuntimeException("Chỉ được hoàn tiền khi giao dịch thanh toán đang ở trạng thái 'completed'.");
        }

        $stmtRefund = $pdo->prepare("
            UPDATE payments
            SET status = 'refunded',
                transfer_note = CONCAT(IFNULL(transfer_note, ''), ' | Hoàn tiền: ', :note)
            WHERE id = :pid AND status = 'completed'
        ");
        $stmtRefund->execute([':note' => $refundNote, ':pid' => $payment['id']]);
        if ($stmtRefund->rowCount() !== 1) {
            throw new RuntimeException("Không thể chuyển trạng thái thanh toán sang 'refunded'.");
        }

        $pdo->commit();
        return ['success' => true, 'payment_status' => 'refunded'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Quy trình Hủy đơn hàng Nguyên tử & Idempotent
 *
 * Bảo đảm toàn bộ các quy tắc nghiệp vụ:
 * - Hỗ trợ cả has_serials = 1 và has_serials = 0.
 * - Chặn hoàn kho 2 lần khi gọi hủy lặp (Idempotency).
 * - Nếu mặt hàng có serial (has_serials = 1) mà không thể chuyển từ 'reserved' về 'available' -> ROLLBACK toàn bộ transaction và ghi lỗi rõ ràng.
 * - Nếu đơn hàng có dùng voucher mà hoàn lượt voucher không thành công -> ROLLBACK toàn bộ transaction.
 * - Không làm mất lịch sử order_items và payments; giữ nguyên payments.status = 'completed' khi hủy đơn đã trả tiền.
 */
function cancelOrderTransaction(PDO $pdo, int $orderId, string $cancelReason): array
{
    $pdo->beginTransaction();
    try {
        // 1. Khóa bản ghi đơn hàng cần hủy để kiểm tra trạng thái hiện tại
        $stmtOrder = $pdo->prepare("SELECT id, order_code, status, coupon_id FROM orders WHERE id = :oid FOR UPDATE");
        $stmtOrder->execute([':oid' => $orderId]);
        $order = $stmtOrder->fetch();

        if (!$order) {
            $pdo->rollBack();
            return ['success' => false, 'reason' => 'ORDER_NOT_FOUND', 'error' => 'Đơn hàng không tồn tại.'];
        }

        // Chỉ cho phép hủy khi đơn đang ở trạng thái pending hoặc confirmed (Idempotent: gọi hủy lần 2 sẽ từ chối ngay)
        if (!in_array($order['status'], ['pending', 'confirmed'], true)) {
            $pdo->rollBack();
            return [
                'success'        => false,
                'reason'         => 'INVALID_STATUS_OR_ALREADY_CANCELLED',
                'current_status' => $order['status'],
                'error'          => "Không thể hủy đơn ở trạng thái '{$order['status']}'.",
            ];
        }

        // 2. Cập nhật trạng thái đơn hàng sang cancelled
        $stmtCancelOrder = $pdo->prepare("
            UPDATE orders
            SET status = 'cancelled',
                cancel_reason = :reason,
                cancelled_at = NOW()
            WHERE id = :oid
              AND status IN ('pending', 'confirmed')
        ");
        $stmtCancelOrder->execute([':reason' => $cancelReason, ':oid' => $orderId]);
        if ($stmtCancelOrder->rowCount() !== 1) {
            throw new RuntimeException("Không thể cập nhật trạng thái đơn hàng #{$orderId} sang 'cancelled'.");
        }

        // 3. Lấy danh sách mặt hàng trong đơn kèm cờ has_serials để hoàn kho nguyên tử
        $stmtItems = $pdo->prepare("
            SELECT oi.id, oi.product_id, oi.product_unit_id, oi.quantity, p.has_serials
            FROM order_items oi
            INNER JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = :oid
            FOR UPDATE
        ");
        $stmtItems->execute([':oid' => $orderId]);
        $items = $stmtItems->fetchAll();

        if (empty($items)) {
            throw new RuntimeException("Đơn hàng #{$orderId} không có dữ liệu chi tiết mặt hàng (order_items trống).");
        }

        $restockedUnits      = 0;
        $restockedQuantities = 0;

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $unitId    = $item['product_unit_id'] !== null ? (int) $item['product_unit_id'] : null;
            $qty       = (int) $item['quantity'];

            if ((int) $item['has_serials'] === 1) {
                // Bắt buộc phải có product_unit_id hợp lệ đối với sản phẩm có serial
                if ($unitId === null) {
                    throw new RuntimeException("Lỗi hoàn kho Serial khi hủy đơn #{$orderId}: Sản phẩm ID={$productId} có has_serials=1 nhưng thiếu product_unit_id.");
                }

                // Khóa máy vật lý và kiểm tra trạng thái phải đang là 'reserved'
                $stmtLockUnit = $pdo->prepare("SELECT id, serial_number, status FROM product_units WHERE id = :uid AND product_id = :pid FOR UPDATE");
                $stmtLockUnit->execute([':uid' => $unitId, ':pid' => $productId]);
                $unitRow = $stmtLockUnit->fetch();

                $stmtReleaseUnit = $pdo->prepare("
                    UPDATE product_units
                    SET status = 'available'
                    WHERE id = :uid AND product_id = :pid AND status = 'reserved'
                ");
                $stmtReleaseUnit->execute([':uid' => $unitId, ':pid' => $productId]);

                // YÊU CẦU 4: Nếu không thể chuyển từ 'reserved' về 'available', bắt buộc ROLLBACK toàn bộ transaction!
                if ($stmtReleaseUnit->rowCount() !== 1) {
                    $curUnitStatus = $unitRow['status'] ?? 'KHÔNG_TỒN_TẠI';
                    throw new RuntimeException("Lỗi hoàn kho Serial khi hủy đơn #{$orderId}: Không thể chuyển máy ID={$unitId} từ 'reserved' về 'available' (trạng thái hiện tại: '{$curUnitStatus}'). Đã ROLLBACK toàn bộ giao dịch.");
                }

                // Cộng hoàn đúng 1 đơn vị tồn kho cho dòng sản phẩm
                $stmtIncStock = $pdo->prepare("
                    UPDATE products
                    SET stock_quantity = stock_quantity + 1,
                        status = CASE WHEN status = 'out_of_stock' THEN 'active' ELSE status END
                    WHERE id = :pid
                ");
                $stmtIncStock->execute([':pid' => $productId]);
                if ($stmtIncStock->rowCount() !== 1) {
                    throw new RuntimeException("Lỗi cộng hoàn tồn kho cho sản phẩm ID={$productId} khi hủy đơn #{$orderId}.");
                }

                $restockedUnits++;
                $restockedQuantities += 1;
            } else {
                // Mặt hàng không có serial (has_serials = 0): Cộng hoàn đúng order_items.quantity vào products.stock_quantity
                $stmtIncStock = $pdo->prepare("
                    UPDATE products
                    SET stock_quantity = stock_quantity + :qty,
                        status = CASE WHEN status = 'out_of_stock' THEN 'active' ELSE status END
                    WHERE id = :pid
                ");
                $stmtIncStock->execute([':qty' => $qty, ':pid' => $productId]);
                if ($stmtIncStock->rowCount() !== 1) {
                    throw new RuntimeException("Lỗi cộng hoàn tồn kho số lượng ({$qty}) cho sản phẩm ID={$productId} khi hủy đơn #{$orderId}.");
                }
                $restockedQuantities += $qty;
            }
        }

        // 4. Hoàn lượt sử dụng Voucher (nếu đơn hàng có dùng voucher): Nếu thất bại phải ROLLBACK toàn bộ transaction!
        $voucherRestored = false;
        if ($order['coupon_id'] !== null) {
            $couponId = (int) $order['coupon_id'];

            $stmtLockUsage = $pdo->prepare("
                SELECT id, coupon_id, status
                FROM coupon_usages
                WHERE order_id = :oid AND coupon_id = :cid AND status = 'used'
                FOR UPDATE
            ");
            $stmtLockUsage->execute([':oid' => $orderId, ':cid' => $couponId]);
            $usage = $stmtLockUsage->fetch();

            if (!$usage) {
                throw new RuntimeException("Lỗi hoàn voucher khi hủy đơn #{$orderId}: Không tìm thấy bản ghi coupon_usages hợp lệ (status='used') cho coupon_id={$couponId}. Đã ROLLBACK toàn bộ giao dịch.");
            }

            // Khóa bản ghi coupon trước khi giảm usage_count
            $stmtLockCoupon = $pdo->prepare("SELECT id, usage_count FROM coupons WHERE id = :cid FOR UPDATE");
            $stmtLockCoupon->execute([':cid' => $couponId]);
            $couponRow = $stmtLockCoupon->fetch();

            $stmtCancelUsage = $pdo->prepare("
                UPDATE coupon_usages
                SET status = 'cancelled'
                WHERE id = :uid AND status = 'used'
            ");
            $stmtCancelUsage->execute([':uid' => $usage['id']]);
            if ($stmtCancelUsage->rowCount() !== 1) {
                throw new RuntimeException("Lỗi hoàn voucher khi hủy đơn #{$orderId}: Không thể cập nhật coupon_usages.status sang 'cancelled'. Đã ROLLBACK toàn bộ giao dịch.");
            }

            // Giảm usage_count đi 1 với điều kiện chống âm (usage_count > 0)
            $stmtDecCoupon = $pdo->prepare("
                UPDATE coupons
                SET usage_count = usage_count - 1
                WHERE id = :cid AND usage_count > 0
            ");
            $stmtDecCoupon->execute([':cid' => $couponId]);
            if ($stmtDecCoupon->rowCount() !== 1) {
                $curUsageCount = $couponRow['usage_count'] ?? 'NULL';
                throw new RuntimeException("Lỗi hoàn voucher khi hủy đơn #{$orderId}: Không thể giảm usage_count cho coupon_id={$couponId} (usage_count hiện tại = {$curUsageCount}). Đã ROLLBACK toàn bộ giao dịch.");
            }

            $voucherRestored = true;
        }

        // 5. Cập nhật trạng thái thanh toán: chỉ chuyển pending -> failed, giữ nguyên completed để bảo toàn lịch sử đối soát
        $stmtUpdatePayment = $pdo->prepare("
            UPDATE payments
            SET status = 'failed'
            WHERE order_id = :oid AND status = 'pending'
        ");
        $stmtUpdatePayment->execute([':oid' => $orderId]);

        $pdo->commit();
        return [
            'success'              => true,
            'restocked_units'      => $restockedUnits,
            'restocked_quantities' => $restockedQuantities,
            'voucher_restored'     => $voucherRestored,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'reason'  => 'ROLLBACK_ON_FAILURE',
            'error'   => $e->getMessage(),
        ];
    }
}

// ============================================================================
// CHƯƠNG TRÌNH CHÍNH: CHẠY TOÀN BỘ CÁC BỘ KIỂM THỬ (TEST SUITES)
// ============================================================================
echo "================================================================================\n";
echo "  BẮT ĐẦU KIỂM THỬ CƠ SỞ DỮ LIỆU & NGHIỆP VỤ TOÀN DIỆN — TL GAMING\n";
echo "  Thời gian chạy: " . date('Y-m-d H:i:s') . "\n";
echo "================================================================================\n\n";

$pdoServer = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Chụp trạng thái CSDL chính (tl_gaming) trước khi chạy test để chứng minh an toàn tuyệt đối
$prodBefore = snapshotDatabaseCounts($pdoServer, $prodDbName);

// Khởi tạo lại môi trường test sạch từ database/tl_gaming.sql và database/seed_tl_gaming.sql
echo "[INIT] Đang nạp lại CSDL kiểm thử độc lập `{$dbName}` từ tl_gaming.sql & seed_tl_gaming.sql...\n";
resetTestDatabase($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
echo "[INIT] Nạp `{$dbName}` hoàn tất. CSDL chính `{$prodDbName}` được giữ nguyên 100%.\n\n";

$pdo = createConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
$results = [];

function recordTest(array &$results, string $id, string $suite, string $title, string $status, string $detail): void
{
    $results[] = [
        'suite'       => $suite,
        'test_id'     => $id,
        'description' => $title,
        'status'      => $status,
        'details'     => $detail,
    ];
    echo sprintf("[%s] %-12s | %s\n", $status, $id, $title);
    echo "       -> " . str_replace("\n", "\n          ", $detail) . "\n\n";
}

// ----------------------------------------------------------------------------
// NHÓM 1: KIỂM TRA CẤU TRÚC SCHEMA, COMPOSITE FK, UNIQUE & CHECK CONSTRAINTS
// ----------------------------------------------------------------------------
$stmtTables = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
$stmtTables->execute([':db' => $dbName]);
$tables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);
recordTest(
    $results,
    'TC-SCH-01',
    '1. Schema & Ràng buộc',
    'Kiểm tra đủ 15 bảng chuẩn hóa (bao gồm inspection_items) dùng InnoDB & utf8mb4',
    count($tables) === 15 && in_array('inspection_items', $tables, true) ? 'PASS' : 'FAIL',
    "Số bảng thực tế: " . count($tables) . "/15 (" . implode(', ', $tables) . ")"
);

$stmtIdx = $pdo->prepare("
    SELECT INDEX_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'order_items' AND NON_UNIQUE = 0
");
$stmtIdx->execute([':db' => $dbName]);
$uniqueIdxOrderItems = $stmtIdx->fetchAll(PDO::FETCH_COLUMN);
$hasOrderUnitUnique  = in_array('uq_order_items_order_unit', $uniqueIdxOrderItems, true);

$stmtCheck = $pdo->prepare("
    SELECT CONSTRAINT_NAME
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = :db AND TABLE_NAME IN ('coupons', 'orders') AND CONSTRAINT_TYPE = 'CHECK'
");
$stmtCheck->execute([':db' => $dbName]);
$checks = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);
$hasCouponBoundCheck = in_array('chk_coupons_usage_bound', $checks, true);
$hasOrderDiscCheck   = in_array('chk_orders_discount', $checks, true);
$hasOrderFinalCheck  = in_array('chk_orders_final_amount', $checks, true);

$fkCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = '{$dbName}'
")->fetchColumn();

recordTest(
    $results,
    'TC-SCH-02',
    '1. Schema & Ràng buộc',
    'Kiểm tra đủ 22 Foreign Keys, UNIQUE (order_id, product_unit_id) và các CHECK tiền tệ/voucher',
    ($fkCount === 22 && $hasOrderUnitUnique && $hasCouponBoundCheck && $hasOrderDiscCheck && $hasOrderFinalCheck) ? 'PASS' : 'FAIL',
    "Foreign Keys: {$fkCount}/22 | uq_order_items_order_unit: " . ($hasOrderUnitUnique ? 'CÓ' : 'THIẾU') .
    " | chk_coupons_usage_bound: " . ($hasCouponBoundCheck ? 'CÓ' : 'THIẾU') .
    " | chk_orders_discount & chk_orders_final_amount: " . (($hasOrderDiscCheck && $hasOrderFinalCheck) ? 'CÓ' : 'THIẾU')
);

// ----------------------------------------------------------------------------
// NHÓM 2: KIỂM TRA DỮ LIỆU SEED, CHECKLIST 18 BƯỚC & SẢN PHẨM KHÔNG SERIAL
// ----------------------------------------------------------------------------
$totalProducts  = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$serialProducts = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE has_serials = 1")->fetchColumn();
$nonSerialProd  = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE has_serials = 0")->fetchColumn();
$inspCount      = (int) $pdo->query("SELECT COUNT(*) FROM product_inspections")->fetchColumn();
$inspItemsCount = (int) $pdo->query("SELECT COUNT(*) FROM inspection_items")->fetchColumn();

recordTest(
    $results,
    'TC-SEED-01',
    '2. Dữ liệu Seed',
    'Kiểm tra dữ liệu mẫu: Sản phẩm có serial (has_serials=1), không serial (has_serials=0) & 18 bước kiểm định',
    ($totalProducts === 14 && $serialProducts === 13 && $nonSerialProd === 1 && $inspCount === 5 && $inspItemsCount === 90) ? 'PASS' : 'FAIL',
    "Tổng SP: {$totalProducts} (has_serials=1: {$serialProducts}, has_serials=0: {$nonSerialProd}) | Biên bản kiểm định: {$inspCount} | Tổng bước kiểm định: {$inspItemsCount} (5 x 18 bước)"
);

// ----------------------------------------------------------------------------
// NHÓM 3: KIỂM THỬ RÀNG BUỘC SERIAL, COMPOSITE FK, CHỐNG TRÙNG SERIAL TRONG CÙNG ĐƠN & TRIGGER
// ----------------------------------------------------------------------------

// TC-SER-01: Composite FK chặn gán Serial của sản phẩm A cho dòng hàng của sản phẩm B
$ser01Passed = false;
$ser01Msg = '';
try {
    $pdo->beginTransaction();
    // Unit ID=13 thuộc Product ID=9, cố tình gán product_id = 1
    $pdo->exec("
        INSERT INTO order_items (order_id, product_id, product_unit_id, product_name, product_sku, unit_serial_number, price, quantity, subtotal, warranty_period)
        VALUES (1, 1, 13, 'Test Sai Composite FK', 'SKU-PC-01', 'TLG-VGA-3070-SN601', 16500000, 1, 16500000, '12 tháng')
    ");
    $pdo->rollBack();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23000') {
        $ser01Passed = true;
        $ser01Msg = "MySQL chặn thành công bởi Composite FK fk_order_items_unit_product (SQLSTATE={$e->getCode()}): " . $e->getMessage();
    } else {
        $ser01Msg = "Bị chặn nhưng khác mã lỗi kỳ vọng: " . $e->getMessage();
    }
}
recordTest(
    $results,
    'TC-SER-01',
    '3. Toàn vẹn Serial',
    'Composite FK chặn gán product_unit_id của sản phẩm này vào dòng order_items của sản phẩm khác',
    $ser01Passed ? 'PASS' : 'FAIL',
    $ser01Msg
);

// TC-SER-02: UNIQUE KEY uq_order_items_order_unit chặn cùng một serial xuất hiện 2 lần trong cùng một đơn hàng
$ser02Passed = false;
$ser02Msg = '';
try {
    $pdo->beginTransaction();
    $pdo->exec("
        INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, total_product_amount, shipping_fee, discount_amount, final_amount, status)
        VALUES ('TLG-DUP-UNIT-01', 2, 'Khách Test Trùng Serial Cùng Đơn', 'dup@demo.vn', '0912345678', 'Số 10 Thái Hà', 'Hà Nội', 15600000, 0, 0, 15600000, 'pending')
    ");
    $dupOrderId = (int) $pdo->lastInsertId();

    $stmtDup = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, product_unit_id, product_name, product_sku, unit_serial_number, price, quantity, subtotal, warranty_period)
        VALUES (:oid, 9, 13, 'VGA MSI Gaming X Trio RTX 3070', 'SKU-VGA-02', 'TLG-VGA-3070-SN601', 7800000, 1, 7800000, '6 tháng')
    ");
    $stmtDup->execute([':oid' => $dupOrderId]);
    // Chèn lặp lần 2 cùng Unit ID=13 trong cùng đơn hàng
    $stmtDup->execute([':oid' => $dupOrderId]);
    $pdo->rollBack();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'uq_order_items_order_unit') !== false) {
        $ser02Passed = true;
        $ser02Msg = "MySQL chặn tuyệt đối bằng UNIQUE KEY uq_order_items_order_unit (SQLSTATE 23000 - Duplicate entry): " . $e->getMessage();
    } else {
        $ser02Msg = "Lỗi khác: " . $e->getMessage();
    }
}
recordTest(
    $results,
    'TC-SER-02',
    '3. Toàn vẹn Serial',
    'Chặn cùng một serial (product_unit_id) xuất hiện 2 lần trong cùng một đơn hàng (uq_order_items_order_unit)',
    $ser02Passed ? 'PASS' : 'FAIL',
    $ser02Msg
);

// TC-SER-03: Chặn gán một serial đã thuộc đơn hàng khác chưa bị hủy (Trigger + Row Lock)
$ser03Passed = false;
$ser03Msg = '';
try {
    $pdo->beginTransaction();
    // Unit ID=1 (Product ID=1, TLG-PC-12400F-SN001) đang nằm trong Đơn hàng ID=1 (status='pending' - chưa hủy)
    $pdo->exec("
        INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, total_product_amount, shipping_fee, discount_amount, final_amount, status)
        VALUES ('TLG-ACTIVE-UNIT-01', 3, 'Khách Test Đơn Đang Mở', 'active@demo.vn', '0988777666', 'Số 12 Cầu Giấy', 'Hà Nội', 11500000, 0, 0, 11500000, 'pending')
    ");
    $newOrdId = (int) $pdo->lastInsertId();

    $stmtAct = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, product_unit_id, product_name, product_sku, unit_serial_number, price, quantity, subtotal, warranty_period)
        VALUES (:oid, 1, 1, 'PC Gaming TL-01', 'SKU-PC-01', 'TLG-PC-12400F-SN001', 11500000, 1, 11500000, '12 tháng')
    ");
    $stmtAct->execute([':oid' => $newOrdId]);
    $pdo->rollBack();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '45000') {
        $ser03Passed = true;
        $ser03Msg = "Trigger tg_check_order_item_unit_active_insert chặn thành công (SQLSTATE 45000): " . $e->getMessage();
    } else {
        $ser03Msg = "Lỗi khác: " . $e->getMessage();
    }
}
recordTest(
    $results,
    'TC-SER-03',
    '3. Toàn vẹn Serial',
    'Chặn gán serial đang thuộc một đơn hàng khác chưa hủy (orders.status <> cancelled)',
    $ser03Passed ? 'PASS' : 'FAIL',
    $ser03Msg
);

// TC-SER-04: Cho phép bán lại serial sau khi đơn hàng trước đó đã bị hủy (cancelled) mà không mất lịch sử đơn cũ
$resellRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-RESELL-U13',
        'user_id'          => 2,
        'customer_name'    => 'Lê Hoàng Nam (Mua lại máy từ đơn đã hủy)',
        'customer_email'   => 'nam.le@example.com',
        'customer_phone'   => '0912345601',
        'shipping_address' => 'Số 45 Thái Hà, Đống Đa, Hà Nội',
    ],
    [
        ['product_id' => 9, 'product_unit_id' => 13, 'quantity' => 1],
    ]
);
$stmtHist = $pdo->query("
    SELECT o.order_code, o.status
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    WHERE oi.product_unit_id = 13
    ORDER BY oi.id ASC
");
$unit13History = $stmtHist->fetchAll();
$ser04Passed = $resellRes['success'] && count($unit13History) === 2 && $unit13History[0]['status'] === 'cancelled' && $unit13History[1]['status'] === 'pending';

if ($resellRes['success']) {
    cancelOrderTransaction($pdo, $resellRes['order_id'], 'Hoàn trả trạng thái máy Unit 13 sau khi kiểm thử bán lại');
    $pdo->prepare("DELETE FROM payments WHERE order_id = :oid")->execute([':oid' => $resellRes['order_id']]);
    $pdo->prepare("DELETE FROM order_items WHERE order_id = :oid")->execute([':oid' => $resellRes['order_id']]);
    $pdo->prepare("DELETE FROM orders WHERE id = :oid")->execute([':oid' => $resellRes['order_id']]);
}

recordTest(
    $results,
    'TC-SER-04',
    '3. Toàn vẹn Serial',
    'Cho phép bán lại serial từ đơn đã hủy (cancelled) sang đơn mới mà vẫn giữ nguyên lịch sử đơn cũ',
    $ser04Passed ? 'PASS' : 'FAIL',
    "Kết quả đặt lại Unit #13 (TLG-VGA-3070-SN601): " . ($resellRes['success'] ? 'THÀNH CÔNG' : 'THẤT BẠI') .
    " | Lịch sử đơn của Unit #13: " . json_encode($unit13History, JSON_UNESCAPED_UNICODE)
);

// ----------------------------------------------------------------------------
// NHÓM 4: KIỂM THỬ CONCURRENCY THỰC SỰ CHỒNG LẤN (2 KẾT NỐI & 2 TIẾN TRÌNH)
// ----------------------------------------------------------------------------

// TC-CON-01: Kiểm thử 2 kết nối PDO độc lập chồng lấn thực sự (A giữ khóa mở chưa commit, B cố khóa/cập nhật và bị Lock Wait Timeout)
$pdoA = createConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
$pdoB = createConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
$connIdA = (int) $pdoA->query("SELECT CONNECTION_ID()")->fetchColumn();
$connIdB = (int) $pdoB->query("SELECT CONNECTION_ID()")->fetchColumn();

$pdoB->exec("SET innodb_lock_wait_timeout = 1");

$con01Logs = [];
$con01Passed = false;
$unitTarget = 5; // Unit #5 (TLG-PC-10700-SN001, Product ID=3) đang ở trạng thái available

try {
    $con01Logs[] = "[" . nowMs() . "] [Kết nối A | Conn #{$connIdA}] BEGIN TRANSACTION";
    $pdoA->beginTransaction();

    $stmtLockA = $pdoA->prepare("SELECT id, serial_number, status FROM product_units WHERE id = :uid AND status = 'available' FOR UPDATE");
    $stmtLockA->execute([':uid' => $unitTarget]);
    $rowA = $stmtLockA->fetch();
    $con01Logs[] = "[" . nowMs() . "] [Kết nối A | Conn #{$connIdA}] Đã khóa độc quyền (X-Lock) Unit #{$unitTarget} ({$rowA['serial_number']}, status={$rowA['status']}). Giao dịch A ĐANG MỞ (chưa commit)!";

    $con01Logs[] = "[" . nowMs() . "] [Kết nối B | Conn #{$connIdB}] BEGIN TRANSACTION (trong khi A vẫn đang giữ khóa)";
    $pdoB->beginTransaction();

    $tStartB = microtime(true);
    $con01Logs[] = "[" . nowMs() . "] [Kết nối B | Conn #{$connIdB}] Thực thi SELECT ... FROM product_units WHERE id = {$unitTarget} FOR UPDATE (bị treo chờ khóa của A)...";

    $bBlockedAndTimedOut = false;
    try {
        $stmtLockB = $pdoB->prepare("SELECT id, serial_number, status FROM product_units WHERE id = :uid AND status = 'available' FOR UPDATE");
        $stmtLockB->execute([':uid' => $unitTarget]);
    } catch (PDOException $eB) {
        $waitSec = round(microtime(true) - $tStartB, 3);
        $bBlockedAndTimedOut = (strpos($eB->getMessage(), '1205') !== false);
        $con01Logs[] = "[" . nowMs() . "] [Kết nối B | Conn #{$connIdB}] Bị chặn suốt {$waitSec}s bởi khóa của A và nhận lỗi: {$eB->getMessage()} -> ROLLBACK B";
        if ($pdoB->inTransaction()) {
            $pdoB->rollBack();
        }
    }

    $stmtUpdA = $pdoA->prepare("UPDATE product_units SET status = 'reserved' WHERE id = :uid AND status = 'available'");
    $stmtUpdA->execute([':uid' => $unitTarget]);
    $rowsA = $stmtUpdA->rowCount();
    $pdoA->commit();
    $con01Logs[] = "[" . nowMs() . "] [Kết nối A | Conn #{$connIdA}] UPDATE product_units SET status='reserved' thành công (rowCount = {$rowsA}) -> COMMIT A";

    // Khôi phục lại Unit #5 về available để chuẩn bị cho bài kiểm thử 2 tiến trình song song tiếp theo
    $pdo->prepare("UPDATE product_units SET status = 'available' WHERE id = :uid")->execute([':uid' => $unitTarget]);

    $con01Passed = ($connIdA !== $connIdB) && $bBlockedAndTimedOut && ($rowsA === 1);
} catch (Throwable $e) {
    if ($pdoA->inTransaction()) {
        $pdoA->rollBack();
    }
    if ($pdoB->inTransaction()) {
        $pdoB->rollBack();
    }
    $con01Logs[] = "Lỗi: " . $e->getMessage();
}

recordTest(
    $results,
    'TC-CON-01',
    '4. Concurrency (Đặt máy)',
    'Kiểm thử 2 kết nối chồng lấn (Kịch bản 1 - Lock Wait Timeout): B cố đặt máy trong khi A đang giữ khóa FOR UPDATE mở',
    $con01Passed ? 'PASS' : 'FAIL',
    implode("\n", $con01Logs)
);

// TC-CON-02: Kiểm thử 2 TIẾN TRÌNH PHP SONG SONG (proc_open) chạy chồng lấn thực sự
$phpBin     = PHP_BINARY;
$scriptPath = __FILE__;

$cmdProcA = sprintf('"%s" "%s" --worker-unit A 5 1200 0', $phpBin, $scriptPath);
$cmdProcB = sprintf('"%s" "%s" --worker-unit B 5 0 250', $phpBin, $scriptPath);

$desc = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$procA = proc_open($cmdProcA, $desc, $pipesA);
$procB = proc_open($cmdProcB, $desc, $pipesB);

$outA = stream_get_contents($pipesA[1]);
$outB = stream_get_contents($pipesB[1]);
foreach ($pipesA as $p) {
    fclose($p);
}
foreach ($pipesB as $p) {
    fclose($p);
}
proc_close($procA);
proc_close($procB);

$resA = json_decode((string) $outA, true) ?: [];
$resB = json_decode((string) $outB, true) ?: [];

$unit5StatusAfter = (string) $pdo->query("SELECT status FROM product_units WHERE id = 5")->fetchColumn();
$prod3StockAfter  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 3")->fetchColumn();

$con02Passed = (
    !empty($resA) && !empty($resB) &&
    $resA['conn_id'] !== $resB['conn_id'] &&
    $resA['success'] === true && $resA['affected_rows'] === 1 &&
    $resB['success'] === false && $resB['affected_rows'] === 0 &&
    $resB['wait_ms'] >= 600 &&
    $unit5StatusAfter === 'reserved' &&
    $prod3StockAfter === 0
);

$con02Detail = array_merge(
    ["--- Nhật ký Tiến trình A (Conn #{$resA['conn_id']}) ---"],
    $resA['logs'] ?? [],
    ["--- Nhật ký Tiến trình B (Conn #{$resB['conn_id']}) ---"],
    $resB['logs'] ?? [],
    ["=> Đối chiếu kết quả: A affected_rows={$resA['affected_rows']} (THÀNH CÔNG), B affected_rows={$resB['affected_rows']} (THẤT BẠI sau khi chờ khóa {$resB['wait_ms']} ms). Trạng thái Unit #5={$unit5StatusAfter}, Tồn kho Product #3={$prod3StockAfter}."]
);

recordTest(
    $results,
    'TC-CON-02',
    '4. Concurrency (Đặt máy)',
    'Kiểm thử 2 tiến trình song song chồng lấn (Kịch bản 2 - Chờ khóa & Thất bại sau khi A commit): Chỉ 1 giao dịch thành công',
    $con02Passed ? 'PASS' : 'FAIL',
    implode("\n", $con02Detail)
);

// ----------------------------------------------------------------------------
// NHÓM 5: KIỂM TRA TIỀN TỆ, SỐ LƯỢNG, PHÍ VẬN CHUYỂN ÂM & CHỐNG GIẢ MẠO GIÁ TỪ CLIENT
// ----------------------------------------------------------------------------

// TC-FIN-01: Kiểm tra quantity <= 0 bị chặn hoàn toàn
$zeroQtyRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-ZERO-QTY',
        'user_id'          => 2,
        'customer_name'    => 'Khách Đặt Số Lượng 0',
        'customer_email'   => 'zero@demo.vn',
        'customer_phone'   => '0912345678',
        'shipping_address' => 'Số 1 Giải Phóng',
    ],
    [
        ['product_id' => 14, 'product_unit_id' => null, 'quantity' => 0],
    ]
);
$negQtyRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-NEG-QTY',
        'user_id'          => 2,
        'customer_name'    => 'Khách Đặt Số Lượng Âm',
        'customer_email'   => 'neg@demo.vn',
        'customer_phone'   => '0912345678',
        'shipping_address' => 'Số 1 Giải Phóng',
    ],
    [
        ['product_id' => 14, 'product_unit_id' => null, 'quantity' => -3],
    ]
);
recordTest(
    $results,
    'TC-FIN-01',
    '5. Kiểm tra Tiền tệ',
    'Chặn đặt hàng với số lượng quantity <= 0 (quantity = 0 và quantity = -3)',
    ($zeroQtyRes['success'] === false && $negQtyRes['success'] === false) ? 'PASS' : 'FAIL',
    "Khi quantity=0: {$zeroQtyRes['error']} | Khi quantity=-3: {$negQtyRes['error']}"
);

// TC-FIN-02: Kiểm tra phí vận chuyển âm (shipping_fee < 0) bị từ chối và rollback
$ordersBeforeNegShip = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$negShipRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-NEG-SHIP-01',
        'user_id'          => 2,
        'customer_name'    => 'Khách Cố Ý Truyền Phí Ship Âm',
        'customer_email'   => 'negship@demo.vn',
        'customer_phone'   => '0912345678',
        'shipping_address' => 'Số 1 Giải Phóng',
        'shipping_fee'     => -50000,
    ],
    [
        ['product_id' => 14, 'product_unit_id' => null, 'quantity' => 1],
    ]
);
$ordersAfterNegShip = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$stock14AfterNegShip = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();

recordTest(
    $results,
    'TC-FIN-02',
    '5. Kiểm tra Tiền tệ',
    'Chặn phí vận chuyển âm (shipping_fee < 0) và đảm bảo Rollback không làm thay đổi tồn kho hay tạo đơn rác',
    ($negShipRes['success'] === false && $ordersBeforeNegShip === $ordersAfterNegShip && $stock14AfterNegShip === 10) ? 'PASS' : 'FAIL',
    "Kết quả khi truyền shipping_fee = -50000: Bị từ chối ({$negShipRes['error']}) | Số đơn hàng giữ nguyên: {$ordersAfterNegShip} | Tồn kho Product #14 giữ nguyên: {$stock14AfterNegShip}"
);

// TC-FIN-03: Không tin giá bán, tổng tiền hay giảm giá gửi từ trình duyệt (Tính toán 100% trên Server từ MySQL)
$tamperPriceRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'           => 'TLG-SERVER-PRICE-01',
        'user_id'              => 2,
        'customer_name'        => 'Khách Giả Mạo Giá Trình Duyệt',
        'customer_email'       => 'tamper@demo.vn',
        'customer_phone'       => '0912345678',
        'shipping_address'     => 'Số 1 Giải Phóng',
        'shipping_fee'         => 30000,
        // Cố tình truyền các trường giả mạo từ phía Client:
        'total_product_amount' => 1000,
        'discount_amount'      => 9999999,
        'final_amount'         => 1,
    ],
    [
        [
            'product_id'      => 14, // Giá thật trong MySQL: 450,000 VND
            'product_unit_id' => null,
            'quantity'        => 2,  // Thành tiền thật: 900,000 VND
            'price'           => 1,  // Client cố tình gửi giá 1 VND
            'subtotal'        => 2,
        ],
    ]
);
$savedOrder = $pdo->query("SELECT total_product_amount, shipping_fee, discount_amount, final_amount FROM orders WHERE id = " . (int) ($tamperPriceRes['order_id'] ?? 0))->fetch();
$savedItem  = $pdo->query("SELECT price, subtotal FROM order_items WHERE order_id = " . (int) ($tamperPriceRes['order_id'] ?? 0))->fetch();

$fin03Passed = (
    $tamperPriceRes['success'] === true &&
    (int) $savedItem['price'] === 450000 &&
    (int) $savedItem['subtotal'] === 900000 &&
    (int) $savedOrder['total_product_amount'] === 900000 &&
    (int) $savedOrder['discount_amount'] === 0 &&
    (int) $savedOrder['shipping_fee'] === 30000 &&
    (int) $savedOrder['final_amount'] === 930000
);
// Hủy và dọn đơn test giả mạo giá để trả lại 2 thanh RAM về kho
cancelOrderTransaction($pdo, (int) $tamperPriceRes['order_id'], 'Dọn đơn sau khi test chống giả mạo giá');

recordTest(
    $results,
    'TC-FIN-03',
    '5. Kiểm tra Tiền tệ',
    'Tính toán toàn bộ giá tiền trên Server từ MySQL (bỏ qua giá bán, giảm giá và tổng tiền giả mạo từ trình duyệt)',
    $fin03Passed ? 'PASS' : 'FAIL',
    "Client gửi price=1, discount=9999999, final=1 -> Server lưu chuẩn từ MySQL: item.price={$savedItem['price']}, total_product_amount={$savedOrder['total_product_amount']}, discount_amount={$savedOrder['discount_amount']}, shipping_fee={$savedOrder['shipping_fee']}, final_amount={$savedOrder['final_amount']}"
);

// ----------------------------------------------------------------------------
// NHÓM 6: BẢO MẬT THANH TOÁN, PHÂN QUYỀN DUYỆT TIỀN & PHÂN BIỆT HỦY ĐƠN / HOÀN TIỀN
// ----------------------------------------------------------------------------

// TC-PAY-01: Khách hàng cố ý truyền payment_status = 'completed' khi tạo đơn
$spoofPayRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-SPOOF-PAY-01',
        'user_id'          => 3,
        'customer_name'    => 'Khách Cố Ý Truyền payment_status = completed',
        'customer_email'   => 'spoofpay@demo.vn',
        'customer_phone'   => '0987654321',
        'shipping_address' => 'Số 88 Nguyễn Trãi',
        'payment_method'   => 'bank_transfer',
        'payment_status'   => 'completed', // Cố tình truyền trạng thái đã thanh toán từ client
    ],
    [
        ['product_id' => 14, 'product_unit_id' => null, 'quantity' => 1],
    ]
);
$spoofOrderId = (int) ($spoofPayRes['order_id'] ?? 0);
$actualPayStatus = (string) $pdo->query("SELECT status FROM payments WHERE order_id = {$spoofOrderId}")->fetchColumn();

recordTest(
    $results,
    'TC-PAY-01',
    '6. Bảo mật Thanh toán',
    'Chặn khách hàng tự truyền payment_status = completed khi đặt đơn (payments.status bắt buộc khởi tạo là pending)',
    ($spoofPayRes['success'] === true && $actualPayStatus === 'pending') ? 'PASS' : 'FAIL',
    "Client truyền payment_status='completed' -> Trạng thái lưu thực tế trong bảng payments: '{$actualPayStatus}'"
);

// TC-PAY-02: Kiểm tra chỉ Admin có phân quyền mới được chuyển payments.status sang 'completed'
// Thử dùng tài khoản Customer (user_id = 2, role = 'customer') để duyệt thanh toán -> phải bị từ chối!
$custConfirmRes = confirmPaymentByAdminTransaction($pdo, $spoofOrderId, 2, 'FAKE-TX-CUST');
$statusAfterCust = (string) $pdo->query("SELECT status FROM payments WHERE order_id = {$spoofOrderId}")->fetchColumn();

// Dùng tài khoản Admin (user_id = 1, role = 'admin') để duyệt thanh toán -> thành công!
$adminConfirmRes = confirmPaymentByAdminTransaction($pdo, $spoofOrderId, 1, 'VCB-ADMIN-VERIFIED-999');
$statusAfterAdmin = (string) $pdo->query("SELECT status FROM payments WHERE order_id = {$spoofOrderId}")->fetchColumn();

recordTest(
    $results,
    'TC-PAY-02',
    '6. Bảo mật Thanh toán',
    'Chỉ quy trình Admin có phân quyền (role = admin) mới được chuyển payments.status sang completed',
    ($custConfirmRes['success'] === false && $statusAfterCust === 'pending' && $adminConfirmRes['success'] === true && $statusAfterAdmin === 'completed') ? 'PASS' : 'FAIL',
    "Customer (ID=2) gọi duyệt: Bị từ chối ({$custConfirmRes['error']}), status='{$statusAfterCust}' | Admin (ID=1) gọi duyệt: Thành công, status='{$statusAfterAdmin}'"
);

// TC-PAY-03: Phân biệt Hủy đơn (cancelled) và Hoàn tiền (refunded), giữ lịch sử giao dịch khi hủy đơn đã thanh toán
$cancelPaidOrder = cancelOrderTransaction($pdo, $spoofOrderId, 'Khách hủy đơn sau khi đã chuyển khoản');
$payStatusAfterCancel = (string) $pdo->query("SELECT status FROM payments WHERE order_id = {$spoofOrderId}")->fetchColumn();

// Sau khi đơn đã hủy (orders.status = 'cancelled'), Admin thực hiện quy trình hoàn tiền riêng biệt
$refundByCustomer = refundPaymentByAdminTransaction($pdo, $spoofOrderId, 2, 'Khách tự bấm hoàn tiền');
$refundByAdmin    = refundPaymentByAdminTransaction($pdo, $spoofOrderId, 1, 'Đã chuyển khoản hoàn tiền qua VCB');
$payStatusAfterRefund = (string) $pdo->query("SELECT status FROM payments WHERE order_id = {$spoofOrderId}")->fetchColumn();

recordTest(
    $results,
    'TC-PAY-03',
    '6. Bảo mật Thanh toán',
    'Phân biệt Hủy đơn và Hoàn tiền: Hủy đơn đã trả tiền giữ nguyên payment=completed; chỉ Admin mới chuyển sang refunded',
    (
        $cancelPaidOrder['success'] === true &&
        $payStatusAfterCancel === 'completed' &&
        $refundByCustomer['success'] === false &&
        $refundByAdmin['success'] === true &&
        $payStatusAfterRefund === 'refunded'
    ) ? 'PASS' : 'FAIL',
    "Sau khi hủy đơn: payments.status='{$payStatusAfterCancel}' (bảo toàn lịch sử thu tiền) | Sau khi Admin thực hiện nghiệp vụ hoàn tiền: payments.status='{$payStatusAfterRefund}'"
);

// ----------------------------------------------------------------------------
// NHÓM 7: KIỂM THỬ VOUCHER TOÀN DIỆN (HẾT HẠN, CHƯA BẮT ĐẦU, PER_USER_LIMIT, VƯỢT GIÁ TRỊ ĐƠN & CONCURRENCY)
// ----------------------------------------------------------------------------

// TC-VOU-01 & TC-VOU-02: Tạo đơn có dùng voucher hợp lệ -> tăng usage_count & tạo coupon_usages(used); Hủy đơn -> giảm usage_count & chuyển coupon_usages(cancelled)
$coupon1Before = $pdo->query("SELECT usage_count, usage_limit FROM coupons WHERE id = 1")->fetch();
$vouOrder = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-VOU-SYNC-01',
        'user_id'          => 4,
        'customer_name'    => 'Phạm Minh Tuấn (Dùng Voucher TLGAMING500K)',
        'customer_email'   => 'tuan.pham@example.com',
        'customer_phone'   => '0978123403',
        'shipping_address' => 'Số 22 Láng Hạ, Hà Nội',
    ],
    [
        ['product_id' => 2, 'product_unit_id' => 3, 'quantity' => 1], // Giá 14,900,000 >= 10,000,000
    ],
    1 // Coupon ID=1 (TLGAMING500K)
);
$coupon1AfterOrder     = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = 1")->fetchColumn();
$usageStatusAfterOrder = (string) $pdo->query("SELECT status FROM coupon_usages WHERE order_id = " . (int) $vouOrder['order_id'])->fetchColumn();

$cancelVouOrder1        = cancelOrderTransaction($pdo, (int) $vouOrder['order_id'], 'Hủy đơn để kiểm tra hoàn lượt voucher');
$coupon1AfterCancel     = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = 1")->fetchColumn();
$usageStatusAfterCancel = (string) $pdo->query("SELECT status FROM coupon_usages WHERE order_id = " . (int) $vouOrder['order_id'])->fetchColumn();

$cancelVouOrder2           = cancelOrderTransaction($pdo, (int) $vouOrder['order_id'], 'Hủy lặp lần 2 đơn có voucher');
$coupon1AfterDupCancel     = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = 1")->fetchColumn();

recordTest(
    $results,
    'TC-VOU-01',
    '7. Quản lý Voucher',
    'Tạo đơn có áp dụng voucher hợp lệ: Tăng coupons.usage_count thêm 1 và lưu coupon_usages (status = used)',
    ($vouOrder['success'] === true && $coupon1AfterOrder === ((int) $coupon1Before['usage_count'] + 1) && $usageStatusAfterOrder === 'used') ? 'PASS' : 'FAIL',
    "usage_count trước khi đặt={$coupon1Before['usage_count']} -> sau khi đặt={$coupon1AfterOrder} | coupon_usages.status='{$usageStatusAfterOrder}'"
);

recordTest(
    $results,
    'TC-VOU-02',
    '7. Quản lý Voucher',
    'Hủy đơn có voucher: Giảm usage_count đi 1, cập nhật coupon_usages.status = cancelled và không trừ lặp khi hủy lần 2',
    (
        $cancelVouOrder1['success'] === true &&
        $cancelVouOrder1['voucher_restored'] === true &&
        $coupon1AfterCancel === (int) $coupon1Before['usage_count'] &&
        $usageStatusAfterCancel === 'cancelled' &&
        $cancelVouOrder2['success'] === false &&
        $coupon1AfterDupCancel === (int) $coupon1Before['usage_count']
    ) ? 'PASS' : 'FAIL',
    "Sau khi hủy lần 1: usage_count={$coupon1AfterCancel}, coupon_usages.status='{$usageStatusAfterCancel}' | Sau khi hủy lặp lần 2: usage_count giữ nguyên={$coupon1AfterDupCancel}"
);

// TC-VOU-03: Test voucher đã hết hạn (Coupon ID=3: EXPIRED2025, end_date = 2025-12-31 23:59:59)
$expiredVouRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-VOU-EXPIRED-01',
        'user_id'          => 4,
        'customer_name'    => 'Khách Dùng Voucher Hết Hạn',
        'customer_email'   => 'expired@demo.vn',
        'customer_phone'   => '0903123456',
        'shipping_address' => 'Số 12 Lê Lợi',
    ],
    [
        ['product_id' => 2, 'product_unit_id' => 3, 'quantity' => 1],
    ],
    3 // Coupon ID=3 (EXPIRED2025)
);
recordTest(
    $results,
    'TC-VOU-03',
    '7. Quản lý Voucher',
    'Chặn sử dụng voucher đã hết hạn (NOW() > end_date)',
    ($expiredVouRes['success'] === false && stripos($expiredVouRes['error'], 'hết hạn') !== false) ? 'PASS' : 'FAIL',
    "Kết quả áp mã EXPIRED2025: Bị từ chối ({$expiredVouRes['error']})"
);

// TC-VOU-04: Test voucher chưa đến thời gian bắt đầu (start_date > NOW())
$pdo->exec("
    INSERT INTO coupons (id, code, description, discount_type, discount_value, max_discount_amount, min_order_amount, usage_limit, usage_count, per_user_limit, start_date, end_date, is_active)
    VALUES (10, 'FUTURE2028', 'Voucher tương lai chưa mở', 'fixed_amount', 300000, NULL, 1000000, 50, 0, 1, DATE_ADD(NOW(), INTERVAL 30 DAY), DATE_ADD(NOW(), INTERVAL 60 DAY), 1)
");
$futureVouRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-VOU-FUTURE-01',
        'user_id'          => 4,
        'customer_name'    => 'Khách Dùng Voucher Chưa Mở',
        'customer_email'   => 'future@demo.vn',
        'customer_phone'   => '0903123456',
        'shipping_address' => 'Số 12 Lê Lợi',
    ],
    [
        ['product_id' => 2, 'product_unit_id' => 3, 'quantity' => 1],
    ],
    10 // Coupon ID=10 (FUTURE2028)
);
recordTest(
    $results,
    'TC-VOU-04',
    '7. Quản lý Voucher',
    'Chặn sử dụng voucher chưa đến thời điểm bắt đầu hiệu lực (NOW() < start_date)',
    ($futureVouRes['success'] === false && stripos($futureVouRes['error'], 'chưa đến thời gian') !== false) ? 'PASS' : 'FAIL',
    "Kết quả áp mã FUTURE2028: Bị từ chối ({$futureVouRes['error']})"
);

// TC-VOU-05: Test vượt giới hạn per_user_limit cho cả khách đăng nhập (user_id) và khách vãng lai (SĐT/Email)
// Trong seed_tl_gaming.sql: User ID=2 (Nguyễn Văn An, SĐT 0912345678, Email nguyen.van.an@demo.vn) đang có Đơn #1 dùng Coupon ID=1 (TLGAMING500K, per_user_limit=1, status='used')
$perUserMemberRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-VOU-PERUSER-MEM',
        'user_id'          => 2, // Đã dùng Coupon ID=1 ở Đơn #1
        'customer_name'    => 'Nguyễn Văn An (Dùng lại mã lần 2)',
        'customer_email'   => 'other.email@demo.vn',
        'customer_phone'   => '0999888777',
        'shipping_address' => 'Số 45 Giải Phóng',
    ],
    [
        ['product_id' => 2, 'product_unit_id' => 3, 'quantity' => 1],
    ],
    1 // Coupon ID=1 (per_user_limit = 1)
);

// Khách vãng lai (user_id = NULL) cố tình dùng lại SĐT 0912345678 đã từng dùng Coupon ID=1
$perUserGuestRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-VOU-PERUSER-GUEST',
        'user_id'          => null,
        'customer_name'    => 'Khách Vãng Lai Trùng SĐT',
        'customer_email'   => 'guest.new@demo.vn',
        'customer_phone'   => '0912345678', // Trùng SĐT trong coupon_usages (status='used')
        'shipping_address' => 'Số 45 Giải Phóng',
    ],
    [
        ['product_id' => 2, 'product_unit_id' => 3, 'quantity' => 1],
    ],
    1 // Coupon ID=1 (per_user_limit = 1)
);

recordTest(
    $results,
    'TC-VOU-05',
    '7. Quản lý Voucher',
    'Thực thi per_user_limit: Chặn khách đăng nhập (theo user_id) và khách vãng lai (theo SĐT/Email) dùng vượt số lần quy định',
    ($perUserMemberRes['success'] === false && $perUserGuestRes['success'] === false) ? 'PASS' : 'FAIL',
    "Khách đăng nhập (user_id=2) dùng lần 2: Bị chặn ({$perUserMemberRes['error']}) | Khách vãng lai trùng SĐT dùng lần 2: Bị chặn ({$perUserGuestRes['error']})"
);

// TC-VOU-06: Test mã giảm giá lớn hơn giá trị đơn hàng (discount_amount > total_product_amount)
$pdo->exec("
    INSERT INTO coupons (id, code, description, discount_type, discount_value, max_discount_amount, min_order_amount, usage_limit, usage_count, per_user_limit, start_date, end_date, is_active)
    VALUES (11, 'BIGDISC1M', 'Voucher giảm 1 triệu nhưng min_order_amount = 0', 'fixed_amount', 1000000, NULL, 0, 50, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 1)
");
// Đặt 1 thanh RAM giá 450,000 VND nhưng áp mã giảm 1,000,000 VND
$overDiscOrderRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-OVER-DISC-01',
        'user_id'          => 4,
        'customer_name'    => 'Khách Dùng Voucher Lớn Hơn Giá Trị Đơn',
        'customer_email'   => 'overdisc@demo.vn',
        'customer_phone'   => '0903123456',
        'shipping_address' => 'Số 12 Lê Lợi',
    ],
    [
        ['product_id' => 14, 'product_unit_id' => null, 'quantity' => 1], // total_product_amount = 450,000 < discount 1,000,000
    ],
    11 // Coupon ID=11 (BIGDISC1M)
);

// Kiểm tra thêm ở tầng SQL: Cố tình INSERT trực tiếp vào bảng orders với discount_amount > total_product_amount
$sqlOverDiscBlocked = false;
try {
    $pdo->exec("
        INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, total_product_amount, shipping_fee, discount_amount, final_amount, status)
        VALUES ('TLG-SQL-OVERDISC', 4, 'Test SQL Check', 'sql@demo.vn', '0900111222', 'Hà Nội', 'Hà Nội', 450000, 100000, 500000, 50000, 'pending')
    ");
} catch (PDOException $e) {
    $sqlOverDiscBlocked = true;
}

recordTest(
    $results,
    'TC-VOU-06',
    '7. Quản lý Voucher',
    'Chặn mã giảm giá lớn hơn giá trị đơn hàng (discount_amount > total_product_amount) ở cả tầng PHP và CHECK MySQL',
    ($overDiscOrderRes['success'] === false && $sqlOverDiscBlocked === true) ? 'PASS' : 'FAIL',
    "Tầng giao dịch PHP: Bị từ chối ({$overDiscOrderRes['error']}) | Tầng MySQL (CONSTRAINT chk_orders_discount): ĐÃ CHẶN"
);

// TC-VOU-07: Kiểm tra ràng buộc không cho usage_count âm (< 0) hoặc vượt quá usage_limit (> usage_limit)
$negCouponBlocked = false;
$overLimitBlocked = false;
try {
    $pdo->exec("UPDATE coupons SET usage_count = usage_count - 999 WHERE id = 1");
} catch (PDOException $e) {
    $negCouponBlocked = true;
}
try {
    $pdo->exec("UPDATE coupons SET usage_count = usage_limit + 1 WHERE id = 1");
} catch (PDOException $e) {
    $overLimitBlocked = true;
}
recordTest(
    $results,
    'TC-VOU-07',
    '7. Quản lý Voucher',
    'Chặn tuyệt đối usage_count âm (< 0) hoặc vượt quá usage_limit (chk_coupons_usage_bound)',
    ($negCouponBlocked && $overLimitBlocked) ? 'PASS' : 'FAIL',
    "Chặn usage_count < 0 (INT UNSIGNED): " . ($negCouponBlocked ? 'ĐÃ CHẶN' : 'LỌT') .
    " | Chặn usage_count > usage_limit (CHECK chk_coupons_usage_bound): " . ($overLimitBlocked ? 'ĐÃ CHẶN' : 'LỌT')
);

// TC-VOU-08: Kiểm thử Concurrency 2 khách hàng đồng thời dùng 1 voucher chỉ còn đúng 1 lượt cuối cùng
$pdo->exec("UPDATE coupons SET usage_limit = 5, usage_count = 4, is_active = 1 WHERE id = 1");

$cmdVouA = sprintf('"%s" "%s" --worker-voucher A 1 1000 0', $phpBin, $scriptPath);
$cmdVouB = sprintf('"%s" "%s" --worker-voucher B 1 0 250', $phpBin, $scriptPath);

$procVA = proc_open($cmdVouA, $desc, $pipesVA);
$procVB = proc_open($cmdVouB, $desc, $pipesVB);

$outVA = stream_get_contents($pipesVA[1]);
$outVB = stream_get_contents($pipesVB[1]);
foreach ($pipesVA as $p) {
    fclose($p);
}
foreach ($pipesVB as $p) {
    fclose($p);
}
proc_close($procVA);
proc_close($procVB);

$resVA = json_decode((string) $outVA, true) ?: [];
$resVB = json_decode((string) $outVB, true) ?: [];

$coupon1Final = $pdo->query("SELECT usage_count, usage_limit FROM coupons WHERE id = 1")->fetch();

$vou08Passed = (
    !empty($resVA) && !empty($resVB) &&
    $resVA['conn_id'] !== $resVB['conn_id'] &&
    $resVA['success'] === true && $resVA['affected_rows'] === 1 &&
    $resVB['success'] === false && $resVB['affected_rows'] === 0 &&
    $resVB['wait_ms'] >= 500 &&
    (int) $coupon1Final['usage_count'] === 5 &&
    (int) $coupon1Final['usage_limit'] === 5
);

$vou08Detail = array_merge(
    ["--- Nhật ký Tiến trình Săn Voucher A (Conn #{$resVA['conn_id']}) ---"],
    $resVA['logs'] ?? [],
    ["--- Nhật ký Tiến trình Săn Voucher B (Conn #{$resVB['conn_id']}) ---"],
    $resVB['logs'] ?? [],
    ["=> Đối chiếu kết quả: A affected_rows={$resVA['affected_rows']} (THÀNH CÔNG), B affected_rows={$resVB['affected_rows']} (THẤT BẠI sau khi chờ khóa {$resVB['wait_ms']} ms). Coupon usage_count={$coupon1Final['usage_count']}/{$coupon1Final['usage_limit']}."]
);

recordTest(
    $results,
    'TC-VOU-08',
    '7. Quản lý Voucher',
    'Concurrency tranh lượt voucher cuối cùng (usage_count = 4/5): 2 tiến trình chồng lấn, chỉ đúng 1 khách áp mã thành công',
    $vou08Passed ? 'PASS' : 'FAIL',
    implode("\n", $vou08Detail)
);

// Khôi phục lại usage_limit = 100 cho Coupon ID=1 để phục vụ các bài kiểm thử hủy đơn tiếp theo
$pdo->exec("UPDATE coupons SET usage_limit = 100 WHERE id = 1");

// ----------------------------------------------------------------------------
// NHÓM 8: KIỂM THỬ HỦY ĐƠN HÀNG, ROLLBACK KHI LỖI HOÀN SERIAL / VOUCHER & AN TOÀN CSDL
// ----------------------------------------------------------------------------

// TC-CAN-01: Hủy đơn hàng chứa sản phẩm có serial (has_serials = 1) - Đơn vừa tạo từ Tiến trình A ở TC-CON-02
$orderIdSerial          = (int) ($resA['order_id'] ?? 0);
$stockBeforeCancelSerial = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 3")->fetchColumn();
$unitBeforeCancelSerial  = (string) $pdo->query("SELECT status FROM product_units WHERE id = 5")->fetchColumn();

$cancelSerialRes1 = cancelOrderTransaction($pdo, $orderIdSerial, 'Khách hàng đổi ý hủy đơn máy có serial');

$stockAfterCancelSerial = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 3")->fetchColumn();
$unitAfterCancelSerial  = (string) $pdo->query("SELECT status FROM product_units WHERE id = 5")->fetchColumn();
$orderStatusAfterSerial = (string) $pdo->query("SELECT status FROM orders WHERE id = {$orderIdSerial}")->fetchColumn();

recordTest(
    $results,
    'TC-CAN-01',
    '8. Hủy đơn & Rollback',
    'Hủy đơn sản phẩm có serial (has_serials = 1): Hoàn đúng product_unit về available và cộng 1 tồn kho',
    (
        $cancelSerialRes1['success'] === true &&
        $stockBeforeCancelSerial === 0 && $stockAfterCancelSerial === 1 &&
        $unitBeforeCancelSerial === 'reserved' && $unitAfterCancelSerial === 'available' &&
        $orderStatusAfterSerial === 'cancelled'
    ) ? 'PASS' : 'FAIL',
    "Trước khi hủy: Unit #5={$unitBeforeCancelSerial}, Stock Product #3={$stockBeforeCancelSerial} | Sau khi hủy: Unit #5={$unitAfterCancelSerial}, Stock Product #3={$stockAfterCancelSerial}, Order Status={$orderStatusAfterSerial}"
);

// TC-CAN-02: Đặt đơn và hủy đơn sản phẩm KHÔNG có serial (has_serials = 0, Product ID=14, quantity = 3)
$stockNonSerialInit = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();
$orderNonSerial = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-NONSERIAL-01',
        'user_id'          => 3,
        'customer_name'    => 'Trần Thị Mai (Mua 3 thanh RAM không serial)',
        'customer_email'   => 'mai.tran@example.com',
        'customer_phone'   => '0934567802',
        'shipping_address' => 'Số 108 Cầu Giấy, Hà Nội',
    ],
    [
        ['product_id' => 14, 'product_unit_id' => null, 'quantity' => 3],
    ]
);
$stockNonSerialAfterOrder  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();
$cancelNonSerialRes1       = cancelOrderTransaction($pdo, (int) $orderNonSerial['order_id'], 'Khách đổi sang mua kit RAM 16GB');
$stockNonSerialAfterCancel = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();

recordTest(
    $results,
    'TC-CAN-02',
    '8. Hủy đơn & Rollback',
    'Đặt và hủy đơn sản phẩm không có serial (has_serials = 0, quantity = 3): Trừ và hoàn đúng số lượng tồn kho',
    (
        $orderNonSerial['success'] === true &&
        $cancelNonSerialRes1['success'] === true &&
        $stockNonSerialInit === 10 &&
        $stockNonSerialAfterOrder === 7 &&
        $stockNonSerialAfterCancel === 10
    ) ? 'PASS' : 'FAIL',
    "Tồn kho Product #14 (RAM Kingston): Ban đầu={$stockNonSerialInit} -> Sau khi đặt 3 chiếc={$stockNonSerialAfterOrder} -> Sau khi hủy đơn={$stockNonSerialAfterCancel}"
);

// TC-CAN-03: Hủy đơn lặp lại nhiều lần (Idempotency) đối với cả đơn có serial và đơn không có serial
$cancelSerialRes2    = cancelOrderTransaction($pdo, $orderIdSerial, 'Gọi hủy lặp lần 2 đơn có serial');
$cancelNonSerialRes2 = cancelOrderTransaction($pdo, (int) $orderNonSerial['order_id'], 'Gọi hủy lặp lần 2 đơn không serial');

$stockProd3AfterDup  = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 3")->fetchColumn();
$stockProd14AfterDup = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();

recordTest(
    $results,
    'TC-CAN-03',
    '8. Hủy đơn & Rollback',
    'Kiểm thử hủy đơn lặp lại lần 2 (Idempotency): Từ chối hủy lặp và tuyệt đối không hoàn kho lần 2',
    (
        $cancelSerialRes2['success'] === false &&
        $cancelNonSerialRes2['success'] === false &&
        $stockProd3AfterDup === 1 &&
        $stockProd14AfterDup === 10
    ) ? 'PASS' : 'FAIL',
    "Hủy lặp đơn có serial: success=false (reason={$cancelSerialRes2['reason']}), Stock Product #3 giữ nguyên={$stockProd3AfterDup} | " .
    "Hủy lặp đơn không serial: success=false (reason={$cancelNonSerialRes2['reason']}), Stock Product #14 giữ nguyên={$stockProd14AfterDup}"
);

// TC-CAN-04: Chống âm tồn kho (stock_quantity không bao giờ < 0) ở cả tầng giao dịch và tầng MySQL
$overOrderRes = placeOrderTransaction(
    $pdo,
    [
        'order_code'       => 'TLG-OVERSTOCK-01',
        'user_id'          => 2,
        'customer_name'    => 'Khách Đặt Vượt Tồn Kho',
        'customer_email'   => 'overstock@demo.vn',
        'customer_phone'   => '0912345601',
        'shipping_address' => 'Hà Nội',
    ],
    [
        ['product_id' => 14, 'product_unit_id' => null, 'quantity' => 15],
    ]
);
$sqlNegativeBlocked = false;
try {
    $pdo->exec("UPDATE products SET stock_quantity = stock_quantity - 999 WHERE id = 14");
} catch (PDOException $e) {
    $sqlNegativeBlocked = true;
}
$stockProd14Final = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 14")->fetchColumn();

recordTest(
    $results,
    'TC-CAN-04',
    '8. Hủy đơn & Rollback',
    'Chống âm tồn kho (stock_quantity >= 0): Từ chối đơn đặt vượt tồn kho và chặn UPDATE âm ở cấp MySQL',
    ($overOrderRes['success'] === false && $sqlNegativeBlocked && $stockProd14Final === 10) ? 'PASS' : 'FAIL',
    "Đặt 15 chiếc khi kho có 10: bị từ chối ({$overOrderRes['error']}) | Chặn trừ âm trực tiếp cấp SQL (INT UNSIGNED): ĐÃ CHẶN | Tồn kho giữ nguyên: {$stockProd14Final}"
);

// TC-CAN-05: Test thất bại khi hoàn Serial (máy không ở trạng thái 'reserved') -> Bắt buộc ROLLBACK toàn bộ transaction
// Đơn hàng #1 trong seed (status = 'pending') gồm Unit #1 (Product #1) và Coupon #1.
// Giả lập sự cố: Unit #1 đang bị chuyển sang trạng thái 'repairing' (không phải 'reserved').
$pdo->exec("UPDATE product_units SET status = 'repairing' WHERE id = 1");
$order1Before   = $pdo->query("SELECT status FROM orders WHERE id = 1")->fetchColumn();
$stock1Before   = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 1")->fetchColumn();
$coupon1BeforeR = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = 1")->fetchColumn();
$usage1BeforeR  = (string) $pdo->query("SELECT status FROM coupon_usages WHERE order_id = 1")->fetchColumn();

$failSerialCancel = cancelOrderTransaction($pdo, 1, 'Thử hủy đơn khi máy không ở trạng thái reserved');

$order1After   = $pdo->query("SELECT status FROM orders WHERE id = 1")->fetchColumn();
$stock1After   = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 1")->fetchColumn();
$coupon1AfterR = (int) $pdo->query("SELECT usage_count FROM coupons WHERE id = 1")->fetchColumn();
$usage1AfterR  = (string) $pdo->query("SELECT status FROM coupon_usages WHERE order_id = 1")->fetchColumn();

// Khôi phục lại Unit #1 về 'reserved'
$pdo->exec("UPDATE product_units SET status = 'reserved' WHERE id = 1");

recordTest(
    $results,
    'TC-CAN-05',
    '8. Hủy đơn & Rollback',
    'Test thất bại khi hoàn Serial (máy không ở trạng thái reserved): Rollback toàn bộ transaction, không làm lệch dữ liệu',
    (
        $failSerialCancel['success'] === false &&
        $failSerialCancel['reason'] === 'ROLLBACK_ON_FAILURE' &&
        $order1Before === 'pending' && $order1After === 'pending' &&
        $stock1Before === $stock1After &&
        $coupon1BeforeR === $coupon1AfterR &&
        $usage1BeforeR === 'used' && $usage1AfterR === 'used'
    ) ? 'PASS' : 'FAIL',
    "Lỗi trả về: {$failSerialCancel['error']} | Sau khi Rollback: orders.status='{$order1After}', products.stock_quantity={$stock1After}, coupons.usage_count={$coupon1AfterR}, coupon_usages.status='{$usage1AfterR}' (Bảo toàn 100%)"
);

// TC-CAN-06: Test thất bại khi hoàn lượt Voucher -> Bắt buộc ROLLBACK toàn bộ transaction (kể cả máy serial đã hoàn ở bước trước trong cùng transaction)
// Giả lập sự cố: Đơn #1 có coupon_id = 1, nhưng bản ghi coupon_usages của Đơn #1 đã bị lệch sang 'cancelled' (hoặc usage_count = 0).
$pdo->exec("UPDATE coupon_usages SET status = 'cancelled' WHERE order_id = 1");
$unit1BeforeV  = (string) $pdo->query("SELECT status FROM product_units WHERE id = 1")->fetchColumn();
$stock1BeforeV = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 1")->fetchColumn();
$order1BeforeV = (string) $pdo->query("SELECT status FROM orders WHERE id = 1")->fetchColumn();

$failVoucherCancel = cancelOrderTransaction($pdo, 1, 'Thử hủy đơn khi hoàn voucher gặp lỗi');

$unit1AfterV  = (string) $pdo->query("SELECT status FROM product_units WHERE id = 1")->fetchColumn();
$stock1AfterV = (int) $pdo->query("SELECT stock_quantity FROM products WHERE id = 1")->fetchColumn();
$order1AfterV = (string) $pdo->query("SELECT status FROM orders WHERE id = 1")->fetchColumn();

// Khôi phục lại coupon_usages của Đơn #1 về 'used'
$pdo->exec("UPDATE coupon_usages SET status = 'used' WHERE order_id = 1");

recordTest(
    $results,
    'TC-CAN-06',
    '8. Hủy đơn & Rollback',
    'Test thất bại khi hoàn lượt Voucher: Rollback toàn bộ transaction (hoàn nguyên cả trạng thái đơn, máy serial và tồn kho)',
    (
        $failVoucherCancel['success'] === false &&
        $failVoucherCancel['reason'] === 'ROLLBACK_ON_FAILURE' &&
        $order1BeforeV === 'pending' && $order1AfterV === 'pending' &&
        $unit1BeforeV === 'reserved' && $unit1AfterV === 'reserved' &&
        $stock1BeforeV === $stock1AfterV
    ) ? 'PASS' : 'FAIL',
    "Lỗi trả về: {$failVoucherCancel['error']} | Sau khi Rollback: orders.status='{$order1AfterV}', Unit #1 status='{$unit1AfterV}', Stock Product #1={$stock1AfterV} (Không bị hoàn kho một nửa!)"
);

// ----------------------------------------------------------------------------
// NHÓM 9: KIỂM TRA BẢO TOÀN CSDL CHÍNH (tl_gaming)
// ----------------------------------------------------------------------------
$prodAfter     = snapshotDatabaseCounts($pdoServer, $prodDbName);
$prodUnchanged = ($prodBefore === $prodAfter);
recordTest(
    $results,
    'TC-SAFE-01',
    '9. An toàn CSDL chính',
    'Đảm bảo toàn bộ quá trình kiểm thử chỉ chạy trên tl_gaming_audit_test, không làm thay đổi dữ liệu trong tl_gaming',
    $prodUnchanged ? 'PASS' : 'FAIL',
    "Trạng thái bảng & số dòng trên `{$prodDbName}` trước và sau khi test khớp nhau 100%: " . json_encode($prodAfter)
);

// Khôi phục lại dữ liệu chuẩn trên tl_gaming_audit_test sau khi kết thúc để giữ môi trường test sạch sẽ
resetTestDatabase($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

// Tổng kết kết quả
$passCount   = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$failCount   = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
$notRunCount = count(array_filter($results, fn($r) => $r['status'] === 'NOT RUN'));

echo "================================================================================\n";
echo "  TỔNG KẾT KẾT QUẢ KIỂM THỬ: PASS = {$passCount} | FAIL = {$failCount} | NOT RUN = {$notRunCount}\n";
echo "================================================================================\n";

// Đồng bộ kết quả thực thi vào file audit_results.json
file_put_contents(
    __DIR__ . '/audit_results.json',
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
);

exit($failCount > 0 ? 1 : 0);
