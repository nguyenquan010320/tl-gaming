# Định nghĩa Chỉ số Kinh doanh (KPI Definitions) — TL Gaming Admin Dashboard

## 1. Quy tắc thời gian & múi giờ
- Múi giờ thống nhất: **`Asia/Ho_Chi_Minh`** (xử lý phía PHP Server, không tin giờ trình duyệt).
- Mọi truy vấn lọc khoảng thời gian đều sử dụng nửa khoảng mở **`[start_at, end_at)`** (`col >= :start_at AND col < :end_at`), không dùng `23:59:59`.
- Khoảng tùy chọn (`custom`) giới hạn tối đa **366 ngày** và bắt buộc `end_date >= start_date`.

## 2. Công thức từng KPI & Quy tắc chống tính trùng
| Chỉ số KPI | Công thức SQL / Nguồn dữ liệu | Mốc phân kỳ | Quy tắc chống trùng & lưu ý |
| --- | --- | --- | --- |
| **Doanh thu đơn hoàn tất** | `SUM(orders.final_amount)` với `orders.status = 'completed'` | `orders.created_at` (ngày đặt đơn) | Truy vấn trực tiếp trên `orders` (không JOIN `order_items`/`payments` -> không nhân bản); không cộng `pending`/`cancelled`; hỗ trợ đơn `0đ`; không tính lại bằng giá mới của `products`. |
| **Tiền đã xác nhận thu** | `SUM(p.amount)` trên bản ghi `payments` có `status = 'completed'` mới nhất (`MAX(id)`) của mỗi `order_id` | `COALESCE(p.confirmed_at, p.created_at)` | Dùng subquery `GROUP BY order_id` lấy `MAX(id)` để chống cộng trùng nếu 1 đơn có nhiều bản ghi `payments`. Phân biệt rõ với Doanh thu đơn hoàn tất (ví dụ đơn đã thu tiền nhưng đang giao hoặc bị hủy). |
| **Khoản cần hoàn tiền thủ công** | `COUNT(*)` và `SUM(p.amount)` trên bản ghi `payments` mới nhất mỗi đơn có `refund_status = 'manual_required'` | Toàn thời gian (vận hành hiện hành) | Không coi là đã hoàn tiền; luôn hiển thị để Admin không bỏ sót khoản nợ hoàn tiền cũ. |
| **Giá trị đơn trung bình (AOV)** | `SUM(final_amount completed) / COUNT(completed)` | `orders.created_at` | Khi `completed_orders = 0` trả về `0` và hiển thị `"Chưa có dữ liệu"`, không chia cho 0. |
| **Phân bố trạng thái đơn** | `COUNT(*)` theo `GROUP BY orders.status` (`pending`, `confirmed`, `shipping`, `completed`, `cancelled`) | `orders.created_at` | Truy vấn trực tiếp bảng `orders`, không nhân bản dòng. |
| **Khách hàng** | Tổng `users.role = 'customer'`; khách mới trong kỳ (`users.created_at`); khách có đơn hoàn tất (`COUNT(DISTINCT orders.user_id)` với `user_id IS NOT NULL`) | `users.created_at` / `orders.created_at` | Tuyệt đối không gộp khách vãng lai (`orders.user_id IS NULL`) vào tài khoản dù trùng email/SĐT. |
| **Sản phẩm & Máy vật lý** | `products.status = 'published'`, `out_of_stock`; máy vật lý đạt chuẩn bán dùng `ProductUnitModel::sellableUnitWhereSql('pu')` | Hiện tại | Tách biệt rõ số dòng sản phẩm (`products`) với số máy vật lý (`product_units` đạt kiểm định 18 bước `passed`). |
| **Top sản phẩm bán chạy** | `SUM(oi.quantity)` và `SUM(oi.subtotal)` từ `order_items oi INNER JOIN orders o` với `o.status = 'completed'` | `orders.created_at` | Dùng hoàn toàn snapshot `order_items`, không cộng đơn `cancelled`, không lấy `products.price` hiện tại. |
