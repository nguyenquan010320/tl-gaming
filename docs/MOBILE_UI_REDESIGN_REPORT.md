# BÁO CÁO REDESIGN GIAO DIỆN MOBILE STOREFRONT (PROMPT 23.1)

- **Dự án:** Website thương mại điện tử bán máy tính cũ **TL Gaming**
- **Công nghệ:** PHP 8 thuần, MySQL/MariaDB PDO, MVC tự xây dựng, HTML5/CSS3/JavaScript thuần (không framework, không thư viện UI ngoài).
- **Phạm vi:** Thiết kế lại toàn bộ trải nghiệm Mobile Storefront (`<= 768px`) dành cho khách hàng; **giữ nguyên 100% giao diện Desktop (`> 768px`)**, **không thay đổi Admin**, **không thay đổi cơ sở dữ liệu hay nghiệp vụ Auth / Cart / Checkout**.
- **Ngày thực hiện:** 25/11/2026

---

## 1. Tóm tắt các thay đổi chính trên Mobile (`<= 768px`)

### 1.1. Mobile Header & Thanh tìm kiếm gọn nhẹ (~107px thay vì 221px)
- **Trước redesign:** Header Mobile cao **221px** (gồm 2 dòng topbar tối màu, cụm nút chữ lớn "Giỏ hàng" và "☰ Danh mục", nền tối chiếm gần 1/3 màn hình).
- **Sau redesign:** Header Mobile nền trắng tinh gọn cao **107px** (giảm **51.6%** chiều cao, nằm đúng chuẩn `105–115px`):
  - **Hàng 1 (`52px`):** Nút Hamburger SVG (trái) → Logo & chữ **TL GAMING** → Icon Giỏ hàng SVG kèm badge số lượng thật → Icon Tài khoản SVG (tự động điều hướng `/tai-khoan` khi đã đăng nhập hoặc `/dang-nhap` khi chưa đăng nhập).
  - **Hàng 2 (`44px`):** Thanh tìm kiếm rộng toàn màn hình (`margin 12px` hai bên, viền xám nhạt `#cbd5e1`, bo góc `11px`), icon kính lúp SVG, placeholder `"Bạn cần tìm sản phẩm gì?"` và nút **Tìm kiếm** màu xanh dương TL Gaming (`#1d4ed8`), giữ nguyên route `GET /san-pham?q=...`.
  - Ẩn hoàn toàn 2 dòng topbar thông báo và các nút chữ lớn cũ trên Mobile; giữ nguyên hoàn toàn Header Deep Navy trên Desktop.

### 1.2. Drawer Menu Danh mục Mobile
- Mở từ nút Hamburger trên Header hoặc nút **Danh mục** trên Bottom Navigation Bar.
- Hiển thị danh mục sản phẩm thực tế lấy từ MySQL (`$navCategories`), các tiện ích (`Tra cứu đơn hàng`, `Quy trình kiểm định 18 bước`, `Tài khoản` / `Đăng nhập` / `Đăng xuất`) và nút gọi **Hotline `1900 8868`** ở chân drawer.

### 1.3. Bottom Navigation Bar cố định 5 mục
- Cao **62px** + hỗ trợ `env(safe-area-inset-bottom)` cho iPhone, nền trắng, viền trên + đổ bóng nhẹ, ẩn hoàn toàn trên Desktop (`> 768px`).
- 5 mục sử dụng SVG đồng nhất, mục đang hoạt động có màu xanh dương `#1d4ed8`:
  1. **Trang chủ** (`/`)
  2. **Danh mục** (mở Drawer danh mục thật)
  3. **Khuyến mãi** (`/#san-pham-noi-bat` — dẫn đến khu vực Giờ Vàng Giá Sốc thực tế, không tạo route 404)
  4. **Giỏ hàng** (`/gio-hang` — hiển thị badge số lượng thật đồng bộ AJAX)
  5. **Gọi ngay** (`tel:19008868` — hotline thật của TL Gaming)
