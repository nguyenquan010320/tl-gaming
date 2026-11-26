# BÁO CÁO KIỂM TOÁN THỊ GIÁC TRANG CHỦ (UI VISUAL AUDIT — A vs B @ 1440px)

- **Dự án:** Website bán máy tính cũ TL Gaming (PHP thuần + MySQL + PDO + MVC tự xây dựng)
- **Đối tượng so sánh:**
  - **Ảnh A (Thiết kế chuẩn Google Stitch):** `media_1794390931444_abe8879d.jpg` (Trang chủ TL Gaming gốc)
  - **Ảnh B (Giao diện Localhost trước khi chỉnh sửa):** `media_1794390935338_cee21871.jpg`
- **Chiều rộng Viewport kiểm chứng:** Desktop `1440px` (`1440x900`)

---

## 1. Thông Số Khung Bố Cục Tổng Thể (Layout & Typography Metrics @ 1440px)

| Thông số kỹ thuật | Ảnh A (Google Stitch gốc) | Ảnh B (Localhost trước khi sửa) | Sau khi chỉnh sửa & Kiểm chứng |
| :--- | :--- | :--- | :--- |
| **Chiều rộng Viewport chuẩn** | `1440px` | `1440px` | Đã kiểm chứng trực tiếp ở `1440x900` |
| **Chiều rộng `.container` chính** | `1360px` (lề trái/phải ~`40px` ở màn `1440px`) | `1280px` (lề trái/phải ~`80px`, làm khối nội dung bị bó hẹp) | **Đã sửa:** `--container-max: 1360px`, `--container-pad: 24px` |
| **Chiều cao Topbar** | `34px`, nền `#0D1320`, chữ `11.8px` | `36px`, nền `#0F172A`, chữ `12px` | **Đã sửa:** Nền `#0D1320`, chữ `0.74rem` (`11.8px`), padding `6px 0` |
| **Chiều cao Header chính** | `70px`, nền `#141C2B`, có vạch ngăn dọc cụm phải | `78px`, nền `#141C2B`, thiếu vạch ngăn dọc | **Đã sửa:** Thêm `.header-action-divider`, icon SVG tay cầm gaming, tai nghe, tài khoản, giỏ hàng |
| **Chiều cao Thanh Menu ngang** | `42px`, 1 dòng duy nhất, nền `#182234` | `46px`, 1 dòng, nền `#182234` | **Đã sửa:** `42px`, 1 dòng duy nhất, gạch chân `#3B82F6` dày `2.5px` cho mục active |
| **Khoảng cách dọc giữa các Section** | Đều đặn (`16px` padding mỗi chiều = `32px` giữa 2 khối) | Không đều, bị chèn thêm Section Danh mục 6 thẻ văn bản | **Đã sửa:** Xóa Section Danh mục 6 thẻ rời rạc; chuẩn hóa `--section-gap: 16px` |
| **Font chữ & Kiểu tiêu đề** | `Be Vietnam Pro`, tiêu đề Section viết kiểu Title Case | Nhiều tiêu đề Section `UPPERCASE` kèm thanh dọc xanh | **Đã sửa:** Đưa tiêu đề Section về Title Case đúng Ảnh A, bỏ thanh dọc xanh thừa |

---

## 2. Bảng So Sánh 13 Khác Biệt Thị Giác Cụ Thể (A vs B) & Trạng Thái Khắc Phục

