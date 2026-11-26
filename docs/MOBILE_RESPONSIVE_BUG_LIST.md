# DANH SÁCH LỖI MOBILE RESPONSIVE ĐÃ PHÁT HIỆN & KHẮC PHỤC (PROMPT 23)

- **Dự án:** Website bán máy tính cũ **TL Gaming** (PHP 8 thuần, MySQL/MariaDB PDO, MVC tự xây dựng, HTML5/CSS3/JS thuần, phong cách Google Stitch).
- **Công cụ kiểm thử:** Google Chrome thật (`headless=new`) qua Chrome DevTools Protocol (CDP) với `Emulation.setDeviceMetricsOverride` (`mobile: true`, `deviceScaleFactor: 2`) và `Emulation.setTouchEmulationEnabled({ enabled: true })`.
- **Cơ sở dữ liệu kiểm thử:** `tl_gaming_test` độc lập (không chạm database thật `tl_gaming`).
- **Tổng số lỗi phát hiện ban đầu:** **7 lỗi** (3 Cao, 3 Trung bình, 1 Thấp) trên tổng số **39 cặp trang × viewport**.
- **Trạng thái sau khi sửa:** **7/7 ĐÃ KHẮC PHỤC & XÁC MINH PASS** trên Chrome Mobile Emulation (`overflowX = false`, `0` lỗi Console JS, `0` lỗi HTTP >= 400, `0` ảnh vỡ).

---

## Bảng tổng hợp lỗi

| Mã lỗi | Mức độ | Khu vực / URL | Viewport phát hiện | Mô tả lỗi & Nguyên nhân gốc | File đã sửa | Trạng thái |
| --- | --- | --- | --- | --- | --- | --- |
| **M-BUG-01** | **Cao** | Trang chủ (`/`) | `320×640`, `360×800`, `375×812`, `390×844`, `414×896`, `430×932`, `768×1024` | Tràn ngang toàn trang nghiêm trọng (`scrollWidth = 1192px–1210px` thay vì `320px–768px`). Nguyên nhân: `.laptop-horiz-card`, `.laptop-horiz-body`, `.component-clean-card`, `.pc-spec-val` không co theo viewport nhỏ (`min-width` mặc định `auto` trong Flex/Grid và chữ/spec không wrap). | `public/assets/css/style.css` | **ĐÃ SỬA (PASS)** |
| **M-BUG-02** | **Trung bình** | Chi tiết sản phẩm (`/san-pham/{slug}`) | `375×812` | Tràn ngang nhẹ (`scrollWidth = 383px > 375px`). Nguyên nhân: `.product-detail-hero-grid` dùng lưới 12 cột kèm `.product-gallery-col` (`grid-column: span 5`) và `.product-summary-col` (`grid-column: span 7`) chưa reset hoàn toàn `grid-template-columns: minmax(0, 1fr)` và `grid-column: auto` ở mobile. | `public/assets/css/style.css` | **ĐÃ SỬA (PASS)** |
| **M-BUG-03** | **Trung bình** | Admin Dashboard (`/admin`) | `320×640` | Biểu đồ doanh thu `#revenueChartCanvas` gây tràn ngang (`scrollWidth = 337px > 320px`). Nguyên nhân: `admin.js` ép bề rộng tối thiểu `Math.max(300, chartWrap.clientWidth || 600)` khiến canvas rộng `300px` vượt quá phần ruột card ở màn `320px` (sau khi trừ padding). | `public/assets/js/admin.js`, `public/assets/css/admin.css` | **ĐÃ SỬA (PASS)** |
| **M-BUG-04** | **Cao** | Form thêm sản phẩm Admin (`/admin/san-pham/them`) | `375×812` | Tràn ngang nặng (`scrollWidth = 434px > 375px`). Nguyên nhân: `.adm-form-grid` nhiều cột và các `.adm-field` thiếu `min-width: 0; max-width: 100%;` trên màn hình `<= 768px`. | `public/assets/css/admin.css` | **ĐÃ SỬA (PASS)** |
| **M-BUG-05** | **Cao** | Chi tiết Serial Admin (`/admin/serial/{id}`) | `375×812` | Tràn ngang rất nặng (`scrollWidth = 820px > 375px`). Nguyên nhân: `.adm-serial-detail-grid` và `.adm-kv-row` giữ cấu trúc ngang nhiều cột và không ngắt dòng chuỗi dài trên mobile. | `public/assets/css/admin.css` | **ĐÃ SỬA (PASS)** |
| **M-BUG-06** | **Thấp** | Phiếu kiểm định 18 bước (`/admin/serial/{id}/kiem-dinh/them`) | `375×812` | Tràn ngang nhẹ (`scrollWidth = 381px > 375px`). Nguyên nhân: khối `.admin-card` / phần tử con bên trong thiếu `min-width: 0; box-sizing: border-box; max-width: 100%` khi thu về `375px`. | `public/assets/css/admin.css` | **ĐÃ SỬA (PASS)** |
| **M-BUG-07** | **Trung bình** | Form Khách hàng & Admin (`/`, `/san-pham`, `/thanh-toan`, `/dang-nhap`, `/admin/*`) | `320×640` – `768×1024` (Portrait) | Các ô nhập liệu `<input>`, `<select>`, `<textarea>` có `font-size` `13.44px–14px` (< `16px`), có nguy cơ kích hoạt auto-zoom khi chạm vào ô input trên iOS Safari, và một số nút/ô nhập chưa đạt `min-height: 44px` theo chuẩn touch target. | `public/assets/css/style.css`, `public/assets/css/admin.css` | **ĐÃ SỬA (PASS)** |

---