- Đã bổ sung `padding-bottom` an toàn trên `body` để Bottom Navigation không che nút mua hàng hay Footer; **tự động ẩn trên trang Checkout (`/thanh-toan`)** để khách hàng tập trung hoàn tất đơn hàng và **chuyển sang Thanh mua hàng cố định trên trang Chi tiết sản phẩm** để hai thanh không bao giờ chồng nhau.

### 1.4. Trang chủ Mobile
- Sắp xếp đúng luồng mua sắm trên điện thoại:
  1. Mobile Header + Thanh tìm kiếm (`107px`).
  2. **Banner quảng cáo** gọn dạng ngang, bo góc `14px`, không chiếm trọn màn hình đầu tiên.
  3. **Cam kết cửa hàng** dạng lưới 2×2 thẻ trắng nhỏ gọn, dễ quét mắt.
  4. **Tìm máy theo ngân sách** (chỉ hiện trên Mobile): lưới 2 cột gồm *Dưới 5 triệu*, *5–10 triệu*, *10–15 triệu*, *15–20 triệu*, *Trên 20 triệu* — liên kết trực tiếp tham số lọc giá thật (`gia-tu`, `gia-den`) của `/san-pham`.
  5. **Danh mục theo nhu cầu** (chỉ hiện trên Mobile): 6 card bo góc nền nhẹ kèm icon SVG (*Học tập & Văn phòng*, *PC Gaming*, *Laptop Gaming*, *Đồ họa & Thiết kế*, *Linh kiện*, *Màn hình*) liên kết đúng danh mục/bộ lọc thật.
  6. **Sản phẩm nổi bật**, **PC Gaming**, **Laptop Gaming**, **Linh kiện & Màn hình**, **Quy trình kiểm định 18 bước** và **Footer**.

### 1.5. Trang Danh sách sản phẩm, Chi tiết sản phẩm, Đăng nhập/Đăng ký, Giỏ hàng & Checkout
- **Danh sách sản phẩm (`/san-pham`):** Thu gọn header danh mục để đưa sản phẩm lên ngay đầu màn hình; lưới 2 cột trên `360px–768px` (1 cột ở `320px`), card trắng viền nhẹ, tên giới hạn 2 dòng, giá nổi bật, nút lọc/sắp xếp dễ chạm.
- **Chi tiết sản phẩm (`/san-pham/{slug}`):** Gallery gọn gàng, giá & trạng thái hiển thị ngay sau ảnh, giữ nguyên nội dung kiểm định 18 bước, bổ sung **Sticky Buy Bar** (`Giỏ hàng` + `Thêm vào giỏ` + `Mua ngay` hoặc `Tạm hết hàng`) đồng bộ trực tiếp với nút mua gốc, không chồng lên Bottom Navigation.
- **Đăng nhập & Đăng ký (`/dang-nhap`):** Ẩn panel marketing tối màu trên Mobile để biểu mẫu trắng hiển thị trọn vẹn ngay dưới Header; input cao `46px`, font `16px`, nút submit full-width; **đã ẩn hoàn toàn thông tin kỹ thuật (`bcrypt`, `CSRF Token`, `Session Regenerate`) khỏi giao diện khách hàng** (cơ chế bảo mật backend vẫn giữ nguyên 100%).
- **Giỏ hàng & Checkout (`/gio-hang`, `/thanh-toan`):** Đồng bộ nền sáng sạch và Header mới; Checkout ẩn Bottom Navigation để không che nút Đặt hàng.

---

## 2. Danh sách tệp mã nguồn đã chỉnh sửa

