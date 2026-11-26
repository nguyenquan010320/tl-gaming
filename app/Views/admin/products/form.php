<?php
/**
 * Biểu mẫu Thêm / Sửa Sản phẩm Admin (`app/Views/admin/products/form.php`).
 *
 * Vai trò:
 * - Dùng chung cho `GET /admin/san-pham/them` và `GET /admin/san-pham/{id}/sua`.
 * - Hỗ trợ đầy đủ trường theo schema `products`, thông số kỹ thuật động `product_specs`,
 *   upload nhiều ảnh vào `public/uploads/products/`, chọn ảnh đại diện, sắp xếp và xóa ảnh an toàn.
 * - Phân tách rõ `has_serials = 0` (điều chỉnh tồn kho nguyên tử / khóa lạc quan) và `has_serials = 1`
 *   (hiển thị số máy từ `product_units`, khóa nhập số lượng giả, liên kết tới Prompt 18).
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

/** @var string $mode */
/** @var array<string, mixed> $product */
/** @var array<string, mixed>|null $dbProduct */
/** @var array<int, array<string, mixed>> $specs */
/** @var array<int, array<string, mixed>> $images */
/** @var array<int, array<string, mixed>> $categories */
/** @var string $formAction */
/** @var bool $hasDeps */

$isEdit          = $mode === 'edit';
$productId       = (int) ($product['id'] ?? 0);
$selectedCatId   = (string) ($product['category_id'] ?? '');
$selectedStatus  = (string) ($product['status'] ?? 'draft');
$hasSerialsVal   = (string) ($product['has_serials'] ?? '1');
$currentDbStock  = (int) (($dbProduct['stock_quantity'] ?? $product['stock_quantity'] ?? 0));
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title"><?= $isEdit ? 'Chỉnh sửa sản phẩm #' . $productId : 'Thêm sản phẩm mới' ?></h1>
        <p class="admin-page-subtitle">
            <?= $isEdit
                ? 'Cập nhật thông tin dòng sản phẩm, thông số kỹ thuật và hình ảnh thực tế. Không làm ảnh hưởng đơn hàng cũ.'
                : 'Tạo sản phẩm mới cho cửa hàng TL Gaming. Hỗ trợ upload nhiều ảnh và thông số kỹ thuật động.' ?>
        </p>
    </div>
    <a href="<?= View::e(View::url('/admin/san-pham')) ?>" class="adm-btn adm-btn-secondary">
        ← Quay lại danh sách
    </a>
</div>

