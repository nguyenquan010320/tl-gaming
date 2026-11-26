<?php
/**
 * Controller điều khiển Trang chủ (`GET /`) của hệ thống Website bán máy tính cũ TL Gaming.
 *
 * Vai trò trong kiến trúc MVC:
 * - Tiếp nhận yêu cầu HTTP GET tại đường dẫn gốc `/` từ `Router`.
 * - Gọi `CategoryModel` và `ProductModel` để truy vấn dữ liệu thực tế từ cơ sở dữ liệu MySQL `tl_gaming` qua PDO:
 *   + Danh sách các danh mục đang hoạt động (`is_active = 1`) và cấu trúc Menu Header.
 *   + Danh sách Sản phẩm Nổi bật (`status = 'published'`), kèm thông số `product_specs` và phần trăm giảm giá hợp lệ.
 *   + Phân nhóm sản phẩm theo 3 khối chuyên biệt: PC Gaming & Đồ họa, Laptop Gaming, Linh kiện & Màn hình.
 *   + Dữ liệu Quy trình Kiểm định 18 bước từ `inspection_items` và các biên bản đã xác nhận trong `product_inspections`.
 * - Truyền toàn bộ dữ liệu đã chuẩn bị sang View `home/index` và Layout `layouts/main` để hiển thị HTML5/CSS3.
 * - Bảo mật: Tuyệt đối không hiển thị thông tin kết nối MySQL, phiên bản PHP, câu lệnh SQL hoặc Raw Exception ra trang công khai.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HomeBannerSettings;
use App\Models\CategoryModel;
use App\Models\ProductModel;
use Throwable;

class HomeController extends Controller
{
    /**
     * Hiển thị giao diện Trang chủ TL Gaming hoàn chỉnh (`GET /`).
     *
     * @return void
     */
    public function index(): void
    {
        $categories         = [];
        $headerMenuItems    = [];
        $featuredProducts   = [];
        $pcProducts         = [];
        $laptopProducts     = [];
        $componentProducts  = [];
        $inspectionOverview = [
            'groups'                => [],
            'verified_records'      => [],
            'total_inspected_units' => 0,
            'total_available_units' => 0,
        ];

        $dbConnected        = Database::isConnected();
        $categoryQueryError = false;
        $productQueryError  = false;

        if ($dbConnected) {
            try {
                $categoryModel   = new CategoryModel();
                $categories      = $categoryModel->getActiveCategories(includeProductCounts: false);
                $headerMenuItems = $categoryModel->buildHeaderMenuItems($categories);
            } catch (Throwable $e) {
                $categoryQueryError = true;
                error_log('[TL Gaming HomeController] Lỗi truy vấn danh mục: ' . $e->getMessage());
            }

            try {
                $productModel = new ProductModel();

                // Xác định danh sách ID danh mục cho từng phân khu sản phẩm từ dữ liệu thực trong bảng categories
                $pcCategoryIds        = $this->resolveCategoryGroupIds($categories, ['pc-gaming-cu']);
                $laptopCategoryIds    = $this->resolveCategoryGroupIds($categories, ['laptop-gaming-cu']);
                $componentCategoryIds = $this->resolveCategoryGroupIds($categories, [
                    'linh-kien-cu',
                    'man-hinh-cu',
                    'vga-cu',
                    'cpu-cu',
                ]);

                $featuredProducts   = $productModel->getFeaturedProducts(8);
                $pcProducts         = $productModel->getPublishedByCategoryIds($pcCategoryIds, 4);
                $laptopProducts     = $productModel->getPublishedByCategoryIds($laptopCategoryIds, 4);
                $componentProducts  = $productModel->getPublishedByCategoryIds($componentCategoryIds, 8);
                $inspectionOverview = $productModel->getInspectionOverviewData();
            } catch (Throwable $e) {
                $productQueryError = true;
                error_log('[TL Gaming HomeController] Lỗi truy vấn dữ liệu sản phẩm trang chủ: ' . $e->getMessage());
            }
        }

        $this->render('home/index', [
            'pageTitle'          => 'TL Gaming — Hệ Thống Máy Tính & Linh Kiện Cũ Kiểm Định 18 Bước',
            'currentRoute'       => '/',
            'homeBanner'         => HomeBannerSettings::current(),
            'headerMenuItems'    => $headerMenuItems,
            'featuredProducts'   => $featuredProducts,
            'pcProducts'         => $pcProducts,
            'laptopProducts'     => $laptopProducts,
            'componentProducts'  => $componentProducts,
            'inspectionOverview' => $inspectionOverview,
            'dbConnected'        => $dbConnected,
            'categoryQueryError' => $categoryQueryError,
            'productQueryError'  => $productQueryError,
        ]);
    }

    /**
     * Trích xuất danh sách `id` của các danh mục khớp theo mảng `slug` hoặc có `parent_id` thuộc nhóm đó.
     *
     * @param array<int, array<string, mixed>> $categories Danh sách danh mục đang hoạt động từ `CategoryModel`.
     * @param array<int, string>               $targetSlugs Danh sách các `slug` danh mục cần gom nhóm.
     * @return array<int, int>                             Mảng các khóa chính `id` danh mục tương ứng.
     */
    private function resolveCategoryGroupIds(array $categories, array $targetSlugs): array
    {
        $slugLookup = array_fill_keys($targetSlugs, true);
        $matchedIds = [];

        foreach ($categories as $cat) {
            $slug = (string) ($cat['slug'] ?? '');
            $id   = (int) ($cat['id'] ?? 0);
            if ($id > 0 && isset($slugLookup[$slug])) {
                $matchedIds[$id] = $id;
            }
        }

        // Bổ sung các danh mục con trực thuộc nếu danh mục cha nằm trong nhóm đã khớp
        foreach ($categories as $cat) {
            $id       = (int) ($cat['id'] ?? 0);
            $parentId = isset($cat['parent_id']) && $cat['parent_id'] !== null ? (int) $cat['parent_id'] : 0;
            if ($id > 0 && $parentId > 0 && isset($matchedIds[$parentId])) {
                $matchedIds[$id] = $id;
            }
        }

        return array_values($matchedIds);
    }
}
