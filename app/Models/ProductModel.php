<?php
/**
 * Model quản lý truy vấn bảng `products` và các bảng liên quan (`product_specs`, `product_units`,
 * `product_inspections`, `inspection_items`) phục vụ Trang chủ và Trang Danh sách Sản phẩm TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Thực thi các câu lệnh truy vấn PDO Prepared Statements trên cơ sở dữ liệu `tl_gaming`.
 * - Tuân thủ nghiêm ngặt quy tắc nghiệp vụ: Chỉ hiển thị công khai các sản phẩm có `p.status = 'published'`
 *   thuộc danh mục đang hoạt động (`c.is_active = 1`). Khách hàng không thể can thiệp trạng thái này từ Request.
 * - Xây dựng bộ lọc đa điều kiện (Từ khóa `q`, Danh mục cha/con, Khoảng giá `min_price`..`max_price`, Trạng thái tồn kho)
 *   kết hợp sắp xếp qua danh sách trắng (Whitelist ORDER BY) và phân trang an toàn (`LIMIT` / `OFFSET`).
 * - Sử dụng mệnh đề `EXISTS` khi tìm kiếm trên `product_specs` để tuyệt đối không gây nhân bản (trùng lặp) dòng sản phẩm.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDO;

class ProductModel extends Model
{
    /**
     * Số lượng sản phẩm hiển thị mặc định trên mỗi trang danh sách (`GET /san-pham`).
     */
    public const PER_PAGE = 12;

    /**
     * Danh sách trắng (Whitelist) ánh xạ mã sắp xếp từ Request sang biểu thức `ORDER BY` an toàn trong SQL.
     * Tuyệt đối không đưa trực tiếp giá trị từ `$_GET` vào câu lệnh SQL.
     *
     * @var array<string, string>
     */
    private const ALLOWED_ORDER_BY = [
        'moi-nhat'   => 'p.created_at DESC, p.id DESC',
        'gia-tang'   => 'p.price ASC, p.id ASC',
        'gia-giam'   => 'p.price DESC, p.id ASC',
        'ten-az'     => 'p.name ASC, p.id ASC',
    ];

    /**
     * Bảng ánh xạ các bí danh (alias) tham số sắp xếp sang khóa chuẩn tiếng Việt.
     *
     * @var array<string, string>
     */
    private const SORT_ALIASES = [
        'moi-nhat'   => 'moi-nhat',
        'newest'     => 'moi-nhat',
        'gia-tang'   => 'gia-tang',
        'price_asc'  => 'gia-tang',
        'gia-giam'   => 'gia-giam',
        'price_desc' => 'gia-giam',
        'ten-az'     => 'ten-az',
        'name_asc'   => 'ten-az',
    ];

    /**
     * Tên bảng chính trong cơ sở dữ liệu `tl_gaming`.
     */
    protected string $table = 'products';

    /**
     * Phép nối bảng danh mục cha/ông (tối đa 3 cấp theo CategoryModel::MAX_DEPTH)
     * để bảo đảm sản phẩm thuộc danh mục có tổ tiên bị ẩn không bao giờ hiển thị công khai.
     */
    private const ACTIVE_CATEGORY_JOINS = "
        LEFT JOIN categories c_p1 ON c.parent_id = c_p1.id
        LEFT JOIN categories c_p2 ON c_p1.parent_id = c_p2.id
    ";

    /**
     * Điều kiện kiểm tra danh mục trực tiếp và toàn bộ tổ tiên (tối đa 3 cấp) đều đang hoạt động.
     */
    private const ACTIVE_CATEGORY_CONDITION = "
        c.is_active = :is_active
        AND (
            c.parent_id IS NULL
            OR (
                c_p1.id IS NOT NULL
                AND c_p1.is_active = 1
                AND (c_p1.parent_id IS NULL OR (c_p2.id IS NOT NULL AND c_p2.is_active = 1))
            )
        )
    ";

    /**
     * Chuẩn hóa khóa sắp xếp từ người dùng về một trong 4 khóa hợp lệ (`moi-nhat`, `gia-tang`, `gia-giam`, `ten-az`).
     *
     * @param string|null $rawSort Giá trị tham số sắp xếp từ Query String.
     * @return string              Khóa sắp xếp hợp lệ (mặc định là `'moi-nhat'`).
     */
    public static function normalizeSortKey(?string $rawSort): string
    {
        $clean = strtolower(trim((string) $rawSort));
        return self::SORT_ALIASES[$clean] ?? 'moi-nhat';
    }

    /**
     * Đếm tổng số sản phẩm `published` thỏa mãn đồng thời các điều kiện tìm kiếm và bộ lọc.
     * Phục vụ tính tổng số trang (`totalPages`) cho thanh phân trang.
     *
     * @param array<string, mixed> $filters Mảng điều kiện lọc đã được Controller chuẩn hóa.
     * @return int                          Tổng số sản phẩm khớp điều kiện.
     */
    public function countFilteredPublishedProducts(array $filters = []): int
    {
        [$whereClause, $bindings] = $this->buildCatalogFilterConditions($filters);

        $activeCatJoins = self::ACTIVE_CATEGORY_JOINS;
        $sql = "
            SELECT COUNT(*)
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            {$activeCatJoins}
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
     * Truy vấn danh sách sản phẩm `published` theo bộ lọc, sắp xếp an toàn và phân trang (`LIMIT` / `OFFSET`).
     *
     * Giải thích cơ chế chống trùng lặp và bảo mật SQL:
     * 1. Khi tìm kiếm từ khóa `q` trên bảng `product_specs`, hàm sử dụng truy vấn con `EXISTS (SELECT 1 FROM product_specs ps WHERE ps.product_id = p.id ...)`
     *    thay vì `LEFT JOIN product_specs`. Nhờ vậy mỗi sản phẩm chỉ xuất hiện duy nhất 1 lần dù có nhiều thông số khớp từ khóa.
     * 2. Mệnh đề `ORDER BY` chỉ lấy từ hằng số `self::ALLOWED_ORDER_BY` sau khi qua `normalizeSortKey()`.
     * 3. `LIMIT` và `OFFSET` được ép kiểu số nguyên dương và truyền qua `bindValue(..., PDO::PARAM_INT)`.
     *
     * @param array<string, mixed> $filters Mảng điều kiện lọc (`keyword`, `category_ids`, `min_price`, `max_price`, `stock_status`, `sort`).
     * @param int                  $limit   Số sản phẩm mỗi trang (mặc định 12).
     * @param int                  $offset  Vị trí bắt đầu lấy bản ghi (`(page - 1) * limit`).
     * @return array<int, array<string, mixed>> Danh sách sản phẩm đã gắn thông số `specs` và phần trăm giảm giá.
     */
    public function getFilteredPublishedProducts(array $filters = [], int $limit = self::PER_PAGE, int $offset = 0): array
    {
        $safeLimit  = max(1, min($limit, 48));
        $safeOffset = max(0, $offset);

        [$whereClause, $bindings] = $this->buildCatalogFilterConditions($filters);

        $sortKey     = self::normalizeSortKey((string) ($filters['sort'] ?? 'moi-nhat'));
        $orderByExpr = self::ALLOWED_ORDER_BY[$sortKey] ?? self::ALLOWED_ORDER_BY['moi-nhat'];

        $sql = "
            SELECT p.id,
                   p.category_id,
                   p.name,
                   p.slug,
                   p.sku,
                   p.summary,
                   p.price,
                   p.original_price,
                   p.warranty_months,
                   p.condition_overview,
                   p.stock_quantity,
                   p.has_serials,
                   p.thumbnail,
                   p.status,
                   p.created_at,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   (
                       SELECT COUNT(DISTINCT pi.id)
                       FROM product_units pu
                       INNER JOIN product_inspections pi ON pi.unit_id = pu.id
                       WHERE pu.product_id = p.id
                         AND pi.checklist_overall_result IN ('passed', 'conditional_pass')
                   ) AS verified_inspection_count,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu2
                       WHERE pu2.product_id = p.id
                         AND pu2.status = 'available'
                         AND (
                             SELECT pi2.checklist_overall_result
                             FROM product_inspections pi2
                             WHERE pi2.unit_id = pu2.id
                             ORDER BY pi2.inspection_date DESC, pi2.id DESC
                             LIMIT 1
                         ) = 'passed'
                         AND (
                             SELECT COUNT(DISTINCT ii2.step_number)
                             FROM inspection_items ii2
                             WHERE ii2.inspection_id = (
                                 SELECT pi2_id.id
                                 FROM product_inspections pi2_id
                                 WHERE pi2_id.unit_id = pu2.id
                                 ORDER BY pi2_id.inspection_date DESC, pi2_id.id DESC
                                 LIMIT 1
                             )
                         ) = 18
                   ) AS available_serial_count
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            " . self::ACTIVE_CATEGORY_JOINS . "
            WHERE {$whereClause}
            ORDER BY {$orderByExpr}
            LIMIT :lim OFFSET :off
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $param => $meta) {
            $stmt->bindValue($param, $meta['val'], $meta['type']);
        }
        $stmt->bindValue(':lim', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return $this->attachSpecsAndEnrich($rows);
    }

    /**
     * Xây dựng mệnh đề `WHERE` và danh sách tham số ràng buộc PDO dùng chung cho cả truy vấn đếm (`COUNT`)
     * và truy vấn lấy danh sách (`SELECT`) trên trang Sản phẩm.
     *
     * Các điều kiện được kết hợp đồng thời bằng toán tử `AND`:
     * - Bắt buộc: `p.status = 'published'` và `c.is_active = 1`.
     * - Danh mục (`category_ids`): `p.category_id IN (...)` (hỗ trợ cả danh mục cha kèm các danh mục con).
     * - Từ khóa (`keyword`): Tìm trong `p.name`, `p.sku`, `p.summary`, `p.condition_overview` hoặc thông số trong `product_specs` (qua `EXISTS`).
     * - Giá thấp nhất (`min_price`): `p.price >= :min_price`.
     * - Giá cao nhất (`max_price`): `p.price <= :max_price`.
     * - Tồn kho (`stock_status`): Nếu bằng `'in_stock'` thì thêm `p.stock_quantity > 0`.
     *
     * @param array<string, mixed> $filters Mảng điều kiện lọc đã chuẩn hóa từ Controller.
     * @return array{0: string, 1: array<string, array{val: mixed, type: int}>} Mảng gồm chuỗi WHERE và mảng tham số bind.
     */
    private function buildCatalogFilterConditions(array $filters): array
    {
        $conditions = [
            'p.status = :status',
            self::ACTIVE_CATEGORY_CONDITION,
        ];
        $bindings = [
            ':status'    => ['val' => 'published', 'type' => PDO::PARAM_STR],
            ':is_active' => ['val' => 1, 'type' => PDO::PARAM_INT],
        ];

        // 1. Lọc theo danh sách ID danh mục (bao gồm danh mục cha và các danh mục con)
        if (isset($filters['category_ids']) && is_array($filters['category_ids']) && $filters['category_ids'] !== []) {
            $cleanIds = array_values(array_unique(array_map('intval', $filters['category_ids'])));
            $inParts  = [];
            foreach ($cleanIds as $idx => $catId) {
                $key            = ":fcat_{$idx}";
                $inParts[]      = $key;
                $bindings[$key] = ['val' => $catId, 'type' => PDO::PARAM_INT];
            }
            $conditions[] = 'p.category_id IN (' . implode(', ', $inParts) . ')';
        }

        // 2. Tìm kiếm theo từ khóa q (Tên sản phẩm, SKU, Tóm tắt, Tình trạng hoặc Thông số kỹ thuật qua EXISTS)
        $cleanKeyword = trim((string) ($filters['keyword'] ?? ''));
        if ($cleanKeyword !== '') {
            // Escape ký tự đại diện LIKE (% và _) để người dùng tìm kiếm chính xác ký tự thực
            $escapedLike = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $cleanKeyword);
            $likeVal     = '%' . $escapedLike . '%';

            $conditions[] = "(
                p.name LIKE :kw_name
                OR p.sku LIKE :kw_sku
                OR p.summary LIKE :kw_summary
                OR p.condition_overview LIKE :kw_cond
                OR EXISTS (
                    SELECT 1
                    FROM product_specs ps
                    WHERE ps.product_id = p.id
                      AND (ps.spec_name LIKE :kw_spec_name OR ps.spec_value LIKE :kw_spec_val)
                )
            )";

            $bindings[':kw_name']      = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_sku']       = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_summary']   = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_cond']      = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_spec_name'] = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_spec_val']  = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
        }

        // 3. Lọc theo giá thấp nhất (VND nguyên)
        if (isset($filters['min_price']) && $filters['min_price'] !== null && (int) $filters['min_price'] > 0) {
            $conditions[]           = 'p.price >= :min_price';
            $bindings[':min_price'] = ['val' => (int) $filters['min_price'], 'type' => PDO::PARAM_INT];
        }

        // 4. Lọc theo giá cao nhất (VND nguyên)
        if (isset($filters['max_price']) && $filters['max_price'] !== null && (int) $filters['max_price'] > 0) {
            $conditions[]           = 'p.price <= :max_price';
            $bindings[':max_price'] = ['val' => (int) $filters['max_price'], 'type' => PDO::PARAM_INT];
        }

        // 5. Lọc theo trạng thái tồn kho ('in_stock' = chỉ lấy sản phẩm còn tồn kho > 0, 'all' = tất cả sản phẩm published)
        $stockStatus = (string) ($filters['stock_status'] ?? 'all');
        if ($stockStatus === 'in_stock') {
            $conditions[] = 'p.stock_quantity > 0';
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * Lấy danh sách Sản phẩm Nổi bật (`status = 'published'`) hiển thị trên Trang chủ.
     * Ưu tiên các dòng máy còn tồn kho (`stock_quantity > 0`), đã có biên bản kiểm định thực tế
     * trong bảng `product_inspections` và có mức giá ưu đãi tốt.
     *
     * @param int $limit Số lượng sản phẩm tối đa hiển thị ở khối Sản phẩm nổi bật.
     * @return array<int, array<string, mixed>> Danh sách sản phẩm đã làm giàu thông số và phần trăm giảm giá.
     */
    public function getFeaturedProducts(int $limit = 8): array
    {
        $safeLimit = max(1, min($limit, 24));

        $sql = "
            SELECT p.id,
                   p.category_id,
                   p.name,
                   p.slug,
                   p.sku,
                   p.summary,
                   p.price,
                   p.original_price,
                   p.warranty_months,
                   p.condition_overview,
                   p.stock_quantity,
                   p.has_serials,
                   p.thumbnail,
                   p.status,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   (
                       SELECT COUNT(DISTINCT pi.id)
                       FROM product_units pu
                       INNER JOIN product_inspections pi ON pi.unit_id = pu.id
                       WHERE pu.product_id = p.id
                         AND pi.checklist_overall_result IN ('passed', 'conditional_pass')
                   ) AS verified_inspection_count,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu2
                       WHERE pu2.product_id = p.id
                         AND pu2.status = 'available'
                         AND (
                             SELECT pi2.checklist_overall_result
                             FROM product_inspections pi2
                             WHERE pi2.unit_id = pu2.id
                             ORDER BY pi2.inspection_date DESC, pi2.id DESC
                             LIMIT 1
                         ) = 'passed'
                         AND (
                             SELECT COUNT(DISTINCT ii2.step_number)
                             FROM inspection_items ii2
                             WHERE ii2.inspection_id = (
                                 SELECT pi2_id.id
                                 FROM product_inspections pi2_id
                                 WHERE pi2_id.unit_id = pu2.id
                                 ORDER BY pi2_id.inspection_date DESC, pi2_id.id DESC
                                 LIMIT 1
                             )
                         ) = 18
                   ) AS available_serial_count
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            " . self::ACTIVE_CATEGORY_JOINS . "
            WHERE p.status = :status
              AND " . self::ACTIVE_CATEGORY_CONDITION . "
            ORDER BY CASE WHEN p.stock_quantity > 0 THEN 0 ELSE 1 END ASC,
                     verified_inspection_count DESC,
                     p.id ASC
            LIMIT :lim
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':is_active', 1, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return $this->attachSpecsAndEnrich($rows);
    }

    /**
     * Truy vấn danh sách sản phẩm `published` thuộc một hoặc nhiều danh mục cụ thể (`category_id IN (...)`).
     * Phục vụ hiển thị các phân khu chuyên biệt trên Trang chủ: PC Gaming, Laptop Gaming, Linh kiện & Màn hình.
     *
     * @param array<int, int> $categoryIds Danh sách khóa chính `category_id` cần lấy sản phẩm.
     * @param int             $limit       Số lượng sản phẩm tối đa trong khối.
     * @return array<int, array<string, mixed>> Danh sách sản phẩm thuộc nhóm danh mục.
     */
    public function getPublishedByCategoryIds(array $categoryIds, int $limit = 8): array
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn(int $id): bool => $id > 0)));
        if ($cleanIds === []) {
            return [];
        }

        $safeLimit    = max(1, min($limit, 24));
        $placeholders = [];
        foreach ($cleanIds as $idx => $catId) {
            $placeholders[] = ":cat_{$idx}";
        }
        $inClause = implode(', ', $placeholders);

        $sql = "
            SELECT p.id,
                   p.category_id,
                   p.name,
                   p.slug,
                   p.sku,
                   p.summary,
                   p.price,
                   p.original_price,
                   p.warranty_months,
                   p.condition_overview,
                   p.stock_quantity,
                   p.has_serials,
                   p.thumbnail,
                   p.status,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   (
                       SELECT COUNT(DISTINCT pi.id)
                       FROM product_units pu
                       INNER JOIN product_inspections pi ON pi.unit_id = pu.id
                       WHERE pu.product_id = p.id
                         AND pi.checklist_overall_result IN ('passed', 'conditional_pass')
                   ) AS verified_inspection_count,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu2
                       WHERE pu2.product_id = p.id
                         AND pu2.status = 'available'
                         AND (
                             SELECT pi2.checklist_overall_result
                             FROM product_inspections pi2
                             WHERE pi2.unit_id = pu2.id
                             ORDER BY pi2.inspection_date DESC, pi2.id DESC
                             LIMIT 1
                         ) = 'passed'
                         AND (
                             SELECT COUNT(DISTINCT ii2.step_number)
                             FROM inspection_items ii2
                             WHERE ii2.inspection_id = (
                                 SELECT pi2_id.id
                                 FROM product_inspections pi2_id
                                 WHERE pi2_id.unit_id = pu2.id
                                 ORDER BY pi2_id.inspection_date DESC, pi2_id.id DESC
                                 LIMIT 1
                             )
                         ) = 18
                   ) AS available_serial_count
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            " . self::ACTIVE_CATEGORY_JOINS . "
            WHERE p.status = :status
              AND " . self::ACTIVE_CATEGORY_CONDITION . "
              AND p.category_id IN ({$inClause})
            ORDER BY CASE WHEN p.stock_quantity > 0 THEN 0 ELSE 1 END ASC,
                     p.id ASC
            LIMIT :lim
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':is_active', 1, PDO::PARAM_INT);
        foreach ($cleanIds as $idx => $catId) {
            $stmt->bindValue(":cat_{$idx}", $catId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':lim', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return $this->attachSpecsAndEnrich($rows);
    }

    /**
     * Lấy danh sách sản phẩm đã xuất bản (`status = 'published'`), hỗ trợ lọc nhanh theo danh mục hoặc từ khóa.
     *
     * @param int                  $limit       Số lượng sản phẩm tối đa cần lấy.
     * @param array<int, int>|null $categoryIds Mảng ID danh mục cần lọc (hoặc null nếu lấy tất cả).
     * @param string|null          $keyword     Từ khóa tìm kiếm theo tên, SKU hoặc thông số kỹ thuật.
     * @return array<int, array<string, mixed>> Danh sách sản phẩm đã làm giàu dữ liệu.
     */
    public function getPublishedPreview(int $limit = 14, ?array $categoryIds = null, ?string $keyword = null): array
    {
        return $this->getFilteredPublishedProducts([
            'category_ids' => $categoryIds,
            'keyword'      => $keyword,
            'sort'         => 'moi-nhat',
        ], $limit, 0);
    }

    /**
     * Tìm một sản phẩm đang mở bán (`status = 'published'`) theo đường dẫn tĩnh `slug`.
     * Kết nối với bảng `categories` (và danh mục cha nếu có) để phục vụ hiển thị Breadcrumb.
     *
     * @param string $slug Đường dẫn tĩnh của sản phẩm.
     * @return array<string, mixed>|null Thông tin sản phẩm đã làm giàu hoặc null nếu không tồn tại / chưa phát hành.
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        $cleanSlug = trim($slug);
        if ($cleanSlug === '' || !preg_match('/^[a-z0-9\-]{1,255}$/', $cleanSlug)) {
            return null;
        }

        $sql = "
            SELECT p.id,
                   p.category_id,
                   p.name,
                   p.slug,
                   p.sku,
                   p.summary,
                   p.description,
                   p.price,
                   p.original_price,
                   p.warranty_months,
                   p.condition_overview,
                   p.stock_quantity,
                   p.has_serials,
                   p.thumbnail,
                   p.status,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   c.parent_id AS category_parent_id,
                   p_cat.name AS parent_category_name,
                   p_cat.slug AS parent_category_slug,
                   (
                       SELECT COUNT(DISTINCT pi.id)
                       FROM product_units pu
                       INNER JOIN product_inspections pi ON pi.unit_id = pu.id
                       WHERE pu.product_id = p.id
                         AND pi.checklist_overall_result IN ('passed', 'conditional_pass')
                   ) AS verified_inspection_count,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu2
                       WHERE pu2.product_id = p.id
                         AND pu2.status = 'available'
                         AND (
                             SELECT pi2.checklist_overall_result
                             FROM product_inspections pi2
                             WHERE pi2.unit_id = pu2.id
                             ORDER BY pi2.inspection_date DESC, pi2.id DESC
                             LIMIT 1
                         ) = 'passed'
                         AND (
                             SELECT COUNT(DISTINCT ii2.step_number)
                             FROM inspection_items ii2
                             WHERE ii2.inspection_id = (
                                 SELECT pi2_id.id
                                 FROM product_inspections pi2_id
                                 WHERE pi2_id.unit_id = pu2.id
                                 ORDER BY pi2_id.inspection_date DESC, pi2_id.id DESC
                                 LIMIT 1
                             )
                         ) = 18
                   ) AS available_serial_count
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            LEFT JOIN categories p_cat ON c.parent_id = p_cat.id
            " . self::ACTIVE_CATEGORY_JOINS . "
            WHERE p.slug = :slug
              AND p.status = :status
              AND " . self::ACTIVE_CATEGORY_CONDITION . "
            LIMIT 1
        ";

        $row = $this->fetchOne($sql, [
            ':slug'      => $cleanSlug,
            ':status'    => 'published',
            ':is_active' => 1,
        ]);

        if ($row === null) {
            return null;
        }

        $enrichedList = $this->attachSpecsAndEnrich([$row]);
        $product      = $enrichedList[0] ?? null;
        if ($product === null) {
            return null;
        }

        // Xác định số lượng mua tối đa thực tế:
        // - Nếu sản phẩm quản lý theo Serial (has_serials = 1): dựa trên số cá thể máy có status = 'available' và stock_quantity.
        // - Nếu sản phẩm quản lý theo số lượng (has_serials = 0): dựa trên stock_quantity.
        $hasSerials    = ((int) ($product['has_serials'] ?? 1)) === 1;
        $stockQuantity = max(0, (int) ($product['stock_quantity'] ?? 0));
        $availSerials  = max(0, (int) ($product['available_serial_count'] ?? 0));

        $purchasableQty = $hasSerials ? min($stockQuantity, $availSerials) : $stockQuantity;
        $product['purchasable_quantity'] = $purchasableQty;
        $product['is_in_stock']          = (((string) $product['status']) === 'published' && $purchasableQty > 0);

        return $product;
    }

    /**
     * Tìm một sản phẩm đang mở bán theo ID (`p.id`), kiểm tra danh mục đang hoạt động.
     * Phục vụ xác thực thêm vào giỏ hàng và tính giá trên Server.
     *
     * @param int $productId Khóa chính sản phẩm.
     * @return array<string, mixed>|null Thông tin sản phẩm hoặc null nếu không tồn tại / đã ẩn.
     */
    public function findPublishedById(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $sql = "
            SELECT p.id,
                   p.category_id,
                   p.name,
                   p.slug,
                   p.sku,
                   p.summary,
                   p.description,
                   p.price,
                   p.original_price,
                   p.warranty_months,
                   p.condition_overview,
                   p.stock_quantity,
                   p.has_serials,
                   p.thumbnail,
                   p.status,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   c.parent_id AS category_parent_id,
                   (
                       SELECT COUNT(DISTINCT pi.id)
                       FROM product_units pu
                       INNER JOIN product_inspections pi ON pi.unit_id = pu.id
                       WHERE pu.product_id = p.id
                         AND pi.checklist_overall_result IN ('passed', 'conditional_pass')
                   ) AS verified_inspection_count,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu2
                       WHERE pu2.product_id = p.id
                         AND pu2.status = 'available'
                         AND (
                             SELECT pi2.checklist_overall_result
                             FROM product_inspections pi2
                             WHERE pi2.unit_id = pu2.id
                             ORDER BY pi2.inspection_date DESC, pi2.id DESC
                             LIMIT 1
                         ) = 'passed'
                         AND (
                             SELECT COUNT(DISTINCT ii2.step_number)
                             FROM inspection_items ii2
                             WHERE ii2.inspection_id = (
                                 SELECT pi2_id.id
                                 FROM product_inspections pi2_id
                                 WHERE pi2_id.unit_id = pu2.id
                                 ORDER BY pi2_id.inspection_date DESC, pi2_id.id DESC
                                 LIMIT 1
                             )
                         ) = 18
                   ) AS available_serial_count
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            " . self::ACTIVE_CATEGORY_JOINS . "
            WHERE p.id = :id
              AND p.status = :status
              AND " . self::ACTIVE_CATEGORY_CONDITION . "
            LIMIT 1
        ";

        $row = $this->fetchOne($sql, [
            ':id'        => $productId,
            ':status'    => 'published',
            ':is_active' => 1,
        ]);

        if ($row === null) {
            return null;
        }

        $enrichedList = $this->attachSpecsAndEnrich([$row]);
        $product      = $enrichedList[0] ?? null;
        if ($product === null) {
            return null;
        }

        $hasSerials    = ((int) ($product['has_serials'] ?? 1)) === 1;
        $stockQuantity = max(0, (int) ($product['stock_quantity'] ?? 0));
        $availSerials  = max(0, (int) ($product['available_serial_count'] ?? 0));

        $purchasableQty = $hasSerials ? min($stockQuantity, $availSerials) : $stockQuantity;
        $product['purchasable_quantity'] = $purchasableQty;
        $product['is_in_stock']          = (((string) $product['status']) === 'published' && $purchasableQty > 0);

        return $product;
    }

    /**
     * Lấy danh sách sản phẩm theo mảng ID để nạp giỏ hàng trong 1 truy vấn duy nhất.
     *
     * @param array<int, int> $productIds Mảng ID sản phẩm.
     * @return array<int, array<string, mixed>> Mảng sản phẩm theo key id sản phẩm.
     */
    public function getPublishedByIds(array $productIds): array
    {
        $cleanIds = [];
        foreach ($productIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $cleanIds[$intId] = $intId;
            }
        }

        if ($cleanIds === []) {
            return [];
        }

        $inPlaceholders = [];
        $params         = [
            ':status'    => 'published',
            ':is_active' => 1,
        ];

        $idx = 0;
        foreach ($cleanIds as $id) {
            $key = ":p_id_{$idx}";
            $inPlaceholders[] = $key;
            $params[$key]     = $id;
            $idx++;
        }

        $inClause = implode(', ', $inPlaceholders);

        $sql = "
            SELECT p.id,
                   p.category_id,
                   p.name,
                   p.slug,
                   p.sku,
                   p.summary,
                   p.description,
                   p.price,
                   p.original_price,
                   p.warranty_months,
                   p.condition_overview,
                   p.stock_quantity,
                   p.has_serials,
                   p.thumbnail,
                   p.status,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   c.parent_id AS category_parent_id,
                   (
                       SELECT COUNT(DISTINCT pi.id)
                       FROM product_units pu
                       INNER JOIN product_inspections pi ON pi.unit_id = pu.id
                       WHERE pu.product_id = p.id
                         AND pi.checklist_overall_result IN ('passed', 'conditional_pass')
                   ) AS verified_inspection_count,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu2
                       WHERE pu2.product_id = p.id
                         AND pu2.status = 'available'
                         AND (
                             SELECT pi2.checklist_overall_result
                             FROM product_inspections pi2
                             WHERE pi2.unit_id = pu2.id
                             ORDER BY pi2.inspection_date DESC, pi2.id DESC
                             LIMIT 1
                         ) = 'passed'
                         AND (
                             SELECT COUNT(DISTINCT ii2.step_number)
                             FROM inspection_items ii2
                             WHERE ii2.inspection_id = (
                                 SELECT pi2_id.id
                                 FROM product_inspections pi2_id
                                 WHERE pi2_id.unit_id = pu2.id
                                 ORDER BY pi2_id.inspection_date DESC, pi2_id.id DESC
                                 LIMIT 1
                             )
                         ) = 18
                   ) AS available_serial_count
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            " . self::ACTIVE_CATEGORY_JOINS . "
            WHERE p.id IN ({$inClause})
              AND p.status = :status
              AND " . self::ACTIVE_CATEGORY_CONDITION . "
        ";

        $rows = $this->fetchAll($sql, $params);
        $enrichedList = $this->attachSpecsAndEnrich($rows);

        $resultMap = [];
        foreach ($enrichedList as $prod) {
            $hasSerials    = ((int) ($prod['has_serials'] ?? 1)) === 1;
            $stockQuantity = max(0, (int) ($prod['stock_quantity'] ?? 0));
            $availSerials  = max(0, (int) ($prod['available_serial_count'] ?? 0));

            $purchasableQty = $hasSerials ? min($stockQuantity, $availSerials) : $stockQuantity;
            $prod['purchasable_quantity'] = $purchasableQty;
            $prod['is_in_stock']          = (((string) $prod['status']) === 'published' && $purchasableQty > 0);

            $resultMap[(int) $prod['id']] = $prod;
        }

        return $resultMap;
    }

    /**
     * Lấy bộ sưu tập hình ảnh (`product_images` kết hợp `products.thumbnail`) cho trang Chi tiết sản phẩm.
     *
     * Giải thích cơ chế xử lý hình ảnh:
     * - Ưu tiên đưa ảnh đại diện chính (`thumbnail` hoặc ảnh có `is_primary = 1`) lên đầu danh sách.
     * - Khử trùng lặp theo đường dẫn `image_url` nếu ảnh trong `thumbnail` trùng với bản ghi trong `product_images`.
     * - Nếu sản phẩm chưa có bất kỳ ảnh nào trong CSDL, trả về 1 phần tử ảnh dự phòng (Fallback SVG).
     *
     * @param int         $productId        Khóa chính của sản phẩm.
     * @param string|null $primaryThumbnail Đường dẫn cột `products.thumbnail`.
     * @param string      $productName      Tên sản phẩm dùng làm `alt` mặc định.
     * @return array<int, array{id: int, image_url: string, alt_text: string, is_primary: int}> Danh sách ảnh gallery.
     */
    public function getGalleryImagesByProductId(
        int $productId,
        ?string $primaryThumbnail = null,
        string $productName = ''
    ): array {
        $dbImages = [];
        if ($productId > 0) {
            $sql = "
                SELECT id, product_id, image_url, alt_text, sort_order, is_primary
                FROM product_images
                WHERE product_id = :product_id
                ORDER BY is_primary DESC, sort_order ASC, id ASC
            ";
            $dbImages = $this->fetchAll($sql, [':product_id' => $productId]);
        }

        $gallery  = [];
        $seenUrls = [];
        $defaultAlt = $productName !== '' ? $productName : 'Hình ảnh thực tế sản phẩm TL Gaming';

        // 1. Đưa ảnh đại diện từ cột products.thumbnail lên vị trí đầu tiên nếu hợp lệ
        $cleanThumb = trim((string) $primaryThumbnail);
        if ($cleanThumb !== '') {
            $seenUrls[$cleanThumb] = true;
            $gallery[] = [
                'id'          => 0,
                'image_url'   => $cleanThumb,
                'alt_text'    => $defaultAlt . ' — Ảnh đại diện chính',
                'is_primary'  => 1,
                'is_fallback' => false,
            ];
        }

        // 2. Bổ sung toàn bộ ảnh góc chụp chi tiết từ bảng product_images
        foreach ($dbImages as $img) {
            $url = trim((string) ($img['image_url'] ?? ''));
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $alt = trim((string) ($img['alt_text'] ?? ''));
            $gallery[] = [
                'id'          => (int) ($img['id'] ?? 0),
                'image_url'   => $url,
                'alt_text'    => $alt !== '' ? $alt : $defaultAlt,
                'is_primary'  => $gallery === [] ? 1 : 0,
                'is_fallback' => false,
            ];
        }

        // 3. Nếu sản phẩm hoàn toàn không có ảnh nào -> trả về 1 mục ảnh Fallback mặc định
        if ($gallery === []) {
            $gallery[] = [
                'id'          => 0,
                'image_url'   => '/assets/images/placeholder-product.svg',
                'alt_text'    => $defaultAlt . ' (Ảnh dự phòng)',
                'is_primary'  => 1,
                'is_fallback' => true,
            ];
        }

        return $gallery;
    }

    /**
     * Truy vấn bảng `product_specs` của một sản phẩm và sắp xếp ưu tiên theo thứ tự phần cứng cốt lõi
     * (CPU -> Mainboard -> RAM -> SSD/HDD -> VGA -> Nguồn PSU -> Vỏ Case -> Màn hình -> Các thông số khác).
     * Tuyệt đối chỉ hiển thị thông số có thực trong CSDL, không tự bịa thêm dòng khi dữ liệu thiếu.
     *
     * @param int $productId Khóa chính của sản phẩm.
     * @return array<int, array{id: int, group_name: string, spec_name: string, spec_value: string, sort_order: int}>
     */
    public function getPrioritizedSpecsByProductId(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $sql = "
            SELECT id, group_name, spec_name, spec_value, sort_order
            FROM product_specs
            WHERE product_id = :product_id
            ORDER BY sort_order ASC, id ASC
        ";
        $rows = $this->fetchAll($sql, [':product_id' => $productId]);
        if ($rows === []) {
            return [];
        }

        // Hàm chấm điểm ưu tiên theo tên thông số phần cứng cho PC Gaming / Laptop
        $getPriorityRank = static function (string $specName): int {
            $lower = function_exists('mb_strtolower')
                ? mb_strtolower($specName, 'UTF-8')
                : strtolower($specName);

            if (str_contains($lower, 'cpu') || str_contains($lower, 'vi xử lý')) {
                return 1;
            }
            if (str_contains($lower, 'main') || str_contains($lower, 'bo mạch')) {
                return 2;
            }
            if (str_contains($lower, 'ram') || str_contains($lower, 'bộ nhớ trong')) {
                return 3;
            }
            if (str_contains($lower, 'ssd') || str_contains($lower, 'hdd') || str_contains($lower, 'ổ cứng') || str_contains($lower, 'lưu trữ')) {
                return 4;
            }
            if (str_contains($lower, 'vga') || str_contains($lower, 'gpu') || str_contains($lower, 'card đồ họa')) {
                return 5;
            }
            if (str_contains($lower, 'psu') || str_contains($lower, 'bộ nguồn')) {
                return 6;
            }
            if (str_contains($lower, 'case') || str_contains($lower, 'vỏ máy')) {
                return 7;
            }
            if (str_contains($lower, 'màn hình') || str_contains($lower, 'display')) {
                return 8;
            }
            return 99;
        };

        $specs = [];
        foreach ($rows as $idx => $r) {
            $sName = (string) ($r['spec_name'] ?? '');
            $specs[] = [
                'id'            => (int) ($r['id'] ?? 0),
                'group_name'    => (string) ($r['group_name'] ?? 'Thông số kỹ thuật'),
                'spec_name'     => $sName,
                'spec_value'    => (string) ($r['spec_value'] ?? ''),
                'sort_order'    => (int) ($r['sort_order'] ?? 0),
                '_priority'     => $getPriorityRank($sName),
                '_original_idx' => $idx,
            ];
        }

        usort($specs, static function (array $a, array $b): int {
            if ($a['_priority'] !== $b['_priority']) {
                return $a['_priority'] <=> $b['_priority'];
            }
            if ($a['sort_order'] !== $b['sort_order']) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            return $a['_original_idx'] <=> $b['_original_idx'];
        });

        foreach ($specs as &$item) {
            unset($item['_priority'], $item['_original_idx']);
        }
        unset($item);

        return $specs;
    }

    /**
     * Truy vấn toàn bộ danh sách cá thể máy vật lý (`product_units`) thuộc một sản phẩm,
     * kèm theo biên bản kiểm định (`product_inspections`) và 18 bước kiểm định (`inspection_items`)
     * gắn chính xác với từng `unit_id` riêng biệt.
     *
     * Nguyên tắc toàn vẹn dữ liệu & bảo mật:
     * 1. Tuyệt đối KHÔNG lấy cột nhạy cảm `import_price` (giá vốn nhập kho) hay thông tin cá nhân của `inspector_id`.
     * 2. Gắn biên bản kiểm định đúng theo `unit_id`: Nếu một sản phẩm có 2 chiếc máy (`SN001` và `SN002`),
     *    trong đó chỉ `SN001` có biên bản trong `product_inspections` còn `SN002` chưa có, thì chỉ `SN001`
     *    nhận trạng thái `has_valid_inspection = true`, còn `SN002` hiển thị rõ "Chưa có biên bản kiểm định".
     * 3. Sử dụng truy vấn gom nhóm (`WHERE unit_id IN (...)` và `WHERE inspection_id IN (...)`) để tránh lỗi N+1 Query.
     *
     * @param int $productId Khóa chính của dòng sản phẩm.
     * @return array<int, array<string, mixed>> Danh sách từng cá thể `product_unit` kèm hồ sơ kiểm định riêng của nó.
     */
    public function getUnitsWithInspectionsByProductId(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        // 1. Lấy danh sách các cá thể máy vật lý của sản phẩm (không lấy import_price)
        $unitSql = "
            SELECT id,
                   product_id,
                   serial_number,
                   hardware_condition_detail,
                   battery_health,
                   cosmetic_grade,
                   status,
                   imported_at
            FROM product_units
            WHERE product_id = :product_id
            ORDER BY CASE
                         WHEN status = 'available' THEN 0
                         WHEN status = 'reserved'  THEN 1
                         ELSE 2
                     END ASC,
                     id ASC
        ";
        $units = $this->fetchAll($unitSql, [':product_id' => $productId]);
        if ($units === []) {
            return [];
        }

        $unitIds = [];
        foreach ($units as $u) {
            $unitIds[] = (int) $u['id'];
        }

        // 2. Lấy các biên bản kiểm định thuộc tập unit_id trên (mỗi unit lấy biên bản mới nhất nếu có)
        $uPlaceholders = [];
        $uParams       = [];
        foreach ($unitIds as $idx => $uid) {
            $key             = ":uid_{$idx}";
            $uPlaceholders[] = $key;
            $uParams[$key]   = $uid;
        }
        $uInClause = implode(', ', $uPlaceholders);

        $inspSql = "
            SELECT id,
                   unit_id,
                   inspection_date,
                   checklist_overall_result,
                   notes
            FROM product_inspections
            WHERE unit_id IN ({$uInClause})
            ORDER BY inspection_date DESC, id DESC
        ";
        $inspRows = $this->fetchAll($inspSql, $uParams);

        $latestInspectionByUnit = [];
        $inspectionIds          = [];
        foreach ($inspRows as $insp) {
            $uid = (int) $insp['unit_id'];
            // Chỉ giữ biên bản mới nhất của đúng unit_id đó
            if (!isset($latestInspectionByUnit[$uid])) {
                $latestInspectionByUnit[$uid] = $insp;
                $inspectionIds[]              = (int) $insp['id'];
            }
        }

        // 3. Lấy chi tiết 18 bước kiểm định từ bảng inspection_items cho các biên bản tìm thấy
        $itemsByInspection = [];
        if ($inspectionIds !== []) {
            $iPlaceholders = [];
            $iParams       = [];
            foreach ($inspectionIds as $idx => $iid) {
                $key             = ":iid_{$idx}";
                $iPlaceholders[] = $key;
                $iParams[$key]   = $iid;
            }
            $iInClause = implode(', ', $iPlaceholders);

            $itemSql = "
                SELECT id,
                       inspection_id,
                       step_number,
                       group_name,
                       item_name,
                       result,
                       notes
                FROM inspection_items
                WHERE inspection_id IN ({$iInClause})
                ORDER BY step_number ASC, id ASC
            ";
            $itemRows = $this->fetchAll($itemSql, $iParams);
            foreach ($itemRows as $item) {
                $iid = (int) $item['inspection_id'];
                $itemsByInspection[$iid][] = [
                    'step_number' => (int) $item['step_number'],
                    'group_name'  => (string) $item['group_name'],
                    'item_name'   => (string) $item['item_name'],
                    'result'      => (string) $item['result'],
                    'notes'       => (string) ($item['notes'] ?? ''),
                ];
            }
        }

        // 4. Kết hợp dữ liệu kiểm định vào đúng từng product_unit tương ứng
        $resultUnits = [];
        foreach ($units as $unit) {
            $uid  = (int) $unit['id'];
            $insp = $latestInspectionByUnit[$uid] ?? null;

            $hasValidInspection = false;
            $inspectionData     = null;

            if ($insp !== null) {
                $iid     = (int) $insp['id'];
                $overall = (string) ($insp['checklist_overall_result'] ?? '');
                $steps   = $itemsByInspection[$iid] ?? [];

                $passedCount     = 0;
                $warningCount    = 0;
                $applicableCount = 0;
                foreach ($steps as $st) {
                    if ($st['result'] !== 'not_applicable') {
                        $applicableCount++;
                    }
                    if ($st['result'] === 'pass') {
                        $passedCount++;
                    } elseif ($st['result'] === 'warning') {
                        $warningCount++;
                    }
                }

                $hasValidInspection = in_array($overall, ['passed', 'conditional_pass'], true);
                $inspectionData = [
                    'id'                       => $iid,
                    'inspection_date'          => (string) $insp['inspection_date'],
                    'checklist_overall_result' => $overall,
                    'notes'                    => (string) ($insp['notes'] ?? ''),
                    'passed_steps_count'       => $passedCount,
                    'warning_steps_count'      => $warningCount,
                    'applicable_steps_count'   => $applicableCount,
                    'total_steps_count'        => count($steps),
                    'items'                    => $steps,
                ];
            }

            $unit['has_valid_inspection'] = $hasValidInspection;
            $unit['inspection']           = $inspectionData;
            $resultUnits[]                = $unit;
        }

        return $resultUnits;
    }

    /**
     * Lấy danh sách tối đa 4 Sản phẩm tương tự (`status = 'published'`) cùng danh mục với sản phẩm đang xem.
     *
     * Quy tắc truy vấn:
     * - Loại trừ chính sản phẩm hiện tại (`p.id <> :exclude_id`).
     * - Chỉ lấy sản phẩm có `p.status = 'published'` và `c.is_active = 1`.
     * - Ưu tiên các sản phẩm cùng `category_id` trực tiếp trước, sau đó đến các sản phẩm trong cùng nhánh danh mục cha/con,
     *   và ưu tiên sản phẩm còn hàng (`p.stock_quantity > 0`).
     *
     * @param int      $excludeProductId Khóa chính của sản phẩm đang xem cần loại trừ.
     * @param int      $categoryId       Khóa chính danh mục của sản phẩm hiện tại.
     * @param int|null $parentCategoryId Khóa chính danh mục cha (nếu sản phẩm thuộc danh mục con).
     * @param int      $limit            Số lượng sản phẩm tương tự tối đa (mặc định: 4).
     * @return array<int, array<string, mixed>> Danh sách sản phẩm tương tự đã làm giàu dữ liệu.
     */
    public function getSimilarPublishedProducts(
        int $excludeProductId,
        int $categoryId,
        ?int $parentCategoryId = null,
        int $limit = 4
    ): array {
        if ($excludeProductId <= 0 || $categoryId <= 0) {
            return [];
        }

        $safeLimit     = max(1, min($limit, 8));
        $rootFamilyId  = ($parentCategoryId !== null && $parentCategoryId > 0) ? $parentCategoryId : $categoryId;

        $sql = "
            SELECT p.id,
                   p.category_id,
                   p.name,
                   p.slug,
                   p.sku,
                   p.summary,
                   p.price,
                   p.original_price,
                   p.warranty_months,
                   p.condition_overview,
                   p.stock_quantity,
                   p.has_serials,
                   p.thumbnail,
                   p.status,
                   c.name AS category_name,
                   c.slug AS category_slug,
                   (
                       SELECT COUNT(DISTINCT pi.id)
                       FROM product_units pu
                       INNER JOIN product_inspections pi ON pi.unit_id = pu.id
                       WHERE pu.product_id = p.id
                         AND pi.checklist_overall_result IN ('passed', 'conditional_pass')
                   ) AS verified_inspection_count,
                   (
                       SELECT COUNT(*)
                       FROM product_units pu2
                       WHERE pu2.product_id = p.id
                         AND pu2.status = 'available'
                         AND (
                             SELECT pi2.checklist_overall_result
                             FROM product_inspections pi2
                             WHERE pi2.unit_id = pu2.id
                             ORDER BY pi2.inspection_date DESC, pi2.id DESC
                             LIMIT 1
                         ) = 'passed'
                         AND (
                             SELECT COUNT(DISTINCT ii2.step_number)
                             FROM inspection_items ii2
                             WHERE ii2.inspection_id = (
                                 SELECT pi2_id.id
                                 FROM product_inspections pi2_id
                                 WHERE pi2_id.unit_id = pu2.id
                                 ORDER BY pi2_id.inspection_date DESC, pi2_id.id DESC
                                 LIMIT 1
                             )
                         ) = 18
                   ) AS available_serial_count
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            " . self::ACTIVE_CATEGORY_JOINS . "
            WHERE p.status = :status
              AND " . self::ACTIVE_CATEGORY_CONDITION . "
              AND p.id <> :exclude_id
              AND (
                  p.category_id = :exact_cat_id
                  OR p.category_id = :root_cat_id
                  OR c.parent_id = :parent_cat_id
              )
            ORDER BY CASE WHEN p.category_id = :order_exact_cat THEN 0 ELSE 1 END ASC,
                     CASE WHEN p.stock_quantity > 0 THEN 0 ELSE 1 END ASC,
                     p.id ASC
            LIMIT :lim
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':is_active', 1, PDO::PARAM_INT);
        $stmt->bindValue(':exclude_id', $excludeProductId, PDO::PARAM_INT);
        $stmt->bindValue(':exact_cat_id', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':root_cat_id', $rootFamilyId, PDO::PARAM_INT);
        $stmt->bindValue(':parent_cat_id', $rootFamilyId, PDO::PARAM_INT);
        $stmt->bindValue(':order_exact_cat', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return $this->attachSpecsAndEnrich($rows);
    }

    /**
     * Đếm tổng số dòng sản phẩm đang ở trạng thái `published` thuộc danh mục đang hoạt động.
     *
     * @return int Tổng số sản phẩm đang mở bán công khai.
     */
    public function countPublishedProducts(): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM {$this->table} p
            INNER JOIN categories c ON p.category_id = c.id
            " . self::ACTIVE_CATEGORY_JOINS . "
            WHERE p.status = :status
              AND " . self::ACTIVE_CATEGORY_CONDITION . "
        ";

        return (int) $this->fetchColumn($sql, [
            ':status'    => 'published',
            ':is_active' => 1,
        ]);
    }

    /**
     * Truy vấn dữ liệu tổng quan Quy trình Kiểm định 18 bước từ bảng `inspection_items` và `product_inspections`.
     *
     * @return array{
     *     groups: array<int, array{group_name: string, steps: array<int, array{step_number: int, item_name: string}>}>,
     *     verified_records: array<int, array<string, mixed>>,
     *     total_inspected_units: int,
     *     total_available_units: int
     * }
     */
    public function getInspectionOverviewData(): array
    {
        $stepsSql = "
            SELECT step_number,
                   MIN(group_name) AS group_name,
                   MIN(item_name) AS item_name
            FROM inspection_items
            GROUP BY step_number
            ORDER BY step_number ASC
        ";
        $stepRows = $this->fetchAll($stepsSql);

        $groupedMap = [];
        foreach ($stepRows as $row) {
            $groupName = (string) ($row['group_name'] ?? 'Hạng mục kỹ thuật');
            if (!isset($groupedMap[$groupName])) {
                $groupedMap[$groupName] = [
                    'group_name' => $groupName,
                    'steps'      => [],
                ];
            }
            $groupedMap[$groupName]['steps'][] = [
                'step_number' => (int) $row['step_number'],
                'item_name'   => (string) $row['item_name'],
            ];
        }

        $recordsSql = "
            SELECT pi.id AS inspection_id,
                   pi.inspection_date,
                   pi.checklist_overall_result,
                   pi.notes,
                   pu.serial_number,
                   pu.cosmetic_grade,
                   pu.battery_health,
                   pu.status AS unit_status,
                   p.name AS product_name,
                   p.slug AS product_slug,
                   p.sku AS product_sku,
                   (
                       SELECT COUNT(*)
                       FROM inspection_items ii
                       WHERE ii.inspection_id = pi.id
                         AND ii.result = 'pass'
                   ) AS passed_steps_count,
                   (
                       SELECT COUNT(*)
                       FROM inspection_items ii2
                       WHERE ii2.inspection_id = pi.id
                         AND ii2.result <> 'not_applicable'
                   ) AS applicable_steps_count
            FROM product_inspections pi
            INNER JOIN product_units pu ON pi.unit_id = pu.id
            INNER JOIN products p ON pu.product_id = p.id
            WHERE p.status = :status
              AND pi.checklist_overall_result IN ('passed', 'conditional_pass')
            ORDER BY pi.inspection_date DESC, pi.id ASC
            LIMIT 5
        ";
        $verifiedRecords = $this->fetchAll($recordsSql, [':status' => 'published']);

        $totalInspectedUnits = (int) $this->fetchColumn("
            SELECT COUNT(DISTINCT pi.unit_id)
            FROM product_inspections pi
            WHERE pi.checklist_overall_result IN ('passed', 'conditional_pass')
        ");

        $totalAvailableUnits = (int) $this->fetchColumn("
            SELECT COUNT(*)
            FROM product_units
            WHERE status = 'available'
        ");

        return [
            'groups'                => array_values($groupedMap),
            'verified_records'      => $verifiedRecords,
            'total_inspected_units' => $totalInspectedUnits,
            'total_available_units' => $totalAvailableUnits,
        ];
    }

    /**
     * Tính toán phần trăm giảm giá hợp lệ từ giá bán hiện tại (`price`) và giá gốc (`original_price`).
     *
     * @param int      $price         Giá bán hiện tại (VND).
     * @param int|null $originalPrice Giá niêm yết gốc ban đầu (VND).
     * @return int                    Phần trăm giảm giá nguyên dương (1..99), hoặc 0 nếu không giảm giá / không hợp lệ.
     */
    public static function calculateDiscountPercent(int $price, ?int $originalPrice): int
    {
        if ($price <= 0 || $originalPrice === null || $originalPrice <= $price) {
            return 0;
        }

        $percent = (int) round((($originalPrice - $price) / $originalPrice) * 100);
        if ($percent < 1 || $percent >= 100) {
            return 0;
        }

        return $percent;
    }

    /**
     * Nạp danh sách thông số kỹ thuật từ bảng `product_specs` cho một tập sản phẩm trong một truy vấn duy nhất (tránh N+1 Query)
     * và chuẩn hóa các trường hiển thị cho View.
     *
     * @param array<int, array<string, mixed>> $products Danh sách dòng sản phẩm thô từ bảng `products`.
     * @return array<int, array<string, mixed>>          Danh sách dòng sản phẩm đã gắn `specs`, `discount_percent`, `is_in_stock`.
     */
    private function attachSpecsAndEnrich(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $productIds = [];
        foreach ($products as $prod) {
            $id = (int) ($prod['id'] ?? 0);
            if ($id > 0) {
                $productIds[] = $id;
            }
        }

        $specsByProduct = [];
        if ($productIds !== []) {
            $placeholders = [];
            $params       = [];
            foreach ($productIds as $idx => $pid) {
                $key            = ":pid_{$idx}";
                $placeholders[] = $key;
                $params[$key]   = $pid;
            }

            $inClause = implode(', ', $placeholders);
            $specSql  = "
                SELECT product_id, group_name, spec_name, spec_value, sort_order
                FROM product_specs
                WHERE product_id IN ({$inClause})
                ORDER BY product_id ASC, sort_order ASC, id ASC
            ";

            $specRows = $this->fetchAll($specSql, $params);
            foreach ($specRows as $spec) {
                $pid = (int) $spec['product_id'];
                $specsByProduct[$pid][] = [
                    'group_name' => (string) ($spec['group_name'] ?? ''),
                    'spec_name'  => (string) ($spec['spec_name'] ?? ''),
                    'spec_value' => (string) ($spec['spec_value'] ?? ''),
                ];
            }
        }

        $enriched = [];
        foreach ($products as $row) {
            $pid           = (int) ($row['id'] ?? 0);
            $price         = (int) ($row['price'] ?? 0);
            $originalPrice = isset($row['original_price']) && $row['original_price'] !== null
                ? (int) $row['original_price']
                : null;

            $discountPercent  = self::calculateDiscountPercent($price, $originalPrice);
            $hasValidDiscount = ($discountPercent > 0 && $originalPrice !== null && $originalPrice > $price);
            $stockQty         = max(0, (int) ($row['stock_quantity'] ?? 0));
            $status           = (string) ($row['status'] ?? 'draft');
            $isInStock        = ($status === 'published' && $stockQty > 0);
            $verifiedCount    = max(0, (int) ($row['verified_inspection_count'] ?? 0));

            $row['price']                     = $price;
            $row['original_price']            = $originalPrice;
            $row['discount_percent']          = $discountPercent;
            $row['has_valid_discount']        = $hasValidDiscount;
            $row['discount_saving']           = $hasValidDiscount ? ($originalPrice - $price) : 0;
            $row['stock_quantity']            = $stockQty;
            $row['is_in_stock']               = $isInStock;
            $row['has_verified_inspection']   = ($verifiedCount > 0);
            $row['verified_inspection_count'] = $verifiedCount;
            $row['specs']                     = $specsByProduct[$pid] ?? [];

            $enriched[] = $row;
        }

        return $enriched;
    }
}