<form action="<?= View::e(View::url($formAction)) ?>" method="POST" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="_csrf_token" value="<?= View::e(Csrf::token()) ?>">

    <!-- KHỐI 1: THÔNG TIN CƠ BẢN & GIÁ BÁN -->
    <div class="admin-card" style="margin-bottom: 18px;">
        <h2 class="admin-section-title">1. Thông tin cơ bản &amp; Giá bán</h2>
        <div class="admin-form-grid">
            <div class="adm-field admin-form-full">
                <label for="prodNameInput">Tên sản phẩm <span class="adm-req">*</span></label>
                <input type="text"
                       id="prodNameInput"
                       name="name"
                       class="adm-input"
                       required
                       maxlength="255"
                       value="<?= View::e((string) ($product['name'] ?? '')) ?>"
                       placeholder="Ví dụ: Laptop Dell Gaming G15 5515 Ryzen 7 5800H / RTX 3060">
            </div>

            <div class="adm-field">
                <label for="prodSkuInput">Mã SKU (Duy nhất) <span class="adm-req">*</span></label>
                <input type="text"
                       id="prodSkuInput"
                       name="sku"
                       class="adm-input"
                       required
                       maxlength="50"
                       value="<?= View::e((string) ($product['sku'] ?? '')) ?>"
                       placeholder="VD: TLG-LAP-G15-5515">
            </div>

            <div class="adm-field">
                <label for="prodSlugInput">Đường dẫn tĩnh (Slug) <span class="adm-req">*</span></label>
                <input type="text"
                       id="prodSlugInput"
                       name="slug"
                       class="adm-input"
                       maxlength="255"
                       data-auto-slug="<?= $isEdit ? '0' : '1' ?>"
                       value="<?= View::e((string) ($product['slug'] ?? '')) ?>"
                       placeholder="tu-dong-tao-tu-ten-san-pham">
            </div>

            <div class="adm-field">
                <label for="prodCatSelect">Danh mục <span class="adm-req">*</span></label>
                <select id="prodCatSelect" name="category_id" class="adm-select" required>
                    <option value="">— Chọn danh mục —</option>
                    <?php foreach ($categories as $cat): ?>
                        <?php
                        $cId       = (string) $cat['id'];
                        $depth     = max(1, (int) ($cat['depth'] ?? 1));
                        $indent    = $depth > 1 ? str_repeat('— ', $depth - 1) : '';
                        $hiddenTag = ((int) ($cat['is_active'] ?? 1) !== 1) ? ' [Đang ẩn]' : '';
                        ?>
                        <option value="<?= View::e($cId) ?>" <?= $selectedCatId === $cId ? 'selected' : '' ?>>
                            <?= View::e($indent . (string) $cat['name'] . $hiddenTag) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="adm-field-hint">Không thể xuất bản (published) vào danh mục đang bị ẩn hoặc có cha bị ẩn.</span>
            </div>

            <div class="adm-field">
                <label for="prodStatusSelect">Trạng thái phát hành</label>
                <select id="prodStatusSelect" name="status" class="adm-select">
                    <option value="draft" <?= $selectedStatus === 'draft' ? 'selected' : '' ?>>Bản nháp (draft)</option>
                    <option value="published" <?= $selectedStatus === 'published' ? 'selected' : '' ?>>Đang bán công khai (published)</option>
                    <option value="out_of_stock" <?= $selectedStatus === 'out_of_stock' ? 'selected' : '' ?>>Hết hàng (out_of_stock)</option>
                    <option value="archived" <?= $selectedStatus === 'archived' ? 'selected' : '' ?>>Đang ẩn (archived)</option>
                </select>
            </div>

            <div class="adm-field">
                <label for="prodPriceInput">Giá bán (VND) <span class="adm-req">*</span></label>
                <input type="number"
                       id="prodPriceInput"
                       name="price"
                       class="adm-input"
                       min="0"
                       step="1000"
                       required
                       value="<?= View::e((string) ($product['price'] ?? '')) ?>">
            </div>

            <div class="adm-field">
                <label for="prodOrigPriceInput">Giá gốc niêm yết (VND)</label>
                <input type="number"
                       id="prodOrigPriceInput"
                       name="original_price"
                       class="adm-input"
                       min="0"
                       step="1000"
                       value="<?= View::e((string) ($product['original_price'] ?? '')) ?>"
                       placeholder="Để trống nếu không giảm giá">
                <span class="adm-field-hint">Nếu nhập, bắt buộc lớn hơn hoặc bằng giá bán hiện tại.</span>
            </div>

            <div class="adm-field">
                <label for="prodWarrantyInput">Bảo hành (Tháng) <span class="adm-req">*</span></label>
                <input type="number"
                       id="prodWarrantyInput"
                       name="warranty_months"
                       class="adm-input"
                       min="0"
                       max="120"
                       required
                       value="<?= View::e((string) ($product['warranty_months'] ?? '12')) ?>">
            </div>

            <div class="adm-field">
                <label for="prodConditionInput">Tổng quan tình trạng máy <span class="adm-req">*</span></label>
                <input type="text"
                       id="prodConditionInput"
                       name="condition_overview"
                       class="adm-input"
                       required
                       maxlength="100"
                       value="<?= View::e((string) ($product['condition_overview'] ?? 'Like New 99%')) ?>"
                       placeholder="VD: Like New 99%, Zin nguyên bản">
            </div>

            <div class="adm-field admin-form-full">
                <label for="prodSummaryInput">Tóm tắt nổi bật</label>
                <textarea id="prodSummaryInput"
                          name="summary"
                          class="adm-textarea"
                          rows="2"
                          maxlength="500"
                          placeholder="Tóm tắt ngắn gọn cấu hình và điểm nổi bật..."><?= View::e((string) ($product['summary'] ?? '')) ?></textarea>
            </div>

            <div class="adm-field admin-form-full">
                <label for="prodDescInput">Mô tả chi tiết &amp; Đánh giá chuyên sâu</label>
                <textarea id="prodDescInput"
                          name="description"
                          class="adm-textarea"
                          rows="5"
                          placeholder="Mô tả chi tiết ngoại hình, hiệu năng, tình trạng thực tế..."><?= View::e((string) ($product['description'] ?? '')) ?></textarea>
            </div>
        </div>
    </div>

    <!-- KHỐI 2: QUẢN LÝ TỒN KHO & SERIAL -->
    <div class="admin-card" style="margin-bottom: 18px;">
        <h2 class="admin-section-title">2. Loại quản lý tồn kho &amp; Serial</h2>
        <div class="admin-form-grid">
            <div class="adm-field">
                <label for="prodHasSerialsSelect">Hình thức quản lý kho</label>
                <?php if ($isEdit && $hasDeps): ?>
                    <input type="hidden" name="has_serials" value="<?= View::e($hasSerialsVal) ?>">
                    <select id="prodHasSerialsSelect" class="adm-select" disabled>
                        <option value="1" <?= $hasSerialsVal === '1' ? 'selected' : '' ?>>Quản lý theo từng máy Serial riêng (has_serials = 1)</option>
                        <option value="0" <?= $hasSerialsVal === '0' ? 'selected' : '' ?>>Quản lý theo tổng số lượng (has_serials = 0)</option>
                    </select>
                    <span class="adm-field-hint" style="color:#b45309;">
                        Đã khóa thay đổi vì sản phẩm có dữ liệu Serial, giỏ hàng hoặc đơn hàng liên quan.
                    </span>
                <?php else: ?>
                    <select id="prodHasSerialsSelect" name="has_serials" class="adm-select" data-stock-toggle="1">
                        <option value="1" <?= $hasSerialsVal === '1' ? 'selected' : '' ?>>Quản lý theo từng máy Serial riêng (has_serials = 1)</option>
                        <option value="0" <?= $hasSerialsVal === '0' ? 'selected' : '' ?>>Quản lý theo tổng số lượng (has_serials = 0)</option>
                    </select>
                <?php endif; ?>
            </div>

            <!-- KHU VỰC DÀNH CHO SẢN PHẨM QUẢN LÝ SỐ LƯỢNG (has_serials = 0) -->
            <div class="adm-field" id="qtyStockBox" style="<?= $hasSerialsVal === '0' ? '' : 'display:none;' ?>">
                <?php if (!$isEdit): ?>
                    <label for="prodStockQtyInput">Số lượng tồn kho ban đầu</label>
                    <input type="number"
                           id="prodStockQtyInput"
                           name="stock_quantity"
                           class="adm-input"
                           min="0"
                           max="100000"
                           value="<?= View::e((string) ($product['stock_quantity'] ?? '0')) ?>">
                    <input type="hidden" name="stock_mode" value="set">
                <?php else: ?>
                    <label for="prodStockAdjInput">Điều chỉnh tồn kho (+/-) — Hiện có: <strong><?= $currentDbStock ?></strong></label>
                    <input type="hidden" name="stock_mode" value="adjust">
                    <input type="hidden" name="expected_stock_quantity" value="<?= $currentDbStock ?>">
                    <input type="number"
                           id="prodStockAdjInput"
                           name="stock_adjustment"
                           class="adm-input"
                           value="0"
                           placeholder="Nhập +5 để nhập thêm hoặc -2 để xuất bớt">
                    <span class="adm-field-hint">
                        Dùng phép cộng/trừ nguyên tử trong Transaction để không làm mất thay đổi tồn kho khi khách đang Checkout đồng thời.
                    </span>
                <?php endif; ?>
            </div>

            <!-- KHU VỰC DÀNH CHO SẢN PHẨM QUẢN LÝ SERIAL (has_serials = 1) -->
            <div class="adm-field" id="serialStockBox" style="<?= $hasSerialsVal === '1' ? '' : 'display:none;' ?>">
                <label>Tồn kho theo Serial (<code>product_units</code>)</label>
                <div class="adm-serial-info-box">
                    <?php if ($isEdit): ?>
                        <div>
                            Sẵn sàng bán: <strong><?= View::e((string) ($dbProduct['available_serial_count'] ?? 0)) ?></strong> ·
                            Đang giữ đơn: <strong><?= View::e((string) ($dbProduct['reserved_serial_count'] ?? 0)) ?></strong> ·
                            Đã bán: <strong><?= View::e((string) ($dbProduct['sold_serial_count'] ?? 0)) ?></strong>
                        </div>
                        <div class="adm-field-hint" style="margin-top:6px;">
                            Không thể nhập số lượng thủ công cho sản phẩm quản lý Serial. Việc thêm máy vật lý và biên bản kiểm định 18 bước thực hiện tại phân hệ Quản lý Serial.
                        </div>
                        <div class="adm-action-group" style="margin-top:8px;">
                            <a href="<?= View::e(View::url('/admin/serial?san-pham=' . (int) ($dbProduct['id'] ?? 0))) ?>" class="adm-btn adm-btn-secondary adm-btn-sm">
                                Quản lý Serial của sản phẩm này →
                            </a>
                            <a href="<?= View::e(View::url('/admin/serial/them?san-pham=' . (int) ($dbProduct['id'] ?? 0))) ?>" class="adm-btn adm-btn-primary adm-btn-sm">
                                + Thêm Serial
                            </a>
                        </div>
                    <?php else: ?>
                        <div>Tồn kho khả dụng khởi tạo = <strong>0</strong> máy.</div>
                        <div class="adm-field-hint" style="margin-top:4px;">
                            Sau khi tạo sản phẩm, từng cá thể máy có Serial sẽ được nhập kho và kiểm định 18 bước ở phân hệ Quản lý Serial (Prompt 18).
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- KHỐI 3: THÔNG SỐ KỸ THUẬT ĐỘNG (product_specs) -->
    <div class="admin-card" style="margin-bottom: 18px;">
        <div class="admin-section-head">
            <div>
                <h2 class="admin-section-title" style="margin-bottom:4px;">3. Thông số kỹ thuật phần cứng (<code>product_specs</code>)</h2>
                <p class="adm-field-hint" style="margin:0;">Thêm, sửa hoặc xóa linh hoạt các thông số (CPU, RAM, SSD, VGA, Mainboard, Nguồn, Màn hình...).</p>
            </div>
            <button type="button" id="addSpecRowBtn" class="adm-btn adm-btn-secondary adm-btn-sm">+ Thêm dòng thông số</button>
        </div>

        <div id="specsBuilderList" class="adm-specs-list">
            <?php foreach ($specs as $idx => $sp): ?>
                <div class="adm-spec-row">
                    <input type="text"
                           name="spec_group[]"
                           class="adm-input"
                           maxlength="100"
                           value="<?= View::e((string) ($sp['group_name'] ?? '')) ?>"
                           placeholder="Nhóm (VD: Cấu hình chính)">
                    <input type="text"
                           name="spec_name[]"
                           class="adm-input"
                           maxlength="100"
                           value="<?= View::e((string) ($sp['spec_name'] ?? '')) ?>"
                           placeholder="Tên thông số (VD: CPU, RAM, VGA)">
                    <input type="text"
                           name="spec_value[]"
                           class="adm-input"
                           maxlength="255"
                           value="<?= View::e((string) ($sp['spec_value'] ?? '')) ?>"
                           placeholder="Giá trị (VD: Core i5-12400F)">
                    <input type="number"
                           name="spec_order[]"
                           class="adm-input adm-spec-order"
                           value="<?= View::e((string) ($sp['sort_order'] ?? ($idx + 1))) ?>"
                           title="Thứ tự">
                    <button type="button" class="adm-btn adm-btn-danger adm-btn-sm remove-spec-row">Xóa</button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- KHỐI 4: HÌNH ẢNH SẢN PHẨM -->
    <div class="admin-card" style="margin-bottom: 18px;">
        <h2 class="admin-section-title">4. Hình ảnh thực tế sản phẩm (<code>product_images</code>)</h2>

        <div class="adm-field">
            <label for="prodImagesInput">Tải lên ảnh mới (JPEG, PNG<?= (function_exists('imagewebp') || defined('IMAGETYPE_WEBP')) ? ', WEBP' : '' ?> — Tối đa 5MB/ảnh, tối đa 10 ảnh)</label>
            <input type="file"
                   id="prodImagesInput"
                   name="images[]"
                   class="adm-input"
                   multiple
                   accept="image/jpeg,image/png,image/webp">
        </div>

        <?php if ($isEdit && $images !== []): ?>
            <div class="adm-gallery-grid" style="margin-top:16px;">
                <?php foreach ($images as $img): ?>
                    <?php $imgId = (int) $img['id']; ?>
                    <div class="adm-gallery-card">
                        <img src="<?= View::e(View::imageUrl((string) $img['image_url'])) ?>"
                             alt="<?= View::e((string) ($img['alt_text'] ?? $product['name'])) ?>">
                        <div class="adm-gallery-controls">
                            <label class="adm-radio-primary">
                                <input type="radio"
                                       name="primary_image_id"
                                       value="<?= $imgId ?>"
                                       <?= ((int) $img['is_primary'] === 1) ? 'checked' : '' ?>>
                                Ảnh đại diện
                            </label>
                            <label class="adm-sort-label">
                                Thứ tự:
                                <input type="number"
                                       name="image_sort_orders[<?= $imgId ?>]"
                                       class="adm-input adm-input-xs"
                                       value="<?= View::e((string) $img['sort_order']) ?>">
                            </label>
                            <button type="submit"
                                    form="delImgForm<?= $imgId ?>"
                                    class="adm-btn adm-btn-danger adm-btn-sm"
                                    onclick="return confirm('Xóa hình ảnh này khỏi sản phẩm?');">
                                Xóa ảnh
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="admin-form-actions">
        <button type="submit" class="adm-btn adm-btn-primary">
            <?= $isEdit ? 'Lưu thay đổi sản phẩm' : 'Tạo sản phẩm mới' ?>
        </button>
        <a href="<?= View::e(View::url('/admin/san-pham')) ?>" class="adm-btn adm-btn-secondary">Hủy bỏ</a>
    </div>
</form>

<?php if ($isEdit && $images !== []): ?>
    <?php foreach ($images as $img): ?>
        <?php $imgId = (int) $img['id']; ?>
        <form id="delImgForm<?= $imgId ?>"
              method="POST"
              action="<?= View::e(View::url("/admin/san-pham/{$productId}/anh/{$imgId}/xoa")) ?>"
              style="display:none;">
            <input type="hidden" name="_csrf_token" value="<?= View::e(Csrf::token()) ?>">
        </form>
    <?php endforeach; ?>
<?php endif; ?>
