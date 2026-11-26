<?php
/**
 * Controller Quản lý Voucher Admin (`app/Controllers/Admin/CouponController.php`) — Prompt 20.
 *
 * Bảo mật:
 * - Chạy sau `AdminMiddleware`; mọi POST chạy sau `CsrfMiddleware` + PRG.
 * - Chặn dữ liệu POST dạng mảng bất thường (chỉ nhận giá trị chuỗi vô hướng).
 * - Không tin `usage_count` từ client; khóa các trường nhạy cảm khi voucher đã có lịch sử sử dụng.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Flash;
use App\Models\AdminCouponModel;
use DomainException;
use Throwable;

class CouponController extends Controller
{
    private AdminCouponModel $couponModel;

    public function __construct()
    {
        $this->couponModel = new AdminCouponModel();
    }

    /**
     * Danh sách voucher Admin (`GET /admin/voucher`) có lọc, sắp xếp whitelist & phân trang server.
     */
    public function index(): void
    {
        $keyword = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $typeRaw = is_string($_GET['loai'] ?? null) ? trim($_GET['loai']) : '';
        $stRaw   = is_string($_GET['trang-thai'] ?? null) ? trim($_GET['trang-thai']) : '';
        $sortRaw = is_string($_GET['sap-xep'] ?? null) ? trim($_GET['sap-xep']) : 'newest';
        $pageRaw = is_string($_GET['trang'] ?? null) ? trim($_GET['trang']) : '1';

        $type = in_array($typeRaw, AdminCouponModel::ALLOWED_DISCOUNT_TYPES, true) ? $typeRaw : '';
        $st   = in_array($stRaw, AdminCouponModel::ALLOWED_STATE_FILTERS, true) ? $stRaw : '';
        $sort = in_array($sortRaw, ['newest', 'oldest', 'code_asc', 'usage_desc'], true) ? $sortRaw : 'newest';

        $perPage = 15;
        $page    = ctype_digit($pageRaw) && (int) $pageRaw > 0 ? (int) $pageRaw : 1;

        $filters = [
            'keyword'       => $keyword,
            'discount_type' => $type,
            'state'         => $st,
        ];

        $totalItems = $this->couponModel->countAdminCoupons($filters);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $coupons = $this->couponModel->getAdminCoupons($filters, $sort, $perPage, ($page - 1) * $perPage);

        $this->render('admin/coupons/index', [
            'pageTitle'    => 'Quản lý voucher | TL Gaming',
            'currentRoute' => '/admin/voucher',
            'breadcrumbs'  => [
                ['label' => 'Voucher', 'url' => '/admin/voucher'],
            ],
            'coupons'      => $coupons,
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
     * Hiển thị form thêm mới voucher (`GET /admin/voucher/them`).
     */
    public function create(): void
    {
        $old = $_SESSION['admin_coupon_old'] ?? [];
        unset($_SESSION['admin_coupon_old']);
        if (!is_array($old)) {
            $old = [];
        }

        $this->render('admin/coupons/form', [
            'pageTitle'    => 'Thêm voucher mới | TL Gaming',
            'currentRoute' => '/admin/voucher',
            'breadcrumbs'  => [
                ['label' => 'Voucher', 'url' => '/admin/voucher'],
                ['label' => 'Thêm voucher', 'url' => '/admin/voucher/them'],
            ],
            'mode'         => 'create',
            'coupon'       => null,
            'old'          => $old,
            'hasHistory'   => false,
        ], 'admin');
    }

    /**
     * Lưu voucher mới (`POST /admin/voucher/them`).
     */
    public function store(): void
    {
        $payload = $this->extractSafePostPayload();
        if ($payload === null) {
            Flash::add('error', 'Dữ liệu biểu mẫu voucher gửi lên không hợp lệ.');
            $this->redirect('/admin/voucher/them');
        }

        try {
            $newId = $this->couponModel->createCoupon($payload);
            Flash::add('success', "Đã tạo voucher \"{$payload['code']}\" thành công.");
            $this->redirect('/admin/voucher/' . $newId . '/sua');
        } catch (DomainException $e) {
            $_SESSION['admin_coupon_old'] = $payload;
            Flash::add('error', $e->getMessage());
            $this->redirect('/admin/voucher/them');
        } catch (Throwable $e) {
            error_log('[AdminCouponController::store] ' . $e->getMessage());
            $_SESSION['admin_coupon_old'] = $payload;
            Flash::add('error', 'Không thể tạo voucher do lỗi hệ thống. Vui lòng thử lại.');
            $this->redirect('/admin/voucher/them');
        }
    }

    /**
     * Hiển thị form sửa voucher (`GET /admin/voucher/{id}/sua`).
     */
    public function edit(string $id): void
    {
        $couponId = $this->parsePositiveId($id);
        if ($couponId === null) {
            Flash::add('error', 'Mã ID voucher không hợp lệ.');
            $this->redirect('/admin/voucher');
        }

        $coupon = $this->couponModel->findById($couponId);
        if ($coupon === null) {
            Flash::add('error', 'Không tìm thấy voucher yêu cầu.');
            $this->redirect('/admin/voucher');
        }

        $old = $_SESSION['admin_coupon_old'] ?? [];
        unset($_SESSION['admin_coupon_old']);
        if (!is_array($old)) {
            $old = [];
        }

        $this->render('admin/coupons/form', [
            'pageTitle'    => 'Sửa voucher ' . $coupon['code'] . ' | TL Gaming',
            'currentRoute' => '/admin/voucher',
            'breadcrumbs'  => [
                ['label' => 'Voucher', 'url' => '/admin/voucher'],
                ['label' => (string) $coupon['code'], 'url' => '/admin/voucher/' . $couponId . '/sua'],
            ],
            'mode'         => 'edit',
            'coupon'       => $coupon,
            'old'          => $old,
            'hasHistory'   => !empty($coupon['has_usage_history']),
        ], 'admin');
    }

    /**
     * Cập nhật voucher (`POST /admin/voucher/{id}/sua`).
     */
    public function update(string $id): void
    {
        $couponId = $this->parsePositiveId($id);
        if ($couponId === null) {
            Flash::add('error', 'Mã ID voucher không hợp lệ.');
            $this->redirect('/admin/voucher');
        }

        $payload = $this->extractSafePostPayload();
        if ($payload === null) {
            Flash::add('error', 'Dữ liệu biểu mẫu voucher gửi lên không hợp lệ.');
            $this->redirect('/admin/voucher/' . $couponId . '/sua');
        }

        try {
            $this->couponModel->updateCoupon($couponId, $payload);
            Flash::add('success', 'Đã cập nhật thông tin voucher thành công.');
        } catch (DomainException $e) {
            $_SESSION['admin_coupon_old'] = $payload;
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[AdminCouponController::update] ' . $e->getMessage());
            $_SESSION['admin_coupon_old'] = $payload;
            Flash::add('error', 'Không thể cập nhật voucher do lỗi hệ thống.');
        }

        $this->redirect('/admin/voucher/' . $couponId . '/sua');
    }

    /**
     * Bật voucher (`POST /admin/voucher/{id}/bat`).
     */
    public function enable(string $id): void
    {
        $this->handleToggle($id, 1);
    }

    /**
     * Tắt voucher (`POST /admin/voucher/{id}/tat`).
     */
    public function disable(string $id): void
    {
        $this->handleToggle($id, 0);
    }

    /**
     * Xem lịch sử sử dụng voucher (`GET /admin/voucher/{id}/lich-su`).
     */
    public function history(string $id): void
    {
        $couponId = $this->parsePositiveId($id);
        if ($couponId === null) {
            Flash::add('error', 'Mã ID voucher không hợp lệ.');
            $this->redirect('/admin/voucher');
        }

        $coupon = $this->couponModel->findById($couponId);
        if ($coupon === null) {
            Flash::add('error', 'Không tìm thấy voucher yêu cầu.');
            $this->redirect('/admin/voucher');
        }

        $pageRaw    = is_string($_GET['trang'] ?? null) ? trim($_GET['trang']) : '1';
        $perPage    = 15;
        $page       = ctype_digit($pageRaw) && (int) $pageRaw > 0 ? (int) $pageRaw : 1;
        $history    = $this->couponModel->getCouponUsageHistory($couponId, $perPage, ($page - 1) * $perPage);
        $totalItems = (int) $history['total_rows'];
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page    = $totalPages;
            $history = $this->couponModel->getCouponUsageHistory($couponId, $perPage, ($page - 1) * $perPage);
        }

        $this->render('admin/coupons/history', [
            'pageTitle'    => 'Lịch sử voucher ' . $coupon['code'] . ' | TL Gaming',
            'currentRoute' => '/admin/voucher',
            'breadcrumbs'  => [
                ['label' => 'Voucher', 'url' => '/admin/voucher'],
                ['label' => (string) $coupon['code'], 'url' => '/admin/voucher/' . $couponId . '/sua'],
                ['label' => 'Lịch sử sử dụng', 'url' => '/admin/voucher/' . $couponId . '/lich-su'],
            ],
            'coupon'       => $coupon,
            'summary'      => $history['summary'],
            'rows'         => $history['rows'],
            'pagination'   => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
            ],
        ], 'admin');
    }

    private function handleToggle(string $id, int $targetActive): void
    {
        $couponId = $this->parsePositiveId($id);
        if ($couponId === null) {
            Flash::add('error', 'Mã ID voucher không hợp lệ.');
            $this->redirect('/admin/voucher');
        }

        try {
            $res = $this->couponModel->toggleCouponActive($couponId, $targetActive);
            $msg = $targetActive === 1
                ? "Đã bật lại voucher \"{$res['code']}\"."
                : "Đã tạm tắt voucher \"{$res['code']}\". Đơn hàng mới sẽ không thể áp dụng mã này.";
            Flash::add('success', $msg);
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[AdminCouponController::handleToggle] ' . $e->getMessage());
            Flash::add('error', 'Không thể thay đổi trạng thái voucher do lỗi hệ thống.');
        }

        $this->redirect('/admin/voucher');
    }

    /**
     * Đọc whitelist các trường POST hợp lệ, từ chối hoàn toàn payload dạng mảng/object bất thường
     * và không bao giờ tiếp nhận `usage_count`.
     *
     * @return array<string, string>|null
     */
    private function extractSafePostPayload(): ?array
    {
        $fields = [
            'code',
            'description',
            'discount_type',
            'discount_value',
            'max_discount_amount',
            'min_order_amount',
            'usage_limit',
            'per_user_limit',
            'start_date',
            'end_date',
            'is_active',
        ];

        $out = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $_POST)) {
                // Không tự gán mặc định '0' cho `is_active` khi thiếu field để tránh tự tắt voucher;
                // để Model từ chối bằng DomainException rõ ràng.
                if ($field !== 'is_active') {
                    $out[$field] = '';
                }
                continue;
            }
            if (!is_string($_POST[$field])) {
                return null;
            }
            $out[$field] = trim($_POST[$field]);
        }
        $out['code'] = strtoupper($out['code']);

        return $out;
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
