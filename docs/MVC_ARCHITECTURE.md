# TÀI LIỆU KIẾN TRÚC KHUNG PHP MVC — WEBSITE BÁN MÁY TÍNH CŨ TL GAMING

**Dự án:** Xây dựng Website bán máy tính cũ TL Gaming (Đồ án tốt nghiệp)  
**Công nghệ:** PHP 8.2+ thuần, MySQL 8.0 (PDO), HTML5/CSS3/JS thuần, không sử dụng framework.

---

## 1. Tổng quan Kiến trúc MVC Tự Xây Dựng

Hệ thống TL Gaming được thiết kế theo mô hình **MVC (Model – View – Controller)** kết hợp mẫu thiết kế **Front Controller** (`public/index.php`) và lớp chắn **Middleware**:

1. **Front Controller (`public/index.php`):** Điểm tiếp nhận duy nhất cho mọi yêu cầu HTTP. Toàn bộ mã nguồn xử lý nghiệp vụ (`app/`), cấu hình (`config/`), định tuyến (`routes/`), cơ sở dữ liệu (`database/`) và tài liệu (`docs/`) đều nằm bên ngoài thư mục `public/` và được bảo vệ thêm bởi `.htaccess` (`Require all denied`).
2. **Router (`app/Core/Router.php`):** Phân tích đường dẫn URL và phương thức HTTP (`GET`, `POST`), trích xuất tham số động trên URL (như `{slug}`, `{id}`), thực thi các Middleware bảo vệ trước khi khởi tạo Controller tương ứng.
3. **Middleware (`app/Middleware/`):** Lớp kiểm tra điều kiện tiên quyết của Request (kiểm tra quyền Admin trong Session với `AdminMiddleware`, kiểm tra mã chống giả mạo biểu mẫu với `CsrfMiddleware`).
4. **Controller (`app/Controllers/`):** Tiếp nhận yêu cầu đã qua kiểm duyệt, gọi các lớp Model để lấy hoặc cập nhật dữ liệu, sau đó chọn View phù hợp để hiển thị.
5. **Model (`app/Models/` & `app/Core/Model.php`):** Tương tác trực tiếp với cơ sở dữ liệu MySQL thông qua `PDO` và Native Prepared Statements.
6. **View (`app/Views/` & `app/Core/View.php`):** Hiển thị giao diện HTML5/CSS3 thông qua bố cục dùng chung (`app/Views/layouts/main.php`) và mã hóa toàn bộ dữ liệu động bằng `View::e()` để chống XSS.

---

## 2. Chi tiết Chức năng Từng Lớp trong `app/Core/` và `app/Middleware/`

### 2.1. `App\Core\Database` (`app/Core/Database.php`)
- **Mục đích:** Quản lý kết nối duy nhất (Singleton) tới MySQL trong vòng đời một Request.
- **Đặc điểm kỹ thuật:**
  - Đọc thông số từ `config/database.php` (nạp từ `.env`).
  - Thiết lập `charset=utf8mb4` và `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`.
  - Bật `PDO::ERRMODE_EXCEPTION` để mọi lỗi SQL đều được bắt và xử lý rõ ràng.
  - Đặt `PDO::ATTR_EMULATE_PREPARES => false` để sử dụng Prepared Statement gốc của MySQL Server.
  - Che giấu thông tin nhạy cảm (mật khẩu, tài khoản DB) nếu xảy ra lỗi kết nối.

### 2.2. `App\Core\Model` (`app/Core/Model.php`)
- **Mục đích:** Lớp cha trừu tượng cho tất cả các Model (`CategoryModel`, `ProductModel`...).
- **Phương thức chính:**
  - `query(string $sql, array $params = [])`: Chuẩn bị và thực thi truy vấn có tham số.
  - `fetchAll(string $sql, array $params = [])`: Lấy danh sách nhiều bản ghi.
  - `fetchOne(string $sql, array $params = [])`: Lấy một bản ghi duy nhất hoặc `null`.
  - `fetchColumn(string $sql, array $params = [])`: Lấy giá trị cột đơn lẻ (`COUNT`, `SUM`...).

### 2.3. `App\Core\View` (`app/Core/View.php`)
- **Mục đích:** Kết xuất giao diện, lồng ghép Layout chung và cung cấp các hàm tiện ích an toàn.
- **Phương thức chính:**
  - `View::render(string $viewPath, array $data = [], ?string $layout = 'main')`: Nạp View con bằng Output Buffering (`ob_start` / `ob_get_clean`) và đặt vào biến `$content` của tệp Layout.
  - `View::e($value)`: Gọi `htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` để chống tấn công XSS.
  - `View::formatVnd($amountVnd)`: Định dạng số nguyên `BIGINT UNSIGNED` thành chuỗi tiền tệ Việt Nam (`14.900.000 ₫`).
  - `View::url(string $path)`: Tạo đường dẫn nội bộ tương thích cả VirtualHost lẫn thư mục con trên XAMPP.

