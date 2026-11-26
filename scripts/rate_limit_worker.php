<?php
/**
 * Worker script phục vụ kiểm thử Race Condition của RateLimiter với nhiều tiến trình đồng thời.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $className): void {
    $prefix  = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';

    if (!str_starts_with($className, $prefix)) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $filePath      = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($filePath)) {
        require_once $filePath;
    }
});

use App\Core\RateLimiter;

$key    = $argv[1] ?? 'test:concurrent';
$window = isset($argv[2]) ? (int) $argv[2] : 60;
$count  = isset($argv[3]) ? (int) $argv[3] : 1;

for ($i = 0; $i < $count; $i++) {
    RateLimiter::hit($key, $window);
    // Micro-pause giả lập độ trễ I/O thực tế
    usleep(1000); // 1ms
}

echo "OK:$count";
