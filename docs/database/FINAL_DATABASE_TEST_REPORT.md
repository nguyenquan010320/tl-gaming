# BÁO CÁO HOÀN THIỆN & KIỂM THỬ CƠ SỞ DỮ LIỆU CUỐI CÙNG (FINAL DATABASE TEST REPORT)
**Dự án:** Website bán máy tính cũ TL Gaming (Đồ án tốt nghiệp)  
**Công nghệ:** PHP 8.2 thuần (PDO) + MySQL/InnoDB + Mô hình MVC tự xây dựng  
**Môi trường thực thi kiểm thử:** `tl_gaming_audit_test` (CSDL kiểm thử độc lập — bảo toàn 100% CSDL chính `tl_gaming`)  
**Thời điểm thực thi:** `2026-11-11 09:47:08`  
**Tổng kết kết quả thực tế:** **30 / 30 PASS (100%)** | **0 FAIL** | **0 NOT RUN**

---

## 1. TỔNG HỢP CÁC LỖI LOGIC & LỖ HỔNG NGHIỆP VỤ ĐÃ ĐƯỢC KHẮC PHỤC TRIỆT ĐỂ

| STT | Hạng mục yêu cầu | Lỗi / Rủi ro trước khi hoàn thiện | Giải pháp kỹ thuật đã triển khai & Kiểm chứng thực tế | File cập nhật |
| :-: | :--- | :--- | :--- | :--- |
| **1** | **Kiểm tra Voucher toàn diện & Định danh Khách vãng lai** | Hàm đặt hàng cũ chưa kiểm tra `start_date`/`end_date`, chưa kiểm tra `per_user_limit`, và chưa chặn `discount_amount > total_product_amount`. | - Kiểm tra đủ 7 điều kiện trong Transaction (`SELECT ... FOR UPDATE`):<br>  1. `is_active = 1`.<br>  2. `NOW() BETWEEN start_date AND end_date` (chặn cả mã hết hạn và mã chưa đến ngày mở).<br>  3. `total_product_amount >= min_order_amount`.<br>  4. `usage_limit = 0 OR usage_count < usage_limit` (cập nhật nguyên tử có điều kiện).<br>  5. Thực thi `per_user_limit`: Với khách đăng nhập (`user_id IS NOT NULL`), đếm `coupon_usages (status = 'used')` theo `user_id OR customer_phone OR LOWER(customer_email) FOR UPDATE`. Với khách vãng lai (`user_id IS NULL`), chuẩn hóa SĐT/Email và đếm theo `customer_phone OR LOWER(customer_email) FOR UPDATE` (kèm thuyết minh rõ hạn chế định danh khách vãng lai).<br>  6. Chặn `discount_amount > total_product_amount` ở cả tầng PHP và `CONSTRAINT chk_orders_discount CHECK (discount_amount >= 0 AND discount_amount <= total_product_amount)`. | `run_db_audit.php`<br>`database/tl_gaming.sql`<br>`docs/database/DATABASE_DESIGN.md` |
| **2** | **Kiểm tra Tiền tệ & Chống giả mạo giá từ Trình duyệt** | Cần chặn `quantity <= 0`, `shipping_fee < 0`, chống tràn số nguyên và chống khách sửa giá từ trình duyệt. | - Kiểm tra chặt `quantity > 0`, `quantity <= MAX_ITEM_QUANTITY (10000)`, `shipping_fee >= 0` và `MAX_SAFE_MONEY_VND (1.000 tỷ VND)`.<br>- Tuyệt đối bỏ qua mọi giá trị `price`, `subtotal`, `total_product_amount`, `discount_amount`, `final_amount` từ phía Client gửi lên; truy vấn đơn giá trực tiếp từ `products.price` và `coupons` trong MySQL.<br>- Tính `final_amount = (total_product_amount - discount_amount) + shipping_fee` sau khi đã xác minh `discount_amount <= total_product_amount`, đảm bảo `final_amount >= 0` và không tràn âm `BIGINT UNSIGNED`. | `run_db_audit.php`<br>`database/tl_gaming.sql`<br>`docs/database/DATABASE_DESIGN.md` |
| **3** | **Bảo mật Thanh toán & Phân biệt Hủy đơn / Hoàn tiền** | Hàm đặt hàng cũ dùng `$orderData['payment_status'] ?? 'pending'`, cho phép khách tự truyền `payment_status = 'completed'`. | - Cưỡng bức gán cứng `payments.status = 'pending'` khi tạo đơn hàng mới, phớt lờ hoàn toàn mọi trường `payment_status` từ dữ liệu đầu vào.<br>- Xây dựng hàm `confirmPaymentByAdminTransaction()` kiểm tra chặt `users.role = 'admin' AND users.status = 'active'` trước khi cho phép đổi `payments.status` sang `'completed'`.<br>- Tách biệt rõ **Hủy đơn** (`cancelOrderTransaction` giữ nguyên `payments.status = 'completed'` để bảo toàn lịch sử thu tiền) và **Hoàn tiền** (`refundPaymentByAdminTransaction` chỉ cho phép Admin chuyển `payments.status` của đơn đã hủy từ `'completed'` sang `'refunded'`). | `run_db_audit.php`<br>`docs/database/DATABASE_DESIGN.md` |
| **4** | **Quy trình Hủy đơn Nguyên tử (Rollback khi lỗi hoàn Serial / Voucher)** | Hàm hủy đơn cũ chỉ cộng kho khi `rowCount === 1` nhưng không `ROLLBACK` nếu chuyển `reserved -> available` thất bại hoặc khi hoàn voucher thất bại. | - Trong `cancelOrderTransaction()`:<br>  + Nếu sản phẩm có `has_serials = 1` mà thiếu `product_unit_id` hoặc lệnh `UPDATE product_units SET status = 'available' WHERE id = :uid AND product_id = :pid AND status = 'reserved'` có `rowCount() !== 1` -> Ném ngoại lệ và **`ROLLBACK` toàn bộ transaction**.<br>  + Nếu đơn hàng có `coupon_id IS NOT NULL` mà không tìm thấy `coupon_usages(status = 'used')` hoặc không cập nhật được `coupon_usages.status = 'cancelled'` hoặc không giảm được `coupons.usage_count` -> Ném ngoại lệ và **`ROLLBACK` toàn bộ transaction** (hoàn nguyên cả trạng thái đơn và các máy serial đã xử lý ở bước trước trong cùng transaction). | `run_db_audit.php`<br>`docs/database/DATABASE_DESIGN.md` |
| **5** | **Đồng bộ `audit_results.json` & Bảo vệ CSDL chính** | `audit_results.json` cũ chỉ có 23 test case từ phiên trước, chưa đồng bộ với bộ kiểm thử mới. | - Tự động xuất và đồng bộ toàn bộ **30/30 Test Cases** vào `audit_results.json` ngay sau khi thực thi trên MySQL thật.<br>- Toàn bộ quá trình test chạy độc lập trên `tl_gaming_audit_test`, đối soát snapshot 15 bảng của `tl_gaming` trước và sau khi chạy khớp nhau 100%. | `run_db_audit.php`<br>`audit_results.json` |

