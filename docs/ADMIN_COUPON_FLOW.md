# Luồng Nghiệp vụ Quản lý Voucher Admin (Prompt 20)

## 1. Thành phần mã nguồn
- **Model**: [AdminCouponModel.php](file:///e:/xampp/htdocs/tl-gaming/app/Models/AdminCouponModel.php)
- **Controller**: [CouponController.php](file:///e:/xampp/htdocs/tl-gaming/app/Controllers/Admin/CouponController.php)
- **Views**: [index.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/coupons/index.php), [form.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/coupons/form.php), [history.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/coupons/history.php)
- **Routes**: `GET /admin/voucher`, `GET/POST /admin/voucher/them`, `GET/POST /admin/voucher/{id}/sua`, `POST /admin/voucher/{id}/bat`, `POST /admin/voucher/{id}/tat`, `GET /admin/voucher/{id}/lich-su`.

## 2. Quy tắc Validation & Khởi tạo
- `code`: chuẩn hóa viết hoa `A-Z0-9_-` (3–50 ký tự), duy nhất (kiểm tra `FOR UPDATE` + ràng buộc `UNIQUE`).
- `discount_type`: `percentage` (1–100, tùy chọn `max_discount_amount`) hoặc `fixed_amount` (> 0 VND).
- `min_order_amount >= 0`, `usage_limit >= 0` (`0` = không giới hạn), `per_user_limit >= 1`, `end_date >= start_date`.
- Chống tràn số nguyên VND, chặn dữ liệu POST mảng bất thường, `usage_count` luôn khởi tạo bằng `0` (bỏ qua giá trị client gửi lên).

## 3. Chính sách Sửa voucher an toàn
| Trạng thái lịch sử | Trường ĐƯỢC PHÉP sửa | Trường KHÓA CỨNG |
| --- | --- | --- |
| **Chưa từng sử dụng** (`usage_count = 0`, chưa có dòng trong `coupon_usages`/`orders`) | `code`, `description`, `discount_type`, `discount_value`, `max_discount_amount`, `min_order_amount`, `usage_limit`, `per_user_limit`, `start_date`, `end_date`, `is_active` | `usage_count` |
| **Đã phát sinh sử dụng** | `description`, `min_order_amount`, `usage_limit` (bắt buộc `= 0` hoặc `>= usage_count`), `per_user_limit`, `start_date`, `end_date`, `is_active` | `code`, `discount_type`, `discount_value`, `max_discount_amount`, `usage_count` |

- Không cho xóa cứng voucher đã xuất hiện trong `orders` hoặc `coupon_usages`.

## 4. Đồng bộ Checkout, Admin Orders & Lịch sử sử dụng
- Voucher tạo/bật từ Admin áp dụng ngay tại Checkout (`CouponModel::validateCouponForCheckout`); voucher tắt, hết hạn hoặc hết lượt bị từ chối ngay (kể cả khi 2 khách tranh lượt cuối cùng đồng thời nhờ khóa `FOR UPDATE`).
- Khi hủy đơn hợp lệ tại Admin Orders, lượt dùng được hoàn theo quy tắc Prompt 19.1 (`coupon_usages.status = 'cancelled'`, giảm `coupons.usage_count` đúng 1 lần).
- Trang lịch sử (`/admin/voucher/{id}/lich-su`) chỉ đọc, phân biệt rõ: `usage_count` đang tính vào giới hạn, tổng lượt từng phát sinh, lượt `used`, lượt `cancelled` và tổng tiền giảm chỉ tính trên các lượt `used`.


## Bổ sung Prompt 20.1 — Validation nghiêm ngặt `is_active`
- Trong `AdminCouponModel::validateCouponPayload()`, trường `is_active` chỉ chấp nhận đúng chuỗi `'0'` hoặc `'1'`.
- Loại bỏ hoàn toàn cách ép kiểu lỏng bằng `!empty()`, ném `DomainException` đối với mọi giá trị sai (`'abc'`, `'-1'`, giá trị rỗng, mảng...) để không bao giờ vô tình kích hoạt voucher khi nhận dữ liệu bất thường.
- `CouponController::extractSafePostPayload()` tiếp tục từ chối mọi trường `$_POST` dạng mảng/object trước khi chuyển vào Model.
