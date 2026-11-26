# BÁO CÁO KIỂM THỬ & RÀ SOÁT CHECKOUT — TL GAMING

- **Ngày thực hiện:** 2026-11-14
- **Môi trường:** PHP 8.5.12 (CLI & Built-in Server), MariaDB 10.11.16 (`tl_gaming_test` cô lập)
- **Phạm vi:** Rà soát lần cuối phân hệ Checkout: thống nhất xác minh Idempotency (`actor_hash`, `payload_hash`) ở cả nhánh ngoài và nhánh trong Transaction (`SELECT ... FOR UPDATE`), dọn giỏ từng phần sau `commit()` (bảo toàn sản phẩm mới thêm & số lượng thay đổi đồng thời, không dọn giỏ khi retry), và kiểm thử hồi quy toàn diện.

---

## 1. TỔNG HỢP KẾT QUẢ KIỂM THỬ

| Bộ kiểm thử | Lệnh chạy | Kết quả |
| :--- | :--- | :--- |
| **Checkout & Concurrency Tests** | `E:\xampp\php\php.exe scripts/run_checkout_tests.php --confirm-test-db` | **PASS: 37 / 37** (`FAIL: 0`) |
| **Cart Regression Tests** | `E:\xampp\php\php.exe scripts/run_cart_tests.php --confirm-test-db` | **PASS: 14 / 14** (`FAIL: 0`) |
| **Auth & Security Regression Tests** | `E:\xampp\php\php.exe scripts/run_regression_tests.php --confirm-test-db` | **PASS: 12 / 12** (`FAIL: 0`) |
| **Kiểm tra cú pháp PHP (`php -l`)** | `OrderModel.php`, `run_checkout_tests.php` | **PASS** |
| **Kiểm tra Responsive trình duyệt thực tế** | Chưa mở lại trình duyệt sau lần sửa này | **NOT RUN** |

---

## 2. CHI TIẾT 37 KỊCH BẢN CHECKOUT (`scripts/run_checkout_tests.php`)

