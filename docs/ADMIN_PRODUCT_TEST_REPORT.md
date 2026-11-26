# Báo cáo Kiểm thử Phân hệ Quản lý Sản phẩm Admin (Prompt 17)

- **Ngày kiểm thử**: `2026-11-14`
- **Môi trường**: PHP `8.5.12`, MariaDB `10.11.16` (XAMPP Windows)
- **CSDL kiểm thử cô lập**: `tl_gaming_test` (chạy tuần tự từng bộ test với `--confirm-test-db`)

## 1. Kiểm tra cú pháp PHP (`php -l`)
| File kiểm tra | Kết quả |
| :--- | :--- |
| `app/Models/ProductModel.php` | **PASS** (No syntax errors detected) |
| `app/Models/OrderModel.php` | **PASS** (No syntax errors detected) |
| `app/Models/AdminProductModel.php` | **PASS** (No syntax errors detected) |
| `app/Controllers/Admin/ProductController.php` | **PASS** (No syntax errors detected) |
| `app/Views/admin/products/index.php` | **PASS** (No syntax errors detected) |
| `app/Views/admin/products/form.php` | **PASS** (No syntax errors detected) |
| `routes/web.php` | **PASS** (No syntax errors detected) |
| `scripts/run_admin_product_tests.php` | **PASS** (No syntax errors detected) |

## 2. Bộ test Quản lý Sản phẩm Admin (`scripts/run_admin_product_tests.php`)
Tổng kết: **PASS=31 | FAIL=0 (Tổng 31)**

| Mã | Kịch bản kiểm thử | Kết quả | Bằng chứng |
| :--- | :--- | :--- | :--- |
| `TC01` | Guest truy cập `/admin/san-pham` bị chặn HTTP 403 | **PASS** | `HTTP 403` |
| `TC02` | Customer truy cập `/admin/san-pham` bị chặn HTTP 403 | **PASS** | `HTTP 403` |
| `TC03` | Admin truy cập danh sách sản phẩm (`/admin/san-pham`) thành công | **PASS** | `HTTP 200` |
| `TC04` | Tìm kiếm, sắp xếp whitelist & phân trang sản phẩm hoạt động | **PASS** | `searchHTTP=200` |
| `TC05` | Thêm sản phẩm hợp lệ kèm 2 ảnh & thông số kỹ thuật thành công | **PASS** | `id=15, imgs=2, specs=1` |
| `TC06` | Từ chối thêm sản phẩm trùng SKU | **PASS** | Chặn trùng SKU |
| `TC07` | Từ chối thêm sản phẩm trùng Slug | **PASS** | Chặn trùng Slug |
| `TC08` | Từ chối giá bán âm | **PASS** | Chặn giá âm |
| `TC09` | Từ chối số lượng tồn kho âm | **PASS** | Chặn tồn kho âm |
| `TC10` | Từ chối danh mục không tồn tại | **PASS** | Chặn danh mục 99999 |
| `TC11` | Chặn publish sản phẩm vào danh mục đang ẩn | **PASS** | Chặn danh mục ẩn |
| `TC12` | Sửa sản phẩm, cập nhật thông số kỹ thuật & điều chỉnh tồn kho nguyên tử (`5+3=8`) | **PASS** | `stock=8, specs=2` |
| `TC13` | Ẩn sản phẩm (`archived`) khiến trang chi tiết công khai không còn hiển thị | **PASS** | `HTTP 404` |
| `TC14` | Hiện lại sản phẩm (`published`) hiển thị lại bình thường trên trang khách | **PASS** | `HTTP 200` |
| `TC15` | Từ chối tệp PHP giả MIME khi upload ảnh | **PASS** | `imgs=2` |
| `TC16` | Upload thêm ảnh hợp lệ qua route riêng thành công | **PASS** | `imgs=3` |
| `TC17` | Chặn xóa ảnh sản phẩm khác (IDOR) & xóa ảnh đúng sản phẩm dọn cả DB và file | **PASS** | `otherKept=yes, ownDeleted=yes` |
| `TC18` | Hàng `has_serials = 1` không nhận tồn kho thủ công & giữ nguyên trạng thái `product_units` | **PASS** | `stock_col=1` |
| `TC19` | Không cho đổi `has_serials` khi sản phẩm đã có `product_units` / `order_items` | **PASS** | Chặn đổi loại kho |
| `TC20` | Sửa sản phẩm đã có đơn hàng không làm thay đổi dữ liệu lịch sử `order_items` | **PASS** | Snapshot `order_items` giữ nguyên |
| `TC21` | Khi danh mục gốc (Cấp 1) ẩn, sản phẩm Cấp 3 bị ẩn đồng bộ khỏi `ProductModel` & trang khách | **PASS** | Sản phẩm ẩn đồng bộ |
| `TC22` | `POST /admin/san-pham/them` sai CSRF bị chặn HTTP 403 | **PASS** | `HTTP 403` |
| `TC23` | Chặn `POST` dữ liệu dạng mảng bất thường không gây cảnh báo PHP | **PASS** | `POST=302` |
| `TC24` | `stock_mode=set` với `expected_stock_quantity` khớp cập nhật thành công (`8->12`) | **PASS** | `stock=12` |
| `TC25` | `stock_mode=set` thiếu `expected_stock_quantity` bị từ chối | **PASS** | `stock=12` |
| `TC26` | `stock_mode=set` sai `expected_stock_quantity` (lỗi thời) bị từ chối | **PASS** | `stock=12` |
| `TC27` | Từ chối `expected_stock_quantity` âm/chữ và `stock_mode` ngoài whitelist | **PASS** | Bị từ chối |
| `TC28` | `stock_mode=adjust` giảm hợp lệ (`12-2=10`) & chặn giảm âm kho | **PASS** | `stock=10` |
| `TC29` | Chặn số lượng vượt giới hạn số nguyên PHP/DB không bị wrap-around | **PASS** | Bị từ chối |
| `TC30` | Checkout trừ kho giữa chừng (`10->7`) khiến Admin gửi `set(expected=10)` bị từ chối an toàn | **PASS** | `stock=7` |
| `TC31` | Rollback DB khi upload ảnh không để lại tệp ảnh mồ côi trên thư mục uploads | **PASS** | `orphanCount=0` |

## 3. Kiểm thử hồi quy toàn hệ thống (chạy tuần tự trên `tl_gaming_test`)
| Bộ kiểm thử | Lệnh chạy | Kết quả |
| :--- | :--- | :--- |
| Quản lý Danh mục Admin (Prompt 16) | `E:\xampp\php\php.exe scripts/run_admin_category_tests.php --confirm-test-db` | **PASS=24 \| FAIL=0 (Tổng 24)** |
| Checkout & Tạo đơn hàng (Prompt 15) | `E:\xampp\php\php.exe scripts/run_checkout_tests.php --confirm-test-db` | **PASS=37 \| FAIL=0 (Tổng 37)** |
| Giỏ hàng (Prompt 14) | `E:\xampp\php\php.exe scripts/run_cart_tests.php --confirm-test-db` | **PASS=14 \| FAIL=0 (Tổng 14)** |
| Bảo mật & Xác thực (Prompt 13) | `E:\xampp\php\php.exe scripts/run_regression_tests.php --confirm-test-db` | **PASS=12/12** |

## 4. Hạng mục chưa chạy tự động (`NOT RUN`)
- **Kiểm tra cú pháp JS qua Node CLI** và **kiểm tra hiển thị responsive mobile/desktop trên trình duyệt thật**: **NOT RUN** trong phiên này.
