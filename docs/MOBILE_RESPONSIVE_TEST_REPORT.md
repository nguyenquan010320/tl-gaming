# BÁO CÁO KIỂM THỬ MOBILE RESPONSIVE & TỐI ƯU UX (PROMPT 23)

- **Dự án:** Website bán máy tính cũ **TL Gaming**
- **Kiến trúc:** PHP 8 thuần, MySQL/MariaDB PDO, MVC tự xây dựng, HTML5/CSS3/JS thuần (không framework), giao diện Google Stitch.
- **Ngày thực hiện:** 25/11/2026
- **Môi trường kiểm thử:**
  - Máy chủ nội bộ PHP 8 (`127.0.0.1:18219`) trỏ riêng sang database kiểm thử `tl_gaming_test` (tuyệt đối không ghi dữ liệu thử nghiệm vào database thật `tl_gaming`).
  - Trình duyệt: **Google Chrome thật** (`C:\Program Files\Google\Chrome\Application\chrome.exe`, `--headless=new`) điều khiển qua Chrome DevTools Protocol (CDP), bật `Emulation.setDeviceMetricsOverride` (`mobile: true`, `deviceScaleFactor: 2`) và `Emulation.setTouchEmulationEnabled({ enabled: true, maxTouchPoints: 5 })`.

---

## 1. Danh sách URL đã kiểm thử

### Khu vực Khách hàng (Storefront)
1. `/` — Trang chủ
2. `/san-pham` — Danh sách sản phẩm & Bộ lọc
3. `/san-pham/{slug}` — Chi tiết sản phẩm
4. `/gio-hang` — Giỏ hàng (đã thêm sản phẩm thật `product_id = 14` còn tồn kho vào giỏ)
5. `/thanh-toan` — Thanh toán đơn hàng (hiển thị đầy đủ 3 bước và biểu mẫu giao nhận)
6. `/dang-nhap` — Đăng nhập
7. `/tra-cuu-don-hang` — Tra cứu đơn hàng
8. `/trang-khong-ton-tai-404` — Trang lỗi 404

### Khu vực Quản trị (Admin — đã đăng nhập tài khoản `admin_e2e@tlgaming.test`)
9. `/admin` — Tổng quan kinh doanh (Dashboard & biểu đồ Canvas)
10. `/admin/danh-muc` — Quản lý danh mục
11. `/admin/san-pham` — Quản lý sản phẩm
12. `/admin/san-pham/them` — Form thêm sản phẩm
13. `/admin/serial` — Quản lý Serial
14. `/admin/serial/1` — Chi tiết Serial
15. `/admin/serial/1/kiem-dinh/them` — Lập biên bản kiểm định 18 bước
16. `/admin/don-hang` — Quản lý đơn hàng
17. `/admin/don-hang/{id}` — Chi tiết đơn hàng
18. `/admin/khach-hang` — Quản lý khách hàng
19. `/admin/khach-hang/{id}` — Chi tiết khách hàng
20. `/admin/voucher` — Quản lý voucher
21. `/admin/voucher/them` — Form thêm voucher
22. `/admin/voucher/1/lich-su` — Lịch sử sử dụng voucher

---

## 2. Ma trận kết quả kiểm thử (39 cặp Trang × Viewport)

Tiêu chí đạt (**PASS**): `scrollWidth <= clientWidth + 1` và `window.innerWidth <= targetWidth + 1` (chống zoom-out ẩn của Chrome Mobile), `0` lỗi Console JS, `0` lỗi HTTP >= 400 (trừ trang 404 chủ đích), `0` ảnh vỡ (`naturalWidth === 0`).

