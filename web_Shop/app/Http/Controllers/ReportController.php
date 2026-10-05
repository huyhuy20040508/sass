<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ReportController — bốn trang trong nhóm "Báo cáo".
 *
 * Trang Tổng quan trả lời "hôm nay thế nào"; nhóm này trả lời "kỳ vừa rồi thế
 * nào và so với kỳ trước ra sao". Vì vậy mọi trang ở đây bắt buộc phải có một
 * KHOẢNG NGÀY do người dùng chọn, và mọi con số đều đi kèm số của kỳ trước.
 *
 * Không có phép gộp nào chạy ở tầng PHP: bốn endpoint /admin/reports/* bên Go đã
 * gộp sẵn bằng SQL. Việc của controller là đọc bộ lọc trên URL, gọi đúng một lần
 * và dịch mã máy (`cod`, `pending`, `member`…) sang nhãn tiếng Việt.
 *
 * Nhóm này nằm sau middleware `admin.manage` — nhân viên KHÔNG vào được, vì báo
 * cáo phơi ra giá vốn / lợi nhuận từng mặt hàng và thông tin liên hệ kèm mức chi
 * tiêu của từng khách. API cũng chặn đúng như vậy, ở đây chỉ để báo sớm và ẩn menu.
 */
class ReportController extends Controller
{
    public const TITLE = 'Báo cáo';

    /** Bốn trang của nhóm — dùng chung cho thanh chuyển trang và cho sidebar. */
    public const PAGES = [
        'revenue' => ['route' => 'admin.reports.revenue', 'label' => 'Doanh thu'],
        'orders' => ['route' => 'admin.reports.orders', 'label' => 'Đơn hàng'],
        'products' => ['route' => 'admin.reports.products', 'label' => 'Sản phẩm'],
        'customers' => ['route' => 'admin.reports.customers', 'label' => 'Khách hàng'],
    ];

    /**
     * Các nút xem nhanh của nhóm báo cáo, theo đúng thứ tự hiển thị.
     *
     * Định nghĩa nằm ở \App\Support\Period — dùng chung với trang Tổng quan để
     * "hôm qua" ở hai nơi luôn là cùng một ngày.
     */
    public const QUICK_CODES = ['today', 'yesterday', '7', '30', '90', '365'];

    /** Preset mở trang lần đầu. */
    public const DEFAULT_RANGE = '30';

    /** Cách chia trục thời gian — nhãn hiện trên nút chọn. */
    public const GROUPS = ['day' => 'Theo ngày', 'week' => 'Theo tuần', 'month' => 'Theo tháng'];

    /** Cách xếp hạng bảng sản phẩm. */
    public const PRODUCT_SORTS = [
        'revenue' => 'Doanh thu cao nhất',
        'units' => 'Bán nhiều nhất',
        'profit' => 'Lãi gộp cao nhất',
    ];

    /** Số dòng của bảng xếp hạng (sản phẩm, khách hàng). */
    public const LIMITS = [10, 20, 50, 100];

    /**
     * Dãy tab trong trang Báo cáo cuối ngày — `$reportTypes` của v2, nhãn đúng chữ
     * bản đang chạy. Bỏ ba tab:
     *   - "Báo cáo tổng hợp": chủ tiệm tách thành trang riêng trong module Báo
     *     cáo (29/09/2026);
     *   - "Doanh thu theo bàn": v2 cũng giấu với cửa hàng bán lẻ (isShop());
     *   - "Báo cáo hoa hồng": chủ tiệm cho ẩn (04/10/2026) — bên mình chưa có
     *     chương trình hoa hồng theo món, hoa hồng xem ở "Báo cáo nhân viên".
     */
    public const TAB_THONG_KE = [
        'sales' => 'Báo cáo doanh thu',
        'products' => 'Báo cáo hàng hóa',
        'expense' => 'Báo cáo chi phí & lợi nhuận',
        'employee' => 'Báo cáo ca',
        'customer' => 'Báo cáo khách hàng',
        'commissionEmployee' => 'Báo cáo nhân viên',
    ];