| STT | Thành phần giao diện | Ảnh A (Google Stitch chuẩn) | Ảnh B (Localhost trước khi sửa) | Giải pháp & Tình trạng sau sửa |
| :---: | :--- | :--- | :--- | :--- |
| **01** | **Container & Tỉ lệ lề trang ở 1440px** | Nội dung trải rộng cân đối (`max-width: 1360px`), khoảng trắng hai bên vừa phải ở màn `1440px`. | `max-width: 1280px` khiến toàn bộ lưới 4 cột bị bó hẹp ở giữa màn hình. | **ĐÃ SỬA:** Đặt `--container-max: 1360px` và `--container-pad: 24px` trong `:root`. |
| **02** | **Header: Logo, Tìm kiếm & Cụm tiện ích phải** | Icon tay cầm chơi game trắng trong ô vuông xanh `#2563EB`; có đường kẻ dọc mảnh ngăn cách giữa `Tư vấn cấu hình` và `Đăng nhập`; nút `Giỏ hàng` xanh dương nổi bật kèm badge đỏ `2`. | Ô logo dùng emoji `🖥️`; thiếu vạch ngăn dọc giữa `Tư vấn cấu hình` và `Đăng nhập`. | **ĐÃ SỬA:** Thay icon logo bằng SVG tay cầm gaming trắng; thêm `.header-action-divider`; dùng icon SVG cho Tìm kiếm, Tư vấn, Tài khoản và Giỏ hàng. |
| **03** | **Hero Banner (Cột trái 8 cột)** | Tiêu đề H1 `TL GAMING – MÁY TÍNH CŨ CHẤT LƯỢNG, GIÁ CỰC TỐT` màu trắng đồng nhất; mô tả gọn 2 dòng; nút chính `Khám phá sản phẩm →` và nút phụ `⚡ Tư vấn cấu hình theo tầm giá`. | Cụm từ `GIÁ CỰC TỐT` bị tô màu xanh cyan; mô tả dài 3 dòng; nhãn nút khác ảnh A. | **ĐÃ SỬA:** Đưa H1 về màu trắng đồng nhất `#FFFFFF`; rút gọn mô tả 2 dòng; cập nhật đúng văn bản và icon 2 nút hành động như Ảnh A. |
| **04** | **Hero Spotlight (Thanh sản phẩm tiêu điểm đáy Banner)** | Gọn nhẹ trên 1 hàng có đường kẻ mảnh ngăn trên: bên trái là ảnh nhỏ `48x48px` + nhãn xanh `Cấu hình tiêu biểu đang mở bán` + tên máy 1 dòng; bên phải chỉ có giá gốc gạch ngang nằm trên giá bán màu đỏ cam `#F87171` cỡ lớn. | Đóng khung hộp dày, hiện thêm dòng SKU/bảo hành dài 2 dòng, giá màu cyan và thêm nút `Xem ngay` màu cyan gây chật chội. | **ĐÃ SỬA:** Bỏ nút `Xem ngay` cyan và dòng mô tả phụ dài; trình bày giá gốc phía trên và giá bán màu đỏ cam `#F87171` phía dưới bên phải đúng như Ảnh A. |
| **05** | **Hero Promo Cards (3 thẻ bên phải 4 cột)** | Mỗi thẻ gồm: hàng trên là pill nhãn màu (`Ưu đãi Hot`, `Xả kho Laptop`, `Độc quyền Online`) bên trái + icon nhỏ góc phải trên; ở giữa là tiêu đề đậm + mô tả 2 dòng; dưới cùng là link xanh. Không có ảnh vuông tối màu bên phải. | Chèn 3 ảnh `.webp` nền tối kích thước `78x78px` bên phải mỗi thẻ làm bố cục khác hẳn Ảnh A. | **ĐÃ SỬA:** Bỏ ảnh thumbnail tối màu bên phải; đặt `.promo-card-top` gồm pill nhãn trái + icon góc phải trên, tiêu đề, mô tả 2 dòng và link điều hướng khớp Ảnh A. |
| **06** | **4 Thẻ Cam Kết (Value Proposition Cards)** | Icon đặt góc trái trên (`align-items: flex-start`), tiêu đề đậm 2 dòng và mô tả chi tiết 2–3 dòng tạo chiều cao thẻ cân đối. | Icon căn giữa, mô tả chỉ có 1 dòng ngắn làm thẻ bị dẹt. | **ĐÃ SỬA:** Căn `flex-start`, mở rộng nội dung mô tả trung thực 2–3 dòng đúng tỉ lệ chiều cao của Ảnh A. |
| **07** | **Luồng Section giữa Cam kết và Flash Sale** | Từ 4 Thẻ Cam Kết đi thẳng xuống khối `GIỜ VÀNG GIÁ SỐC`. | Chèn thêm cả một Section `DANH MỤC MÁY TÍNH & LINH KIỆN CŨ` gồm 6 thẻ chữ (2 hàng) không có trong Ảnh A. | **ĐÃ SỬA:** Loại bỏ Section 6 thẻ danh mục rời rạc khỏi Trang chủ để luồng thị giác khớp 100% với Ảnh A. |
| **08** | **Khung Flash Sale (`GIỜ VÀNG GIÁ SỐC`)** | Khung nền trắng viền xám nhạt `#E2E8F0` tinh tế; thanh tiêu đề gồm badge đỏ `GIỜ VÀNG GIÁ SỐC`, chỉ số bên trái và link `Xem tất cả... ›` bên phải. | Khung viền đỏ đậm `2px`; tiêu đề thêm dòng chữ lớn hoặc hiển thị 3 ô số cách nhau bởi dấu `:` dễ gây nhầm là đồng hồ đếm ngược. | **ĐÃ SỬA (Prompt 12.3):** Đổi viền khung sang `#E2E8F0`; bỏ hoàn toàn 3 ô đếm ngược giả lập `14 : 15 : 18` và thay bằng dải chỉ số có nhãn rõ ràng (`.flash-metric-pills`): `Giảm trực tiếp theo giá niêm yết` • `Đang mở bán: 14 sản phẩm` • `Sẵn kho: 15 máy theo Serial`. |
| **09** | **Card Sản Phẩm trong Flash Sale (4 cột)** | Khung ảnh lớn (`aspect-ratio: 16/11`), dòng danh mục nhỏ màu xám, tên sản phẩm 2 dòng, **không có bảng thông số 4 ô**, giá đỏ + giá gốc gạch ngang, thanh trạng thái đỏ mảnh và **1 nút xanh dương full-width `🛍️ Mua ngay giá sốc`**. | Dùng chung mẫu card với PC Gaming nên bị nhồi thêm `Mã SKU`, bảng thông số 4 ô (`stitch-spec-grid`) và 2 nút (`Xem chi tiết` + `🛒`), làm card quá dài và rối chữ. | **ĐÃ SỬA:** Xây dựng hàm `$renderFlashSaleCard()` riêng: khung ảnh `16:11`, ẩn dòng SKU và bảng 4 ô thông số, dùng 1 nút xanh dương full-width `🛍️ Mua ngay giá sốc` như Ảnh A. Chỉ gắn nhãn `✓ Đã kiểm định 18B` cho sản phẩm có biên bản kiểm định thực tế. |
| **10** | **Section PC Gaming Cũ Tuyển Chọn (4 cột) & Nhãn Kiểm Định** | Tiêu đề có dòng nhãn nhỏ `🖥️ CẤU HÌNH CÂN MỌI TỰA GAME` phía trên `PC Gaming Cũ Tuyển Chọn – Đã Kiểm Tra Kỹ Càng`; bên phải là các pill lọc bo tròn. Card có 2 pill nhỏ dưới ảnh, tên 2 dòng, hộp thông số 2x2 gọn nhẹ, hàng giá + nhãn phụ bên phải, dưới cùng là 2 nút `Xem chi tiết` (trắng viền xám) và `🛒 Thêm vào giỏ` (xanh dương có chữ). | Tiêu đề in hoa có vạch dọc xanh; card hiện thêm dòng `Mã SKU` thừa; dùng dấu tích `✓ Quản lý Serial` cho cả máy chưa có biên bản kiểm định; chiều cao hộp cấu hình và hộp tóm tắt lệch nhau vài pixel. | **ĐÃ SỬA (Prompt 12.3):** Chỉ hiển thị tích xanh `✓ Đã kiểm định 18B` khi `$hasInspection === true` (`PC Gaming TL-01`); 2 máy chưa có biên bản kiểm định (`TL-02`, `TL-03`) hiển thị nhãn trung tính `Quản lý theo Serial` (không có dấu `✓`). Đồng bộ `min-height: 54px` giúp cả 3 thẻ cao đúng `470.17px`. |
| **11** | **Khối Đôi Laptop & Linh Kiện (Bento 2 cột)** | Cả cột trái (Laptop) và cột phải (Linh kiện) đều nằm trong **2 khung thẻ trắng lớn có viền và bo góc `16px`** có cùng chiều cao (`6 cột + 6 cột`). Cột trái hiển thị 3 thẻ Laptop ngang (nút `Chọn mua` màu xanh dương đậm). Cột phải hiển thị **lưới 2x2 gồm 4 thẻ thông tin linh kiện gọn nhẹ** (không có ảnh đen lớn) + thanh kêu gọi tư vấn ở đáy. | Hai cột không có khung thẻ trắng bao ngoài; cột trái hiện 4 laptop với nút `Chọn mua` màu xám nhạt; cột phải hiện 7 thẻ có ảnh đen lớn kéo dài gấp đôi cột trái, làm lệch hẳn bố cục trang. | **ĐÃ SỬA:** Bọc mỗi cột trong khung thẻ trắng `.stitch-bento-box` cân xứng (6 cột + 6 cột); cột trái hiển thị 3 Laptop ngang với nút `Chọn mua` xanh dương `#0051D5`; cột phải hiển thị lưới 2x2 (4 linh kiện tiêu biểu) theo đúng thiết kế thẻ chữ gọn của Ảnh A + hộp tư vấn ở đáy. |
| **12** | **Section Quy Trình Kiểm Định 18 Bước** | Tiêu đề và mô tả căn trái (`MINH BẠCH TUYỆT ĐỐI` + `Quy Trình Kiểm Định Linh Kiện 18 Bước Tại TL GAMING`); bên dưới là 4 thẻ bước `01` – `04` trên nền tối hài hòa. | Tiêu đề căn giữa; chèn thêm bảng nền trắng khổng lồ bên trong khối Navy làm khối tối bị kéo dài quá mức. | **ĐÃ SỬA:** Căn trái tiêu đề & mô tả như Ảnh A; giữ 4 thẻ bước `01` – `04` kết hợp dải pill hồ sơ Serial thực tế từ MySQL (`.stitch-qa-verified-strip`) đồng bộ màu tối gọn gàng ngay bên dưới. |
| **13** | **Section Đánh Giá & Footer 4 Cột** | Tiêu đề `Đánh Giá Thực Tế Từ Cộng Đồng Gamer` (Title Case, không có vạch dọc xanh), bên phải có cụm trạng thái sao. Footer 4 cột có logo `TL GAMING` + pill xanh `CŨ ZIN 100%`, 6 ô phương thức thanh toán (lưới 3x2) và hộp chứng nhận nền xanh lá nhạt. | Tiêu đề Đánh giá in hoa kèm vạch xanh; Footer cột 1 có icon vuông xanh thừa, cột 4 chỉ có 4 ô thanh toán và hộp chứng nhận màu xanh dương. | **ĐÃ SỬA:** Chuẩn hóa tiêu đề Đánh giá và thiết kế trạng thái trống 3 cột hài hòa (không bịa review giả); sửa Footer cột 1 (`TL GAMING` + pill `CŨ ZIN 100%`) và cột 4 (lưới 3x2 gồm 6 pill thanh toán + hộp chứng nhận xanh lá nhạt `#F0FDF4`). |

