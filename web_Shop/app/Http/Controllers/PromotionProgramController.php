<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * CRM → Chương trình khuyến mãi — tab "Chương trình khuyến mại", khuôn
 * crm/promotion-program của bản v2.
 *
 * Chương trình có nhiều BẬC (theo tổng tiền hàng / số lượng nhóm hàng / số lượng
 * sản phẩm), mỗi bậc giảm % hoặc tiền và có hàng tặng. Lưu tạm / Duyệt; đã
 * duyệt thì phải Huỷ duyệt mới sửa. Thu ngân chọn chương trình ở quầy.
 */
class PromotionProgramController extends Controller
{
    use Concerns\BaoThieuQuyen;

    use \App\Http\Controllers\Concerns\DialogReply;

    /** Loại khuyến mại — 1 "Số lượng khách hàng" của v2 bỏ vì quầy shop không nhập số khách. */
    public const LOAI = [0 => 'Phiếu bán hàng', 2 => 'Nhóm hàng', 3 => 'Danh sách hàng'];

    /** Bảy ô "Thứ trong tuần", số theo ISO như API: 1 = Thứ Hai. */
    public const THU = [1 => 'Hai', 2 => 'Ba', 3 => 'Tư', 4 => 'Năm', 5 => 'Sáu', 6 => 'Bảy', 7 => 'CN'];

    /** Ô chọn cột của v2 (manager_promotion_program). */
    public const COT = [
        'program_code' => 'Mã chương trình',
        'program_name' => 'Tên chương trình',
        'approve' => 'Duyệt',
        'status' => 'Trạng thái',
        'from_date' => 'Từ ngày',
        'to_date' => 'Đến ngày',
        'branch' => 'Chi nhánh',
    ];

    public const PAGE_SIZES = [10, 20, 30, 40, 50];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $list = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;
        $thieuQuyen = false;

        // Bỏ chọn hết trạng thái = không trạng thái nào → bảng rỗng (không hỏi API).
        if ($filters['statuses'] !== []) {
            try {
                $res = $this->api->chuongTrinhKM($this->query($filters));
                if ($res->successful()) {
                    $list = $res->json('data') ?? [];
                    $meta = array_merge($meta, $res->json('meta') ?? []);
                } else {
                    // 403 KHÁC "chưa có dữ liệu": bày bảng rỗng kèm câu "chưa có
                    // chương trình nào" là nói sai chuyện đang xảy ra.
                    ['thieuQuyen' => $thieuQuyen, 'error' => $error] =
                        $this->doLoiDanhSach($res, 'chương trình khuyến mại');
                }
            } catch (\Throwable $e) {
                Log::error('Load promotion programs failed', ['msg' => $e->getMessage()]);
                $error = 'Không tải được danh sách chương trình khuyến mại. Kiểm tra kết nối API.';
            }
        }

