<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * CRM → Chương trình khuyến mãi → tab "Voucher/Coupon" — khuôn crm/voucher-coupon
 * của bản v2.
 *
 * Một chương trình phát ra nhiều mã (tiền tố + 5 ký tự ngẫu nhiên + hậu tố).
 * "Lưu" = Chưa phát hành, còn sửa / xoá, chưa có mã. "Phát hành" = sinh đủ mã,
 * từ đó chỉ xem và bật / tắt từng mã. Mỗi mã là một mã giảm giá thường nên quầy
 * và website dùng ngay ở ô "Mã giảm giá".
 */
class VoucherCouponController extends Controller
{
    use Concerns\BaoThieuQuyen;

    use \App\Http\Controllers\Concerns\DialogReply;

    /** Ô chọn cột của v2 — cột => [nhãn, bật sẵn]. */
    public const COT = [
        'show_code' => ['Mã chương trình', true],
        'show_program_name' => ['Tên chương trình', true],
        'show_program_description' => ['Mô tả chương trình', false],
        'show_min_order_value' => ['Áp dụng cho hóa đơn trên', true],
        'show_branch' => ['Chi nhánh', false],
        'show_quantity' => ['Số lượng', true],
        'show_promotion_type' => ['Hình thức', true],
        'show_value' => ['Giá trị', true],
        'show_status' => ['Trạng thái', false],
        'show_start_date' => ['Từ ngày', true],
        'show_end_date' => ['Đến ngày', true],
        'show_creator' => ['Người tạo', true],
    ];

    public const TRANG_THAI = [2 => 'Phát hành', 1 => 'Chưa phát hành'];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $list = [];
        $meta = ['page' => $filters['page'], 'page_size' => 10, 'total' => 0, 'total_pages' => 1];
        $error = null;
        $thieuQuyen = false;