---

## 3. Danh Sách Tệp Đã Chỉnh Sửa

1. `docs/UI_VISUAL_AUDIT.md` — Tài liệu phân tích đối chiếu thị giác A/B ở viewport `1440px`, danh sách 13 hạng mục đã sửa, danh sách tài nguyên ảnh còn thiếu và báo cáo khác biệt còn lại.
2. `app/Views/layouts/main.php` — Chỉnh sửa Header (Topbar, Logo SVG tay cầm gaming, Thanh tìm kiếm + từ khóa gợi ý 1 dòng, vạch ngăn dọc cụm tiện ích phải, Menu ngang 1 dòng) và Footer 4 cột (`TL GAMING` + `CŨ ZIN 100%`, lưới 3x2 pill thanh toán, hộp chứng nhận xanh lá nhạt).
3. `app/Views/home/index.php` — Tái cấu trúc toàn bộ các Section của Trang chủ khớp Ảnh A (Hero 2 cột, 4 thẻ Cam kết, Flash Sale `GIỜ VÀNG GIÁ SỐC`, PC Gaming 4 cột, Khối đôi Bento Laptop ngang & Linh kiện 2x2, Quy trình kiểm định 18 bước căn trái, Trạng thái trống 3 cột cho Đánh giá khách hàng).
4. `public/assets/css/style.css` — Cập nhật hệ thống biến CSS (`:root`), kích thước `.container` (`1360px`), khoảng cách, màu sắc, card sản phẩm và bố cục Responsive cho Desktop/Tablet/Mobile.

