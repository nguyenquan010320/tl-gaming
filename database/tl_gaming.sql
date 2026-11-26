-- =============================================================================
-- ĐỒ ÁN TỐT NGHIỆP: XÂY DỰNG WEBSITE BÁN MÁY TÍNH CŨ TL GAMING
-- HỆ QUẢN TRỊ CƠ SỞ DỮ LIỆU: MySQL 8.0 / MariaDB 10.4+ (XAMPP Windows)
-- BỘ MÃ KÝ TỰ: utf8mb4 | BẢNG MÃ SẮP XẾP: utf8mb4_unicode_ci | ENGINE: InnoDB
-- TÁC GIẢ: Nhóm phát triển đồ án TL Gaming
-- MỤC ĐÍCH: Khởi tạo cấu trúc CSDL chuẩn hóa (Schema) cho 14 bảng nghiệp vụ
-- =============================================================================

-- Thiết lập tạo CSDL nếu chưa tồn tại
CREATE DATABASE IF NOT EXISTS `tl_gaming`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Lựa chọn CSDL làm việc mặc định
USE `tl_gaming`;

-- =============================================================================
-- THỨ TỰ TẠO BẢNG (Tuân thủ nghiêm ngặt thứ tự phụ thuộc khóa ngoại):
-- 1.  users                : Tài khoản thành viên và quản trị viên
-- 2.  categories           : Danh mục sản phẩm (cây phân cấp cha - con)
-- 3.  products             : Dòng sản phẩm / Model máy tính cũ
-- 4.  product_images       : Thư viện ảnh chụp thực tế sản phẩm
-- 5.  product_specs        : Cấu hình kỹ thuật chi tiết của dòng máy
-- 6.  product_units        : Từng chiếc máy cũ vật lý (có số Serial duy nhất)
-- 7.  product_inspections  : Phiên kiểm tra kỹ thuật phần cứng tổng thể
-- 8.  inspection_items     : Chi tiết 18 bước kiểm tra kỹ thuật chuẩn hóa
-- 9.  carts                : Giỏ hàng cố định cho tài khoản đã đăng nhập
-- 10. cart_items           : Chi tiết các mặt hàng trong giỏ của thành viên
-- 11. coupons              : Mã giảm giá (voucher) khuyến mãi
-- 12. orders               : Đơn đặt hàng (hỗ trợ cả thành viên và khách vãng lai)
-- 13. order_items          : Chi tiết đơn hàng, snapshot giá và gán serial máy
-- 14. payments             : Lịch sử ghi nhận thanh toán (COD & Chuyển khoản thủ công)
-- 15. coupon_usages        : Nhật ký sử dụng mã giảm giá theo đơn hàng
-- =============================================================================


