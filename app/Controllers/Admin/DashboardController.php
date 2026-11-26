<?php
/**
 * Controller Trang Tổng quan Kinh doanh & Phân tích Doanh thu Admin (`GET /admin`) — Prompt 21.
 *
 * Vai trò & bảo mật:
 * - Thực thi sau `AdminMiddleware` (bắt buộc đăng nhập `role === 'admin'`).
 * - Chỉ đọc dữ liệu thực từ MySQL thông qua `AdminDashboardModel`, không dùng số liệu giả.
 * - Validate chặt các tham số bộ lọc thời gian (`ky`, `tu-ngay`, `den-ngay`), chặn mảng/kiểu lạ.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\AdminDashboardModel;
use DateTimeImmutable;
use DateTimeZone;

class DashboardController extends Controller
{
    private AdminDashboardModel $dashboardModel;

    public function __construct()
    {
        $this->dashboardModel = new AdminDashboardModel();
    }

    /**
     * Hiển thị Trang Tổng quan Kinh doanh (`GET /admin`).
     *
     * @return void
     */
    public function index(): void
    {
        $presetRaw   = is_string($_GET['ky'] ?? null) ? trim($_GET['ky']) : '30d';
        $dateFromRaw = is_string($_GET['tu-ngay'] ?? null) ? trim($_GET['tu-ngay']) : '';
        $dateToRaw   = is_string($_GET['den-ngay'] ?? null) ? trim($_GET['den-ngay']) : '';

        $range = AdminDashboardModel::resolveDateRange($presetRaw, $dateFromRaw, $dateToRaw);
        if ($range['error'] !== '') {
            Flash::add('warning', $range['error']);
        }

        $kpi           = $this->dashboardModel->getKpiSummary($range['start_at'], $range['end_at']);
        $revenueSeries = $this->dashboardModel->getRevenueSeries($range['start_at'], $range['end_at'], $range['granularity']);
        $statusDist    = $this->dashboardModel->getOrderStatusDistribution($range['start_at'], $range['end_at']);
        $topProducts   = $this->dashboardModel->getTopSellingProducts($range['start_at'], $range['end_at'], 5);
        $recentOrders  = $this->dashboardModel->getRecentOrders(10);
        $alerts        = $this->dashboardModel->getOperationalAlerts();

        $this->render('admin/dashboard', [
            'pageTitle'     => 'Tổng quan kinh doanh | TL Gaming',
            'currentRoute'  => '/admin',
            'adminUser'     => Auth::user(),
            'breadcrumbs'   => [
                ['label' => 'Tổng quan kinh doanh', 'url' => '/admin'],
            ],
            'range'         => $range,
            'kpi'           => $kpi,
            'revenueSeries' => $revenueSeries,
            'statusDist'    => $statusDist,
            'topProducts'   => $topProducts,
            'recentOrders'  => $recentOrders,
            'alerts'        => $alerts,
            'generatedAt'   => (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s'),
        ], 'admin');
    }
}
