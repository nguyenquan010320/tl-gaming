<?php
/**
 * Controller Quản lý Danh mục (`app/Controllers/Admin/CategoryController.php`) thuộc phân hệ Admin.
 *
 * Vai trò & bảo mật:
 * - Chỉ được thực thi sau `AdminMiddleware` (đã xác thực `Auth::isAdmin()` và `Auth::revalidate()`).
 * - Mọi thao tác làm thay đổi dữ liệu (`store`, `update`, `hide`, `show`) bắt buộc dùng phương thức `POST`
 *   được bảo vệ bởi `CsrfMiddleware` và áp dụng mô hình PRG (Post/Redirect/Get).
 * - Tuyệt đối chỉ tiếp nhận các trường dữ liệu nằm trong whitelist (`name`, `slug`, `description`,
 *   `parent_id`, `display_order`, `is_active`); không tin `user_id` hay `role` từ Request.
 * - Không hiển thị thông báo lỗi SQL thô ra giao diện người dùng.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\CategoryModel;
use DomainException;
use Throwable;

class CategoryController extends Controller
{
    private CategoryModel $categoryModel;

    public function __construct()
    {
        $this->categoryModel = new CategoryModel();
    }

    /**
     * Hiển thị danh sách danh mục quản trị (`GET /admin/danh-muc`),
     * hỗ trợ tìm kiếm theo tên/slug, lọc trạng thái và phân trang.
     *
     * @return void
     */
    public function index(): void
    {
        $keyword = trim((string) ($_GET['q'] ?? ''));
        $statusParam = trim((string) ($_GET['trang-thai'] ?? ''));
        $statusFilter = match ($statusParam) {
            '1'     => 1,
            '0'     => 0,
            default => null,
        };

        $requestedPage = max(1, (int) ($_GET['page'] ?? 1));
        $perPage       = 15;

        try {
            $allCategories = $this->categoryModel->getAdminCategories($keyword, $statusFilter);
        } catch (Throwable $e) {
            error_log('[Admin\CategoryController] Lỗi tải danh sách danh mục: ' . $e->getMessage());
            $allCategories = [];
            Flash::add('error', 'Không thể tải danh sách danh mục lúc này. Vui lòng thử lại.');
        }

        $totalItems = count($allCategories);
        $pagination = $this->buildPagination($totalItems, $requestedPage, $perPage, '/admin/danh-muc', [
            'q'          => $keyword !== '' ? $keyword : null,
            'trang-thai' => $statusParam !== '' ? $statusParam : null,
        ]);

        $categories = array_slice($allCategories, $pagination['offset'], $pagination['per_page']);

        $this->render('admin/categories/index', [
            'pageTitle'    => 'Quản lý danh mục | TL Gaming Admin',
            'currentRoute' => '/admin/danh-muc',
            'adminUser'    => Auth::user(),
            'breadcrumbs'  => [
                ['label' => 'Quản lý danh mục', 'url' => '/admin/danh-muc'],
            ],
            'categories'   => $categories,
            'totalItems'   => $totalItems,
            'keyword'      => $keyword,
            'statusParam'  => $statusParam,
            'pagination'   => $pagination,
        ], 'admin');
    }

    /**
     * Hiển thị biểu mẫu thêm danh mục mới (`GET /admin/danh-muc/them`).
     *
     * @return void
     */
    public function create(): void
    {
        $old = is_array($_SESSION['admin_category_old'] ?? null) ? $_SESSION['admin_category_old'] : [];
        unset($_SESSION['admin_category_old']);

        $parentOptions = $this->categoryModel->getParentOptions(null);

        $this->render('admin/categories/form', [
            'pageTitle'     => 'Thêm danh mục mới | TL Gaming Admin',
            'currentRoute'  => '/admin/danh-muc',
            'adminUser'     => Auth::user(),
            'breadcrumbs'   => [
                ['label' => 'Quản lý danh mục', 'url' => '/admin/danh-muc'],
                ['label' => 'Thêm danh mục', 'url' => '/admin/danh-muc/them'],
            ],
            'mode'          => 'create',
            'category'      => $old !== [] ? $old : [
                'id'            => 0,
                'name'          => '',
                'slug'          => '',
                'description'   => '',
                'parent_id'     => '',
                'display_order' => 0,
                'is_active'     => 1,
            ],
            'parentOptions' => $parentOptions,
            'formAction'    => '/admin/danh-muc/them',
        ], 'admin');
    }

    /**
     * Xử lý tạo mới danh mục (`POST /admin/danh-muc/them`) theo mô hình PRG.
     *
     * @return void
     */
    public function store(): void
    {
        [$validated, $errors, $rawInput] = $this->validateCategoryInput($_POST);

        if ($errors !== []) {
            $_SESSION['admin_category_old'] = $rawInput;
            Flash::add('error', $errors[0]);
            $this->redirect('/admin/danh-muc/them');
        }

        try {
            $newId = $this->categoryModel->createCategory($validated);
            unset($_SESSION['admin_category_old']);
            Flash::add('success', "Đã tạo danh mục \"{$validated['name']}\" (ID: #{$newId}) thành công.");
            $this->redirect('/admin/danh-muc');
        } catch (DomainException $e) {
            $_SESSION['admin_category_old'] = $rawInput;
            Flash::add('error', $e->getMessage());
            $this->redirect('/admin/danh-muc/them');
        } catch (Throwable $e) {
            error_log('[Admin\CategoryController] Lỗi tạo danh mục: ' . $e->getMessage());
            $_SESSION['admin_category_old'] = $rawInput;
            Flash::add('error', 'Hệ thống gặp sự cố kỹ thuật khi lưu danh mục. Vui lòng thử lại.');
            $this->redirect('/admin/danh-muc/them');
        }
    }

    /**
     * Hiển thị biểu mẫu chỉnh sửa danh mục (`GET /admin/danh-muc/{id}/sua`).
     *
     * @param string $id ID danh mục truyền từ URL.
     * @return void
     */
    public function edit(string $id): void
    {
        $catId = $this->parsePositiveId($id);
        if ($catId === null) {
            Flash::add('error', 'ID danh mục không hợp lệ.');
            $this->redirect('/admin/danh-muc');
        }

        $category = $this->categoryModel->findById($catId);
        if ($category === null) {
            Flash::add('error', 'Không tìm thấy danh mục cần chỉnh sửa.');
            $this->redirect('/admin/danh-muc');
        }

        $old = is_array($_SESSION['admin_category_old'] ?? null) ? $_SESSION['admin_category_old'] : [];
        unset($_SESSION['admin_category_old']);

        $formData = $old !== [] ? array_merge($category, $old) : $category;
        $parentOptions = $this->categoryModel->getParentOptions($catId);

        $this->render('admin/categories/form', [
            'pageTitle'     => 'Sửa danh mục: ' . $category['name'] . ' | TL Gaming Admin',
            'currentRoute'  => '/admin/danh-muc',
            'adminUser'     => Auth::user(),
            'breadcrumbs'   => [
                ['label' => 'Quản lý danh mục', 'url' => '/admin/danh-muc'],
                ['label' => 'Sửa #' . $catId, 'url' => "/admin/danh-muc/{$catId}/sua"],
            ],
            'mode'          => 'edit',
            'category'      => $formData,
            'parentOptions' => $parentOptions,
            'formAction'    => "/admin/danh-muc/{$catId}/sua",
        ], 'admin');
    }

    /**
     * Xử lý cập nhật danh mục (`POST /admin/danh-muc/{id}/sua`) theo mô hình PRG.
     *
     * @param string $id ID danh mục truyền từ URL.
     * @return void
     */
    public function update(string $id): void
    {
        $catId = $this->parsePositiveId($id);
        if ($catId === null) {
            Flash::add('error', 'ID danh mục không hợp lệ.');
            $this->redirect('/admin/danh-muc');
        }

        $existing = $this->categoryModel->findById($catId);
        if ($existing === null) {
            Flash::add('error', 'Không tìm thấy danh mục cần cập nhật.');
            $this->redirect('/admin/danh-muc');
        }

        [$validated, $errors, $rawInput] = $this->validateCategoryInput($_POST);

        if ($errors !== []) {
            $_SESSION['admin_category_old'] = $rawInput;
            Flash::add('error', $errors[0]);
            $this->redirect("/admin/danh-muc/{$catId}/sua");
        }

        try {
            $cascadedCount = $this->categoryModel->updateCategory($catId, $validated);
            unset($_SESSION['admin_category_old']);

            $msg = "Đã cập nhật danh mục \"{$validated['name']}\" thành công.";
            if ((string) $existing['slug'] !== (string) $validated['slug']) {
                $msg .= " Lưu ý: đường dẫn lọc sản phẩm đã đổi sang /san-pham?danh-muc={$validated['slug']}.";
            }
            if ($cascadedCount > 0) {
                $msg .= " Đồng thời đã ẩn {$cascadedCount} danh mục con trực thuộc.";
            }

            Flash::add('success', $msg);
            $this->redirect('/admin/danh-muc');
        } catch (DomainException $e) {
            $_SESSION['admin_category_old'] = $rawInput;
            Flash::add('error', $e->getMessage());
            $this->redirect("/admin/danh-muc/{$catId}/sua");
        } catch (Throwable $e) {
            error_log('[Admin\CategoryController] Lỗi cập nhật danh mục #' . $catId . ': ' . $e->getMessage());
            $_SESSION['admin_category_old'] = $rawInput;
            Flash::add('error', 'Hệ thống gặp sự cố kỹ thuật khi cập nhật danh mục. Vui lòng thử lại.');
            $this->redirect("/admin/danh-muc/{$catId}/sua");
        }
    }

    /**
     * Ẩn danh mục và tự động ẩn dây chuyền các danh mục con trực thuộc (`POST /admin/danh-muc/{id}/an`).
     *
     * @param string $id ID danh mục truyền từ URL.
     * @return void
     */
    public function hide(string $id): void
    {
        $catId = $this->parsePositiveId($id);
        if ($catId === null) {
            Flash::add('error', 'ID danh mục không hợp lệ.');
            $this->redirect('/admin/danh-muc');
        }

        try {
            $cascaded = $this->categoryModel->setCategoryStatus($catId, false);
            $msg      = 'Đã ẩn danh mục khỏi trang cửa hàng công khai.';
            if ($cascaded > 0) {
                $msg .= " Đã tự động ẩn kèm {$cascaded} danh mục con trực thuộc.";
            }
            Flash::add('success', $msg);
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[Admin\CategoryController] Lỗi ẩn danh mục #' . $catId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể ẩn danh mục lúc này. Vui lòng thử lại.');
        }

        $this->redirect('/admin/danh-muc');
    }

    /**
     * Hiển thị lại danh mục (`POST /admin/danh-muc/{id}/hien`).
     *
     * @param string $id ID danh mục truyền từ URL.
     * @return void
     */
    public function show(string $id): void
    {
        $catId = $this->parsePositiveId($id);
        if ($catId === null) {
            Flash::add('error', 'ID danh mục không hợp lệ.');
            $this->redirect('/admin/danh-muc');
        }

        try {
            $this->categoryModel->setCategoryStatus($catId, true);
            Flash::add('success', 'Đã bật hiển thị danh mục trở lại.');
        } catch (DomainException $e) {
            Flash::add('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[Admin\CategoryController] Lỗi hiển thị danh mục #' . $catId . ': ' . $e->getMessage());
            Flash::add('error', 'Không thể bật hiển thị danh mục lúc này. Vui lòng thử lại.');
        }

        $this->redirect('/admin/danh-muc');
    }

    /**
     * Phân tích và kiểm tra ID danh mục truyền qua tham số URL.
     * Chỉ chấp nhận chuỗi chữ số nguyên dương hợp lệ (chống ép kiểu ngầm như "12abc" -> 12).
     *
     * @param string $rawId Chuỗi ID nhận từ Router.
     * @return int|null ID nguyên dương hoặc null nếu không hợp lệ.
     */
    private function parsePositiveId(string $rawId): ?int
    {
        $trimmed = trim($rawId);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }
        $id = (int) $trimmed;
        return $id > 0 ? $id : null;
    }

    /**
     * Trích xuất an toàn một trường kiểu vô hướng (chuỗi/số) từ `$_POST`,
     * từ chối mảng/object để tránh cảnh báo `Array to string conversion`.
     *
     * @param array<string, mixed> $post    Dữ liệu POST.
     * @param string               $key     Tên trường cần đọc.
     * @param string               $default Giá trị mặc định khi trường vắng mặt hoặc sai kiểu.
     * @return array{0: bool, 1: string} [kiểu_hợp_lệ, giá_trị_đã_trim].
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
     * Kiểm tra kiểu dữ liệu và chuẩn hóa dữ liệu đầu vào từ biểu mẫu Thêm/Sửa danh mục theo danh sách trường whitelist.
     * Bảo toàn toàn bộ dữ liệu hợp lệ đã nhập trong `$rawInput` khi có lỗi validation.
     *
     * @param array<string, mixed> $post Dữ liệu `$_POST` từ biểu mẫu.
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<string, mixed>}
     */
    private function validateCategoryInput(array $post): array
    {
        [$nameOk, $name]                 = $this->extractScalarString($post, 'name', '');
        [$slugOk, $rawSlug]              = $this->extractScalarString($post, 'slug', '');
        [$descOk, $description]          = $this->extractScalarString($post, 'description', '');
        [$parentOk, $parentRaw]          = $this->extractScalarString($post, 'parent_id', '');
        [$orderOk, $displayOrderRaw]     = $this->extractScalarString($post, 'display_order', '0');
        [$activeOk, $isActiveRaw]        = $this->extractScalarString($post, 'is_active', '1');

        $errors = [];

        if (!$nameOk) {
            $errors[] = 'Tên danh mục phải là chuỗi ký tự hợp lệ.';
        } elseif ($name === '' || mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 100) {
            $errors[] = 'Tên danh mục không được để trống và phải dài từ 2 đến 100 ký tự.';
        }

        $slug = '';
        if (!$slugOk) {
            $errors[] = 'Đường dẫn tĩnh (slug) phải là chuỗi ký tự hợp lệ.';
        } else {
            $slug = $rawSlug !== '' ? CategoryModel::toSlug($rawSlug) : ($nameOk ? CategoryModel::toSlug($name) : '');
            if ($slug === '' || strlen($slug) > 120 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $errors[] = 'Slug danh mục không hợp lệ (chỉ dùng chữ thường không dấu, số và dấu gạch ngang).';
            }
        }

        $parentId = null;
        if (!$parentOk) {
            $errors[] = 'Danh mục cha có kiểu dữ liệu không hợp lệ.';
        } elseif ($parentRaw !== '') {
            if (ctype_digit($parentRaw) && (int) $parentRaw > 0) {
                $parentId = (int) $parentRaw;
            } else {
                $errors[] = 'Danh mục cha được chọn không hợp lệ.';
            }
        }

        if (!$descOk) {
            $errors[] = 'Mô tả danh mục phải là chuỗi ký tự hợp lệ.';
        } elseif (mb_strlen($description, 'UTF-8') > 2000) {
            $errors[] = 'Mô tả danh mục không được vượt quá 2.000 ký tự.';
        }

        $displayOrder = 0;
        if (!$orderOk) {
            $errors[] = 'Thứ tự hiển thị có kiểu dữ liệu không hợp lệ.';
        } elseif ($displayOrderRaw === '') {
            $displayOrder = 0;
        } elseif (!preg_match('/^-?\d+$/', $displayOrderRaw)) {
            $errors[] = 'Thứ tự hiển thị phải là số nguyên hợp lệ.';
        } else {
            $displayOrder = (int) $displayOrderRaw;
            if ($displayOrder < -9999 || $displayOrder > 9999) {
                $errors[] = 'Thứ tự hiển thị phải nằm trong khoảng -9999 đến 9999.';
            }
        }

        $isActive = 1;
        if (!$activeOk) {
            $errors[] = 'Trạng thái hiển thị có kiểu dữ liệu không hợp lệ.';
        } elseif ($isActiveRaw !== '0' && $isActiveRaw !== '1') {
            $errors[] = 'Trạng thái hiển thị không hợp lệ.';
        } else {
            $isActive = (int) $isActiveRaw;
        }

        $validated = [
            'name'          => $name,
            'slug'          => $slug,
            'description'   => $description,
            'parent_id'     => $parentId,
            'display_order' => $displayOrder,
            'is_active'     => $isActive,
        ];

        $rawInput = [
            'name'          => $name,
            'slug'          => $rawSlug !== '' ? $rawSlug : $slug,
            'description'   => $description,
            'parent_id'     => $parentOk ? $parentRaw : '',
            'display_order' => $orderOk ? ($displayOrderRaw !== '' ? $displayOrderRaw : '0') : '0',
            'is_active'     => $isActive,
        ];

        return [$validated, $errors, $rawInput];
    }
}
