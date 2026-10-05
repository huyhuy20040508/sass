<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Hàng hóa — tab `products` của báo cáo cuối
 * ngày v2 (RevenueReportController::handleTableProducts + chart tab=product +
 * modal "Chi tiết hàng hóa").
 *
 * Bảng mỗi MẶT HÀNG một dòng; bấm vào mã thì hộp chi tiết ra từng hoá đơn có
 * mặt hàng đó (gọi ngầm `orders`). Dạng Biểu đồ: top bán chạy, số lượng theo
 * giờ / thứ / tháng — cùng một lượt gọi GET /admin/reports/goods.
 *
 * Tên route là `goods` vì `admin.reports.products` đã là trang báo cáo sản phẩm cũ.
 */
class GoodsReportController extends Controller
{
    use Concerns\LocHangHoa;

    /** Năm cột của bảng, đúng thứ tự và nhãn bản v2 (report-products-revenue). */
    public const COT = [
        'code' => 'Mã hàng hóa',
        'name' => 'Tên hàng hóa',
        'quantity' => 'Số lượng',
        'amount' => 'Tổng giá bán',
        'total' => 'Thành tiền (VAT)',
    ];

    /** Cột chữ — sắp theo bảng chữ cái thay vì theo số. */
    protected const COT_CHU = ['code', 'name'];

    /** Hai ô "Top" của biểu đồ chỉ có ba mức như v2. */
    public const MUC_TOP = [5, 10, 15];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $bao = [];
        $error = null;

        try {
            $res = $this->api->reportGoods($this->truyVan($filters) + array_filter([
                'keyword' => $filters['keyword'],
                'top' => $filters['top'],
                'sort' => $filters['top_sort'],
                'top_weekday' => $filters['top_weekday'],
            ], fn ($v) => $v !== ''));
            if ($res->successful()) {
                $bao = $res->json('data') ?? [];
            } else {
                Log::warning('Load goods report failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được báo cáo hàng hóa.';
            }
        } catch (\Throwable $e) {
            Log::error('Load goods report failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được báo cáo hàng hóa. Kiểm tra kết nối API.';
        }

        $dong = ReportController::sapXepDong($bao['rows'] ?? [], $filters['sort_field'], $filters['sort_type'], self::COT_CHU);

        if ($request->query('xuat') === 'excel') {
            return $this->xuat($dong, $bao['totals'] ?? [], $filters);
        }

        $cotTat = array_filter(explode(',', (string) $request->query('hide', '')));
        $columns = [];
        foreach (array_merge(['stt'], array_keys(self::COT)) as $c) {
            $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
        }

        $view = view('v2::reports.goods', [
            'filters' => $filters,
            'dong' => $dong,
            'tong' => $bao['totals'] ?? [],
            'bieuDo' => [
                'top' => $bao['top'] ?? [],
                'gio' => $bao['by_hour'] ?? [],
                'thu' => $bao['by_weekday'] ?? [],
                'thang' => $bao['by_month'] ?? [],
            ],
            'columns' => $columns,
            'chiNhanh' => CurrentBranch::danhSach()['ds'],
            'nhomHang' => $this->nhomHang(),
            'dsHang' => $this->hangCuaNhom($filters),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Hoá đơn có bán MỘT mặt hàng trong kỳ — hộp chi tiết, gọi ngầm, trả JSON đã
     * đổi nhãn. Cùng bộ lọc chi nhánh / nguồn đơn với bảng đang xem.
     */
    public function orders(Request $request)
    {
        $filters = $this->filters($request);
        $id = (int) $request->query('product_id', 0);
        if ($id <= 0) {
            return response()->json(['message' => 'Thiếu mặt hàng.'], 422);
        }

        try {
            $res = $this->api->reportGoodsOrders(['product_id' => $id] + $this->truyVan($filters));
        } catch (\Throwable $e) {
            Log::error('Load goods orders failed', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không tải được hoá đơn của mặt hàng. Kiểm tra kết nối API.'], 502);
        }
        if (! $res->successful()) {
            return response()->json(['message' => $res->json('message') ?: 'Không tải được hoá đơn của mặt hàng.'], $res->status());
        }

        $ds = array_map(fn ($h) => $h + [
            'nguon' => ReportController::NGUON_DON[$h['channel'] ?? ''] ?? '',
        ], $res->json('data') ?? []);
        $ten = (string) ($ds[0]['item_name'] ?? $request->query('name', ''));
        $ngay = fn ($s) => Carbon::parse($s)->format('d-m-Y');

        return response()->json([
            'data' => $ds,
            // Khuôn tiêu đề v2: "Chi tiết hàng hóa - TÊN (từ 00:00 / đến 23:59)".
            'tieu_de' => 'Chi tiết hàng hóa - '.mb_strtoupper($ten)
                .' ('.$ngay($filters['from_date']).' 00:00 / '.$ngay($filters['to_date']).' 23:59)',
        ]);
    }

    /** Bộ lọc: phần chung theo mặt hàng (LocHangHoa) + sắp xếp, hai ô Top. */
    protected function filters(Request $request): array
    {
        $top = fn (string $k) => in_array((int) $request->query($k), self::MUC_TOP, true) ? (string) (int) $request->query($k) : '5';

        return $this->locHang($request) + [
            'sort_field' => isset(self::COT[$request->query('sort_field')]) ? (string) $request->query('sort_field') : '',
            'top' => $top('top'),
            'top_sort' => $request->query('top_sort') === 'asc' ? 'asc' : 'desc',
            'top_weekday' => $top('top_weekday'),
        ];
    }

    /** Xuất đúng bảng đang xem (cùng bộ lọc, cùng thứ tự), kèm dòng tổng. */
    protected function xuat(array $dong, array $tong, array $filters)
    {
        $ten = 'bao-cao-hang-hoa-'.$filters['from_date'].'-den-'.$filters['to_date'].'.csv';

        return response()->streamDownload(function () use ($dong, $tong) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['STT'], array_values(self::COT)));
            foreach ($dong as $i => $d) {
                fputcsv($out, array_merge([$i + 1], array_map(fn ($k) => $d[$k] ?? '', array_keys(self::COT))));
            }
            if ($dong) {
                fputcsv($out, ['', '', 'Tổng cộng', $tong['quantity'] ?? 0, $tong['amount'] ?? 0, $tong['total'] ?? 0]);
            }
            fclose($out);
        }, $ten, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