---

## 2. PHÂN TÍCH QUY TẮC & HẠN CHẾ ĐỊNH DANH KHÁCH VÃNG LAI KHI DÙNG VOUCHER

- **Quy tắc thực thi trong hệ thống (`placeOrderTransaction`):**
  1. Chuẩn hóa số điện thoại (`normalizePhone`: loại bỏ khoảng trắng, dấu chấm/gạch ngang, chỉ giữ chữ số) và địa chỉ email (`normalizeEmail`: chuyển chữ thường `strtolower(trim($email))`).
  2. Khi khách vãng lai (`user_id IS NULL`) áp dụng mã voucher có `per_user_limit > 0`, hệ thống khóa và đếm số bản ghi trong `coupon_usages`:
     ```sql
     SELECT COUNT(*) FROM coupon_usages
     WHERE coupon_id = :cid
       AND status = 'used'
       AND (customer_phone = :phone OR LOWER(customer_email) = :email)
     FOR UPDATE
     ```
  3. Nếu khách đã đăng nhập (`user_id IS NOT NULL`), hệ thống kiểm tra đồng thời cả 3 tiêu chí `(user_id = :uid OR customer_phone = :phone OR LOWER(customer_email) = :email)` để ngăn chặn việc khách dùng mã 1 lần ở chế độ vãng lai rồi đăng nhập tài khoản để dùng thêm lần 2 với cùng SĐT/Email.
