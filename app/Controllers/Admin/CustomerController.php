<?php
/**
 * Controller Quản lý Khách hàng Admin (`app/Controllers/Admin/CustomerController.php`) — Prompt 20.
 *
 * Bảo mật:
 * - Chạy sau `AdminMiddleware`; mọi thao tác ghi (`lock`, `unlock`) chạy qua `POST` + `CsrfMiddleware`.
 * - Không cho thay đổi `role`, mật khẩu hoặc xóa dữ liệu mua hàng của khách.
 * - Không cho Admin tự khóa tài khoản của chính mình.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\AdminCustomerModel;
use DomainException;
use Throwable;

class CustomerController extends Controller
{
    private AdminCustomerModel $customerModel;

    public function __construct()
    {
        $this->customerModel = new AdminCustomerModel();
    }

    /**
     * Danh sách khách hàng (`GET /admin/khach-hang`) có lọc, sắp xếp whitelist & phân trang server.
     */
    public function index(): void
    {
        $keyword     = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $statusRaw   = is_string($_GET['trang-thai'] ?? null) ? trim($_GET['trang-thai']) : '';
        $dateFromRaw = is_string($_GET['tu-ngay'] ?? null) ? trim($_GET['tu-ngay']) : '';
        $dateToRaw   = is_string($_GET['den-ngay'] ?? null) ? trim($_GET['den-ngay']) : '';
        $sortRaw     = is_string($_GET['sap-xep'] ?? null) ? trim($_GET['sap-xep']) : 'newest';
        $pageRaw     = is_string($_GET['trang'] ?? null) ? trim($_GET['trang']) : '1';

        $status   = in_array($statusRaw, AdminCustomerModel::ALLOWED_STATUSES, true) ? $statusRaw : '';
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromRaw) ? $dateFromRaw : '';
        $dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToRaw) ? $dateToRaw : '';
        $sort     = in_array($sortRaw, ['newest', 'oldest', 'spent_desc', 'orders_desc', 'name_asc'], true) ? $sortRaw : 'newest';

        $perPage = 15;
        $page    = ctype_digit($pageRaw) && (int) $pageRaw > 0 ? (int) $pageRaw : 1;

        $filters = [
            'keyword'   => $keyword,
            'status'    => $status,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ];

        $totalItems = $this->customerModel->countAdminCustomers($filters);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset    = ($page - 1) * $perPage;
        $customers = $this->customerModel->getAdminCustomers($filters, $sort, $perPage, $offset);

        $this->render('admin/customers/index', [
            'pageTitle'    => 'Quản lý khách hàng | TL Gaming',
            'currentRoute' => '/admin/khach-hang',
            'breadcrumbs'  => [
                ['label' => 'Khách hàng', 'url' => '/admin/khach-hang'],
            ],
            'customers'    => $customers,
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
     * Xem chi tiết khách hàng & lịch sử đơn hàng của riêng tài khoản (`GET /admin/khach-hang/{id}`).
     */
    public function show(string $id): void
    {
        $customerId = $this->parsePositiveId($id);
        if ($customerId === null) {
            Flash::add('error', 'Mã ID khách hàng không hợp lệ.');
            $this->redirect('/admin/khach-hang');
        }

        $customer = $this->customerModel->getCustomerDetail($customerId);
        if ($customer === null) {
            Flash::add('error', 'Không tìm thấy tài khoản khách hàng yêu cầu.');
            $this->redirect('/admin/khach-hang');
        }

        $pageRaw    = is_string($_GET['trang'] ?? null) ? trim($_GET['trang']) : '1';
        $perPage    = 10;
        $page       = ctype_digit($pageRaw) && (int) $pageRaw > 0 ? (int) $pageRaw : 1;
        $totalItems = $this->customerModel->countCustomerOrders($customerId);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $orders = $this->customerModel->getCustomerOrders($customerId, $perPage, ($page - 1) * $perPage);

        $this->render('admin/customers/show', [
            'pageTitle'    => 'Khách hàng: ' . $customer['full_name'] . ' | TL Gaming',
            'currentRoute' => '/admin/khach-hang',
            'breadcrumbs'  => [
                ['label' => 'Khách hàng', 'url' => '/admin/khach-hang'],
                ['label' => (string) $customer['full_name'], 'url' => '/admin/khach-hang/' . $customerId],
            ],
            'customer'     => $customer,
            'orders'       => $orders,
            'pagination'   => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
            ],
        ], 'admin');
    }

    /**
     * Khóa tài khoản khách hàng (`POST /admin/khach-hang/{id}/khoa`).
     */
    public function lock(string $id): void
    {
        $this->handleStatusChange($id, 'locked');
    }

    /**
     * Mở khóa tài khoản khách hàng (`POST /admin/khach-hang/{id}/mo-khoa`).
     */
    public function unlock(string $id): void
    {
        $this->handleStatusChange($id, 'active');
    }

    /**
     * Xử lý chung khóa/mở khóa tài khoản khách hàng (không nhận role/status từ POST).
     */
    private function handleStatusChange(string $id, string $targetStatus): void
    {
        $customerId = $this->parsePositiveId($id);
        $adminId    = (int) (Auth::id() ?? 0);
        if ($customerId === null || $adminId <= 0) {
            Flash::add('error', 'Yêu cầu cập nhật trạng thái khách hàng không hợp lệ.');
            $this->redirect('/admin/khach-hang');
        }

        try {
            $res = $this->customerModel->updateCustomerStatus($customerId, $targetStatus, $adminId);
            $msg = $targetStatus === 'locked'
                ? "Đã khóa tài khoản khách hàng {$res['full_name']} ({$res['email']}). Các phiên đăng nhập của tài khoản này sẽ bị từ chối."
                : "Đã mở khóa tài khoản khách hàng {$res['full_name']} ({$res['email']}).";
            Flash::add('success', $msg);
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[AdminCustomerController::handleStatusChange] ' . $e->getMessage());
            Flash::add('error', 'Không thể cập nhật trạng thái khách hàng do lỗi hệ thống.');
        }

        $this->redirect('/admin/khach-hang/' . $customerId);
    }

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
