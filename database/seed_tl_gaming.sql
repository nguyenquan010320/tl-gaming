-- =============================================================================
-- ĐỒ ÁN TỐT NGHIỆP: XÂY DỰNG WEBSITE BÁN MÁY TÍNH CŨ TL GAMING
-- FILE: seed_tl_gaming.sql
-- MỤC ĐÍCH: Dữ liệu mẫu phục vụ phát triển (Dev), kiểm thử (Test) và Demo bảo vệ
-- TƯƠNG THÍCH: MySQL 8.0 / MariaDB 10.4+ (XAMPP Windows)
-- =============================================================================

USE `tl_gaming`;

-- Thiết lập an toàn kiểm tra khóa ngoại
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- 1. BẢNG users: TÀI KHOẢN MẪU (1 ADMIN + 3 CUSTOMERS)
-- Mật khẩu được mã hóa an toàn bằng hàm password_hash(..., PASSWORD_BCRYPT) của PHP
-- - Admin   : admin@tlgaming.vn   | Mật khẩu: Admin@123456
-- - Khách 1 : nguyen.van.an@demo.vn | Mật khẩu: Customer@123
-- - Khách 2 : tran.thi.binh@demo.vn | Mật khẩu: Customer@123
-- - Khách 3 : le.hoang.long@demo.vn | Mật khẩu: Customer@123
-- =============================================================================
INSERT INTO `users` (`id`, `role`, `email`, `password_hash`, `full_name`, `phone`, `address`, `status`, `created_at`, `updated_at`)
VALUES
(1, 'admin', 'admin@tlgaming.vn', '$2y$12$Ag47Xej10Itp1rvzgvccpeCDbHK2i3Nzfrozj1IlUxihvlX16rQAG', 'Quản Trị Viên TL Gaming', '0988000111', 'Số 123 Cầu Giấy, Phường Dịch Vọng, Quận Cầu Giấy, Hà Nội', 'active', '2026-01-01 08:00:00', '2026-01-01 08:00:00'),
(2, 'customer', 'nguyen.van.an@demo.vn', '$2y$12$PXrgPDRuv.nE6m75BOgTqeRuqc3xVwZ6XEIgwIiA1vzoyf4XwLTgS', 'Nguyễn Văn An', '0912345678', 'Số 45 Giải Phóng, Phường Đồng Tâm, Quận Hai Bà Trưng, Hà Nội', 'active', '2026-01-10 09:30:00', '2026-01-10 09:30:00'),
(3, 'customer', 'tran.thi.binh@demo.vn', '$2y$12$PXrgPDRuv.nE6m75BOgTqeRuqc3xVwZ6XEIgwIiA1vzoyf4XwLTgS', 'Trần Thị Bình', '0987654321', 'Số 88 Nguyễn Trãi, Phường Thượng Đình, Quận Thanh Xuân, Hà Nội', 'active', '2026-01-15 14:15:00', '2026-01-15 14:15:00'),
(4, 'customer', 'le.hoang.long@demo.vn', '$2y$12$PXrgPDRuv.nE6m75BOgTqeRuqc3xVwZ6XEIgwIiA1vzoyf4XwLTgS', 'Lê Hoàng Long', '0903123456', 'Số 12 Lê Lợi, Phường Bến Nghé, Quận 1, TP. Hồ Chí Minh', 'active', '2026-02-01 10:00:00', '2026-02-01 10:00:00')
ON DUPLICATE KEY UPDATE 
    `role` = VALUES(`role`),
    `email` = VALUES(`email`),
    `password_hash` = VALUES(`password_hash`),
    `full_name` = VALUES(`full_name`),
    `phone` = VALUES(`phone`),
    `address` = VALUES(`address`),
    `status` = VALUES(`status`);


-- =============================================================================
-- 2. BẢNG categories: DANH MỤC SẢN PHẨM (CÂY CHA - CON)
-- =============================================================================
INSERT INTO `categories` (`id`, `parent_id`, `name`, `slug`, `description`, `display_order`, `is_active`, `created_at`, `updated_at`)
VALUES
(1, NULL, 'PC Gaming Cũ', 'pc-gaming-cu', 'Các dàn máy tính để bàn chơi game đã qua sử dụng, tuyển chọn linh kiện tốt', 1, 1, '2026-01-01 08:30:00', '2026-01-01 08:30:00'),
(2, NULL, 'Laptop Gaming Cũ', 'laptop-gaming-cu', 'Laptop chuyên game đồ họa cũ giá rẻ, nguyên bản chưa qua sửa chữa', 2, 1, '2026-01-01 08:30:00', '2026-01-01 08:30:00'),
(3, NULL, 'Linh Kiện Cũ', 'linh-kien-cu', 'Linh kiện máy tính cũ bóc máy, bao test đổi trả uy tín', 3, 1, '2026-01-01 08:30:00', '2026-01-01 08:30:00'),
(4, NULL, 'Màn Hình Cũ', 'man-hinh-cu', 'Màn hình máy tính cũ tần số quét cao 144Hz - 240Hz sắc nét', 4, 1, '2026-01-01 08:30:00', '2026-01-01 08:30:00'),
(5, 3, 'Card Đồ Họa Cũ (VGA)', 'vga-cu', 'VGA cũ chiến mượt game AAA, kiểm định nhiệt độ Furmark kĩ lưỡng', 1, 1, '2026-01-01 08:30:00', '2026-01-01 08:30:00'),
(6, 3, 'Bộ Vi Xử Lý Cũ (CPU)', 'cpu-cu', 'Chip xử lý Intel và AMD cũ hoạt động ổn định, giá cực tốt', 2, 1, '2026-01-01 08:30:00', '2026-01-01 08:30:00')
ON DUPLICATE KEY UPDATE 
    `parent_id` = VALUES(`parent_id`),
    `name` = VALUES(`name`),
    `slug` = VALUES(`slug`),
    `description` = VALUES(`description`),
    `display_order` = VALUES(`display_order`),
    `is_active` = VALUES(`is_active`);


-- =============================================================================
-- 3. BẢNG products: 13 DÒNG SẢN PHẨM MẪU ĐẠI DIỆN CHO CÁC DANH MỤC
-- Lưu ý: stock_quantity phản ánh đúng số máy có trạng thái 'available' trong kho
-- has_serials = 1 cho máy có số Serial riêng (PC, Laptop, VGA, CPU, Màn hình)
-- =============================================================================
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `sku`, `summary`, `description`, `price`, `original_price`, `warranty_months`, `condition_overview`, `stock_quantity`, `has_serials`, `thumbnail`, `status`, `created_at`, `updated_at`)
VALUES
-- Danh mục 1: PC Gaming Cũ
(1, 1, 'PC Gaming TL-01 Core i5-12400F / RTX 2060S / RAM 16GB / SSD 512GB', 'pc-gaming-tl-01-i5-12400f-rtx-2060s', 'SKU-PC-01', 'Dàn PC Gaming quốc dân chiến tốt GTA V, Valorant, CS2, FO4 ở mức đồ họa cao.', 'Cấu hình cân bằng hoàn hảo giữa vi xử lý Intel Core i5 12400F thế hệ 12 và Card đồ họa RTX 2060 Super 8GB VRAM. Bộ nguồn 650W chuẩn 80 Plus Bronze đảm bảo độ bền tối đa.', 11500000, 14500000, 12, 'Linh kiện tuyển chọn 98-99%, main nguồn new', 1, 1, '/uploads/products/pc-gaming-01.webp', 'published', '2026-01-10 10:00:00', '2026-01-10 10:00:00'),
(2, 1, 'PC Gaming TL-02 Ryzen 5 5600X / RTX 3060 12GB / RAM 32GB / SSD 1TB', 'pc-gaming-tl-02-r5-5600x-rtx-3060', 'SKU-PC-02', 'Cỗ máy đồ họa và gaming chuyên nghiệp, livestream mượt mà.', 'Bộ vi xử lý AMD Ryzen 5 5600X 6 nhân 12 luồng kết hợp cùng RTX 3060 12GB giúp render video 4K và chơi các tựa game Cyberpunk 2077, Black Myth Wukong cực mượt.', 15800000, 19500000, 12, 'Đẹp 99% nguyên bản, quạt tản nhiệt khí LED RGB', 2, 1, '/uploads/products/pc-gaming-02.webp', 'published', '2026-01-10 10:00:00', '2026-01-10 10:00:00'),
(3, 1, 'PC Đồ Họa Core i7-10700 / GTX 1660 Ti 6GB / RAM 16GB / SSD 500GB', 'pc-do-hoa-i7-10700-gtx-1660ti', 'SKU-PC-03', 'Máy trạm đồ họa 2D 3D Photoshop, AutoCAD, Premiere Pro giá học sinh sinh viên.', 'Vi xử lý 8 nhân 16 luồng mạnh mẽ kết hợp cùng Card GTX 1660 Ti 6GB GDDR6 mát mẻ, tiết kiệm điện.', 9800000, 12500000, 6, 'Hình thức 95%, hoạt động 100% chức năng', 1, 1, '/uploads/products/pc-gaming-03.webp', 'published', '2026-01-10 10:00:00', '2026-01-10 10:00:00'),

