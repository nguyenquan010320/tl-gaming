# Luồng Quản lý Serial / Đơn vị máy cũ (`product_units`) — TL Gaming Admin

## 1. Tổng quan kiến trúc & Định tuyến
Phân hệ Quản lý Serial quản lý từng cá thể máy tính / linh kiện cũ có `has_serials = 1`. Tất cả route đều đi qua `AdminMiddleware` (`Auth::revalidate()`), và mọi route `POST` đi qua `CsrfMiddleware` + PRG (Post-Redirect-Get):

| Method | URL | Controller::Action | Mô tả |
| :--- | :--- | :--- | :--- |
| `GET` | `/admin/serial` | `SerialController::index` | Danh sách serial, tìm kiếm (`q`), lọc theo sản phẩm, trạng thái, ngoại quan, kết quả kiểm định, phân trang server. |
| `GET` | `/admin/serial/them` | `SerialController::create` | Biểu mẫu thêm máy / serial mới (hỗ trợ `?san-pham={id}`). |
| `POST` | `/admin/serial/them` | `SerialController::store` | Lưu máy / serial mới trong transaction `FOR UPDATE` + đồng bộ `products.stock_quantity`. |
| `GET` | `/admin/serial/{id}` | `SerialController::show` | Xem chi tiết máy, trạng thái `is_sellable` và lịch sử kiểm định. |
| `GET` | `/admin/serial/{id}/sua` | `SerialController::edit` | Biểu mẫu chỉnh sửa máy kèm `expected_row_version`. |
| `POST` | `/admin/serial/{id}/sua` | `SerialController::update` | Cập nhật máy với khóa lạc quan `row_version` và kiểm tra máy trạng thái. |

## 2. Quy tắc chính thức về Serial đủ điều kiện bán (Prompt 18.1)
Áp dụng thống nhất 100% tại `ProductUnitModel::sellableUnitWhereSql()`, `ProductUnitModel::computeSellableFlag()`, `OrderModel`, `ProductModel`, `AdminProductModel` và cache `products.stock_quantity`:
1. `product_units.status = 'available'`.
2. Phải tồn tại biên bản kiểm định mới nhất (`ORDER BY inspection_date DESC, id DESC LIMIT 1`) có đủ **18 bước** (`COUNT(DISTINCT step_number) = 18` trong `inspection_items`).
3. Kết luận biên bản mới nhất đó phải là `passed`.
4. **Không được bán**: máy chưa kiểm định (`NULL`), máy có biên bản mới nhất `failed` hoặc `conditional_pass` (chưa có chính sách phê duyệt riêng), và máy ở trạng thái `reserved`, `sold`, `repairing`, `returned`.
5. **Đồng bộ tồn kho**: `ProductUnitModel::syncProductStockCache()` cập nhật `products.stock_quantity` trong cùng transaction mỗi khi thêm/sửa `product_units`, lập biên bản mới (`InspectionModel::createInspection`), hoặc giữ máy khi Checkout (`OrderModel::createOrderFromCheckout`).

## 3. Khóa lạc quan (`row_version`) & An toàn hoàn trả (`returned`)
- **Khóa lạc quan bằng số nguyên tăng dần (`row_version`)**:
  - `DATETIME` trong MySQL không có microsecond nên 2 request sửa trong cùng 1 giây có thể có cùng `updated_at`.
  - Bảng `product_units` dùng cột `row_version INT UNSIGNED NOT NULL DEFAULT 1`.
  - `ProductUnitModel::updateUnit()` **bắt buộc** có `expected_row_version > 0` (không cho bỏ trống để vượt qua), khóa `SELECT ... FOR UPDATE`, đối chiếu khớp `row_version` rồi tăng `row_version = row_version + 1`.
  - Checkout (`OrderModel`) khi chuyển `available -> reserved` và kiểm định (`InspectionModel`) khi mở bán `repairing/returned -> available` cũng tăng `row_version = row_version + 1`, chặn Admin ghi đè từ phiên cũ.
