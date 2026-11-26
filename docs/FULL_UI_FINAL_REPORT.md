# BÁO CÁO NGHIỆM THU CUỐI CÙNG — FULL WEBSITE UI/UX AUDIT, REDESIGN & FINAL POLISH (PROMPT 24)

- **Dự án:** Website thương mại điện tử bán máy tính & linh kiện cũ **TL Gaming**
- **Kiến trúc:** PHP 8 thuần, MySQL/MariaDB PDO, MVC tự xây dựng, HTML5/CSS3/JavaScript thuần (không framework, không ORM, không thư viện UI ngoài).
- **Ngày nghiệm thu:** 25/11/2026
- **Cơ sở dữ liệu kiểm thử:** `tl_gaming_test` cô lập (tuyệt đối không ghi dữ liệu thử nghiệm vào database thật `tl_gaming`).

---

## 1. Tổng quan số liệu kiểm thử & cải thiện

| Chỉ số | Giá trị đo thực tế |
| --- | --- |
| **Tổng số trang / tuyến đường đã kiểm tra** | **21 tuyến đường thực tế** (11 Storefront + 10 Admin Portal) |
| **Tổng số cặp Trang × Viewport kiểm tra trên Chrome thật** | **43 cặp** trải đều trên **13 kích thước màn hình** (`320`, `360`, `375`, `390`, `414`, `430`, `768`, `820`, `1024`, `1280`, `1440`, `1920px` và Landscape `812×375`) |
| **Tổng số nhóm lỗi UI/UX phát hiện trong Audit** | **8 nhóm lỗi** (`UI-AUD-01` → `UI-AUD-08`) |
| **Tổng số lỗi đã khắc phục & xác minh PASS** | **8/8 (100%)** |
| **Lỗi nghiêm trọng còn tồn tại** | **0** |
| **Chiều dài Trang chủ Mobile (`375×812`)** | Giảm từ **`8,862px`** xuống **`6,513px`** (giảm **`2,349px` / 26.5%**) |
| **Lỗi tràn ngang / Console JS / HTTP >= 400 / Ảnh vỡ** | **0 / 43** cặp kiểm tra |
| **Bộ kiểm thử hồi quy nghiệp vụ (11 suites)** | **230 / 230 PASS** (`0 FAIL`) |

---

## 2. Danh sách các tệp đã thêm mới / chỉnh sửa

1. `app/Views/partials/product_card.php` *(Tạo mới)* — Component Product Card thống nhất toàn Storefront.
2. `config/contact.php` *(Cập nhật)* — Tập trung cấu hình Hotline, Email, Zalo, Facebook, Messenger và cờ `show_unconfigured_channels`.
3. `.env.example` *(Cập nhật)* — Tài liệu hóa các biến liên hệ `TL_FACEBOOK_URL`, `TL_SHOW_UNCONFIGURED_CHANNELS`.
4. `app/Views/partials/floating_contact_widget.php` *(Cập nhật)* — Đầy đủ 5 icon SVG (Zalo, Facebook, Messenger, Hotline, Back to Top) + nút an toàn cho kênh chưa cấu hình.
5. `app/Views/home/index.php` *(Cập nhật)* — Áp dụng Unified Product Card cho cả 4 section sản phẩm, đổi icon Cam kết sang SVG, chuyển Quy trình kiểm định 18 bước thành Accordion trên Mobile, rút gọn khối Minh bạch đánh giá.
6. `app/Views/products/index.php` *(Cập nhật)* — Chuyển lưới danh sách sản phẩm sang Unified Product Card.
7. `app/Views/products/show.php` *(Cập nhật)* — Chuyển khu vực Cấu hình tương tự sang Unified Product Card.
8. `app/Views/layouts/main.php` *(Cập nhật)* — Bổ sung Accordion cho 3 nhóm liên kết Footer Mobile (giữ đủ 100% liên kết pháp lý/chính sách) và liên kết cấu hình liên hệ tập trung.
9. `public/assets/css/style.css` *(Cập nhật)* — Hoàn thiện Unified Design System, CSS cho Unified Product Card, QA Accordion, Footer Accordion, Floating Contact Widget 5 nút + Toast thông báo.
10. `public/assets/css/admin.css` *(Cập nhật)* — Đồng bộ tông xanh chủ đạo `--adm-blue: #2454d9` và nền `--adm-bg: #f5f7fa`.
11. `public/assets/js/app.js` *(Cập nhật)* — Bổ sung xử lý thông báo kênh liên hệ chưa cấu hình (`#fcwStatusToast`), QA Accordion và Footer Accordion.

