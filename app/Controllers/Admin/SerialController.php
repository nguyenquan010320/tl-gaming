<?php
/**
 * Controller Quản lý Serial / Đơn vị máy cũ Admin (`app/Controllers/Admin/SerialController.php`).
 *
 * Vai trò & bảo mật:
 * - Chỉ thực thi sau `AdminMiddleware` (đã xác thực quyền Admin + `Auth::revalidate()`).
 * - Mọi thao tác ghi (`store`, `update`) dùng `POST` + `CsrfMiddleware` + PRG (Post-Redirect-Get).
 * - Kiểm tra chặt kiểu dữ liệu đầu vào (loại bỏ mảng/object bất thường), whitelist trạng thái và phân hạng.
 * - Bảo vệ trạng thái `reserved`/`sold`/đã có đơn hàng và dùng optimistic lock `expected_updated_at`.
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

class SerialController extends Controller
{
    private ProductUnitModel $unitModel;
    private InspectionModel $inspectionModel;

    public function __construct()
    {
        $this->unitModel       = new ProductUnitModel();
        $this->inspectionModel = new InspectionModel();
    }

    /**
     * Hiển thị danh sách serial / máy đơn lẻ (`GET /admin/serial`).
     *
     * @return void
     */
    public function index(): void
    {
        $keyword          = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $productRaw       = is_string($_GET['san-pham'] ?? null) ? trim($_GET['san-pham']) : '';
        $statusRaw        = is_string($_GET['trang-thai'] ?? null) ? trim($_GET['trang-thai']) : '';
        $gradeRaw         = is_string($_GET['ngoai-quan'] ?? null) ? trim($_GET['ngoai-quan']) : '';
        $inspectionRaw    = is_string($_GET['kiem-dinh'] ?? null) ? trim($_GET['kiem-dinh']) : '';

        $productId = ($productRaw !== '' && ctype_digit($productRaw) && (int) $productRaw > 0)
            ? (int) $productRaw
            : 0;
        $status = in_array($statusRaw, ProductUnitModel::ALLOWED_STATUSES, true) ? $statusRaw : '';
        $grade  = in_array($gradeRaw, ProductUnitModel::ALLOWED_GRADES, true) ? $gradeRaw : '';
        $inspectionStatus = in_array($inspectionRaw, ['none', 'passed', 'conditional_pass', 'failed'], true)
            ? $inspectionRaw
            : '';

        $filters = [
            'keyword'           => $keyword,
            'product_id'        => $productId,
            'status'            => $status,
            'cosmetic_grade'    => $grade,
            'inspection_status' => $inspectionStatus,
        ];

        $requestedPage = max(1, (int) ($_GET['page'] ?? 1));
        $perPage       = 15;

        try {
            $totalItems = $this->unitModel->countAdminUnits($filters);
            $pagination = $this->buildPagination($totalItems, $requestedPage, $perPage, '/admin/serial', [
                'q'          => $keyword !== '' ? $keyword : null,
                'san-pham'   => $productId > 0 ? (string) $productId : null,
                'trang-thai' => $status !== '' ? $status : null,
                'ngoai-quan' => $grade !== '' ? $grade : null,
                'kiem-dinh'  => $inspectionStatus !== '' ? $inspectionStatus : null,
            ]);
            $units          = $this->unitModel->getAdminUnits($filters, $pagination['per_page'], $pagination['offset']);
            $serialProducts = $this->unitModel->getSerialProducts();
        } catch (Throwable $e) {
            error_log('[Admin\SerialController] Lỗi tải danh sách serial: ' . $e->getMessage());
            $totalItems     = 0;
            $pagination     = $this->buildPagination(0, 1, $perPage, '/admin/serial');
            $units          = [];
            $serialProducts = [];
            Flash::add('error', 'Không thể tải danh sách serial lúc này. Vui lòng thử lại.');
        }

        $this->render('admin/serials/index', [
            'pageTitle'      => 'Quản lý Serial & Máy cũ | TL Gaming Admin',
            'currentRoute'   => '/admin/serial',
            'adminUser'      => Auth::user(),
            'breadcrumbs'    => [
                ['label' => 'Quản lý Serial & Kiểm định', 'url' => '/admin/serial'],
            ],
            'units'          => $units,
            'serialProducts' => $serialProducts,
            'totalItems'     => $totalItems,
            'pagination'     => $pagination,
            'filters'        => [
                'q'          => $keyword,
                'san-pham'   => $productId > 0 ? (string) $productId : '',
                'trang-thai' => $status,
                'ngoai-quan' => $grade,
                'kiem-dinh'  => $inspectionStatus,
            ],
        ], 'admin');
    }

    /**
     * Hiển thị biểu mẫu thêm máy / serial mới (`GET /admin/serial/them`).
     *
     * @return void
     */
    public function create(): void
    {
        $old = is_array($_SESSION['admin_serial_old'] ?? null) ? $_SESSION['admin_serial_old'] : [];
        unset($_SESSION['admin_serial_old']);

        $preselectProductId = is_string($_GET['san-pham'] ?? null) && ctype_digit($_GET['san-pham'])
            ? (int) $_GET['san-pham']
            : 0;

        try {
            $serialProducts = $this->unitModel->getSerialProducts();
        } catch (Throwable $e) {
            error_log('[Admin\SerialController] Lỗi tải sản phẩm serial: ' . $e->getMessage());
            $serialProducts = [];
            Flash::add('error', 'Không thể tải danh sách sản phẩm quản lý theo serial.');
        }

        $unit = array_merge([
            'id'             => 0,
            'product_id'     => $preselectProductId > 0 ? (string) $preselectProductId : '',
            'serial_number'  => '',
            'cosmetic_grade' => 'Grade A',
            'battery_health' => '',
            'import_price'   => '0',
            'status'         => 'available',
            'notes'          => '',
            'row_version'    => '1',
        ], $old);

        $this->render('admin/serials/form', [
            'pageTitle'      => 'Thêm máy / Serial mới | TL Gaming Admin',
            'currentRoute'   => '/admin/serial',
            'adminUser'      => Auth::user(),
            'breadcrumbs'    => [
                ['label' => 'Quản lý Serial & Kiểm định', 'url' => '/admin/serial'],
                ['label' => 'Thêm máy / Serial', 'url' => '/admin/serial/them'],
            ],
            'isEdit'         => false,
            'unit'           => $unit,
            'serialProducts' => $serialProducts,
            'formAction'     => '/admin/serial/them',
        ], 'admin');
    }

    /**
     * Xử lý thêm máy / serial mới (`POST /admin/serial/them`).
     *
     * @return void
     */
    public function store(): void
    {
        [$payload, $errors, $old] = $this->validateSerialPost(false);

        if ($errors !== []) {
            $_SESSION['admin_serial_old'] = $old;
            Flash::add('error', $errors[0]);
            $this->redirect('/admin/serial/them');
        }

        try {
            $newId = $this->unitModel->createUnit($payload);
            unset($_SESSION['admin_serial_old']);
            Flash::add('success', 'Đã thêm máy / serial mới thành công.');
            $this->redirect('/admin/serial/' . $newId);
        } catch (DomainException $e) {
            $_SESSION['admin_serial_old'] = $old;
            Flash::add('error', $e->getMessage());
            $this->redirect('/admin/serial/them');
        } catch (Throwable $e) {
            error_log('[Admin\SerialController] Lỗi tạo serial: ' . $e->getMessage());
            $_SESSION['admin_serial_old'] = $old;
            Flash::add('error', 'Không thể lưu máy / serial mới lúc này. Vui lòng thử lại.');
            $this->redirect('/admin/serial/them');
        }
    }

    /**
     * Xem chi tiết một máy / serial và lịch sử kiểm định (`GET /admin/serial/{id}`).
     *
     * @param string $id
     * @return void
     */
    public function show(string $id): void
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
            error_log('[Admin\SerialController] Lỗi tải chi tiết serial #' . $unitId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể tải thông tin đơn vị serial lúc này.');
            $this->redirect('/admin/serial');
        }

        $this->render('admin/serials/show', [
            'pageTitle'    => 'Chi tiết Serial ' . $unit['serial_number'] . ' | TL Gaming Admin',
            'currentRoute' => '/admin/serial',
            'adminUser'    => Auth::user(),
            'breadcrumbs'  => [
                ['label' => 'Quản lý Serial & Kiểm định', 'url' => '/admin/serial'],
                ['label' => $unit['serial_number'], 'url' => '/admin/serial/' . $unitId],
            ],
            'unit'         => $unit,
            'inspections'  => $inspections,
        ], 'admin');
    }

    /**
     * Hiển thị biểu mẫu chỉnh sửa thông tin máy / serial (`GET /admin/serial/{id}/sua`).
     *
     * @param string $id
     * @return void
     */
    public function edit(string $id): void
    {
        $unitId = $this->parsePositiveId($id);
        if ($unitId === null) {
            Flash::add('error', 'Mã đơn vị serial không hợp lệ.');
            $this->redirect('/admin/serial');
        }

        try {
            $existing = $this->unitModel->findById($unitId);
            if ($existing === null) {
                Flash::add('error', 'Không tìm thấy đơn vị máy / serial cần sửa.');
                $this->redirect('/admin/serial');
            }
            $serialProducts = $this->unitModel->getSerialProducts();
        } catch (Throwable $e) {
            error_log('[Admin\SerialController] Lỗi tải form sửa serial #' . $unitId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể tải biểu mẫu sửa serial lúc này.');
            $this->redirect('/admin/serial');
        }

        $old = is_array($_SESSION['admin_serial_old'] ?? null) ? $_SESSION['admin_serial_old'] : [];
        unset($_SESSION['admin_serial_old']);

        $unit = array_merge($existing, $old);
        // Luôn giữ row_version mới nhất từ DB để optimistic lock khớp khi vừa tải lại trang
        $unit['row_version'] = (string) ($existing['row_version'] ?? '1');

        $this->render('admin/serials/form', [
            'pageTitle'      => 'Chỉnh sửa Serial ' . $existing['serial_number'] . ' | TL Gaming Admin',
            'currentRoute'   => '/admin/serial',
            'adminUser'      => Auth::user(),
            'breadcrumbs'    => [
                ['label' => 'Quản lý Serial & Kiểm định', 'url' => '/admin/serial'],
                ['label' => $existing['serial_number'], 'url' => '/admin/serial/' . $unitId],
                ['label' => 'Chỉnh sửa', 'url' => '/admin/serial/' . $unitId . '/sua'],
            ],
            'isEdit'         => true,
            'unit'           => $unit,
            'existingUnit'   => $existing,
            'serialProducts' => $serialProducts,
            'formAction'     => '/admin/serial/' . $unitId . '/sua',
        ], 'admin');
    }

    /**
     * Xử lý cập nhật thông tin máy / serial (`POST /admin/serial/{id}/sua`).
     *
     * @param string $id
     * @return void
     */
    public function update(string $id): void
    {
        $unitId = $this->parsePositiveId($id);
        if ($unitId === null) {
            Flash::add('error', 'Mã đơn vị serial không hợp lệ.');
            $this->redirect('/admin/serial');
        }

        [$payload, $errors, $old] = $this->validateSerialPost(true);

        if ($errors !== []) {
            $_SESSION['admin_serial_old'] = $old;
            Flash::add('error', $errors[0]);
            $this->redirect('/admin/serial/' . $unitId . '/sua');
        }

        try {
            $this->unitModel->updateUnit($unitId, $payload);
            unset($_SESSION['admin_serial_old']);
            Flash::add('success', 'Đã cập nhật thông tin máy / serial thành công.');
            $this->redirect('/admin/serial/' . $unitId);
        } catch (DomainException $e) {
            $_SESSION['admin_serial_old'] = $old;
            Flash::add('error', $e->getMessage());
            $this->redirect('/admin/serial/' . $unitId . '/sua');
        } catch (Throwable $e) {
            error_log('[Admin\SerialController] Lỗi cập nhật serial #' . $unitId . ': ' . $e->getMessage());
            $_SESSION['admin_serial_old'] = $old;
            Flash::add('error', 'Không thể cập nhật đơn vị serial lúc này. Vui lòng thử lại.');
            $this->redirect('/admin/serial/' . $unitId . '/sua');
        }
    }

    /**
     * Kiểm tra và chuẩn hóa dữ liệu POST cho biểu mẫu thêm/sửa serial.
     *
     * @param bool $isEdit
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<string, string>}
     */
    private function validateSerialPost(bool $isEdit): array
    {
        $errors = [];

        $productIdRaw   = $this->extractScalarString($_POST['product_id'] ?? null);
        $serialRaw      = $this->extractScalarString($_POST['serial_number'] ?? null);
        $gradeRaw       = $this->extractScalarString($_POST['cosmetic_grade'] ?? null);
        $batteryRaw     = $this->extractScalarString($_POST['battery_health'] ?? null);
        $importPriceRaw = $this->extractScalarString($_POST['import_price'] ?? null);
        $statusRaw      = $this->extractScalarString($_POST['status'] ?? null);
        $notesRaw       = $this->extractScalarString($_POST['notes'] ?? null);
        $expectedVerRaw = $this->extractScalarString($_POST['expected_row_version'] ?? null);

        $old = [
            'product_id'     => $productIdRaw ?? '',
            'serial_number'  => $serialRaw !== null ? trim($serialRaw) : '',
            'cosmetic_grade' => $gradeRaw ?? 'Grade A',
            'battery_health' => $batteryRaw !== null ? trim($batteryRaw) : '',
            'import_price'   => $importPriceRaw !== null ? trim($importPriceRaw) : '0',
            'status'         => $statusRaw ?? 'available',
            'notes'          => $notesRaw !== null ? trim($notesRaw) : '',
        ];

        $productId = ($productIdRaw !== null && ctype_digit(trim($productIdRaw)) && (int) trim($productIdRaw) > 0)
            ? (int) trim($productIdRaw)
            : 0;
        if ($productId <= 0) {
            $errors[] = 'Vui lòng chọn sản phẩm áp dụng quản lý theo serial.';
        }

        $serialNumber = $serialRaw !== null ? trim($serialRaw) : '';
        if ($serialNumber === '' || mb_strlen($serialNumber, 'UTF-8') > 120) {
            $errors[] = 'Số serial phải có từ 1 đến 120 ký tự.';
        }

        $cosmeticGrade = $gradeRaw !== null ? trim($gradeRaw) : '';
        if (!in_array($cosmeticGrade, ProductUnitModel::ALLOWED_GRADES, true)) {
            $errors[] = 'Phân hạng ngoại quan không hợp lệ.';
        }

        $batteryHealth = null;
        $batteryTrim   = $batteryRaw !== null ? trim($batteryRaw) : '';
        if ($batteryTrim !== '') {
            $parsedBattery = $this->parseBoundedInt($batteryTrim, 0, 100);
            if ($parsedBattery === null) {
                $errors[] = 'Tình trạng pin phải là số nguyên từ 0 đến 100 (hoặc để trống).';
            } else {
                $batteryHealth = $parsedBattery;
            }
        }

        $importTrim  = $importPriceRaw !== null ? trim($importPriceRaw) : '';
        $importPrice = 0;
        if ($importTrim !== '') {
            $parsedPrice = $this->parseBoundedInt($importTrim, 0, ProductUnitModel::MAX_IMPORT_PRICE);
            if ($parsedPrice === null) {
                $errors[] = 'Giá nhập phải là số nguyên từ 0 đến 100.000.000.000đ.';
            } else {
                $importPrice = $parsedPrice;
            }
        }

        $status        = $statusRaw !== null ? trim($statusRaw) : '';
        $allowedStatus = $isEdit ? ProductUnitModel::ALLOWED_STATUSES : ProductUnitModel::ALLOWED_CREATE_STATUSES;
        if (!in_array($status, $allowedStatus, true)) {
            $errors[] = $isEdit
                ? 'Trạng thái đơn vị serial không hợp lệ.'
                : 'Khi thêm mới chỉ được chọn trạng thái Sẵn sàng bán hoặc Đang sửa chữa.';
        }

        $notes = $notesRaw !== null ? trim($notesRaw) : '';
        if (mb_strlen($notes, 'UTF-8') > 2000) {
            $errors[] = 'Ghi chú tối đa 2000 ký tự.';
        }

        $expectedRowVersion = 0;
        if ($isEdit) {
            $parsedVer = $this->parseBoundedInt($expectedVerRaw, 1, 2147483647);
            if ($parsedVer === null) {
                $errors[] = 'Thiếu hoặc sai phiên bản khóa lạc quan (expected_row_version) để chống ghi đè.';
            } else {
                $expectedRowVersion = $parsedVer;
            }
        }

        $payload = [
            'product_id'           => $productId,
            'serial_number'        => $serialNumber,
            'cosmetic_grade'       => $cosmeticGrade,
            'battery_health'       => $batteryHealth,
            'import_price'         => $importPrice,
            'status'               => $status,
            'notes'                => $notes,
            'expected_row_version' => $expectedRowVersion,
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

    /**
     * Phân tích số nguyên trong khoảng [min, max].
     */
    private function parseBoundedInt(mixed $value, int $min, int $max): ?int
    {
        $str = $this->extractScalarString($value);
        if ($str === null) {
            return null;
        }
        $trimmed = trim($str);
        if ($trimmed === '' || !preg_match('/^-?\d+$/', $trimmed)) {
            return null;
        }
        $intVal = filter_var($trimmed, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);
        return $intVal === false ? null : (int) $intVal;
    }
}