-- Danh mục 2: Laptop Gaming Cũ
(4, 2, 'Laptop Dell Gaming G15 5515 Ryzen 7 5800H / RTX 3060 / 16GB / 512GB / 15.6" 120Hz', 'dell-gaming-g15-5515-r7-5800h-rtx-3060', 'SKU-LAP-01', 'Dòng laptop gaming nồi đồng cối đá tản nhiệt cực mát của Dell.', 'Thiết kế hầm hố chuẩn quân đội, màn hình 120Hz chống chói IPS, bàn phím gõ nảy, hiệu năng mạnh mẽ cân mọi tác vụ nặng.', 16800000, 23000000, 12, 'Like New 99% xách tay Nhật, pin zin tốt', 1, 1, '/uploads/products/dell-g15.webp', 'published', '2026-01-12 11:00:00', '2026-01-12 11:00:00'),
(5, 2, 'Laptop Asus TUF Gaming F15 FX506HC Core i5-11400H / RTX 3050 / 16GB / 512GB / 144Hz', 'asus-tuf-gaming-f15-fx506hc-i5-11400h-rtx-3050', 'SKU-LAP-02', 'Laptop gaming quốc dân màn 144Hz thiết kế mỏng nhẹ hiện đại.', 'Đạt chuẩn độ bền quân sự MIL-STD-810H, bàn phím Aura RGB bắt mắt, Card đồ họa RTX 3050 hỗ trợ công nghệ DLSS tăng tốc khung hình.', 13900000, 18500000, 6, 'Đẹp 98% nguyên tem niêm phong DGW', 1, 1, '/uploads/products/asus-tuf-f15.webp', 'published', '2026-01-12 11:00:00', '2026-01-12 11:00:00'),
(6, 2, 'Laptop Lenovo Legion 5 15ACH6 Ryzen 5 5600H / GTX 1650 / 16GB / 512GB / 15.6" 120Hz', 'lenovo-legion-5-15ach6-r5-5600h-gtx-1650', 'SKU-LAP-03', 'Huyền thoại laptop gaming tản nhiệt số 1 phân khúc giá rẻ.', 'Bàn phím Legion TrueStrike gõ sướng nhất làng laptop gaming, cổng kết nối đưa ra sau gọn gàng.', 12200000, 16000000, 6, '95% xước nhẹ nắp lưng, màn hình zin đẹp', 1, 1, '/uploads/products/legion-5.webp', 'published', '2026-01-12 11:00:00', '2026-01-12 11:00:00'),
(7, 2, 'Laptop Acer Nitro 5 Tiger AN515-58 Core i7-12700H / RTX 3060 / 16GB / 512GB / 144Hz', 'acer-nitro-5-tiger-i7-12700h-rtx-3060', 'SKU-LAP-04', 'Dòng Nitro 5 Tiger lột xác toàn diện thiết kế và hiệu năng siêu khủng.', 'CPU Core i7 thế hệ 12 với 14 nhân 20 luồng cực khỏe, Card RTX 3060 full công suất 140W max TGP.', 19500000, 27000000, 12, '99% hàng chính hãng hết bảo hành hãng, shop bao test 1 năm', 1, 1, '/uploads/products/acer-nitro-5.webp', 'published', '2026-01-12 11:00:00', '2026-01-12 11:00:00'),

-- Danh mục 5: Card Đồ Họa Cũ (VGA)
(8, 5, 'VGA Asus ROG Strix GeForce RTX 2060 Super 8GB GDDR6 Cũ', 'vga-asus-rog-strix-rtx-2060-super-8gb', 'SKU-VGA-01', 'Bản Strix 3 quạt tản nhiệt mát nhất dòng 2060S, LED RGB rực rỡ.', 'Bản cao cấp nhất của Asus với heatsink dày cộp, backplate kim loại cứng cáp, nhiệt độ full load chỉ loanh quanh 62 độ C.', 4600000, 6500000, 3, 'Hàng tuyển phòng máy gia đình, mạch sáng bóng không rỉ sét', 1, 1, '/uploads/products/vga-rog-strix-2060s.webp', 'published', '2026-01-15 09:00:00', '2026-01-15 09:00:00'),
(9, 5, 'VGA MSI Gaming X Trio GeForce RTX 3070 8GB GDDR6 Cũ', 'vga-msi-gaming-x-trio-rtx-3070-8gb', 'SKU-VGA-02', 'Dòng Gaming X Trio đỉnh cao, chiến game 2K 4K max setting.', 'Thiết kế cánh quạt Torx Fan 4.0 độc quyền, hỗ trợ ép xung nhẹ tại nhà máy, hiệu năng tương đương RTX 2080 Ti.', 7800000, 11000000, 6, 'Đẹp 98% còn tem Void ốc zin', 1, 1, '/uploads/products/vga-msi-3070.webp', 'published', '2026-01-15 09:00:00', '2026-01-15 09:00:00'),

-- Danh mục 6: Bộ Vi Xử Lý Cũ (CPU)
(10, 6, 'CPU Intel Core i5-12400F Tray Cũ (Socket LGA1700 / 6 Nhân 12 Luồng)', 'cpu-intel-core-i5-12400f-cu', 'SKU-CPU-01', 'Bộ vi xử lý quốc dân cho các dàn máy chơi game thế hệ mới.', 'Xung nhịp tối đa lên tới 4.4GHz, không tích hợp iGPU giúp tối ưu giá thành và nhiệt độ hoạt động.', 2500000, 3200000, 12, 'Chân tiếp xúc vàng óng không móp méo cấn góc', 2, 1, '/uploads/products/cpu-i5-12400f.webp', 'published', '2026-01-15 09:00:00', '2026-01-15 09:00:00'),
(11, 6, 'CPU AMD Ryzen 7 5700X Cũ (Socket AM4 / 8 Nhân 16 Luồng)', 'cpu-amd-ryzen-7-5700x-cu', 'SKU-CPU-02', 'Chip 8 nhân cực mát công suất chỉ 65W TDP cho anh em cày cuốc giả lập.', 'Kiến trúc Zen 3 tối ưu hóa độ trễ, bộ nhớ đệm L3 Cache 32MB hỗ trợ đắc lực cho game thủ esports.', 3600000, 4800000, 12, 'Chân cắm thẳng tắp nguyên vẹn 100%', 1, 1, '/uploads/products/cpu-r7-5700x.webp', 'published', '2026-01-15 09:00:00', '2026-01-15 09:00:00'),

-- Danh mục 4: Màn Hình Cũ
(12, 4, 'Màn Hình Asus TUF Gaming VG249Q 24 inch IPS 144Hz 1ms Cũ', 'man-hinh-asus-tuf-vg249q-24-inch-144hz', 'SKU-MON-01', 'Màn hình chuẩn eSports góc nhìn rộng tấm nền IPS, chân đế xoay nâng hạ.', 'Tần số quét 144Hz thời gian phản hồi 1ms MPRT, công nghệ khử xé hình FreeSync Premium cực mượt.', 2650000, 3900000, 6, 'Màn không điểm chết, đủ chân đế zin và cáp DisplayPort', 1, 1, '/uploads/products/man-hinh-asus-vg249q.webp', 'published', '2026-01-15 09:00:00', '2026-01-15 09:00:00'),
(13, 4, 'Màn Hình Cong Samsung Odyssey G5 LC27G55T 27 inch 2K 144Hz 1ms HDR10 Cũ', 'man-hinh-samsung-odyssey-g5-27-inch-2k-144hz', 'SKU-MON-02', 'Màn hình cong 1000R ôm trọn tầm mắt, độ phân giải 2K sắc nét.', 'Trải nghiệm xem phim và chơi game góc nhìn thứ nhất chìm đắm với độ cong hoàn hảo theo mắt người.', 3950000, 5800000, 6, 'Đẹp keng 99% đủ nguồn adapter zin', 1, 1, '/uploads/products/man-hinh-samsung-g5.webp', 'published', '2026-01-15 09:00:00', '2026-01-15 09:00:00'),

-- Danh mục 3: Linh Kiện Cũ (Sản phẩm quản lý theo số lượng has_serials = 0, không dùng serial riêng)
(14, 3, 'RAM Desktop Kingston Fury Beast 8GB DDR4 3200MHz Cũ (Quản lý theo số lượng)', 'ram-desktop-kingston-fury-beast-8gb-ddr4-3200mhz-cu', 'SKU-RAM-01', 'Thanh RAM lẻ nâng cấp máy bàn, bóc máy văn phòng đẹp như mới, quản lý tồn kho theo số lượng.', 'Tản nhiệt nhôm đen nhám, hỗ trợ XMP 2.0 chạy chuẩn bus 3200MHz ổn định tuyệt đối.', 450000, 650000, 12, 'Đẹp 99% chân vàng sáng bóng, hàng số lượng', 10, 0, '/uploads/products/ram-kingston-8gb.webp', 'published', '2026-01-15 09:00:00', '2026-01-15 09:00:00')
ON DUPLICATE KEY UPDATE 
    `category_id` = VALUES(`category_id`),
    `name` = VALUES(`name`),
    `slug` = VALUES(`slug`),
    `sku` = VALUES(`sku`),
    `summary` = VALUES(`summary`),
    `description` = VALUES(`description`),
    `price` = VALUES(`price`),
    `original_price` = VALUES(`original_price`),
    `warranty_months` = VALUES(`warranty_months`),
    `condition_overview` = VALUES(`condition_overview`),
    `stock_quantity` = VALUES(`stock_quantity`),
    `has_serials` = VALUES(`has_serials`),
    `thumbnail` = VALUES(`thumbnail`),
    `status` = VALUES(`status`);


