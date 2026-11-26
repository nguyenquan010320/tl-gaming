# Báo cáo Kiểm thử Admin Layout & Quản lý Danh mục (Prompt 16 & Hoàn thiện)

- **Ngày kiểm thử**: `2026-11-14`
- **Môi trường**: PHP `8.5.12`, MariaDB `10.11.16` (XAMPP Windows)
- **CSDL kiểm thử cô lập**: `tl_gaming_test` (chạy tuần tự từng bộ test với `--confirm-test-db`)

## 1. Kiểm thử cú pháp PHP (`php -l`)
| File kiểm tra | Kết quả |
| :--- | :--- |
| `app/Models/CategoryModel.php` | **PASS** (No syntax errors detected) |
| `app/Controllers/Admin/CategoryController.php` | **PASS** (No syntax errors detected) |
| `scripts/run_admin_category_tests.php` | **PASS** (No syntax errors detected) |

## 2. Bộ test Quản lý Danh mục Admin (`scripts/run_admin_category_tests.php`)
Tổng kết: **PASS=24 | FAIL=0 (Tổng 24)**

| Mã | Kịch bản kiểm thử | Kết quả | Bằng chứng |
| :--- | :--- | :--- | :--- |
| `TC01` | Admin xem danh sách danh mục (`/admin/danh-muc`) với Admin Layout | **PASS** | `HTTP 200` |
| `TC02` | Guest truy cập `/admin/danh-muc` bị chặn HTTP 403 | **PASS** | `HTTP 403` |
| `TC03` | Customer truy cập `/admin/danh-muc` bị chặn HTTP 403 | **PASS** | `HTTP 403` |
| `TC04` | Thêm danh mục hợp lệ thành công (PRG) | **PASS** | `newId=7` |
| `TC05` | Thêm danh mục trùng slug bị từ chối | **PASS** | `count=7->7` |
| `TC06` | Sửa tên, slug, mô tả danh mục thành công | **PASS** | `slug=ban-phim-gaming-cu` |
| `TC07` | Ẩn danh mục cha tự động ẩn danh mục con & chặn hiện con khi cha đang ẩn | **PASS** | `parent=0, child=0->0` |
| `TC08` | Hiện lại danh mục cha và danh mục con thành công | **PASS** | `parent=1, child=1` |
| `TC09` | Tạo danh mục con trực thuộc danh mục cha | **PASS** | `childId=8, parentId=7` |
| `TC10` | Không cho danh mục tự làm cha của chính nó | **PASS** | `parent_id=0` |
| `TC11` | Không cho tạo chu trình cha/con (gán con làm cha tổ tiên) | **PASS** | `parent_id=0` |
| `TC12` | Không cho xóa cứng danh mục đang có sản phẩm | **PASS** | `hardDeleteBlocked=yes` |
| `TC13` | `POST /admin/danh-muc/them` sai CSRF bị chặn HTTP 403 | **PASS** | `HTTP 403` |
| `TC14` | Input không hợp lệ (tên rỗng / slug sai) bị từ chối | **PASS** | `validationErrorShown=yes` |
| `TC15` | Chống XSS (HTML escape) và SQL Injection (Prepared Statements) | **PASS** | `xssEscaped=yes, sqliSafe=yes` |
| `TC16` | Danh mục ẩn không xuất hiện trong danh mục hoạt động & sản phẩm công khai | **PASS** | `hiddenNotActive=yes` |
| `TC17` | Trang chủ và bộ lọc sản phẩm vẫn hoạt động bình thường | **PASS** | `homeHTTP=200, shopHTTP=200` |
| `TC18` | Lọc danh mục 3 cấp (Cấp 1, Cấp 2, Cấp 3) trả đúng sản phẩm `published` | **PASS** | `fam1=10,11,12` |
| `TC19` | Đếm `published_product_count` chính xác ở cả 3 cấp (`3/2/1`, không đếm `draft`) | **PASS** | `counts=3/2/1` |
| `TC20` | Ẩn nhánh con (Cấp 2) tự động loại trừ khỏi đếm và lọc sản phẩm công khai của Cấp 1 | **PASS** | `cnt1AfterHide=1` |
| `TC21` | Chặn dữ liệu `POST` dạng mảng bất thường không gây cảnh báo PHP | **PASS** | `POST=302, GET=200` |
| `TC22` | Chặn `display_order` chuỗi bất thường và bảo toàn dữ liệu form khi nhập sai | **PASS** | `POST=302, GET=200` |
| `TC23` | Chặn ID danh mục không hợp lệ trên URL (`12abc`) | **PASS** | `HTTP 302` |
| `TC24` | Trang chủ và tìm kiếm sản phẩm (`/san-pham?q=RTX`) hoạt động bình thường | **PASS** | `searchHTTP=200` |

## 3. Kiểm thử hồi quy toàn hệ thống (chạy tuần tự trên `tl_gaming_test`)
| Bộ kiểm thử | Lệnh chạy | Kết quả |
| :--- | :--- | :--- |
| Bảo mật & Xác thực (Prompt 13 & 14) | `E:\xampp\php\php.exe scripts/run_regression_tests.php --confirm-test-db` | **PASS=12/12** |
| Giỏ hàng (Prompt 14) | `E:\xampp\php\php.exe scripts/run_cart_tests.php --confirm-test-db` | **PASS=14 \| FAIL=0 (Tổng 14)** |
| Checkout & Tạo đơn hàng (Prompt 15) | `E:\xampp\php\php.exe scripts/run_checkout_tests.php --confirm-test-db` | **PASS=37 \| FAIL=0 (Tổng 37)** |

## 4. Hạng mục chưa chạy tự động (`NOT RUN`)
- **Kiểm tra hiển thị responsive trên trình duyệt thật** (Desktop 1440px & Mobile < 991px drawer toggle): **NOT RUN** trong phiên này.
