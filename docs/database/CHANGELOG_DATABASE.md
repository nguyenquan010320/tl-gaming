# BÁO CÁO THAY ĐỔI THIẾT KẾ CƠ SỞ DỮ LIỆU (CHANGELOG)
## ĐỒ ÁN TỐT NGHIỆP: WEBSITE BÁN MÁY TÍNH CŨ TL GAMING
**Phiên bản:** Revision 2.0  
**Ngày cập nhật:** 10/11/2026  
**Môi trường áp dụng:** MySQL 8.0 / MariaDB 10.4+ (XAMPP Windows)  
**Phạm vi:** Điều chỉnh, hoàn thiện cấu trúc CSDL và kiểm thử chuyên sâu trước khi lập trình mô hình PHP MVC thuần.

---

### 1. TỔNG QUAN CÁC NỘI DUNG ĐIỀU CHỈNH CHÍNH

Sau khi rà soát toàn diện giữa các tài liệu đặc tả nghiệp vụ, mã nguồn SQL, dữ liệu mẫu, sơ đồ ERD và thực nghiệm kiểm thử đồng thời (concurrency testing), hệ thống cơ sở dữ liệu đã được hoàn thiện với 5 thay đổi cốt lõi:

| STT | Hạng mục điều chỉnh | Phiên bản cũ (v1.0) | Phiên bản mới (v2.0) | Lý do & Ý nghĩa nghiệp vụ |
| :---: | :--- | :--- | :--- | :--- |
| **1** | **Chuẩn hóa quy trình kiểm định 18 bước** | 8 cột checklist gộp trực tiếp trong `product_inspections` | Tách thành bảng con `inspection_items` (chuẩn 3NF), `product_inspections` lưu phiên kiểm định | Khớp hoàn toàn với quảng bá 18 bước kiểm tra trên UI; hỗ trợ giá trị `not_applicable` cho máy bàn/linh kiện; lưu ghi chú kỹ thuật riêng từng bước. |
| **2** | **Phân biệt hàng có Serial và hàng số lượng** | Chưa có cờ đánh dấu trong `products` | Thêm cột `products.has_serials TINYINT(1) DEFAULT 1` | Hỗ trợ phân loại sản phẩm có serial cá thể hóa (PC, Laptop, VGA) và sản phẩm phụ kiện/linh kiện quản lý theo số lượng thông thường. |
| **3** | **Toàn vẹn gán máy vào đơn (Product-Unit Alignment)** | Chỉ kiểm tra `product_unit_id` đơn lẻ | Bổ sung `UNIQUE KEY (id, product_id)` trong `product_units` và Composite FK `(product_unit_id, product_id)` trong `order_items` | Ngăn chặn triệt để lỗi logic gán nhầm Serial của sản phẩm A vào dòng hàng của sản phẩm B ở mức database engine. |
| **4** | **Kiểm soát xuất bán Serial qua Trigger** | Unique Key cứng trên `product_unit_id` (nếu có sẽ chặn tái xuất bán) | Xây dựng Trigger `tg_check_order_item_unit_active_insert/update` | Ngăn chặn gán cùng một máy vật lý cho nhiều hơn 1 đơn hàng đang hoạt động (`status != 'cancelled'`), nhưng cho phép bán lại bình thường nếu đơn trước đó đã bị hủy. |
| **5** | **Quy trình hủy đơn Idempotent & Bảo toàn lịch sử** | Kịch bản tuần tự đơn giản | Xây dựng quy trình hủy đơn chuẩn ACID trong 1 transaction duy nhất | Chỉ hoàn kho máy `reserved`, kiểm tra trạng thái trước khi hủy (không hoàn 2 lần), giữ nguyên trạng thái thanh toán `completed` (bảo toàn chứng từ kế toán). |

---

### 2. CHI TIẾT CÁC THAY ĐỔI CẤU TRÚC BẢNG (SCHEMA REVISIONS)

#### 2.1. Thêm Bảng mới: `inspection_items` (Bảng thứ 15)
- **Cấu trúc bảng:**
  ```sql
  CREATE TABLE IF NOT EXISTS `inspection_items` (
      `id` INT UNSIGNED AUTO_INCREMENT COMMENT 'Khóa chính chi tiết bước kiểm tra',
      `inspection_id` INT UNSIGNED NOT NULL COMMENT 'Khóa ngoại trỏ đến biên bản kiểm định',
      `step_number` TINYINT UNSIGNED NOT NULL COMMENT 'Số thứ tự bước kiểm tra (từ 1 đến 18)',
      `group_name` VARCHAR(100) NOT NULL COMMENT 'Tên nhóm hạng mục kiểm tra',
      `item_name` VARCHAR(150) NOT NULL COMMENT 'Tên cụ thể của bước kiểm tra phần cứng',
      `result` ENUM('pass', 'fail', 'warning', 'not_applicable') NOT NULL DEFAULT 'pass' COMMENT 'Kết quả kiểm tra',
      `notes` VARCHAR(255) DEFAULT NULL COMMENT 'Ghi chú kỹ thuật cụ thể cho bước này',
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```
- **Ràng buộc:**
  - `uq_inspection_step`: Đảm bảo mỗi biên bản chỉ có đúng một kết quả duy nhất cho mỗi bước từ 1 đến 18.
  - `chk_inspection_items_step`: Kiểm soát giá trị bước luôn nằm trong khoảng $[1, 18]$.

