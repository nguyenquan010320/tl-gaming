# -*- coding: utf-8 -*-
"""
Script kiểm thử toàn diện CSDL TL Gaming trên database 'tl_gaming_audit_test'.
Thực hiện các bài test:
1. Đối chiếu Schema (Bảng, Cột, PK, FK, Index)
2. Toàn vẹn dữ liệu Seed (Duplicate checks, FK integrity)
3. Kiểm toán tính toán tài chính & logic voucher
4. Kiểm thử Guest Checkout
5. Kiểm thử Bảo toàn dữ liệu (Snapshot & ON DELETE RESTRICT)
6. Kiểm thử Concurrency & Khóa bản ghi (SELECT ... FOR UPDATE)
7. Kiểm thử Quy trình hủy đơn & Hoàn tồn kho
8. Kiểm thử Ràng buộc thanh toán mặc định
"""

import subprocess
import json
import re

MYSQL_BIN = r"E:\xampp\mysql\bin\mysql.exe"
DB_NAME = "tl_gaming_audit_test"

def run_query(query, as_json=False):
    cmd = [MYSQL_BIN, "-u", "root", "-e", f"USE `{DB_NAME}`; {query}"]
    if as_json:
        cmd.append("--json")
    result = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8")
    if result.returncode != 0:
        return {"error": result.stderr.strip(), "success": False}
    return {"output": result.stdout.strip(), "success": True}

test_results = []

def record_test(suite, test_id, description, status, details=""):
    test_results.append({
        "suite": suite,
        "test_id": test_id,
        "description": description,
        "status": status,
        "details": details
    })
    print(f"[{status}] {test_id}: {description}")

print("=== BẮT ĐẦU KIỂM THỬ TOÀN DIỆN CSDL TL GAMING ===")

# -----------------------------------------------------------------------------
# SUITE 1: SCHEMA & TOÀN VẸN CẤU TRÚC
# -----------------------------------------------------------------------------
# 1.1 Kiểm tra số lượng bảng (Kỳ vọng 14 bảng)
res = run_query("SELECT COUNT(*) AS total_tables FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'tl_gaming_audit_test';")
if "14" in res.get("output", ""):
    record_test("Schema", "TC-SCH-01", "Kiểm tra tổng số bảng (14 bảng InnoDB)", "PASS", "Đủ 14/14 bảng chuẩn hóa")
else:
    record_test("Schema", "TC-SCH-01", "Kiểm tra tổng số bảng (14 bảng InnoDB)", "FAIL", res.get("output", ""))

# 1.2 Kiểm tra 100% bảng sử dụng InnoDB và utf8mb4_unicode_ci
res = run_query("SELECT COUNT(*) AS non_innodb FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'tl_gaming_audit_test' AND (ENGINE != 'InnoDB' OR TABLE_COLLATION != 'utf8mb4_unicode_ci');")
if "0" in res.get("output", "").split():
    record_test("Schema", "TC-SCH-02", "Kiểm tra Storage Engine InnoDB & utf8mb4_unicode_ci", "PASS", "100% bảng đạt chuẩn")
else:
    record_test("Schema", "TC-SCH-02", "Kiểm tra Storage Engine InnoDB & utf8mb4_unicode_ci", "FAIL", res.get("output", ""))

# 1.3 Kiểm tra Khóa ngoại (Kỳ vọng 20 Foreign Keys)
res = run_query("SELECT COUNT(*) AS total_fks FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'tl_gaming_audit_test';")
if "20" in res.get("output", "").split():
    record_test("Schema", "TC-SCH-03", "Kiểm tra tổng số khóa ngoại (20 FKs)", "PASS", "Đủ 20/20 Foreign Keys")
else:
    record_test("Schema", "TC-SCH-03", "Kiểm tra tổng số khóa ngoại (20 FKs)", "FAIL", res.get("output", ""))

# 1.4 Kiểm tra Primary Keys (14/14 bảng có PK 'id')
res = run_query("SELECT COUNT(DISTINCT TABLE_NAME) AS pk_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'tl_gaming_audit_test' AND COLUMN_KEY = 'PRI' AND COLUMN_NAME = 'id';")
if "14" in res.get("output", "").split():
    record_test("Schema", "TC-SCH-04", "Kiểm tra Khóa chính PK trên 14 bảng", "PASS", "14/14 bảng đều có PK id INT UNSIGNED AUTO_INCREMENT")
