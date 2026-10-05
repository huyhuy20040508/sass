<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Báo cáo → Báo cáo tổng hợp — dựng theo tab "Báo cáo tổng hợp" của báo cáo cuối
 * ngày bản v2 (RevenueReportController::handleTableTotal + chart summary).
 *
 * Bức tranh MỘT NGÀY: thu/chi, tổng kết bán hàng theo hình thức, hàng đã bán,
 * hàng bị trả và doanh thu theo giờ. Hai dạng xem như v2 — Biểu đồ và Danh sách —
 * cùng một lượt gọi GET /admin/reports/summary.
 */
class SummaryReportController extends Controller
{
    public const TITLE = 'Báo cáo tổng hợp';

    /** Nguồn đơn — v2 có Tại bàn / Mang về / Online; bên mình là quầy và web. */
    public const NGUON = [
        'pos' => 'Tại quầy',
        'web' => 'Online',
    ];

    /**
     * Bốn dòng hình thức luôn có mặt, đúng khối "Tổng kết bán hàng" của v2 — kể cả
     * khi bằng 0. QR tự động gộp hai cổng payos và sepay.
     */
    public const HINH_THUC = [
        'cash' => ['nhan' => 'Tiền mặt', 'ma' => ['cash']],
        'transfer' => ['nhan' => 'Chuyển khoản', 'ma' => ['bank_transfer']],
        'card' => ['nhan' => 'Quẹt thẻ', 'ma' => ['card']],
        'auto_qr' => ['nhan' => 'QR tự động', 'ma' => ['payos', 'sepay']],
    ];

    /** Hình thức của đơn web — chỉ bày khi trong ngày thật sự có tiền. */
    public const HINH_THUC_KHAC = [
        'cod' => 'Thu hộ (COD)',
        'vnpay' => 'VNPay',
        'momo' => 'MoMo',
    ];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $bao = [];
        $error = null;

        try {
            $res = $this->api->reportSummary(array_filter([
                'date' => $filters['date'],
                'shop_id' => $filters['shop_id'],
                'channel' => $filters['channel'],
            ], fn ($v) => $v !== ''));
            if ($res->successful()) {
                $bao = $res->json('data') ?? [];
            } else {
                Log::warning('Load summary report failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được báo cáo tổng hợp.';
            }
        } catch (\Throwable $e) {
            Log::error('Load summary report failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được báo cáo tổng hợp. Kiểm tra kết nối API.';
        }

        $view = view('v2::reports.summary', [
            'filters' => $filters,
            'so' => $this->quyDoi($bao),
            'chiNhanh' => CurrentBranch::danhSach()['ds'],
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Kết quả API → đúng các ô của trang. Thiếu khối nào (API lỗi) thì về 0 —
     * trang trắng số còn đọc được là "ngày này chưa có gì".
     */
    public function quyDoi(array $bao): array
    {
        $theoMa = [];
        foreach ($bao['by_payment_method'] ?? [] as $s) {
            $theoMa[(string) ($s['key'] ?? '')] = (float) ($s['revenue'] ?? 0);
        }

        $hinhThuc = [];
        foreach (self::HINH_THUC as $khoa => $ht) {
            $hinhThuc[$khoa] = [
                'nhan' => $ht['nhan'],
                'tien' => array_sum(array_map(fn ($m) => $theoMa[$m] ?? 0, $ht['ma'])),
            ];
        }
        foreach (self::HINH_THUC_KHAC as $ma => $nhan) {
            if (($theoMa[$ma] ?? 0) > 0) {
                $hinhThuc[$ma] = ['nhan' => $nhan, 'tien' => $theoMa[$ma]];
            }
        }

        $gio = array_fill(0, 24, ['don' => 0, 'tien' => 0.0]);
        foreach ($bao['by_hour'] ?? [] as $s) {
            $h = (int) ($s['key'] ?? -1);
            if ($h >= 0 && $h < 24) {
                $gio[$h] = ['don' => (int) ($s['orders'] ?? 0), 'tien' => (float) ($s['revenue'] ?? 0)];
            }
        }

        $thu = (float) data_get($bao, 'cashbook.income', 0);
        $chi = (float) data_get($bao, 'cashbook.expense', 0);

        return [
            'phieu_thu' => (int) data_get($bao, 'cashbook.income_count', 0),
            'phieu_chi' => (int) data_get($bao, 'cashbook.expense_count', 0),
            'thu' => $thu,
            'chi' => $chi,
            'thu_chi' => $thu - $chi,
            'so_don' => (int) data_get($bao, 'totals.orders', 0),
            'doanh_thu' => (float) data_get($bao, 'totals.revenue', 0),
            'hinh_thuc' => $hinhThuc,
            'mat_hang' => (int) data_get($bao, 'item_kinds', 0),
            'so_luong' => (int) data_get($bao, 'totals.units', 0),
            'tra_dong' => (int) data_get($bao, 'returns.lines', 0),
            'tra_so_luong' => (int) data_get($bao, 'returns.units', 0),
            'tra_tien' => (float) data_get($bao, 'returns.refund', 0),
            'gio' => $gio,
        ];
    }

    /** Bộ lọc trên URL. Ngày sai thì về hôm nay — trang XEM, không báo lỗi. */
    protected function filters(Request $request): array
    {
        $ngay = Carbon::today();
        $v = trim((string) $request->query('date', ''));
        try {
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $v)) {
                $ngay = Carbon::createFromFormat('d-m-Y', $v)->startOfDay();
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $ngay = Carbon::createFromFormat('Y-m-d', $v)->startOfDay();
            }
        } catch (\Throwable $e) {
        }

        // Chi nhánh: '' = chi nhánh đang làm việc, '0' = cả cửa hàng, '<n>' = một.
        $shop = $request->query('shop_id');
        $shop = $shop === null || $shop === '' ? '' : (string) (int) $shop;

        $kenh = (string) $request->query('channel', '');

        return [
            'date' => $ngay->format('Y-m-d'),
            'shop_id' => $shop,
            'channel' => isset(self::NGUON[$kenh]) ? $kenh : '',
            'show' => $request->query('show') === 'table' ? 'table' : 'chart',
        ];
    }
}
