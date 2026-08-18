<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Quản lý danh mục sản phẩm — giao diện & luồng port 1:1 từ màn "Nhóm hàng hóa"
 * (GroupsSection) của dự án OrderTable: cây loại lớn/nhóm bên trái + bảng nhóm con
 * bên phải, modal tạo/sửa kèm bảng "Nhóm con".
 *
 * Toàn bộ dữ liệu đọc/ghi qua Go API (không truy cập MySQL trực tiếp).
 * Danh mục cấp 1 mặc định đóng vai trò "loại lớn" (rule=parent) — bị khóa,
 * chỉ dùng để chứa nhóm con; các danh mục còn lại là "nhóm" (rule=children) CRUD tự do.
 */
class CategoryController extends Controller
{
    /** Danh mục cấp 1 mặc định — khóa: không sửa/xóa/đổi trạng thái (đóng vai trò "loại lớn"). */
    public const PROTECTED_SLUGS = ['cau-lac-bo', 'doi-tuyen-quoc-gia', 'phu-kien'];

    /** Số cấp danh mục tối đa (loại lớn -> nhóm -> nhóm con). */
    public const MAX_DEPTH = 3;

    public function __construct(protected ApiClient $api) {}

    /** Danh sách danh mục (phẳng) — view tự dựng cây từ parent_id. */
    public function index()
    {
        $categories = [];

        try {
            $res = $this->api->categories(all: true);
            if ($res->successful()) {
                $categories = $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Load categories failed', ['msg' => $e->getMessage()]);

            return view('categories.index', ['categories' => []])
                ->with('error', 'Không tải được danh sách danh mục. Kiểm tra kết nối API.');
        }

        // Gắn cờ "protected" cho các danh mục mặc định (loại lớn).
        $categories = collect($categories)->map(function ($c) {
            $c['protected'] = in_array($c['slug'] ?? '', self::PROTECTED_SLUGS, true);

            return $c;
        })->all();

        return view('categories.index', ['categories' => $categories]);
    }

    /**
     * Tạo danh mục (nhóm) + các nhóm con nhập kèm trong modal.
     * parent_id = node đang chọn trên cây (loại lớn -> nhóm cấp 1; nhóm -> nhóm con).
     */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        if ($data['parent_id'] !== null && $this->depthOf($data['parent_id']) >= self::MAX_DEPTH) {
            return back()->withInput()->with('error', 'Chỉ cho phép tối đa ' . self::MAX_DEPTH . ' cấp danh mục.');
        }

        try {
            $res = $this->api->createCategory($data);
        } catch (\Throwable $e) {
            Log::error('Create category failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API.');
        }

        if (! $res->successful()) {
            return back()->withInput()->with('error', $res->json('message') ?: 'Tạo danh mục thất bại.');
        }

        $newId = (int) data_get($res->json('data'), 'id');
        $child = $this->saveChildren($request, $newId);

        if ($child['fail'] > 0) {
            return redirect()->route('admin.categories.index')
                ->with('focus_parent', $data['parent_id'])
                ->with('error', "Đã thêm danh mục nhưng {$child['fail']} nhóm con lưu không thành công.");
        }

        return redirect()->route('admin.categories.index')
            ->with('focus_parent', $data['parent_id'])
            ->with('success', 'Đã thêm danh mục.');
    }

    /** Cập nhật danh mục + upsert các nhóm con trong modal. */
    public function update(Request $request, int $id)
    {
        if ($this->isProtected($id)) {
            return back()->with('error', 'Đây là danh mục mặc định, không thể chỉnh sửa.');
        }

        $data = $this->validated($request);

        if ($data['parent_id'] !== null && $this->depthOf($data['parent_id']) >= self::MAX_DEPTH) {
            return back()->withInput()->with('error', 'Chỉ cho phép tối đa ' . self::MAX_DEPTH . ' cấp danh mục.');
        }

        try {
            $res = $this->api->updateCategory($id, $data);
        } catch (\Throwable $e) {
            Log::error('Update category failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API.');
        }

        if (! $res->successful()) {
            return back()->withInput()->with('error', $res->json('message') ?: 'Cập nhật danh mục thất bại.');
        }

        $child = $this->saveChildren($request, $id);

        if ($child['fail'] > 0) {
            return redirect()->route('admin.categories.index')
                ->with('focus_parent', $data['parent_id'])
                ->with('error', "Đã cập nhật danh mục nhưng {$child['fail']} nhóm con lưu không thành công.");
        }

        return redirect()->route('admin.categories.index')
            ->with('focus_parent', $data['parent_id'])
            ->with('success', 'Đã cập nhật danh mục.');
    }

    /** Xóa danh mục. */
    public function destroy(int $id)
    {
        if ($this->isProtected($id)) {
            return back()->with('error', 'Đây là danh mục mặc định, không thể xóa.');
        }

        return $this->send(
            fn () => $this->api->deleteCategory($id),
            'Đã xóa danh mục.'
        );
    }

    /** Xóa nhiều danh mục đã chọn trên bảng (bulk). */
    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->all();

