<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Quản lý nhóm hàng hóa — bố cục và luồng theo bản cũ v2 (màn "Nhóm sản phẩm"):
 * cây nhóm bên trái + bảng nhóm con bên phải, modal thêm/sửa kèm bảng "Nhóm con".
 *
 * Toàn bộ dữ liệu đọc/ghi qua Go API (không truy cập MySQL trực tiếp).
 * Hai nhóm gốc cố định (xem ROOTS) đóng vai "loại lớn": bị khóa, chỉ để chứa
 * nhóm con; các nhóm còn lại CRUD tự do, lồng bao nhiêu cấp cũng được.
 */
class CategoryController extends Controller
{
    /**
     * Hai nhóm gốc cố định (slug => tên) — khóa: không sửa/xóa/đổi trạng thái.
     * Đóng vai "loại lớn" như odr_menu_group_parents của bản v2, nhưng ở đây là
     * danh mục cấp 1 bình thường nên mỗi cửa hàng phải có sẵn hai dòng này.
     */
    public const ROOTS = [
        'hang-ban' => 'Hàng bán',
        'hang-hoa-khac' => 'Hàng hóa khác',
    ];

    /** Slug của các nhóm gốc — dùng để gắn cờ khóa. */
    public const PROTECTED_SLUGS = ['hang-ban', 'hang-hoa-khac'];

    public function __construct(protected ApiClient $api) {}

    /** Danh sách nhóm (phẳng) — view tự dựng cây từ parent_id. */
    public function index()
    {
        $categories = [];

        try {
            $res = $this->api->categories(all: true);
            if ($res->successful()) {
                $categories = $res->json('data') ?? [];
                $categories = $this->ensureRoots($categories);
            }
        } catch (\Throwable $e) {
            Log::error('Load categories failed', ['msg' => $e->getMessage()]);

            return view('categories.index', ['categories' => []])
                ->with('error', 'Không tải được danh sách nhóm hàng hóa. Kiểm tra kết nối API.');
        }

        // Gắn cờ "protected" cho hai nhóm gốc cố định.
        $categories = collect($categories)->map(function ($c) {
            $c['protected'] = in_array($c['slug'] ?? '', self::PROTECTED_SLUGS, true);

            return $c;
        })->all();

        return view('categories.index', ['categories' => $categories]);
    }

    /**
     * Tạo nhóm hàng hóa + các nhóm con nhập kèm trong modal.
     * parent_id = nhóm đang chọn trên cây.
     */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        try {
            $res = $this->api->createCategory($data);
        } catch (\Throwable $e) {
            Log::error('Create category failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API.');
        }

        if (! $res->successful()) {
            return back()->withInput()->with('error', $this->apiError($res, 'Tạo nhóm hàng hóa thất bại.'));
        }

        $newId = (int) data_get($res->json('data'), 'id');
        $child = $this->saveChildren($request, $newId);

        if ($child['fail'] > 0) {
            return redirect()->route('admin.categories.index')
                ->with('focus_parent', $data['parent_id'])
                ->with('error', "Đã thêm nhóm nhưng {$child['fail']} nhóm con lưu không thành công.");
        }

