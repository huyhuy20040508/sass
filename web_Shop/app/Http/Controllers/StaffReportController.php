<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Báo cáo ca — tab `employee` của báo cáo cuối
 * ngày v2 (RevenueReportController::handleTableEmployee + chart tab=staff).
 *
 * Mỗi dòng là doanh thu của MỘT nhân viên trong MỘT ca trong MỘT ngày. Dạng
 * Biểu đồ: doanh thu và giá trị đơn trung bình theo nhân viên. Cả hai từ một
 * lượt gọi GET /admin/reports/staff.
 */
class StaffReportController extends Controller
{
    use Concerns\LocNhanVien;

    /** Bảy cột (không kể STT), đúng thứ tự và nhãn bản v2 (report-employee-revenue). */
    public const COT = [
        'date' => 'Ngày',
        'shift' => 'Mã ca',
        'employee_code' => 'Mã nhân viên',
        'name' => 'Tên nhân sự',
        'order_count' => 'Tổng số đơn',
        'revenue' => 'Doanh thu(VAT)',
        'avg_order' => 'Giá trị TB',
    ];

    protected const COT_CHU = ['date', 'shift', 'employee_code', 'name'];

    /**
     * Ô "Nhóm nhân viên". v2 chia theo loại tài khoản (Quản lý / Quầy bếp / Thu
     * ngân / Order); bên mình tài khoản chia theo CỬA VÀO (users.access_areas).
     */
    public const NHOM = ['quan_ly' => 'Quản lý', 'thu_ngan' => 'Thu ngân'];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $bao = [];
        $error = null;

        try {
            $res = $this->api->reportStaff($this->truyVanNhanVien($filters));
            if ($res->successful()) {
                $bao = $res->json('data') ?? [];
            } else {
                Log::warning('Load staff report failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được báo cáo ca.';
            }
        } catch (\Throwable $e) {
            Log::error('Load staff report failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được báo cáo ca. Kiểm tra kết nối API.';
        }

        $dong = array_map(fn ($r) => $r + ['shift' => self::tenCa($r)], $bao['rows'] ?? []);
        $dong = ReportController::sapXepDong($dong, $filters['sort_field'], $filters['sort_type'], self::COT_CHU);

        if ($request->query('xuat') === 'excel') {
            return $this->xuat($dong, $bao['totals'] ?? [], $filters);
        }

        $cotTat = array_filter(explode(',', (string) $request->query('hide', '')));
        $columns = [];
        foreach (array_merge(['stt'], array_keys(self::COT)) as $c) {
            $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
        }

        $view = view('v2::reports.staff', [
            'filters' => $filters,
            'dong' => $dong,
            'tong' => $bao['totals'] ?? [],
            'theoNguoi' => $bao['by_staff'] ?? [],
            'columns' => $columns,
            'chiNhanh' => CurrentBranch::danhSach()['ds'],
            'dsNhanVien' => $this->nhanVien($filters['area']),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Chữ ở cột "Mã ca": ca thật mang số ca; đơn quầy lúc không mở ca và đơn web có nhãn riêng. */
    public static function tenCa(array $r): string
    {
        return match ($r['kind'] ?? '') {
            'shift' => 'Ca #'.($r['shift_id'] ?? ''),
            'online' => 'Online',
            default => 'Ngoài ca',
        };
    }

    protected function filters(Request $request): array
    {
        return $this->locNhanVien($request) + [
            'sort_field' => isset(self::COT[$request->query('sort_field')]) ? (string) $request->query('sort_field') : '',
            'show' => $request->query('show') === 'chart' ? 'chart' : 'table',
        ];
    }

    /** Xuất đúng bảng đang xem (cùng bộ lọc, cùng thứ tự), kèm dòng tổng. */
    protected function xuat(array $dong, array $tong, array $filters)
    {
        $ten = 'bao-cao-ca-'.$filters['from_date'].'-den-'.$filters['to_date'].'.csv';

        return response()->streamDownload(function () use ($dong, $tong) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['STT'], array_values(self::COT)));
            foreach ($dong as $i => $d) {
                fputcsv($out, array_merge([$i + 1], array_map(
                    fn ($k) => $k === 'avg_order' ? round((float) ($d[$k] ?? 0)) : ($d[$k] ?? ''),
                    array_keys(self::COT)
                )));
            }
            if ($dong) {
                fputcsv($out, ['', 'Tổng cộng', '', '', '', $tong['order_count'] ?? 0, $tong['revenue'] ?? 0, round((float) ($tong['avg_order'] ?? 0))]);
            }
            fclose($out);
        }, $ten, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
