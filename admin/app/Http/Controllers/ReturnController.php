<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ReturnController — trang "Trả hàng" của khu quản trị.
 *
 * Phiếu trả hàng là sổ riêng, không phải một trạng thái của đơn: một đơn có thể
 * có nhiều phiếu (khách trả dần từng món) và mỗi phiếu tự đi theo luồng duyệt
 * của nó. Toàn bộ luật (được trả bao nhiêu, nhập kho lúc nào, hoàn tiền ra sao)
 * do Go API giữ — controller này chỉ chuyển tiếp và dịch câu chữ sang tiếng Việt.
 */
class ReturnController extends Controller
{
    public const STATUSES = [
        'pending' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'received' => 'Đã nhận hàng',
        'refunded' => 'Đã hoàn tiền',
        'rejected' => 'Từ chối',
        'cancelled' => 'Khách rút',
    ];

    /** Tông màu badge — dùng chung bảng màu với trang Đơn hàng. */
    public const STATUS_TONES = [
        'pending' => 'wait', 'approved' => 'info', 'received' => 'move',
        'refunded' => 'done', 'rejected' => 'stop', 'cancelled' => 'stop',
    ];

    /** Nhãn nút cho từng bước kế tiếp — hiện đúng việc nhân viên sắp làm. */
    public const STATUS_ACTIONS = [
        'approved' => 'Duyệt yêu cầu',
        'received' => 'Đã nhận hàng',
        'refunded' => 'Đã hoàn tiền',
        'rejected' => 'Từ chối',
        'cancelled' => 'Huỷ phiếu',
    ];

    public const REASONS = [
        'defective' => 'Sản phẩm lỗi/hỏng',
        'wrong_item' => 'Giao sai sản phẩm',
        'wrong_size' => 'Sai size',
        'not_as_described' => 'Không giống mô tả',
        'changed_mind' => 'Đổi ý, không có nhu cầu',
        'other' => 'Lý do khác',
    ];

    public const REFUND_METHODS = [
        'bank_transfer' => 'Chuyển khoản',
        'cash' => 'Tiền mặt',
        'ewallet' => 'Ví điện tử',
        'none' => 'Không hoàn tiền (đổi hàng)',
    ];

    public const SORTS = [
        'newest' => 'Mới nhất', 'oldest' => 'Cũ nhất',
        'amount_desc' => 'Tiền hoàn cao nhất', 'amount_asc' => 'Tiền hoàn thấp nhất',
    ];

    /** Trả hàng chỉ có MỘT trang: mọi phiếu nằm chung một bảng, lọc theo trạng thái
     *  ngay trên đó. Không tách thành nhiều khung nhìn — số phiếu ít hơn đơn hàng
     *  rất nhiều, chia nhỏ chỉ khiến nhân viên phải nhớ phiếu đang nằm ở mục nào. */
    public const TITLE = 'Trả hàng';

    public const EMPTY_TEXT = 'Chưa có phiếu trả hàng nào. Phiếu sẽ xuất hiện khi khách gửi yêu cầu trên website hoặc nhân viên lập tại quầy.';

