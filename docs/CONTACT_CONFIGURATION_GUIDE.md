# HƯỚNG DẪN CẤU HÌNH THÔNG TIN LIÊN HỆ & FLOATING CONTACT WIDGET — TL GAMING

Tài liệu hướng dẫn chủ website cập nhật thông tin **Hotline, Email, Zalo, Facebook và Messenger** tại một nơi duy nhất mà không cần sửa code giao diện HTML/CSS/JS.

---

## 1. Tệp cấu hình nằm ở đâu?

- **Tệp cấu hình PHP trung tâm:** `config/contact.php`
- **Tệp biến môi trường (ưu tiên chỉnh sửa tại đây):** `.env` tại thư mục gốc dự án (`e:\xampp\htdocs\tl-gaming\.env`) — tham khảo mẫu tại `.env.example`.
- **Component hiển thị widget:** `app/Views/partials/floating_contact_widget.php`

Mọi vị trí trên website (**Header Topbar**, **Footer**, **Mobile Drawer**, **Bottom Navigation**, **Trang Đăng nhập** và **Floating Contact Widget**) đều tự động đọc từ `config/contact.php`.

---

## 2. Cách cập nhật từng kênh liên hệ (chỉnh trong `.env`)

Mở tệp `.env` và cập nhật các dòng sau:

```dotenv
# 1. Hotline hiển thị và số gọi tel:
TL_HOTLINE_DISPLAY="1900 8868"
TL_HOTLINE_TEL="19008868"
TL_HOTLINE_HOURS="08:30 – 21:00"

# 2. Email hỗ trợ khách hàng
TL_SUPPORT_EMAIL="hotro@tlgaming.vn"

# 3. Kênh Zalo (chọn 1 trong 2 cách: điền URL Zalo OA/Cá nhân HOẶC điền số điện thoại Zalo)
TL_ZALO_URL="https://zalo.me/0912345678"
# hoặc để trống TL_ZALO_URL và điền:
TL_ZALO_PHONE=""

# 4. Đường dẫn Fanpage Facebook chính thức
TL_FACEBOOK_URL="https://www.facebook.com/tlgamingvn"

# 5. Đường dẫn Messenger chính thức (m.me hoặc messenger.com)
TL_MESSENGER_URL="https://m.me/tlgamingvn"

# 6. Bật/tắt hiển thị icon đối với các kênh CHƯA điền thông tin thật
#    true  = Vẫn hiện icon đẹp, bấm vào hiện thông báo "Kênh liên hệ đang được cập nhật."
#    false = Ẩn hoàn toàn các icon chưa có URL/SĐT hợp lệ
TL_SHOW_UNCONFIGURED_CHANNELS=true
```

---

## 3. Quy tắc kiểm tra bảo mật URL tự động trong `config/contact.php`

Hệ thống tự động kiểm tra định dạng trước khi render thẻ `<a href="...">` (chống link lỗi hoặc XSS):
- **Hotline (`TL_HOTLINE_TEL`):** Tự động lọc chỉ giữ chữ số và dấu `+`, tạo `tel:...`.
- **Zalo (`TL_ZALO_URL` / `TL_ZALO_PHONE`):** Chấp nhận `https://zalo.me/...`, `https://oa.zalo.me/...` hoặc số điện thoại `9–15` chữ số (tự tạo `https://zalo.me/<số>`).
- **Facebook (`TL_FACEBOOK_URL`):** Chỉ chấp nhận `https://facebook.com/...`, `https://www.facebook.com/...`, `https://m.facebook.com/...` hoặc `https://fb.com/...`.
- **Messenger (`TL_MESSENGER_URL`):** Chỉ chấp nhận `https://m.me/...` hoặc `https://www.messenger.com/...`.
- Tất cả liên kết mở tab mới đều tự động gắn `target="_blank" rel="noopener noreferrer"`.

---

## 4. Cách bật / tắt từng icon

- **Muốn bật hoạt động ngay cho một kênh:** Điền URL/SĐT hợp lệ tương ứng trong `.env` → icon tự chuyển từ `<button>` thông báo sang thẻ `<a>` dẫn trực tiếp tới kênh thật.
- **Muốn ẩn các kênh chưa cấu hình:** Đặt `TL_SHOW_UNCONFIGURED_CHANNELS=false` trong `.env`.
- **Muốn tạm ẩn riêng 1 kênh đã cấu hình:** Xóa trống giá trị của biến môi trường tương ứng (ví dụ `TL_FACEBOOK_URL=""`) và đặt `TL_SHOW_UNCONFIGURED_CHANNELS=false`.

---

## 5. Cách kiểm tra sau khi cập nhật

1. Lưu tệp `.env` và tải lại trang chủ bằng trình duyệt (`Ctrl + F5`).
2. **Trên Desktop:** Di chuột vào từng nút góc phải dưới màn hình (Zalo, Facebook, Messenger, Hotline) để kiểm tra tooltip, bấm thử để xác nhận mở đúng trang đích hoặc trình gọi điện `tel:`.
3. **Trên Mobile:** Bấm nút liên hệ tròn màu xanh góc phải (trên thanh Bottom Navigation) để bung đủ 4 kênh, kiểm tra từng nút và bấm dấu `×` (hoặc bấm ra ngoài) để đóng.
4. Cuộn xuống quá `320px` để xác nhận nút **Lên đầu trang (Back to Top)** xuất hiện và cuộn mượt về đầu trang.
