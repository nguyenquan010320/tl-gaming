<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

$fields = [
    'showroom_address' => ['Địa chỉ showroom', 'text', true, 'Địa chỉ hiển thị trên cửa hàng.'],
    'opening_hours' => ['Giờ mở cửa', 'text', true, 'Ví dụ 08:30 - 21:30 (Cả CN & ngày lễ)'],
    'hotline' => ['Hotline hỗ trợ', 'tel', true, 'Số này cũng dùng cho nút gọi trên cửa hàng.'],
    'support_email' => ['Email hỗ trợ', 'email', true, 'Địa chỉ nhận email từ khách hàng, hiển thị ở chân trang.'],
    'facebook_url' => ['Liên kết Facebook', 'url', false, 'Dùng liên kết HTTPS đến Fanpage Facebook. Có thể để trống.'],
    'zalo_url' => ['Liên kết Zalo', 'url', false, 'Dùng liên kết HTTPS từ zalo.me hoặc oa.zalo.me. Có thể để trống.'],
    'messenger_url' => ['Liên kết Messenger', 'url', false, 'Dùng liên kết HTTPS từ m.me hoặc messenger.com. Có thể để trống.'],
];
?>
<div class="admin-page-head">
    <div>
        <h1 class="admin-page-title">Thông tin liên hệ</h1>
        <p class="admin-page-subtitle">Cập nhật địa chỉ, giờ mở cửa, hotline và các kênh liên hệ của cửa hàng.</p>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="admin-flash-stack">
        <div class="admin-alert admin-alert-error" role="alert">Vui lòng kiểm tra các mục có thông báo lỗi bên dưới.</div>
    </div>
<?php endif; ?>

<div class="admin-card">
    <form action="<?= View::e(View::url($formAction)) ?>" method="post">
        <?= Csrf::field() ?>

        <div class="admin-form-grid">
            <?php foreach ($fields as $name => [$label, $type, $required, $hint]): ?>
                <?php
                $id = 'contact-' . $name;
                $error = $errors[$name] ?? null;
                ?>
                <div class="adm-field<?= $name === 'showroom_address' ? ' admin-form-full' : '' ?>">
                    <label for="<?= View::e($id) ?>">
                        <?= View::e($label) ?><?php if ($required): ?> <span class="adm-req" aria-hidden="true">*</span><?php endif; ?>
                    </label>
                    <input type="<?= View::e($type) ?>"
                           class="adm-input"
                           id="<?= View::e($id) ?>"
                           name="<?= View::e($name) ?>"
                           value="<?= View::e($values[$name]) ?>"
                           maxlength="<?= $limits[$name] ?>"
                           aria-describedby="<?= View::e($id) ?>-hint"
                           <?= $required ? 'required' : '' ?>
                           <?= $error !== null ? 'aria-invalid="true"' : '' ?>>
                    <small class="<?= $error !== null ? 'adm-req' : 'adm-field-hint' ?>" id="<?= View::e($id) ?>-hint">
                        <?= View::e($error ?? $hint) ?>
                    </small>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="adm-form-actions">
            <button type="submit" class="adm-btn adm-btn-primary">Lưu thay đổi</button>
        </div>
    </form>
</div>
