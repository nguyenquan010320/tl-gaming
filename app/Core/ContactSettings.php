<?php

declare(strict_types=1);

namespace App\Core;

final class ContactSettings
{
    private const FILE = __DIR__ . '/../../storage/contact-settings.json';
    private const SOCIAL_HOSTS = [
        'facebook' => ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.com'],
        'zalo' => ['zalo.me', 'oa.zalo.me'],
        'messenger' => ['m.me', 'messenger.com', 'www.messenger.com'],
    ];

    /** @return array<string, mixed> */
    public static function configuration(): array
    {
        static $configuration = null;

        return $configuration ??= require __DIR__ . '/../../config/contact.php';
    }

    /** @return array<string, string> */
    public static function read(): array
    {
        return JsonSettingsFile::read(self::FILE);
    }

    /** @param array<string, string> $settings */
    public static function save(array $settings): void
    {
        JsonSettingsFile::write(self::FILE, $settings);
    }

    public static function dialNumber(string $display): ?string
    {
        if (preg_match('/^\+?[0-9 ().-]+$/D', $display) !== 1) {
            return null;
        }

        $number = preg_replace('/[^0-9+]/', '', $display) ?? '';
        return preg_match('/^\+?[0-9]{3,15}$/D', $number) === 1 ? $number : null;
    }

    public static function isSocialUrl(string $channel, string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::SOCIAL_HOSTS[$channel] ?? [], true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && trim($parts['path'] ?? '', '/') !== '';
    }
}