| STT | Trang kiểm thử | Viewport | Chế độ | Trước sửa | Sau sửa |
| --- | --- | --- | --- | --- | --- |
| 1 | Trang chủ (`/`) | `320×640` | Mobile | FAIL (`sw=1210`) | **PASS** |
| 2 | Trang chủ (`/`) | `360×800` | Mobile | FAIL (`sw=1210`) | **PASS** |
| 3 | Trang chủ (`/`) | `375×812` | Mobile | FAIL (`sw=1192`) | **PASS** |
| 4 | Trang chủ (`/`) | `390×844` | Mobile | FAIL (`sw=1192`) | **PASS** |
| 5 | Trang chủ (`/`) | `414×896` | Mobile | FAIL (`sw=1192`) | **PASS** |
| 6 | Trang chủ (`/`) | `430×932` | Mobile | FAIL (`sw=1192`) | **PASS** |
| 7 | Trang chủ (`/`) | `768×1024` | Tablet | FAIL (`sw=1192`) | **PASS** |
| 8 | Trang chủ (`/`) | `812×375` | Landscape | PASS | **PASS** |
| 9 | Trang chủ (`/`) | `1440×900` | Desktop | PASS | **PASS** |
| 10 | Danh sách sản phẩm (`/san-pham`) | `320×640` | Mobile | PASS (input < 16px) | **PASS** |
| 11 | Danh sách sản phẩm (`/san-pham`) | `375×812` | Mobile | PASS (input < 16px) | **PASS** |
| 12 | Danh sách sản phẩm (`/san-pham`) | `1440×900` | Desktop | PASS | **PASS** |
| 13 | Chi tiết sản phẩm (`/san-pham/{slug}`) | `375×812` | Mobile | FAIL (`sw=383`) | **PASS** |
| 14 | Giỏ hàng có sản phẩm (`/gio-hang`) | `320×640` | Mobile | Chưa đo có SP | **PASS** |
| 15 | Giỏ hàng có sản phẩm (`/gio-hang`) | `375×812` | Mobile | Chưa đo có SP | **PASS** |
| 16 | Thanh toán có sản phẩm (`/thanh-toan`) | `320×640` | Mobile | Chưa đo có SP | **PASS** |
| 17 | Thanh toán có sản phẩm (`/thanh-toan`) | `375×812` | Mobile | Chưa đo có SP | **PASS** |
| 18 | Thanh toán có sản phẩm (`/thanh-toan`) | `390×844` | Mobile | Chưa đo có SP | **PASS** |
| 19 | Đăng nhập (`/dang-nhap`) | `375×812` | Mobile | PASS (input < 16px) | **PASS** |
| 20 | Tra cứu đơn hàng (`/tra-cuu-don-hang`) | `375×812` | Mobile | PASS (input < 16px) | **PASS** |
| 21 | Trang 404 (`/trang-khong-ton-tai-404`) | `375×812` | Mobile | PASS | **PASS** |
| 22 | Admin Dashboard (`/admin`) | `320×640` | Mobile | FAIL (`sw=337`) | **PASS** |
| 23 | Admin Dashboard (`/admin`) | `375×812` | Mobile | PASS (input < 16px) | **PASS** |
| 24 | Admin Dashboard (`/admin`) | `768×1024` | Tablet | PASS | **PASS** |
| 25 | Admin Dashboard (`/admin`) | `812×375` | Landscape | PASS | **PASS** |
| 26 | Admin Dashboard (`/admin`) | `1440×900` | Desktop | PASS | **PASS** |
| 27 | Admin Danh mục (`/admin/danh-muc`) | `375×812` | Mobile | PASS | **PASS** |
| 28 | Admin Sản phẩm (`/admin/san-pham`) | `375×812` | Mobile | PASS | **PASS** |
| 29 | Admin Thêm sản phẩm (`/admin/san-pham/them`) | `375×812` | Mobile | FAIL (`sw=434`) | **PASS** |
| 30 | Admin Serial (`/admin/serial`) | `375×812` | Mobile | PASS | **PASS** |
| 31 | Admin Chi tiết Serial (`/admin/serial/1`) | `375×812` | Mobile | FAIL (`sw=820`) | **PASS** |
| 32 | Admin Kiểm định 18 bước (`/admin/serial/1/kiem-dinh/them`) | `375×812` | Mobile | FAIL (`sw=381`) | **PASS** |
| 33 | Admin Đơn hàng (`/admin/don-hang`) | `375×812` | Mobile | PASS | **PASS** |
| 34 | Admin Chi tiết đơn hàng (`/admin/don-hang/{id}`) | `375×812` | Mobile | PASS | **PASS** |
| 35 | Admin Khách hàng (`/admin/khach-hang`) | `375×812` | Mobile | PASS | **PASS** |
| 36 | Admin Chi tiết khách hàng (`/admin/khach-hang/{id}`) | `375×812` | Mobile | PASS | **PASS** |
| 37 | Admin Voucher (`/admin/voucher`) | `375×812` | Mobile | PASS | **PASS** |
| 38 | Admin Thêm voucher (`/admin/voucher/them`) | `375×812` | Mobile | PASS | **PASS** |
| 39 | Admin Lịch sử voucher (`/admin/voucher/1/lich-su`) | `375×812` | Mobile | PASS | **PASS** |

**Tổng kết ma trận:** **39/39 PASS** (`issuesCount = 0`).

---

## 3. Tóm tắt lỗi phát hiện & File đã chỉnh sửa

Chi tiết từng lỗi xem tại [`docs/MOBILE_RESPONSIVE_BUG_LIST.md`](MOBILE_RESPONSIVE_BUG_LIST.md) (`M-BUG-01` → `M-BUG-07`).

**Các file mã nguồn đã chỉnh sửa (chỉ CSS & JS giao diện, không sửa PHP backend hay Database):**
1. `public/assets/css/style.css` — Sửa tràn ngang trang chủ (`320px–768px`), sửa lưới 12 cột ở trang chi tiết sản phẩm, chuyển lưới sản phẩm về 1 cột ở `<= 360px`, chuẩn hóa `font-size: 16px` & `min-height: 44px` cho input/nút trên mobile (`<= 768px`).
2. `public/assets/css/admin.css` — Chống tràn ngang trên toàn bộ `.admin-main`, `.admin-content`, `.admin-card`, `.adm-field`; chuyển `.adm-form-grid`, `.adm-serial-detail-grid`, `.adm-detail-grid`, `.adm-kv-row` sang 1 cột ở `<= 768px`; chuẩn hóa `font-size: 16px` & `min-height: 44px` cho input mobile Admin; giới hạn `.adm-chart-box canvas { max-width: 100%; }`.
3. `public/assets/js/admin.js` — Điều chỉnh bề rộng tối thiểu khi vẽ `#revenueChartCanvas` từ `Math.max(300, ...)` xuống `Math.max(200, Math.floor(chartWrap.clientWidth || 300))` để không gây tràn ngang ở màn hình `320px`.

