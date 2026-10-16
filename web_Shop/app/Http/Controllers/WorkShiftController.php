<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiJsonReply;
use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Điều phối ca & sổ quỹ — đối chiếu TIỀN TRONG KÉT với SỔ.
 *
 * CHỈ TIỀN MẶT: chuyển khoản không đi qua két, gộp vào là con số đối chiếu
 * không còn khớp tiền đếm được.
 */
class WorkShiftController extends Controller
{
    use Concerns\V2ScreenFallback;

    use ApiJsonReply;

    public const TITLE = 'Điều phối ca';

    /** Kỳ của lịch sử ca. Mặc định 7 ngày: đủ thấy tuần trực vừa qua mà vẫn một trang. */
    public const KY = [
        'hom-nay' => 'Hôm nay',
        '7-ngay' => '7 ngày',
        'thang-nay' => 'Tháng này',
        'tuy-chon' => 'Tuỳ chọn',
    ];

    public const PAGE_SIZE = 10;

    public const DIRECTIONS = [
        'in' => 'Thu',
        'out' => 'Chi',
    ];

    /** Dịch cột reference_type của dòng sổ quỹ ra tiếng người. */
    public const SOURCES = [
        'order' => 'Bán hàng',
        'order_return' => 'Trả hàng',
        'income_expense' => 'Phiếu thu chi',
        'manual' => 'Ghi tay',
    ];

    public function __construct(protected ApiClient $api) {}

    /**
     * Một trang dựng đủ: ca đang mở, lịch sử ca theo bộ lọc, tờ ca đang chọn kèm sổ
     * quỹ + doanh thu, tiền ngoài ca hôm nay. Chọn ca khác là tải lại trang với
     * ?ca= — giữ nguyên bộ lọc lịch sử trên URL.
     */
    public function index(Request $request)
    {
        if ($ve = $this->veQuayNeuThieuView('v2::pos.work-shift')) {
            return $ve;
        }

        $filters = $this->filters($request);
        $error = null;

        $hienTai = $this->doc(fn () => $this->api->caHienTai(), null);
        $nguoiTruc = $this->doc(fn () => $this->api->caNguoiTruc(), []) ?? [];
        // Đọc thẳng (không qua doc()) để lấy thêm meta: người xem gắn được không,
        // và mốc gắn được (giờ đóng ca trước).
        $ngoaiCa = [];
        $ganNgoai = ['co' => false, 'tu' => null];
        try {
            $r = $this->api->caNgoaiCa();
            if ($r->successful()) {
                $ngoaiCa = $r->json('data') ?? [];
                $ganNgoai = ['co' => (bool) $r->json('meta.co_the_gan'), 'tu' => $r->json('meta.gan_tu')];
            }
        } catch (\Throwable $e) {
            Log::info('Thu ngan: tai tien ngoai ca that bai', ['msg' => $e->getMessage()]);
        }
        $caTruoc = ($this->doc(fn () => $this->api->caLamViec(['status' => 'da_dong', 'page' => 1, 'page_size' => 1]), []) ?? [])[0] ?? null;

        $list = [];
        $meta = ['page' => $filters['page'], 'page_size' => self::PAGE_SIZE, 'total' => 0, 'total_pages' => 1];
        try {
            $res = $this->api->caLamViec(array_filter([
                'status' => 'da_dong',
                'from_date' => $filters['from_date'],
                'to_date' => $filters['to_date'],
                'opened_by' => $filters['opened_by'] ?: null,
                'lech' => $filters['lech'] ? 1 : null,
                'page' => $filters['page'],
                'page_size' => self::PAGE_SIZE,
            ], fn ($v) => $v !== null && $v !== ''));
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách ca.';
            }
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai danh sach ca that bai', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách ca. Kiểm tra kết nối API.';
        }

        // Không chọn gì thì mở ca đang trực; chưa mở ca thì khung lớn là lời mời Mở ca.
        $chonId = $filters['ca'] ?: (int) ($hienTai['id'] ?? 0);
        $chon = $chonId ? $this->doc(fn () => $this->api->caChiTiet($chonId), null) : null;

        $truc = self::nguoiTrucKet($this->api);
        $quaLau = self::caMoQuaLau($hienTai, $this->api);
        $view = view('v2::pos.work-shift', compact('list', 'filters', 'meta', 'hienTai', 'nguoiTruc', 'ngoaiCa', 'ganNgoai', 'caTruoc', 'chon', 'truc', 'quaLau'));