---

## 4. Danh Sách Tài Nguyên Ảnh Sản Phẩm Thực Tế Còn Thiếu

Qua kiểm tra toàn bộ kho mã nguồn, thư mục `public/uploads/products/` hiện chỉ chứa 23 tệp `.webp` dạng đồ họa sơ đồ kỹ thuật (blueprint schematic) được sinh tự động khi tạo dữ liệu mẫu, **chưa có ảnh chụp sản phẩm thực tế (photographic studio/real-life photos)**. Tuân thủ đúng Quy tắc 8 (Giai đoạn 3), hệ thống giữ nguyên đường dẫn ảnh khớp từng sản phẩm trong MySQL (không tự gán ảnh sai sản phẩm) và liệt kê đầy đủ 23 tệp ảnh cần bổ sung ảnh chụp thực tế:

1. `public/uploads/products/pc-gaming-01.webp` — Ảnh đại diện PC Gaming TL-01 Core i5-12400F / RTX 2060S
2. `public/uploads/products/pc-01-main.webp` — Ảnh chi tiết chính PC Gaming TL-01
3. `public/uploads/products/pc-01-side.webp` — Ảnh góc nghiêng PC Gaming TL-01
4. `public/uploads/products/pc-gaming-02.webp` — Ảnh đại diện PC Gaming TL-02 Ryzen 5 5600X / RTX 3060
5. `public/uploads/products/pc-02-main.webp` — Ảnh chi tiết chính PC Gaming TL-02
6. `public/uploads/products/pc-gaming-03.webp` — Ảnh đại diện PC Đồ Họa Core i7-10700 / GTX 1660 Ti
7. `public/uploads/products/dell-g15.webp` — Ảnh đại diện Laptop Dell Gaming G15 5515
8. `public/uploads/products/dell-g15-main.webp` — Ảnh chi tiết chính Laptop Dell Gaming G15 5515
9. `public/uploads/products/dell-g15-keyboard.webp` — Ảnh bàn phím Laptop Dell Gaming G15 5515
10. `public/uploads/products/asus-tuf-f15.webp` — Ảnh đại diện Laptop Asus TUF Gaming F15 FX506HC
11. `public/uploads/products/asus-f15-main.webp` — Ảnh chi tiết chính Laptop Asus TUF Gaming F15
12. `public/uploads/products/legion-5.webp` — Ảnh đại diện Laptop Lenovo Legion 5 15ACH6
13. `public/uploads/products/acer-nitro-5.webp` — Ảnh đại diện Laptop Acer Nitro 5 Eagle AN515-57
14. `public/uploads/products/vga-rog-strix-2060s.webp` — Ảnh đại diện VGA Asus ROG Strix RTX 2060 Super 8GB
15. `public/uploads/products/vga-2060s-main.webp` — Ảnh chi tiết chính VGA Asus ROG Strix RTX 2060 Super
16. `public/uploads/products/vga-msi-3070.webp` — Ảnh đại diện VGA MSI Gaming X Trio RTX 3070 8GB
17. `public/uploads/products/vga-3070-main.webp` — Ảnh chi tiết chính VGA MSI Gaming X Trio RTX 3070
18. `public/uploads/products/cpu-i5-12400f.webp` — Ảnh đại diện CPU Intel Core i5-12400F Tray Cũ
19. `public/uploads/products/cpu-r7-5700x.webp` — Ảnh đại diện CPU AMD Ryzen 7 5700X Tray Cũ
20. `public/uploads/products/man-hinh-asus-vg249q.webp` — Ảnh đại diện Màn hình Asus TUF Gaming VG249Q1A 24"
21. `public/uploads/products/mon-asus-main.webp` — Ảnh chi tiết chính Màn hình Asus VG249Q1A
22. `public/uploads/products/man-hinh-samsung-g5.webp` — Ảnh đại diện Màn hình Samsung Odyssey G5 27"
23. `public/uploads/products/ram-kingston-8gb.webp` — Ảnh đại diện RAM Desktop Kingston Fury Beast 8GB DDR4

