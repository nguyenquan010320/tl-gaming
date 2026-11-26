<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

$fields = [
    'title' => ['Tiêu đề', 2, true, 'Có thể xuống dòng để chia tiêu đề.'],
    'description' => ['Mô tả', 3, false, 'Có thể để trống.'],
    'button_url' => ['Liên kết nút Xem sản phẩm', 0, true, 'Dùng đường dẫn như /san-pham hoặc liên kết HTTP/HTTPS đầy đủ.'],
];
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Banner trang chủ</h1>
        <p class="admin-page-subtitle">Cập nhật ảnh và nội dung banner ở đầu trang chủ.</p>
    </div>
    <a href="<?= View::e(View::url('/')) ?>" class="adm-btn adm-btn-secondary">Xem trang chủ</a>
</div>

<?php if ($errors !== []): ?>
    <div class="admin-flash-stack">
        <div class="admin-alert admin-alert-error" role="alert">Vui lòng kiểm tra các mục có thông báo lỗi bên dưới.</div>
    </div>
<?php endif; ?>

<div class="admin-card">
    <form action="<?= View::e(View::url($formAction)) ?>" method="post" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <div class="admin-form-grid">
            <div class="adm-field">
                <img class="admin-banner-preview" src="<?= View::e(View::url($banner['image'])) ?>" alt="Ảnh banner đang hiển thị">
                <label for="banner-image">Tải ảnh banner mới</label>
                <input class="adm-input" id="banner-image" name="image" type="file"
                       accept="image/jpeg,image/png,image/webp" aria-describedby="banner-image-hint"
                       <?= isset($errors['image']) ? 'aria-invalid="true"' : '' ?>>
                <small id="banner-image-hint" class="<?= isset($errors['image']) ? 'adm-req' : 'adm-field-hint' ?>">
                    <?= View::e($errors['image'] ?? 'JPEG, PNG hoặc WebP, tối đa 5 MB. Ưu tiên ảnh ngang có chủ thể ở nửa phải. Để trống để giữ ảnh hiện tại.') ?>
                </small>
            </div>

            <div class="admin-banner-fields">
                <?php foreach ($fields as $field => [$label, $rows, $required, $hint]): ?>
                    <?php
                    $id = 'banner-' . $field;
                    $error = $errors[$field] ?? null;
                    ?>
                    <div class="adm-field">
                        <label for="<?= View::e($id) ?>"><?= View::e($label) ?></label>
                        <?php if ($rows > 0): ?>
                            <textarea class="adm-textarea" id="<?= View::e($id) ?>" name="<?= View::e($field) ?>"
                                      rows="<?= $rows ?>" maxlength="<?= $limits[$field] ?>"
                                      aria-describedby="<?= View::e($id) ?>-hint"
                                      <?= $required ? 'required' : '' ?>
                                      <?= $error !== null ? 'aria-invalid="true"' : '' ?>><?= View::e($banner[$field]) ?></textarea>
                        <?php else: ?>
                            <input class="adm-input" id="<?= View::e($id) ?>" name="<?= View::e($field) ?>"
                                   type="text" maxlength="<?= $limits[$field] ?>"
                                   value="<?= View::e($banner[$field]) ?>"
                                   aria-describedby="<?= View::e($id) ?>-hint" required
                                   <?= $error !== null ? 'aria-invalid="true"' : '' ?>>
                        <?php endif; ?>
                        <small class="<?= $error !== null ? 'adm-req' : 'adm-field-hint' ?>" id="<?= View::e($id) ?>-hint">
                            <?= View::e($error ?? $hint) ?>
                        </small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="adm-form-actions">
            <button type="submit" class="adm-btn adm-btn-primary">Lưu banner</button>
        </div>
    </form>
</div>