        if (empty($ids)) {
            return back()->with('error', 'Chưa chọn danh mục nào để xóa.');
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
                ->with('error', "Đã xóa {$ok} nhóm; {$fail} nhóm xóa không thành công (đang chứa nhóm con hoặc sản phẩm).");
        }

        return redirect()->route('admin.categories.index')->with('success', "Đã xóa {$ok} nhóm.");
    }

    /**
     * Nhận file ảnh từ modal, lưu vào public disk (public/storage/categories),
     * trả URL tuyệt đối để gán vào trường image của danh mục.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => ImageStore::rules(),
        ], ImageStore::messages());

        return response()->json(['url' => ImageStore::put($request->file('image'), 'categories')]);
    }

    // ---------- Helpers ----------

    /**
     * Lưu các dòng "nhóm con" từ modal dưới danh mục $parentId.
     * Dòng có id -> cập nhật; dòng chưa có id -> tạo mới. (Không tự xóa dòng bị gỡ.)
     */
    protected function saveChildren(Request $request, int $parentId): array
    {
        $ok = 0;
        $fail = 0;

        foreach ($this->childRows($request) as $ch) {
            $payload = [
                'name' => $ch['name'],
                'slug' => filled($ch['code']) ? $this->slugify($ch['code']) : $this->slugify($ch['name']),
                'parent_id' => $parentId,
                'description' => $ch['description'],
                'image' => '',
                'sort_order' => $ch['sort_order'],
                'is_active' => $ch['is_active'],
            ];

            try {
                $res = $ch['id']
                    ? $this->api->updateCategory($ch['id'], $payload)
                    : $this->api->createCategory($payload);
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
            if ($name === '') {
                continue;
            }
            $out[] = [
                'id' => filled($r['id'] ?? null) ? (int) $r['id'] : null,
                'code' => trim((string) ($r['code'] ?? '')),
                'name' => $name,
                'sort_order' => (int) ($r['sort_order'] ?? 0),
                'description' => (string) ($r['description'] ?? ''),
                'is_active' => filled($r['is_active'] ?? null) ? (bool) $r['is_active'] : true,
            ];
        }

        return $out;
    }

    /**
     * Độ sâu (cấp) của một danh mục: cấp 1 = danh mục gốc (parent_id null).
     * Đi ngược chuỗi parent_id trên toàn bộ danh mục. Trả 0 nếu không tìm thấy.
     */
    protected function depthOf(int $id): int
    {
        try {
            $res = $this->api->categories(all: true);
            if (! $res->successful()) {
                return 0;
            }
            $all = collect($res->json('data') ?? [])->keyBy('id');
        } catch (\Throwable $e) {
            Log::warning('Compute category depth failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return 0;
        }

        $depth = 0;
        $cur = $all->get($id);
        // Chặn vòng lặp vô hạn nếu dữ liệu parent_id bị lỗi (tối đa = số danh mục).
        $guard = $all->count() + 1;

        while ($cur && $guard-- > 0) {
            $depth++;
            $pid = $cur['parent_id'] ?? null;
            if ($pid === null) {
                break;
            }
            $cur = $all->get((int) $pid);
        }

        return $depth;
    }

    /** Kiểm tra 1 danh mục có phải danh mục mặc định (bị khóa) không. */
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

    /** Chuẩn hóa & validate dữ liệu danh mục chính từ form. */
    protected function validated(Request $request): array
    {
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable'],
        ], [
            'name.required' => 'Vui lòng nhập tên danh mục.',
            'name.max' => 'Tên danh mục tối đa 150 ký tự.',
            'slug.max' => 'Slug tối đa 191 ký tự.',
            'description.max' => 'Mô tả tối đa 500 ký tự.',
        ])->stopOnFirstFailure()->validate();

        return [
            'name' => $v['name'],
            'slug' => filled($v['slug'] ?? null) ? $this->slugify($v['slug']) : $this->slugify($v['name']),
            'parent_id' => filled($v['parent_id'] ?? null) ? (int) $v['parent_id'] : null,
            'description' => $v['description'] ?? '',
            'image' => $v['image'] ?? '',
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

    /**
     * Tạo slug hỗ trợ tiếng Việt.
     * Str::slug trên một số môi trường không phiên âm được dấu tiếng Việt
     * (ụ, ể, ử... bị bỏ), nên ta chuyển sang ASCII trước.
     */
    protected function slugify(string $text): string
    {
        $map = [
            'a' => 'áàạảãâấầậẩẫăắằặẳẵ', 'e' => 'éèẹẻẽêếềệểễ',
            'i' => 'íìịỉĩ', 'o' => 'óòọỏõôốồộổỗơớờợởỡ',
            'u' => 'úùụủũưứừựửữ', 'y' => 'ýỳỵỷỹ', 'd' => 'đ',
        ];

        $text = mb_strtolower($text, 'UTF-8');
        foreach ($map as $ascii => $chars) {
            $text = preg_replace('/[' . $chars . ']/u', $ascii, $text);
        }

        return Str::slug($text);
    }
}
