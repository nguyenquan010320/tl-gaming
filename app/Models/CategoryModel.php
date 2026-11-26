<?php
/**
 * Model quản lý truy vấn bảng `categories` (Danh mục sản phẩm máy tính & linh kiện cũ).
 *
 * Vai trò trong kiến trúc MVC:
 * - Truy vấn danh sách danh mục đang hoạt động (`is_active = 1`) từ cơ sở dữ liệu MySQL `tl_gaming` bằng PDO.
 * - Đếm số lượng sản phẩm đang mở bán (`status = 'published'`) thuộc từng danh mục (bao gồm cả danh mục con trực thuộc).
 * - Chuẩn bị cấu trúc cây danh mục và thanh điều hướng Header để truyền từ Controller sang View mà không viết SQL trong View.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DomainException;
use PDO;
use Throwable;

class CategoryModel extends Model
{
    /**
     * Độ sâu tối đa cho phép của cây danh mục (Cấp 1: Gốc -> Cấp 2: Con -> Cấp 3: Cháu).
     */
    public const MAX_DEPTH = 3;

    /**
     * Tên bảng chính trong cơ sở dữ liệu `tl_gaming`.
     */
    protected string $table = 'categories';

    /**
     * Lấy danh sách toàn bộ danh mục đang kích hoạt (`is_active = 1`) và không có tổ tiên nào bị ẩn,
     * kèm thông tin danh mục cha và tổng số sản phẩm `published` thuộc cả nhánh danh mục (tối đa 3 cấp).
     *
     * Giải thích cách tính `published_product_count`:
     * - Truy vấn số lượng sản phẩm có `status = 'published'` theo từng `category_id` bằng `GROUP BY` (không đếm trùng).
     * - Duyệt cây danh mục đang hoạt động theo chiều rộng (BFS) tối đa `MAX_DEPTH` cấp với tập `$visited` chống lặp vô hạn.
     * - Cộng dồn số sản phẩm trực tiếp của chính danh mục đó cùng toàn bộ con và cháu đang hoạt động;
     *   các nhánh hoặc danh mục có `is_active = 0` bị loại bỏ hoàn toàn.
     *
     * @return array<int, array<string, mixed>> Danh sách danh mục sản phẩm đang hoạt động.
     *     Khi cần số lượng, mỗi mục có thêm published_product_count và family_ids.
     */
    public function getActiveCategories(bool $includeProductCounts = true): array
    {
        $sql = "
            SELECT c.id,
                   c.parent_id,
                   c.name,
                   c.slug,
                   c.description,
                   c.display_order,
                   c.is_active,
                   p_cat.name AS parent_name,
                   p_cat.slug AS parent_slug
            FROM {$this->table} c
            LEFT JOIN {$this->table} p_cat ON c.parent_id = p_cat.id
            ORDER BY CASE WHEN c.parent_id IS NULL THEN 0 ELSE 1 END ASC,
                     c.display_order ASC,
                     c.id ASC
        ";

        $rows = $this->fetchAll($sql);
        if ($rows === []) {
            return [];
        }

        [$activeById, $childrenByParent] = $this->buildActiveCategoryMap($rows);
        $directCounts = [];
        if ($includeProductCounts && $activeById !== []) {
            $countRows = $this->fetchAll(
                "SELECT category_id, COUNT(*) AS total_count FROM products WHERE status = :pub_status GROUP BY category_id",
                [':pub_status' => 'published']
            );
            foreach ($countRows as $cRow) {
                $directCounts[(int) $cRow['category_id']] = (int) $cRow['total_count'];
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $catId = (int) $row['id'];
            if (!isset($activeById[$catId])) {
                continue;
            }

            // Tập ID duy nhất gồm chính danh mục và các con/cháu đang hoạt động nên không bao giờ đếm trùng sản phẩm
            if ($includeProductCounts) {
                $familyIds = $this->collectActiveFamilyIdsFromMap($activeById, $childrenByParent, $catId);
                $publishedCount = 0;
                foreach ($familyIds as $familyId) {
                    $publishedCount += $directCounts[$familyId] ?? 0;
                }
                $row['family_ids'] = $familyIds;
                $row['published_product_count'] = $publishedCount;
            }

            unset($row['is_active']);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Tìm thông tin chi tiết một danh mục đang hoạt động theo đường dẫn tĩnh `slug`
     * (bảo đảm cả danh mục lẫn toàn bộ tổ tiên của nó đều đang hoạt động).
     *
     * @param string $slug Đường dẫn tĩnh của danh mục (ví dụ: 'pc-gaming-cu', 'laptop-gaming-cu').
     * @return array<string, mixed>|null Bản ghi danh mục hoặc null nếu không tồn tại / đang ẩn.
     */
    public function findActiveBySlug(string $slug): ?array
    {
        $cleanSlug = trim($slug);
        if ($cleanSlug === '') {
            return null;
        }

        $sql = "
            SELECT c.id, c.parent_id, c.name, c.slug, c.description, c.display_order, c.is_active
            FROM {$this->table} c
            WHERE c.slug = :slug
              AND c.is_active = :is_active
            LIMIT 1
        ";

        $row = $this->fetchOne($sql, [
            ':slug'      => $cleanSlug,
            ':is_active' => 1,
        ]);

        if ($row === null) {
            return null;
        }

        // Kiểm tra toàn bộ chuỗi tổ tiên (cha, ông) để bảo đảm không có tổ tiên nào đang ẩn
        if ($row['parent_id'] !== null && $this->findHiddenAncestor((int) $row['parent_id']) !== null) {
            return null;
        }

        unset($row['is_active']);
        return $row;
    }

    /**
     * Lấy danh sách ID của một danh mục cùng toàn bộ danh mục con và cháu đang hoạt động (tối đa `MAX_DEPTH` = 3 cấp).
     * Phục vụ lọc sản phẩm nhiều cấp (ví dụ: Linh kiện cũ -> Card đồ họa -> RTX 3060).
     *
     * Giải thích thuật toán:
     * - Tải toàn bộ cặp `(id, parent_id, is_active)` để dựng bản đồ cây hợp lệ (loại bỏ danh mục ẩn và nhánh có tổ tiên ẩn).
     * - Nếu `$categoryId` không tồn tại hoặc đang ẩn (hoặc có tổ tiên ẩn), trả về mảng rỗng `[]`.
     * - Duyệt BFS từ `$categoryId` với tập `$visited` chống vòng lặp vô hạn nếu dữ liệu DB bị sai lệch quan hệ,
     *   đồng thời giới hạn số bước duyệt theo `MAX_DEPTH` (3 cấp).
     *
     * @param int $categoryId Khóa chính của danh mục cần lấy.
     * @return array<int, int> Danh sách khóa chính `id` duy nhất của danh mục gốc và các con/cháu đang hoạt động.
     */
    public function getCategoryFamilyIds(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $rows = $this->fetchAll("SELECT id, parent_id, is_active FROM {$this->table} ORDER BY id ASC");
        if ($rows === []) {
            return [];
        }

        [$activeById, $childrenByParent] = $this->buildActiveCategoryMap($rows);
        if (!isset($activeById[$categoryId])) {
            return [];
        }

        return $this->collectActiveFamilyIdsFromMap($activeById, $childrenByParent, $categoryId);
    }

    /**
     * Chuẩn bị danh sách liên kết trên thanh Menu danh mục ở Header theo thiết kế Google Stitch TL Gaming,
     * ánh xạ trực tiếp tới các danh mục đang hoạt động trong MySQL.
     *
     * @param array<int, array<string, mixed>> $activeCategories Danh sách danh mục trả về từ `getActiveCategories()`.
     * @return array<int, array{label: string, url: string, slug: string}>
     */
    public function buildHeaderMenuItems(array $activeCategories): array
    {
        $items = [];
        foreach ($activeCategories as $cat) {
            $slug = (string) ($cat['slug'] ?? '');
            if ($slug === '' || $cat['parent_id'] !== null) {
                continue;
            }
            $items[] = [
                'label' => (string) $cat['name'],
                'slug'  => $slug,
                'url'   => '/san-pham?danh-muc=' . rawurlencode($slug),
            ];
        }

        return $items;
    }

    // =========================================================================
    // PHÂN HỆ QUẢN TRỊ DANH MỤC (ADMIN CRUD & KIỂM SOÁT CÂY CHA/CON)
    // =========================================================================

    /**
     * Lấy danh sách danh mục phục vụ trang Quản trị (`/admin/danh-muc`) theo cấu trúc cây cha -> con,
     * kèm số lượng sản phẩm trực tiếp, số lượng danh mục con và tên danh mục cha.
     *
     * @param string   $keyword      Từ khóa tìm kiếm theo tên hoặc slug (tùy chọn).
     * @param int|null $statusFilter Lọc trạng thái (`1`: hiển thị, `0`: ẩn, `null`: tất cả).
     * @return array<int, array<string, mixed>> Danh sách danh mục đã sắp xếp theo cây phân cấp.
     */
    public function getAdminCategories(string $keyword = '', ?int $statusFilter = null): array
    {
        $sql = "
            SELECT c.id,
                   c.parent_id,
                   c.name,
                   c.slug,
                   c.description,
                   c.display_order,
                   c.is_active,
                   c.created_at,
                   c.updated_at,
                   p_cat.name AS parent_name,
                   p_cat.is_active AS parent_is_active,
                   (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id) AS direct_product_count,
                   (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id AND pr.status = :pub_status) AS published_product_count,
                   (SELECT COUNT(*) FROM {$this->table} ch WHERE ch.parent_id = c.id) AS child_count
            FROM {$this->table} c
            LEFT JOIN {$this->table} p_cat ON c.parent_id = p_cat.id
            ORDER BY c.display_order ASC, c.id ASC
        ";

        $rows = $this->fetchAll($sql, [':pub_status' => 'published']);
        $flatTree = $this->flattenCategoryTree($rows);

        $cleanKeyword = mb_strtolower(trim($keyword), 'UTF-8');
        if ($cleanKeyword === '' && $statusFilter === null) {
            return $flatTree;
        }

        $filtered = [];
        foreach ($flatTree as $item) {
            if ($statusFilter !== null && (int) $item['is_active'] !== $statusFilter) {
                continue;
            }

            if ($cleanKeyword !== '') {
                $haystack = mb_strtolower(
                    (string) $item['name'] . ' ' . (string) $item['slug'] . ' ' . (string) ($item['parent_name'] ?? ''),
                    'UTF-8'
                );
                if (!str_contains($haystack, $cleanKeyword)) {
                    continue;
                }
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    /**
     * Tìm thông tin chi tiết một danh mục bất kỳ theo khóa chính `id` (bao gồm cả danh mục đang ẩn).
     *
     * @param int $id Khóa chính danh mục.
     * @return array<string, mixed>|null Bản ghi danh mục hoặc null nếu không tồn tại.
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sql = "
            SELECT c.id,
                   c.parent_id,
                   c.name,
                   c.slug,
                   c.description,
                   c.display_order,
                   c.is_active,
                   c.created_at,
                   c.updated_at,
                   p_cat.name AS parent_name,
                   p_cat.is_active AS parent_is_active,
                   (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id) AS direct_product_count,
                   (SELECT COUNT(*) FROM {$this->table} ch WHERE ch.parent_id = c.id) AS child_count
            FROM {$this->table} c
            LEFT JOIN {$this->table} p_cat ON c.parent_id = p_cat.id
            WHERE c.id = :id
            LIMIT 1
        ";

        return $this->fetchOne($sql, [':id' => $id]);
    }

    /**
     * Kiểm tra xem `slug` đã tồn tại trong bảng `categories` hay chưa (hỗ trợ loại trừ chính bản ghi đang sửa).
     *
     * @param string   $slug      Đường dẫn tĩnh cần kiểm tra.
     * @param int|null $excludeId ID danh mục đang sửa (null nếu đang thêm mới).
     * @return bool True nếu slug đã bị trùng, False nếu hợp lệ để sử dụng.
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $cleanSlug = trim($slug);
        if ($cleanSlug === '') {
            return false;
        }

        if ($excludeId !== null && $excludeId > 0) {
            $sql = "SELECT id FROM {$this->table} WHERE slug = :slug AND id != :exclude_id LIMIT 1";
            $val = $this->fetchColumn($sql, [':slug' => $cleanSlug, ':exclude_id' => $excludeId]);
            return $val !== false && $val !== null;
        }

        $sql = "SELECT id FROM {$this->table} WHERE slug = :slug LIMIT 1";
        $val = $this->fetchColumn($sql, [':slug' => $cleanSlug]);
        return $val !== false && $val !== null;
    }

    /**
     * Kiểm tra tính hợp lệ của quan hệ danh mục cha/con trước khi lưu vào MySQL:
     * 1. Không cho phép danh mục tự làm cha của chính nó.
     * 2. Xác minh danh mục cha tồn tại trong CSDL.
     * 3. Truy ngược toàn bộ chuỗi tổ tiên để phát hiện chu trình (không cho gán con/cháu làm cha của tổ tiên).
     * 4. Giới hạn tổng độ sâu cây phân cấp không vượt quá `MAX_DEPTH` (3 cấp).
     *
     * @param int|null $categoryId ID danh mục đang sửa (null nếu thêm mới).
     * @param int|null $parentId   ID danh mục cha dự kiến gán (null nếu là danh mục gốc).
     * @return array{valid: bool, message: string, depth: int}
     */
    public function validateParentHierarchy(?int $categoryId, ?int $parentId): array
    {
        $rows = $this->fetchAll("SELECT id, parent_id, name, is_active FROM {$this->table}");
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $subtreeHeight = ($categoryId !== null && $categoryId > 0)
            ? $this->calculateSubtreeHeight($byId, $categoryId)
            : 1;

        if ($parentId === null || $parentId <= 0) {
            if ($subtreeHeight > self::MAX_DEPTH) {
                return [
                    'valid'   => false,
                    'message' => 'Cây danh mục vượt quá độ sâu tối đa ' . self::MAX_DEPTH . ' cấp.',
                    'depth'   => $subtreeHeight,
                ];
            }
            return ['valid' => true, 'message' => '', 'depth' => 1];
        }

        if ($categoryId !== null && $parentId === $categoryId) {
            return [
                'valid'   => false,
                'message' => 'Không thể chọn chính danh mục này làm danh mục cha.',
                'depth'   => 0,
            ];
        }

        if (!isset($byId[$parentId])) {
            return [
                'valid'   => false,
                'message' => 'Danh mục cha được chọn không tồn tại trong hệ thống.',
                'depth'   => 0,
            ];
        }

        // Truy ngược chuỗi tổ tiên từ $parentId lên gốc để phát hiện chu trình và đo độ sâu
        $visited       = [];
        $cursorId      = $parentId;
        $ancestorDepth = 0;

        while ($cursorId !== null && $cursorId > 0) {
            if ($categoryId !== null && $cursorId === $categoryId) {
                return [
                    'valid'   => false,
                    'message' => 'Không thể chọn danh mục con/cháu làm danh mục cha vì sẽ tạo vòng lặp phân cấp.',
                    'depth'   => 0,
                ];
            }

            if (isset($visited[$cursorId])) {
                return [
                    'valid'   => false,
                    'message' => 'Phát hiện vòng lặp phân cấp trong dữ liệu danh mục hiện có.',
                    'depth'   => 0,
                ];
            }

            $visited[$cursorId] = true;
            $ancestorDepth++;

            if (!isset($byId[$cursorId])) {
                break;
            }
            $nextParent = $byId[$cursorId]['parent_id'];
            $cursorId   = $nextParent !== null ? (int) $nextParent : null;
        }

        $resultingMaxDepth = $ancestorDepth + $subtreeHeight;
        if ($resultingMaxDepth > self::MAX_DEPTH) {
            return [
                'valid'   => false,
                'message' => 'Không thể gán danh mục cha này vì tổng độ sâu cây danh mục sẽ vượt quá giới hạn ' . self::MAX_DEPTH . ' cấp.',
                'depth'   => $resultingMaxDepth,
            ];
        }

        return ['valid' => true, 'message' => '', 'depth' => $ancestorDepth + 1];
    }

    /**
     * Lấy danh sách tùy chọn cho ô chọn Danh mục cha (`<select name="parent_id">`),
     * tự động loại trừ chính danh mục đang sửa, toàn bộ con cháu của nó và các nhánh đã đạt độ sâu tối đa.
     *
     * @param int|null $excludeId ID danh mục đang sửa (null nếu thêm mới).
     * @return array<int, array<string, mixed>>
     */
    public function getParentOptions(?int $excludeId = null): array
    {
        $rows = $this->fetchAll("SELECT id, parent_id, name, display_order, is_active FROM {$this->table} ORDER BY display_order ASC, id ASC");
        $tree = $this->flattenCategoryTree($rows);

        $excludedIds = [];
        if ($excludeId !== null && $excludeId > 0) {
            $excludedIds = $this->collectDescendantIds($rows, $excludeId);
            $excludedIds[] = $excludeId;
        }
        $excludedMap = array_flip($excludedIds);

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $movingSubtreeHeight = ($excludeId !== null && $excludeId > 0)
            ? $this->calculateSubtreeHeight($byId, $excludeId)
            : 1;

        $options = [];
        foreach ($tree as $item) {
            $itemId = (int) $item['id'];
            if (isset($excludedMap[$itemId])) {
                continue;
            }
            if (((int) $item['depth'] + $movingSubtreeHeight) > self::MAX_DEPTH) {
                continue;
            }
            $options[] = $item;
        }

        return $options;
    }

    /**
     * Tạo mới danh mục sản phẩm trong MySQL Transaction với danh sách trường whitelist.
     *
     * @param array<string, mixed> $data Dữ liệu đã validate (`parent_id`, `name`, `slug`, `description`, `display_order`, `is_active`).
     * @return int Khóa chính `id` của danh mục vừa tạo.
     */
    public function createCategory(array $data): int
    {
        $parentId     = isset($data['parent_id']) && (int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null;
        $name         = trim((string) ($data['name'] ?? ''));
        $slug         = trim((string) ($data['slug'] ?? ''));
        $description  = trim((string) ($data['description'] ?? ''));
        $displayOrder = (int) ($data['display_order'] ?? 0);
        $isActive     = ((int) ($data['is_active'] ?? 1)) === 1 ? 1 : 0;

        $this->db->beginTransaction();

        try {
            $hierarchy = $this->validateParentHierarchy(null, $parentId);
            if (!$hierarchy['valid']) {
                throw new DomainException($hierarchy['message']);
            }

            if ($this->slugExists($slug, null)) {
                throw new DomainException("Đường dẫn tĩnh (slug) \"{$slug}\" đã tồn tại. Vui lòng chọn slug khác.");
            }

            // Không cho phép tạo danh mục con đang hiển thị dưới một danh mục cha đang ẩn
            if ($isActive === 1 && $parentId !== null) {
                $parentRow = $this->fetchOne("SELECT id, name, is_active FROM {$this->table} WHERE id = :id LIMIT 1 FOR UPDATE", [':id' => $parentId]);
                if ($parentRow !== null && (int) $parentRow['is_active'] !== 1) {
                    throw new DomainException("Không thể đặt trạng thái Hiển thị khi danh mục cha \"{$parentRow['name']}\" đang bị ẩn.");
                }
            }

            $stmt = $this->db->prepare("
                INSERT INTO {$this->table} (
                    parent_id, name, slug, description, display_order, is_active, created_at, updated_at
                ) VALUES (
                    :parent_id, :name, :slug, :description, :display_order, :is_active, NOW(), NOW()
                )
            ");
            $stmt->bindValue(':parent_id', $parentId, $parentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
            $stmt->bindValue(':description', $description !== '' ? $description : null, $description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':display_order', $displayOrder, PDO::PARAM_INT);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_INT);
            $stmt->execute();

            $newId = (int) $this->db->lastInsertId();
            $this->db->commit();

            return $newId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cập nhật thông tin danh mục sản phẩm (chỉ cho phép các trường whitelist) trong MySQL Transaction.
     * - Kiểm tra chống tự làm cha, chống chu trình và giới hạn độ sâu.
     * - Nếu chuyển sang ẩn (`is_active = 0`), tự động ẩn toàn bộ danh mục con/cháu để tránh rò rỉ sản phẩm công khai.
     *
     * @param int                  $id   Khóa chính danh mục cần sửa.
     * @param array<string, mixed> $data Dữ liệu cập nhật đã validate.
     * @return int Số danh mục con/cháu bị ẩn theo (nếu có).
     */
    public function updateCategory(int $id, array $data): int
    {
        if ($id <= 0) {
            throw new DomainException('ID danh mục không hợp lệ.');
        }

        $parentId     = isset($data['parent_id']) && (int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null;
        $name         = trim((string) ($data['name'] ?? ''));
        $slug         = trim((string) ($data['slug'] ?? ''));
        $description  = trim((string) ($data['description'] ?? ''));
        $displayOrder = (int) ($data['display_order'] ?? 0);
        $isActive     = ((int) ($data['is_active'] ?? 1)) === 1 ? 1 : 0;

        $this->db->beginTransaction();

        try {
            $existing = $this->fetchOne("SELECT id FROM {$this->table} WHERE id = :id LIMIT 1 FOR UPDATE", [':id' => $id]);
            if ($existing === null) {
                throw new DomainException('Không tìm thấy danh mục cần cập nhật.');
            }

            $hierarchy = $this->validateParentHierarchy($id, $parentId);
            if (!$hierarchy['valid']) {
                throw new DomainException($hierarchy['message']);
            }

            if ($this->slugExists($slug, $id)) {
                throw new DomainException("Đường dẫn tĩnh (slug) \"{$slug}\" đã được sử dụng bởi danh mục khác.");
            }

            if ($isActive === 1 && $parentId !== null) {
                $hiddenAncestor = $this->findHiddenAncestor($parentId);
                if ($hiddenAncestor !== null) {
                    throw new DomainException("Không thể hiển thị danh mục khi danh mục cha \"{$hiddenAncestor['name']}\" đang bị ẩn.");
                }
            }

            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET parent_id = :parent_id,
                    name = :name,
                    slug = :slug,
                    description = :description,
                    display_order = :display_order,
                    is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->bindValue(':parent_id', $parentId, $parentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
            $stmt->bindValue(':description', $description !== '' ? $description : null, $description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':display_order', $displayOrder, PDO::PARAM_INT);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $cascadedChildren = 0;
            if ($isActive === 0) {
                $cascadedChildren = $this->cascadeHideDescendants($id);
            }

            $this->db->commit();
            return $cascadedChildren;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Thay đổi trạng thái ẩn/hiện (`is_active`) của một danh mục bằng POST Transaction an toàn.
     * - Khi ẩn (`$isActive = false`): tự động ẩn luôn các danh mục con/cháu trực thuộc để đảm bảo
     *   sản phẩm thuộc nhánh danh mục này không xuất hiện sai trên website khách hàng.
     * - Khi hiện (`$isActive = true`): kiểm tra danh mục cha (nếu có) phải đang hiển thị.
     *
     * @param int  $id       Khóa chính danh mục.
     * @param bool $isActive True để hiển thị (`1`), False để ẩn (`0`).
     * @return int Tổng số danh mục con/cháu bị ẩn kèm theo (khi ẩn danh mục cha).
     */
    public function setCategoryStatus(int $id, bool $isActive): int
    {
        if ($id <= 0) {
            throw new DomainException('ID danh mục không hợp lệ.');
        }

        $this->db->beginTransaction();

        try {
            $row = $this->fetchOne("SELECT id, parent_id, name, is_active FROM {$this->table} WHERE id = :id LIMIT 1 FOR UPDATE", [':id' => $id]);
            if ($row === null) {
                throw new DomainException('Không tìm thấy danh mục yêu cầu.');
            }

            if ($isActive && $row['parent_id'] !== null) {
                $hiddenAncestor = $this->findHiddenAncestor((int) $row['parent_id']);
                if ($hiddenAncestor !== null) {
                    throw new DomainException(
                        "Không thể hiển thị danh mục con khi danh mục cha \"{$hiddenAncestor['name']}\" đang bị ẩn. Vui lòng hiện danh mục cha trước."
                    );
                }
            }

            $upd = $this->db->prepare("UPDATE {$this->table} SET is_active = :active, updated_at = NOW() WHERE id = :id");
            $upd->execute([
                ':active' => $isActive ? 1 : 0,
                ':id'     => $id,
            ]);

            $cascaded = 0;
            if (!$isActive) {
                $cascaded = $this->cascadeHideDescendants($id);
            }

            $this->db->commit();
            return $cascaded;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Chốt chặn nghiệp vụ: Không cho phép xóa cứng danh mục đang chứa sản phẩm hoặc đang có danh mục con.
     * Hệ thống ưu tiên thao tác Ẩn danh mục (`setCategoryStatus($id, false)`) để bảo toàn toàn vẹn tham chiếu.
     *
     * @param int $id Khóa chính danh mục.
     * @return void
     */
    public function deleteCategory(int $id): void
    {
        $row = $this->findById($id);
        if ($row === null) {
            throw new DomainException('Không tìm thấy danh mục.');
        }

        if ((int) ($row['direct_product_count'] ?? 0) > 0) {
            throw new DomainException('Không thể xóa cứng danh mục đang có sản phẩm. Vui lòng sử dụng chức năng Ẩn danh mục.');
        }

        if ((int) ($row['child_count'] ?? 0) > 0) {
            throw new DomainException('Không thể xóa cứng danh mục đang có danh mục con trực thuộc. Vui lòng sử dụng chức năng Ẩn danh mục.');
        }

        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Chuyển đổi chuỗi tiếng Việt có dấu sang Slug chuẩn SEO (`[a-z0-9-]`).
     *
     * @param string $text Tên danh mục hoặc chuỗi gốc.
     * @return string Chuỗi slug đã chuẩn hóa.
     */
    public static function toSlug(string $text): string
    {
        $str = mb_strtolower(trim($text), 'UTF-8');
        $map = [
            'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
            'đ' => 'd',
        ];
        $str = strtr($str, $map);
        $str = preg_replace('/[^a-z0-9]+/', '-', $str) ?? '';
        return trim($str, '-');
    }

    /**
     * Trải phẳng mảng danh mục thành danh sách phân cấp có thuộc tính `depth` (bắt đầu từ 1).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function flattenCategoryTree(array $rows, ?int $parentId = null, int $depth = 1, array &$visited = []): array
    {
        $result = [];
        foreach ($rows as $row) {
            $rowId       = (int) $row['id'];
            $rowParentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;

            if ($rowParentId !== $parentId || isset($visited[$rowId])) {
                continue;
            }

            $visited[$rowId] = true;
            $row['depth']    = $depth;
            $result[]        = $row;

            foreach ($this->flattenCategoryTree($rows, $rowId, $depth + 1, $visited) as $child) {
                $result[] = $child;
            }
        }

        // Phòng ngừa bản ghi mồ côi (nếu có) không bị bỏ sót khỏi giao diện quản trị
        if ($parentId === null) {
            foreach ($rows as $row) {
                $rowId = (int) $row['id'];
                if (!isset($visited[$rowId])) {
                    $visited[$rowId] = true;
                    $row['depth']    = 1;
                    $result[]        = $row;
                }
            }
        }

        return $result;
    }

    /**
     * Tính chiều cao tối đa của cây con bắt đầu từ `$rootId` (chính nó = 1).
     *
     * @param array<int, array<string, mixed>> $byId
     */
    private function calculateSubtreeHeight(array $byId, int $rootId, array $visited = []): int
    {
        if (isset($visited[$rootId])) {
            return 1;
        }
        $visited[$rootId] = true;

        $maxChildHeight = 0;
        foreach ($byId as $id => $row) {
            if ($row['parent_id'] !== null && (int) $row['parent_id'] === $rootId) {
                $h = $this->calculateSubtreeHeight($byId, (int) $id, $visited);
                if ($h > $maxChildHeight) {
                    $maxChildHeight = $h;
                }
            }
        }

        return 1 + $maxChildHeight;
    }

    /**
     * Thu thập toàn bộ ID con/cháu của một danh mục (có bảo vệ chống lặp vô hạn).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function collectDescendantIds(array $rows, int $parentId, array &$visited = []): array
    {
        $descendants = [];
        foreach ($rows as $row) {
            $rowId = (int) $row['id'];
            if ($row['parent_id'] !== null && (int) $row['parent_id'] === $parentId && !isset($visited[$rowId])) {
                $visited[$rowId] = true;
                $descendants[]   = $rowId;
                foreach ($this->collectDescendantIds($rows, $rowId, $visited) as $subId) {
                    $descendants[] = $subId;
                }
            }
        }
        return $descendants;
    }

    /**
     * Tìm tổ tiên gần nhất đang bị ẩn (`is_active = 0`) nếu có.
     *
     * @return array<string, mixed>|null
     */
    private function findHiddenAncestor(int $startParentId): ?array
    {
        $visited  = [];
        $cursorId = $startParentId;

        while ($cursorId > 0 && !isset($visited[$cursorId])) {
            $visited[$cursorId] = true;
            $row = $this->fetchOne("SELECT id, parent_id, name, is_active FROM {$this->table} WHERE id = :id LIMIT 1", [':id' => $cursorId]);
            if ($row === null) {
                return null;
            }
            if ((int) $row['is_active'] !== 1) {
                return $row;
            }
            $cursorId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        }

        return null;
    }

    /**
     * Ẩn dây chuyền toàn bộ danh mục con/cháu đang kích hoạt khi danh mục cha bị ẩn.
     *
     * @param int $parentId ID danh mục cha vừa bị ẩn.
     * @return int Số lượng danh mục con/cháu đã chuyển sang trạng thái ẩn.
     */
    private function cascadeHideDescendants(int $parentId): int
    {
        $rows        = $this->fetchAll("SELECT id, parent_id, is_active FROM {$this->table}");
        $descendants = $this->collectDescendantIds($rows, $parentId);
        if ($descendants === []) {
            return 0;
        }

        $placeholders = [];
        $params       = [':active' => 0];
        foreach ($descendants as $idx => $descId) {
            $ph                = ':id_' . $idx;
            $placeholders[]    = $ph;
            $params[$ph]       = $descId;
        }

        $inClause = implode(', ', $placeholders);
        $stmt     = $this->db->prepare("
            UPDATE {$this->table}
            SET is_active = :active, updated_at = NOW()
            WHERE id IN ({$inClause}) AND is_active = 1
        ");
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Xây dựng bản đồ các danh mục thực sự đang hoạt động (chính nó `is_active = 1`,
     * không có tổ tiên bị ẩn và không có chu trình lỗi trong dữ liệu).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<int, int>>}
     */
    private function buildActiveCategoryMap(array $rows): array
    {
        $rawById = [];
        foreach ($rows as $row) {
            $rawById[(int) $row['id']] = $row;
        }

        $activeById       = [];
        $childrenByParent = [];

        foreach ($rawById as $id => $row) {
            if ((int) ($row['is_active'] ?? 0) !== 1) {
                continue;
            }

            // Kiểm tra toàn bộ chuỗi tổ tiên, dùng $visited để chặn vòng lặp vô hạn nếu DB có dữ liệu bất thường
            $isChainValid = true;
            $visited      = [$id => true];
            $cursorId     = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;

            while ($cursorId > 0) {
                if (isset($visited[$cursorId]) || !isset($rawById[$cursorId])) {
                    $isChainValid = false;
                    break;
                }
                if ((int) ($rawById[$cursorId]['is_active'] ?? 0) !== 1) {
                    $isChainValid = false;
                    break;
                }
                $visited[$cursorId] = true;
                $parentRow          = $rawById[$cursorId];
                $cursorId           = $parentRow['parent_id'] !== null ? (int) $parentRow['parent_id'] : 0;
            }

            if (!$isChainValid) {
                continue;
            }

            $activeById[$id] = $row;
            if ($row['parent_id'] !== null) {
                $childrenByParent[(int) $row['parent_id']][] = $id;
            }
        }

        return [$activeById, $childrenByParent];
    }

    /**
     * Duyệt BFS từ `$rootId` để thu thập danh sách ID duy nhất gồm chính nó và các con/cháu đang hoạt động,
     * giới hạn tối đa `MAX_DEPTH` (3 cấp) và bảo vệ bằng `$visited` chống vòng lặp vô hạn.
     *
     * @param array<int, array<string, mixed>> $activeById
     * @param array<int, array<int, int>>      $childrenByParent
     * @return array<int, int>
     */
    private function collectActiveFamilyIdsFromMap(array $activeById, array $childrenByParent, int $rootId): array
    {
        if (!isset($activeById[$rootId])) {
            return [];
        }

        $visited = [$rootId => true];
        $queue   = [['id' => $rootId, 'depth' => 1]];
        $ids     = [$rootId];

        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current['depth'] >= self::MAX_DEPTH) {
                continue;
            }

            $currId = $current['id'];
            foreach ($childrenByParent[$currId] ?? [] as $childId) {
                if (!isset($activeById[$childId]) || isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $ids[]             = $childId;
                $queue[]           = ['id' => $childId, 'depth' => $current['depth'] + 1];
            }
        }

        return $ids;
    }
}
