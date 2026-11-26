# Báo cáo Kiểm thử Quản lý Đơn hàng Admin & Hồi quy (Prompt 19)

Mọi bộ kiểm thử được thực thi trên CSDL cô lập `tl_gaming_test` với cờ `--confirm-test-db` (tuyệt đối không tác động CSDL `tl_gaming`).

## 1. Kết quả các bộ kiểm thử
| Bộ kiểm thử | Kết quả | Phạm vi đã xác minh |
| --- | --- | --- |
| `scripts/run_admin_order_tests.php --confirm-test-db` | **PASS (27/27)** | RBAC (403 Guest/Customer), CSRF (403), Array POST (302 an toàn), XSS & SQLi, Filter & Phân trang server, State Machine, COD/Chuyển khoản tách biệt, Chặn hoàn tất COD chưa thu tiền, Chặn xác nhận thanh toán trùng, Hoàn tất (`reserved` → `sold`, `row_version++`), Hủy đơn hoàn tồn kho số lượng & serial (đạt 18 bước về `available`, `failed` về `repairing`), Hoàn voucher không lặp, Cờ hoàn tiền thủ công (`manual_required`), Deadlock retry mô phỏng (`40001/1213`), 2 Admin xác nhận/hủy đồng thời (`proc_open`), Đồng nhất cache `stock_quantity` |
| `scripts/run_serial_concurrency_tests.php --confirm-test-db` | **PASS (11/11)** | Tương tranh Checkout vs Serial/Inspection, `row_version`, 2 kết nối InnoDB thật, đồng bộ cache |
| `scripts/run_admin_serial_tests.php --confirm-test-db` | **PASS (34/34)** | Quản lý Serial & Kiểm định 18 bước |
| `scripts/run_admin_product_tests.php --confirm-test-db` | **PASS (31/31)** | Quản lý Sản phẩm Admin |
| `scripts/run_admin_category_tests.php --confirm-test-db` | **PASS (24/24)** | Quản lý Danh mục Admin 3 cấp |
| `scripts/run_checkout_tests.php --confirm-test-db` | **PASS (37/37)** | Checkout, Idempotency & Inventory |
| `scripts/run_cart_tests.php --confirm-test-db` | **PASS (14/14)** | Giỏ hàng Session/MySQL & Hợp nhất giỏ |
| `scripts/run_regression_tests.php --confirm-test-db` | **PASS (12/12)** | Auth, RateLimiter, CSRF & RBAC |
| `php -l` trên các tệp PHP đã sửa/tạo | **PASS** | `AdminOrderModel.php`, `OrderController.php`, `orders/index.php`, `orders/show.php`, `layouts/admin.php`, `routes/web.php`, `OrderModel.php`, `run_admin_order_tests.php` |
| Kiểm thử responsive giao diện trên trình duyệt thực | **NOT RUN** | Đã kiểm tra SSR HTML & CSS media query `@media (max-width: 960px)` trong `admin.css`, chưa mở trình duyệt trực quan ở lượt này |

## 2. Lưu ý kỹ thuật phát hiện & đã xử lý trong quá trình test
- **OrderModel truyền PDO kết nối**: đã cập nhật `new CartModel($this->db)` và `new CouponModel($this->db)` trong `OrderModel::createOrderFromCheckout()` để `CartModel` và `CouponModel` luôn dùng chung kết nối/transaction với `OrderModel` (tránh lock wait `1205` khi truyền kết nối PDO độc lập).
- **Baseline tồn kho serial**: toàn bộ 13 sản phẩm `has_serials = 1` trong `tl_gaming_test` khớp `products.stock_quantity == sellable_count` sau toàn bộ kịch bản kiểm thử đơn hàng (`O36`: `mismatch=0`).


## Cập nhật kiểm thử Prompt 19.1 (Chạy trên `tl_gaming_test`)

| Bộ kiểm thử / Kiểm tra | Kết quả | Chi tiết |
| --- | --- | --- |
| `scripts/run_admin_order_tests.php --confirm-test-db` | **PASS (32/32)** | Bổ sung `O37` (sai `has_serials`), `O38` (chống tràn `INT UNSIGNED`), `O39` (serial không `reserved`), `O40` (`usage_count` không nhất quán phải rollback), `O41` (`delivery_method` pickup/delivery & đơn cũ `NULL` -> `unknown`) |
| `scripts/run_serial_concurrency_tests.php --confirm-test-db` | **PASS (11/11)** | Tương tranh Checkout/Serial/Inspection |
| `scripts/run_admin_serial_tests.php --confirm-test-db` | **PASS (34/34)** | Quản lý Serial & Kiểm định 18 bước |
| `scripts/run_admin_product_tests.php --confirm-test-db` | **PASS (31/31)** | Quản lý Sản phẩm Admin |
| `scripts/run_admin_category_tests.php --confirm-test-db` | **PASS (24/24)** | Quản lý Danh mục Admin |
| `scripts/run_checkout_tests.php --confirm-test-db` | **PASS (37/37)** | Checkout, Idempotency & Voucher |
| `scripts/run_cart_tests.php --confirm-test-db` | **PASS (14/14)** | Giỏ hàng & Hợp nhất giỏ |
| `scripts/run_regression_tests.php --confirm-test-db` | **PASS (12/12)** | Auth, RateLimiter, CSRF, RBAC & trang công khai |
| Migration `20261121_orders_delivery_method.sql` | **PASS (2x idempotent)** | Chạy 2 lần trên `tl_gaming_test` không lỗi |
| `php -l` các tệp PHP & `node --check` (`admin.js`, `app.js`) | **PASS** | Không lỗi cú pháp |
| Kiểm thử responsive trên trình duyệt thực | **NOT RUN** | Chưa mở trình duyệt kiểm tra trực quan |
