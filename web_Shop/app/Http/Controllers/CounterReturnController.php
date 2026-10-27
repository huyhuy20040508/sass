<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiJsonReply;
use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Trả hàng ở quầy — một bước: chọn đơn → chọn món → hoàn tiền (hoặc đổi hàng) → in phiếu.
 *
 * Gọn hơn Quản trị → Trả hàng: không có chuỗi duyệt, phí ship hoàn, số tài khoản,
 * sửa / huỷ phiếu. Luật đơn nào trả được (HĐĐT, còn nợ, quá hạn, chi nhánh khác,
 * chưa mở ca) và tiền hoàn đều do API (/admin/quay/tra-hang) quyết.
 */
class CounterReturnController extends Controller
{
    use ApiJsonReply;

    public const TITLE = 'Trả hàng';

    public const KY = CounterCashbookController::KY;

    public const LOAI = ['' => 'Tất cả', 'refund' => 'Hoàn tiền', 'swap' => 'Đổi hàng'];

    /** Lý do ở quầy — mã trùng ReturnController::REASONS; `kho` = mặc định có nhập lại kho không. */
    public const LY_DO = [
        'defective' => ['ten' => 'Lỗi / hỏng', 'kho' => false],
        'wrong_size' => ['ten' => 'Sai mẫu / size', 'kho' => true],
        'changed_mind' => ['ten' => 'Đổi ý', 'kho' => true],
        'not_as_described' => ['ten' => 'Không như mô tả', 'kho' => true],
        'wrong_item' => ['ten' => 'Giao sai hàng', 'kho' => true],
        'other' => ['ten' => 'Khác', 'kho' => true],
    ];

    public const HINH_THUC = ['cash' => 'Tiền mặt', 'bank_transfer' => 'Chuyển khoản'];

    public const PAGE_SIZE = 50;

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $ca = $this->doc(fn () => $this->api->caHienTai(), null);
        $f = $this->filters($request, $ca);

