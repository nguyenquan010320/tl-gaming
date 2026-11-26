# Hướng dẫn Triển khai & Nâng cấp trên XAMPP (`DEPLOYMENT_XAMPP_GUIDE.md`) — TL Gaming

## 1. Yêu cầu môi trường
- **XAMPP** với PHP 8.2+ (đã kiểm chứng trên PHP 8.5.12), Apache (`mod_rewrite` bật), MySQL 8.x hoặc MariaDB 10.x/11.x.
- **PHP Extensions bắt buộc**: `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `openssl`.
- **Quyền ghi thư mục**: `public/uploads/` và `storage/` (chứa `storage/ratelimit/` và khóa flock).

## 2. Cấu hình `.env`
Sao chép `.env.example` thành `.env` tại thư mục gốc `e:\xampp\htdocs\tl-gaming\.env` (Apache `.htaccess` đã chặn HTTP 403 đối với `.env`, `storage/`, `scripts/`, `app/`, `config/`, `database/`):
- Thiết lập `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=tl_gaming`, `DB_USERNAME=root`, `DB_PASSWORD=` (tùy máy).
- Cấu hình tùy chọn showroom (`TL_SHOWROOM_HN_ADDRESS`, `TL_SHOWROOM_HCM_ADDRESS`) và tài khoản ngân hàng (`TL_BANK_NAME`, `TL_BANK_ACCOUNT_NUMBER`, `TL_BANK_ACCOUNT_HOLDER`) nếu muốn kích hoạt thanh toán chuyển khoản / nhận tại showroom.

## 3. Phân biệt Cài mới vs Nâng cấp Database cũ
### A. Cài đặt mới hoàn toàn (Chưa có DB `tl_gaming`)
```powershell
E:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS tl_gaming CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content database/tl_gaming.sql | E:\xampp\mysql\bin\mysql.exe -u root tl_gaming
# (Tùy chọn) Nạp dữ liệu mẫu khởi tạo:
Get-Content database/seed_tl_gaming.sql | E:\xampp\mysql\bin\mysql.exe -u root tl_gaming
# Đồng bộ tồn kho cache cho sản phẩm has_serials = 1:
E:\xampp\php\php.exe scripts/sync_serial_stock_cache.php --dry-run
E:\xampp\php\php.exe scripts/sync_serial_stock_cache.php --apply
```
*(Nếu cài mới từ `database/tl_gaming.sql`, toàn bộ cột và bảng từ Prompt 15–21 đã có sẵn, KHÔNG cần chạy migration).*

### B. Nâng cấp Database `tl_gaming` cũ đang vận hành (KHÔNG DROP DB)
1. **Sao lưu bắt buộc trước khi nâng cấp**:
```powershell
E:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines --triggers tl_gaming > backup_tl_gaming_before_upgrade.sql
```
2. **Chạy tuần tự các migration an toàn (tương thích cả MySQL 8 và MariaDB, idempotent)**:
```powershell
Get-Content database/migrations/20261114_product_units_integrity_and_row_version.sql | E:\xampp\mysql\bin\mysql.exe -u root tl_gaming
Get-Content database/migrations/20261114_admin_orders_audit_and_refund_flag.sql | E:\xampp\mysql\bin\mysql.exe -u root tl_gaming
Get-Content database/migrations/20261121_orders_delivery_method.sql | E:\xampp\mysql\bin\mysql.exe -u root tl_gaming
```
3. **Đối soát tồn kho serial sau nâng cấp**:
```powershell
E:\xampp\php\php.exe scripts/sync_serial_stock_cache.php --dry-run
```

## 4. Tạo tài khoản Admin & Khôi phục dữ liệu
- **Tạo tài khoản Admin qua CLI** (không có form công khai tạo Admin):
```powershell
E:\xampp\php\php.exe scripts/create_admin.php
```
- **Khôi phục dữ liệu từ bản sao lưu khi cần**:
```powershell
Get-Content backup_tl_gaming_before_upgrade.sql | E:\xampp\mysql\bin\mysql.exe -u root tl_gaming
```
- Truy cập website tại: `http://localhost/tl-gaming/` hoặc `http://localhost/tl-gaming/public/`.
