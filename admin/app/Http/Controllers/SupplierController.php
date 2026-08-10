<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SupplierController — trang "Nhà cung cấp" của khu quản trị.
 *
 * Đây là danh mục ĐẦU MỐI MUA VÀO: trang Đặt hàng nhập chọn nhà cung cấp từ đây,
 * còn tiền đã mua / còn nợ thì API tự tổng hợp từ phiếu đặt hàng (bỏ phiếu nháp
 * và phiếu đã huỷ) nên trang này không tự cộng lại.
 *
 * Giống trang Thương hiệu về mặt dữ liệu: API /admin/suppliers trả TOÀN BỘ danh
 * sách một lần (số nhà cung cấp luôn nhỏ), nên tìm kiếm / lọc / sắp xếp / cắt
 * trang làm ngay tại controller, view vẫn dùng chung component phân trang.
 */
class SupplierController extends Controller
{
    public const TITLE = 'Nhà cung cấp';

    public const STATUSES = [
        'active' => 'Đang hợp tác',
        'inactive' => 'Ngừng hợp tác',
    ];

    /** Lọc theo công nợ — việc hay làm nhất trên trang này là đi soát tiền còn nợ. */
    public const DEBTS = [
        'debt' => 'Còn nợ',
        'clear' => 'Không nợ',
    ];

    public const SORTS = [
        'name_asc' => 'Tên A → Z',
        'name_desc' => 'Tên Z → A',
        'code_asc' => 'Mã tăng dần',
        'debt_desc' => 'Còn nợ nhiều nhất',
        'amount_desc' => 'Mua nhiều nhất',
        'purchases_desc' => 'Nhiều phiếu nhất',
        'recent_order' => 'Đặt hàng gần nhất',
        'newest' => 'Mới thêm nhất',
    ];

    public const PAGE_SIZES = [10, 20, 50, 100];

    public const EMPTY_TEXT = 'Chưa có nhà cung cấp nào. Bấm "Thêm nhà cung cấp" để khai đầu mối nhập hàng — sau đó mới lập được phiếu đặt hàng.';

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $all = [];

        try {
            $res = $this->api->suppliers();
            if ($res->successful()) {
                $all = $res->json('data') ?? [];
            } else {
                Log::warning('Load suppliers failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách nhà cung cấp.';
            }
        } catch (\Throwable $e) {
            Log::error('Load suppliers failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách nhà cung cấp. Kiểm tra kết nối API.';
        }

        $filtered = $this->applyFilters($all, $filters);
        $total = count($filtered);
        $totalPages = max(1, (int) ceil($total / $filters['page_size']));
        $page = min($filters['page'], $totalPages);

        $view = view('suppliers.index', [
            'suppliers' => array_values(array_slice($filtered, ($page - 1) * $filters['page_size'], $filters['page_size'])),
            'filters' => array_merge($filters, ['page' => $page]),
            'stats' => $this->stats($all),
            'meta' => [
                'page' => $page,
                'page_size' => $filters['page_size'],
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->createSupplier($data),
            'Đã thêm nhà cung cấp "'.$data['name'].'".',
            $request
        );
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->updateSupplier($id, $data),
            'Đã cập nhật nhà cung cấp "'.$data['name'].'".',
            $request
        );
    }

    /**
     * Bật/tắt "đang hợp tác" — API nhận cả bản ghi nên phải đọc lại rồi gửi đủ
     * các trường, nếu chỉ gửi is_active thì tên/liên hệ sẽ bị ghi rỗng.
     */
    public function toggleStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ], [
            'is_active.required' => 'Thiếu trạng thái cần đặt.',
        ]);

        $sup = $this->find($id);
        if ($sup === null) {
            return $this->backToList($request)->with('error', 'Không tải được thông tin nhà cung cấp. Vui lòng thử lại.');
        }

        $isActive = (bool) $validated['is_active'];

