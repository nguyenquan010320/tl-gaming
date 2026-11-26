<?php
/**
 * Model Quản lý Sản phẩm Admin (`app/Models/AdminProductModel.php`).
 *
 * Vai trò trong kiến trúc MVC:
 * - Cung cấp truy vấn danh sách, tìm kiếm, lọc, sắp xếp và phân trang phía server cho Admin (`/admin/san-pham`).
 * - Thực hiện Thêm/Sửa/Ẩn/Hiện sản phẩm, quản lý ảnh (`product_images`) và thông số kỹ thuật (`product_specs`)
 *   trong transaction PDO an toàn, bảo toàn dữ liệu lịch sử đơn hàng (`order_items`) và cá thể serial (`product_units`).
 * - Phân tách rạch ròi sản phẩm quản lý theo số lượng (`has_serials = 0`) và theo Serial (`has_serials = 1`).
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DomainException;
use PDO;
use Throwable;

class AdminProductModel extends Model
{
    /**
     * Danh sách trạng thái hợp lệ theo ENUM thực tế của bảng `products`.
     */
    public const ALLOWED_STATUSES = ['draft', 'published', 'out_of_stock', 'archived'];

    /**
     * Giới hạn tồn kho tối đa theo quy tắc nghiệp vụ (nằm gọn trong INT UNSIGNED).
     */
    public const MAX_STOCK_QUANTITY = 100000;

    /**
     * Giới hạn giá bán tối đa theo quy tắc nghiệp vụ (100 tỷ VND, an toàn cho BIGINT UNSIGNED & PHP int).
     */
    public const MAX_PRICE_VND = 100000000000;

    /**
     * Whitelist biểu thức ORDER BY an toàn cho danh sách sản phẩm Admin.
     *
     * @var array<string, string>
     */
    private const ALLOWED_SORT_MAP = [
        'moi-nhat' => 'p.id DESC',
        'cu-nhat'  => 'p.id ASC',
        'cap-nhat' => 'p.updated_at DESC, p.id DESC',
        'gia-tang' => 'p.price ASC, p.id DESC',
        'gia-giam' => 'p.price DESC, p.id DESC',
        'ten-az'   => 'p.name ASC, p.id DESC',
    ];

    protected string $table = 'products';

    /**
     * Chuẩn hóa khóa sắp xếp theo whitelist.
     */
    public static function normalizeSortKey(?string $rawSort): string
    {
        $clean = strtolower(trim((string) $rawSort));
        return isset(self::ALLOWED_SORT_MAP[$clean]) ? $clean : 'moi-nhat';
    }

    /**
     * Đếm tổng số sản phẩm phục vụ phân trang phía server cho Admin.
     *
     * @param array<string, mixed> $filters Bộ lọc (`keyword`, `category_ids`, `status`, `stock_type`).
     * @return int Tổng số bản ghi khớp điều kiện.
     */
    public function countAdminProducts(array $filters = []): int
    {
        [$whereClause, $bindings] = $this->buildAdminWhere($filters);

        $sql = "
            SELECT COUNT(*)
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            WHERE {$whereClause}
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $param => $meta) {
            $stmt->bindValue($param, $meta['val'], $meta['type']);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Lấy danh sách sản phẩm phân trang cho Admin kèm thông tin danh mục, ảnh, và số lượng khả dụng.
     *
     * @param array<string, mixed> $filters Bộ lọc tìm kiếm.
     * @param int                  $limit   Số dòng mỗi trang.
     * @param int                  $offset  Vị trí bắt đầu.
     * @return array<int, array<string, mixed>> Danh sách sản phẩm.
     */
    public function getAdminProducts(array $filters = [], int $limit = 15, int $offset = 0): array
    {
        $safeLimit  = max(1, min($limit, 50));
        $safeOffset = max(0, $offset);
        $sortKey    = self::normalizeSortKey($filters['sort'] ?? null);
        $orderBy    = self::ALLOWED_SORT_MAP[$sortKey];

        [$whereClause, $bindings] = $this->buildAdminWhere($filters);

        $sql = "
            SELECT p.id,
                   p.category_id,
                   p.name,
                   p.slug,
                   p.sku,
                   p.price,
                   p.original_price,
                   p.warranty_months,
                   p.condition_overview,
                   p.stock_quantity,
                   p.has_serials,
                   p.thumbnail,
                   p.status,
                   p.created_at,
                   p.updated_at,
                   c.name AS category_name,
                   c.is_active AS category_is_active,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu
                       WHERE pu.product_id = p.id
                         AND pu.status = 'available'
                         AND (
                             SELECT pi_sell.checklist_overall_result
                             FROM product_inspections pi_sell
                             WHERE pi_sell.unit_id = pu.id
                             ORDER BY pi_sell.inspection_date DESC, pi_sell.id DESC
                             LIMIT 1
                         ) = 'passed'
                         AND (
                             SELECT COUNT(DISTINCT ii_sell.step_number)
                             FROM inspection_items ii_sell
                             WHERE ii_sell.inspection_id = (
                                 SELECT pi_sell_id.id
                                 FROM product_inspections pi_sell_id
                                 WHERE pi_sell_id.unit_id = pu.id
                                 ORDER BY pi_sell_id.inspection_date DESC, pi_sell_id.id DESC
                                 LIMIT 1
                             )
                         ) = 18
                   ) AS available_serial_count,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu_all
                       WHERE pu_all.product_id = p.id
                   ) AS total_units_count
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            WHERE {$whereClause}
            ORDER BY {$orderBy}
            LIMIT :lim OFFSET :off
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $param => $meta) {
            $stmt->bindValue($param, $meta['val'], $meta['type']);
        }
        $stmt->bindValue(':lim', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Tìm thông tin chi tiết một sản phẩm theo ID cho Admin (bao gồm cả draft/archived và thống kê phụ thuộc).
     *
     * @param int $id ID sản phẩm.
     * @return array<string, mixed>|null Bản ghi sản phẩm hoặc null nếu không tồn tại.
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sql = "
            SELECT p.*,
                   c.name AS category_name,
                   c.is_active AS category_is_active,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu
                       WHERE pu.product_id = p.id
                         AND pu.status = 'available'
                         AND (
                             SELECT pi_sell.checklist_overall_result
                             FROM product_inspections pi_sell
                             WHERE pi_sell.unit_id = pu.id
                             ORDER BY pi_sell.inspection_date DESC, pi_sell.id DESC
                             LIMIT 1
                         ) = 'passed'
                         AND (
                             SELECT COUNT(DISTINCT ii_sell.step_number)
                             FROM inspection_items ii_sell
                             WHERE ii_sell.inspection_id = (
                                 SELECT pi_sell_id.id
                                 FROM product_inspections pi_sell_id
                                 WHERE pi_sell_id.unit_id = pu.id
                                 ORDER BY pi_sell_id.inspection_date DESC, pi_sell_id.id DESC
                                 LIMIT 1
                             )
                         ) = 18
                   ) AS available_serial_count,
                   (SELECT COUNT(*) FROM product_units pu2 WHERE pu2.product_id = p.id AND pu2.status = 'reserved') AS reserved_serial_count,
                   (SELECT COUNT(*) FROM product_units pu3 WHERE pu3.product_id = p.id AND pu3.status = 'sold') AS sold_serial_count,
                   (SELECT COUNT(*) FROM product_units pu4 WHERE pu4.product_id = p.id) AS total_units_count,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = p.id) AS order_items_count,
                   (SELECT COUNT(*) FROM cart_items ci WHERE ci.product_id = p.id) AS cart_items_count
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            WHERE p.id = :id
            LIMIT 1
        ";

        return $this->fetchOne($sql, [':id' => $id]);
    }

    /**
     * Kiểm tra mã SKU đã tồn tại hay chưa (tùy chọn loại trừ ID đang sửa).
     */
    public function skuExists(string $sku, ?int $excludeId = null): bool
    {
        $sql    = "SELECT 1 FROM {$this->table} WHERE sku = :sku";
        $params = [':sku' => $sku];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        return $this->fetchOne($sql . ' LIMIT 1', $params) !== null;
    }

    /**
     * Kiểm tra đường dẫn tĩnh Slug đã tồn tại hay chưa (tùy chọn loại trừ ID đang sửa).
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql    = "SELECT 1 FROM {$this->table} WHERE slug = :slug";
        $params = [':slug' => $slug];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        return $this->fetchOne($sql . ' LIMIT 1', $params) !== null;
    }

    /**
     * Lấy danh sách hình ảnh của một sản phẩm theo thứ tự ưu tiên hiển thị.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getImagesByProductId(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        return $this->fetchAll(
            "SELECT id, product_id, image_url, alt_text, sort_order, is_primary, created_at
             FROM product_images
             WHERE product_id = :pid
             ORDER BY is_primary DESC, sort_order ASC, id ASC",
            [':pid' => $productId]
        );
    }

    /**
     * Lấy toàn bộ thông số kỹ thuật động (`product_specs`) của sản phẩm.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSpecsByProductId(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        return $this->fetchAll(
            "SELECT id, product_id, group_name, spec_name, spec_value, sort_order
             FROM product_specs
             WHERE product_id = :pid
             ORDER BY sort_order ASC, id ASC",
            [':pid' => $productId]
        );
    }

    /**
     * Lấy danh sách ID thuộc toàn bộ nhánh con/cháu của một danh mục (dùng cho bộ lọc Admin).
     *
     * @return array<int, int>
     */
    public function getAdminCategoryBranchIds(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $rows = $this->fetchAll("SELECT id, parent_id FROM categories ORDER BY id ASC");
        $childrenByParent = [];
        $exists = false;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if ($id === $categoryId) {
                $exists = true;
            }
            if ($r['parent_id'] !== null) {
                $childrenByParent[(int) $r['parent_id']][] = $id;
            }
        }

        if (!$exists) {
            return [$categoryId];
        }

        $visited = [$categoryId => true];
        $queue   = [$categoryId];
        $result  = [$categoryId];
        while ($queue !== []) {
            $curr = array_shift($queue);
            foreach ($childrenByParent[$curr] ?? [] as $cid) {
                if (!isset($visited[$cid])) {
                    $visited[$cid] = true;
                    $result[]      = $cid;
                    $queue[]       = $cid;
                }
            }
        }

        return $result;
    }

    /**
     * Tạo mới sản phẩm kèm thông số kỹ thuật và bộ sưu tập ảnh trong một Transaction PDO duy nhất.
     *
     * Quy tắc nghiệp vụ:
     * - Kiểm tra danh mục tồn tại; nếu `status === 'published'`, bắt buộc danh mục và toàn bộ tổ tiên đang hoạt động.
     * - Nếu `has_serials = 1`, ép `stock_quantity = 0` (tồn kho thực tế lấy từ `product_units`, không tạo máy giả).
     * - Đồng bộ `products.thumbnail` theo ảnh đại diện (`is_primary = 1`).
     *
     * @param array<string, mixed>              $data           Dữ liệu sản phẩm đã validate.
     * @param array<int, array<string, mixed>>  $specs          Danh sách thông số kỹ thuật đã validate.
     * @param array<int, array<string, mixed>>  $uploadedImages Danh sách ảnh đã upload hợp lệ.
     * @return int ID sản phẩm vừa tạo.
     *
     * @throws DomainException Khi vi phạm ràng buộc nghiệp vụ (trùng SKU/slug, danh mục ẩn...).
     */
    public function createProduct(array $data, array $specs = [], array $uploadedImages = []): int
    {
        $this->db->beginTransaction();
        try {
            $categoryId = (int) $data['category_id'];
            $status     = (string) $data['status'];

            $this->assertValidCategoryForProduct($categoryId, $status === 'published');

            if ($this->skuExists((string) $data['sku'])) {
                throw new DomainException('Mã SKU đã tồn tại trong hệ thống. Vui lòng chọn SKU khác.');
            }
            if ($this->slugExists((string) $data['slug'])) {
                throw new DomainException('Đường dẫn tĩnh (Slug) đã tồn tại. Vui lòng chọn slug khác.');
            }

            $hasSerials = ((int) $data['has_serials']) === 1 ? 1 : 0;
            if ($hasSerials === 1) {
                $stockQuantity = 0;
            } else {
                if (!isset($data['stock_quantity']) || !is_int($data['stock_quantity']) || $data['stock_quantity'] < 0 || $data['stock_quantity'] > self::MAX_STOCK_QUANTITY) {
                    throw new DomainException('Số lượng tồn kho ban đầu không hợp lệ (phải từ 0 đến ' . self::MAX_STOCK_QUANTITY . ').');
                }
                $stockQuantity = $data['stock_quantity'];
            }

            $stmt = $this->db->prepare("
                INSERT INTO {$this->table} (
                    category_id, name, slug, sku, summary, description,
                    price, original_price, warranty_months, condition_overview,
                    stock_quantity, has_serials, thumbnail, status, created_at, updated_at
                ) VALUES (
                    :category_id, :name, :slug, :sku, :summary, :description,
                    :price, :original_price, :warranty_months, :condition_overview,
                    :stock_quantity, :has_serials, :thumbnail, :status, NOW(), NOW()
                )
            ");
            $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(':name', (string) $data['name'], PDO::PARAM_STR);
            $stmt->bindValue(':slug', (string) $data['slug'], PDO::PARAM_STR);
            $stmt->bindValue(':sku', (string) $data['sku'], PDO::PARAM_STR);
            $stmt->bindValue(':summary', $data['summary'] !== '' ? (string) $data['summary'] : null, $data['summary'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':description', $data['description'] !== '' ? (string) $data['description'] : null, $data['description'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':price', (int) $data['price'], PDO::PARAM_INT);
            if ($data['original_price'] !== null) {
                $stmt->bindValue(':original_price', (int) $data['original_price'], PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':original_price', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':warranty_months', (int) $data['warranty_months'], PDO::PARAM_INT);
            $stmt->bindValue(':condition_overview', (string) $data['condition_overview'], PDO::PARAM_STR);
            $stmt->bindValue(':stock_quantity', $stockQuantity, PDO::PARAM_INT);
            $stmt->bindValue(':has_serials', $hasSerials, PDO::PARAM_INT);
            $stmt->bindValue(':thumbnail', null, PDO::PARAM_NULL);
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->execute();

            $productId = (int) $this->db->lastInsertId();

            if ($specs !== []) {
                $this->replaceSpecsInternal($productId, $specs);
            }

            if ($uploadedImages !== []) {
                $this->insertImagesInternal($productId, $uploadedImages, true);
                $this->syncPrimaryThumbnailInternal($productId);
            }

            $this->db->commit();
            return $productId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cập nhật thông tin sản phẩm, thông số kỹ thuật và ảnh trong một Transaction PDO (`FOR UPDATE`).
     *
     * Bảo vệ tính toàn vẹn nghiệp vụ:
     * - Không làm thay đổi bất kỳ bản ghi `order_items` lịch sử nào (đã có snapshot).
     * - Không thay đổi trạng thái `reserved`/`sold` của `product_units`.
     * - Chặn đổi `has_serials` nếu sản phẩm đã có `product_units`, `order_items` hoặc `cart_items`.
     * - Đối với `has_serials = 0`: hỗ trợ điều chỉnh tồn kho nguyên tử (`adjust`: `stock_quantity + delta`)
     *   hoặc kiểm tra khóa lạc quan (`set`: đối chiếu `expected_stock_quantity`) để không bị mất cập nhật khi Checkout đồng thời.
     *
     * @param int                              $id              ID sản phẩm cần cập nhật.
     * @param array<string, mixed>             $data            Dữ liệu sản phẩm đã validate.
     * @param array<int, array<string, mixed>> $specs           Danh sách thông số kỹ thuật mới.
     * @param array<int, array<string, mixed>> $newImages       Ảnh mới upload thêm (nếu có).
     * @param int|null                         $primaryImageId  ID ảnh được chọn làm đại diện (nếu có).
     * @param array<int, int>                  $imageSortOrders Mảng `[image_id => sort_order]` (nếu có).
     * @return void
     *
     * @throws DomainException Khi vi phạm ràng buộc nghiệp vụ hoặc xung đột tồn kho đồng thời.
     */
    public function updateProduct(
        int $id,
        array $data,
        array $specs,
        array $newImages = [],
        ?int $primaryImageId = null,
        array $imageSortOrders = []
    ): void {
        $this->db->beginTransaction();
        try {
            $locked = $this->fetchOne(
                "SELECT id, category_id, stock_quantity, has_serials, status
                 FROM {$this->table}
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE",
                [':id' => $id]
            );

            if ($locked === null) {
                throw new DomainException('Không tìm thấy sản phẩm cần cập nhật.');
            }

            $categoryId = (int) $data['category_id'];
            $status     = (string) $data['status'];
            $this->assertValidCategoryForProduct($categoryId, $status === 'published');

            if ($this->skuExists((string) $data['sku'], $id)) {
                throw new DomainException('Mã SKU đã tồn tại ở một sản phẩm khác.');
            }
            if ($this->slugExists((string) $data['slug'], $id)) {
                throw new DomainException('Đường dẫn tĩnh (Slug) đã tồn tại ở một sản phẩm khác.');
            }

            $oldHasSerials = ((int) $locked['has_serials']) === 1 ? 1 : 0;
            $newHasSerials = ((int) $data['has_serials']) === 1 ? 1 : 0;

            if ($oldHasSerials !== $newHasSerials) {
                $deps = $this->getStockTypeDependenciesInternal($id);
                if ($deps['units_count'] > 0 || $deps['order_items_count'] > 0 || $deps['cart_items_count'] > 0) {
                    throw new DomainException(
                        'Không thể thay đổi loại quản lý tồn kho vì sản phẩm đã có dữ liệu máy Serial, đơn hàng hoặc giỏ hàng liên quan.'
                    );
                }
            }

            // Tính toán tồn kho an toàn chống mất cập nhật khi Checkout diễn ra đồng thời
            $currentStock = (int) $locked['stock_quantity'];
            if ($newHasSerials === 1) {
                // Sản phẩm quản lý theo Serial: giữ nguyên stock_quantity = 0 khi chuyển mới hoặc không cho nhập số lượng giả
                $finalStockQuantity = $oldHasSerials === 0 ? 0 : $currentStock;
            } else {
                $stockMode = $data['stock_mode'] ?? null;
                if ($stockMode !== 'adjust' && $stockMode !== 'set') {
                    throw new DomainException('Chế độ cập nhật tồn kho không hợp lệ (chỉ chấp nhận adjust hoặc set).');
                }

                if ($stockMode === 'adjust') {
                    if (!isset($data['stock_adjustment']) || !is_int($data['stock_adjustment'])) {
                        throw new DomainException('Giá trị điều chỉnh tồn kho phải là số nguyên hợp lệ.');
                    }
                    $delta              = $data['stock_adjustment'];
                    $finalStockQuantity = $currentStock + $delta;
                    if ($finalStockQuantity < 0) {
                        throw new DomainException("Số lượng tồn kho hiện tại ({$currentStock}) không đủ để giảm thêm {$delta}.");
                    }
                    if ($finalStockQuantity > self::MAX_STOCK_QUANTITY) {
                        throw new DomainException('Số lượng tồn kho sau điều chỉnh vượt quá giới hạn cho phép (' . self::MAX_STOCK_QUANTITY . ').');
                    }
                } else {
                    // Chế độ set bắt buộc có expected_stock_quantity hợp lệ để thực thi Optimistic Locking
                    if (
                        !array_key_exists('expected_stock_quantity', $data)
                        || !is_int($data['expected_stock_quantity'])
                        || $data['expected_stock_quantity'] < 0
                        || $data['expected_stock_quantity'] > self::MAX_STOCK_QUANTITY
                    ) {
                        throw new DomainException('Thiếu hoặc sai số lượng tồn kho đối chiếu (expected_stock_quantity) khi cập nhật trực tiếp.');
                    }

                    $expectedStock = $data['expected_stock_quantity'];
                    if ($currentStock !== $expectedStock) {
                        throw new DomainException(
                            "Tồn kho hiện tại đã thay đổi từ {$expectedStock} thành {$currentStock} do có giao dịch đồng thời. Vui lòng tải lại trang trước khi cập nhật."
                        );
                    }

                    if (
                        !array_key_exists('stock_quantity', $data)
                        || !is_int($data['stock_quantity'])
                        || $data['stock_quantity'] < 0
                        || $data['stock_quantity'] > self::MAX_STOCK_QUANTITY
                    ) {
                        throw new DomainException('Số lượng tồn kho mới không hợp lệ (phải từ 0 đến ' . self::MAX_STOCK_QUANTITY . ').');
                    }
                    $finalStockQuantity = $data['stock_quantity'];
                }
            }

            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET category_id = :category_id,
                    name = :name,
                    slug = :slug,
                    sku = :sku,
                    summary = :summary,
                    description = :description,
                    price = :price,
                    original_price = :original_price,
                    warranty_months = :warranty_months,
                    condition_overview = :condition_overview,
                    stock_quantity = :stock_quantity,
                    has_serials = :has_serials,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(':name', (string) $data['name'], PDO::PARAM_STR);
            $stmt->bindValue(':slug', (string) $data['slug'], PDO::PARAM_STR);
            $stmt->bindValue(':sku', (string) $data['sku'], PDO::PARAM_STR);
            $stmt->bindValue(':summary', $data['summary'] !== '' ? (string) $data['summary'] : null, $data['summary'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':description', $data['description'] !== '' ? (string) $data['description'] : null, $data['description'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':price', (int) $data['price'], PDO::PARAM_INT);
            if ($data['original_price'] !== null) {
                $stmt->bindValue(':original_price', (int) $data['original_price'], PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':original_price', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':warranty_months', (int) $data['warranty_months'], PDO::PARAM_INT);
            $stmt->bindValue(':condition_overview', (string) $data['condition_overview'], PDO::PARAM_STR);
            $stmt->bindValue(':stock_quantity', $finalStockQuantity, PDO::PARAM_INT);
            $stmt->bindValue(':has_serials', $newHasSerials, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $this->replaceSpecsInternal($id, $specs);

            if ($imageSortOrders !== []) {
                $this->updateImageSortOrdersInternal($id, $imageSortOrders);
            }
            if ($primaryImageId !== null && $primaryImageId > 0) {
                $this->setPrimaryImageInternal($id, $primaryImageId);
            }
            if ($newImages !== []) {
                $this->insertImagesInternal($id, $newImages, $primaryImageId === null);
            }
            $this->syncPrimaryThumbnailInternal($id);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Đổi trạng thái ẩn (`archived`) hoặc hiển thị (`published`) cho sản phẩm trong Transaction.
     *
     * @throws DomainException Khi sản phẩm không tồn tại hoặc danh mục đang ẩn không thể publish.
     */
    public function setProductStatus(int $id, string $targetStatus): void
    {
        if (!in_array($targetStatus, self::ALLOWED_STATUSES, true)) {
            throw new DomainException('Trạng thái sản phẩm không hợp lệ.');
        }

        $this->db->beginTransaction();
        try {
            $locked = $this->fetchOne(
                "SELECT id, category_id, status FROM {$this->table} WHERE id = :id LIMIT 1 FOR UPDATE",
                [':id' => $id]
            );
            if ($locked === null) {
                throw new DomainException('Không tìm thấy sản phẩm cần thay đổi trạng thái.');
            }

            if ($targetStatus === 'published') {
                $this->assertValidCategoryForProduct((int) $locked['category_id'], true);
            }

            $stmt = $this->db->prepare("UPDATE {$this->table} SET status = :status, updated_at = NOW() WHERE id = :id");
            $stmt->execute([
                ':status' => $targetStatus,
                ':id'     => $id,
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Thêm danh sách ảnh đã upload vào `product_images` cho sản phẩm có sẵn (qua route riêng).
     *
     * @param int                             $productId      ID sản phẩm.
     * @param array<int, array<string, mixed>> $uploadedImages Mảng ảnh đã kiểm tra MIME & lưu disk.
     * @return void
     */
    public function addImagesToProduct(int $productId, array $uploadedImages): void
    {
        if ($uploadedImages === []) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $locked = $this->fetchOne("SELECT id FROM {$this->table} WHERE id = :id LIMIT 1 FOR UPDATE", [':id' => $productId]);
            if ($locked === null) {
                throw new DomainException('Không tìm thấy sản phẩm để thêm ảnh.');
            }

            $existingCount = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM product_images WHERE product_id = :pid",
                [':pid' => $productId]
            );
            if ($existingCount + count($uploadedImages) > 10) {
                throw new DomainException('Mỗi sản phẩm chỉ được lưu tối đa 10 hình ảnh.');
            }

            $this->insertImagesInternal($productId, $uploadedImages, true);
            $this->syncPrimaryThumbnailInternal($productId);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Xóa một ảnh thuộc đúng sản phẩm (`product_id` + `image_id`), đồng bộ lại ảnh đại diện.
     * Chỉ trả về đường dẫn file để xóa vật lý SAU KHI commit và xác nhận không còn bản ghi nào khác tham chiếu tới file đó.
     *
     * @param int $productId ID sản phẩm.
     * @param int $imageId   ID ảnh cần xóa.
     * @return string|null Đường dẫn tương đối của file an toàn để unlink, hoặc null nếu file vẫn còn nơi khác tham chiếu.
     */
    public function deleteProductImage(int $productId, int $imageId): ?string
    {
        $this->db->beginTransaction();
        try {
            $lockedProd = $this->fetchOne("SELECT id FROM {$this->table} WHERE id = :id LIMIT 1 FOR UPDATE", [':id' => $productId]);
            if ($lockedProd === null) {
                throw new DomainException('Không tìm thấy sản phẩm.');
            }

            $image = $this->fetchOne(
                "SELECT id, product_id, image_url, is_primary FROM product_images WHERE id = :img_id AND product_id = :pid LIMIT 1 FOR UPDATE",
                [':img_id' => $imageId, ':pid' => $productId]
            );
            if ($image === null) {
                throw new DomainException('Hình ảnh không tồn tại hoặc không thuộc sản phẩm này.');
            }

            $imageUrl = (string) $image['image_url'];

            $delStmt = $this->db->prepare("DELETE FROM product_images WHERE id = :img_id AND product_id = :pid");
            $delStmt->execute([':img_id' => $imageId, ':pid' => $productId]);

            // Nếu vừa xóa ảnh đại diện chính -> tự động chọn ảnh kế tiếp làm đại diện
            if ((int) $image['is_primary'] === 1) {
                $nextImg = $this->fetchOne(
                    "SELECT id FROM product_images WHERE product_id = :pid ORDER BY sort_order ASC, id ASC LIMIT 1",
                    [':pid' => $productId]
                );
                if ($nextImg !== null) {
                    $this->db->prepare("UPDATE product_images SET is_primary = 1 WHERE id = :id")
                        ->execute([':id' => (int) $nextImg['id']]);
                }
            }

            $this->syncPrimaryThumbnailInternal($productId);

            // Kiểm tra xem còn bản ghi product_images hoặc products.thumbnail nào dùng cùng file hay không
            $otherImgRefs = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM product_images WHERE image_url = :url",
                [':url' => $imageUrl]
            );
            $otherThumbRefs = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM {$this->table} WHERE thumbnail = :url",
                [':url' => $imageUrl]
            );

            $this->db->commit();

            return ($otherImgRefs === 0 && $otherThumbRefs === 0) ? $imageUrl : null;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Xây dựng mệnh đề WHERE và tham số PDO cho danh sách sản phẩm Admin.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, array{val: mixed, type: int}>}
     */
    private function buildAdminWhere(array $filters): array
    {
        $conditions = ['1 = 1'];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $likeVal = '%' . $escaped . '%';
            $conditions[] = '(p.name LIKE :kw_name OR p.sku LIKE :kw_sku)';
            $bindings[':kw_name'] = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_sku']  = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
        }

        if (isset($filters['category_ids']) && is_array($filters['category_ids']) && $filters['category_ids'] !== []) {
            $inParts = [];
            foreach (array_values(array_unique(array_map('intval', $filters['category_ids']))) as $idx => $cid) {
                $ph            = ":cat_{$idx}";
                $inParts[]     = $ph;
                $bindings[$ph] = ['val' => $cid, 'type' => PDO::PARAM_INT];
            }
            $conditions[] = 'p.category_id IN (' . implode(', ', $inParts) . ')';
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::ALLOWED_STATUSES, true)) {
            $conditions[]         = 'p.status = :status';
            $bindings[':status']  = ['val' => $status, 'type' => PDO::PARAM_STR];
        }

        $stockType = trim((string) ($filters['stock_type'] ?? ''));
        if ($stockType === 'serial') {
            $conditions[]              = 'p.has_serials = :has_serials';
            $bindings[':has_serials']  = ['val' => 1, 'type' => PDO::PARAM_INT];
        } elseif ($stockType === 'quantity') {
            $conditions[]              = 'p.has_serials = :has_serials';
            $bindings[':has_serials']  = ['val' => 0, 'type' => PDO::PARAM_INT];
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * Kiểm tra danh mục tồn tại và nếu đang publish thì toàn bộ chuỗi tổ tiên phải đang hoạt động (`is_active = 1`).
     *
     * @throws DomainException
     */
    private function assertValidCategoryForProduct(int $categoryId, bool $requireActiveTree): void
    {
        if ($categoryId <= 0) {
            throw new DomainException('Danh mục sản phẩm không hợp lệ.');
        }

        $cat = $this->fetchOne(
            "SELECT id, parent_id, name, is_active FROM categories WHERE id = :id LIMIT 1",
            [':id' => $categoryId]
        );
        if ($cat === null) {
            throw new DomainException('Danh mục được chọn không tồn tại.');
        }

        if (!$requireActiveTree) {
            return;
        }

        if ((int) $cat['is_active'] !== 1) {
            throw new DomainException("Không thể xuất bản sản phẩm vào danh mục đang bị ẩn (\"{$cat['name']}\").");
        }

        // Duyệt ngược chuỗi tổ tiên (tối đa 3 cấp) kèm tập $visited chống chu trình
        $visited  = [$categoryId => true];
        $cursorId = $cat['parent_id'] !== null ? (int) $cat['parent_id'] : 0;
        while ($cursorId > 0 && !isset($visited[$cursorId])) {
            $visited[$cursorId] = true;
            $ancestor = $this->fetchOne(
                "SELECT id, parent_id, name, is_active FROM categories WHERE id = :id LIMIT 1",
                [':id' => $cursorId]
            );
            if ($ancestor === null) {
                throw new DomainException('Danh mục cha của sản phẩm không còn tồn tại.');
            }
            if ((int) $ancestor['is_active'] !== 1) {
                throw new DomainException("Không thể xuất bản sản phẩm vì danh mục cha (\"{$ancestor['name']}\") đang bị ẩn.");
            }
            $cursorId = $ancestor['parent_id'] !== null ? (int) $ancestor['parent_id'] : 0;
        }
    }

    /**
     * Đếm các dữ liệu phụ thuộc để bảo vệ không cho đổi `has_serials` gây mất nhất quán.
     *
     * @return array{units_count: int, order_items_count: int, cart_items_count: int}
     */
    private function getStockTypeDependenciesInternal(int $productId): array
    {
        return [
            'units_count'       => (int) $this->fetchColumn("SELECT COUNT(*) FROM product_units WHERE product_id = :id", [':id' => $productId]),
            'order_items_count' => (int) $this->fetchColumn("SELECT COUNT(*) FROM order_items WHERE product_id = :id", [':id' => $productId]),
            'cart_items_count'  => (int) $this->fetchColumn("SELECT COUNT(*) FROM cart_items WHERE product_id = :id", [':id' => $productId]),
        ];
    }

    /**
     * Thay thế toàn bộ thông số kỹ thuật động của sản phẩm trong transaction hiện tại.
     *
     * @param array<int, array<string, mixed>> $specs
     */
    private function replaceSpecsInternal(int $productId, array $specs): void
    {
        $this->db->prepare("DELETE FROM product_specs WHERE product_id = :pid")->execute([':pid' => $productId]);

        if ($specs === []) {
            return;
        }

        $ins = $this->db->prepare("
            INSERT INTO product_specs (product_id, group_name, spec_name, spec_value, sort_order, created_at, updated_at)
            VALUES (:pid, :group_name, :spec_name, :spec_value, :sort_order, NOW(), NOW())
        ");

        foreach ($specs as $idx => $spec) {
            $groupName = trim((string) ($spec['group_name'] ?? ''));
            $ins->bindValue(':pid', $productId, PDO::PARAM_INT);
            $ins->bindValue(':group_name', $groupName !== '' ? $groupName : null, $groupName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $ins->bindValue(':spec_name', (string) $spec['spec_name'], PDO::PARAM_STR);
            $ins->bindValue(':spec_value', (string) $spec['spec_value'], PDO::PARAM_STR);
            $ins->bindValue(':sort_order', (int) ($spec['sort_order'] ?? ($idx + 1)), PDO::PARAM_INT);
            $ins->execute();
        }
    }

    /**
     * Chèn các bản ghi ảnh mới vào `product_images`.
     *
     * @param array<int, array<string, mixed>> $images
     */
    private function insertImagesInternal(int $productId, array $images, bool $makeFirstPrimaryIfNone): void
    {
        $hasPrimary = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM product_images WHERE product_id = :pid AND is_primary = 1",
            [':pid' => $productId]
        ) > 0;

        $maxOrder = (int) $this->fetchColumn(
            "SELECT COALESCE(MAX(sort_order), 0) FROM product_images WHERE product_id = :pid",
            [':pid' => $productId]
        );

        $ins = $this->db->prepare("
            INSERT INTO product_images (product_id, image_url, alt_text, sort_order, is_primary, created_at)
            VALUES (:pid, :url, :alt, :sort_order, :is_primary, NOW())
        ");

        foreach ($images as $idx => $img) {
            $isPrimary = 0;
            if (!$hasPrimary && $makeFirstPrimaryIfNone && $idx === 0) {
                $isPrimary  = 1;
                $hasPrimary = true;
            }
            $maxOrder++;
            $alt = trim((string) ($img['alt_text'] ?? ''));

            $ins->bindValue(':pid', $productId, PDO::PARAM_INT);
            $ins->bindValue(':url', (string) $img['image_url'], PDO::PARAM_STR);
            $ins->bindValue(':alt', $alt !== '' ? $alt : null, $alt !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $ins->bindValue(':sort_order', (int) ($img['sort_order'] ?? $maxOrder), PDO::PARAM_INT);
            $ins->bindValue(':is_primary', $isPrimary, PDO::PARAM_INT);
            $ins->execute();
        }
    }

    /**
     * Cập nhật thứ tự sắp xếp ảnh cho đúng sản phẩm.
     *
     * @param array<int, int> $sortOrders Map `[image_id => sort_order]`.
     */
    private function updateImageSortOrdersInternal(int $productId, array $sortOrders): void
    {
        $upd = $this->db->prepare("
            UPDATE product_images
            SET sort_order = :sort_order
            WHERE id = :id AND product_id = :pid
        ");
        foreach ($sortOrders as $imgId => $order) {
            if ((int) $imgId <= 0) {
                continue;
            }
            $upd->execute([
                ':sort_order' => (int) $order,
                ':id'         => (int) $imgId,
                ':pid'        => $productId,
            ]);
        }
    }

    /**
     * Đặt ảnh đại diện chính (`is_primary = 1`) thuộc đúng sản phẩm.
     */
    private function setPrimaryImageInternal(int $productId, int $imageId): void
    {
        $exists = $this->fetchOne(
            "SELECT id FROM product_images WHERE id = :id AND product_id = :pid LIMIT 1",
            [':id' => $imageId, ':pid' => $productId]
        );
        if ($exists === null) {
            return;
        }

        $this->db->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = :pid")
            ->execute([':pid' => $productId]);
        $this->db->prepare("UPDATE product_images SET is_primary = 1 WHERE id = :id AND product_id = :pid")
            ->execute([':id' => $imageId, ':pid' => $productId]);
    }

    /**
     * Đồng bộ cột `products.thumbnail` theo ảnh đại diện hiện tại của sản phẩm.
     */
    private function syncPrimaryThumbnailInternal(int $productId): void
    {
        $primary = $this->fetchOne(
            "SELECT image_url FROM product_images WHERE product_id = :pid ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1",
            [':pid' => $productId]
        );
        $thumbUrl = $primary !== null ? (string) $primary['image_url'] : null;

        $stmt = $this->db->prepare("UPDATE {$this->table} SET thumbnail = :thumb WHERE id = :pid");
        if ($thumbUrl !== null) {
            $stmt->bindValue(':thumb', $thumbUrl, PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':thumb', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
        $stmt->execute();
    }
}
