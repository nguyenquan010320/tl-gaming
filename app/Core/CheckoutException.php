<?php
/**
 * Ngoại lệ nghiệp vụ Thanh toán (CheckoutException) — TL Gaming.
 *
 * Vai trò:
 * - Phân tách rõ ràng giữa lỗi nghiệp vụ an toàn để hiển thị cho người dùng
 *   (ví dụ: hết hàng, voucher hết hạn, token idempotency không hợp lệ, thay đổi giá)
 *   với lỗi kỹ thuật/CSDL (`PDOException`, `Error`, lỗi hệ thống) tuyệt đối không được lộ ra giao diện.
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class CheckoutException extends RuntimeException
{
}
