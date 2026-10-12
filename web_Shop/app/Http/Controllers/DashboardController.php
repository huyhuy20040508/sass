<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use App\Support\Period;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
 * một màn riêng chạy kiểu khác là hai lối đi cho cùng một việc. Bù lại các lượt
 * gọi API chạy song song (ApiClient::getMany) để trang không chậm theo số khối.
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

    /**
     * Mặc định của từng thẻ Top. Thanh toán / nguồn đơn / chi nhánh chỉ có vài
     * lát nên bày hết (bản thiết kế 11/10/2026 bỏ ô chọn Top ở ba thẻ này).
     */
    public const TOP_MAC_DINH = ['products' => 5, 'payment' => 15, 'origin' => 15, 'promo' => 5, 'branch' => 15];

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

    /** Cách chia trục biểu đồ doanh thu người dùng chọn được. */
    public const GROUPS = ['day' => 'Ngày', 'week' => 'Tuần', 'month' => 'Tháng'];

    /**
     * Khoá cấu hình giữ mục tiêu doanh thu tháng (doanh thu thuần).
     *
     * Thanh mục tiêu chỉ hiện khi API đã khai khoá này trong registry cấu hình —
     * chưa khai thì PUT /settings trả 422 "khoá không tồn tại", bày ô nhập ra chỉ
     * để người dùng gõ vào rồi bị từ chối.
     */
    public const GOAL_KEY = 'monthly_revenue_goal';

    /** Ca mở quá chừng này giờ mà chưa chốt thì đưa vào thẻ "Cần chú ý". */
    public const CA_QUA_LAU_GIO = 24;

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $chiNhanh = CurrentBranch::danhSach();
        $f = $this->filters($request, $chiNhanh['ds']);
        $cn = ['shop_id' => $f['branch']];
        [$tuTruoc, $denTruoc] = $this->khoangTruoc($f['from'], $f['to']);
        $ky = ['from' => $f['from'], 'to' => $f['to']];

        $goi = [
            'doanhThu' => ['/admin/reports/revenue', $ky + ['group_by' => $f['group']] + $cn],
            // Kỳ trước cùng độ dài, cùng cách chia trục — nét đứt trên biểu đồ doanh thu.
            'doanhThuTruoc' => ['/admin/reports/revenue', ['from' => $tuTruoc, 'to' => $denTruoc, 'group_by' => $f['group']] + $cn],
            'donHang' => ['/admin/reports/orders', $ky + $cn],
            'sanPham' => ['/admin/reports/products', $ky + ['sort' => 'units'] + $cn],
            'mua' => ['/admin/phieu-mua-hang', $this->queryMua($f, 1)],
            'muaTruoc' => ['/admin/phieu-mua-hang', $this->queryMua(['from' => $tuTruoc, 'to' => $denTruoc] + $f, 1)],
            'ca' => ['/admin/ca-lam-viec', ['status' => 'dang_mo', 'page_size' => 100] + $cn],
            'tonKho' => ['/admin/inventory/stats', ['low_stock' => $this->api->settingInt('low_stock_threshold', BranchStockController::LOW_STOCK)]],
            'hoaDonLoi' => ['/admin/etax/hoa-don', ['status' => 'failed', 'page_size' => 1]],
        ];
        // Khuyến mại được dùng: báo cáo khuyến mại chia theo loại chương trình, gọi
        // đủ các loại rồi gộp lại.
        foreach (array_keys(PromotionReportController::TAB) as $tab) {
            $goi['km_'.$tab] = ['/admin/reports/promotions', $ky + ['tab' => $tab] + $cn];
        }
        // Thanh mục tiêu luôn bày tiến độ THÁNG NÀY; kỳ đang xem không trọn một
        // tháng thì gọi thêm doanh thu từ mùng 1 tới hôm nay.
        if (! $this->tronThang($f)) {
            $goi['doanhThuThang'] = ['/admin/reports/revenue', ['from' => now()->startOfMonth()->format('Y-m-d'), 'to' => now()->format('Y-m-d'), 'group_by' => 'month'] + $cn];
        }
        $nen = $this->api->getMany($goi);

        $doanhThu = $this->data($nen['doanhThu'] ?? null, 'revenue report');
        $donHang = $this->data($nen['donHang'] ?? null, 'order report');
        $chart = $this->duLieuBieuDo($doanhThu, $f, $this->data($nen['doanhThuTruoc'] ?? null, 'previous revenue report'));
        $muaHang = $this->muaHang($f, $nen['mua'] ?? null, $chart['ranges']);
        $muaTruoc = $this->muaHang(['from' => $tuTruoc, 'to' => $denTruoc] + $f, $nen['muaTruoc'] ?? null);
        $caMo = $this->caDangMo($this->data($nen['ca'] ?? null, 'open shifts'), $chiNhanh['ds']);
        $kpi = $this->kpi($doanhThu, $donHang, $muaHang);
        $kpi['so']['purchase_qty'] = $muaHang['soLuong'] || $muaTruoc['soLuong']
            ? $this->soVoiKyTruoc($muaHang['soLuong'], $muaTruoc['soLuong']) + ['chenh' => $muaHang['soLuong'] - $muaTruoc['soLuong']]
            : null;
        $km = $this->khuyenMai($nen, $f['top_promo']);

        return view('v2::dashboard.index', [
            'filters' => $f,
            'rangeGroups' => self::RANGE_GROUPS,
            'rangeLabels' => $this->rangeLabels(),
            'topChoices' => self::TOP_CHOICES,
            'groups' => self::GROUPS,
            'chiNhanh' => $chiNhanh,
            'kpi' => $kpi,
            'kyTruoc' => $this->kyTruoc($doanhThu),
            'mucTieu' => $this->mucTieu($f, $this->tronThang($f) ? $kpi['net'] : $this->thuan($this->data($nen['doanhThuThang'] ?? null, 'month revenue'))),
            'caMo' => $caMo,
            'canhBao' => $this->canhBao($caMo, $this->data($nen['tonKho'] ?? null, 'inventory stats'), $nen['hoaDonLoi'] ?? null),
            'chart' => $chart,
            'muaTheoMoc' => $muaHang['theoMoc'],
            'gioCaoDiem' => $this->gioCaoDiem($donHang),
            'theoThu' => $this->theoThu($donHang),
            'banChay' => $this->banChay($this->data($nen['sanPham'] ?? null, 'product report'), $f),
            'khuyenMai' => $km,
            'theoThanhToan' => $this->catLat($doanhThu['by_payment_method'] ?? [], $f['top_payment']),
            'theoNguon' => $this->catLat($donHang['by_source'] ?? [], $f['top_origin']),
            'theoChiNhanh' => $this->catLat($doanhThu['by_shop'] ?? [], $f['top_branch'], 'label'),
            'capNhatLuc' => now()->format('H:i'),
        ]);
    }

    /**
     * Lưu mục tiêu doanh thu tháng của cả cửa hàng.
     *
     * Ghi qua PUT /settings như màn Cài đặt, nên quyền là quyền sửa cấu hình
     * (`cau-hinh.sua`) — API trả 403 thì nói lại đúng câu của nó.
     *
     * ĐẶT và BỎ là hai ý định khác nhau, đi hai đường khác nhau. Trước đây ô
     * trống (hoặc gõ chữ, vì JS lọc sạch chữ nên ô thành rỗng) rơi về 0, mà 0
     * nghĩa là "chưa đặt" — màn báo xanh "Đã lưu" rồi xoá sạch mục tiêu cũ.
     * Người dùng không hề muốn bỏ, và câu báo còn nói ngược lại điều vừa xảy ra.
     *
     * Nên: không có chữ số nào thì KHÔNG ghi gì cả, giữ nguyên số cũ và nói
     * thiếu gì. Muốn bỏ thì bấm nút "Bỏ mục tiêu" — nút ấy gửi `bo_muc_tieu`,
     * là lời khai rõ ràng chứ không phải hệ quả của một ô rỗng.
     */
    public function mucTieuLuu(Request $request)
    {
        $boMucTieu = $request->boolean('bo_muc_tieu');
        $chuSo = preg_replace('/\D/', '', (string) $request->input('muc_tieu', ''));

        if (! $boMucTieu) {
            if ($chuSo === '') {
                return back()->with('error', 'Nhập số tiền mục tiêu.');
            }
            // Gõ 0 cũng là bỏ mục tiêu, nhưng bằng một cách không ai đọc ra được
            // ý ấy — bắt khai bằng nút cho rõ.
            if ((int) $chuSo === 0) {
                return back()->with('error', 'Mục tiêu phải lớn hơn 0. Muốn bỏ thì bấm "Bỏ mục tiêu".');
            }
        }

        $so = $boMucTieu ? 0 : (int) $chuSo;

        try {
            $res = $this->api->updateSettings([self::GOAL_KEY => (string) $so]);
        } catch (\Throwable $e) {
            Log::warning('Dashboard: save goal failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return back()->with('error', (string) ($res->json('errors.'.self::GOAL_KEY) ?: $res->json('message') ?: 'Không lưu được mục tiêu.'));
        }

        Cache::forget(ApiClient::khoaCacheSettings());

        return back()->with('success', $boMucTieu ? 'Đã bỏ mục tiêu doanh thu tháng.' : 'Đã lưu mục tiêu doanh thu tháng.');
    }

    // ---------- Bộ lọc ----------

    /**
     * Kỳ đang xem, chi nhánh đang xem, cách chia trục + số dòng của từng thẻ Top.
     *
     * Ngày tự chọn thắng preset: gõ tay vào hai ô ngày là người dùng đã nói rõ
     * mình muốn gì. Khoảng tự chọn trùng đúng một preset thì nút đó vẫn sáng lên
     * (Period::match lo việc này) để hai cách chọn không mâu thuẫn nhau.
     *
     * Chi nhánh là bộ lọc RIÊNG của màn này, không phải chi nhánh làm việc của
     * tab như ở Thu chi / Đơn hàng: màn chỉ đọc, nên xem gộp cả cửa hàng (mặc
     * định, như v2) không gây ghi nhầm kho — chủ tiệm chốt 06/10/2026. Nhân
     * viên bị ghim chi nhánh gửi gì lên thì API vẫn ép về chi nhánh của họ.
     *
     * @param  array<int, array<string, mixed>>  $dsChiNhanh
     * @return array<string, mixed>
     */
    protected function filters(Request $request, array $dsChiNhanh = []): array
    {
        $from = $this->ngay($request->query('from'));
        $to = $this->ngay($request->query('to'));

        // Mã kỳ người dùng BẤM, giữ nguyên để sáng đúng nút đó.
        $maKy = null;

        if ($from === null || $to === null) {
            $range = (string) $request->query('range', self::DEFAULT_RANGE);
            if (! in_array($range, self::RANGE_CODES, true)) {
                $range = self::DEFAULT_RANGE;
            }
            $maKy = $range;
            $window = Period::resolve($range);
            $from = $window['from'];
            $to = $window['to'];
        } elseif ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $branch = (int) $request->query('branch', '0');
        $coThat = collect($dsChiNhanh)->contains(fn ($cn) => (int) ($cn['id'] ?? 0) === $branch);

        $group = (string) $request->query('group', '');

        $out = [
            'from' => $from,
            'to' => $to,
            'branch' => $coThat ? $branch : 0,
            // Bấm nút thì sáng ĐÚNG nút đã bấm; chỉ dò theo ngày khi người dùng gõ
            // ngày tự chọn.
            //
            // Vì sao không dò cho cả hai: nhiều preset trùng khoảng nhau tuỳ thời
            // điểm, và Period::match trả về preset ĐỨNG TRƯỚC trong danh sách. Đầu
            // tháng 10, "quý này" và "tháng này" cùng là 01/10 → hôm nay, nên bấm
            // "Quý này" lại thấy "Tháng này" sáng — người bấm tưởng nút không ăn.
            // Tháng 1 thì "Năm nay" cũng vậy.
            'range' => $maKy ?? Period::match($from, $to, self::RANGE_CODES),
            'describe' => Period::describe($from, $to, self::RANGE_CODES),
            'group' => isset(self::GROUPS[$group]) ? $group : $this->chiaTruc($from, $to),
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

    /**
     * Cách chia trục mặc định khi người dùng chưa bấm Ngày / Tuần / Tháng.
     *
     * Một năm chia theo ngày là 365 cột chen trong một thẻ rộng chừng 600px —
     * không đọc được cột nào. Kỳ càng dài thì gộp càng thô.
     */
    protected function chiaTruc(string $from, string $to): string
    {
        $ngay = (strtotime($to) - strtotime($from)) / 86400 + 1;

        return match (true) {
            $ngay > 120 => 'month',
            $ngay > 45 => 'week',
            default => 'day',
        };
    }

    // ---------- Từng khối số liệu ----------

    /**
     * Sáu ô đầu trang. Công thức theo chú giải của bản v2 (message.gross_revenue_formula):
     * gộp là tiền hàng chưa trừ gì, thuần là gộp trừ giảm giá. Lợi nhuận gộp lấy
     * thẳng `profit` của API (thuần trừ giá vốn, không tính phí ship vì đó là tiền
     * thu hộ nhà xe) — tự trừ ở đây là hai nơi giữ hai công thức.
     *
     * @return array<string, mixed>
     */
    protected function kpi(array $doanhThu, array $donHang, array $muaHang): array
    {
        $tien = function (array $t): array {
            $gop = (float) ($t['subtotal'] ?? 0) + (float) ($t['shipping'] ?? 0);

            return ['gross' => $gop, 'net' => $gop - (float) ($t['discount'] ?? 0), 'profit' => (float) ($t['profit'] ?? 0), 'orders' => (int) ($t['orders'] ?? 0)];
        };
        $nay = $tien($doanhThu['totals'] ?? []);
        $truoc = $tien($doanhThu['prev'] ?? []);

        $so = [];
        foreach (['gross', 'net', 'profit', 'orders'] as $k) {
            $so[$k] = $this->soVoiKyTruoc($nay[$k], $truoc[$k]);
        }

        return $nay + [
            'prev' => $truoc,
            'discount' => (float) ($doanhThu['totals']['discount'] ?? 0),
            'purchase_count' => $muaHang['soPhieu'],
            'purchase_paid' => $muaHang['daTra'],
            'purchase_items' => $muaHang['matHang'],
            'margin' => $nay['net'] > 0 ? $nay['profit'] / $nay['net'] * 100 : null,
            'aov' => (float) ($doanhThu['totals']['aov'] ?? 0),
            'units_per_order' => (float) ($donHang['totals']['units_per_order'] ?? 0),
            'purchase_cost' => $muaHang['tien'],
            'purchase_qty' => $muaHang['soLuong'],
            'purchase_sampled' => $muaHang['catBot'],
            'so' => $so,
        ];
    }

    /**
     * Mức tăng/giảm so với kỳ trước cùng độ dài, hoặc null khi cả hai kỳ đều 0.
     *
     * Kỳ trước bằng 0 thì không có phần trăm nào đúng (chia cho 0) — gắn nhãn
     * "mới" thay vì in "+∞%" hay "+100%" bịa ra.
     *
     * @return array{moi: bool, pct: ?float}|null
     */
    protected function soVoiKyTruoc(float $nay, float $truoc): ?array
    {
        if ($truoc == 0.0) {
            return $nay == 0.0 ? null : ['moi' => true, 'pct' => null];
        }

        return ['moi' => false, 'pct' => ($nay - $truoc) / abs($truoc) * 100];
    }

    /** Khoảng của kỳ trước như API đã dùng để so — in ra cho người xem biết đang so với đâu. */
    protected function kyTruoc(array $doanhThu): ?string
    {
        $tu = $doanhThu['prev_from'] ?? null;
        $den = $doanhThu['prev_to'] ?? null;
        if (! $tu || ! $den) {
            return null;
        }

        return date('d/m', strtotime($tu)).' → '.date('d/m/Y', strtotime($den));
    }

    /**
     * Thanh mục tiêu: chỉ khi kỳ đang xem nằm trọn trong MỘT tháng và bắt đầu từ
     * ngày 1 ("Tháng này", "Tháng trước", hoặc tự chọn 01 → cuối tháng). Mục tiêu
     * là của cả tháng; so nó với một tuần hay một quý là con số vô nghĩa.
     *
     * @return array<string, mixed>|null
     */
    protected function mucTieu(array $f, float $thuan): ?array
    {
        $values = $this->api->settingValues();
        if (! array_key_exists(self::GOAL_KEY, $values)) {
            return null;
        }

        // Xem trọn một tháng thì thanh nói về tháng ấy; kỳ khác thì nói về tháng
        // này — doanh thu thuần đã gọi riêng từ mùng 1 tới hôm nay.
        $thang = $this->tronThang($f) ? $f['from'] : now()->format('Y-m-d');

        $dich = (float) $values[self::GOAL_KEY];
        $out = [
            'thang' => date('m/Y', strtotime($thang)),
            'muc_tieu' => $dich,
            'dat' => $thuan,
            'pct' => $dich > 0 ? $thuan / $dich * 100 : null,
            'can_dat_pct' => null,
            'con_ngay' => null,
            'moi_ngay' => null,
        ];

        // Tháng đang chạy: vạch "đến hôm nay lẽ ra phải đạt" và số cần bán thêm
        // mỗi ngày còn lại — tháng đã qua thì hai con số ấy không còn nghĩa.
        if ($dich > 0 && substr($thang, 0, 7) === now()->format('Y-m')) {
            $soNgay = now()->daysInMonth;
            $homNay = now()->day;
            $out['can_dat_pct'] = $homNay / $soNgay * 100;
            $out['con_ngay'] = $soNgay - $homNay + 1;
            $out['moi_ngay'] = max(0, $dich - $thuan) / $out['con_ngay'];
        }

        return $out;
    }

    /**
     * Các ca đang mở trong phạm vi chi nhánh đang xem — "Tất cả" thì mỗi chi
     * nhánh có ca mở là một dòng, như danh sách ca của v2.
     *
     * Danh sách ca không kèm tổng thu/chi (chỉ chi tiết từng ca mới cộng sổ quỹ),
     * nên phải gọi thêm một lượt mỗi ca — các lượt ấy chạy song song, và số lượt
     * bị chặn bởi luật một chi nhánh chỉ một ca mở.
     *
     * @param  array<int, array<string, mixed>>  $dsCa
     * @param  array<int, array<string, mixed>>  $dsChiNhanh
     * @return array<int, array<string, mixed>>
     */
    protected function caDangMo(array $dsCa, array $dsChiNhanh): array
    {
        $ten = [];
        foreach ($dsChiNhanh as $cn) {
            $ten[(int) ($cn['id'] ?? 0)] = (string) ($cn['name'] ?? '');
        }

        $goi = [];
        foreach ($dsCa as $i => $ca) {
            $goi[$i] = ['/admin/ca-lam-viec/'.(int) ($ca['id'] ?? 0)];
        }
        $chiTiet = $this->api->getMany($goi);

        $out = [];
        foreach ($dsCa as $i => $ca) {
            $ca = ($this->data($chiTiet[$i] ?? null, 'shift detail')['ca'] ?? null) ?: $ca;
            $dauCa = (float) ($ca['opening_cash'] ?? 0);
            $thu = (float) ($ca['tong_thu'] ?? 0);
            $chi = (float) ($ca['tong_chi'] ?? 0);

            // Ca của API không có "mã ca" riêng như bản v2 — id CHÍNH LÀ thứ hai
            // bên đối chiếu khi tra lại một lượt trực, nên in thẳng nó.
            $out[] = [
                'ma' => '#'.($ca['id'] ?? '—'),
                'chi_nhanh' => (string) (($ca['shop_name'] ?? '') ?: ($ten[(int) ($ca['shop_id'] ?? 0)] ?? '')),
                'nguoi_mo' => (string) ($ca['opened_by_name'] ?? ''),
                'gio_mo' => $ca['opened_at'] ?? null,
                'dau_ca' => $dauCa,
                'thu' => $thu,
                'chi' => $chi,
                // Tiền SỔ nói lẽ ra đang có trong két: đầu ca cộng thu trừ chi.
                'tien_mat' => $dauCa + $thu - $chi,
                'so_don' => (int) ($ca['so_don_tien_mat'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Thẻ "Cần chú ý" — chỉ những việc có số liệu thật để nói.
     *
     * Tồn kho đếm trên toàn kho (API stats không cắt theo chi nhánh); hoá đơn
     * điện tử lỗi là số hoá đơn đang ở trạng thái `failed`, chờ phát hành lại.
     * Thiếu quyền xem kho / hoá đơn thì API trả 403 và dòng đó tự vắng.
     *
     * @param  array<int, array<string, mixed>>  $caMo
     *                                                  `chu` là HTML đã thoát sẵn (view in bằng {!! !!}) — phần chữ lấy từ dữ liệu
     *                                                  (tên chi nhánh) phải đi qua e() ở đây.
     * @return array<int, array{chu: string, link: ?string}>
     */
    protected function canhBao(array $caMo, array $tonKho = [], Response|\Throwable|null $hoaDonLoi = null): array
    {
        $out = [];
        foreach ($caMo as $ca) {
            $gio = $ca['gio_mo'] ? (time() - strtotime($ca['gio_mo'])) / 3600 : 0;
            if ($gio < self::CA_QUA_LAU_GIO) {
                continue;
            }
            $bao = $gio >= 48 ? floor($gio / 24).' ngày' : floor($gio).' giờ';
            $out[] = [
                'chu' => 'Ca '.e($ca['ma']).($ca['chi_nhanh'] !== '' ? ' ở '.e($ca['chi_nhanh']) : '').' đã mở '.$bao.' chưa chốt',
                'link' => route('admin.shift-report.index'),
            ];
        }

        if (($het = (int) ($tonKho['out_of_stock'] ?? 0)) > 0) {
            $out[] = ['chu' => '<b>'.$het.' mặt hàng</b> đã hết hàng', 'link' => route('admin.ton-kho-chi-nhanh.index', ['stock' => 'out'])];
        }
        if (($sapHet = (int) ($tonKho['low_stock'] ?? 0)) > 0) {
            $out[] = ['chu' => '<b>'.$sapHet.' mặt hàng</b> sắp hết', 'link' => route('admin.ton-kho-chi-nhanh.index', ['stock' => 'low'])];
        }
        if ($hoaDonLoi instanceof Response && $hoaDonLoi->successful() && ($loi = (int) ($hoaDonLoi->json('meta.total') ?? 0)) > 0) {
            $out[] = ['chu' => '<b>'.$loi.' hoá đơn điện tử</b> phát hành lỗi', 'link' => route('admin.hoa-don-dien-tu.index', ['status' => 'failed'])];
        }

        return $out;
    }

    /**
     * Số liệu biểu đồ doanh thu: gộp, thuần, giá vốn — kèm khoảng ngày của
     * từng cột để bấm vào cột là mở đúng danh sách đơn của cột ấy.
     *
     * Chi phí ở đây là GIÁ VỐN của hàng đã bán (`cost` trong báo cáo), không
     * phải tiền mua hàng nhập kho — chỉ giá vốn mới so cùng trục với doanh thu
     * của chính những đơn đó.
     *
     * @return array<string, mixed>
     */
    protected function duLieuBieuDo(array $doanhThu, array $f, array $truoc = []): array
    {
        $out = ['labels' => [], 'gross' => [], 'net' => [], 'cost' => [], 'profit' => [], 'orders' => [], 'prev' => [], 'ranges' => []];
        $thuan = fn (array $b) => (float) ($b['subtotal'] ?? 0) + (float) ($b['shipping'] ?? 0) - (float) ($b['discount'] ?? 0);

        foreach ($doanhThu['buckets'] ?? [] as $b) {
            $nhan = (string) ($b['label'] ?? '');
            $g = (float) ($b['subtotal'] ?? 0) + (float) ($b['shipping'] ?? 0);
            $out['labels'][] = $this->nhanMoc($nhan);
            $out['gross'][] = $g;
            $out['net'][] = $thuan($b);
            $out['cost'][] = (float) ($b['cost'] ?? 0);
            $out['profit'][] = (float) ($b['profit'] ?? 0);
            $out['orders'][] = (int) ($b['orders'] ?? 0);
            $out['ranges'][] = $this->khoangMoc($nhan, $f['from'], $f['to']);
        }

        // Kỳ trước cùng độ dài nên cùng số mốc (tuần / tháng có thể lệch một mốc
        // ở hai đầu) — ghép theo THỨ TỰ mốc chứ không theo nhãn ngày.
        $mocTruoc = array_values($truoc['buckets'] ?? []);
        foreach (array_keys($out['labels']) as $i) {
            $out['prev'][] = isset($mocTruoc[$i]) ? $thuan($mocTruoc[$i]) : null;
        }

        return $out;
    }

    /** Kỳ đang xem bắt đầu mùng 1 và nằm trọn trong một tháng ("Tháng này", "Tháng trước", 01 → cuối tháng). */
    protected function tronThang(array $f): bool
    {
        return substr($f['from'], 8, 2) === '01' && substr($f['from'], 0, 7) === substr($f['to'], 0, 7);
    }

    /** Doanh thu thuần của một báo cáo doanh thu (gộp − giảm giá), cùng công thức ô KPI. */
    protected function thuan(array $doanhThu): float
    {
        $t = $doanhThu['totals'] ?? [];

        return (float) ($t['subtotal'] ?? 0) + (float) ($t['shipping'] ?? 0) - (float) ($t['discount'] ?? 0);
    }

    /**
     * Kỳ liền trước CÙNG ĐỘ DÀI, kết thúc ngay trước kỳ đang xem — đúng cách
     * API tính `prev` (ReportPeriod.Prev) để nét đứt và ô KPI so cùng một kỳ.
     *
     * @return array{0: string, 1: string}
     */
    protected function khoangTruoc(string $from, string $to): array
    {
        $soNgay = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;

        return [date('Y-m-d', strtotime($from.' -'.$soNgay.' days')), date('Y-m-d', strtotime($from.' -1 day'))];
    }

    /**
     * Số đơn theo thứ trong tuần (1 = Thứ Hai … 7 = Chủ nhật).
     *
     * @return array<int, array{nhan: string, don: int}>
     */
    protected function theoThu(array $donHang): array
    {
        $don = [];
        foreach ($donHang['by_weekday'] ?? [] as $r) {
            $don[(int) ($r['key'] ?? 0)] = (int) ($r['orders'] ?? 0);
        }

        $out = [];
        foreach ([1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'] as $k => $nhan) {
            $out[] = ['nhan' => $nhan, 'don' => $don[$k] ?? 0];
        }

        return $out;
    }

    /**
     * Khuyến mại được dùng trong kỳ: gộp mọi loại chương trình của báo cáo
     * khuyến mại, nhiều tiền giảm trước, lấy N dòng. Tổng giảm cộng mọi loại;
     * số lượt cộng thẳng nên đơn áp hai chương trình tính hai lượt.
     *
     * @param  array<string, mixed>  $nen
     * @return array{ds: array<int, array<string, mixed>>, tong: float, luot: int, tab: string}
     */
    protected function khuyenMai(array $nen, int $n): array
    {
        $ds = [];
        foreach (array_keys(PromotionReportController::TAB) as $tab) {
            foreach ($this->data($nen['km_'.$tab] ?? null, 'promotion report '.$tab)['rows'] ?? [] as $r) {
                if ((int) ($r['order_count'] ?? 0) <= 0) {
                    continue;
                }
                $ds[] = ['ten' => (string) ($r['name'] ?? ''), 'giam' => (float) ($r['discount'] ?? 0), 'luot' => (int) $r['order_count'], 'tab' => $tab];
            }
        }
        usort($ds, fn ($a, $b) => [$b['giam'], $b['luot']] <=> [$a['giam'], $a['luot']]);

        return [
            'ds' => array_slice($ds, 0, $n),
            'tong' => array_sum(array_column($ds, 'giam')),
            'luot' => array_sum(array_column($ds, 'luot')),
            'tab' => $ds[0]['tab'] ?? array_key_first(PromotionReportController::TAB),
        ];
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
     * Khoảng ngày (d-m-Y) của một mốc, cắt trong kỳ đang xem — tuần đầu kỳ bắt
     * đầu giữa tuần thì danh sách đơn cũng chỉ từ ngày đầu kỳ, khớp con số của cột.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function khoangMoc(string $label, string $from, string $to): ?array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $label) === 1) {
            [$a, $b] = [$label, $label];
        } elseif (preg_match('/^(\d{4})-W(\d{1,2})$/', $label, $m) === 1) {
            $a = (new \DateTimeImmutable)->setISODate((int) $m[1], (int) $m[2])->format('Y-m-d');
            $b = date('Y-m-d', strtotime($a.' +6 days'));
        } elseif (preg_match('/^\d{4}-\d{2}$/', $label) === 1) {
            $a = $label.'-01';
            $b = date('Y-m-t', strtotime($a));
        } else {
            return null;
        }

        $a = max($a, $from);
        $b = min($b, $to);

        return [date('d-m-Y', strtotime($a)), date('d-m-Y', strtotime($b))];
    }

    /**
     * Số đơn theo giờ trong ngày. Trục luôn phủ 7h–22h để các kỳ nhìn so được
     * với nhau; đơn rơi ngoài khung ấy thì nới trục ra cho đủ, không giấu đi.
     *
     * @return array{gio: array<int, array{h: int, don: int}>, dinh: ?int}
     */
    protected function gioCaoDiem(array $donHang): array
    {
        $don = [];
        foreach ($donHang['by_hour'] ?? [] as $r) {
            $don[(int) ($r['key'] ?? 0)] = (int) ($r['orders'] ?? 0);
        }

        $coDon = array_keys(array_filter($don));
        $dau = min([7, ...$coDon]);
        $cuoi = max([22, ...$coDon]);

        $gio = [];
        for ($h = $dau; $h <= $cuoi; $h++) {
            $gio[] = ['h' => $h, 'don' => $don[$h] ?? 0];
        }

        $dinh = $coDon === [] ? null : array_search(max($don), $don, true);

        return ['gio' => $gio, 'dinh' => $dinh === false ? null : $dinh];
    }

    /**
     * Top mặt hàng bán chạy — xếp theo SỐ LƯỢNG, không theo tiền.
     *
     * Thẻ này in cột "Số lượng" nên phải xếp theo đúng cột đang in; xếp theo
     * doanh thu mà in số lượng thì bảng trông như sắp xếp sai.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function banChay(array $bc, array $f): array
    {
        $ds = array_values(array_filter(
            $bc['items'] ?? [],
            fn ($r) => (int) ($r['units'] ?? 0) > 0
        ));
        $theoTien = $ds;
        usort($theoTien, fn ($a, $b) => ($b['revenue'] ?? 0) <=> ($a['revenue'] ?? 0));

        return ['sl' => array_slice($ds, 0, $f['top_products']), 'dt' => array_slice($theoTien, 0, $f['top_products'])];
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

    /** @return array<string, mixed> */
    protected function queryMua(array $f, int $trang): array
    {
        return ['from_date' => $f['from'], 'to_date' => $f['to'], 'shop_id' => $f['branch'], 'page' => $trang, 'page_size' => 100];
    }

    /**
     * Tiền mua hàng và số lượng nhập kho trong kỳ.
     *
     * Cộng từ danh sách phiếu vì `/phieu-mua-hang/stats` không nhận khoảng ngày.
     * Trang 1 đi cùng đợt gọi đầu; biết tổng số trang rồi mới gọi song song phần còn lại.
     *
     * @return array{tien: float, soLuong: int, catBot: bool}
     */
    protected function muaHang(array $f, Response|\Throwable|null $trang1, array $moc = []): array
    {
        $out = ['tien' => 0.0, 'soLuong' => 0, 'catBot' => false, 'soPhieu' => 0, 'daTra' => 0.0, 'matHang' => 0,
            'theoMoc' => ['tien' => array_fill(0, count($moc), 0.0), 'soLuong' => array_fill(0, count($moc), 0)]];
        // Mốc của biểu đồ (d-m-Y) quy về Y-m-d để so chuỗi.
        $ymd = fn (string $d) => substr($d, 6, 4).'-'.substr($d, 3, 2).'-'.substr($d, 0, 2);
        $khoang = array_map(fn ($r) => $r ? [$ymd($r[0]), $ymd($r[1])] : null, $moc);
        $bienThe = [];
        if (! $trang1 instanceof Response || ! $trang1->successful()) {
            $this->data($trang1, 'purchase orders');

            return $out;
        }

        $soTrang = (int) ($trang1->json('meta.total_pages') ?? 1);
        $out['catBot'] = $soTrang > self::MAX_PURCHASE_PAGES;

        $goi = [];
        for ($t = 2; $t <= min($soTrang, self::MAX_PURCHASE_PAGES); $t++) {
            $goi[$t] = ['/admin/phieu-mua-hang', $this->queryMua($f, $t)];
        }

        foreach ([$trang1, ...array_values($this->api->getMany($goi))] as $res) {
            foreach ($res instanceof Response && $res->successful() ? ($res->json('data') ?? []) : [] as $phieu) {
                if (in_array($phieu['status'] ?? '', self::PURCHASE_DEAD, true)) {
                    continue;
                }
                $tien = (float) ($phieu['total_amount'] ?? 0);
                $sl = 0;
                foreach ($phieu['items'] ?? [] as $dong) {
                    $sl += (int) ($dong['base_quantity'] ?? $dong['quantity'] ?? 0);
                    $bienThe[(int) ($dong['product_variant_id'] ?? 0)] = true;
                }
                $out['tien'] += $tien;
                $out['soLuong'] += $sl;
                $out['soPhieu']++;
                $out['daTra'] += (float) ($phieu['paid_amount'] ?? 0);

                $ngay = substr((string) (($phieu['document_date'] ?? null) ?: ($phieu['created_at'] ?? '')), 0, 10);
                foreach ($khoang as $i => $k) {
                    if ($k && $ngay >= $k[0] && $ngay <= $k[1]) {
                        $out['theoMoc']['tien'][$i] += $tien;
                        $out['theoMoc']['soLuong'][$i] += $sl;
                        break;
                    }
                }
            }
        }
        unset($bienThe[0]);
        $out['matHang'] = count($bienThe);

        return $out;
    }

    /**
     * `data` của một lượt gọi, hỏng thì mảng rỗng — mỗi khối tự chịu lỗi riêng.
     *
     * @return array<string, mixed>
     */
    protected function data(Response|\Throwable|null $res, string $what): array
    {
        if ($res instanceof Response && $res->successful()) {
            return $res->json('data') ?? [];
        }

        Log::warning('Dashboard: '.$what.' failed', $res instanceof Response
            ? ['status' => $res->status()]
            : ['msg' => $res instanceof \Throwable ? $res->getMessage() : 'no response']);

        return [];
    }
}
