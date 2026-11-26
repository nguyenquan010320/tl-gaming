# BÁO CÁO ĐỒNG BỘ DESIGN SYSTEM & FLOATING CONTACT WIDGET (PROMPT 23.2)

- **Dự án:** Website thương mại điện tử bán máy tính cũ **TL Gaming**
- **Công nghệ:** PHP 8 thuần, MySQL/MariaDB PDO, MVC tự xây dựng, HTML5/CSS3/JavaScript thuần (không Bootstrap, Tailwind, React, Vue, không plugin chat bên thứ ba).
- **Ngày hoàn thành:** 25/11/2026
- **Phạm vi:** Đồng bộ hoàn toàn Design System (màu sắc, font chữ, Header nền trắng + Menu xanh thương hiệu, khoảng cách, card, badge) giữa Desktop và Mobile; bổ sung **Floating Contact Widget** + nút **Back to Top** dùng chung cấu hình liên hệ tập trung; giữ nguyên bố cục Mobile của Prompt 23.1, giữ nguyên Admin, cơ sở dữ liệu và toàn bộ nghiệp vụ Auth / Cart / Checkout.

---

## 1. Các tệp mã nguồn đã thêm / chỉnh sửa

| Tệp | Loại thay đổi | Mô tả chi tiết |
| --- | --- | --- |
| `config/contact.php` | **Tạo mới** | Cấu hình thông tin liên hệ tập trung (`hotline`, `email`, `zalo`, `messenger`) đọc từ `.env`; kiểm tra chặt URL hợp lệ và mặc định tắt Zalo/Messenger khi chưa có dữ liệu thật. |
| `.env.example` | **Cập nhật** | Bổ sung tài liệu các biến môi trường `TL_HOTLINE_*`, `TL_SUPPORT_EMAIL`, `TL_ZALO_URL`, `TL_ZALO_PHONE`, `TL_MESSENGER_URL`. |
| `app/Views/partials/floating_contact_widget.php` | **Tạo mới** | Component liên hệ nổi dùng chung cho Storefront (Zalo, Messenger, Hotline, Back to Top) dùng SVG thuần, tự ẩn kênh chưa cấu hình. |
| `app/Views/layouts/main.php` | **Cập nhật** | Nạp `config/contact.php` dùng chung cho Topbar, Footer, Mobile Drawer, Bottom Navigation; tinh chỉnh nút tìm kiếm ngắn (`Tìm`), điểm nhấn nút `Gọi ngay` và nhúng `floating_contact_widget.php`. |
| `app/Views/auth/login.php` | **Cập nhật** | Đọc hotline hỗ trợ từ `config/contact.php` thay vì hardcode trực tiếp. |
| `public/assets/css/style.css` | **Cập nhật** | Hợp nhất bộ CSS Variables Design System dùng chung PC & Mobile, chuyển Desktop Header sang nền trắng + thanh danh mục màu thương hiệu `#2454D9`, bổ sung CSS cho Floating Contact Widget (Desktop & Mobile thu gọn), kiểm soát z-index/safe-area và tinh chỉnh chi tiết Mobile (Phần X). |
| `public/assets/js/app.js` | **Cập nhật** | Điều khiển đóng/mở Floating Contact Widget trên Mobile (bấm nút, bấm ngoài, phím `Escape`) và nút Back to Top (chỉ hiện khi cuộn `>= 320px`, hỗ trợ `prefers-reduced-motion`). |

---

## 2. Bảng màu chính thức (Unified Design System)

Tất cả màu sắc được tập trung duy nhất tại `:root` trong `public/assets/css/style.css` và ánh xạ thống nhất sang các biến Stitch hiện có (không khai báo trùng lặp xung đột):