    /** Tab trong trang đã dựng và route của nó; tab khác bày mờ. */
    public const TAB_ROUTE = [
        'sales' => 'admin.reports.sales',
        'products' => 'admin.reports.goods',
        'expense' => 'admin.reports.profit',
        'employee' => 'admin.reports.staff',
        'commissionEmployee' => 'admin.reports.employees',
        'customer' => 'admin.reports.customers',
    ];

    /**
     * Sáu nút xem nhanh của khối Thời gian trên trang Thống kê, đúng bản v2.
     *
     * Khác `QUICK_CODES` của ba trang báo cáo cũ (7/30/90/365 ngày): bên này là
     * tuần và tháng, nên tự tính chứ không đi qua Period.
     */
    public const KY_NHANH = [
        'today' => 'Hôm nay',
        'yesterday' => 'Hôm qua',
        'thisWeek' => 'Tuần này',
        'lastWeek' => 'Tuần trước',
        'thisMonth' => 'Tháng này',
        'lastMonth' => 'Tháng trước',
    ];

    /** Mười cột của bảng Báo cáo cuối ngày → Khách hàng, đúng thứ tự và nhãn bản v2. */
    public const COT_KHACH = [
        'code' => 'Mã khách hàng',
        'name' => 'Tên khách hàng',
        'name_group' => 'Tên nhóm',
        'rank' => 'Hạng',
        'total_expense' => 'Tổng chi',
        'price_avg' => 'Giá trị TB',
        'accumulated_points' => 'Điểm tích lũy',
        'payment' => 'Thanh toán',
        'debt' => 'Công nợ',
        'total_order' => 'Tổng số đơn',
    ];

    /** Cột bấm được để sắp xếp — như v2, cột nào cũng sắp được. */
    public const SAP_XEP_KHACH = [
        'code', 'name', 'name_group', 'rank', 'total_expense', 'price_avg',
        'accumulated_points', 'payment', 'debt', 'total_order',
    ];

    /** Bảng khách bày tối đa bao nhiêu dòng — trần của API, v2 không phân trang tab này. */
    public const SO_DONG_KHACH = 100;

    /** Nguồn đơn của ô lọc — v2 có Tại bàn / Mang về / Online; bên mình là quầy và web. */
    public const NGUON_DON = ['pos' => 'Tại quầy', 'web' => 'Online'];

    /** Thứ trong tuần — API trả key "1".."7" với 1 = Thứ Hai. */
    public const WEEKDAYS = [
        1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm',
        5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ Nhật',
    ];

    /** Kênh bán — đơn có tài khoản và đơn khách vãng lai. */
    public const CHANNELS = ['member' => 'Khách có tài khoản', 'guest' => 'Khách vãng lai'];

    /**
     * Màu gắn CỨNG theo đối tượng, không theo thứ hạng trong kỳ: đổi kỳ xem thì
     * COD vẫn xanh dương, VNPay vẫn tím — màu nhảy chỗ là biểu đồ đọc sai.
     * Cùng bảng màu với trang Tổng quan.
     */
    public const METHOD_COLORS = [
        'cod' => '#1890ff', 'vnpay' => '#722ed1', 'momo' => '#13c2c2',
        'bank_transfer' => '#fa8c16', 'payos' => '#52c41a', 'sepay' => '#eb2f96',
    ];

    public const CHANNEL_COLORS = ['member' => '#1890ff', 'guest' => '#8c8c8c'];

    public function __construct(protected ApiClient $api) {}

    // ---------- Bốn trang ----------

    public function revenue(Request $request)
    {
        $filters = $this->filters($request);

        return $this->render('reports.revenue', 'revenue', $filters, fn () => $this->api->reportRevenue([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'shop_id' => $filters['shop_id'],
            'group_by' => $filters['group_by'],
        ]));
    }

    public function orders(Request $request)
    {
        $filters = $this->filters($request);

        return $this->render('reports.orders', 'orders', $filters, fn () => $this->api->reportOrders([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'shop_id' => $filters['shop_id'],
            'group_by' => $filters['group_by'],
        ]));
    }

