# DANH SÁCH LỖI UI/UX ĐÃ PHÁT HIỆN & KHẮC PHỤC TOÀN WEBSITE (PROMPT 24)

- **Dự án:** Website kinh doanh máy tính và linh kiện cũ **TL Gaming**
- **Ngày hoàn tất:** 25/11/2026
- **Tổng số lỗi UI/UX phát hiện trong Giai đoạn Audit:** **8 nhóm lỗi** (`UI-AUD-01` → `UI-AUD-08`: 4 Cao, 3 Trung bình, 1 Thấp).
- **Tổng số lỗi đã khắc phục & xác minh PASS trên Chrome thật:** **8/8 (100%)**.
- **Lỗi nghiêm trọng còn tồn tại:** **0**.

---

## Bảng tổng hợp theo dõi lỗi

| Mã lỗi | Mức độ | Trang / Khu vực | Viewport | Nguyên nhân gốc | Tệp đã sửa | Trạng thái |
| --- | --- | --- | --- | --- | --- | --- |
| **UI-AUD-01** | **Cao** | Trang chủ, `/san-pham`, `/san-pham/{slug}` | `320px–1920px` | Tồn tại 6 cấu trúc Product Card rời rạc (Linh kiện không có ảnh, Laptop dạng ngang, Flash Sale riêng, PC Gaming riêng) khiến chiều cao và nút hành động không thẳng hàng. | `app/Views/partials/product_card.php`, `app/Views/home/index.php`, `app/Views/products/index.php`, `app/Views/products/show.php`, `public/assets/css/style.css` | **ĐÃ SỬA (PASS)** |
| **UI-AUD-02** | **Cao** | Trang chủ Mobile — Quy trình kiểm định 18 bước | `320px–768px` | Khối `.stitch-qa-navy-box` hiển thị trải dài 4 card văn bản lớn làm tổng chiều dài Trang chủ Mobile lên tới `8,862px`. | `app/Views/home/index.php`, `public/assets/css/style.css`, `public/assets/js/app.js` | **ĐÃ SỬA (PASS)** |
| **UI-AUD-03** | **Cao** | Footer Mobile toàn Storefront | `320px–768px` | 4 cột chân trang xếp dọc hoàn toàn trên điện thoại làm Footer quá dài (>1,200px). | `app/Views/layouts/main.php`, `public/assets/css/style.css`, `public/assets/js/app.js` | **ĐÃ SỬA (PASS)** |
| **UI-AUD-04** | **Cao** | Floating Contact Widget toàn Storefront | `320px–1920px` | Thiếu icon Facebook; Zalo và Messenger bị ẩn hoàn toàn khi chưa điền URL thật thay vì hiển thị an toàn kèm thông báo đang cập nhật. | `config/contact.php`, `.env.example`, `app/Views/partials/floating_contact_widget.php`, `public/assets/css/style.css`, `public/assets/js/app.js` | **ĐÃ SỬA (PASS)** |
| **UI-AUD-05** | **Trung bình** | Cam kết cửa hàng & Nút CTA trên thẻ sản phẩm | `320px–1920px` | Dùng emoji (`📋`, `🛡️`, `💰`, `🎧`, `🛒`) làm icon giao diện gây lệch phong cách giữa các hệ điều hành. | `app/Views/home/index.php`, `app/Views/partials/product_card.php`, `public/assets/css/style.css` | **ĐÃ SỬA (PASS)** |
| **UI-AUD-06** | **Trung bình** | Trang chủ — Khối Bento Laptop & Linh kiện | `320px–1440px` | Khối Laptop và Linh kiện gò chung vào 2 hộp Bento không đồng nhất với các section sản phẩm còn lại. | `app/Views/home/index.php`, `public/assets/css/style.css` | **ĐÃ SỬA (PASS)** |
| **UI-AUD-07** | **Trung bình** | Design System CSS (`style.css`) | `320px–1920px` | Các màu hardcode (`#1d4ed8`, `#0051d5`, `#ba1a1a`) và nền body Mobile `#f8fafc` lệch nhẹ với nền Desktop `#F5F7FA`. | `public/assets/css/style.css` | **ĐÃ SỬA (PASS)** |
| **UI-AUD-08** | **Thấp** | Admin Portal (`/admin/*`) | `320px–1440px` | Màu chủ đạo `--adm-blue` (`#2563eb`) và nền `--adm-bg` (`#f1f5f9`) lệch nhẹ tông xanh thương hiệu mới `#2454D9` và `#F5F7FA`. | `public/assets/css/admin.css` | **ĐÃ SỬA (PASS)** |

---

## Bằng chứng cải thiện trước / sau
- Chiều dài Trang chủ Mobile `375×812`: giảm từ **`8,862px`** (`before/home_mobile_375_full.png`) xuống **`6,513px`** (`after/mobile/home_mobile_375_full.png`, tiết kiệm **`2,349px`** ~ **26.5%**) trong khi vẫn giữ đầy đủ ảnh sản phẩm, thông số chính, quy trình kiểm định 18 bước và toàn bộ liên kết chính sách.
- 100% Product Card trên Trang chủ, `/san-pham`, Kết quả tìm kiếm và Sản phẩm liên quan thẳng hàng tuyệt đối theo 1 component chuẩn (`app/Views/partials/product_card.php`).
- Floating Contact Widget hiển thị đủ 5 nút SVG (Zalo, Facebook, Messenger, Hotline `tel:19008868`, Back to Top); bấm kênh chưa cấu hình hiện thông báo an toàn `"Zalo: Kênh liên hệ đang được cập nhật."`.
