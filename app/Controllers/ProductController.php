<?php
/**
 * Controller điều khiển Trang Danh sách Sản phẩm (`GET /san-pham`) và Chi tiết Sản phẩm (`GET /san-pham/{slug}`).
 *
 * Vai trò trong kiến trúc MVC:
 * - Tiếp nhận, kiểm tra tính hợp lệ và chuẩn hóa toàn bộ tham số truy vấn từ `$_GET`:
 *   + `q`: Từ khóa tìm kiếm (giới hạn tối đa 100 ký tự, loại bỏ ký tự điều khiển).
 *   + `danh-muc`: Đường dẫn tĩnh `slug` của danh mục cha hoặc danh mục con.
 *   + `gia-tu` / `min_price` và `gia-den` / `max_price`: Khoảng giá lọc theo số nguyên VND hợp lệ.
 *   + `ton-kho` / `stock`: Trạng thái tồn kho (`all` hoặc `in_stock`).
 *   + `sap-xep` / `sort`: Tiêu chí sắp xếp đối chiếu qua danh sách trắng (Whitelist).
 *   + `page`: Số trang nguyên dương hợp lệ (giới hạn trần tránh truy vấn quá lớn).
 * - Gọi `CategoryModel` và `ProductModel` để đếm tổng số kết quả, tính toán phân trang (`buildPagination`)
 *   và lấy danh sách 12 sản phẩm mỗi trang.
 * - Tuyệt đối không cho phép khách hàng truyền tham số sửa đổi trạng thái `status = 'published'`.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\View;
use App\Models\CategoryModel;
use App\Models\ProductModel;
use Throwable;

class ProductController extends Controller
{
    /**
     * Độ dài tối đa cho phép của từ khóa tìm kiếm `q` (tính theo ký tự UTF-8).
     */
    private const MAX_KEYWORD_LENGTH = 100;

    /**
     * Giá trị trần tối đa hợp lệ cho bộ lọc giá (500.000.000 VND).
     */
    private const MAX_PRICE_LIMIT = 500000000;

    /**
     * Số trang tối đa cho phép từ tham số `GET page` nhằm ngăn chặn truy vấn OFFSET quá lớn.
     */
    private const MAX_PAGE_LIMIT = 1000;

    /**
     * Hiển thị trang Danh sách sản phẩm hoàn chỉnh (`GET /san-pham`), hỗ trợ tìm kiếm, bộ lọc đa điều kiện,
     * sắp xếp an toàn và phân trang 12 sản phẩm/trang.
     *
     * @return void
     */
    public function index(): void
    {
        $categories        = [];
        $headerMenuItems   = [];
        $products          = [];
        $activeCategory    = null;
        $parentCategory    = null;

        // 1. Chuẩn hóa và kiểm tra an toàn toàn bộ tham số đầu vào từ $_GET
        $searchQuery  = $this->sanitizeKeyword($_GET['q'] ?? '');
        $categorySlug = $this->sanitizeSlug($_GET['danh-muc'] ?? $_GET['category'] ?? '');
        $minPrice     = $this->sanitizePrice($_GET['gia-tu'] ?? $_GET['min_price'] ?? null);
        $maxPrice     = $this->sanitizePrice($_GET['gia-den'] ?? $_GET['max_price'] ?? null);
        $stockStatus  = $this->sanitizeStockStatus($_GET['ton-kho'] ?? $_GET['stock'] ?? 'all');
        $sortKey      = ProductModel::normalizeSortKey($this->extractScalarString($_GET['sap-xep'] ?? $_GET['sort'] ?? 'moi-nhat'));
        $requestedPage = $this->sanitizePage($_GET['page'] ?? 1);

        // Nếu người dùng nhập Giá thấp nhất lớn hơn Giá cao nhất, tự động hoán đổi để khoảng giá luôn hợp lệ
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
        }

        $dbConnected       = Database::isConnected();
        $productQueryError = false;
        $totalItems        = 0;

        if ($dbConnected) {
            try {
                $categoryModel   = new CategoryModel();
                $categories      = $categoryModel->getActiveCategories();
                $headerMenuItems = $categoryModel->buildHeaderMenuItems($categories);

                $filterCategoryIds = null;
                if ($categorySlug !== '') {
                    $categoryById = [];
                    foreach ($categories as $catItem) {
                        $categoryById[(int) $catItem['id']] = $catItem;
                        if (strtolower((string) $catItem['slug']) === $categorySlug) {
                            $activeCategory = $catItem;
                        }
                    }
                    $filterCategoryIds = $activeCategory['family_ids'] ?? [-1];
                    $parentCategory = $categoryById[(int) ($activeCategory['parent_id'] ?? 0)] ?? null;
                }

                $filters = [
                    'keyword'      => $searchQuery !== '' ? $searchQuery : null,
                    'category_ids' => $filterCategoryIds,
                    'min_price'    => $minPrice,
                    'max_price'    => $maxPrice,
                    'stock_status' => $stockStatus,
                    'sort'         => $sortKey,
                ];

                $productModel = new ProductModel();
                $totalItems   = $productModel->countFilteredPublishedProducts($filters);
            } catch (Throwable $e) {
                $productQueryError = true;
                error_log('[TL Gaming ProductController] Lỗi đếm sản phẩm lọc: ' . $e->getMessage());
            }
        }

        // 2. Chuẩn bị danh sách tham số Query String cần bảo toàn khi chuyển trang hoặc đổi thứ tự sắp xếp
        $preservedParams = [
            'q'        => $searchQuery !== '' ? $searchQuery : null,
            'danh-muc' => $categorySlug !== '' ? $categorySlug : null,
            'gia-tu'   => $minPrice !== null ? (string) $minPrice : null,
            'gia-den'  => $maxPrice !== null ? (string) $maxPrice : null,
            'ton-kho'  => $stockStatus !== 'all' ? $stockStatus : null,
            'sap-xep'  => $sortKey !== 'moi-nhat' ? $sortKey : null,
        ];

        $pagination = $this->buildPagination(
            $totalItems,
            $requestedPage,
            ProductModel::PER_PAGE,
            '/san-pham',
            $preservedParams
        );

        // 3. Truy vấn danh sách sản phẩm cho trang hiện tại sau khi đã xác định offset hợp lệ
        if ($dbConnected && !$productQueryError && $totalItems > 0) {
            try {
                $productModel = $productModel ?? new ProductModel();
                $products     = $productModel->getFilteredPublishedProducts(
                    $filters ?? [],
                    $pagination['per_page'],
                    $pagination['offset']
                );
            } catch (Throwable $e) {
                $productQueryError = true;
                error_log('[TL Gaming ProductController] Lỗi lấy danh sách sản phẩm phân trang: ' . $e->getMessage());
            }
        }

        $hasActiveFilters = (
            $searchQuery !== ''
            || $categorySlug !== ''
            || $minPrice !== null
            || $maxPrice !== null
            || $stockStatus !== 'all'
        );

        $pageHeading = 'Tất Cả Máy Tính & Linh Kiện Cũ Kiểm Định';
        if ($activeCategory !== null && $searchQuery !== '') {
            $pageHeading = sprintf('%s — Tìm kiếm: "%s"', (string) $activeCategory['name'], $searchQuery);
        } elseif ($activeCategory !== null) {
            $pageHeading = (string) $activeCategory['name'];
        } elseif ($searchQuery !== '') {
            $pageHeading = sprintf('Kết quả tìm kiếm: "%s"', $searchQuery);
        }

        $this->render('products/index', [
            'pageTitle'         => $pageHeading . ' | TL Gaming',
            'currentRoute'      => '/san-pham',
            'pageHeading'       => $pageHeading,
            'categories'        => $categories,
            'headerMenuItems'   => $headerMenuItems,
            'products'          => $products,
            'activeCategory'    => $activeCategory,
            'parentCategory'    => $parentCategory,
            'searchQuery'       => $searchQuery,
            'categorySlug'      => $categorySlug,
            'minPrice'          => $minPrice,
            'maxPrice'          => $maxPrice,
            'stockStatus'       => $stockStatus,
            'filterParams'      => $preservedParams,
            'pagination'        => $pagination,
            'hasActiveFilters'  => $hasActiveFilters,
            'dbConnected'       => $dbConnected,
            'productQueryError' => $productQueryError,
        ]);
    }

    /**
     * Hiển thị trang Chi tiết sản phẩm hoàn chỉnh theo đường dẫn tĩnh (`GET /san-pham/{slug}`).
     *
     * Luồng điều phối MVC:
     * 1. Chuẩn hóa tham số `$slug` và gọi `ProductModel::findPublishedBySlug()` (chỉ lấy `status = 'published'`).
     * 2. Nếu sản phẩm không tồn tại hoặc đang ở trạng thái `draft`/`archived`, trả về mã `HTTP 404 Not Found` thật.
     * 3. Truy vấn không lặp (tránh N+1):
     *    - Bộ sưu tập hình ảnh thực tế (`product_images` + `products.thumbnail`).
     *    - Bảng thông số kỹ thuật ưu tiên phần cứng (`product_specs`).
     *    - Danh sách từng cá thể máy vật lý (`product_units`) kèm biên bản kiểm định riêng biệt (`product_inspections` + `inspection_items`).
     *    - Tối đa 4 sản phẩm tương tự cùng danh mục (`getSimilarPublishedProducts`).
     * 4. Truyền dữ liệu đã chuẩn bị sang View `products/show`.
     *
     * @param string $slug Đường dẫn tĩnh SEO của sản phẩm.
     * @return void
     */
    public function show(string $slug): void
    {
        $headerMenuItems = [];
        $product         = null;
        $galleryImages   = [];
        $specs           = [];
        $units           = [];
        $similarProducts = [];
        $cleanSlug       = $this->sanitizeSlug($slug);
        $dbConnected     = Database::isConnected();

        if ($dbConnected) {
            try {
                $categoryModel   = new CategoryModel();
                $headerMenuItems = $categoryModel->buildHeaderMenuItems($categoryModel->getActiveCategories(includeProductCounts: false));

                if ($cleanSlug !== '') {
                    $productModel = new ProductModel();
                    $product      = $productModel->findPublishedBySlug($cleanSlug);

                    if ($product !== null) {
                        $productId        = (int) $product['id'];
                        $categoryId       = (int) $product['category_id'];
                        $parentCategoryId = isset($product['category_parent_id']) && $product['category_parent_id'] !== null
                            ? (int) $product['category_parent_id']
                            : null;

                        $galleryImages = $productModel->getGalleryImagesByProductId(
                            $productId,
                            (string) ($product['thumbnail'] ?? ''),
                            (string) ($product['name'] ?? '')
                        );
                        $specs           = $productModel->getPrioritizedSpecsByProductId($productId);
                        $units           = $productModel->getUnitsWithInspectionsByProductId($productId);
                        $similarProducts = $productModel->getSimilarPublishedProducts(
                            $productId,
                            $categoryId,
                            $parentCategoryId,
                            4
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('[TL Gaming ProductController] Lỗi truy vấn chi tiết sản phẩm: ' . $e->getMessage());
            }
        }

        if ($product === null) {
            http_response_code(404);
            View::render('errors/404', [
                'pageTitle'       => '404 - Không tìm thấy sản phẩm | TL Gaming',
                'requestedPath'   => '/san-pham/' . $slug,
                'headerMenuItems' => $headerMenuItems,
            ]);
            return;
        }

        $this->render('products/show', [
            'pageTitle'       => (string) $product['name'] . ' | TL Gaming',
            'currentRoute'    => '/san-pham',
            'headerMenuItems' => $headerMenuItems,
            'product'         => $product,
            'galleryImages'   => $galleryImages,
            'specs'           => $specs,
            'units'           => $units,
            'similarProducts' => $similarProducts,
        ]);
    }

    /**
     * Trích xuất chuỗi thuần túy từ biến đầu vào (chống lỗi TypeError khi người dùng truyền mảng trên Query String như `?q[]=abc`).
     *
     * @param mixed $input Giá trị đầu vào từ `$_GET`.
     * @return string      Chuỗi an toàn hoặc chuỗi rỗng nếu không phải kiểu vô hướng.
     */
    private function extractScalarString(mixed $input): string
    {
        if (is_string($input) || is_int($input) || is_float($input)) {
            return (string) $input;
        }
        return '';
    }

    /**
     * Làm sạch và giới hạn độ dài từ khóa tìm kiếm `q` (tối đa 100 ký tự UTF-8).
     *
     * @param mixed $rawKeyword Giá trị `$_GET['q']`.
     * @return string           Từ khóa đã chuẩn hóa khoảng trắng và cắt giới hạn độ dài.
     */
    private function sanitizeKeyword(mixed $rawKeyword): string
    {
        $str = $this->extractScalarString($rawKeyword);
        // Loại bỏ các ký tự điều khiển không in được
        $str = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $str);
        // Gộp nhiều khoảng trắng liên tiếp thành 1 khoảng trắng
        $str = trim((string) preg_replace('/\s+/u', ' ', $str));

        if ($str === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($str, 0, self::MAX_KEYWORD_LENGTH, 'UTF-8');
        }

        return substr($str, 0, self::MAX_KEYWORD_LENGTH);
    }

    /**
     * Làm sạch tham số đường dẫn tĩnh (`slug` của danh mục hoặc sản phẩm).
     *
     * @param mixed $rawSlug   Giá trị slug đầu vào.
     * @param int   $maxLength Độ dài tối đa cho phép (mặc định 255 ký tự theo `products.slug`).
     * @return string          Chuỗi slug đã cắt khoảng trắng.
     */
    private function sanitizeSlug(mixed $rawSlug, int $maxLength = 255): string
    {
        $str = strtolower(trim($this->extractScalarString($rawSlug)));
        if ($str === '') {
            return '';
        }
        return substr($str, 0, max(1, $maxLength));
    }

    /**
     * Kiểm tra và chuẩn hóa tham số lọc giá (`gia-tu`, `gia-den`) thành số nguyên VND dương hợp lệ.
     * Nếu người dùng nhập chữ, số âm hoặc định dạng sai, trả về `null` (bỏ qua điều kiện giá đó mà không gây lỗi).
     *
     * @param mixed $rawPrice Giá trị giá từ `$_GET`.
     * @return int|null       Số tiền VND nguyên dương hoặc null.
     */
    private function sanitizePrice(mixed $rawPrice): ?int
    {
        $str = trim($this->extractScalarString($rawPrice));
        if ($str === '') {
            return null;
        }

        // Cho phép người dùng nhập số có dấu chấm/phẩy phân cách hàng nghìn (ví dụ: 10.000.000)
        $digitsOnly = str_replace(['.', ',', ' '], '', $str);
        if (!preg_match('/^\d+$/', $digitsOnly)) {
            return null;
        }

        $val = (int) $digitsOnly;
        if ($val <= 0) {
            return null;
        }

        return min($val, self::MAX_PRICE_LIMIT);
    }

    /**
     * Chuẩn hóa tham số trạng thái tồn kho (`ton-kho`) qua danh sách trắng (`all` hoặc `in_stock`).
     *
     * @param mixed $rawStock Giá trị `$_GET['ton-kho']`.
     * @return string         `'in_stock'` hoặc `'all'`.
     */
    private function sanitizeStockStatus(mixed $rawStock): string
    {
        $str = strtolower(trim($this->extractScalarString($rawStock)));
        if (in_array($str, ['in_stock', 'con-hang', 'available', '1'], true)) {
            return 'in_stock';
        }
        return 'all';
    }

    /**
     * Kiểm tra và chuẩn hóa tham số trang `page` thành số nguyên dương hợp lệ nằm trong khoảng `[1 .. MAX_PAGE_LIMIT]`.
     *
     * @param mixed $rawPage Giá trị `$_GET['page']`.
     * @return int           Số trang hợp lệ (>= 1).
     */
    private function sanitizePage(mixed $rawPage): int
    {
        $str = trim($this->extractScalarString($rawPage));
        if ($str === '' || !preg_match('/^\d+$/', $str)) {
            return 1;
        }

        $page = (int) $str;
        if ($page < 1) {
            return 1;
        }

        return min($page, self::MAX_PAGE_LIMIT);
    }
}
