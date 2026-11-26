# Kế hoạch Kiểm thử Tích hợp Cuối Toàn Hệ thống (Final Integration Test Plan) — TL Gaming

## 1. Mục tiêu & Phạm vi
- Kiểm thử toàn bộ hệ thống website bán máy tính cũ **TL Gaming** (PHP 8 thuần, MySQL/MariaDB PDO, MVC tự xây dựng, HTML5/CSS3/JS thuần) từ luồng Khách hàng đến luồng Quản trị, bảo mật, tương tranh đồng thời, giao diện responsive trên trình duyệt Chrome thực và triển khai trên XAMPP.
- **Cô lập dữ liệu**: Toàn bộ kiểm thử ghi dữ liệu chạy trên `tl_gaming_test` (bắt buộc cờ `--confirm-test-db`). Tuyệt đối không tạo dữ liệu giả hay xóa dữ liệu trên CSDL thật `tl_gaming`.

## 2. Bảng đối chiếu nghiệp vụ đã triển khai
| Phân hệ | Route / Module | Trạng thái | Bộ kiểm thử tương ứng |
| --- | --- | --- | --- |
| Trang chủ, Danh sách & Chi tiết SP + 18 bước | `/`, `/san-pham`, `/san-pham/{slug}` | Đã triển khai | `run_regression_tests.php`, `run_final_e2e_integration_tests.php` |
| Xác thực, Phân quyền & RateLimiter | `/dang-nhap`, `/dang-ky`, `/tai-khoan`, `/doi-mat-khau` | Đã triển khai | `run_regression_tests.php` |
| Giỏ hàng Session/MySQL & Hợp nhất | `/gio-hang*` | Đã triển khai | `run_cart_tests.php` |
| Checkout, Idempotency, Voucher & Tra cứu đơn | `/thanh-toan*`, `/tra-cuu-don-hang` | Đã triển khai | `run_checkout_tests.php` |
| Admin Danh mục 3 cấp | `/admin/danh-muc*` | Đã triển khai | `run_admin_category_tests.php` |
| Admin Sản phẩm, Ảnh, Thông số & Tồn kho | `/admin/san-pham*` | Đã triển khai | `run_admin_product_tests.php` |
| Admin Serial & Kiểm định 18 bước | `/admin/serial*`, `/admin/kiem-dinh*` | Đã triển khai | `run_admin_serial_tests.php`, `run_serial_concurrency_tests.php` |
| Admin Đơn hàng, Thanh toán & Hoàn tồn kho | `/admin/don-hang*` | Đã triển khai | `run_admin_order_tests.php` |
| Admin Khách hàng & Voucher | `/admin/khach-hang*`, `/admin/voucher*` | Đã triển khai | `run_admin_customer_coupon_tests.php` |
| Admin Business Dashboard & Analytics | `/admin` | Đã triển khai | `run_admin_dashboard_tests.php` |
| Giao diện trình duyệt thực (1440/768/375px) | Chrome Headless CDP | Đã kiểm thử | Kịch bản kiểm thử trình duyệt Chrome (15 màn hình) |
| Cài đặt hệ thống / Cổng thanh toán ngoài / Nhân viên | Ngoài phạm vi đồ án | Chưa triển khai (hiển thị "Sắp có") | — |
