-- ============================================================================
-- Migration: 20261121_orders_delivery_method.sql
-- Mục đích (Prompt 19.1 - Nhiệm vụ 2):
--   - Bổ sung cột `delivery_method` (`delivery` | `pickup` | NULL cho đơn cũ)
--     vào bảng `orders` để xác định chính xác hình thức nhận hàng thay vì
--     phụ thuộc duy nhất vào chuỗi `shipping_address`.
--   - KHÔNG sửa dữ liệu snapshot `shipping_address` của các đơn cũ; giữ nguyên
--     `delivery_method = NULL` đối với đơn cũ để Admin hiển thị tương thích an toàn.
--   - Tương thích 100% MySQL 8.x và MariaDB 10.x/11.x, không DROP bảng, idempotent.
--
-- BACKUP TRƯỚC KHI CHẠY:
--   E:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines --triggers tl_gaming > backup_before_prompt19_1.sql
-- CHẠY MIGRATION:
--   E:\xampp\mysql\bin\mysql.exe -u root tl_gaming < database/migrations/20261121_orders_delivery_method.sql
-- ============================================================================

SET @col_delivery_method := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'delivery_method'
);
SET @sql_delivery_method := IF(
    @col_delivery_method = 0,
    'ALTER TABLE `orders` ADD COLUMN `delivery_method` ENUM(''delivery'', ''pickup'') DEFAULT NULL COMMENT ''Hình thức nhận hàng (delivery: giao tận nơi, pickup: nhận tại showroom, NULL: đơn cũ chưa lưu cột này)'' AFTER `shipping_district`',
    'SELECT ''[SKIP] Cột orders.delivery_method đã tồn tại'' AS migration_status'
);
PREPARE stmt_delivery_method FROM @sql_delivery_method;
EXECUTE stmt_delivery_method;
DEALLOCATE PREPARE stmt_delivery_method;
