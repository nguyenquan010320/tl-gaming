# TÀI LIỆU ĐỐI CHIẾU & ÁNH XẠ THIẾT KẾ GOOGLE STITCH — TL GAMING

> **Mục đích tài liệu:** Phân tích chi tiết 6 bản thiết kế giao diện chính thức từ **Google Stitch** cho dự án **TL Gaming — Website bán máy tính cũ**, đối chiếu với giao diện Dark Gaming trước đây và xác định bảng ánh xạ màu sắc, bố cục, typography, thành phần UI cũng như quy tắc dữ liệu trung thực cho 3 trang đã triển khai (`Trang chủ`, `Danh sách sản phẩm`, `Chi tiết sản phẩm`).

---

## 1. TỔNG QUAN HỆ THỐNG THIẾT KẾ GOOGLE STITCH (`TL GAMING RETAIL`)

Qua phân tích 6 màn hình thiết kế Google Stitch (`Trang chủ`, `Danh sách sản phẩm`, `Chi tiết sản phẩm`, `Giỏ hàng`, `Thanh toán đơn hàng`, `Dashboard quản trị`), hệ thống giao diện chính thức của **TL Gaming** sử dụng ngôn ngữ thiết kế **Clinical High-Trust Light Retail kết hợp Deep Navy Header**:

### 1.1. Bảng màu chuẩn (Color Palette)

| Thành phần | Mã màu Stitch | Mã màu Dark UI cũ | Quy tắc áp dụng trên Website |
| :--- | :--- | :--- | :--- |
| **Nền trang chính (`Background`)** | `#F9F9FF` / `#F8FAFC` | `#0B0F17` (Đen tím tối) | Nền sáng sạch sẽ, giúp thông số kỹ thuật và ảnh chụp máy tính cũ rõ nét, tạo cảm giác minh bạch. |
| **Nền thẻ sản phẩm / Khối nội dung (`Card Surface`)** | `#FFFFFF` | `#131B2E` (Xanh đen) | Toàn bộ card sản phẩm, bộ lọc, khung thông tin chính, bảng thông số dùng nền trắng tinh `#FFFFFF`, viền mảnh `#E2E8F0`. |
| **Nền phụ / Khung ảnh sản phẩm (`Surface Low`)** | `#F0F3FF` / `#F1F5F9` | `#1A243B` | Nền khung ảnh vuông (1:1) của sản phẩm, ô tóm tắt thông số 2 cột (`CPU`, `VGA`, `RAM`, `SSD`) và dòng chẵn trong bảng kỹ thuật. |
| **Header & Khối nhấn thương hiệu (`Deep Navy`)** | `#141C2B` / `#101827` | `#080C14` | Dùng cho **Header điều hướng**, **Banner Hero chính**, và khối **Quy trình kiểm định 18 bước** để giữ chất công nghệ / gaming chuyên nghiệp. |
| **Ô tìm kiếm & Thẻ trên nền Navy (`Navy Container`)** | `#263143` / `#1E293B` | `#131B2E` | Ô input tìm kiếm trên Header và 4 thẻ bước kiểm định (`01` – `04`) bên trong khối Deep Navy. |
| **Màu chữ chính (`Primary Text`)** | `#111C2D` / `#1E293B` | `#F8FAFC` (Trắng) | Chữ xanh đen đậm sắc nét trên nền sáng, độ tương phản cao. |
| **Màu chữ phụ (`Secondary Text`)** | `#45474C` / `#64748B` | `#94A3B8` | Mô tả ngắn, nhãn thông số phụ, breadcrumb, đường dẫn. |
| **Màu chữ mờ / Giá gốc gạch ngang (`Muted Text`)** | `#76777D` / `#94A3B8` | `#64748B` | Giá niêm yết gạch ngang (`old_price`), ghi chú phụ. |
| **Màu nút hành động chính (`Primary CTA Blue`)** | `#0051D5` (Hover: `#316BF3`) | `#00F0FF` (Cyan neon) | Nút `Mua ngay`, `Áp dụng bộ lọc`, `Giỏ hàng`, `Tìm kiếm`, nhãn danh mục đang chọn. Chữ trắng `#FFFFFF`. |
| **Màu nền phụ xanh dương nhạt (`Primary Fixed`)** | `#DBE1FF` / `#EFF6FF` | `rgba(0,240,255,0.1)` | Pill `Bảo hành 12T`, hộp cam kết kiểm định, nút phụ `Xem chi tiết` / `Chọn mua`. |
| **Màu giá bán & Khuyến mãi (`Price / Sale Crimson`)** | `#BA1A1A` / `#EF4444` | `#FF6B00` / `#00F0FF` | **Giá bán chính thức (`selling_price`)**, badge giảm giá `-XX%`, khung viền khối Flash Sale / Giờ vàng giá sốc. |
| **Màu nhấn kiểm định (`Cyan Trust Badge`)** | `#4CD7F6` / `#ACEDFF` | `#00F0FF` | Nhãn `✓ Đã kiểm định 18 bước`, badge `CŨ ZIN` cạnh logo, điểm nhấn kỹ thuật trên nền Navy. |
| **Màu trạng thái còn hàng (`Success Emerald`)** | `#10B981` / `#059669` | `#10B981` | Trạng thái `Sẵn hàng giao ngay`, serial `available`, kết quả kiểm định `ĐẠT (PASS)`. |

