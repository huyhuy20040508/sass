<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lịch sử đơn — những đơn do CHÍNH quầy bán ra.
 *
 * Không dùng trang Đơn hàng bên quản trị: đơn quầy xong ngay lúc tạo, người
 * trực chỉ quay lại để tìm một đơn khách đang hỏi hoặc in lại phiếu hỏng.
 * Mặc định lọc HÔM NAY — người đứng quầy hỏi "đơn vừa nãy", không hỏi "đơn
 * tháng trước".
 */
class CashierController extends Controller
{
    use Concerns\V2ScreenFallback;

    public const TITLE = 'Lịch sử đơn';

    public const PAGE_SIZE = 20;

    public function __construct(protected ApiClient $api) {}

    public function donHang(Request $request)
    {
        if ($ve = $this->veQuayNeuThieuView('v2::pos.orders')) {
            return $ve;
        }

        $filters = $this->filters($request);
        $list = [];
        $meta = ['page' => $filters['page'], 'page_size' => self::PAGE_SIZE, 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->orders([
                // KHOÁ CỨNG channel=pos: một đơn giao hàng lọt vào đây thì người
                // trực tưởng mình phải làm gì đó với nó, mà nút xử lý ở module kia.
                'channel' => 'pos',
                'keyword' => $filters['keyword'],
                'from_date' => $filters['from_date'],
                'to_date' => $filters['to_date'],
                'sort' => 'newest',
                'page' => $filters['page'],
                'page_size' => self::PAGE_SIZE,
            ]);

            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách đơn quầy.';
            }
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai don quay that bai', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách đơn quầy. Kiểm tra kết nối API.';
        }

        $view = view('v2::pos.orders', compact('list', 'filters', 'meta'));

        return $error ? $view->with('error', $error) : $view;
    }

    protected function filters(Request $request): array
    {
        $ngay = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        $tu = $ngay($request->query('from_date'));
        $den = $ngay($request->query('to_date'));
        $tuKhoa = trim((string) $request->query('keyword', ''));

        // Chưa chọn ngày: hôm nay cho CẢ HAI đầu. TRỪ KHI có từ khoá — gõ mã đơn là
        // tìm đúng đơn đó ở bất kỳ ngày nào, kẹp hôm nay vào là báo "không thấy"
        // cho một đơn đang nằm ngay trong sổ.
        if ($tu === '' && $den === '' && $tuKhoa === '') {
            $tu = $den = Carbon::now()->format('Y-m-d');
        }

        return [
            'keyword' => $tuKhoa,
            'from_date' => $tu,
            'to_date' => $den,
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }
}