---

## 3. Kết quả kiểm thử Chrome thật (43 cặp Trang × Viewport)

- **13 kích thước trên Trang chủ (`/`):** `320×640`, `360×800`, `375×812`, `390×844`, `414×896`, `430×932`, `768×1024`, `820×1180`, `1024×768`, `1280×800`, `1440×900`, `1920×1080`, `812×375` (Landscape) — **13/13 PASS**.
- **Storefront đa trang:** `/san-pham` (`320`, `375`, `768`, `1440`), `/san-pham?q=RTX`, `/san-pham?gia-tu=5000000&gia-den=15000000`, `/san-pham/{slug}` (`375`, `768`, `1440`), `/dang-nhap` (`375`, `1440` + Tab Đăng ký), `/gio-hang` có sản phẩm (`375`), `/thanh-toan` có sản phẩm (`320`, `375`, `390`), `/tra-cuu-don-hang` (`375`), `/trang-khong-ton-tai-404` (`375`) — **17/17 PASS**.
- **Admin Portal:** `/admin`, `/admin/danh-muc`, `/admin/san-pham`, `/admin/serial`, `/admin/serial/1/kiem-dinh/them`, `/admin/don-hang`, `/admin/khach-hang`, `/admin/voucher` trên Desktop `1440×900` và Mobile `375×812` — **13/13 PASS**.
- **Tổng hợp Chrome CDP:** **`43/43 PASS`** (`issuesCount = 0`).

---

## 4. Kết quả kiểm thử tương tác thực tế trên trình duyệt

| Kịch bản tương tác | Kết quả | Bằng chứng |
| --- | --- | --- |
| Floating Contact Widget Desktop (5 nút SVG) | **PASS** | `zalo: true`, `facebook: true`, `messenger: true`, `hotline: "tel:19008868"`, `topVisible: true` |
| Bấm kênh chưa cấu hình (Zalo) | **PASS** | Hiện toast `"Zalo: Kênh liên hệ đang được cập nhật."`, không điều hướng sai, không lỗi JS |
| Floating Contact Widget Mobile (thu gọn ↔ mở rộng ↔ `Escape`) | **PASS** | Bung đủ 4 kênh phía trên Bottom Nav, đóng bằng nút `×` / bấm ngoài / phím `Escape` |
| Back to Top (`scrollY >= 320px`) | **PASS** | Ẩn ở `0px`, hiện khi cuộn `600px`, bấm cuộn mượt về `0px` |
| Footer Mobile Accordion (`.footer-acc-trigger`) | **PASS** | `aria-expanded`: `"false"` → `"true"`, không che Bottom Navigation |
| Quy trình kiểm định 18 bước Accordion (`.qa-acc-trigger`) | **PASS** | `aria-expanded`: `"false"` → `"true"`, mở đủ thông tin hạng mục kiểm định thật |
| Menu Drawer Mobile, Bộ lọc, Giỏ hàng (SP thật), Checkout | **PASS** | Hoạt động chính xác, Checkout ẩn Widget & Bottom Nav, không chồng lấn |
| Kiểm tra cú pháp PHP (`php -l`) & JS (`node --check`) | **PASS** | 7/7 tệp PHP và `app.js` / `admin.js` hợp lệ |

---

## 5. Kết quả hồi quy 11 bộ kiểm thử nghiệp vụ (`tl_gaming_test`)

| STT | Bộ kiểm thử | Kết quả |
| --- | --- | --- |
| 1 | `scripts/run_admin_category_tests.php --confirm-test-db` | **24 / 24 PASS** |
| 2 | `scripts/run_admin_product_tests.php --confirm-test-db` | **31 / 31 PASS** |
| 3 | `scripts/run_admin_serial_tests.php --confirm-test-db` | **34 / 34 PASS** |
| 4 | `scripts/run_serial_concurrency_tests.php --confirm-test-db` | **11 / 11 PASS** |
| 5 | `scripts/run_regression_tests.php` | **12 / 12 PASS** |
| 6 | `scripts/run_cart_tests.php --confirm-test-db` | **14 / 14 PASS** |
| 7 | `scripts/run_checkout_tests.php --confirm-test-db` | **37 / 37 PASS** |
| 8 | `scripts/run_admin_order_tests.php --confirm-test-db` | **32 / 32 PASS** |
| 9 | `scripts/run_admin_customer_coupon_tests.php --confirm-test-db` | **17 / 17 PASS** |
| 10 | `scripts/run_admin_dashboard_tests.php --confirm-test-db` | **9 / 9 PASS** |
| 11 | `scripts/run_final_e2e_integration_tests.php --confirm-test-db` | **9 / 9 PASS** |
| **TỔNG CỘNG** | **11 / 11 Suites** | **230 / 230 PASS (0 FAIL)** |