### 1.2. Typography & Bố cục (Layout Grid)

- **Font chữ:** `Be Vietnam Pro` (Google Fonts) hỗ trợ hoàn hảo tiếng Việt có dấu, kết hợp `JetBrains Mono` / monospace cho mã SKU và số Serial (`S/N`).
- **Container chuẩn:** `max-width: 1280px`, `padding: 0 16px`, lưới 12 cột (`grid-template-columns: repeat(12, minmax(0, 1fr))`), khoảng cách `gap: 16px` – `24px`.
- **Bo góc (`Border Radius`):**
  - Card sản phẩm & hộp nội dung: `8px` (`0.5rem`) đến `12px` (`0.75rem`).
  - Khối lớn (Hero Banner, Khối Kiểm định 18 bước, Khung Flash Sale): `12px` (`0.75rem`) đến `16px` (`1rem`).
  - Badge & Pill trạng thái: `4px` – `6px` hoặc `9999px` (pill tròn).

---

## 2. PHÂN TÍCH CHI TIẾT 6 MÀN HÌNH GOOGLE STITCH

### Màn hình 1: Trang chủ TL Gaming (`trang_ch_tl_gaming`)
- **Header (`#141C2B`):**
  - Thanh topbar phụ: Cam kết kiểm định 18 bước, thông tin showroom, hỗ trợ kỹ thuật, tra cứu bảo hành.
  - Thanh chính: Logo `TL GAMING` kèm badge cyan `CŨ ZIN`, thanh tìm kiếm trung tâm (`#263143`) kèm từ khóa gợi ý nhanh (`PC i5-12400F`, `RTX 3060`, `Laptop Gaming`, `Màn 144Hz`), cụm Hotline (`1900.xxxx`), nút Tài khoản, nút Giỏ hàng xanh dương `#0051D5` có chấm đỏ số lượng.
  - Thanh điều hướng danh mục: Nút `DANH MỤC SẢN PHẨM` + các liên kết danh mục (`PC Gaming Cũ`, `Laptop Gaming Cũ`, `Linh Kiện PC`, `Màn Hình Gaming`, `Quy Trình Test 18 Bước`) + pill đỏ `Xả Kho Giá Tốt`.
- **Hero Section (Lưới 12 cột bất đối xứng 8 + 4):**
  - **Cột trái (8 cột):** Banner nền `#141C2B` có lưới kỹ thuật mờ, badge cyan `CAM KẾT LINH KIỆN ZIN 100% CHƯA SỬA CHỮA`, tiêu đề lớn `TL GAMING – MÁY TÍNH CŨ CHẤT LƯỢNG, GIÁ CỰC TỐT`, 2 nút CTA (`Xem Cấu Hình Giá Tốt` + `Quy Trình Test 18 Bước`), và thanh sản phẩm tiêu biểu ở đáy banner lấy từ CSDL thực.
  - **Cột phải (4 cột):** 3 thẻ trắng xếp dọc (`PC Gaming Cũ Chiến Mọi Tựa Game`, `Laptop Gaming Cũ Zin Đẹp 98-99%`, `Linh Kiện Cũ Zin Giá Tiết Kiệm`) dẫn tới danh mục thực tế.