    public function products(Request $request)
    {
        $filters = $this->filters($request);

        return $this->render('reports.products', 'products', $filters, fn () => $this->api->reportProducts([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'shop_id' => $filters['shop_id'],
            'sort' => $filters['sort'],
            'limit' => $filters['limit'],
        ]));
    }

    /**
     * Báo cáo cuối ngày → Khách hàng, dựng theo tab `customer` của báo cáo v2
     * (report/end-day/customer): bảng 10 cột gộp theo từng khách trong kỳ.
     *
     * API trả `top` — bảng xếp hạng chi tiêu đã cắt sẵn theo `limit`; ở đây chỉ
     * đổi tên cột sang đúng khuôn v2, sắp lại theo cột người dùng bấm và cộng
     * dòng tổng.
     */
    public function customers(Request $request)
    {
        $filters = $this->locKhach($request);

        $bao = [];
        $error = null;

        try {
            $res = $this->api->reportCustomers(array_filter([
                'from' => $filters['from_date'],
                'to' => $filters['to_date'],
                'shop_id' => $filters['shop_id'],
                'group_by' => $filters['group_by'],
                'limit' => $filters['limit'],
                'customer_group_id' => $filters['customer_group_id'],
                'user_id' => $filters['user_id'],
                'keyword' => $filters['keyword'],
                'channel' => $filters['channel'],
            ], fn ($v) => $v !== '' && $v !== null));
            if ($res->successful()) {
                $bao = $res->json('data') ?? [];
            } else {
                Log::warning('Load report failed', ['page' => 'customers', 'status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được số liệu báo cáo.';
            }
        } catch (\Throwable $e) {
            Log::error('Load report failed', ['page' => 'customers', 'msg' => $e->getMessage()]);
            $error = 'Không tải được số liệu báo cáo. Kiểm tra kết nối API.';
        }

        $rows = $this->dongKhach($bao['top'] ?? [], $filters);
        $khachLe = $this->dongKhachLe($bao['totals'] ?? [], $filters);

        if ($request->query('xuat') === 'excel') {
            return $this->xuatKhach(array_merge($khachLe ? [$khachLe] : [], $rows), $filters);
        }

        // Cột đang tắt nằm ở ?hide=, giữ được sau khi đổi bộ lọc.
        $cotTat = array_filter(explode(',', (string) $request->query('hide', '')));
        $columns = [];
        // STT cũng tắt được như v2 (`show_stt`).
        foreach (array_merge(['stt'], array_keys(self::COT_KHACH)) as $c) {
            $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
        }

        $view = view('v2::reports.customer', [
            'page' => 'customers',
            'filters' => $filters,
            'rows' => $rows,
            'khachLe' => $khachLe,
            'tong' => $this->tongKhach(array_merge($khachLe ? [$khachLe] : [], $rows)),
            'columns' => $columns,
            'chiNhanh' => $this->chiNhanhChoLoc(),
            'nhomKhach' => $this->nhomKhachChoLoc(),
            'dsKhach' => $this->khachChoLoc(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Sắp bảng theo một cột. Cột chữ so theo bảng chữ tiếng Việt: so byte thì
     * "Áo" đứng sau "Nón".
     */
    public static function sapXepDong(array $dong, string $cot, string $chieu, array $cotChu): array
    {
        if ($cot === '') {
            return $dong;
        }
        $huong = $chieu === 'asc' ? 1 : -1;
        $vn = in_array($cot, $cotChu, true) ? new \Collator('vi_VN') : null;
        $vn?->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
        usort($dong, fn ($a, $b) => $huong * ($vn
            ? $vn->compare((string) ($a[$cot] ?? ''), (string) ($b[$cot] ?? ''))
            : (($a[$cot] ?? 0) <=> ($b[$cot] ?? 0))));

        return $dong;
    }

    /**
     * Bộ lọc CHUNG của mọi tab trong trang Báo cáo cuối ngày: kỳ (sáu mốc nhanh
     * hoặc Tuỳ chỉnh), chi nhánh, nguồn đơn. Tab nào cần thêm ô lọc riêng thì
     * cộng thêm vào kết quả này (xem locKhach, SalesReportController::filters).
     *
     * Không dùng chung `filters()` với ba trang báo cáo cũ vì khối Thời gian ở
     * đây là sáu mốc tuần/tháng của v2, không phải 7/30/90/365 ngày.
     */
    public static function locCuoiNgay(Request $request): array
    {
        // Ô ngày của v2 gửi dd-mm-yyyy; link cũ còn yyyy-mm-dd. Nhận cả hai.
        $doc = function (?string $v): ?Carbon {
            $v = trim((string) $v);
            try {
                if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $v)) {
                    return Carbon::createFromFormat('d-m-Y', $v)->startOfDay();
                }

                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? Carbon::createFromFormat('Y-m-d', $v)->startOfDay() : null;
            } catch (\Throwable $e) {
                return null;
            }
        };

        $from = $doc($request->query('from_date'));
        $to = $doc($request->query('to_date'));

        // Khai đủ hai đầu thì khoảng tự chọn thắng; không thì rơi về mốc nhanh.
        $quick = null;
        if ($from === null || $to === null) {
            $ma = (string) $request->query('range', 'today');
            if (! isset(self::KY_NHANH[$ma])) {
                $ma = 'today';
            }
            [$from, $to] = self::khoangKyNhanh($ma);
            $quick = $ma;
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $shop = $request->query('shop_id');
        $nguon = (string) $request->query('channel', '');

        return [
            'from_date' => $from->format('Y-m-d'),
            'to_date' => $to->format('Y-m-d'),
            // Ép về int: Carbon 3 trả float, để nguyên thì so sánh chặt ở view trượt.
            'days' => (int) $from->diffInDays($to) + 1,
            'quick' => $quick,
            'shop_id' => $shop === null || $shop === '' ? '' : (string) (int) $shop,
            'channel' => isset(self::NGUON_DON[$nguon]) ? $nguon : '',
            'sort_type' => $request->query('sort_type') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * Chữ trong hộp kỳ bên phải tiêu đề, đúng khuôn v2:
     * "Ngày 01-05-2026 00:00 / 10-05-2026 23:59 · (Chi nhánh)".
     */
    public static function kyChu(array $filters, array $chiNhanh): string
    {
        $cn = $filters['shop_id'] !== ''
            ? $filters['shop_id']
            : (string) (\App\Services\CurrentBranch::danhSach()['dangChon'] ?? '');
        $ten = $cn === '0' ? 'Tất cả chi nhánh' : (collect($chiNhanh)->firstWhere('id', (int) $cn)['name'] ?? '');
        $ngay = fn ($s) => Carbon::parse($s)->format('d-m-Y');

        return 'Ngày '.$ngay($filters['from_date']).' 00:00 / '.$ngay($filters['to_date']).' 23:59'
            .($ten !== '' ? ' · ('.$ten.')' : '');
    }

    /** Bộ lọc của tab Khách hàng: phần chung cộng nhóm / một khách / từ khoá. */
    protected function locKhach(Request $request): array
    {
        return self::locCuoiNgay($request) + [
            'limit' => self::SO_DONG_KHACH,
            'group_by' => 'day',
            'keyword' => trim((string) $request->query('keyword', '')),
            'customer_group_id' => max(0, (int) $request->query('customer_group_id', 0)) ?: '',
            'user_id' => max(0, (int) $request->query('user_id', 0)) ?: '',
            'sort_field' => in_array($request->query('sort_field'), self::SAP_XEP_KHACH, true)
                ? (string) $request->query('sort_field')
                : '',
        ];
    }

    /** Sáu mốc nhanh của v2 quy về khoảng ngày cụ thể. Báo cáo kết ca dùng chung. */
    public static function khoangKyNhanh(string $ma): array
    {
        $homNay = Carbon::today();

        return match ($ma) {
            'yesterday' => [$homNay->copy()->subDay(), $homNay->copy()->subDay()],
            'thisWeek' => [$homNay->copy()->startOfWeek(), $homNay->copy()->endOfWeek()],
            'lastWeek' => [
                $homNay->copy()->subWeek()->startOfWeek(),
                $homNay->copy()->subWeek()->endOfWeek(),
            ],
            'thisMonth' => [$homNay->copy()->startOfMonth(), $homNay->copy()->endOfMonth()],
            'lastMonth' => [
                $homNay->copy()->subMonthNoOverflow()->startOfMonth(),
                $homNay->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [$homNay->copy(), $homNay->copy()],
        };
    }

    /**
     * Đổi `top` của API sang đúng 10 cột của bảng v2.
     *
     * Lọc nhóm / từ khoá / nguồn đơn đều làm ở API — lọc ở đây thì chỉ lọc được
     * trong số dòng API đã cắt theo `limit`, khách khớp mà nằm ngoài top là mất.
     * Mã khách lấy đúng mã trên hồ sơ CRM; khách chưa có mã thì để trống.
     */
    protected function dongKhach(array $top, array $filters): array
    {
        $rows = [];
        foreach ($top as $r) {
            $ten = (string) ($r['name'] ?? '');
            $rows[] = [
                'code' => (string) ($r['customer_code'] ?? ''),
                'name' => $ten !== '' ? $ten : __('message.retail_customer'),
                'name_group' => (string) ($r['group_name'] ?? ''),
                'rank' => (string) ($r['rank_name'] ?? ''),
                'total_expense' => (float) ($r['revenue'] ?? 0),
                'price_avg' => (float) ($r['aov'] ?? 0),
                'accumulated_points' => (int) ($r['points'] ?? 0),
                'payment' => (float) ($r['paid'] ?? 0),
                'debt' => (float) ($r['debt'] ?? 0),
                'total_order' => (int) ($r['orders'] ?? 0),
            ];
        }

        if ($filters['sort_field'] !== '') {
            $huong = $filters['sort_type'] === 'asc' ? 1 : -1;
            $cot = $filters['sort_field'];
            usort($rows, fn ($a, $b) => $huong * ($a[$cot] <=> $b[$cot]));
        }

        return $rows;
    }

    /**
     * Dòng "Bán cho người tiêu dùng" của v2 — gộp mọi đơn khách vãng lai (không
     * gắn khách). Chỉ bày khi KHÔNG lọc theo nhóm, theo khách hay theo tên: khách
     * lẻ không thuộc nhóm nào, cũng không có tên để khớp.
     */
    protected function dongKhachLe(array $tong, array $filters): ?array
    {
        $don = (int) ($tong['guest_orders'] ?? 0);
        if ($don === 0 || $filters['customer_group_id'] !== '' || $filters['user_id'] !== '' || $filters['keyword'] !== '') {
            return null;
        }

        $tien = (float) ($tong['guest_revenue'] ?? 0);
        $daTra = (float) ($tong['guest_paid'] ?? 0);

        return [
            'code' => '',
            'name' => 'Bán cho người tiêu dùng',
            'name_group' => '',
            'rank' => '',
            'total_expense' => $tien,
            'price_avg' => $tien / $don,
            'accumulated_points' => 0,
            'payment' => $daTra,
            'debt' => max($tien - $daTra, 0),
            'total_order' => $don,
        ];
    }

    /** Dòng tổng cuối bảng — cộng đúng phần đang bày. */
    protected function tongKhach(array $rows): array
    {
        $tong = array_fill_keys(
            ['total_expense', 'price_avg', 'accumulated_points', 'payment', 'debt', 'total_order'],
            0
        );

        foreach ($rows as $r) {
            foreach (array_keys($tong) as $k) {
                $tong[$k] += $r[$k];
            }
        }

        // Giá trị trung bình KHÔNG cộng dồn được: tổng chi tiêu chia tổng số đơn
        // mới ra con số đúng của cả kỳ.
        $tong['price_avg'] = $tong['total_order'] > 0 ? $tong['total_expense'] / $tong['total_order'] : 0;

        return $tong;
    }

    /** Xuất đúng phần đang lọc, 10 cột như bảng. */
    protected function xuatKhach(array $rows, array $filters)
    {
        $ten = 'thong-ke-khach-hang-'.$filters['from_date'].'-den-'.$filters['to_date'].'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['STT'], array_values(self::COT_KHACH)));
            foreach ($rows as $i => $r) {
                fputcsv($out, array_merge([$i + 1], array_map(
                    fn ($k) => $r[$k],
                    array_keys(self::COT_KHACH)
                )));
            }
            fclose($out);
        }, $ten, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------- Phần dùng chung ----------

    /**
     * Đọc bộ lọc trên URL và quy về giá trị hợp lệ.
     *
     * Ngày gõ sai hoặc khoảng đảo đầu đuôi thì lùi về mặc định thay vì báo lỗi:
     * đây là trang XEM, một tham số hỏng không đáng để người dùng nhận màn hình
     * trắng. Cùng lý do với cách tầng service bên API xử lý.
     *
     * `range` là đường tắt của các nút xem nhanh (today, yesterday, 7, 30…);
     * khi có from_date/to_date tường minh thì hai cái đó thắng.
     */
    protected function filters(Request $request): array
    {
        $parse = function (?string $value): ?Carbon {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            try {
                return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
            } catch (\Throwable $e) {
                return null;
            }
        };

        $to = $parse($request->query('to_date'));
        $from = $parse($request->query('from_date'));

        // Chưa khai đủ hai đầu thì rơi về preset. Chỉ nhận preset có trong danh
        // sách nút của nhóm này — mã lạ trên URL không được phép dựng ra một kỳ
        // mà giao diện không có nút nào sáng lên tương ứng.
        if ($from === null || $to === null) {
            $code = (string) $request->query('range', self::DEFAULT_RANGE);
            if (! in_array($code, self::QUICK_CODES, true)) {
                $code = self::DEFAULT_RANGE;
            }
            $range = Period::resolve($code);
            $from = Carbon::parse($range['from']);
            $to = Carbon::parse($range['to']);
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        // Ép về int: Carbon 3 trả FLOAT từ diffInDays, để nguyên thì mọi so sánh
        // chặt với số nguyên ở view đều trượt.
        $days = (int) $from->diffInDays($to) + 1;

        $group = (string) $request->query('group_by', '');
        if (! isset(self::GROUPS[$group])) {
            // Không khai thì tự chọn theo độ dài kỳ — cùng ngưỡng với bên API để
            // nút đang sáng luôn khớp với dữ liệu thật sự được vẽ.
            $group = match (true) {
                $days > 180 => 'month',
                $days > 62 => 'week',
                default => 'day',
            };
        }

        $sort = (string) $request->query('sort', 'revenue');
        if (! isset(self::PRODUCT_SORTS[$sort])) {
            $sort = 'revenue';
        }

        // CHI NHÁNH XEM BÁO CÁO.
        //
        // Ba trạng thái, và trạng thái thứ nhất là lý do ô này phải có mặt: từ khi
        // báo cáo cắt theo chi nhánh đang làm việc, chủ tiệm chọn kho 2 ở thanh
        // trên cùng là mất luôn đường xem toàn công ty — mà bảng "chia theo chi
        // nhánh" ngay trong báo cáo doanh thu sinh ra chính là để so các kho với
        // nhau, và nó teo lại còn một dòng.
        //
        //   ''   → chưa khai, dùng chi nhánh đang làm việc (mặc định)
        //   '0'  → TẤT CẢ chi nhánh
        //   '<n>'→ đúng một chi nhánh
        //
        // Nhân viên bị phân công thì API bỏ qua tham số này (xem chiNhanhLoc bên
        // API) — ô chọn ở đây chỉ có nghĩa với người quản cả cửa hàng.
        $shop = $request->query('shop_id');
        $shop = $shop === null || $shop === '' ? '' : (string) (int) $shop;

        $limit = (int) $request->query('limit', 20);
        if (! in_array($limit, self::LIMITS, true)) {
            $limit = 20;
        }

        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        // Nút xem nhanh nào đang khớp — so bằng KHOẢNG NGÀY THẬT, không nhìn tham
        // số trên URL: bấm nút "Hôm qua" hay tự chọn đúng ngày hôm qua trên lịch
        // thì cũng phải thấy nút đó sáng lên như nhau.
        $quick = Period::match($fromDate, $toDate, self::QUICK_CODES);

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'days' => $days,
            'quick' => $quick,
            // Kỳ có neo vào hôm nay không — quyết định trang có tự nạp lại lúc
            // sang ngày mới hay không (xem partials/day-rollover).
            'anchored' => $quick !== null,
            'today' => Period::today(),
            'group_by' => $group,
            'sort' => $sort,
            'limit' => $limit,
            'shop_id' => $shop,
        ];
    }

    /**
     * Gọi API rồi dựng view, hỏng thì vẫn trả trang kèm cảnh báo.
     *
     * Báo cáo trắng số còn đọc được là "kỳ này không có gì", còn màn hình lỗi thì
     * không nói được gì cả — nên mọi trục trặc đều quy về $error + dữ liệu rỗng,
     * và view tự lo hiển thị số 0.
     */
    protected function render(string $view, string $page, array $filters, callable $call)
    {
        $data = [];
        $error = null;

        try {
            $res = $call();
            if ($res->successful()) {
                $data = $res->json('data') ?? [];
            } else {
                Log::warning('Load report failed', ['page' => $page, 'status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được số liệu báo cáo.';
            }
        } catch (\Throwable $e) {
            Log::error('Load report failed', ['page' => $page, 'msg' => $e->getMessage()]);
            $error = 'Không tải được số liệu báo cáo. Kiểm tra kết nối API.';
        }

        return view($view, [
            'page' => $page,
            'filters' => $filters,
            'report' => $data,
            'error' => $error,
            'chiNhanh' => $this->chiNhanhChoLoc(),
        ]);
    }

    /**
     * Khách cho ô "--Khách hàng--" (chọn một khách). API danh sách khách trả tối
     * đa 100 khách một trang — đủ cho ô chọn có tìm kiếm của một cửa hàng nhỏ.
     */
    protected function khachChoLoc(): array
    {
        try {
            $res = $this->api->customers(['page_size' => 100]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Nhóm khách cho ô lọc của trang Khách hàng. Hỏng thì trả rỗng — mất một ô lọc còn hơn mất cả trang. */
    protected function nhomKhachChoLoc(): array
    {
        try {
            $res = $this->api->nhomKhachHang(true);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Danh sách chi nhánh cho ô lọc. Hỏng thì trả rỗng — ô chọn biến mất và báo
     * cáo vẫn chạy theo chi nhánh đang làm việc như cũ.
     */
    protected function chiNhanhChoLoc(): array
    {
        try {
            $res = $this->api->chiNhanh(true);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ---------- Trợ giúp cho view ----------

    /**
     * Đổi nhãn mốc thời gian của API sang dạng người đọc.
     *
     * API trả "2026-07-28" / "2026-W31" / "2026-07" tuỳ cách chia. Trục biểu đồ
     * hẹp nên bản ngắn bỏ luôn phần năm khi mốc nằm trong năm nay.
     */
    public static function bucketLabel(string $label, string $groupBy, bool $short = false): string
    {
        if ($groupBy === 'month') {
            $parts = explode('-', $label);
            if (count($parts) === 2) {
                return $short ? 'T'.(int) $parts[1] : 'Tháng '.(int) $parts[1].'/'.$parts[0];
            }

            return $label;
        }

        if ($groupBy === 'week') {
            $parts = explode('-W', $label);
            if (count($parts) === 2) {
                return $short ? 'T'.(int) $parts[1] : 'Tuần '.(int) $parts[1].'/'.$parts[0];
            }

            return $label;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $label);
        } catch (\Throwable $e) {
            return $label;
        }

        return $short ? $date->format('d/m') : $date->format('d/m/Y');
    }

    /** Nhãn khung giờ: "14h" — trục 24 cột không còn chỗ cho gì dài hơn. */
    public static function hourLabel(string $key): string
    {
        return ((int) $key).'h';
    }
}