---

## 6. Bảng trạng thái trung thực (PASS / FAIL / NOT RUN / N/A)

| Hạng mục | Trạng thái | Ghi chú |
| --- | --- | --- |
| Design System thống nhất PC/Tablet/Mobile | **PASS** | Cùng 1 bộ CSS Variables và font `Be Vietnam Pro` |
| Unified Product Card trên toàn Storefront | **PASS** | 1 partial `product_card.php` dùng cho Home, Catalog, Search & Similar |
| Floating Contact Widget 5 nút + thông báo kênh chưa cấu hình | **PASS** | Xác minh trực tiếp cả Desktop & Mobile trên Chrome thật |
| Trang chủ gọn hơn, QA 18 bước & Footer Mobile Accordion | **PASS** | Giảm `2,349px` chiều dài Mobile Home, đủ thông tin thật |
| Hồi quy 11 bộ kiểm thử nghiệp vụ & Admin | **PASS** | `230/230 PASS`, Admin giữ nguyên đầy đủ chức năng |
| Route Tin tức / Blog hoặc Khuyến mãi độc lập | **N/A** | Không tồn tại trong `routes/web.php` (Khuyến mãi trỏ về `/#san-pham-noi-bat`) |
| Mở trực tiếp link Zalo / Facebook / Messenger thật | **NOT RUN** | Đang chờ chủ website điền URL thật vào `.env` theo `docs/CONTACT_CONFIGURATION_GUIDE.md` |
| Kiểm thử trên điện thoại vật lý thật (iPhone Safari / Android Chrome) | **NOT RUN** | Giới hạn môi trường chỉ có Chrome Desktop qua CDP Mobile & Touch Emulation |

---

## 7. Hạn chế khi kiểm thử bằng Chrome Emulation

Chrome CDP Mobile Emulation mô phỏng chính xác viewport, DPR `2x`, touch event, media queries và layout DOM, nhưng **không thay thế hoàn toàn** kiểm thử trên thiết bị vật lý thật về:
1. Hành vi co giãn chiều cao khi bàn phím ảo (iOS/Android) bật lên trên biểu mẫu Checkout và Đăng nhập.
2. Thanh địa chỉ trình duyệt tự ẩn/hiện động (`100dvh`) và hiệu ứng cuộn quán tính Safari iOS.
3. Khả năng mở ứng dụng gọi điện/Zalo/Messenger bản địa khi bấm liên kết `tel:` và `https://zalo.me/...`.

---

## 8. Thư mục ảnh chụp bàn giao (`docs/screenshots/full-ui-final/`)

- **`before/`** (6 ảnh trước sửa): `home_desktop_1440.png`, `home_mobile_375.png`, `home_mobile_375_full.png`, `products_mobile_375.png`, `product_detail_mobile_375.png`, `login_mobile_375.png`.
- **`after/desktop/`** (4 ảnh): `home_desktop_1440.png`, `products_desktop_1440.png`, `product_detail_desktop_1440.png`, `login_desktop_1440.png`.
- **`after/tablet/`** (2 ảnh): `home_tablet_768.png`, `products_tablet_768.png`.
- **`after/mobile/`** (12 ảnh): `home_mobile_375.png`, `home_mobile_375_full.png`, `products_mobile_375.png`, `product_detail_mobile_375.png`, `login_mobile_375.png`, `register_mobile_375.png`, `cart_mobile_375.png`, `checkout_mobile_375.png`, `order_lookup_mobile_375.png`, `footer_mobile_375.png`, `menu_drawer_mobile_375.png`, `bottom_nav_mobile_375.png`.
- **`contact-widget/`** (4 ảnh): `contact_widget_desktop.png`, `contact_widget_mobile_collapsed.png`, `contact_widget_mobile_expanded.png`, `contact_widget_unconfigured_notice.png`.
- **`admin/`** (5 ảnh): `admin_dashboard_1440.png`, `admin_products_1440.png`, `admin_serial_1440.png`, `admin_inspection_1440.png`, `admin_orders_1440.png`.

