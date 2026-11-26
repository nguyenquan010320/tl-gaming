# Tài liệu Thiết kế Admin Layout & Quản lý Danh mục (Prompt 16)

## 1. Tổng quan kiến trúc
Phân hệ Quản trị TL Gaming được xây dựng trên kiến trúc **PHP 8 thuần + MySQL/MariaDB PDO + MVC tự viết + HTML5/CSS3/JS thuần** (không framework, không ORM).

- **Layout dùng chung (`admin`)**: [app/Views/layouts/admin.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/layouts/admin.php)
  - Sidebar tối (`#0f172a` / `#141c2b` theo thiết kế Google Stitch), logo `TL GAMING`, menu điều hướng phân hệ:
    - `Tổng quan` (`/admin`) — Hoạt động
    - `Quản lý danh mục` (`/admin/danh-muc`) — Hoạt động
    - `Quản lý sản phẩm`, `Quản lý đơn hàng`, `Quản lý khách hàng`, `Thống kê doanh thu`, `Cài đặt hệ thống` — gắn nhãn trung thực `Sắp triển khai`, chưa gắn link giả lập.
  - Khối tài khoản Admin đang đăng nhập + nút **Đăng xuất** sử dụng form `POST /dang-xuat` kèm CSRF token.
  - Khu vực nội dung nền sáng (`#f1f5f9`), Header + Breadcrumb động, thông báo một lần qua `Flash::pull()` (tự escape qua `View::e()`).
  - Responsive drawer cho màn hình `< 991px` điều khiển bởi [public/assets/js/admin.js](file:///e:/xampp/htdocs/tl-gaming/public/assets/js/admin.js) và [public/assets/css/admin.css](file:///e:/xampp/htdocs/tl-gaming/public/assets/css/admin.css).

## 2. Danh sách Routes & Phân quyền
Tất cả route quản trị đều đi qua `AdminMiddleware` (kiểm tra `Auth::isAdmin() && Auth::revalidate() && Auth::isAdmin()`, trả HTTP 403 nếu không phải Admin). Mọi thao tác ghi đều dùng `POST` + `CsrfMiddleware` + mô hình Post/Redirect/Get (PRG):

| Method | URI | Middleware | Controller Action |
| :--- | :--- | :--- | :--- |
| `GET` | `/admin` | `AdminMiddleware` | `Admin\DashboardController::index` |
| `GET` | `/admin/danh-muc` | `AdminMiddleware` | `Admin\CategoryController::index` |
| `GET` | `/admin/danh-muc/them` | `AdminMiddleware` | `Admin\CategoryController::create` |
| `POST` | `/admin/danh-muc/them` | `AdminMiddleware`, `CsrfMiddleware` | `Admin\CategoryController::store` |
| `GET` | `/admin/danh-muc/{id}/sua` | `AdminMiddleware` | `Admin\CategoryController::edit` |
| `POST` | `/admin/danh-muc/{id}/sua` | `AdminMiddleware`, `CsrfMiddleware` | `Admin\CategoryController::update` |
| `POST` | `/admin/danh-muc/{id}/an` | `AdminMiddleware`, `CsrfMiddleware` | `Admin\CategoryController::hide` |
| `POST` | `/admin/danh-muc/{id}/hien` | `AdminMiddleware`, `CsrfMiddleware` | `Admin\CategoryController::show` |

## 3. Nghiệp vụ & Ràng buộc dữ liệu (`CategoryModel`)
1. **Validation phía Server**:
   - `name`: bắt buộc, độ dài 2–100 ký tự.
   - `slug`: tự sinh từ `name` nếu để trống (`CategoryModel::toSlug()`), bắt buộc khớp định dạng slug hợp lệ và duy nhất trong bảng `categories` (`uq_categories_slug`).
   - Whitelist field: chỉ nhận `name`, `slug`, `parent_id`, `description`, `display_order`, `is_active`.
2. **Phân cấp & Chống chu trình (`validateParentHierarchy`)**:
   - Không cho danh mục tự làm cha của chính nó (`parent_id == id`).
   - Không cho chọn hậu duệ (con/cháu) làm cha (chống tạo chu trình).
   - Giới hạn độ sâu cây tối đa `CategoryModel::MAX_DEPTH = 3`.
3. **Ẩn/Hiện đồng bộ cây danh mục (`setCategoryStatus`)**:
   - Khi **ẩn** một danh mục cha (`is_active = 0`), hệ thống thực hiện trong transaction để ẩn toàn bộ danh mục con/cháu thuộc nhánh đó, tránh rò rỉ sản phẩm thuộc nhánh bị ẩn ra phía khách hàng.
   - Khi **hiện** một danh mục con (`is_active = 1`), hệ thống kiểm tra toàn bộ tổ tiên; nếu có bất kỳ danh mục cha/ông nào đang ẩn thì chặn và thông báo yêu cầu hiện danh mục cha trước.
   - Truy vấn phía khách hàng (`getActiveCategories`, `findActiveBySlug`, `getCategoryFamilyIds`, `buildHeaderMenuItems`) đồng bộ kiểm tra `is_active = 1`.
4. **Bảo vệ toàn vẹn xóa (`deleteCategory`)**:
   - Không cho xóa cứng danh mục khi đang có sản phẩm trực thuộc hoặc có danh mục con; ưu tiên nghiệp vụ ẩn danh mục.
5. **Bảo mật**:
   - Escape toàn bộ dữ liệu hiển thị HTML bằng `View::e()`.
   - Toàn bộ truy vấn SQL dùng PDO Prepared Statements; lọc/sắp xếp dùng whitelist cố định (không nhận `ORDER BY` từ người dùng).
   - Không lộ thông báo lỗi SQL thô ra giao diện.
