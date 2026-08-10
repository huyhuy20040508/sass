<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * DashboardController — trang tổng quan.
 *
 * Gom số liệu từ nhiều endpoint của Go API. Mỗi khối tự chịu lỗi riêng: một
 * endpoint hỏng chỉ làm khối đó trống chứ không làm trắng cả trang — đây là
 * trang đầu tiên nhân viên nhìn thấy mỗi sáng.
 */
class DashboardController extends Controller
{
    /**
     * Các khoảng thời gian xem được, theo đúng thứ tự nút hiển thị.
     *
     * "Hôm qua" là một NGÀY ĐÃ ĐÓNG SỔ: mở lúc 8h sáng hay 23h đêm đều ra cùng
     * một con số, nên đây mới là chỗ xem lại kết quả một ngày buôn bán. "Hôm nay"
     * thì ngược lại, còn chạy tiếp tới nửa đêm.
     *
     * Định nghĩa nằm ở \App\Support\Period — dùng chung với nhóm trang Báo cáo để
     * hai nơi không bao giờ hiểu "hôm qua" thành hai ngày khác nhau.
     */
    public const RANGE_CODES = ['today', 'yesterday', '7', '30', '90'];

    /** Preset mở trang lần đầu. */
    public const DEFAULT_RANGE = '30';

    /**
     * Ngưỡng coi là sắp hết hàng (tính theo từng biến thể) khi không đọc được cấu
     * hình hệ thống. Mức thật lấy từ khoá `low_stock_threshold` bên API — cùng một
     * ngưỡng với trang Tồn kho, xem lowStockThreshold().
     */
    public const LOW_STOCK = 5;

    /** Số dòng sắp hết hàng hiển thị trên trang — bảng chỉ có chừng đó chỗ. */
    public const LOW_STOCK_ROWS = 8;

    /** Số trang đơn (100 đơn/trang) tối đa được quét để bóc tách cơ cấu kỳ này.
     *  Quét hết mọi đơn của 90 ngày có thể là hàng nghìn dòng — chặn ở đây và
     *  đánh dấu `sampled` để giao diện nói thẳng là số liệu tính trên mẫu. */
    public const MAX_ORDER_PAGES = 5;

    /** Trạng thái không tính vào doanh thu (khớp cách API tính OrderStats). */
    protected const DEAD_STATUSES = ['cancelled', 'returned'];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        // Kỳ đang xem luôn quy về một preset: trang này không có lịch chọn ngày,
        // mọi đường vào đều là một trong các nút ở RANGE_CODES.
        $range = (string) $request->query('range', self::DEFAULT_RANGE);
        if (! in_array($range, self::RANGE_CODES, true)) {
            $range = self::DEFAULT_RANGE;
        }
        $window = Period::resolve($range);
        $days = Period::PRESETS[$range]['days'];

