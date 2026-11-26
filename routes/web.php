<?php
/**
 * Bảng định tuyến Web chính thức (`routes/web.php`) của hệ thống TL Gaming.
 *
 * Vai trò:
 * - Khai báo toàn bộ các đường dẫn URL (GET / POST) cùng Controller, Action và Middleware tương ứng.
 * - Mọi route thuộc phân hệ Quản trị (`/admin`) bắt buộc gắn `AdminMiddleware` để chặn truy cập trái phép.
 */

declare(strict_types=1);

use App\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Controllers\Admin\ContactController as AdminContactController;
use App\Controllers\Admin\CouponController as AdminCouponController;
use App\Controllers\Admin\CustomerController as AdminCustomerController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\HomeBannerController as AdminHomeBannerController;
use App\Controllers\Admin\InspectionController as AdminInspectionController;
use App\Controllers\Admin\OrderController as AdminOrderController;
use App\Controllers\Admin\ProductController as AdminProductController;
use App\Controllers\Admin\SerialController as AdminSerialController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\HomeController;
use App\Controllers\ProductController;
use App\Core\Router;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;

/** @var Router $router */

// 1. Trang chủ TL Gaming hoàn chỉnh
$router->get('/', [HomeController::class, 'index']);

// 2. Trang danh sách sản phẩm & kết quả điều hướng tìm kiếm / danh mục (`GET /san-pham?q=...&danh-muc=...`)
$router->get('/san-pham', [ProductController::class, 'index']);

// 3. Trang chi tiết sản phẩm theo đường dẫn tĩnh SEO (`GET /san-pham/{slug}`)
$router->get('/san-pham/{slug}', [ProductController::class, 'show']);

// 4. Phân hệ Giỏ hàng chuẩn nghiệp vụ & bảo mật (Prompt 14)
$router->get('/gio-hang', [CartController::class, 'index']);
$router->post('/gio-hang/them', [CartController::class, 'add'], [CsrfMiddleware::class]);
$router->post('/gio-hang/cap-nhat', [CartController::class, 'update'], [CsrfMiddleware::class]);
$router->post('/gio-hang/xoa', [CartController::class, 'remove'], [CsrfMiddleware::class]);
$router->post('/gio-hang/xoa-tat-ca', [CartController::class, 'clear'], [CsrfMiddleware::class]);
$router->post('/gio-hang/mua-ngay', [CartController::class, 'buyNow'], [CsrfMiddleware::class]);

// 4.1. Phân hệ Thanh toán (Checkout), Tạo đơn hàng & Tra cứu đơn hàng (Prompt 15)
$router->get('/thanh-toan', [CheckoutController::class, 'showCheckout']);
$router->post('/thanh-toan/xem-truoc', [CheckoutController::class, 'previewSummary'], [CsrfMiddleware::class]);
$router->post('/thanh-toan', [CheckoutController::class, 'placeOrder'], [CsrfMiddleware::class]);
$router->get('/dat-hang/thanh-cong/{order_code}', [CheckoutController::class, 'orderSuccess']);
$router->get('/tra-cuu-don-hang', [CheckoutController::class, 'showLookup']);
$router->post('/tra-cuu-don-hang', [CheckoutController::class, 'lookup'], [CsrfMiddleware::class]);

// 5. Xác thực & tài khoản (Prompt 13)
//    - Mọi route POST gắn CsrfMiddleware để từ chối biểu mẫu thiếu/sai mã CSRF.
//    - Route hồ sơ & đổi mật khẩu gắn AuthMiddleware (khách vãng lai bị chuyển về /dang-nhap).
//    - Không có route công khai nào tạo tài khoản Admin (Admin chỉ tạo bằng CLI scripts/create_admin.php).
$router->get('/dang-nhap', [AuthController::class, 'showLogin']);
$router->post('/dang-nhap', [AuthController::class, 'login'], [CsrfMiddleware::class]);
$router->post('/dang-ky', [AuthController::class, 'register'], [CsrfMiddleware::class]);
$router->post('/dang-xuat', [AuthController::class, 'logout'], [CsrfMiddleware::class]);
$router->get('/tai-khoan', [AuthController::class, 'showProfile'], [AuthMiddleware::class]);
$router->post('/tai-khoan', [AuthController::class, 'updateProfile'], [CsrfMiddleware::class, AuthMiddleware::class]);
$router->get('/doi-mat-khau', [AuthController::class, 'showChangePassword'], [AuthMiddleware::class]);
$router->post('/doi-mat-khau', [AuthController::class, 'changePassword'], [CsrfMiddleware::class, AuthMiddleware::class]);

