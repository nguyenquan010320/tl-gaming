# Kịch bản Demo Nghiệm thu Đồ án Tốt nghiệp (`DEMO_SCENARIO.md`) — TL Gaming

Thời lượng dự kiến: **10–15 phút** trên XAMPP (`http://localhost/tl-gaming/`).

## Phần 1 — Trải nghiệm Khách hàng (4 phút)
1. **Trang chủ & Tìm kiếm (`/`, `/san-pham`)**:
   - Giới thiệu giao diện Google Stitch TL Gaming, tìm kiếm theo từ khóa (`RTX`), lọc theo danh mục 3 cấp.
2. **Chi tiết máy & Kiểm định 18 bước (`/san-pham/{slug}`)**:
   - Xem thông số kỹ thuật động, trạng thái tồn kho thực tế theo Serial đạt chuẩn kiểm định 18 bước.
3. **Giỏ hàng & Hợp nhất giỏ (`/gio-hang`, `/dang-nhap`)**:
   - Thêm sản phẩm khi chưa đăng nhập (Session), đăng ký/đăng nhập tài khoản khách hàng -> chứng minh giỏ hàng tự hợp nhất vào MySQL.
4. **Checkout, Voucher & Tra cứu đơn (`/thanh-toan`, `/tra-cuu-don-hang`)**:
   - Nhập mã voucher hợp lệ -> xem tính lại tổng tiền từ Server -> đặt đơn COD -> hiển thị mã đơn thành công -> tra cứu đơn bằng Mã đơn + SĐT.

## Phần 2 — Quy trình Vận hành Admin (6 phút)
5. **Đăng nhập Admin & Dashboard (`/admin`)**:
   - Trình bày 4 KPI chính + 4 KPI bổ sung, biểu đồ doanh thu Canvas JS thuần, lọc theo kỳ (`30 ngày`, `Năm nay`, `Tùy chọn`), phân bố trạng thái đơn và cảnh báo vận hành.
6. **Quản lý Danh mục, Sản phẩm, Serial & Kiểm định 18 bước (`/admin/danh-muc`, `/admin/san-pham`, `/admin/serial`)**:
   - Tạo nhanh 1 serial mới ở trạng thái `repairing` (chưa bán được) -> lập biên bản kiểm định đủ 18 bước `pass` + mở bán -> chứng minh tồn kho bán được tăng tức thì.
7. **Xử lý Đơn hàng, Thanh toán & Hoàn tồn kho (`/admin/don-hang`)**:
   - Mở đơn hàng vừa đặt: chứng minh trạng thái đơn và thanh toán tách biệt -> **Xác nhận đơn** -> **Xác nhận thanh toán** -> **Giao hàng** -> **Hoàn tất** (serial tự chuyển `reserved -> sold`).
   - Quay lại `/admin` bấm **Làm mới thống kê**: chứng minh doanh thu đơn hoàn tất và biểu đồ tăng khớp chính xác.
   - Demo **Hủy đơn** trước khi hoàn tất có lý do hủy -> chứng minh tồn kho/serial và lượt voucher được hoàn đúng 1 lần, ghi đầy đủ vào **Audit Log**.
8. **Quản lý Khách hàng & Voucher (`/admin/khach-hang`, `/admin/voucher`)**:
   - Xem chi tiết khách hàng (không gộp đơn vãng lai, chỉ tính chi tiêu từ đơn hoàn tất), demo khóa/mở khóa tài khoản.
   - Tạo/tắt voucher và xem trang **Lịch sử sử dụng** phân biệt rõ lượt `used` và `cancelled`.

## Phần 3 — Chứng minh Bảo mật & Kiểm thử Tự động (2 phút)
9. Mở trình duyệt ẩn danh thử truy cập `/admin` và `/.env` -> đều bị chặn **HTTP 403 Forbidden**.
10. Chạy nhanh 1 lệnh kiểm thử tích hợp trên `tl_gaming_test` (nếu hội đồng yêu cầu):
```powershell
E:\xampp\php\php.exe scripts/run_final_e2e_integration_tests.php --confirm-test-db
```
