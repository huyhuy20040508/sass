<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PurchaseController — trang "Đặt hàng nhập" của khu quản trị.
 *
 * Đây là chiều MUA VÀO của kho: cửa hàng đặt hàng từ nhà cung cấp, hàng về thì
 * nhận theo từng đợt và tồn kho chỉ được cộng đúng lúc bấm nhận hàng. Toàn bộ
 * luật (sửa được tới lúc nào, nhận bao nhiêu là vượt, giá vốn có ghi đè không)
 * do Go API giữ — controller này chỉ chuyển tiếp và dịch câu chữ sang tiếng Việt.
 */
class PurchaseController extends Controller
{
    public const STATUSES = [
        'draft' => 'Nháp',
        'ordered' => 'Đã đặt hàng',
        'partial' => 'Nhận một phần',
        'received' => 'Đã nhận đủ',
        'cancelled' => 'Đã huỷ',
    ];

    /** Tông màu badge — dùng chung bảng màu với trang Đơn hàng / Trả hàng. */
    public const STATUS_TONES = [
        'draft' => 'wait', 'ordered' => 'info', 'partial' => 'move',
        'received' => 'done', 'cancelled' => 'stop',
    ];

    /** Nhãn nút cho từng bước kế tiếp — hiện đúng việc nhân viên sắp làm. */
    public const STATUS_ACTIONS = [
        'ordered' => 'Gửi nhà cung cấp',
        'cancelled' => 'Huỷ phiếu',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid' => 'Chưa trả',
        'partial' => 'Trả một phần',
        'paid' => 'Đã trả đủ',
    ];

    public const PAYMENT_TONES = [
        'unpaid' => 'stop', 'partial' => 'wait', 'paid' => 'done',
    ];

    public const SORTS = [
        'newest' => 'Mới nhất',
        'oldest' => 'Cũ nhất',
        'expected_asc' => 'Hẹn giao sớm nhất',
        'total_desc' => 'Giá trị cao nhất',
        'total_asc' => 'Giá trị thấp nhất',
    ];

    public const TITLE = 'Đặt hàng nhập';

    public const EMPTY_TEXT = 'Chưa có phiếu đặt hàng nào. Bấm "Lập phiếu đặt hàng" để đặt hàng từ nhà cung cấp — hàng chỉ vào kho khi bạn xác nhận đã nhận.';

