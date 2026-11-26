# TL GAMING — WEBSITE BÁN MÁY TÍNH & LINH KIỆN CŨ KIỂM ĐỊNH 18 BƯỚC

Đồ án tốt nghiệp xây dựng hệ thống thương mại điện tử chuyên kinh doanh **PC Gaming cũ, Laptop Gaming cũ và Linh kiện máy tính cũ** quản lý minh bạch theo từng số **Serial vật lý (`product_units`)** và **Biên bản kiểm định kỹ thuật 18 bước (`product_inspections` / `inspection_items`)**.

---

## 1. Công nghệ sử dụng (Bắt buộc)

- **Backend:** PHP 8.2+ thuần (không sử dụng Laravel, CodeIgniter hay bất kỳ PHP framework/ORM nào).
- **Cơ sở dữ liệu:** MySQL 8.0 / MariaDB (kết nối qua `PDO` với Native Prepared Statements, bộ mã `utf8mb4_unicode_ci`, `ENGINE=InnoDB`).
- **Kiến trúc:** Mô hình **MVC (Model – View – Controller)** tự xây dựng từ đầu.
- **Frontend:** HTML5, CSS3 thuần, JavaScript thuần (không sử dụng React, Vue, Bootstrap hay Tailwind).
- **Môi trường phát triển:** XAMPP trên Windows, Visual Studio Code, phpMyAdmin.

---

## 2. Cấu trúc thư mục dự án

```text
tl-gaming/
├── app/
│   ├── Controllers/          # Các lớp Controller xử lý yêu cầu người dùng
│   │   ├── Admin/            # Controller phân hệ Quản trị viên
│   │   │   └── DashboardController.php
│   │   ├── AuthController.php
│   │   ├── CartController.php
│   │   ├── HomeController.php
│   │   └── ProductController.php
│   ├── Core/                 # Lõi kiến trúc MVC tự xây dựng
│   │   ├── Auth.php          # Quản lý Session an toàn & kiểm tra phân quyền
│   │   ├── Controller.php    # Lớp Controller cơ sở
│   │   ├── Csrf.php          # Sinh và xác minh CSRF Token chống giả mạo
│   │   ├── Database.php      # Quản lý kết nối PDO MySQL (Singleton)
│   │   ├── ErrorHandler.php  # Xử lý lỗi theo môi trường development/production
│   │   ├── Model.php         # Lớp Model cơ sở dùng PDO Prepared Statements
│   │   ├── Router.php        # Định tuyến GET/POST, tham số URL & xử lý 404
│   │   └── View.php          # Kết xuất Layout, View & chống XSS (View::e)
│   ├── Middleware/           # Các lớp kiểm soát truy cập trước khi vào Controller
│   │   ├── AdminMiddleware.php
│   │   └── CsrfMiddleware.php
│   ├── Models/               # Các lớp Model truy vấn dữ liệu từ MySQL
│   │   ├── CategoryModel.php
│   │   └── ProductModel.php
│   └── Views/                # Các tệp giao diện HTML/PHP
│       ├── admin/
│       ├── auth/
│       ├── cart/
│       ├── errors/           # Giao diện lỗi 403, 404, 500
│       ├── home/
│       ├── layouts/          # Layout dùng chung (Header, Navigation, Footer)
│       └── products/
├── config/                   # Cấu hình hệ thống & CSDL (chặn truy cập HTTP)
│   ├── app.php
│   ├── check_db.php          # Lệnh CLI kiểm tra kết nối MySQL
│   ├── database.php
│   └── env.php
├── database/                 # Schema SQL và dữ liệu mẫu (15 bảng chuẩn hóa)
│   ├── tl_gaming.sql
│   └── seed_tl_gaming.sql
├── docs/                     # Tài liệu thiết kế CSDL, ERD, Kiến trúc MVC & Báo cáo test
│   ├── database/
│   ├── diagrams/
│   └── MVC_ARCHITECTURE.md
├── public/                   # Thư mục công khai duy nhất (DocumentRoot)
│   ├── assets/
│   │   ├── css/style.css
│   │   ├── js/app.js
│   │   └── images/
│   ├── uploads/
│   ├── .htaccess
│   └── index.php             # Front Controller duy nhất của hệ thống
├── routes/
│   └── web.php               # Khai báo các tuyến đường URL của ứng dụng
├── .env.example              # File cấu hình môi trường mẫu
└── README.md
```

---

## 3. Hướng dẫn cài đặt và chạy trên XAMPP Windows

