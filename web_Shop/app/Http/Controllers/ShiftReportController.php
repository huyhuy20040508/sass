<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Thống kê → Báo cáo kết ca — dựng theo bản v2
 * (Report/ShiftReportController + report/end-shift-report/index, list).
 *
 * Mỗi dòng là MỘT CA: ai trực, mở/đóng lúc nào, bán được bao nhiêu đơn, tiền về
 * theo từng hình thức, và phần đối chiếu két (đầu ca, cuối ca theo sổ, tiền giao
 * thực tế, chênh lệch).
 *
 * Mọi con số gộp sẵn ở GET /admin/reports/shifts bên Go. Controller chỉ đọc bộ
 * lọc trên URL, gọi một lần rồi đổ ra bảng.
 */
class ShiftReportController extends Controller
{
    public const TITLE = 'Báo cáo kết ca';

    /** Cỡ trang — v2 mở ở 10. */
    public const PAGE_SIZES = [10, 20, 50, 100];

    public const PAGE_SIZE_MAC_DINH = 10;

    /** Mốc mở trang lần đầu — v2 mở ở "Tháng này". */
    public const KY_MAC_DINH = 'thisMonth';

    /**
     * Mười chín cột, đúng thứ tự và nhãn bản v2 đang chạy. Khoá là tên cột trong
     * ?hide= và là khoá của dòng sau khi controller đã quy đổi (xem dong()).
     */
    public const COT_BANG = [
        'employee_code' => 'Mã nhân sự',
        'employee_name' => 'Tên nhân sự',
        'open_time' => 'Giờ mở ca',
        'close_time' => 'Giờ đóng ca',
        'total_order' => 'Tổng số đơn',
        'cash' => 'Tiền mặt',
        'transfer' => 'Chuyển khoản',
        'swipe_card' => 'Quẹt thẻ',
        'auto_qr' => 'QR tự động',
        'opening_cash' => 'Tiền mặt đầu ca',
        'closing_cash' => 'Tiền mặt cuối ca',
        'cash_delivery' => 'Giao tiền mặt',
        'cash_difference' => 'Số tiền chênh lệch',
        'total_revenue' => 'Tổng doanh thu',
        'date' => 'Ngày',
        'opener' => 'Người mở ca',
        'open_note' => 'Ghi chú mở ca',
        'closer' => 'Người đóng ca',
        'close_note' => 'Ghi chú đóng ca',
    ];

