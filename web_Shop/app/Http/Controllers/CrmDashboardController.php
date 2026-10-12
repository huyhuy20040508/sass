<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * CRM → Tổng quan. Số liệu theo CrmDashboardService của ordertable v2 (view của
 * v2 đã mất, chỉ còn service); giao diện theo bản thiết kế design/html/
 * crm-tong-quan.html đã duyệt 11/10/2026, cùng khuôn với Thống kê → Tổng quan.
 *
 * Mọi con số gộp sẵn ở GET /admin/reports/crm bên Go.
 */
class CrmDashboardController extends Controller
{
    public const TITLE = 'Tổng quan CRM';

    /** Mốc kỳ của khung lọc — 7 / 14 / 30 ngày là ba mốc của v2. */
    public const KY = [
        '7' => '7 ngày qua',
        '14' => '2 tuần qua',
        '30' => '30 ngày qua',
        'thisMonth' => 'Tháng này',
        'lastMonth' => 'Tháng trước',
    ];

    public const KY_MAC_DINH = '30';

    public const LOAI_KHACH = [
        '' => 'Tất cả',
        'individual' => 'Cá nhân',
        'company' => 'Doanh nghiệp',
    ];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $chiNhanh = CurrentBranch::danhSach()['ds'];
        $filters = $this->filters($request, $chiNhanh);
        $bc = [];
        $error = null;

        try {
            $res = $this->api->reportCrm($this->truyVan($filters));
            if ($res->successful()) {
                $bc = $res->json('data') ?? [];
            } else {
                Log::warning('Load CRM dashboard failed', ['status' => $res->status()]);
                $error = $res->status() === 403
                    ? 'Bạn chưa được giao quyền xem khách hàng — nhờ chủ tiệm mở quyền ở màn Phân quyền.'
                    : ($res->json('message') ?: 'Không tải được số liệu CRM.');
            }
        } catch (\Throwable $e) {
            Log::error('Load CRM dashboard failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được số liệu CRM. Kiểm tra kết nối API.';
        }

        $view = view('v2::crm.dashboard.index', [
            'filters' => $filters,
            'bc' => $bc,
            'chiNhanh' => $chiNhanh,
            'lienKet' => $this->lienKet($filters),
            'capNhatLuc' => now()->format('H:i'),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Bộ lọc trên URL. Chi nhánh là bộ lọc riêng của màn (như Thống kê →
     * Tổng quan), không đổi chi nhánh làm việc; 0 = cả cửa hàng.
     */
    protected function filters(Request $request, array $chiNhanh): array
    {
        $ngay = function ($v): ?Carbon {
            $v = trim((string) $v);
            try {
                if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $v)) {
                    return Carbon::createFromFormat('d-m-Y', $v)->startOfDay();
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    return Carbon::createFromFormat('Y-m-d', $v)->startOfDay();
                }
            } catch (\Throwable $e) {
            }

            return null;
        };

        $from = $ngay($request->query('from'));
        $to = $ngay($request->query('to'));
        $ky = null;
        if ($from === null || $to === null) {
            $ky = (string) $request->query('range', self::KY_MAC_DINH);
            if (! isset(self::KY[$ky])) {
                $ky = self::KY_MAC_DINH;
            }
            [$from, $to] = self::khoangKy($ky);
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $branch = (int) $request->query('branch', 0);
        if ($branch > 0 && ! collect($chiNhanh)->contains(fn ($c) => (int) $c['id'] === $branch)) {
            $branch = 0;
        }
        $loai = (string) $request->query('type', '');
        $nam = (int) $request->query('year', 0);
        $thang = (string) $request->query('reg_month', '');

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'range' => $ky,
            'branch' => $branch,
            'type' => isset(self::LOAI_KHACH[$loai]) ? $loai : '',
            'year' => $nam >= 2000 && $nam <= (int) now()->year ? $nam : null,
            'reg_month' => preg_match('/^\d{4}-\d{2}$/', $thang) ? $thang : null,
        ];
    }

    public static function khoangKy(string $ky): array
    {
        $homNay = Carbon::today();

        return match ($ky) {
            'thisMonth' => [$homNay->copy()->startOfMonth(), $homNay->copy()],
            'lastMonth' => [$homNay->copy()->subMonthNoOverflow()->startOfMonth(), $homNay->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [$homNay->copy()->subDays((int) $ky - 1), $homNay->copy()],
        };
    }

    protected function truyVan(array $filters): array
    {
        return array_filter([
            'from' => $filters['from'],
            'to' => $filters['to'],
            'shop_id' => $filters['branch'],
            'customer_type' => $filters['type'],
            'year' => $filters['year'],
            'reg_month' => $filters['reg_month'],
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /** Đường bấm từ ô KPI và thẻ "Cần chăm sóc" sang các màn chi tiết, đúng kỳ / bộ lọc. */
    protected function lienKet(array $filters): array
    {
        $dmy = fn ($d) => Carbon::parse($d)->format('d-m-Y');
        $loai = ['individual' => ['0'], 'company' => ['1']][$filters['type']] ?? null;
        $khach = fn (array $q) => route('admin.crm.customers.index', array_filter($q + ['type' => $loai]));

        return [
            'doanh_thu' => route('admin.reports.sales', ['from_date' => $dmy($filters['from']), 'to_date' => $dmy($filters['to']), 'shop_id' => $filters['branch']]),
            'don' => route('admin.crm.orders.index', ['from_date' => $dmy($filters['from']), 'to_date' => $dmy($filters['to'])]),
            'khach_mua' => $khach(['last_tx_mode' => 'custom', 'last_tx_from' => $filters['from'], 'last_tx_to' => $filters['to']]),
            'dang_ky' => $khach(['created_from' => $filters['from'], 'created_to' => $filters['to']]),
            'sinh_nhat' => $khach(['birthday_preset' => 7]),
            'lau_chua_quay_lai' => $khach(['last_tx_mode' => 'custom', 'last_tx_from' => '2000-01-01', 'last_tx_to' => now()->subDays(61)->format('Y-m-d')]),
            'cong_no' => $khach(['sort_by' => 'total_debt', 'sort_dir' => 'desc']),
            'hang' => route('admin.crm.membership.index'),
            'khach_ma' => fn (string $ma) => $khach(['keyword' => $ma]),
        ];
    }
}
