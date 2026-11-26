# HƯỚNG DẪN DỮ LIỆU MẪU (SEED GUIDE)
## HỆ THỐNG: WEBSITE BÁN MÁY TÍNH CŨ TL GAMING

Tài liệu này hướng dẫn chi tiết cách nạp (import), cấu trúc dữ liệu thử nghiệm và các câu lệnh SQL kiểm chứng (Audit Queries) cho tệp dữ liệu mẫu `database/seed_tl_gaming.sql` phục vụ quá trình lập trình, kiểm thử và demo bảo vệ đồ án tốt nghiệp.

---

### MỤC LỤC
1. [Mục tiêu và Phạm vi Dữ liệu Mẫu](#1-mục-tiêu-và-phạm-vi-dữ-liệu-mẫu)
2. [Danh sách Tài khoản Demo & Cơ chế Bảo mật](#2-danh-sách-tài-khoản-demo--cơ-chế-bảo-mật)
3. [Hướng dẫn Import Dữ liệu Mẫu](#3-hướng-dẫn-import-dữ-liệu-mẫu)
4. [Các Câu lệnh SQL Kiểm tra và Đối soát Nghiệp vụ](#4-các-câu-lệnh-sql-kiểm-tra-và-đối-soát-nghiệp-vụ)
   - 4.1. Thống kê số lượng bản ghi trên 14 bảng
   - 4.2. Kiểm chứng đồng bộ tồn kho (Single Source of Truth)
   - 4.3. Kiểm chứng công thức tài chính đơn hàng
   - 4.4. Kiểm chứng đối soát thanh toán (COD & Chuyển khoản)
   - 4.5. Kiểm chứng vòng đời gán Serial máy cũ
   - 4.6. Kiểm chứng danh sách Voucher và Hoàn mã khi hủy đơn
   - 4.7. Kiểm chứng giỏ hàng thành viên
5. [Cơ chế Idempotent (Chạy lại không trùng dữ liệu)](#5-cơ-chế-idempotent-chạy-lại-không-trùng-dữ-liệu)

---

### 1. MỤC TIÊU VÀ PHẠM VI DỮ LIỆU MẪU

Tệp `database/seed_tl_gaming.sql` được xây dựng nhằm cung cấp dữ liệu thực tế và hoàn chỉnh nhất cho đồ án, bao gồm:
- **Tài khoản:** 1 Quản trị viên (`admin`) và 3 Khách hàng mẫu (`customer`).
- **Danh mục:** 6 danh mục (hỗ trợ phân cấp cha - con: PC Gaming cũ, Laptop cũ, Linh kiện cũ, Màn hình cũ, VGA cũ, CPU cũ).
- **Sản phẩm:** 13 dòng máy/mẫu hàng đa dạng với đầy đủ ảnh, thông số kỹ thuật, giá nguyên VND, thời gian bảo hành và tình trạng.
- **Cá thể máy vật lý (Serial):** 19 chiếc máy có số Serial duy nhất và phân loại Grade A/B/C.
- **Biên bản kiểm định (Inspection):** 5 biên bản kiểm tra kỹ thuật 8 bước (CPU, RAM, Ổ cứng, GPU, Màn hình, Pin, Phím, Cổng kết nối).
- **Mã giảm giá (Coupons):** 4 mã đại diện cho 4 trường hợp (còn hạn theo tiền cố định, còn hạn theo %, đã hết hạn năm 2025, và mã có điều kiện đơn tối thiểu rất cao để test từ chối).
- **Đơn hàng:** 5 đơn hàng bao quát đủ **5 trạng thái vòng đời**: `pending`, `confirmed`, `shipping`, `completed`, `cancelled` (hỗ trợ cả tài khoản thành viên và khách vãng lai).
- **Thanh toán:** Đối soát COD và Chuyển khoản ngân hàng (có mã giao dịch, ảnh bill chuyển tiền).
- **Giỏ hàng CSDL:** Giỏ hàng thành viên lưu trữ sản phẩm chờ thanh toán.

---

### 2. DANH SÁCH TÀI KHOẢN DEMO & CƠ CHẾ BẢO MẬT

> [!IMPORTANT]
> **Tuyệt đối không lưu mật khẩu dạng văn bản thô (Plaintext).** Toàn bộ tài khoản demo đều được mã hóa bằng thuật toán Bcrypt thông qua hàm chuẩn `password_hash($plain, PASSWORD_BCRYPT)` của PHP, sẵn sàng để xác thực bằng `password_verify()` trong tầng Authentication MVC.

| Vai trò (Role) | Họ và tên | Email đăng nhập | Mật khẩu Plaintext | Chuỗi Password Hash (Bcrypt) trong CSDL |
| :---: | :--- | :--- | :---: | :--- |
| **admin** | Quản Trị Viên TL Gaming | `admin@tlgaming.vn` | `Admin@123456` | `$2y$12$Ag47Xej10Itp1rvzgvccpeCDbHK2i3Nzfrozj1IlUxihvlX16rQAG` |
| **customer** | Nguyễn Văn An | `nguyen.van.an@demo.vn` | `Customer@123` | `$2y$12$PXrgPDRuv.nE6m75BOgTqeRuqc3xVwZ6XEIgwIiA1vzoyf4XwLTgS` |
| **customer** | Trần Thị Bình | `tran.thi.binh@demo.vn` | `Customer@123` | `$2y$12$PXrgPDRuv.nE6m75BOgTqeRuqc3xVwZ6XEIgwIiA1vzoyf4XwLTgS` |
| **customer** | Lê Hoàng Long | `le.hoang.long@demo.vn` | `Customer@123` | `$2y$12$PXrgPDRuv.nE6m75BOgTqeRuqc3xVwZ6XEIgwIiA1vzoyf4XwLTgS` |

#### Đoạn mã kiểm tra đăng nhập mẫu trong PHP MVC:
```php
// Xác thực thông tin đăng nhập trong UserController hoặc AuthService
$user = $userModel->findByEmail($email);
if ($user && password_verify($inputPassword, $user['password_hash'])) {
    // Đăng nhập thành công, lưu session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
} else {
    // Sai email hoặc mật khẩu
    $error = 'Email hoặc mật khẩu không chính xác!';
}
```

---

### 3. HƯỚNG DẪN IMPORT DỮ LIỆU MẪU

#### Cách 1: Nạp qua giao diện phpMyAdmin
1. Mở trình duyệt truy cập: `http://localhost/phpmyadmin`.
2. Tại danh sách cơ sở dữ liệu cột bên trái, bấm chọn database `tl_gaming`.
3. Bấm vào tab **Nhập (Import)** trên thanh menu ngang.
4. Bấm nút **Choose File (Chọn tệp)** và chọn tệp:
   `E:\xampp\htdocs\tl-gaming\database\seed_tl_gaming.sql`
5. Nhấn nút **Nhập (Import)** ở góc dưới cùng để thực thi.

#### Cách 2: Nạp qua Command Line (CMD / PowerShell)
Mở PowerShell tại thư mục dự án và thực thi:
```powershell
cmd /c "`"E:\xampp\mysql\bin\mysql.exe`" -u root < database\seed_tl_gaming.sql"
```

---

### 4. CÁC CÂU LỆNH SQL KIỂM TRA VÀ ĐỐI SOÁT NGHIỆP VỤ

Sinh viên có thể chạy trực tiếp các câu lệnh sau trong tab **SQL** của phpMyAdmin hoặc console MySQL để kiểm chứng và thuyết minh trước hội đồng chấm đồ án:

#### 4.1. Thống kê số lượng bản ghi trên 14 bảng
```sql
SELECT 'users' AS table_name, count(*) AS total_rows FROM tl_gaming.users
UNION ALL SELECT 'categories', count(*) FROM tl_gaming.categories
UNION ALL SELECT 'products', count(*) FROM tl_gaming.products
UNION ALL SELECT 'product_images', count(*) FROM tl_gaming.product_images
UNION ALL SELECT 'product_specs', count(*) FROM tl_gaming.product_specs
UNION ALL SELECT 'product_units', count(*) FROM tl_gaming.product_units
UNION ALL SELECT 'product_inspections', count(*) FROM tl_gaming.product_inspections
UNION ALL SELECT 'carts', count(*) FROM tl_gaming.carts
UNION ALL SELECT 'cart_items', count(*) FROM tl_gaming.cart_items
UNION ALL SELECT 'coupons', count(*) FROM tl_gaming.coupons
UNION ALL SELECT 'orders', count(*) FROM tl_gaming.orders
UNION ALL SELECT 'order_items', count(*) FROM tl_gaming.order_items
UNION ALL SELECT 'payments', count(*) FROM tl_gaming.payments
UNION ALL SELECT 'coupon_usages', count(*) FROM tl_gaming.coupon_usages;
```

#### 4.2. Kiểm chứng đồng bộ tồn kho (Single Source of Truth)
> **Quy tắc:** Số tồn kho hiển thị ngoài danh mục `products.stock_quantity` bắt buộc phải khớp 100% với số lượng máy vật lý đang ở trạng thái `available` trong `product_units`.
```sql
SELECT 
    p.id, 
    p.sku, 
    p.name,
    p.stock_quantity AS cached_stock, 
    COUNT(CASE WHEN u.status = 'available' THEN 1 END) AS real_available_units,
    CASE 
        WHEN p.stock_quantity = COUNT(CASE WHEN u.status = 'available' THEN 1 END) 
        THEN 'KHỚP CHÍNH XÁC (MATCH)' 
        ELSE 'LỆCH (MISMATCH)' 
    END AS check_result
FROM tl_gaming.products p
LEFT JOIN tl_gaming.product_units u ON p.id = u.product_id
GROUP BY p.id, p.sku, p.name, p.stock_quantity;
```

#### 4.3. Kiểm chứng công thức tài chính đơn hàng
> **Quy tắc kế toán:** `final_amount = total_product_amount - discount_amount + shipping_fee`.
```sql
SELECT 
    id, 
    order_code, 
    status, 
    total_product_amount, 
    shipping_fee, 
    discount_amount, 
    final_amount,
    (total_product_amount - discount_amount + shipping_fee) AS calculated_amount,
    CASE 
        WHEN final_amount = (total_product_amount - discount_amount + shipping_fee) 
        THEN 'ĐÚNG (CORRECT)' 
        ELSE 'SAI (WRONG)' 
    END AS financial_audit
FROM tl_gaming.orders;
```

#### 4.4. Kiểm chứng đối soát thanh toán (COD & Chuyển khoản)
> **Quy tắc:** Số tiền giao dịch trong bảng `payments` phải khớp với `final_amount` của đơn hàng, trạng thái thanh toán phản ánh đúng tiến trình đơn hàng (đơn chờ thanh toán thì `payments.status = 'pending'`, đơn hủy thì `failed`, đơn hoàn tất thì `completed`).
```sql
SELECT 
    p.id AS payment_id,
    p.order_id,
    o.order_code,
    o.status AS order_status,
    p.payment_method,
    p.amount AS payment_amount,
    o.final_amount AS order_final_amount,
    p.status AS payment_status,
    p.transaction_code,
    p.confirmed_at,
    CASE WHEN p.amount = o.final_amount THEN 'KHỚP' ELSE 'LỆCH' END AS amount_match
FROM tl_gaming.payments p
JOIN tl_gaming.orders o ON p.order_id = o.id;
```

#### 4.5. Kiểm chứng vòng đời gán Serial máy cũ
> **Quy tắc:**
> - Đơn đang xử lý (`pending`, `confirmed`, `shipping`): máy serial ở trạng thái `reserved`.
> - Đơn đã giao xong (`completed`): máy serial ở trạng thái `sold`.
> - Đơn bị hủy (`cancelled`): máy serial được hoàn về kho ở trạng thái `available` để bán cho khách khác.
```sql
SELECT 
    oi.id AS item_id,
    oi.order_id,
    o.order_code,
    oi.unit_serial_number,
    pu.serial_number AS actual_unit_serial,
    pu.status AS unit_current_status,
    o.status AS order_status,
    CASE 
        WHEN o.status IN ('pending', 'confirmed', 'shipping') AND pu.status = 'reserved' THEN 'HỢP LỆ (RESERVED)'
        WHEN o.status = 'completed' AND pu.status = 'sold' THEN 'HỢP LỆ (SOLD)'
        WHEN o.status = 'cancelled' AND pu.status = 'available' THEN 'HỢP LỆ (RESTOCKED)'
        ELSE 'CẦN KIỂM TRA'
    END AS serial_audit
FROM tl_gaming.order_items oi
JOIN tl_gaming.orders o ON oi.order_id = o.id
LEFT JOIN tl_gaming.product_units pu ON oi.product_unit_id = pu.id;
```

#### 4.6. Kiểm chứng danh sách Voucher và Hoàn mã khi hủy đơn
```sql
-- Danh sách mã voucher và hạn dùng
SELECT 
    code, 
    discount_type, 
    discount_value, 
    min_order_amount, 
    start_date, 
    end_date, 
    CASE WHEN end_date < NOW() THEN 'ĐÃ HẾT HẠN' ELSE 'CÒN HẠN' END AS validity_status
FROM tl_gaming.coupons;

-- Lịch sử dùng mã (Đơn 5 bị hủy -> coupon_usages.status = 'cancelled' để hoàn mã)
SELECT 
    cu.id,
    c.code AS coupon_code,
    cu.order_id,
    o.order_code,
    cu.customer_email,
    cu.discount_applied,
    cu.status AS coupon_usage_status,
    o.status AS order_status
FROM tl_gaming.coupon_usages cu
JOIN tl_gaming.coupons c ON cu.coupon_id = c.id
JOIN tl_gaming.orders o ON cu.order_id = o.id;
```

#### 4.7. Kiểm chứng giỏ hàng thành viên
```sql
SELECT 
    c.id AS cart_id,
    u.full_name AS customer_name,
    p.name AS product_name,
    p.price AS unit_price,
    ci.quantity,
    (p.price * ci.quantity) AS item_total
FROM tl_gaming.carts c
JOIN tl_gaming.users u ON c.user_id = u.id
JOIN tl_gaming.cart_items ci ON c.id = ci.cart_id
JOIN tl_gaming.products p ON ci.product_id = p.id;
```

---

### 5. CƠ CHẾ IDEMPOTENT (CHẠY LẠI KHÔNG TRÙNG DỮ LIỆU)

Tệp `database/seed_tl_gaming.sql` được thiết kế theo nguyên lý **Idempotent** sử dụng cú pháp:
```sql
INSERT INTO `table_name` (...) VALUES (...)
ON DUPLICATE KEY UPDATE column1 = VALUES(column1), ...;
```
- Khi chạy import lần đầu: Toàn bộ dữ liệu mẫu sẽ được thêm mới.
- Khi chạy import lại lần thứ 2 hoặc nhiều lần: Hệ thống sẽ **cập nhật lại các trường dữ liệu** về trạng thái chuẩn của bản seed mà **không sinh ra các bản ghi trùng lặp** và **không vi phạm các ràng buộc UNIQUE**.
- Giúp sinh viên dễ dàng khôi phục lại dữ liệu demo ban đầu trước mỗi buổi báo cáo hoặc kiểm thử.
