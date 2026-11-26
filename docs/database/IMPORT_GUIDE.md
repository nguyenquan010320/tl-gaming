# HƯỚNG DẪN IMPORT CƠ SỞ DỮ LIỆU TL GAMING (XAMPP / PHPMYADMIN)

Tài liệu này hướng dẫn chi tiết cách import cơ sở dữ liệu `tl_gaming` vào hệ thống **XAMPP / phpMyAdmin** hoặc qua **Command Line (CMD / PowerShell)** trên hệ điều hành Windows dành cho sinh viên thực hiện đồ án tốt nghiệp.

---

## 1. THÔNG TIN FILE CƠ SỞ DỮ LIỆU
- **Đường dẫn tệp SQL:** `database/tl_gaming.sql`
- **Tên cơ sở dữ liệu:** `tl_gaming`
- **Bộ mã ký tự (Charset):** `utf8mb4`
- **Bảng mã sắp xếp (Collation):** `utf8mb4_unicode_ci`
- **Storage Engine:** `InnoDB` (bắt buộc để hỗ trợ Khóa ngoại và Giao dịch ACID)
- **Số lượng bảng:** 14 bảng chuẩn hóa theo `DATABASE_DESIGN.md`

---

## 2. PHƯƠNG PHÁP 1: IMPORT QUA GIAO DIỆN PHPMYADMIN (KHUYÊN DÙNG)

### Bước 1: Khởi động dịch vụ trong XAMPP Control Panel
1. Mở phần mềm **XAMPP Control Panel**.
2. Nhấn nút **Start** tại 2 dịch vụ:
   - **Apache** (Cổng mặc định: 80, 443)
   - **MySQL** (Cổng mặc định: 3306)
3. Đảm bảo cả hai dịch vụ đều chuyển sang nền màu xanh lá cây.

### Bước 2: Truy cập phpMyAdmin
1. Mở trình duyệt web (Google Chrome, Microsoft Edge...).
2. Truy cập đường dẫn: `http://localhost/phpmyadmin` hoặc click nút **Admin** trên dòng MySQL tại XAMPP Control Panel.

### Bước 3: Thực hiện Import tệp `tl_gaming.sql`
> [!NOTE]
> File `database/tl_gaming.sql` đã được viết sẵn câu lệnh:
> `CREATE DATABASE IF NOT EXISTS \`tl_gaming\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
> và `USE \`tl_gaming\`;` nên bạn **không cần tạo trước database rỗng**.

1. Tại thanh menu ngang trên cùng của phpMyAdmin, nhấp chuột vào tab **Nhập (Import)**.
2. Tại mục **Tập tin để nhập (File to import)**:
   - Nhấn nút **Choose File (Chọn tệp)**.
   - Điều hướng đến thư mục dự án và chọn file:
     `E:\xampp\htdocs\tl-gaming\database\tl_gaming.sql`
3. Tại mục **Bảng mã của tập tin (Character set of the file)**: Giữ nguyên `utf-8`.
4. Cuộn xuống cuối trang và nhấn nút **Nhập (Import)** (hoặc nút **Go / Thực hiện**).
5. Chờ hệ thống thực thi trong khoảng 2 - 5 giây. Khi màn hình xuất hiện thông báo màu xanh:
   `Import has been successfully finished, XX queries executed.` là hoàn thành.

### Bước 4: Kiểm tra kết quả
1. Nhìn vào danh sách cơ sở dữ liệu ở cột bên trái, nhấp chọn `tl_gaming`.
2. Kiểm tra danh sách hiển thị đúng đủ **14 bảng**:
   `users`, `categories`, `products`, `product_images`, `product_specs`, `product_units`, `product_inspections`, `carts`, `cart_items`, `coupons`, `orders`, `order_items`, `payments`, `coupon_usages`.
3. Kiểm tra cột **Engine** đều là `InnoDB` và cột **Collation** là `utf8mb4_unicode_ci`.

---

## 3. PHƯƠNG PHÁP 2: IMPORT BẰNG DÒNG LỆNH (COMMAND PROMPT / POWERSHELL)

Phương pháp này dành cho lập trình viên muốn thao tác nhanh hoặc khi file SQL quá lớn vượt quá giới hạn upload của phpMyAdmin.

### Cách 1: Sử dụng Command Prompt (cmd.exe)
1. Mở **Command Prompt** (bấm phím `Windows + R`, gõ `cmd` rồi nhấn `Enter`).
2. Di chuyển vào thư mục cài đặt MySQL của XAMPP:
   ```cmd
   cd /d E:\xampp\mysql\bin
   ```
3. Chạy lệnh import trực tiếp (mặc định tài khoản XAMPP là `root` và không có mật khẩu):
   ```cmd
   mysql.exe -u root < "E:\xampp\htdocs\tl-gaming\database\tl_gaming.sql"
   ```
4. Kiểm tra danh sách bảng đã import:
   ```cmd
   mysql.exe -u root -e "SHOW TABLES FROM tl_gaming;"
   ```

### Cách 2: Sử dụng PowerShell
1. Mở **PowerShell** tại thư mục gốc của dự án `E:\xampp\htdocs\tl-gaming`.
2. Thực thi lệnh sau:
   ```powershell
   cmd /c "`"E:\xampp\mysql\bin\mysql.exe`" -u root < database\tl_gaming.sql"
   ```
3. Kiểm tra kết quả:
   ```powershell
   & "E:\xampp\mysql\bin\mysql.exe" -u root -e "SHOW TABLES FROM tl_gaming;"
   ```

---

## 4. CÁC LỖI THƯỜNG GẶP VÀ CÁCH XỬ LÝ (TROUBLESHOOTING)

### Lỗi 1: `MySQL server has gone away` hoặc `max_allowed_packet`
- **Nguyên nhân:** Kích thước tệp tin hoặc gói tin truy vấn lớn hơn thiết lập mặc định của MySQL trong XAMPP.
- **Cách khắc phục:**
  1. Mở tệp `E:\xampp\mysql\bin\my.ini`.
  2. Tìm dòng `max_allowed_packet` và điều chỉnh thành:
     ```ini
     max_allowed_packet = 64M
     ```
  3. Khởi động lại dịch vụ MySQL trên XAMPP.

### Lỗi 2: `Port 3306 in use by another application`
- **Nguyên nhân:** Cổng 3306 đang bị chiếm bởi một bản MySQL cài đặt độc lập khác ngoài XAMPP.
- **Cách khắc phục:**
  1. Mở `services.msc`, tìm dịch vụ `MySQL` cũ và nhấn **Stop**.
  2. Sau đó quay lại XAMPP Control Panel nhấn **Start** cho MySQL.

### Lỗi 3: `Access denied for user 'root'@'localhost'`
- **Nguyên nhân:** Tài khoản `root` của MySQL trong XAMPP đã được đặt mật khẩu.
- **Cách khắc phục:**
  Thêm tham số `-p` vào câu lệnh dòng lệnh:
  ```cmd
  mysql.exe -u root -p < "E:\xampp\htdocs\tl-gaming\database\tl_gaming.sql"
  ```
  Nhập mật khẩu của bạn khi được nhắc.

---

## 5. THÔNG TIN KẾT NỐI MẪU DÀNH CHO BƯỚC CODE PHP (PDO) TIẾP THEO
Khi cấu hình kết nối CSDL trong mô hình MVC PHP thuần:
```php
$host = '127.0.0.1';
$port = '3306';
$dbname = 'tl_gaming';
$username = 'root';
$password = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$pdo = new PDO($dsn, $username, $password, $options);
```
