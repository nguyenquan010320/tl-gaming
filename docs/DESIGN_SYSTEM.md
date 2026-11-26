# TL GAMING — DESIGN SYSTEM CHÍNH THỨC (PC, TABLET & MOBILE)

Tài liệu quy chuẩn hệ thống thiết kế giao diện thống nhất cho toàn bộ Storefront **TL Gaming** (CSS3 thuần tại `public/assets/css/style.css`, không framework).

---

## 1. Bảng màu chính thức (`:root` CSS Variables)

| Token CSS | Mã màu | Vai trò & Quy tắc sử dụng |
| --- | --- | --- |
| `--color-primary` | `#2454D9` | Màu thương hiệu chính TL Gaming (Nút chính, thanh danh mục Desktop, mục active Bottom Nav, liên kết) |
| `--color-primary-dark` | `#183783` | Trạng thái hover / active của nút chính |
| `--color-primary-light` | `#EAF2FF` | Nền badge thông tin, nền icon hộp cam kết, nền hover nhẹ |
| `--color-bg` | `#F5F7FA` | Nền trang chính thống nhất 100% trên cả Desktop & Mobile |
| `--color-surface` | `#FFFFFF` | Nền Header, Card sản phẩm, Card form, Drawer & Bottom Navigation |
| `--color-surface-subtle` | `#F8FAFC` | Nền khung ảnh sản phẩm, hộp thông số ngắn & ô tìm kiếm |
| `--color-text` | `#0F172A` | Văn bản chính & tiêu đề |
| `--color-text-secondary` | `#475569` | Văn bản nội dung mô tả |
| `--color-text-muted` | `#64748B` | Văn bản phụ, nhãn meta, giá gốc gạch ngang |
| `--color-border` | `#DCE4EE` | Viền thẻ, viền thanh điều hướng, đường kẻ phân tách |
| `--color-price` | `#DC2626` | Giá bán hiện tại, badge giảm giá, badge đếm giỏ hàng |
| `--color-success` | `#16A34A` | Còn hàng, đã kiểm định 18 bước, nút Hotline |
| `--color-warning` | `#D97706` | Cảnh báo trạng thái |
| `--color-danger` | `#DC2626` | Lỗi validation, hủy đơn, hết hàng |
| `--color-zalo` | `#0068FF` | Nút nổi Zalo |
| `.fcw-btn-facebook` | `#1877F2` | Nút nổi Facebook |
| `.fcw-btn-messenger` | `linear-gradient(135deg, #0099ff, #6f42ff)` | Nút nổi Messenger |

---

## 2. Hệ thống Typography

- **Font gia đình duy nhất:** `var(--font-family)` = `"Be Vietnam Pro", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif`.
- **Áp dụng:** `body`, `button`, `input`, `select`, `textarea` đều kế thừa chung `--font-family`.
- **Trọng lượng chữ:** `400` (Regular), `500` (Medium), `600` (Semibold), `700` (Bold), `800` (ExtraBold cho giá & tiêu đề lớn).
- **Kích thước chuẩn:**
  - Nội dung chính: `14–16px`, `line-height: 1.55`.
  - Input / Select / Textarea trên Mobile (`<= 768px`): tối thiểu `16px` (chống iOS Safari auto-zoom).
  - Tên sản phẩm trên Card: `0.92rem` (Desktop) / `0.82rem` (Mobile), giới hạn tối đa 2 dòng (`-webkit-line-clamp: 2`).
  - Giá bán: `1.08rem` (Desktop) / `0.94rem` (Mobile), đậm `800`, màu `--color-price`.
  - Tiêu đề Section: `1.32rem` (Desktop) / `1.06rem` (Mobile).

---

## 3. Khoảng cách, Bo góc & Đổ bóng

- **Thang khoảng cách:** `4px`, `8px`, `12px`, `16px`, `20px`, `24px`, `32px`.
- **Bo góc:** `--radius-sm: 8px` (nút, ô thông số), `--radius-md: 12px` (card sản phẩm, ô tìm kiếm), `--radius-lg: 16px` (khối section, card form).
- **Đổ bóng:** `--shadow-sm: 0 2px 6px rgba(15, 23, 42, 0.05)`, `--shadow-md: 0 4px 16px rgba(15, 23, 42, 0.08)`.
- **Vùng chạm cảm ứng (Mobile):** Nút biểu tượng Header, nút đóng Drawer, nút Floating Contact Widget, nút Accordion tối thiểu `44 × 44px`.

---

## 4. Kiến trúc Component chuẩn

1. **Unified Product Card (`app/Views/partials/product_card.php`):**
   - Khung ảnh `aspect-ratio: 16 / 11` (`object-fit: contain`), badge giảm giá/kiểm định 18B, danh mục, tên 2 dòng, tối đa 3 thông số kỹ thuật ưu tiên (CPU/VGA/RAM/SSD/Màn hình), giá đỏ + giá gốc gạch ngang, pill bảo hành/tồn kho và nút CTA căn đáy bằng Flexbox.
   - Responsive Grid: Desktop `4 cột` (Home) / `3 cột` (Catalog), Tablet `2–3 cột`, Mobile `360–768px` `2 cột`, Mobile `320px` `1 cột`.
2. **Header & Navigation:**
   - Desktop (`> 768px`): Topbar sáng `#f1f5f9` + Main Header trắng `#FFFFFF` + Thanh danh mục màu thương hiệu `#2454D9`.
   - Mobile (`<= 768px`): Header trắng gọn `~107px` (Hamburger + Logo + Giỏ hàng + Tài khoản + Thanh tìm kiếm full-width), Drawer danh mục trượt trái, Bottom Navigation 5 mục (`62px + safe-area`).
3. **Accordion Components (Mobile `<= 768px`):**
   - Khối Quy trình kiểm định 18 bước (`.qa-acc-trigger`) và 3 nhóm liên kết Footer (`.footer-acc-trigger`) sử dụng `<button type="button" aria-expanded>` hỗ trợ chuột, chạm và bàn phím; tự mở đầy đủ trên Desktop.
4. **Kiểm soát tầng Z-Index & Khoảng trống an toàn:**
   - Sticky Header: `z-index: 900–1000`
   - Floating Contact Widget: `z-index: 880` (dưới Header/Drawer/Lightbox), toast thông báo `z-index: 1050`
   - Bottom Navigation / Sticky Buy Bar: `z-index: 950–960`
   - Mobile Drawer Backdrop / Drawer: `z-index: 1150 / 1200`
   - Biến safe-area: `--mobile-bottom-nav-height: 64px`, `--mobile-sticky-buy-height: 64px`, `--safe-area-bottom: env(safe-area-inset-bottom, 0px)`.
