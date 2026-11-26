<?php
/**
 * Controller Kiểm định Kỹ thuật 18 bước Admin (`app/Controllers/Admin/InspectionController.php`).
 *
 * Vai trò & bảo mật:
 * - Chỉ thực thi sau `AdminMiddleware` (đã xác thực quyền Admin + `Auth::revalidate()`).
 * - Ghi nhận `inspector_id` trực tiếp từ `Auth::id()`, không tin dữ liệu POST ẩn.
 * - Lưu biên bản theo mô hình append-only (không cho sửa/xóa biên bản cũ).
 * - Bắt buộc đủ 18 bước chuẩn 1..18 và áp đặt quy tắc kết quả tổng thể ở phía server.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\InspectionModel;
use App\Models\ProductUnitModel;
use DomainException;
use Throwable;

class InspectionController extends Controller
{
    private ProductUnitModel $unitModel;
    private InspectionModel $inspectionModel;

    public function __construct()
    {
        $this->unitModel       = new ProductUnitModel();
        $this->inspectionModel = new InspectionModel();
    }

    /**
     * Hiển thị lịch sử kiểm định của một đơn vị serial (`GET /admin/serial/{id}/kiem-dinh`).
     *
     * @param string $id
     * @return void
     */
    public function history(string $id): void
    {
        $unitId = $this->parsePositiveId($id);
        if ($unitId === null) {
            Flash::add('error', 'Mã đơn vị serial không hợp lệ.');
            $this->redirect('/admin/serial');
        }

        try {
            $unit = $this->unitModel->findById($unitId);
            if ($unit === null) {
                Flash::add('error', 'Không tìm thấy đơn vị máy / serial yêu cầu.');
                $this->redirect('/admin/serial');
            }
            $inspections = $this->inspectionModel->getInspectionsByUnitId($unitId);
        } catch (Throwable $e) {
            error_log('[Admin\InspectionController] Lỗi tải lịch sử kiểm định #' . $unitId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể tải lịch sử kiểm định lúc này.');
            $this->redirect('/admin/serial');
        }

        $this->render('admin/inspections/history', [
            'pageTitle'    => 'Lịch sử kiểm định ' . $unit['serial_number'] . ' | TL Gaming Admin',
            'currentRoute' => '/admin/serial',
            'adminUser'    => Auth::user(),
            'breadcrumbs'  => [
                ['label' => 'Quản lý Serial & Kiểm định', 'url' => '/admin/serial'],
                ['label' => $unit['serial_number'], 'url' => '/admin/serial/' . $unitId],
                ['label' => 'Lịch sử kiểm định', 'url' => '/admin/serial/' . $unitId . '/kiem-dinh'],
            ],
            'unit'         => $unit,
            'inspections'  => $inspections,
        ], 'admin');
    }

    /**
     * Hiển thị biểu mẫu lập biên bản kiểm định 18 bước (`GET /admin/serial/{id}/kiem-dinh/them`).
     *
     * @param string $id
     * @return void
     */
    public function create(string $id): void
    {
        $unitId = $this->parsePositiveId($id);
        if ($unitId === null) {
            Flash::add('error', 'Mã đơn vị serial không hợp lệ.');
            $this->redirect('/admin/serial');
        }

        try {
            $unit = $this->unitModel->findById($unitId);
            if ($unit === null) {
                Flash::add('error', 'Không tìm thấy đơn vị máy / serial yêu cầu.');
                $this->redirect('/admin/serial');
            }
        } catch (Throwable $e) {
            error_log('[Admin\InspectionController] Lỗi tải form kiểm định #' . $unitId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể tải biểu mẫu kiểm định lúc này.');
            $this->redirect('/admin/serial');
        }

        $old = is_array($_SESSION['admin_inspection_old'] ?? null) ? $_SESSION['admin_inspection_old'] : [];
        unset($_SESSION['admin_inspection_old']);

        $checklist = $this->inspectionModel->getOfficialChecklist();

        $this->render('admin/inspections/create', [
            'pageTitle'    => 'Lập biên bản 18 bước - ' . $unit['serial_number'] . ' | TL Gaming Admin',
            'currentRoute' => '/admin/serial',
            'adminUser'    => Auth::user(),
            'breadcrumbs'  => [
                ['label' => 'Quản lý Serial & Kiểm định', 'url' => '/admin/serial'],
                ['label' => $unit['serial_number'], 'url' => '/admin/serial/' . $unitId],
                ['label' => 'Kiểm định 18 bước', 'url' => '/admin/serial/' . $unitId . '/kiem-dinh/them'],
            ],
            'unit'         => $unit,
            'checklist'    => $checklist,
            'old'          => $old,
            'formAction'   => '/admin/serial/' . $unitId . '/kiem-dinh/them',
        ], 'admin');
    }

    /**
     * Xử lý lưu biên bản kiểm định 18 bước (`POST /admin/serial/{id}/kiem-dinh/them`).
     *
     * @param string $id
     * @return void
     */
    public function store(string $id): void
    {
        $unitId = $this->parsePositiveId($id);
        if ($unitId === null) {
            Flash::add('error', 'Mã đơn vị serial không hợp lệ.');
            $this->redirect('/admin/serial');
        }

        $inspectorId = Auth::id();
        if ($inspectorId === null || $inspectorId <= 0) {
            Flash::add('error', 'Không xác định được kỹ thuật viên thực hiện kiểm định.');
            $this->redirect('/admin/serial/' . $unitId);
        }

        [$payload, $errors, $old] = $this->validateInspectionPost();

        if ($errors !== []) {
            $_SESSION['admin_inspection_old'] = $old;
            Flash::add('error', $errors[0]);
            $this->redirect('/admin/serial/' . $unitId . '/kiem-dinh/them');
        }

        try {
            $result = $this->inspectionModel->createInspection(
                $unitId,
                $inspectorId,
                $payload['overall_result'],
                $payload['notes'],
                $payload['items'],
                $payload['mark_available_if_passed']
            );
            unset($_SESSION['admin_inspection_old']);

            $msg = 'Đã lưu biên bản kiểm định 18 bước thành công.';
            $unitStatus = (string) ($result['unit_status'] ?? '');
            if ($payload['mark_available_if_passed'] && $unitStatus === 'available') {
                $msg .= ' Máy đã được chuyển sang trạng thái Sẵn sàng bán.';
            } elseif (in_array($unitStatus, ['reserved', 'sold'], true)) {
                $msg .= ' Trạng thái đơn hàng hiện tại của máy (' . $unitStatus . ') được giữ nguyên.';
            }

            Flash::add('success', $msg);
            $this->redirect('/admin/kiem-dinh/' . (int) $result['inspection_id']);
        } catch (DomainException $e) {
            $_SESSION['admin_inspection_old'] = $old;
            Flash::add('error', $e->getMessage());
            $this->redirect('/admin/serial/' . $unitId . '/kiem-dinh/them');
        } catch (Throwable $e) {
            error_log('[Admin\InspectionController] Lỗi lưu biên bản kiểm định #' . $unitId . ': ' . $e->getMessage());
            $_SESSION['admin_inspection_old'] = $old;
            Flash::add('error', 'Không thể lưu biên bản kiểm định lúc này. Vui lòng thử lại.');
            $this->redirect('/admin/serial/' . $unitId . '/kiem-dinh/them');
        }
    }

    /**
     * Xem chi tiết một biên bản kiểm định 18 bước (`GET /admin/kiem-dinh/{inspectionId}`).
     *
     * @param string $inspectionId
     * @return void
     */
    public function show(string $inspectionId): void
    {
        $id = $this->parsePositiveId($inspectionId);
        if ($id === null) {
            Flash::add('error', 'Mã biên bản kiểm định không hợp lệ.');
            $this->redirect('/admin/serial');
        }

        try {
            $inspection = $this->inspectionModel->findInspectionWithItems($id);
            if ($inspection === null) {
                Flash::add('error', 'Không tìm thấy biên bản kiểm định yêu cầu.');
                $this->redirect('/admin/serial');
            }
        } catch (Throwable $e) {
            error_log('[Admin\InspectionController] Lỗi tải biên bản #' . $id . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể tải biên bản kiểm định lúc này.');
            $this->redirect('/admin/serial');
        }

        $unitId = (int) $inspection['unit_id'];

        $this->render('admin/inspections/show', [
            'pageTitle'    => 'Biên bản #' . $id . ' - ' . $inspection['serial_number'] . ' | TL Gaming Admin',
            'currentRoute' => '/admin/serial',
            'adminUser'    => Auth::user(),
            'breadcrumbs'  => [
                ['label' => 'Quản lý Serial & Kiểm định', 'url' => '/admin/serial'],
                ['label' => (string) $inspection['serial_number'], 'url' => '/admin/serial/' . $unitId],
                ['label' => 'Biên bản #' . $id, 'url' => '/admin/kiem-dinh/' . $id],
            ],
            'inspection'   => $inspection,
        ], 'admin');
    }

    /**
     * Kiểm tra và chuẩn hóa dữ liệu POST cho biên bản kiểm định 18 bước.
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<string, mixed>}
     */
    private function validateInspectionPost(): array
    {
        $errors = [];

        $overallRaw       = $this->extractScalarString($_POST['overall_result'] ?? null);
        $notesRaw         = $this->extractScalarString($_POST['notes'] ?? null);
        $markAvailableRaw = $this->extractScalarString($_POST['mark_available_if_passed'] ?? null);
        $stepsRaw         = $_POST['steps'] ?? null;

        $overallResult = $overallRaw !== null ? trim($overallRaw) : '';
        $notes         = $notesRaw !== null ? trim($notesRaw) : '';
        $markAvailable = in_array($markAvailableRaw, ['1', 'on', 'true'], true);

        $oldSteps = [];
        $items    = [];

        if (!is_array($stepsRaw)) {
            $errors[] = 'Dữ liệu 18 bước kiểm định không hợp lệ.';
        } else {
            for ($step = 1; $step <= 18; $step++) {
                $row = $stepsRaw[$step] ?? ($stepsRaw[(string) $step] ?? null);
                if (!is_array($row)) {
                    $errors[] = 'Thiếu kết quả kiểm tra cho bước số ' . $step . '.';
                    continue;
                }

                $resStr  = $this->extractScalarString($row['result'] ?? null);
                $noteStr = $this->extractScalarString($row['note'] ?? null);

                $resVal  = $resStr !== null ? trim($resStr) : '';
                $noteVal = $noteStr !== null ? trim($noteStr) : '';

                $oldSteps[$step] = [
                    'result' => $resVal,
                    'note'   => $noteVal,
                ];

                if (!in_array($resVal, InspectionModel::ALLOWED_ITEM_RESULTS, true)) {
                    $errors[] = 'Bước số ' . $step . ' có trạng thái kết quả không hợp lệ.';
                }
                if (mb_strlen($noteVal, 'UTF-8') > 255) {
                    $errors[] = 'Ghi chú bước số ' . $step . ' tối đa 255 ký tự.';
                }

                $items[$step] = [
                    'step_number' => $step,
                    'result'      => $resVal,
                    'notes'       => $noteVal,
                ];
            }
        }

        if (!in_array($overallResult, InspectionModel::ALLOWED_OVERALL_RESULTS, true)) {
            $errors[] = 'Kết luận tổng thể biên bản không hợp lệ.';
        }

        if (mb_strlen($notes, 'UTF-8') > 2000) {
            $errors[] = 'Ghi chú tổng thể biên bản tối đa 2000 ký tự.';
        }

        $old = [
            'overall_result'           => $overallResult,
            'notes'                    => $notes,
            'mark_available_if_passed' => $markAvailable ? '1' : '0',
            'steps'                    => $oldSteps,
        ];

        $payload = [
            'overall_result'           => $overallResult,
            'notes'                    => $notes,
            'mark_available_if_passed' => $markAvailable,
            'items'                    => $items,
        ];

        return [$payload, $errors, $old];
    }

    /**
     * Phân tích ID số nguyên dương từ tham số đường dẫn.
     */
    private function parsePositiveId(string $raw): ?int
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }
        $val = (int) $trimmed;
        return $val > 0 ? $val : null;
    }

    /**
     * Trích xuất chuỗi vô hướng từ dữ liệu POST, từ chối mảng hoặc object.
     */
    private function extractScalarString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }
}