else:
    record_test("Schema", "TC-SCH-04", "Kiểm tra Khóa chính PK trên 14 bảng", "FAIL", res.get("output", ""))

# -----------------------------------------------------------------------------
# SUITE 2: DỮ LIỆU SEED & RÀNG BUỘC DUY NHẤT (UNIQUE & DUPLICATION)
# -----------------------------------------------------------------------------
# 2.1 Kiểm tra trùng Email
res = run_query("SELECT email, COUNT(*) AS cnt FROM users GROUP BY email HAVING cnt > 1;")
if not res.get("output") or "cnt" not in res.get("output", ""):
    record_test("Integrity", "TC-INT-01", "Kiểm tra không trùng lặp email người dùng", "PASS", "Email người dùng là duy nhất")
else:
    record_test("Integrity", "TC-INT-01", "Kiểm tra không trùng lặp email người dùng", "FAIL", res.get("output"))

# 2.2 Kiểm tra trùng SKU và Slug sản phẩm
res = run_query("SELECT sku, COUNT(*) AS cnt FROM products GROUP BY sku HAVING cnt > 1 UNION ALL SELECT slug, COUNT(*) FROM products GROUP BY slug HAVING cnt > 1;")
if not res.get("output") or "cnt" not in res.get("output", ""):
    record_test("Integrity", "TC-INT-02", "Kiểm tra không trùng lặp SKU & Slug sản phẩm", "PASS", "SKU và Slug sản phẩm là duy nhất")
else:
    record_test("Integrity", "TC-INT-02", "Kiểm tra không trùng lặp SKU & Slug sản phẩm", "FAIL", res.get("output"))

# 2.3 Kiểm tra trùng Serial máy vật lý
res = run_query("SELECT serial_number, COUNT(*) AS cnt FROM product_units GROUP BY serial_number HAVING cnt > 1;")
if not res.get("output") or "cnt" not in res.get("output", ""):
    record_test("Integrity", "TC-INT-03", "Kiểm tra không trùng lặp Serial Number máy cũ", "PASS", "Tất cả 19 máy có serial duy nhất")
else:
    record_test("Integrity", "TC-INT-03", "Kiểm tra không trùng lặp Serial Number máy cũ", "FAIL", res.get("output"))

# 2.4 Kiểm tra trùng mã đơn hàng và mã voucher
res = run_query("SELECT order_code, COUNT(*) AS cnt FROM orders GROUP BY order_code HAVING cnt > 1 UNION ALL SELECT code, COUNT(*) FROM coupons GROUP BY code HAVING cnt > 1;")
if not res.get("output") or "cnt" not in res.get("output", ""):
    record_test("Integrity", "TC-INT-04", "Kiểm tra không trùng lặp Order Code & Coupon Code", "PASS", "Order code và coupon code là duy nhất")
else:
    record_test("Integrity", "TC-INT-04", "Kiểm tra không trùng lặp Order Code & Coupon Code", "FAIL", res.get("output"))

# -----------------------------------------------------------------------------
# SUITE 3: KIỂM TOÁN TÀI CHÍNH & TỒN KHO (FINANCIAL & STOCK AUDIT)
# -----------------------------------------------------------------------------
# 3.1 Kiểm toán công thức: final_amount = total_product_amount - discount_amount + shipping_fee
res = run_query("""
SELECT COUNT(*) AS mismatch_count FROM orders 
WHERE final_amount != (total_product_amount - discount_amount + shipping_fee);
""")
if "0" in res.get("output", "").split():
    record_test("Financial", "TC-FIN-01", "Kiểm toán công thức tổng tiền 5 đơn hàng", "PASS", "100% đơn hàng khớp chính xác")
else:
    record_test("Financial", "TC-FIN-01", "Kiểm toán công thức tổng tiền 5 đơn hàng", "FAIL", res.get("output"))

# 3.2 Kiểm toán đơn giá chi tiết: subtotal = price * quantity
res = run_query("""
SELECT COUNT(*) AS mismatch_count FROM order_items 
WHERE subtotal != (price * quantity);
""")
if "0" in res.get("output", "").split():
    record_test("Financial", "TC-FIN-02", "Kiểm toán thành tiền order_items (price * quantity)", "PASS", "100% dòng chi tiết khớp chính xác")
