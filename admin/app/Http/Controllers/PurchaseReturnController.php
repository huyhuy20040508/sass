<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * PurchaseReturnController — trang "Trả hàng nhập" của khu quản trị.
 *
 * Chiều NGƯỢC của nhập hàng: hàng đã nhận về nhưng lỗi/sai/thừa thì trả lại nhà
 * cung cấp. Luồng: lập phiếu nháp → "Đã trả NCC" (TRỪ tồn kho đúng lúc này) →
 * "NCC đã hoàn tiền".
 *
 * Toàn bộ luật (trả được bao nhiêu, sửa/huỷ tới lúc nào, kho đủ hàng không) do Go
 * API giữ — controller này chỉ chuyển tiếp và dịch câu chữ sang tiếng Việt.
 */
class PurchaseReturnController extends Controller
{
    public const TITLE = 'Trả hàng nhập';

    public const STATUSES = [
        'draft' => 'Nháp',
        'returned' => 'Đã trả NCC',
        'refunded' => 'NCC đã hoàn tiền',
        'cancelled' => 'Đã huỷ',
    ];

    /** Tông màu badge — dùng chung bảng màu với các trang phiếu khác. */
    public const STATUS_TONES = [
        'draft' => 'wait', 'returned' => 'move', 'refunded' => 'done', 'cancelled' => 'stop',
    ];

    /** Nhãn nút cho từng bước kế tiếp — hiện đúng việc nhân viên sắp làm. */
    public const STATUS_ACTIONS = [
        'returned' => 'Đã trả cho NCC',
        'refunded' => 'NCC đã hoàn tiền',
        'cancelled' => 'Huỷ phiếu',
    ];

    public const REFUND_STATUSES = [
        'unpaid' => 'Chưa hoàn',
        'partial' => 'Hoàn một phần',
        'paid' => 'Đã hoàn đủ',
    ];

    public const REFUND_TONES = [
        'unpaid' => 'stop', 'partial' => 'wait', 'paid' => 'done',
    ];

    public const REASONS = [
        'defect' => 'Hàng lỗi / hỏng',
        'wrong_item' => 'Giao sai mẫu / sai size',
        'over_stock' => 'Giao vượt số đặt',
        'expired' => 'Hàng cũ, quá mùa',
        'other' => 'Lý do khác',
    ];

    public const SORTS = [
        'newest' => 'Mới nhất',
        'oldest' => 'Cũ nhất',
        'amount_desc' => 'Giá trị cao nhất',
        'amount_asc' => 'Giá trị thấp nhất',
    ];

    public const PAGE_SIZES = [20, 50, 100];

    public const EMPTY_TEXT = 'Chưa có phiếu trả hàng nhập nào. Bấm "Lập phiếu trả hàng" khi hàng nhà cung cấp giao bị lỗi, sai mẫu hoặc thừa — tồn kho chỉ giảm khi bạn xác nhận đã trả.';

