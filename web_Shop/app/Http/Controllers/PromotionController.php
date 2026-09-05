<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Quản lý chương trình khuyến mãi — dựng theo đúng khuôn các trang danh sách khác:
 * [ header + thẻ số liệu ] + [ thanh lọc realtime ] + [ bảng ] + [ chân trang ] + modal CRUD.
 *
 * Ba điểm riêng của module này:
 *
 * 1. TRẠNG THÁI KHÔNG CHỈ LÀ BẬT/TẮT. Một chương trình bật sẵn nhưng chưa tới ngày
 *    thì chưa giảm cho ai. Bảng hiện đúng bốn trạng thái (đang chạy / chờ tới ngày
 *    / đã kết thúc / tạm dừng) thay vì nói "đang bật" rồi để người bán tự hỏi vì
 *    sao giá ngoài cửa hàng không đổi. Trạng thái do API tính, trang này chỉ hiển thị.
 *
 * 2. PHẠM VI LÀ THỨ QUAN TRỌNG NHẤT. Một chương trình không có phạm vi thì không
 *    giảm cho ai, nên modal bắt buộc chọn ít nhất một sản phẩm / danh mục / thương
 *    hiệu, và bảng hiện luôn số sản phẩm đang bị phủ.
 *
 * 3. KHÔNG GHI ĐÈ GIÁ SẢN PHẨM. Mức giảm được API tính lúc khách đọc hàng, nên hết
 *    đợt là giá tự về như cũ — xoá chương trình cũng không để lại dấu vết nào trong
 *    bảng products.
 */
class PromotionController extends Controller
{
    public const TITLE = 'Khuyến mãi';

    /** Trạng thái thực tế, khớp `promotionStatus` bên API. */
    public const STATUSES = [
        'running' => 'Đang chạy',
        'scheduled' => 'Chờ tới ngày',
        'ended' => 'Đã kết thúc',
        'paused' => 'Tạm dừng',
    ];

    public const DISCOUNT_TYPES = [
        'percentage' => 'Giảm theo %',
        'fixed' => 'Giảm số tiền',
    ];

    public const SORTS = [
        'newest' => 'Mới nhất',
        'oldest' => 'Cũ nhất',
        'start_desc' => 'Ngày bắt đầu gần nhất',
        'start_asc' => 'Ngày bắt đầu xa nhất',
        'name_asc' => 'Tên A → Z',
    ];