---

## 9. Bổ sung Prompt 24 — Loại bỏ khối thống kê & sắp xếp sản phẩm trên Storefront

- **Phạm vi kiểm tra:** Quét toàn bộ `app/Views/**/*.php`; chỉ duy nhất `app/Views/products/index.php` chứa khối `.catalog-toolbar` (dòng `"Hiển thị X–Y / Z sản phẩm (Trang ...)"`, nhóm nút `"Sắp xếp theo:"` và dropdown `#sortSelect` `"Mới nhất"`).
- **Xử lý sạch từ HTML & CSS:**
  - Gỡ bỏ hoàn toàn `<div class="catalog-toolbar">...</div>` cùng biến `$sortLabels` / `$buildSortUrl` thừa trong `app/Views/products/index.php` và xóa CSS `.catalog-toolbar`, `.toolbar-summary`, `.stitch-sort-pills`, `.sort-select` trong `public/assets/css/style.css`.
  - Giữ lại duy nhất nút mở bộ lọc `#mobileFilterToggleBtn` (icon SVG thuần) trên Tablet & Mobile (`<= 1024px`, ẩn hoàn toàn trên Desktop `> 1024px`) để khách vẫn lọc theo từ khóa, danh mục, khoảng giá và tình trạng tồn kho.
  - Đưa lưới sản phẩm lên sát ngay dưới tiêu đề danh mục / nút lọc, không để lại khoảng trống thừa.
  - Giữ nguyên toàn bộ logic sắp xếp mặc định mới nhất (`moi-nhat`) và tham số URL trong `ProductController` / `ProductModel`.
- **Xác minh thực tế sau chỉnh sửa:** `320px`, `375px`, `768px`, `1440px` trên `/san-pham` đều **PASS** (`43/43` cặp Chrome CDP `0` lỗi, E2E HTTP `9/9 PASS`), ảnh chụp cập nhật tại `after/mobile/products_mobile_375.png`, `after/tablet/products_tablet_768.png`, `after/desktop/products_desktop_1440.png`.

---

## 10. Hoàn thiện Prompt 24 — Breakpoint bộ lọc 1024/1025px, Widget Mobile không che CTA & Kiểm thử chuyên sâu Danh mục

### 10.1. Chỉnh sửa đã thực hiện
1. **Breakpoint bộ lọc `1024px` (`public/assets/css/style.css`, `public/assets/js/app.js`):**
   - Chuyển riêng các quy tắc thu gọn bộ lọc danh mục (`.catalog-layout`, `.catalog-sidebar`, `.catalog-sidebar.is-open`, `.sidebar-close-btn`, `.mobile-filter-trigger`) từ `@media (max-width: 900px)` sang `@media (max-width: 1024px)`. Giữ nguyên quy tắc trang chi tiết, giỏ hàng và đăng nhập tại `@media (max-width: 900px)`.
   - Bổ sung listener `window.matchMedia('(min-width: 1025px)')` trong `app.js` để tự đồng bộ lại `.is-open` và `aria-expanded="false"` khi chuyển kích thước vượt mốc `1024/1025px`.
2. **Sửa Floating Contact Widget che nút mua trên Mobile (`public/assets/css/style.css`):**
   - Ở trạng thái thu gọn trên Mobile (`<= 768px`), thu gọn vùng `.fcw-channels` về `width: 0` và đặt nút mở liên hệ bám sát mép phải (`right: 0`, dạng tab rộng `34px`, cao `44px`, bo góc trái `12px 0 0 12px`).
   - Kết quả đo hình học trên Chrome thật: `overlapAreaPx = 0` tại cả `320px`, `375px` và `430px` (`fcwRect.left > ctaRect.right`), nút `"Xem & Mua ngay"` cột phải hiển thị trọn vẹn chữ và bấm điều hướng bình thường ở mọi vị trí cuộn. Khi mở rộng (`.is-expanded`), vẫn hiển thị đầy đủ 4 kênh Zalo, Facebook, Messenger, Hotline + Back to Top + thông báo `"Zalo: Kênh liên hệ đang được cập nhật."`.
