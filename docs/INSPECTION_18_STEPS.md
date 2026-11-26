# Quy trình Kiểm định Kỹ thuật 18 bước (`product_inspections` & `inspection_items`) — TL Gaming

## 1. Định tuyến & Quyền hạn
- `GET /admin/serial/{id}/kiem-dinh` (`InspectionController::history`): Lịch sử kiểm định append-only (`inspection_date DESC, id DESC`).
- `GET /admin/serial/{id}/kiem-dinh/them` (`InspectionController::create`): Biểu mẫu lập biên bản 18 bước.
- `POST /admin/serial/{id}/kiem-dinh/them` (`InspectionController::store`): Lưu biên bản mới trong transaction (`FOR UPDATE` trên `product_units`), ghi nhận `inspector_id = Auth::id()` (từ chối giả mạo qua POST), và đồng bộ `products.stock_quantity`.
- `GET /admin/kiem-dinh/{inspectionId}` (`InspectionController::show`): Xem chi tiết 18 bước của biên bản (chỉ đọc, không cho sửa/xóa biên bản cũ).

## 2. Danh sách 18 bước chuẩn (`InspectionModel::OFFICIAL_18_STEPS`)
1. **Ngoại quan & Thân vỏ** (Bước 1–3): Vỏ máy/khung vỏ; Bản lề/độ rơ/chân đế; Tem niêm phong/ốc vít.
2. **Màn hình & Hiển thị** (Bước 4–6): Điểm chết pixel; Hở sáng/đốm/ám màu; Tần số quét & độ sáng tối đa.
3. **Hiệu năng & Tản nhiệt** (Bước 7–9): Stress test CPU (Cinebench/AIDA64); Stress test GPU (Furmark/3DMark); Tản nhiệt & độ ồn quạt.
4. **Bộ nhớ & Lưu trữ** (Bước 10–12): RAM (MemTest86); Sức khỏe SSD/HDD (CrystalDiskInfo); Tốc độ đọc/ghi (CrystalDiskMark).
5. **Bàn phím & Điều khiển** (Bước 13–14): Toàn bộ phím bấm & độ nảy; Touchpad & phím chuột.
6. **Pin & Nguồn điện** (Bước 15–16): Độ chai pin & chu kỳ sạc; Sạc nhanh & độ ổn định adapter.
7. **Kết nối & Ngoại vi** (Bước 17): Cổng USB, Type-C, HDMI, LAN.
8. **Âm thanh & Camera** (Bước 18): Loa ngoài, Micro thu âm & Webcam.

## 3. Quy tắc kiểm duyệt phía Server (`InspectionModel::createInspection`)
- Bắt buộc có đủ cả 18 bước (`1..18`), không thiếu bước, không trùng lặp, không cho phép cả 18 bước đều là `not_applicable`.
- Kết quả từng bước thuộc whitelist: `pass`, `warning`, `fail`, `not_applicable`.
- Kết luận tổng thể (`checklist_overall_result`):
  - Có ít nhất 1 bước `fail` → bắt buộc kết luận tổng thể là `failed`.
  - Có ít nhất 1 bước `warning` → chặn chọn `passed`; nếu chọn `conditional_pass` thì bắt buộc có `notes` không rỗng.
  - Chọn `conditional_pass` khi không có `warning` → từ chối.
- **Chính sách mở bán sau kiểm định (Prompt 18.1)**:
  - Tuỳ chọn `mark_available_if_passed` **CHỈ** chuyển máy từ `repairing` hoặc `returned` sang `available` (và tăng `row_version`) khi kết luận là `passed`.
  - Kết luận `conditional_pass` và `failed` **KHÔNG** tự động mở bán và không đủ điều kiện Checkout.
  - Tuyệt đối không thay đổi trạng thái của máy đang `reserved` hoặc `sold`.
  - Sau khi ghi nhận biên bản, `ProductUnitModel::syncProductStockCache()` cập nhật lại `products.stock_quantity` trong cùng transaction.
