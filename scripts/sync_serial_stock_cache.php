<?php
/**
 * Script đối soát và đồng bộ tồn kho cache (`products.stock_quantity`) cho các sản phẩm có serial (`has_serials = 1`).
 *
 * Nguyên tắc an toàn:
 * - Mặc định chạy chế độ `--dry-run` (CHỈ kiểm tra và báo cáo chênh lệch, KHÔNG ghi vào DB).
 * - Chỉ ghi DB khi truyền rõ cờ `--apply`.
 * - Chỉ tính các serial đạt chuẩn bán qua `ProductUnitModel::sellableUnitWhereSql()`.
 * - Không bao giờ động vào sản phẩm `has_serials = 0` và không tạo kiểm định giả.
 * - Có thể truyền `--db=tl_gaming_test` để chạy trên DB kiểm thử thay vì DB cấu hình trong `.env`.
 *
 * Ví dụ:
 *   E:\xampp\php\php.exe scripts/sync_serial_stock_cache.php --dry-run
 *   E:\xampp\php\php.exe scripts/sync_serial_stock_cache.php --apply
 *   E:\xampp\php\php.exe scripts/sync_serial_stock_cache.php --db=tl_gaming_test --apply
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Core/CheckoutException.php';
require_once __DIR__ . '/../app/Models/InspectionModel.php';
require_once __DIR__ . '/../app/Models/ProductUnitModel.php';

use App\Models\ProductUnitModel;

$args  = $argv ?? [];
$apply = in_array('--apply', $args, true);

if ($apply && in_array('--dry-run', $args, true)) {
    fwrite(STDERR, "Lỗi: Không thể truyền đồng thời --dry-run và --apply.\n");
    exit(1);
}

// Cho phép ghi đè tên DB qua --db=<name> (ví dụ --db=tl_gaming_test)
foreach ($args as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $overrideDb = trim(substr($arg, 5));
        if ($overrideDb !== '') {
            putenv("DB_DATABASE={$overrideDb}");
            $_ENV['DB_DATABASE']    = $overrideDb;
            $_SERVER['DB_DATABASE'] = $overrideDb;
        }
    }
}

$targetDb = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'tl_gaming');
$modeText = $apply ? 'APPLY (CÓ GHI DB)' : 'DRY-RUN (CHỈ KIỂM TRA, KHÔNG GHI DB)';

echo "=== ĐỐI SOÁT TỒN KHO CACHE SERIAL (has_serials = 1) ===\n";
echo "CSDL mục tiêu : {$targetDb}\n";
echo "Chế độ chạy   : {$modeText}\n\n";

$unitModel = new ProductUnitModel();
$rows      = $unitModel->reconcileAllSerialStockCache($apply);

if ($rows === []) {
    echo "Không có sản phẩm nào có has_serials = 1 trong CSDL.\n";
    exit(0);
}

$mismatchCount = 0;
$updatedCount  = 0;

foreach ($rows as $row) {
    if (!empty($row['changed'])) {
        $mismatchCount++;
    }
    if (!empty($row['updated'])) {
        $updatedCount++;
    }

    $flag = !empty($row['updated'])
        ? 'UPDATED'
        : (!empty($row['changed']) ? 'MISMATCH' : 'OK');

    echo sprintf(
        "[%-8s] #%-4d | %-18s | cache=%-3d | sellable=%-3d | %s\n",
        $flag,
        (int) $row['product_id'],
        (string) $row['sku'],
        (int) $row['old_stock'],
        (int) $row['actual_sellable'],
        (string) $row['name']
    );
}

echo "\n--- TỔNG KẾT ---\n";
echo 'Tổng sản phẩm has_serials = 1 đã kiểm tra : ' . count($rows) . "\n";
echo "Số sản phẩm lệch cache                    : {$mismatchCount}\n";
echo "Số sản phẩm đã cập nhật                   : {$updatedCount}\n";
if (!$apply && $mismatchCount > 0) {
    echo "Gợi ý: Chạy lại với cờ --apply để đồng bộ số lượng bán được thực tế.\n";
}
exit(0);
