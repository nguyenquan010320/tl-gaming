# Báo cáo Kiểm thử Quản lý Khách hàng, Voucher & Hồi quy (Prompt 20)

Toàn bộ kiểm thử được thực thi trên CSDL cô lập `tl_gaming_test` với cờ `--confirm-test-db` (không tác động CSDL vận hành `tl_gaming`).

## 1. Kết quả kiểm thử
| Bộ kiểm thử / Kiểm tra | Kết quả | Chi tiết |
| --- | --- | --- |
| `scripts/run_admin_customer_coupon_tests.php --confirm-test-db` | **PASS (15/15)** | RBAC 403 Guest/Customer, không trộn Admin, thống kê đơn & chi tiêu chỉ đơn `completed` không gộp Guest, khóa/mở khóa + `Auth::revalidate()` thu hồi phiên ngay, chặn tự khóa Admin/đổi `role`/`password`, CRUD voucher, validation đầy đủ, khóa trường nhạy cảm sau khi đã dùng, tắt/hết hạn bị Checkout từ chối, 2 khách tranh lượt voucher cuối (`proc_open`, `ok=1/2`), hoàn lượt khi hủy đơn & lịch sử phân biệt `used`/`cancelled`, CSRF (403), POST mảng (302), XSS & SQLi |
| `scripts/run_admin_order_tests.php --confirm-test-db` | **PASS (32/32)** | Hồi quy Quản lý Đơn hàng Admin & Hoàn tồn kho |
| `scripts/run_checkout_tests.php --confirm-test-db` | **PASS (37/37)** | Hồi quy Checkout, Idempotency & Voucher |
| `scripts/run_cart_tests.php --confirm-test-db` | **PASS (14/14)** | Hồi quy Giỏ hàng |
| `scripts/run_regression_tests.php --confirm-test-db` | **PASS (12/12)** | Hồi quy Auth, RateLimiter, CSRF & RBAC |
| `scripts/run_serial_concurrency_tests.php --confirm-test-db` | **PASS (11/11)** | Hồi quy Tương tranh Serial & Kiểm định |
| `scripts/run_admin_serial_tests.php --confirm-test-db` | **PASS (34/34)** | Hồi quy Quản lý Serial & Kiểm định 18 bước |
| `scripts/run_admin_product_tests.php --confirm-test-db` | **PASS (31/31)** | Hồi quy Quản lý Sản phẩm Admin |
| `scripts/run_admin_category_tests.php --confirm-test-db` | **PASS (24/24)** | Hồi quy Quản lý Danh mục Admin |
| `php -l` các tệp PHP & `node --check` (`admin.js`, `app.js`) | **PASS** | Không lỗi cú pháp |
| Kiểm thử responsive giao diện trên trình duyệt thực | **NOT RUN** | Chưa mở trình duyệt kiểm tra trực quan ở lượt này |

## 2. Hướng dẫn kiểm tra trên XAMPP
1. Khởi động Apache và MySQL/MariaDB trên XAMPP.
2. Đăng nhập tài khoản Admin tại `/dang-nhap`.
3. Truy cập **Quản lý khách hàng** (`/admin/khach-hang`) để tìm kiếm, xem chi tiết lịch sử đơn và thử khóa/mở khóa tài khoản khách hàng.
4. Truy cập **Quản lý voucher** (`/admin/voucher`) để thêm mới, sửa, bật/tắt, xem lịch sử sử dụng và thử áp dụng mã ngay tại `/thanh-toan`.


## Cập nhật kiểm thử Prompt 20.1 (Chạy trên `tl_gaming_test`)
- `scripts/run_admin_customer_coupon_tests.php --confirm-test-db`: **PASS (17/17)** — bổ sung `V30` (tạo voucher `is_active='0'` và Checkout từ chối) và `V31` (từ chối `is_active='abc'`, `'-1'`, `['1']` ở cả Model và HTTP POST, xác minh voucher đang tắt không bị vô tình bật lên `1`).
- `scripts/run_admin_order_tests.php --confirm-test-db`: **PASS (32/32)**.
- `scripts/run_checkout_tests.php --confirm-test-db`: **PASS (37/37)**.
- `php -l` (`AdminCouponModel.php`, `run_admin_customer_coupon_tests.php`) & `node --check` (`admin.js`, `app.js`): **PASS**.
- Kiểm thử responsive giao diện trên trình duyệt thực: **NOT RUN**.
