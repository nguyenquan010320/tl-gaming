# TÀI LIỆU PHÂN TÍCH VÀ THIẾT KẾ CƠ SỞ DỮ LIỆU
## ĐỒ ÁN TỐT NGHIỆP: WEBSITE BÁN MÁY TÍNH CŨ TL GAMING

---

### MỤC LỤC
1. [Mục tiêu Cơ sở Dữ liệu](#1-mục-tiêu-cơ-sở-dữ-liệu)
2. [Danh sách 15 Bảng và Chức năng Nghiệp vụ](#2-danh-sách-15-bảng-và-chức-năng-nghiệp-vụ)
3. [Từ điển Dữ liệu Chi tiết (Detailed Data Dictionary)](#3-từ-điển-dữ-liệu-chi-tiết)
   - 3.1. Bảng `users`
   - 3.2. Bảng `categories`
   - 3.3. Bảng `products`
   - 3.4. Bảng `product_images`
   - 3.5. Bảng `product_specs`
   - 3.6. Bảng `product_units`
   - 3.7. Bảng `product_inspections`
   - 3.8. Bảng `inspection_items`
   - 3.9. Bảng `carts`
   - 3.10. Bảng `cart_items`
   - 3.11. Bảng `coupons`
   - 3.12. Bảng `orders`
   - 3.13. Bảng `order_items`
   - 3.14. Bảng `payments`
   - 3.15. Bảng `coupon_usages`
   - 3.16. Bảng `checkout_idempotency`
4. [Các Mối Quan hệ Thực thể (Entity Relationships)](#4-các-mối-quan-hệ-thực-thể)
5. [Quy tắc Nghiệp vụ và Ràng buộc Toàn vẹn (Business Rules)](#5-quy-tắc-nghiệp-vụ-và-ràng-buộc-toàn-vẹn)
   - 5.1. Quản lý Serial máy cũ và Gán đơn hàng
   - 5.2. Quản lý Tồn kho và Tránh mâu thuẫn dữ liệu
   - 5.3. Xử lý Voucher và Hạn mức Khách vãng lai
   - 5.4. Đóng băng Dữ liệu (Snapshot) và Bảo toàn Lịch sử
6. [Những Quyết định Thiết kế cùng Lý do (Design Decisions)](#6-những-quyết-định-thiết-kế-cùng-lý-do)
7. [Danh sách Các Vấn đề Cần Xác nhận (Pending Confirmations)](#7-danh-sách-các-vấn-đề-cần-xác-nhận)

---

### 1. MỤC TIÊU CƠ SỞ DỮ LIỆU

Cơ sở dữ liệu của hệ thống **TL Gaming** được thiết kế nhằm phục vụ bài toán thương mại điện tử chuyên biệt cho lĩnh vực **máy tính, laptop và linh kiện cũ/đã qua sử dụng**, đáp ứng các tiêu chuẩn kỹ thuật của đồ án tốt nghiệp:

1. **Chuẩn hóa và Tương thích:** Thiết kế chuẩn hóa theo dạng chuẩn 3NF (Third Normal Form), tương thích tối ưu với hệ quản trị cơ sở dữ liệu **MySQL 8.0** trên môi trường XAMPP (Windows).
2. **Quản lý Định danh Cá thể Hóa (Unique Serial/IMEI Tracking):** Khác biệt hoàn toàn với thương mại điện tử hàng mới nguyên seal (chỉ quản lý tồn kho số lượng chung), máy tính cũ đòi hỏi quản lý chính xác từng chiếc máy vật lý thông qua số Serial/Service Tag, gắn liền với ngoại hình riêng (Grade A/B/C), độ chai pin, và kết quả kiểm định kỹ thuật (inspection checklist).
3. **Bảo tồn Lịch sử Giao dịch và Đơn hàng (Audit Trail):** Thông tin sản phẩm, cấu hình, giá bán và bảo hành tại thời điểm khách đặt hàng được "đóng băng" (snapshot) trong chi tiết đơn hàng, ngăn ngừa tình trạng biến động giá hoặc thay đổi thông tin sản phẩm trong tương lai làm sai lệch doanh thu và chính sách bảo hành.
4. **Hỗ trợ Khách hàng Đa dạng (Guest & Member Checkout):** Cho phép đặt hàng nhanh mà không bắt buộc tạo tài khoản (khách vãng lai), đồng thời vẫn kiểm soát chặt chẽ việc áp dụng mã giảm giá và đối soát đơn hàng.
5. **Đơn giản, Rõ ràng cho Mô hình MVC PHP thuần:** Thiết kế cấu trúc bảng tường minh, kiểu dữ liệu chuẩn xác, không lạm dụng trigger hay thủ tục lưu trữ phức tạp, giúp sinh viên dễ dàng hiện thực hóa bằng PDO trong mô hình MVC thuần và tự tin giải trình trước hội đồng bảo vệ tốt nghiệp.

---

### 2. DANH SÁCH 15 BẢNG VÀ CHỨC NĂNG NGHIỆP VỤ

| STT | Tên bảng | Chức năng nghiệp vụ chính |
| :---: | :--- | :--- |
| **1** | `users` | Quản lý thông tin tài khoản người dùng, phân quyền giữa khách hàng (`customer`) và quản trị viên (`admin`). |
| **2** | `categories` | Phân cấp danh mục sản phẩm (Laptop Gaming, PC Đồ Họa, Linh kiện, Màn hình cũ...), hỗ trợ danh mục cha - con. |
| **3** | `products` | Lưu trữ thông tin chung của dòng sản phẩm/model máy, cờ `has_serials`, giá bán VND nguyên, bảo hành, tồn kho. |
| **4** | `product_images` | Lưu trữ danh sách ảnh chụp thực tế của sản phẩm (ảnh ngoại hình, các góc cạnh, cổng kết nối). |
| **5** | `product_specs` | Lưu trữ cấu hình kỹ thuật chi tiết của dòng máy (CPU, RAM, VGA, Ổ cứng, Tấm nền màn hình, Nguồn...). |
| **6** | `product_units` | Quản lý từng cá thể máy tính cũ vật lý theo số Serial/IMEI riêng, khóa duy nhất kết hợp `(id, product_id)`. |
| **7** | `product_inspections`| Lưu trữ phiên kiểm định chất lượng kỹ thuật phần cứng tổng thể của từng chiếc máy trước khi xuất bán. |
| **8** | `inspection_items`| Chuẩn hóa 3NF chi tiết 18 bước kiểm tra kỹ thuật chuyên sâu kèm ghi chú và kết quả từng hạng mục. |
| **9** | `carts` | Quản lý giỏ hàng cố định trên cơ sở dữ liệu cho khách hàng đã đăng nhập tài khoản. |
| **10** | `cart_items` | Lưu trữ các mặt hàng và số lượng mà thành viên thêm vào giỏ hàng. |
| **11** | `coupons` | Quản lý mã giảm giá (voucher) theo phần trăm (%) hoặc số tiền cố định VND, hạn mức sử dụng và thời hạn hiệu lực. |
| **12** | `orders` | Quản lý đơn hàng tổng thể, thông tin giao hàng, tổng tiền, chiết khấu và trạng thái xử lý đơn (hỗ trợ cả khách vãng lai). |
| **13** | `order_items` | Chi tiết mặt hàng trong đơn, lưu snapshot giá, khóa ngoại kết hợp `(product_unit_id, product_id)` và trigger chặn bán trùng serial active. |
| **14** | `payments` | Ghi nhận lịch sử và tiến trình thanh toán của đơn hàng (COD hoặc chuyển khoản thủ công, mặc định pending). |
| **15** | `coupon_usages` | Ghi nhận nhật ký lịch sử sử dụng mã voucher theo từng đơn hàng, hỗ trợ chống gian lận lặp mã cho cả khách vãng lai. |
| **16** | `checkout_idempotency` | Lưu khóa chống gửi trùng đơn hàng bền vững trong MySQL, gắn tác nhân, hash dữ liệu đặt hàng và đơn hàng đã tạo. |

---

### 3. TỪ ĐIỂN DỮ LIỆU CHI TIẾT (DETAILED DATA DICTIONARY)

#### 3.1. Bảng `users` (Tài khoản người dùng)
- **Mục đích:** Lưu trữ tài khoản người dùng đăng nhập hệ thống, hỗ trợ 2 vai trò `customer` và `admin`.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính định danh tài khoản. |
| `role` | `ENUM('customer','admin')` | NO | `'customer'` | INDEX | Vai trò phân quyền trên hệ thống. |
| `email` | `VARCHAR(191)` | NO | None | **UNIQUE** | Email đăng nhập duy nhất (191 ký tự tối ưu hóa utf8mb4 index). |
| `password_hash` | `VARCHAR(255)` | NO | None | None | Mật khẩu mã hóa an toàn bằng hàm `password_hash()` của PHP (Bcrypt/Argon2id). |
| `full_name` | `VARCHAR(100)` | NO | None | None | Họ và tên hiển thị của người dùng. |
| `phone` | `VARCHAR(20)` | YES | NULL | INDEX | Số điện thoại liên hệ nhận hàng. |
| `address` | `VARCHAR(255)` | YES | NULL | None | Địa chỉ mặc định của người dùng. |
| `status` | `ENUM('active','locked')` | NO | `'active'` | INDEX | Trạng thái tài khoản: đang hoạt động hoặc bị khóa. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm đăng ký tài khoản. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm cập nhật thông tin gần nhất. |

---

#### 3.2. Bảng `categories` (Danh mục sản phẩm)
- **Mục đích:** Phân loại sản phẩm theo cây phân cấp (cha - con), hỗ trợ lọc danh mục nhanh.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính danh mục. |
| `parent_id` | `INT UNSIGNED` | YES | NULL | **FK**, INDEX | Khóa ngoại trỏ đến `categories.id`. `ON DELETE RESTRICT ON UPDATE CASCADE`. Nếu là danh mục gốc thì giá trị là NULL. |
| `name` | `VARCHAR(100)` | NO | None | None | Tên danh mục (ví dụ: Laptop Gaming Cũ, PC Đồ Họa Cũ). |
| `slug` | `VARCHAR(120)` | NO | None | **UNIQUE** | Đường dẫn URL thân thiện (SEO). |
| `description` | `TEXT` | YES | NULL | None | Mô tả ngắn giới thiệu danh mục. |
| `display_order` | `INT` | NO | `0` | INDEX | Thứ tự ưu tiên hiển thị trên menu điều hướng. |
| `is_active` | `TINYINT(1)` | NO | `1` | INDEX | `1`: Cho phép hiển thị, `0`: Ẩn danh mục khỏi website. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm tạo danh mục. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm sửa danh mục gần nhất. |

---

#### 3.3. Bảng `products` (Dòng sản phẩm máy tính cũ)
- **Mục đích:** Lưu thông tin thương mại tổng quan của mẫu máy (Model).

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính sản phẩm. |
| `category_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ tới `categories.id`. `ON DELETE RESTRICT ON UPDATE CASCADE`. |
| `name` | `VARCHAR(255)` | NO | None | None | Tên sản phẩm hiển thị (ví dụ: Dell Gaming G15 5515 Ryzen 7 5800H RTX 3060 Cũ). |
| `slug` | `VARCHAR(255)` | NO | None | **UNIQUE** | Đường dẫn URL chuẩn SEO duy nhất. |
| `sku` | `VARCHAR(50)` | NO | None | **UNIQUE** | Mã định danh quản lý mẫu hàng (ví dụ: SKU-DELL-G15-01). |
| `summary` | `VARCHAR(500)` | YES | NULL | None | Đoạn tóm tắt đặc điểm nổi bật để hiển thị thẻ sản phẩm. |
| `description` | `LONGTEXT` | YES | NULL | None | Bài viết đánh giá chi tiết sản phẩm. |
| `price` | `BIGINT UNSIGNED` | NO | None | INDEX | Giá bán hiện tại bằng tiền đồng Việt Nam (VND nguyên, không số lẻ thập phân). |
| `original_price` | `BIGINT UNSIGNED` | YES | NULL | None | Giá niêm yết ban đầu hoặc giá khi mua mới để tính tỷ lệ tiết kiệm (VND). |
| `warranty_months` | `INT UNSIGNED` | NO | `12` | None | Thời gian bảo hành tiêu chuẩn áp dụng cho dòng máy (tính theo tháng, ví dụ: 6, 12 tháng). |
| `condition_overview` | `VARCHAR(100)` | NO | None | None | Tóm tắt tình trạng máy dòng này (ví dụ: "Đã qua sử dụng 98-99%", "Hàng thanh lý văn phòng"). |
| `stock_quantity` | `INT UNSIGNED` | NO | `0` | INDEX | Tổng số lượng máy vật lý sẵn sàng bán (`available`) trong kho (trường đệm hiển thị nhanh, đồng bộ với `product_units`). |
| `has_serials` | `TINYINT(1)` | NO | `1` | INDEX | `1`: Sản phẩm quản lý theo số Serial riêng (PC, Laptop, VGA...); `0`: Sản phẩm phụ kiện/linh kiện quản lý theo số lượng thông thường. Ràng buộc `CHECK (has_serials IN (0, 1))`. |
| `thumbnail` | `VARCHAR(255)` | YES | NULL | None | Đường dẫn tệp ảnh đại diện chính của sản phẩm. |
| `status` | `ENUM('draft','published','out_of_stock','archived')` | NO | `'draft'` | INDEX | Trạng thái hiển thị sản phẩm trên web. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm tạo sản phẩm. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm cập nhật sản phẩm gần nhất. |

---

#### 3.4. Bảng `product_images` (Bộ sưu tập ảnh sản phẩm)
- **Mục đích:** Lưu trữ nhiều ảnh chụp thực tế cho một dòng sản phẩm cũ.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính của ảnh. |
| `product_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `products.id`. `ON DELETE CASCADE ON UPDATE CASCADE` (Khi xóa sản phẩm chưa phát sinh đơn, ảnh liên quan được dọn dẹp). |
| `image_url` | `VARCHAR(255)` | NO | None | None | Đường dẫn lưu trữ hình ảnh trên máy chủ (ví dụ: `/uploads/products/dell-g15-1.webp`). |
| `alt_text` | `VARCHAR(255)` | YES | NULL | None | Thuộc tính alt cho thẻ img hỗ trợ SEO và trợ năng. |
| `sort_order` | `INT` | NO | `0` | None | Thứ tự hiển thị trong bộ sưu tập (gallery). |
| `is_primary` | `TINYINT(1)` | NO | `0` | INDEX | `1`: Ảnh chính đại diện, `0`: Ảnh phụ. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm tải ảnh lên. |

---

#### 3.5. Bảng `product_specs` (Thông số kỹ thuật sản phẩm)
- **Mục đích:** Lưu trữ cấu hình kỹ thuật dạng cặp Key - Value linh hoạt cho máy tính cũ.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính của thông số kỹ thuật. |
| `product_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `products.id`. `ON DELETE CASCADE ON UPDATE CASCADE`. |
| `group_name` | `VARCHAR(100)` | YES | NULL | None | Nhóm thông số (ví dụ: "Vi xử lý & Đồ họa", "Bộ nhớ & Lưu trữ", "Màn hình"). |
| `spec_name` | `VARCHAR(100)` | NO | None | None | Tên linh kiện/thông số (ví dụ: CPU, RAM, Ổ cứng, VGA, Màn hình, Cổng kết nối). |
| `spec_value` | `VARCHAR(255)` | NO | None | None | Giá trị chi tiết (ví dụ: "Intel Core i7-11800H", "16GB DDR4 3200MHz", "SSD 512GB NVMe M.2"). |
| `sort_order` | `INT` | NO | `0` | None | Thứ tự sắp xếp hiển thị trên bảng thông số. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm tạo thông số. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm sửa đổi thông số. |

> **Chỉ mục kết hợp:** `INDEX idx_prod_spec (product_id, spec_name)` giúp truy vấn lọc cấu hình nhanh chóng.

---

#### 3.6. Bảng `carts` (Giỏ hàng của tài khoản đã đăng nhập)
- **Mục đích:** Lưu trữ giỏ hàng cố định của thành viên giữa các phiên đăng nhập. Khách vãng lai sẽ dùng cơ chế PHP Session riêng biệt.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính giỏ hàng. |
| `user_id` | `INT UNSIGNED` | NO | None | **FK, UNIQUE** | Khóa ngoại 1-1 với `users.id`. `ON DELETE CASCADE ON UPDATE CASCADE`. Mỗi người dùng chỉ có duy nhất 1 giỏ hàng hoạt động. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm khởi tạo giỏ hàng. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm thay đổi giỏ hàng gần nhất. |

---

#### 3.7. Bảng `cart_items` (Chi tiết mặt hàng trong giỏ)
- **Mục đích:** Lưu danh sách các dòng sản phẩm và số lượng muốn mua trong giỏ của thành viên.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính của dòng giỏ hàng. |
| `cart_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `carts.id`. `ON DELETE CASCADE ON UPDATE CASCADE`. |
| `product_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `products.id`. `ON DELETE CASCADE ON UPDATE CASCADE`. |
| `quantity` | `INT UNSIGNED` | NO | `1` | None | Số lượng sản phẩm muốn mua (phải > 0 và <= tồn kho khả dụng). |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm thêm vào giỏ. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm cập nhật số lượng. |

> **Ràng buộc duy nhất:** `UNIQUE KEY uq_cart_product (cart_id, product_id)` để đảm bảo mỗi sản phẩm chỉ xuất hiện 1 dòng trong giỏ (nếu thêm tiếp thì tăng `quantity`).

---

#### 3.8. Bảng `orders` (Đơn đặt hàng)
- **Mục đích:** Quản lý đơn hàng tổng thể, lưu trữ thông tin giao dịch của cả thành viên và khách vãng lai.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính đơn hàng. |
| `order_code` | `VARCHAR(32)` | NO | None | **UNIQUE** | Mã đơn hàng đối soát định dạng chuẩn (ví dụ: `TLG-20261110-8A7F`). |
| `user_id` | `INT UNSIGNED` | YES | NULL | **FK**, INDEX | Khóa ngoại trỏ đến `users.id`. `ON DELETE SET NULL ON UPDATE CASCADE`. **NULL nếu khách vãng lai mua hàng không đăng nhập**. |
| `customer_name` | `VARCHAR(100)` | NO | None | None | Họ tên người nhận hàng. |
| `customer_email` | `VARCHAR(191)` | NO | None | INDEX | Email người nhận (để gửi biên lai đơn và kiểm tra voucher). |
| `customer_phone` | `VARCHAR(20)` | NO | None | INDEX | Số điện thoại người nhận hàng (dùng tra cứu đơn và định danh khách). |
| `shipping_address` | `VARCHAR(255)` | NO | None | None | Địa chỉ chi tiết nhận hàng (Số nhà, đường/thôn xóm). |
| `shipping_city` | `VARCHAR(100)` | NO | None | None | Tỉnh/Thành phố nhận hàng. |
| `shipping_district` | `VARCHAR(100)` | YES | NULL | None | Quận/Huyện/Thị xã nhận hàng. |
| `customer_note` | `VARCHAR(500)` | YES | NULL | None | Lời nhắn hoặc yêu cầu cài đặt thêm của khách (cài thêm Win, Office, test game...). |
| `total_product_amount`| `BIGINT UNSIGNED` | NO | None | CHECK | Tổng tiền sản phẩm trước khi áp dụng giảm giá (VND), tính hoàn toàn trên Server từ bảng `products`. `CHECK (total_product_amount >= 0)`. |
| `shipping_fee` | `BIGINT UNSIGNED` | NO | `0` | CHECK | Phí vận chuyển giao hàng tận nơi (VND). `CHECK (shipping_fee >= 0)`. |
| `discount_amount` | `BIGINT UNSIGNED` | NO | `0` | CHECK | Số tiền được giảm giá qua voucher (VND). Ràng buộc `chk_orders_discount`: `CHECK (discount_amount >= 0 AND discount_amount <= total_product_amount)`. |
| `final_amount` | `BIGINT UNSIGNED` | NO | None | CHECK | Tổng tiền thực tế cần thanh toán (VND). Ràng buộc `chk_orders_final_amount`: `CHECK (final_amount >= 0 AND final_amount = total_product_amount + shipping_fee - discount_amount)`. |
| `coupon_id` | `INT UNSIGNED` | YES | NULL | **FK**, INDEX | Khóa ngoại trỏ đến `coupons.id`. `ON DELETE RESTRICT ON UPDATE CASCADE`. Lưu mã giảm giá đã áp dụng. |
| `status` | `ENUM('pending','confirmed','shipping','completed','cancelled')` | NO | `'pending'` | INDEX | Vòng đời đơn hàng: Chờ xác nhận -> Đã xác nhận -> Đang giao -> Đã hoàn tất -> Đã hủy. |
| `cancel_reason` | `VARCHAR(255)` | YES | NULL | None | Lý do hủy đơn (nếu trạng thái là `cancelled`). |
| `cancelled_at` | `DATETIME` | YES | NULL | None | Thời điểm đơn hàng bị hủy. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | INDEX | Thời điểm đặt hàng. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm cập nhật trạng thái đơn gần nhất. |

---

#### 3.9. Bảng `order_items` (Chi tiết mặt hàng và Gán máy vật lý)
- **Mục đích:** Lưu trữ chi tiết sản phẩm đã mua, **đóng băng (snapshot)** thông số, đơn giá và **gán trực tiếp chiếc máy có số Serial cụ thể**.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính của chi tiết đơn hàng. |
| `order_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `orders.id`. `ON DELETE RESTRICT ON UPDATE CASCADE` (Tuyệt đối không xóa dòng hóa đơn đã ghi nhận). |
| `product_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `products.id`. `ON DELETE RESTRICT ON UPDATE CASCADE` (Ngăn chặn xóa sản phẩm gốc khi đã có đơn). |
| `product_unit_id` | `INT UNSIGNED` | YES | NULL | **FK**, INDEX | Khóa ngoại đơn trỏ đến `product_units.id`. `ON DELETE RESTRICT ON UPDATE CASCADE`. Gán máy vật lý có serial cụ thể. |
| `(product_unit_id, product_id)` | Composite | YES | NULL | **FK (Composite)**, INDEX | Khóa ngoại kết hợp trỏ đến `product_units(id, product_id)`. `ON DELETE RESTRICT ON UPDATE CASCADE`. Ngăn chặn triệt để lỗi logic gán nhầm Serial của sản phẩm A vào dòng hàng của sản phẩm B. |
| `(order_id, product_unit_id)` | Composite | YES | NULL | **UNIQUE (`uq_order_items_order_unit`)** | Khóa duy nhất kết hợp ngăn chặn cùng một chiếc máy vật lý (`product_unit_id`) bị chèn lặp 2 lần trong cùng một đơn hàng (`order_id`). Đối với hàng không có serial (`product_unit_id IS NULL`), MySQL cho phép nhiều dòng `NULL` trong cùng đơn hàng. |
| `product_name` | `VARCHAR(255)` | NO | None | None | **Snapshot**: Lưu nguyên văn tên sản phẩm tại thời điểm chốt đơn. |
| `product_sku` | `VARCHAR(50)` | NO | None | None | **Snapshot**: Lưu mã SKU của sản phẩm tại thời điểm mua. |
| `unit_serial_number` | `VARCHAR(100)` | YES | NULL | INDEX | **Snapshot**: Lưu chuỗi số Serial/Service Tag máy vật lý được gán vào đơn. |
| `price` | `BIGINT UNSIGNED` | NO | None | None | **Snapshot**: Đơn giá bán tại thời điểm mua (VND). |
| `quantity` | `INT UNSIGNED` | NO | `1` | None | Số lượng (đối với máy tính cũ có serial riêng, quy định nghiệp vụ bắt buộc `quantity = 1` trên mỗi dòng). |
| `subtotal` | `BIGINT UNSIGNED` | NO | None | None | Thành tiền của dòng: `price * quantity` (VND). |
| `warranty_period` | `VARCHAR(50)` | NO | None | None | **Snapshot**: Thời hạn bảo hành cam kết tại thời điểm mua (ví dụ: "12 tháng đổi mới linh kiện"). |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm tạo dòng chi tiết đơn hàng. |

---

#### 3.10. Bảng `payments` (Lịch sử thanh toán đơn hàng)
- **Mục đích:** Ghi nhận đối soát tiền thanh toán, hỗ trợ cả 2 hình thức: Thanh toán khi nhận hàng (COD) và Chuyển khoản ngân hàng thủ công.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính giao dịch thanh toán. |
| `order_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `orders.id`. `ON DELETE RESTRICT ON UPDATE CASCADE`. |
| `payment_method` | `ENUM('cod','bank_transfer')` | NO | None | INDEX | Phương thức thanh toán: COD hoặc Chuyển khoản ngân hàng. |
| `amount` | `BIGINT UNSIGNED` | NO | None | CHECK | Số tiền thanh toán ghi nhận (VND, `CHECK (amount >= 0)` — hỗ trợ cả đơn hàng `0đ` khi voucher hợp lệ giảm 100% tiền hàng và phí vận chuyển = `0đ`, vẫn giữ `status = 'pending'`). |
| `status` | `ENUM('pending','completed','failed','refunded')` | NO | `'pending'` | INDEX | Trạng thái giao dịch: Chờ duyệt tiền -> Hoàn tất -> Thất bại -> Đã hoàn tiền. |
| `transaction_code` | `VARCHAR(100)` | YES | NULL | INDEX | Mã giao dịch ngân hàng / Mã tham chiếu do khách hoặc ngân hàng cung cấp. |
| `bank_name` | `VARCHAR(100)` | YES | NULL | None | Tên ngân hàng nhận thanh toán (ví dụ: Vietcombank, MBBank, VietinBank). |
| `account_number` | `VARCHAR(50)` | YES | NULL | None | Số tài khoản ngân hàng của cửa hàng nhận tiền. |
| `transfer_note` | `VARCHAR(255)` | YES | NULL | None | Nội dung chuyển khoản khách ghi (ví dụ: `TLG 20261110 8A7F`). |
| `payment_proof_image`| `VARCHAR(255)` | YES | NULL | None | Đường dẫn ảnh chụp biên lai/bill chuyển khoản khách upload lên để admin đối soát. |
| `confirmed_by_user_id`| `INT UNSIGNED` | YES | NULL | **FK**, INDEX | Khóa ngoại trỏ đến `users.id`. `ON DELETE SET NULL ON UPDATE CASCADE`. Admin trực tiếp kiểm tra và bấm xác nhận tiền về tài khoản. |
| `confirmed_at` | `DATETIME` | YES | NULL | None | Thời điểm admin duyệt giao dịch. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm tạo bản ghi thanh toán. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm cập nhật trạng thái thanh toán. |

---

#### 3.11. Bảng `coupons` (Mã giảm giá / Voucher)
- **Mục đích:** Quản lý các chương trình ưu đãi giảm giá theo phần trăm hoặc số tiền cố định.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính mã giảm giá. |
| `code` | `VARCHAR(50)` | NO | None | **UNIQUE** | Mã ký tự voucher khách nhập (ví dụ: `TLGAMING500K`, `HE2026`). |
| `description` | `VARCHAR(255)` | YES | NULL | None | Diễn giải chương trình khuyến mãi. |
| `discount_type` | `ENUM('percentage','fixed_amount')` | NO | None | None | Loại giảm giá: theo phần trăm (`%`) hoặc số tiền cố định (`VND`). |
| `discount_value` | `BIGINT UNSIGNED` | NO | None | None | Giá trị giảm: Số phần trăm (1 đến 100) hoặc số tiền cụ thể (ví dụ: 500,000 VND). |
| `max_discount_amount`| `BIGINT UNSIGNED` | YES | NULL | None | Số tiền giảm tối đa (áp dụng bắt buộc khi `discount_type = 'percentage'`). |
| `min_order_amount` | `BIGINT UNSIGNED` | NO | `0` | None | Giá trị đơn hàng tối thiểu để được áp dụng mã (VND). |
| `usage_limit` | `INT UNSIGNED` | NO | `0` | CHECK | Giới hạn tổng số lượt sử dụng toàn hệ thống (`0` nghĩa là không giới hạn). Ràng buộc `chk_coupons_usage_bound`: `usage_limit = 0 OR usage_count <= usage_limit`. |
| `usage_count` | `INT UNSIGNED` | NO | `0` | CHECK | Số lượt mã đã được khách hàng sử dụng thực tế (kiểu `INT UNSIGNED` chống âm `< 0` và không vượt quá `usage_limit`). |
| `per_user_limit` | `INT UNSIGNED` | NO | `1` | None | Số lần tối đa một khách hàng (user hoặc sđt/email vãng lai) được dùng mã này. |
| `start_date` | `DATETIME` | NO | None | INDEX | Thời điểm bắt đầu có hiệu lực. |
| `end_date` | `DATETIME` | NO | None | INDEX | Thời điểm hết hạn sử dụng. |
| `is_active` | `TINYINT(1)` | NO | `1` | INDEX | `1`: Mã đang mở kích hoạt, `0`: Tạm ngưng áp dụng. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm tạo voucher. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm cập nhật voucher. |

---

#### 3.12. Bảng `coupon_usages` (Nhật ký sử dụng mã giảm giá)
- **Mục đích:** Lưu lịch sử kích hoạt mã giảm giá cho từng đơn hàng cụ thể, giúp đối soát và ngăn chặn lặp mã gian lận.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính nhật ký dùng mã. |
| `coupon_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `coupons.id`. `ON DELETE RESTRICT ON UPDATE CASCADE`. |
| `order_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `orders.id`. `ON DELETE RESTRICT ON UPDATE CASCADE`. |
| `user_id` | `INT UNSIGNED` | YES | NULL | **FK**, INDEX | Khóa ngoại trỏ đến `users.id`. `ON DELETE SET NULL ON UPDATE CASCADE`. NULL nếu khách vãng lai. |
| `customer_email` | `VARCHAR(191)` | NO | None | INDEX | Email khách hàng nhận diện lượt dùng đối với cả khách vãng lai. |
| `customer_phone` | `VARCHAR(20)` | NO | None | INDEX | Số điện thoại khách hàng nhận diện lượt dùng đối với cả khách vãng lai. |
| `discount_applied` | `BIGINT UNSIGNED` | NO | None | None | Số tiền thực tế được khấu trừ vào đơn hàng này (VND). |
| `used_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm ghi nhận sử dụng mã. |
| `status` | `ENUM('used','cancelled')` | NO | `'used'` | INDEX | Trạng thái lượt dùng: `used` (hợp lệ) hoặc `cancelled` (hoàn lại lượt khi hủy đơn). |

---

#### 3.13. Bảng `product_units` (Cá thể máy tính cũ vật lý / Serial)
- **Mục đích:** Quản lý từng chiếc máy tính vật lý trong kho có số Serial/Service Tag riêng biệt.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính máy vật lý. Thuộc `UNIQUE KEY uq_unit_id_product (id, product_id)`. |
| `product_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến dòng sản phẩm `products.id`. `ON DELETE RESTRICT ON UPDATE CASCADE`. Thuộc `UNIQUE KEY uq_unit_id_product (id, product_id)`. |
| `serial_number` | `VARCHAR(100)` | NO | None | **UNIQUE** | Số Serial / Service Tag / IMEI duy nhất của chiếc máy vật lý. |
| `hardware_condition_detail` | `TEXT` | YES | NULL | None | Mô tả thực tế ngoại hình và linh kiện chiếc máy này (ví dụ: "Xước nhẹ mặt đáy, màn hình không điểm chết, phím không bóng"). |
| `battery_health` | `INT UNSIGNED` | YES | NULL | None | Tỉ lệ % sức khỏe pin thực tế đối với laptop (ví dụ: `92` nghĩa là chai 8%). |
| `cosmetic_grade` | `ENUM('Grade A','Grade B','Grade C')` | NO | `'Grade A'` | INDEX | Phân loại ngoại hình: Grade A (98-99% Like New), Grade B (90-95% trầy nhẹ), Grade C (Cũ xước nhiều). |
| `status` | `ENUM('available','reserved','sold','repairing','returned')` | NO | `'available'` | INDEX | Trạng thái máy: Khả dụng bán (`available`), Đã giữ chỗ cho đơn (`reserved`), Đã bán xuất kho (`sold`), Đang bảo hành sửa chữa (`repairing`), Khách trả lại chờ kiểm định (`returned`). |
| `import_price` | `BIGINT UNSIGNED` | YES | NULL | None | Giá vốn nhập vào của riêng chiếc máy này (VND, phục vụ tính lãi lỗ cho cửa hàng). |
| `imported_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Ngày nhập kho chiếc máy này. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm ghi nhận máy vào CSDL. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm cập nhật thông tin máy. |

---

#### 3.7. Bảng `product_inspections` (Biên bản kiểm định chất lượng máy)
- **Mục đích:** Lưu phiên kiểm định phần cứng tổng thể của kỹ thuật viên đối với từng chiếc máy cụ thể trước khi bán hoặc khi nhập kho.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính biên bản kiểm định. |
| `unit_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `product_units.id`. `ON DELETE CASCADE ON UPDATE CASCADE`. |
| `inspector_id` | `INT UNSIGNED` | YES | NULL | **FK**, INDEX | Khóa ngoại trỏ đến `users.id` (Admin hoặc Kỹ thuật viên kiểm tra). `ON DELETE SET NULL ON UPDATE CASCADE`. |
| `inspection_date` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | INDEX | Thời điểm tiến hành kiểm định kỹ thuật. |
| `checklist_overall_result` | `ENUM('passed','conditional_pass','failed')` | NO | `'passed'` | INDEX | Kết luận tổng thể: Đạt chuẩn xuất bán (`passed`), Đạt có điều kiện (`conditional_pass`), Không đạt (`failed`). |
| `notes` | `TEXT` | YES | NULL | None | Ghi chú kỹ thuật chi tiết kết quả test tổng quan. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm tạo biên bản. |

---

#### 3.8. Bảng `inspection_items` (Chi tiết 18 bước kiểm định kỹ thuật chuyên sâu)
- **Mục đích:** Chuẩn hóa 3NF quy trình kiểm định 18 bước (khớp với công bố giao diện người dùng), lưu trữ kết quả và ghi chú độc lập cho từng hạng mục kiểm tra phần cứng.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính của bước kiểm tra. |
| `inspection_id` | `INT UNSIGNED` | NO | None | **FK**, INDEX | Khóa ngoại trỏ đến `product_inspections.id`. `ON DELETE CASCADE ON UPDATE CASCADE`. |
| `step_number` | `TINYINT UNSIGNED` | NO | None | INDEX | Thứ tự bước từ 1 đến 18 (`CHECK (step_number BETWEEN 1 AND 18)`). |
| `group_name` | `VARCHAR(100)` | NO | None | None | Tên nhóm hạng mục (Ngoại quan, Màn hình, Hiệu năng, Bộ nhớ, Bàn phím, Pin, Kết nối, Âm thanh). |
| `item_name` | `VARCHAR(150)` | NO | None | None | Tên cụ thể của bước kiểm tra. |
| `result` | `ENUM('pass','fail','warning','not_applicable')` | NO | `'pass'` | INDEX | Kết quả: `pass` (Đạt), `fail` (Hỏng), `warning` (Cảnh báo chai/xước), `not_applicable` (Không áp dụng, ví dụ pin/bản lề đối với máy PC để bàn). |
| `notes` | `VARCHAR(255)` | YES | NULL | None | Ghi chú thông số đo lường thực tế (nhiệt độ tải, % sức khỏe, số giờ chạy...). |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm lưu kết quả bước. |

> **Khóa duy nhất kết hợp:** `UNIQUE KEY uq_inspection_step (inspection_id, step_number)` đảm bảo mỗi biên bản chỉ có đúng 1 bản ghi cho mỗi bước từ 1 đến 18.

---

#### 3.16. Bảng `checkout_idempotency` (Khóa chống đặt đơn hàng trùng lặp)
- **Mục đích:** Lưu trữ bền vững trong MySQL các `idempotency_key` đã được sử dụng khi đặt hàng, tránh phụ thuộc hoàn toàn vào Session/File và đảm bảo trả về đúng đơn hàng đã commit kể cả khi tiến trình PHP dừng giữa chừng.

| Tên trường | Kiểu dữ liệu | Nullable | Mặc định | Khóa / Chỉ mục | Ràng buộc & Diễn giải |
| :--- | :--- | :---: | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | NO | AUTO_INCREMENT | **PK** | Khóa chính bản ghi idempotency. |
| `idempotency_key` | `VARCHAR(64)` | NO | None | **UNIQUE** | Mã token chống gửi trùng được Server cấp theo phiên. |
| `actor_hash` | `CHAR(64)` | NO | None | INDEX | Mã băm tác nhân đặt hàng (`user_id` hoặc `session_id`). |
| `payload_hash` | `CHAR(64)` | NO | None | None | Mã băm SHA-256 giỏ hàng và thông tin đặt đơn; chặn dùng lại khóa cũ cho payload khác. |
| `order_id` | `INT UNSIGNED` | YES | NULL | **FK**, INDEX | Khóa ngoại trỏ đến `orders.id` (`ON DELETE SET NULL ON UPDATE CASCADE`). |
| `order_code` | `VARCHAR(32)` | YES | NULL | None | Mã đơn hàng đã tạo thành công. |
| `final_amount` | `BIGINT UNSIGNED` | NO | `0` | None | Tổng tiền thanh toán cuối cùng của đơn hàng (VND). |
| `status` | `ENUM('processing','completed')` | NO | `'processing'` | None | Trạng thái xử lý khóa idempotency. |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | None | Thời điểm khởi tạo bản ghi. |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | None | Thời điểm cập nhật gần nhất. |

---

### 4. CÁC MỐI QUAN HỆ THỰC THỂ (ENTITY RELATIONSHIPS)

#### 4.1. Mối quan hệ 1 - 1 (One-to-One)
1. **`users` (1) — (0..1) `carts`:**
   - Mỗi người dùng đăng nhập có tối đa một giỏ hàng tồn tại trên hệ thống. Khóa ngoại `carts.user_id` có chỉ mục `UNIQUE`.

#### 4.2. Mối quan hệ 1 - N (One-to-Many)
1. **`categories` (1) — (0..n) `categories` (Đệ quy danh mục cha - con):**
   - Một danh mục cha có thể chứa nhiều danh mục con thông qua trường `categories.parent_id`.
2. **`categories` (1) — (0..n) `products`:**
   - Một danh mục sản phẩm chứa nhiều dòng sản phẩm (`category_id`).
3. **`products` (1) — (0..n) `product_images`:**
   - Một dòng sản phẩm có nhiều hình ảnh mô tả thực tế (`product_id`).
4. **`products` (1) — (0..n) `product_specs`:**
   - Một dòng sản phẩm có nhiều dòng thông số phần cứng kỹ thuật (`product_id`).
5. **`products` (1) — (0..n) `product_units`:**
   - Một dòng sản phẩm/model máy có thể có nhiều chiếc máy vật lý trong kho mang các số Serial khác nhau (`product_id`).
6. **`product_units` (1) — (0..n) `product_inspections`:**
   - Một chiếc máy vật lý có thể trải qua nhiều lần kiểm định chất lượng (lúc nhập kho, định kỳ, sau sửa chữa) (`unit_id`).
7. **`product_inspections` (1) — (18) `inspection_items`:**
   - Một phiên kiểm định kỹ thuật có 18 bước kiểm tra phần cứng chi tiết tương ứng (`inspection_id`). `ON DELETE CASCADE ON UPDATE CASCADE`.
8. **`carts` (1) — (0..n) `cart_items`:**
   - Một giỏ hàng thành viên chứa nhiều sản phẩm (`cart_id`).
9. **`orders` (1) — (1..n) `order_items`:**
   - Một đơn hàng bao gồm một hoặc nhiều dòng chi tiết sản phẩm (`order_id`).
10. **`orders` (1) — (1..n) `payments`:**
    - Một đơn hàng có thể có nhiều bản ghi thanh toán tương ứng (ví dụ: khách chuyển khoản lần đầu thất bại, sau đó chuyển khoản lại hoặc đổi sang COD) (`order_id`).
11. **`coupons` (1) — (0..n) `coupon_usages`:**
    - Một mã giảm giá có thể được sử dụng trong nhiều đơn hàng khác nhau (`coupon_id`).
12. **`users` (0..1) — (0..n) `orders`:**
    - Một khách hàng thành viên có thể đặt nhiều đơn hàng. Nếu là khách vãng lai, `orders.user_id` mang giá trị `NULL`.
13. **`users` (0..1) — (0..n) `coupon_usages`:**
    - Một khách hàng thành viên có thể có nhiều nhật ký dùng voucher. Nếu khách vãng lai, `coupon_usages.user_id` mang giá trị `NULL`.
14. **`users` (0..1) — (0..n) `product_inspections`:**
    - Một nhân viên kỹ thuật / admin có thể thực hiện kiểm định nhiều máy tính (`inspector_id`).
15. **`users` (0..1) — (0..n) `payments`:**
    - Một admin có thể duyệt xác nhận đối soát cho nhiều khoản thanh toán chuyển khoản (`confirmed_by_user_id`).
16. **`orders` (0..1) — (0..n) `checkout_idempotency`:**
    - Một đơn hàng sau khi commit được liên kết với bản ghi khóa idempotency tương ứng (`order_id`) để phục hồi kết quả khi gửi lại cùng request.

#### 4.3. Mối quan hệ N - N (Many-to-Many - Đã chuẩn hóa qua bảng trung gian)
1. **`carts` — `products`:** Được liên kết nhiều - nhiều thông qua bảng trung gian `cart_items`.
2. **`orders` — `products`:** Được liên kết nhiều - nhiều thông qua bảng trung gian `order_items`.
3. **`orders` — `product_units`:** Được phân rã thông qua bảng chi tiết `order_items` (kèm quy tắc mỗi dòng `order_item` gắn chính xác một máy vật lý có `quantity = 1`).
4. **`users` (hoặc Khách vãng lai) — `coupons`:** Được liên kết thông qua bảng trung gian `coupon_usages`.

---

### 5. QUY TẮC NGHIỆP VỤ VÀ RÀNG BUỘC TOÀN VẸN (BUSINESS RULES)

#### 5.1. Quản lý Serial máy cũ, Phân tích Race Condition của Trigger và Cơ chế Khóa Dòng (Row Locking)
- **Đặc thù máy cũ (`has_serials = 1`) và phụ kiện quản lý theo số lượng (`has_serials = 0`):**
  - Với sản phẩm có `has_serials = 1` (PC, Laptop, Linh kiện có serial riêng): Mỗi chiếc máy trong `product_units` là một thực thể vật lý độc nhất vô nhị (`serial_number` UNIQUE). Khi đưa vào `order_items`, bắt buộc `product_unit_id IS NOT NULL` và `quantity = 1`.
  - Với sản phẩm có `has_serials = 0` (phụ kiện/linh kiện quản lý theo số lượng): Không tạo bản ghi trong `product_units`, khi đưa vào `order_items` thì `product_unit_id IS NULL`, `unit_serial_number IS NULL` và `quantity >= 1`.
- **Phân tích Race Condition của Trigger trong InnoDB (`REPEATABLE READ`) và giải pháp phòng vệ nhiều lớp:**
  - *Bản chất kỹ thuật:* Trong MySQL/InnoDB với mức cô lập mặc định `REPEATABLE READ`, câu lệnh `SELECT COUNT(*)` bên trong `BEFORE INSERT` trigger (`tg_check_order_item_unit_active_insert`) là một **Consistent Non-Locking Snapshot Read** (đọc snapshot không khóa). Nếu hai giao dịch A và B cùng lúc thực hiện `INSERT INTO order_items` cho cùng một `product_unit_id` mà **không khóa dòng cha trước**, cả hai trigger đều đọc snapshot trước khi giao dịch kia `COMMIT` và đều thấy `v_active_count = 0`, dẫn đến nguy cơ lọt qua trigger nếu chỉ dựa vào trigger!
  - *Giải pháp phòng vệ 3 lớp (Defense-in-Depth):*
    1. **Lớp 1 — Khóa dòng độc quyền ở tầng Giao dịch (Cơ chế chính chống Race Condition đồng thời):** Trước khi tạo `order_items` cho máy có serial (`has_serials = 1`), ứng dụng bắt buộc mở Transaction và thực thi:
       - `SELECT id, serial_number, status FROM product_units WHERE id = :unit_id AND product_id = :product_id FOR UPDATE` (khóa X-Lock trực tiếp bản ghi máy vật lý trên bảng `product_units`).
       - `UPDATE product_units SET status = 'reserved' WHERE id = :unit_id AND status = 'available'` và kiểm tra `rowCount() === 1`. Nếu giao dịch B chạy chồng lấn khi giao dịch A đang giữ khóa, B bắt buộc phải chờ khóa của A; ngay khi A commit (`status` thành `'reserved'`), B được giải phóng khóa nhưng nhận `rowCount() === 0` và lập tức `ROLLBACK`.
    2. **Lớp 2 — Khóa duy nhất kết hợp trong cùng đơn hàng (`uq_order_items_order_unit`) và Khóa ngoại kết hợp (`fk_order_items_unit_product`):**
       - `UNIQUE KEY uq_order_items_order_unit (order_id, product_unit_id)` sử dụng Unique Index của InnoDB (luôn kiểm tra khóa kể cả giữa các giao dịch chưa commit) để chặn tuyệt đối việc cùng một `product_unit_id` xuất hiện 2 lần trong cùng một đơn hàng (`order_id`).
       - `FOREIGN KEY (product_unit_id, product_id) REFERENCES product_units (id, product_id)` chặn tuyệt đối việc gán nhầm Serial của sản phẩm A cho dòng hàng của sản phẩm B.
    3. **Lớp 3 — Trigger kiểm tra chéo liên đơn hàng (`tg_check_order_item_unit_active_insert` / `_update`):** Chặn các thao tác chèn/cập nhật tuần tự gán lại một `product_unit_id` đang nằm trong một đơn hàng khác chưa hủy (`orders.status <> 'cancelled'`), đồng thời cho phép bán lại chiếc máy đó trong đơn hàng mới sau khi đơn hàng cũ đã chuyển sang `'cancelled'` mà không làm mất lịch sử đơn hàng cũ.

#### 5.2. Quản lý Tồn kho và Quy trình Hủy đơn Nguyên tử & Idempotent (`has_serials = 1` & `has_serials = 0`)
- **Nguyên tắc "Single Source of Truth" và Chống âm tồn kho (`stock_quantity >= 0`):**
  - Cột `products.stock_quantity` dùng kiểu `INT UNSIGNED NOT NULL DEFAULT 0` kết hợp `CHECK (stock_quantity >= 0)` và câu lệnh cập nhật có điều kiện biên:
    `UPDATE products SET stock_quantity = stock_quantity - :qty1 WHERE id = :product_id AND stock_quantity >= :qty2`
  - Nếu `rowCount() === 0` (số lượng đặt vượt quá tồn kho hiện tại), giao dịch lập tức `ROLLBACK`, đảm bảo tồn kho không bao giờ bị âm.
- **Quy trình Hủy đơn và Hoàn kho Nguyên tử & Idempotent (Thực thi trong 1 Transaction duy nhất):**
  1. Khóa bản ghi đơn hàng: `SELECT id, status, coupon_id FROM orders WHERE id = :order_id FOR UPDATE`.
  2. Kiểm tra trạng thái đơn: Chỉ cho phép hủy khi `status IN ('pending', 'confirmed')`. Nếu đơn đã ở trạng thái `'cancelled'` (gọi hủy lặp lần 2) hoặc `'shipping'` / `'completed'`, từ chối ngay và `ROLLBACK` — **tuyệt đối không hoàn kho 2 lần**.
  3. Cập nhật trạng thái đơn sang `'cancelled'`, lưu `cancel_reason` và `cancelled_at = NOW()`.
  4. Duyệt danh sách `order_items` của đơn hàng (`FOR UPDATE`):
     - **Nếu mặt hàng có serial (`has_serials = 1`):** Bắt buộc `product_unit_id IS NOT NULL`. Khóa `product_units ... FOR UPDATE`, thực thi `UPDATE product_units SET status = 'available' WHERE id = :unit_id AND product_id = :product_id AND status = 'reserved'`. **Nếu `rowCount() !== 1` (máy không ở trạng thái `'reserved'` hoặc lỗi dữ liệu), lập tức ném lỗi và `ROLLBACK` toàn bộ giao dịch hủy đơn**. Khi `rowCount() === 1`, cộng hoàn `products.stock_quantity = stock_quantity + 1`.
     - **Nếu mặt hàng không có serial (`has_serials = 0`, `product_unit_id IS NULL`):** Cộng hoàn đúng số lượng `order_items.quantity` vào `products.stock_quantity` (`UPDATE products SET stock_quantity = stock_quantity + :qty WHERE id = :product_id`). Nếu `rowCount() !== 1`, lập tức `ROLLBACK`.
  5. Hoàn lượt sử dụng Voucher (nếu `orders.coupon_id IS NOT NULL`):
     - Khóa bản ghi `coupon_usages` (`WHERE order_id = :order_id AND coupon_id = :coupon_id AND status = 'used' FOR UPDATE`) và khóa `coupons` (`FOR UPDATE`).
     - Cập nhật `coupon_usages SET status = 'cancelled' WHERE id = :usage_id AND status = 'used'` và `coupons SET usage_count = usage_count - 1 WHERE id = :coupon_id AND usage_count > 0`.
     - **Nếu bất kỳ bước hoàn voucher nào thất bại (`!$usage` hoặc `rowCount() !== 1`), bắt buộc `ROLLBACK` toàn bộ giao dịch hủy đơn** (kể cả trạng thái đơn và các máy serial đã hoàn trước đó trong cùng giao dịch đều được quay lui về trạng thái ban đầu).
  6. Bảo toàn lịch sử thanh toán & Phân biệt Hủy đơn và Hoàn tiền:
     - Nếu `payments.status = 'pending'` thì chuyển sang `'failed'`.
     - Nếu `payments.status = 'completed'` (khách đã chuyển khoản được duyệt) thì **giữ nguyên `'completed'`** khi hủy đơn để kế toán đối soát. Chỉ khi Admin thực hiện nghiệp vụ hoàn tiền riêng (`refundPaymentByAdminTransaction`), trạng thái mới chuyển từ `'completed'` sang `'refunded'`.

#### 5.3. Xử lý Voucher Toàn diện, Giới hạn Khách vãng lai và Chống Race Condition
- **Kiểm tra đầy đủ 7 điều kiện Voucher trong Transaction (`SELECT ... FOR UPDATE`):**
  1. `is_active = 1`: Voucher phải đang hoạt động.
  2. Thời gian hiệu lực: Thời điểm hiện tại nằm trong khoảng `start_date <= NOW() <= end_date` (chặn mã chưa đến giờ mở hoặc đã hết hạn).
  3. Giá trị đơn tối thiểu: `total_product_amount >= min_order_amount`.
  4. Giới hạn tổng toàn hệ thống: `usage_limit = 0 OR usage_count < usage_limit`, cập nhật nguyên tử có điều kiện `UPDATE coupons SET usage_count = usage_count + 1 WHERE id = :cid AND is_active = 1 AND NOW() BETWEEN start_date AND end_date AND (usage_limit = 0 OR usage_count < usage_limit)` (`rowCount() === 1`).
  5. Giới hạn mỗi người dùng (`per_user_limit`):
     - **Khách hàng đã đăng nhập (`user_id IS NOT NULL`):** Đếm số bản ghi trong `coupon_usages` có `coupon_id = :cid AND status = 'used' AND (user_id = :uid OR customer_phone = :phone OR LOWER(customer_email) = LOWER(:email)) FOR UPDATE`. Nếu `>= per_user_limit` thì từ chối.
     - **Khách vãng lai (`user_id IS NULL`):** Chuẩn hóa `customer_phone` (chỉ giữ chữ số) và `customer_email` (`LOWER(TRIM(email))`), đếm số bản ghi trong `coupon_usages` có `coupon_id = :cid AND status = 'used' AND (customer_phone = :phone OR LOWER(customer_email) = LOWER(:email)) FOR UPDATE`. Nếu `>= per_user_limit` thì từ chối.
     - **Phân tích hạn chế khi định danh khách vãng lai theo Email/SĐT:** Vì khách vãng lai không có tài khoản xác thực (`user_id IS NULL`), việc kiểm tra theo cặp `(customer_phone OR customer_email)` ngăn chặn hiệu quả khách vô tình hoặc cố ý dùng lại cùng SĐT hoặc cùng Email, nhưng **không thể chặn tuyệt đối** trường hợp một người cố tình dùng đồng thời một SIM số điện thoại khác VÀ một địa chỉ email khác cho đơn hàng COD mới. Để giảm thiểu rủi ro này trong thực tế: (a) luôn khóa chặt tổng ngân sách khuyến mãi bằng `usage_limit` toàn hệ thống; (b) với các mã voucher giá trị giảm lớn, tầng ứng dụng MVC có thể cấu hình yêu cầu bắt buộc đăng nhập tài khoản thành viên.
  6. Chống giảm giá vượt giá trị đơn: `discount_amount <= total_product_amount` (bị chặn ở cả tầng PHP lẫn `CONSTRAINT chk_orders_discount` trên bảng `orders`).
  7. Đồng bộ trạng thái: Lưu `coupon_usages.status = 'used'` khi đặt đơn và chuyển sang `'cancelled'` kèm giảm `usage_count` khi hủy đơn.

#### 5.4. Tính Tiền Phía Server, Bảo Mật Thanh Toán và Bảo Toàn Lịch Sử
- **Tính toán tiền tệ 100% trên Server & Chống tràn kiểu dữ liệu:**
  - Kiểm tra chặt `quantity > 0` và `shipping_fee >= 0`.
  - Tuyệt đối không tin cậy bất kỳ tham số `price`, `subtotal`, `total_product_amount`, `discount_amount`, hay `final_amount` nào gửi từ phía trình duyệt (Client). Mọi đơn giá đều được truy vấn trực tiếp từ `products.price` và `coupons` trong MySQL bên trong Transaction.
  - Kiểm tra giới hạn an toàn số học (`MAX_SAFE_MONEY_VND`) trước khi nhân `price * quantity` và kiểm tra `discount_amount <= total_product_amount` trước khi thực hiện phép trừ `(total_product_amount - discount_amount) + shipping_fee`, đảm bảo `final_amount >= 0` và không bao giờ xảy ra tràn số nguyên (`PHP_INT_MAX`) hay tràn âm (`BIGINT UNSIGNED` underflow).
- **Bảo mật trạng thái thanh toán (`payments.status`):**
  - Khi khách hàng tạo đơn hàng mới, hệ thống cưỡng bức gán `payments.status = 'pending'`, hoàn toàn bỏ qua mọi trường `payment_status` từ dữ liệu đầu vào của khách.
  - Chỉ quy trình duyệt thanh toán của Quản trị viên (`confirmPaymentByAdminTransaction` kiểm tra `users.role = 'admin' AND users.status = 'active'`) mới được phép chuyển `payments.status` từ `'pending'` sang `'completed'` và ghi nhận `confirmed_by_user_id`, `confirmed_at`.
- **Quy tắc bảo vệ dữ liệu tài chính kế toán (Snapshot & FK Restrict):**
  - Toàn bộ các bảng nghiệp vụ kế toán, giao dịch và bảo hành (`orders`, `order_items`, `payments`, `coupon_usages`) áp dụng nghiêm ngặt `ON DELETE RESTRICT` từ các bảng cấu hình (`products`, `product_units`, `coupons`) và `ON DELETE SET NULL` đối với `users`.
  - Dữ liệu đóng băng trong `order_items` (`product_name`, `product_sku`, `unit_serial_number`, `price`, `warranty_period`) giữ nguyên giá trị lịch sử tại thời điểm mua.

---

### 6. NHỮNG QUYẾT ĐỊNH THIẾT KẾ CÙNG LÝ DO (DESIGN DECISIONS)

1. **Lựa chọn kiểu số nguyên `BIGINT UNSIGNED` cho giá tiền thay vì `DECIMAL(12,2)` hay `FLOAT`:**
   - *Lý do:* Đơn vị tiền tệ Việt Nam Đồng (VND) không sử dụng phần thập phân (không có xu/hào). Việc dùng `FLOAT/DOUBLE` sẽ gây ra sai số dấu phẩy động nguy hiểm khi tính tổng tiền đơn hàng. Dùng `BIGINT UNSIGNED` vừa tối ưu hóa dung lượng lưu trữ, tốc độ so sánh và tính toán số học nhanh hơn `DECIMAL`, vừa hỗ trợ khoảng giá trị lên tới hàng tỷ tỷ đồng, hoàn toàn đáp ứng các dàn PC Gaming cao cấp.
2. **Thiết kế phân tách 2 tầng giỏ hàng (MySQL Cart vs Session Cart):**
   - *Lý do:* Khách vãng lai chưa đăng nhập có tần suất truy cập và thêm hàng vào giỏ rất lớn nhưng tỷ lệ chuyển đổi đơn hàng không đồng đều. Lưu giỏ hàng khách vãng lai trong PHP Session (`$_SESSION['cart']`) giúp giải phóng cơ sở dữ liệu MySQL khỏi hàng triệu bản ghi rác từ khách ghé thăm hoặc bot tìm kiếm. Khi khách đăng nhập, hệ thống sẽ thực hiện hợp nhất (merge) giỏ hàng từ Session vào MySQL (`carts` và `cart_items`).
3. **Phân tách rõ ràng giữa `products` và `product_units`:**
   - *Lý do:* Đây là sự khác biệt cốt lõi giữa hệ thống bán máy tính cũ và máy tính mới. Nếu chỉ có bảng `products`, ta không thể lưu được rằng chiếc laptop Dell G15 mã này hiện có chiếc serial SN001 bị xước nhẹ ở góc đáy (Grade B, pin 91%), còn chiếc serial SN002 lại đẹp như mới (Grade A, pin 98%). Việc tách thành `product_units` cho phép quản lý chi tiết đến từng cá thể phần cứng, đồng thời tạo cơ sở cho việc bảo hành theo số Serial sau bán hàng.
4. **Tách riêng bảng `product_inspections` thay vì để checklist trong `product_units`:**
   - *Lý do:* Một chiếc máy tính cũ có thể trải qua nhiều lần kiểm định: lần 1 khi thu mua máy cũ từ khách, lần 2 sau khi kỹ thuật vệ sinh tra keo tản nhiệt và nâng cấp RAM/SSD, lần 3 khi khách đem máy đến bảo hành. Thiết kế bảng riêng với quan hệ 1-N cho phép lưu lại toàn bộ biên bản kiểm định lịch sử, tăng tính minh bạch và uy tín cho website bán máy cũ TL Gaming.
5. **Cơ chế ghi nhận thanh toán thủ công (Manual Bank Transfer & COD):**
   - *Lý do:* Đồ án quy định sử dụng PHP thuần và không bắt buộc tích hợp cổng thanh toán trực tuyến tự động bên thứ ba (VNPay, MoMo thường đòi hỏi giấy phép kinh doanh và IP tĩnh). Bảng `payments` được thiết kế có trường `payment_proof_image` (ảnh chụp biên lai chuyển tiền) và `confirmed_by_user_id` (admin đối soát) vừa sát thực tế kinh doanh máy tính cũ tại Việt Nam, vừa đơn giản, tin cậy và dễ giải trình bảo vệ đồ án.

---

### 7. DANH SÁCH CÁC VẤN ĐỀ CẦN XÁC NHẬN (PENDING CONFIRMATIONS)

Trước khi chuyển sang bước viết mã SQL hoặc lập trình mô hình MVC, các vấn đề nghiệp vụ sau đây được liệt kê để xác nhận với giảng viên hướng dẫn hoặc chủ đồ án:

1. **Thời gian giữ chỗ máy (Reservation Timeout):**
   - *Vấn đề:* Khi khách đặt đơn chọn hình thức Chuyển khoản ngân hàng, máy vật lý sẽ chuyển trạng thái sang `reserved`. Hệ thống cần giữ chỗ trong bao lâu (ví dụ: 12 giờ hay 24 giờ)? Nếu sau thời gian này khách không chuyển khoản hoặc không upload bill, hệ thống có tự động hủy đơn và chuyển trạng thái máy về `available` hay để admin hủy thủ công?
2. **Cơ chế chọn máy của khách hàng trên giao diện:**
   - *Vấn đề:* Khách hàng khi xem sản phẩm có được tự mình bấm chọn từng số Serial / từng độ chai pin và độ mới cụ thể (ví dụ: "Tôi muốn mua chiếc SN001 pin 98% giá chênh thêm 200k"), hay khách hàng chỉ chọn dòng sản phẩm chung, sau đó hệ thống/admin sẽ phân bổ máy ngẫu nhiên theo tiêu chuẩn Grade A/B?
3. **Phạm vi áp dụng của Voucher giảm giá:**
   - *Vấn đề:* Voucher hiện tại đang thiết kế áp dụng trên tổng giá trị đơn hàng (`orders.final_amount`). Đồ án có yêu cầu mã giảm giá áp dụng riêng cho từng danh mục (ví dụ: chỉ giảm cho Laptop cũ, không áp dụng cho Linh kiện) hay không?
4. **Cơ chế trả lời khiếu nại và đổi trả máy:**
   - *Vấn đề:* Khi một chiếc máy đã bán (`sold`) nhưng khách đổi trả trong 7 ngày theo chính sách bảo hành, máy đó được chuyển trạng thái sang `returned`. Đơn hàng cũ sẽ được đánh dấu hoàn tiền (`refunded`) trên `payments`, và chiếc máy đó sau khi kiểm định lại sẽ được nhập kho bán lại dưới dạng hàng xả kho. Quy trình này có cần giao diện quản trị riêng trong đồ án không?

---
*Tài liệu được soạn thảo phục vụ đồ án tốt nghiệp Website Bán máy tính cũ TL Gaming - Phiên bản Phân tích & Thiết kế Cơ sở Dữ liệu 1.0.*