---

## 4. Danh sách ảnh chụp màn hình đã lưu (`docs/screenshots/mobile-final/`)

Tổng cộng **18 ảnh PNG** chụp từ Chrome thật:
- **Trước khi sửa (bằng chứng lỗi):**
  - `before_home_320.png`
  - `before_home_375.png`
- **Khách hàng (sau khi sửa):**
  - `home_320.png`
  - `home_375.png`
  - `products_375.png`
  - `product_detail_375.png`
  - `cart_375.png` (có sản phẩm RAM Kingston Fury Beast 8GB trong giỏ)
  - `checkout_375.png` (trang `/thanh-toan` đầy đủ 3 bước & form giao nhận)
- **Quản trị Admin (sau khi sửa):**
  - `admin_dashboard_375.png`
  - `admin_dashboard_768.png`
  - `admin_dashboard_1440.png` (kiểm tra regression Desktop)
  - `admin_products_375.png`
  - `admin_serial_375.png`
  - `admin_inspection_375.png`
  - `admin_orders_375.png`
  - `admin_order_detail_375.png`
  - `admin_customers_375.png`
  - `admin_vouchers_375.png`

---

## 5. Kết quả kiểm tra Console JS, HTTP, Tương tác cảm ứng & Regression

| Hạng mục kiểm tra | Kết quả | Chi tiết |
| --- | --- | --- |
| **Lỗi Console JavaScript** | **PASS** | `0` ngoại lệ / lỗi console trên toàn bộ 39 lượt tải trang. |
| **Lỗi HTTP / Tài nguyên / Ảnh vỡ** | **PASS** | `0` request >= 400 (ngoại trừ `/trang-khong-ton-tai-404` chủ đích), `0` ảnh vỡ (`naturalWidth === 0`). |
| **Menu Mobile Khách hàng (`375×812`)** | **PASS** | Bấm nút toggle danh mục chuyển `aria-expanded`: `"true"` → `"false"` chính xác. |
| **Sidebar Drawer Admin (`375×812`)** | **PASS** | Bấm `#adminMenuToggle` mở `body.admin-sidebar-open = true`, bấm `#adminSidebarBackdrop` đóng về `false` chính xác. |
| **Input Font-Size & Touch Target (`<= 768px` Portrait)** | **PASS** | `0` ô input/select/textarea có `font-size < 16px` trên mobile portrait (chống iOS Safari auto-zoom). |
| **Kiểm tra cú pháp JS (`node --check`)** | **PASS** | `public/assets/js/admin.js` và `public/assets/js/app.js` hợp lệ. |
| **Regression Desktop (`1440×900`)** | **PASS** | Trang chủ, Danh sách sản phẩm và Admin Dashboard `1440×900` giữ nguyên bố cục Google Stitch, không bị ảnh hưởng bởi CSS mobile. |
| **Regression E2E Backend (`run_final_e2e_integration_tests.php`)** | **PASS** | **9/9** kịch bản E2E tích hợp cuối PASS trên `tl_gaming_test`. |
| **Kiểm thử trên điện thoại vật lý thật (iOS Safari / Android Chrome thực tế)** | **NOT RUN** | Môi trường hiện tại chỉ chạy giả lập thiết bị trên Chrome Desktop (CDP Mobile Emulation + Touch Emulation). Không có thiết bị iPhone/Android vật lý kết nối để kiểm thử trực tiếp bàn phím ảo, thanh địa chỉ động (`100dvh`) và cuộn quán tính thật. |

---

## 6. Hạn chế cần lưu ý & Khuyến nghị trước khi bàn giao
1. **Giả lập Chrome ≠ Điện thoại vật lý thật:** Chrome CDP Mobile Emulation mô phỏng chính xác kích thước viewport, DPR `2x`, touch event và layout CSS, nhưng **không thay thế hoàn toàn** việc kiểm tra thực tế trên iPhone (Safari iOS) và Android (Chrome Mobile) — đặc biệt là hành vi tự động cuộn khi mở bàn phím ảo ở trang `/thanh-toan` và `/admin/serial/{id}/kiem-dinh/them`.
2. **Đề xuất khi demo đồ án:** Nên mở website qua mạng LAN nội bộ bằng điện thoại thật để test nhanh 3 thao tác chính: tìm kiếm sản phẩm, thêm vào giỏ + đặt hàng, và duyệt phiếu kiểm định 18 bước trên màn hình dọc.
