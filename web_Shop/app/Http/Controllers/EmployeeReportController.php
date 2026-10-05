<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Báo cáo nhân viên — tab `commissionEmployee`
 * của báo cáo cuối ngày v2 (RevenueReportController::handleTableCommissionEmployee).
 *
 * Mỗi NHÂN VIÊN một dòng: doanh thu, trả hàng, doanh thu tính hoa hồng, hoa hồng.
 * Bấm vào tên thì hộp "Báo cáo nhân viên - Tên" ra từng đơn của người đó — đơn
 * đi kèm ngay trong lượt gọi GET /admin/reports/employees, không gọi thêm.
 * Như v2, tab này không có dạng Biểu đồ.
 */
class EmployeeReportController extends Controller
{
    use Concerns\LocNhanVien;

    /** Sáu cột (không kể STT), đúng thứ tự và nhãn bản v2. */
    public const COT = [
        'name' => 'Tên nhân viên',
        'revenue' => 'Tổng Doanh Thu',
        'revenue_vat' => 'Tổng doanh thu (VAT)',
        'returned' => 'Tổng tiền trả hàng',
        'commission_base' => 'Doanh thu tính hoa hồng',
        'commission' => 'Hoa hồng',
    ];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->locNhanVien($request);
        $bao = [];
        $error = null;

        try {
            $res = $this->api->reportEmployees($this->truyVanNhanVien($filters));
            if ($res->successful()) {
                $bao = $res->json('data') ?? [];
            } else {
                Log::warning('Load employee report failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được báo cáo nhân viên.';
            }
        } catch (\Throwable $e) {
            Log::error('Load employee report failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được báo cáo nhân viên. Kiểm tra kết nối API.';
        }

        $dong = $bao['rows'] ?? [];

        if ($request->query('xuat') === 'excel') {
            return $this->xuat($dong, $bao['totals'] ?? [], $filters);
        }

        $view = view('v2::reports.employees', [
            'filters' => $filters,
            'dong' => $dong,
            'tong' => $bao['totals'] ?? [],
            // Đơn của từng người cho hộp chi tiết, theo user_id — v2 cũng nhúng sẵn.
            'donTheoNguoi' => collect($dong)->mapWithKeys(fn ($r) => [(string) $r['user_id'] => [
                'name' => $r['name'] ?? '',
                'rate' => (float) ($r['commission_rate'] ?? 0),
                'orders' => array_map(fn ($o) => $o + [
                    'ngay' => isset($o['created_at']) ? Carbon::parse($o['created_at'])->format('d-m-Y H:i') : '',
                ], $r['orders'] ?? []),
            ]])->all(),
            'chiNhanh' => CurrentBranch::danhSach()['ds'],
            'dsNhanVien' => $this->nhanVien($filters['area']),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Xuất đúng bảng đang xem, kèm dòng tổng — như v2 chỉ xuất bảng ngoài. */
    protected function xuat(array $dong, array $tong, array $filters)
    {
        $ten = 'bao-cao-nhan-vien-'.$filters['from_date'].'-den-'.$filters['to_date'].'.csv';
        $so = array_slice(array_keys(self::COT), 1);

        return response()->streamDownload(function () use ($dong, $tong, $so) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['STT'], array_values(self::COT)));
            foreach ($dong as $i => $d) {
                fputcsv($out, array_merge([$i + 1, $d['name'] ?? ''], array_map(fn ($k) => round((float) ($d[$k] ?? 0)), $so)));
            }
            fputcsv($out, array_merge(['Tổng cộng', ''], array_map(fn ($k) => round((float) ($tong[$k] ?? 0)), $so)));
            fclose($out);
        }, $ten, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
