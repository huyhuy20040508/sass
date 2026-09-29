<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * CRM → Chương trình khuyến mãi → tab "Khuyến mại đồng giá" — khuôn
 * crm/fixed-price của bản v2.
 *
 * "Giỏ có từ Q cái của nhóm hàng / sản phẩm X thì mọi cái của X bán đúng giá P,
 * kèm hàng tặng." Chương trình Lưu tạm / Duyệt; đã duyệt thì phải Huỷ duyệt mới
 * sửa, và không xoá được. Quầy chỉ thấy chương trình đã duyệt và đang bật.
 */
class FixedPriceController extends Controller
{
    use \App\Http\Controllers\Concerns\DialogReply;

    public const LOAI = [1 => 'Nhóm hàng', 2 => 'Danh sách hàng'];

    public const PAGE_SIZES = [10, 20, 30, 40, 50];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $list = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->dongGia(array_filter([
                'from_date' => $filters['from_date'],
                'to_date' => $filters['to_date'],
                'shop_ids' => implode(',', $filters['shop_ids']),
                'codes' => implode(',', $filters['codes']),
                'page' => $filters['page'],
                'page_size' => $filters['page_size'],
            ], fn ($v) => $v !== '' && $v !== null));
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách đồng giá.';
            }
        } catch (\Throwable $e) {
            Log::error('Load fixed prices failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách đồng giá. Kiểm tra kết nối API.';
        }

        $view = view('v2::crm.fixed-prices.index', [
            'list' => $list,
            'filters' => $filters,
            'meta' => $meta,
            'chiNhanh' => $this->chiNhanh(),
            'danhMuc' => $this->danhMuc(),
            'sanPham' => $this->sanPham(),
            'maCT' => $this->maCT(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    public function store(Request $request)
    {
        return $this->goi($request, fn ($d) => $this->api->taoDongGia($d), 'Tạo chương trình khuyến mại thành công.');
    }

    public function update(Request $request, int $id)
    {
        return $this->goi($request, fn ($d) => $this->api->suaDongGia($id, $d), 'Đã lưu chương trình khuyến mại đồng giá.');
    }

    public function status(Request $request, int $id)
    {
        $on = $request->boolean('status');

        return $this->traLoi($request, fn () => $this->api->batTatDongGia($id, $on), $on ? 'Đã bật chương trình.' : 'Đã tắt chương trình.');
    }

    public function huyDuyet(Request $request, int $id)
    {
        return $this->traLoi($request, fn () => $this->api->huyDuyetDongGia($id), 'Đã huỷ duyệt — chương trình về Lưu tạm.');
    }

    public function destroy(Request $request, int $id)
    {
        return $this->traLoi($request, fn () => $this->api->xoaDongGia($id), 'Đã xoá chương trình đồng giá.');
    }

    /** Dữ liệu hộp Thêm/Sửa → thân API. Luật chi tiết (trùng, ngày, chi nhánh) API giữ. */
    protected function goi(Request $request, callable $gui, string $xong)
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:1,2'],
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
            'days_of_week.required' => 'Chọn ít nhất một ngày trong tuần.',
            'details.required' => 'Chưa có dữ liệu chi tiết đồng giá.',
        ]);

        // Bảng dòng đi dạng JSON trong một ô: FormData không chở được mảng lồng
        // (dòng → hàng tặng) mà không dựng tay hàng chục khoá details[0][gifts][1]….
        $dong = json_decode($v['details'], true);
        if (! is_array($dong) || $dong === []) {
            return $this->traLoiHopThoai($request, false, 'Chưa có dữ liệu chi tiết đồng giá.');
        }

        $ngay = fn ($s) => $s ? Carbon::createFromFormat('d-m-Y', $s)->format('Y-m-d') : '';
        $data = [
            'name' => $v['name'],
            'description' => (string) ($v['description'] ?? ''),
            'type' => (int) $v['type'],
            'approved' => $request->boolean('approved'),
            'status' => $request->boolean('status'),
            'no_time_limit' => $request->boolean('no_time_limit'),
            'start_date' => $request->boolean('no_time_limit') ? '' : $ngay($v['start_date'] ?? ''),
            'end_date' => $request->boolean('no_time_limit') ? '' : $ngay($v['end_date'] ?? ''),
            'days_of_week' => array_values(array_unique(array_map('intval', $v['days_of_week']))),
            'all_shops' => $request->boolean('all_shops'),
            'shop_ids' => array_values(array_unique(array_filter(array_map('intval', $v['shop_ids'] ?? [])))),
            'details' => array_map(fn ($d) => [
                'object_id' => (int) ($d['object_id'] ?? 0),
                'quantity' => (int) ($d['quantity'] ?? 0),
                'price' => (float) ($d['price'] ?? 0),
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
            Log::error('Fixed price API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $xong, fn () => redirect()->route('admin.crm.promotions.dongGia'))
            : $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác không thành công.'), null, $res->status());
    }

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
            // Như v2: mở lần đầu thì lọc từ đầu tháng tới hôm nay, chi nhánh đang
            // làm việc, và ô "Mã khuyến mãi" chọn sẵn mọi mã (= không lọc mã).
            'from_date' => $ngay('from_date', now()->startOfMonth()->format('Y-m-d')),
            'to_date' => $ngay('to_date', now()->format('Y-m-d')),
            'shop_ids' => $request->has('shop_ids')
                ? collect((array) $request->query('shop_ids', []))->map(fn ($v) => (int) $v)->filter()->unique()->values()->all()
                : array_values(array_filter([ApiClient::chiNhanhDangLam()])),
            'codes' => collect((array) $request->query('codes', []))->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all(),
            'codes_all' => ! $request->has('codes'),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 10,
        ];
    }

    protected function maCT(): array
    {
        try {
            $res = $this->api->maDongGia();

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function chiNhanh(): array
    {
        try {
            $res = $this->api->chiNhanh(true);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function danhMuc(): array
    {
        try {
            $res = $this->api->categories(true);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Sản phẩm kèm biến thể: sản phẩm cho dòng "Danh sách hàng", biến thể cho ô
     * hàng tặng (tặng thì phải đúng một biến thể để trừ kho).
     */
    protected function sanPham(): array
    {
        try {
            $res = $this->api->products(['all' => 'true', 'page' => 1, 'page_size' => 100, 'status' => 'active']);
            if (! $res->successful()) {
                return [];
            }

            return collect($res->json('data') ?? [])->map(fn ($p) => [
                'id' => (int) ($p['id'] ?? 0),
                'name' => (string) ($p['name'] ?? ''),
                'variants' => collect($p['variants'] ?? [])->map(fn ($v) => [
                    'id' => (int) ($v['id'] ?? 0),
                    'name' => trim((string) ($p['name'] ?? '').(($v['name'] ?? '') !== '' ? ' ('.$v['name'].')' : '')),
                    // Cột "ĐVT" của bảng hàng tặng (hộp Xem).
                    'unit' => (string) ($p['unit']['name'] ?? ''),
                ])->filter(fn ($v) => $v['id'] > 0)->values()->all(),
            ])->filter(fn ($p) => $p['id'] > 0)->values()->all();
        } catch (\Throwable $e) {
            Log::error('Fetch products for fixed price failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }
}