        try {
            $res = $this->api->voucherCoupon($this->query($filters));
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                ['thieuQuyen' => $thieuQuyen, 'error' => $error] =
                    $this->doLoiDanhSach($res, 'voucher / coupon');
            }
        } catch (\Throwable $e) {
            Log::error('Load voucher programs failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách voucher/coupon. Kiểm tra kết nối API.';
        }

        $view = view('v2::crm.voucher-coupons.index', [
            'list' => $list,
            'filters' => $filters,
            'meta' => $meta,
            'columns' => $this->cot($request),
            'chiNhanh' => $this->mang(fn () => $this->api->chiNhanh(true)),
            'danhMuc' => $this->mang(fn () => $this->api->categories(true)),
            'thieuQuyen' => $thieuQuyen,
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    public function store(Request $request)
    {
        return $this->luu($request, fn ($d) => $this->api->taoVoucherCoupon($d));
    }

    public function update(Request $request, int $id)
    {
        return $this->luu($request, fn ($d) => $this->api->suaVoucherCoupon($id, $d));
    }

    public function destroy(Request $request, int $id)
    {
        return $this->traLoi($request, fn () => $this->api->xoaVoucherCoupon($id), 'Xoá chương trình thành công.');
    }

    /** "Danh sách mã chi tiết" dưới bảng — JSON kèm phân trang. */
    public function codes(Request $request, int $id)
    {
        return $this->json(fn () => $this->api->maVoucherCoupon($id, array_filter([
            'keyword' => trim((string) $request->query('keyword', '')),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => 10,
        ], fn ($v) => $v !== '')));
    }

    public function codeStatus(Request $request, int $id)
    {
        $on = $request->boolean('is_active');

        return $this->json(fn () => $this->api->batTatMaVoucher($id, $on), $on ? 'Đã bật mã.' : 'Đã tắt mã.');
    }

    public function codeHistory(int $id)
    {
        return $this->json(fn () => $this->api->lichSuMaVoucher($id));
    }

    /**
     * Xuất danh sách chương trình đang lọc ra .xlsx.
     *
     * .xlsx THẬT, không phải CSV đội tên nút "Xuất Excel": nút ghi "Xuất Excel"
     * mà tệp tải về là voucher-coupon-*.csv kiểu text/csv — nhãn nói một đằng,
     * tệp một nẻo. Đi cùng đường taiXlsx() của Nhân sự, Nhà cung cấp, Thu chi,
     * Công nợ, Phiếu mua hàng.
     *
     * Hai cột tiền và số lượng ghi kiểu SỐ chứ không phải chữ đã chấm nghìn, nhờ
     * vậy người nhận cộng được ngay — đúng cái CSV không làm được.
     */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $rows = [];
        try {
            $q = array_merge($this->query($filters), ['page' => 1, 'page_size' => 100]);
            do {
                $res = $this->api->voucherCoupon($q);
                if (! $res->successful()) {
                    break;
                }
                $rows = array_merge($rows, $res->json('data') ?? []);
                $q['page']++;
            } while ($q['page'] <= (int) ($res->json('meta.total_pages') ?? 1) && $q['page'] <= 50);
        } catch (\Throwable $e) {
            Log::error('Export voucher programs failed', ['msg' => $e->getMessage()]);
        }
        $ma = collect($this->mang(fn () => $this->api->chiNhanh(true)))->mapWithKeys(fn ($c) => [$c['id'] => ($c['code'] ?? '') ?: ($c['name'] ?? '')])->all();

        $hang = [['STT', 'Mã chương trình', 'Tên chương trình', 'Mô tả chương trình', 'Áp dụng cho hóa đơn trên', 'Chi nhánh',
            'Số lượng', 'Hình thức', 'Giá trị (%, $)', 'Trạng thái', 'Từ ngày', 'Đến ngày', 'Người tạo']];

        foreach ($rows as $i => $p) {
            $hang[] = [
                $i + 1,
                (string) ($p['code'] ?? ''),
                (string) ($p['name'] ?? ''),
                (string) ($p['description'] ?? ''),
                (float) ($p['min_order_amount'] ?? 0),
                ! empty($p['all_shops']) ? collect($ma)->implode(', ') : collect($p['shop_ids'] ?? [])->map(fn ($id) => $ma[$id] ?? '#'.$id)->implode(', '),
                (int) ($p['quantity'] ?? 0),
                self::hinhThuc($p),
                // Giá trị để kiểu CHỮ: cột này trộn "10%" với "11.000 đ" tuỳ dòng,
                // ghi kiểu số thì mất đơn vị và 10% nằm cạnh 11000 đọc thành vô lý.
                self::giaTri($p),
                self::TRANG_THAI[$p['status']] ?? '',
                self::ngay($p, 'start_date'),
                self::ngay($p, 'end_date'),
                (string) ($p['created_by_name'] ?? ''),
            ];
        }

        return $this->taiXlsx($hang, 'voucher-coupon-'.date('Ymd-His'), 'Voucher coupon');
    }

    public static function hinhThuc(array $p): string
    {
        return ($p['discount_type'] ?? '') === 'percentage' ? 'Coupon' : 'Voucher';
    }

    public static function giaTri(array $p): string
    {
        return ($p['discount_type'] ?? '') === 'percentage'
            ? rtrim(rtrim(number_format((float) $p['discount_value'], 2, '.', ''), '0'), '.').'%'
            : number_format((float) $p['discount_value']).' đ';
    }

    public static function ngay(array $p, string $k): string
    {
        if (! empty($p['no_time_limit'])) {
            return '∞';
        }

        return ! empty($p[$k]) ? Carbon::parse($p[$k])->format('d-m-Y') : '-';
    }

    protected function luu(Request $request, callable $gui)
    {
        $tien = fn ($v) => $v === null || $v === '' ? null : (float) preg_replace('/[^\d.]/', '', (string) $v);
        $request->merge([
            'discount_value' => $tien($request->input('discount_value')),
            'min_order_amount' => $tien($request->input('min_order_amount')),
            'max_discount_amount' => $tien($request->input('max_discount_amount')),
        ]);
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', 'in:percentage,fixed'],
            'discount_value' => ['required', 'numeric', 'gt:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'min_order_amount' => ['required', 'numeric', 'min:0'],
            'all_shops' => ['nullable', 'boolean'],
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => ['integer'],
            'all_categories' => ['nullable', 'boolean'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'no_time_limit' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date_format:d-m-Y'],
            'end_date' => ['nullable', 'date_format:d-m-Y'],
            'prefix' => ['nullable', 'string', 'max:20'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'quantity' => ['required', 'integer', 'min:1', 'max:200'],
            'usage_limit' => ['required', 'integer', 'min:1'],
            'release' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Trường tên chương trình là bắt buộc.',
            'discount_value.required' => 'Trường giá trị khuyến mại là bắt buộc.',
            'min_order_amount.required' => 'Trường áp dụng cho hóa đơn trên là bắt buộc.',
            'quantity.required' => 'Trường số lượng là bắt buộc.',
            'quantity.max' => 'Số lượng tối đa 200.',
            'usage_limit.required' => 'Trường số lần áp dụng là bắt buộc.',
        ]);

        $khongHan = $request->boolean('no_time_limit');
        $ngay = fn ($s) => $s ? Carbon::createFromFormat('d-m-Y', $s)->format('Y-m-d') : '';
        $data = [
            'name' => $v['name'],
            'description' => (string) ($v['description'] ?? ''),
            'discount_type' => $v['discount_type'],
            'discount_value' => (float) $v['discount_value'],
            'max_discount_amount' => $v['max_discount_amount'] ?? null,
            'min_order_amount' => (float) $v['min_order_amount'],
            'all_shops' => $request->boolean('all_shops'),
            'shop_ids' => array_values(array_unique(array_filter(array_map('intval', $v['shop_ids'] ?? [])))),
            'all_categories' => $request->boolean('all_categories'),
            'category_ids' => array_values(array_unique(array_filter(array_map('intval', $v['category_ids'] ?? [])))),
            'no_time_limit' => $khongHan,
            'start_date' => $khongHan ? '' : $ngay($v['start_date'] ?? ''),
            'end_date' => $khongHan ? '' : $ngay($v['end_date'] ?? ''),
            'prefix' => (string) ($v['prefix'] ?? ''),
            'suffix' => (string) ($v['suffix'] ?? ''),
            'quantity' => (int) $v['quantity'],
            'usage_limit' => (int) $v['usage_limit'],
            'release' => $request->boolean('release'),
        ];

        return $this->traLoi($request, fn () => $gui($data), $data['release'] ? 'Phát hành thành công.' : 'Lưu chương trình thành công.');
    }

    protected function traLoi(Request $request, callable $goi, string $xong)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Voucher program API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $xong, fn () => redirect()->route('admin.crm.promotions.voucher'))
            : $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác không thành công.'), null, $res->status());
    }

    protected function json(callable $goi, ?string $xong = null)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Voucher program API call failed', ['msg' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }
        if (! $res->successful()) {
            return response()->json(['success' => false, 'message' => $res->json('message') ?: 'Thao tác không thành công.'],
                $res->status() === 500 ? 502 : $res->status());
        }

        return response()->json(['success' => true, 'message' => $xong, 'data' => $res->json('data'), 'meta' => $res->json('meta')]);
    }

    protected function filters(Request $request): array
    {
        return [
            // Như v2: chưa lọc thì chọn sẵn chi nhánh đang làm việc.
            'shop_ids' => $request->has('shop_ids')
                ? collect((array) $request->query('shop_ids', []))->map(fn ($v) => (int) $v)->filter()->unique()->values()->all()
                : array_values(array_filter([ApiClient::chiNhanhDangLam()])),
            // Rỗng = "Tất cả".
            'statuses' => array_values(array_intersect(array_map('intval', (array) $request->query('statuses', [])), [1, 2])),
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }

    protected function query(array $f): array
    {
        return array_filter([
            'shop_ids' => implode(',', $f['shop_ids']),
            'statuses' => implode(',', $f['statuses']),
            'page' => $f['page'],
            'page_size' => 10,
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /** Cột đang bật: ?cols=a,b (người dùng đã chọn) hoặc mặc định của v2. */
    protected function cot(Request $request): array
    {
        $chon = $request->has('cols') ? array_filter(explode(',', (string) $request->query('cols'))) : null;
        $out = [];
        foreach (self::COT as $cot => [, $macDinh]) {
            $out[$cot] = $chon === null ? $macDinh : in_array($cot, $chon, true);
        }

        return $out;
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
}
