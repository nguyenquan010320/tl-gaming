<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\ContactSettings;
use App\Core\Controller;
use App\Core\Flash;
use Throwable;

final class ContactController extends Controller
{
    private const ROUTE = '/admin/cai-dat/lien-he';
    private const FIELD_LIMITS = [
        'showroom_address' => 240,
        'opening_hours' => 100,
        'hotline' => 32,
        'support_email' => 254,
        'facebook_url' => 512,
        'zalo_url' => 512,
        'messenger_url' => 512,
    ];

    public function edit(): void
    {
        $contact = ContactSettings::configuration();
        $this->renderForm([
            'showroom_address' => $contact['showroom']['address'],
            'opening_hours' => $contact['showroom']['hours'],
            'hotline' => $contact['hotline']['display'],
            'support_email' => $contact['email']['address'],
            'facebook_url' => $contact['facebook']['href'],
            'zalo_url' => $contact['zalo']['href'],
            'messenger_url' => $contact['messenger']['href'],
        ]);
    }

    public function update(): void
    {
        $values = [];
        $errors = [];

        foreach (self::FIELD_LIMITS as $name => $limit) {
            $submitted = $_POST[$name] ?? null;
            $value = is_string($submitted) ? trim($submitted) : '';
            $values[$name] = $value;

            if (!is_string($submitted) || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) !== 0 || mb_strlen($value, 'UTF-8') > $limit) {
                $errors[$name] = "Vui lòng nhập thông tin trên một dòng, tối đa {$limit} ký tự.";
                continue;
            }

            $error = match ($name) {
                'showroom_address', 'opening_hours' => $value === '' ? 'Vui lòng điền thông tin này.' : null,
                'hotline' => ContactSettings::dialNumber($value) === null ? 'Vui lòng nhập số hotline hợp lệ.' : null,
                'support_email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                    ? 'Vui lòng nhập địa chỉ email hợp lệ.' : null,
                'facebook_url' => $value !== '' && !ContactSettings::isSocialUrl('facebook', $value)
                    ? 'Vui lòng dùng liên kết HTTPS trên facebook.com hoặc fb.com.' : null,
                'zalo_url' => $value !== '' && !ContactSettings::isSocialUrl('zalo', $value)
                    ? 'Vui lòng dùng liên kết HTTPS trên zalo.me hoặc oa.zalo.me.' : null,
                'messenger_url' => $value !== '' && !ContactSettings::isSocialUrl('messenger', $value)
                    ? 'Vui lòng dùng liên kết HTTPS trên m.me hoặc messenger.com.' : null,
            };

            if ($error !== null) {
                $errors[$name] = $error;
            }
        }

        if ($errors !== []) {
            http_response_code(422);
            $this->renderForm($values, $errors);
            return;
        }

        try {
            ContactSettings::save($values);
        } catch (Throwable $error) {
            error_log('[TL Gaming ContactController] ' . $error->getMessage());
            Flash::add('error', 'Chưa thể lưu thông tin liên hệ. Vui lòng thử lại.');
            http_response_code(500);
            $this->renderForm($values);
            return;
        }

        Flash::add('success', 'Đã cập nhật thông tin liên hệ của cửa hàng.');
        $this->redirect(self::ROUTE, 303);
    }

    private function renderForm(array $values, array $errors = []): void
    {
        $this->render('admin/contact/edit', [
            'pageTitle' => 'Thông tin liên hệ | Quản trị TL Gaming',
            'currentRoute' => self::ROUTE,
            'breadcrumbs' => [['label' => 'Thông tin liên hệ']],
            'formAction' => self::ROUTE,
            'values' => $values,
            'errors' => $errors,
            'limits' => self::FIELD_LIMITS,
        ], 'admin');
    }
}
