# BÁO CÁO AUDIT TOÀN BỘ GIAO DIỆN UI/UX TRƯỚC KHI HOÀN THIỆN (PROMPT 24)

- **Dự án:** Website kinh doanh máy tính & linh kiện cũ **TL Gaming**
- **Ngày Audit:** 25/11/2026
- **Công cụ Audit:** Đọc trực tiếp toàn bộ `routes/web.php`, Layouts (`main.php`, `admin.php`), Views (Home, Products, Product Detail, Auth, Cart, Checkout, Admin), `public/assets/css/style.css` (7,260+ dòng), `public/assets/css/admin.css`, `public/assets/js/app.js`, `public/assets/js/admin.js`, kết hợp chụp và đo đạc thực tế trên Google Chrome CDP (`tl_gaming_test`).
- **Chỉ số đo trước khi sửa:** Chiều dài Trang chủ Mobile `375×812` lên tới **`8,862px`** (~10.9 lần chiều cao màn hình điện thoại).

---

## 1. Phân tích kỹ thuật CSS / JS tích tụ từ Prompt 22 → 23.2

1. **Chồng chất CSS Override ở cuối `style.css`:** Các Prompt 23, 23.1, 23.2 nối tiếp nhau thêm 3 khối `@media (max-width: 768px)` và nhiều khai báo `!important` thay vì chuẩn hóa component gốc, gây khó bảo trì và lệch màu nhỏ giữa các phần tử (`#1d4ed8`, `#0051d5`, `#ba1a1a` xen lẫn `#2454D9`, `#DC2626`).
2. **4 kiểu Card sản phẩm khác nhau trên cùng Website:**
   - Trang chủ dùng riêng 4 kiểu: `$renderFlashSaleCard` (có thanh tồn kho đỏ), `$renderPcProductCard` (hộp thông số 2×2 + 2 nút), `.laptop-horizontal-card` (card ngang), `.component-clean-card` (chỉ có chữ, **không có ảnh sản phẩm**).
   - Trang `/san-pham` dùng 1 kiểu card thứ 5.
   - Khu vực *Cấu hình tương tự* ở `/san-pham/{slug}` dùng 1 kiểu card thứ 6.
   - Hậu quả: chiều cao card lệch nhau, nút mua hàng không thẳng hàng, trải nghiệm Mobile rời rạc.
3. **Trang chủ Mobile quá dài (`8,862px`):**
   - Khối Quy trình kiểm định 18 bước nền tối `.stitch-qa-navy-box` trải dài 4 card văn bản lớn + dải serial dài.
   - Footer Mobile 4 cột xếp dọc không thu gọn, chiếm hơn `1,200px` chiều cao.
   - Các section sản phẩm không thống nhất lưới và số lượng hiển thị.
4. **Dùng Emoji thay SVG Icon ở nhiều khối giao diện:** Cam kết cửa hàng (`📋`, `🛡️`, `💰`, `🎧`), nút giỏ hàng (`🛒`), nút lọc (`⚙`) khiến độ dày nét và thẩm mỹ thiếu đồng nhất trên các hệ điều hành.
5. **Floating Contact Widget thiếu Facebook & đang ẩn Zalo/Messenger:**
   - Prompt 23.2 đã ẩn Zalo & Messenger do chưa có URL thật, và chưa có nút **Facebook** riêng biệt.
   - Yêu cầu Prompt 24: hiển thị đủ **5 nút** (**Zalo `#0068FF`**, **Facebook `#1877F2`**, **Messenger**, **Hotline**, **Back to Top**); các kênh chưa có thông tin thật vẫn hiển thị an toàn dạng `<button type="button">` (không dùng `href="#"`) và hiện thông báo nhẹ *"Kênh liên hệ đang được cập nhật."* khi người dùng chạm vào.

---

## 2. Danh sách chi tiết lỗi UI/UX phát hiện (8 nhóm lỗi chính)