## Chi tiết từng lỗi & Cách khắc phục

### M-BUG-01 — Trang chủ tràn ngang `1192px–1210px` trên Mobile & Tablet
- **Ảnh bằng chứng trước/sau:**
  - Trước sửa: `docs/screenshots/mobile-final/before_home_320.png`, `docs/screenshots/mobile-final/before_home_375.png`
  - Sau sửa: `docs/screenshots/mobile-final/home_320.png`, `docs/screenshots/mobile-final/home_375.png`
- **Giải pháp trong `public/assets/css/style.css`:**
  - Bổ sung `@media (max-width: 900px)` ép `min-width: 0; max-width: 100%;` cho `.laptop-horiz-card`, `.laptop-horiz-body`, `.component-clean-card`, `.pc-spec-val` và chuyển card ngang sang xếp dọc gọn gàng trên màn hình hẹp.
  - Bổ sung `@media (max-width: 360px)` đưa lưới sản phẩm về 1 cột duy nhất để tránh vỡ chữ và ép card quá hẹp trên thiết bị `320px–360px`.
- **Kết quả sau sửa:** `scrollWidth = clientWidth` ở toàn bộ `320px`, `360px`, `375px`, `390px`, `414px`, `430px`, `768px`, `812×375`, `1440px`.

### M-BUG-02 — Trang Chi tiết sản phẩm tràn ngang `383px` tại `375×812`
- **Ảnh bằng chứng sau sửa:** `docs/screenshots/mobile-final/product_detail_375.png`
- **Giải pháp trong `public/assets/css/style.css`:**
  - Dưới `@media (max-width: 900px)`, đặt `.product-detail-hero-grid { grid-template-columns: minmax(0, 1fr) !important; }` và `.product-gallery-col, .product-summary-col { grid-column: auto !important; min-width: 0; max-width: 100%; }`.
- **Kết quả sau sửa:** `scrollWidth = 375`, `clientWidth = 375`, `overflowX = false`.

### M-BUG-03 — Biểu đồ Canvas Admin Dashboard tràn ngang tại `320×640`
- **Ảnh bằng chứng sau sửa:** `docs/screenshots/mobile-final/admin_dashboard_375.png`, `docs/screenshots/mobile-final/admin_dashboard_768.png`, `docs/screenshots/mobile-final/admin_dashboard_1440.png`
- **Giải pháp:**
  - Trong `public/assets/js/admin.js`: đổi `Math.max(300, chartWrap.clientWidth || 600)` thành `Math.max(200, Math.floor(chartWrap.clientWidth || 300))`.
  - Trong `public/assets/css/admin.css`: thêm `.adm-chart-box canvas { max-width: 100%; height: auto; }`.
- **Kết quả sau sửa:** `scrollWidth = 320` tại `320×640`, biểu đồ vẫn vẽ sắc nét và không vỡ bố cục Desktop `1440×900`.

### M-BUG-04, M-BUG-05, M-BUG-06 — Tràn ngang ở Form sản phẩm, Chi tiết Serial & Kiểm định 18 bước Admin
- **Ảnh bằng chứng sau sửa:** `docs/screenshots/mobile-final/admin_products_375.png`, `docs/screenshots/mobile-final/admin_serial_375.png`, `docs/screenshots/mobile-final/admin_inspection_375.png`
- **Giải pháp trong `public/assets/css/admin.css`:**
  - Bổ sung `min-width: 0; max-width: 100%; box-sizing: border-box;` cho `.admin-main`, `.admin-content`, `.admin-card`, `.adm-field` và phần tử con của Grid.
  - Dưới `@media (max-width: 768px)`, chuyển `.adm-form-grid`, `.adm-serial-detail-grid`, `.adm-detail-grid`, `.adm-kv-row` sang 1 cột duy nhất (`grid-template-columns: minmax(0, 1fr)`), cho phép wrap chuỗi dài.
- **Kết quả sau sửa:** Toàn bộ trang Admin tại `375×812` có `scrollWidth = 375`, `overflowX = false`.

### M-BUG-07 — Kích thước chữ Input `< 16px` & Touch target trên Mobile
- **Giải pháp trong `public/assets/css/style.css` & `public/assets/css/admin.css`:**
  - Dưới `@media (max-width: 768px)`, chuẩn hóa `font-size: 16px !important; min-height: 44px;` cho các ô `input`, `select`, `textarea` hiển thị trên mobile.
- **Kết quả sau sửa:** `0` trang mobile portrait (`320px–768px`) còn input `< 16px`. (Các viewport Desktop `1440×900` và Landscape `812×375` có chiều rộng `> 768px` giữ nguyên thiết kế Desktop gọn gàng.)

---

## Ghi chú kiểm tra bổ sung trong quá trình chụp Giỏ hàng & Thanh toán
- Ở lần chạy script tự động đầu tiên, script đã chọn nhầm sản phẩm Serial đã bán hết trong dữ liệu seed E2E khiến `/thanh-toan` chuyển hướng về `/gio-hang` trống.
- Đã cập nhật script để thêm sản phẩm còn tồn kho thật (`product_id = 14` trong `tl_gaming_test`, quản lý số lượng) qua `POST /gio-hang/them` kèm CSRF token hợp lệ, xác nhận `checkoutPath === "/thanh-toan"` và kiểm tra trực tiếp cả giao diện **Giỏ hàng có sản phẩm** (`Cart 320`, `Cart 375`) và **Thanh toán đầy đủ biểu mẫu** (`Checkout 320`, `Checkout 375`, `Checkout 390`). Kết quả: **0 lỗi tràn ngang, 0 lỗi JS, giao diện rõ ràng dễ thao tác**.
