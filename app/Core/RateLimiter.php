<?php
/**
 * Lớp RateLimiter — Giới hạn số lần thử (chống Brute-force và Denial of Service)
 * dựa trên cơ chế khóa tệp tin `flock()` nguyên tử (Atomic File Lock) trong `storage/ratelimit/`.
 *
 * Vai trò bảo mật cốt lõi:
 * 1. Chống Race Condition: Sử dụng `flock(LOCK_EX)` bao bọc TOÀN BỘ chu trình Đọc -> Sửa -> Ghi (Read-Modify-Write),
 *    không chỉ khóa ở bước ghi, ngăn chặn tình trạng nhiều request đồng thời ghi đè làm mất lượt đếm thất bại.
 * 2. Bảo mật danh tính: Tên tệp lưu trữ chỉ là mã băm SHA-256 (`hash('sha256', $key) . '.json'`),
 *    tuyệt đối không ghi email, mật khẩu hay chuỗi thô của người dùng lên hệ thống tệp.
 * 3. Chống nuốt lỗi (Fail-Closed / Không âm thầm bỏ qua lỗi): Nếu thư mục không tồn tại, ổ đĩa không có quyền ghi,
 *    hoặc tệp JSON bị lỗi cú pháp, hệ thống sẽ ghi log chi tiết và tự động chuyển sang cơ chế đếm trong bộ nhớ
 *    (in-memory static fallback) để tiếp tục tính lượt thất bại, không để kẻ tấn công lợi dụng lỗi đĩa để brute-force.
 * 4. Đọc an toàn: Thao tác đọc kiểm tra `tooManyAttempts()` sử dụng `flock(LOCK_SH)` (khóa chia sẻ) để đảm bảo
 *    không bao giờ đọc phải tệp đang trong quá trình `ftruncate`/ghi dở dang từ tiến trình khác.
 */

declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    /**
     * Mảng tĩnh lưu trữ bộ đếm trong bộ nhớ ứng dụng (Fallback) khi ổ đĩa gặp lỗi ghi hoặc không có quyền ghi,
     * đảm bảo lượt đăng nhập sai luôn được ghi nhận trong chu kỳ Request / CLI.
     *
     * @var array<string, array<int, int>>
     */
    private static array $memoryFallbackHits = [];

    /**
     * Lấy đường dẫn thư mục lưu trữ tệp khóa và tạo thư mục nếu chưa có.
     *
     * @return string|null Đường dẫn thư mục hoặc null nếu không thể tạo / không có quyền ghi.
     */
    private static function directory(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/storage/ratelimit';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                error_log('[RateLimiter] Lỗi: Không thể khởi tạo thư mục lưu trữ bộ đếm: ' . $dir);
                return null;
            }
        }

        if (!is_writable($dir)) {
            error_log('[RateLimiter] Cảnh báo: Thư mục không có quyền ghi: ' . $dir);
            return null;
        }

        return $dir;
    }

    /**
     * Tạo đường dẫn tệp băm an toàn theo mã SHA-256 từ khóa định danh.
     *
     * @param string $key Khóa định danh (ví dụ: IP + email).
     * @return string|null Đường dẫn tệp hoặc null nếu thư mục không khả dụng.
     */
    private static function filePath(string $key): ?string
    {
        $dir = self::directory();
        if ($dir === null) {
            return null;
        }
        return $dir . '/' . hash('sha256', $key) . '.json';
    }

    /**
     * Đọc danh sách các mốc thời gian thất bại còn hiệu lực với khóa chia sẻ LOCK_SH.
     *
     * @param string $key    Khóa định danh.
     * @param int    $window Cửa sổ thời gian tính bằng giây.
     * @return array<int, int> Danh sách timestamp các lần thất bại.
     */
    public static function getHits(string $key, int $window): array
    {
        $threshold = time() - $window;
        $fileHits  = [];
        $filePath  = self::filePath($key);

        if ($filePath !== null && is_file($filePath)) {
            $fp = @fopen($filePath, 'rb');
            if ($fp !== false) {
                if (flock($fp, LOCK_SH)) {
                    $rawContent = stream_get_contents($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);

                    if (is_string($rawContent) && trim($rawContent) !== '') {
                        $decoded = json_decode($rawContent, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $ts) {
                                if (is_int($ts) && $ts > $threshold) {
                                    $fileHits[] = $ts;
                                }
                            }
                        } else {
                            // Tệp JSON bị hỏng cú pháp: Ghi log cảnh báo và không coi là 0 lần thử
                            error_log('[RateLimiter] Cảnh báo: Tệp bộ đếm bị hỏng định dạng JSON: ' . $filePath);
                            // Coi như đã có 1 mốc thời gian thất bại gần nhất để không reset bộ đếm về 0
                            $fileHits[] = time();
                        }
                    }
                } else {
                    fclose($fp);
                    error_log('[RateLimiter] Không thể khóa đọc (LOCK_SH): ' . $filePath);
                }
            }
        }

        // Hợp nhất với bộ đếm dự phòng trong bộ nhớ và Session (khi ổ đĩa gặp lỗi ghi hoặc không khả dụng)
        $memHits = self::$memoryFallbackHits[$key] ?? [];
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['_rl_hits'][$key]) && is_array($_SESSION['_rl_hits'][$key])) {
            $memHits = array_merge($memHits, $_SESSION['_rl_hits'][$key]);
        }
        foreach ($memHits as $ts) {
            if ($ts > $threshold) {
                $fileHits[] = $ts;
            }
        }

        // Sắp xếp các mốc thời gian tăng dần (bảo toàn đầy đủ tất cả các lần thử ngay cả khi diễn ra trong cùng 1 giây)
        sort($fileHits, SORT_NUMERIC);

        return $fileHits;
    }

    /**
     * Lấy số lần thử thất bại hiện tại trong cửa sổ thời gian.
     *
     * @param string $key    Khóa định danh.
     * @param int    $window Cửa sổ thời gian tính bằng giây.
     * @return int Số lần thử.
     */
    public static function attempts(string $key, int $window): int
    {
        return count(self::getHits($key, $window));
    }

    /**
     * Kiểm tra xem khóa định danh đã vượt quá số lần thử tối đa trong khung thời gian hay chưa.
     *
     * @param string $key         Khóa định danh (IP hoặc IP + email).
     * @param int    $maxAttempts Số lần thất bại tối đa cho phép.
     * @param int    $window      Cửa sổ thời gian tính bằng giây.
     * @return bool True nếu đã vượt quá giới hạn (cần chặn), False nếu còn trong giới hạn.
     */
    public static function tooManyAttempts(string $key, int $maxAttempts, int $window): bool
    {
        return count(self::getHits($key, $window)) >= $maxAttempts;
    }

    /**
     * Ghi nhận một lần thất bại nguyên tử (Atomic Increment) sử dụng `flock(LOCK_EX)`.
     * Toàn bộ chu trình Mở -> Đọc -> Lọc cửa sổ trượt -> Thêm mốc mới -> Ghi đè được bảo vệ trong khóa độc quyền.
     *
     * @param string $key    Khóa định danh.
     * @param int    $window Cửa sổ thời gian tính bằng giây.
     * @return void
     */
    public static function hit(string $key, int $window): void
    {
        $now         = time();
        $threshold   = $now - $window;
        $filePath    = self::filePath($key);
        $savedToDisk = false;

        if ($filePath !== null) {
            // Mở tệp ở chế độ 'c+b': tạo nếu chưa có, không truncate khi mở, con trỏ ở đầu tệp
            $fp = @fopen($filePath, 'c+b');
            if ($fp !== false) {
                // Khóa ĐỘC QUYỀN trên toàn bộ quá trình đọc - sửa - ghi
                if (flock($fp, LOCK_EX)) {
                    $raw = stream_get_contents($fp);
                    $hits = [];

                    if (is_string($raw) && trim($raw) !== '') {
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $ts) {
                                if (is_int($ts) && $ts > $threshold) {
                                    $hits[] = $ts;
                                }
                            }
                        } else {
                            error_log('[RateLimiter] Tệp JSON hỏng khi ghi nhận hit, khởi tạo lại: ' . $filePath);
                            // Giữ lại 1 mốc thời gian trước đó thay vì xóa sạch
                            $hits[] = $now - 1;
                        }
                    }

                    // Bổ sung mốc thời gian thất bại hiện tại
                    $hits[] = $now;

                    // Cắt ngắn tệp về 0 và ghi lại mảng JSON mới
                    ftruncate($fp, 0);
                    rewind($fp);
                    $bytesWritten = fwrite($fp, (string) json_encode($hits, JSON_UNESCAPED_SLASHES));
                    fflush($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);

                    if ($bytesWritten !== false) {
                        $savedToDisk = true;
                    }
                } else {
                    fclose($fp);
                    error_log('[RateLimiter] Không thể thiết lập khóa LOCK_EX: ' . $filePath);
                }
            } else {
                error_log('[RateLimiter] Không thể mở tệp khóa để ghi: ' . $filePath);
            }
        }

        // Nếu ghi đĩa thất bại (không có quyền ghi, đầy ổ đĩa, lỗi fopen):
        // KHÔNG âm thầm bỏ qua! Lưu vào bộ nhớ tĩnh và Session fallback để tiếp tục đếm số lần thất bại,
        // tuyệt đối KHÔNG mặc định cho phép người dùng đăng nhập vô hạn khi kho lưu gặp sự cố.
        if (!$savedToDisk) {
            self::$memoryFallbackHits[$key][] = $now;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_rl_hits'][$key][] = $now;
            }
            error_log('[RateLimiter] Cảnh báo: Kho lưu tệp không khả dụng, đã chuyển lượt thất bại vào fallback (RAM + Session) cho khóa: ' . hash('sha256', $key));
        }
    }

    /**
     * Kiểm tra xem kho lưu trữ tệp trên đĩa cứng có sẵn sàng hoạt động hay không.
     *
     * @return bool True nếu thư mục tồn tại và có quyền ghi, False nếu gặp sự cố.
     */
    public static function isStorageAvailable(): bool
    {
        return self::directory() !== null;
    }

    /**
     * Tính toán số giây còn lại người dùng phải chờ trước khi được phép thử lại.
     *
     * @param string $key    Khóa định danh.
     * @param int    $window Cửa sổ thời gian tính bằng giây.
     * @return int Số giây còn phải chờ (tối thiểu 1 giây nếu đang bị khóa, 0 nếu không bị khóa).
     */
    public static function availableIn(string $key, int $window): int
    {
        $hits = self::getHits($key, $window);
        if ($hits === []) {
            return 0;
        }

        $oldest = min($hits);
        $remaining = ($oldest + $window) - time();
        return max(1, $remaining);
    }

    /**
     * Xóa sạch bộ đếm của một khóa định danh (được gọi sau khi người dùng đăng nhập thành công).
     *
     * @param string $key Khóa định danh.
     * @return void
     */
    public static function clear(string $key): void
    {
        unset(self::$memoryFallbackHits[$key]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['_rl_hits'][$key]);
        }
        $filePath = self::filePath($key);

        if ($filePath !== null && is_file($filePath)) {
            $fp = @fopen($filePath, 'c+b');
            if ($fp !== false) {
                if (flock($fp, LOCK_EX)) {
                    ftruncate($fp, 0);
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
            }
            @unlink($filePath);
        }
    }
}
