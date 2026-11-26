# TÀI LIỆU XÁC MINH CƠ SỞ DỮ LIỆU (SCHEMA VALIDATION)
## HỆ THỐNG: WEBSITE BÁN MÁY TÍNH CŨ TL GAMING

Tài liệu này cung cấp các câu lệnh kiểm chứng SQL (dựa trên `information_schema`) và kết quả kiểm thử thực tế trên hệ quản trị cơ sở dữ liệu MySQL (MariaDB 10.11 trên XAMPP Windows) nhằm đảm bảo CSDL đáp ứng 100% các tiêu chuẩn thiết kế và yêu cầu nghiệp vụ.

---

### MỤC LỤC
1. [Xác minh Tổng số lượng Bảng và Storage Engine](#1-xác-minh-tổng-số-lượng-bảng-và-storage-engine)
2. [Xác minh Khóa chính (Primary Keys)](#2-xác-minh-khóa-chính-primary-keys)
3. [Xác minh Khóa ngoại và Hành vi ON DELETE / ON UPDATE](#3-xác-minh-khóa-ngoại-và-hành-vi-on-delete--on-update)
4. [Xác minh Ràng buộc Duy nhất (Unique Constraints)](#4-xác-minh-ràng-buộc-duy-nhất-unique-constraints)
5. [Xác minh Kiểu Dữ liệu Tiền tệ VND (BIGINT UNSIGNED)](#5-xác-minh-kiểu-dữ-liệu-tiền-tệ-vnd-bigint-unsigned)
6. [Xác minh Trạng thái Mặc định Thanh toán và Khách vãng lai](#6-xác-minh-trạng-thái-mặc-định-thanh-toán-và-khách-vãng-lai)
7. [Xác minh Ràng buộc Toàn vẹn (CHECK Constraints)](#7-xác-minh-ràng-buộc-toàn-vẹn-check-constraints)
8. [Phân tích Kiểm chứng Nghiệp vụ Serial Máy cũ](#8-phân-tích-kiểm-chứng-nghiệp-vụ-serial-máy-cũ)

---

### 1. XÁC MINH TỔNG SỐ LƯỢNG BẢNG VÀ STORAGE ENGINE

#### Câu lệnh kiểm tra:
```sql
SELECT 
    TABLE_NAME, 
    ENGINE, 
    TABLE_COLLATION, 
    TABLE_COMMENT 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'tl_gaming' 
ORDER BY TABLE_NAME;
```

#### Kết quả thực tế (Đủ 14 bảng, 100% InnoDB, utf8mb4_unicode_ci):
| STT | Tên bảng | Storage Engine | Collation | Trạng thái |
| :---: | :--- | :---: | :---: | :---: |
| 1 | `cart_items` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 2 | `carts` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 3 | `categories` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 4 | `coupon_usages` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 5 | `coupons` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 6 | `order_items` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 7 | `orders` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 8 | `payments` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 9 | `product_images` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 10 | `product_inspections` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 11 | `product_specs` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 12 | `product_units` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 13 | `products` | InnoDB | `utf8mb4_unicode_ci` | Đạt |
| 14 | `users` | InnoDB | `utf8mb4_unicode_ci` | Đạt |

---

### 2. XÁC MINH KHÓA CHÍNH (PRIMARY KEYS)

#### Câu lệnh kiểm tra:
```sql
SELECT 
    TABLE_NAME, 
    COLUMN_NAME, 
    DATA_TYPE, 
    EXTRA 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'tl_gaming' 
  AND COLUMN_KEY = 'PRI'
ORDER BY TABLE_NAME;
```

#### Kết quả kiểm chứng:
- Tất cả 14 bảng đều có khóa chính duy nhất tên là `id`, kiểu `INT UNSIGNED`, tự động tăng `auto_increment`.
- Đảm bảo tính nhất quán tuyệt đối trong quy chuẩn đặt tên và dễ dàng xử lý trong mô hình Base Model của kiến trúc MVC PHP thuần.

---

### 3. XÁC MINH KHÓA NGOẠI VÀ HÀNH VI ON DELETE / ON UPDATE

#### Câu lệnh kiểm tra:
```sql
SELECT 
    kcu.TABLE_NAME AS 'Bảng con',
    kcu.COLUMN_NAME AS 'Cột FK',
    kcu.CONSTRAINT_NAME AS 'Tên khóa ngoại',
    kcu.REFERENCED_TABLE_NAME AS 'Bảng cha',
    kcu.REFERENCED_COLUMN_NAME AS 'Cột PK cha',
    rc.UPDATE_RULE AS 'ON UPDATE',
    rc.DELETE_RULE AS 'ON DELETE'
FROM information_schema.KEY_COLUMN_USAGE kcu
JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME 
   AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE kcu.TABLE_SCHEMA = 'tl_gaming'
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME;
```

#### Bảng tổng hợp 20 Khóa ngoại và Hành vi ứng xử:
| Bảng con | Cột FK | Tên khóa ngoại | Bảng cha | ON DELETE | ON UPDATE | Ý nghĩa nghiệp vụ bảo toàn |
| :--- | :--- | :--- | :--- | :---: | :---: | :--- |
| `carts` | `user_id` | `fk_carts_user` | `users` | **CASCADE** | CASCADE | Xóa tài khoản thì giỏ hàng tạm thời bị dọn dẹp theo. |
| `cart_items` | `cart_id` | `fk_cart_items_cart` | `carts` | **CASCADE** | CASCADE | Xóa giỏ hàng thì các món trong giỏ bị dọn dẹp theo. |
| `cart_items` | `product_id` | `fk_cart_items_product`| `products` | **CASCADE** | CASCADE | Sản phẩm bị xóa thì tự bỏ khỏi giỏ hàng chờ mua. |
| `categories` | `parent_id` | `fk_categories_parent` | `categories` | **RESTRICT** | CASCADE | Không cho xóa danh mục cha nếu còn danh mục con trực thuộc. |
| `coupon_usages` | `coupon_id` | `fk_coupon_usages_coupon`| `coupons` | **RESTRICT** | CASCADE | **Bảo tồn chứng từ**: Không cho xóa voucher đã phát sinh sử dụng. |
| `coupon_usages` | `order_id` | `fk_coupon_usages_order` | `orders` | **RESTRICT** | CASCADE | **Bảo tồn chứng từ**: Không cho xóa đơn hàng đã áp mã giảm giá. |
| `coupon_usages` | `user_id` | `fk_coupon_usages_user` | `users` | **SET NULL** | CASCADE | Tài khoản người dùng bị xóa, lịch sử áp mã vẫn giữ nguyên với `user_id = NULL`. |
| `orders` | `coupon_id`| `fk_orders_coupon` | `coupons` | **RESTRICT** | CASCADE | Không thể xóa voucher khi đã có đơn hàng sử dụng mã đó. |
| `orders` | `user_id` | `fk_orders_user` | `users` | **SET NULL** | CASCADE | **Bảo tồn doanh thu**: Khách xóa tài khoản, đơn hàng không bị mất mà lưu `user_id = NULL`. |
| `order_items` | `order_id` | `fk_order_items_order` | `orders` | **RESTRICT** | CASCADE | Không thể tùy tiện xóa đơn hàng khi đã có các dòng chi tiết. |
| `order_items` | `product_id`| `fk_order_items_product`| `products` | **RESTRICT** | CASCADE | **Bảo tồn dữ liệu bán hàng**: Không cho xóa sản phẩm gốc khi đã có đơn đặt. |
| `order_items` | `product_unit_id`| `fk_order_items_unit`| `product_units` | **RESTRICT** | CASCADE | **Bảo hành**: Không cho xóa chiếc máy vật lý có serial đã bán cho khách. |
| `payments` | `order_id` | `fk_payments_order` | `orders` | **RESTRICT** | CASCADE | **Bảo tồn sổ sách kế toán**: Không xóa đơn hàng khi đã có ghi nhận thanh toán. |
| `payments` | `confirmed_by_user_id`| `fk_payments_confirmed_by`| `users` | **SET NULL** | CASCADE | Admin đối soát nghỉ việc/xóa tài khoản, lịch sử duyệt tiền chuyển thành `NULL`. |
| `products` | `category_id`| `fk_products_category` | `categories` | **RESTRICT** | CASCADE | Không cho xóa danh mục khi vẫn còn sản phẩm đang thuộc danh mục đó. |
| `product_images`| `product_id`| `fk_product_images_product`| `products` | **CASCADE** | CASCADE | Xóa sản phẩm mẫu (chưa phát sinh đơn) thì xóa toàn bộ ảnh liên quan. |
| `product_inspections`| `inspector_id`| `fk_inspections_inspector`| `users` | **SET NULL** | CASCADE | Kỹ thuật viên test nghỉ việc, biên bản kiểm định vẫn lưu lại. |
| `product_inspections`| `unit_id` | `fk_inspections_unit` | `product_units` | **CASCADE** | CASCADE | Khi máy vật lý bị hủy bỏ (chưa bán), biên bản test đi theo máy. |
| `product_specs` | `product_id`| `fk_product_specs_product`| `products` | **CASCADE** | CASCADE | Xóa sản phẩm mẫu thì cấu hình kỹ thuật đi theo. |
| `product_units` | `product_id`| `fk_product_units_product`| `products` | **RESTRICT** | CASCADE | Không thể xóa dòng sản phẩm khi trong kho vẫn còn các máy vật lý cụ thể. |

---

### 4. XÁC MINH RÀNG BUỘC DUY NHẤT (UNIQUE CONSTRAINTS)

#### Câu lệnh kiểm tra:
```sql
SELECT 
    TABLE_NAME, 
    NON_UNIQUE, 
    INDEX_NAME, 
    COLUMN_NAME 
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = 'tl_gaming' 
  AND NON_UNIQUE = 0 
  AND INDEX_NAME != 'PRIMARY'
ORDER BY TABLE_NAME, INDEX_NAME;
```

#### Danh sách chỉ mục duy nhất:
1. `carts.uq_carts_user`: Ràng buộc 1 người dùng chỉ có 1 giỏ hàng hoạt động trên CSDL.
2. `cart_items.uq_cart_items_product` `(cart_id, product_id)`: Ràng buộc mỗi sản phẩm chỉ xuất hiện 1 dòng trong 1 giỏ hàng.
3. `categories.uq_categories_slug`: Đảm bảo đường dẫn URL danh mục là duy nhất trên toàn web.
4. `coupons.uq_coupons_code`: Đảm bảo mã voucher không bị trùng lặp.
5. `orders.uq_orders_code`: Đảm bảo mã đơn hàng (ví dụ: `TLG-20261110-XXXX`) không bị trùng.
6. `products.uq_products_slug`: Đảm bảo đường dẫn URL chi tiết sản phẩm chuẩn SEO duy nhất.
7. `products.uq_products_sku`: Đảm bảo mã SKU quản lý kho là duy nhất giữa các mẫu máy.
8. `product_units.uq_product_units_serial`: **Đặc biệt quan trọng** — Đảm bảo mỗi số Serial / IMEI / Service Tag của máy cũ chỉ xuất hiện một lần duy nhất trong kho.
9. `users.uq_users_email`: Đảm bảo mỗi email chỉ đăng ký được 1 tài khoản.

---

### 5. XÁC MINH KIỂU DỮ LIỆU TIỀN TỆ VND (BIGINT UNSIGNED)

#### Câu lệnh kiểm tra:
```sql
SELECT 
    TABLE_NAME, 
    COLUMN_NAME, 
    COLUMN_TYPE 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'tl_gaming' 
  AND (COLUMN_NAME LIKE '%price%' OR COLUMN_NAME LIKE '%amount%' OR COLUMN_NAME LIKE '%discount%')
ORDER BY TABLE_NAME, COLUMN_NAME;
```

#### Kết quả xác thực:
- `products.price`: `bigint(20) unsigned` — **ĐẠT**
- `products.original_price`: `bigint(20) unsigned` — **ĐẠT**
- `product_units.import_price`: `bigint(20) unsigned` — **ĐẠT**
- `coupons.discount_value`: `bigint(20) unsigned` — **ĐẠT**
- `coupons.max_discount_amount`: `bigint(20) unsigned` — **ĐẠT**
- `coupons.min_order_amount`: `bigint(20) unsigned` — **ĐẠT**
- `orders.total_product_amount`: `bigint(20) unsigned` — **ĐẠT**
- `orders.shipping_fee`: `bigint(20) unsigned` — **ĐẠT**
- `orders.discount_amount`: `bigint(20) unsigned` — **ĐẠT**
- `orders.final_amount`: `bigint(20) unsigned` — **ĐẠT**
- `order_items.price`: `bigint(20) unsigned` — **ĐẠT**
- `order_items.subtotal`: `bigint(20) unsigned` — **ĐẠT**
- `payments.amount`: `bigint(20) unsigned` — **ĐẠT**
- `coupon_usages.discount_applied`: `bigint(20) unsigned` — **ĐẠT**

> [!IMPORTANT]
> Toàn bộ 14 trường lưu trữ tiền tệ đều sử dụng `BIGINT UNSIGNED`, tuyệt đối không sử dụng `FLOAT`, `DOUBLE` hoặc số có phần lẻ thập phân, đảm bảo tính toán tiền Việt Nam Đồng (VND) chính xác tuyệt đối.

---

### 6. XÁC MINH TRẠNG THÁI MẶC ĐỊNH THANH TOÁN VÀ KHÁCH VÃNG LAI

#### Câu lệnh kiểm tra:
```sql
SELECT 
    TABLE_NAME, 
    COLUMN_NAME, 
    IS_NULLABLE, 
    COLUMN_DEFAULT, 
    COLUMN_TYPE 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'tl_gaming' 
  AND (
      (TABLE_NAME = 'payments' AND COLUMN_NAME = 'status') OR
      (TABLE_NAME = 'orders' AND COLUMN_NAME = 'user_id') OR
      (TABLE_NAME = 'coupon_usages' AND COLUMN_NAME = 'user_id')
  );
```

#### Kết quả xác thực:
1. `payments.status`: Giá trị mặc định là `'pending'`. **Tuyệt đối không bao giờ mặc định là đã thanh toán** (`completed`). Đơn hàng COD hoặc Chuyển khoản bắt buộc phải qua khâu admin kiểm tra tiền vào tài khoản hoặc giao hàng thành công mới được chuyển sang `completed`.
2. `orders.user_id`: Thuộc tính `IS_NULLABLE = YES`. **Hỗ trợ đầy đủ nghiệp vụ Guest Checkout** (khách mua hàng không cần đăng nhập).
3. `coupon_usages.user_id`: Thuộc tính `IS_NULLABLE = YES`. Giúp ghi nhận lượt dùng voucher của khách vãng lai thông qua cặp định danh `customer_phone` và `customer_email`.

---

### 7. XÁC MINH RÀNG BUỘC TOÀN VẸN (CHECK CONSTRAINTS)

#### Danh sách các ràng buộc CHECK đã được kiểm chứng hoạt động:
1. `chk_products_price`: `price >= 0` (Giá bán không âm).
2. `chk_products_original_price`: `original_price IS NULL OR original_price >= price` (Giá niêm yết gốc lớn hơn hoặc bằng giá bán).
3. `chk_product_units_battery`: `battery_health IS NULL OR (battery_health >= 0 AND battery_health <= 100)` (Phần trăm sức khỏe pin từ 0% đến 100%).
4. `chk_cart_items_quantity`: `quantity > 0` (Số lượng trong giỏ phải lớn hơn 0).
5. `chk_coupons_discount_rule`: Nếu `discount_type = 'percentage'` thì giá trị giảm phải `<= 100`.
6. `chk_coupons_date_validity`: `end_date >= start_date` (Ngày kết thúc voucher phải sau ngày bắt đầu).
7. `chk_orders_final_amount`: `final_amount >= 0` (Tổng tiền thanh toán không được âm).
8. `chk_payments_amount`: `amount > 0` (Số tiền thanh toán phải lớn hơn 0).

---

### 8. PHÂN TÍCH KIỂM CHỨNG NGHIỆP VỤ SERIAL MÁY CŨ

#### Vấn đề: Tại sao không đặt khóa UNIQUE cứng trên `order_items.product_unit_id`?
- **Phân tích kỹ thuật:**
  Trong MySQL, nếu ta đặt `UNIQUE (product_unit_id)` trên bảng `order_items`, một chiếc máy vật lý (ví dụ: máy Dell G15 có `id = 10`, số serial `DL-9821`) chỉ có thể xuất hiện trong đúng một bản ghi của bảng `order_items`.
- **Hệ quả nếu đặt UNIQUE cứng:**
  Nếu một khách hàng A đặt mua chiếc máy `id = 10`, nhưng sau đó khách hàng A đổi ý và hủy đơn (`orders.status = 'cancelled'`). Chiếc máy `id = 10` này sẽ được hoàn trả về trạng thái `available` trong kho để bán cho khách hàng B tiếp theo. Khi khách hàng B đặt đơn mới, MySQL sẽ ngay lập tức báo lỗi:
  `ERROR 1062 (23000): Duplicate entry '10' for key 'uq_order_items_unit'`. Khi đó cửa hàng sẽ **không bao giờ bán lại được chiếc máy đó**!
- **Giải pháp chuẩn hóa đã triển khai:**
  1. Trên bảng `order_items`, trường `product_unit_id` được đánh chỉ mục thường `INDEX idx_order_items_unit` (không đặt UNIQUE cứng).
  2. Ràng buộc *"Một máy serial chỉ có thể thuộc tối đa một chi tiết đơn chưa hủy hoặc đã hoàn tất"* được quản lý thông qua trường trạng thái máy `product_units.status`:
     - Khi máy được gán cho đơn hàng đang xử lý, trạng thái máy chuyển sang `'reserved'`.
     - Chỉ những chiếc máy có trạng thái `'available'` mới được phép thêm vào đơn hàng.
     - Kiểm soát nghiêm ngặt bằng Transaction cấp độ nghiệp vụ trong Controller của mô hình MVC.
