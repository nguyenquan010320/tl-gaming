# Báo cáo Kiểm thử Tích hợp Cuối Toàn Hệ thống (Final Integration Test Report) — TL Gaming

Mọi bộ kiểm thử tự động dưới đây đều được **chạy lại hoàn toàn sau lần chỉnh sửa mã nguồn cuối cùng** trên CSDL cô lập `tl_gaming_test` (không cộng số liệu cũ).

## 1. Tổng hợp kết quả 11 bộ kiểm thử tự động (230/230 PASS)
| # | Bộ kiểm thử | Kết quả | Số lượng | Phạm vi xác minh |
| --- | --- | --- | --- | --- |
| 1 | `scripts/run_final_e2e_integration_tests.php --confirm-test-db` | **PASS** | 9/9 | Hành trình E2E HTTP + Session + CSRF trọn vẹn Khách hàng (14 bước) & Admin (19 bước) |
| 2 | `scripts/run_admin_dashboard_tests.php --confirm-test-db` | **PASS** | 9/9 | KPI hoàn tất, AOV, tiền thu không cộng trùng, đơn 0đ, biểu đồ năm/ngày, cảnh báo, test HTTP thiếu `is_active` Voucher |
| 3 | `scripts/run_admin_customer_coupon_tests.php --confirm-test-db` | **PASS** | 17/17 | Khách hàng, khóa/mở khóa (`Auth::revalidate`), Voucher CRUD, khóa trường nhạy cảm, tranh lượt cuối đồng thời |
| 4 | `scripts/run_admin_order_tests.php --confirm-test-db` | **PASS** | 32/32 | State Machine, COD/CK, `reserved -> sold`, hủy đơn hoàn tồn kho/serial/voucher chặt chẽ, chống tràn `INT UNSIGNED`, `delivery_method`, cờ hoàn tiền thủ công |
| 5 | `scripts/run_checkout_tests.php --confirm-test-db` | **PASS** | 37/37 | Checkout, Idempotency bền vững, đơn 0đ, RateLimiter tra cứu, bảo toàn giỏ mới |
| 6 | `scripts/run_cart_tests.php --confirm-test-db` | **PASS** | 14/14 | Giỏ Session/MySQL, hợp nhất giỏ, 5 tiến trình đồng thời |
| 7 | `scripts/run_regression_tests.php --confirm-test-db` | **PASS** | 12/12 | Auth, Bcrypt, RateLimiter flock, CSRF, RBAC, thu hồi phiên đa thiết bị, trang công khai HTTP 200 |
| 8 | `scripts/run_serial_concurrency_tests.php --confirm-test-db` | **PASS** | 11/11 | Tương tranh Checkout vs Serial/Inspection, `row_version`, 2 kết nối InnoDB thật, deadlock retry mô phỏng |
| 9 | `scripts/run_admin_serial_tests.php --confirm-test-db` | **PASS** | 34/34 | Serial CRUD, kiểm định 18 bước, chuẩn bán `sellableUnitWhereSql()`, đồng nhất tồn kho |
| 10 | `scripts/run_admin_product_tests.php --confirm-test-db` | **PASS** | 31/31 | Sản phẩm, upload ảnh MIME, thông số động, `stock_mode=set/adjust`, rollback ảnh mồ côi |
| 11 | `scripts/run_admin_category_tests.php --confirm-test-db` | **PASS** | 24/24 | Danh mục 3 cấp, chống chu trình, ẩn/hiện lan truyền, XSS & SQLi |

## 2. Kiểm thử Giao diện Trình duyệt Thực (Google Chrome Headless CDP)
- **Môi trường**: Google Chrome thực, server cô lập cổng `18199` trỏ `tl_gaming_test`, kiểm tra 15 màn hình ở `1440x900` (Desktop), `768x1024` (Tablet), `375x812` (Mobile).
- **Kết quả**: **PASS (15/15 màn hình)**
  - `overflowX = false` trên toàn bộ 15 màn hình (kể cả Admin Dashboard Tablet `768px` và Mobile `375px` sau khi sửa `BUG-05`).
  - `consoleErrors = []` (0 lỗi JavaScript) trên toàn bộ 15 màn hình.
  - Sidebar Mobile (`#adminMenuToggle`) tại `375px`: `opened = true`, `closed = true`.
  - Biểu đồ Canvas `#revenueChartCanvas`: vẽ thành công và tự co giãn (`577x260` Desktop, `679x260` Tablet, `301x260` Mobile).
- **Ảnh chụp màn hình thực tế (Artifacts)**:
  - `scratch/ui_screenshots/home_1440.png`
  - `scratch/ui_screenshots/home_375.png`
  - `scratch/ui_screenshots/admin_dashboard_1440.png`
  - `scratch/ui_screenshots/admin_dashboard_768.png`
  - `scratch/ui_screenshots/admin_dashboard_375.png`
  - `scratch/ui_screenshots/admin_orders_1440.png`

## 3. Kiểm thử Bảo mật & Hạ tầng XAMPP
- Apache `.htaccess` chặn truy cập trực tiếp (HTTP `403`): `.env`, `storage/`, `scripts/`, `app/Core/Database.php` -> **PASS**.
- `public/uploads/.htaccess` tồn tại bảo vệ thư mục upload khỏi thực thi PHP -> **PASS**.
- PHP extensions (`pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `openssl`) đầy đủ -> **PASS**.
- Cú pháp PHP (`php -l`) và JS (`node --check public/assets/js/admin.js`, `app.js`) -> **PASS**.
- **Đánh giá mức độ sẵn sàng demo đồ án**: **SẴN SÀNG NGHIỆM THU & DEMO** (0 lỗi Critical/High/Medium còn tồn đọng).
