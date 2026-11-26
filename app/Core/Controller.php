<?php
/**
 * Lớp Controller cơ sở (Base Controller) cho toàn bộ các Controller trong hệ thống TL Gaming.
 *
 * Vai trò:
 * - Cung cấp các phương thức dùng chung để kết xuất giao diện (`render`), chuyển hướng (`redirect`),
 *   trả về phản hồi JSON (`json`) và dừng xử lý với mã lỗi HTTP (`abort`).
 */

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    /**
     * Kết xuất một tệp View cùng với dữ liệu và Layout chỉ định.
     *
     * @param string               $viewPath Đường dẫn View trong `app/Views` (ví dụ: 'home/index').
     * @param array<string, mixed> $data     Mảng dữ liệu truyền sang View.
     * @param string|null          $layout   Tên tệp layout trong `app/Views/layouts` (mặc định: 'main').
     * @return void
     */
    protected function render(string $viewPath, array $data = [], ?string $layout = 'main'): void
    {
        View::render($viewPath, $data, $layout);
    }

    /**
     * Chuyển hướng trình duyệt sang một đường dẫn nội bộ khác và kết thúc Request.
     *
     * @param string $path       Đường dẫn đích (ví dụ: '/dang-nhap').
     * @param int    $statusCode Mã trạng thái HTTP chuyển hướng (mặc định: 302 Found).
     * @return never
     */
    protected function redirect(string $path, int $statusCode = 302): never
    {
        $targetUrl = View::url($path);
        header("Location: {$targetUrl}", true, $statusCode);
        exit;
    }

    /**
     * Trả về phản hồi định dạng JSON (phục vụ các yêu cầu AJAX nếu có).
     *
     * @param array<string, mixed> $payload    Dữ liệu trả về.
     * @param int                  $statusCode Mã trạng thái HTTP (mặc định: 200).
     * @return never
     */
    protected function json(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Tính toán thông số phân trang (Pagination) và tạo danh sách URL giữ nguyên các tham số lọc/tìm kiếm hiện hành.
     * Hàm tái sử dụng cho danh sách sản phẩm và các danh sách dữ liệu khác trong hệ thống.
     *
     * @param int                  $totalItems  Tổng số bản ghi thỏa mãn điều kiện lọc.
     * @param int                  $requestedPage Số trang người dùng yêu cầu (đã chuẩn hóa >= 1).
     * @param int                  $perPage     Số bản ghi trên mỗi trang (mặc định: 12).
     * @param string               $basePath    Đường dẫn cơ sở (ví dụ: '/san-pham').
     * @param array<string, mixed> $queryParams Các tham số Query String cần giữ lại khi chuyển trang (`q`, `danh-muc`, ...).
     * @return array{
     *     total_items: int,
     *     per_page: int,
     *     current_page: int,
     *     total_pages: int,
     *     offset: int,
     *     from_item: int,
     *     to_item: int,
     *     has_prev: bool,
     *     has_next: bool,
     *     prev_url: string|null,
     *     next_url: string|null,
     *     pages: array<int, array{number: int, url: string, is_current: bool}>
     * }
     */
    protected function buildPagination(
        int $totalItems,
        int $requestedPage,
        int $perPage = 12,
        string $basePath = '/san-pham',
        array $queryParams = []
    ): array {
        $safePerPage    = max(1, $perPage);
        $safeTotalItems = max(0, $totalItems);
        $totalPages     = $safeTotalItems > 0 ? (int) ceil($safeTotalItems / $safePerPage) : 1;

        // Giới hạn trang hiện tại nằm trong khoảng [1 .. totalPages] để tránh truy vấn OFFSET vượt quá dữ liệu thực
        $currentPage = max(1, min($requestedPage, $totalPages));
        $offset      = ($currentPage - 1) * $safePerPage;

        $fromItem = $safeTotalItems > 0 ? $offset + 1 : 0;
        $toItem   = $safeTotalItems > 0 ? min($safeTotalItems, $offset + $safePerPage) : 0;

        // Lọc bỏ các tham số rỗng hoặc mặc định trước khi ghép vào Query String
        $cleanQuery = [];
        foreach ($queryParams as $key => $val) {
            if ($key === 'page' || $val === null || $val === '') {
                continue;
            }
            $cleanQuery[$key] = (string) $val;
        }

        $makePageUrl = static function (int $pageNum) use ($basePath, $cleanQuery): string {
            $params = $cleanQuery;
            if ($pageNum > 1) {
                $params['page'] = (string) $pageNum;
            }
            $qs = http_build_query($params);
            return View::url($basePath) . ($qs !== '' ? '?' . $qs : '');
        };

        $pages = [];
        for ($p = 1; $p <= $totalPages; $p++) {
            $pages[] = [
                'number'     => $p,
                'url'        => $makePageUrl($p),
                'is_current' => ($p === $currentPage),
            ];
        }

        $hasPrev = $currentPage > 1;
        $hasNext = $currentPage < $totalPages;

        return [
            'total_items'  => $safeTotalItems,
            'per_page'     => $safePerPage,
            'current_page' => $currentPage,
            'total_pages'  => $totalPages,
            'offset'       => $offset,
            'from_item'    => $fromItem,
            'to_item'      => $toItem,
            'has_prev'     => $hasPrev,
            'has_next'     => $hasNext,
            'prev_url'     => $hasPrev ? $makePageUrl($currentPage - 1) : null,
            'next_url'     => $hasNext ? $makePageUrl($currentPage + 1) : null,
            'pages'        => $pages,
        ];
    }
}