        return $error ? $view->with('error', $error) : $view;
    }

    /** Đường cũ của trang chi tiết — nay tờ ca nằm ngay trong trang danh sách. */
    public function show(int $id)
    {
        return redirect()->route('thu-ngan.ca-lam-viec.index', ['ca' => $id]);
    }

    /**
     * Phiếu kết ca in nhiệt. ?tam=1 khi ca còn mở: in số tới giờ, ghi rõ "phiếu tạm"
     * để không ai cầm nó đi làm biên bản bàn giao.
     */
    public function phieu(Request $request, int $id)
    {
        try {
            $res = $this->api->caChiTiet($id);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai phieu ca that bai', ['id' => $id, 'msg' => $e->getMessage()]);
            abort(404);
        }
        abort_unless($res->successful(), 404);

        return view('v2::pos.shift-slip', $this->thongTinPhieu($request) + [
            'ca' => $res->json('data.ca') ?? [],
            'soQuy' => $res->json('data.so_quy') ?? [],
            'doanhThu' => $res->json('data.doanh_thu') ?? [],
            'tam' => $request->boolean('tam'),
            'tongHop' => null,
        ]);
    }

    /** Phiếu tổng hợp các ca đã đóng theo đúng bộ lọc lịch sử đang xem. */
    public function phieuTongHop(Request $request)
    {
        $filters = $this->filters($request);
        try {
            $res = $this->api->caLamViec(array_filter([
                'status' => 'da_dong',
                'from_date' => $filters['from_date'],
                'to_date' => $filters['to_date'],
                'opened_by' => $filters['opened_by'] ?: null,
                'lech' => $filters['lech'] ? 1 : null,
                'page' => 1,
                'page_size' => 100,
            ], fn ($v) => $v !== null && $v !== ''));
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai phieu tong hop ca that bai', ['msg' => $e->getMessage()]);
            abort(502);
        }
        abort_unless($res->successful(), 502);

        return view('v2::pos.shift-slip', $this->thongTinPhieu($request) + [
            'ca' => null,
            'tongHop' => ['ds' => $res->json('data') ?? [], 'tu' => $filters['from_date'], 'den' => $filters['to_date'], 'total' => (int) $res->json('meta.total')],
            'tam' => false,
        ]);
    }

    /**
     * JSON: ca đang mở của chi nhánh đang làm việc.
     *
     * Hỏng thì trả null chứ không trả lỗi: quầy vẫn phải bán được khi cụm ca trục
     * trặc — ca là thứ ghi chép, không phải thứ gác cửa.
     */
    public function hienTai()
    {
        try {
            $res = $this->api->caHienTai();
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data')]);
            }
        } catch (\Throwable $e) {
            Log::info('Thu ngan: tai ca hien tai that bai', ['msg' => $e->getMessage()]);
        }

        return response()->json(['data' => null]);
    }

    public function moCa(Request $request)
    {
        $data = $request->validate([
            'opening_cash' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'employee_id' => 'nullable|integer|min:1',
            'opened_at' => 'nullable|date_format:Y-m-d\TH:i',
            'opening_count' => 'nullable|array|max:20',
            'opening_count.*.menh_gia' => 'required|integer|min:1',
            'opening_count.*.so_to' => 'required|integer|min:0|max:100000',
        ], [
            'opening_cash.required' => 'Vui lòng đếm và nhập số tiền đang có trong két.',
            'opened_at.date_format' => 'Giờ mở không đúng dạng.',
        ]);

        try {
            $res = $this->api->moCa(array_filter([
                'opening_cash' => (float) $data['opening_cash'],
                'note' => trim((string) ($data['note'] ?? '')),
                'employee_id' => isset($data['employee_id']) ? (int) $data['employee_id'] : null,
                'opened_at' => isset($data['opened_at']) ? str_replace('T', ' ', $data['opened_at']) : null,
                'opening_count' => $this->bangDem($data['opening_count'] ?? null),
            ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            return $this->jsonTuApi(fn () => throw $e, 'Không mở được ca.');
        }

        // 422 của API kèm lỗi từng ô (người trực, giờ mở) dưới câu chung "Dữ liệu
        // không hợp lệ" — đưa câu của ô lên, hộp thoại chỉ có một dòng báo lỗi.
        $o = $res->status() === 422 && is_array($res->json('errors')) ? array_diff_key($res->json('errors'), ['ma' => 1]) : [];
        if ($o) {
            return response()->json(['message' => (string) reset($o), 'errors' => $o], 422);
        }

        return $this->jsonTuApi(fn () => $res, 'Không mở được ca.');
    }

    /**
     * Ô "Người trực két": nhân sự đang làm ở chi nhánh, chọn sẵn hồ sơ gắn với tài
     * khoản đang đăng nhập. API hỏng thì danh sách rỗng — hộp vẫn mở được ca với
     * người trực mặc định (API tự lấy hồ sơ của người bấm).
     *
     * @return array{ds: array<int, array{id:int, name:string, code:string}>, macDinh: ?int}
     */
    /**
     * Ca đang mở quá ngưỡng giờ ở Cài đặt → câu nhắc cho dải vàng (Điều phối ca, Bán
     * hàng) và Tổng quan. Chỉ nhắc, không chặn bán. Ngưỡng 0 = tắt nhắc.
     *
     * @return array{id:int, cau:string, gio:float}|null
     */
    public static function caMoQuaLau(?array $ca, ApiClient $api): ?array
    {
        if (! $ca || ! empty($ca['closed_at']) || empty($ca['opened_at'])) {
            return null;
        }
        $nguong = $api->settingInt('shift_open_alert_hours', 24);
        $mo = \Illuminate\Support\Carbon::parse($ca['opened_at']);
        $gio = $mo->diffInMinutes(now(), true) / 60;
        if ($nguong <= 0 || $gio < $nguong) {
            return null;
        }
        $tu = $mo->isToday() ? 'từ '.$mo->format('H:i')
            : ($mo->isYesterday() ? 'từ hôm qua '.$mo->format('H:i') : 'từ '.$mo->format('H:i d/m'));
        $bao = $gio >= 48 ? floor($gio / 24).' ngày' : floor($gio).' giờ';
        $id = (int) ($ca['id'] ?? 0);

        return ['id' => $id, 'gio' => $gio, 'cau' => "Ca #{$id} mở {$tu} ({$bao}) chưa đóng — đóng ca để chốt két."];
    }

    public static function nguoiTrucKet(ApiClient $api): array
    {
        $ds = [];
        try {
            $res = $api->nhanVienTrucCa();
            if ($res->successful()) {
                $ds = $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Thu ngan: tai nhan su truc ca that bai', ['msg' => $e->getMessage()]);
        }
        $toi = (int) data_get(session('api.user'), 'id', 0);
        $macDinh = collect($ds)->first(fn ($nv) => (int) ($nv['user_id'] ?? 0) === $toi);

        return ['ds' => $ds, 'macDinh' => $macDinh ? (int) $macDinh['id'] : null];
    }

    /** Gắn khoản tiền mặt "ngoài ca" vào ca đang mở — API kiểm quyền và mốc. */
    public function ganNgoaiCa(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|min:1',
        ], ['ids.required' => 'Chọn ít nhất một khoản để gắn.']);

        return $this->jsonTuApi(fn () => $this->api->ganNgoaiCa(array_map('intval', $data['ids'])), 'Không gắn được khoản ngoài ca.');
    }

    public function dongCa(Request $request)
    {
        $data = $request->validate([
            'counted_cash' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'delivered_cash' => 'nullable|numeric|min:0',
            'kept_cash' => 'nullable|numeric|min:0',
            'counted_count' => 'nullable|array|max:20',
            'counted_count.*.menh_gia' => 'required|integer|min:1',
            'counted_count.*.so_to' => 'required|integer|min:0|max:100000',
        ], [
            'counted_cash.required' => 'Vui lòng đếm và nhập số tiền thực tế trong két.',
            'delivered_cash.min' => 'Giao về chủ không được âm.',
            'kept_cash.min' => 'Giữ lại không được âm.',
        ]);

        // Két lệch mà không ghi lý do thì hôm sau không ai giải thích được khoản
        // chênh. Kiểm ở đây chứ không ở API: API còn phục vụ app khác chưa có ô ghi chú.
        if (trim((string) ($data['note'] ?? '')) === '' && ($lech = $this->lechKet((float) $data['counted_cash'])) !== null) {
            return response()->json([
                'message' => 'Két '.($lech < 0 ? 'thiếu ' : 'dư ').number_format(abs($lech), 0, ',', '.').' đ so với sổ — ghi lý do chênh lệch trước khi đóng ca.',
                'errors' => ['note' => ['Ghi lý do chênh lệch.']],
            ], 422);
        }

        return $this->jsonTuApi(fn () => $this->api->dongCa(array_filter([
            'counted_cash' => (float) $data['counted_cash'],
            'note' => trim((string) ($data['note'] ?? '')),
            'delivered_cash' => isset($data['delivered_cash']) ? (float) $data['delivered_cash'] : null,
            'kept_cash' => isset($data['kept_cash']) ? (float) $data['kept_cash'] : null,
            'counted_count' => $this->bangDem($data['counted_count'] ?? null),
        ], fn ($v) => $v !== null)), 'Không đóng được ca.');
    }

    /** Bảng đếm theo mệnh giá → đúng kiểu số cho API; rỗng = không gửi. API kiểm tổng. */
    private function bangDem(?array $ds): ?array
    {
        $ds = array_map(fn ($d) => ['menh_gia' => (int) $d['menh_gia'], 'so_to' => (int) $d['so_to']], $ds ?? []);

        return $ds ?: null;
    }

    public function ghiSoQuy(Request $request)
    {
        $data = $request->validate([
            'direction' => 'required|in:'.implode(',', array_keys(self::DIRECTIONS)),
            'amount' => 'required|numeric|gt:0',
            'reason' => 'required|string|max:255',
            'xac_nhan_am' => 'nullable|boolean',
        ], [
            'amount.gt' => 'Số tiền phải lớn hơn 0.',
            'reason.required' => 'Vui lòng ghi lý do — một khoản ra khỏi két không có lý do thì đúng bằng mất tiền.',
        ]);

        return $this->jsonTuApi(fn () => $this->api->ghiSoQuy([
            'direction' => $data['direction'],
            'amount' => (float) $data['amount'],
            'reason' => trim($data['reason']),
            'xac_nhan_am' => (bool) ($data['xac_nhan_am'] ?? false),
        ]), 'Không ghi được sổ quỹ.');
    }

    /** Chênh lệch theo ca đang mở; null khi khớp hoặc không đọc được ca (để API phán). */
    protected function lechKet(float $demDuoc): ?float
    {
        $ca = $this->doc(fn () => $this->api->caHienTai(), null);
        if (! is_array($ca)) {
            return null;
        }
        $theoSo = (float) ($ca['opening_cash'] ?? 0) + (float) ($ca['tong_thu'] ?? 0) - (float) ($ca['tong_chi'] ?? 0);
        $lech = round($demDuoc - $theoSo);

        return $lech == 0 ? null : $lech;
    }

    /** Gọi một đường ĐỌC phụ; hỏng thì trả $macDinh — thiếu một khối không đáng sập cả màn. */
    protected function doc(callable $goi, mixed $macDinh): mixed
    {
        try {
            $res = $goi();

            return $res->successful() ? $res->json('data') : $macDinh;
        } catch (\Throwable $e) {
            Log::info('Thu ngan: doc du lieu ca that bai', ['msg' => $e->getMessage()]);

            return $macDinh;
        }
    }

    protected function thongTinPhieu(Request $request): array
    {
        $cn = \App\Services\CurrentBranch::danhSach();

        return [
            'tenCuaHang' => $this->api->settingString('site_name', config('app.name')),
            'diaChi' => $this->api->settingString('store_address'),
            'khoGiay' => $request->query('paper') === '58' ? '58' : '80',
            'tenChiNhanh' => (string) (collect($cn['ds'])->firstWhere('id', $cn['dangChon'])['name'] ?? ''),
        ];
    }

    protected function filters(Request $request): array
    {
        $ngay = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        $ky = (string) $request->query('ky', '7-ngay');
        $ky = isset(self::KY[$ky]) ? $ky : '7-ngay';
        $homNay = now();

        [$tu, $den] = match ($ky) {
            'hom-nay' => [$homNay->toDateString(), $homNay->toDateString()],
            'thang-nay' => [$homNay->copy()->startOfMonth()->toDateString(), $homNay->toDateString()],
            'tuy-chon' => [$ngay($request->query('from_date')), $ngay($request->query('to_date'))],
            default => [$homNay->copy()->subDays(6)->toDateString(), $homNay->toDateString()],
        };
        if ($tu !== '' && $den !== '' && $tu > $den) {
            [$tu, $den] = [$den, $tu];
        }

        return [
            'ky' => $ky,
            'from_date' => $tu,
            'to_date' => $den,
            'opened_by' => max(0, (int) $request->query('opened_by', 0)),
            'lech' => $request->query('lech') === '1',
            'page' => max(1, (int) $request->query('page', 1)),
            'ca' => max(0, (int) $request->query('ca', 0)),
        ];
    }
}