        return redirect()->route('admin.categories.index')
            ->with('focus_parent', $data['parent_id'])
            ->with('success', 'Đã thêm nhóm hàng hóa.');
    }

    /** Cập nhật nhóm hàng hóa + upsert các nhóm con trong modal. */
    public function update(Request $request, int $id)
    {
        $current = $this->fetch($id);

        if ($current !== null && in_array($current['slug'] ?? '', self::PROTECTED_SLUGS, true)) {
            return back()->with('error', 'Đây là nhóm gốc cố định, không thể chỉnh sửa.');
        }

        $data = $this->validated($request);
        // Mã và ảnh không sửa được ở màn này — chuyển tiếp giá trị cũ để khỏi ghi rỗng đè lên.
        $data['slug'] = (string) ($current['slug'] ?? '');
        $data['image'] = (string) ($current['image'] ?? '');

        try {
            $res = $this->api->updateCategory($id, $data);
        } catch (\Throwable $e) {
            Log::error('Update category failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API.');
        }

        if (! $res->successful()) {
            return back()->withInput()->with('error', $this->apiError($res, 'Cập nhật nhóm hàng hóa thất bại.'));
        }

        $child = $this->saveChildren($request, $id);

        if ($child['fail'] > 0) {
            return redirect()->route('admin.categories.index')
                ->with('focus_parent', $data['parent_id'])
                ->with('error', "Đã cập nhật nhóm nhưng {$child['fail']} nhóm con lưu không thành công.");
        }

        return redirect()->route('admin.categories.index')
            ->with('focus_parent', $data['parent_id'])
            ->with('success', 'Đã cập nhật nhóm hàng hóa.');
    }

    /** Xóa một nhóm hàng hóa. */
    public function destroy(int $id)
    {
        if ($this->isProtected($id)) {
            return back()->with('error', 'Đây là nhóm gốc cố định, không thể xóa.');
        }

        return $this->send(
            fn () => $this->api->deleteCategory($id),
            'Đã xóa nhóm hàng hóa.'
        );
    }

    /** Xóa nhiều nhóm đã chọn trên bảng (bulk). */
    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->all();

        if (empty($ids)) {
            return back()->with('error', 'Chưa chọn nhóm nào để xóa.');
        }

        $ok = 0;
        $fail = 0;

        foreach ($ids as $id) {
            if ($this->isProtected($id)) {
                $fail++;

                continue;
            }
            try {
                $res = $this->api->deleteCategory($id);
                $res->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::warning('Bulk delete category failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $fail++;
            }
        }

        if ($fail > 0) {
            return redirect()->route('admin.categories.index')
                ->with('error', "Đã xóa {$ok} nhóm; {$fail} nhóm xóa không thành công (là nhóm gốc, hoặc đang chứa nhóm con).");
        }

        return redirect()->route('admin.categories.index')->with('success', "Đã xóa {$ok} nhóm.");
    }

    /**
     * Xóa toàn bộ nhóm con trực tiếp của một nhóm — nút "Xóa tất cả" trong modal.
     * Theo bản v2: chỉ cần một nhóm con còn nhánh cấp dưới là dừng, không xóa gì.
     */
    public function destroyChildren(int $id)
    {
        try {
            $res = $this->api->categories(all: true);
            $all = $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load categories failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được máy chủ API.');
        }

        $childIds = collect($all)
            ->filter(fn ($c) => (int) ($c['parent_id'] ?? 0) === $id)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if (empty($childIds)) {
            return back()->with('error', 'Nhóm này chưa có nhóm con nào.');
        }

        $hasGrandChild = collect($all)->contains(fn ($c) => in_array((int) ($c['parent_id'] ?? 0), $childIds, true));

        if ($hasGrandChild) {
            return back()->with('error', 'Có nhóm con đang chứa nhóm cấp dưới — hãy xóa cấp dưới trước.');
        }

        $ok = 0;
        $fail = 0;

        foreach ($childIds as $childId) {
            try {
                $res = $this->api->deleteCategory($childId);
                $res->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::warning('Delete child category failed', ['id' => $childId, 'msg' => $e->getMessage()]);
                $fail++;
            }
        }

        $redirect = redirect()->route('admin.categories.index')->with('focus_parent', $id);

        return $fail > 0
            ? $redirect->with('error', "Đã xóa {$ok} nhóm con; {$fail} nhóm xóa không thành công.")
            : $redirect->with('success', "Đã xóa {$ok} nhóm con.");
    }

    // ---------- Helpers ----------

    /**
     * Bảo đảm cửa hàng có đủ hai nhóm gốc cố định (Hàng bán / Hàng hóa khác).
     * Chạy khi mở trang, chỉ ghi khi thiếu — mỗi cửa hàng là một tenant riêng nên
     * không seed sẵn được từ migration.
     *
     * @param  array $categories danh sách phẳng vừa nạp
     * @return array danh sách đã có đủ gốc
     */
    protected function ensureRoots(array $categories): array
    {
        $existing = collect($categories)->pluck('slug')->all();
        $created = false;

        foreach (self::ROOTS as $slug => $name) {
            if (in_array($slug, $existing, true)) {
                continue;
            }
            try {
                $res = $this->api->createCategory([
                    'name' => $name,
                    'slug' => $slug,
                    'parent_id' => null,
                    'description' => '',
                    'image' => '',
                    'sort_order' => 0,
                    'is_active' => true,
                ]);
                if ($res->successful()) {
                    $created = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Create root category failed', ['slug' => $slug, 'msg' => $e->getMessage()]);
            }
        }

        if (! $created) {
            return $categories;
        }

        try {
            $res = $this->api->categories(all: true);

            return $res->successful() ? ($res->json('data') ?? $categories) : $categories;
        } catch (\Throwable $e) {
            Log::warning('Reload categories after seeding roots failed', ['msg' => $e->getMessage()]);

            return $categories;
        }
    }

    /**
     * Câu lỗi cho người dùng từ phản hồi API. API trả 409 kèm câu nói về "slug"
     * (thuật ngữ tầng dữ liệu) — mã ở màn này tự sinh nên nói lại cho dễ hiểu.
     */
    protected function apiError($res, string $fallback): string
    {
        if ($res->status() === 409) {
            return 'Mã nhóm bị trùng do có người vừa thêm cùng lúc. Vui lòng bấm Lưu lại.';
        }

        return $res->json('message') ?: $fallback;
    }

    // Mã nhóm KHÔNG sinh ở đây nữa: API đặt mã (theo quy tắc đánh số của cửa
    // hàng, hoặc dải NH0001 nếu chưa bật quy tắc). Hai bên cùng sinh mã là hai
    // dải số cãi nhau — xem ThongSoChungController.

    /** Lấy một nhóm theo id, null nếu không đọc được. */
    protected function fetch(int $id): ?array
    {
        try {
            $res = $this->api->category($id);
            if ($res->successful()) {
                return $res->json('data');
            }
        } catch (\Throwable $e) {
            Log::warning('Load category failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Lưu các dòng "nhóm con" từ modal dưới nhóm $parentId.
     * Dòng có id -> cập nhật (giữ mã cũ); dòng chưa có id -> tạo mới, mã do API đặt.
     * (Không tự xóa dòng bị gỡ.)
     */
    protected function saveChildren(Request $request, int $parentId): array
    {
        $rows = $this->childRows($request);

        if (empty($rows)) {
            return ['ok' => 0, 'fail' => 0];
        }

        $ok = 0;
        $fail = 0;

        foreach ($rows as $ch) {
            $payload = [
                'name' => $ch['name'],
                'parent_id' => $parentId,
                'description' => $ch['description'],
                'image' => $ch['image'],
                'sort_order' => $ch['sort_order'],
                'is_active' => $ch['is_active'],
            ];

            try {
                if ($ch['id']) {
                    $payload['slug'] = $ch['code'];   // dòng cũ giữ nguyên mã
                    $res = $this->api->updateCategory($ch['id'], $payload);
                } else {
                    $res = $this->api->createCategory($payload);
                }
                $res->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::warning('Save child category failed', ['msg' => $e->getMessage()]);
                $fail++;
            }
        }

        return compact('ok', 'fail');
    }

    /** Chuẩn hóa mảng children[] từ form (bỏ dòng trống tên). */
    protected function childRows(Request $request): array
    {
        $rows = $request->input('children', []);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            // Thiếu tên thì bỏ qua — view đã chặn trước, đây là chốt chặn cuối.
            if ($name === '') {
                continue;
            }
            $out[] = [
                'id' => filled($r['id'] ?? null) ? (int) $r['id'] : null,
                'code' => trim((string) ($r['code'] ?? '')),
                'name' => $name,
                'sort_order' => (int) ($r['sort_order'] ?? 0),
                'description' => (string) ($r['description'] ?? ''),
                'image' => (string) ($r['image'] ?? ''),
                'is_active' => filled($r['is_active'] ?? null) ? (bool) $r['is_active'] : true,
            ];
        }

        return $out;
    }

    /** Kiểm tra một nhóm có phải nhóm gốc cố định (bị khóa) không. */
    protected function isProtected(int $id): bool
    {
        try {
            $res = $this->api->category($id);
            if ($res->successful()) {
                return in_array(data_get($res->json('data'), 'slug'), self::PROTECTED_SLUGS, true);
            }
        } catch (\Throwable $e) {
            Log::warning('Check protected category failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return false;
    }

    /** Chuẩn hóa & validate dữ liệu nhóm chính từ form. */
    protected function validated(Request $request): array
    {
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable'],
        ], [
            'name.required' => 'Vui lòng nhập tên nhóm hàng hóa.',
            'name.max' => 'Tên nhóm hàng hóa tối đa 150 ký tự.',
            'description.max' => 'Mô tả tối đa 500 ký tự.',
        ])->stopOnFirstFailure()->validate();

        // Không có 'slug': mã do máy chủ tự sinh (store) hoặc giữ nguyên (update).
        return [
            'name' => $v['name'],
            'parent_id' => filled($v['parent_id'] ?? null) ? (int) $v['parent_id'] : null,
            'description' => $v['description'] ?? '',
            'image' => '',
            'sort_order' => (int) ($v['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** Gọi API và chuyển hướng kèm thông báo phù hợp. */
    protected function send(\Closure $call, string $successMsg)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Category API call failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được máy chủ API.');
        }

        if ($res->successful()) {
            return redirect()->route('admin.categories.index')->with('success', $successMsg);
        }

        $message = $res->json('message') ?: 'Thao tác thất bại.';

        return back()->withInput()->with('error', $message);
    }
}
