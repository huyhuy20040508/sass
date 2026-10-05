<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Doanh thu — tab `sales` của báo cáo cuối ngày
 * v2 (RevenueReportController::handleTableSales + chart tab=revenue + modal).
 *
 * Bảng mỗi NGÀY một dòng; bấm vào ngày thì hộp "Chi tiết báo cáo bán hàng" ra
 * từng hoá đơn của ngày đó (gọi ngầm `orders`). Dạng Biểu đồ: bốn biểu đồ theo
 * ngày / giờ / thứ / tháng, vẽ từ cùng một lượt gọi GET /admin/reports/sales.
 */
class SalesReportController extends Controller
{
    /** Mười cột của bảng, đúng thứ tự và nhãn bản v2 (report-sales-revenue). */
    public const COT = [
        'date' => 'Thời gian',
        'revenue' => 'Tổng Doanh Thu',
        'revenue_vat' => 'Tổng Doanh Thu (VAT)',
        'returns' => 'Tổng tiền trả hàng',
        'paid' => 'Đã thanh toán',
        'cash' => 'Tiền mặt',
        'transfer' => 'Chuyển khoản',
        'card' => 'Quẹt Thẻ',
        'auto_qr' => 'QR Tự động',
        'debt' => 'Công nợ',
    ];

    /** Màu chữ từng cột, đúng lớp v2 gắn (text-danger / success / primary / warning). */
    public const MAU = [
        'returns' => 'text-danger', 'cash' => 'text-success', 'transfer' => 'text-primary',
        'auto_qr' => 'text-warning', 'debt' => 'text-danger',
    ];

    /** Ô "Phương thức thanh toán", đúng thứ tự v2. Khoá là mã gửi sang API. */
    public const HINH_THUC = [
        'auto_qr' => 'QR Tự động',
        'card' => 'Quẹt Thẻ',
        'bank_transfer' => 'Chuyển khoản',
        'cash' => 'Tiền mặt',
    ];

    /** Nhãn hình thức của đơn cho hộp chi tiết. */
    public const TEN_HINH_THUC = [
        'cash' => 'Tiền mặt', 'bank_transfer' => 'Chuyển khoản', 'payos' => 'QR tự động',
        'sepay' => 'QR tự động', 'cod' => 'Thu hộ (COD)', 'vnpay' => 'VNPay', 'momo' => 'MoMo',
    ];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $bao = [];
        $error = null;

