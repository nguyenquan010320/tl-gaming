<?php
/**
 * Controller Quản lý Sản phẩm Admin (`app/Controllers/Admin/ProductController.php`).
 *
 * Vai trò & bảo mật:
 * - Chỉ thực thi sau `AdminMiddleware` (đã xác thực quyền Admin + `Auth::revalidate()`).
 * - Mọi thao tác ghi (`store`, `update`, `hide`, `show`, `uploadImages`, `deleteImage`) dùng `POST` + `CsrfMiddleware` + PRG.
 * - Kiểm tra kiểu dữ liệu đầu vào nghiêm ngặt (từ chối mảng/object bất thường, không gây PHP warning).
 * - Upload hình ảnh kiểm tra lỗi PHP upload, dung lượng, MIME thực tế (`finfo` + `getimagesize`), tạo tên ngẫu nhiên,
 *   lưu vào `public/uploads/products/` và dọn file an toàn khi transaction lỗi hoặc khi xóa ảnh.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\AdminProductModel;
use App\Models\CategoryModel;
use DomainException;
use Throwable;

class ProductController extends Controller
{
    /**
     * Dung lượng tối đa mỗi tệp ảnh tải lên (5 MB).
     */
    private const MAX_IMAGE_BYTES = 5242880;

    /**
     * Số lượng ảnh tối đa cho mỗi sản phẩm.
     */
    private const MAX_IMAGES_PER_PRODUCT = 10;

    private AdminProductModel $productModel;
    private CategoryModel $categoryModel;

    public function __construct()
    {
        $this->productModel  = new AdminProductModel();
        $this->categoryModel = new CategoryModel();
    }

    /**
     * Hiển thị danh sách sản phẩm Admin (`GET /admin/san-pham`),
     * hỗ trợ tìm kiếm tên/SKU, lọc danh mục, trạng thái, loại tồn kho, sắp xếp whitelist và phân trang server.
     *
     * @return void
     */
    public function index(): void
    {
        $keyword   = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $catRaw    = is_string($_GET['danh-muc'] ?? null) ? trim($_GET['danh-muc']) : '';
        $statusRaw = is_string($_GET['trang-thai'] ?? null) ? trim($_GET['trang-thai']) : '';
        $typeRaw   = is_string($_GET['loai-kho'] ?? null) ? trim($_GET['loai-kho']) : '';
        $sortKey   = AdminProductModel::normalizeSortKey(is_string($_GET['sap-xep'] ?? null) ? $_GET['sap-xep'] : null);

        $categoryId  = ($catRaw !== '' && ctype_digit($catRaw) && (int) $catRaw > 0) ? (int) $catRaw : 0;
        $categoryIds = $categoryId > 0 ? $this->productModel->getAdminCategoryBranchIds($categoryId) : null;
        $status      = in_array($statusRaw, AdminProductModel::ALLOWED_STATUSES, true) ? $statusRaw : '';
        $stockType   = in_array($typeRaw, ['serial', 'quantity'], true) ? $typeRaw : '';

        $filters = [
            'keyword'      => $keyword,
            'category_ids' => $categoryIds,
            'status'       => $status,
            'stock_type'   => $stockType,
            'sort'         => $sortKey,
        ];

        $requestedPage = max(1, (int) ($_GET['page'] ?? 1));
        $perPage       = 15;

        try {
            $totalItems = $this->productModel->countAdminProducts($filters);
            $pagination = $this->buildPagination($totalItems, $requestedPage, $perPage, '/admin/san-pham', [
                'q'          => $keyword !== '' ? $keyword : null,
                'danh-muc'   => $categoryId > 0 ? (string) $categoryId : null,
                'trang-thai' => $status !== '' ? $status : null,
                'loai-kho'   => $stockType !== '' ? $stockType : null,
                'sap-xep'    => $sortKey !== 'moi-nhat' ? $sortKey : null,
            ]);
            $products   = $this->productModel->getAdminProducts($filters, $pagination['per_page'], $pagination['offset']);
            $categories = $this->categoryModel->getAdminCategories();
        } catch (Throwable $e) {
            error_log('[Admin\ProductController] Lỗi tải danh sách sản phẩm: ' . $e->getMessage());
            $totalItems = 0;
            $pagination = $this->buildPagination(0, 1, $perPage, '/admin/san-pham');
            $products   = [];
            $categories = [];
            Flash::add('error', 'Không thể tải danh sách sản phẩm lúc này. Vui lòng thử lại.');
        }

        $this->render('admin/products/index', [
            'pageTitle'    => 'Quản lý sản phẩm | TL Gaming Admin',
            'currentRoute' => '/admin/san-pham',
            'adminUser'    => Auth::user(),
            'breadcrumbs'  => [
                ['label' => 'Quản lý sản phẩm', 'url' => '/admin/san-pham'],
            ],
            'products'     => $products,
            'categories'   => $categories,
            'totalItems'   => $totalItems,
            'pagination'   => $pagination,
            'filters'      => [
                'q'          => $keyword,
                'danh-muc'   => $categoryId > 0 ? (string) $categoryId : '',
                'trang-thai' => $status,
                'loai-kho'   => $stockType,
                'sap-xep'    => $sortKey,
            ],
        ], 'admin');
    }

    /**
     * Hiển thị biểu mẫu thêm sản phẩm mới (`GET /admin/san-pham/them`).
     *
     * @return void
     */
    public function create(): void
    {
        $old = is_array($_SESSION['admin_product_old'] ?? null) ? $_SESSION['admin_product_old'] : [];
        unset($_SESSION['admin_product_old']);

        $categories = $this->categoryModel->getAdminCategories();

        $defaultProduct = [
            'id'                 => 0,
            'category_id'        => '',
            'name'               => '',
            'slug'               => '',
            'sku'                => '',
            'summary'            => '',
            'description'        => '',
            'price'              => '',
            'original_price'     => '',
            'warranty_months'    => '12',
            'condition_overview' => 'Like New 99%',
            'stock_quantity'     => '0',
            'has_serials'        => '1',
            'status'             => 'draft',
        ];

        $productData = $old !== [] ? array_merge($defaultProduct, $old['product'] ?? []) : $defaultProduct;
        $specsData   = $old['specs'] ?? [
            ['group_name' => 'Cấu hình chính', 'spec_name' => 'CPU', 'spec_value' => '', 'sort_order' => 1],
            ['group_name' => 'Cấu hình chính', 'spec_name' => 'RAM', 'spec_value' => '', 'sort_order' => 2],
            ['group_name' => 'Cấu hình chính', 'spec_name' => 'Ổ cứng (SSD/HDD)', 'spec_value' => '', 'sort_order' => 3],
            ['group_name' => 'Cấu hình chính', 'spec_name' => 'Card đồ họa (VGA)', 'spec_value' => '', 'sort_order' => 4],
        ];

        $this->render('admin/products/form', [
            'pageTitle'    => 'Thêm sản phẩm mới | TL Gaming Admin',
            'currentRoute' => '/admin/san-pham',
            'adminUser'    => Auth::user(),
            'breadcrumbs'  => [
                ['label' => 'Quản lý sản phẩm', 'url' => '/admin/san-pham'],
                ['label' => 'Thêm sản phẩm', 'url' => '/admin/san-pham/them'],
            ],
            'mode'         => 'create',
            'product'      => $productData,
            'specs'        => $specsData,
            'images'       => [],
            'categories'   => $categories,
            'formAction'   => '/admin/san-pham/them',
            'hasDeps'      => false,
        ], 'admin');
    }

    /**
     * Xử lý tạo mới sản phẩm (`POST /admin/san-pham/them`) theo mô hình PRG.
     *
     * @return void
     */
    public function store(): void
    {
        [$validated, $errors, $rawProduct] = $this->validateProductInput($_POST, true);
        [$specs, $specErrors, $rawSpecs]   = $this->validateSpecsInput($_POST);
        $errors = array_merge($errors, $specErrors);

        $uploadedImages = [];
        if ($errors === []) {
            [$uploadedImages, $uploadErrors] = $this->processUploadedImages($_FILES['images'] ?? null, 0);
            $errors = array_merge($errors, $uploadErrors);
        }

        if ($errors !== []) {
            $this->cleanupUploadedFiles($uploadedImages);
            $_SESSION['admin_product_old'] = ['product' => $rawProduct, 'specs' => $rawSpecs];
            Flash::add('error', $errors[0]);
            $this->redirect('/admin/san-pham/them');
        }

        try {
            $newId = $this->productModel->createProduct($validated, $specs, $uploadedImages);
            unset($_SESSION['admin_product_old']);
            Flash::add('success', "Đã tạo sản phẩm \"{$validated['name']}\" (ID: #{$newId}) thành công.");
            $this->redirect("/admin/san-pham/{$newId}/sua");
        } catch (DomainException $e) {
            $this->cleanupUploadedFiles($uploadedImages);
            $_SESSION['admin_product_old'] = ['product' => $rawProduct, 'specs' => $rawSpecs];
            Flash::add('error', $e->getMessage());
            $this->redirect('/admin/san-pham/them');
        } catch (Throwable $e) {
            $this->cleanupUploadedFiles($uploadedImages);
            error_log('[Admin\ProductController] Lỗi tạo sản phẩm: ' . $e->getMessage());
            $_SESSION['admin_product_old'] = ['product' => $rawProduct, 'specs' => $rawSpecs];
            Flash::add('error', 'Hệ thống gặp sự cố kỹ thuật khi lưu sản phẩm. Vui lòng thử lại.');
            $this->redirect('/admin/san-pham/them');
        }
    }

    /**
     * Hiển thị biểu mẫu chỉnh sửa sản phẩm (`GET /admin/san-pham/{id}/sua`).
     *
     * @param string $id ID sản phẩm từ URL.
     * @return void
     */
    public function edit(string $id): void
    {
        $productId = $this->parsePositiveId($id);
        if ($productId === null) {
            Flash::add('error', 'ID sản phẩm không hợp lệ.');
            $this->redirect('/admin/san-pham');
        }

        $product = $this->productModel->findById($productId);
        if ($product === null) {
            Flash::add('error', 'Không tìm thấy sản phẩm cần chỉnh sửa.');
            $this->redirect('/admin/san-pham');
        }

        $old = is_array($_SESSION['admin_product_old'] ?? null) ? $_SESSION['admin_product_old'] : [];
        unset($_SESSION['admin_product_old']);

        $formProduct = $old !== [] ? array_merge($product, $old['product'] ?? []) : $product;
        $specs       = $old !== [] ? ($old['specs'] ?? []) : $this->productModel->getSpecsByProductId($productId);
        $images      = $this->productModel->getImagesByProductId($productId);
        $categories  = $this->categoryModel->getAdminCategories();

        $hasDeps = ((int) ($product['total_units_count'] ?? 0) > 0)
            || ((int) ($product['order_items_count'] ?? 0) > 0)
            || ((int) ($product['cart_items_count'] ?? 0) > 0);

        $this->render('admin/products/form', [
            'pageTitle'    => 'Sửa sản phẩm: ' . $product['name'] . ' | TL Gaming Admin',
            'currentRoute' => '/admin/san-pham',
            'adminUser'    => Auth::user(),
            'breadcrumbs'  => [
                ['label' => 'Quản lý sản phẩm', 'url' => '/admin/san-pham'],
                ['label' => 'Sửa #' . $productId, 'url' => "/admin/san-pham/{$productId}/sua"],
            ],
            'mode'         => 'edit',
            'product'      => $formProduct,
            'dbProduct'    => $product,
            'specs'        => $specs,
            'images'       => $images,
            'categories'   => $categories,
            'formAction'   => "/admin/san-pham/{$productId}/sua",
            'hasDeps'      => $hasDeps,
        ], 'admin');
    }

    /**
     * Xử lý cập nhật thông tin sản phẩm, thông số và ảnh (`POST /admin/san-pham/{id}/sua`).
     *
     * @param string $id ID sản phẩm từ URL.
     * @return void
     */
    public function update(string $id): void
    {
        $productId = $this->parsePositiveId($id);
        if ($productId === null) {
            Flash::add('error', 'ID sản phẩm không hợp lệ.');
            $this->redirect('/admin/san-pham');
        }

        $existing = $this->productModel->findById($productId);
        if ($existing === null) {
            Flash::add('error', 'Không tìm thấy sản phẩm cần cập nhật.');
            $this->redirect('/admin/san-pham');
        }

        [$validated, $errors, $rawProduct] = $this->validateProductInput($_POST, false);
        [$specs, $specErrors, $rawSpecs]   = $this->validateSpecsInput($_POST);
        $errors = array_merge($errors, $specErrors);

        $primaryImageId = null;
        if (isset($_POST['primary_image_id']) && is_string($_POST['primary_image_id']) && ctype_digit(trim($_POST['primary_image_id']))) {
            $primaryImageId = (int) trim($_POST['primary_image_id']);
        }

        $imageSortOrders = [];
        if (isset($_POST['image_sort_orders']) && is_array($_POST['image_sort_orders'])) {
            foreach ($_POST['image_sort_orders'] as $imgKey => $sortVal) {
                if (ctype_digit((string) $imgKey) && is_scalar($sortVal) && preg_match('/^-?\d+$/', trim((string) $sortVal))) {
                    $imageSortOrders[(int) $imgKey] = (int) trim((string) $sortVal);
                }
            }
        }

        $existingImagesCount = count($this->productModel->getImagesByProductId($productId));
        $uploadedImages      = [];
        if ($errors === []) {
            [$uploadedImages, $uploadErrors] = $this->processUploadedImages($_FILES['images'] ?? null, $existingImagesCount);
            $errors = array_merge($errors, $uploadErrors);
        }

        if ($errors !== []) {
            $this->cleanupUploadedFiles($uploadedImages);
            $_SESSION['admin_product_old'] = ['product' => $rawProduct, 'specs' => $rawSpecs];
            Flash::add('error', $errors[0]);
            $this->redirect("/admin/san-pham/{$productId}/sua");
        }

        try {
            $this->productModel->updateProduct($productId, $validated, $specs, $uploadedImages, $primaryImageId, $imageSortOrders);
            unset($_SESSION['admin_product_old']);
            Flash::add('success', "Đã cập nhật sản phẩm \"{$validated['name']}\" thành công.");
            $this->redirect("/admin/san-pham/{$productId}/sua");
        } catch (DomainException $e) {
            $this->cleanupUploadedFiles($uploadedImages);
            $_SESSION['admin_product_old'] = ['product' => $rawProduct, 'specs' => $rawSpecs];
            Flash::add('error', $e->getMessage());
            $this->redirect("/admin/san-pham/{$productId}/sua");
        } catch (Throwable $e) {
            $this->cleanupUploadedFiles($uploadedImages);
            error_log('[Admin\ProductController] Lỗi cập nhật sản phẩm #' . $productId . ': ' . $e->getMessage());
            $_SESSION['admin_product_old'] = ['product' => $rawProduct, 'specs' => $rawSpecs];
            Flash::add('error', 'Hệ thống gặp sự cố kỹ thuật khi cập nhật sản phẩm. Vui lòng thử lại.');
            $this->redirect("/admin/san-pham/{$productId}/sua");
        }
    }

    /**
     * Ẩn sản phẩm (`status = 'archived'`) khỏi trang khách hàng (`POST /admin/san-pham/{id}/an`).
     */
    public function hide(string $id): void
    {
        $productId = $this->parsePositiveId($id);
        if ($productId === null) {
            Flash::add('error', 'ID sản phẩm không hợp lệ.');
            $this->redirect('/admin/san-pham');
        }

        try {
            $this->productModel->setProductStatus($productId, 'archived');
            Flash::add('success', 'Đã ẩn sản phẩm khỏi cửa hàng công khai (dữ liệu đơn hàng cũ vẫn được bảo toàn).');
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[Admin\ProductController] Lỗi ẩn sản phẩm #' . $productId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể ẩn sản phẩm lúc này.');
        }

        $this->redirect('/admin/san-pham');
    }

    /**
     * Xuất bản / hiển thị lại sản phẩm (`status = 'published'`) (`POST /admin/san-pham/{id}/hien`).
     */
    public function show(string $id): void
    {
        $productId = $this->parsePositiveId($id);
        if ($productId === null) {
            Flash::add('error', 'ID sản phẩm không hợp lệ.');
            $this->redirect('/admin/san-pham');
        }

        try {
            $this->productModel->setProductStatus($productId, 'published');
            Flash::add('success', 'Đã bật hiển thị (xuất bản) sản phẩm trở lại.');
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[Admin\ProductController] Lỗi hiển thị sản phẩm #' . $productId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể xuất bản sản phẩm lúc này.');
        }

        $this->redirect('/admin/san-pham');
    }

    /**
     * Tải lên thêm ảnh cho sản phẩm (`POST /admin/san-pham/{id}/anh/them`).
     */
    public function uploadImages(string $id): void
    {
        $productId = $this->parsePositiveId($id);
        if ($productId === null) {
            Flash::add('error', 'ID sản phẩm không hợp lệ.');
            $this->redirect('/admin/san-pham');
        }

        $existing = $this->productModel->findById($productId);
        if ($existing === null) {
            Flash::add('error', 'Không tìm thấy sản phẩm.');
            $this->redirect('/admin/san-pham');
        }

        $existingCount = count($this->productModel->getImagesByProductId($productId));
        [$uploadedImages, $uploadErrors] = $this->processUploadedImages($_FILES['images'] ?? null, $existingCount);

        if ($uploadErrors !== []) {
            $this->cleanupUploadedFiles($uploadedImages);
            Flash::add('error', $uploadErrors[0]);
            $this->redirect("/admin/san-pham/{$productId}/sua");
        }

        if ($uploadedImages === []) {
            Flash::add('error', 'Vui lòng chọn ít nhất một tệp ảnh hợp lệ để tải lên.');
            $this->redirect("/admin/san-pham/{$productId}/sua");
        }

        try {
            $this->productModel->addImagesToProduct($productId, $uploadedImages);
            Flash::add('success', 'Đã tải lên ' . count($uploadedImages) . ' hình ảnh mới cho sản phẩm.');
        } catch (DomainException $e) {
            $this->cleanupUploadedFiles($uploadedImages);
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->cleanupUploadedFiles($uploadedImages);
            error_log('[Admin\ProductController] Lỗi upload ảnh sản phẩm #' . $productId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể lưu hình ảnh lúc này. Vui lòng thử lại.');
        }

        $this->redirect("/admin/san-pham/{$productId}/sua");
    }

    /**
     * Xóa một hình ảnh thuộc đúng sản phẩm (`POST /admin/san-pham/{id}/anh/{imageId}/xoa`).
     */
    public function deleteImage(string $id, string $imageId): void
    {
        $productId = $this->parsePositiveId($id);
        $imgId     = $this->parsePositiveId($imageId);
        if ($productId === null || $imgId === null) {
            Flash::add('error', 'Tham số sản phẩm hoặc hình ảnh không hợp lệ.');
            $this->redirect('/admin/san-pham');
        }

        try {
            $safeUrlToDelete = $this->productModel->deleteProductImage($productId, $imgId);
            if ($safeUrlToDelete !== null && !$this->unlinkSafeProductUpload($safeUrlToDelete)) {
                error_log('[Admin\ProductController] Cảnh báo: Đã xóa bản ghi ảnh #' . $imgId . ' nhưng không thể xóa tệp vật lý: ' . $safeUrlToDelete);
                Flash::add('info', 'Đã xóa ảnh khỏi sản phẩm, nhưng tệp ảnh cũ trên máy chủ chưa thể xóa tự động (đã ghi nhật ký vận hành).');
            } else {
                Flash::add('success', 'Đã xóa hình ảnh khỏi sản phẩm.');
            }
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[Admin\ProductController] Lỗi xóa ảnh #' . $imgId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể xóa hình ảnh lúc này.');
        }

        $this->redirect("/admin/san-pham/{$productId}/sua");
    }

    /**
     * Kiểm tra ID nguyên dương từ tham số URL.
     */
    private function parsePositiveId(string $rawId): ?int
    {
        $trimmed = trim($rawId);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }
        $val = (int) $trimmed;
        return $val > 0 ? $val : null;
    }

    /**
     * Phân tích số nguyên có giới hạn biên bằng `filter_var`, chống tràn số nguyên PHP và CSDL.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function parseBoundedInt(string $raw, int $min, int $max, string $fieldLabel): array
    {
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            return [null, "{$fieldLabel} phải là số nguyên hợp lệ."];
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => $min,
                'max_range' => $max,
            ],
        ]);
        if ($parsed === false) {
            return [null, "{$fieldLabel} vượt quá giới hạn cho phép ({$min} đến {$max})."];
        }

        return [(int) $parsed, null];
    }

    /**
     * Đọc an toàn một trường vô hướng từ `$_POST` (chống mảng/object gây PHP warning).
     *
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: string}
     */
    private function extractScalarString(array $post, string $key, string $default = ''): array
    {
        if (!array_key_exists($key, $post)) {
            return [true, $default];
        }
        $val = $post[$key];
        if (!is_string($val) && !is_int($val) && !is_float($val)) {
            return [false, $default];
        }
        return [true, trim((string) $val)];
    }

    /**
     * Kiểm tra toàn diện dữ liệu sản phẩm trên Server (whitelist, kiểu dữ liệu, nghiệp vụ giá/tồn kho/danh mục).
     *
     * @param array<string, mixed> $post     Dữ liệu POST.
     * @param bool                 $isCreate Đang ở chế độ tạo mới hay cập nhật.
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<string, mixed>}
     */
    private function validateProductInput(array $post, bool $isCreate): array
    {
        [$nameOk, $name]               = $this->extractScalarString($post, 'name', '');
        [$skuOk, $sku]                 = $this->extractScalarString($post, 'sku', '');
        [$slugOk, $rawSlug]            = $this->extractScalarString($post, 'slug', '');
        [$catOk, $catRaw]              = $this->extractScalarString($post, 'category_id', '');
        [$sumOk, $summary]             = $this->extractScalarString($post, 'summary', '');
        [$descOk, $description]        = $this->extractScalarString($post, 'description', '');
        [$priceOk, $priceRaw]          = $this->extractScalarString($post, 'price', '');
        [$origOk, $origRaw]            = $this->extractScalarString($post, 'original_price', '');
        [$warrOk, $warrRaw]            = $this->extractScalarString($post, 'warranty_months', '12');
        [$condOk, $conditionOverview]  = $this->extractScalarString($post, 'condition_overview', '');
        [$statusOk, $status]           = $this->extractScalarString($post, 'status', 'draft');
        [$serialOk, $hasSerialsRaw]    = $this->extractScalarString($post, 'has_serials', '1');
        [$stockOk, $stockRaw]          = $this->extractScalarString($post, 'stock_quantity', '0');
        [$stockModeOk, $stockMode]     = $this->extractScalarString($post, 'stock_mode', $isCreate ? 'set' : 'adjust');
        [$adjOk, $stockAdjRaw]         = $this->extractScalarString($post, 'stock_adjustment', '0');
        [$expOk, $expectedStockRaw]    = $this->extractScalarString($post, 'expected_stock_quantity', '');

        $errors = [];

        if (!$nameOk) {
            $errors[] = 'Tên sản phẩm có kiểu dữ liệu không hợp lệ.';
        } elseif ($name === '' || mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 255) {
            $errors[] = 'Tên sản phẩm không được để trống và phải từ 2 đến 255 ký tự.';
        }

        if (!$skuOk) {
            $errors[] = 'Mã SKU có kiểu dữ liệu không hợp lệ.';
        } else {
            $sku = strtoupper($sku);
            if ($sku === '' || strlen($sku) > 50 || !preg_match('/^[A-Z0-9][A-Z0-9\-_]{1,49}$/', $sku)) {
                $errors[] = 'Mã SKU không hợp lệ (chỉ gồm chữ in hoa, số, dấu gạch ngang/dưới, dài 2–50 ký tự).';
            }
        }

        $slug = '';
        if (!$slugOk) {
            $errors[] = 'Đường dẫn tĩnh (Slug) có kiểu dữ liệu không hợp lệ.';
        } else {
            $slug = $rawSlug !== '' ? CategoryModel::toSlug($rawSlug) : ($nameOk ? CategoryModel::toSlug($name) : '');
            if ($slug === '' || strlen($slug) > 255 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $errors[] = 'Slug sản phẩm không hợp lệ (chỉ dùng chữ thường không dấu, số và dấu gạch ngang).';
            }
        }

        $categoryId = 0;
        if (!$catOk || $catRaw === '' || !ctype_digit($catRaw) || (int) $catRaw <= 0) {
            $errors[] = 'Vui lòng chọn danh mục sản phẩm hợp lệ.';
        } else {
            $categoryId = (int) $catRaw;
        }

        if (!$sumOk || mb_strlen($summary, 'UTF-8') > 500) {
            $errors[] = 'Tóm tắt sản phẩm không hợp lệ hoặc vượt quá 500 ký tự.';
        }
        if (!$descOk || mb_strlen($description, 'UTF-8') > 50000) {
            $errors[] = 'Mô tả chi tiết sản phẩm không hợp lệ.';
        }

        $price = 0;
        if (!$priceOk || $priceRaw === '') {
            $errors[] = 'Giá bán VND phải là số nguyên không âm hợp lệ.';
        } else {
            [$parsedPrice, $priceErr] = $this->parseBoundedInt($priceRaw, 0, AdminProductModel::MAX_PRICE_VND, 'Giá bán VND');
            if ($priceErr !== null) {
                $errors[] = $priceErr;
            } else {
                $price = (int) $parsedPrice;
            }
        }

        $originalPrice = null;
        if (!$origOk) {
            $errors[] = 'Giá gốc có kiểu dữ liệu không hợp lệ.';
        } elseif ($origRaw !== '') {
            [$parsedOrig, $origErr] = $this->parseBoundedInt($origRaw, 0, AdminProductModel::MAX_PRICE_VND, 'Giá gốc VND');
            if ($origErr !== null) {
                $errors[] = $origErr;
            } else {
                $originalPrice = (int) $parsedOrig;
                if ($originalPrice < $price) {
                    $errors[] = 'Giá gốc (niêm yết) phải lớn hơn hoặc bằng giá bán hiện tại.';
                }
            }
        }

        $warrantyMonths = 12;
        if (!$warrOk || $warrRaw === '') {
            $errors[] = 'Thời hạn bảo hành phải là số nguyên không âm (tháng).';
        } else {
            [$parsedWarr, $warrErr] = $this->parseBoundedInt($warrRaw, 0, 120, 'Thời hạn bảo hành');
            if ($warrErr !== null) {
                $errors[] = $warrErr;
            } else {
                $warrantyMonths = (int) $parsedWarr;
            }
        }

        if (!$condOk || $conditionOverview === '' || mb_strlen($conditionOverview, 'UTF-8') > 100) {
            $errors[] = 'Tổng quan tình trạng máy không được để trống và tối đa 100 ký tự.';
        }

        if (!$statusOk || !in_array($status, AdminProductModel::ALLOWED_STATUSES, true)) {
            $errors[] = 'Trạng thái sản phẩm không hợp lệ.';
        }

        $hasSerials = 1;
        if (!$serialOk || ($hasSerialsRaw !== '0' && $hasSerialsRaw !== '1')) {
            $errors[] = 'Loại quản lý tồn kho không hợp lệ.';
        } else {
            $hasSerials = (int) $hasSerialsRaw;
        }

        $stockQuantity   = 0;
        $stockAdjustment = 0;
        $expectedStock   = null;
        $cleanStockMode  = $isCreate ? 'set' : 'adjust';

        if (!$stockModeOk || ($stockMode !== 'adjust' && $stockMode !== 'set')) {
            $errors[] = 'Chế độ cập nhật tồn kho không hợp lệ (chỉ chấp nhận adjust hoặc set).';
        } else {
            $cleanStockMode = $stockMode;
        }

        if ($hasSerials === 0) {
            if ($isCreate || $cleanStockMode === 'set') {
                if (!$stockOk || $stockRaw === '') {
                    $errors[] = 'Số lượng tồn kho phải là số nguyên không âm hợp lệ.';
                } else {
                    [$parsedStock, $stockErr] = $this->parseBoundedInt($stockRaw, 0, AdminProductModel::MAX_STOCK_QUANTITY, 'Số lượng tồn kho');
                    if ($stockErr !== null) {
                        $errors[] = $stockErr;
                    } else {
                        $stockQuantity = (int) $parsedStock;
                    }
                }

                // Ở chế độ sửa với stock_mode=set, bắt buộc có expected_stock_quantity hợp lệ để không bỏ qua Optimistic Locking
                if (!$isCreate && $cleanStockMode === 'set') {
                    if (!$expOk || $expectedStockRaw === '') {
                        $errors[] = 'Thiếu số lượng tồn kho đối chiếu (expected_stock_quantity) khi cập nhật trực tiếp.';
                    } else {
                        [$parsedExp, $expErr] = $this->parseBoundedInt($expectedStockRaw, 0, AdminProductModel::MAX_STOCK_QUANTITY, 'Tồn kho đối chiếu');
                        if ($expErr !== null) {
                            $errors[] = $expErr;
                        } else {
                            $expectedStock = (int) $parsedExp;
                        }
                    }
                }
            } else {
                if (!$adjOk) {
                    $errors[] = 'Số lượng điều chỉnh tồn kho có kiểu dữ liệu không hợp lệ.';
                } elseif ($stockAdjRaw !== '') {
                    [$parsedAdj, $adjErr] = $this->parseBoundedInt(
                        $stockAdjRaw,
                        -AdminProductModel::MAX_STOCK_QUANTITY,
                        AdminProductModel::MAX_STOCK_QUANTITY,
                        'Số lượng điều chỉnh tồn kho'
                    );
                    if ($adjErr !== null) {
                        $errors[] = $adjErr;
                    } else {
                        $stockAdjustment = (int) $parsedAdj;
                    }
                }
            }
        }

        $validated = [
            'category_id'             => $categoryId,
            'name'                    => $name,
            'slug'                    => $slug,
            'sku'                     => $sku,
            'summary'                 => $summary,
            'description'             => $description,
            'price'                   => $price,
            'original_price'          => $originalPrice,
            'warranty_months'         => $warrantyMonths,
            'condition_overview'      => $conditionOverview,
            'status'                  => $status,
            'has_serials'             => $hasSerials,
            'stock_quantity'          => $stockQuantity,
            'stock_mode'              => $cleanStockMode,
            'stock_adjustment'        => $stockAdjustment,
            'expected_stock_quantity' => $expectedStock,
        ];

        $rawProduct = [
            'category_id'        => $catOk ? $catRaw : '',
            'name'               => $name,
            'slug'               => $slugOk ? ($rawSlug !== '' ? $rawSlug : $slug) : '',
            'sku'                => $skuOk ? $sku : '',
            'summary'            => $sumOk ? $summary : '',
            'description'        => $descOk ? $description : '',
            'price'              => $priceOk ? $priceRaw : '',
            'original_price'     => $origOk ? $origRaw : '',
            'warranty_months'    => $warrOk ? $warrRaw : '12',
            'condition_overview' => $condOk ? $conditionOverview : '',
            'status'             => $statusOk ? $status : 'draft',
            'has_serials'        => $serialOk ? $hasSerialsRaw : '1',
            'stock_quantity'     => $stockOk ? $stockRaw : '0',
        ];

        return [$validated, $errors, $rawProduct];
    }

    /**
     * Kiểm tra danh sách thông số kỹ thuật động (`product_specs`) gửi từ biểu mẫu.
     *
     * @param array<string, mixed> $post
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>, 2: array<int, array<string, mixed>>}
     */
    private function validateSpecsInput(array $post): array
    {
        $names  = $post['spec_name'] ?? [];
        $values = $post['spec_value'] ?? [];
        $groups = $post['spec_group'] ?? [];
        $orders = $post['spec_order'] ?? [];

        if (!is_array($names) || !is_array($values) || !is_array($groups) || !is_array($orders)) {
            return [[], ['Dữ liệu thông số kỹ thuật gửi lên không hợp lệ.'], []];
        }

        $specs    = [];
        $rawSpecs = [];
        $errors   = [];
        $seenKeys = [];

        $count = count($names);
        if ($count > 50) {
            return [[], ['Số lượng thông số kỹ thuật không được vượt quá 50 dòng.'], []];
        }

        for ($i = 0; $i < $count; $i++) {
            $rawName  = $names[$i] ?? '';
            $rawVal   = $values[$i] ?? '';
            $rawGroup = $groups[$i] ?? '';
            $rawOrder = $orders[$i] ?? (string) ($i + 1);

            if (!is_scalar($rawName) || !is_scalar($rawVal) || !is_scalar($rawGroup) || !is_scalar($rawOrder)) {
                $errors[] = 'Thông số kỹ thuật chứa kiểu dữ liệu không hợp lệ.';
                continue;
            }

            $sName  = trim((string) $rawName);
            $sVal   = trim((string) $rawVal);
            $sGroup = trim((string) $rawGroup);
            $sOrder = trim((string) $rawOrder);

            // Bỏ qua dòng hoàn toàn trống
            if ($sName === '' && $sVal === '') {
                continue;
            }

            $rawSpecs[] = [
                'group_name' => $sGroup,
                'spec_name'  => $sName,
                'spec_value' => $sVal,
                'sort_order' => $sOrder,
            ];

            if ($sName === '' || mb_strlen($sName, 'UTF-8') > 100) {
                $errors[] = 'Tên thông số kỹ thuật không được trống và tối đa 100 ký tự.';
                continue;
            }
            if ($sVal === '' || mb_strlen($sVal, 'UTF-8') > 255) {
                $errors[] = "Giá trị của thông số \"{$sName}\" không được trống và tối đa 255 ký tự.";
                continue;
            }
            if (mb_strlen($sGroup, 'UTF-8') > 100) {
                $errors[] = "Tên nhóm của thông số \"{$sName}\" tối đa 100 ký tự.";
                continue;
            }

            $dupKey = mb_strtolower($sGroup . '||' . $sName, 'UTF-8');
            if (isset($seenKeys[$dupKey])) {
                $errors[] = "Thông số \"{$sName}\" bị trùng lặp trong cùng nhóm.";
                continue;
            }
            $seenKeys[$dupKey] = true;

            $specs[] = [
                'group_name' => $sGroup,
                'spec_name'  => $sName,
                'spec_value' => $sVal,
                'sort_order' => preg_match('/^-?\d+$/', $sOrder) ? (int) $sOrder : ($i + 1),
            ];
        }

        return [$specs, $errors, $rawSpecs];
    }

    /**
     * Kiểm tra bảo mật và lưu nhiều tệp ảnh upload vào `public/uploads/products/`.
     *
     * Kiểm tra thực tế bằng `finfo` + `getimagesize`, từ chối tệp PHP hoặc giả mạo MIME,
     * sinh tên ngẫu nhiên an toàn và chống path traversal.
     *
     * @param mixed $files         Mảng `$_FILES['images']`.
     * @param int   $existingCount Số ảnh đã có của sản phẩm.
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function processUploadedImages(mixed $files, int $existingCount): array
    {
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
            return [[], []];
        }

        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/products';
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return [[], ['Không thể khởi tạo thư mục lưu ảnh sản phẩm trên máy chủ.']];
        }

        $realUploadDir = realpath($uploadDir);
        if ($realUploadDir === false) {
            return [[], ['Thư mục lưu ảnh sản phẩm không hợp lệ.']];
        }

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
        ];
        if (function_exists('imagewebp') || defined('IMAGETYPE_WEBP')) {
            $allowedMimes['image/webp'] = 'webp';
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $uploaded = [];
        $errors   = [];
        $fileCnt  = count($files['name']);

        for ($i = 0; $i < $fileCnt; $i++) {
            $errCode = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($errCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($existingCount + count($uploaded) >= self::MAX_IMAGES_PER_PRODUCT) {
                $errors[] = 'Mỗi sản phẩm chỉ được tải lên tối đa ' . self::MAX_IMAGES_PER_PRODUCT . ' hình ảnh.';
                break;
            }

            if ($errCode !== UPLOAD_ERR_OK) {
                $errors[] = 'Có lỗi xảy ra khi tải tệp ảnh lên máy chủ (mã lỗi: ' . $errCode . ').';
                continue;
            }

            $tmpPath = (string) ($files['tmp_name'][$i] ?? '');
            $size    = (int) ($files['size'][$i] ?? 0);

            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                $errors[] = 'Tệp tải lên không hợp lệ.';
                continue;
            }

            if ($size <= 0 || $size > self::MAX_IMAGE_BYTES) {
                $errors[] = 'Kích thước mỗi ảnh không được vượt quá 5 MB.';
                continue;
            }

            $realMime = $finfo !== false ? (string) finfo_file($finfo, $tmpPath) : '';
            if (!isset($allowedMimes[$realMime])) {
                $errors[] = 'Định dạng tệp ảnh không được phép (chỉ chấp nhận JPEG, PNG' . (isset($allowedMimes['image/webp']) ? ', WEBP' : '') . ').';
                continue;
            }

            $imgInfo = @getimagesize($tmpPath);
            if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1])) {
                $errors[] = 'Tệp tải lên không phải là hình ảnh hợp lệ.';
                continue;
            }

            $ext      = $allowedMimes[$realMime];
            $safeName = 'prod_' . bin2hex(random_bytes(16)) . '.' . $ext;
            $destPath = $realUploadDir . DIRECTORY_SEPARATOR . $safeName;

            if (!@move_uploaded_file($tmpPath, $destPath)) {
                $errors[] = 'Không thể lưu tệp ảnh vào thư mục lưu trữ.';
                continue;
            }

            $uploaded[] = [
                'image_url'  => '/uploads/products/' . $safeName,
                'alt_text'   => '',
                'sort_order' => $existingCount + count($uploaded) + 1,
                'disk_path'  => $destPath,
            ];
        }

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        if ($errors !== []) {
            $this->cleanupUploadedFiles($uploaded);
            return [[], $errors];
        }

        return [$uploaded, []];
    }

    /**
     * Dọn dẹp các tệp ảnh mới upload nếu giao dịch DB thất bại.
     *
     * @param array<int, array<string, mixed>> $uploadedImages
     */
    private function cleanupUploadedFiles(array $uploadedImages): void
    {
        foreach ($uploadedImages as $img) {
            $path = (string) ($img['disk_path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Xóa an toàn tệp ảnh trong `public/uploads/products/` sau khi đã xóa DB, chống path traversal.
     *
     * @return bool `true` nếu đã xóa thành công (hoặc tệp không còn tồn tại), `false` nếu xóa thất bại.
     */
    private function unlinkSafeProductUpload(string $relativeUrl): bool
    {
        $prefix = '/uploads/products/';
        if (!str_starts_with($relativeUrl, $prefix)) {
            return true;
        }

        $filename = basename($relativeUrl);
        if ($filename === '' || $filename === '.' || $filename === '..' || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
            return false;
        }

        $baseDir = realpath(dirname(__DIR__, 3) . '/public/uploads/products');
        if ($baseDir === false) {
            return false;
        }

        $target = realpath($baseDir . DIRECTORY_SEPARATOR . $filename);
        if ($target === false || !str_starts_with($target, $baseDir . DIRECTORY_SEPARATOR) || !is_file($target)) {
            return true;
        }

        return @unlink($target);
    }
}