| Token CSS | Mã màu | Vai trò sử dụng (PC & Mobile) |
| --- | --- | --- |
| `--color-primary` | `#2454D9` | Màu chủ đạo TL Gaming (Nút chính, thanh danh mục PC, mục active Bottom Nav, link) |
| `--color-primary-dark` | `#183783` | Trạng thái hover / nhấn nút chính |
| `--color-primary-light` | `#EAF2FF` | Nền badge, nền hover nhẹ, nền phụ nổi bật |
| `--color-bg` | `#F5F7FA` | Nền trang chính thống nhất 100% trên cả Desktop (`rgb(245, 247, 250)`) và Mobile (`rgb(245, 247, 250)`) |
| `--color-surface` | `#FFFFFF` | Nền Header PC/Mobile, nền Card sản phẩm, nền Form & Bottom Navigation |
| `--color-text` | `#0F172A` | Chữ tiêu đề và nội dung chính (Xanh Navy đậm) |
| `--color-text-muted` | `#64748B` | Chữ phụ, mô tả, giá cũ gạch ngang |
| `--color-border` | `#DCE4EE` | Viền thẻ, viền thanh tìm kiếm và đường kẻ ngăn cách |
| `--color-price` | `#DC2626` | Giá bán chính & badge giảm giá / số lượng giỏ hàng |
| `--color-success` | `#16A34A` | Trạng thái còn hàng, kiểm định đạt, màu nút Hotline |
| `--color-warning` | `#D97706` | Trạng thái cảnh báo |
| `--color-zalo` | `#0068FF` | Nút Zalo (khi được cấu hình hợp lệ) |
| `--color-messenger` | `#1877F2` | Nút Facebook Messenger (khi được cấu hình hợp lệ) |

**Bo góc & Đổ bóng chuẩn:** `--radius-sm: 8px`, `--radius-md: 12px`, `--radius-lg: 16px`, `--shadow-sm: 0 2px 6px rgba(15, 23, 42, 0.05)`, `--shadow-md: 0 4px 16px rgba(15, 23, 42, 0.08)`.

---

## 3. Font chữ chính thức

- **Font gia đình duy nhất:** `"Be Vietnam Pro", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif` (biến `--font-family`, hỗ trợ đầy đủ dấu tiếng Việt với các mức weight `400`, `500`, `600`, `700`, `800` và fallback hệ thống không phụ thuộc hoàn toàn vào CDN).
- **Kế thừa toàn diện:** `body`, `button`, `input`, `select`, `textarea` đều sử dụng `var(--font-family)`.
- **Xác minh thực tế bằng `getComputedStyle(document.body).fontFamily` trên Chrome:**
  - Desktop `1440×900`: `"Be Vietnam Pro", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif`
  - Mobile `375×812`: `"Be Vietnam Pro", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif`
  - Kết quả so khớp: **`identical = true`**.

---

## 4. Cấu hình thông tin liên hệ tập trung (`config/contact.php`)

- **Hotline:** `1900 8868` (`tel:19008868`), khung giờ `08:30 – 21:00` — xác nhận từ mã nguồn thực tế, sử dụng đồng bộ tại Header Topbar, Footer, Mobile Drawer, Bottom Navigation và Floating Contact Widget.
- **Email hỗ trợ:** `hotro@tlgaming.vn` (`mailto:hotro@tlgaming.vn`).
- **Zalo:** Đã chuẩn bị đầy đủ logic kiểm tra URL `https://zalo.me/...` / `https://oa.zalo.me/...` hoặc SĐT qua `.env` (`TL_ZALO_URL`, `TL_ZALO_PHONE`) và SVG nhận diện `#0068FF`. Hiện đang **tự động ẩn (`enabled = false`)** vì chưa có tài khoản Zalo thực tế.
- **Facebook Messenger:** Đã chuẩn bị đầy đủ logic kiểm tra URL `https://m.me/...` / `messenger.com` qua `.env` (`TL_MESSENGER_URL`) và SVG nhận diện `#1877F2`. Hiện đang **tự động ẩn (`enabled = false`)** vì chưa có Fanpage thực tế.