- **4 Thẻ Cam Kết Giá Trị (`Value Propositions`):** 4 thẻ trắng ngang hàng với icon xanh dương nhạt (`#DBE1FF` / `#0051D5`).
- **Khối Ưu Đãi Giá Tốt / Flash Sale (`Giờ Vàng Giá Sốc`):** Khung trắng viền đỏ `#BA1A1A` dày 2px, header nền hồng nhạt `#FFF5F5` với badge đỏ `GIỜ VÀNG GIÁ SỐC`, lưới 4 sản phẩm có mức giảm giá sâu nhất từ MySQL.
- **Khối PC Gaming Cũ Tuyển Chọn (Lưới 4 cột):**
  - Header tiêu đề + các nút lọc nhanh + link `Xem tất cả`.
  - Card sản phẩm chuẩn Stitch: Khung ảnh vuông nền `#F0F3FF`, badge giảm giá đỏ góc trái trên, badge `✓ Đã kiểm định` góc phải trên, dòng pill `Bảo hành XXT` + `Tình trạng`, tên sản phẩm 2 dòng, hộp thông số 2x2 nền `#F0F3FF` (`CPU`, `VGA`, `RAM`, `SSD`), giá bán màu đỏ `#BA1A1A` + giá cũ gạch ngang, 2 nút đáy card (`Xem chi tiết` + nút icon giỏ hàng xanh `#0051D5`).
- **Khối Bento Đôi (12 cột = 7 + 5):**
  - **Cột trái (7 cột) — Laptop Cũ Tuyển Chọn:** Danh sách thẻ ngang (ảnh vuông bên trái, pill bảo hành + tên + thông số ở giữa, giá đỏ + nút `Chọn mua` bên phải).
  - **Cột phải (5 cột) — Linh Kiện Cũ Zin Giá Tốt:** Lưới 2x2 thẻ linh kiện nhỏ gọn + hộp kêu gọi tư vấn cấu hình ở đáy.
- **Khối Quy Trình Kiểm Định Linh Kiện 18 Bước:** Khung nền `#141C2B` bo góc lớn, gồm 4 thẻ bước đánh số `01`, `02`, `03`, `04` màu cyan `#4CD7F6` + danh sách biên bản kiểm định thực tế từ CSDL.
- **Khối Đánh Giá Khách Hàng & Footer 4 cột nền trắng `#FFFFFF`.**

### Màn hình 2: Danh sách sản phẩm (`danh_s_ch_s_n_ph_m_tl_gaming`)
- **Breadcrumb nền sáng:** `Trang chủ > Danh mục sản phẩm`.
- **Category Header Card:** Khung trắng viền `#E2E8F0` hiển thị tên danh mục hiện tại, mô tả, tổng số sản phẩm thực tế bên trái; bên phải là hộp cam kết kiểm định & bảo hành nền `#141C2B`.
- **Bố cục 12 cột (Sidebar 3 cột + Nội dung 9 cột):**
  - **Sidebar bộ lọc (3 cột, sticky):** Thẻ trắng `#FFFFFF` có tiêu đề `Bộ lọc chuyên sâu` + link `Thiết lập lại`, lọc Khoảng giá (chọn nhanh + nhập giá từ/đến), lọc Danh mục sản phẩm, lọc Tình trạng kho (`Tất cả`, `Chỉ còn hàng`), từ khóa tìm kiếm và nút xanh `#0051D5` `Áp dụng bộ lọc`.
  - **Khu vực chính (9 cột):**
    - Thanh công cụ sắp xếp (`Sắp xếp theo:` các pill `Mới nhất`, `Giá tăng dần`, `Giá giảm dần`, `Tên A-Z` đồng bộ với `<select>`) + bộ đếm `Hiển thị X - Y trên Z sản phẩm`.
    - Lưới thẻ sản phẩm (3 cột trên màn hình lớn), mỗi thẻ có ảnh 1:1, badge giảm giá & kiểm định, pill tình trạng & bảo hành, lưới 4 ô thông số (`CPU`, `VGA`, `RAM`, `SSD`), giá đỏ `#BA1A1A`, và 2 nút `Chi tiết` + `Mua ngay`.
    - Thanh phân trang (`Pagination`) ở dưới cùng + 3 thẻ cam kết dịch vụ cuối trang.