        $view = view('v2::crm.promotions.index', [
            'list' => $list,
            'filters' => $filters,
            'meta' => $meta,
            'chiNhanh' => $this->mang(fn () => $this->api->chiNhanh(true)),
            'danhMuc' => $this->mang(fn () => $this->api->categories(true)),
            'sanPham' => $this->sanPham(),
            'maCT' => $this->mang(fn () => $this->api->maChuongTrinhKM()),
            // View dùng cờ này để đổi câu của dòng rỗng và giấu nút Tạo / Xuất.
            'thieuQuyen' => $thieuQuyen,
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    public function store(Request $request)
    {
        return $this->luu($request, fn ($d) => $this->api->taoChuongTrinhKM($d), 'Tạo chương trình khuyến mại thành công.');
    }

    public function update(Request $request, int $id)
    {
        return $this->luu($request, fn ($d) => $this->api->suaChuongTrinhKM($id, $d), 'Đã lưu chương trình khuyến mại.');
    }

    public function duplicate(Request $request, int $id)
    {
        return $this->traLoi($request, fn () => $this->api->nhanBanChuongTrinhKM($id), 'Đã nhân bản chương trình.');
    }

    public function status(Request $request, int $id)
    {
        $on = $request->boolean('status');

        return $this->traLoi($request, fn () => $this->api->batTatChuongTrinhKM($id, $on), $on ? 'Đã bật chương trình.' : 'Đã tắt chương trình.');
    }

    public function huyDuyet(Request $request, int $id)
    {
        return $this->traLoi($request, fn () => $this->api->huyDuyetChuongTrinhKM($id), 'Đã huỷ duyệt — chương trình về Lưu tạm.');
    }

    public function destroy(Request $request, int $id)
    {
        return $this->traLoi($request, fn () => $this->api->xoaChuongTrinhKM($id), 'Đã xoá chương trình khuyến mại.');
    }

    /** Xuất Excel — cột của PromotionExport bên v2, đúng bộ lọc đang xem. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $rows = [];
        if ($filters['statuses'] !== []) {
            try {
                $q = array_merge($this->query($filters), ['page' => 1, 'page_size' => 100]);
                do {
                    $res = $this->api->chuongTrinhKM($q);
                    if (! $res->successful()) {
                        break;
                    }
                    $rows = array_merge($rows, $res->json('data') ?? []);
                    $q['page']++;
                } while ($q['page'] <= (int) ($res->json('meta.total_pages') ?? 1) && $q['page'] <= 50);
            } catch (\Throwable $e) {
                Log::error('Export promotion programs failed', ['msg' => $e->getMessage()]);
            }
        }
        $ma = collect($this->mang(fn () => $this->api->chiNhanh(true)))->mapWithKeys(fn ($c) => [$c['id'] => ($c['code'] ?? '') ?: ($c['name'] ?? '')])->all();
        $ngay = fn ($v) => $v ? Carbon::parse($v)->format('d-m-Y') : '-';

        return response()->streamDownload(function () use ($rows, $ma, $ngay) {
            $f = fopen('php://output', 'w');
            fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($f, ['STT', 'Mã chương trình', 'Tên chương trình', 'Mô tả chương trình', 'Duyệt', 'Trạng thái',
                'Tự động áp dụng', 'Từ ngày', 'Đến ngày', 'Chi nhánh']);
            foreach ($rows as $i => $p) {
                fputcsv($f, [
                    $i + 1, $p['code'], $p['name'], $p['description'] ?? '',
                    ! empty($p['approved']) ? 'Đã được duyệt' : 'Lưu tạm',
                    ! empty($p['status']) ? 'Hoạt động' : 'Không hoạt động',
                    'Không',
                    ! empty($p['no_time_limit']) ? '-' : $ngay($p['start_date'] ?? ''),
                    ! empty($p['no_time_limit']) ? '-' : $ngay($p['end_date'] ?? ''),
                    ! empty($p['all_shops']) ? 'Tất cả' : collect($p['shop_ids'] ?? [])->map(fn ($id) => $ma[$id] ?? '#'.$id)->implode(', '),
                ]);
            }
            fclose($f);
        }, 'promotion-program-'.date('YmdHis').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function luu(Request $request, callable $gui, string $xong)
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:0,2,3'],
            'approved' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'no_time_limit' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date_format:d-m-Y'],
            'end_date' => ['nullable', 'date_format:d-m-Y'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'all_shops' => ['nullable', 'boolean'],
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => ['integer'],
            'details' => ['required', 'string'],
        ], [
            'name.required' => 'Nhập tên chương trình.',
            'name.max' => 'Tên chương trình tối đa 100 ký tự.',
            'days_of_week.required' => 'Chọn ít nhất một ngày trong tuần.',
            'details.required' => 'Chi tiết khuyến mãi không được để trống.',
        ]);

        // Bảng bậc đi dạng JSON trong một ô: FormData không chở được mảng lồng
        // (bậc → hàng tặng) mà không dựng tay hàng chục khoá details[0][gifts][1]….
        $dong = json_decode($v['details'], true);
        if (! is_array($dong) || $dong === []) {
            return $this->traLoiHopThoai($request, false, 'Chi tiết khuyến mãi không được để trống.');
        }
        $khongHan = $request->boolean('no_time_limit');
        $ngay = fn ($s) => $s ? Carbon::createFromFormat('d-m-Y', $s)->format('Y-m-d') : '';

        $data = [
            'name' => $v['name'],
            'description' => (string) ($v['description'] ?? ''),
            'type' => (int) $v['type'],
            'approved' => $request->boolean('approved'),
            'status' => $request->boolean('status'),
            'no_time_limit' => $khongHan,
            'start_date' => $khongHan ? '' : $ngay($v['start_date'] ?? ''),
            'end_date' => $khongHan ? '' : $ngay($v['end_date'] ?? ''),
            'days_of_week' => array_values(array_unique(array_map('intval', $v['days_of_week']))),
            'all_shops' => $request->boolean('all_shops'),
            'shop_ids' => array_values(array_unique(array_filter(array_map('intval', $v['shop_ids'] ?? [])))),
            'details' => array_map(fn ($d) => [
                'total_apply' => (float) ($d['total_apply'] ?? 0),
                'object_id' => (int) ($d['object_id'] ?? 0),
                'quantity' => (int) ($d['quantity'] ?? 0),
                'formality' => (int) ($d['formality'] ?? 0),
                'value' => (float) ($d['value'] ?? 0),
                'max_value' => (float) ($d['max_value'] ?? 0),
                'gifts' => array_map(fn ($g) => [
                    'product_variant_id' => (int) ($g['product_variant_id'] ?? 0),
                    'quantity' => (int) ($g['quantity'] ?? 0),
                ], array_values((array) ($d['gifts'] ?? []))),
            ], array_values($dong)),
        ];

        return $this->traLoi($request, fn () => $gui($data), $xong);
    }

    protected function traLoi(Request $request, callable $goi, string $xong)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Promotion program API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $xong, fn () => redirect()->route('admin.crm.promotions.index'))
            : $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác không thành công.'), null, $res->status());
    }

    /**
     * Bộ lọc v2: Thời gian (NGÀY TẠO, mặc định đầu tháng → hôm nay), Chi nhánh,
     * Mã khuyến mãi, Trạng thái (mặc định chọn cả hai).
     */
    protected function filters(Request $request): array
    {
        $pageSize = (int) $request->query('page_size', 10);
        $ngay = function (string $k, string $macDinh) use ($request) {
            if (! $request->has($k)) {
                return $macDinh;
            }
            try {
                $v = trim((string) $request->query($k, ''));

                return $v === '' ? '' : Carbon::createFromFormat('d-m-Y', $v)->format('Y-m-d');
            } catch (\Throwable $e) {
                return '';
            }
        };

        return [
            'from_date' => $ngay('from_date', now()->startOfMonth()->format('Y-m-d')),
            'to_date' => $ngay('to_date', now()->format('Y-m-d')),
            // Như v2: chưa lọc thì chọn sẵn chi nhánh đang làm việc.
            'shop_ids' => $request->has('shop_ids')
                ? collect((array) $request->query('shop_ids', []))->map(fn ($v) => (int) $v)->filter()->unique()->values()->all()
                : array_values(array_filter([ApiClient::chiNhanhDangLam()])),
            'codes' => collect((array) $request->query('codes', []))->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all(),
            'statuses' => $request->has('statuses')
                ? array_values(array_intersect(array_map('strval', (array) $request->query('statuses')), ['1', '0']))
                : ['1', '0'],
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 10,
        ];
    }

    protected function query(array $f): array
    {
        return array_filter([
            'from_date' => $f['from_date'], 'to_date' => $f['to_date'],
            'shop_ids' => implode(',', $f['shop_ids']),
            'codes' => implode(',', $f['codes']),
            'statuses' => count($f['statuses']) === 1 ? $f['statuses'][0] : '',
            'page' => $f['page'], 'page_size' => $f['page_size'],
        ], fn ($v) => $v !== '' && $v !== null);
    }

    protected function mang(callable $goi): array
    {
        try {
            $res = $goi();

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Sản phẩm kèm biến thể: sản phẩm cho bậc "Danh sách hàng", biến thể cho hàng tặng. */
    protected function sanPham(): array
    {
        return collect($this->mang(fn () => $this->api->products(['all' => 'true', 'page' => 1, 'page_size' => 100, 'status' => 'active'])))
            ->map(fn ($p) => [
                'id' => (int) ($p['id'] ?? 0),
                'name' => (string) ($p['name'] ?? ''),
                'variants' => collect($p['variants'] ?? [])->map(fn ($v) => [
                    'id' => (int) ($v['id'] ?? 0),
                    'name' => trim((string) ($p['name'] ?? '').(($v['name'] ?? '') !== '' ? ' ('.$v['name'].')' : '')),
                    // Cột "Đơn vị" của bảng hàng tặng — đơn vị tính của mặt hàng.
                    'unit' => (string) ($p['unit']['name'] ?? ''),
                ])->filter(fn ($v) => $v['id'] > 0)->values()->all(),
            ])->filter(fn ($p) => $p['id'] > 0)->values()->all();
    }
}
