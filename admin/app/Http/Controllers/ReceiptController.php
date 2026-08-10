<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ReceiptController — trang "Nhập hàng" của khu quản trị.
 *
 * Trang này là SỔ HÀNG VỀ KHO: mỗi lần bấm "Nhận hàng" trên một phiếu đặt là một
 * ĐỢT nhập, và đây là chỗ tra lại đợt nào về lúc nào, ai nhận, bao nhiêu hàng,
 * giá trị bao nhiêu.
 *
 * Việc nhập hàng vẫn đi qua đúng một đường: POST /admin/purchases/{id}/receive
 * (PurchaseController::receive) — chỗ duy nhất được cộng tồn kho. Form nhận hàng
 * trên trang này chỉ là một lối vào khác của cùng luồng đó, nên không có nguy cơ
 * hai đường ghi kho lệch nhau.
 */
class ReceiptController extends Controller
{
    public const TITLE = 'Nhập hàng';

    public const SORTS = [
        'newest' => 'Mới nhập nhất',
        'oldest' => 'Cũ nhất',
        'qty_desc' => 'Nhiều hàng nhất',
        'amount_desc' => 'Giá trị cao nhất',
    ];

    public const PAGE_SIZES = [20, 50, 100];

    public const EMPTY_TEXT = 'Chưa có đợt nhập hàng nào. Bấm "Nhận hàng" để ghi nhận hàng của một phiếu đặt đã về kho — tồn kho chỉ tăng khi bạn xác nhận ở đây.';

    /** Trạng thái phiếu đặt còn hàng chưa về — nhóm phiếu chờ nhập. */
    public const PENDING_STATUSES = 'ordered,partial';

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $receipts = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->receipts($filters);
            if ($res->successful()) {
                $receipts = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load receipts failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách đợt nhập hàng.';
            }
        } catch (\Throwable $e) {
            Log::error('Load receipts failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách đợt nhập hàng. Kiểm tra kết nối API.';
        }

        $view = view('receipts.index', compact('receipts', 'filters', 'meta'))
            ->with('stats', $this->stats())
            ->with('suppliers', $this->supplierList())
            ->with('pending', $this->pendingOrders());

        return $error ? $view->with('error', $error) : $view;
    }

    /** JSON: chi tiết một đợt nhập (dòng hàng + tồn trước/sau) cho modal xem nhanh. */
    public function detail(string $code)
    {
        try {
            $res = $this->api->receipt($code);
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data')]);
            }
        } catch (\Throwable $e) {
            Log::error('Load receipt detail failed', ['code' => $code, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không tải được chi tiết đợt nhập hàng.'], 404);
    }

    /** Xuất sổ nhập hàng theo đúng bộ lọc đang xem. */
    public function export(Request $request)
    {
        $rows = $this->fetchAll($this->filters($request));
        $fileName = 'nhap-hang-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Mã đợt nhập', 'Thời điểm nhận', 'Phiếu đặt', 'Nhà cung cấp',
                'Số mặt hàng', 'SL nhận', 'Giá trị nhập', 'Người nhận', 'Ghi chú']);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['code'] ?? '',
                    ! empty($r['received_at']) ? Carbon::parse($r['received_at'])->format('d/m/Y H:i') : '',
                    $r['po_code'] ?? '',
                    $r['supplier_name'] ?? '',
                    (int) ($r['line_count'] ?? 0),
                    (int) ($r['quantity'] ?? 0),
                    (float) ($r['amount'] ?? 0),
                    $r['created_by_name'] ?? '',
                    $r['note'] ?? '',
                ]);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------- Helpers ----------

    protected function filters(Request $request): array
    {
        $sort = (string) $request->query('sort', 'newest');
        $psize = (int) $request->query('page_size', 20);
        $supplier = (int) $request->query('supplier_id', 0);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'supplier_id' => $supplier > 0 ? $supplier : '',
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
            'total_receipts' => 0, 'total_quantity' => 0, 'total_amount' => 0,
            'today_receipts' => 0, 'today_quantity' => 0,
            'pending_orders' => 0, 'pending_quantity' => 0,
        ];
        try {
            $res = $this->api->receiptStats();
            if ($res->successful()) {
                $stats = array_merge($stats, $res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::info('Load receipt stats failed', ['msg' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * Phiếu đặt còn hàng chưa về, kèm số còn thiếu của từng dòng — form nhận hàng
     * đọc trực tiếp từ đây nên không phải gọi thêm API lúc chọn phiếu.
     */
    protected function pendingOrders(): array
    {
        try {
            $res = $this->api->purchases([
                'status' => self::PENDING_STATUSES,
                'sort' => 'expected_asc',
                'page' => 1,
                'page_size' => 100,
            ]);
            if (! $res->successful()) {
                return [];
            }

            $out = [];
            foreach ($res->json('data') ?? [] as $po) {
                $items = [];
                foreach ($po['items'] ?? [] as $it) {
                    $remain = (int) ($it['quantity'] ?? 0) - (int) ($it['received_quantity'] ?? 0);
                    if ($remain <= 0) {
                        continue; // dòng đã về đủ, không cần hiện trong form nhận
                    }
                    $items[] = [
                        'item_id' => (int) ($it['id'] ?? 0),
                        'product_name' => (string) ($it['product_name'] ?? ''),
                        'variant_sku' => (string) ($it['variant_sku'] ?? ''),
                        'size' => (string) ($it['size'] ?? ''),
                        'color' => (string) ($it['color'] ?? ''),
                        'quantity' => (int) ($it['quantity'] ?? 0),
                        'received_quantity' => (int) ($it['received_quantity'] ?? 0),
                        'remain' => $remain,
                        'unit_cost' => (float) ($it['unit_cost'] ?? 0),
                    ];
                }
                if (empty($items)) {
                    continue; // phiếu đã về đủ hàng nhưng chưa kịp đổi trạng thái
                }
                $out[] = [
                    'id' => (int) ($po['id'] ?? 0),
                    'po_code' => (string) ($po['po_code'] ?? ''),
                    'supplier_name' => (string) ($po['supplier_name'] ?? ''),
                    'status' => (string) ($po['status'] ?? ''),
                    'expected_date' => $po['expected_date'] ?? null,
                    'remain' => array_sum(array_column($items, 'remain')),
                    'items' => $items,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::info('Load pending purchases failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Danh sách nhà cung cấp cho ô lọc. */
    protected function supplierList(): array
    {
        try {
            $res = $this->api->suppliers();
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Load suppliers failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /** Đọc hết các trang theo bộ lọc để xuất file. */
    protected function fetchAll(array $filters): array
    {
        $all = [];
        $query = array_merge($filters, ['page' => 1, 'page_size' => 100]);
        $totalPages = 1;
        try {
            do {
                $res = $this->api->receipts($query);
                if (! $res->successful()) {
                    break;
                }
                $all = array_merge($all, $res->json('data') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $totalPages && $query['page'] <= 100);
        } catch (\Throwable $e) {
            Log::error('Export receipts failed', ['msg' => $e->getMessage()]);
        }

        return $all;
    }
}
