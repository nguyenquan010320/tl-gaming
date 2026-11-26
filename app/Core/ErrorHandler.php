<?php
/**
 * Lớp ErrorHandler quản lý lỗi và ngoại lệ tập trung cho toàn bộ ứng dụng MVC.
 *
 * Vai trò:
 * - Chuyển đổi các lỗi PHP (Warning, Notice, Deprecated) thành `ErrorException` để xử lý đồng nhất.
 * - Phân biệt rõ hành vi giữa hai môi trường:
 *   + Môi trường `development` (`APP_DEBUG=true`): Hiển thị thông báo lỗi rõ ràng hỗ trợ sinh viên/lập trình viên gỡ lỗi
 *     (nhưng vẫn lọc bỏ thông tin nhạy cảm).
 *   + Môi trường `production` (`APP_DEBUG=false`): Ghi nhật ký lỗi và hiển thị trang thông báo lỗi 500 thân thiện,
 *     tuyệt đối không lộ đường dẫn tệp máy chủ, câu lệnh SQL hay Stack Trace ra trình duyệt.
 */

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

class ErrorHandler
{
    /**
     * Cờ đánh dấu chế độ gỡ lỗi (true = development, false = production).
     */
    private static bool $debug = false;

    /**
     * Đăng ký các hàm bắt lỗi và bắt ngoại lệ toàn cục của PHP.
     *
     * @param bool $debug Trạng thái bật/tắt chế độ debug từ `config/app.php`.
     * @return void
     */
    public static function register(bool $debug = false): void
    {
        self::$debug = $debug;
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
    }

    /**
     * Chuyển lỗi PHP thông thường thành ngoại lệ `ErrorException`.
     *
     * @param int    $severity Mức độ lỗi PHP.
     * @param string $message  Thông điệp lỗi.
     * @param string $file     Tệp phát sinh lỗi.
     * @param int    $line     Dòng phát sinh lỗi.
     * @return bool
     * @throws ErrorException
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // Không ném ngoại lệ dừng chương trình đối với cảnh báo ngừng hỗ trợ (Deprecation) của PHP,
        // mà chỉ ghi vào error_log nội bộ để đảm bảo luồng nghiệp vụ vẫn vận hành ổn định.
        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            error_log(sprintf('[TL Gaming Deprecated] %s in %s:%d', $message, $file, $line));
            return true;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Xử lý ngoại lệ chưa được bắt (Uncaught Exception) và hiển thị giao diện lỗi phù hợp.
     *
     * @param Throwable $exception Ngoại lệ phát sinh trong quá trình xử lý Request.
     * @return void
     */
    public static function handleException(Throwable $exception): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(500);
        }

        // Ghi lỗi vào error_log của PHP để quản trị viên tra cứu
        error_log(sprintf(
            '[TL Gaming Error] %s: %s in %s:%d',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "[LỖI HỆ THỐNG] " . $exception->getMessage() . PHP_EOL);
            return;
        }

        try {
            View::render('errors/500', [
                'pageTitle' => '500 - Lỗi hệ thống | TL Gaming',
                'debug'     => self::$debug,
                'message'   => self::$debug
                    ? $exception->getMessage()
                    : 'Hệ thống đang tạm thời gián đoạn để bảo trì kỹ thuật. Quý khách vui lòng thử lại sau ít phút.',
                'file'      => self::$debug ? basename($exception->getFile()) : null,
                'line'      => self::$debug ? $exception->getLine() : null,
            ]);
        } catch (Throwable) {
            echo '<h1>500 - Lỗi hệ thống</h1><p>Đã xảy ra sự cố kỹ thuật. Vui lòng thử lại sau.</p>';
        }
    }
}
