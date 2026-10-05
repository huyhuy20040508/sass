<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Chi phí & lợi nhuận — tab `expense` của báo
 * cáo cuối ngày v2 (RevenueReportController::handleTableExpense + chart tab=cost).
 *
 * Bảng mỗi MẶT HÀNG một dòng: bán bao nhiêu, tiền bán, tiền vốn, lãi, biên lãi
 * — kể cả mặt hàng đang bán mà kỳ này chưa bán được (mọi cột 0), như v2. Dạng
 * Biểu đồ: giá bán / giá vốn / lợi nhuận theo ngày / tuần / tháng. Cả hai từ một
 * lượt gọi GET /admin/reports/profit.
 */
class ProfitReportController extends Controller
{
    use Concerns\LocHangHoa;

    /** Tám cột của bảng, đúng thứ tự bản v2 (report-expense-revenue). */
    public const COT = [
        'code' => 'Mã',
        'name' => 'Tên hàng hóa',
        'category_name' => 'Danh mục',
        'quantity' => 'Số lượng bán',
        'revenue' => 'Tổng giá bán',
        'cost' => 'Tổng giá vốn',
        'profit' => 'Lợi nhuận',
        'margin' => 'Biên lợi nhuận (%)',
    ];

    protected const COT_CHU = ['code', 'name', 'category_name'];

    /** Mốc của biểu đồ. Bỏ trống thì API tự chọn theo độ dài kỳ. */
    public const MOC = ['day' => 'Theo ngày', 'week' => 'Theo tuần', 'month' => 'Theo tháng'];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $bao = [];
        $error = null;

        try {
            $res = $this->api->reportProfit($this->truyVan($filters) + array_filter([
                'keyword' => $filters['keyword'],
                'group_by' => $filters['group_by'],
            ], fn ($v) => $v !== ''));
            if ($res->successful()) {
                $bao = $res->json('data') ?? [];
            } else {
                Log::warning('Load profit report failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được báo cáo chi phí & lợi nhuận.';
            }
        } catch (\Throwable $e) {
            Log::error('Load profit report failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được báo cáo chi phí & lợi nhuận. Kiểm tra kết nối API.';
        }

        $dong = ReportController::sapXepDong($bao['rows'] ?? [], $filters['sort_field'], $filters['sort_type'], self::COT_CHU);

        if ($request->query('xuat') === 'excel') {
            return $this->xuat($dong, $bao['totals'] ?? [], $filters);
        }

        $cotTat = array_filter(explode(',', (string) $request->query('hide', '')));
        $columns = [];
        foreach (array_keys(self::COT) as $c) {
            $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
        }

        $view = view('v2::reports.profit', [
            'filters' => $filters,
            'dong' => $dong,
            'tong' => $bao['totals'] ?? [],
            'bieuDo' => ['moc' => $bao['group_by'] ?? 'day', 'cot' => $bao['chart'] ?? []],
            'columns' => $columns,
            'chiNhanh' => CurrentBranch::danhSach()['ds'],
            'nhomHang' => $this->nhomHang(),
            'dsHang' => $this->hangCuaNhom($filters),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Bộ lọc: phần chung theo mặt hàng (LocHangHoa) + sắp xếp, mốc biểu đồ, chiều biểu đồ. */
    protected function filters(Request $request): array
    {
        return $this->locHang($request) + [
            'sort_field' => isset(self::COT[$request->query('sort_field')]) ? (string) $request->query('sort_field') : '',
            'group_by' => isset(self::MOC[$request->query('group_by')]) ? (string) $request->query('group_by') : '',
            // Nút đảo của v2: mặc định mốc cũ bên trái; asc… desc = mới trước.
            'chart_sort' => $request->query('chart_sort') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /** Xuất đúng bảng đang xem (cùng bộ lọc, cùng thứ tự), kèm dòng tổng. */
    protected function xuat(array $dong, array $tong, array $filters)
    {
        $ten = 'bao-cao-chi-phi-loi-nhuan-'.$filters['from_date'].'-den-'.$filters['to_date'].'.csv';
        $so = fn ($k, $d) => $k === 'margin' ? round((float) ($d[$k] ?? 0), 2) : ($d[$k] ?? '');

        return response()->streamDownload(function () use ($dong, $tong, $so) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values(self::COT));
            foreach ($dong as $d) {
                fputcsv($out, array_map(fn ($k) => $so($k, $d), array_keys(self::COT)));
            }
            if ($dong) {
                fputcsv($out, array_merge(['Tổng cộng', '', ''], array_map(
                    fn ($k) => $so($k, $tong),
                    ['quantity', 'revenue', 'cost', 'profit', 'margin']
                )));
            }
            fclose($out);
        }, $ten, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