        $query = ['page' => $f['page'], 'page_size' => self::PAGE_SIZE];
        if ($f['q'] !== '') {
            $query['keyword'] = $f['q'];
        } elseif ($f['ky'] === 'ca') {
            $query['from_time'] = Carbon::parse($ca['opened_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } else {
            $query += ['from_date' => $f['from_date'], 'to_date' => $f['to_date']];
        }

        $rows = [];
        $meta = [];
        $error = null;
        try {
            $res = $this->api->traHangQuay($query + array_filter(['kind' => $f['kind']]));
            if ($res->successful()) {
                $rows = $res->json('data') ?? [];
                $meta = $res->json('meta') ?? [];
            } else {
                $error = $res->json('message') ?: 'Không tải được phiếu trả hàng.';
            }
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai phieu tra that bai', ['msg' => $e->getMessage()]);
            $error = 'Không tải được phiếu trả hàng. Kiểm tra kết nối API.';
        }

        $coTheTra = (bool) ($meta['co_the_tra'] ?? false);
        $view = view('v2::pos.returns', compact('rows', 'meta', 'f', 'ca', 'coTheTra'));

        return $error ? $view->with('error', $error) : $view;
    }

    /** JSON bước chọn đơn. */
    public function donHang(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        $days = (int) $request->query('days', 7);

        return $this->jsonTuApi(fn () => $this->api->donTraQuay(array_filter([
            'q' => $q,
            'days' => in_array($days, [0, 7, 30], true) ? max(1, $days) : 7,
        ], fn ($v) => $v !== '')), 'Không tải được danh sách đơn.');
    }

    /** JSON món còn trả được của một đơn. */
    public function monTra(int $id)
    {
        return $this->jsonTuApi(fn () => $this->api->traDuocTaiQuay($id), 'Không tải được món của đơn.');
    }

    public function store(Request $request)
    {
        $du = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'in:'.implode(',', array_keys(self::LY_DO))],
            'reason_note' => ['nullable', 'string', 'max:500'],
            'refund_method' => ['required', 'in:'.implode(',', array_keys(self::HINH_THUC))],
            'deduction' => ['nullable', 'numeric', 'min:0'],
            'restock' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'reason.required' => 'Chọn lý do trả hàng.',
            'items.required' => 'Chọn ít nhất một món khách trả.',
        ]);

        return $this->jsonTuApi(fn () => $this->api->traTaiQuay([
            'order_id' => (int) $du['order_id'],
            'reason' => $du['reason'],
            'reason_note' => trim((string) ($du['reason_note'] ?? '')),
            'refund_method' => $du['refund_method'],
            'deduction' => (float) ($du['deduction'] ?? 0),
            'restock' => (bool) $du['restock'],
            'items' => $this->dongTra($du['items']),
        ]), 'Không trả được hàng.');
    }

    public function doiHang(Request $request)
    {
        $du = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'in:'.implode(',', array_keys(self::LY_DO))],
            'reason_note' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required', 'in:'.implode(',', array_keys(self::HINH_THUC))],
            'restock' => ['required', 'boolean'],
            'tra' => ['required', 'array', 'min:1', 'max:50'],
            'tra.*.order_item_id' => ['required', 'integer', 'min:1'],
            'tra.*.quantity' => ['required', 'integer', 'min:1'],
            'moi' => ['required', 'array', 'min:1', 'max:50'],
            'moi.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'moi.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'reason.required' => 'Chọn lý do trả hàng.',
            'moi.required' => 'Chọn món khách đổi sang — hoặc chuyển về Hoàn tiền.',
        ]);

        return $this->jsonTuApi(fn () => $this->api->doiHangQuay([
            'order_id' => (int) $du['order_id'],
            'reason' => $du['reason'],
            'reason_note' => trim((string) ($du['reason_note'] ?? '')),
            'payment_method' => $du['payment_method'],
            'restock' => (bool) $du['restock'],
            'tra' => $this->dongTra($du['tra']),
            'moi' => array_map(fn ($m) => ['product_variant_id' => (int) $m['product_variant_id'], 'quantity' => (int) $m['quantity']], $du['moi']),
        ]), 'Không đổi được hàng.');
    }

    private function dongTra(array $ds): array
    {
        return array_values(array_map(fn ($d) => ['order_item_id' => (int) $d['order_item_id'], 'quantity' => (int) $d['quantity']], $ds));
    }

    protected function filters(Request $request, ?array $ca): array
    {
        $ngay = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        $ky = (string) $request->query('ky', $ca ? 'ca' : 'hom-nay');
        $ky = isset(self::KY[$ky]) ? $ky : 'hom-nay';
        if ($ky === 'ca' && ! $ca) {
            $ky = 'hom-nay';
        }
        $homNay = now();

        [$tu, $den] = match ($ky) {
            'thang-nay' => [$homNay->copy()->startOfMonth()->toDateString(), $homNay->toDateString()],
            'tuy-chon' => [$ngay($request->query('from_date')) ?: $homNay->copy()->subDays(6)->toDateString(), $ngay($request->query('to_date')) ?: $homNay->toDateString()],
            default => [$homNay->toDateString(), $homNay->toDateString()],
        };
        if ($tu > $den) {
            [$tu, $den] = [$den, $tu];
        }
        $kind = (string) $request->query('kind', '');

        return [
            'ky' => $ky,
            'from_date' => $tu,
            'to_date' => $den,
            'kind' => isset(self::LOAI[$kind]) ? $kind : '',
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'page' => max(1, (int) $request->query('page', 1)),
            'phieu' => max(0, (int) $request->query('phieu', 0)),
            'don' => max(0, (int) $request->query('don', 0)),
        ];
    }

    protected function doc(callable $goi, mixed $macDinh): mixed
    {
        try {
            $res = $goi();

            return $res->successful() ? $res->json('data') : $macDinh;
        } catch (\Throwable $e) {
            Log::info('Thu ngan: doc du lieu tra hang that bai', ['msg' => $e->getMessage()]);

            return $macDinh;
        }
    }
}