1. `app/Views/layouts/main.php` — Thêm Mobile Header, thanh tìm kiếm Mobile, Drawer danh mục Mobile, Bottom Navigation Bar 5 mục và class định danh route cho Checkout / Product Detail.
2. `app/Views/home/index.php` — Thêm 2 khối Mobile-only *Tìm máy theo ngân sách* và *Danh mục theo nhu cầu* sau *Cam kết cửa hàng*.
3. `app/Views/auth/login.php` — Gỡ thanh chữ kỹ thuật `bcrypt` / `CSRF Token` / `Session Regenerate` khỏi giao diện khách hàng.
4. `app/Views/products/show.php` — Thêm thanh mua hàng cố định trên Mobile (`.m-sticky-buy-bar`).
5. `public/assets/js/app.js` — Điều khiển Drawer danh mục Mobile, đồng bộ badge số lượng giỏ hàng đa vị trí và kết nối Sticky Buy Bar với form đặt mua hiện có.
6. `public/assets/css/style.css` — Bổ sung toàn bộ Design System Mobile (`@media (max-width: 768px)` và `@media (max-width: 359px)`), cô lập hoàn toàn không ảnh hưởng Desktop `> 768px`.

---

## 3. Kết quả kiểm thử Chrome thật (CDP) & E2E HTTP

| Hạng mục kiểm thử | Kết quả | Bằng chứng / Ghi chú |
| --- | --- | --- |
| **Kích thước màn hình kiểm tra** (`320`, `360`, `375`, `390`, `414`, `430`, `768`, `1440px`) | **PASS (19/19)** | `issuesCount = 0`, `overflowX = false` trên tất cả 19 lượt kiểm tra. |
| **Chiều cao Header + Tìm kiếm Mobile (`375px`)** | **PASS** | `107px` (trước redesign: `221px`, mục tiêu: `105–115px`). |
| **Drawer danh mục Mobile** | **PASS** | Mở/đóng mượt (`aria-expanded="true"`, `.is-open = true`), hiển thị danh mục thật từ MySQL. |
| **Bottom Navigation Bar (`375px`)** | **PASS** | Cao `62px`, đủ 5 mục, badge giỏ hàng thực tế (`1`), `tel:19008868`, ẩn trên Checkout (`false`) & Desktop `1440px` (`false`). |
| **Ẩn chữ kỹ thuật trên trang Đăng nhập/Đăng ký** | **PASS** | `hasBcrypt = false`, `hasCsrf = false`, `hasRegen = false`. |
| **Lỗi Console JS / HTTP / Ảnh vỡ** | **PASS** | `0` lỗi Console JS, `0` lỗi HTTP >= 400, `0` ảnh vỡ. |
| **Kiểm tra cú pháp PHP (`php -l`) & JS (`node --check`)** | **PASS** | 4/4 file View PHP & `app.js` không có lỗi cú pháp. |
| **Bộ E2E HTTP Integration Tests trên `tl_gaming_test`** | **PASS (9/9)** | `E2E-C1..C5` và `E2E-A1..A4` đều PASS (`PASS=9 | FAIL=0`). |
| **Kiểm thử trên điện thoại vật lý thật** | **NOT RUN** | Môi trường hiện tại sử dụng Chrome thật qua CDP Mobile & Touch Emulation. |

---

## 4. Ảnh chụp trước & sau (`docs/screenshots/mobile-redesign/`)

Tổng cộng **18 ảnh PNG** (9 trước + 9 sau):

| Màn hình | Trước redesign | Sau redesign |
| --- | --- | --- |
| Trang chủ Mobile (`375×812`) | `before_home_mobile.png` | `after_home_mobile.png` |
| Menu danh mục Mobile (`375×812`) | `before_menu_mobile.png` | `after_menu_mobile.png` |
| Danh sách sản phẩm (`375×812`) | `before_products_mobile.png` | `after_products_mobile.png` |
| Chi tiết sản phẩm (`375×812`) | `before_product_detail_mobile.png` | `after_product_detail_mobile.png` |
| Đăng nhập (`375×812`) | `before_login_mobile.png` | `after_login_mobile.png` |
| Đăng ký (`375×812`) | `before_register_mobile.png` | `after_register_mobile.png` |
| Giỏ hàng (`375×812`) | `before_cart_mobile.png` | `after_cart_mobile.png` |
| Checkout (`375×812`) | `before_checkout_mobile.png` | `after_checkout_mobile.png` |
| Trang chủ Desktop (`1440×900`) | `before_home_desktop.png` | `after_home_desktop.png` |