### Màn hình 3: Chi tiết sản phẩm (`chi_ti_t_s_n_ph_m_tl_gaming`)
- **Breadcrumb:** `Trang chủ / Danh mục / Tên sản phẩm`.
- **Khối thông tin chính (Thẻ trắng 12 cột = 5 cột Ảnh + 7 cột Thông tin):**
  - **Cột trái (5 cột — Gallery):**
    - Khung ảnh chính vuông 1:1 nền `#F0F3FF`, góc trái trên có badge đỏ `-XX%` và badge cyan `BẢO HÀNH XX THÁNG`, góc phải dưới có nút `Phóng to ảnh`.
    - Dải 5 ảnh thumbnail bên dưới (ảnh đang chọn có viền xanh `#0051D5`).
    - Hộp cam kết xanh dương nhạt (`#EFF6FF` viền `#BFDBFE`): `Cam kết chất lượng TL Gaming — Máy nguyên bản, đã vượt qua quy trình kiểm định phần cứng 18 bước...`.
  - **Cột phải (7 cột — Thông tin & Mua hàng):**
    - Dòng mã định danh: Badge nền tối `MÃ SKU: ...` + pill xanh `ĐÃ KIỂM ĐỊNH (TESTED PASS)`.
    - Tiêu đề `H1` chữ đậm màu `#111C2D`.
    - Thanh trạng thái máy nền xanh nhạt (`Tình trạng máy: ...` + trạng thái kho `Sẵn hàng giao ngay` / `Tạm hết hàng`).
    - Hộp Giá bán nền `#F8FAFC`: Giá bán lớn màu đỏ `#BA1A1A` (`36px`), giá gốc gạch ngang, phần trăm tiết kiệm, kèm hộp thông tin bảo hành bên phải.
    - Hộp Chính sách & Dịch vụ đi kèm (nền vàng nhạt `#FFFBEB` viền `#FDE68A`): Chỉ liệt kê dịch vụ thực tế của cửa hàng (vệ sinh, tra keo tản nhiệt, cài đặt HĐH, đồng kiểm khi nhận hàng — không tạo quà tặng giả).
    - Lưới **Cấu hình linh kiện tóm tắt** (2 cột x 3 hàng) lấy từ `product_specs`.
    - Danh sách **Đơn vị máy theo Serial (`product_units`)** từ MySQL (hiển thị rõ `serial_number`, `condition_grade`, trạng thái kho và tình trạng biên bản kiểm định của đúng serial đó).
    - Hàng nút hành động: Bộ chọn số lượng (`-` / `+` giới hạn theo `purchasable_quantity`), nút viền xanh `THÊM VÀO GIỎ HÀNG` (`#addToCartBtn`), nút xanh đậm `MUA NGAY - GIAO HÀNG TẬN NƠI` (`#buyNowBtn`) và hộp thông báo `#cartDevNotice`.
    - 3 cam kết dịch vụ ở chân cột phải (`Cam kết nguyên bản`, `Bảo hành uy tín`, `Giao hàng an toàn`).
- **Khối 4 Tab chi tiết (Thẻ trắng):**
  1. `Đặc điểm nổi bật & Hiệu năng` (`#tab-btn-highlights`): Bài viết mô tả thực tế & điểm nhấn phần cứng từ CSDL.
  2. `Bảng thông số kỹ thuật chi tiết` (`#tab-btn-specs`): Bảng 2 cột xen kẽ màu nền `#F8FAFC` / `#FFFFFF` hiển thị đầy đủ `product_specs`.
  3. `Biên bản kiểm định linh kiện` (`#tab-btn-inspection`): Hiển thị biên bản `product_inspections` và bảng 18 hạng mục `inspection_items` gắn chính xác với từng `product_units` (serial).
  4. `Đánh giá khách hàng` (`#tab-btn-reviews`): Thông báo trung thực chức năng đang phát triển, tuyệt đối không tạo đánh giá giả.
- **Khối Cấu hình tương tự cùng phân khúc:** Lưới 4 cột sản phẩm cùng danh mục với nút viền xanh `Xem cấu hình`.

### Màn hình 4, 5, 6 (Tham chiếu đồng bộ hệ thống cho các giai đoạn sau)
- **Màn hình 4 — Giỏ hàng (`gi_h_ng_tl_gaming`):** Nền sáng `#F8FAFC`, bảng sản phẩm bên trái (8 cột) kèm badge serial & kiểm định, khung tóm tắt đơn hàng & mã giảm giá bên phải (4 cột), nút thanh toán xanh `#0051D5`.
- **Màn hình 5 — Thanh toán (`thanh_to_n_n_h_ng_tl_gaming`):** Bố cục 2 cột (Thông tin giao hàng + Phương thức thanh toán COD / Chuyển khoản ngân hàng ở cột trái; Tóm tắt đơn hàng ở cột phải).
- **Màn hình 6 — Dashboard quản trị (`dashboard_qu_n_tr_tl_gaming`):** Sidebar trái nền Deep Navy `#141C2B`, khu vực nội dung chính nền sáng `#F8FAFC` với 4 thẻ KPI, biểu đồ doanh thu và bảng đơn hàng mới nhất.