- **Hạn chế thực tế khi định danh khách vãng lai theo SĐT/Email:**
  - Do khách vãng lai không có định danh tài khoản xác thực (`user_id IS NULL`), cơ chế kiểm tra theo `customer_phone OR customer_email` chỉ chặn được khách hàng sử dụng lại cùng số điện thoại hoặc cùng địa chỉ email, nhưng **không thể chặn tuyệt đối** trường hợp một người cố tình dùng đồng thời một SIM số điện thoại mới VÀ một địa chỉ email mới cho đơn hàng COD tiếp theo.
  - **Giải pháp kiểm soát rủi ro:** (1) Luôn giới hạn tổng ngân sách khuyến mãi bằng `usage_limit` toàn hệ thống kèm khóa dòng `SELECT ... FOR UPDATE`; (2) Với các voucher giảm giá sâu, tầng Controller/Service trong MVC có thể cấu hình yêu cầu bắt buộc đăng nhập tài khoản thành viên (`user_id IS NOT NULL`).

---

## 3. NHẬT KÝ THỰC THI CONCURRENCY CHỒNG LẤN THỰC SỰ (TIMESTAMPS MILI-GIÂY)

### 3.1. `TC-CON-01`: Kết nối A giữ khóa `FOR UPDATE` mở, Kết nối B cố đặt cùng máy `#5` và bị Lock Wait Timeout
```text
[09:47:09.648] [Kết nối A | Conn #288] BEGIN TRANSACTION
[09:47:09.648] [Kết nối A | Conn #288] Đã khóa độc quyền (X-Lock) Unit #5 (TLG-PC-10700-SN001, status=available). Giao dịch A ĐANG MỞ (chưa commit)!
[09:47:09.648] [Kết nối B | Conn #289] BEGIN TRANSACTION (trong khi A vẫn đang giữ khóa)
[09:47:09.648] [Kết nối B | Conn #289] Thực thi SELECT ... FROM product_units WHERE id = 5 FOR UPDATE (bị treo chờ khóa của A)...
[09:47:10.662] [Kết nối B | Conn #289] Bị chặn suốt 1.014s bởi khóa của A và nhận lỗi: SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction -> ROLLBACK B
[09:47:10.665] [Kết nối A | Conn #288] UPDATE product_units SET status='reserved' thành công (rowCount = 1) -> COMMIT A
```

### 3.2. `TC-CON-02`: Hai tiến trình PHP chạy chồng lấn (`proc_open`) — B chờ khóa của A `936 ms` và thất bại sau khi A commit
```text
--- Nhật ký Tiến trình A (Conn #290) ---
[09:47:10.771] [A | Conn #290] Bắt đầu Transaction (BEGIN)
[09:47:10.772] [A | Conn #290] Gửi lệnh SELECT ... FROM product_units WHERE id = 5 FOR UPDATE
[09:47:10.773] [A | Conn #290] Đã giành được khóa dòng (Thời gian chờ khóa: 2 ms) | Trạng thái máy đọc được: available
[09:47:10.774] [A | Conn #290] Đang giữ khóa mở trong 1200 ms (chưa COMMIT)...
[09:47:11.990] [A | Conn #290] UPDATE product_units thành công (rowCount = 1) -> COMMIT đơn TLG-MP-A-9173 (ID=9)

--- Nhật ký Tiến trình B (Conn #291) ---
[09:47:11.053] [B | Conn #291] Bắt đầu Transaction (BEGIN)
[09:47:11.053] [B | Conn #291] Gửi lệnh SELECT ... FROM product_units WHERE id = 5 FOR UPDATE
[09:47:11.989] [B | Conn #291] Đã giành được khóa dòng (Thời gian chờ khóa: 936 ms) | Trạng thái máy đọc được: reserved
[09:47:11.990] [B | Conn #291] UPDATE product_units thất bại (rowCount = 0, máy đã bị giữ chỗ bởi giao dịch khác) -> ROLLBACK
```