| Mã TC | Mô tả kịch bản | Trạng thái | Bằng chứng thực tế |
| :---: | :--- | :---: | :--- |
| `TC01` | Guest checkout COD thành công | **PASS** | Tạo đơn `TLG-20261113-1436B2`, `user_id = NULL`. |
| `TC02` | Customer đặt đơn thành công | **PASS** | Tạo đơn `TLG-20261113-F95A3F`, `user_id = 3`. |
| `TC03` | Customer checkout Chuyển khoản ngân hàng (khi `.env` có cấu hình) | **PASS** | `payment_method = bank_transfer`, `payments.status = pending`. |
| `TC04` | Giỏ hàng rỗng không cho mở `/thanh-toan` | **PASS** | `HTTP 302 -> /gio-hang`. |
| `TC05` | Sản phẩm hết hàng bị chặn tạo đơn (`CheckoutException`) | **PASS** | `outOfStockBlocked = yes`. |
| `TC06` | Sản phẩm trạng thái `draft`/`archived` bị chặn tạo đơn | **PASS** | `archivedBlocked = yes`. |
| `TC07` | Sản phẩm có Serial (`ID=8`) giữ máy `reserved` & gán `unit_serial_number` | **PASS** | `unit_status = reserved`, `serial = TLG-VGA-2060S-SN501`. |
| `TC08` | Sản phẩm quản lý số lượng (`ID=14`) trừ kho chính xác | **PASS** | Tồn kho giảm `10 -> 8`. |
| `TC09` | Hai khách đặt cùng một Serial đồng thời -> chỉ 1 thành công | **PASS** | `successCount = 1/2`. |
| `TC10` | Hai khách đặt vượt tồn kho số lượng đồng thời -> 1 thành công, kho không âm | **PASS** | `successCount = 1/2`, `remainingStock14 = 3`. |
| `TC11` | Voucher hợp lệ (`TLGAMING500K`) giảm đúng 500.000đ & ghi `coupon_usages` | **PASS** | `discount_amount = 500000`. |
| `TC12` | Voucher hết hạn (`EXPIRED2025`) bị từ chối | **PASS** | Thông báo hết hạn chính xác. |
| `TC13` | Voucher hết lượt bị từ chối | **PASS** | Từ chối đúng điều kiện. |
| `TC14` | Hai khách tranh chấp lượt voucher cuối cùng đồng thời -> chỉ 1 thành công | **PASS** | `successCount = 1/2`, `usage_count = 1`. |
| `TC15` | Giá sản phẩm thay đổi khi đang ở Checkout -> chặn & yêu cầu xem lại | **PASS** | Không tạo đơn (`orders_user3: 1 -> 1`). |
| `TC16` | Phí vận chuyển & giá bị sửa trên trình duyệt bị Server bỏ qua | **PASS** | Server tính `total = 4600000`, `shipping_fee = 50000`. |
| `TC17` | Gửi `payment_status=paid` bị Server bỏ qua, luôn khởi tạo `pending` | **PASS** | `payments.status = pending`. |
| `TC18` | `POST /thanh-toan` sai CSRF token bị chặn | **PASS** | `HTTP 403`. |
| `TC19` | Double submit cùng `idempotency_key` chỉ tạo 1 đơn duy nhất | **PASS** | Cả 2 lần trả về cùng 1 mã đơn `TLG-20261113-1436B2`. |
| `TC20` | Lỗi SQL giữa Transaction rollback toàn bộ & tách biệt lỗi kỹ thuật | **PASS** | `stock: 8 -> 8`, `orders: 7 -> 7`, `isTechErr = yes` (không lộ `SQLSTATE`). |
| `TC21` | Rollback không làm mất giỏ hàng của khách | **PASS** | `cart_items_user3 = 1`. |
| `TC22` | Chỉ xóa giỏ hàng sau khi `commit()` thành công | **PASS** | Giỏ hàng rỗng sau khi commit. |
| `TC23` | Tra cứu đơn hợp lệ bằng mã đơn + SĐT | **PASS** | Hiển thị đúng đơn `TLG-20261113-1436B2`. |
| `TC24` | Chống IDOR xem đơn người khác & tra cứu sai SĐT/email | **PASS** | `directIdorHTTP = 302 -> /tra-cuu-don-hang`, tra cứu sai bị chặn. |
| `TC25` | Chưa cấu hình TK ngân hàng trong `.env` -> UI ẩn & Server từ chối `bank_transfer` | **PASS** | `uiHidesBank = yes`, `orders: 15 -> 15`. |
| `TC26` | Đơn hàng `0đ` (voucher giảm 100% + `shipping_fee = 0`) ghi nhận `payments.amount = 0`, `status = pending` | **PASS** | `final = 0`, `pay_amount = 0`, `status = pending`. |
| `TC27` | Đơn hàng `1đ` và `> 0đ` ghi nhận đúng số tiền thực tế | **PASS** | `final_1d = 1`, `pay_1d = 1`. |
| `TC28` | Phục hồi Idempotency bền vững từ MySQL khi PHP ngắt sau commit rồi gửi lại | **PASS** | Trả về đúng đơn `TLG-20261113-A058CA` (`dup = yes`), không tạo đơn thứ 2. |
| `TC29` | Từ chối `idempotency_key` giả mạo / chưa được Server cấp phát | **PASS** | `forgedRejected = yes`. |
| `TC30` | Từ chối tái sử dụng `idempotency_key` cũ cho giỏ hàng / payload khác | **PASS** | `reusedPayloadRejected = yes`. |
| `TC31` | Hai request đồng thời gửi CÙNG `idempotency_key` -> chỉ tạo 1 đơn trong MySQL | **PASS** | `ordersCreated = 1`, `workerOk = 1/2`. |
| `TC32` | `RateLimiter` tra cứu đơn hàng chặn sau 5 lần sai (chống đổi mã đơn né limit) | **PASS** | `HTTP 429`. |
| `TC33` | Serial có biên bản cũ `failed` + mới nhất `passed` được bán; mới nhất `failed` bị chặn | **PASS** | Tạo đơn `TLG-20261114-E7BBC9` cho máy đã pass lại; máy `failed` mới nhất bị chặn (`latestFailBlocked = yes`). |
| `TC34` | Token không thuộc tác nhân hiện tại (chưa commit & đã commit) bị từ chối | **PASS** | `uncommittedBlocked = yes`, `committedCrossActorBlocked = yes`. |
| `TC35` | Thêm sản phẩm mới vào giỏ khi đặt hàng -> chỉ loại bỏ SP đã commit, giữ SP mới (Guest & Customer) | **PASS** | `guestKept13 = yes`, `custKept13 = yes`. |
| `TC36` | Cùng sản phẩm thay đổi số lượng trong hai phiên -> chỉ trừ đúng số lượng đã commit (`5 - 2 = 3`) | **PASS** | `guestLeft = 3`, `custLeft = 3`. |
| `TC37` | Retry đơn cũ không làm mất giỏ mới & không trừ kho lần 2 | **PASS** | `stock: 8 -> 8`, `keptNewItem13 = 1`. |