---

## 5. Báo Cáo Những Khác Biệt Còn Lại So Với Ảnh A (Do Ràng Buộc Dữ Liệu Thực Tế)

1. **Số lượng card trong Section PC Gaming (3 card trên lưới 4 cột):**
   - Trong Ảnh A có 4 card PC Gaming. Tuy nhiên, trong CSDL MySQL (`database/seed_tl_gaming.sql`) danh mục `pc-gaming-cu` hiện chỉ có **3 sản phẩm mở bán**. Tuân thủ nghiêm ngặt yêu cầu *"PC Gaming: dựng lưới 4 cột như Stitch khi viewport đủ rộng; nếu MySQL chỉ có 3 sản phẩm thì không tự tạo card thứ tư"*, hệ thống giữ lưới 4 cột và hiển thị đúng 3 sản phẩm thực tế (ô cột thứ 4 để trống tự nhiên).
2. **Hình ảnh đại diện sản phẩm (`.webp`):**
   - Ảnh A sử dụng ảnh chụp studio nền sáng của case PC và laptop, trong khi kho dữ liệu hiện tại của dự án đang dùng bộ ảnh `.webp` sơ đồ kỹ thuật nền tối. Khung chứa ảnh đã được chỉnh về đúng tỉ lệ `16:11` nền `#F4F6FB` của Ảnh A để khi thay thế các tệp `.webp` bằng ảnh chụp thật sẽ khớp ngay lập tức.