        return view('dashboard', [
            'user' => session('api.user'),
            'apiOnline' => $this->apiOnline(),
            'range' => $range,
            'days' => $days,
            'window' => $window,
            // Câu ghép vào tiêu đề thẻ: "Doanh thu hôm qua", "Doanh thu 30 ngày qua".
            'periodLabel' => Period::PRESETS[$range]['phrase'],
            'today' => Period::today(),
            'orderStats' => $this->orderStats(),
            'customerStats' => $this->customerStats(),
            'returnStats' => $this->returnStats(),
            'revenue' => $this->revenue($window),
            'breakdown' => $this->breakdown($window),
            'recentOrders' => $this->recentOrders(),
            'topProducts' => $this->topProducts(),
            'topCustomers' => $this->topCustomers(),
            'lowStock' => $this->lowStock(),
            // Ngưỡng thật đang áp — view in ra trong phụ đề, không đọc hằng số nữa.
            'lowStockThreshold' => $this->lowStockThreshold(),
        ]);
    }

    // ---------- Từng khối số liệu ----------

    protected function apiOnline(): bool
    {
        try {
            return $this->api->get('/health')->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function orderStats(): array
    {
        return $this->fetch(
            fn () => $this->api->orderStats(),
            ['total' => 0, 'pending' => 0, 'processing' => 0, 'shipping' => 0, 'completed' => 0, 'cancelled' => 0, 'revenue' => 0],
            'order stats'
        );
    }

    protected function customerStats(): array
    {
        return $this->fetch(
            fn () => $this->api->customerStats(),
            ['total' => 0, 'active' => 0, 'inactive' => 0],
            'customer stats'
        );
    }

    protected function returnStats(): array
    {
        return $this->fetch(
            fn () => $this->api->returnStats(),
            ['total' => 0, 'pending' => 0, 'approved' => 0, 'received' => 0, 'refunded' => 0, 'rejected' => 0, 'cancelled' => 0, 'refunded_amount' => 0],
            'return stats'
        );
    }

    /**
     * Chuỗi doanh thu theo ngày + số liệu kỳ trước để tính mức tăng/giảm.
     *
     * Dùng /admin/reports/revenue chứ KHÔNG dùng /admin/orders/revenue: đường cũ
     * chỉ nhận `days` và luôn kết thúc ở hôm nay, nên không có cách nào diễn đạt
     * "hôm qua". Đường mới nhận from/to nên mọi preset đều gọi được, và vẫn trả
     * kèm tổng của kỳ trước như cũ.
     *
     * Trả về ĐÚNG hình dạng mà dashboard.blade.php vốn đang đọc (points có khoá
     * `date`), để phần vẽ biểu đồ không phải sửa theo.
     */
    protected function revenue(array $window): array
    {
        $empty = ['points' => [], 'total_revenue' => 0, 'total_orders' => 0, 'prev_revenue' => 0, 'prev_orders' => 0];

        $data = $this->fetch(
            fn () => $this->api->reportRevenue([
                'from' => $window['from'],
                'to' => $window['to'],
                'group_by' => 'day',
            ]),
            [],
            'revenue series'
        );
        if (! $data) {
            return $empty;
        }

        return [
            'points' => array_map(fn ($b) => [
                'date' => $b['label'] ?? '',
                'orders' => (int) ($b['orders'] ?? 0),
                'revenue' => (float) ($b['revenue'] ?? 0),
            ], $data['buckets'] ?? []),
            'total_revenue' => (float) ($data['totals']['revenue'] ?? 0),
            'total_orders' => (int) ($data['totals']['orders'] ?? 0),
            'prev_revenue' => (float) ($data['prev']['revenue'] ?? 0),
            'prev_orders' => (int) ($data['prev']['orders'] ?? 0),
        ];
    }

    /**
     * Bóc tách cơ cấu đơn hàng của kỳ đang xem.
     *
     * API chỉ trả tổng doanh thu theo ngày, không có mặt cắt theo phương thức
     * thanh toán / khu vực / khung giờ. Ở đây quét danh sách đơn trong kỳ (tối đa
     * MAX_ORDER_PAGES × 100 đơn) rồi tự gộp — đủ cho trang tổng quan; khi lượng
     * đơn lớn hơn thì nên đẩy các phép gộp này xuống API.
     */
    protected function breakdown(array $window): array
    {
        $out = [
            'scanned' => 0,        // số đơn thực sự đọc được
            'total' => 0,          // tổng đơn trong kỳ theo API
            'sampled' => false,    // true = chỉ gộp trên một phần đơn của kỳ
            'net_orders' => 0,     // đơn còn sống (không huỷ/hoàn)
            'net_revenue' => 0.0,
            'aov' => 0.0,
            'dead_orders' => 0,    // đơn huỷ + hoàn hàng
            'guest_orders' => 0,   // đơn không gắn tài khoản khách
            'methods' => [],       // payment_method => [orders, revenue]
            'unpaid_orders' => 0,  // đơn còn sống nhưng chưa thu tiền
            'unpaid_amount' => 0.0,
            'provinces' => [],     // tỉnh/thành => [orders, revenue]
            'statuses' => [],      // status => số đơn trong kỳ
            'hours' => array_fill(0, 24, 0),
        ];

        $from = $window['from'];
        $to = $window['to'];

        $page = 1;
        $pages = 1;
        do {
            try {
                $res = $this->api->orders([
                    'from_date' => $from, 'to_date' => $to,
                    'sort' => 'newest', 'page' => $page, 'page_size' => 100,
                ]);
                if (! $res->successful()) {
                    Log::warning('Dashboard: breakdown failed', ['status' => $res->status()]);
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning('Dashboard: breakdown failed', ['msg' => $e->getMessage()]);
                break;
            }

            $rows = $res->json('data') ?? [];
            $out['total'] = (int) ($res->json('meta.total') ?? 0);
            $pages = max(1, (int) ($res->json('meta.total_pages') ?? 1));

            foreach ($rows as $o) {
                $out['scanned']++;
                $status = $o['status'] ?? 'pending';
                $amount = (float) ($o['total_amount'] ?? 0);
                $out['statuses'][$status] = ($out['statuses'][$status] ?? 0) + 1;

                if (! empty($o['created_at'])) {
                    $h = (int) \Illuminate\Support\Carbon::parse($o['created_at'])->format('G');
                    $out['hours'][$h] = ($out['hours'][$h] ?? 0) + 1;
                }

                if (in_array($status, self::DEAD_STATUSES, true)) {
                    $out['dead_orders']++;
                    continue;
                }

                $out['net_orders']++;
                $out['net_revenue'] += $amount;
                if (empty($o['user_id'])) {
                    $out['guest_orders']++;
                }

                $method = $o['payment_method'] ?? 'cod';
                $out['methods'][$method]['orders'] = ($out['methods'][$method]['orders'] ?? 0) + 1;
                $out['methods'][$method]['revenue'] = ($out['methods'][$method]['revenue'] ?? 0) + $amount;

                if (($o['payment_status'] ?? 'pending') !== 'paid') {
                    $out['unpaid_orders']++;
                    $out['unpaid_amount'] += $amount;
                }

                $province = trim((string) ($o['shipping_province'] ?? ''));
                if ($province !== '') {
                    $out['provinces'][$province]['orders'] = ($out['provinces'][$province]['orders'] ?? 0) + 1;
                    $out['provinces'][$province]['revenue'] = ($out['provinces'][$province]['revenue'] ?? 0) + $amount;
                }
            }

            $page++;
        } while ($page <= $pages && $page <= self::MAX_ORDER_PAGES);

        $out['sampled'] = $out['total'] > $out['scanned'];
        $out['aov'] = $out['net_orders'] > 0 ? $out['net_revenue'] / $out['net_orders'] : 0.0;

        // Nhiều đơn nhất lên trước — phần đuôi dài không có chỗ trên trang.
        uasort($out['methods'], fn ($a, $b) => $b['orders'] <=> $a['orders']);
        uasort($out['provinces'], fn ($a, $b) => $b['orders'] <=> $a['orders']);
        $out['provinces'] = \array_slice($out['provinces'], 0, 6, true);

        return $out;
    }

    protected function recentOrders(): array
    {
        return $this->fetch(
            fn () => $this->api->orders(['page_size' => 8, 'sort' => 'newest']),
            [],
            'recent orders'
        );
    }

    /** Top sản phẩm bán chạy — API đã có sẵn kiểu sắp xếp này. */
    protected function topProducts(): array
    {
        return $this->fetch(
            fn () => $this->api->products(['sort' => 'best_selling', 'page_size' => 5, 'all' => 'true']),
            [],
            'top products'
        );
    }

    /** Khách chi tiêu nhiều nhất — API đã có sẵn kiểu sắp xếp này. */
    protected function topCustomers(): array
    {
        return $this->fetch(
            fn () => $this->api->customers(['sort' => 'spent_desc', 'page_size' => 5]),
            [],
            'top customers'
        );
    }

    /**
     * Biến thể sắp hết hàng — lấy thẳng từ endpoint tồn kho.
     *
     * Endpoint này sắp `stock_asc` trên TOÀN kho nên trang đầu đã đúng là những
     * biến thể ít hàng nhất, không còn cảnh quét 100 sản phẩm đầu rồi lọc tay và
     * bỏ sót phần kho phía sau. Hàng hết sạch (tồn 0) cũng nằm trong nhóm này và
     * lên trước — đó mới là thứ cần nhập gấp.
     *
     * Không dùng `stock=low` vì bộ lọc đó chỉ nhận tồn > 0, sẽ rụng mất đúng
     * những dòng "Hết hàng" mà thẻ này đang hiển thị; sắp xếp tồn tăng dần rồi
     * cắt ở ngưỡng cho ra cả hai nhóm trong một lần gọi.
     *
     * Lấy dư vài dòng vì API mới lọc được trạng thái bán của biến thể, chưa lọc
     * được của sản phẩm cha: biến thể còn bán nhưng sản phẩm đang ẩn thì loại ở
     * đây, phần dư bù lại đúng bằng những dòng bị loại đó.
     */
    /**
     * Ngưỡng "sắp hết" đang cấu hình — cùng khoá với trang Tồn kho để hai nơi không
     * bao giờ cảnh báo lệch nhau. Đọc qua bản cache 5 phút của ApiClient.
     */
    protected function lowStockThreshold(): int
    {
        return $this->api->settingInt('low_stock_threshold', self::LOW_STOCK);
    }

    protected function lowStock(): array
    {
        $threshold = $this->lowStockThreshold();

        $items = $this->fetch(
            fn () => $this->api->inventory([
                'is_active' => 'true',
                'sort' => 'stock_asc',
                'page_size' => self::LOW_STOCK_ROWS * 3,
            ]),
            [],
            'low stock'
        );

        $rows = [];
        foreach ($items as $it) {
            $stock = (int) ($it['stock_quantity'] ?? 0);
            if ($stock > $threshold) {
                break; // đã sắp tồn tăng dần — từ đây trở đi không còn gì để cảnh báo
            }
            if (! ($it['product_active'] ?? true)) {
                continue;
            }
            $rows[] = [
                'product' => $it['product_name'] ?? '',
                'sku' => $it['sku'] ?? '',
                'variant' => trim(implode(' / ', array_filter([$it['size'] ?? '', $it['color'] ?? '']))),
                'stock' => $stock,
            ];
            if (\count($rows) >= self::LOW_STOCK_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /** Gọi API, trả về mặc định khi hỏng và ghi log — không ném lỗi ra trang. */
    protected function fetch(callable $call, array $default, string $what): array
    {
        try {
            $res = $call();
            if ($res->successful()) {
                return $res->json('data') ?? $default;
            }
            Log::warning('Dashboard: '.$what.' failed', ['status' => $res->status()]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard: '.$what.' failed', ['msg' => $e->getMessage()]);
        }

        return $default;
    }
}
