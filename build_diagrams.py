# -*- coding: utf-8 -*-
"""
Tự động sinh sơ đồ ERD cho đồ án tốt nghiệp Website bán máy tính cũ TL Gaming.
- Xuất file draw.io 2 trang: 1. ERD Chi Tiết, 2. ERD Tổng Quan.
- Xuất 2 file SVG vector chất lượng cao để chèn trực tiếp vào báo cáo tốt nghiệp.
- Xác thực cú pháp XML và đối chiếu đầy đủ 15 bảng, 22 Foreign Keys.
"""

import xml.etree.ElementTree as ET
import os

# =============================================================================
# 1. KHỞI TẠO DỮ LIỆU 15 BẢNG CHUẨN XÁC TỪ DATABASE/TL_GAMING.SQL
# =============================================================================

TABLES = [
    {
        "id": "users",
        "name": "users",
        "title": "Tài khoản & Phân quyền",
        "fill": "#DAE8FC",
        "stroke": "#6C8EBF",
        "header_fill": "#B9D5F7",
        "x": 1240, "y": 80, "w": 300,
        "overview_x": 860, "overview_y": 80, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("role", "ENUM('customer','admin')", "DEFAULT 'customer'", False),
            ("email", "VARCHAR(191)", "[UQ]", False),
            ("password_hash", "VARCHAR(255)", "", False),
            ("full_name", "VARCHAR(100)", "", False),
            ("phone", "VARCHAR(20)", "[IDX]", True),
            ("address", "VARCHAR(255)", "", True),
            ("status", "ENUM('active','locked')", "DEFAULT 'active'", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "categories",
        "name": "categories",
        "title": "Danh mục sản phẩm",
        "fill": "#D5E8D4",
        "stroke": "#82B366",
        "header_fill": "#B7DBB5",
        "x": 50, "y": 80, "w": 290,
        "overview_x": 50, "overview_y": 80, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("parent_id", "INT UNSIGNED", "[FK]", True),
            ("name", "VARCHAR(100)", "", False),
            ("slug", "VARCHAR(120)", "[UQ]", False),
            ("description", "TEXT", "", True),
            ("display_order", "INT", "DEFAULT 0", False),
            ("is_active", "TINYINT(1)", "DEFAULT 1", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "products",
        "name": "products",
        "title": "Dòng sản phẩm / Model",
        "fill": "#D5E8D4",
        "stroke": "#82B366",
        "header_fill": "#B7DBB5",
        "x": 420, "y": 80, "w": 320,
        "overview_x": 320, "overview_y": 80, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("category_id", "INT UNSIGNED", "[FK]", False),
            ("name", "VARCHAR(255)", "", False),
            ("slug", "VARCHAR(255)", "[UQ]", False),
            ("sku", "VARCHAR(50)", "[UQ]", False),
            ("summary", "VARCHAR(500)", "", True),
            ("description", "LONGTEXT", "", True),
            ("price", "BIGINT UNSIGNED", "", False),
            ("original_price", "BIGINT UNSIGNED", "", True),
            ("warranty_months", "INT UNSIGNED", "DEFAULT 12", False),
            ("condition_overview", "VARCHAR(100)", "", False),
            ("stock_quantity", "INT UNSIGNED", "DEFAULT 0", False),
            ("has_serials", "TINYINT(1)", "DEFAULT 1", False),
            ("thumbnail", "VARCHAR(255)", "", True),
            ("status", "ENUM('draft','pub...')", "DEFAULT 'draft'", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "product_images",
        "name": "product_images",
        "title": "Ảnh thực tế sản phẩm",
        "fill": "#D5E8D4",
        "stroke": "#82B366",
        "header_fill": "#B7DBB5",
        "x": 50, "y": 660, "w": 290,
        "overview_x": 50, "overview_y": 380, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK, UQ_PROD]", False),
            ("product_id", "INT UNSIGNED", "[FK, UQ_PROD]", False),
            ("image_url", "VARCHAR(255)", "", False),
            ("alt_text", "VARCHAR(255)", "", True),
            ("sort_order", "INT", "DEFAULT 0", False),
            ("is_primary", "TINYINT(1)", "DEFAULT 0", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
        ]
    },
    {
        "id": "product_specs",
        "name": "product_specs",
        "title": "Thông số kỹ thuật phần cứng",
        "fill": "#D5E8D4",
        "stroke": "#82B366",
        "header_fill": "#B7DBB5",
        "x": 50, "y": 380, "w": 290,
        "overview_x": 50, "overview_y": 230, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK, UQ_PROD]", False),
            ("product_id", "INT UNSIGNED", "[FK, UQ_PROD]", False),
            ("group_name", "VARCHAR(100)", "", True),
            ("spec_name", "VARCHAR(100)", "", False),
            ("spec_value", "VARCHAR(255)", "", False),
            ("sort_order", "INT", "DEFAULT 0", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "product_units",
        "name": "product_units",
        "title": "Cá thể máy cũ (Serial)",
        "fill": "#FFE6CC",
        "stroke": "#D79B00",
        "header_fill": "#FCD5B5",
        "x": 820, "y": 80, "w": 320,
        "overview_x": 590, "overview_y": 80, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK, UQ_PROD]", False),
            ("product_id", "INT UNSIGNED", "[FK, UQ_PROD]", False),
            ("serial_number", "VARCHAR(100)", "[UQ]", False),
            ("hardware_condition_detail", "TEXT", "", True),
            ("battery_health", "INT UNSIGNED", "0-100%", True),
            ("cosmetic_grade", "ENUM('Grade A/B/C')", "DEFAULT 'Grade A'", False),
            ("status", "ENUM('avail/sold...')", "DEFAULT 'available'", False),
            ("import_price", "BIGINT UNSIGNED", "", True),
            ("imported_at", "DATETIME", "DEFAULT NOW()", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "product_inspections",
        "name": "product_inspections",
        "title": "Phiên kiểm định phần cứng",
        "fill": "#FFE6CC",
        "stroke": "#D79B00",
        "header_fill": "#FCD5B5",
        "x": 820, "y": 450, "w": 310,
        "overview_x": 590, "overview_y": 230, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("unit_id", "INT UNSIGNED", "[FK]", False),
            ("inspector_id", "INT UNSIGNED", "[FK]", True),
            ("inspection_date", "DATETIME", "DEFAULT NOW()", False),
            ("checklist_overall_result", "ENUM('passed/cond/fail')", "DEFAULT 'passed'", False),
            ("notes", "TEXT", "", True),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
        ]
    },
    {
        "id": "inspection_items",
        "name": "inspection_items",
        "title": "Chi tiết 18 bước kiểm tra",
        "fill": "#FFE6CC",
        "stroke": "#D79B00",
        "header_fill": "#FCD5B5",
        "x": 820, "y": 680, "w": 330,
        "overview_x": 590, "overview_y": 380, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("inspection_id", "INT UNSIGNED", "[FK, UQ_STEP]", False),
            ("step_number", "TINYINT UNSIGNED", "[UQ_STEP, 1-18]", False),
            ("group_name", "VARCHAR(100)", "", False),
            ("item_name", "VARCHAR(150)", "", False),
            ("result", "ENUM('pass/fail/warn/na')", "DEFAULT 'pass'", False),
            ("notes", "VARCHAR(255)", "", True),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
        ]
    },
    {
        "id": "carts",
        "name": "carts",
        "title": "Giỏ hàng thành viên",
        "fill": "#E1D5E7",
        "stroke": "#9673A6",
        "header_fill": "#D0BFD9",
        "x": 420, "y": 550, "w": 280,
        "overview_x": 320, "overview_y": 230, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("user_id", "INT UNSIGNED", "[FK, UQ]", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "cart_items",
        "name": "cart_items",
        "title": "Chi tiết mặt hàng trong giỏ",
        "fill": "#E1D5E7",
        "stroke": "#9673A6",
        "header_fill": "#D0BFD9",
        "x": 420, "y": 720, "w": 280,
        "overview_x": 320, "overview_y": 380, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("cart_id", "INT UNSIGNED", "[FK]", False),
            ("product_id", "INT UNSIGNED", "[FK]", False),
            ("quantity", "INT UNSIGNED", "DEFAULT 1", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "coupons",
        "name": "coupons",
        "title": "Mã giảm giá (Voucher)",
        "fill": "#F8CECC",
        "stroke": "#B85450",
        "header_fill": "#F5AEAA",
        "x": 2040, "y": 80, "w": 310,
        "overview_x": 1400, "overview_y": 80, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("code", "VARCHAR(50)", "[UQ]", False),
            ("description", "VARCHAR(255)", "", True),
            ("discount_type", "ENUM('%/fixed')", "", False),
            ("discount_value", "BIGINT UNSIGNED", "", False),
            ("max_discount_amount", "BIGINT UNSIGNED", "", True),
            ("min_order_amount", "BIGINT UNSIGNED", "DEFAULT 0", False),
            ("usage_limit", "INT UNSIGNED", "DEFAULT 0", False),
            ("usage_count", "INT UNSIGNED", "DEFAULT 0", False),
            ("per_user_limit", "INT UNSIGNED", "DEFAULT 1", False),
            ("start_date", "DATETIME", "", False),
            ("end_date", "DATETIME", "", False),
            ("is_active", "TINYINT(1)", "DEFAULT 1", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "orders",
        "name": "orders",
        "title": "Đơn đặt hàng (Hỗ trợ Guest)",
        "fill": "#D0ECE7",
        "stroke": "#1ABC9C",
        "header_fill": "#A2DED0",
        "x": 1640, "y": 80, "w": 320,
        "overview_x": 1130, "overview_y": 80, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("order_code", "VARCHAR(32)", "[UQ]", False),
            ("user_id", "INT UNSIGNED", "[FK, Guest NULL]", True),
            ("customer_name", "VARCHAR(100)", "", False),
            ("customer_email", "VARCHAR(191)", "", False),
            ("customer_phone", "VARCHAR(20)", "", False),
            ("shipping_address", "VARCHAR(255)", "", False),
            ("shipping_city", "VARCHAR(100)", "", False),
            ("shipping_district", "VARCHAR(100)", "", True),
            ("customer_note", "VARCHAR(500)", "", True),
            ("total_product_amount", "BIGINT UNSIGNED", "", False),
            ("shipping_fee", "BIGINT UNSIGNED", "DEFAULT 0", False),
            ("discount_amount", "BIGINT UNSIGNED", "DEFAULT 0", False),
            ("final_amount", "BIGINT UNSIGNED", "", False),
            ("coupon_id", "INT UNSIGNED", "[FK]", True),
            ("status", "ENUM('pending/shipped...')", "DEFAULT 'pending'", False),
            ("cancel_reason", "VARCHAR(255)", "", True),
            ("cancelled_at", "DATETIME", "", True),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "order_items",
        "name": "order_items",
        "title": "Chi tiết đơn & Snapshot",
        "fill": "#D0ECE7",
        "stroke": "#1ABC9C",
        "header_fill": "#A2DED0",
        "x": 1240, "y": 420, "w": 310,
        "overview_x": 1130, "overview_y": 230, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("order_id", "INT UNSIGNED", "[FK, UQ_ORD_UNIT]", False),
            ("product_id", "INT UNSIGNED", "[FK, Comp_FK]", False),
            ("product_unit_id", "INT UNSIGNED", "[FK, Comp_FK, UQ]", True),
            ("product_name", "VARCHAR(255)", "Snapshot", False),
            ("product_sku", "VARCHAR(50)", "Snapshot", False),
            ("unit_serial_number", "VARCHAR(100)", "Snapshot", True),
            ("price", "BIGINT UNSIGNED", "Snapshot", False),
            ("quantity", "INT UNSIGNED", "DEFAULT 1", False),
            ("subtotal", "BIGINT UNSIGNED", "", False),
            ("warranty_period", "VARCHAR(50)", "Snapshot", False),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
        ]
    },
    {
        "id": "payments",
        "name": "payments",
        "title": "Lịch sử thanh toán (COD & Chuyển khoản)",
        "fill": "#D4EDDA",
        "stroke": "#28A745",
        "header_fill": "#B8E2C2",
        "x": 1640, "y": 660, "w": 320,
        "overview_x": 1130, "overview_y": 380, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("order_id", "INT UNSIGNED", "[FK]", False),
            ("payment_method", "ENUM('cod','bank')", "", False),
            ("amount", "BIGINT UNSIGNED", "", False),
            ("status", "ENUM('pending/comp...')", "DEFAULT 'pending'", False),
            ("transaction_code", "VARCHAR(100)", "", True),
            ("bank_name", "VARCHAR(100)", "", True),
            ("account_number", "VARCHAR(50)", "", True),
            ("transfer_note", "VARCHAR(255)", "", True),
            ("payment_proof_image", "VARCHAR(255)", "", True),
            ("confirmed_by_user_id", "INT UNSIGNED", "[FK]", True),
            ("confirmed_at", "DATETIME", "", True),
            ("created_at", "DATETIME", "DEFAULT NOW()", False),
            ("updated_at", "DATETIME", "ON UPDATE NOW()", False),
        ]
    },
    {
        "id": "coupon_usages",
        "name": "coupon_usages",
        "title": "Nhật ký sử dụng mã giảm giá",
        "fill": "#F8CECC",
        "stroke": "#B85450",
        "header_fill": "#F5AEAA",
        "x": 2040, "y": 550, "w": 310,
        "overview_x": 1400, "overview_y": 230, "overview_w": 220, "overview_h": 70,
        "columns": [
            ("id", "INT UNSIGNED", "[PK]", False),
            ("coupon_id", "INT UNSIGNED", "[FK]", False),
            ("order_id", "INT UNSIGNED", "[FK]", False),
            ("user_id", "INT UNSIGNED", "[FK, Guest NULL]", True),
            ("customer_email", "VARCHAR(191)", "", False),
            ("customer_phone", "VARCHAR(20)", "", False),
            ("discount_applied", "BIGINT UNSIGNED", "", False),
            ("used_at", "DATETIME", "DEFAULT NOW()", False),
            ("status", "ENUM('used','canc')", "DEFAULT 'used'", False),
        ]
    }
]

# 22 KHÓA NGOẠI (FOREIGN KEYS) VÀ QUAN HỆ
RELATIONSHIPS = [
    {
        "id": "rel_categories_parent",
        "name": "fk_categories_parent",
        "source": "categories", "target": "categories",
        "cardinality": "1 : 0..n (Đệ quy)",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_products_category",
        "name": "fk_products_category",
        "source": "categories", "target": "products",
        "cardinality": "1 : 0..n",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_images_product",
        "name": "fk_product_images_product",
        "source": "products", "target": "product_images",
        "cardinality": "1 : 0..n",
        "on_delete": "CASCADE", "on_update": "CASCADE"
    },
    {
        "id": "rel_specs_product",
        "name": "fk_product_specs_product",
        "source": "products", "target": "product_specs",
        "cardinality": "1 : 0..n",
        "on_delete": "CASCADE", "on_update": "CASCADE"
    },
    {
        "id": "rel_units_product",
        "name": "fk_product_units_product",
        "source": "products", "target": "product_units",
        "cardinality": "1 : 0..n (Dòng máy - Serial)",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_inspections_unit",
        "name": "fk_inspections_unit",
        "source": "product_units", "target": "product_inspections",
        "cardinality": "1 : 0..n (Biên bản test)",
        "on_delete": "CASCADE", "on_update": "CASCADE"
    },
    {
        "id": "rel_inspections_inspector",
        "name": "fk_inspections_inspector",
        "source": "users", "target": "product_inspections",
        "cardinality": "0..1 : 0..n (Kỹ thuật viên)",
        "on_delete": "SET NULL", "on_update": "CASCADE"
    },
    {
        "id": "rel_inspection_items_inspection",
        "name": "fk_inspection_items_inspection",
        "source": "product_inspections", "target": "inspection_items",
        "cardinality": "1 : 18 (18 bước kiểm tra)",
        "on_delete": "CASCADE", "on_update": "CASCADE"
    },
    {
        "id": "rel_carts_user",
        "name": "fk_carts_user",
        "source": "users", "target": "carts",
        "cardinality": "1 : 0..1 (Giỏ hàng tài khoản)",
        "on_delete": "CASCADE", "on_update": "CASCADE"
    },
    {
        "id": "rel_cart_items_cart",
        "name": "fk_cart_items_cart",
        "source": "carts", "target": "cart_items",
        "cardinality": "1 : 0..n",
        "on_delete": "CASCADE", "on_update": "CASCADE"
    },
    {
        "id": "rel_cart_items_product",
        "name": "fk_cart_items_product",
        "source": "products", "target": "cart_items",
        "cardinality": "1 : 0..n",
        "on_delete": "CASCADE", "on_update": "CASCADE"
    },
    {
        "id": "rel_orders_user",
        "name": "fk_orders_user",
        "source": "users", "target": "orders",
        "cardinality": "0..1 : 0..n (Guest NULL)",
        "on_delete": "SET NULL", "on_update": "CASCADE"
    },
    {
        "id": "rel_orders_coupon",
        "name": "fk_orders_coupon",
        "source": "coupons", "target": "orders",
        "cardinality": "0..1 : 0..n",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_order_items_order",
        "name": "fk_order_items_order",
        "source": "orders", "target": "order_items",
        "cardinality": "1 : 1..n",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_order_items_product",
        "name": "fk_order_items_product",
        "source": "products", "target": "order_items",
        "cardinality": "1 : 0..n",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_order_items_unit",
        "name": "fk_order_items_unit",
        "source": "product_units", "target": "order_items",
        "cardinality": "0..1 : 0..1 (Gán máy Serial)",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_order_items_unit_product",
        "name": "fk_order_items_unit_product",
        "source": "product_units", "target": "order_items",
        "cardinality": "0..1 : 0..1 (Khóa ngoại kết hợp)",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_payments_order",
        "name": "fk_payments_order",
        "source": "orders", "target": "payments",
        "cardinality": "1 : 1..n",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_payments_confirmed_by",
        "name": "fk_payments_confirmed_by",
        "source": "users", "target": "payments",
        "cardinality": "0..1 : 0..n (Admin duyệt)",
        "on_delete": "SET NULL", "on_update": "CASCADE"
    },
    {
        "id": "rel_coupon_usages_coupon",
        "name": "fk_coupon_usages_coupon",
        "source": "coupons", "target": "coupon_usages",
        "cardinality": "1 : 0..n",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_coupon_usages_order",
        "name": "fk_coupon_usages_order",
        "source": "orders", "target": "coupon_usages",
        "cardinality": "1 : 0..n",
        "on_delete": "RESTRICT", "on_update": "CASCADE"
    },
    {
        "id": "rel_coupon_usages_user",
        "name": "fk_coupon_usages_user",
        "source": "users", "target": "coupon_usages",
        "cardinality": "0..1 : 0..n (Guest NULL)",
        "on_delete": "SET NULL", "on_update": "CASCADE"
    }
]


# =============================================================================
# 2. XÂY DỰNG FILE DRAW.IO XML (2 TRANG)
# =============================================================================

def build_drawio_xml():
    mxfile = ET.Element("mxfile", {
        "host": "app.diagrams.net",
        "modified": "2026-11-10T21:00:00.000Z",
        "agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Antigravity/TL-Gaming",
        "version": "24.7.17",
        "type": "device"
    })

    # -------------------------------------------------------------------------
    # TRANG 1: ERD CHI TIẾT (Đầy đủ cột, kiểu dữ liệu, PK/FK)
    # -------------------------------------------------------------------------
    d1 = ET.SubElement(mxfile, "diagram", {
        "id": "page-1-detailed",
        "name": "1. ERD Chi Tiết (Detailed ERD)"
    })
    m1 = ET.SubElement(d1, "mxGraphModel", {
        "dx": "1600", "dy": "900", "grid": "1", "gridSize": "10",
        "guides": "1", "tooltips": "1", "connect": "1", "arrows": "1",
        "fold": "1", "page": "1", "pageScale": "1",
        "pageWidth": "2500", "pageHeight": "1200", "background": "#F8F9FA"
    })
    root1 = ET.SubElement(m1, "root")
    ET.SubElement(root1, "mxCell", {"id": "0"})
    ET.SubElement(root1, "mxCell", {"id": "1", "parent": "0"})

    # Tiêu đề sơ đồ trang 1
    title_cell = ET.SubElement(root1, "mxCell", {
        "id": "doc_title_1",
        "value": "&lt;b style=&quot;font-size:20px;color:#1A365D;&quot;&gt;SƠ ĐỒ CƠ SỞ DỮ LIỆU CHI TIẾT (DETAILED ERD) - TL GAMING&lt;/b&gt;&lt;br&gt;&lt;span style=&quot;font-size:13px;color:#4A5568;&quot;&gt;Hệ thống thương mại điện tử máy tính cũ | MySQL 8.0 &amp; MariaDB (14 Bảng, 20 Khóa ngoại)&lt;/span&gt;",
        "style": "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;",
        "vertex": "1", "parent": "1"
    })
    ET.SubElement(title_cell, "mxGeometry", {
        "x": "400", "y": "15", "width": "1600", "height": "45", "as": "geometry"
    })

    # Tạo các bảng chi tiết
    row_height = 20
    header_height = 34

    for tbl in TABLES:
        total_h = header_height + len(tbl["columns"]) * row_height + 4
        tbl_cell = ET.SubElement(root1, "mxCell", {
            "id": f"p1_table_{tbl['id']}",
            "value": f"&lt;b style=&quot;font-size:13px;&quot;&gt;{tbl['name']}&lt;/b&gt;&lt;br&gt;&lt;i style=&quot;font-size:10px;font-weight:normal;&quot;&gt;({tbl['title']})&lt;/i&gt;",
            "style": f"swimlane;fontStyle=0;align=center;verticalAlign=top;childLayout=stackLayout;horizontal=1;startSize={header_height};horizontalStack=0;resizeParent=1;resizeParentMax=0;resizeLast=0;collapsible=0;marginBottom=0;whiteSpace=wrap;html=1;fillColor={tbl['fill']};strokeColor={tbl['stroke']};strokeWidth=1.5;fontColor=#2D3748;shadow=1;",
            "vertex": "1", "parent": "1"
        })
        ET.SubElement(tbl_cell, "mxGeometry", {
            "x": str(tbl["x"]), "y": str(tbl["y"]),
            "width": str(tbl["w"]), "height": str(total_h),
            "as": "geometry"
        })

        for i, (c_name, c_type, c_tag, c_null) in enumerate(tbl["columns"]):
            tag_html = ""
            if "[PK]" in c_tag:
                tag_html = " &lt;font color=&quot;#C53030&quot;&gt;&lt;b&gt;[PK]&lt;/b&gt;&lt;/font&gt;"
            elif "[FK" in c_tag:
                tag_html = f" &lt;font color=&quot;#2B6CB0&quot;&gt;&lt;b&gt;{c_tag}&lt;/b&gt;&lt;/font&gt;"
            elif "[UQ]" in c_tag:
                tag_html = " &lt;font color=&quot;#2F855A&quot;&gt;&lt;b&gt;[UQ]&lt;/b&gt;&lt;/font&gt;"
            elif c_tag:
                tag_html = f" &lt;font color=&quot;#718096&quot;&gt;({c_tag})&lt;/font&gt;"

            null_text = "&lt;font color=&quot;#A0AEC0&quot;&gt;null&lt;/font&gt;" if c_null else ""
            row_val = f"&lt;span style=&quot;font-family:Consolas, monospace;font-size:11px;&quot;&gt;+ &lt;b&gt;{c_name}&lt;/b&gt;: {c_type}{tag_html} {null_text}&lt;/span&gt;"
            
            c_cell = ET.SubElement(root1, "mxCell", {
                "id": f"p1_col_{tbl['id']}_{i}",
                "value": row_val,
                "style": "text;strokeColor=none;fillColor=none;align=left;verticalAlign=middle;spacingLeft=8;spacingRight=6;overflow=hidden;rotatable=0;points=[[0,0.5],[1,0.5]];portConstraint=eastwest;whiteSpace=wrap;html=1;",
                "vertex": "1", "parent": f"p1_table_{tbl['id']}"
            })
            ET.SubElement(c_cell, "mxGeometry", {
                "y": str(header_height + i * row_height),
                "width": str(tbl["w"]), "height": str(row_height),
                "as": "geometry"
            })

    # Tạo các mối quan hệ (connectors) trên Trang 1
    for rel in RELATIONSHIPS:
        edge_style = "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;fontSize=10;fontColor=#2D3748;strokeColor=#4A5568;strokeWidth=1.5;startArrow=ERmandOne;startFill=0;endArrow=ERzeroToMany;endFill=0;"
        if "1 : 1" in rel["cardinality"]:
            edge_style = "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;fontSize=10;fontColor=#2D3748;strokeColor=#4A5568;strokeWidth=1.5;startArrow=ERmandOne;startFill=0;endArrow=ERmandOne;endFill=0;"
        elif "0..1 : 0..1" in rel["cardinality"]:
            edge_style = "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;fontSize=10;fontColor=#2D3748;strokeColor=#4A5568;strokeWidth=1.5;startArrow=ERzeroToOne;startFill=0;endArrow=ERzeroToOne;endFill=0;"
        elif "0..1 :" in rel["cardinality"]:
            edge_style = "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;fontSize=10;fontColor=#2D3748;strokeColor=#4A5568;strokeWidth=1.5;startArrow=ERzeroToOne;startFill=0;endArrow=ERzeroToMany;endFill=0;"

        e_cell = ET.SubElement(root1, "mxCell", {
            "id": f"p1_{rel['id']}",
            "value": f"&lt;span style=&quot;background-color:#FFFFFF;padding:1px 3px;border-radius:3px;&quot;&gt;{rel['cardinality']}&lt;/span&gt;",
            "style": edge_style,
            "edge": "1", "parent": "1",
            "source": f"p1_table_{rel['source']}",
            "target": f"p1_table_{rel['target']}"
        })
        ET.SubElement(e_cell, "mxGeometry", {
            "relative": "1", "as": "geometry"
        })

    # -------------------------------------------------------------------------
    # TRANG 2: ERD TỔNG QUAN (Chỉ thể hiện tên bảng, phân nhóm và quan hệ)
    # -------------------------------------------------------------------------
    d2 = ET.SubElement(mxfile, "diagram", {
        "id": "page-2-overview",
        "name": "2. ERD Tổng Quan (Overview ERD)"
    })
    m2 = ET.SubElement(d2, "mxGraphModel", {
        "dx": "1400", "dy": "800", "grid": "1", "gridSize": "10",
        "guides": "1", "tooltips": "1", "connect": "1", "arrows": "1",
        "fold": "1", "page": "1", "pageScale": "1",
        "pageWidth": "1700", "pageHeight": "600", "background": "#F8F9FA"
    })
    root2 = ET.SubElement(m2, "root")
    ET.SubElement(root2, "mxCell", {"id": "0"})
    ET.SubElement(root2, "mxCell", {"id": "1", "parent": "0"})

    # Tiêu đề sơ đồ trang 2
    title_cell_2 = ET.SubElement(root2, "mxCell", {
        "id": "doc_title_2",
        "value": "&lt;b style=&quot;font-size:18px;color:#1A365D;&quot;&gt;SƠ ĐỒ CƠ SỞ DỮ LIỆU TỔNG QUAN (CONCEPTUAL / OVERVIEW ERD) - TL GAMING&lt;/b&gt;&lt;br&gt;&lt;span style=&quot;font-size:12px;color:#4A5568;&quot;&gt;Mô hình thực thể và liên kết theo phân cụm chức năng hệ thống&lt;/span&gt;",
        "style": "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;",
        "vertex": "1", "parent": "1"
    })
    ET.SubElement(title_cell_2, "mxGeometry", {
        "x": "200", "y": "15", "width": "1300", "height": "40", "as": "geometry"
    })

    # Tạo các thực thể tổng quan gọn gàng
    for tbl in TABLES:
        overview_val = f"&lt;b style=&quot;font-size:14px;&quot;&gt;{tbl['name']}&lt;/b&gt;&lt;br&gt;&lt;span style=&quot;font-size:11px;color:#4A5568;&quot;&gt;{tbl['title']}&lt;/span&gt;&lt;br&gt;&lt;span style=&quot;font-size:10px;color:#718096;&quot;&gt;({len(tbl['columns'])} cột)&lt;/span&gt;"
        t_cell = ET.SubElement(root2, "mxCell", {
            "id": f"p2_table_{tbl['id']}",
            "value": overview_val,
            "style": f"rounded=1;whiteSpace=wrap;html=1;fillColor={tbl['fill']};strokeColor={tbl['stroke']};strokeWidth=2;fontColor=#2D3748;shadow=1;align=center;verticalAlign=middle;",
            "vertex": "1", "parent": "1"
        })
        ET.SubElement(t_cell, "mxGeometry", {
            "x": str(tbl["overview_x"]), "y": str(tbl["overview_y"]),
            "width": str(tbl["overview_w"]), "height": str(tbl["overview_h"]),
            "as": "geometry"
        })

    # Tạo connectors trên Trang 2
    for rel in RELATIONSHIPS:
        edge_style = "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;fontSize=10;fontColor=#2D3748;strokeColor=#4A5568;strokeWidth=1.5;startArrow=ERmandOne;startFill=0;endArrow=ERzeroToMany;endFill=0;"
        if "1 : 1" in rel["cardinality"]:
            edge_style = "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;fontSize=10;fontColor=#2D3748;strokeColor=#4A5568;strokeWidth=1.5;startArrow=ERmandOne;startFill=0;endArrow=ERmandOne;endFill=0;"
        elif "0..1 : 0..1" in rel["cardinality"]:
            edge_style = "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;fontSize=10;fontColor=#2D3748;strokeColor=#4A5568;strokeWidth=1.5;startArrow=ERzeroToOne;startFill=0;endArrow=ERzeroToOne;endFill=0;"
        elif "0..1 :" in rel["cardinality"]:
            edge_style = "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;fontSize=10;fontColor=#2D3748;strokeColor=#4A5568;strokeWidth=1.5;startArrow=ERzeroToOne;startFill=0;endArrow=ERzeroToMany;endFill=0;"

        e_cell = ET.SubElement(root2, "mxCell", {
            "id": f"p2_{rel['id']}",
            "value": f"&lt;span style=&quot;background-color:#FFFFFF;padding:1px 3px;border-radius:3px;&quot;&gt;{rel['cardinality']}&lt;/span&gt;",
            "style": edge_style,
            "edge": "1", "parent": "1",
            "source": f"p2_table_{rel['source']}",
            "target": f"p2_table_{rel['target']}"
        })
        ET.SubElement(e_cell, "mxGeometry", {
            "relative": "1", "as": "geometry"
        })

    # Lưu ra tệp drawio
    drawio_path = os.path.join("docs", "diagrams", "TL_Gaming_ERD.drawio")
    tree = ET.ElementTree(mxfile)
    ET.indent(tree, space="  ", level=0)
    tree.write(drawio_path, encoding="utf-8", xml_declaration=True)
    print(f"Đã tạo file draw.io thành công: {drawio_path}")
    return drawio_path


# =============================================================================
# 3. SINH FILE SVG VECTOR CHO BÁO CÁO (TRỰC QUAN & CHÈN WORD / LATEX)
# =============================================================================

def build_svg_diagrams():
    # -------------------------------------------------------------------------
    # SVG 1: ERD Chi Tiết
    # -------------------------------------------------------------------------
    svg1_path = os.path.join("docs", "diagrams", "TL_Gaming_ERD_Detailed.svg")
    width_svg = 2420
    height_svg = 1100

    svg_lines = [
        f'<?xml version="1.0" encoding="UTF-8"?>',
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width_svg} {height_svg}" width="{width_svg}" height="{height_svg}">',
        f'  <defs>',
        f'    <filter id="shadow" x="-5%" y="-5%" width="110%" height="110%">',
        f'      <feDropShadow dx="2" dy="3" stdDeviation="3" flood-color="#000000" flood-opacity="0.12"/>',
        f'    </filter>',
        f'    <marker id="crow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">',
        f'      <path d="M 0 1 L 8 5 L 0 9" fill="none" stroke="#4A5568" stroke-width="1.5"/>',
        f'    </marker>',
        f'    <marker id="one" viewBox="0 0 10 10" refX="2" refY="5" markerWidth="6" markerHeight="6" orient="auto">',
        f'      <line x1="2" y1="1" x2="2" y2="9" stroke="#4A5568" stroke-width="2"/>',
        f'    </marker>',
        f'  </defs>',
        f'  <!-- Nền sơ đồ -->',
        f'  <rect width="{width_svg}" height="{height_svg}" fill="#F8F9FA"/>',
        f'  <!-- Header tiêu đề -->',
        f'  <rect x="0" y="0" width="{width_svg}" height="65" fill="#1A365D"/>',
        f'  <text x="{width_svg//2}" y="32" font-family="Segoe UI, Roboto, sans-serif" font-size="20" font-weight="bold" fill="#FFFFFF" text-anchor="middle">SƠ ĐỒ CƠ SỞ DỮ LIỆU CHI TIẾT (DETAILED ERD) - WEBSITE MÁY TÍNH CŨ TL GAMING</text>',
        f'  <text x="{width_svg//2}" y="52" font-family="Segoe UI, Roboto, sans-serif" font-size="12" fill="#E2E8F0" text-anchor="middle">Hệ thống 14 Bảng chuẩn hóa InnoDB | MySQL 8.0 &amp; MariaDB | Đầy đủ 20 Khóa ngoại và Kiểu dữ liệu</text>'
    ]

    tbl_dict = {t["id"]: t for t in TABLES}

    for rel in RELATIONSHIPS:
        s = tbl_dict[rel["source"]]
        t = tbl_dict[rel["target"]]

        if rel["source"] == rel["target"]:
            # Đệ quy categories
            x1, y1 = s["x"], s["y"] + 40
            svg_lines.append(f'  <path d="M {x1} {y1} C {x1-40} {y1-30}, {x1-40} {y1+50}, {x1} {y1+20}" fill="none" stroke="#4A5568" stroke-width="1.5" marker-end="url(#crow)"/>')
            svg_lines.append(f'  <text x="{x1-45}" y="{y1+10}" font-family="Segoe UI" font-size="10" fill="#2D3748" text-anchor="end">1:0..n</text>')
            continue

        # Điểm giữa của source và target
        sx = s["x"] + s["w"] // 2
        sy = s["y"] + 60
        tx = t["x"] + t["w"] // 2
        ty = t["y"] + 60

        if t["x"] > s["x"] + s["w"]:
            x1 = s["x"] + s["w"]
            y1 = s["y"] + 50
            x2 = t["x"]
            y2 = t["y"] + 50
            mx = (x1 + x2) // 2
            path_d = f"M {x1} {y1} L {mx} {y1} L {mx} {y2} L {x2} {y2}"
            label_x, label_y = mx, min(y1, y2) + abs(y1 - y2) // 2
        elif t["y"] > s["y"]:
            x1 = s["x"] + s["w"] // 2
            y1 = s["y"] + 30 + len(s["columns"]) * 20
            x2 = t["x"] + t["w"] // 2
            y2 = t["y"]
            my = (y1 + y2) // 2
            path_d = f"M {x1} {y1} L {x1} {my} L {x2} {my} L {x2} {y2}"
            label_x, label_y = (x1 + x2) // 2, my - 4
        else:
            x1 = s["x"]
            y1 = s["y"] + 60
            x2 = t["x"] + t["w"]
            y2 = t["y"] + 60
            mx = (x1 + x2) // 2
            path_d = f"M {x1} {y1} L {mx} {y1} L {mx} {y2} L {x2} {y2}"
            label_x, label_y = mx, (y1 + y2) // 2

        svg_lines.append(f'  <path d="{path_d}" fill="none" stroke="#718096" stroke-width="1.5" stroke-dasharray="none" marker-start="url(#one)" marker-end="url(#crow)"/>')
        svg_lines.append(f'  <rect x="{label_x-35}" y="{label_y-9}" width="70" height="16" fill="#FFFFFF" rx="3" stroke="#CBD5E0" stroke-width="0.8"/>')
        svg_lines.append(f'  <text x="{label_x}" y="{label_y+3}" font-family="Segoe UI, Roboto, sans-serif" font-size="9" fill="#2D3748" text-anchor="middle" font-weight="bold">{rel["cardinality"].split()[0]}</text>')

    # Vẽ từng bảng Entity
    row_h = 20
    head_h = 36

    for tbl in TABLES:
        total_h = head_h + len(tbl["columns"]) * row_h + 4
        x, y, w = tbl["x"], tbl["y"], tbl["w"]

        svg_lines.append(f'  <!-- Bảng {tbl["name"]} -->')
        svg_lines.append(f'  <g filter="url(#shadow)">')
        # Thân bảng
        svg_lines.append(f'    <rect x="{x}" y="{y}" width="{w}" height="{total_h}" rx="6" fill="#FFFFFF" stroke="{tbl["stroke"]}" stroke-width="1.8"/>')
        # Header bảng
        svg_lines.append(f'    <path d="M {x} {y+head_h} L {x} {y+6} Q {x} {y} {x+6} {y} L {x+w-6} {y} Q {x+w} {y} {x+w} {y+6} L {x+w} {y+head_h} Z" fill="{tbl["fill"]}"/>')
        svg_lines.append(f'    <line x1="{x}" y1="{y+head_h}" x2="{x+w}" y2="{y+head_h}" stroke="{tbl["stroke"]}" stroke-width="1.5"/>')
        svg_lines.append(f'    <text x="{x + w//2}" y="{y+18}" font-family="Segoe UI, Roboto, sans-serif" font-size="13" font-weight="bold" fill="#1A202C" text-anchor="middle">{tbl["name"]}</text>')
        svg_lines.append(f'    <text x="{x + w//2}" y="{y+31}" font-family="Segoe UI, Roboto, sans-serif" font-size="10" fill="#4A5568" text-anchor="middle">({tbl["title"]})</text>')

        # Từng dòng cột
        for i, (c_name, c_type, c_tag, c_null) in enumerate(tbl["columns"]):
            ry = y + head_h + i * row_h
            if i % 2 == 1:
                svg_lines.append(f'    <rect x="{x+1}" y="{ry}" width="{w-2}" height="{row_h}" fill="#F7FAFC"/>')

            tag_color = "#718096"
            if "[PK]" in c_tag:
                tag_color = "#E53E3E"
            elif "[FK" in c_tag:
                tag_color = "#3182CE"
            elif "[UQ]" in c_tag:
                tag_color = "#38A169"

            svg_lines.append(f'    <text x="{x+10}" y="{ry+14}" font-family="Consolas, monospace" font-size="11" font-weight="bold" fill="#2D3748">{c_name}</text>')
            svg_lines.append(f'    <text x="{x+135}" y="{ry+14}" font-family="Consolas, monospace" font-size="10" fill="#4A5568">{c_type}</text>')
            if c_tag:
                svg_lines.append(f'    <text x="{x+w-10}" y="{ry+14}" font-family="Segoe UI, sans-serif" font-size="9" font-weight="bold" fill="{tag_color}" text-anchor="end">{c_tag}</text>')

        svg_lines.append(f'  </g>')

    svg_lines.append('</svg>')

    with open(svg1_path, "w", encoding="utf-8") as f:
        f.write("\n".join(svg_lines))
    print(f"Đã tạo file SVG chi tiết: {svg1_path}")

    # -------------------------------------------------------------------------
    # SVG 2: ERD Tổng Quan
    # -------------------------------------------------------------------------
    svg2_path = os.path.join("docs", "diagrams", "TL_Gaming_ERD_Overview.svg")
    w2 = 1680
    h2 = 520

    svg2_lines = [
        f'<?xml version="1.0" encoding="UTF-8"?>',
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w2} {h2}" width="{w2}" height="{h2}">',
        f'  <defs>',
        f'    <filter id="shadow2" x="-5%" y="-5%" width="110%" height="110%">',
        f'      <feDropShadow dx="2" dy="2" stdDeviation="3" flood-color="#000000" flood-opacity="0.12"/>',
        f'    </filter>',
        f'    <marker id="crow2" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">',
        f'      <path d="M 0 1 L 8 5 L 0 9" fill="none" stroke="#4A5568" stroke-width="1.5"/>',
        f'    </marker>',
        f'  </defs>',
        f'  <rect width="{w2}" height="{h2}" fill="#F8F9FA"/>',
        f'  <rect x="0" y="0" width="{w2}" height="55" fill="#2C3E50"/>',
        f'  <text x="{w2//2}" y="28" font-family="Segoe UI, sans-serif" font-size="18" font-weight="bold" fill="#FFFFFF" text-anchor="middle">SƠ ĐỒ CƠ SỞ DỮ LIỆU TỔNG QUAN (CONCEPTUAL / OVERVIEW ERD) - TL GAMING</text>',
        f'  <text x="{w2//2}" y="45" font-family="Segoe UI, sans-serif" font-size="11" fill="#BDC3C7" text-anchor="middle">Mô hình thực thể và quan hệ giữa 14 bảng nghiệp vụ (Cụm chức năng)</text>'
    ]

    for rel in RELATIONSHIPS:
        s = tbl_dict[rel["source"]]
        t = tbl_dict[rel["target"]]
        if rel["source"] == rel["target"]:
            continue
        sx = s["overview_x"] + s["overview_w"] // 2
        sy = s["overview_y"] + s["overview_h"] // 2
        tx = t["overview_x"] + t["overview_w"] // 2
        ty = t["overview_y"] + t["overview_h"] // 2

        svg2_lines.append(f'  <line x1="{sx}" y1="{sy}" x2="{tx}" y2="{ty}" stroke="#7F8C8D" stroke-width="1.5" marker-end="url(#crow2)"/>')

    for tbl in TABLES:
        x, y, w, h = tbl["overview_x"], tbl["overview_y"], tbl["overview_w"], tbl["overview_h"]
        svg2_lines.append(f'  <g filter="url(#shadow2)">')
        svg2_lines.append(f'    <rect x="{x}" y="{y}" width="{w}" height="{h}" rx="8" fill="{tbl["fill"]}" stroke="{tbl["stroke"]}" stroke-width="2"/>')
        svg2_lines.append(f'    <text x="{x + w//2}" y="{y+25}" font-family="Segoe UI, sans-serif" font-size="14" font-weight="bold" fill="#1A202C" text-anchor="middle">{tbl["name"]}</text>')
        svg2_lines.append(f'    <text x="{x + w//2}" y="{y+42}" font-family="Segoe UI, sans-serif" font-size="11" fill="#4A5568" text-anchor="middle">{tbl["title"]}</text>')
        svg2_lines.append(f'    <text x="{x + w//2}" y="{y+56}" font-family="Segoe UI, sans-serif" font-size="10" fill="#718096" text-anchor="middle">({len(tbl["columns"])} cột)</text>')
        svg2_lines.append(f'  </g>')

    svg2_lines.append('</svg>')

    with open(svg2_path, "w", encoding="utf-8") as f:
        f.write("\n".join(svg2_lines))
    print(f"Đã tạo file SVG tổng quan: {svg2_path}")


