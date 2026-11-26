<?php
/**
 * Biểu mẫu Thêm / Sửa Danh mục (`app/Views/admin/categories/form.php`).
 *
 * Vai trò:
 * - Dùng chung cho `GET /admin/danh-muc/them` và `GET /admin/danh-muc/{id}/sua`.
 * - Bảo vệ bằng CSRF token, hiển thị rõ cảnh báo khi đổi slug hoặc thay đổi trạng thái danh mục cha/con.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

/** @var string $mode */
/** @var array<string, mixed> $category */
/** @var array<int, array<string, mixed>> $parentOptions */
/** @var string $formAction */

$isEdit          = $mode === 'edit';
$selectedParent  = (string) ($category['parent_id'] ?? '');
$selectedActive  = (int) ($category['is_active'] ?? 1);
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title"><?= $isEdit ? 'Chỉnh sửa danh mục' : 'Thêm danh mục mới' ?></h1>
        <p class="admin-page-subtitle">
            <?= $isEdit
                ? 'Cập nhật thông tin danh mục #' . (int) ($category['id'] ?? 0) . '. Hệ thống tự động kiểm tra chống chu trình cha/con.'
                : 'Tạo danh mục mới cho cửa hàng TL Gaming. Hỗ trợ tối đa ' . \App\Models\CategoryModel::MAX_DEPTH . ' cấp phân cấp.' ?>
        </p>
    </div>
    <a href="<?= View::e(View::url('/admin/danh-muc')) ?>" class="adm-btn adm-btn-secondary">
        ← Quay lại danh sách
    </a>
</div>

<div class="admin-card">
    <form action="<?= View::e(View::url($formAction)) ?>" method="POST" novalidate>
        <input type="hidden" name="_csrf_token" value="<?= View::e(Csrf::token()) ?>">

        <div class="admin-form-grid">
            <div class="adm-field">
                <label for="catNameInput">Tên danh mục <span class="adm-req">*</span></label>
                <input type="text"
                       id="catNameInput"
                       name="name"
                       class="adm-input"
                       required
                       maxlength="100"
                       value="<?= View::e((string) ($category['name'] ?? '')) ?>"
                       placeholder="Ví dụ: Laptop Gaming Cũ">
            </div>

            <div class="adm-field">
                <label for="catSlugInput">Đường dẫn tĩnh (Slug) <span class="adm-req">*</span></label>
                <input type="text"
                       id="catSlugInput"
                       name="slug"
                       class="adm-input"
                       maxlength="120"
                       data-auto-slug="<?= $isEdit ? '0' : '1' ?>"
                       value="<?= View::e((string) ($category['slug'] ?? '')) ?>"
                       placeholder="vi-du-laptop-gaming-cu">
                <span class="adm-field-hint">
                    <?php if ($isEdit): ?>
                        Lưu ý: Thay đổi slug sẽ làm đổi URL lọc sản phẩm (<code>/san-pham?danh-muc=slug-moi</code>).
                    <?php else: ?>
                        Để trống để tự động tạo từ Tên danh mục (chỉ gồm chữ thường, số và dấu gạch ngang).
                    <?php endif; ?>
                </span>
            </div>

            <div class="adm-field">
                <label for="catParentSelect">Danh mục cha</label>
                <select id="catParentSelect" name="parent_id" class="adm-select">
                    <option value="">— Là danh mục gốc (Cấp 1) —</option>
                    <?php foreach ($parentOptions as $opt): ?>
                        <?php
                        $optId     = (string) $opt['id'];
                        $optDepth  = max(1, (int) ($opt['depth'] ?? 1));
                        $indent    = $optDepth > 1 ? str_repeat('— ', $optDepth - 1) : '';
                        $hiddenTag = ((int) ($opt['is_active'] ?? 1) !== 1) ? ' [Đang ẩn]' : '';
                        ?>
                        <option value="<?= View::e($optId) ?>" <?= $selectedParent === $optId ? 'selected' : '' ?>>
                            <?= View::e($indent . (string) $opt['name'] . $hiddenTag) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="adm-field-hint">Hệ thống tự động loại trừ chính danh mục này và các danh mục con để tránh vòng lặp.</span>
            </div>

            <div class="adm-field">
                <label for="catOrderInput">Thứ tự hiển thị</label>
                <input type="number"
                       id="catOrderInput"
                       name="display_order"
                       class="adm-input"
                       min="-9999"
                       max="9999"
                       value="<?= View::e((string) ($category['display_order'] ?? 0)) ?>">
                <span class="adm-field-hint">Số nhỏ hơn sẽ ưu tiên hiển thị trước.</span>
            </div>

            <div class="adm-field">
                <label for="catStatusSelect">Trạng thái hiển thị</label>
                <select id="catStatusSelect" name="is_active" class="adm-select">
                    <option value="1" <?= $selectedActive === 1 ? 'selected' : '' ?>>Hiển thị (Công khai)</option>
                    <option value="0" <?= $selectedActive === 0 ? 'selected' : '' ?>>Ẩn (Ngừng hiển thị công khai)</option>
                </select>
                <span class="adm-field-hint">Nếu ẩn danh mục cha, toàn bộ danh mục con trực thuộc cũng sẽ tự động bị ẩn.</span>
            </div>

            <div class="adm-field admin-form-full">
                <label for="catDescInput">Mô tả danh mục</label>
                <textarea id="catDescInput"
                          name="description"
                          class="adm-textarea"
                          rows="4"
                          maxlength="2000"
                          placeholder="Mô tả ngắn gọn về nhóm sản phẩm thuộc danh mục này..."><?= View::e((string) ($category['description'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="adm-form-actions">
            <button type="submit" class="adm-btn adm-btn-primary">
                <?= $isEdit ? 'Lưu thay đổi' : 'Tạo danh mục' ?>
            </button>
            <a href="<?= View::e(View::url('/admin/danh-muc')) ?>" class="adm-btn adm-btn-secondary">
                Hủy
            </a>
        </div>
    </form>
</div>
