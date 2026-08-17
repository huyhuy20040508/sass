<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Đơn quầy — những đơn do CHÍNH module thu ngân bán ra.
 *
 * VÌ SAO KHÔNG DÙNG TRANG ĐƠN HÀNG BÊN QUẢN TRỊ: hai loại đơn vận hành khác
 * hẳn nhau. Đơn 'web' còn phải xác nhận, soạn hàng, giao, đổi trạng thái —
 * trang bên kia dựng quanh việc đó, với bộ lọc nhiều tầng và các nút hàng loạt.
 * Đơn quầy thì xong ngay lúc tạo: người trực chỉ quay lại vì đúng một lý do là
 * khách cầm phiếu tới hỏi, hoặc phiếu in hỏng và phải in lại. Nên trang này bỏ
 * hết phần xử lý và làm rất tốt đúng hai việc: tìm nhanh một đơn, in lại phiếu.
 *
 * Bộ lọc mặc định là NGÀY HÔM NAY. Người đứng quầy hỏi "đơn vừa nãy", không hỏi
 * "đơn tháng trước"; mở trang ra mà đổ về cả nghìn đơn cũ thì cái cần tìm nằm
 * đâu đó trang thứ mười.
 */
class ThuNganController extends Controller
{
    public const TITLE = 'Đơn quầy';

    public const PAGE_SIZE = 20;

    public function __construct(protected ApiClient $api) {}

    public function donHang(Request $request)
    {
        $filters = $this->filters($request);
        $list = [];
        $meta = ['page' => $filters['page'], 'page_size' => self::PAGE_SIZE, 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->orders([
                // KHOÁ CỨNG channel=pos: đây là trang của module quầy, và một đơn
                // giao hàng lọt vào đây thì người trực sẽ tưởng mình phải làm gì
                // đó với nó — trong khi mọi nút xử lý đều nằm ở module kia.
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
            Log::error('Load don quay failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách đơn quầy. Kiểm tra kết nối API.';
        }

        $view = view('thu-ngan.don-hang', compact('list', 'filters', 'meta'));

        return $error ? $view->with('error', $error) : $view;
    }

    protected function filters(Request $request): array
    {
        $homNay = Carbon::now()->format('Y-m-d');
        $ngay = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';

        $tu = $ngay($request->query('from_date'));
        $den = $ngay($request->query('to_date'));
        $tuKhoa = trim((string) $request->query('keyword', ''));

        // Chưa chọn ngày nào thì lấy hôm nay cho CẢ HAI đầu. Chỉ đặt một đầu thì
        // "từ hôm nay" hoá ra là "từ hôm nay tới vô tận" — với đơn quầy thì tương
        // lai không có đơn nào, nhưng câu trên màn hình vẫn nói sai.
        //
        // TRỪ KHI có từ khoá: người gõ một mã đơn vào ô tìm là đang tìm đúng đơn
        // đó, ở bất kỳ ngày nào. Kẹp thêm ngày hôm nay vào là trả về "không tìm
        // thấy" cho một đơn đang nằm ngay trong sổ.
        if ($tu === '' && $den === '' && $tuKhoa === '') {
            $tu = $den = $homNay;
        }

        return [
            'keyword' => $tuKhoa,
            'from_date' => $tu,
            'to_date' => $den,
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }
}
