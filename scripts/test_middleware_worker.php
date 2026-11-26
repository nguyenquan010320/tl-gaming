<?php
/**
 * Worker script phục vụ kiểm thử phân quyền AdminMiddleware trong môi trường tiến trình cô lập.
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

require_once dirname(__DIR__) . '/config/env.php';
loadEnvFile(dirname(__DIR__) . '/.env');

use App\Core\Auth;
use App\Middleware\AdminMiddleware;
use App\Models\UserModel;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$roleToTest = $argv[1] ?? 'guest';

if ($roleToTest === 'admin') {
    $admin = (new UserModel())->findByEmailForLogin('admin@tlgaming.vn');
    if (!$admin) {
        echo "FAIL:ADMIN_NOT_FOUND\n";
        exit(1);
    }
    Auth::login($admin);
} elseif ($roleToTest === 'customer') {
    $_SESSION['auth_user'] = [
        'id'        => 9999,
        'email'     => 'customer_test@example.com',
        'full_name' => 'Test Customer',
        'role'      => 'customer',
        'status'    => 'active',
        'pw_sig'    => 'dummy_sig',
    ];
} else {
    $_SESSION = [];
}

// Chạy middleware
$middleware = new AdminMiddleware();
$middleware->handle();

// Nếu không bị exit(403) thì đến được đây
echo "OK:ADMIN_AUTHORIZED\n";
