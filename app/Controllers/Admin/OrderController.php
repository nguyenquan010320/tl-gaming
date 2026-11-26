<?php
/**
 * Controller Quản lý Đơn hàng Admin (`app/Controllers/Admin/OrderController.php`) — Prompt 19.
 *
 * Vai trò & bảo mật:
 * - Thực thi sau `AdminMiddleware` (đã xác thực Admin + `Auth::revalidate()`).
 * - Mọi action thay đổi trạng thái đơn/thanh toán đều dùng `POST` + `CsrfMiddleware` + PRG.
 * - Không tin `status`, `amount` hay `order_items` từ request trình duyệt.
 * - Tách biệt lỗi nghiệp vụ (`DomainException`) và lỗi hệ thống (`Throwable`), không lộ SQL/stack trace.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\AdminOrderModel;
use DomainException;
use Throwable;

class OrderController extends Controller
{
    private AdminOrderModel $orderModel;

    public function __construct()
    {
        $this->orderModel = new AdminOrderModel();
    }

    /**
     * Danh sách đơn hàng Admin có tìm kiếm, lọc, sắp xếp whitelist và phân trang server (`GET /admin/don-hang`).
     *
     * @return void
     */
    public function index(): void
    {
        $keyword       = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $statusRaw     = is_string($_GET['trang-thai'] ?? null) ? trim($_GET['trang-thai']) : '';
        $payMethodRaw  = is_string($_GET['pttt'] ?? null) ? trim($_GET['pttt']) : '';
        $payStatusRaw  = is_string($_GET['tt-thanh-toan'] ?? null) ? trim($_GET['tt-thanh-toan']) : '';
        $dateFromRaw   = is_string($_GET['tu-ngay'] ?? null) ? trim($_GET['tu-ngay']) : '';
        $dateToRaw     = is_string($_GET['den-ngay'] ?? null) ? trim($_GET['den-ngay']) : '';
        $sortRaw       = is_string($_GET['sap-xep'] ?? null) ? trim($_GET['sap-xep']) : 'newest';
        $pageRaw       = is_string($_GET['trang'] ?? null) ? trim($_GET['trang']) : '1';

        $status        = in_array($statusRaw, AdminOrderModel::ALLOWED_ORDER_STATUSES, true) ? $statusRaw : '';
        $paymentMethod = in_array($payMethodRaw, AdminOrderModel::ALLOWED_PAYMENT_METHODS, true) ? $payMethodRaw : '';

        $allowedPayFilters = array_merge(AdminOrderModel::ALLOWED_PAYMENT_STATUSES, ['manual_refund_required']);
        $paymentStatus     = in_array($payStatusRaw, $allowedPayFilters, true) ? $payStatusRaw : '';

        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromRaw) ? $dateFromRaw : '';
        $dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToRaw) ? $dateToRaw : '';
        $sort     = in_array($sortRaw, ['newest', 'oldest', 'amount_desc', 'amount_asc'], true) ? $sortRaw : 'newest';

        $perPage = 15;
        $page    = ctype_digit($pageRaw) && (int) $pageRaw > 0 ? (int) $pageRaw : 1;

        $filters = [
            'keyword'        => $keyword,
            'status'         => $status,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
        ];

        $totalItems = $this->orderModel->countAdminOrders($filters);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $orders = $this->orderModel->getAdminOrders($filters, $sort, $perPage, $offset);

        $this->render('admin/orders/index', [
            'pageTitle'    => 'Quản lý đơn hàng | TL Gaming',
            'currentRoute' => '/admin/don-hang',
            'breadcrumbs'  => [
                ['label' => 'Đơn hàng', 'url' => '/admin/don-hang'],
            ],
            'orders'       => $orders,
            'filters'      => $filters,
            'sort'         => $sort,
            'pagination'   => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
            ],
        ], 'admin');
    }

    /**
     * Xem chi tiết đơn hàng (`GET /admin/don-hang/{id}`).
     *
     * @param string $id
     * @return void
     */
    public function show(string $id): void
    {
        $orderId = $this->parsePositiveId($id);
        if ($orderId === null) {
            Flash::add('error', 'Mã ID đơn hàng không hợp lệ.');
            $this->redirect('/admin/don-hang');
        }

        $order = $this->orderModel->getOrderDetail($orderId);
        if ($order === null) {
            Flash::add('error', 'Không tìm thấy đơn hàng yêu cầu.');
            $this->redirect('/admin/don-hang');
        }

        $this->render('admin/orders/show', [
            'pageTitle'    => 'Đơn hàng ' . $order['order_code'] . ' | TL Gaming',
            'currentRoute' => '/admin/don-hang',
            'breadcrumbs'  => [
                ['label' => 'Đơn hàng', 'url' => '/admin/don-hang'],
                ['label' => (string) $order['order_code'], 'url' => '/admin/don-hang/' . $orderId],
            ],
            'order'        => $order,
        ], 'admin');
    }

    /**
     * Xác nhận đơn hàng (`POST /admin/don-hang/{id}/xac-nhan`).
     */
    public function confirm(string $id): void
    {
        $orderId = $this->parsePositiveId($id);
        $adminId = (int) (Auth::id() ?? 0);
        if ($orderId === null || $adminId <= 0) {
            Flash::add('error', 'Yêu cầu xác nhận đơn hàng không hợp lệ.');
            $this->redirect('/admin/don-hang');
        }

        try {
            $res = $this->orderModel->confirmOrder($orderId, $adminId);
            Flash::add('success', "Đã xác nhận đơn hàng {$res['order_code']} thành công.");
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[AdminOrderController::confirm] ' . $e->getMessage());
            Flash::add('error', 'Không thể xác nhận đơn hàng do lỗi hệ thống. Vui lòng thử lại.');
        }

        $this->redirect('/admin/don-hang/' . $orderId);
    }

    /**
     * Xác nhận đã thu tiền COD hoặc đối soát Chuyển khoản (`POST /admin/don-hang/{id}/xac-nhan-thanh-toan`).
     */
    public function confirmPayment(string $id): void
    {
        $orderId = $this->parsePositiveId($id);
        $adminId = (int) (Auth::id() ?? 0);
        if ($orderId === null || $adminId <= 0) {
            Flash::add('error', 'Yêu cầu xác nhận thanh toán không hợp lệ.');
            $this->redirect('/admin/don-hang');
        }

        $rawTxCode = $_POST['transaction_code'] ?? '';
        $rawNote   = $_POST['payment_note'] ?? '';
        if (!is_string($rawTxCode) || !is_string($rawNote)) {
            Flash::add('error', 'Dữ liệu xác nhận thanh toán gửi lên không hợp lệ.');
            $this->redirect('/admin/don-hang/' . $orderId);
        }

        try {
            $res = $this->orderModel->confirmPayment($orderId, $adminId, $rawTxCode, $rawNote);
            Flash::add(
                'success',
                sprintf(
                    'Đã xác nhận thanh toán đơn %s (%sđ).',
                    $res['order_code'],
                    number_format((int) $res['amount'], 0, ',', '.')
                )
            );
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[AdminOrderController::confirmPayment] ' . $e->getMessage());
            Flash::add('error', 'Không thể xác nhận thanh toán do lỗi hệ thống. Vui lòng thử lại.');
        }

        $this->redirect('/admin/don-hang/' . $orderId);
    }

    /**
     * Chuyển đơn sang Đang giao / Xử lý giao hàng (`POST /admin/don-hang/{id}/giao-hang`).
     */
    public function ship(string $id): void
    {
        $orderId = $this->parsePositiveId($id);
        $adminId = (int) (Auth::id() ?? 0);
        if ($orderId === null || $adminId <= 0) {
            Flash::add('error', 'Yêu cầu giao hàng không hợp lệ.');
            $this->redirect('/admin/don-hang');
        }

        $rawNote = $_POST['action_note'] ?? '';
        if (!is_string($rawNote)) {
            Flash::add('error', 'Ghi chú giao hàng gửi lên không hợp lệ.');
            $this->redirect('/admin/don-hang/' . $orderId);
        }

        try {
            $res = $this->orderModel->shipOrder($orderId, $adminId, $rawNote);
            Flash::add('success', "Đơn hàng {$res['order_code']} đã chuyển sang trạng thái Đang giao / Xử lý.");
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[AdminOrderController::ship] ' . $e->getMessage());
            Flash::add('error', 'Không thể chuyển trạng thái giao hàng do lỗi hệ thống.');
        }

        $this->redirect('/admin/don-hang/' . $orderId);
    }

    /**
     * Hoàn tất đơn hàng (`POST /admin/don-hang/{id}/hoan-tat`).
     */
    public function complete(string $id): void
    {
        $orderId = $this->parsePositiveId($id);
        $adminId = (int) (Auth::id() ?? 0);
        if ($orderId === null || $adminId <= 0) {
            Flash::add('error', 'Yêu cầu hoàn tất đơn hàng không hợp lệ.');
            $this->redirect('/admin/don-hang');
        }

        $rawNote = $_POST['action_note'] ?? '';
        if (!is_string($rawNote)) {
            Flash::add('error', 'Ghi chú hoàn tất gửi lên không hợp lệ.');
            $this->redirect('/admin/don-hang/' . $orderId);
        }

        try {
            $res = $this->orderModel->completeOrder($orderId, $adminId, $rawNote);
            Flash::add('success', "Đã hoàn tất đơn hàng {$res['order_code']} ({$res['sold_serials']} serial chuyển sold).");
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[AdminOrderController::complete] ' . $e->getMessage());
            Flash::add('error', 'Không thể hoàn tất đơn hàng do lỗi hệ thống.');
        }

        $this->redirect('/admin/don-hang/' . $orderId);
    }

    /**
     * Hủy đơn hàng chưa giao và hoàn tồn kho + voucher (`POST /admin/don-hang/{id}/huy`).
     */
    public function cancel(string $id): void
    {
        $orderId = $this->parsePositiveId($id);
        $adminId = (int) (Auth::id() ?? 0);
        if ($orderId === null || $adminId <= 0) {
            Flash::add('error', 'Yêu cầu hủy đơn hàng không hợp lệ.');
            $this->redirect('/admin/don-hang');
        }

        $rawReason = $_POST['cancel_reason'] ?? '';
        if (!is_string($rawReason)) {
            Flash::add('error', 'Dữ liệu lý do hủy đơn không hợp lệ.');
            $this->redirect('/admin/don-hang/' . $orderId);
        }

        try {
            $res   = $this->orderModel->cancelOrder($orderId, $adminId, $rawReason);
            $extra = $res['manual_refund_required']
                ? ' Lưu ý: Đơn đã thanh toán, đã đánh dấu CẦN HOÀN TIỀN THỦ CÔNG.'
                : '';
            Flash::add(
                'success',
                sprintf(
                    'Đã hủy đơn %s (hoàn %d tồn kho số lượng, giải phóng %d serial).%s',
                    $res['order_code'],
                    (int) $res['restored_qty_units'],
                    (int) $res['released_serials'],
                    $extra
                )
            );
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[AdminOrderController::cancel] ' . $e->getMessage());
            Flash::add('error', 'Không thể hủy đơn hàng do lỗi hệ thống. Vui lòng thử lại.');
        }

        $this->redirect('/admin/don-hang/' . $orderId);
    }

    /**
     * Phân tích ID số nguyên dương từ tham số đường dẫn URL.
     */
    private function parsePositiveId(string $raw): ?int
    {
        $clean = trim($raw);
        if ($clean === '' || !ctype_digit($clean)) {
            return null;
        }
        $val = (int) $clean;
        return $val > 0 ? $val : null;
    }
}