    public const PAGE_SIZES = [10, 20, 50, 100];

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách chương trình khuyến mãi. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $promotions = [];
        $meta = ['page' => 1, 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $stats = ['total' => 0] + array_fill_keys(array_keys(self::STATUSES), 0);

        try {
            $res = $this->api->promotions($this->query($filters));
            if ($res->successful()) {
                $promotions = $res->json('data') ?? [];
                $meta = $res->json('meta') ?: $meta;
            } else {
                Log::warning('Load promotions failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách khuyến mãi.';
            }

            $statRes = $this->api->promotionStats();
            if ($statRes->successful()) {
                $stats = array_merge($stats, $statRes->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::error('Load promotions failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách khuyến mãi. Kiểm tra kết nối API.';
        }

        $view = view('promotions.index', [
            'promotions' => $promotions,
            'filters' => $filters,
            'stats' => $stats,
            'meta' => $meta,
            // Hai nguồn để chọn phạm vi. Nạp sẵn cùng trang thay vì gọi thêm khi mở
            // modal: cửa hàng cỡ này chỉ vài trăm dòng, mà đợi API lúc đang điền
            // biểu mẫu thì khó chịu hơn nhiều so với việc trang nặng thêm chút.
            'categories' => $this->fetchCategories(),
            'products' => $this->fetchProducts(),
            'chiNhanh' => $this->chiNhanhChoForm(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    public function store(Request $request)
    {
        return $this->send(
            fn () => $this->api->createPromotion($this->validated($request)),
            'Đã tạo chương trình khuyến mãi.',
            $request
        );
    }

    public function update(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->updatePromotion($id, $this->validated($request)),
            'Đã cập nhật chương trình khuyến mãi.',
            $request
        );
    }

    /** Bật/tắt — dừng một đợt giữa chừng mà không phải sửa ngày kết thúc. */
    public function toggleStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ], [
            'is_active.required' => 'Thiếu trạng thái cần đặt.',
        ]);

        $isActive = (bool) $validated['is_active'];

        return $this->send(
            fn () => $this->api->updatePromotionStatus($id, $isActive),
            $isActive ? 'Đã bật chương trình.' : 'Đã tạm dừng chương trình.',
            $request
        );
    }

    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->deletePromotion($id),
            'Đã xoá chương trình khuyến mãi.',
            $request
        );
    }

    /** Xoá nhiều chương trình đã chọn. */
    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->all();

        if (empty($ids)) {
            return $this->backToList($request)->with('error', 'Chưa chọn chương trình nào để xoá.');
        }

        $ok = 0;
        $fail = 0;
        foreach ($ids as $id) {
            try {
                $this->api->deletePromotion($id)->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::error('Bulk delete promotion failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $fail++;
            }
        }

        $redirect = $this->backToList($request);

        if ($fail === 0) {
            return $redirect->with('success', "Đã xoá {$ok} chương trình.");
        }

        return $redirect->with(
            $ok > 0 ? 'success' : 'error',
            $ok > 0 ? "Đã xoá {$ok} chương trình, {$fail} chương trình lỗi." : "Không xoá được chương trình nào ({$fail} lỗi)."
        );
    }

    /** Xuất danh sách theo đúng bộ lọc đang xem. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $rows = [];

        try {
            // page_size lớn để lấy trọn bộ lọc hiện tại, không phải chỉ trang đang xem:
            // xuất file mà chỉ được 20 dòng đầu là cái bẫy im lặng.
            $res = $this->api->promotions($this->query($filters) + ['page' => 1, 'page_size' => 100]);
            if ($res->successful()) {
                $rows = $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Export promotions failed', ['msg' => $e->getMessage()]);
        }

        $fileName = 'khuyen-mai-'.date('Ymd-His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, ['ID', 'Tên chương trình', 'Kiểu giảm', 'Mức giảm', 'Trần giảm', 'Bắt đầu', 'Kết thúc', 'Trạng thái', 'Số sản phẩm']);

            foreach ($rows as $p) {
                fputcsv($file, [
                    $p['id'] ?? '',
                    $p['name'] ?? '',
                    self::DISCOUNT_TYPES[$p['discount_type'] ?? ''] ?? '',
                    $this->discountLabel($p),
                    $p['max_discount_amount'] ?? '',
                    $this->formatDate($p['start_at'] ?? null),
                    $this->formatDate($p['end_at'] ?? null),
                    self::STATUSES[$p['status'] ?? ''] ?? '',
                    $p['product_count'] ?? 0,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'newest');
        $pageSize = (int) $request->query('page_size', 20);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'from_date' => $this->cleanDate($request->query('from_date')),
            'to_date' => $this->cleanDate($request->query('to_date')),
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'newest',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 20,
        ];
    }

    /** Bộ lọc → query gửi sang API (bỏ những khoá rỗng cho URL gọn). */
    protected function query(array $filters): array
    {
        return array_filter([
            'keyword' => $filters['keyword'],
            'status' => $filters['status'] !== 'all' ? $filters['status'] : '',
            'from_date' => $filters['from_date'],
            'to_date' => $filters['to_date'],
            'sort' => $filters['sort'],
            'page' => $filters['page'],
            'page_size' => $filters['page_size'],
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /** Dữ liệu chương trình từ modal thêm/sửa. */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', 'in:'.implode(',', array_keys(self::DISCOUNT_TYPES))],
            'discount_value' => ['required', 'numeric', 'gt:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'gt:0'],
            'start_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'end_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:start_at'],
            'is_active' => ['nullable'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => ['integer'],
        ], [
            'name.required' => 'Vui lòng nhập tên chương trình.',
            'name.max' => 'Tên chương trình tối đa 150 ký tự.',
            'discount_type.required' => 'Vui lòng chọn kiểu giảm giá.',
            'discount_value.required' => 'Vui lòng nhập mức giảm.',
            'discount_value.gt' => 'Mức giảm phải lớn hơn 0.',
            'max_discount_amount.gt' => 'Trần giảm phải lớn hơn 0.',
            'start_at.required' => 'Vui lòng chọn thời gian bắt đầu.',
            'start_at.date_format' => 'Thời gian bắt đầu không hợp lệ.',
            'end_at.required' => 'Vui lòng chọn thời gian kết thúc.',
            'end_at.date_format' => 'Thời gian kết thúc không hợp lệ.',
            'end_at.after' => 'Thời gian kết thúc phải sau thời gian bắt đầu.',
        ]);

        $percent = $validated['discount_type'] === 'percentage';

        // Chặn tại đây chứ không chỉ dựa vào API: người dùng gõ 150% thì phải thấy
        // lỗi ngay dưới ô đang gõ, không phải một dòng đỏ chung chung ở đầu trang.
        if ($percent && (float) $validated['discount_value'] > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Phần trăm giảm phải trong khoảng 1–100.',
            ]);
        }

        $ids = fn (string $key) => collect($validated[$key] ?? [])
            ->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();

        return [
            'name' => $validated['name'],
            'description' => (string) ($validated['description'] ?? ''),
            'discount_type' => $validated['discount_type'],
            'discount_value' => (float) $validated['discount_value'],
            // Trần giảm chỉ có nghĩa khi giảm theo %. Gửi kèm ở kiểu "giảm số tiền"
            // là để lại một con số nằm im trong database rồi có người tưởng nó đang
            // có tác dụng.
            'max_discount_amount' => $percent && $validated['max_discount_amount'] !== null
                ? (float) $validated['max_discount_amount']
                : null,
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'is_active' => $request->boolean('is_active'),
            'product_ids' => $ids('product_ids'),
            'category_ids' => $ids('category_ids'),
            // Bỏ trống = chạy ở MỌI chi nhánh. Đây là quy ước của cả hệ thống
            // (xem ô "Chi nhánh" của mặt hàng), nên đừng tự điền chi nhánh đang
            // làm việc vào cho "tiện": làm vậy là mọi chương trình lập từ nay bỗng
            // chỉ chạy ở một kho, mà không ai bấm gì để chọn thế cả.
            'shop_ids' => $ids('shop_ids'),
        ];
    }

    /**
     * Danh sách chi nhánh cho ô "Chi nhánh" của biểu mẫu.
     *
     * Chỉ lấy chi nhánh ĐANG MỞ: gán một chương trình vào kho đã đóng thì nó
     * không chạy ở đâu cả, mà màn hình lại nói là có chọn.
     *
     * Hỏng thì trả mảng rỗng — ô chọn biến mất và chương trình chạy toàn cửa
     * hàng như trước, chứ không chặn người dùng lưu.
     */
    protected function chiNhanhChoForm(): array
    {
        try {
            $res = $this->api->chiNhanh(true);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Mức giảm dạng chữ, dùng cho file xuất ra. */
    protected function discountLabel(array $p): string
    {
        $value = (float) ($p['discount_value'] ?? 0);

        return ($p['discount_type'] ?? '') === 'percentage'
            ? rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',').'%'
            : number_format($value, 0, ',', '.').'đ';
    }

    protected function fetchCategories(): array
    {
        try {
            $res = $this->api->categories(true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Fetch categories for promotion failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Danh sách sản phẩm rút gọn cho ô chọn phạm vi.
     *
     * Lấy cả sản phẩm đang ẩn: chương trình có thể được dựng trước cho một đợt sắp
     * mở bán, lúc đó hàng còn đang ẩn.
     */
    protected function fetchProducts(): array
    {
        try {
            $res = $this->api->products(['all' => 'true', 'page' => 1, 'page_size' => 100]);
            if (! $res->successful()) {
                return [];
            }

            return collect($res->json('data') ?? [])
                ->map(fn ($p) => [
                    'id' => (int) ($p['id'] ?? 0),
                    'name' => (string) ($p['name'] ?? ''),
                    'sku' => (string) ($p['sku'] ?? ''),
                    'thumbnail' => (string) ($p['thumbnail'] ?? ''),
                    'base_price' => (float) ($p['base_price'] ?? 0),
                ])
                ->filter(fn ($p) => $p['id'] > 0)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::error('Fetch products for promotion failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    protected function cleanDate($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function formatDate(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Promotion API call failed', ['msg' => $e->getMessage()]);

            return $this->backToList($request)->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return $this->backToList($request)->with('success', $success);
        }

        return $this->backToList($request)
            ->withInput()
            ->with('error', $res->json('message') ?: 'Thao tác không thành công.');
    }

    protected function backToList(Request $request)
    {
        $return = $request->input('return');
        if (is_string($return) && str_starts_with($return, '/')) {
            return redirect($return);
        }

        return redirect()->route('admin.promotions.index', $request->query());
    }
}
