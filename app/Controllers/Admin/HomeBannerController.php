<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\HomeBannerSettings;
use InvalidArgumentException;
use Throwable;

final class HomeBannerController extends Controller
{
    private const ROUTE = '/admin/cai-dat/banner-trang-chu';
    private const LIMITS = ['title' => 120, 'description' => 320, 'button_url' => 1024];

    public function edit(): void
    {
        $this->renderForm();
    }

    public function update(): void
    {
        $values = [];
        $errors = [];
        foreach (self::LIMITS as $field => $limit) {
            $input = $_POST[$field] ?? null;
            $value = is_string($input) ? trim(str_replace(["\r\n", "\r"], "\n", $input)) : '';
            $values[$field] = $value;

            if (!is_string($input) || preg_match('/\p{Cc}/u', str_replace("\n", '', $value)) !== 0
                || mb_strlen($value, 'UTF-8') > $limit) {
                $errors[$field] = "Vui lòng nhập nội dung hợp lệ, tối đa {$limit} ký tự.";
            }
        }
        if (!isset($errors['title']) && $values['title'] === '') {
            $errors['title'] = 'Vui lòng nhập tiêu đề banner.';
        }
        if (!isset($errors['button_url']) && !HomeBannerSettings::isButtonUrl($values['button_url'])) {
            $errors['button_url'] = 'Vui lòng nhập đường dẫn nội bộ hoặc liên kết HTTP/HTTPS hợp lệ.';
        }
        if ($errors !== []) {
            http_response_code(422);
            $this->renderForm($values, $errors);
            return;
        }

        try {
            HomeBannerSettings::save($values, $_FILES['image'] ?? null);
        } catch (InvalidArgumentException $error) {
            http_response_code(422);
            $this->renderForm($values, ['image' => $error->getMessage()]);
            return;
        } catch (Throwable $error) {
            error_log('[TL Gaming HomeBannerController] ' . $error->getMessage());
            Flash::add('error', 'Chưa thể lưu banner trang chủ. Vui lòng thử lại.');
            http_response_code(500);
            $this->renderForm($values);
            return;
        }

        Flash::add('success', 'Đã cập nhật banner trang chủ.');
        $this->redirect(self::ROUTE, 303);
    }

    private function renderForm(array $values = [], array $errors = []): void
    {
        $this->render('admin/home_banner/edit', [
            'pageTitle' => 'Banner trang chủ | Quản trị TL Gaming',
            'currentRoute' => self::ROUTE,
            'breadcrumbs' => [['label' => 'Banner trang chủ']],
            'formAction' => self::ROUTE,
            'banner' => array_replace(HomeBannerSettings::current(), $values),
            'errors' => $errors,
            'limits' => self::LIMITS,
        ], 'admin');
    }
}
