-- ============================================================================
-- Migration: 20261114_product_units_integrity_and_row_version.sql
-- Đối tượng áp dụng:
--   - Chỉ áp dụng cho CSDL `tl_gaming` CŨ đã khởi tạo trước Prompt 18.1.
--   - Nếu cài mới hoàn toàn từ `database/tl_gaming.sql`, KHÔNG cần chạy tệp này.
--   - Tương thích 100% cả MySQL 8.x lẫn MariaDB 10.x/11.x (dùng INFORMATION_SCHEMA
--     + PREPARE/EXECUTE, không dùng cú pháp `ADD COLUMN IF NOT EXISTS` riêng của MariaDB).
--   - KHÔNG DROP/RECREATE bảng, KHÔNG làm mất dữ liệu hiện có, chạy lặp lại nhiều lần an toàn.
--
-- Mục đích:
--   1. Thêm `row_version` (INT UNSIGNED NOT NULL DEFAULT 1) vào `product_units`
--      để thực thi khóa lạc quan (optimistic locking) chính xác tuyệt đối.
--   2. Thêm `returned_at` và `returned_baseline_inspection_id` vào `product_units`
--      để xác định mốc hoàn trả của máy, ngăn dùng biên bản kiểm định cũ (trước
--      khi trả hàng) để mở bán lại máy `returned`.
--
-- 1) BACKUP TRƯỚC KHI CHẠY (BẮT BUỘC):
--    E:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines --triggers tl_gaming > backup_tl_gaming_before_18_2.sql
--
-- 2) CHẠY MIGRATION (chọn đúng tên DB ở dòng lệnh, không hardcode `USE`):
--    E:\xampp\mysql\bin\mysql.exe -u root tl_gaming < database/migrations/20261114_product_units_integrity_and_row_version.sql
--
-- 3) KIỂM TRA SAU MIGRATION:
--    E:\xampp\mysql\bin\mysql.exe -u root tl_gaming -e "SHOW COLUMNS FROM product_units WHERE Field IN ('row_version', 'returned_at', 'returned_baseline_inspection_id');"
--    E:\xampp\php\php.exe scripts/sync_serial_stock_cache.php --dry-run
--    (Nếu phát hiện lệch tồn kho cache ở sản phẩm has_serials = 1 thì chạy thêm --apply)
-- ============================================================================

-- 1. Thêm cột `row_version` nếu chưa tồn tại
SET @col_exists_row_version := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_units'
      AND COLUMN_NAME = 'row_version'
);
SET @sql_row_version := IF(
    @col_exists_row_version = 0,
    'ALTER TABLE `product_units` ADD COLUMN `row_version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''Phiên bản khóa lạc quan tăng dần mỗi lần cập nhật'' AFTER `import_price`',
    'SELECT ''[SKIP] Cột product_units.row_version đã tồn tại'' AS migration_status'
);
PREPARE stmt_row_version FROM @sql_row_version;
EXECUTE stmt_row_version;
DEALLOCATE PREPARE stmt_row_version;

-- 2. Thêm cột `returned_at` nếu chưa tồn tại
SET @col_exists_returned_at := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_units'
      AND COLUMN_NAME = 'returned_at'
);
SET @sql_returned_at := IF(
    @col_exists_returned_at = 0,
    'ALTER TABLE `product_units` ADD COLUMN `returned_at` DATETIME DEFAULT NULL COMMENT ''Thời điểm gần nhất máy chuyển sang trạng thái returned'' AFTER `imported_at`',
    'SELECT ''[SKIP] Cột product_units.returned_at đã tồn tại'' AS migration_status'
);
PREPARE stmt_returned_at FROM @sql_returned_at;
EXECUTE stmt_returned_at;
DEALLOCATE PREPARE stmt_returned_at;

-- 3. Thêm cột `returned_baseline_inspection_id` nếu chưa tồn tại
SET @col_exists_returned_baseline := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_units'
      AND COLUMN_NAME = 'returned_baseline_inspection_id'
);
SET @sql_returned_baseline := IF(
    @col_exists_returned_baseline = 0,
    'ALTER TABLE `product_units` ADD COLUMN `returned_baseline_inspection_id` INT UNSIGNED DEFAULT NULL COMMENT ''ID biên bản kiểm định cao nhất tại thời điểm máy chuyển sang returned'' AFTER `returned_at`',
    'SELECT ''[SKIP] Cột product_units.returned_baseline_inspection_id đã tồn tại'' AS migration_status'
);
PREPARE stmt_returned_baseline FROM @sql_returned_baseline;
EXECUTE stmt_returned_baseline;
DEALLOCATE PREPARE stmt_returned_baseline;
