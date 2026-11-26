# Tài liệu Thiết kế Phân hệ Quản lý Sản phẩm Admin (Prompt 17)

## 1. Tổng quan & Kiến trúc
Phân hệ **Quản lý Sản phẩm Admin** được xây dựng trên **PHP 8 thuần + MySQL/MariaDB PDO + MVC tự viết + HTML5/CSS3/JS thuần**, dùng chung Layout Admin Google Stitch ([app/Views/layouts/admin.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/layouts/admin.php)).

- **Controller**: [app/Controllers/Admin/ProductController.php](file:///e:/xampp/htdocs/tl-gaming/app/Controllers/Admin/ProductController.php)
- **Admin Model**: [app/Models/AdminProductModel.php](file:///e:/xampp/htdocs/tl-gaming/app/Models/AdminProductModel.php)
- **Public Model đồng bộ**: [app/Models/ProductModel.php](file:///e:/xampp/htdocs/tl-gaming/app/Models/ProductModel.php) & [app/Models/OrderModel.php](file:///e:/xampp/htdocs/tl-gaming/app/Models/OrderModel.php)
- **Views**: [app/Views/admin/products/index.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/products/index.php) & [app/Views/admin/products/form.php](file:///e:/xampp/htdocs/tl-gaming/app/Views/admin/products/form.php)

## 2. Routes & Phân quyền
Mọi route đều gắn `AdminMiddleware`, các thao tác thay đổi dữ liệu dùng `POST` + `CsrfMiddleware` + mô hình Post/Redirect/Get (PRG):

| Method | URI | Controller Action |
| :--- | :--- | :--- |
| `GET` | `/admin/san-pham` | `Admin\ProductController::index` |
| `GET` | `/admin/san-pham/them` | `Admin\ProductController::create` |
| `POST` | `/admin/san-pham/them` | `Admin\ProductController::store` |
| `GET` | `/admin/san-pham/{id}/sua` | `Admin\ProductController::edit` |
| `POST` | `/admin/san-pham/{id}/sua` | `Admin\ProductController::update` |
| `POST` | `/admin/san-pham/{id}/an` | `Admin\ProductController::hide` |
| `POST` | `/admin/san-pham/{id}/hien` | `Admin\ProductController::show` |
| `POST` | `/admin/san-pham/{id}/anh/them` | `Admin\ProductController::uploadImages` |
| `POST` | `/admin/san-pham/{id}/anh/{imageId}/xoa` | `Admin\ProductController::deleteImage` |

## 3. Luồng Thêm / Sửa / Ẩn / Hiện Sản phẩm
1. **Validation phía Server (`validateProductInput`)**:
   - Từ chối kiểu mảng/object bất thường bằng `extractScalarString()` (không gây PHP Warning).
   - Kiểm tra tên (2–255 ký tự), SKU duy nhất (`^[A-Z0-9][A-Z0-9\-_]{1,49}$`), Slug duy nhất, `price >= 0`, `original_price IS NULL OR original_price >= price`, `warranty_months` (0–120), `condition_overview` không rỗng, `status` thuộc `['draft', 'published', 'out_of_stock', 'archived']`.
   - Khi tạo mới hoặc cập nhật sang `published` (kể cả nút **Hiện**), hệ thống kiểm tra danh mục trực tiếp và toàn bộ chuỗi tổ tiên (tối đa 3 cấp) phải đang hoạt động (`is_active = 1`).
2. **Ẩn sản phẩm (`hide`)**:
   - Chuyển `status = 'archived'` thay vì xóa cứng, bảo toàn toàn vẹn lịch sử đơn hàng `order_items` (đã lưu snapshot `product_name`, `product_sku`, `price`, `warranty_period`) và các cá thể `product_units`.

## 4. Quản lý Hình ảnh (`public/uploads/products/`)
- Thư mục `public/uploads/products/` có tệp [.htaccess](file:///e:/xampp/htdocs/tl-gaming/public/uploads/products/.htaccess) chặn thực thi mọi tệp PHP/script và tắt liệt kê thư mục.
- Kiểm tra lỗi PHP upload, dung lượng tối đa `5 MB/ảnh`, tối đa `10 ảnh/sản phẩm`.
- Xác minh MIME thực tế bằng `finfo_open(FILEINFO_MIME_TYPE)` và `getimagesize()`, chỉ chấp nhận `image/jpeg`, `image/png` (và `image/webp` nếu môi trường hỗ trợ); từ chối tuyệt đối tệp PHP giả mạo phần mở rộng.
- Đặt tên ngẫu nhiên bằng `bin2hex(random_bytes(16))`, chống path traversal khi xóa (`basename` + `realpath` prefix check).
- Tự động dọn file mới upload nếu transaction DB lỗi; khi xóa ảnh qua `deleteProductImage()`, chỉ `unlink()` file vật lý sau khi transaction xóa DB đã commit và không còn bản ghi `product_images` / `products.thumbnail` nào khác tham chiếu. Nếu việc xóa file vật lý thất bại sau commit, hệ thống ghi log vận hành (`error_log`) và hiển thị thông báo rõ ràng để quản trị viên biết còn file rác cần dọn, không báo thành công tuyệt đối.

## 5. Quản lý Thông số Kỹ thuật (`product_specs`)
- Hỗ trợ bảng key-value động (`group_name`, `spec_name`, `spec_value`, `sort_order`), không hardcode cố định 6 trường.
- Kiểm tra độ dài, chống trùng lặp `(group_name, spec_name)` và cập nhật đồng bộ trong cùng transaction với sản phẩm.

## 6. Phân tách Tồn kho Số lượng vs Serial & Tác động tới Checkout
- **Hàng quản lý theo số lượng (`has_serials = 0`)**:
  - Quản lý theo `products.stock_quantity` (giới hạn `0..100000`, kiểm tra tràn số nguyên bằng `filter_var(..., FILTER_VALIDATE_INT)`).
  - Chế độ cập nhật `stock_mode` chỉ nhận `adjust` hoặc `set` (từ chối mọi giá trị ngoài whitelist).
  - Khi `stock_mode = adjust`: cộng/trừ `stock_adjustment` trực tiếp trên `currentStock` đã khóa bằng `SELECT ... FOR UPDATE`, chặn kết quả `< 0` hoặc vượt `MAX_STOCK_QUANTITY`.
  - Khi `stock_mode = set` ở chế độ sửa: **bắt buộc** có `expected_stock_quantity` hợp lệ để thực thi Optimistic Locking; nếu tồn kho thực tế trong DB đã thay đổi (do Admin khác hoặc Checkout đồng thời), từ chối cập nhật và yêu cầu tải lại trang. Không dùng `max(0, ...)` để âm thầm sửa dữ liệu sai.
- **Hàng quản lý theo Serial (`has_serials = 1`)**:
  - Không cho nhập `stock_quantity` thủ công; số lượng khả dụng được tính từ `product_units` có `status = 'available'`.
  - Khi sửa sản phẩm, tuyệt đối không làm thay đổi trạng thái `reserved` / `sold` của các máy trong `product_units`.
  - Chặn đổi `has_serials` khi sản phẩm đã phát sinh `product_units`, `order_items` hoặc `cart_items`.
- **Hiển thị công khai & Checkout**:
  - Toàn bộ truy vấn công khai trong [ProductModel.php](file:///e:/xampp/htdocs/tl-gaming/app/Models/ProductModel.php) và kiểm tra đặt hàng trong [OrderModel.php](file:///e:/xampp/htdocs/tl-gaming/app/Models/OrderModel.php) đều nối `c_p1`, `c_p2` để bảo đảm sản phẩm chỉ hiển thị/bán được khi chính danh mục và toàn bộ tổ tiên (tối đa 3 cấp) đều `is_active = 1`.