3. **Dọn JavaScript sắp xếp thừa (`public/assets/js/app.js`):**
   - Xóa hoàn toàn listener `#sortSelect` / `#catalogSortForm` đã bỏ và cập nhật comment đầu tệp; giữ nguyên nút chọn nhanh khoảng giá (`.quick-price-btn`), bộ lọc và logic sắp xếp trong `ProductController` / `ProductModel`.

### 10.2. Kết quả kiểm thử Responsive & Resize qua mốc 1024 / 1025px

| Kích thước | Bố cục `.catalog-layout` | Sidebar ban đầu | Nút Bộ lọc | Mở / Đóng & `aria-expanded` | Tràn ngang | Kết quả |
| --- | --- | --- | --- | --- | --- | --- |
| `320px` | 1 cột | `none` | `inline-flex` | `false → true → false` | Không | **PASS** |
| `375px` | 1 cột | `none` | `inline-flex` | `false → true → false` | Không | **PASS** |
| `768px` | 1 cột | `none` | `inline-flex` | `false → true → false` | Không | **PASS** |
| `900px` | 1 cột | `none` | `inline-flex` | `false → true → false` | Không | **PASS** |
| `901px` | 1 cột | `none` | `inline-flex` | `false → true → false` | Không | **PASS** |
| `1024px` | 1 cột | `none` | `inline-flex` | `false → true → false` | Không | **PASS** |
| `1025px` | 2 cột (Sidebar trái) | `block` | `none` | N/A (Sidebar luôn mở) | Không | **PASS** |
| `1440px` | 2 cột (Sidebar trái) | `block` | `none` | N/A (Sidebar luôn mở) | Không | **PASS** |

- **Resize qua mốc `1024 ↔ 1025px`:**
  - Mở bộ lọc tại `1024px` (`sidebar=block`, `aria=true`) → đổi sang `1025px` (`sidebar=block`, `btn=none`, `aria=false`) → quay về `1024px` (`sidebar=none`, `btn=inline-flex`, `aria=false`): **PASS**.
  - Đóng bộ lọc tại `1024px` → đổi sang `1025px` (`sidebar=block`, `btn=none`): **PASS**.

### 10.3. Kết quả kiểm thử Widget liên hệ & CTA cột phải trên Mobile (`320`, `375`, `430px`)

| Ca kiểm thử | Dữ liệu đo thực tế | Kết quả |
| --- | --- | --- |
| `320px` — Không che CTA & cuộn bấm CTA | `ctaRect.right=285 < fcwRect.left=286` (`overlapAreaPx=0`), `scrollCtaHitOk=true`, điều hướng tới `/san-pham/man-hinh-asus-tuf-vg249q-24-inch-144hz` | **PASS** |
| `375px` — Không che CTA cột phải & cuộn bấm CTA | `ctaRect.right=340 < fcwRect.left=341` (`overlapAreaPx=0`), `hitIsCta=true`, `noOverlapBottomNav=true`, bấm CTA điều hướng đúng | **PASS** |
| `430px` — Không che CTA cột phải & cuộn bấm CTA | `ctaRect.right=395 < fcwRect.left=396` (`overlapAreaPx=0`), `hitIsCta=true`, `noOverlapBottomNav=true`, bấm CTA điều hướng đúng | **PASS** |
| Product Detail `375px` — Không chồng Sticky Buy Bar | `fcwBottom=740 <= stickyBarTop=751`, mở rộng đủ 4 kênh + Back to Top + toast `"Zalo: Kênh liên hệ đang được cập nhật."` | **PASS** |

### 10.4. Đối chiếu Bộ lọc, Phân trang & Sắp xếp với CSDL `tl_gaming_test` (15 sản phẩm `published`, ID `1..15`)