3. **Thông tin chi tiết cấu hình trên Card PC Gaming thứ 2 và thứ 3:**
   - Trong dữ liệu seed MySQL hiện tại, bảng `product_specs` mới có bản ghi thông số chi tiết (CPU, VGA, RAM, SSD) cho sản phẩm PC đầu tiên (`PC Gaming TL-01`), còn 2 sản phẩm PC thứ 2 và thứ 3 chưa có bản ghi trong `product_specs` nên hiển thị đoạn tóm tắt `summary` thực tế từ bảng `products` với cùng chiều cao khung (`min-height: 54px`).
4. **Chỉ số hiển thị tại thanh tiêu đề khối `GIỜ VÀNG GIÁ SỐC` và thanh tiến độ đỏ:**
   - Do CSDL chưa có cột thời gian kết thúc chiến dịch Flash Sale thực tế, hệ thống đã bỏ hoàn toàn đồng hồ đếm ngược giả lập (`HH : MM : SS`) để tránh gây hiểu nhầm và thay bằng các chỉ số có nhãn rõ ràng (`Giảm trực tiếp theo giá niêm yết`, `Đang mở bán: 14 sản phẩm`, `Sẵn kho: 15 máy theo Serial`) cùng thanh lượng tồn kho thực tế (`SẴN KHO: CÒN X SẢN PHẨM`).
5. **Khối Đánh Giá Thực Tế Từ Cộng Đồng Gamer:**
   - Do CSDL chưa có bảng/dữ liệu đánh giá khách hàng thực tế, thay vì bịa đặt 3 đánh giá giả kèm điểm `4.9/5 (1.420 đánh giá)` như ảnh minh họa tĩnh, hệ thống trình bày lưới 3 thẻ trạng thái trống hài hòa đúng bố cục 3 cột của Ảnh A và dẫn chiếu sang hồ sơ kiểm định 18 bước theo mã Serial.
