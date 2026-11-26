# Báo cáo Kiểm thử Phân hệ Quản lý Serial & Kiểm định 18 bước (Prompt 18 & 18.1)

- **Môi trường kiểm thử**: PHP 8.5.12 (`E:\xampp\php\php.exe`), MariaDB (`tl_gaming_test` cô lập với `--confirm-test-db`), PHP Built-in Server.
- **Ngày chạy**: 2026-11-14

## 1. Các lỗi đã khắc phục ở Prompt 18.1
1. **Thống nhất quy tắc đủ điều kiện bán** trên cả `OrderModel`, `ProductModel`, `AdminProductModel`, `ProductUnitModel` và cache `products.stock_quantity`: chỉ máy `available` có biên bản kiểm định mới nhất đạt `passed` và đủ 18 bước `inspection_items` mới được bán (chặn máy chưa kiểm định, `conditional_pass`, `failed`, `reserved`/`sold`/`repairing`/`returned`).
2. **Bảo vệ luồng `returned` / `repairing` → `available`**: thêm `returned_at` và `returned_baseline_inspection_id` vào `product_units`, chặn dùng biên bản cũ trước khi hoàn trả (kể cả trong cùng 1 giây), chặn `conditional_pass` mở bán lại.
3. **Sửa khóa lạc quan (Optimistic Locking)**: thêm `row_version` tăng dần trên `product_units`, bắt buộc `expected_row_version > 0` khi sửa (không cho bỏ trống), tăng `row_version` cả khi Checkout giữ máy hoặc kiểm định mở bán lại, giải quyết triệt để xung đột trong cùng 1 giây.

## 2. Kiểm tra cú pháp (`php -l`)
Tất cả các tệp PHP mới tạo và chỉnh sửa đều đạt `No syntax errors detected`:
- `app/Models/ProductUnitModel.php`, `app/Models/InspectionModel.php`, `app/Models/AdminProductModel.php`, `app/Models/ProductModel.php`, `app/Models/OrderModel.php`
- `app/Controllers/Admin/SerialController.php`, `app/Controllers/Admin/InspectionController.php`
- `app/Views/admin/serials/form.php`

## 3. Kết quả chạy các bộ kiểm thử tự động trên `tl_gaming_test`

| Bộ kiểm thử | Lệnh chạy | Kết quả | Ghi chú |
| :--- | :--- | :--- | :--- |
| Serial & Kiểm định 18 bước (Prompt 18 & 18.1) | `php scripts/run_admin_serial_tests.php --confirm-test-db` | **34/34 PASS** (FAIL: 0) | Gồm toàn bộ S1–S34: máy chưa kiểm định bị chặn, `conditional_pass` bị chặn, `passed` cũ + `failed` mới bị chặn, `returned` cùng giây không dùng biên bản cũ, thiếu `expected_row_version` bị chặn, 2 Admin sửa cùng 1 giây, Checkout vs Admin `row_version`, đồng bộ tồn Storefront/Admin/Checkout. |
| Quản lý Sản phẩm Admin (Prompt 17) | `php scripts/run_admin_product_tests.php --confirm-test-db` | **31/31 PASS** (FAIL: 0) | Không hồi quy. |
| Quản lý Danh mục Admin (Prompt 16) | `php scripts/run_admin_category_tests.php --confirm-test-db` | **24/24 PASS** (FAIL: 0) | Không hồi quy. |
| Checkout & Đặt hàng (Prompt 15/15.1) | `php scripts/run_checkout_tests.php --confirm-test-db` | **37/37 PASS** (FAIL: 0) | Đã cập nhật setup trên `tl_gaming_test` (cấp biên bản `passed` 18 bước cho máy test trong `tl_gaming_test`, không sửa `seed_tl_gaming.sql`). |
| Giỏ hàng (Prompt 14/14.1) | `php scripts/run_cart_tests.php --confirm-test-db` | **14/14 PASS** (FAIL: 0) | Đã cập nhật setup trên `tl_gaming_test` và dọn `storage/ratelimit/*.json` sau lượt Checkout TC32. |
| Bảo mật & Xác thực (Prompt 13/14) | `php scripts/run_regression_tests.php --confirm-test-db` | **12/12 PASS** (FAIL: 0) | Không hồi quy. |

## 4. Hạng mục chưa chạy (`NOT RUN`)
- Kiểm thử tương tác JavaScript phía trình duyệt thực và responsive UI trên trình duyệt thực: **NOT RUN** (đã kiểm thử toàn bộ HTTP SSR, HTML markup, transaction, concurrency và nghiệp vụ server-side/CSDL).


## Cập nhật kiểm thử Prompt 18.2 (Chạy lại toàn bộ trên `tl_gaming_test`)

| Bộ kiểm thử | Kết quả | Chi tiết |
| --- | --- | --- |
| `scripts/run_serial_concurrency_tests.php --confirm-test-db` | **11/11 PASS** | CC-01..CC-11 (2 kết nối PDO độc lập + `proc_open` song song, `row_version`, `order_items.product_unit_id`, CC-08 kiểm thử retry bằng `PDOException 40001/1213` mô phỏng, CC-11 xác minh 2 kết nối InnoDB thật khóa `FOR UPDATE` tuần tự hóa sạch) |
| `scripts/run_admin_serial_tests.php --confirm-test-db` | **34/34 PASS** | Toàn bộ kiểm thử Serial & Kiểm định 18 bước |
| `scripts/run_admin_product_tests.php --confirm-test-db` | **31/31 PASS** | Toàn bộ kiểm thử Quản lý Sản phẩm Admin |
| `scripts/run_admin_category_tests.php --confirm-test-db` | **24/24 PASS** | Toàn bộ kiểm thử Quản lý Danh mục Admin |
| `scripts/run_checkout_tests.php --confirm-test-db` | **37/37 PASS** | Toàn bộ kiểm thử Checkout, Idempotency & Inventory |
| `scripts/run_cart_tests.php --confirm-test-db` | **14/14 PASS** | Toàn bộ kiểm thử Giỏ hàng (sau khi xóa `storage/ratelimit/*.json`) |
| `scripts/run_regression_tests.php --confirm-test-db` | **12/12 PASS** | Toàn bộ kiểm thử hồi quy Auth & Security |
| `scripts/sync_serial_stock_cache.php --db=tl_gaming_test --dry-run` | **0 lệch / 13 SP** | Toàn bộ 13 sản phẩm `has_serials = 1` khớp `stock_quantity == sellable_count` |
| Migration `20261114_product_units_integrity_and_row_version.sql` | **PASS (2x idempotent)** | Chạy 2 lần liên tiếp trên `tl_gaming_test` không lỗi |
| `php -l` trên các tệp PHP đã sửa | **PASS** | `Model.php`, `ProductUnitModel.php`, `InspectionModel.php`, `OrderModel.php`, `sync_serial_stock_cache.php`, `run_serial_concurrency_tests.php` |
| Kiểm thử giao diện JS trình duyệt thực | **NOT RUN** | Chưa chạy kiểm thử tương tác trình duyệt trong lượt này |