| Mã lỗi | Trang / Khu vực | Viewport | Selector liên quan | Nguyên nhân gốc | Mức độ | Screenshot trước sửa | Phương án khắc phục |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **UI-AUD-01** | Trang chủ, `/san-pham`, `/san-pham/{slug}` | `320px–1920px` | `.flash-sale-card`, `.pc-showcase-card`, `.laptop-horizontal-card`, `.component-clean-card`, `.catalog-product-grid .product-card` | Tồn tại 6 biến thể HTML/CSS Product Card khác nhau; khu vực Linh kiện không có ảnh, chiều cao card lệch, nút CTA không thẳng hàng. | **Cao** | `before/home_mobile_375_full.png`, `before/products_mobile_375.png` | Xây dựng 1 partial chuẩn dùng chung `app/Views/partials/product_card.php` (ảnh `16:11`, badge, tên 2 dòng, 3 thông số chính, giá đỏ/cũ, tồn kho+BH, CTA căn đáy Flexbox). |
| **UI-AUD-02** | Trang chủ Mobile — Khối kiểm định 18 bước | `320px–768px` | `.stitch-qa-navy-box`, `.stitch-qa-steps-grid`, `.stitch-qa-step-card` | Hiển thị trải dài 4 khối chữ lớn trên nền tối khiến Trang chủ Mobile quá nặng chữ và dài (`8,862px`). | **Cao** | `before/home_mobile_375_full.png` | Chuyển 4 nhóm kiểm định thành **Accordion** tương tác gọn nhẹ trên Mobile (`aria-expanded`, icon SVG, nút xem chi tiết), giữ đủ dữ liệu thật từ MySQL. |
| **UI-AUD-03** | Footer Mobile toàn Storefront | `320px–768px` | `.site-footer`, `.footer-grid`, `.footer-col` | 4 cột liên kết xếp dọc toàn bộ trên Mobile tạo khối chân trang dài >1,200px. | **Cao** | `before/home_mobile_375_full.png` | Redesign Footer Mobile với **Accordion** có `aria-expanded` & hỗ trợ bàn phím cho 3 nhóm liên kết (Chính sách, Danh mục, Thanh toán); Desktop giữ nguyên 4 cột mở sẵn. |
| **UI-AUD-04** | Floating Contact Widget toàn Storefront | `320px–1920px` | `.fcw-root`, `.fcw-channels`, `config/contact.php` | Thiếu icon **Facebook**; các kênh Zalo/Messenger chưa cấu hình đang bị ẩn hoàn toàn nên khách không thấy đủ hệ sinh thái kênh hỗ trợ. | **Cao** | `before/home_desktop_1440.png`, `before/home_mobile_375.png` | Bổ sung `facebook_url` & `show_unconfigured_channels = true` trong `config/contact.php`; render đủ 5 icon SVG (Zalo, Facebook, Messenger, Hotline, Back to Top); kênh chưa cấu hình hiện toast nhẹ *"Kênh liên hệ đang được cập nhật."*. |
| **UI-AUD-05** | Cam kết cửa hàng & Nút CTA | `320px–1920px` | `.commitment-icon`, `.product-card .btn` | Sử dụng emoji (`📋`, `🛡️`, `💰`, `🎧`, `🛒`) làm icon giao diện gây lệch nét giữa Windows / iOS / Android. | **Trung bình** | `before/home_mobile_375.png`, `before/home_desktop_1440.png` | Thay toàn bộ icon Cam kết và nút CTA bằng bộ SVG đồng nhất cùng độ dày nét `stroke-width="2"`. |
| **UI-AUD-06** | Trang chủ — Khối Bento Laptop & Linh kiện | `320px–1440px` | `.stitch-bento-split`, `.laptop-horizontal-stack`, `.component-bento-grid` | Khối Laptop và Linh kiện bị gò chung vào 2 hộp Bento chật, không dùng chung lưới Product Card chuẩn và thiếu ảnh ở Linh kiện. | **Trung bình** | `before/home_mobile_375_full.png` | Tách thành 2 section độc lập gọn gàng (*Laptop Gaming Cũ Tuyển Chọn* & *Linh Kiện & Màn Hình Zin Giá Tốt*) dùng chung lưới Unified Product Card (tối đa 4 SP/section + nút Xem tất cả). |
| **UI-AUD-07** | Kiến trúc CSS `style.css` | `320px–1920px` | Toàn bộ cuối file `style.css` | Còn nhiều mã màu hardcode (`#1d4ed8`, `#0051d5`, `#ba1a1a`) và khối media query lặp lại giữa Prompt 23 / 23.1 / 23.2. | **Trung bình** | `before/home_desktop_1440.png` | Chuẩn hóa về bộ Design Token thống nhất (`--color-primary: #2454D9`, `--color-price: #DC2626`, `--color-bg: #F5F7FA`, `--font-family`), dọn sạch quy tắc mâu thuẫn. |
| **UI-AUD-08** | Admin Portal (`/admin/*`) | `320px–1440px` | `public/assets/css/admin.css` | Kiểm tra độ đồng nhất font `Be Vietnam Pro`, màu xanh chủ đạo `#2454D9` và responsive bảng/filter trên mọi trang Admin. | **Thấp** | *(Kiểm tra trực tiếp ở Giai đoạn 9 & 10)* | Đồng bộ màu `--adm-blue` về `#2454D9`, xác minh toàn bộ trang Admin (Dashboard, Sản phẩm, Serial, Kiểm định 18 bước, Đơn hàng, Khách hàng, Voucher) trên Chrome thật. |

---

## 3. Phạm vi kiểm thử Route thực tế (từ `routes/web.php`)

- **Storefront (Khách hàng):** `/`, `/san-pham` (kèm `?danh-muc=...`, `?q=...`, `?gia-tu=...&gia-den=...`, `?sap-xep=...`), `/san-pham/{slug}`, `/gio-hang` (có SP & trống), `/thanh-toan` (giao hàng & nhận tại showroom), `/dat-hang/thanh-cong/{order_code}`, `/tra-cuu-don-hang`, `/dang-nhap` (Tab Đăng nhập & Tab Đăng ký), `/tai-khoan`, `/doi-mat-khau`, trang lỗi `404` & `403`.
- **Route không tồn tại (N/A):** Không có trang Tin tức/Blog riêng, không có trang Khuyến mãi độc lập (điều hướng về `/#san-pham-noi-bat`), không có trang Chi tiết đơn hàng khách hàng độc lập ngoài `/tra-cuu-don-hang` và `/dat-hang/thanh-cong/{order_code}`.
- **Admin Portal:** `/admin`, `/admin/danh-muc`, `/admin/san-pham`, `/admin/san-pham/them`, `/admin/serial`, `/admin/serial/{id}`, `/admin/serial/{id}/kiem-dinh/them`, `/admin/don-hang`, `/admin/don-hang/{id}`, `/admin/khach-hang`, `/admin/khach-hang/{id}`, `/admin/voucher`, `/admin/voucher/them`, `/admin/voucher/{id}/lich-su`.
