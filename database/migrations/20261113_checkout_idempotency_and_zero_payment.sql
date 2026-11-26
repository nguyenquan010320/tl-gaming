-- ============================================================================
-- Migration: 20261113_checkout_idempotency_and_zero_payment.sql
-- Đối tượng áp dụng:
--   - Chỉ áp dụng cho CSDL `tl_gaming` CŨ đã khởi tạo trước Prompt 15.1.
--   - Nếu cài mới hoàn toàn từ `database/tl_gaming.sql` (16 bảng), KHÔNG cần chạy tệp này.
-- Mục đích:
--   1. Cho phép đơn hàng 0đ trong bảng `payments` khi voucher hợp lệ giảm 100%
--      tiền hàng và phí vận chuyển = 0đ (`CHECK (amount >= 0)`).
--   2. Tạo bảng `checkout_idempotency` lưu trữ khóa chống gửi trùng đơn hàng
--      bền vững trong MySQL (không phụ thuộc hoàn toàn vào Session/File),
--      gắn với tác nhân đặt hàng, hash giỏ hàng/payload và đơn hàng đã tạo.
-- Cách chạy trên XAMPP:
--   E:\xampp\mysql\bin\mysql.exe -u root tl_gaming < database/migrations/20261113_checkout_idempotency_and_zero_payment.sql
-- Lưu ý: Migration không phá hủy dữ liệu hiện có (Non-destructive).
-- ============================================================================

USE `tl_gaming`;

-- 1. Cập nhật ràng buộc số tiền thanh toán cho phép >= 0đ
ALTER TABLE `payments`
    DROP CONSTRAINT IF EXISTS `chk_payments_amount`;

ALTER TABLE `payments`
    ADD CONSTRAINT `chk_payments_amount` CHECK (`amount` >= 0);

-- 2. Tạo bảng `checkout_idempotency` lưu khóa chống đặt đơn trùng bền vững
CREATE TABLE IF NOT EXISTS `checkout_idempotency` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `idempotency_key` VARCHAR(64) NOT NULL,
    `actor_hash` CHAR(64) NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `order_id` INT UNSIGNED DEFAULT NULL,
    `order_code` VARCHAR(32) DEFAULT NULL,
    `final_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('processing', 'completed') NOT NULL DEFAULT 'processing',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_checkout_idem_key` (`idempotency_key`),
    KEY `idx_checkout_idem_actor` (`actor_hash`),
    KEY `idx_checkout_idem_order` (`order_id`),
    CONSTRAINT `fk_checkout_idem_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
