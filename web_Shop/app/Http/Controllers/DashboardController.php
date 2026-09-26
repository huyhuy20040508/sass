<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * DashboardController — màn Tổng quan, dựng lại theo bản v2.
 *
 * Gom số liệu từ nhiều endpoint của Go API. Mỗi khối tự chịu lỗi riêng: một
 * endpoint hỏng chỉ làm khối đó trống chứ không làm trắng cả trang — đây là
 * trang đầu tiên nhân viên nhìn thấy mỗi sáng.
 *
 * Trang dựng SẴN ở máy chủ rồi mới trả về, không để trống rồi gọi AJAX lấp vào
 * như bản gốc: mọi màn v2 khác trong dự án đều lọc bằng tham số trên URL, nên
 * một màn riêng chạy kiểu khác là hai lối đi cho cùng một việc.
 */
class DashboardController extends Controller
{
    /**
     * Các kỳ xem được, theo đúng thứ tự bày trong khung lọc bên trái.
     *
     * Hai họ nằm cạnh nhau có chủ ý: cửa sổ trượt ("7 ngày qua") để xem đà bán,
     * và mốc lịch ("tháng này") để đối chiếu sổ sách. Định nghĩa nằm ở
     * \App\Support\Period — dùng chung với nhóm trang Báo cáo.
     */
    public const RANGE_CODES = [
        'today', 'yesterday',
        'this-week', 'last-week', '7',
        'this-month', 'last-month', '30',
        'this-quarter', 'last-quarter',
        'this-year', 'last-year',
    ];

    /** Nhóm nút trong khung lọc: tiêu đề => mã kỳ. */
    public const RANGE_GROUPS = [
        'Theo ngày' => ['today', 'yesterday'],
        'Theo tuần' => ['this-week', 'last-week', '7'],
        'Theo tháng' => ['this-month', 'last-month', '30'],
        'Theo quý' => ['this-quarter', 'last-quarter'],
        'Theo năm' => ['this-year', 'last-year'],
    ];

    /** Mở trang lần đầu: ca đang chạy hôm nay là thứ người ta mở màn này để xem. */
    public const DEFAULT_RANGE = 'today';

    /** Số dòng của các thẻ "Top …" — bày đúng những lựa chọn bản v2 có. */
    public const TOP_CHOICES = [3, 5, 10, 15];

    /** Mặc định của từng thẻ Top, theo đúng bản v2. */
    public const TOP_MAC_DINH = ['products' => 5, 'payment' => 3, 'origin' => 3, 'promo' => 3, 'branch' => 3];

    /**
     * Số trang phiếu mua hàng (100 phiếu/trang) tối đa được quét.
     *
     * Hai ô "Chi phí mua hàng" và "Số lượng nhập kho" phải cộng từ danh sách vì
     * `/phieu-mua-hang/stats` không nhận khoảng ngày. Chặn ở đây để một kỳ dài
     * không kéo theo hàng chục lượt gọi; quá ngưỡng thì trang nói thẳng là số
     * liệu tính trên mẫu chứ không im lặng đưa ra con số thiếu.
     */
    public const MAX_PURCHASE_PAGES = 5;

    /** Phiếu mua chưa duyệt không tính vào tiền hàng đã mua — khớp cách API tính stats. */
    protected const PURCHASE_DEAD = ['cancelled', 'draft'];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $doanhThu = $this->doanhThu($filters);
        $donHang = $this->donHang($filters);
        $muaHang = $this->muaHang($filters);

        $tong = $doanhThu['totals'] ?? [];
        $gop = (float) ($tong['subtotal'] ?? 0) + (float) ($tong['shipping'] ?? 0);