    public const PAGE_SIZES = [20, 50, 100];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $returns = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->returns($filters);
            if ($res->successful()) {
                $returns = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load returns failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách phiếu trả hàng.';
            }
        } catch (\Throwable $e) {
            Log::error('Load returns failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách phiếu trả hàng. Kiểm tra kết nối API.';
        }

        $view = view('returns.index', compact('returns', 'filters', 'meta'))->with('stats', $this->stats());

        return $error ? $view->with('error', $error) : $view;
    }

    /** JSON: chi tiết phiếu cho khối xem nhanh. */
    public function detail(int $id)
    {
        try {
            $res = $this->api->orderReturn($id);
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data')]);
            }
        } catch (\Throwable $e) {
            Log::error('Load return detail failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không tải được chi tiết phiếu trả hàng.'], 404);
    }

    /** JSON: tìm đơn hàng ĐÃ GIAO để lập phiếu trả. */
    public function searchOrders(Request $request)
    {
        $keyword = trim((string) $request->query('q', ''));
        $list = [];
        try {
            // Chỉ đơn đã giao / hoàn tất mới trả hàng được — lọc ngay ở đây để nhân
            // viên không chọn phải đơn rồi mới bị API từ chối.
            $res = $this->api->orders([
                'keyword' => $keyword,
                'status' => 'delivered,completed',
                'page_size' => 10,
            ]);
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Search orders for return failed', ['msg' => $e->getMessage()]);
        }

        return response()->json(['data' => array_map(fn ($o) => [
            'id' => $o['id'] ?? 0,
            'code' => $o['order_code'] ?? '',
            'name' => $o['recipient_name'] ?? '',
            'phone' => $o['recipient_phone'] ?? '',
            'total' => (float) ($o['total_amount'] ?? 0),
        ], $list)]);
    }

    /** JSON: các dòng hàng của một đơn kèm số còn trả được. */
    public function returnable(int $orderId)
    {
        try {
            $res = $this->api->returnableItems($orderId);
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data')]);
            }

            return response()->json(['message' => $res->json('message') ?: 'Không tải được sản phẩm của đơn.'], 422);
        } catch (\Throwable $e) {
            Log::error('Load returnable items failed', ['order' => $orderId, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không kết nối được API.'], 500);
    }

    /** Nhân viên lập phiếu trả hàng tại quầy. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|integer|min:1',
            'reason' => 'required|in:'.implode(',', array_keys(self::REASONS)),
            'reason_note' => 'nullable|string|max:500',
            'refund_method' => 'nullable|in:'.implode(',', array_keys(self::REFUND_METHODS)),
            'bank_account' => 'nullable|string|max:50',
            'bank_holder' => 'nullable|string|max:150',
            'bank_name' => 'nullable|string|max:150',
            'shipping_fee' => 'nullable|numeric|min:0',
            'deduction' => 'nullable|numeric|min:0',
            'restock' => 'nullable|boolean',
            'admin_note' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|integer|min:1',
            // min:0 chứ không phải min:1 — form gửi lên MỌI dòng còn trả được của
            // đơn, dòng nhân viên không chọn có số lượng 0 và bị lọc ngay dưới đây.
            'items.*.quantity' => 'required|integer|min:0',
        ]);

        $items = array_values(array_filter(
            array_map(fn ($it) => [
                'order_item_id' => (int) $it['order_item_id'],
                'quantity' => (int) $it['quantity'],
            ], $data['items']),
            fn ($it) => $it['quantity'] > 0
        ));

        if (empty($items)) {
            return $this->backToList($request)->with('error', 'Vui lòng chọn ít nhất một sản phẩm để trả.');
        }

        $payload = [
            'order_id' => (int) $data['order_id'],
            'reason' => $data['reason'],
            'reason_note' => $data['reason_note'] ?? '',
            'refund_method' => $data['refund_method'] ?? 'bank_transfer',
            'bank_account' => $data['bank_account'] ?? '',
            'bank_holder' => $data['bank_holder'] ?? '',
            'bank_name' => $data['bank_name'] ?? '',
            'shipping_fee' => (float) ($data['shipping_fee'] ?? 0),
            'deduction' => (float) ($data['deduction'] ?? 0),
            'restock' => (bool) ($data['restock'] ?? true),
            'admin_note' => $data['admin_note'] ?? '',
            'items' => $items,
        ];

        return $this->send(
            fn () => $this->api->createReturn($payload),
            'Đã lập phiếu trả hàng.',
            $request
        );
    }

    /** Chuyển trạng thái hàng loạt: đẩy các phiếu đã chọn sang bước kế tiếp.
     *  Không nhận "rejected"/"cancelled" vì hai thao tác này bắt buộc có lý do —
     *  phải làm trên từng phiếu. Phiếu không hợp luồng được bỏ qua, không tính lỗi. */
    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'status' => 'required|in:approved,received,refunded',
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|min:1',
        ]);

        $target = $data['status'];
        $label = self::STATUSES[$target] ?? $target;

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        foreach (array_unique($data['ids']) as $id) {
            try {
                $res = $this->api->updateReturnStatus((int) $id, $target, '');
                if ($res->successful()) {
                    $ok++;
                } elseif (in_array($res->status(), [409, 422], true)) {
                    // Phiếu không ở trạng thái cho phép chuyển tới đích này — bỏ qua.
                    $skipped++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::error('Bulk return status failed', ['id' => $id, 'status' => $target, 'msg' => $e->getMessage()]);
                $failed++;
            }
        }

        $parts = [];
        if ($ok > 0) { $parts[] = "{$ok} phiếu đã chuyển sang \"{$label}\""; }
        if ($skipped > 0) { $parts[] = "bỏ qua {$skipped} phiếu không hợp luồng"; }
        if ($failed > 0) { $parts[] = "{$failed} phiếu lỗi"; }
        $msg = empty($parts) ? 'Không có phiếu nào được xử lý.' : implode(', ', $parts).'.';

        $redirect = $this->backToList($request);

        return $failed > 0 && $ok === 0
            ? $redirect->with('error', $msg)
            : $redirect->with('success', $msg);
    }

    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => 'required|in:approved,received,refunded,rejected,cancelled',
            'note' => 'nullable|string|max:255',
        ]);

        if ($data['status'] === 'rejected' && trim((string) ($data['note'] ?? '')) === '') {
            return $this->backToList($request)->with('error', 'Vui lòng nhập lý do từ chối yêu cầu trả hàng.');
        }

        return $this->send(
            fn () => $this->api->updateReturnStatus($id, $data['status'], (string) ($data['note'] ?? '')),
            'Đã chuyển phiếu sang "'.self::STATUSES[$data['status']].'".',
            $request
        );
    }

    public function settle(Request $request, int $id)
    {
        $data = $request->validate([
            'shipping_fee' => 'nullable|numeric|min:0',
            'deduction' => 'nullable|numeric|min:0',
            'refund_method' => 'nullable|in:'.implode(',', array_keys(self::REFUND_METHODS)),
            'bank_account' => 'nullable|string|max:50',
            'bank_holder' => 'nullable|string|max:150',
            'bank_name' => 'nullable|string|max:150',
            'restock' => 'nullable|boolean',
        ]);

        return $this->send(
            fn () => $this->api->settleReturn($id, [
                'shipping_fee' => (float) ($data['shipping_fee'] ?? 0),
                'deduction' => (float) ($data['deduction'] ?? 0),
                'refund_method' => $data['refund_method'] ?? '',
                'bank_account' => $data['bank_account'] ?? '',
                'bank_holder' => $data['bank_holder'] ?? '',
                'bank_name' => $data['bank_name'] ?? '',
                'restock' => (bool) ($data['restock'] ?? true),
            ]),
            'Đã chốt số tiền hoàn.',
            $request
        );
    }

    public function updateNote(Request $request, int $id)
    {
        $data = $request->validate(['admin_note' => 'nullable|string|max:500']);

        return $this->send(
            fn () => $this->api->updateReturnNote($id, (string) ($data['admin_note'] ?? '')),
            'Đã lưu ghi chú cho phiếu trả hàng.',
            $request
        );
    }

    public function export(Request $request)
    {
        // Có ?ids=... thì chỉ xuất các phiếu được chọn trên bảng; không thì xuất
        // theo bộ lọc đang áp dụng.
        $rows = $request->query('ids', '') !== ''
            ? $this->fetchByIds($request)
            : $this->fetchAll($this->filters($request));
        $fileName = 'tra-hang-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Mã phiếu', 'Ngày tạo', 'Mã đơn', 'Người nhận', 'Lý do', 'Số sản phẩm', 'Tiền hàng', 'Phí ship hoàn', 'Khấu trừ', 'Tiền hoàn', 'Hình thức hoàn', 'Trạng thái']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['return_code'] ?? '',
                    ! empty($r['created_at']) ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('d/m/Y H:i') : '',
                    data_get($r, 'order.order_code', ''),
                    data_get($r, 'order.recipient_name', ''),
                    self::REASONS[$r['reason'] ?? ''] ?? ($r['reason'] ?? ''),
                    collect($r['items'] ?? [])->sum('quantity'),
                    (float) ($r['items_amount'] ?? 0),
                    (float) ($r['shipping_fee'] ?? 0),
                    (float) ($r['deduction'] ?? 0),
                    (float) ($r['refund_amount'] ?? 0),
                    self::REFUND_METHODS[$r['refund_method'] ?? ''] ?? ($r['refund_method'] ?? ''),
                    self::STATUSES[$r['status'] ?? ''] ?? ($r['status'] ?? ''),
                ]);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------- Helpers ----------

    /** Đọc các phiếu theo ?ids=1,2,3 — giữ đúng thứ tự người dùng đã chọn. */
    protected function fetchByIds(Request $request): array
    {
        $ids = [];
        foreach (explode(',', (string) $request->query('ids', '')) as $part) {
            $n = (int) trim($part);
            if ($n > 0 && ! in_array($n, $ids, true)) {
                $ids[] = $n;
            }
        }
        $ids = array_slice($ids, 0, 200); // chặn xuất quá nhiều một lúc

        $rows = [];
        foreach ($ids as $id) {
            try {
                $res = $this->api->orderReturn($id);
                if ($res->successful() && ($r = $res->json('data'))) {
                    $rows[] = $r;
                }
            } catch (\Throwable $e) {
                Log::error('Load return for export failed', ['id' => $id, 'msg' => $e->getMessage()]);
            }
        }

        return $rows;
    }

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $reason = (string) $request->query('reason', 'all');
        $sort = (string) $request->query('sort', 'newest');
        $psize = (int) $request->query('page_size', 20);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'reason' => isset(self::REASONS[$reason]) ? $reason : 'all',
            'from_date' => $date($request->query('from_date')),
            'to_date' => $date($request->query('to_date')),
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'newest',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($psize, self::PAGE_SIZES, true) ? $psize : 20,
        ];
    }

    protected function stats(): array
    {
        $stats = [
            'total' => 0, 'pending' => 0, 'approved' => 0, 'received' => 0,
            'refunded' => 0, 'rejected' => 0, 'cancelled' => 0, 'refunded_amount' => 0,
        ];
        try {
            $res = $this->api->returnStats();
            if ($res->successful()) {
                $stats = array_merge($stats, $res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::info('Load return stats failed', ['msg' => $e->getMessage()]);
        }

        return $stats;
    }

    protected function fetchAll(array $filters): array
    {
        $all = [];
        $query = array_merge($filters, ['page' => 1, 'page_size' => 100]);
        $totalPages = 1;
        try {
            do {
                $res = $this->api->returns($query);
                if (! $res->successful()) {
                    break;
                }
                $all = array_merge($all, $res->json('data') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $totalPages && $query['page'] <= 100);
        } catch (\Throwable $e) {
            Log::error('Export returns failed', ['msg' => $e->getMessage()]);
        }

        return $all;
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Return API call failed', ['msg' => $e->getMessage()]);

            return $this->backToList($request)->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return $this->backToList($request)->with('success', $success);
        }

        return $this->backToList($request)->with('error', $res->json('message') ?: 'Thao tác không thành công.');
    }

    protected function backToList(Request $request)
    {
        $return = $request->input('return');
        if (is_string($return) && str_starts_with($return, '/')) {
            return redirect($return);
        }

        return redirect()->route('admin.returns.index', $request->query());
    }
}
