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
            'group_by' => $filters['group_by'],
        ]));
    }

    public function orders(Request $request)
    {
        $filters = $this->filters($request);

        return $this->render('reports.orders', 'orders', $filters, fn () => $this->api->reportOrders([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'group_by' => $filters['group_by'],
        ]));
    }

    public function products(Request $request)
    {
        $filters = $this->filters($request);

        return $this->render('reports.products', 'products', $filters, fn () => $this->api->reportProducts([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'sort' => $filters['sort'],
            'limit' => $filters['limit'],
        ]));
    }

    public function customers(Request $request)
    {
        $filters = $this->filters($request);

        return $this->render('reports.customers', 'customers', $filters, fn () => $this->api->reportCustomers([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'group_by' => $filters['group_by'],
            'limit' => $filters['limit'],
        ]));
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
        ]);
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