### 3.3. `TC-VOU-08`: Hai tiến trình song song tranh 1 lượt Voucher cuối cùng (`usage_count = 4/5`)
```text
--- Nhật ký Tiến trình Săn Voucher A (Conn #292) ---
[09:47:12.149] [A | Conn #292] Bắt đầu Transaction (BEGIN)
[09:47:12.149] [A | Conn #292] Gửi lệnh SELECT ... FROM coupons WHERE id = 1 FOR UPDATE
[09:47:12.149] [A | Conn #292] Đã giành khóa coupon (Chờ khóa: 0 ms) | usage_count=4/5
[09:47:12.149] [A | Conn #292] Giữ khóa coupon mở trong 1000 ms (chưa COMMIT)...
[09:47:13.167] [A | Conn #292] Áp dụng voucher thành công (rowCount = 1) -> COMMIT đơn TLG-VOU-A-5291

--- Nhật ký Tiến trình Săn Voucher B (Conn #293) ---
[09:47:12.435] [B | Conn #293] Bắt đầu Transaction (BEGIN)
[09:47:12.435] [B | Conn #293] Gửi lệnh SELECT ... FROM coupons WHERE id = 1 FOR UPDATE
[09:47:13.165] [B | Conn #293] Đã giành khóa coupon (Chờ khóa: 730 ms) | usage_count=5/5
[09:47:13.167] [B | Conn #293] Hết lượt voucher (rowCount = 0, usage_count đã đạt giới hạn) -> ROLLBACK
```

---

## 4. MA TRẬN KẾT QUẢ 30 CA KIỂM THỬ THỰC TẾ (ĐỒNG BỘ 100% VỚI `audit_results.json`)