---

## 5. Kết quả kiểm tra Floating Contact Widget & Back to Top

| Hạng mục kiểm tra | Kết quả | Bằng chứng đo thực tế trên Chrome |
| --- | --- | --- |
| Hotline `tel:` hợp lệ | **PASS** | `href="tel:19008868"` |
| Không tạo liên kết Zalo / Messenger giả | **PASS** | `zaloRendered = false`, `messengerRendered = false` khi chưa cấu hình `.env` |
| Back to Top ẩn khi ở đầu trang | **PASS** | `topBtnHiddenInitially = true` (`scrollY = 0`) |
| Back to Top hiện khi cuộn `>= 320px` & cuộn mượt về đầu trang | **PASS** | `topBtnVisibleAfterScroll = true`, sau khi bấm `scrollAfterTopClick = 0` |
| Mobile thu gọn mặc định & bung/đóng mượt (bấm nút / `Escape`) | **PASS** | `expanded`: `"false"` → click → `"true"` (`aria-expanded="true"`) → `Escape` → `"false"` |
| Không chồng Bottom Navigation (`375×812`) | **PASS** | `fcwBottom = 736px <= navTop = 750px` (cách thoáng `14px`) |
| Không chồng Sticky Buy Bar trên Chi tiết sản phẩm (`375×812`) | **PASS** | `fcwBottom = 736px <= barTop = 751px` (cách thoáng `15px`) |
| Ẩn trên trang Checkout (`/thanh-toan`) | **PASS** | `checkoutWidgetHidden = true` |
| Không hiển thị trong khu vực Admin (`/admin`) | **PASS** | `adminHasWidget = false` |

---

## 6. So sánh Desktop & Mobile trước và sau Prompt 23.2

- **Header:** Trước Prompt 23.2, Desktop sử dụng Header nền tối Deep Navy `#141C2B` trong khi Mobile dùng nền trắng `#FFFFFF`. Sau Prompt 23.2, **cả Desktop và Mobile đều đồng bộ Header nền trắng (`rgb(255, 255, 255)`)**, logo xanh dương `#2454D9`, chữ Navy `#0F172A`, thanh tìm kiếm viền xám nhạt, nút tìm kiếm màu xanh thương hiệu và badge giỏ hàng màu đỏ `#DC2626`. Thanh danh mục ngang Desktop dùng màu xanh thương hiệu `#2454D9`.
- **Liên hệ & Điều hướng:** Bổ sung Floating Contact Widget góc phải + Back to Top cho cả PC và Mobile (thu gọn gọn gàng trên Mobile), đồng bộ màu sắc và nút `Gọi ngay` nổi bật trên Bottom Navigation.
- **Chi tiết Mobile:** Rút gọn nút tìm kiếm thành `Tìm`, căn chỉnh badge giỏ hàng không che icon, chuẩn hóa chiều cao card cam kết & ô ngân sách (`Trên 20 triệu` xếp dọc rõ ràng).

---

## 7. Kết quả kiểm thử Responsive (10 kích thước màn hình trên Chrome thật)

Tất cả **20 lượt kiểm tra** trên đủ 10 kích thước (`320px`, `360px`, `375px`, `390px`, `414px`, `430px`, `768px`, `1024px`, `1440px`, `1920px`) đều đạt **PASS**:
- `issuesCount = 0` (`overflowX = false` trên 20/20 trang × viewport).
- `0` lỗi Console JavaScript, `0` lỗi tải tài nguyên / HTTP >= 400, `0` ảnh vỡ.

---

## 8. Kết quả kiểm thử hồi quy & Cú pháp

