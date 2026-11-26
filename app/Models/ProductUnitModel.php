<?php
/**
 * Model Quản lý Cá thể Máy theo Số Serial (`app/Models/ProductUnitModel.php`).
 *
 * Vai trò trong kiến trúc MVC:
 * - Quản lý bảng `product_units`: danh sách phân trang, tìm kiếm, lọc, xem chi tiết, nhập máy mới và cập nhật thông tin máy.
 * - Kiểm soát nghiêm ngặt quy tắc trạng thái máy (`available`, `reserved`, `sold`, `repairing`, `returned`)
 *   và điều kiện đủ tiêu chuẩn bán (đồng bộ với `OrderModel` và `ProductModel`).
 * - Bảo vệ giao dịch đồng thời bằng Transaction PDO và `SELECT ... FOR UPDATE`.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DomainException;
use PDO;
use Throwable;

class ProductUnitModel extends Model
{
    public const ALLOWED_STATUSES        = ['available', 'reserved', 'sold', 'repairing', 'returned'];
    public const ALLOWED_CREATE_STATUSES = ['available', 'repairing'];
    public const ALLOWED_GRADES          = ['Grade A', 'Grade B', 'Grade C'];
    public const MAX_IMPORT_PRICE        = 100000000000;

    protected string $table = 'product_units';

    /**
     * Lấy danh sách dòng sản phẩm quản lý theo Serial (`has_serials = 1`) phục vụ dropdown lọc/nhập máy.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSerialProducts(): array
    {
        return $this->fetchAll("
            SELECT id, name, sku, status
            FROM products
            WHERE has_serials = 1
            ORDER BY name ASC, id ASC
        ");
    }

    /**
     * Đếm tổng số máy vật lý khớp bộ lọc Admin.
     *
     * @param array<string, mixed> $filters
     */
    public function countAdminUnits(array $filters = []): int
    {
        [$whereClause, $bindings] = $this->buildAdminWhere($filters);

        $sql = "
            SELECT COUNT(*)
            FROM {$this->table} pu
            INNER JOIN products p ON pu.product_id = p.id
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
     * Lấy danh sách máy theo Serial phân trang phía server, kèm kết quả kiểm định mới nhất
     * (`ORDER BY pi.inspection_date DESC, pi.id DESC LIMIT 1`) và cờ `is_sellable`.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getAdminUnits(array $filters = [], int $limit = 15, int $offset = 0): array
    {
        $safeLimit  = max(1, min($limit, 50));
        $safeOffset = max(0, $offset);

        [$whereClause, $bindings] = $this->buildAdminWhere($filters);

        $sql = "
            SELECT pu.*,
                   p.name AS product_name,
                   p.sku AS product_sku,
                   p.status AS product_status,
                   (
                       SELECT pi.id
                       FROM product_inspections pi
                       WHERE pi.unit_id = pu.id
                       ORDER BY pi.inspection_date DESC, pi.id DESC
                       LIMIT 1
                   ) AS latest_inspection_id,
                   (
                       SELECT pi2.inspection_date
                       FROM product_inspections pi2
                       WHERE pi2.unit_id = pu.id
                       ORDER BY pi2.inspection_date DESC, pi2.id DESC
                       LIMIT 1
                   ) AS latest_inspection_date,
                   (
                       SELECT pi3.checklist_overall_result
                       FROM product_inspections pi3
                       WHERE pi3.unit_id = pu.id
                       ORDER BY pi3.inspection_date DESC, pi3.id DESC
                       LIMIT 1
                   ) AS latest_inspection_result,
                   (
                       SELECT COUNT(DISTINCT ii3.step_number)
                       FROM inspection_items ii3
                       WHERE ii3.inspection_id = (
                           SELECT pi_step.id
                           FROM product_inspections pi_step
                           WHERE pi_step.unit_id = pu.id
                           ORDER BY pi_step.inspection_date DESC, pi_step.id DESC
                           LIMIT 1
                       )
                   ) AS latest_inspection_steps_count,
                   (
                       SELECT COUNT(*)
                       FROM product_inspections pi4
                       WHERE pi4.unit_id = pu.id
                   ) AS inspections_count
            FROM {$this->table} pu
            INNER JOIN products p ON pu.product_id = p.id
            WHERE {$whereClause}
            ORDER BY pu.id DESC
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
        foreach ($rows as &$row) {
            $row['is_sellable'] = self::computeSellableFlag(
                (string) $row['status'],
                $row['latest_inspection_result'] !== null ? (string) $row['latest_inspection_result'] : null,
                (int) ($row['latest_inspection_steps_count'] ?? 0)
            );
        }
        unset($row);

        return $rows;
    }

    /**
     * Tìm chi tiết một máy vật lý theo ID kèm thông tin sản phẩm, số đơn hàng liên quan và kiểm định mới nhất.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->fetchOne("
            SELECT pu.*,
                   p.name AS product_name,
                   p.sku AS product_sku,
                   p.slug AS product_slug,
                   p.has_serials AS product_has_serials,
                   p.status AS product_status,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.product_unit_id = pu.id) AS order_items_count,
                   (SELECT COUNT(*) FROM product_inspections pi WHERE pi.unit_id = pu.id) AS inspections_count,
                   (
                       SELECT pi2.id
                       FROM product_inspections pi2
                       WHERE pi2.unit_id = pu.id
                       ORDER BY pi2.inspection_date DESC, pi2.id DESC
                       LIMIT 1
                   ) AS latest_inspection_id,
                   (
                       SELECT pi3.checklist_overall_result
                       FROM product_inspections pi3
                       WHERE pi3.unit_id = pu.id
                       ORDER BY pi3.inspection_date DESC, pi3.id DESC
                       LIMIT 1
                   ) AS latest_inspection_result,
                   (
                       SELECT pi4.inspection_date
                       FROM product_inspections pi4
                       WHERE pi4.unit_id = pu.id
                       ORDER BY pi4.inspection_date DESC, pi4.id DESC
                       LIMIT 1
                   ) AS latest_inspection_date,
                   (
                       SELECT COUNT(DISTINCT ii5.step_number)
                       FROM inspection_items ii5
                       WHERE ii5.inspection_id = (
                           SELECT pi5.id
                           FROM product_inspections pi5
                           WHERE pi5.unit_id = pu.id
                           ORDER BY pi5.inspection_date DESC, pi5.id DESC
                           LIMIT 1
                       )
                   ) AS latest_inspection_steps_count
            FROM {$this->table} pu
            INNER JOIN products p ON pu.product_id = p.id
            WHERE pu.id = :id
            LIMIT 1
        ", [':id' => $id]);

        if ($row === null) {
            return null;
        }

        $row['is_sellable'] = self::computeSellableFlag(
            (string) $row['status'],
            $row['latest_inspection_result'] !== null ? (string) $row['latest_inspection_result'] : null,
            (int) ($row['latest_inspection_steps_count'] ?? 0)
        );

        return $row;
    }

    /**
     * Điều kiện SQL chuẩn thống nhất cho một đơn vị serial đủ điều kiện bán công khai & phân bổ Checkout.
     *
     * Quy tắc bắt buộc:
     * - Trạng thái máy là `available`.
     * - Tồn tại biên bản kiểm định mới nhất (`inspection_date DESC, id DESC LIMIT 1`) có kết luận `passed`.
     * - Biên bản mới nhất đó phải có đủ 18 bước kiểm tra (`inspection_items`).
     */
    public static function sellableUnitWhereSql(string $unitAlias = 'pu'): string
    {
        return "
            {$unitAlias}.status = 'available'
            AND (
                SELECT pi_sell.checklist_overall_result
                FROM product_inspections pi_sell
                WHERE pi_sell.unit_id = {$unitAlias}.id
                ORDER BY pi_sell.inspection_date DESC, pi_sell.id DESC
                LIMIT 1
            ) = 'passed'
            AND (
                SELECT COUNT(DISTINCT ii_sell.step_number)
                FROM inspection_items ii_sell
                WHERE ii_sell.inspection_id = (
                    SELECT pi_sell_id.id
                    FROM product_inspections pi_sell_id
                    WHERE pi_sell_id.unit_id = {$unitAlias}.id
                    ORDER BY pi_sell_id.inspection_date DESC, pi_sell_id.id DESC
                    LIMIT 1
                )
            ) = 18
        ";
    }

    /**
     * Đồng bộ cache `products.stock_quantity` cho sản phẩm quản lý theo Serial (`has_serials = 1`)
     * ngay trong transaction hiện hành để đảm bảo đồng nhất giữa Storefront, Admin và Checkout.
     */
    public static function syncProductStockCache(PDO $db, int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        $sellableSql = self::sellableUnitWhereSql('pu_sync');
        $stmt        = $db->prepare("
            UPDATE products
            SET stock_quantity = (
                SELECT COUNT(*)
                FROM product_units pu_sync
                WHERE pu_sync.product_id = :pid_cnt
                  AND {$sellableSql}
            ), updated_at = NOW()
            WHERE id = :pid_upd
              AND has_serials = 1
        ");
        $stmt->execute([':pid_cnt' => $productId, ':pid_upd' => $productId]);
    }

    /**
     * Xác định máy có đủ điều kiện bán công khai/Checkout hay không (đồng bộ tuyệt đối với `OrderModel`, `ProductModel`, `AdminProductModel`).
     * Chỉ khi `status === 'available'`, biên bản kiểm định mới nhất là `'passed'` VÀ có đủ 18 bước.
     */
    public static function computeSellableFlag(string $unitStatus, ?string $latestInspectionResult, int $latestStepCount = 0): bool
    {
        if ($unitStatus !== 'available') {
            return false;
        }
        return $latestInspectionResult === 'passed' && $latestStepCount === 18;
    }

    /**
     * Kiểm tra số Serial đã tồn tại trên hệ thống chưa (tùy chọn loại trừ ID đang sửa).
     */
    public function serialExists(string $serial, ?int $excludeId = null): bool
    {
        $sql    = "SELECT 1 FROM {$this->table} WHERE serial_number = :serial";
        $params = [':serial' => $serial];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        return $this->fetchOne($sql . ' LIMIT 1', $params) !== null;
    }

    /**
     * Nhập kho một cá thể máy mới theo số Serial trong Transaction PDO.
     *
     * Ràng buộc:
     * - Chỉ cho phép thêm vào dòng sản phẩm có `has_serials = 1`.
     * - Số Serial phải duy nhất toàn hệ thống.
     * - Trạng thái ban đầu chỉ nhận `available` hoặc `repairing`.
     *
     * @param array<string, mixed> $data Dữ liệu đã validate từ Controller.
     * @return int ID máy vừa tạo.
     *
     * @throws DomainException
     */
    public function createUnit(array $data): int
    {
        return (int) $this->runTransactionWithDeadlockRetry(function () use ($data): int {
            $productId = (int) $data['product_id'];
            $product   = $this->fetchOne(
                "SELECT id, name, has_serials FROM products WHERE id = :id LIMIT 1 FOR UPDATE",
                [':id' => $productId]
            );
            if ($product === null) {
                throw new DomainException('Sản phẩm được chọn không tồn tại.');
            }
            if ((int) $product['has_serials'] !== 1) {
                throw new DomainException("Sản phẩm \"{$product['name']}\" quản lý theo số lượng (has_serials = 0), không thể nhập máy theo Serial.");
            }

            $serial = (string) $data['serial_number'];
            if ($this->serialExists($serial)) {
                throw new DomainException("Số Serial \"{$serial}\" đã tồn tại trong hệ thống.");
            }

            $status = (string) $data['status'];
            if (!in_array($status, self::ALLOWED_CREATE_STATUSES, true)) {
                throw new DomainException('Trạng thái khởi tạo của máy chỉ được là "available" hoặc "repairing".');
            }

            $stmt = $this->db->prepare("
                INSERT INTO {$this->table} (
                    product_id, serial_number, hardware_condition_detail, battery_health,
                    cosmetic_grade, status, import_price, imported_at, created_at, updated_at
                ) VALUES (
                    :product_id, :serial_number, :hardware_detail, :battery_health,
                    :cosmetic_grade, :status, :import_price, NOW(), NOW(), NOW()
                )
            ");
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':serial_number', $serial, PDO::PARAM_STR);
            $hw = (string) ($data['hardware_condition_detail'] ?? '');
            $stmt->bindValue(':hardware_detail', $hw !== '' ? $hw : null, $hw !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);

            if ($data['battery_health'] !== null) {
                $stmt->bindValue(':battery_health', (int) $data['battery_health'], PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':battery_health', null, PDO::PARAM_NULL);
            }

            $stmt->bindValue(':cosmetic_grade', (string) $data['cosmetic_grade'], PDO::PARAM_STR);
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);

            if ($data['import_price'] !== null) {
                $stmt->bindValue(':import_price', (int) $data['import_price'], PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':import_price', null, PDO::PARAM_NULL);
            }

            $stmt->execute();
            $unitId = (int) $this->db->lastInsertId();

            self::syncProductStockCache($this->db, $productId);

            return $unitId;
        });
    }

    /**
     * Cập nhật thông tin máy vật lý trong Transaction PDO (`FOR UPDATE`) với kiểm soát trạng thái nghiêm ngặt.
     *
     * Quy tắc bảo vệ:
     * - Không cho đổi `product_id` nếu máy đã có kiểm định hoặc đơn hàng.
     * - Không cho sửa `serial_number` nếu máy đang `reserved`, `sold` hoặc đã gắn đơn hàng (`order_items`).
     * - Không cho chuyển `reserved`/`sold` sang trạng thái khác hoặc đặt tự do thành `reserved`/`sold`.
     * - Không cho chuyển `repairing` hoặc `returned` sang `available` nếu chưa đáp ứng chính sách kiểm định lại.
     * - Hỗ trợ khóa lạc quan `expected_updated_at` chống ghi đè khi hai Admin sửa đồng thời hoặc Checkout vừa giữ máy.
     *
     * @param int                  $id   ID máy vật lý.
     * @param array<string, mixed> $data Dữ liệu đã validate.
     * @return void
     *
     * @throws DomainException
     */
    public function updateUnit(int $id, array $data): void
    {
        $expectedRowVersion = isset($data['expected_row_version']) && is_numeric($data['expected_row_version'])
            ? (int) $data['expected_row_version']
            : 0;
        if ($expectedRowVersion <= 0) {
            throw new DomainException('Thiếu phiên bản khóa lạc quan (expected_row_version) để chống ghi đè đồng thời.');
        }

        $newProductId = (int) $data['product_id'];

        $this->runTransactionWithDeadlockRetry(function () use ($id, $data, $expectedRowVersion, $newProductId): void {
            // Đọc product_id hiện tại (chưa khóa) để xác định tập products cần khóa theo thứ tự ID tăng dần,
            // đảm bảo thứ tự khóa nhất quán: products (id ASC) -> product_units -> tránh deadlock với Checkout.
            $preUnit = $this->fetchOne("SELECT product_id FROM {$this->table} WHERE id = :id LIMIT 1", [':id' => $id]);
            if ($preUnit === null) {
                throw new DomainException('Không tìm thấy cá thể máy cần cập nhật.');
            }

            $preProductId   = (int) $preUnit['product_id'];
            $lockProductIds = array_values(array_unique(array_filter(
                [$preProductId, $newProductId],
                static fn(int $pid): bool => $pid > 0
            )));
            sort($lockProductIds, SORT_NUMERIC);

            $lockedProducts = [];
            foreach ($lockProductIds as $lpid) {
                $pRow = $this->fetchOne(
                    "SELECT id, has_serials FROM products WHERE id = :pid LIMIT 1 FOR UPDATE",
                    [':pid' => $lpid]
                );
                if ($pRow !== null) {
                    $lockedProducts[$lpid] = $pRow;
                }
            }

            $locked = $this->fetchOne(
                "SELECT * FROM {$this->table} WHERE id = :id LIMIT 1 FOR UPDATE",
                [':id' => $id]
            );
            if ($locked === null) {
                throw new DomainException('Không tìm thấy cá thể máy cần cập nhật.');
            }

            // Nếu một phiên khác vừa đổi product_id của máy ngay trước khi lấy khóa -> row_version cũng đã đổi, chặn ngay
            if ((int) ($locked['row_version'] ?? 0) !== $expectedRowVersion || (int) $locked['product_id'] !== $preProductId) {
                throw new DomainException(
                    'Thông tin hoặc trạng thái máy vừa được thay đổi bởi một phiên làm việc hoặc đơn hàng khác. Vui lòng tải lại trang trước khi lưu.'
                );
            }

            $orderCount      = (int) $this->fetchColumn("SELECT COUNT(*) FROM order_items WHERE product_unit_id = :uid", [':uid' => $id]);
            $inspectionCount = (int) $this->fetchColumn("SELECT COUNT(*) FROM product_inspections WHERE unit_id = :uid", [':uid' => $id]);

            $oldProductId = (int) $locked['product_id'];
            if ($newProductId !== $oldProductId) {
                if ($orderCount > 0 || $inspectionCount > 0) {
                    throw new DomainException('Không thể đổi dòng sản phẩm vì máy đã có lịch sử kiểm định hoặc đơn hàng liên quan.');
                }
                $targetProd = $lockedProducts[$newProductId] ?? null;
                if ($targetProd === null || (int) $targetProd['has_serials'] !== 1) {
                    throw new DomainException('Dòng sản phẩm đích không hợp lệ hoặc không quản lý theo Serial.');
                }
            }

            $oldStatus = (string) $locked['status'];
            $oldSerial = (string) $locked['serial_number'];
            $newSerial = (string) $data['serial_number'];

            if ($newSerial !== $oldSerial) {
                if ($oldStatus === 'reserved' || $oldStatus === 'sold' || $orderCount > 0) {
                    throw new DomainException('Không được phép sửa số Serial của máy đang giữ đơn, đã bán hoặc đã gắn với đơn hàng.');
                }
                if ($this->serialExists($newSerial, $id)) {
                    throw new DomainException("Số Serial \"{$newSerial}\" đã thuộc về một máy khác.");
                }
            }

            $newStatus = (string) $data['status'];
            if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
                throw new DomainException('Trạng thái máy không hợp lệ.');
            }

            if ($newStatus !== $oldStatus) {
                if ($oldStatus === 'reserved' || $oldStatus === 'sold') {
                    throw new DomainException(
                        "Không thể tự ý đổi trạng thái máy đang ở trạng thái \"{$oldStatus}\" trong mục quản lý Serial (do quy trình Đơn hàng quản lý)."
                    );
                }

                if ($newStatus === 'reserved' || $newStatus === 'sold') {
                    throw new DomainException('Không được phép chuyển thủ công trạng thái máy sang "reserved" hoặc "sold".');
                }

                if (($oldStatus === 'repairing' || $oldStatus === 'returned') && $newStatus === 'available') {
                    $latestInsp = $this->fetchOne("
                        SELECT pi.id, pi.checklist_overall_result, pi.inspection_date, pi.created_at,
                               (SELECT COUNT(DISTINCT ii.step_number) FROM inspection_items ii WHERE ii.inspection_id = pi.id) AS steps_count
                        FROM product_inspections pi
                        WHERE pi.unit_id = :uid
                        ORDER BY pi.inspection_date DESC, pi.id DESC
                        LIMIT 1
                    ", [':uid' => $id]);

                    if (
                        $latestInsp === null
                        || (string) $latestInsp['checklist_overall_result'] !== 'passed'
                        || (int) ($latestInsp['steps_count'] ?? 0) !== 18
                    ) {
                        throw new DomainException(
                            'Không thể chuyển máy từ "' . $oldStatus . '" sang "available" khi chưa có biên bản kiểm định 18 bước mới nhất đạt chuẩn (passed). Kết quả failed hoặc conditional_pass không đủ điều kiện bán.'
                        );
                    }

                    // Nếu máy đang ở trạng thái returned -> bắt buộc phải có biên bản kiểm định MỚI được lập sau mốc hoàn trả
                    if ($oldStatus === 'returned') {
                        $baselineId = (int) ($locked['returned_baseline_inspection_id'] ?? 0);
                        if ((int) $latestInsp['id'] <= $baselineId) {
                            throw new DomainException(
                                'Máy trả về (returned) bắt buộc phải có biên bản kiểm định mới sau thời điểm hoàn trả; không được dùng biên bản cũ để mở bán lại.'
                            );
                        }
                        if (!empty($locked['returned_at']) && strtotime((string) $latestInsp['created_at']) < strtotime((string) $locked['returned_at'])) {
                            throw new DomainException(
                                'Máy trả về (returned) bắt buộc phải thực hiện kiểm định lại sau thời điểm hoàn trả trước khi mở bán.'
                            );
                        }
                    }
                }
            }

            // Xác định mốc hoàn trả khi chuyển máy sang trạng thái `returned`
            $setReturnedClause = '';
            $returnedBaseline  = null;
            if ($newStatus === 'returned' && $oldStatus !== 'returned') {
                $returnedBaseline  = (int) $this->fetchColumn(
                    "SELECT COALESCE(MAX(id), 0) FROM product_inspections WHERE unit_id = :uid",
                    [':uid' => $id]
                );
                $setReturnedClause = ', returned_at = NOW(), returned_baseline_inspection_id = :returned_baseline_id';
            }

            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET product_id = :product_id,
                    serial_number = :serial_number,
                    hardware_condition_detail = :hardware_detail,
                    battery_health = :battery_health,
                    cosmetic_grade = :cosmetic_grade,
                    status = :status,
                    import_price = :import_price,
                    row_version = row_version + 1,
                    updated_at = NOW()
                    {$setReturnedClause}
                WHERE id = :id
            ");
            $stmt->bindValue(':product_id', $newProductId, PDO::PARAM_INT);
            $stmt->bindValue(':serial_number', $newSerial, PDO::PARAM_STR);
            $hw = (string) ($data['hardware_condition_detail'] ?? '');
            $stmt->bindValue(':hardware_detail', $hw !== '' ? $hw : null, $hw !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);

            if ($data['battery_health'] !== null) {
                $stmt->bindValue(':battery_health', (int) $data['battery_health'], PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':battery_health', null, PDO::PARAM_NULL);
            }

            $stmt->bindValue(':cosmetic_grade', (string) $data['cosmetic_grade'], PDO::PARAM_STR);
            $stmt->bindValue(':status', $newStatus, PDO::PARAM_STR);

            if ($data['import_price'] !== null) {
                $stmt->bindValue(':import_price', (int) $data['import_price'], PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':import_price', null, PDO::PARAM_NULL);
            }

            if ($returnedBaseline !== null) {
                $stmt->bindValue(':returned_baseline_id', $returnedBaseline, PDO::PARAM_INT);
            }

            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            self::syncProductStockCache($this->db, $newProductId);
            if ($newProductId !== $oldProductId) {
                self::syncProductStockCache($this->db, $oldProductId);
            }
        });
    }

    /**
     * Xây dựng mệnh đề WHERE và tham số PDO cho danh sách Serial Admin.
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
            $conditions[] = '(pu.serial_number LIKE :kw_serial OR p.sku LIKE :kw_sku OR p.name LIKE :kw_name)';
            $bindings[':kw_serial'] = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_sku']    = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
            $bindings[':kw_name']   = ['val' => $likeVal, 'type' => PDO::PARAM_STR];
        }

        $productId = (int) ($filters['product_id'] ?? 0);
        if ($productId > 0) {
            $conditions[]             = 'pu.product_id = :product_id';
            $bindings[':product_id']  = ['val' => $productId, 'type' => PDO::PARAM_INT];
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::ALLOWED_STATUSES, true)) {
            $conditions[]         = 'pu.status = :status';
            $bindings[':status']  = ['val' => $status, 'type' => PDO::PARAM_STR];
        }

        $grade = trim((string) ($filters['cosmetic_grade'] ?? ''));
        if ($grade !== '' && in_array($grade, self::ALLOWED_GRADES, true)) {
            $conditions[]        = 'pu.cosmetic_grade = :grade';
            $bindings[':grade']  = ['val' => $grade, 'type' => PDO::PARAM_STR];
        }

        $inspStatus = trim((string) ($filters['inspection_status'] ?? ''));
        if ($inspStatus === 'none') {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM product_inspections pi_none WHERE pi_none.unit_id = pu.id)';
        } elseif (in_array($inspStatus, InspectionModel::ALLOWED_OVERALL_RESULTS, true)) {
            $conditions[] = "(
                SELECT pi_flt.checklist_overall_result
                FROM product_inspections pi_flt
                WHERE pi_flt.unit_id = pu.id
                ORDER BY pi_flt.inspection_date DESC, pi_flt.id DESC
                LIMIT 1
            ) = :insp_status";
            $bindings[':insp_status'] = ['val' => $inspStatus, 'type' => PDO::PARAM_STR];
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * Đối soát và đồng bộ tồn kho cache (`products.stock_quantity`) cho toàn bộ sản phẩm `has_serials = 1`.
     * Luôn duyệt và khóa theo thứ tự `products.id ASC`, không chạm sản phẩm `has_serials = 0` và không tạo kiểm định giả.
     *
     * @param bool $apply `false` = chỉ kiểm tra (--dry-run), `true` = cập nhật DB (--apply).
     * @return array<int, array<string, mixed>>
     */
    public function reconcileAllSerialStockCache(bool $apply = false): array
    {
        $products = $this->db->query(
            'SELECT id, sku, name, stock_quantity FROM products WHERE has_serials = 1 ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        if (!$products) {
            return [];
        }

        $sellableWhere = self::sellableUnitWhereSql('pu');
        $results       = [];

        foreach ($products as $product) {
            $productId = (int) $product['id'];

            if (!$apply) {
                $cntStmt = $this->db->prepare("
                    SELECT COUNT(*)
                    FROM product_units pu
                    WHERE pu.product_id = :pid
                      AND {$sellableWhere}
                ");
                $cntStmt->execute([':pid' => $productId]);
                $actualCount = (int) $cntStmt->fetchColumn();
                $oldStock    = (int) $product['stock_quantity'];

                $results[] = [
                    'product_id'      => $productId,
                    'sku'             => (string) $product['sku'],
                    'name'            => (string) $product['name'],
                    'old_stock'       => $oldStock,
                    'actual_sellable' => $actualCount,
                    'changed'         => $oldStock !== $actualCount,
                    'updated'         => false,
                ];
                continue;
            }

            $txOutcome = $this->runTransactionWithDeadlockRetry(function () use ($productId, $sellableWhere): array {
                // Khóa dòng products trước theo thứ tự id ASC để đồng nhất với Checkout/Admin
                $lockStmt = $this->db->prepare(
                    'SELECT id, sku, name, stock_quantity FROM products WHERE id = :pid AND has_serials = 1 LIMIT 1 FOR UPDATE'
                );
                $lockStmt->execute([':pid' => $productId]);
                $lockedProduct = $lockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$lockedProduct) {
                    return [
                        'skipped'         => true,
                        'product_id'      => $productId,
                        'sku'             => '',
                        'name'            => '',
                        'old_stock'       => 0,
                        'actual_sellable' => 0,
                        'changed'         => false,
                        'updated'         => false,
                    ];
                }

                $cntStmt = $this->db->prepare("
                    SELECT COUNT(*)
                    FROM product_units pu
                    WHERE pu.product_id = :pid
                      AND {$sellableWhere}
                ");
                $cntStmt->execute([':pid' => $productId]);
                $actualCount = (int) $cntStmt->fetchColumn();
                $oldStock    = (int) $lockedProduct['stock_quantity'];
                $changed     = $oldStock !== $actualCount;

                if ($changed) {
                    $updStmt = $this->db->prepare('UPDATE products SET stock_quantity = :qty WHERE id = :pid');
                    $updStmt->execute([
                        ':qty' => $actualCount,
                        ':pid' => $productId,
                    ]);
                }

                return [
                    'skipped'         => false,
                    'product_id'      => $productId,
                    'sku'             => (string) $lockedProduct['sku'],
                    'name'            => (string) $lockedProduct['name'],
                    'old_stock'       => $oldStock,
                    'actual_sellable' => $actualCount,
                    'changed'         => $changed,
                    'updated'         => $changed,
                ];
            });

            if (empty($txOutcome['skipped'])) {
                $results[] = $txOutcome;
            }
        }

        return $results;
    }
}
