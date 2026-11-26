-- ============================================================================
-- Migration: 20261114_admin_orders_audit_and_refund_flag.sql
-- Mục đích (Prompt 19 - Quản lý đơn hàng Admin):
--   1. Thêm cột `refund_status`, `refund_note`, `refund_requested_at` vào `payments`
--      để đánh dấu "cần xử lý hoàn tiền thủ công" khi hủy đơn đã thanh toán (>0đ)
--      mà KHÔNG tự ý giả lập `payments.status = 'refunded'`.
--   2. Tạo bảng `order_status_logs` lưu dấu vết thao tác Admin (xác nhận, duyệt
--      thanh toán, giao hàng, hoàn tất, hủy đơn) với FK tới `orders` và `users`.
--   - Tương thích 100% MySQL 8.x và MariaDB 10.x/11.x, không DROP bảng, idempotent.
--
-- BACKUP TRƯỚC KHI CHẠY:
--   E:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines --triggers tl_gaming > backup_before_prompt19.sql
-- CHẠY MIGRATION:
--   E:\xampp\mysql\bin\mysql.exe -u root tl_gaming < database/migrations/20261114_admin_orders_audit_and_refund_flag.sql
-- ============================================================================

-- 1. Thêm `refund_status` vào `payments` nếu chưa tồn tại
SET @col_refund_status := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'refund_status'
);
SET @sql_refund_status := IF(
    @col_refund_status = 0,
    'ALTER TABLE `payments` ADD COLUMN `refund_status` ENUM(''none'', ''manual_required'', ''refunded'') NOT NULL DEFAULT ''none'' COMMENT ''Trạng thái xử lý hoàn tiền thủ công khi hủy đơn đã thanh toán'' AFTER `status`',
    'SELECT ''[SKIP] Cột payments.refund_status đã tồn tại'' AS migration_status'
);
PREPARE stmt_refund_status FROM @sql_refund_status;
EXECUTE stmt_refund_status;
DEALLOCATE PREPARE stmt_refund_status;

-- 2. Thêm `refund_note` vào `payments` nếu chưa tồn tại
SET @col_refund_note := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'refund_note'
);
SET @sql_refund_note := IF(
    @col_refund_note = 0,
    'ALTER TABLE `payments` ADD COLUMN `refund_note` VARCHAR(255) DEFAULT NULL COMMENT ''Ghi chú yêu cầu hoàn tiền thủ công'' AFTER `refund_status`',
    'SELECT ''[SKIP] Cột payments.refund_note đã tồn tại'' AS migration_status'
);
PREPARE stmt_refund_note FROM @sql_refund_note;
EXECUTE stmt_refund_note;
DEALLOCATE PREPARE stmt_refund_note;

-- 3. Thêm `refund_requested_at` vào `payments` nếu chưa tồn tại
SET @col_refund_req := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'refund_requested_at'
);
SET @sql_refund_req := IF(
    @col_refund_req = 0,
    'ALTER TABLE `payments` ADD COLUMN `refund_requested_at` DATETIME DEFAULT NULL COMMENT ''Thời điểm phát sinh yêu cầu hoàn tiền thủ công'' AFTER `refund_note`',
    'SELECT ''[SKIP] Cột payments.refund_requested_at đã tồn tại'' AS migration_status'
);
PREPARE stmt_refund_req FROM @sql_refund_req;
EXECUTE stmt_refund_req;
DEALLOCATE PREPARE stmt_refund_req;

-- 4. Tạo bảng audit `order_status_logs` nếu chưa tồn tại
CREATE TABLE IF NOT EXISTS `order_status_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính nhật ký thao tác đơn hàng',
    `order_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến đơn hàng',
    `action_type` ENUM('confirm', 'confirm_payment', 'ship', 'complete', 'cancel', 'mark_refunded') NOT NULL COMMENT 'Loại thao tác nghiệp vụ Admin',
    `old_status` VARCHAR(30) DEFAULT NULL COMMENT 'Trạng thái đơn hoặc thanh toán trước thao tác',
    `new_status` VARCHAR(30) DEFAULT NULL COMMENT 'Trạng thái đơn hoặc thanh toán sau thao tác',
    `actor_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'ID Admin thực hiện thao tác',
    `reason` VARCHAR(255) DEFAULT NULL COMMENT 'Lý do (bắt buộc khi hủy đơn)',
    `note` VARCHAR(500) DEFAULT NULL COMMENT 'Ghi chú bổ sung (mã giao dịch, tóm tắt hoàn tồn/voucher)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm ghi nhận thao tác',
    PRIMARY KEY (`id`),
    INDEX `idx_order_logs_order_created` (`order_id`, `created_at`),
    INDEX `idx_order_logs_actor` (`actor_user_id`),
    INDEX `idx_order_logs_action` (`action_type`),
    CONSTRAINT `fk_order_logs_order` FOREIGN KEY (`order_id`)
        REFERENCES `orders` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_order_logs_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng audit lịch sử xử lý đơn hàng và thanh toán bởi Admin';