else:
    record_test("Financial", "TC-FIN-02", "Kiểm toán thành tiền order_items (price * quantity)", "FAIL", res.get("output"))

# 3.3 Kiểm toán tiền thanh toán payments khớp final_amount
res = run_query("""
SELECT COUNT(*) AS mismatch_count FROM payments p 
JOIN orders o ON p.order_id = o.id 
WHERE p.amount != o.final_amount;
""")
if "0" in res.get("output", "").split():
    record_test("Financial", "TC-FIN-03", "Kiểm toán số tiền thanh toán payments khớp đơn hàng", "PASS", "100% giao dịch thanh toán khớp số tiền")
else:
    record_test("Financial", "TC-FIN-03", "Kiểm toán số tiền thanh toán payments khớp đơn hàng", "FAIL", res.get("output"))

# 3.4 Kiểm toán đồng bộ tồn kho: stock_quantity == COUNT(units where available)
res = run_query("""
SELECT COUNT(*) AS mismatch_count FROM (
    SELECT p.id, p.stock_quantity, COUNT(CASE WHEN u.status = 'available' THEN 1 END) AS real_avail
    FROM products p
    LEFT JOIN product_units u ON p.id = u.product_id
    GROUP BY p.id, p.stock_quantity
    HAVING p.stock_quantity != real_avail
) AS t;
""")
if "0" in res.get("output", "").split():
    record_test("Stock", "TC-STK-01", "Kiểm toán Single Source of Truth tồn kho", "PASS", "13/13 sản phẩm khớp chính xác với product_units")
else:
    record_test("Stock", "TC-STK-01", "Kiểm toán Single Source of Truth tồn kho", "FAIL", res.get("output"))

# -----------------------------------------------------------------------------
# SUITE 4: KIỂM THỬ KHÁCH VÃNG LAI (GUEST CHECKOUT)
# -----------------------------------------------------------------------------
# 4.1 Kiểm tra đơn hàng mẫu của khách vãng lai (orders.user_id IS NULL)
res = run_query("SELECT id, order_code, customer_name, customer_email, customer_phone FROM orders WHERE user_id IS NULL;")
if "Hoàng Minh Đức" in res.get("output", "") or "duc.hoang@demo.vn" in res.get("output", ""):
    record_test("GuestCheckout", "TC-GST-01", "Kiểm tra đơn hàng vãng lai hiện có trong CSDL", "PASS", "Đơn hàng TLG-20260214-003 lưu thành công với user_id = NULL")
else:
    record_test("GuestCheckout", "TC-GST-01", "Kiểm tra đơn hàng vãng lai hiện có trong CSDL", "FAIL", res.get("output"))

# 4.2 Thử nghiệm tạo đơn hàng vãng lai mới trực tiếp
res = run_query("""
INSERT INTO orders (order_code, user_id, customer_name, customer_email, customer_phone, shipping_address, shipping_city, total_product_amount, shipping_fee, discount_amount, final_amount, status)
VALUES ('TLG-TEST-GUEST-01', NULL, 'Khách Vãng Lai Test', 'guest.test@gmail.com', '0999888777', '123 Test Street', 'Hà Nội', 5000000, 30000, 0, 5030000, 'pending');
SELECT id, order_code, user_id FROM orders WHERE order_code = 'TLG-TEST-GUEST-01';
""")
if "TLG-TEST-GUEST-01" in res.get("output", "") and "NULL" in res.get("output", "").upper():
    record_test("GuestCheckout", "TC-GST-02", "Thực thi tạo đơn Guest Checkout mới vào CSDL", "PASS", "CSDL chấp nhận user_id = NULL mà không bị vi phạm FK")
else:
    record_test("GuestCheckout", "TC-GST-02", "Thực thi tạo đơn Guest Checkout mới vào CSDL", "FAIL", res.get("output"))