-- -----------------------------------------------------------------------------
-- BẢNG 1: users (Tài khoản người dùng & Quản trị viên)
-- Vai trò: Quản lý đăng nhập, phân quyền hệ thống (customer / admin)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính định danh tài khoản',
    `role` ENUM('customer', 'admin') NOT NULL DEFAULT 'customer' COMMENT 'Vai trò người dùng trong hệ thống',
    `email` VARCHAR(191) NOT NULL COMMENT 'Email đăng nhập duy nhất',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'Mật khẩu băm an toàn (password_hash Bcrypt/Argon2id)',
    `full_name` VARCHAR(100) NOT NULL COMMENT 'Họ và tên người dùng',
    `phone` VARCHAR(20) DEFAULT NULL COMMENT 'Số điện thoại liên hệ',
    `address` VARCHAR(255) DEFAULT NULL COMMENT 'Địa chỉ mặc định của người dùng',
    `status` ENUM('active', 'locked') NOT NULL DEFAULT 'active' COMMENT 'Trạng thái hoạt động của tài khoản',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm đăng ký tài khoản',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật tài khoản gần nhất',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_phone` (`phone`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng quản lý tài khoản người dùng và phân quyền';


-- -----------------------------------------------------------------------------
-- BẢNG 2: categories (Danh mục sản phẩm)
-- Vai trò: Phân cấp chủng loại máy tính (Laptop Gaming, PC Đồ Họa, Linh kiện...)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính danh mục',
    `parent_id` INT UNSIGNED DEFAULT NULL COMMENT 'Khóa ngoại trỏ đến danh mục cha (NULL nếu là danh mục gốc)',
    `name` VARCHAR(100) NOT NULL COMMENT 'Tên danh mục',
    `slug` VARCHAR(120) NOT NULL COMMENT 'Đường dẫn tĩnh chuẩn SEO duy nhất',
    `description` TEXT DEFAULT NULL COMMENT 'Mô tả chi tiết danh mục',
    `display_order` INT NOT NULL DEFAULT 0 COMMENT 'Thứ tự ưu tiên sắp xếp hiển thị',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Trạng thái hiển thị (1: hiển thị, 0: ẩn)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo danh mục',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_slug` (`slug`),
    INDEX `idx_categories_parent` (`parent_id`),
    INDEX `idx_categories_display_order` (`display_order`),
    INDEX `idx_categories_is_active` (`is_active`),
    CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`)
        REFERENCES `categories` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `chk_categories_is_active` CHECK (`is_active` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng phân cấp danh mục sản phẩm';


-- -----------------------------------------------------------------------------
-- BẢNG 3: products (Dòng sản phẩm máy tính cũ)
-- Vai trò: Lưu trữ thông tin chung của mẫu máy, giá bán VND nguyên, bảo hành
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính dòng sản phẩm',
    `category_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại thuộc danh mục nào',
    `name` VARCHAR(255) NOT NULL COMMENT 'Tên sản phẩm đầy đủ',
    `slug` VARCHAR(255) NOT NULL COMMENT 'Đường dẫn tĩnh chuẩn SEO duy nhất',
    `sku` VARCHAR(50) NOT NULL COMMENT 'Mã quản lý kho (Stock Keeping Unit) duy nhất',
    `summary` VARCHAR(500) DEFAULT NULL COMMENT 'Tóm tắt đặc điểm nổi bật hiển thị ở thẻ sản phẩm',
    `description` LONGTEXT DEFAULT NULL COMMENT 'Mô tả chi tiết và đánh giá chuyên sâu',
    `price` BIGINT UNSIGNED NOT NULL COMMENT 'Giá bán hiện tại bằng tiền VND nguyên (không số lẻ)',
    `original_price` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Giá niêm yết ban đầu khi mới ra mắt để tính % giảm giá',
    `warranty_months` INT UNSIGNED NOT NULL DEFAULT 12 COMMENT 'Thời gian cam kết bảo hành tính bằng tháng',
    `condition_overview` VARCHAR(100) NOT NULL COMMENT 'Tổng quan tình trạng dòng máy (ví dụ: Like New 99%, 95%)',
    `stock_quantity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Số lượng máy vật lý sẵn sàng bán (cache đồng bộ từ product_units hoặc số lượng tồn kho)',
    `has_serials` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Đánh dấu quản lý từng serial riêng (1: có serial riêng trong product_units, 0: linh kiện/phụ kiện theo số lượng)',
    `thumbnail` VARCHAR(255) DEFAULT NULL COMMENT 'Đường dẫn ảnh đại diện chính của sản phẩm',
    `status` ENUM('draft', 'published', 'out_of_stock', 'archived') NOT NULL DEFAULT 'draft' COMMENT 'Trạng thái phát hành sản phẩm',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo sản phẩm',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật sản phẩm',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_products_slug` (`slug`),
    UNIQUE KEY `uq_products_sku` (`sku`),
    INDEX `idx_products_category` (`category_id`),
    INDEX `idx_products_price` (`price`),
    INDEX `idx_products_stock` (`stock_quantity`),
    INDEX `idx_products_status` (`status`),
    INDEX `idx_products_has_serials` (`has_serials`),
    CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`)
        REFERENCES `categories` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `chk_products_price` CHECK (`price` >= 0),
    CONSTRAINT `chk_products_original_price` CHECK (`original_price` IS NULL OR `original_price` >= `price`),
    CONSTRAINT `chk_products_warranty` CHECK (`warranty_months` >= 0),
    CONSTRAINT `chk_products_stock` CHECK (`stock_quantity` >= 0),
    CONSTRAINT `chk_products_has_serials` CHECK (`has_serials` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng thông tin dòng sản phẩm máy tính cũ';


-- -----------------------------------------------------------------------------
-- BẢNG 4: product_images (Bộ sưu tập hình ảnh thực tế của sản phẩm)
-- Vai trò: Lưu nhiều góc chụp ngoại hình thực tế của máy cũ
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_images` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính ảnh sản phẩm',
    `product_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến sản phẩm sở hữu',
    `image_url` VARCHAR(255) NOT NULL COMMENT 'Đường dẫn tệp hình ảnh lưu trữ trên máy chủ',
    `alt_text` VARCHAR(255) DEFAULT NULL COMMENT 'Mô tả văn bản thay thế cho ảnh',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT 'Thứ tự hiển thị ảnh trong album',
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Đánh dấu ảnh đại diện chính (1: chính, 0: phụ)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tải ảnh lên',
    PRIMARY KEY (`id`),
    INDEX `idx_product_images_product` (`product_id`),
    INDEX `idx_product_images_primary` (`is_primary`),
    CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`)
        REFERENCES `products` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `chk_product_images_is_primary` CHECK (`is_primary` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng hình ảnh chi tiết của sản phẩm';


-- -----------------------------------------------------------------------------
-- BẢNG 5: product_specs (Thông số kỹ thuật phần cứng chi tiết)
-- Vai trò: Lưu cấu hình dạng Key-Value (CPU, RAM, VGA, Màn hình, Ổ cứng...)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_specs` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính thông số kỹ thuật',
    `product_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến sản phẩm sở hữu',
    `group_name` VARCHAR(100) DEFAULT NULL COMMENT 'Tên nhóm thông số (ví dụ: Vi xử lý & Đồ họa, Bộ nhớ)',
    `spec_name` VARCHAR(100) NOT NULL COMMENT 'Tên linh kiện/thông số (ví dụ: CPU, RAM, Ổ cứng, VGA)',
    `spec_value` VARCHAR(255) NOT NULL COMMENT 'Giá trị chi tiết linh kiện',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT 'Thứ tự hiển thị thông số',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo thông số',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm sửa đổi thông số',
    PRIMARY KEY (`id`),
    INDEX `idx_product_specs_product` (`product_id`),
    INDEX `idx_product_specs_lookup` (`product_id`, `spec_name`),
    CONSTRAINT `fk_product_specs_product` FOREIGN KEY (`product_id`)
        REFERENCES `products` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng cấu hình kỹ thuật phần cứng của sản phẩm';


-- -----------------------------------------------------------------------------
-- BẢNG 6: product_units (Cá thể máy tính vật lý trong kho có Serial riêng)
-- Vai trò: Quản lý từng chiếc máy cũ vật lý, số Serial, ngoại hình, pin và tình trạng kho
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_units` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính máy vật lý',
    `product_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến dòng sản phẩm chung',
    `serial_number` VARCHAR(100) NOT NULL COMMENT 'Số Serial / Service Tag / IMEI duy nhất của chiếc máy',
    `hardware_condition_detail` TEXT DEFAULT NULL COMMENT 'Mô tả chi tiết ngoại hình, độ xước, linh kiện của riêng chiếc này',
    `battery_health` INT UNSIGNED DEFAULT NULL COMMENT 'Tỷ lệ % pin còn lại thực tế đối với laptop (0 - 100)',
    `cosmetic_grade` ENUM('Grade A', 'Grade B', 'Grade C') NOT NULL DEFAULT 'Grade A' COMMENT 'Phân loại hình thức (Grade A: 99% like new, Grade B: 95%, Grade C: cũ trầy)',
    `status` ENUM('available', 'reserved', 'sold', 'repairing', 'returned') NOT NULL DEFAULT 'available' COMMENT 'Trạng thái máy trong kho',
    `import_price` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Giá vốn nhập vào chiếc máy này (phục vụ tính lãi lỗ)',
    `row_version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Phiên bản khóa lạc quan tăng dần mỗi lần cập nhật',
    `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm nhập kho chiếc máy này',
    `returned_at` DATETIME DEFAULT NULL COMMENT 'Thời điểm gần nhất máy chuyển sang trạng thái returned',
    `returned_baseline_inspection_id` INT UNSIGNED DEFAULT NULL COMMENT 'ID biên bản kiểm định cao nhất tại thời điểm máy chuyển sang returned',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo bản ghi máy',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật thông tin máy',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_units_serial` (`serial_number`),
    UNIQUE KEY `uq_unit_id_product` (`id`, `product_id`),
    INDEX `idx_product_units_product` (`product_id`),
    INDEX `idx_product_units_status` (`status`),
    INDEX `idx_product_units_grade` (`cosmetic_grade`),
    CONSTRAINT `fk_product_units_product` FOREIGN KEY (`product_id`)
        REFERENCES `products` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `chk_product_units_battery` CHECK (`battery_health` IS NULL OR (`battery_health` >= 0 AND `battery_health` <= 100)),
    CONSTRAINT `chk_product_units_import_price` CHECK (`import_price` IS NULL OR `import_price` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng quản lý từng cá thể máy cũ vật lý theo số Serial';


-- -----------------------------------------------------------------------------
-- BẢNG 7: product_inspections (Biên bản kiểm tra kỹ thuật phần cứng máy)
-- Vai trò: Lưu phiên kiểm định chất lượng phần cứng trước khi xuất bán
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_inspections` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính biên bản kiểm định',
    `unit_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến chiếc máy vật lý được test',
    `inspector_id` INT UNSIGNED DEFAULT NULL COMMENT 'Khóa ngoại trỏ đến nhân viên/admin thực hiện kiểm định',
    `inspection_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tiến hành kiểm tra',
    `checklist_overall_result` ENUM('passed', 'conditional_pass', 'failed') NOT NULL DEFAULT 'passed' COMMENT 'Kết luận chung của lần kiểm tra',
    `notes` TEXT DEFAULT NULL COMMENT 'Ghi chú kỹ thuật chi tiết kết quả test tổng thể',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo biên bản kiểm định',
    PRIMARY KEY (`id`),
    INDEX `idx_inspections_unit` (`unit_id`),
    INDEX `idx_inspections_inspector` (`inspector_id`),
    INDEX `idx_inspections_result` (`checklist_overall_result`),
    INDEX `idx_inspections_date` (`inspection_date`),
    CONSTRAINT `fk_inspections_unit` FOREIGN KEY (`unit_id`)
        REFERENCES `product_units` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_inspections_inspector` FOREIGN KEY (`inspector_id`)
        REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng biên bản kiểm định kỹ thuật phần cứng máy cũ';


-- -----------------------------------------------------------------------------
-- BẢNG 8: inspection_items (Chi tiết 18 bước kiểm tra kỹ thuật chuyên sâu)
-- Vai trò: Chuẩn hóa 3NF quy trình 18 bước kiểm tra, hỗ trợ đầy đủ ghi chú & kết quả từng hạng mục
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inspection_items` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính chi tiết bước kiểm tra',
    `inspection_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến biên bản kiểm định',
    `step_number` TINYINT UNSIGNED NOT NULL COMMENT 'Số thứ tự bước kiểm tra (từ 1 đến 18)',
    `group_name` VARCHAR(100) NOT NULL COMMENT 'Tên nhóm hạng mục kiểm tra',
    `item_name` VARCHAR(150) NOT NULL COMMENT 'Tên cụ thể của bước kiểm tra phần cứng',
    `result` ENUM('pass', 'fail', 'warning', 'not_applicable') NOT NULL DEFAULT 'pass' COMMENT 'Kết quả kiểm tra (pass: Đạt, fail: Hỏng, warning: Cảnh báo chai/xước, not_applicable: Không áp dụng)',
    `notes` VARCHAR(255) DEFAULT NULL COMMENT 'Ghi chú kỹ thuật cụ thể cho bước này (nhiệt độ, phần trăm, số serial phụ...)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm ghi nhận kết quả bước kiểm tra',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_inspection_step` (`inspection_id`, `step_number`),
    INDEX `idx_inspection_items_inspection` (`inspection_id`),
    INDEX `idx_inspection_items_result` (`result`),
    INDEX `idx_inspection_items_step` (`step_number`),
    CONSTRAINT `fk_inspection_items_inspection` FOREIGN KEY (`inspection_id`)
        REFERENCES `product_inspections` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `chk_inspection_items_step` CHECK (`step_number` BETWEEN 1 AND 18)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng chi tiết 18 bước kiểm định kỹ thuật phần cứng máy cũ';


-- -----------------------------------------------------------------------------
-- BẢNG 9: carts (Giỏ hàng tài khoản thành viên)
-- Vai trò: Lưu trữ giỏ hàng cố định trên CSDL cho người dùng đã đăng nhập
-- Lưu ý: Khách vãng lai dùng PHP Session riêng, khi đăng nhập sẽ merge vào bảng này
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `carts` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính giỏ hàng',
    `user_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại 1-1 với tài khoản người dùng',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo giỏ hàng',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật giỏ hàng gần nhất',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_carts_user` (`user_id`),
    CONSTRAINT `fk_carts_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng giỏ hàng của thành viên đăng nhập';


-- -----------------------------------------------------------------------------
-- BẢNG 10: cart_items (Chi tiết mặt hàng trong giỏ của thành viên)
-- Vai trò: Lưu danh sách sản phẩm và số lượng đặt trong giỏ
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cart_items` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính dòng giỏ hàng',
    `cart_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến giỏ hàng',
    `product_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến sản phẩm chọn mua',
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Số lượng sản phẩm muốn mua',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm thêm vào giỏ',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật số lượng',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cart_items_product` (`cart_id`, `product_id`),
    INDEX `idx_cart_items_cart` (`cart_id`),
    INDEX `idx_cart_items_product` (`product_id`),
    CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`)
        REFERENCES `carts` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_cart_items_product` FOREIGN KEY (`product_id`)
        REFERENCES `products` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `chk_cart_items_quantity` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng chi tiết các mặt hàng trong giỏ';


-- -----------------------------------------------------------------------------
-- BẢNG 11: coupons (Mã giảm giá / Voucher)
-- Vai trò: Quản lý voucher theo %, số tiền cố định, thời hạn dùng và lượt dùng
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coupons` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính mã giảm giá',
    `code` VARCHAR(50) NOT NULL COMMENT 'Mã voucher khách hàng nhập (viết hoa duy nhất)',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Mô tả chương trình ưu đãi',
    `discount_type` ENUM('percentage', 'fixed_amount') NOT NULL COMMENT 'Hình thức giảm giá (% hoặc số tiền VND cố định)',
    `discount_value` BIGINT UNSIGNED NOT NULL COMMENT 'Giá trị giảm (% từ 1-100 hoặc số tiền VND)',
    `max_discount_amount` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Mức giảm tối đa áp dụng khi giảm theo %',
    `min_order_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Giá trị đơn hàng tối thiểu để áp dụng voucher',
    `usage_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Tổng số lượt dùng tối đa toàn hệ thống (0 là không giới hạn)',
    `usage_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Số lượt đã sử dụng thực tế',
    `per_user_limit` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Số lần tối đa một khách hàng được dùng mã này',
    `start_date` DATETIME NOT NULL COMMENT 'Thời điểm bắt đầu có hiệu lực',
    `end_date` DATETIME NOT NULL COMMENT 'Thời điểm hết hạn áp dụng',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Trạng thái hoạt động (1: đang mở, 0: tạm đóng)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo mã',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật mã',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_coupons_code` (`code`),
    INDEX `idx_coupons_date_range` (`start_date`, `end_date`),
    INDEX `idx_coupons_is_active` (`is_active`),
    CONSTRAINT `chk_coupons_discount_rule` CHECK (
        (`discount_type` = 'percentage' AND `discount_value` <= 100) OR
        (`discount_type` = 'fixed_amount')
    ),
    CONSTRAINT `chk_coupons_discount_value` CHECK (`discount_value` > 0),
    CONSTRAINT `chk_coupons_max_discount` CHECK (`max_discount_amount` IS NULL OR `max_discount_amount` > 0),
    CONSTRAINT `chk_coupons_min_order` CHECK (`min_order_amount` >= 0),
    CONSTRAINT `chk_coupons_usage_limit` CHECK (`usage_limit` >= 0),
    CONSTRAINT `chk_coupons_usage_count` CHECK (`usage_count` >= 0),
    CONSTRAINT `chk_coupons_usage_bound` CHECK (`usage_limit` = 0 OR `usage_count` <= `usage_limit`),
    CONSTRAINT `chk_coupons_per_user` CHECK (`per_user_limit` >= 1),
    CONSTRAINT `chk_coupons_date_validity` CHECK (`end_date` >= `start_date`),
    CONSTRAINT `chk_coupons_is_active` CHECK (`is_active` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng quản lý mã giảm giá (voucher)';


-- -----------------------------------------------------------------------------
-- BẢNG 12: orders (Đơn đặt hàng)
-- Vai trò: Quản lý đơn hàng, người nhận, số tiền và trạng thái xử lý
-- Hỗ trợ khách vãng lai: user_id được phép NULL
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính đơn hàng',
    `order_code` VARCHAR(32) NOT NULL COMMENT 'Mã đơn hàng đối soát định dạng chuẩn duy nhất (ví dụ: TLG-20261110-XXXX)',
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Khóa ngoại trỏ đến tài khoản người mua (NULL nếu là khách vãng lai)',
    `customer_name` VARCHAR(100) NOT NULL COMMENT 'Họ và tên người nhận hàng',
    `customer_email` VARCHAR(191) NOT NULL COMMENT 'Email người nhận hàng để gửi thông báo đơn và kiểm soát voucher',
    `customer_phone` VARCHAR(20) NOT NULL COMMENT 'Số điện thoại người nhận hàng',
    `shipping_address` VARCHAR(255) NOT NULL COMMENT 'Địa chỉ nhận hàng chi tiết',
    `shipping_city` VARCHAR(100) NOT NULL COMMENT 'Tỉnh / Thành phố nhận hàng',
    `shipping_district` VARCHAR(100) DEFAULT NULL COMMENT 'Quận / Huyện / Thị xã nhận hàng',
    `delivery_method` ENUM('delivery', 'pickup') DEFAULT NULL COMMENT 'Hình thức nhận hàng (delivery: giao tận nơi, pickup: nhận tại showroom, NULL: đơn cũ chưa lưu cột này)',
    `customer_note` VARCHAR(500) DEFAULT NULL COMMENT 'Ghi chú hoặc yêu cầu thêm của khách hàng',
    `total_product_amount` BIGINT UNSIGNED NOT NULL COMMENT 'Tổng tiền hàng nguyên bản trước giảm giá (VND)',
    `shipping_fee` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Phí vận chuyển giao hàng (VND)',
    `discount_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Số tiền được giảm trừ qua mã voucher (VND)',
    `final_amount` BIGINT UNSIGNED NOT NULL COMMENT 'Tổng số tiền thực tế khách phải trả (VND)',
    `coupon_id` INT UNSIGNED DEFAULT NULL COMMENT 'Khóa ngoại trỏ đến mã voucher đã áp dụng',
    `status` ENUM('pending', 'confirmed', 'shipping', 'completed', 'cancelled') NOT NULL DEFAULT 'pending' COMMENT 'Vòng đời trạng thái xử lý đơn hàng',
    `cancel_reason` VARCHAR(255) DEFAULT NULL COMMENT 'Lý do hủy đơn hàng',
    `cancelled_at` DATETIME DEFAULT NULL COMMENT 'Thời điểm đơn hàng bị hủy',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm đặt đơn hàng',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật đơn hàng gần nhất',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_orders_code` (`order_code`),
    INDEX `idx_orders_user` (`user_id`),
    INDEX `idx_orders_coupon` (`coupon_id`),
    INDEX `idx_orders_status` (`status`),
    INDEX `idx_orders_customer_phone` (`customer_phone`),
    INDEX `idx_orders_customer_email` (`customer_email`),
    INDEX `idx_orders_created_at` (`created_at`),
    CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT `fk_orders_coupon` FOREIGN KEY (`coupon_id`)
        REFERENCES `coupons` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `chk_orders_total_product` CHECK (`total_product_amount` >= 0),
    CONSTRAINT `chk_orders_shipping_fee` CHECK (`shipping_fee` >= 0),
    CONSTRAINT `chk_orders_discount` CHECK (`discount_amount` >= 0 AND `discount_amount` <= `total_product_amount`),
    CONSTRAINT `chk_orders_final_amount` CHECK (`final_amount` >= 0 AND `final_amount` = `total_product_amount` + `shipping_fee` - `discount_amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng đơn đặt hàng tổng thể';


-- -----------------------------------------------------------------------------
-- BẢNG 13: order_items (Chi tiết mặt hàng trong đơn & Snapshot giá/máy gán)
-- Vai trò: Lưu snapshot tên sản phẩm, đơn giá tại thời điểm mua và gán Serial máy
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính dòng chi tiết đơn hàng',
    `order_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến đơn hàng sở hữu',
    `product_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến dòng sản phẩm',
    `product_unit_id` INT UNSIGNED DEFAULT NULL COMMENT 'Khóa ngoại trỏ đến chiếc máy vật lý có serial cụ thể được xuất cho đơn',
    `product_name` VARCHAR(255) NOT NULL COMMENT 'Snapshot: Tên sản phẩm tại thời điểm mua',
    `product_sku` VARCHAR(50) NOT NULL COMMENT 'Snapshot: Mã SKU của sản phẩm tại thời điểm mua',
    `unit_serial_number` VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot: Số Serial của chiếc máy vật lý được gán',
    `price` BIGINT UNSIGNED NOT NULL COMMENT 'Snapshot: Đơn giá sản phẩm tại thời điểm mua (VND)',
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Số lượng (đối với máy cũ có serial, bắt buộc tách thành các dòng quantity = 1)',
    `subtotal` BIGINT UNSIGNED NOT NULL COMMENT 'Thành tiền dòng chi tiết: price * quantity (VND)',
    `warranty_period` VARCHAR(50) NOT NULL COMMENT 'Snapshot: Chính sách bảo hành cam kết tại thời điểm mua',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm ghi nhận chi tiết đơn hàng',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order_items_order_unit` (`order_id`, `product_unit_id`),
    INDEX `idx_order_items_order` (`order_id`),
    INDEX `idx_order_items_product` (`product_id`),
    INDEX `idx_order_items_unit` (`product_unit_id`),
    INDEX `idx_order_items_serial` (`unit_serial_number`),
    INDEX `idx_order_items_unit_product` (`product_unit_id`, `product_id`),
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`)
        REFERENCES `orders` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`)
        REFERENCES `products` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `fk_order_items_unit` FOREIGN KEY (`product_unit_id`)
        REFERENCES `product_units` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `fk_order_items_unit_product` FOREIGN KEY (`product_unit_id`, `product_id`)
        REFERENCES `product_units` (`id`, `product_id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `chk_order_items_price` CHECK (`price` >= 0),
    CONSTRAINT `chk_order_items_quantity` CHECK (`quantity` > 0),
    CONSTRAINT `chk_order_items_subtotal` CHECK (`subtotal` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng chi tiết mặt hàng trong đơn lưu snapshot và gán máy vật lý';


-- -----------------------------------------------------------------------------
-- BẢNG 14: payments (Lịch sử thanh toán đơn hàng)
-- Vai trò: Quản lý ghi nhận tiền COD và Chuyển khoản đối soát thủ công
-- Mặc định trạng thái: pending (chờ thanh toán), tuyệt đối không mặc định là đã thanh toán
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính giao dịch thanh toán',
    `order_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến đơn hàng cần thanh toán',
    `payment_method` ENUM('cod', 'bank_transfer') NOT NULL COMMENT 'Hình thức thanh toán: Thu hộ COD hoặc Chuyển khoản ngân hàng',
    `amount` BIGINT UNSIGNED NOT NULL COMMENT 'Số tiền thanh toán ghi nhận (VND)',
    `status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending' COMMENT 'Trạng thái thanh toán (mặc định pending, chưa thanh toán)',
    `refund_status` ENUM('none', 'manual_required', 'refunded') NOT NULL DEFAULT 'none' COMMENT 'Trạng thái xử lý hoàn tiền thủ công khi hủy đơn đã thanh toán',
    `refund_note` VARCHAR(255) DEFAULT NULL COMMENT 'Ghi chú yêu cầu hoàn tiền thủ công',
    `refund_requested_at` DATETIME DEFAULT NULL COMMENT 'Thời điểm phát sinh yêu cầu hoàn tiền thủ công',
    `transaction_code` VARCHAR(100) DEFAULT NULL COMMENT 'Mã tham chiếu giao dịch hoặc mã chuyển khoản ngân hàng',
    `bank_name` VARCHAR(100) DEFAULT NULL COMMENT 'Tên ngân hàng nhận thanh toán (ví dụ: Vietcombank, MBBank)',
    `account_number` VARCHAR(50) DEFAULT NULL COMMENT 'Số tài khoản ngân hàng nhận tiền',
    `transfer_note` VARCHAR(255) DEFAULT NULL COMMENT 'Nội dung chuyển tiền của khách hàng',
    `payment_proof_image` VARCHAR(255) DEFAULT NULL COMMENT 'Đường dẫn ảnh chụp bill/biên lai chuyển khoản để admin đối soát',
    `confirmed_by_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Khóa ngoại trỏ đến admin thực hiện đối soát và duyệt tiền về tài khoản',
    `confirmed_at` DATETIME DEFAULT NULL COMMENT 'Thời điểm admin duyệt hoàn tất thanh toán',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo bản ghi thanh toán',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật thanh toán',
    PRIMARY KEY (`id`),
    INDEX `idx_payments_order` (`order_id`),
    INDEX `idx_payments_method` (`payment_method`),
    INDEX `idx_payments_status` (`status`),
    INDEX `idx_payments_transaction_code` (`transaction_code`),
    INDEX `idx_payments_confirmed_by` (`confirmed_by_user_id`),
    CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`)
        REFERENCES `orders` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_confirmed_by` FOREIGN KEY (`confirmed_by_user_id`)
        REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT `chk_payments_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng lịch sử thanh toán đơn hàng (COD & Chuyển khoản ngân hàng, hỗ trợ đơn 0đ)';


-- -----------------------------------------------------------------------------
-- BẢNG 15: coupon_usages (Nhật ký sử dụng mã giảm giá)
-- Vai trò: Lưu lịch sử kích hoạt mã theo đơn, chống gian lận lặp mã cho khách vãng lai
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coupon_usages` (
    `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính nhật ký dùng voucher',
    `coupon_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến mã voucher đã dùng',
    `order_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến đơn hàng được giảm giá',
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Khóa ngoại trỏ đến tài khoản người mua (NULL nếu là khách vãng lai)',
    `customer_email` VARCHAR(191) NOT NULL COMMENT 'Email khách nhận đơn (dùng đối soát giới hạn đối với khách vãng lai)',
    `customer_phone` VARCHAR(20) NOT NULL COMMENT 'Số điện thoại khách nhận đơn (dùng đối soát giới hạn đối với khách vãng lai)',
    `discount_applied` BIGINT UNSIGNED NOT NULL COMMENT 'Số tiền thực tế được giảm trừ vào đơn hàng này (VND)',
    `used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm áp dụng mã',
    `status` ENUM('used', 'cancelled') NOT NULL DEFAULT 'used' COMMENT 'Trạng thái lượt dùng (used: hợp lệ, cancelled: đã hoàn lại khi hủy đơn)',
    PRIMARY KEY (`id`),
    INDEX `idx_coupon_usages_coupon` (`coupon_id`),
    INDEX `idx_coupon_usages_order` (`order_id`),
    INDEX `idx_coupon_usages_user` (`user_id`),
    INDEX `idx_coupon_usages_phone` (`customer_phone`),
    INDEX `idx_coupon_usages_email` (`customer_email`),
    INDEX `idx_coupon_usages_status` (`status`),
    INDEX `idx_coupon_usages_lookup` (`coupon_id`, `customer_phone`, `status`),
    CONSTRAINT `fk_coupon_usages_coupon` FOREIGN KEY (`coupon_id`)
        REFERENCES `coupons` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `fk_coupon_usages_order` FOREIGN KEY (`order_id`)
        REFERENCES `orders` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT `fk_coupon_usages_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT `chk_coupon_usages_discount` CHECK (`discount_applied` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng nhật ký sử dụng mã giảm giá';


-- -----------------------------------------------------------------------------
-- TRIGGERS: RÀNG BUỘC TOÀN VẸN NGHIỆP VỤ PHÒNG VỆ LỚP THỨ HAI (DEFENSE-IN-DEPTH)
-- -----------------------------------------------------------------------------
-- LƯU Ý KIẾN TRÚC QUAN TRỌNG VỀ CONCURRENCY (ĐỒNG THỜI):
-- Trong hệ quản trị InnoDB với mức cô lập mặc định REPEATABLE READ, câu lệnh
-- SELECT thường bên trong Trigger là "Consistent Nonlocking Read" (đọc snapshot),
-- nên NẾU chỉ dựa vào Trigger đọc dữ liệu đơn hàng thì 2 transaction chạy song song
-- chưa commit sẽ KHÔNG nhìn thấy bản ghi mới của nhau (Race Condition).
-- Do đó:
-- 1. Biện pháp chống bán trùng CHÍNH (Primary Concurrency Control) bắt buộc thực hiện
--    ở tầng giao dịch ứng dụng thông qua:
--    - Khóa dòng máy vật lý: SELECT ... FROM product_units WHERE id = ? AND status = 'available' FOR UPDATE
--    - Cập nhật có điều kiện: UPDATE product_units SET status = 'reserved' WHERE id = ? AND status = 'available'
--    - Kiểm tra số dòng bị ảnh hưởng: rowCount() === 1
-- 2. Khóa UNIQUE KEY uq_order_items_order_unit (order_id, product_unit_id) chặn tuyệt đối
--    lỗi gán cùng 1 serial hai lần trong cùng một đơn hàng.
-- 3. Hai Trigger dưới đây đóng vai trò chốt chặn bảo vệ thứ hai (Second-layer Guard)
--    chống lại các thao tác chèn/sửa dữ liệu tuần tự trái phép vào máy đã thuộc đơn chưa hủy,
--    đồng thời vẫn cho phép tái xuất bán máy khi đơn hàng cũ đã chuyển sang 'cancelled'.
DELIMITER //

DROP TRIGGER IF EXISTS `tg_check_order_item_unit_active_insert` //
CREATE TRIGGER `tg_check_order_item_unit_active_insert`
BEFORE INSERT ON `order_items`
FOR EACH ROW
BEGIN
    IF NEW.product_unit_id IS NOT NULL THEN
        IF EXISTS (
            SELECT 1 
            FROM `order_items` oi
            JOIN `orders` o ON oi.order_id = o.id
            WHERE oi.product_unit_id = NEW.product_unit_id
              AND oi.order_id != NEW.order_id
              AND o.status != 'cancelled'
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Lỗi toàn vẹn dữ liệu: Chiếc máy có Serial này đang thuộc một đơn hàng chưa hủy!';
        END IF;
    END IF;
END //

DROP TRIGGER IF EXISTS `tg_check_order_item_unit_active_update` //
CREATE TRIGGER `tg_check_order_item_unit_active_update`
BEFORE UPDATE ON `order_items`
FOR EACH ROW
BEGIN
    IF NEW.product_unit_id IS NOT NULL AND (OLD.product_unit_id IS NULL OR NEW.product_unit_id != OLD.product_unit_id) THEN
        IF EXISTS (
            SELECT 1 
            FROM `order_items` oi
            JOIN `orders` o ON oi.order_id = o.id
            WHERE oi.product_unit_id = NEW.product_unit_id
              AND oi.order_id != NEW.order_id
              AND oi.id != OLD.id
              AND o.status != 'cancelled'
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Lỗi toàn vẹn dữ liệu: Chiếc máy có Serial này đang thuộc một đơn hàng chưa hủy!';
        END IF;
    END IF;
END //

DELIMITER ;

-- -----------------------------------------------------------------------------
-- BẢNG 16: checkout_idempotency (Khóa chống đặt đơn hàng trùng lặp bền vững)
-- Vai trò: Lưu trạng thái & kết quả tạo đơn theo idempotency_key trong MySQL
--          để chống double-submit và phục hồi đúng đơn đã commit kể cả khi PHP dừng giữa chừng.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `checkout_idempotency` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính bản ghi idempotency',
    `idempotency_key` VARCHAR(64) NOT NULL COMMENT 'Mã khóa idempotency duy nhất được Server cấp theo phiên',
    `actor_hash` CHAR(64) NOT NULL COMMENT 'Mã băm tác nhân đặt hàng (user_id hoặc session_id)',
    `payload_hash` CHAR(64) NOT NULL COMMENT 'Mã băm SHA-256 giỏ hàng & thông tin đặt đơn',
    `order_id` INT UNSIGNED DEFAULT NULL COMMENT 'Khóa ngoại trỏ tới đơn hàng đã tạo thành công',
    `order_code` VARCHAR(32) DEFAULT NULL COMMENT 'Mã đơn hàng đã tạo thành công',
    `final_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Tổng tiền thanh toán cuối cùng (VND)',
    `status` ENUM('processing', 'completed') NOT NULL DEFAULT 'processing' COMMENT 'Trạng thái xử lý khóa',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm khởi tạo khóa',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_checkout_idem_key` (`idempotency_key`),
    KEY `idx_checkout_idem_actor` (`actor_hash`),
    KEY `idx_checkout_idem_order` (`order_id`),
    CONSTRAINT `fk_checkout_idem_order` FOREIGN KEY (`order_id`)
        REFERENCES `orders` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng lưu khóa idempotency thanh toán bền vững trong MySQL';

-- -----------------------------------------------------------------------------
-- BẢNG 17: order_status_logs (Nhật ký audit thao tác đơn hàng & thanh toán Admin)
-- -----------------------------------------------------------------------------
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

-- =============================================================================
-- KẾT THÚC KHỞI TẠO SCHEMA CSDL TL GAMING (17 BẢNG CHUẨN HÓA INNODB + 2 TRIGGERS)
-- =============================================================================
