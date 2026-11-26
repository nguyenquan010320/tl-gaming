# Kiến trúc & Luồng Hoạt động Admin Business Dashboard (Prompt 21)

## 1. Kiến trúc MVC
- **Model (Read-only)**: [AdminDashboardModel.php](file:///e:/xampp/htdocs/tl-gaming/app/Models/AdminDashboardModel.php)
  - `resolveDateRange()`: chuẩn hóa preset (`today`, `7d`, `30d`, `month`, `year`, `custom`) thành `[start_at, end_at)`.
  - `getKpiSummary()`, `getRevenueSeries()`, `getOrderStatusDistribution()`, `getTopSellingProducts()`, `getRecentOrders()`, `getOperationalAlerts()`.
- **Controller**: [DashboardController.php](file:///e:/xampp/htdocs/tl-gaming/app/Controllers/Admin/DashboardController.php) (bảo vệ bởi `AdminMiddleware`).
- **View**: [dashboard.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/dashboard.php) dùng chung [admin.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/layouts/admin.php).
- **Frontend thuần**: CSS trong [admin.css](file:///e:/xampp/htdocs/tl-gaming/public/assets/css/admin.css) và biểu đồ Canvas JS thuần (không dùng CDN/thư viện ngoài) trong [admin.js](file:///e:/xampp/htdocs/tl-gaming/public/assets/js/admin.js).

## 2. Biểu đồ doanh thu & Responsive
- Dữ liệu chuỗi thời gian được tổng hợp phía MySQL (`GROUP BY DATE_FORMAT(...)`) và điền đủ các mốc doanh thu `0đ` ở PHP trước khi xuất JSON gọn (chỉ gồm `label`, `revenue`, `orders`, không có dữ liệu khách hàng).
- JavaScript thuần vẽ biểu đồ cột trên `<canvas>` hỗ trợ `devicePixelRatio`, tự vẽ lại khi đổi kích thước cửa sổ (`resize`) và hiển thị tooltip chi tiết khi rê chuột.
- CSS Grid tự co giãn từ 4 cột KPI (desktop) -> 2 cột (`<= 1180px`) -> 1 cột (`<= 640px`).

## 3. Bảo mật & Tối ưu
- Toàn bộ truy vấn dùng PDO Prepared Statements và chỉ đọc (`SELECT`), không sửa dữ liệu từ Dashboard.
- Không hiển thị email/SĐT đầy đủ của khách trên bảng đơn gần đây ở Dashboard.
- Đã hoàn thiện bản sửa nhỏ từ Prompt 20.1 trong [CouponController.php](file:///e:/xampp/htdocs/tl-gaming/app/Controllers/Admin/CouponController.php): request POST tạo/sửa voucher thiếu `is_active` bị từ chối rõ ràng và không tự tắt voucher đang bật.

## 4. Hướng dẫn kiểm tra trên XAMPP
1. Khởi động Apache và MySQL/MariaDB trên XAMPP.
2. Đăng nhập tài khoản Admin tại `/dang-nhap`, mở `/admin`.
3. Kiểm tra các nút lọc nhanh (`Hôm nay`, `7 ngày`, `30 ngày`, `Tháng này`, `Năm nay`) và khoảng ngày tùy chọn.
4. Thử hoàn tất hoặc hủy một đơn hàng tại `/admin/don-hang`, sau đó quay lại `/admin` bấm **Làm mới thống kê** để đối chiếu số liệu thay đổi tức thì theo dữ liệu thật.
