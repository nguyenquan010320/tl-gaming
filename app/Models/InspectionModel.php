<?php
/**
 * Model Quản lý Biên bản Kiểm định Kỹ thuật 18 bước (`app/Models/InspectionModel.php`).
 *
 * Vai trò trong kiến trúc MVC:
 * - Cung cấp danh sách 18 bước kiểm định chuẩn tương thích với `database/seed_tl_gaming.sql`.
 * - Lưu biên bản mới vào `product_inspections` và đầy đủ 18 dòng vào `inspection_items` trong một Transaction PDO.
 * - Tính toán và xác minh kết luận tổng thể (`passed`, `conditional_pass`, `failed`) hoàn toàn trên Server.
 * - Tuyệt đối không ghi đè hoặc xóa lịch sử kiểm định cũ; xác định biên bản mới nhất theo `inspection_date DESC, id DESC`.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DomainException;
use PDO;
use Throwable;

class InspectionModel extends Model
{
    /**
     * Kết quả tổng thể hợp lệ của bảng `product_inspections`.
     */
    public const ALLOWED_OVERALL_RESULTS = ['passed', 'conditional_pass', 'failed'];

    /**
     * Kết quả từng bước hợp lệ của bảng `inspection_items`.
     */
    public const ALLOWED_ITEM_RESULTS = ['pass', 'fail', 'warning', 'not_applicable'];

    /**
     * Danh sách 18 bước kiểm định chuẩn (giữ nguyên tên nhóm và hạng mục từ `database/seed_tl_gaming.sql`).
     *
     * @var array<int, array{step_number: int, group_name: string, item_name: string}>
     */
    public const OFFICIAL_18_STEPS = [
        1  => ['step_number' => 1,  'group_name' => 'Ngoại quan & Thân vỏ',   'item_name' => 'Kiểm tra vết cấn móp, trầy xước, nứt vỡ khung vỏ'],
        2  => ['step_number' => 2,  'group_name' => 'Ngoại quan & Thân vỏ',   'item_name' => 'Kiểm tra bản lề, độ gập mở, độ rơ và chân đế cao su'],
        3  => ['step_number' => 3,  'group_name' => 'Ngoại quan & Thân vỏ',   'item_name' => 'Kiểm tra tem bảo hành niêm phong, ốc vít nguyên bản'],
        4  => ['step_number' => 4,  'group_name' => 'Màn hình & Hiển thị',    'item_name' => 'Kiểm tra điểm chết màn hình (Dead/Stuck Pixel test)'],
        5  => ['step_number' => 5,  'group_name' => 'Màn hình & Hiển thị',    'item_name' => 'Kiểm tra hở sáng viền, đốm phản quang, ám ố màu sắc'],
        6  => ['step_number' => 6,  'group_name' => 'Màn hình & Hiển thị',    'item_name' => 'Kiểm tra tần số quét chuẩn và độ sáng tối đa'],
        7  => ['step_number' => 7,  'group_name' => 'Hiệu năng & Tản nhiệt',  'item_name' => 'Stress test CPU Intel/AMD bằng Cinebench / AIDA64'],
        8  => ['step_number' => 8,  'group_name' => 'Hiệu năng & Tản nhiệt',  'item_name' => 'Stress test Card đồ họa GPU bằng Furmark / 3DMark'],
        9  => ['step_number' => 9,  'group_name' => 'Hiệu năng & Tản nhiệt',  'item_name' => 'Kiểm tra hệ thống tản nhiệt và độ ồn của quạt làm mát'],
        10 => ['step_number' => 10, 'group_name' => 'Bộ nhớ & Lưu trữ',       'item_name' => 'Kiểm tra lỗi bộ nhớ RAM bằng MemTest86'],
        11 => ['step_number' => 11, 'group_name' => 'Bộ nhớ & Lưu trữ',       'item_name' => 'Kiểm tra sức khỏe ổ cứng SSD/HDD bằng CrystalDiskInfo'],
        12 => ['step_number' => 12, 'group_name' => 'Bộ nhớ & Lưu trữ',       'item_name' => 'Đo tốc độ đọc/ghi thực tế ổ cứng SSD bằng CrystalDiskMark'],
        13 => ['step_number' => 13, 'group_name' => 'Bàn phím & Điều khiển',  'item_name' => 'Kiểm tra toàn bộ phím bấm và độ nảy bàn phím'],
        14 => ['step_number' => 14, 'group_name' => 'Bàn phím & Điều khiển',  'item_name' => 'Kiểm tra Touchpad cảm ứng đa điểm và phím chuột'],
        15 => ['step_number' => 15, 'group_name' => 'Pin & Nguồn điện',       'item_name' => 'Kiểm tra độ chai pin thực tế và số chu kỳ sạc'],
        16 => ['step_number' => 16, 'group_name' => 'Pin & Nguồn điện',       'item_name' => 'Kiểm tra khả năng sạc nhanh và độ ổn định bộ sạc adapter'],
        17 => ['step_number' => 17, 'group_name' => 'Kết nối & Ngoại vi',     'item_name' => 'Kiểm tra các cổng kết nối ngoại vi USB, Type-C, HDMI, LAN'],
        18 => ['step_number' => 18, 'group_name' => 'Âm thanh & Camera',      'item_name' => 'Kiểm tra hệ thống Loa ngoài, Micro thu âm và Webcam'],
    ];

    protected string $table = 'product_inspections';

    /**
     * Lấy danh sách 18 bước kiểm định chuẩn.
     *
     * @return array<int, array{step_number: int, group_name: string, item_name: string}>
     */
    public static function getOfficialChecklist(): array
    {
        return self::OFFICIAL_18_STEPS;
    }

    /**
     * Lấy lịch sử toàn bộ biên bản kiểm định của một máy (`unit_id`),
     * sắp xếp theo thời gian kiểm định giảm dần và ID giảm dần (`inspection_date DESC, id DESC`).
     *
     * @param int $unitId ID cá thể máy (`product_units.id`).
     * @return array<int, array<string, mixed>>
     */
    public function getInspectionsByUnitId(int $unitId): array
    {
        if ($unitId <= 0) {
            return [];
        }

        $sql = "
            SELECT pi.id,
                   pi.unit_id,
                   pi.inspector_id,
                   pi.inspection_date,
                   pi.checklist_overall_result,
                   pi.notes,
                   pi.created_at,
                   u.full_name AS inspector_name,
                   u.email AS inspector_email,
                   (SELECT COUNT(*) FROM inspection_items ii WHERE ii.inspection_id = pi.id AND ii.result = 'pass') AS pass_count,
                   (SELECT COUNT(*) FROM inspection_items ii WHERE ii.inspection_id = pi.id AND ii.result = 'warning') AS warning_count,
                   (SELECT COUNT(*) FROM inspection_items ii WHERE ii.inspection_id = pi.id AND ii.result = 'fail') AS fail_count,
                   (SELECT COUNT(*) FROM inspection_items ii WHERE ii.inspection_id = pi.id AND ii.result = 'not_applicable') AS na_count
            FROM {$this->table} pi
            LEFT JOIN users u ON pi.inspector_id = u.id
            WHERE pi.unit_id = :unit_id
            ORDER BY pi.inspection_date DESC, pi.id DESC
        ";

        return $this->fetchAll($sql, [':unit_id' => $unitId]);
    }

    /**
     * Lấy chi tiết một biên bản kiểm định kèm thông tin máy, sản phẩm và đủ 18 bước `inspection_items`.
     *
     * @param int $inspectionId ID biên bản kiểm định.
     * @return array<string, mixed>|null
     */
    public function findInspectionWithItems(int $inspectionId): ?array
    {
        if ($inspectionId <= 0) {
            return null;
        }

        $inspection = $this->fetchOne("
            SELECT pi.*,
                   u.full_name AS inspector_name,
                   pu.serial_number,
                   pu.cosmetic_grade,
                   pu.battery_health,
                   pu.status AS unit_status,
                   p.id AS product_id,
                   p.name AS product_name,
                   p.sku AS product_sku
            FROM {$this->table} pi
            INNER JOIN product_units pu ON pi.unit_id = pu.id
            INNER JOIN products p ON pu.product_id = p.id
            LEFT JOIN users u ON pi.inspector_id = u.id
            WHERE pi.id = :id
            LIMIT 1
        ", [':id' => $inspectionId]);

        if ($inspection === null) {
            return null;
        }

        $items = $this->fetchAll("
            SELECT id, inspection_id, step_number, group_name, item_name, result, notes, created_at
            FROM inspection_items
            WHERE inspection_id = :inspection_id
            ORDER BY step_number ASC
        ", [':inspection_id' => $inspectionId]);

        $inspection['items'] = $items;
        return $inspection;
    }

    /**
     * Tạo mới một biên bản kiểm định đầy đủ 18 bước trong Transaction PDO (`FOR UPDATE`).
     *
     * Quy tắc kiểm tra trên Server:
     * 1. Bắt buộc đủ đúng 18 bước (1..18), không thiếu, không trùng lặp, mỗi bước thuộc `ALLOWED_ITEM_RESULTS`.
     * 2. Không chấp nhận cả 18 bước đều là `not_applicable` (phải có ít nhất 1 bước được kiểm tra thực tế).
     * 3. Quy tắc kết luận tổng thể:
     *    - Có >= 1 bước `fail` -> bắt buộc `failed` (không thể duyệt `passed` hoặc `conditional_pass`).
     *    - Có 0 `fail` và >= 1 `warning` -> không thể duyệt `passed`; được phép `conditional_pass` (bắt buộc có ghi chú lý do) hoặc `failed`.
     *    - Có 0 `fail` và 0 `warning` -> hợp lệ cho `passed` (hoặc `failed` nếu kỹ thuật viên ghi chú từ chối).
     * 4. Nếu máy đang `reserved` hoặc `sold`, tuyệt đối giữ nguyên trạng thái đơn hàng, không can thiệp.
     * 5. Nếu `$markAvailableIfPassed = true` và máy đang `repairing`/`returned` và biên bản đạt `passed`/`conditional_pass` -> chuyển sang `available`.
     *
     * @param int                              $unitId                  ID máy vật lý.
     * @param int                              $inspectorId             ID Admin đang đăng nhập (lấy từ `Auth::id()`).
     * @param string                           $requestedResult         Kết luận tổng thể Admin đề xuất.
     * @param string                           $notes                   Ghi chú tổng thể.
     * @param array<int, array<string, mixed>> $items                   Danh sách 18 bước đã chuẩn hóa.
     * @param bool                             $markAvailableIfPassed   Có chuyển máy `repairing`/`returned` sang `available` nếu đạt không.
     * @return array{inspection_id: int, overall_result: string, unit_status: string}
     *
     * @throws DomainException Khi thiếu bước, sai kết quả hoặc vi phạm quy tắc phê duyệt.
     */
    public function createInspection(
        int $unitId,
        int $inspectorId,
        string $requestedResult,
        string $notes,
        array $items,
        bool $markAvailableIfPassed = false
    ): array {
        if ($unitId <= 0 || $inspectorId <= 0) {
            throw new DomainException('Thông tin máy hoặc kiểm định viên không hợp lệ.');
        }

        if (!in_array($requestedResult, self::ALLOWED_OVERALL_RESULTS, true)) {
            throw new DomainException('Kết luận tổng thể không hợp lệ.');
        }

        $notes = trim($notes);

        if (count($items) !== 18) {
            throw new DomainException('Biên bản kiểm định bắt buộc phải có đầy đủ cả 18 bước.');
        }

        $seenSteps  = [];
        $passCount  = 0;
        $warnCount  = 0;
        $failCount  = 0;
        $naCount    = 0;
        $cleanItems = [];

        foreach ($items as $item) {
            $step = (int) ($item['step_number'] ?? 0);
            $res  = (string) ($item['result'] ?? '');
            $note = trim((string) ($item['notes'] ?? ''));

            if ($step < 1 || $step > 18 || !isset(self::OFFICIAL_18_STEPS[$step])) {
                throw new DomainException("Số thứ tự bước kiểm định không hợp lệ (chỉ từ 1 đến 18): {$step}.");
            }
            if (isset($seenSteps[$step])) {
                throw new DomainException("Bước kiểm định số {$step} bị trùng lặp.");
            }
            if (!in_array($res, self::ALLOWED_ITEM_RESULTS, true)) {
                throw new DomainException("Kết quả của bước {$step} không hợp lệ.");
            }
            if (mb_strlen($note, 'UTF-8') > 255) {
                throw new DomainException("Ghi chú của bước {$step} không được vượt quá 255 ký tự.");
            }

            $seenSteps[$step] = true;
            match ($res) {
                'pass'           => $passCount++,
                'warning'        => $warnCount++,
                'fail'           => $failCount++,
                'not_applicable' => $naCount++,
            };

            $official = self::OFFICIAL_18_STEPS[$step];
            $cleanItems[$step] = [
                'step_number' => $step,
                'group_name'  => $official['group_name'],
                'item_name'   => $official['item_name'],
                'result'      => $res,
                'notes'       => $note,
            ];
        }

        if (count($seenSteps) !== 18) {
            throw new DomainException('Danh sách bước kiểm định phải đủ từ bước 1 đến bước 18.');
        }

        if ($naCount === 18) {
            throw new DomainException('Không thể lưu biên bản khi cả 18 bước đều là Không áp dụng.');
        }

        // Xác minh kết luận tổng thể trên Server theo chính sách chất lượng
        if ($failCount > 0 && $requestedResult !== 'failed') {
            throw new DomainException(
                "Có {$failCount} bước kiểm định KHÔNG ĐẠT (fail). Hệ thống chỉ cho phép kết luận tổng thể là \"failed\"."
            );
        }

        if ($warnCount > 0 && $requestedResult === 'passed') {
            throw new DomainException(
                "Có {$warnCount} bước CẢNH BÁO (warning). Không thể chọn \"passed\" hoàn toàn; vui lòng chọn \"conditional_pass\" kèm ghi chú hoặc \"failed\"."
            );
        }

        if ($requestedResult === 'conditional_pass') {
            if ($warnCount === 0) {
                throw new DomainException('Chỉ chọn "conditional_pass" khi có ít nhất 1 bước cảnh báo (warning).');
            }
            if ($notes === '') {
                throw new DomainException('Khi phê duyệt "conditional_pass", bắt buộc nhập ghi chú điều kiện/lý do.');
            }
        }

        if (mb_strlen($notes, 'UTF-8') > 2000) {
            throw new DomainException('Ghi chú tổng thể không được vượt quá 2.000 ký tự.');
        }

        return $this->runTransactionWithDeadlockRetry(function () use (
            $unitId,
            $inspectorId,
            $requestedResult,
            $notes,
            $cleanItems,
            $markAvailableIfPassed
        ): array {
            // Khóa theo thứ tự nhất quán: products -> product_units -> product_inspections -> inspection_items
            // nhằm tránh deadlock khi chạy song song với Checkout (`OrderModel`) hoặc cập nhật Serial (`ProductUnitModel`).
            $preUnit = $this->fetchOne(
                "SELECT product_id FROM product_units WHERE id = :id LIMIT 1",
                [':id' => $unitId]
            );
            if ($preUnit === null) {
                throw new DomainException('Không tìm thấy máy vật lý cần kiểm định.');
            }
            $preProductId = (int) $preUnit['product_id'];

            $this->fetchOne(
                "SELECT id FROM products WHERE id = :pid LIMIT 1 FOR UPDATE",
                [':pid' => $preProductId]
            );

            $lockedUnit = $this->fetchOne(
                "SELECT id, product_id, status FROM product_units WHERE id = :id LIMIT 1 FOR UPDATE",
                [':id' => $unitId]
            );
            if ($lockedUnit === null) {
                throw new DomainException('Không tìm thấy máy vật lý cần kiểm định.');
            }

            // Nếu máy vừa được chuyển sang product_id khác ngay trước khi lấy khóa, ném lỗi xung đột để đảm bảo thứ tự khóa chuẩn
            if ((int) $lockedUnit['product_id'] !== $preProductId) {
                throw new DomainException('Sản phẩm gắn với máy vừa bị thay đổi đồng thời. Vui lòng thử lại.');
            }

            $insInspection = $this->db->prepare("
                INSERT INTO {$this->table} (unit_id, inspector_id, inspection_date, checklist_overall_result, notes, created_at)
                VALUES (:unit_id, :inspector_id, NOW(), :overall, :notes, NOW())
            ");
            $insInspection->bindValue(':unit_id', $unitId, PDO::PARAM_INT);
            $insInspection->bindValue(':inspector_id', $inspectorId, PDO::PARAM_INT);
            $insInspection->bindValue(':overall', $requestedResult, PDO::PARAM_STR);
            $insInspection->bindValue(':notes', $notes !== '' ? $notes : null, $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insInspection->execute();

            $inspectionId = (int) $this->db->lastInsertId();

            $insItem = $this->db->prepare("
                INSERT INTO inspection_items (inspection_id, step_number, group_name, item_name, result, notes, created_at)
                VALUES (:inspection_id, :step_number, :group_name, :item_name, :result, :notes, NOW())
            ");

            ksort($cleanItems);
            foreach ($cleanItems as $cItem) {
                $insItem->bindValue(':inspection_id', $inspectionId, PDO::PARAM_INT);
                $insItem->bindValue(':step_number', $cItem['step_number'], PDO::PARAM_INT);
                $insItem->bindValue(':group_name', $cItem['group_name'], PDO::PARAM_STR);
                $insItem->bindValue(':item_name', $cItem['item_name'], PDO::PARAM_STR);
                $insItem->bindValue(':result', $cItem['result'], PDO::PARAM_STR);
                $insItem->bindValue(':notes', $cItem['notes'] !== '' ? $cItem['notes'] : null, $cItem['notes'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insItem->execute();
            }

            $currentUnitStatus = (string) $lockedUnit['status'];
            $finalUnitStatus   = $currentUnitStatus;

            // Tuyệt đối không thay đổi trạng thái của máy đang reserved hoặc sold (bảo vệ đơn hàng).
            // Chỉ mở bán lại khi biên bản đạt chuẩn hoàn toàn (`passed`), không tự mở bán khi `conditional_pass` hoặc `failed`.
            if (
                $markAvailableIfPassed
                && $requestedResult === 'passed'
                && ($currentUnitStatus === 'repairing' || $currentUnitStatus === 'returned')
            ) {
                $this->db->prepare("UPDATE product_units SET status = 'available', row_version = row_version + 1, updated_at = NOW() WHERE id = :id")
                    ->execute([':id' => $unitId]);
                $finalUnitStatus = 'available';
            }

            if (!class_exists(ProductUnitModel::class)) {
                require_once __DIR__ . '/ProductUnitModel.php';
            }
            ProductUnitModel::syncProductStockCache($this->db, (int) $lockedUnit['product_id']);

            return [
                'inspection_id'  => $inspectionId,
                'overall_result' => $requestedResult,
                'unit_status'    => $finalUnitStatus,
            ];
        });
    }
}