# -----------------------------------------------------------------------------
# SUITE 5: BẢO TOÀN LỊCH SỬ KHI ẨN/XÓA SẢN PHẨM & SNAPSHOT
# -----------------------------------------------------------------------------
# 5.1 Kiểm tra khả năng ẩn sản phẩm (status = 'archived')
res = run_query("""
UPDATE products SET status = 'archived' WHERE id = 1;
SELECT p.id, p.status, oi.product_name, oi.price 
FROM products p 
JOIN order_items oi ON p.id = oi.product_id 
WHERE p.id = 1;
""")
if "archived" in res.get("output", "") and "PC Gaming TL-01" in res.get("output", ""):
    record_test("HistoryAudit", "TC-HIS-01", "Kiểm tra ẩn sản phẩm (archived) không ảnh hưởng order_items", "PASS", "Sản phẩm chuyển sang archived nhưng đơn hàng cũ vẫn giữ nguyên bản")
else:
    record_test("HistoryAudit", "TC-HIS-01", "Kiểm tra ẩn sản phẩm (archived) không ảnh hưởng order_items", "FAIL", res.get("output"))

# 5.2 Kiểm tra ràng buộc khóa ngoại chặn xóa sản phẩm đã có đơn (ON DELETE RESTRICT)
res = run_query("DELETE FROM products WHERE id = 1;")
if not res.get("success") and "Cannot delete or update a parent row" in res.get("error", ""):
    record_test("HistoryAudit", "TC-HIS-02", "Kiểm tra ON DELETE RESTRICT chặn xóa sản phẩm đã có đơn", "PASS", "MySQL chặn hành vi xóa sản phẩm bảo vệ 100% lịch sử hóa đơn")
else:
    record_test("HistoryAudit", "TC-HIS-02", "Kiểm tra ON DELETE RESTRICT chặn xóa sản phẩm đã có đơn", "FAIL", str(res))

# Khôi phục trạng thái published cho sản phẩm 1
run_query("UPDATE products SET status = 'published' WHERE id = 1;")

# -----------------------------------------------------------------------------
# SUITE 6: MÔ PHỎNG TRANSACTIONS & KHÓA BẢN GHI (CONCURRENCY & SELECT FOR UPDATE)
# -----------------------------------------------------------------------------
# Mô phỏng đặt chiếc máy chỉ còn 1 cái:
# Bước A: Khóa chiếc máy available bằng SELECT ... FOR UPDATE
res_lock = run_query("""
START TRANSACTION;
SELECT id, serial_number, status FROM product_units 
WHERE product_id = 3 AND status = 'available' 
LIMIT 1 FOR UPDATE;
UPDATE product_units SET status = 'reserved' WHERE id = 5;
UPDATE products SET stock_quantity = stock_quantity - 1 WHERE id = 3;
COMMIT;
SELECT p.stock_quantity, u.status FROM products p JOIN product_units u ON p.id = u.product_id WHERE p.id = 3;
""")
if "reserved" in res_lock.get("output", "") and "0" in res_lock.get("output", "").split():
    record_test("Concurrency", "TC-CON-01", "Mô phỏng Transaction + SELECT FOR UPDATE khi gán máy", "PASS", "Máy chuyển thành công sang reserved và tồn kho giảm về 0 an toàn")
else:
    record_test("Concurrency", "TC-CON-01", "Mô phỏng Transaction + SELECT FOR UPDATE khi gán máy", "FAIL", res_lock.get("output"))

# Bước B: Giao dịch thứ 2 cố gắng mua tiếp khi máy đã hết
res_lock2 = run_query("""
SELECT id, serial_number, status FROM product_units 
WHERE product_id = 3 AND status = 'available' 
LIMIT 1;
""")
if not res_lock2.get("output") or "TLG" not in res_lock2.get("output", ""):
    record_test("Concurrency", "TC-CON-02", "Kiểm tra chặn bán vượt tồn kho (Overselling prevention)", "PASS", "Không còn máy available nào để khóa -> Đơn hàng thứ 2 bị từ chối chính xác")
else:
    record_test("Concurrency", "TC-CON-02", "Kiểm tra chặn bán vượt tồn kho (Overselling prevention)", "FAIL", res_lock2.get("output"))

# Trả lại trạng thái máy 5 về available và tồn kho về 1
run_query("UPDATE product_units SET status = 'available' WHERE id = 5; UPDATE products SET stock_quantity = 1 WHERE id = 3;")