### Bước 1: Thiết lập Cơ sở dữ liệu MySQL
1. Mở **XAMPP Control Panel**, khởi động dịch vụ **Apache** và **MySQL**.
2. Nếu chưa nạp cơ sở dữ liệu `tl_gaming`, mở **phpMyAdmin** (`http://localhost/phpmyadmin`) hoặc dùng dòng lệnh để nạp lần lượt:
   - `database/tl_gaming.sql` (Tạo 15 bảng chuẩn hóa InnoDB và 2 Triggers).
   - `database/seed_tl_gaming.sql` (Nạp dữ liệu mẫu phục vụ phát triển và demo).

### Bước 2: Thiết lập file cấu hình `.env`
1. Sao chép file `.env.example` thành `.env` tại thư mục gốc `e:\xampp\htdocs\tl-gaming\.env`:
   ```powershell
   Copy-Item .env.example .env
   ```
2. Kiểm tra thông số kết nối MySQL trong `.env`:
   ```ini
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=tl_gaming
   DB_USERNAME=root
   DB_PASSWORD=
   ```
3. Kiểm tra kết nối MySQL bằng lệnh CLI:
   ```powershell
   E:\xampp\php\php.exe config/check_db.php
   ```

### Bước 3: Cấu hình `DocumentRoot` trỏ vào `public/` trên XAMPP
Để bảo mật tuyệt đối (không cho trình duyệt truy cập vào `app/`, `config/`, `database/`, `docs/`), cấu hình VirtualHost trong file `E:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName tlgaming.local
    DocumentRoot "E:/xampp/htdocs/tl-gaming/public"
    <Directory "E:/xampp/htdocs/tl-gaming/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
Thêm dòng `127.0.0.1 tlgaming.local` vào file `C:\Windows\System32\drivers\etc\hosts` và khởi động lại Apache.

*(Ngoài ra, dự án cũng đã cấu hình sẵn `.htaccess` ở thư mục gốc và `Router` tự động nhận diện đường dẫn để hỗ trợ truy cập qua `http://localhost/tl-gaming/public` hoặc chạy bằng PHP Built-in Server: `E:\xampp\php\php.exe -S 127.0.0.1:8080 -t public`).*

---

## 4. Danh sách các URL thử nghiệm (Bộ khung MVC)

| Phương thức | Đường dẫn URL | Controller & Action | Mô tả & Kết quả kỳ vọng |
| :---: | :--- | :--- | :--- |
| `GET` | `/` | `HomeController@index` | Trang chủ tạm thời, hiển thị trạng thái kết nối CSDL, danh mục `is_active = 1` và sản phẩm `published`. (`HTTP 200`) |
| `GET` | `/san-pham` | `ProductController@index` | Trang danh sách sản phẩm tạm thời (chỉ lấy sản phẩm `status = 'published'`). (`HTTP 200`) |
| `GET` | `/gio-hang` | `CartController@index` | Trang giỏ hàng tạm thời. (`HTTP 200`) |
| `GET` | `/dang-nhap` | `AuthController@showLogin` | Trang đăng nhập tạm thời có tích hợp sẵn `Csrf::field()`. (`HTTP 200`) |
| `GET` | `/admin` | `Admin\DashboardController@index` | Trang quản trị tạm thời được bảo vệ bởi `AdminMiddleware`. Khách chưa đăng nhập hoặc không phải Admin nhận mã **`HTTP 403 Forbidden`**. |
| `GET` | `/duong-dan-sai` | `Router@handleNotFound` | Mọi URL không tồn tại trả về trang lỗi **`HTTP 404 Not Found`**. |

---

## 5. Luồng xử lý Request trong kiến trúc MVC

```text
Trình duyệt (HTTP Request)
   │
   ▼
public/index.php (Front Controller)
   ├─ Nạp Autoloader PSR-4 (App\ -> app/)
   ├─ Nạp cấu hình config/app.php & .env
   ├─ Kích hoạt ErrorHandler & Auth::startSession() + Csrf::token()
   │
   ▼
App\Core\Router (Đối chiếu routes/web.php)
   ├─ Không khớp route nào ──► Trả HTTP 404 (app/Views/errors/404.php)
   ├─ Khớp route ───────────► Chạy Middleware (AdminMiddleware / CsrfMiddleware)
   │                              └─ Nếu vi phạm quyền/CSRF ──► Trả HTTP 403
   ▼
App\Controllers\*Controller
   ├─ Gọi App\Models\*Model (Truy vấn MySQL qua PDO Prepared Statements)
   │
   ▼
App\Core\View::render()
   └─ Escape dữ liệu đầu ra (View::e) + Lồng vào Layout chung (app/Views/layouts/main.php)
      ──► Trả HTML an toàn về Trình duyệt
```