        return $this->send(
            fn () => $this->api->updateSupplier($id, $this->payloadFrom($sup, $isActive)),
            $isActive
                ? 'Đã bật lại hợp tác với "'.($sup['name'] ?? '#'.$id).'".'
                : 'Đã ngừng hợp tác với "'.($sup['name'] ?? '#'.$id).'" — không còn hiện trong ô chọn khi lập phiếu.',
            $request
        );
    }

    /** Xoá nhà cung cấp — chặn ngay nếu còn phiếu đặt hàng trỏ tới. */
    public function destroy(Request $request, int $id)
    {
        $sup = $this->find($id);
        if ($sup === null) {
            return $this->backToList($request)->with('error', 'Không tải được thông tin nhà cung cấp. Vui lòng thử lại.');
        }

        $used = (int) ($sup['purchase_count'] ?? 0);
        if ($used > 0) {
            return $this->backToList($request)->with(
                'error',
                'Không xoá được "'.($sup['name'] ?? '#'.$id).'": còn '.$used.' phiếu đặt hàng gắn với nhà cung cấp này. '
                .'Hãy chuyển sang "Ngừng hợp tác" thay vì xoá, để các phiếu cũ vẫn giữ được đầu mối liên hệ.'
            );
        }

        return $this->send(
            fn () => $this->api->deleteSupplier($id),
            'Đã xoá nhà cung cấp "'.($sup['name'] ?? '#'.$id).'".',
            $request
        );
    }

    /** Xoá nhiều nhà cung cấp đã chọn — bỏ qua bên nào còn phiếu đặt hàng. */
    public function bulkDestroy(Request $request)
    {
        $ids = $this->idsFrom($request);
        if (empty($ids)) {
            return $this->backToList($request)->with('error', 'Chưa chọn nhà cung cấp nào để xoá.');
        }

        // Đọc một lần cả danh sách để biết bên nào còn phiếu (khỏi gọi API chi tiết
        // cho từng id).
        $byId = collect($this->fetchAll())->keyBy('id');

        $ok = 0;
        $blocked = 0;
        $fail = 0;

        foreach ($ids as $id) {
            if ((int) data_get($byId->get($id), 'purchase_count', 0) > 0) {
                $blocked++;

                continue;
            }
            try {
                $this->api->deleteSupplier($id)->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::error('Bulk delete supplier failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $fail++;
            }
        }

        $redirect = $this->backToList($request);

        if ($blocked === 0 && $fail === 0) {
            return $redirect->with('success', "Đã xoá {$ok} nhà cung cấp.");
        }

        $parts = [];
        if ($ok > 0) {
            $parts[] = "đã xoá {$ok} nhà cung cấp";
        }
        if ($blocked > 0) {
            $parts[] = "bỏ qua {$blocked} bên còn phiếu đặt hàng";
        }
        if ($fail > 0) {
            $parts[] = "{$fail} bên lỗi";
        }

        return $redirect->with($ok > 0 ? 'success' : 'error', 'Kết quả: '.implode('; ', $parts).'.');
    }

    /**
     * Bật/tắt hợp tác hàng loạt — lối thoát cho các bên còn phiếu nên không xoá
     * được: chọn nhiều bên rồi cho ngừng hợp tác một lượt.
     */
    public function bulkStatus(Request $request)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $isActive = $request->boolean('is_active');

        $ids = $this->idsFrom($request);
        if (empty($ids)) {
            return $this->backToList($request)->with('error', 'Chưa chọn nhà cung cấp nào.');
        }

        $byId = collect($this->fetchAll())->keyBy('id');
        $ok = 0;
        $skipped = 0;
        $fail = 0;

        foreach ($ids as $id) {
            $sup = $byId->get($id);
            if (! is_array($sup)) {
                $fail++;

                continue;
            }
            // Đang đúng trạng thái cần đặt thì không gọi API cho tốn lượt.
            if ((bool) ($sup['is_active'] ?? false) === $isActive) {
                $skipped++;

                continue;
            }
            try {
                $this->api->updateSupplier($id, $this->payloadFrom($sup, $isActive))->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::error('Bulk toggle supplier failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $fail++;
            }
        }

        $label = $isActive ? 'Đang hợp tác' : 'Ngừng hợp tác';
        $parts = [];
        if ($ok > 0) {
            $parts[] = "{$ok} nhà cung cấp chuyển sang \"{$label}\"";
        }
        if ($skipped > 0) {
            $parts[] = "bỏ qua {$skipped} bên đã ở trạng thái này";
        }
        if ($fail > 0) {
            $parts[] = "{$fail} bên lỗi";
        }
        $msg = empty($parts) ? 'Không có nhà cung cấp nào được xử lý.' : implode(', ', $parts).'.';

        $redirect = $this->backToList($request);

        return $ok > 0 ? $redirect->with('success', $msg) : $redirect->with('error', $msg);
    }

    /** Xuất danh sách theo đúng bộ lọc đang xem. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $suppliers = $this->applyFilters($this->fetchAll(), $filters);
        $fileName = 'danh-sach-nha-cung-cap-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($suppliers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Mã', 'Tên nhà cung cấp', 'Người liên hệ', 'Điện thoại', 'Email', 'Địa chỉ',
                'Mã số thuế', 'Số phiếu đặt', 'Tổng đã đặt', 'Còn nợ', 'Lần đặt gần nhất', 'Trạng thái', 'Ghi chú']);

            foreach ($suppliers as $s) {
                fputcsv($out, [
                    $s['code'] ?? '',
                    $s['name'] ?? '',
                    $s['contact_name'] ?? '',
                    $s['phone'] ?? '',
                    $s['email'] ?? '',
                    $s['address'] ?? '',
                    $s['tax_code'] ?? '',
                    (int) ($s['purchase_count'] ?? 0),
                    (float) ($s['purchase_amount'] ?? 0),
                    (float) ($s['debt_amount'] ?? 0),
                    ! empty($s['last_order_at']) ? Carbon::parse($s['last_order_at'])->format('d/m/Y') : '',
                    ($s['is_active'] ?? false) ? self::STATUSES['active'] : self::STATUSES['inactive'],
                    $s['note'] ?? '',
                ]);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $debt = (string) $request->query('debt', 'all');
        $sort = (string) $request->query('sort', 'name_asc');
        $pageSize = (int) $request->query('page_size', 20);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'debt' => isset(self::DEBTS[$debt]) ? $debt : 'all',
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'name_asc',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 20,
        ];
    }

    /** Tìm kiếm + lọc + sắp xếp tại chỗ (API trả nguyên danh sách). */
    protected function applyFilters(array $suppliers, array $filters): array
    {
        $list = collect($suppliers);

        if ($filters['keyword'] !== '') {
            $kw = mb_strtolower($filters['keyword'], 'UTF-8');
            $list = $list->filter(function ($s) use ($kw) {
                foreach (['code', 'name', 'contact_name', 'phone', 'email', 'address', 'tax_code', 'note'] as $field) {
                    if (str_contains(mb_strtolower((string) ($s[$field] ?? ''), 'UTF-8'), $kw)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if ($filters['status'] !== 'all') {
            $want = $filters['status'] === 'active';
            $list = $list->filter(fn ($s) => (bool) ($s['is_active'] ?? false) === $want);
        }

        if ($filters['debt'] !== 'all') {
            $wantDebt = $filters['debt'] === 'debt';
            $list = $list->filter(fn ($s) => ((float) ($s['debt_amount'] ?? 0) > 0) === $wantDebt);
        }

        $name = fn ($s) => mb_strtolower((string) ($s['name'] ?? ''), 'UTF-8');

        $list = match ($filters['sort']) {
            'name_desc' => $list->sortByDesc($name, SORT_NATURAL),
            'code_asc' => $list->sortBy(fn ($s) => (string) ($s['code'] ?? ''), SORT_NATURAL),
            'debt_desc' => $list->sortByDesc(fn ($s) => (float) ($s['debt_amount'] ?? 0)),
            'amount_desc' => $list->sortByDesc(fn ($s) => (float) ($s['purchase_amount'] ?? 0)),
            'purchases_desc' => $list->sortByDesc(fn ($s) => (int) ($s['purchase_count'] ?? 0)),
            'recent_order' => $list->sortByDesc(fn ($s) => (string) ($s['last_order_at'] ?? '')),
            'newest' => $list->sortByDesc(fn ($s) => (string) ($s['created_at'] ?? '')),
            default => $list->sortBy($name, SORT_NATURAL),
        };

        return $list->values()->all();
    }

    /** Số liệu trên header + số đếm trong ô lọc — tính trên TOÀN BỘ danh sách. */
    protected function stats(array $all): array
    {
        $stats = [
            'total' => count($all),
            'active' => 0,
            'inactive' => 0,
            'debt' => 0,
            'clear' => 0,
            'purchases' => 0,
            'amount' => 0.0,
            'debt_amount' => 0.0,
        ];

        foreach ($all as $s) {
            ($s['is_active'] ?? false) ? $stats['active']++ : $stats['inactive']++;
            (float) ($s['debt_amount'] ?? 0) > 0 ? $stats['debt']++ : $stats['clear']++;
            $stats['purchases'] += (int) ($s['purchase_count'] ?? 0);
            $stats['amount'] += (float) ($s['purchase_amount'] ?? 0);
            $stats['debt_amount'] += (float) ($s['debt_amount'] ?? 0);
        }

        return $stats;
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable'],
        ], [
            'name.required' => 'Vui lòng nhập tên nhà cung cấp.',
            'name.max' => 'Tên nhà cung cấp tối đa 150 ký tự.',
            'code.max' => 'Mã nhà cung cấp tối đa 30 ký tự.',
            'email.email' => 'Email không đúng định dạng.',
            'phone.max' => 'Số điện thoại tối đa 20 ký tự.',
            'address.max' => 'Địa chỉ tối đa 255 ký tự.',
            'tax_code.max' => 'Mã số thuế tối đa 30 ký tự.',
            'note.max' => 'Ghi chú tối đa 500 ký tự.',
        ]);

        return [
            'code' => trim((string) ($data['code'] ?? '')),
            'name' => trim($data['name']),
            'contact_name' => trim((string) ($data['contact_name'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'address' => trim((string) ($data['address'] ?? '')),
            'tax_code' => trim((string) ($data['tax_code'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** Payload gửi API khi chỉ muốn đổi trạng thái: giữ nguyên mọi trường còn lại. */
    protected function payloadFrom(array $sup, bool $isActive): array
    {
        return [
            'code' => (string) ($sup['code'] ?? ''),
            'name' => (string) ($sup['name'] ?? ''),
            'contact_name' => (string) ($sup['contact_name'] ?? ''),
            'phone' => (string) ($sup['phone'] ?? ''),
            'email' => (string) ($sup['email'] ?? ''),
            'address' => (string) ($sup['address'] ?? ''),
            'tax_code' => (string) ($sup['tax_code'] ?? ''),
            'note' => (string) ($sup['note'] ?? ''),
            'is_active' => $isActive,
        ];
    }

    /** ids[] từ form bulk — bỏ giá trị rác, không trùng, chặn quá 200 dòng. */
    protected function idsFrom(Request $request): array
    {
        return collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->take(200)
            ->values()
            ->all();
    }

    protected function find(int $id): ?array
    {
        try {
            $res = $this->api->supplier($id);
            if ($res->successful()) {
                return $res->json('data');
            }
        } catch (\Throwable $e) {
            Log::info('Load supplier failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return null;
    }

    protected function fetchAll(): array
    {
        try {
            $res = $this->api->suppliers();
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Fetch all suppliers failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Supplier API call failed', ['msg' => $e->getMessage()]);

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

        return redirect()->route('admin.suppliers.index', $request->query());
    }
}