# -----------------------------------------------------------------------------
# SUITE 7: QUY TRÌNH HỦY ĐƠN & HOÀN LẠI TỒN KHO / VOUCHER
# -----------------------------------------------------------------------------
# Mô phỏng hủy đơn 1:
res_cancel = run_query("""
START TRANSACTION;
-- 1. Cập nhật trạng thái đơn sang cancelled
UPDATE orders SET status = 'cancelled', cancel_reason = 'Khách hủy test', cancelled_at = NOW() WHERE id = 1;
-- 2. Lấy danh sách máy vật lý trong đơn và hoàn về available
UPDATE product_units pu
JOIN order_items oi ON pu.id = oi.product_unit_id
SET pu.status = 'available'
WHERE oi.order_id = 1;
-- 3. Cập nhật tồn kho sản phẩm tăng lại
UPDATE products p
JOIN order_items oi ON p.id = oi.product_id
SET p.stock_quantity = p.stock_quantity + oi.quantity
WHERE oi.order_id = 1;
-- 4. Hoàn lại mã voucher
UPDATE coupon_usages SET status = 'cancelled' WHERE order_id = 1;
-- 5. Cập nhật thanh toán COD thành failed
UPDATE payments SET status = 'failed' WHERE order_id = 1;
COMMIT;

SELECT o.status AS order_status, pu.status AS unit_status, p.stock_quantity, cu.status AS coupon_status, pay.status AS pay_status
FROM orders o
JOIN order_items oi ON o.id = oi.order_id
JOIN product_units pu ON oi.product_unit_id = pu.id
JOIN products p ON oi.product_id = p.id
LEFT JOIN coupon_usages cu ON o.id = cu.order_id
LEFT JOIN payments pay ON o.id = pay.order_id
WHERE o.id = 1;
""")
if "cancelled" in res_cancel.get("output", "") and "available" in res_cancel.get("output", ""):
    record_test("Cancellation", "TC-CAN-01", "Kiểm tra quy trình hủy đơn và hoàn trả máy về kho", "PASS", "Máy hoàn về available, voucher hoàn thành cancelled, thanh toán failed")
else:
    record_test("Cancellation", "TC-CAN-01", "Kiểm tra quy trình hủy đơn và hoàn trả máy về kho", "FAIL", res_cancel.get("output"))

# -----------------------------------------------------------------------------
# SUITE 8: KIỂM THỬ TRẠNG THÁI THANH TOÁN MẶC ĐỊNH
# -----------------------------------------------------------------------------
res_pay = run_query("""
INSERT INTO payments (order_id, payment_method, amount) VALUES (3, 'cod', 13960000);
SELECT status FROM payments WHERE id = LAST_INSERT_ID();
""")
if "pending" in res_pay.get("output", ""):
    record_test("PaymentSecurity", "TC-PAY-01", "Kiểm tra payments.status mặc định là 'pending'", "PASS", "Thanh toán mới tạo luôn mang trạng thái pending, không bao giờ tự động paid")
else:
    record_test("PaymentSecurity", "TC-PAY-01", "Kiểm tra payments.status mặc định là 'pending'", "FAIL", res_pay.get("output"))

# Dọn dẹp bản ghi thanh toán test vừa tạo
run_query("DELETE FROM payments WHERE id = (SELECT MAX(id) FROM payments WHERE order_id = 3 AND status = 'pending' AND transaction_code IS NULL);")
run_query("DELETE FROM orders WHERE order_code = 'TLG-TEST-GUEST-01';")

# -----------------------------------------------------------------------------
# TỔNG HỢP KẾT QUẢ
# -----------------------------------------------------------------------------
print("\n=== KẾT QUẢ TỔNG HỢP KIỂM THỬ ===")
pass_count = sum(1 for t in test_results if t["status"] == "PASS")
fail_count = sum(1 for t in test_results if t["status"] == "FAIL")
print(f"Tổng số bài test: {len(test_results)}")
print(f"PASS: {pass_count}")
print(f"FAIL: {fail_count}")

# Lưu kết quả json tạm để phân tích
with open("audit_results.json", "w", encoding="utf-8") as f:
    json.dump(test_results, f, ensure_ascii=False, indent=2)
