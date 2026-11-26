# Luồng Nghiệp vụ Quản lý Đơn hàng Admin & Hoàn tồn kho (Prompt 19)

## 1. Kiến trúc & Các tệp thành phần
- **Model**: [AdminOrderModel.php](file:///e:/xampp/htdocs/tl-gaming/app/Models/AdminOrderModel.php)
- **Controller**: [OrderController.php](file:///e:/xampp/htdocs/tl-gaming/app/Controllers/Admin/OrderController.php)
- **Views (Admin Layout Stitch)**:
  - Danh sách & bộ lọc phân trang server: [index.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/orders/index.php)
  - Chi tiết đơn, thanh toán, State Machine & Audit log: [show.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/orders/show.php)
- **Routes**: [web.php](file:///e:/xampp/htdocs/tl-gaming/routes/web.php) (bảo vệ bằng `AdminMiddleware` và `CsrfMiddleware` cho mọi `POST`).
- **Migration**: [20261114_admin_orders_audit_and_refund_flag.sql](file:///e:/xampp/htdocs/tl-gaming/database/migrations/20261114_admin_orders_audit_and_refund_flag.sql) (tương thích cả MySQL 8 và MariaDB, bổ sung cột cờ hoàn tiền thủ công trên `payments` và bảng audit `order_status_logs`).

## 2. State Machine đơn hàng (theo đúng ENUM `orders.status`)
ENUM hiện có: `'pending'`, `'confirmed'`, `'shipping'`, `'completed'`, `'cancelled'`.

- `pending` (Chờ xác nhận) → `confirmed` (Xác nhận đơn) hoặc `cancelled` (Hủy đơn)
- `confirmed` (Đã xác nhận) → `shipping` (Đang giao / Xử lý tại showroom) hoặc `cancelled` (Hủy đơn)
- `shipping` (Đang giao / Xử lý) → `completed` (Hoàn tất) — **khóa hủy thường**, cần quy trình trả hàng/hoàn tiền riêng nếu phát sinh hoàn trả.
- `completed` / `cancelled` → Trạng thái kết thúc, không cho chuyển ngược hoặc hủy lại.

Mọi chuyển trạng thái được kiểm tra tập trung qua `AdminOrderModel::assertTransitionAllowed()` kết hợp `SELECT ... FOR UPDATE` trên dòng `orders`.

## 3. Quản lý thanh toán COD & Chuyển khoản
- Tách biệt hoàn toàn với trạng thái đơn hàng qua route `POST /admin/don-hang/{id}/xac-nhan-thanh-toan`.
- **COD**: khi tạo đơn luôn là `payments.status = 'pending'`. Xác nhận đơn (`confirmed`) không tự đánh dấu đã thu tiền. Admin chỉ xác nhận thanh toán khi đã thu tiền thực tế (và bắt buộc đã xác nhận thanh toán trước khi `completeOrder`).
- **Chuyển khoản**: chỉ đánh dấu `completed` sau khi Admin đối soát giao dịch thực tế; nếu `amount > 0` bắt buộc nhập `transaction_code`. Hỗ trợ đơn `0đ` theo đúng giá trị `payments.amount = 0` (không giả mạo `1đ`).
- Chặn xác nhận thanh toán 2 lần và chặn xác nhận thanh toán cho đơn đã hủy.

## 4. Quy tắc giữ & hoàn Serial / Tồn kho số lượng
- **Xác nhận (`confirmOrder`) & Giao hàng (`shipOrder`)**: không trừ `products.stock_quantity` lần 2 (Checkout đã giữ serial `reserved` hoặc trừ `stock_quantity` trước đó). Xác nhận đơn kiểm tra lại serial gắn đơn vẫn ở trạng thái `reserved`.
- **Hoàn tất (`completeOrder`)**: kiểm tra `payments.status === 'completed'`, chuyển serial thuộc đơn từ `reserved` → `sold` và tăng `row_version`, không trừ `stock_quantity` lần 2, giữ nguyên snapshot `order_items`.
- **Hủy đơn (`cancelOrder`)** (chỉ cho phép ở `pending` hoặc `confirmed`, bắt buộc `cancel_reason`):
  - **Sản phẩm số lượng (`has_serials = 0`)**: hoàn đúng `order_items.quantity` vào `products.stock_quantity`.
  - **Sản phẩm Serial (`has_serials = 1`)**: chỉ giải phóng serial đang `reserved` thuộc đúng đơn; nếu biên bản kiểm định mới nhất sau mốc `returned_baseline_inspection_id` đạt `'passed'` đủ 18 bước thì đưa về `'available'`, ngược lại đưa về `'repairing'` (không tự mở bán serial lỗi/chưa đạt). Tăng `row_version` và gọi `ProductUnitModel::syncProductStockCache()`. Không bao giờ tự đưa máy `'sold'` về `'available'`.

## 5. Hoàn lượt Voucher & Hoàn tiền thủ công
- **Voucher**: khóa `coupons` và `coupon_usages` (`status = 'used'`) bằng `FOR UPDATE`; chuyển `coupon_usages.status` sang `'cancelled'` (giữ nguyên lịch sử) và giảm `coupons.usage_count` đi đúng số dòng hủy được (không bao giờ hoàn 2 lần).
- **Đơn đã thanh toán (`payments.status = 'completed'` và `amount > 0`)**: giữ nguyên `payments.status = 'completed'` (không giả lập `'refunded'`), bật `payments.refund_status = 'manual_required'` kèm `refund_note` và `refund_requested_at`.

## 6. Transaction, Thứ tự khóa & Audit
- Toàn bộ thao tác ghi dùng `Model::runTransactionWithDeadlockRetry()` (bắt `SQLSTATE 40001` / mã `1213`, `1205`, rollback toàn phần trước khi retry tối đa 3 lần).
- Thứ tự khóa nhất quán với Checkout & Serial Admin: `products (id ASC FOR UPDATE)` → `orders (FOR UPDATE)` → `product_units (id ASC FOR UPDATE)` → `payments (FOR UPDATE)` → `coupons (FOR UPDATE)` → `coupon_usages (FOR UPDATE)`.
- Mọi thao tác đều ghi vào `order_status_logs` (`order_id`, `action_type`, `old_status`, `new_status`, `actor_user_id`, `reason`, `note`, `created_at`).

## 7. Hướng dẫn sử dụng trên XAMPP
1. Nếu đang dùng DB cũ trước Prompt 19, sao lưu và chạy migration:
   ```powershell
   E:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines --triggers tl_gaming > backup_before_prompt19.sql
   Get-Content database/migrations/20261114_admin_orders_audit_and_refund_flag.sql | E:\xampp\mysql\bin\mysql.exe -u root tl_gaming
   ```
2. Đăng nhập tài khoản Admin tại `/dang-nhap` và truy cập menu **Quản lý đơn hàng** (`/admin/don-hang`).


## Bổ sung Prompt 19.1 — Siết toàn vẹn hoàn kho, hình thức nhận hàng & hoàn lượt voucher

1. **Hoàn tồn kho chặt chẽ (`AdminOrderModel::cancelOrder()`)**:
   - Từ chối hủy và rollback toàn bộ nếu `order_items` rỗng, có dòng `product_id <= 0`/`quantity <= 0`, hoặc thiếu bản ghi `products`.
   - Đối chiếu bắt buộc loại quản lý tồn kho:
     - `has_serials = 1` bắt buộc phải có `product_unit_id`, máy khớp `product_id`, ở trạng thái `reserved`, cập nhật đúng 1 dòng và đồng bộ cache `stock_quantity`.
     - `has_serials = 0` bắt buộc không có `product_unit_id`, kiểm tra không tràn số nguyên và không vượt giới hạn `INT UNSIGNED` (`4294967295`), và xác minh `UPDATE products` tác động đúng 1 dòng (`rowCount() === 1`).
2. **Hình thức nhận hàng (`orders.delivery_method`)**:
   - Migration [20261121_orders_delivery_method.sql](file:///e:/xampp/htdocs/tl-gaming/database/migrations/20261121_orders_delivery_method.sql) thêm cột `delivery_method ENUM('delivery', 'pickup') DEFAULT NULL` vào `orders` (không đoán bừa hay sửa snapshot `shipping_address` của đơn cũ).
   - `OrderModel::createOrderFromCheckout()` lưu trực tiếp `'delivery'` hoặc `'pickup'` vào `orders.delivery_method`.
   - `AdminOrderModel::resolveDeliveryMethod()` ưu tiên `orders.delivery_method`; với đơn cũ (`NULL`), chỉ coi là `'pickup'` nếu `shipping_address` bắt đầu bằng `Nhận tại ` và `shipping_fee === 0`, còn lại trả về `'unknown'` (`Chưa rõ (đơn cũ)`).
3. **Hoàn lượt voucher an toàn**:
   - Khóa `coupons` và `coupon_usages (status = 'used')` bằng `FOR UPDATE`.
   - Kiểm tra `coupons.usage_count >= count($usageRows)` trước khi trừ; tuyệt đối không dùng `GREATEST(0, ...)` để che giấu sai lệch. Nếu không đủ bộ đếm để hoàn, ghi `error_log(...)` và ném `DomainException` rollback toàn bộ.
   - Giữ lại dòng lịch sử `coupon_usages` ở trạng thái `'cancelled'`.