// 6. Phân hệ Quản trị (Admin) — Bảo vệ nghiêm ngặt bởi AdminMiddleware (trả 403 nếu chưa đăng nhập quyền Admin)
$router->get('/admin', [DashboardController::class, 'index'], [AdminMiddleware::class]);
$router->get('/admin/cai-dat/lien-he', [AdminContactController::class, 'edit'], [AdminMiddleware::class]);
$router->post('/admin/cai-dat/lien-he', [AdminContactController::class, 'update'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->get('/admin/cai-dat/banner-trang-chu', [AdminHomeBannerController::class, 'edit'], [AdminMiddleware::class]);
$router->post('/admin/cai-dat/banner-trang-chu', [AdminHomeBannerController::class, 'update'], [AdminMiddleware::class, CsrfMiddleware::class]);

// 6.1. Quản lý Danh mục sản phẩm (Prompt 16)
$router->get('/admin/danh-muc', [AdminCategoryController::class, 'index'], [AdminMiddleware::class]);
$router->get('/admin/danh-muc/them', [AdminCategoryController::class, 'create'], [AdminMiddleware::class]);
$router->post('/admin/danh-muc/them', [AdminCategoryController::class, 'store'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->get('/admin/danh-muc/{id}/sua', [AdminCategoryController::class, 'edit'], [AdminMiddleware::class]);
$router->post('/admin/danh-muc/{id}/sua', [AdminCategoryController::class, 'update'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/danh-muc/{id}/an', [AdminCategoryController::class, 'hide'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/danh-muc/{id}/hien', [AdminCategoryController::class, 'show'], [AdminMiddleware::class, CsrfMiddleware::class]);

// 6.2. Quản lý Sản phẩm Admin (Prompt 17)
$router->get('/admin/san-pham', [AdminProductController::class, 'index'], [AdminMiddleware::class]);
$router->get('/admin/san-pham/them', [AdminProductController::class, 'create'], [AdminMiddleware::class]);
$router->post('/admin/san-pham/them', [AdminProductController::class, 'store'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->get('/admin/san-pham/{id}/sua', [AdminProductController::class, 'edit'], [AdminMiddleware::class]);
$router->post('/admin/san-pham/{id}/sua', [AdminProductController::class, 'update'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/san-pham/{id}/an', [AdminProductController::class, 'hide'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/san-pham/{id}/hien', [AdminProductController::class, 'show'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/san-pham/{id}/anh/them', [AdminProductController::class, 'uploadImages'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/san-pham/{id}/anh/{imageId}/xoa', [AdminProductController::class, 'deleteImage'], [AdminMiddleware::class, CsrfMiddleware::class]);

// 6.3. Quản lý Serial & Kiểm định 18 bước Admin (Prompt 18)
$router->get('/admin/serial', [AdminSerialController::class, 'index'], [AdminMiddleware::class]);
$router->get('/admin/serial/them', [AdminSerialController::class, 'create'], [AdminMiddleware::class]);
$router->post('/admin/serial/them', [AdminSerialController::class, 'store'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->get('/admin/serial/{id}', [AdminSerialController::class, 'show'], [AdminMiddleware::class]);
$router->get('/admin/serial/{id}/sua', [AdminSerialController::class, 'edit'], [AdminMiddleware::class]);
$router->post('/admin/serial/{id}/sua', [AdminSerialController::class, 'update'], [AdminMiddleware::class, CsrfMiddleware::class]);

$router->get('/admin/serial/{id}/kiem-dinh', [AdminInspectionController::class, 'history'], [AdminMiddleware::class]);
$router->get('/admin/serial/{id}/kiem-dinh/them', [AdminInspectionController::class, 'create'], [AdminMiddleware::class]);
$router->post('/admin/serial/{id}/kiem-dinh/them', [AdminInspectionController::class, 'store'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->get('/admin/kiem-dinh/{inspectionId}', [AdminInspectionController::class, 'show'], [AdminMiddleware::class]);

// 6.4. Quản lý Đơn hàng & Hoàn tồn kho Admin (Prompt 19)
$router->get('/admin/don-hang', [AdminOrderController::class, 'index'], [AdminMiddleware::class]);
$router->get('/admin/don-hang/{id}', [AdminOrderController::class, 'show'], [AdminMiddleware::class]);
$router->post('/admin/don-hang/{id}/xac-nhan', [AdminOrderController::class, 'confirm'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/don-hang/{id}/xac-nhan-thanh-toan', [AdminOrderController::class, 'confirmPayment'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/don-hang/{id}/giao-hang', [AdminOrderController::class, 'ship'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/don-hang/{id}/hoan-tat', [AdminOrderController::class, 'complete'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/don-hang/{id}/huy', [AdminOrderController::class, 'cancel'], [AdminMiddleware::class, CsrfMiddleware::class]);

// 6.5. Quản lý Khách hàng Admin (Prompt 20)
$router->get('/admin/khach-hang', [AdminCustomerController::class, 'index'], [AdminMiddleware::class]);
$router->get('/admin/khach-hang/{id}', [AdminCustomerController::class, 'show'], [AdminMiddleware::class]);
$router->post('/admin/khach-hang/{id}/khoa', [AdminCustomerController::class, 'lock'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/khach-hang/{id}/mo-khoa', [AdminCustomerController::class, 'unlock'], [AdminMiddleware::class, CsrfMiddleware::class]);

// 6.6. Quản lý Voucher Admin (Prompt 20)
$router->get('/admin/voucher', [AdminCouponController::class, 'index'], [AdminMiddleware::class]);
$router->get('/admin/voucher/them', [AdminCouponController::class, 'create'], [AdminMiddleware::class]);
$router->post('/admin/voucher/them', [AdminCouponController::class, 'store'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->get('/admin/voucher/{id}/sua', [AdminCouponController::class, 'edit'], [AdminMiddleware::class]);
$router->post('/admin/voucher/{id}/sua', [AdminCouponController::class, 'update'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/voucher/{id}/bat', [AdminCouponController::class, 'enable'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->post('/admin/voucher/{id}/tat', [AdminCouponController::class, 'disable'], [AdminMiddleware::class, CsrfMiddleware::class]);
$router->get('/admin/voucher/{id}/lich-su', [AdminCouponController::class, 'history'], [AdminMiddleware::class]);