    public const PAGE_SIZES = [20, 50, 100];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $purchases = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->purchases($filters);
            if ($res->successful()) {
                $purchases = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load purchases failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách phiếu đặt hàng.';
            }
        } catch (\Throwable $e) {
            Log::error('Load purchases failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách phiếu đặt hàng. Kiểm tra kết nối API.';
        }

        $view = view('purchases.index', compact('purchases', 'filters', 'meta'))
            ->with('stats', $this->stats());

        return $error ? $view->with('error', $error) : $view;
    }

    /** JSON: chi tiết phiếu cho khối xem nhanh / form sửa / form nhận hàng. */
    public function detail(int $id)
    {
        try {
            $res = $this->api->purchase($id);
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data')]);
            }
        } catch (\Throwable $e) {
            Log::error('Load purchase detail failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không tải được chi tiết phiếu đặt hàng.'], 404);
    }

    /** JSON: tìm biến thể để thêm vào phiếu (kèm tồn kho + giá vốn gợi ý). */
    public function searchVariants(Request $request)
    {
        $list = [];
        try {
            $res = $this->api->purchaseVariants(trim((string) $request->query('q', '')), 20);
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Search variants for purchase failed', ['msg' => $e->getMessage()]);
        }

        return response()->json(['data' => $list]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request, [
            // Lập phiếu xong bấm "Lưu nháp" hay "Đặt hàng ngay" — hai nút, một form.
            'status' => 'nullable|in:draft,ordered',
        ]);

        $payload = $this->payloadFrom($data);
        $payload['status'] = $data['status'] ?? 'draft';

        return $this->send(
            fn () => $this->api->createPurchase($payload),
            $payload['status'] === 'ordered'
                ? 'Đã lập phiếu và gửi cho nhà cung cấp.'
                : 'Đã lưu phiếu đặt hàng nháp.',
            $request
        );
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validatePayload($request);

        return $this->send(
            fn () => $this->api->updatePurchase($id, $this->payloadFrom($data)),
            'Đã cập nhật phiếu đặt hàng.',
            $request
        );
    }

    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => 'required|in:ordered,cancelled',
            'note' => 'nullable|string|max:255',
        ]);

        if ($data['status'] === 'cancelled' && trim((string) ($data['note'] ?? '')) === '') {
            return $this->backToList($request)->with('error', 'Vui lòng nhập lý do huỷ phiếu đặt hàng.');
        }

        return $this->send(
            fn () => $this->api->updatePurchaseStatus($id, $data['status'], (string) ($data['note'] ?? '')),
            'Đã chuyển phiếu sang "'.self::STATUSES[$data['status']].'".',
            $request
        );
    }

    /** Nhận MỘT đợt hàng về kho. */
    public function receive(Request $request, int $id)
    {
        $data = $request->validate([
            'update_cost' => 'nullable|boolean',
            'note' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|min:1',
            // min:0 chứ không phải min:1 — form gửi lên MỌI dòng của phiếu, dòng đợt
            // này chưa về có số 0 và bị lọc ngay dưới đây.
            'items.*.quantity' => 'required|integer|min:0',
        ]);

        $items = array_values(array_filter(
            array_map(fn ($it) => [
                'item_id' => (int) $it['item_id'],
                'quantity' => (int) $it['quantity'],
            ], $data['items']),
            fn ($it) => $it['quantity'] > 0
        ));

        if (empty($items)) {
            return $this->backToList($request)->with('error', 'Vui lòng nhập số lượng nhận cho ít nhất một sản phẩm.');
        }

        return $this->send(
            fn () => $this->api->receivePurchase($id, [
                'items' => $items,
                'update_cost' => (bool) ($data['update_cost'] ?? true),
                'note' => (string) ($data['note'] ?? ''),
            ]),
            'Đã nhận hàng và cộng vào tồn kho.',
            $request
        );
    }

    public function payment(Request $request, int $id)
    {
        $data = $request->validate(['paid_amount' => 'required|numeric|min:0']);

        return $this->send(
            fn () => $this->api->updatePurchasePayment($id, (float) $data['paid_amount']),
            'Đã cập nhật thanh toán cho nhà cung cấp.',
            $request
        );
    }

    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->deletePurchase($id),
            'Đã xoá phiếu đặt hàng nháp.',
            $request
        );
    }

    /** Gửi hàng loạt các phiếu nháp đã chọn cho nhà cung cấp.
     *  Không nhận "cancelled" vì huỷ bắt buộc có lý do — phải làm trên từng phiếu.
     *  Phiếu không hợp luồng được bỏ qua, không tính lỗi. */
    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'status' => 'required|in:ordered',
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|min:1',
        ]);

        $label = self::STATUSES[$data['status']];
        $ok = 0;
        $skipped = 0;
        $failed = 0;
        foreach (array_unique($data['ids']) as $id) {
            try {
                $res = $this->api->updatePurchaseStatus((int) $id, $data['status'], '');
                if ($res->successful()) {
                    $ok++;
                } elseif (in_array($res->status(), [409, 422], true)) {
                    $skipped++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::error('Bulk purchase status failed', ['id' => $id, 'msg' => $e->getMessage()]);
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

    public function export(Request $request)
    {
        // Có ?ids=... thì chỉ xuất các phiếu được chọn trên bảng; không thì xuất
        // theo bộ lọc đang áp dụng.
        $rows = $request->query('ids', '') !== ''
            ? $this->fetchByIds($request)
            : $this->fetchAll($this->filters($request));
        $fileName = 'dat-hang-nhap-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Mã phiếu', 'Ngày lập', 'Nhà cung cấp', 'Hẹn giao', 'Số mặt hàng', 'SL đặt', 'SL đã nhận',
                'Tiền hàng', 'Chiết khấu', 'Vận chuyển', 'Tổng tiền', 'Đã trả', 'Thanh toán', 'Trạng thái']);
            foreach ($rows as $r) {
                $items = collect($r['items'] ?? []);
                fputcsv($out, [
                    $r['po_code'] ?? '',
                    ! empty($r['created_at']) ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('d/m/Y H:i') : '',
                    $r['supplier_name'] ?? '',
                    ! empty($r['expected_date']) ? \Illuminate\Support\Carbon::parse($r['expected_date'])->format('d/m/Y') : '',
                    $items->count(),
                    $items->sum('quantity'),
                    $items->sum('received_quantity'),
                    (float) ($r['items_amount'] ?? 0),
                    (float) ($r['discount_amount'] ?? 0),
                    (float) ($r['shipping_fee'] ?? 0),
                    (float) ($r['total_amount'] ?? 0),
                    (float) ($r['paid_amount'] ?? 0),
                    self::PAYMENT_STATUSES[$r['payment_status'] ?? ''] ?? ($r['payment_status'] ?? ''),
                    self::STATUSES[$r['status'] ?? ''] ?? ($r['status'] ?? ''),
                ]);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------- Helpers ----------

    /** Luật chung cho form lập & sửa phiếu; $extra là các luật riêng của từng luồng. */
    protected function validatePayload(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'supplier_name' => 'required|string|max:150',
            'expected_date' => 'nullable|date_format:Y-m-d',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_fee' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ], $extra));
    }

    protected function payloadFrom(array $data): array
    {
        return [
            'supplier_name' => trim((string) $data['supplier_name']),
            'expected_date' => (string) ($data['expected_date'] ?? ''),
            'discount_amount' => (float) ($data['discount_amount'] ?? 0),
            'shipping_fee' => (float) ($data['shipping_fee'] ?? 0),
            'note' => (string) ($data['note'] ?? ''),
            'items' => array_values(array_map(fn ($it) => [
                'variant_id' => (int) $it['variant_id'],
                'quantity' => (int) $it['quantity'],
                'unit_cost' => (float) $it['unit_cost'],
            ], $data['items'])),
        ];
    }

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $payment = (string) $request->query('payment_status', 'all');
        $sort = (string) $request->query('sort', 'newest');
        $psize = (int) $request->query('page_size', 20);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'payment_status' => isset(self::PAYMENT_STATUSES[$payment]) ? $payment : 'all',
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
            'total' => 0, 'draft' => 0, 'ordered' => 0, 'partial' => 0, 'received' => 0,
            'cancelled' => 0, 'incoming_quantity' => 0, 'incoming_value' => 0, 'debt_amount' => 0,
        ];
        try {
            $res = $this->api->purchaseStats();
            if ($res->successful()) {
                $stats = array_merge($stats, $res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::info('Load purchase stats failed', ['msg' => $e->getMessage()]);
        }

        return $stats;
    }

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
                $res = $this->api->purchase($id);
                if ($res->successful() && ($r = $res->json('data'))) {
                    $rows[] = $r;
                }
            } catch (\Throwable $e) {
                Log::error('Load purchase for export failed', ['id' => $id, 'msg' => $e->getMessage()]);
            }
        }

        return $rows;
    }

    protected function fetchAll(array $filters): array
    {
        $all = [];
        $query = array_merge($filters, ['page' => 1, 'page_size' => 100]);
        $totalPages = 1;
        try {
            do {
                $res = $this->api->purchases($query);
                if (! $res->successful()) {
                    break;
                }
                $all = array_merge($all, $res->json('data') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $totalPages && $query['page'] <= 100);
        } catch (\Throwable $e) {
            Log::error('Export purchases failed', ['msg' => $e->getMessage()]);
        }

        return $all;
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Purchase API call failed', ['msg' => $e->getMessage()]);

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

        return redirect()->route('admin.purchases.index', $request->query());
    }
}
