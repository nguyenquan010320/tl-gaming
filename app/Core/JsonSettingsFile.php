<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class JsonSettingsFile
{
    /** @return array<string, string> */
    public static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $json = @file_get_contents($file);
        $settings = $json !== false ? json_decode($json, true) : null;
        if (!is_array($settings)) {
            error_log('[TL Gaming JsonSettingsFile] Không thể đọc ' . basename($file));
            return [];
        }

        return array_filter($settings, 'is_string');
    }

    /** @param array<string, string> $settings */
    public static function write(string $file, array $settings): void
    {
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporaryFile = dirname($file) . '/' . basename($file, '.json') . '-' . bin2hex(random_bytes(12)) . '.tmp';

        // Cùng thư mục để việc thay thế luôn diễn ra sau khi đã ghi đủ dữ liệu.
        try {
            if (@file_put_contents($temporaryFile, $json) !== strlen($json) || !@rename($temporaryFile, $file)) {
                throw new RuntimeException('Không thể ghi ' . basename($file) . ' vào storage.');
            }
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }
}