---

## 3. ĐỐI CHIẾU GIAO DIỆN HIỆN TẠI (DARK GAMING) VỚI GOOGLE STITCH & CÁC ĐIỂM CẦN SỬA

| Hạng mục | Giao diện hiện tại (Dark Gaming) | Thiết kế chuẩn Google Stitch | Hành động chỉnh sửa |
| :--- | :--- | :--- | :--- |
| **Màu nền tổng thể** | Nền tối `#0B0F17`, chữ trắng `#F8FAFC` | Nền sáng `#F9F9FF` / `#F8FAFC`, chữ tối `#111C2D` | Chuyển toàn bộ biến CSS và nền `body`, `.main-content` sang Light Mode chuẩn Stitch; chỉ giữ `#141C2B` cho Header, Hero Banner và khối Kiểm định 18 bước. |
| **Card sản phẩm** | Nền tối `#131B2E`, viền cyan phát sáng, giá màu cam/cyan | Nền trắng `#FFFFFF`, khung ảnh `#F0F3FF`, giá bán màu đỏ `#BA1A1A`, nút xanh `#0051D5` | Thiết kế lại `.product-card` đúng cấu trúc Stitch: ảnh 1:1, badge đỏ/cyan, pill bảo hành, hộp spec 2x2 nền xám xanh nhạt, giá đỏ, 2 nút hành động. |
| **Trang chủ (`home/index.php`)** | Hero 2 cột cân xứng nền tối, danh mục dạng thẻ tròn tối, không có Bento Laptop/Linh kiện | Hero 8+4 bất đối xứng, 4 thẻ cam kết trắng, khung Flash Sale viền đỏ, lưới PC 4 cột, Bento 7+5 (Laptop ngang + Linh kiện 2x2), khối 18 bước nền Navy | Viết lại `app/Views/home/index.php` khớp 100% bố cục Stitch và đổ dữ liệu thật từ MySQL (`$featuredProducts`, `$pcProducts`, `$laptopProducts`, `$componentProducts`, `$verifiedRecords`). |
| **Trang danh sách (`products/index.php`)** | Sidebar tối, card tối, thanh sắp xếp tối | Header danh mục nền trắng kèm hộp cam kết Navy, Sidebar trắng 3 cột, Toolbar sắp xếp có pill chọn nhanh + select, lưới 3 cột trên nền sáng, 3 cam kết cuối trang | Cập nhật `app/Views/products/index.php` sang bố cục Stitch, giữ nguyên toàn bộ tham số GET (`q`, `danh-muc`, `gia-tu`, `gia-den`, `ton-kho`, `sap-xep`, `page`). |
| **Trang chi tiết (`products/show.php`)** | Khung tối, bảng spec tối, tab tối | Lưới 5+7 trên thẻ trắng, giá đỏ lớn trên nền `#F8FAFC`, lưới tóm tắt 6 ô cấu hình, danh sách Serial rõ ràng, 4 Tab nền sáng, bảng 18 hạng mục kiểm định sáng sủa | Cập nhật `app/Views/products/show.php` sang giao diện Stitch, giữ nguyên toàn bộ ID tương tác (`#mainProductImage`, `#productThumbList`, `#qtyInput`, `#addToCartBtn`, `#buyNowBtn`, `#cartDevNotice`, 4 tab ARIA). |
| **Quy tắc trung thực dữ liệu** | Đã tuân thủ dữ liệu thật | Ảnh mẫu Stitch có một số số liệu minh họa tĩnh (như `"Đã bán 16/20"`, `"FPS CS2: 250+"`, `"128 đánh giá 4.9/5"`) | **Tuyệt đối không đưa dữ liệu giả vào code:** Thay thanh `"Đã bán 16/20"` bằng tồn kho thực tế từ `stock_quantity`; không bịa thông số FPS; không tạo đánh giá khách hàng giả; chỉ hiển thị biên bản kiểm định cho đúng `product_unit` có trong CSDL. |