- `php -l` (`config/contact.php`, `app/Views/partials/floating_contact_widget.php`, `app/Views/layouts/main.php`, `app/Views/auth/login.php`): **PASS (0 lỗi cú pháp)**.
- `node --check public/assets/js/app.js`: **PASS**.
- **E2E HTTP Integration Tests trên `tl_gaming_test`:** **9/9 PASS** (`E2E-C1..C5`, `E2E-A1..A4`).
- **Admin Dashboard Regression (`1440×900`):** Giữ nguyên hoàn toàn giao diện Google Stitch Admin, không có Floating Contact Widget (`admin_dashboard_regression.png`).

---

## 9. Bảng tổng hợp PASS / FAIL / NOT RUN

| Hạng mục | Trạng thái | Ghi chú |
| --- | --- | --- |
| Đồng bộ CSS Variables & Font chữ PC/Mobile | **PASS** | Xác minh qua `getComputedStyle()` trên Chrome thật |
| Đồng bộ Header Desktop nền trắng + Menu xanh thương hiệu | **PASS** | Xác minh trực quan tại `home_desktop_1440.png` |
| Floating Contact Widget (Desktop & Mobile thu gọn/mở rộng) | **PASS** | Hotline `tel:19008868`, không chồng Bottom Nav/Sticky Bar, ẩn ở Checkout & Admin |
| Back to Top (`scrollY >= 320px`, smooth scroll) | **PASS** | Kiểm thử cuộn xuống `600px` và bấm trở về `0px` |
| Responsive 10 kích thước (`320px` – `1920px`) | **PASS** | `20/20` lượt kiểm tra, `0` tràn ngang, `0` lỗi JS/HTTP |
| Hồi quy E2E HTTP & Admin | **PASS** | `9/9` E2E PASS trên `tl_gaming_test`, Admin không bị ảnh hưởng |
| Hiển thị trực tiếp nút Zalo & Messenger với tài khoản thật | **NOT RUN** | Đã sẵn sàng code & validation; đang ẩn do chủ website chưa cung cấp URL Zalo/Messenger thật |
| Kiểm thử trực tiếp trên thiết bị điện thoại vật lý (iOS/Android) | **NOT RUN** | Môi trường hiện tại kiểm thử bằng Google Chrome thật qua CDP Mobile & Touch Emulation |

---

## 10. Thông tin liên hệ còn thiếu cần chủ website cung cấp

Để kích hoạt đầy đủ 2 nút **Zalo** và **Facebook Messenger** trên Floating Contact Widget, chủ website chỉ cần điền thông tin thật vào tệp `.env` (không cần sửa code PHP/HTML/CSS):

1. **Tài khoản Zalo OA hoặc Số điện thoại Zalo tư vấn chính thức:**
   - Điền `TL_ZALO_URL="https://zalo.me/..."` (hoặc `TL_ZALO_PHONE="09xxxxxxxx"`).
2. **Đường dẫn Facebook Messenger (m.me) chính thức của Fanpage TL Gaming:**
   - Điền `TL_MESSENGER_URL="https://m.me/..."`.
3. *(Tùy chọn)* Xác nhận lại Hotline (`TL_HOTLINE_DISPLAY` / `TL_HOTLINE_TEL`), giờ làm việc (`TL_HOTLINE_HOURS`) và email hỗ trợ (`TL_SUPPORT_EMAIL`) nếu muốn thay đổi khỏi mặc định hiện tại (`1900 8868`, `08:30 – 21:00`, `hotro@tlgaming.vn`).

---

## 11. Danh sách 13 ảnh chụp bằng chứng (`docs/screenshots/unified-design/`)

1. `home_desktop_1440.png`
2. `home_mobile_375.png`
3. `products_desktop_1440.png`
4. `products_mobile_375.png`
5. `product_detail_desktop_1440.png`
6. `product_detail_mobile_375.png`
7. `login_desktop_1440.png`
8. `login_mobile_375.png`
9. `contact_widget_desktop.png`
10. `contact_widget_mobile_collapsed.png`
11. `contact_widget_mobile_expanded.png`
12. `checkout_mobile_375.png`
13. `admin_dashboard_regression.png`
