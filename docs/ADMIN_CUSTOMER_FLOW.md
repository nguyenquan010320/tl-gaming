# Luồng Nghiệp vụ Quản lý Khách hàng Admin (Prompt 20)

## 1. Thành phần mã nguồn
- **Model**: [AdminCustomerModel.php](file:///e:/xampp/htdocs/tl-gaming/app/Models/AdminCustomerModel.php)
- **Controller**: [CustomerController.php](file:///e:/xampp/htdocs/tl-gaming/app/Controllers/Admin/CustomerController.php)
- **Views**: [index.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/customers/index.php), [show.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/customers/show.php)
- **Routes**: `GET /admin/khach-hang`, `GET /admin/khach-hang/{id}`, `POST /admin/khach-hang/{id}/khoa`, `POST /admin/khach-hang/{id}/mo-khoa` trong [web.php](file:///e:/xampp/htdocs/tl-gaming/routes/web.php).

## 2. Quy tắc nghiệp vụ & Thống kê
- **Cô lập vai trò**: Chỉ hiển thị người dùng có `users.role = 'customer'`. Không trộn tài khoản `admin` vào danh sách khách hàng và không cho thao tác khóa/mở khóa tài khoản `admin`.
- **Không gộp khách vãng lai**: Mọi số liệu (`total_orders`, `completed_orders`, `cancelled_orders`, `total_spent`) và lịch sử đơn chỉ truy vấn theo `orders.user_id = users.id`. Đơn khách vãng lai (`orders.user_id IS NULL`) tuyệt đối không tự gộp vào tài khoản kể cả khi trùng email hoặc số điện thoại.
- **Định nghĩa tổng chi tiêu**: Chỉ cộng `orders.final_amount` của các đơn có `orders.status = 'completed'`; không cộng đơn `cancelled` hoặc đơn chưa hoàn tất.
- **Bảo mật dữ liệu**: Không bao giờ truy vấn/hiển thị `password_hash`, token hay dữ liệu phiên.

## 3. Quy tắc khóa / mở khóa tài khoản
- Chạy qua `POST` + `AdminMiddleware` + `CsrfMiddleware` + `SELECT ... FOR UPDATE` trong `Model::runTransactionWithDeadlockRetry()`.
- Không cho Admin tự khóa chính mình (`$customerId === $adminId`).
- Không cho thay đổi `role`, mật khẩu hoặc xóa lịch sử đơn hàng/giỏ/thanh toán.
- Khi chuyển `users.status = 'locked'`, cơ chế `Auth::revalidate()` lập tức thu hồi và từ chối mọi phiên đăng nhập hiện hành/tiếp theo của khách hàng đó.
