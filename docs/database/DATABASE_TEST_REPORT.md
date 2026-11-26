# BÁO CÁO KIỂM TOÁN VÀ KIỂM THỬ CƠ SỞ DỮ LIỆU
## ĐỒ ÁN TỐT NGHIỆP: WEBSITE BÁN MÁY TÍNH CŨ TL GAMING
**Phiên bản Schema:** 2.0 (15 Bảng, 22 Foreign Keys, 2 Triggers)  
**Ngày thực hiện kiểm thử:** 10/11/2026  
**Môi trường kiểm thử:** MariaDB 10.11 / MySQL 8.0 (XAMPP Windows 10/11, PHP 8.2 PDO)  
**Cơ sở dữ liệu kiểm thử:** `tl_gaming_audit_test` (Độc lập, không ảnh hưởng dữ liệu sản xuất `tl_gaming`)

---

### 1. TỔNG QUAN KẾT QUẢ KIỂM THỬ

Toàn bộ 23 ca kiểm thử tự động (Test Cases) được thực thi trực tiếp thông qua script PHP PDO chuẩn [run_db_audit.php](file:///e:/xampp/htdocs/tl-gaming/run_db_audit.php) trên môi trường cơ sở dữ liệu thật với kết quả:

| Chỉ số | Giá trị | Tỷ lệ |
| :--- | :---: | :---: |
| **Tổng số ca kiểm thử (Total Tests)** | **23** | **100.0%** |
| **Số ca Đạt (PASS)** | **23** | **100.0%** |
| **Số ca Thất bại (FAIL)** | **0** | **0.0%** |
| **Số ca Chưa chạy (NOT RUN)** | **0** | **0.0%** |
| **Đánh giá mức độ sẵn sàng** | **SẴN SÀNG TRIỂN KHAI PHP MVC** | **100%** |

---

### 2. MA TRẬN KẾT QUẢ KIỂM THỬ CHI TIẾT (TEST EXECUTION MATRIX)

#### Nhóm 1: Kiểm toán Cấu trúc Schema & Foreign Keys (Schema Integrity)
| Mã Test | Mô tả ca kiểm thử | Kết quả | Chi tiết chứng thực |
| :--- | :--- | :---: | :--- |
| **TC-SCH-01** | Kiểm tra đủ 15 bảng nghiệp vụ | **PASS** | Đủ 15/15 bảng trong `information_schema.TABLES`. |
| **TC-SCH-02** | Kiểm tra cấu trúc bảng chuẩn hóa `inspection_items` | **PASS** | Bảng con có đủ 8 cột chuẩn hóa 3NF, đúng kiểu dữ liệu. |
| **TC-SCH-03** | Kiểm tra cờ `has_serials` trong bảng `products` | **PASS** | Cột `has_serials TINYINT(1)` tồn tại với chỉ mục và ràng buộc CHECK (0, 1). |
| **TC-SCH-04** | Kiểm tra Unique Key kết hợp `(id, product_id)` trong `product_units` | **PASS** | Khóa `uq_unit_id_product` hoạt động làm đích tham chiếu. |
| **TC-SCH-05** | Kiểm tra Composite FK `fk_order_items_unit_product` | **PASS** | Khóa ngoại kết hợp từ `order_items` trỏ chuẩn xác đến `product_units(id, product_id)`. |
| **TC-SCH-06** | Kiểm tra đầy đủ 22 Foreign Keys nghiệp vụ | **PASS** | Đủ 22/22 Foreign Keys trong `information_schema.REFERENTIAL_CONSTRAINTS`. |

#### Nhóm 2: Toàn vẹn Dữ liệu Mẫu & 18 Bước Kiểm định (Seed Verification)
| Mã Test | Mô tả ca kiểm thử | Kết quả | Chi tiết chứng thực |
| :--- | :--- | :---: | :--- |
| **TC-SEE-01** | Kiểm tra đủ 90 bản ghi `inspection_items` cho 5 máy mẫu | **PASS** | Đủ 5 máy * 18 bước = 90 bản ghi kiểm tra kỹ thuật. |
| **TC-SEE-02** | Kiểm tra tính liên tục và đầy đủ của bước 1-18 | **PASS** | Mỗi biên bản có đủ bước từ 1 đến 18, không đứt quãng, không nhảy bước. |
| **TC-SEE-03** | Kiểm tra tính duy nhất (No Duplicates) của `users.email` | **PASS** | 0 bản ghi trùng lặp email. |
| **TC-SEE-04** | Kiểm tra tính duy nhất (No Duplicates) của `products.sku` | **PASS** | 0 bản ghi trùng lặp mã SKU. |
| **TC-SEE-05** | Kiểm tra tính duy nhất (No Duplicates) của `products.slug` | **PASS** | 0 bản ghi trùng lặp SEO slug. |
| **TC-SEE-06** | Kiểm tra tính duy nhất (No Duplicates) của `product_units.serial_number` | **PASS** | 0 bản ghi trùng lặp số Serial máy. |
| **TC-SEE-07** | Kiểm tra tính duy nhất (No Duplicates) của `orders.order_code` | **PASS** | 0 bản ghi trùng lặp mã đơn hàng. |
| **TC-SEE-08** | Kiểm tra tính duy nhất (No Duplicates) của `coupons.code` | **PASS** | 0 bản ghi trùng lặp mã voucher. |

#### Nhóm 3: Kiểm toán Tính toán Tài chính & Vòng đời Thanh toán (Finance & Payment)
| Mã Test | Mô tả ca kiểm thử | Kết quả | Chi tiết chứng thực |
| :--- | :--- | :---: | :--- |
| **TC-FIN-01** | Kiểm tra công thức tính tổng tiền đơn hàng | **PASS** | `final_amount = total_product_amount + shipping_fee - discount_amount` khớp 100%. |
| **TC-FIN-02** | Kiểm tra `payments.status` mặc định luôn là `'pending'` | **PASS** | Bản ghi thanh toán mới tạo luôn mang trạng thái `pending`, không tự động nhảy sang `paid/completed`. |

#### Nhóm 4: Ràng buộc Toàn vẹn Serial & Composite Foreign Key (Serial Integrity)
| Mã Test | Mô tả ca kiểm thử | Kết quả | Chi tiết chứng thực |
| :--- | :--- | :---: | :--- |
| **TC-INT-01** | Kiểm tra Composite FK chặn gán Serial của sản phẩm khác | **PASS** | Cố tình gán Serial của VGA vào đơn PC bị MySQL từ chối với mã lỗi chuẩn `SQLSTATE 23000 (Error 1452)`. |
| **TC-INT-02** | Kiểm tra Trigger chặn gán máy của đơn chưa hủy cho đơn khác | **PASS** | Cố tình bán một chiếc máy đang thuộc đơn hàng đang giao/chờ xử lý bị Trigger chặn với `SQLSTATE 45000`. |
| **TC-INT-03** | Kiểm tra cho phép tái xuất bán máy từ đơn hàng đã hủy | **PASS** | Máy từ đơn hàng `cancelled` được phép gán bán lại cho khách hàng mới mà không vi phạm lịch sử. |

#### Nhóm 5: Kiểm thử Concurrency Đồng thời với 2 Kết nối Độc lập (Concurrency Testing)
| Mã Test | Mô tả ca kiểm thử | Kết quả | Chi tiết chứng thực |
| :--- | :--- | :---: | :--- |
| **TC-CON-01** | Mô phỏng 2 kết nối độc lập mua chiếc máy duy nhất với `SELECT FOR UPDATE` | **PASS** | Sử dụng 2 đối tượng PDO độc lập (`$pdo1` và `$pdo2`): Kết nối 1 khóa bản ghi và cập nhật thành công (`rowCount = 1`); Kết nối 2 bị chặn hoặc nhận `rowCount = 0` (hết hàng), loại bỏ hoàn toàn nguy cơ Overselling. |

#### Nhóm 6: Quy trình Hủy đơn & Hoàn kho Idempotent (Idempotent Cancellation)
| Mã Test | Mô tả ca kiểm thử | Kết quả | Chi tiết chứng thực |
| :--- | :--- | :---: | :--- |
| **TC-CAN-01** | Hủy đơn hợp lệ: Hoàn máy về `available`, tăng tồn kho, payment `failed` | **PASS** | Máy được hoàn về kho, tồn kho tăng lại đúng số lượng mặt hàng. |
| **TC-CAN-02** | Kiểm tra tính Idempotent: Tuyệt đối không hoàn kho lần 2 | **PASS** | Gửi yêu cầu hủy lặp lại trên đơn đã hủy bị từ chối, tồn kho giữ nguyên (không bị tăng khống). |
| **TC-CAN-03** | Bảo toàn lịch sử kế toán: Không chuyển payment `completed` thành `failed` | **PASS** | Đơn hàng đã chuyển khoản thành công khi bị hủy vẫn giữ nguyên trạng thái thanh toán `completed` để phục vụ đối soát hoàn tiền. |

---

### 3. THIẾT KẾ QUY TRÌNH HỦY ĐƠN CHUẨN ACID CHO TẦNG PHP MVC

Dựa trên thực nghiệm kiểm thử thành công, mô hình hủy đơn hàng trong tầng Controller/Service của PHP thuần được khuyến nghị triển khai theo cấu trúc chuẩn sau:

```php
/**
 * Quy trình hủy đơn hàng an toàn (Idempotent Order Cancellation)
 * Được thực thi trọn vẹn trong 1 Transaction duy nhất
 */
function cancelOrder($pdo, $orderId, $reason) {
    $pdo->beginTransaction();
    try {
        // 1. Khóa và kiểm tra trạng thái đơn hiện tại
        $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $orderId]);
        $status = $stmt->fetchColumn();

        if (!$status) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Đơn hàng không tồn tại'];
        }
        if ($status === 'cancelled') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Đơn hàng đã được hủy trước đó'];
        }
        if (in_array($status, ['shipping', 'completed'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Không thể hủy đơn hàng đang giao hoặc đã hoàn tất'];
        }

        // 2. Chuyển trạng thái đơn hàng sang cancelled
        $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = :reason, cancelled_at = NOW() WHERE id = :id");
        $stmt->execute([':reason' => $reason, ':id' => $orderId]);

        // 3. Khóa và duyệt từng máy vật lý trong đơn: chỉ hoàn máy đang có status = 'reserved'
        $stmtUnits = $pdo->prepare("SELECT oi.product_id, oi.product_unit_id, pu.status AS unit_status 
                                    FROM order_items oi 
                                    JOIN product_units pu ON oi.product_unit_id = pu.id 
                                    WHERE oi.order_id = :order_id FOR UPDATE");
        $stmtUnits->execute([':order_id' => $orderId]);
        $units = $stmtUnits->fetchAll();

        foreach ($units as $unit) {
            if ($unit['unit_status'] === 'reserved') {
                // Hoàn máy về available
                $upUnit = $pdo->prepare("UPDATE product_units SET status = 'available' WHERE id = :uid AND status = 'reserved'");
                $upUnit->execute([':uid' => $unit['product_unit_id']]);
                
                if ($upUnit->rowCount() === 1) {
                    // Tăng tồn kho sản phẩm tương ứng
                    $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + 1 WHERE id = :pid")
                        ->execute([':pid' => $unit['product_id']]);
                }
            }
        }

        // 4. Hoàn lại lượt dùng mã giảm giá (nếu có)
        $pdo->prepare("UPDATE coupon_usages SET status = 'cancelled' WHERE order_id = :order_id AND status = 'used'")
            ->execute([':order_id' => $orderId]);

        // 5. Cập nhật thanh toán: CHỈ chuyển pending -> failed. TUYỆT ĐỐI giữ nguyên completed
        $pdo->prepare("UPDATE payments SET status = 'failed' WHERE order_id = :order_id AND status = 'pending'")
            ->execute([':order_id' => $orderId]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Hủy đơn hàng và hoàn trả kho thành công'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()];
    }
}
```

---

### 4. KẾT LUẬN VÀ BÀN GIAO

1. **Tính tương thích:** Cơ sở dữ liệu tương thích 100% với MySQL 8.0, MariaDB 10.4+, phpMyAdmin và XAMPP trên Windows.
2. **Tính bảo toàn:** Toàn bộ dữ liệu lịch sử tài chính, snapshot đơn giá và thông số được bảo tồn nguyên vẹn.
3. **Mức độ sẵn sàng:** CSDL đã được nạp đầy đủ dữ liệu mẫu chuẩn và kiểm thử 23/23 ca thành công. Dự án đã hoàn toàn sẵn sàng để bước sang giai đoạn phát triển mã nguồn PHP MVC thuần.
