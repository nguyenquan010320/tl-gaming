<?php
/**
 * Model quản lý Giỏ hàng (`carts` và `cart_items`) trong cơ sở dữ liệu `tl_gaming`.
 *
 * Vai trò trong kiến trúc MVC:
 * - Quản lý giỏ hàng bền vững (Persistent Cart) cho thành viên đã đăng nhập (`Auth::check() = true`).
 * - Cung cấp cơ chế hợp nhất (Merge) giỏ hàng của khách vãng lai (Session Cart) vào giỏ hàng CSDL khi đăng nhập.
 * - Đảm bảo phân lập người dùng (Chống IDOR): Mọi thao tác truy vấn, thêm, sửa, xóa giỏ hàng BẮT BUỘC
 *   gắn liền với `user_id` đã được xác thực qua `Auth::id()`; tuyệt đối không cho phép client gửi `cart_id` tùy tiện.
 * - Tuân thủ ràng buộc toàn vẹn CSDL: Khóa ngoại `fk_cart_items_cart`, `fk_cart_items_product`,
 *   chỉ mục duy nhất `uq_cart_items_product (cart_id, product_id)` và ràng buộc `quantity > 0`.
 * - Tự động đối chiếu tồn kho khả dụng từ `ProductModel` (bao gồm `status = 'available'` của `product_units`
 *   đối với máy có Serial) và tự động điều chỉnh số lượng nếu vượt quá tồn kho thực tế.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Model;
use PDO;
use Throwable;

class CartModel extends Model
{
    /**
     * Bảng giỏ hàng chính.
     */
    protected string $table = 'carts';

    /**
     * Thực thi một câu lệnh INSERT, UPDATE hoặc DELETE và trả về true nếu thành công.
     *
     * @param string $sql Câu lệnh SQL.
     * @param array<int|string, mixed> $params Mảng tham số.
     * @return bool True nếu câu lệnh thực thi không lỗi.
     */
    protected function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount() >= 0;
    }

    /**
     * Lấy ID giỏ hàng của thành viên trong bảng `carts`, hoặc tự động tạo mới nếu chưa có.
     *
     * @param int $userId Khóa chính người dùng từ Auth::id().
     * @return int        Khóa chính `carts.id`.
     */
    public function getOrCreateCartId(int $userId): int
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('ID người dùng không hợp lệ để tạo giỏ hàng.');
        }

        // 1. Tìm giỏ hàng hiện có của user
        $sql = "SELECT id FROM {$this->table} WHERE user_id = :user_id LIMIT 1";
        $cartId = $this->fetchColumn($sql, [':user_id' => $userId]);

        if ($cartId !== false && $cartId !== null) {
            return (int) $cartId;
        }

        // 2. Nếu chưa có, tạo mới bản ghi trong bảng `carts`
        $insertSql = "
            INSERT INTO {$this->table} (user_id, created_at, updated_at)
            VALUES (:user_id, NOW(), NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ";
        $this->execute($insertSql, [':user_id' => $userId]);

        // Lấy lại ID sau khi insert/update
        $cartId = $this->fetchColumn($sql, [':user_id' => $userId]);
        return (int) $cartId;
    }

    /**
     * Lấy danh sách các dòng sản phẩm thô trong giỏ CSDL của một tài khoản (`user_id`).
     *
     * @param int $userId Khóa chính người dùng.
     * @return array<int, array{id: int, cart_id: int, product_id: int, quantity: int}>
     */
    public function getRawItemsByUserId(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $cartId = $this->getOrCreateCartId($userId);
        $sql = "
            SELECT id, cart_id, product_id, quantity
            FROM cart_items
            WHERE cart_id = :cart_id
            ORDER BY id ASC
        ";

        return $this->fetchAll($sql, [':cart_id' => $cartId]);
    }

    /**
     * Thêm sản phẩm vào giỏ hàng CSDL của tài khoản hoặc tăng số lượng nếu đã có.
     * Sử dụng Transaction và khóa dòng (`FOR UPDATE`) để tránh lỗi xung đột khi gửi nhiều request đồng thời.
     * Tự động chặn nếu tổng số lượng vượt quá tồn kho khả dụng.
     *
     * @param int $userId    ID tài khoản người dùng.
     * @param int $productId ID sản phẩm muốn thêm.
     * @param int $quantity  Số lượng muốn thêm (> 0).
     * @param int $maxStock  Tồn kho khả dụng tối đa được phép mua.
     * @return array{success: bool, quantity: int, message: string}
     */
    public function addItem(int $userId, int $productId, int $quantity, int $maxStock): array
    {
        if ($userId <= 0 || $productId <= 0 || $quantity <= 0) {
            return ['success' => false, 'quantity' => 0, 'message' => 'Dữ liệu sản phẩm không hợp lệ.'];
        }

        if ($maxStock <= 0) {
            return ['success' => false, 'quantity' => 0, 'message' => 'Sản phẩm hiện đã hết hàng.'];
        }

        $cartId = $this->getOrCreateCartId($userId);
        $ownTransaction = !$this->db->inTransaction();

        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // Khóa dòng giỏ hàng cha (`carts`) để tuần tự hóa các request đồng thời của cùng 1 user, tránh deadlock gap-lock
            $this->fetchOne("SELECT id FROM {$this->table} WHERE id = :cart_id FOR UPDATE", [':cart_id' => $cartId]);

            // Khóa dòng sản phẩm trong giỏ hàng (nếu đã tồn tại) để xử lý đồng thời an toàn
            $checkSql = "
                SELECT id, quantity
                FROM cart_items
                WHERE cart_id = :cart_id AND product_id = :product_id
                LIMIT 1
                FOR UPDATE
            ";
            $existing = $this->fetchOne($checkSql, [
                ':cart_id'    => $cartId,
                ':product_id' => $productId,
            ]);

            $currentQty = $existing !== null ? (int) $existing['quantity'] : 0;
            $targetQty  = $currentQty + $quantity;

            // Chặn nếu đã đạt tối đa tồn kho
            if ($currentQty >= $maxStock) {
                if ($ownTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return [
                    'success'  => false,
                    'quantity' => $currentQty,
                    'message'  => "Sản phẩm trong giỏ đã đạt mức tối đa theo tồn kho khả dụng ({$maxStock}).",
                ];
            }

            // Giới hạn theo tồn kho nếu vượt quá
            $finalQty = min($targetQty, $maxStock);

            if ($existing !== null) {
                $updateSql = "
                    UPDATE cart_items
                    SET quantity = :quantity, updated_at = NOW()
                    WHERE id = :id
                ";
                $this->execute($updateSql, [
                    ':quantity' => $finalQty,
                    ':id'       => (int) $existing['id'],
                ]);
            } else {
                $insertSql = "
                    INSERT INTO cart_items (cart_id, product_id, quantity, created_at, updated_at)
                    VALUES (:cart_id, :product_id, :quantity, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        quantity = LEAST(quantity + VALUES(quantity), :max_stock),
                        updated_at = NOW()
                ";
                $this->execute($insertSql, [
                    ':cart_id'    => $cartId,
                    ':product_id' => $productId,
                    ':quantity'   => $finalQty,
                    ':max_stock'  => $maxStock,
                ]);
            }

            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            $msg = $finalQty < $targetQty
                ? "Đã thêm vào giỏ (đạt mức tồn kho khả dụng tối đa: {$maxStock})."
                : "Đã thêm sản phẩm vào giỏ hàng thành công.";

            return ['success' => true, 'quantity' => $finalQty, 'message' => $msg];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cập nhật số lượng của một sản phẩm đang có trong giỏ hàng CSDL.
     * Bắt buộc kiểm tra sản phẩm đã có trong giỏ, chặn số lượng <= 0 hoặc vượt quá tồn kho khả dụng.
     * Sử dụng Transaction và khóa dòng (`FOR UPDATE`) để tránh race condition.
     *
     * @param int $userId    ID tài khoản.
     * @param int $productId ID sản phẩm.
     * @param int $quantity  Số lượng mới (> 0).
     * @param int $maxStock  Tồn kho khả dụng tối đa.
     * @return array{success: bool, quantity: int, message: string}
     */
    public function updateQuantity(int $userId, int $productId, int $quantity, int $maxStock): array
    {
        if ($userId <= 0 || $productId <= 0) {
            return ['success' => false, 'quantity' => 0, 'message' => 'Dữ liệu không hợp lệ.'];
        }

        // Chặn số lượng <= 0 theo đúng nghiệp vụ (không âm thầm xóa khi gọi API cập nhật)
        if ($quantity <= 0) {
            return ['success' => false, 'quantity' => 0, 'message' => 'Số lượng sản phẩm phải lớn hơn 0.'];
        }

        if ($maxStock <= 0) {
            return ['success' => false, 'quantity' => 0, 'message' => 'Sản phẩm hiện đã hết hàng, không thể cập nhật số lượng.'];
        }

        $cartId = $this->getOrCreateCartId($userId);
        $ownTransaction = !$this->db->inTransaction();

        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // Khóa dòng giỏ hàng cha (`carts`) để tuần tự hóa các request đồng thời của cùng 1 user
            $this->fetchOne("SELECT id FROM {$this->table} WHERE id = :cart_id FOR UPDATE", [':cart_id' => $cartId]);

            // Kiểm tra sản phẩm thực sự tồn tại trong giỏ trước khi cập nhật
            $checkSql = "
                SELECT id, quantity
                FROM cart_items
                WHERE cart_id = :cart_id AND product_id = :product_id
                LIMIT 1
                FOR UPDATE
            ";
            $existing = $this->fetchOne($checkSql, [
                ':cart_id'    => $cartId,
                ':product_id' => $productId,
            ]);

            if ($existing === null) {
                if ($ownTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return [
                    'success'  => false,
                    'quantity' => 0,
                    'message'  => 'Sản phẩm không có trong giỏ hàng của bạn.',
                ];
            }

            $currentQty = (int) $existing['quantity'];

            // Chặn vượt quá tồn kho khả dụng từ phía server
            if ($quantity > $maxStock) {
                if ($ownTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return [
                    'success'  => false,
                    'quantity' => $currentQty,
                    'message'  => "Số lượng yêu cầu ({$quantity}) vượt quá tồn kho khả dụng ({$maxStock}).",
                ];
            }

            $updateSql = "
                UPDATE cart_items
                SET quantity = :quantity, updated_at = NOW()
                WHERE id = :id
            ";
            $this->execute($updateSql, [
                ':quantity' => $quantity,
                ':id'       => (int) $existing['id'],
            ]);

            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return ['success' => true, 'quantity' => $quantity, 'message' => 'Đã cập nhật số lượng thành công.'];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Xóa một sản phẩm khỏi giỏ hàng CSDL của tài khoản.
     *
     * @param int $userId    ID tài khoản.
     * @param int $productId ID sản phẩm cần xóa.
     * @return bool True nếu xóa thành công.
     */
    public function removeItem(int $userId, int $productId): bool
    {
        if ($userId <= 0 || $productId <= 0) {
            return false;
        }

        $cartId = $this->getOrCreateCartId($userId);
        $deleteSql = "
            DELETE FROM cart_items
            WHERE cart_id = :cart_id AND product_id = :product_id
        ";
        return $this->execute($deleteSql, [
            ':cart_id'    => $cartId,
            ':product_id' => $productId,
        ]);
    }

    /**
     * Xóa toàn bộ sản phẩm trong giỏ hàng CSDL của tài khoản.
     *
     * @param int $userId ID tài khoản.
     * @return bool True nếu làm trống giỏ thành công.
     */
    public function clearCart(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $cartId = $this->getOrCreateCartId($userId);
        $deleteSql = "DELETE FROM cart_items WHERE cart_id = :cart_id";
        return $this->execute($deleteSql, [':cart_id' => $cartId]);
    }

    /**
     * Hợp nhất giỏ hàng Session của khách vãng lai vào giỏ hàng CSDL của tài khoản khi đăng nhập.
     *
     * Quy tắc nghiệp vụ & Bảo mật:
     * 1. Thực thi trong PDO Transaction (`beginTransaction`, `commit`, `rollBack`) để đảm bảo tính nguyên tử.
     * 2. Sản phẩm trùng nhau: cộng dồn số lượng nhưng khống chế không vượt quá tồn kho khả dụng hiện tại.
     * 3. Sản phẩm đã bị ẩn (`status != 'published'`) hoặc hết hàng (`purchasable_quantity <= 0`) sẽ tự động bỏ qua.
     * 4. Không hợp nhất lại nhiều lần khi F5 làm mới trang (được kích hoạt chính xác tại thời điểm đăng nhập thành công).
     *
     * @param int                  $userId     ID tài khoản người dùng vừa đăng nhập.
     * @param array<int|string, mixed> $guestItems Mảng giỏ hàng Session dạng [product_id => quantity] hoặc tương đương.
     * @return array{merged_count: int, adjusted_count: int, skipped_count: int}
     */
    public function mergeGuestCartIntoUserCart(int $userId, array $guestItems): array
    {
        if ($userId <= 0 || $guestItems === []) {
            return ['merged_count' => 0, 'adjusted_count' => 0, 'skipped_count' => 0];
        }

        // Chuẩn hóa mảng [product_id => quantity]
        $cleanGuestItems = [];
        foreach ($guestItems as $k => $v) {
            if (is_array($v) && isset($v['product_id'], $v['quantity'])) {
                $pId = (int) $v['product_id'];
                $qty = (int) $v['quantity'];
            } else {
                $pId = (int) $k;
                $qty = (int) $v;
            }
            if ($pId > 0 && $qty > 0) {
                $cleanGuestItems[$pId] = ($cleanGuestItems[$pId] ?? 0) + $qty;
            }
        }

        if ($cleanGuestItems === []) {
            return ['merged_count' => 0, 'adjusted_count' => 0, 'skipped_count' => 0];
        }

        $productModel = new ProductModel();
        $productsMap  = $productModel->getPublishedByIds(array_keys($cleanGuestItems));

        $mergedCount   = 0;
        $adjustedCount = 0;
        $skippedCount  = 0;

        $db = $this->db;
        $db->beginTransaction();

        try {
            $cartId = $this->getOrCreateCartId($userId);

            // Đọc giỏ hiện tại trong CSDL và khóa các dòng để tránh xung đột
            $existingRows = $this->fetchAll("
                SELECT product_id, quantity
                FROM cart_items
                WHERE cart_id = :cart_id
                FOR UPDATE
            ", [':cart_id' => $cartId]);

            $existingMap = [];
            foreach ($existingRows as $row) {
                $existingMap[(int) $row['product_id']] = (int) $row['quantity'];
            }

            foreach ($cleanGuestItems as $productId => $guestQty) {
                $product = $productsMap[$productId] ?? null;

                // 1. Kiểm tra sản phẩm hợp lệ, còn mở bán và còn tồn kho
                if ($product === null || empty($product['is_in_stock'])) {
                    $skippedCount++;
                    continue;
                }

                $maxStock = (int) ($product['purchasable_quantity'] ?? 0);
                if ($maxStock <= 0) {
                    $skippedCount++;
                    continue;
                }

                $currentDbQty = $existingMap[$productId] ?? 0;
                $targetQty    = $currentDbQty + $guestQty;
                $finalQty     = min($targetQty, $maxStock);

                if ($finalQty < $targetQty) {
                    $adjustedCount++;
                }

                if (isset($existingMap[$productId])) {
                    // Cập nhật dòng đã có
                    $stmt = $db->prepare("
                        UPDATE cart_items
                        SET quantity = :quantity, updated_at = NOW()
                        WHERE cart_id = :cart_id AND product_id = :product_id
                    ");
                    $stmt->execute([
                        ':quantity'   => $finalQty,
                        ':cart_id'    => $cartId,
                        ':product_id' => $productId,
                    ]);
                } else {
                    // Thêm dòng mới
                    $stmt = $db->prepare("
                        INSERT INTO cart_items (cart_id, product_id, quantity, created_at, updated_at)
                        VALUES (:cart_id, :product_id, :quantity, NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':cart_id'    => $cartId,
                        ':product_id' => $productId,
                        ':quantity'   => $finalQty,
                    ]);
                }

                $mergedCount++;
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[CartModel] Lỗi hợp nhất giỏ hàng: ' . $e->getMessage());
            throw $e;
        }

        return [
            'merged_count'   => $mergedCount,
            'adjusted_count' => $adjustedCount,
            'skipped_count'  => $skippedCount,
        ];
    }

    /**
     * Tự động đồng bộ lại giỏ hàng tạm (`pending_guest_cart`) vào MySQL nếu trước đó
     * quá trình hợp nhất khi đăng nhập bị gián đoạn.
     * Chỉ xóa giỏ tạm khi Transaction hợp nhất thành công để đảm bảo không mất dữ liệu
     * và không cộng trùng số lượng.
     *
     * @param int $userId ID tài khoản đang đăng nhập.
     * @return array{synced: bool, attempted: bool, merged_count: int, adjusted_count: int, skipped_count: int}
     */
    public function syncPendingGuestCartIfNeeded(int $userId): array
    {
        if ($userId <= 0) {
            return ['synced' => false, 'attempted' => false, 'merged_count' => 0, 'adjusted_count' => 0, 'skipped_count' => 0];
        }

        $pendingCart = Auth::getPendingGuestCart($userId);
        if ($pendingCart === []) {
            return ['synced' => false, 'attempted' => false, 'merged_count' => 0, 'adjusted_count' => 0, 'skipped_count' => 0];
        }

        try {
            $mergeResult = $this->mergeGuestCartIntoUserCart($userId, $pendingCart);
            // Chỉ xóa giỏ tạm sau khi Transaction hợp nhất vào MySQL đã commit thành công
            Auth::clearPendingGuestCart();

            return [
                'synced'         => true,
                'attempted'      => true,
                'merged_count'   => $mergeResult['merged_count'],
                'adjusted_count' => $mergeResult['adjusted_count'],
                'skipped_count'  => $mergeResult['skipped_count'],
            ];
        } catch (Throwable $e) {
            error_log('[CartModel] Đồng bộ lại giỏ tạm thất bại: ' . $e->getMessage());
            // Giữ nguyên pending_guest_cart trong Session để không làm mất sản phẩm của người dùng
            return [
                'synced'         => false,
                'attempted'      => true,
                'merged_count'   => 0,
                'adjusted_count' => 0,
                'skipped_count'  => 0,
            ];
        }
    }

    /**
     * Lấy toàn bộ thông tin chi tiết của giỏ hàng phục vụ hiển thị trên View Google Stitch.
     * Hoạt động đồng nhất cho cả Khách vãng lai (đọc từ Session) và Thành viên đã đăng nhập
     * (đọc từ CSDL MySQL, đồng thời gộp hiển thị các sản phẩm từ giỏ tạm `pending_guest_cart`
     * nếu quá trình đồng bộ khi đăng nhập bị lỗi trước đó).
     *
     * Đảm bảo tính toán độc lập 100% trên Server:
     * - Giá được nạp trực tiếp từ bảng `products` của MySQL.
     * - Khống chế số lượng theo tồn kho khả dụng thực tế.
     * - Tính toán tạm tính và tổng tiền bằng kiểu số nguyên (int VND).
     * - Không áp dụng bất kỳ giảm giá voucher giả lập nào.
     *
     * @param int|null                 $userId           ID người dùng nếu đã đăng nhập, null nếu là khách.
     * @param array<int|string, mixed> $sessionCart      Mảng giỏ hàng Session nếu là khách vãng lai.
     * @param array<int|string, mixed> $pendingGuestCart Mảng giỏ hàng tạm chưa đồng bộ của tài khoản (nếu có).
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     subtotal: int,
     *     discount: int,
     *     total: int,
     *     total_quantity: int,
     *     distinct_count: int,
     *     adjustments: array<int, string>,
     *     has_pending_sync: bool,
     *     is_empty: bool
     * }
     */
    public function getDetailedCart(?int $userId, array $sessionCart = [], array $pendingGuestCart = []): array
    {
        $rawItems       = [];
        $dbMap          = [];
        $pendingMap     = [];
        $hasPendingSync = false;

        if ($userId !== null && $userId > 0) {
            try {
                $dbItems = $this->getRawItemsByUserId($userId);
                foreach ($dbItems as $row) {
                    $pId = (int) $row['product_id'];
                    $qty = (int) $row['quantity'];
                    if ($pId > 0 && $qty > 0) {
                        $dbMap[$pId]    = $qty;
                        $rawItems[$pId] = $qty;
                    }
                }
            } catch (Throwable $e) {
                error_log('[CartModel] Lỗi đọc giỏ CSDL khi dựng chi tiết giỏ: ' . $e->getMessage());
            }

            // Gộp hiển thị giỏ tạm chưa đồng bộ (chỉ đọc để hiển thị, không tự xóa trước khi merge thành công)
            foreach ($pendingGuestCart as $k => $v) {
                if (is_array($v) && isset($v['product_id'], $v['quantity'])) {
                    $pId = (int) $v['product_id'];
                    $qty = (int) $v['quantity'];
                } else {
                    $pId = (int) $k;
                    $qty = (int) $v;
                }
                if ($pId > 0 && $qty > 0) {
                    $pendingMap[$pId] = ($pendingMap[$pId] ?? 0) + $qty;
                    $rawItems[$pId]   = ($rawItems[$pId] ?? 0) + $qty;
                    $hasPendingSync   = true;
                }
            }
        } else {
            foreach ($sessionCart as $k => $v) {
                if (is_array($v) && isset($v['product_id'], $v['quantity'])) {
                    $pId = (int) $v['product_id'];
                    $qty = (int) $v['quantity'];
                } else {
                    $pId = (int) $k;
                    $qty = (int) $v;
                }
                if ($pId > 0 && $qty > 0) {
                    $rawItems[$pId] = ($rawItems[$pId] ?? 0) + $qty;
                }
            }
        }

        if ($rawItems === []) {
            return [
                'items'            => [],
                'subtotal'         => 0,
                'discount'         => 0,
                'total'            => 0,
                'total_quantity'   => 0,
                'distinct_count'   => 0,
                'adjustments'      => [],
                'has_pending_sync' => false,
                'is_empty'         => true,
            ];
        }

        $productModel = new ProductModel();
        $productsMap  = $productModel->getPublishedByIds(array_keys($rawItems));

        $detailedItems = [];
        $subtotal      = 0;
        $totalQuantity = 0;
        $adjustments   = [];

        foreach ($rawItems as $productId => $qty) {
            $product = $productsMap[$productId] ?? null;

            // Nếu sản phẩm không còn tồn tại, chưa xuất bản hoặc danh mục bị ẩn -> tự động loại khỏi giỏ
            if ($product === null) {
                $adjustments[] = "Một sản phẩm trong giỏ đã ngừng kinh doanh và được xóa khỏi danh sách.";
                if ($userId !== null && $userId > 0) {
                    if (isset($dbMap[$productId])) {
                        $this->removeItem($userId, $productId);
                    }
                    if (isset($pendingMap[$productId])) {
                        unset($pendingMap[$productId]);
                        Auth::setPendingGuestCart($userId, $pendingMap);
                    }
                } else {
                    unset($_SESSION['cart'][$productId]);
                }
                continue;
            }

            $maxStock = (int) ($product['purchasable_quantity'] ?? 0);

            // Nếu sản phẩm hết hàng
            if ($maxStock <= 0) {
                $adjustments[] = "Sản phẩm \"{$product['name']}\" hiện đã hết hàng và được xóa khỏi giỏ hàng.";
                if ($userId !== null && $userId > 0) {
                    if (isset($dbMap[$productId])) {
                        $this->removeItem($userId, $productId);
                    }
                    if (isset($pendingMap[$productId])) {
                        unset($pendingMap[$productId]);
                        Auth::setPendingGuestCart($userId, $pendingMap);
                    }
                } else {
                    unset($_SESSION['cart'][$productId]);
                }
                continue;
            }

            // Nếu số lượng trong giỏ vượt quá tồn kho khả dụng hiện tại -> tự động điều chỉnh
            $finalQty = min($qty, $maxStock);
            if ($finalQty < $qty) {
                $adjustments[] = "Số lượng sản phẩm \"{$product['name']}\" được điều chỉnh về {$finalQty} theo tồn kho khả dụng.";
                if ($userId !== null && $userId > 0) {
                    if (isset($dbMap[$productId]) && !isset($pendingMap[$productId])) {
                        $this->updateQuantity($userId, $productId, $finalQty, $maxStock);
                    } elseif (isset($pendingMap[$productId])) {
                        $allowedPending = max(0, $finalQty - ($dbMap[$productId] ?? 0));
                        if ($allowedPending > 0) {
                            $pendingMap[$productId] = $allowedPending;
                        } else {
                            unset($pendingMap[$productId]);
                        }
                        Auth::setPendingGuestCart($userId, $pendingMap);
                    }
                } else {
                    $_SESSION['cart'][$productId] = $finalQty;
                }
            }

            $unitPrice    = (int) ($product['price'] ?? 0);
            $itemSubtotal = $unitPrice * $finalQty;

            $hasDiscount  = !empty($product['original_price']) && ((int) $product['original_price'] > $unitPrice);
            $origPrice    = $hasDiscount ? (int) $product['original_price'] : null;

            $detailedItems[] = [
                'id'                 => (int) $product['id'],
                'product_id'         => (int) $product['id'],
                'name'               => (string) $product['name'],
                'slug'               => (string) $product['slug'],
                'sku'                => (string) $product['sku'],
                'thumbnail'          => (string) ($product['thumbnail'] ?? ''),
                'price'              => $unitPrice,
                'original_price'     => $origPrice,
                'has_valid_discount' => $hasDiscount,
                'discount_percent'   => (int) ($product['discount_percent'] ?? 0),
                'condition_overview' => (string) ($product['condition_overview'] ?? 'Tuyển chọn 99%'),
                'warranty_months'    => (int) ($product['warranty_months'] ?? 12),
                'has_serials'        => ((int) ($product['has_serials'] ?? 1)) === 1,
                'has_verified_inspection' => !empty($product['verified_inspection_count']),
                'stock_quantity'     => $maxStock,
                'quantity'           => $finalQty,
                'item_subtotal'      => $itemSubtotal,
            ];

            $subtotal      += $itemSubtotal;
            $totalQuantity += $finalQty;
        }

        $discount = 0; // Tuyệt đối không giả lập voucher demo
        $total    = max(0, $subtotal - $discount);

        return [
            'items'            => $detailedItems,
            'subtotal'         => $subtotal,
            'discount'         => $discount,
            'total'            => $total,
            'total_quantity'   => $totalQuantity,
            'distinct_count'   => count($detailedItems),
            'adjustments'      => $adjustments,
            'has_pending_sync' => $hasPendingSync,
            'is_empty'         => $detailedItems === [],
        ];
    }

    /**
     * Lấy tổng số lượng sản phẩm hiện tại trong giỏ để cập nhật Badge Giỏ hàng trên Header.
     * Bao gồm cả sản phẩm trong giỏ tạm đang chờ đồng bộ (nếu có).
     *
     * @return int Tổng số lượng sản phẩm.
     */
    public static function getCurrentTotalQuantity(): int
    {
        if (Auth::check()) {
            $userId  = (int) Auth::id();
            $dbTotal = 0;

            try {
                $cartModel = new self();
                $cartId    = $cartModel->getOrCreateCartId($userId);
                $sum       = $cartModel->fetchColumn("
                    SELECT COALESCE(SUM(quantity), 0)
                    FROM cart_items
                    WHERE cart_id = :cart_id
                ", [':cart_id' => $cartId]);
                $dbTotal = (int) $sum;
            } catch (Throwable $e) {
                error_log('[CartModel] Lỗi đếm giỏ CSDL: ' . $e->getMessage());
            }

            $pendingTotal = 0;
            foreach (Auth::getPendingGuestCart($userId) as $v) {
                $qty = is_array($v) && isset($v['quantity']) ? (int) $v['quantity'] : (int) $v;
                if ($qty > 0) {
                    $pendingTotal += $qty;
                }
            }

            return $dbTotal + $pendingTotal;
        }

        $guestCart = $_SESSION['cart'] ?? [];
        if (!is_array($guestCart)) {
            return 0;
        }

        $total = 0;
        foreach ($guestCart as $v) {
            $qty = is_array($v) && isset($v['quantity']) ? (int) $v['quantity'] : (int) $v;
            if ($qty > 0) {
                $total += $qty;
            }
        }

        return $total;
    }
}