-- =============================================================================
-- 4. BẢNG product_images: THƯ VIỆN HÌNH ẢNH MẪU
-- =============================================================================
INSERT INTO `product_images` (`id`, `product_id`, `image_url`, `alt_text`, `sort_order`, `is_primary`, `created_at`)
VALUES
(1, 1, '/uploads/products/pc-01-main.webp', 'Mặt trước vỏ case kính cường lực PC Gaming TL-01', 1, 1, '2026-01-10 10:05:00'),
(2, 1, '/uploads/products/pc-01-side.webp', 'Nội thất tản nhiệt và quạt RGB PC Gaming TL-01', 2, 0, '2026-01-10 10:05:00'),
(3, 2, '/uploads/products/pc-02-main.webp', 'Ảnh tổng thể PC Gaming TL-02 Ryzen 5', 1, 1, '2026-01-10 10:05:00'),
(4, 4, '/uploads/products/dell-g15-main.webp', 'Ảnh chính diện Dell Gaming G15', 1, 1, '2026-01-12 11:05:00'),
(5, 4, '/uploads/products/dell-g15-keyboard.webp', 'Bàn phím và touchpad Dell Gaming G15', 2, 0, '2026-01-12 11:05:00'),
(6, 5, '/uploads/products/asus-f15-main.webp', 'Ảnh chính diện Asus TUF Gaming F15', 1, 1, '2026-01-12 11:05:00'),
(7, 8, '/uploads/products/vga-2060s-main.webp', 'Ảnh 3 quạt VGA Asus ROG Strix 2060S', 1, 1, '2026-01-15 09:05:00'),
(8, 9, '/uploads/products/vga-3070-main.webp', 'Ảnh VGA MSI RTX 3070 Gaming X Trio', 1, 1, '2026-01-15 09:05:00'),
(9, 12, '/uploads/products/mon-asus-main.webp', 'Mặt trước màn hình Asus TUF 24 inch 144Hz', 1, 1, '2026-01-15 09:05:00')
ON DUPLICATE KEY UPDATE 
    `product_id` = VALUES(`product_id`),
    `image_url` = VALUES(`image_url`),
    `alt_text` = VALUES(`alt_text`),
    `sort_order` = VALUES(`sort_order`),
    `is_primary` = VALUES(`is_primary`);


-- =============================================================================
-- 5. BẢNG product_specs: CẤU HÌNH PHẦN CỨNG CHI TIẾT
-- =============================================================================
INSERT INTO `product_specs` (`id`, `product_id`, `group_name`, `spec_name`, `spec_value`, `sort_order`, `created_at`, `updated_at`)
VALUES
-- PC 1
(1, 1, 'Phần cứng cốt lõi', 'Bộ vi xử lý (CPU)', 'Intel Core i5-12400F (6 nhân 12 luồng, Turbo 4.4GHz)', 1, '2026-01-10 10:10:00', '2026-01-10 10:10:00'),
(2, 1, 'Phần cứng cốt lõi', 'Card đồ họa (VGA)', 'NVIDIA GeForce RTX 2060 Super 8GB GDDR6', 2, '2026-01-10 10:10:00', '2026-01-10 10:10:00'),
(3, 1, 'Bộ nhớ & Lưu trữ', 'Bộ nhớ trong (RAM)', '16GB (2x8GB) DDR4 3200MHz tản thép', 3, '2026-01-10 10:10:00', '2026-01-10 10:10:00'),
(4, 1, 'Bộ nhớ & Lưu trữ', 'Ổ cứng lưu trữ', 'SSD 512GB M.2 NVMe PCIe Gen3', 4, '2026-01-10 10:10:00', '2026-01-10 10:10:00'),
(5, 1, 'Bo mạch & Nguồn', 'Bo mạch chủ (Mainboard)', 'Chipset B660M Pro Gaming', 5, '2026-01-10 10:10:00', '2026-01-10 10:10:00'),
(6, 1, 'Bo mạch & Nguồn', 'Bộ nguồn (PSU)', '650W 80 Plus Bronze công suất thực', 6, '2026-01-10 10:10:00', '2026-01-10 10:10:00'),

-- Laptop Dell G15
(7, 4, 'Cấu hình chi tiết', 'Bộ vi xử lý (CPU)', 'AMD Ryzen 7 5800H (8 nhân 16 luồng, 3.2GHz - 4.4GHz)', 1, '2026-01-12 11:10:00', '2026-01-12 11:10:00'),
(8, 4, 'Cấu hình chi tiết', 'Card đồ họa (VGA)', 'NVIDIA GeForce RTX 3060 6GB GDDR6 115W', 2, '2026-01-12 11:10:00', '2026-01-12 11:10:00'),
(9, 4, 'Màn hình hiển thị', 'Màn hình', '15.6 inch FHD (1920x1080), 120Hz, WVA Anti-Glare', 3, '2026-01-12 11:10:00', '2026-01-12 11:10:00'),
(10, 4, 'Pin & Nguồn', 'Dung lượng pin', '6-Cell 86Whr (sức khỏe pin thực tế ~92%)', 4, '2026-01-12 11:10:00', '2026-01-12 11:10:00'),

-- VGA MSI RTX 3070
(11, 9, 'Thông số VGA', 'Xung nhịp GPU Boost', '1830 MHz (Gaming & Silent Mode)', 1, '2026-01-15 09:10:00', '2026-01-15 09:10:00'),
(12, 9, 'Thông số VGA', 'Bộ nhớ VRAM', '8GB GDDR6 256-bit 14 Gbps', 2, '2026-01-15 09:10:00', '2026-01-15 09:10:00'),
(13, 9, 'Nguồn đề xuất', 'Nguồn khuyến nghị', 'Tối thiểu 650W (Cổng nguồn 2x 8-pin)', 3, '2026-01-15 09:10:00', '2026-01-15 09:10:00')
ON DUPLICATE KEY UPDATE 
    `product_id` = VALUES(`product_id`),
    `group_name` = VALUES(`group_name`),
    `spec_name` = VALUES(`spec_name`),
    `spec_value` = VALUES(`spec_value`),
    `sort_order` = VALUES(`sort_order`);


-- =============================================================================
-- 6. BẢNG product_units: CÁ THỂ MÁY CŨ VẬT LÝ TRONG KHO (SERIAL DUY NHẤT)
-- Quản lý vòng đời serial: available, reserved (giữ chỗ đơn), sold (đã bán)
-- Tổng số máy available khớp chính xác với stock_quantity của từng sản phẩm!
-- =============================================================================
INSERT INTO `product_units` (`id`, `product_id`, `serial_number`, `hardware_condition_detail`, `battery_health`, `cosmetic_grade`, `status`, `import_price`, `imported_at`, `created_at`, `updated_at`)
VALUES
-- Máy thuộc sản phẩm 1 (PC Gaming TL-01) - 1 reserved (Đơn 1), 1 available -> stock = 1
(1, 1, 'TLG-PC-12400F-SN001', 'Nội thất sạch đẹp, keo MX-4 mới tra, case mặt kính không vết xước', NULL, 'Grade A', 'reserved', 9200000, '2026-01-10 09:00:00', '2026-01-10 09:00:00', '2026-02-10 15:00:00'),
(2, 1, 'TLG-PC-12400F-SN002', 'Linh kiện tuyển chọn nguyên bản, nguồn Seasonic 650W', NULL, 'Grade A', 'available', 9200000, '2026-01-10 09:00:00', '2026-01-10 09:00:00', '2026-01-10 09:00:00'),

-- Máy thuộc sản phẩm 2 (PC Gaming TL-02) - 2 available -> stock = 2
(3, 2, 'TLG-PC-5600X-SN001', 'Full box linh kiện, quạt LED ARGB đồng bộ mainboard', NULL, 'Grade A', 'available', 12800000, '2026-01-10 09:00:00', '2026-01-10 09:00:00', '2026-01-10 09:00:00'),
(4, 2, 'TLG-PC-5600X-SN002', 'Vỏ case Montech kính cường lực, card RTX 3060 mát mẻ', NULL, 'Grade A', 'available', 12800000, '2026-01-10 09:00:00', '2026-01-10 09:00:00', '2026-01-10 09:00:00'),

-- Máy thuộc sản phẩm 3 (PC Đồ Họa i7-10700) - 1 available -> stock = 1
(5, 3, 'TLG-PC-10700-SN001', 'Xước dăm nhẹ nắp hông kim loại, hiệu năng ổn định', NULL, 'Grade B', 'available', 7800000, '2026-01-10 09:00:00', '2026-01-10 09:00:00', '2026-01-10 09:00:00'),

-- Máy thuộc sản phẩm 4 (Dell Gaming G15) - 1 reserved (Đơn 2), 1 available -> stock = 1
(6, 4, 'TLG-LAP-DELLG15-SN101', 'Bản lề chắc chắn, bàn phím gõ êm, màn hình đẹp không ám ố', 92, 'Grade A', 'reserved', 13500000, '2026-01-12 10:00:00', '2026-01-12 10:00:00', '2026-02-12 10:00:00'),
(7, 4, 'TLG-LAP-DELLG15-SN102', 'Ngoại hình 99%, pin còn 95%, sạc Oval 240W zin theo máy', 95, 'Grade A', 'available', 13500000, '2026-01-12 10:00:00', '2026-01-12 10:00:00', '2026-01-12 10:00:00'),