#### 2.2. Điều chỉnh Bảng: `product_inspections`
- Lược bỏ 8 cột checklist gộp (`checklist_cpu`, `checklist_ram`, `checklist_storage`, `checklist_gpu`, `checklist_display`, `checklist_battery`, `checklist_keyboard_touchpad`, `checklist_ports`).
- Giữ lại các trường phiên kiểm định: `id`, `unit_id`, `inspector_id`, `inspection_date`, `checklist_overall_result`, `notes`, `created_at`.
- Phân định rõ ràng: `product_inspections` đóng vai trò Master Header, `inspection_items` đóng vai trò Detail Items.

#### 2.3. Điều chỉnh Bảng: `products`
- Bổ sung cột:
  ```sql
  `has_serials` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Đánh dấu quản lý từng serial riêng (1: có serial riêng trong product_units, 0: linh kiện/phụ kiện theo số lượng)',
  INDEX `idx_products_has_serials` (`has_serials`),
  CONSTRAINT `chk_products_has_serials` CHECK (`has_serials` IN (0, 1))
  ```

#### 2.4. Điều chỉnh Bảng: `product_units`
- Bổ sung khóa duy nhất kết hợp:
  ```sql
  UNIQUE KEY `uq_unit_id_product` (`id`, `product_id`)
  ```
- Mục đích: Làm cơ sở để bảng `order_items` tham chiếu khóa ngoại kết hợp `(product_unit_id, product_id)`.

#### 2.5. Điều chỉnh Bảng: `order_items`
- Bổ sung khóa ngoại kết hợp:
  ```sql
  INDEX `idx_order_items_unit_product` (`product_unit_id`, `product_id`),
  CONSTRAINT `fk_order_items_unit_product` FOREIGN KEY (`product_unit_id`, `product_id`)
      REFERENCES `product_units` (`id`, `product_id`)
      ON DELETE RESTRICT
      ON UPDATE CASCADE
  ```

#### 2.6. Bổ sung Trigger Kiểm soát Serial Đơn hàng Hoạt động
- Trigger ngăn chặn việc một máy đang thuộc một đơn hàng chưa hủy bị gán cho đơn hàng khác:
  ```sql
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
                AND oi.id != OLD.id
                AND o.status != 'cancelled'
          ) THEN
              SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'Lỗi toàn vẹn dữ liệu: Chiếc máy có Serial này đang thuộc một đơn hàng chưa hủy!';
          END IF;
      END IF;
  END //

  DELIMITER ;
  ```

---

### 3. THAY ĐỔI DỮ LIỆU SEED (`seed_tl_gaming.sql`)

1. **Khởi tạo dữ liệu 18 bước kiểm định mẫu:**
   - Đã sinh đầy đủ 90 bản ghi (`5 máy * 18 bước = 90`) vào bảng `inspection_items`.
   - Đối với máy để bàn (PC Gaming) và linh kiện rời (VGA, màn hình): các bước không tương thích (như độ chai pin, bản lề gập mở) được gắn kết quả chuẩn xác là `not_applicable` kèm ghi chú rõ ràng.
   - Đối với laptop có hao mòn theo thời gian: thể hiện trạng thái `warning` cho độ chai pin 12%, phản ánh chân thực nghiệp vụ mua bán laptop cũ.
2. **Cập nhật dữ liệu `products`:**
   - Cung cấp giá trị `has_serials = 1` cho toàn bộ 13 sản phẩm mẫu.

---

### 4. ĐỒNG BỘ TÀI LIỆU VÀ SƠ ĐỒ THIẾT KẾ (SYNC STATUS)

- **`docs/database/DATABASE_DESIGN.md`:** Cập nhật đủ danh mục 15 bảng, 22 Foreign Keys, mô tả chi tiết bảng `inspection_items`, quy tắc khóa ngoại kết hợp và trigger nghiệp vụ.
- **`database/tl_gaming.sql`:** Tệp script chính thống đã hoàn thiện với 15 bảng InnoDB, 22 FKs và 2 Triggers.
- **`database/seed_tl_gaming.sql`:** Tệp seed dữ liệu mẫu hoàn thiện, tương thích 100% với schema mới.
- **`docs/diagrams/TL_Gaming_ERD.drawio`:** Đã tái sinh 2 trang (Chi tiết & Tổng quan) chứa đúng 15 bảng và 22 quan hệ.
- **`docs/diagrams/TL_Gaming_ERD_Detailed.svg` & `Overview.svg`:** Đã xuất vector SVG sắc nét phục vụ chèn vào tài liệu Word/PDF báo cáo tốt nghiệp.
- **`run_db_audit.php`:** Bộ test tự động qua PDO đạt **23/23 PASS (100%)**, không còn bất kỳ lỗi nào.