| Ca kiểm thử | Kỳ vọng (đối chiếu SQL `tl_gaming_test`) | Kết quả thực tế trên trình duyệt | Trạng thái |
| --- | --- | --- | --- |
| Mặc định `/san-pham` (`moi-nhat`) | Trang 1 trả 12 SP mới nhất (`ID 15 → 4`): đầu tiên là `#15 PC Gaming E2E RTX 4060`, `#14 RAM Kingston Fury Beast`, `#13 Samsung Odyssey G5` | 12 SP, 3 SP đầu khớp chính xác `#15`, `#14`, `#13` | **PASS** |
| Lọc từ khóa `?q=RTX` | 8 SP chứa RTX (`#15, #9, #8, #7, #5, #4, #2, #1`) | 8 SP khớp đúng 8 tên sản phẩm | **PASS** |
| Lọc danh mục `?danh-muc=pc-gaming-cu` | 3 SP (`#3, #2, #1`), `<= 12` → ẩn thanh phân trang | 3 SP khớp đúng, `hasPagination = false` | **PASS** |
| Lọc khoảng giá `?gia-tu=5000000&gia-den=15000000` | 6 SP (`15M, 7.8M, 12.2M, 13.9M, 9.8M, 11.5M`) | 6 SP khớp đúng toàn bộ mức giá | **PASS** |
| Lọc tồn kho `?ton-kho=in_stock` (14 SP `>= 13`) | Trang 1: 12 SP (`#14 → #3`), Trang 2: 2 SP (`#2 PC Gaming TL-02`, `#1 PC Gaming TL-01`), không trùng, URL giữ `ton-kho=in_stock` | Trang 1: 12 SP; bấm `Sau` → `/san-pham?ton-kho=in_stock&page=2` (2 SP `#2, #1`, `duplicate=0`), nút `Trước` trỏ `/san-pham?ton-kho=in_stock` | **PASS** |
| Đổi lọc từ Trang 2 (`q=RTX` khi đang ở trang 2 `ton-kho=in_stock`) | Quay về Trang 1 (không mang `page=2`), trả 7 SP RTX còn hàng (`#9,#8,#7,#5,#4,#2,#1`) | URL `/san-pham?q=RTX&gia-tu=&gia-den=&danh-muc=&ton-kho=in_stock`, `hasPage2=false`, 7 SP | **PASS** |
| Lọc kết hợp `?danh-muc=pc-gaming-cu&gia-tu=10000000&ton-kho=in_stock` | 2 SP (`#2: 15.8M`, `#1: 11.5M`, loại `#3: 9.8M`) | 2 SP khớp chính xác `#2` và `#1` | **PASS** |
| Không có kết quả `?q=KHONGTONTAI99999` & Xóa bộ lọc | 0 SP, hiện thông báo trống + bấm Xóa lọc quay về `/san-pham` (12 SP trang 1) | 0 SP, thông báo `"Không tìm thấy sản phẩm nào phù hợp"`, bấm xóa về `/san-pham` (12 SP) | **PASS** |
| Sắp xếp qua URL `?sap-xep=gia-tang&ton-kho=in_stock` | Giá tăng dần không giảm qua cả Trang 1 (12 SP: `450k → 15.8M`) & Trang 2 (2 SP: `16.8M, 19.5M`), giữ `sap-xep=gia-tang` khi chuyển trang & khi gửi form lọc `q=RTX` (8 SP: `4.6M → 19.5M`) | `isStrictlyNonDecreasing=true`, Trang 2 URL `/san-pham?ton-kho=in_stock&sap-xep=gia-tang&page=2` (`16.8M, 19.5M`), gửi form lọc giữ `sap-xep=gia-tang` với 8 giá tăng dần | **PASS** |
| Kiểm tra cú pháp & E2E | `node --check app.js`, `php -l main.php`, `run_final_e2e_integration_tests.php --confirm-test-db` | `0` lỗi cú pháp, `0` lỗi Console/HTTP, E2E **9/9 PASS** | **PASS** |
| Kiểm thử trên điện thoại vật lý thật | — | **NOT RUN** (chỉ chạy Chrome Desktop CDP Emulation) | **NOT RUN** |

### 10.5. Ảnh chụp bằng chứng bổ sung (`docs/screenshots/full-ui-final/`)
- `after/mobile/products_mobile_375.png` (375px — widget bám mép phải 0px chồng lên nút mua cột phải)
- `after/tablet/products_tablet_1024.png` (1024px — bố cục 1 cột + nút Bộ lọc tìm kiếm)
- `after/desktop/products_desktop_1025.png` (1025px — bố cục 2 cột với Sidebar bộ lọc trái, ẩn nút mở bộ lọc)
- `after/mobile/products_page2_filtered_375.png` & `after/desktop/products_page2_filtered_1440.png` (Trang 2 khi đang lọc `?ton-kho=in_stock&page=2`)