        return view('v2::dashboard.index', [
            'filters' => $filters,
            'rangeGroups' => self::RANGE_GROUPS,
            'rangeLabels' => $this->rangeLabels(),
            'topChoices' => self::TOP_CHOICES,
            'chiNhanh' => CurrentBranch::danhSach(),

            // Sáu ô đầu trang. Công thức theo đúng chú giải của bản v2 (xem
            // message.gross_revenue_formula): gộp là tiền hàng chưa trừ gì, thuần
            // là gộp trừ giảm giá, còn ước tính đếm cả đơn chưa thu tiền.
            'kpi' => [
                'gross' => $gop,
                'net' => $gop - (float) ($tong['discount'] ?? 0),
                'estimated' => $this->uocTinh($doanhThu),
                'orders' => (int) ($tong['orders'] ?? 0),
                'purchase_cost' => $muaHang['tien'],
                'purchase_qty' => $muaHang['soLuong'],
                'purchase_sampled' => $muaHang['catBot'],
            ],

            'ca' => $this->caHienTai(),
            'chart' => $this->duLieuBieuDo($doanhThu),
            'banChay' => $this->banChay($filters),
            'theoThanhToan' => $this->catLat($doanhThu['by_payment_method'] ?? [], $filters['top_payment']),
            'theoNguon' => $this->catLat($donHang['by_source'] ?? [], $filters['top_origin']),
            'theoKhuyenMai' => $this->khuyenMai(),
            'theoChiNhanh' => $this->catLat($doanhThu['by_shop'] ?? [], $filters['top_branch'], 'label'),
        ]);
    }

    // ---------- Bộ lọc ----------

    /**
     * Kỳ đang xem + số dòng của từng thẻ Top.
     *
     * Ngày tự chọn thắng preset: gõ tay vào hai ô ngày là người dùng đã nói rõ
     * mình muốn gì. Khoảng tự chọn trùng đúng một preset thì nút đó vẫn sáng lên
     * (Period::match lo việc này) để hai cách chọn không mâu thuẫn nhau.
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        $from = $this->ngay($request->query('from'));
        $to = $this->ngay($request->query('to'));

        if ($from === null || $to === null) {
            $range = (string) $request->query('range', self::DEFAULT_RANGE);
            if (! in_array($range, self::RANGE_CODES, true)) {
                $range = self::DEFAULT_RANGE;
            }
            $window = Period::resolve($range);
            $from = $window['from'];
            $to = $window['to'];
        } elseif ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $out = [
            'from' => $from,
            'to' => $to,
            'range' => Period::match($from, $to, self::RANGE_CODES),
            'describe' => Period::describe($from, $to, self::RANGE_CODES),
        ];

        foreach (self::TOP_MAC_DINH as $khoa => $macDinh) {
            $n = (int) $request->query('top_'.$khoa, (string) $macDinh);
            $out['top_'.$khoa] = in_array($n, self::TOP_CHOICES, true) ? $n : $macDinh;
        }

        return $out;
    }

    /**
     * Ngày trên URL, chuẩn hoá về Y-m-d.
     *
     * Nhận cả `d-m-Y` vì lịch của vỏ v2 điền ra khuôn ấy, và cả `Y-m-d` để link
     * chia sẻ / bookmark cũ vẫn mở đúng kỳ. Khuôn lạ trả null để nơi gọi rơi về
     * preset thay vì dựng ra một kỳ bừa.
     */
    protected function ngay(mixed $v): ?string
    {
        $s = trim((string) $v);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s) === 1) {
            return checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4)) ? $s : null;
        }

        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? $m[3].'-'.$m[2].'-'.$m[1] : null;
        }

        return null;
    }

    /** @return array<string, string> */
    protected function rangeLabels(): array
    {
        $out = [];
        foreach (self::RANGE_CODES as $code) {
            $out[$code] = Period::PRESETS[$code]['label'];
        }

        return $out;
    }

    // ---------- Từng khối số liệu ----------

    /** @return array<string, mixed> */
    protected function doanhThu(array $f): array
    {
        return $this->fetch(
            fn () => $this->api->reportRevenue(['from' => $f['from'], 'to' => $f['to'], 'group_by' => $this->chiaTruc($f)]),
            [],
            'revenue report'
        );
    }

    /** @return array<string, mixed> */
    protected function donHang(array $f): array
    {
        return $this->fetch(
            fn () => $this->api->reportOrders(['from' => $f['from'], 'to' => $f['to']]),
            [],
            'order report'
        );
    }

    /**
     * Cách chia trục thời gian của biểu đồ doanh thu.
     *
     * Một năm chia theo ngày là 365 cột chen trong một thẻ rộng chừng 600px —
     * không đọc được cột nào. Kỳ càng dài thì gộp càng thô.
     */
    protected function chiaTruc(array $f): string
    {
        $ngay = (strtotime($f['to']) - strtotime($f['from'])) / 86400 + 1;

        return match (true) {
            $ngay > 120 => 'month',
            $ngay > 45 => 'week',
            default => 'day',
        };
    }

    /**
     * Doanh thu ước tính = tiền đơn CHƯA thu + ĐÃ thu.
     *
     * Khác doanh thu thuần ở chỗ nó đếm cả đơn công nợ và đơn mới trả một phần:
     * đây là con số trả lời "kỳ này bán được bao nhiêu", còn thuần trả lời "đã
     * chắc chắn vào túi bao nhiêu". Đơn hoàn tiền / thu lỗi không tính.
     */
    protected function uocTinh(array $doanhThu): float
    {
        $tong = 0.0;
        foreach ($doanhThu['by_payment_status'] ?? [] as $lat) {
            if (in_array($lat['key'] ?? '', ['pending', 'paid'], true)) {
                $tong += (float) ($lat['revenue'] ?? 0);
            }
        }

        return $tong;
    }

    /**
     * Ca đang mở của chi nhánh đang xem, hoặc null khi chưa ai mở ca.
     *
     * API trả `data: null` chứ không phải lỗi khi không có ca — đó là trạng thái
     * bình thường của một tiệm chưa tới giờ bán.
     */
    protected function caHienTai(): ?array
    {
        $ca = null;
        try {
            $res = $this->api->caHienTai();
            if ($res->successful()) {
                $ca = $res->json('data');
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard: current shift failed', ['msg' => $e->getMessage()]);
        }

        if (! is_array($ca)) {
            return null;
        }

        // Ca của API không có "mã ca" riêng như bản v2 — id CHÍNH LÀ thứ hai bên
        // đối chiếu khi tra lại một lượt trực, nên in thẳng nó thay vì bịa ra
        // một dãy mã không có trong sổ.
        //
        // Tiền mặt là tiền SỔ nói lẽ ra đang có trong két: đầu ca cộng thu trừ
        // chi. Ca đã chốt thì lấy số đã ký nhận hôm ấy (`expected_cash`).
        $tienMat = $ca['expected_cash']
            ?? (float) ($ca['opening_cash'] ?? 0) + (float) ($ca['tong_thu'] ?? 0) - (float) ($ca['tong_chi'] ?? 0);

        // API không phải lúc nào cũng điền `shop_name`; ca đang mở thì luôn là ca
        // của chi nhánh đang xem, nên lấy tên ở đó còn hơn bày một dấu gạch.
        $tenCN = (string) ($ca['shop_name'] ?? '');
        if ($tenCN === '') {
            $cn = CurrentBranch::danhSach();
            foreach ($cn['ds'] ?? [] as $b) {
                if ((int) ($b['id'] ?? 0) === (int) ($ca['shop_id'] ?? 0)) {
                    $tenCN = (string) ($b['name'] ?? '');
                    break;
                }
            }
        }

        return [
            'ma' => '#'.($ca['id'] ?? '—'),
            'chi_nhanh' => $tenCN,
            'nguoi_mo' => (string) ($ca['opened_by_name'] ?? ''),
            'gio_mo' => $ca['opened_at'] ?? null,
            'gio_dong' => $ca['closed_at'] ?? null,
            'tien_mat' => (float) $tienMat,
            'so_don' => (int) ($ca['so_don_tien_mat'] ?? 0),
        ];
    }

    /**
     * Ba đường của biểu đồ "Doanh thu bán hàng": gộp, thuần, chi phí mua hàng.
     *
     * Chi phí ở đây là GIÁ VỐN của hàng đã bán (`cost` trong báo cáo), không
     * phải tiền mua hàng nhập kho — hai thứ khác nhau và chỉ giá vốn mới so
     * cùng trục với doanh thu của chính những đơn đó.
     *
     * @return array<string, mixed>
     */
    protected function duLieuBieuDo(array $doanhThu): array
    {
        $nhan = [];
        $gop = [];
        $thuan = [];
        $von = [];

        foreach ($doanhThu['buckets'] ?? [] as $b) {
            $nhan[] = $this->nhanMoc((string) ($b['label'] ?? ''));
            $g = (float) ($b['subtotal'] ?? 0) + (float) ($b['shipping'] ?? 0);
            $gop[] = $g;
            $thuan[] = $g - (float) ($b['discount'] ?? 0);
            $von[] = (float) ($b['cost'] ?? 0);
        }

        return ['labels' => $nhan, 'gross' => $gop, 'net' => $thuan, 'cost' => $von];
    }

    /**
     * Nhãn một mốc trên trục ngang, gọn lại cho vừa bề ngang thẻ.
     *
     * API trả khoá tự mô tả ("2026-09-13", "2026-W38", "2026-09"); in nguyên
     * dạng ấy thì hai mươi mốc chen nhau thành một vệt chữ xoay nghiêng.
     */
    protected function nhanMoc(string $label): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $label, $m) === 1) {
            return $m[3].'/'.$m[2];
        }
        if (preg_match('/^(\d{4})-W(\d{1,2})$/', $label, $m) === 1) {
            return 'T'.(int) $m[2];
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $label, $m) === 1) {
            return $m[2].'/'.$m[1];
        }

        return $label;
    }

    /**
     * Top mặt hàng bán chạy — xếp theo SỐ LƯỢNG, không theo tiền.
     *
     * Thẻ này in cột "Số lượng" nên phải xếp theo đúng cột đang in; xếp theo
     * doanh thu mà in số lượng thì bảng trông như sắp xếp sai.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function banChay(array $f): array
    {
        $bc = $this->fetch(
            fn () => $this->api->reportProducts(['from' => $f['from'], 'to' => $f['to'], 'sort' => 'units']),
            [],
            'product report'
        );

        $ds = array_values(array_filter(
            $bc['items'] ?? [],
            fn ($r) => (int) ($r['units'] ?? 0) > 0
        ));

        return array_slice($ds, 0, $f['top_products']);
    }

    /**
     * Cắt một danh sách "lát" của báo cáo về N dòng nhiều nhất.
     *
     * @param  array<int, array<string, mixed>>  $lat
     * @return array<int, array<string, mixed>>
     */
    protected function catLat(array $lat, int $n, string $nhan = 'key'): array
    {
        $ds = array_values(array_filter($lat, fn ($r) => (float) ($r['revenue'] ?? 0) != 0.0 || (int) ($r['orders'] ?? 0) > 0));
        usort($ds, fn ($a, $b) => ($b['revenue'] ?? 0) <=> ($a['revenue'] ?? 0));

        return array_map(
            fn ($r) => $r + ['nhan' => (string) ($r[$nhan] ?? $r['key'] ?? '')],
            array_slice($ds, 0, $n)
        );
    }

    /**
     * Top khuyến mại theo số lần dùng.
     *
     * API chưa có sổ đếm lượt dùng từng chương trình (`/promotions/stats` chỉ
     * đếm chương trình theo trạng thái), nên thẻ này bày rỗng và nói rõ lý do
     * thay vì đưa ra một con số không có nguồn.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function khuyenMai(): array
    {
        return [];
    }

    /**
     * Tiền mua hàng và số lượng nhập kho trong kỳ.
     *
     * Cộng từ danh sách phiếu vì `/phieu-mua-hang/stats` không nhận khoảng ngày.
     *
     * @return array{tien: float, soLuong: int, catBot: bool}
     */
    protected function muaHang(array $f): array
    {
        $tien = 0.0;
        $soLuong = 0;
        $catBot = false;
        $trang = 1;
        $soTrang = 1;

        try {
            do {
                $res = $this->api->phieuMuaHang([
                    'from_date' => $f['from'],
                    'to_date' => $f['to'],
                    'page' => $trang,
                    'page_size' => 100,
                ]);
                if (! $res->successful()) {
                    break;
                }

                foreach ($res->json('data') ?? [] as $phieu) {
                    if (in_array($phieu['status'] ?? '', self::PURCHASE_DEAD, true)) {
                        continue;
                    }
                    $tien += (float) ($phieu['total_amount'] ?? 0);
                    foreach ($phieu['items'] ?? [] as $dong) {
                        $soLuong += (int) ($dong['base_quantity'] ?? $dong['quantity'] ?? 0);
                    }
                }

                $soTrang = (int) ($res->json('meta.total_pages') ?? 1);
                $catBot = $soTrang > self::MAX_PURCHASE_PAGES;
                $trang++;
            } while ($trang <= $soTrang && $trang <= self::MAX_PURCHASE_PAGES);
        } catch (\Throwable $e) {
            Log::warning('Dashboard: purchase orders failed', ['msg' => $e->getMessage()]);
        }

        return ['tien' => $tien, 'soLuong' => $soLuong, 'catBot' => $catBot];
    }

    /**
     * Gọi một endpoint, hỏng thì trả mặc định.
     *
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
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
