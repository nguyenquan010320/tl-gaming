# Báo cáo Kiểm thử Admin Business Dashboard & Hồi quy Toàn hệ thống (Prompt 21)

Toàn bộ kiểm thử được thực thi trên CSDL cô lập `tl_gaming_test` với cờ `--confirm-test-db` (không tác động CSDL `tl_gaming`).

| Bộ kiểm thử / Kiểm tra | Kết quả | Chi tiết |
| --- | --- | --- |
| `scripts/run_admin_dashboard_tests.php --confirm-test-db` | **PASS (9/9)** | RBAC 403 Guest/Customer, Admin 200 OK, CSDL trống không chia 0 & đủ 30 mốc, Doanh thu=3tr/AOV=1.5tr (gồm đơn 0đ, không cộng pending/cancelled, không nhân bản nhiều `order_items` & nhiều `payments`, không gộp Guest), biểu đồ năm 12 tháng, chặn ngày tùy chọn sai, Top sản phẩm đúng snapshot, đơn gần đây không lộ SĐT/email, cảnh báo vận hành & serial đạt chuẩn 18 bước, và test HTTP POST thiếu `is_active` ở Voucher bị từ chối + giữ nguyên trạng thái |
| `scripts/run_admin_customer_coupon_tests.php --confirm-test-db` | **PASS (17/17)** | Hồi quy Quản lý Khách hàng & Voucher |
| `scripts/run_admin_order_tests.php --confirm-test-db` | **PASS (32/32)** | Hồi quy Quản lý Đơn hàng & Hoàn tồn kho |
| `scripts/run_checkout_tests.php --confirm-test-db` | **PASS (37/37)** | Hồi quy Checkout, Idempotency & Voucher |
| `scripts/run_cart_tests.php --confirm-test-db` | **PASS (14/14)** | Hồi quy Giỏ hàng |
| `scripts/run_regression_tests.php --confirm-test-db` | **PASS (12/12)** | Hồi quy Auth, RateLimiter, CSRF & RBAC |
| `scripts/run_serial_concurrency_tests.php --confirm-test-db` | **PASS (11/11)** | Hồi quy Tương tranh Serial & Kiểm định |
| `scripts/run_admin_serial_tests.php --confirm-test-db` | **PASS (34/34)** | Hồi quy Quản lý Serial & Kiểm định 18 bước |
| `scripts/run_admin_product_tests.php --confirm-test-db` | **PASS (31/31)** | Hồi quy Quản lý Sản phẩm Admin |
| `scripts/run_admin_category_tests.php --confirm-test-db` | **PASS (24/24)** | Hồi quy Quản lý Danh mục Admin |
| `php -l` các tệp PHP & `node --check` (`admin.js`, `app.js`) | **PASS** | Không lỗi cú pháp |
| Responsive desktop/tablet/mobile trên trình duyệt thực | **NOT RUN** | Đã cài đặt CSS responsive (`<= 1180px`, `<= 640px`) và Canvas auto-resize, chưa mở trình duyệt kiểm tra trực quan ở lượt này |
