<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Quản lý thương hiệu — dựng theo đúng khuôn trang "Danh sách khách hàng":
 * [ header ] + [ thanh lọc realtime ] + [ bảng compact ] + [ chân trang ] + modal CRUD.
 *
 * Khác một điểm về dữ liệu: API /brands trả TOÀN BỘ thương hiệu một lần (không
 * phân trang phía server) vì số lượng thương hiệu luôn nhỏ. Nên việc tìm kiếm /
 * lọc / sắp xếp / cắt trang được làm ngay tại controller, còn view vẫn dùng
 * chung component phân trang như mọi trang khác.
 *
 * store() phục vụ hai lối vào: modal "thêm nhanh" bên trang Sản phẩm (gửi AJAX,
 * cần JSON) và modal thêm đầy đủ của chính trang này (form thường, cần redirect).
 */
class BrandController extends Controller
{
    public const STATUSES = [
        'active' => 'Đang hoạt động',
        'inactive' => 'Ngừng hoạt động',
    ];

    public const SORTS = [
        'name_asc' => 'Tên A → Z',
        'name_desc' => 'Tên Z → A',
        'newest' => 'Mới nhất',
        'oldest' => 'Cũ nhất',
        'products_desc' => 'Nhiều sản phẩm nhất',
    ];

    public const PAGE_SIZES = [10, 20, 50, 100];

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách thương hiệu. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $all = [];

        try {
            $res = $this->api->brands(all: true);
            if ($res->successful()) {
                $all = $res->json('data') ?? [];
            } else {
                Log::warning('Load brands failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách thương hiệu.';
            }
        } catch (\Throwable $e) {
            Log::error('Load brands failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách thương hiệu. Kiểm tra kết nối API.';
        }

        $stats = [
            'total' => count($all),
            'active' => 0,
            'inactive' => 0,
            'products' => 0,
        ];
        foreach ($all as $b) {
            ($b['is_active'] ?? false) ? $stats['active']++ : $stats['inactive']++;
            $stats['products'] += (int) ($b['product_count'] ?? 0);
        }

        $filtered = $this->applyFilters($all, $filters);
        $total = count($filtered);
        $totalPages = max(1, (int) ceil($total / $filters['page_size']));
        $page = min($filters['page'], $totalPages);

        $brands = array_slice($filtered, ($page - 1) * $filters['page_size'], $filters['page_size']);

        $view = view('brands.index', [
            'brands' => array_values($brands),
            'filters' => array_merge($filters, ['page' => $page]),
            'stats' => $stats,
            'meta' => [
                'page' => $page,
                'page_size' => $filters['page_size'],
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Tạo thương hiệu.
     * - Gọi AJAX (thêm nhanh từ trang Sản phẩm): trả JSON {id, name} / {message}.
     * - Gửi form thường (modal của trang này): redirect kèm thông báo.
     */
    public function store(Request $request)
    {
        $wantsJson = $request->expectsJson();

        // Thêm nhanh chỉ có ô tên — các trường còn lại nhận giá trị mặc định.
        $data = $wantsJson ? $this->validatedQuick($request) : $this->validated($request);

        try {
            $res = $this->api->createBrand($data);
        } catch (\Throwable $e) {
            Log::error('Create brand failed', ['msg' => $e->getMessage()]);

            return $wantsJson
                ? response()->json(['message' => 'Không kết nối được máy chủ API.'], 502)
                : $this->backToList($request)->withInput()->with('error', 'Không kết nối được máy chủ API.');
        }

        if (! $res->successful()) {
            $message = $res->json('message') ?: 'Tạo thương hiệu thất bại.';

            return $wantsJson
                ? response()->json(['message' => $message], $res->status() ?: 422)
                : $this->backToList($request)->withInput()->with('error', $message);
        }

        $brand = $res->json('data');

        if ($wantsJson) {
            return response()->json([
                'id' => data_get($brand, 'id'),
                'name' => data_get($brand, 'name', $data['name']),
            ]);
        }

        return $this->backToList($request)->with('success', 'Đã thêm thương hiệu "'.$data['name'].'".');
    }

    /** Cập nhật thương hiệu. */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->updateBrand($id, $data),
            'Đã cập nhật thương hiệu "'.$data['name'].'".',
            $request
        );
    }

    /** Bật/tắt hiển thị thương hiệu — giữ nguyên các trường còn lại. */
    public function toggleStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ], [
            'is_active.required' => 'Thiếu trạng thái cần đặt.',
        ]);

        $brand = $this->find($id);
        if ($brand === null) {
            return $this->backToList($request)->with('error', 'Không tải được thông tin thương hiệu. Vui lòng thử lại.');
        }

        $isActive = (bool) $validated['is_active'];

        return $this->send(
            fn () => $this->api->updateBrand($id, [
                'name' => (string) ($brand['name'] ?? ''),
                'slug' => (string) ($brand['slug'] ?? ''),
                'logo' => (string) ($brand['logo'] ?? ''),
                'description' => (string) ($brand['description'] ?? ''),
                'is_active' => $isActive,
            ]),
            $isActive ? 'Đã hiện thương hiệu trên cửa hàng.' : 'Đã ẩn thương hiệu khỏi cửa hàng.',
            $request
        );
    }