-- Máy thuộc sản phẩm 5 (Asus TUF F15) - 1 reserved (Đơn 3), 1 available -> stock = 1
(8, 5, 'TLG-LAP-ASUSTUF-SN201', 'Vỏ nhôm phay xước nhẹ góc dưới, phím LED RGB đầy đủ', 88, 'Grade B', 'reserved', 11000000, '2026-01-12 10:00:00', '2026-01-12 10:00:00', '2026-02-14 08:30:00'),
(9, 5, 'TLG-LAP-ASUSTUF-SN202', 'Ngoại hình đẹp 99%, pin còn 94%, phụ kiện sạc zin', 94, 'Grade A', 'available', 11000000, '2026-01-12 10:00:00', '2026-01-12 10:00:00', '2026-01-12 10:00:00'),

-- Máy thuộc sản phẩm 6 (Lenovo Legion 5) - 1 available -> stock = 1
(10, 6, 'TLG-LAP-LEGION5-SN301', 'Mặt A dán skin bảo vệ, các cổng kết nối sáng bóng', 89, 'Grade A', 'available', 9800000, '2026-01-12 10:00:00', '2026-01-12 10:00:00', '2026-01-12 10:00:00'),

-- Máy thuộc sản phẩm 7 (Acer Nitro 5 Tiger) - 1 available -> stock = 1
(11, 7, 'TLG-LAP-NITRO5-SN401', 'Máy đẹp như bóc hộp, kèm củ sạc zin 230W', 96, 'Grade A', 'available', 15500000, '2026-01-12 10:00:00', '2026-01-12 10:00:00', '2026-01-12 10:00:00'),

-- Máy thuộc sản phẩm 8 (VGA Asus Strix 2060S) - 1 available -> stock = 1
(12, 8, 'TLG-VGA-2060S-SN501', 'Quạt quay êm không kêu rít, Furmark 15 phút max 64 độ C', NULL, 'Grade A', 'available', 3700000, '2026-01-15 08:30:00', '2026-01-15 08:30:00', '2026-01-15 08:30:00'),

-- Máy thuộc sản phẩm 9 (VGA MSI RTX 3070) - Đơn 5 hủy nên máy hoàn về available -> stock = 1
(13, 9, 'TLG-VGA-3070-SN601', 'Card nguyên bản từ dàn game cyber cao cấp, tem void nguyên vẹn', NULL, 'Grade A', 'available', 6200000, '2026-01-15 08:30:00', '2026-01-15 08:30:00', '2026-02-18 16:00:00'),

-- Máy thuộc sản phẩm 10 (CPU i5-12400F) - 2 available -> stock = 2
(14, 10, 'TLG-CPU-12400F-SN701', 'Lưng chip sạch keo, chân tiếp xúc đẹp', NULL, 'Grade A', 'available', 2000000, '2026-01-15 08:30:00', '2026-01-15 08:30:00', '2026-01-15 08:30:00'),
(15, 10, 'TLG-CPU-12400F-SN702', 'Lưng chip có vết cài ngàm socket bình thường', NULL, 'Grade A', 'available', 2000000, '2026-01-15 08:30:00', '2026-01-15 08:30:00', '2026-01-15 08:30:00'),

-- Máy thuộc sản phẩm 11 (CPU Ryzen 7 5700X) - 1 available -> stock = 1
(16, 11, 'TLG-CPU-5700X-SN801', 'Đầy đủ hộp nhựa bảo vệ, chân socket thẳng tắp', NULL, 'Grade A', 'available', 2900000, '2026-01-15 08:30:00', '2026-01-15 08:30:00', '2026-01-15 08:30:00'),