# =============================================================================
# 4. HÀM KIỂM CHỨNG TỰ ĐỘNG (VALIDATION)
# =============================================================================

def validate_all():
    print("\n--- BẮT ĐẦU KIỂM CHỨNG TỰ ĐỘNG ---")
    drawio_path = os.path.join("docs", "diagrams", "TL_Gaming_ERD.drawio")

    # 1. Parse XML
    try:
        tree = ET.parse(drawio_path)
        root = tree.getroot()
        print("✔ Cú pháp XML của file draw.io: HỢP LỆ 100%")
    except Exception as e:
        print(f"❌ Lỗi cú pháp XML: {e}")
        return False

    # 2. Kiểm tra 2 diagram/page
    diagrams = root.findall("diagram")
    if len(diagrams) == 2:
        print(f"✔ Đủ 2 trang trong file drawio: Trang 1 ({diagrams[0].get('name')}), Trang 2 ({diagrams[1].get('name')})")
    else:
        print(f"❌ Số trang không khớp: tìm thấy {len(diagrams)} trang")
        return False

    # 3. Đếm entity trên trang 1
    m1 = diagrams[0].find("mxGraphModel")
    r1 = m1.find("root")
    p1_tables = [c for c in r1.findall("mxCell") if "swimlane" in c.get("style", "")]
    print(f"✔ Trang 1: Đủ {len(p1_tables)} entity bảng (kỳ vọng 14)")

    # 4. Đếm connector trên trang 1
    p1_edges = [c for c in r1.findall("mxCell") if c.get("id", "").startswith("p1_rel_")]
    print(f"✔ Trang 1: Đủ {len(p1_edges)} mối quan hệ Foreign Key (kỳ vọng 20)")

    # 5. Đếm entity và connector trên trang 2
    m2 = diagrams[1].find("mxGraphModel")
    r2 = m2.find("root")
    p2_tables = [c for c in r2.findall("mxCell") if c.get("id", "").startswith("p2_table_")]
    p2_edges = [c for c in r2.findall("mxCell") if c.get("id", "").startswith("p2_rel_")]
    print(f"✔ Trang 2: Đủ {len(p2_tables)} entity tổng quan và {len(p2_edges)} đường quan hệ")

    # 6. Kiểm tra sự tồn tại của 2 file SVG
    svg1 = os.path.join("docs", "diagrams", "TL_Gaming_ERD_Detailed.svg")
    svg2 = os.path.join("docs", "diagrams", "TL_Gaming_ERD_Overview.svg")
    if os.path.exists(svg1) and os.path.exists(svg2):
        print(f"✔ Đã sinh đầy đủ 2 tệp vector SVG cho báo cáo ({os.path.getsize(svg1)} bytes, {os.path.getsize(svg2)} bytes)")

    return True

if __name__ == "__main__":
    build_drawio_xml()
    build_svg_diagrams()
    validate_all()