    /** Xoá thương hiệu — chặn nếu vẫn còn sản phẩm gắn vào. */
    public function destroy(Request $request, int $id)
    {
        $brand = $this->find($id);
        if ($brand === null) {
            return $this->backToList($request)->with('error', 'Không tải được thông tin thương hiệu. Vui lòng thử lại.');
        }

        $used = (int) ($brand['product_count'] ?? 0);
        if ($used > 0) {
            return $this->backToList($request)->with(
                'error',
                'Không xoá được "'.($brand['name'] ?? '#'.$id).'": còn '.$used.' sản phẩm đang gắn thương hiệu này. '
                .'Hãy chuyển các sản phẩm sang thương hiệu khác trước, hoặc tắt hiển thị thay vì xoá.'
            );
        }

        return $this->send(
            fn () => $this->api->deleteBrand($id),
            'Đã xoá thương hiệu "'.($brand['name'] ?? '#'.$id).'".',
            $request
        );
    }

    /** Xoá nhiều thương hiệu đã chọn — bỏ qua thương hiệu còn sản phẩm. */
    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->all();

        if (empty($ids)) {
            return $this->backToList($request)->with('error', 'Chưa chọn thương hiệu nào để xoá.');
        }

        // Đọc một lần cả danh sách để biết thương hiệu nào còn sản phẩm (tránh gọi
        // API chi tiết cho từng id).
        $byId = collect($this->fetchAll())->keyBy('id');

        $ok = 0;
        $blocked = 0;
        $fail = 0;

        foreach ($ids as $id) {
            if ((int) data_get($byId->get($id), 'product_count', 0) > 0) {
                $blocked++;

                continue;
            }
            try {
                $this->api->deleteBrand($id)->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::error('Bulk delete brand failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $fail++;
            }
        }

        $redirect = $this->backToList($request);

        if ($blocked === 0 && $fail === 0) {
            return $redirect->with('success', "Đã xoá {$ok} thương hiệu.");
        }

        $parts = [];
        if ($ok > 0) {
            $parts[] = "đã xoá {$ok} thương hiệu";
        }
        if ($blocked > 0) {
            $parts[] = "bỏ qua {$blocked} thương hiệu còn sản phẩm";
        }
        if ($fail > 0) {
            $parts[] = "{$fail} thương hiệu lỗi";
        }

        return $redirect->with($ok > 0 ? 'success' : 'error', 'Kết quả: '.implode('; ', $parts).'.');
    }