    /** Cột tiền — canh phải, in số có dấu chấm ngăn nghìn. */
    public const COT_TIEN = [
        'cash', 'transfer', 'swipe_card', 'auto_qr', 'opening_cash', 'closing_cash',
        'cash_delivery', 'cash_difference', 'total_revenue',
    ];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $ca = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1, 'tong' => []];
        $error = null;

        try {
            $res = $this->api->reportShifts($this->truyVan($filters));
            if ($res->successful()) {
                $ca = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load shift report failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được báo cáo kết ca.';
            }
        } catch (\Throwable $e) {
            Log::error('Load shift report failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được báo cáo kết ca. Kiểm tra kết nối API.';
        }

        // Trang quá số trang thật (lọc lại khi đang đứng ở trang cuối) thì lùi về
        // trang cuối, không bày bảng trắng kèm câu "chưa có ca nào".
        $soTrang = max(1, (int) $meta['total_pages']);
        if ($filters['page'] > $soTrang && (int) $meta['total'] > 0) {
            return redirect()->to($request->fullUrlWithQuery(['page' => $soTrang]));
        }

        $view = view('v2::reports.shift', [
            'filters' => $filters,
            'rows' => array_map([$this, 'dong'], $ca),
            'meta' => $meta,
            'chiNhanh' => CurrentBranch::danhSach()['ds'],
            'nhanVien' => $this->danhMucNhanVien(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Xuất đúng bộ lọc đang bật, đủ 19 cột như bảng — nút "Xuất Excel" của v2. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $rows = array_map([$this, 'dong'], $this->docHet($filters));
        $ten = 'bao-cao-ket-ca-'.$filters['from_date'].'-den-'.$filters['to_date'].'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['STT'], array_values(self::COT_BANG)));
            foreach ($rows as $i => $r) {
                fputcsv($out, array_merge([$i + 1], array_map(
                    fn ($k) => $k === 'close_time' && $r['dang_mo'] ? 'Đang mở' : $r[$k],
                    array_keys(self::COT_BANG)
                )));
            }
            fclose($out);
        }, $ten, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Một ca của API → một dòng của bảng.
     *
     * Ca chưa đóng không có tiền cuối ca / tiền giao / chênh lệch: để TRỐNG chứ
     * không in 0 — "chênh 0" đọc thành "két khớp", mà ca đó chưa ai đếm két.
     * Cột Ngày lấy ngày đóng ca như v2; ca còn mở thì lấy ngày mở, không thì dòng
     * ấy chẳng có ngày nào vì hai cột giờ chỉ in HH:mm.
     */
    public function dong(array $c): array
    {
        $gio = fn ($v) => $v ? Carbon::parse($v)->format('H:i') : '';
        $dangMo = empty($c['closed_at']);
        $soNeuDong = fn ($k) => $dangMo || ! isset($c[$k]) ? null : (float) $c[$k];

        return [
            'id' => (int) ($c['id'] ?? 0),
            'dang_mo' => $dangMo,
            'shop_name' => (string) ($c['shop_name'] ?? ''),
            'employee_code' => (string) ($c['employee_code'] ?? ''),
            'employee_name' => (string) (($c['employee_name'] ?? '') ?: ($c['opened_by_name'] ?? '')),
            'open_time' => $gio($c['opened_at'] ?? null),
            'close_time' => $gio($c['closed_at'] ?? null),
            'total_order' => (int) ($c['order_count'] ?? 0),
            'cash' => (float) ($c['total_cash'] ?? 0),
            'transfer' => (float) ($c['total_transfer'] ?? 0),
            'swipe_card' => (float) ($c['total_card'] ?? 0),
            'auto_qr' => (float) ($c['total_auto_qr'] ?? 0),
            'opening_cash' => (float) ($c['opening_cash'] ?? 0),
            'closing_cash' => $soNeuDong('expected_cash'),
            'cash_delivery' => $soNeuDong('counted_cash'),
            'cash_difference' => $soNeuDong('difference'),
            'total_revenue' => (float) ($c['total_revenue'] ?? 0),
            'date' => ($moc = ($c['closed_at'] ?? null) ?: ($c['opened_at'] ?? null)) ? Carbon::parse($moc)->format('d-m-Y') : '',
            'opener' => (string) ($c['opened_by_name'] ?? ''),
            'open_note' => (string) ($c['open_note'] ?? ''),
            'closer' => (string) ($c['closed_by_name'] ?? ''),
            'close_note' => (string) ($c['close_note'] ?? ''),
        ];
    }

    /**
     * Bộ lọc trên URL, quy về giá trị hợp lệ. Ngày hỏng hay khoảng đảo đầu đuôi
     * thì lùi về mặc định chứ không báo lỗi — trang XEM, một tham số hỏng không
     * đáng một màn hình trắng.
     */
    protected function filters(Request $request): array
    {
        $from = $this->ngay($request->query('from_date'));
        $to = $this->ngay($request->query('to_date'));

        // Khai đủ hai đầu thì khoảng tự chọn thắng; không thì rơi về mốc nhanh.
        $quick = null;
        if ($from === null || $to === null) {
            $ma = (string) $request->query('range', self::KY_MAC_DINH);
            if (! isset(ReportController::KY_NHANH[$ma])) {
                $ma = self::KY_MAC_DINH;
            }
            [$from, $to] = ReportController::khoangKyNhanh($ma);
            $quick = $ma;
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        // Chi nhánh: v2 chỉ bày chi nhánh, không có "Tất cả" — bảng không có cột
        // chi nhánh nên trộn ca của nhiều quầy vào một bảng là đọc không ra.
        // Không khai thì lấy chi nhánh đang làm việc.
        $shop = (int) $request->query('shop_id', 0);
        if ($shop <= 0) {
            $shop = (int) CurrentBranch::danhSach()['dangChon'];
        }

        $psize = (int) $request->query('page_size', self::PAGE_SIZE_MAC_DINH);

        return [
            'from_date' => $from->format('Y-m-d'),
            'to_date' => $to->format('Y-m-d'),
            'quick' => $quick,
            'shop_id' => $shop > 0 ? $shop : '',
            'user_id' => max(0, (int) $request->query('user_id', 0)) ?: '',
            'keyword' => trim((string) $request->query('keyword', '')),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($psize, self::PAGE_SIZES, true) ? $psize : self::PAGE_SIZE_MAC_DINH,
        ];
    }

    /** Tham số gửi sang API — bỏ ô trống và mấy khoá chỉ giao diện dùng. */
    protected function truyVan(array $filters): array
    {
        $q = [
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'shop_id' => $filters['shop_id'],
            'user_id' => $filters['user_id'],
            'keyword' => $filters['keyword'],
            'page' => $filters['page'],
            'page_size' => $filters['page_size'],
        ];

        return array_filter($q, fn ($v) => $v !== '' && $v !== null);
    }

    /** Đọc hết các trang cho bản xuất. Chặn ở 100 trang × 100 dòng. */
    protected function docHet(array $filters): array
    {
        $all = [];
        $query = array_merge($this->truyVan($filters), ['page' => 1, 'page_size' => 100]);
        try {
            do {
                $res = $this->api->reportShifts($query);
                if (! $res->successful()) {
                    break;
                }
                $all = array_merge($all, $res->json('data') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $totalPages && $query['page'] <= 100);
        } catch (\Throwable $e) {
            Log::error('Export shift report failed', ['msg' => $e->getMessage()]);
        }

        return $all;
    }

    /** Danh sách cho ô lọc Nhân viên. Hỏng thì trả rỗng — mất một ô lọc còn hơn mất cả trang. */
    protected function danhMucNhanVien(): array
    {
        try {
            $res = $this->api->users(['status' => 'active', 'page_size' => 100]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('Load nhan vien cho bao cao ket ca failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Ô ngày gửi dd-mm-yyyy (lịch v2) hoặc yyyy-mm-dd; nhận cả hai. */
    protected function ngay($v): ?Carbon
    {
        $v = trim((string) $v);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return Carbon::createFromFormat('Y-m-d', $v)->startOfDay();
            }
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $v)) {
                return Carbon::createFromFormat('d-m-Y', $v)->startOfDay();
            }
        } catch (\Throwable $e) {
        }

        return null;
    }
}