| Mã Test | Nhóm kiểm thử | Nội dung kiểm thử | Trạng thái | Bằng chứng thực thi trên MySQL |
| :---: | :--- | :--- | :---: | :--- |
| `TC-SCH-01` | 1. Schema & Ràng buộc | Đủ 15 bảng chuẩn hóa (gồm `inspection_items`) dùng InnoDB & `utf8mb4` | **PASS** | Số bảng thực tế: 15/15 bảng. |
| `TC-SCH-02` | 1. Schema & Ràng buộc | Đủ 22 Foreign Keys, `uq_order_items_order_unit`, `chk_coupons_usage_bound`, `chk_orders_discount`, `chk_orders_final_amount` | **PASS** | Đủ 22/22 FK và đầy đủ các UNIQUE / CHECK ràng buộc tiền tệ & voucher. |
| `TC-SEED-01` | 2. Dữ liệu Seed | 14 sản phẩm (`has_serials=1`: 13 SP, `has_serials=0`: 1 SP) & 90 bước kiểm định (5 x 18 bước) | **PASS** | Tổng SP: 14, Biên bản kiểm định: 5, Chi tiết bước: 90 bản ghi. |
| `TC-SER-01` | 3. Toàn vẹn Serial | Composite FK `fk_order_items_unit_product` chặn gán Serial của SP khác | **PASS** | Chặn bởi `SQLSTATE[23000]: Error 1452 Cannot add or update a child row`. |
| `TC-SER-02` | 3. Toàn vẹn Serial | `uq_order_items_order_unit` chặn cùng 1 Serial xuất hiện 2 lần trong cùng đơn hàng | **PASS** | Chặn bởi `SQLSTATE[23000]: 1062 Duplicate entry '6-13' for key 'uq_order_items_order_unit'`. |
| `TC-SER-03` | 3. Toàn vẹn Serial | Trigger chặn gán Serial đang thuộc một đơn hàng khác chưa hủy (`status <> 'cancelled'`) | **PASS** | Chặn bởi Trigger `tg_check_order_item_unit_active_insert` (`SQLSTATE 45000`). |
| `TC-SER-04` | 3. Toàn vẹn Serial | Cho phép bán lại Serial từ đơn đã hủy (`cancelled`) sang đơn mới mà giữ nguyên lịch sử đơn cũ | **PASS** | Đặt lại `Unit #13` thành công trong đơn mới, giữ nguyên bản ghi lịch sử ở đơn `#5` (`cancelled`). |
| `TC-CON-01` | 4. Concurrency (Đặt máy) | 2 kết nối chồng lấn (A giữ khóa mở chưa commit, B chờ khóa `1.014s` và bị `1205 Lock wait timeout`) | **PASS** | `Conn #289` (B) bị chặn `1.014s` và rollback; `Conn #288` (A) cập nhật `rowCount = 1` và commit. |
| `TC-CON-02` | 4. Concurrency (Đặt máy) | 2 tiến trình song song chồng lấn (B chờ khóa `936ms`, nhận `rowCount = 0` sau khi A commit) | **PASS** | `Conn #290` (A) `affected_rows = 1`; `Conn #291` (B) chờ khóa `936ms`, `affected_rows = 0`. |
| `TC-FIN-01` | 5. Kiểm tra Tiền tệ | Chặn đặt hàng với số lượng `quantity <= 0` (`quantity = 0` và `quantity = -3`) | **PASS** | Cả 2 trường hợp bị từ chối và rollback ngay lập tức. |
| `TC-FIN-02` | 5. Kiểm tra Tiền tệ | Chặn phí vận chuyển âm (`shipping_fee = -50000`) và Rollback không làm lệch kho | **PASS** | Bị từ chối (`shipping_fee < 0`), số đơn và tồn kho `Product #14` giữ nguyên `10`. |
| `TC-FIN-03` | 5. Kiểm tra Tiền tệ | Tính toán toàn bộ giá tiền trên Server từ MySQL (bỏ qua giá/giảm giá/tổng tiền giả mạo từ Client) | **PASS** | Client gửi `price=1, discount=9999999, final=1` -> Server lưu đúng `price=450000, total=900000, ship=30000, final=930000`. |
| `TC-PAY-01` | 6. Bảo mật Thanh toán | Chặn khách hàng tự truyền `payment_status = 'completed'` khi tạo đơn | **PASS** | Client truyền `payment_status='completed'` -> Bản ghi `payments` vẫn khởi tạo chặt chẽ là `'pending'`. |
| `TC-PAY-02` | 6. Bảo mật Thanh toán | Chỉ Admin có phân quyền (`role = 'admin'`) mới chuyển được `payments.status` sang `'completed'` | **PASS** | Customer (`ID=2`) gọi hàm duyệt bị từ chối; Admin (`ID=1`) duyệt thành công sang `'completed'`. |
| `TC-PAY-03` | 6. Bảo mật Thanh toán | Phân biệt Hủy đơn (`cancelled`) và Hoàn tiền (`refunded`), bảo toàn lịch sử giao dịch | **PASS** | Khi hủy đơn đã trả tiền, `payments.status` giữ nguyên `'completed'`; chỉ Admin mới chuyển sang `'refunded'`. |
| `TC-VOU-01` | 7. Quản lý Voucher | Tạo đơn có voucher hợp lệ: Tăng `coupons.usage_count` thêm 1 và lưu `coupon_usages(used)` | **PASS** | `usage_count` tăng từ `1 -> 2`, `coupon_usages.status = 'used'`. |
| `TC-VOU-02` | 7. Quản lý Voucher | Hủy đơn có voucher: Giảm `usage_count` đi 1, chuyển `coupon_usages(cancelled)`, chống trừ lặp | **PASS** | Hủy lần 1: `usage_count` về `1`, `status = 'cancelled'`; hủy lặp lần 2 bị từ chối. |
| `TC-VOU-03` | 7. Quản lý Voucher | Chặn sử dụng voucher đã hết hạn (`NOW() > end_date`, mã `EXPIRED2025`) | **PASS** | Bị từ chối và rollback (`Voucher EXPIRED2025 đã hết hạn sử dụng`). |
| `TC-VOU-04` | 7. Quản lý Voucher | Chặn sử dụng voucher chưa đến thời điểm bắt đầu (`NOW() < start_date`, mã `FUTURE2028`) | **PASS** | Bị từ chối và rollback (`Voucher FUTURE2028 chưa đến thời gian có hiệu lực`). |
| `TC-VOU-05` | 7. Quản lý Voucher | Thực thi `per_user_limit`: Chặn cả khách đăng nhập (`user_id`) và khách vãng lai (`SĐT/Email`) dùng vượt giới hạn | **PASS** | Cả khách thành viên (`user_id=2`) và khách vãng lai trùng SĐT `0912345678` đều bị chặn khi dùng lần 2. |
| `TC-VOU-06` | 7. Quản lý Voucher | Chặn mã giảm giá lớn hơn giá trị đơn (`discount_amount > total_product_amount`) ở cả PHP & MySQL CHECK | **PASS** | Đơn `450,000đ` áp voucher `1,000,000đ` bị từ chối ở cả PHP và `chk_orders_discount` trong MySQL. |
| `TC-VOU-07` | 7. Quản lý Voucher | Chặn tuyệt đối `usage_count < 0` hoặc `usage_count > usage_limit` | **PASS** | Chặn bởi `INT UNSIGNED` và `CHECK (chk_coupons_usage_bound)`. |
| `TC-VOU-08` | 7. Quản lý Voucher | Concurrency 2 khách đồng thời tranh lượt voucher cuối cùng (`usage_count = 4/5`) | **PASS** | `Conn #292` (A) thành công (`rowCount = 1`); `Conn #293` (B) chờ khóa `730ms`, thất bại (`rowCount = 0`). |
| `TC-CAN-01` | 8. Hủy đơn & Rollback | Hủy đơn sản phẩm có serial (`has_serials = 1`): Hoàn máy về `available` & cộng `+1` tồn kho | **PASS** | `Unit #5` chuyển `reserved -> available`, tồn kho `Product #3` tăng `0 -> 1`. |
| `TC-CAN-02` | 8. Hủy đơn & Rollback | Đặt và hủy đơn sản phẩm không có serial (`has_serials = 0`, `quantity = 3`) | **PASS** | Tồn kho `Product #14` (`SKU-RAM-01`): `10 -> 7 -> 10`. |
| `TC-CAN-03` | 8. Hủy đơn & Rollback | Hủy đơn lặp lại lần 2 (Idempotency) cho cả đơn có serial và không có serial | **PASS** | Từ chối hủy lặp (`INVALID_STATUS_OR_ALREADY_CANCELLED`), không hoàn kho lần 2. |
| `TC-CAN-04` | 8. Hủy đơn & Rollback | Chống âm tồn kho (`stock_quantity >= 0`) ở cả tầng giao dịch và tầng MySQL | **PASS** | Đặt `15` chiếc khi kho có `10` bị từ chối; lệnh SQL trừ âm trực tiếp bị chặn bởi `INT UNSIGNED`. |
| `TC-CAN-05` | 8. Hủy đơn & Rollback | **Test thất bại khi hoàn Serial** (máy không ở trạng thái `reserved`) -> Rollback toàn bộ transaction | **PASS** | Khi `Unit #1` đang `'repairing'`, hủy đơn `#1` thất bại và `ROLLBACK`: `orders.status` giữ `'pending'`, kho và voucher không đổi. |
| `TC-CAN-06` | 8. Hủy đơn & Rollback | **Test thất bại khi hoàn lượt Voucher** -> Rollback toàn bộ transaction (không hoàn kho một nửa) | **PASS** | Khi hoàn voucher đơn `#1` lỗi, toàn bộ giao dịch `ROLLBACK`: `orders.status` giữ `'pending'`, `Unit #1` giữ `'reserved'`, tồn kho giữ `1`. |
| `TC-SAFE-01` | 9. An toàn CSDL chính | Không làm thay đổi hoặc mất dữ liệu trong CSDL chính `tl_gaming` | **PASS** | Snapshot số dòng 15 bảng của `tl_gaming` trước và sau khi chạy 30 test cases khớp nhau 100%. |