- **Bảo vệ máy hoàn trả (`returned`) & sửa chữa (`repairing`)**:
  - Khi chuyển sang `returned`, hệ thống lưu mốc `returned_at = NOW()` và `returned_baseline_inspection_id = MAX(id)` của `product_inspections` tại thời điểm hoàn trả.
  - Khi chuyển `returned -> available`, bắt buộc phải có biên bản kiểm định **mới** (`id > returned_baseline_inspection_id`, `created_at >= returned_at`) với đủ 18 bước và kết luận `passed` (loại trừ hoàn toàn biên bản cũ trước hoàn trả, kể cả xảy ra trong cùng 1 giây).

## 4. Migration áp dụng cho CSDL cũ
Nếu CSDL đã khởi tạo trước Prompt 18.1, chạy migration non-destructive:
- File: `database/migrations/20261114_product_units_integrity_and_row_version.sql`
- Lệnh XAMPP: `E:\xampp\mysql\bin\mysql.exe -u root tl_gaming < database/migrations/20261114_product_units_integrity_and_row_version.sql`
- (Nếu cài mới từ `database/tl_gaming.sql`, schema đã bao gồm sẵn `row_version`, `returned_at`, `returned_baseline_inspection_id`).


## Bổ sung Prompt 18.2 — Thứ tự khóa, Deadlock Retry, Migration tương thích MySQL 8/MariaDB & Đối soát tồn kho cache

1. **Thứ tự khóa nhất quán chống Deadlock (`products.id ASC -> product_units -> product_inspections/inspection_items`)**:
   - `OrderModel::createOrderFromCheckout()`: sắp xếp sản phẩm theo `product_id ASC`, khóa `products ... FOR UPDATE` trước khi khóa `product_units ... FOR UPDATE`.
   - `ProductUnitModel::createUnit()` và `ProductUnitModel::updateUnit()`: xác định tập `[$preProductId, $newProductId]`, sắp xếp `SORT_NUMERIC`, khóa các dòng `products` theo thứ tự `id ASC FOR UPDATE` **trước** khi khóa `product_units ... FOR UPDATE`, xác minh `row_version` và `product_id`, sau đó đồng bộ `products.stock_quantity`.
   - `InspectionModel::createInspection()`: khóa `products WHERE id = :pid FOR UPDATE` **trước** khi khóa `product_units WHERE id = :id FOR UPDATE`, chèn `product_inspections` + 18 `inspection_items`, tùy chọn mở bán `available` (chỉ khi đạt chuẩn) và đồng bộ `products.stock_quantity`.
2. **Deadlock Detection & Transaction Retry (`Model::runTransactionWithDeadlockRetry`)**:
   - Phát hiện `SQLSTATE 40001` hoặc driver error code `1213` (Deadlock) / `1205` (Lock wait timeout) qua `Model::isDeadlockException()`.
   - Rollback toàn bộ transaction hiện tại, chờ jitter backoff `15-60ms`, thử lại tối đa 3 lần; không bao giờ tạo trùng đơn hàng/biên bản kiểm định hoặc báo thành công sau rollback.
3. **Migration tương thích MySQL 8 & MariaDB (`database/migrations/20261114_product_units_integrity_and_row_version.sql`)**:
   - Dùng `INFORMATION_SCHEMA.COLUMNS` (`TABLE_SCHEMA = DATABASE()`) kết hợp `PREPARE / EXECUTE / DEALLOCATE PREPARE`, loại bỏ cú pháp `ADD COLUMN IF NOT EXISTS` riêng của MariaDB, không `DROP TABLE`, an toàn khi chạy lặp lại.
4. **Script đối soát tồn kho cache (`scripts/sync_serial_stock_cache.php`)**:
   - Mặc định chạy `--dry-run` (chỉ báo cáo chênh lệch, không ghi DB); chỉ cập nhật `products.stock_quantity` khi truyền `--apply`.
   - Chỉ đối soát sản phẩm `has_serials = 1` theo `ProductUnitModel::sellableUnitWhereSql()`, khóa `products FOR UPDATE` theo `id ASC`, không chạm `has_serials = 0` và không tạo kiểm định giả.