    /** Phiếu đặt đã có hàng về mới trả lại được. */
    public const RECEIVED_STATUSES = 'partial,received';

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $returns = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->purchaseReturns($filters);
            if ($res->successful()) {
                $returns = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load purchase returns failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách phiếu trả hàng nhập.';
            }
        } catch (\Throwable $e) {
            Log::error('Load purchase returns failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách phiếu trả hàng nhập. Kiểm tra kết nối API.';
        }

        $view = view('purchase-returns.index', compact('returns', 'filters', 'meta'))
            ->with('stats', $this->stats())
            ->with('sources', $this->receivedOrders());

        return $error ? $view->with('error', $error) : $view;
    }

    /** JSON: chi tiết phiếu trả cho modal xem nhanh / form sửa. */
    public function detail(int $id)
    {
        try {
            $res = $this->api->purchaseReturn($id);
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data')]);
            }
        } catch (\Throwable $e) {
            Log::error('Load purchase return detail failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không tải được chi tiết phiếu trả hàng.'], 404);
    }

    /** JSON: các dòng của phiếu đặt còn trả lại được (form lập phiếu đọc từ đây). */
    public function returnable(int $purchaseId)
    {
        try {
            $res = $this->api->purchaseReturnable($purchaseId);
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data') ?? []]);
            }
        } catch (\Throwable $e) {
            Log::info('Load returnable items failed', ['id' => $purchaseId, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không tải được danh sách hàng còn trả được.'], 404);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request, [
            // Lập xong bấm "Lưu nháp" hay "Trả hàng ngay" — hai nút, một form.
            'status' => 'nullable|in:draft,returned',
        ]);

        $payload = $this->payloadFrom($data);
        $payload['status'] = $data['status'] ?? 'draft';

        return $this->send(
            fn () => $this->api->createPurchaseReturn($payload),
            $payload['status'] === 'returned'
                ? 'Đã lập phiếu trả và trừ hàng khỏi tồn kho.'
                : 'Đã lưu phiếu trả hàng nháp.',
            $request
        );
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validatePayload($request);

        return $this->send(
            fn () => $this->api->updatePurchaseReturn($id, $this->payloadFrom($data)),
            'Đã cập nhật phiếu trả hàng.',
            $request
        );
    }

    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => 'required|in:returned,refunded,cancelled',
            'note' => 'nullable|string|max:255',
        ]);

        if ($data['status'] === 'cancelled' && trim((string) ($data['note'] ?? '')) === '') {
            return $this->backToList($request)->with('error', 'Vui lòng nhập lý do huỷ phiếu trả hàng.');
        }

        $messages = [
            'returned' => 'Đã trả hàng cho nhà cung cấp và trừ khỏi tồn kho.',
            'refunded' => 'Đã ghi nhận nhà cung cấp hoàn đủ tiền.',
            'cancelled' => 'Đã huỷ phiếu trả hàng.',
        ];

        return $this->send(
            fn () => $this->api->updatePurchaseReturnStatus($id, $data['status'], (string) ($data['note'] ?? '')),
            $messages[$data['status']],
            $request
        );
    }

    public function refund(Request $request, int $id)
    {
        $data = $request->validate(['refund_amount' => 'required|numeric|min:0']);

        return $this->send(
            fn () => $this->api->updatePurchaseReturnRefund($id, (float) $data['refund_amount']),
            'Đã cập nhật số tiền nhà cung cấp hoàn.',
            $request
        );
    }

    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->deletePurchaseReturn($id),
            'Đã xoá phiếu trả hàng nháp.',
            $request
        );
    }

    /** Xuất danh sách theo đúng bộ lọc đang xem. */
    public function export(Request $request)
    {
        $rows = $this->fetchAll($this->filters($request));
        $fileName = 'tra-hang-nhap-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Mã phiếu trả', 'Ngày lập', 'Phiếu đặt', 'Nhà cung cấp', 'Lý do',
                'Số mặt hàng', 'SL trả', 'Giá trị trả', 'NCC đã hoàn', 'Còn phải hoàn',
                'Tình trạng hoàn tiền', 'Trạng thái', 'Ngày trả', 'Ghi chú']);

            foreach ($rows as $r) {
                $items = collect($r['items'] ?? []);
                $amount = (float) ($r['items_amount'] ?? 0);
                $refund = (float) ($r['refund_amount'] ?? 0);
                fputcsv($out, [
                    $r['return_code'] ?? '',
                    ! empty($r['created_at']) ? Carbon::parse($r['created_at'])->format('d/m/Y H:i') : '',
                    $r['po_code'] ?? '',
                    $r['supplier_name'] ?? '',
                    self::REASONS[$r['reason'] ?? ''] ?? ($r['reason'] ?? ''),
                    $items->count(),
                    $items->sum('quantity'),
                    $amount,
                    $refund,
                    max($amount - $refund, 0),
                    self::REFUND_STATUSES[$r['refund_status'] ?? ''] ?? ($r['refund_status'] ?? ''),
                    self::STATUSES[$r['status'] ?? ''] ?? ($r['status'] ?? ''),
                    ! empty($r['returned_at']) ? Carbon::parse($r['returned_at'])->format('d/m/Y H:i') : '',
                    $r['note'] ?? '',
                ]);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------- Helpers ----------

    protected function validatePayload(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'purchase_order_id' => 'required|integer|min:1',
            'reason' => 'nullable|in:'.implode(',', array_keys(self::REASONS)),
            'note' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|integer|min:1',
            // min:0 chứ không phải min:1 — form gửi lên MỌI dòng còn trả được, dòng
            // không trả có số 0 và bị lọc ngay dưới đây.
            'items.*.quantity' => 'required|integer|min:0',
        ], $extra));
    }

    protected function payloadFrom(array $data): array
    {
        $items = array_values(array_filter(
            array_map(fn ($it) => [
                'purchase_order_item_id' => (int) $it['purchase_order_item_id'],
                'quantity' => (int) $it['quantity'],
            ], $data['items']),
            fn ($it) => $it['quantity'] > 0
        ));

        return [
            'purchase_order_id' => (int) $data['purchase_order_id'],
            'reason' => (string) ($data['reason'] ?? 'other'),
            'note' => (string) ($data['note'] ?? ''),
            'items' => $items,
        ];
    }

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $refund = (string) $request->query('refund_status', 'all');
        $reason = (string) $request->query('reason', 'all');
        $sort = (string) $request->query('sort', 'newest');
        $psize = (int) $request->query('page_size', 20);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'refund_status' => isset(self::REFUND_STATUSES[$refund]) ? $refund : 'all',
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
            'total' => 0, 'draft' => 0, 'returned' => 0, 'refunded' => 0, 'cancelled' => 0,
            'returned_quantity' => 0, 'returned_amount' => 0, 'pending_refund' => 0,
        ];
        try {
            $res = $this->api->purchaseReturnStats();
            if ($res->successful()) {
                $stats = array_merge($stats, $res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::info('Load purchase return stats failed', ['msg' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * Phiếu đặt ĐÃ CÓ HÀNG VỀ — nguồn để lập phiếu trả. Chỉ cần thông tin đầu phiếu;
     * dòng hàng còn trả được nạp riêng khi người dùng chọn phiếu (API tính chính xác
     * phần đã nằm trong các phiếu trả khác).
     */
    protected function receivedOrders(): array
    {
        try {
            $res = $this->api->purchases([
                'status' => self::RECEIVED_STATUSES,
                'sort' => 'newest',
                'page' => 1,
                'page_size' => 100,
            ]);
            if (! $res->successful()) {
                return [];
            }

            $out = [];
            foreach ($res->json('data') ?? [] as $po) {
                $received = 0;
                foreach ($po['items'] ?? [] as $it) {
                    $received += (int) ($it['received_quantity'] ?? 0);
                }
                if ($received <= 0) {
                    continue; // chưa nhận cái nào thì không có gì để trả
                }
                $out[] = [
                    'id' => (int) ($po['id'] ?? 0),
                    'po_code' => (string) ($po['po_code'] ?? ''),
                    'supplier_name' => (string) ($po['supplier_name'] ?? ''),
                    'status' => (string) ($po['status'] ?? ''),
                    'received' => $received,
                    'created_at' => $po['created_at'] ?? null,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::info('Load received purchases failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    protected function fetchAll(array $filters): array
    {
        $all = [];
        $query = array_merge($filters, ['page' => 1, 'page_size' => 100]);
        $totalPages = 1;
        try {
            do {
                $res = $this->api->purchaseReturns($query);
                if (! $res->successful()) {
                    break;
                }
                $all = array_merge($all, $res->json('data') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $totalPages && $query['page'] <= 100);
        } catch (\Throwable $e) {
            Log::error('Export purchase returns failed', ['msg' => $e->getMessage()]);
        }

        return $all;
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Purchase return API call failed', ['msg' => $e->getMessage()]);

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

        return redirect()->route('admin.purchase-returns.index', $request->query());
    }
}
