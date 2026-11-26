<?php

declare(strict_types=1);

namespace App\Core;

use finfo;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class HomeBannerSettings
{
    private const FILE = __DIR__ . '/../../storage/home-banner.json';
    private const LOCK = __DIR__ . '/../../storage/home-banner.lock';
    private const PUBLIC_DIR = __DIR__ . '/../../public';
    private const UPLOAD_PREFIX = '/uploads/banners/';
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    private const MAX_IMAGE_EDGE = 6000;
    private const MAX_IMAGE_PIXELS = 24000000;
    private const IMAGE_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const DEFAULTS = [
        'title' => "PC & laptop\ngaming cũ",
        'description' => 'Xem cấu hình, tình trạng máy và hồ sơ kiểm định theo từng số serial.',
        'button_url' => '/san-pham',
        'image' => '/assets/images/storefront-hero.webp',
    ];

    /** @return array{title: string, description: string, button_url: string, image: string} */
    public static function current(): array
    {
        $banner = array_replace(self::DEFAULTS, array_intersect_key(JsonSettingsFile::read(self::FILE), self::DEFAULTS));
        if (!preg_match('#^/uploads/banners/[a-f0-9]{32}\.(jpg|png|webp)$#D', $banner['image'])
            || !is_file(self::PUBLIC_DIR . $banner['image'])) {
            $banner['image'] = self::DEFAULTS['image'];
        }
        if (!self::isButtonUrl($banner['button_url'])) {
            $banner['button_url'] = self::DEFAULTS['button_url'];
        }
        return $banner;
    }

    public static function isButtonUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) || str_contains($url, '\\')) {
            return false;
        }

        if (str_starts_with($url, '#') || (str_starts_with($url, '/') && !str_starts_with($url, '//'))) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
    }

    /** @param array{title: string, description: string, button_url: string} $values */
    public static function save(array $values, ?array $upload): void
    {
        $newImage = self::storeImage($upload);

        try {
            $lock = @fopen(self::LOCK, 'c');
            if ($lock === false) {
                throw new RuntimeException('Không thể mở khóa lưu banner trang chủ.');
            }
            try {
                if (!flock($lock, LOCK_EX)) {
                    throw new RuntimeException('Không thể khóa cấu hình banner trang chủ.');
                }
                $values['image'] = $newImage ?? self::current()['image'];
                JsonSettingsFile::write(self::FILE, $values);
            } finally {
                fclose($lock);
            }
        } catch (Throwable $error) {
            if ($newImage !== null) {
                @unlink(self::PUBLIC_DIR . $newImage);
            }
            throw $error;
        }
    }

    private static function storeImage(?array $upload): ?string
    {
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('Ảnh banner phải có dung lượng tối đa 5 MB.');
        }
        $temporaryFile = $upload['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($temporaryFile) || !is_uploaded_file($temporaryFile)) {
            throw new InvalidArgumentException('Không thể đọc tệp tải lên. Vui lòng chọn lại ảnh.');
        }

        $bytes = filesize($temporaryFile);
        if ($bytes === false || $bytes < 1 || $bytes > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('Ảnh banner phải có dung lượng tối đa 5 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryFile);
        $image = @getimagesize($temporaryFile);
        if (!isset(self::IMAGE_EXTENSIONS[$mime]) || $image === false || $image['mime'] !== $mime) {
            throw new InvalidArgumentException('Vui lòng tải ảnh JPEG, PNG hoặc WebP hợp lệ.');
        }
        if ($mime === 'image/webp') {
            // RIFF ghi kích thước phần dữ liệu tính từ byte thứ 8.
            $sizeField = @file_get_contents($temporaryFile, false, null, 4, 4);
            if ($sizeField === false || strlen($sizeField) !== 4
                || unpack('Vsize', $sizeField)['size'] > $bytes - 8) {
                throw new InvalidArgumentException('Ảnh WebP bị thiếu dữ liệu. Vui lòng chọn một tệp ảnh đầy đủ.');
            }
        }
        [$width, $height] = $image;
        if ($width < 1 || $height < 1 || max($width, $height) > self::MAX_IMAGE_EDGE
            || $width * $height > self::MAX_IMAGE_PIXELS) {
            throw new InvalidArgumentException('Ảnh không được vượt quá 6.000 px mỗi cạnh và 24 megapixel.');
        }

        $directory = self::PUBLIC_DIR . self::UPLOAD_PREFIX;
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Không thể tạo thư mục lưu ảnh banner.');
        }
        $imagePath = self::UPLOAD_PREFIX . bin2hex(random_bytes(16)) . '.' . self::IMAGE_EXTENSIONS[$mime];
        if (!@move_uploaded_file($temporaryFile, self::PUBLIC_DIR . $imagePath)) {
            throw new RuntimeException('Không thể lưu tệp ảnh banner.');
        }
        return $imagePath;
    }
}