-- Máy thuộc sản phẩm 12 (Màn Asus TUF 24") - 1 sold (Đơn 4), 1 available -> stock = 1
(17, 12, 'TLG-MON-ASUS-SN901', 'Tấm nền IPS nguyên bản, đã bán và bàn giao cho khách', NULL, 'Grade A', 'sold', 2100000, '2026-01-15 08:30:00', '2026-01-15 08:30:00', '2026-02-15 11:00:00'),
(18, 12, 'TLG-MON-ASUS-SN902', 'Màn hình sáng đều không hở sáng, đầy đủ hộp', NULL, 'Grade A', 'available', 2100000, '2026-01-15 08:30:00', '2026-01-15 08:30:00', '2026-01-15 08:30:00'),

-- Máy thuộc sản phẩm 13 (Màn Samsung Odyssey G5) - 1 available -> stock = 1
(19, 13, 'TLG-MON-SAMS-SN903', 'Màn cong không xước mặt hiển thị, nguồn adapter zin', NULL, 'Grade A', 'available', 3100000, '2026-01-15 08:30:00', '2026-01-15 08:30:00', '2026-01-15 08:30:00')
ON DUPLICATE KEY UPDATE 
    `product_id` = VALUES(`product_id`),
    `serial_number` = VALUES(`serial_number`),
    `hardware_condition_detail` = VALUES(`hardware_condition_detail`),
    `battery_health` = VALUES(`battery_health`),
    `cosmetic_grade` = VALUES(`cosmetic_grade`),
    `status` = VALUES(`status`),
    `import_price` = VALUES(`import_price`);


-- =============================================================================
-- 7. BẢNG product_inspections: BIÊN BẢN KIỂM ĐỊNH KỸ THUẬT TỔNG THỂ
-- =============================================================================
INSERT INTO `product_inspections` (`id`, `unit_id`, `inspector_id`, `inspection_date`, `checklist_overall_result`, `notes`, `created_at`)
VALUES
-- Biên bản test máy 1 (PC TL-01)
(1, 1, 1, '2026-01-10 14:00:00', 'passed', 'Nhiệt độ Furmark 65 độ C, CrystalDiskInfo Good 100%, nhiệt độ phòng 25 độ', '2026-01-10 14:30:00'),
-- Biên bản test máy 6 (Laptop Dell G15)
(2, 6, 1, '2026-01-12 15:00:00', 'passed', 'Pin chai 8%, màn hình không điểm chết, bàn phím gõ êm, âm thanh to rõ', '2026-01-12 15:30:00'),
-- Biên bản test máy 8 (Laptop Asus TUF F15)
(3, 8, 1, '2026-01-12 16:00:00', 'conditional_pass', 'Pin chai 12% theo tuổi thọ (vẫn dùng được > 2.5h tác vụ văn phòng)', '2026-01-12 16:30:00'),
-- Biên bản test máy 13 (VGA MSI 3070)
(4, 13, 1, '2026-01-15 11:00:00', 'passed', '3DMark TimeSpy đạt chuẩn điểm số gốc, quạt êm không rung lắc', '2026-01-15 11:30:00'),
-- Biên bản test máy 17 (Màn hình Asus TUF 24")
(5, 17, 1, '2026-01-15 14:00:00', 'passed', 'Màn hình không điểm chết, không sọc chỉ, tần số quét nhận đủ 144Hz', '2026-01-15 14:30:00')
ON DUPLICATE KEY UPDATE 
    `unit_id` = VALUES(`unit_id`),
    `inspector_id` = VALUES(`inspector_id`),
    `inspection_date` = VALUES(`inspection_date`),
    `checklist_overall_result` = VALUES(`checklist_overall_result`),
    `notes` = VALUES(`notes`);


-- =============================================================================
-- 8. BẢNG inspection_items: CHI TIẾT 18 BƯỚC KIỂM ĐỊNH KỸ THUẬT CHO 5 MÁY MẪU (90 BẢN GHI)
INSERT INTO `inspection_items` (`id`, `inspection_id`, `step_number`, `group_name`, `item_name`, `result`, `notes`, `created_at`)
VALUES
(1, 1, 1, 'Ngoại quan & Thân vỏ', 'Kiểm tra vết cấn móp, trầy xước, nứt vỡ khung vỏ', 'pass', 'Vỏ case nguyên vẹn, kính cường lực không xước', '2026-01-10 14:30:00'),
(2, 1, 2, 'Ngoại quan & Thân vỏ', 'Kiểm tra bản lề, độ gập mở, độ rơ và chân đế cao su', 'not_applicable', 'Không áp dụng cho dàn máy PC để bàn', '2026-01-10 14:30:00'),
(3, 1, 3, 'Ngoại quan & Thân vỏ', 'Kiểm tra tem bảo hành niêm phong, ốc vít nguyên bản', 'pass', 'Tem niêm phong linh kiện đầy đủ', '2026-01-10 14:30:00'),
(4, 1, 4, 'Màn hình & Hiển thị', 'Kiểm tra điểm chết màn hình (Dead/Stuck Pixel test)', 'not_applicable', 'Không áp dụng cho dàn máy PC để bàn', '2026-01-10 14:30:00'),
(5, 1, 5, 'Màn hình & Hiển thị', 'Kiểm tra hở sáng viền, đốm phản quang, ám ố màu sắc', 'not_applicable', 'Không áp dụng cho dàn máy PC để bàn', '2026-01-10 14:30:00'),
(6, 1, 6, 'Màn hình & Hiển thị', 'Kiểm tra tần số quét chuẩn và độ sáng tối đa', 'not_applicable', 'Không áp dụng cho dàn máy PC để bàn', '2026-01-10 14:30:00'),
(7, 1, 7, 'Hiệu năng & Tản nhiệt', 'Stress test CPU Intel/AMD bằng Cinebench / AIDA64', 'pass', 'CPU i5-12400F Cinebench R23 max 68 độ C', '2026-01-10 14:30:00'),
(8, 1, 8, 'Hiệu năng & Tản nhiệt', 'Stress test Card đồ họa GPU bằng Furmark / 3DMark', 'pass', 'Furmark RTX 2060S ổn định ở 65 độ C', '2026-01-10 14:30:00'),
(9, 1, 9, 'Hiệu năng & Tản nhiệt', 'Kiểm tra hệ thống tản nhiệt và độ ồn của quạt làm mát', 'pass', 'Quạt tản khí êm, tốc độ 1500 RPM', '2026-01-10 14:30:00'),
(10, 1, 10, 'Bộ nhớ & Lưu trữ', 'Kiểm tra lỗi bộ nhớ RAM bằng MemTest86', 'pass', 'MemTest86 4/4 Pass 0 lỗi', '2026-01-10 14:30:00'),
(11, 1, 11, 'Bộ nhớ & Lưu trữ', 'Kiểm tra sức khỏe ổ cứng SSD/HDD bằng CrystalDiskInfo', 'pass', 'CrystalDiskInfo Good 100%, 1200 giờ chạy', '2026-01-10 14:30:00'),
(12, 1, 12, 'Bộ nhớ & Lưu trữ', 'Đo tốc độ đọc/ghi thực tế ổ cứng SSD bằng CrystalDiskMark', 'pass', 'Tốc độ đọc 3200MB/s, ghi 2500MB/s', '2026-01-10 14:30:00'),
(13, 1, 13, 'Bàn phím & Điều khiển', 'Kiểm tra toàn bộ phím bấm và độ nảy bàn phím', 'not_applicable', 'Không áp dụng cho dàn máy PC để bàn', '2026-01-10 14:30:00'),
(14, 1, 14, 'Bàn phím & Điều khiển', 'Kiểm tra Touchpad cảm ứng đa điểm và phím chuột', 'not_applicable', 'Không áp dụng cho dàn máy PC để bàn', '2026-01-10 14:30:00'),
(15, 1, 15, 'Pin & Nguồn điện', 'Kiểm tra độ chai pin thực tế và số chu kỳ sạc', 'not_applicable', 'Không áp dụng cho dàn máy PC để bàn', '2026-01-10 14:30:00'),
(16, 1, 16, 'Pin & Nguồn điện', 'Kiểm tra khả năng sạc nhanh và độ ổn định bộ sạc adapter', 'not_applicable', 'Không áp dụng cho dàn máy PC để bàn', '2026-01-10 14:30:00'),
(17, 1, 17, 'Kết nối & Ngoại vi', 'Kiểm tra các cổng kết nối ngoại vi USB, Type-C, HDMI, LAN', 'pass', 'Nhận đủ cổng USB 3.0, LAN 1Gbps, HDMI', '2026-01-10 14:30:00'),
(18, 1, 18, 'Âm thanh & Camera', 'Kiểm tra hệ thống Loa ngoài, Micro thu âm và Webcam', 'not_applicable', 'Không áp dụng cho dàn máy PC để bàn', '2026-01-10 14:30:00'),
(19, 2, 1, 'Ngoại quan & Thân vỏ', 'Kiểm tra vết cấn móp, trầy xước, nứt vỡ khung vỏ', 'pass', 'Ngoại hình Like New 99%, không cấn góc', '2026-01-12 15:30:00'),
(20, 2, 2, 'Ngoại quan & Thân vỏ', 'Kiểm tra bản lề, độ gập mở, độ rơ và chân đế cao su', 'pass', 'Bản lề chắc chắn, gập mở 1 tay trơn tru', '2026-01-12 15:30:00'),
(21, 2, 3, 'Ngoại quan & Thân vỏ', 'Kiểm tra tem bảo hành niêm phong, ốc vít nguyên bản', 'pass', 'Tem void ốc đáy còn nguyên bản', '2026-01-12 15:30:00'),
(22, 2, 4, 'Màn hình & Hiển thị', 'Kiểm tra điểm chết màn hình (Dead/Stuck Pixel test)', 'pass', 'Màn 120Hz IPS không điểm chết nào', '2026-01-12 15:30:00'),
(23, 2, 5, 'Màn hình & Hiển thị', 'Kiểm tra hở sáng viền, đốm phản quang, ám ố màu sắc', 'pass', 'Không hở sáng, màu sắc đồng đều', '2026-01-12 15:30:00'),
(24, 2, 6, 'Màn hình & Hiển thị', 'Kiểm tra tần số quét chuẩn và độ sáng tối đa', 'pass', 'Tần số quét nhận đúng 120Hz', '2026-01-12 15:30:00'),
(25, 2, 7, 'Hiệu năng & Tản nhiệt', 'Stress test CPU Intel/AMD bằng Cinebench / AIDA64', 'pass', 'Ryzen 7 5800H max 82 độ C khi tải nặng', '2026-01-12 15:30:00'),
(26, 2, 8, 'Hiệu năng & Tản nhiệt', 'Stress test Card đồ họa GPU bằng Furmark / 3DMark', 'pass', 'RTX 3060 TimeSpy 8800 điểm', '2026-01-12 15:30:00'),
(27, 2, 9, 'Hiệu năng & Tản nhiệt', 'Kiểm tra hệ thống tản nhiệt và độ ồn của quạt làm mát', 'pass', 'Hệ thống quạt kép tản nhiệt tốt', '2026-01-12 15:30:00'),
(28, 2, 10, 'Bộ nhớ & Lưu trữ', 'Kiểm tra lỗi bộ nhớ RAM bằng MemTest86', 'pass', 'RAM 16GB dual channel hoạt động chuẩn', '2026-01-12 15:30:00'),
(29, 2, 11, 'Bộ nhớ & Lưu trữ', 'Kiểm tra sức khỏe ổ cứng SSD/HDD bằng CrystalDiskInfo', 'pass', 'SSD NVMe Good 99%', '2026-01-12 15:30:00'),
(30, 2, 12, 'Bộ nhớ & Lưu trữ', 'Đo tốc độ đọc/ghi thực tế ổ cứng SSD bằng CrystalDiskMark', 'pass', 'Tốc độ đọc 3500MB/s', '2026-01-12 15:30:00'),
(31, 2, 13, 'Bàn phím & Điều khiển', 'Kiểm tra toàn bộ phím bấm và độ nảy bàn phím', 'pass', 'Phím LED trắng gõ nảy, không liệt phím', '2026-01-12 15:30:00'),
(32, 2, 14, 'Bàn phím & Điều khiển', 'Kiểm tra Touchpad cảm ứng đa điểm và phím chuột', 'pass', 'Touchpad mượt, cử chỉ đa điểm nhạy', '2026-01-12 15:30:00'),
(33, 2, 15, 'Pin & Nguồn điện', 'Kiểm tra độ chai pin thực tế và số chu kỳ sạc', 'pass', 'Dung lượng pin 86Wh, độ chai pin 8%', '2026-01-12 15:30:00'),
(34, 2, 16, 'Pin & Nguồn điện', 'Kiểm tra khả năng sạc nhanh và độ ổn định bộ sạc adapter', 'pass', 'Sạc nhanh Dell 240W chuẩn dòng điện', '2026-01-12 15:30:00'),
(35, 2, 17, 'Kết nối & Ngoại vi', 'Kiểm tra các cổng kết nối ngoại vi USB, Type-C, HDMI, LAN', 'pass', 'Cổng Type-C DP và USB hoạt động tốt', '2026-01-12 15:30:00'),
(36, 2, 18, 'Âm thanh & Camera', 'Kiểm tra hệ thống Loa ngoài, Micro thu âm và Webcam', 'pass', 'Loa to rõ không rè, mic và webcam nét', '2026-01-12 15:30:00'),
(37, 3, 1, 'Ngoại quan & Thân vỏ', 'Kiểm tra vết cấn móp, trầy xước, nứt vỡ khung vỏ', 'pass', 'Hình thức 98%, nắp máy xước dăm nhẹ', '2026-01-12 16:30:00'),
(38, 3, 2, 'Ngoại quan & Thân vỏ', 'Kiểm tra bản lề, độ gập mở, độ rơ và chân đế cao su', 'pass', 'Bản lề vững, góc mở tốt', '2026-01-12 16:30:00'),
(39, 3, 3, 'Ngoại quan & Thân vỏ', 'Kiểm tra tem bảo hành niêm phong, ốc vít nguyên bản', 'pass', 'Tem DGW phân phối chính hãng nguyên vẹn', '2026-01-12 16:30:00'),
(40, 3, 4, 'Màn hình & Hiển thị', 'Kiểm tra điểm chết màn hình (Dead/Stuck Pixel test)', 'pass', 'Màn hình không điểm chết', '2026-01-12 16:30:00'),
(41, 3, 5, 'Màn hình & Hiển thị', 'Kiểm tra hở sáng viền, đốm phản quang, ám ố màu sắc', 'pass', 'Viền dưới hơi hở sáng nhẹ (bình thường dòng TUF)', '2026-01-12 16:30:00'),
(42, 3, 6, 'Màn hình & Hiển thị', 'Kiểm tra tần số quét chuẩn và độ sáng tối đa', 'pass', 'Tần số quét 144Hz hoạt động tốt', '2026-01-12 16:30:00'),
(43, 3, 7, 'Hiệu năng & Tản nhiệt', 'Stress test CPU Intel/AMD bằng Cinebench / AIDA64', 'pass', 'i5-11400H 78 độ C', '2026-01-12 16:30:00'),
(44, 3, 8, 'Hiệu năng & Tản nhiệt', 'Stress test Card đồ họa GPU bằng Furmark / 3DMark', 'pass', 'RTX 3050 Furmark 68 độ C', '2026-01-12 16:30:00'),
(45, 3, 9, 'Hiệu năng & Tản nhiệt', 'Kiểm tra hệ thống tản nhiệt và độ ồn của quạt làm mát', 'pass', 'Tản nhiệt quạt êm', '2026-01-12 16:30:00'),
(46, 3, 10, 'Bộ nhớ & Lưu trữ', 'Kiểm tra lỗi bộ nhớ RAM bằng MemTest86', 'pass', 'RAM Pass test 100%', '2026-01-12 16:30:00'),
(47, 3, 11, 'Bộ nhớ & Lưu trữ', 'Kiểm tra sức khỏe ổ cứng SSD/HDD bằng CrystalDiskInfo', 'pass', 'SSD Good 98%', '2026-01-12 16:30:00'),
(48, 3, 12, 'Bộ nhớ & Lưu trữ', 'Đo tốc độ đọc/ghi thực tế ổ cứng SSD bằng CrystalDiskMark', 'pass', 'Tốc độ SSD đạt chuẩn Gen3', '2026-01-12 16:30:00'),
(49, 3, 13, 'Bàn phím & Điều khiển', 'Kiểm tra toàn bộ phím bấm và độ nảy bàn phím', 'pass', 'Bàn phím RGB đổi màu chuẩn', '2026-01-12 16:30:00'),
(50, 3, 14, 'Bàn phím & Điều khiển', 'Kiểm tra Touchpad cảm ứng đa điểm và phím chuột', 'pass', 'Touchpad mượt mà', '2026-01-12 16:30:00'),
(51, 3, 15, 'Pin & Nguồn điện', 'Kiểm tra độ chai pin thực tế và số chu kỳ sạc', 'warning', 'Pin chai 12% theo tuổi thọ (vẫn dùng được > 2.5h tác vụ văn phòng)', '2026-01-12 16:30:00'),
(52, 3, 16, 'Pin & Nguồn điện', 'Kiểm tra khả năng sạc nhanh và độ ổn định bộ sạc adapter', 'pass', 'Sạc Asus 180W chính hãng ổn định', '2026-01-12 16:30:00'),
(53, 3, 17, 'Kết nối & Ngoại vi', 'Kiểm tra các cổng kết nối ngoại vi USB, Type-C, HDMI, LAN', 'pass', 'Tất cả cổng cắm hoạt động bình thường', '2026-01-12 16:30:00'),
(54, 3, 18, 'Âm thanh & Camera', 'Kiểm tra hệ thống Loa ngoài, Micro thu âm và Webcam', 'pass', 'Loa kép DTS:X to, mic thu âm trong', '2026-01-12 16:30:00'),
(55, 4, 1, 'Ngoại quan & Thân vỏ', 'Kiểm tra vết cấn móp, trầy xước, nứt vỡ khung vỏ', 'pass', 'Backplate kim loại đẹp keng không cong vênh', '2026-01-15 11:30:00'),
(56, 4, 2, 'Ngoại quan & Thân vỏ', 'Kiểm tra bản lề, độ gập mở, độ rơ và chân đế cao su', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(57, 4, 3, 'Ngoại quan & Thân vỏ', 'Kiểm tra tem bảo hành niêm phong, ốc vít nguyên bản', 'pass', 'Tem Void trên ốc nguyên bản chưa bung mở', '2026-01-15 11:30:00'),
(58, 4, 4, 'Màn hình & Hiển thị', 'Kiểm tra điểm chết màn hình (Dead/Stuck Pixel test)', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(59, 4, 5, 'Màn hình & Hiển thị', 'Kiểm tra hở sáng viền, đốm phản quang, ám ố màu sắc', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(60, 4, 6, 'Màn hình & Hiển thị', 'Kiểm tra tần số quét chuẩn và độ sáng tối đa', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(61, 4, 7, 'Hiệu năng & Tản nhiệt', 'Stress test CPU Intel/AMD bằng Cinebench / AIDA64', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(62, 4, 8, 'Hiệu năng & Tản nhiệt', 'Stress test Card đồ họa GPU bằng Furmark / 3DMark', 'pass', '3DMark TimeSpy 13800 điểm, Furmark 66 độ C', '2026-01-15 11:30:00'),
(63, 4, 9, 'Hiệu năng & Tản nhiệt', 'Kiểm tra hệ thống tản nhiệt và độ ồn của quạt làm mát', 'pass', '3 quạt Torx 4.0 quay êm, không kêu rít', '2026-01-15 11:30:00'),
(64, 4, 10, 'Bộ nhớ & Lưu trữ', 'Kiểm tra lỗi bộ nhớ RAM bằng MemTest86', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(65, 4, 11, 'Bộ nhớ & Lưu trữ', 'Kiểm tra sức khỏe ổ cứng SSD/HDD bằng CrystalDiskInfo', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(66, 4, 12, 'Bộ nhớ & Lưu trữ', 'Đo tốc độ đọc/ghi thực tế ổ cứng SSD bằng CrystalDiskMark', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(67, 4, 13, 'Bàn phím & Điều khiển', 'Kiểm tra toàn bộ phím bấm và độ nảy bàn phím', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(68, 4, 14, 'Bàn phím & Điều khiển', 'Kiểm tra Touchpad cảm ứng đa điểm và phím chuột', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(69, 4, 15, 'Pin & Nguồn điện', 'Kiểm tra độ chai pin thực tế và số chu kỳ sạc', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(70, 4, 16, 'Pin & Nguồn điện', 'Kiểm tra khả năng sạc nhanh và độ ổn định bộ sạc adapter', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(71, 4, 17, 'Kết nối & Ngoại vi', 'Kiểm tra các cổng kết nối ngoại vi USB, Type-C, HDMI, LAN', 'pass', 'Xuất đủ 3 cổng DisplayPort và 1 HDMI', '2026-01-15 11:30:00'),
(72, 4, 18, 'Âm thanh & Camera', 'Kiểm tra hệ thống Loa ngoài, Micro thu âm và Webcam', 'not_applicable', 'Không áp dụng cho linh kiện Card màn hình VGA', '2026-01-15 11:30:00'),
(73, 5, 1, 'Ngoại quan & Thân vỏ', 'Kiểm tra vết cấn móp, trầy xước, nứt vỡ khung vỏ', 'pass', 'Khung viền mỏng đẹp, không xước mặt kính', '2026-01-15 14:30:00'),
(74, 5, 2, 'Ngoại quan & Thân vỏ', 'Kiểm tra bản lề, độ gập mở, độ rơ và chân đế cao su', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(75, 5, 3, 'Ngoại quan & Thân vỏ', 'Kiểm tra tem bảo hành niêm phong, ốc vít nguyên bản', 'pass', 'Tem xuất xưởng nguyên bản', '2026-01-15 14:30:00'),
(76, 5, 4, 'Màn hình & Hiển thị', 'Kiểm tra điểm chết màn hình (Dead/Stuck Pixel test)', 'pass', 'Test nền 5 màu không điểm chết pixel', '2026-01-15 14:30:00'),
(77, 5, 5, 'Màn hình & Hiển thị', 'Kiểm tra hở sáng viền, đốm phản quang, ám ố màu sắc', 'pass', 'Tấm nền IPS sáng đều, góc nhìn 178 độ', '2026-01-15 14:30:00'),
(78, 5, 6, 'Màn hình & Hiển thị', 'Kiểm tra tần số quét chuẩn và độ sáng tối đa', 'pass', 'Nhận đủ tần số quét 144Hz và FreeSync', '2026-01-15 14:30:00'),
(79, 5, 7, 'Hiệu năng & Tản nhiệt', 'Stress test CPU Intel/AMD bằng Cinebench / AIDA64', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(80, 5, 8, 'Hiệu năng & Tản nhiệt', 'Stress test Card đồ họa GPU bằng Furmark / 3DMark', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(81, 5, 9, 'Hiệu năng & Tản nhiệt', 'Kiểm tra hệ thống tản nhiệt và độ ồn của quạt làm mát', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(82, 5, 10, 'Bộ nhớ & Lưu trữ', 'Kiểm tra lỗi bộ nhớ RAM bằng MemTest86', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(83, 5, 11, 'Bộ nhớ & Lưu trữ', 'Kiểm tra sức khỏe ổ cứng SSD/HDD bằng CrystalDiskInfo', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(84, 5, 12, 'Bộ nhớ & Lưu trữ', 'Đo tốc độ đọc/ghi thực tế ổ cứng SSD bằng CrystalDiskMark', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(85, 5, 13, 'Bàn phím & Điều khiển', 'Kiểm tra toàn bộ phím bấm và độ nảy bàn phím', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(86, 5, 14, 'Bàn phím & Điều khiển', 'Kiểm tra Touchpad cảm ứng đa điểm và phím chuột', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(87, 5, 15, 'Pin & Nguồn điện', 'Kiểm tra độ chai pin thực tế và số chu kỳ sạc', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(88, 5, 16, 'Pin & Nguồn điện', 'Kiểm tra khả năng sạc nhanh và độ ổn định bộ sạc adapter', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00'),
(89, 5, 17, 'Kết nối & Ngoại vi', 'Kiểm tra các cổng kết nối ngoại vi USB, Type-C, HDMI, LAN', 'pass', 'Cổng DisplayPort, HDMI và ngõ audio nhận đủ', '2026-01-15 14:30:00'),
(90, 5, 18, 'Âm thanh & Camera', 'Kiểm tra hệ thống Loa ngoài, Micro thu âm và Webcam', 'not_applicable', 'Không áp dụng cho Màn hình hiển thị', '2026-01-15 14:30:00')
ON DUPLICATE KEY UPDATE 
    `inspection_id` = VALUES(`inspection_id`),
    `step_number` = VALUES(`step_number`),
    `group_name` = VALUES(`group_name`),
    `item_name` = VALUES(`item_name`),
    `result` = VALUES(`result`),
    `notes` = VALUES(`notes`);

-- =============================================================================
-- 9. BẢNG coupons: MÃ GIẢM GIÁ (VOUCHER)
-- - TLGAMING500K: Giảm 500,000đ cho đơn từ 10 triệu (Còn hạn)
-- - WELCOME10   : Giảm 10% tối đa 1,000,000đ cho đơn từ 5 triệu (Còn hạn)
-- - EXPIRED2025 : Giảm 15% (Đã hết hạn vào năm 2025 để demo kiểm tra voucher hết hạn)
-- - VIP2000K    : Giảm 2,000,000đ cho đơn từ 50 triệu (Đơn hàng thông thường không đủ điều kiện)
-- =============================================================================
INSERT INTO `coupons` (`id`, `code`, `description`, `discount_type`, `discount_value`, `max_discount_amount`, `min_order_amount`, `usage_limit`, `usage_count`, `per_user_limit`, `start_date`, `end_date`, `is_active`, `created_at`, `updated_at`)
VALUES
(1, 'TLGAMING500K', 'Giảm ngay 500.000đ cho dàn PC và Laptop từ 10.000.000đ', 'fixed_amount', 500000, NULL, 10000000, 100, 1, 1, '2026-01-01 00:00:00', '2027-12-31 23:59:59', 1, '2026-01-01 08:00:00', '2026-01-01 08:00:00'),
(2, 'WELCOME10', 'Giảm 10% tối đa 1.000.000đ cho khách hàng thành viên đơn từ 5.000.000đ', 'percentage', 10, 1000000, 5000000, 50, 1, 1, '2026-01-01 00:00:00', '2027-12-31 23:59:59', 1, '2026-01-01 08:00:00', '2026-01-01 08:00:00'),
(3, 'EXPIRED2025', 'Mã tri ân năm cũ (Đã hết hạn)', 'percentage', 15, 2000000, 5000000, 20, 5, 1, '2025-01-01 00:00:00', '2025-12-31 23:59:59', 1, '2025-01-01 08:00:00', '2025-12-31 23:59:59'),
(4, 'VIP2000K', 'Ưu đãi đặc biệt giảm 2.000.000đ cho đơn phòng net từ 50.000.000đ', 'fixed_amount', 2000000, NULL, 50000000, 10, 0, 1, '2026-01-01 00:00:00', '2027-12-31 23:59:59', 1, '2026-01-01 08:00:00', '2026-01-01 08:00:00')
ON DUPLICATE KEY UPDATE 
    `code` = VALUES(`code`),
    `description` = VALUES(`description`),
    `discount_type` = VALUES(`discount_type`),
    `discount_value` = VALUES(`discount_value`),
    `max_discount_amount` = VALUES(`max_discount_amount`),
    `min_order_amount` = VALUES(`min_order_amount`),
    `usage_limit` = VALUES(`usage_limit`),
    `usage_count` = VALUES(`usage_count`),
    `per_user_limit` = VALUES(`per_user_limit`),
    `start_date` = VALUES(`start_date`),
    `end_date` = VALUES(`end_date`),
    `is_active` = VALUES(`is_active`);


-- =============================================================================
-- 10. BẢNG orders: 5 ĐƠN ĐẶT HÀNG MẪU (ĐỦ 5 TRẠNG THÁI: PENDING, CONFIRMED, SHIPPING, COMPLETED, CANCELLED)
-- Kiểm tra công thức tài chính: final_amount = total_product_amount - discount_amount + shipping_fee
-- =============================================================================
INSERT INTO `orders` (`id`, `order_code`, `user_id`, `customer_name`, `customer_email`, `customer_phone`, `shipping_address`, `shipping_city`, `shipping_district`, `customer_note`, `total_product_amount`, `shipping_fee`, `discount_amount`, `final_amount`, `coupon_id`, `status`, `cancel_reason`, `cancelled_at`, `created_at`, `updated_at`)
VALUES
-- Đơn 1: PENDING - Khách thành viên (id=2), thanh toán COD, áp mã TLGAMING500K
-- Tính toán: 11,500,000 (tiền hàng) - 500,000 (voucher) + 50,000 (ship) = 11,050,000
(1, 'TLG-20260210-001', 2, 'Nguyễn Văn An', 'nguyen.van.an@demo.vn', '0912345678', 'Số 45 Giải Phóng, Phường Đồng Tâm', 'Hà Nội', 'Hai Bà Trưng', 'Nhờ shop cài sẵn Windows 11 Pro và game Liên Minh Huyền Thoại', 11500000, 50000, 500000, 11050000, 1, 'pending', NULL, NULL, '2026-02-10 14:30:00', '2026-02-10 14:30:00'),

-- Đơn 2: CONFIRMED - Khách thành viên (id=3), chuyển khoản ngân hàng, áp mã WELCOME10
-- Tính toán: 16,800,000 (tiền hàng) - 1,000,000 (voucher max) + 0 (freeship) = 15,800,000
(2, 'TLG-20260212-002', 3, 'Trần Thị Bình', 'tran.thi.binh@demo.vn', '0987654321', 'Số 88 Nguyễn Trãi, Phường Thượng Đình', 'Hà Nội', 'Thanh Xuân', 'Giao máy trong giờ hành chính, gọi trước khi đến', 16800000, 0, 1000000, 15800000, 2, 'confirmed', NULL, NULL, '2026-02-12 09:15:00', '2026-02-12 10:00:00'),

-- Đơn 3: SHIPPING - Khách vãng lai (GUEST CHECKOUT: user_id = NULL), thanh toán COD
-- Tính toán: 13,900,000 (tiền hàng) - 0 + 60,000 (ship) = 13,960,000
(3, 'TLG-20260214-003', NULL, 'Hoàng Minh Đức', 'duc.hoang@demo.vn', '0934567890', 'Số 19 Đường số 5, KDC Cityland Park Hills', 'TP. Hồ Chí Minh', 'Gò Vấp', 'Đóng gói bọc xốp cẩn thận giúp mình nhé', 13900000, 60000, 0, 13960000, NULL, 'shipping', NULL, NULL, '2026-02-14 08:00:00', '2026-02-14 08:30:00'),

-- Đơn 4: COMPLETED - Khách thành viên (id=4), chuyển khoản ngân hàng, đã nhận hàng và hoàn tất
-- Tính toán: 2,650,000 (tiền hàng) - 0 + 40,000 (ship) = 2,690,000
(4, 'TLG-20260215-004', 4, 'Lê Hoàng Long', 'le.hoang.long@demo.vn', '0903123456', 'Số 12 Lê Lợi, Phường Bến Nghé', 'TP. Hồ Chí Minh', 'Quận 1', 'Đơn hàng mua màn hình phụ', 2650000, 40000, 0, 2690000, NULL, 'completed', NULL, NULL, '2026-02-15 10:00:00', '2026-02-15 11:30:00'),

-- Đơn 5: CANCELLED - Khách thành viên (id=2), thanh toán COD, đơn bị hủy do khách đổi ý
-- Tính toán: 7,800,000 (tiền hàng) - 780,000 (voucher) + 50,000 (ship) = 7,070,000
(5, 'TLG-20260218-005', 2, 'Nguyễn Văn An', 'nguyen.van.an@demo.vn', '0912345678', 'Số 45 Giải Phóng, Phường Đồng Tâm', 'Hà Nội', 'Hai Bà Trưng', 'Đổi ý sang mua RTX 3080', 7800000, 50000, 780000, 7070000, 2, 'cancelled', 'Khách hàng liên hệ hủy đơn để chuyển sang model RTX 3080 cao hơn', '2026-02-18 16:00:00', '2026-02-18 15:30:00', '2026-02-18 16:00:00')
ON DUPLICATE KEY UPDATE 
    `order_code` = VALUES(`order_code`),
    `user_id` = VALUES(`user_id`),
    `customer_name` = VALUES(`customer_name`),
    `customer_email` = VALUES(`customer_email`),
    `customer_phone` = VALUES(`customer_phone`),
    `shipping_address` = VALUES(`shipping_address`),
    `shipping_city` = VALUES(`shipping_city`),
    `shipping_district` = VALUES(`shipping_district`),
    `customer_note` = VALUES(`customer_note`),
    `total_product_amount` = VALUES(`total_product_amount`),
    `shipping_fee` = VALUES(`shipping_fee`),
    `discount_amount` = VALUES(`discount_amount`),
    `final_amount` = VALUES(`final_amount`),
    `coupon_id` = VALUES(`coupon_id`),
    `status` = VALUES(`status`),
    `cancel_reason` = VALUES(`cancel_reason`),
    `cancelled_at` = VALUES(`cancelled_at`);


-- =============================================================================
-- 11. BẢNG order_items: CHI TIẾT ĐƠN HÀNG, SNAPSHOT GIÁ & GÁN MÁY SERIAL
-- Tuân thủ nghiệp vụ:
-- - Mỗi máy có serial được tách thành 1 dòng riêng (quantity = 1).
-- - Snapshot nguyên bản đơn giá, tên sản phẩm và chính sách bảo hành cam kết.
-- - Máy serial thuộc đơn 1, 2, 3 ở trạng thái 'reserved'; đơn 4 là 'sold'; đơn 5 đã hủy nên máy hoàn về 'available'.
-- =============================================================================
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_unit_id`, `product_name`, `product_sku`, `unit_serial_number`, `price`, `quantity`, `subtotal`, `warranty_period`, `created_at`)
VALUES
-- Dòng chi tiết của Đơn 1 (Gán máy Serial TLG-PC-12400F-SN001)
(1, 1, 1, 1, 'PC Gaming TL-01 Core i5-12400F / RTX 2060S / RAM 16GB / SSD 512GB', 'SKU-PC-01', 'TLG-PC-12400F-SN001', 11500000, 1, 11500000, '12 tháng đổi mới linh kiện', '2026-02-10 14:30:00'),

-- Dòng chi tiết của Đơn 2 (Gán máy Serial TLG-LAP-DELLG15-SN101)
(2, 2, 4, 6, 'Laptop Dell Gaming G15 5515 Ryzen 7 5800H / RTX 3060 / 16GB / 512GB / 15.6" 120Hz', 'SKU-LAP-01', 'TLG-LAP-DELLG15-SN101', 16800000, 1, 16800000, '12 tháng phần cứng, bao test 15 ngày', '2026-02-12 09:15:00'),

-- Dòng chi tiết của Đơn 3 (Gán máy Serial TLG-LAP-ASUSTUF-SN201)
(3, 3, 5, 8, 'Laptop Asus TUF Gaming F15 FX506HC Core i5-11400H / RTX 3050 / 16GB / 512GB / 144Hz', 'SKU-LAP-02', 'TLG-LAP-ASUSTUF-SN201', 13900000, 1, 13900000, '6 tháng bảo hành tiêu chuẩn', '2026-02-14 08:00:00'),

-- Dòng chi tiết của Đơn 4 (Gán máy Serial TLG-MON-ASUS-SN901)
(4, 4, 12, 17, 'Màn Hình Asus TUF Gaming VG249Q 24 inch IPS 144Hz 1ms Cũ', 'SKU-MON-01', 'TLG-MON-ASUS-SN901', 2650000, 1, 2650000, '6 tháng 1 đổi 1 nếu lỗi điểm chết', '2026-02-15 10:00:00'),

-- Dòng chi tiết của Đơn 5 (Từng gán máy Serial TLG-VGA-3070-SN601 nhưng đơn đã hủy)
(5, 5, 9, 13, 'VGA MSI Gaming X Trio GeForce RTX 3070 8GB GDDR6 Cũ', 'SKU-VGA-02', 'TLG-VGA-3070-SN601', 7800000, 1, 7800000, '6 tháng cam kết nguyên bản', '2026-02-18 15:30:00')
ON DUPLICATE KEY UPDATE 
    `order_id` = VALUES(`order_id`),
    `product_id` = VALUES(`product_id`),
    `product_unit_id` = VALUES(`product_unit_id`),
    `product_name` = VALUES(`product_name`),
    `product_sku` = VALUES(`product_sku`),
    `unit_serial_number` = VALUES(`unit_serial_number`),
    `price` = VALUES(`price`),
    `quantity` = VALUES(`quantity`),
    `subtotal` = VALUES(`subtotal`),
    `warranty_period` = VALUES(`warranty_period`);


-- =============================================================================
-- 12. BẢNG payments: LỊCH SỬ THANH TOÁN (COD & CHUYỂN KHOẢN NGÂN HÀNG THỦ CÔNG)
-- Lưu ý: Đơn pending có payment status = 'pending' (chưa thanh toán)
-- Đơn confirmed và completed có payment status = 'completed' (được admin duyệt)
-- =============================================================================
INSERT INTO `payments` (`id`, `order_id`, `payment_method`, `amount`, `status`, `transaction_code`, `bank_name`, `account_number`, `transfer_note`, `payment_proof_image`, `confirmed_by_user_id`, `confirmed_at`, `created_at`, `updated_at`)
VALUES
-- Thanh toán Đơn 1: COD - Chờ giao hàng thu tiền tận nơi
(1, 1, 'cod', 11050000, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-10 14:30:00', '2026-02-10 14:30:00'),

-- Thanh toán Đơn 2: Chuyển khoản ngân hàng - Khách upload ảnh bill và Admin duyệt
(2, 2, 'bank_transfer', 15800000, 'completed', 'MB-FT2604389102', 'MBBank (Ngân hàng Quân Đội)', '0988000111', 'TLG 20260212 002 Tran Thi Binh', '/uploads/payments/bill-don-2.webp', 1, '2026-02-12 09:45:00', '2026-02-12 09:20:00', '2026-02-12 09:45:00'),

-- Thanh toán Đơn 3: COD - Hàng đang vận chuyển, chờ shipper thu tiền
(3, 3, 'cod', 13960000, 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 08:00:00', '2026-02-14 08:00:00'),

-- Thanh toán Đơn 4: Chuyển khoản ngân hàng - Đã nhận tiền thành công
(4, 4, 'bank_transfer', 2690000, 'completed', 'VCB-9872134567', 'Vietcombank', '0011004567890', 'TLG 20260215 004 Le Hoang Long', '/uploads/payments/bill-don-4.webp', 1, '2026-02-15 10:20:00', '2026-02-15 10:05:00', '2026-02-15 10:20:00'),

-- Thanh toán Đơn 5: COD - Đơn bị hủy nên trạng thái thanh toán là failed
(5, 5, 'cod', 7070000, 'failed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 15:30:00', '2026-02-18 16:00:00')
ON DUPLICATE KEY UPDATE 
    `order_id` = VALUES(`order_id`),
    `payment_method` = VALUES(`payment_method`),
    `amount` = VALUES(`amount`),
    `status` = VALUES(`status`),
    `transaction_code` = VALUES(`transaction_code`),
    `bank_name` = VALUES(`bank_name`),
    `account_number` = VALUES(`account_number`),
    `transfer_note` = VALUES(`transfer_note`),
    `payment_proof_image` = VALUES(`payment_proof_image`),
    `confirmed_by_user_id` = VALUES(`confirmed_by_user_id`),
    `confirmed_at` = VALUES(`confirmed_at`);


-- =============================================================================
-- 13. BẢNG coupon_usages: NHẬT KÝ SỬ DỤNG VOUCHER
-- Khi đơn 5 bị hủy -> status = 'cancelled' (hoàn lại lượt dùng cho khách)
-- =============================================================================
INSERT INTO `coupon_usages` (`id`, `coupon_id`, `order_id`, `user_id`, `customer_email`, `customer_phone`, `discount_applied`, `used_at`, `status`)
VALUES
-- Đơn 1 dùng mã TLGAMING500K
(1, 1, 1, 2, 'nguyen.van.an@demo.vn', '0912345678', 500000, '2026-02-10 14:30:00', 'used'),

-- Đơn 2 dùng mã WELCOME10
(2, 2, 2, 3, 'tran.thi.binh@demo.vn', '0987654321', 1000000, '2026-02-12 09:15:00', 'used'),

-- Đơn 5 dùng mã WELCOME10 nhưng đơn bị hủy -> status = 'cancelled' (hoàn voucher)
(3, 2, 5, 2, 'nguyen.van.an@demo.vn', '0912345678', 780000, '2026-02-18 15:30:00', 'cancelled')
ON DUPLICATE KEY UPDATE 
    `coupon_id` = VALUES(`coupon_id`),
    `order_id` = VALUES(`order_id`),
    `user_id` = VALUES(`user_id`),
    `customer_email` = VALUES(`customer_email`),
    `customer_phone` = VALUES(`customer_phone`),
    `discount_applied` = VALUES(`discount_applied`),
    `status` = VALUES(`status`);


-- =============================================================================
-- 14 & 15. BẢNG carts & cart_items: GIỎ HÀNG THÀNH VIÊN ĐANG CHỜ MUA HÀNG
-- =============================================================================
-- Khách Nguyễn Văn An (id=2) đang có 1 giỏ hàng hoạt động
INSERT INTO `carts` (`id`, `user_id`, `created_at`, `updated_at`)
VALUES
(1, 2, '2026-02-20 09:00:00', '2026-02-20 09:15:00')
ON DUPLICATE KEY UPDATE 
    `user_id` = VALUES(`user_id`);

-- Mặt hàng đang nằm trong giỏ: 1 chiếc Laptop Acer Nitro 5 Tiger (id=7)
INSERT INTO `cart_items` (`id`, `cart_id`, `product_id`, `quantity`, `created_at`, `updated_at`)
VALUES
(1, 1, 7, 1, '2026-02-20 09:15:00', '2026-02-20 09:15:00')
ON DUPLICATE KEY UPDATE 
    `cart_id` = VALUES(`cart_id`),
    `product_id` = VALUES(`product_id`),
    `quantity` = VALUES(`quantity`);

-- Khôi phục lại trạng thái kiểm tra khóa ngoại ban đầu
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- =============================================================================
-- KẾT THÚC SEED DỮ LIỆU MẪU TL GAMING (HOÀN TẤT)
-- =============================================================================