        try {
            $res = $this->api->reportSales($this->truyVan($filters));
            if ($res->successful()) {
                $bao = $res->json('data') ?? [];
            } else {
                Log::warning('Load sales report failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được báo cáo doanh thu.';
            }
        } catch (\Throwable $e) {
            Log::error('Load sales report failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được báo cáo doanh thu. Kiểm tra kết nối API.';
        }

        $ngay = $bao['days'] ?? [];
        if ($filters['sort_field'] !== '') {
            $huong = $filters['sort_type'] === 'asc' ? 1 : -1;
            $cot = $filters['sort_field'];
            usort($ngay, fn ($a, $b) => $huong * (($a[$cot] ?? 0) <=> ($b[$cot] ?? 0)));
        }

        if ($request->query('xuat') === 'excel') {
            return $this->xuat($ngay, $bao['totals'] ?? [], $filters);
        }

        // Cột đang tắt nằm ở ?hide=, giữ được sau khi đổi bộ lọc. STT cũng tắt được.
        $cotTat = array_filter(explode(',', (string) $request->query('hide', '')));
        $columns = [];
        foreach (array_merge(['stt'], array_keys(self::COT)) as $c) {
            $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
        }

        $view = view('v2::reports.sales', [
            'filters' => $filters,
            'ngay' => $ngay,
            'tong' => $bao['totals'] ?? [],
            'bieuDo' => [
                'ngay' => $bao['by_day'] ?? [],
                'gio' => $bao['by_hour'] ?? [],
                'thu' => $bao['by_weekday'] ?? [],
                'thang' => $bao['by_month'] ?? [],
            ],
            'columns' => $columns,
            'chiNhanh' => CurrentBranch::danhSach()['ds'],
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Hoá đơn của MỘT ngày cho hộp chi tiết — gọi ngầm, trả JSON đã đổi nhãn.
     * Cùng bộ lọc chi nhánh / nguồn / hình thức với bảng đang xem.
     */
    public function orders(Request $request)
    {
        $filters = $this->filters($request);
        $ngay = (string) $request->query('date', '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ngay)) {
            return response()->json(['message' => 'Ngày không hợp lệ.'], 422);
        }

        try {
            $res = $this->api->reportSalesOrders(['date' => $ngay] + array_diff_key($this->truyVan($filters), ['from' => 1, 'to' => 1]));
        } catch (\Throwable $e) {
            Log::error('Load sales orders failed', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không tải được hoá đơn của ngày. Kiểm tra kết nối API.'], 502);
        }
        if (! $res->successful()) {
            return response()->json(['message' => $res->json('message') ?: 'Không tải được hoá đơn của ngày.'], $res->status());
        }

        $dong = array_map(fn ($h) => $h + [
            'nguon' => ReportController::NGUON_DON[$h['channel'] ?? ''] ?? '',
            'hinh_thuc' => self::TEN_HINH_THUC[$h['payment_method'] ?? ''] ?? (string) ($h['payment_method'] ?? ''),
        ], $res->json('data') ?? []);

        return response()->json([
            'data' => $dong,
            'tieu_de' => 'Chi tiết báo cáo bán hàng '.Carbon::parse($ngay)->format('d/m/Y'),
            'chi_nhanh' => $this->tenChiNhanh($filters),
        ]);
    }

    /** Bộ lọc: phần chung của Báo cáo cuối ngày + hình thức thanh toán, sắp xếp, dạng xem. */
    protected function filters(Request $request): array
    {
        $chon = array_values(array_intersect(
            array_keys(self::HINH_THUC),
            explode(',', (string) $request->query('methods', ''))
        ));

        return ReportController::locCuoiNgay($request) + [
            // Tích đủ bốn ô (hoặc không ô nào hợp lệ) = không lọc.
            'methods' => $chon && count($chon) < count(self::HINH_THUC) ? implode(',', $chon) : '',
            'sort_field' => isset(self::COT[$request->query('sort_field')]) ? (string) $request->query('sort_field') : '',
            'show' => $request->query('show') === 'chart' ? 'chart' : 'table',
        ];
    }

    /** Tham số gửi sang API — bỏ ô trống. */
    protected function truyVan(array $filters): array
    {
        return array_filter([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'shop_id' => $filters['shop_id'],
            'channel' => $filters['channel'],
            'methods' => $filters['methods'],
        ], fn ($v) => $v !== '');
    }

    protected function tenChiNhanh(array $filters): string
    {
        $cn = $filters['shop_id'] !== '' ? $filters['shop_id'] : (string) (CurrentBranch::danhSach()['dangChon'] ?? '');

        return $cn === '0'
            ? 'Tất cả chi nhánh'
            : (collect(CurrentBranch::danhSach()['ds'])->firstWhere('id', (int) $cn)['name'] ?? '');
    }

    /** Xuất đúng bảng đang xem (cùng bộ lọc, cùng thứ tự), kèm dòng tổng. */
    protected function xuat(array $ngay, array $tong, array $filters)
    {
        $ten = 'bao-cao-doanh-thu-'.$filters['from_date'].'-den-'.$filters['to_date'].'.csv';

        return response()->streamDownload(function () use ($ngay, $tong) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['STT'], array_values(self::COT)));
            foreach ($ngay as $i => $d) {
                fputcsv($out, array_merge([$i + 1], array_map(fn ($k) => $d[$k] ?? 0, array_keys(self::COT))));
            }
            if ($ngay) {
                fputcsv($out, array_merge(['', 'Tổng cộng ('.count($ngay).')'], array_map(
                    fn ($k) => $tong[$k] ?? 0,
                    array_slice(array_keys(self::COT), 1)
                )));
            }
            fclose($out);
        }, $ten, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