### 2.4. `App\Core\Controller` (`app/Core/Controller.php`)
- **Mục đích:** Lớp cơ sở cho mọi Controller (`HomeController`, `ProductController`, `CartController`, `AuthController`, `Admin\DashboardController`).
- **Phương thức chính:**
  - `render(...)`: Gọi `View::render(...)`.
  - `redirect(string $path, int $statusCode = 302)`: Chuyển hướng HTTP an toàn.
  - `json(array $payload, int $statusCode = 200)`: Trả phản hồi JSON chuẩn UTF-8.

### 2.5. `App\Core\Router` (`app/Core/Router.php`)
- **Mục đích:** Định tuyến yêu cầu HTTP tới đúng Controller hoặc trả về trang lỗi 404.
- **Phương thức chính:**
  - `get($path, $handler, $middlewares = [])` & `post($path, $handler, $middlewares = [])`.
  - `dispatch($requestMethod, $requestUri)`: Chuẩn hóa URI, khớp biểu thức chính quy, kích hoạt Middleware và gọi Action của Controller. Nếu không khớp route nào, trả về mã `HTTP 404` kèm giao diện `app/Views/errors/404.php`.

### 2.6. `App\Core\Auth` (`app/Core/Auth.php`)
- **Mục đích:** Khởi tạo Session an toàn và kiểm tra trạng thái đăng nhập / vai trò người dùng.
- **Nguyên tắc bảo mật:**
  - Bật `session.use_strict_mode = 1`, `session.cookie_httponly = 1`, `SameSite = Lax`.
  - Chỉ đọc thông tin định danh từ `$_SESSION['auth_user']`. Tuyệt đối không bao giờ đọc `user_id` hay `role` từ `$_GET`, `$_POST`, `$_COOKIE` hay `$_REQUEST`.

### 2.7. `App\Core\Csrf` (`app/Core/Csrf.php`)
- **Mục đích:** Bảo vệ các biểu mẫu `POST` khỏi tấn công Cross-Site Request Forgery.
- **Cơ chế:** Sinh token 64 ký tự hex bằng `random_bytes(32)`, nhúng vào biểu mẫu qua `Csrf::field()` và đối chiếu bằng hàm `hash_equals()` trong `Csrf::verify()`.

### 2.8. `App\Core\ErrorHandler` (`app/Core/ErrorHandler.php`)
- **Mục đích:** Xử lý lỗi và ngoại lệ tập trung.
- **Cơ chế:** Khi `APP_DEBUG=true` (môi trường phát triển), hiển thị thông điệp lỗi kỹ thuật để lập trình viên kiểm tra; khi `APP_DEBUG=false` (môi trường vận hành), ghi vào `error_log` và hiển thị trang thông báo lỗi 500 thân thiện, không làm lộ cấu trúc tệp hay câu lệnh SQL.

### 2.9. `App\Middleware\AdminMiddleware` & `App\Middleware\CsrfMiddleware`
- **`AdminMiddleware`:** Chặn khách vãng lai (`Guest`) và tài khoản `customer` truy cập vào `/admin`, trả về mã `HTTP 403 Forbidden` và hiển thị giao diện `errors/403.php`.
- **`CsrfMiddleware`:** Tự động kiểm tra tính hợp lệ của CSRF Token trên các yêu cầu `POST`.

---

## 3. Các Nguyên Tắc Nghiệp Vụ Cốt Lõi Cho Giai Đoạn Phát Triển Tiếp Theo

1. **Chỉ mở bán sản phẩm `published`:** Mọi truy vấn hiển thị danh sách sản phẩm công khai và thêm vào giỏ hàng bắt buộc lọc `products.status = 'published'`.
2. **Tính toán tiền tệ 100% trên Server:** Đơn giá sản phẩm (`products.price`), phí vận chuyển (`shipping_fee`), giảm giá voucher (`discount_amount`) và tổng thanh toán (`final_amount`) phải được tính trên Server từ MySQL trong Transaction.
3. **Bảo mật trạng thái thanh toán:** Khi tạo đơn hàng mới, `payments.status` luôn khởi tạo là `'pending'`. Chỉ Admin được xác thực quyền mới được duyệt chuyển sang `'completed'`.
4. **Chống bán trùng máy cũ (Concurrency):** Mọi thao tác đặt mua máy có serial (`has_serials = 1`) hoặc dùng voucher có giới hạn đều phải khóa bản ghi bằng `SELECT ... FOR UPDATE` bên trong Transaction.