    /** Nhận logo từ modal, lưu vào public disk, trả URL tuyệt đối. */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'image' => ImageStore::rules(allowSvg: true),
        ], ImageStore::messages(allowSvg: true));

        return response()->json(['url' => ImageStore::put($request->file('image'), 'brands')]);
    }

    /** Xuất danh sách thương hiệu theo đúng bộ lọc đang xem. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $brands = $this->applyFilters($this->fetchAll(), $filters);
        $fileName = 'danh-sach-thuong-hieu-'.date('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($brands) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, ['ID', 'Tên thương hiệu', 'Đường dẫn (slug)', 'Mô tả', 'Số sản phẩm', 'Trạng thái', 'Ngày tạo']);

            foreach ($brands as $b) {
                fputcsv($file, [
                    $b['id'] ?? '',
                    $b['name'] ?? '',
                    $b['slug'] ?? '',
                    $b['description'] ?? '',
                    (int) ($b['product_count'] ?? 0),
                    ($b['is_active'] ?? false) ? self::STATUSES['active'] : self::STATUSES['inactive'],
                    ! empty($b['created_at']) ? \Illuminate\Support\Carbon::parse($b['created_at'])->format('d/m/Y H:i') : '',
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
        $sort = (string) $request->query('sort', 'name_asc');
        $pageSize = (int) $request->query('page_size', 20);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'name_asc',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 20,
        ];
    }

    /** Tìm kiếm + lọc trạng thái + sắp xếp (làm tại đây vì API trả nguyên danh sách). */
    protected function applyFilters(array $brands, array $filters): array
    {
        $list = collect($brands);

        if ($filters['keyword'] !== '') {
            $kw = mb_strtolower($filters['keyword'], 'UTF-8');
            $list = $list->filter(function ($b) use ($kw) {
                foreach (['name', 'slug', 'description'] as $field) {
                    if (str_contains(mb_strtolower((string) ($b[$field] ?? ''), 'UTF-8'), $kw)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if ($filters['status'] !== 'all') {
            $want = $filters['status'] === 'active';
            $list = $list->filter(fn ($b) => (bool) ($b['is_active'] ?? false) === $want);
        }

        $list = match ($filters['sort']) {
            'name_desc' => $list->sortByDesc(fn ($b) => mb_strtolower((string) ($b['name'] ?? ''), 'UTF-8'), SORT_NATURAL),
            'newest' => $list->sortByDesc(fn ($b) => (string) ($b['created_at'] ?? '')),
            'oldest' => $list->sortBy(fn ($b) => (string) ($b['created_at'] ?? '')),
            'products_desc' => $list->sortByDesc(fn ($b) => (int) ($b['product_count'] ?? 0)),
            default => $list->sortBy(fn ($b) => mb_strtolower((string) ($b['name'] ?? ''), 'UTF-8'), SORT_NATURAL),
        };

        return $list->values()->all();
    }

    /** Dữ liệu thương hiệu đầy đủ từ modal của trang này. */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:191'],
            'logo' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable'],
        ], [
            'name.required' => 'Vui lòng nhập tên thương hiệu.',
            'name.max' => 'Tên thương hiệu tối đa 150 ký tự.',
            'slug.max' => 'Đường dẫn tối đa 191 ký tự.',
            'logo.max' => 'Đường dẫn ảnh tối đa 255 ký tự.',
            'description.max' => 'Mô tả tối đa 500 ký tự.',
        ]);

        return [
            'name' => $validated['name'],
            'slug' => filled($validated['slug'] ?? null)
                ? $this->slugify($validated['slug'])
                : $this->slugify($validated['name']),
            'logo' => (string) ($validated['logo'] ?? ''),
            'description' => (string) ($validated['description'] ?? ''),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** Dữ liệu cho lối "thêm nhanh" (chỉ có tên) từ modal trang Sản phẩm. */
    protected function validatedQuick(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
        ], [
            'name.required' => 'Vui lòng nhập tên thương hiệu.',
            'name.max' => 'Tên thương hiệu tối đa 150 ký tự.',
        ]);

        return [
            'name' => $data['name'],
            'slug' => $this->slugify($data['name']),
            'logo' => '',
            'description' => '',
            'is_active' => true,
        ];
    }

    protected function find(int $id): ?array
    {
        try {
            $res = $this->api->brand($id);
            if ($res->successful()) {
                return $res->json('data');
            }
        } catch (\Throwable $e) {
            Log::info('Load brand failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return null;
    }

    protected function fetchAll(): array
    {
        try {
            $res = $this->api->brands(all: true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Fetch all brands failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Brand API call failed', ['msg' => $e->getMessage()]);

            return $this->backToList($request)->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return $this->backToList($request)->with('success', $success);
        }

        return $this->backToList($request)
            ->with('error', $res->json('message') ?: 'Thao tác không thành công.');
    }

    protected function backToList(Request $request)
    {
        $return = $request->input('return');
        if (is_string($return) && str_starts_with($return, '/')) {
            return redirect($return);
        }

        return redirect()->route('admin.brands.index', $request->query());
    }

    /** Slug hỗ trợ tiếng Việt (đồng bộ Category/Product). */
    protected function slugify(string $text): string
    {
        $map = [
            'a' => 'áàạảãâấầậẩẫăắằặẳẵ', 'e' => 'éèẹẻẽêếềệểễ',
            'i' => 'íìịỉĩ', 'o' => 'óòọỏõôốồộổỗơớờợởỡ',
            'u' => 'úùụủũưứừựửữ', 'y' => 'ýỳỵỷỹ', 'd' => 'đ',
        ];

        $text = mb_strtolower($text, 'UTF-8');
        foreach ($map as $ascii => $chars) {
            $text = preg_replace('/['.$chars.']/u', $ascii, $text);
        }

        return Str::slug($text);
    }
}
