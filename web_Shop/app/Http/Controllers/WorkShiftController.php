<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiJsonReply;
use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Điều phối ca & sổ quỹ — đối chiếu TIỀN TRONG KÉT với SỔ.
 *
 * CHỈ TIỀN MẶT: chuyển khoản không đi qua két, gộp vào là con số đối chiếu
 * không còn khớp tiền đếm được.
 */
class WorkShiftController extends Controller
{
    use Concerns\V2ScreenFallback;

    use ApiJsonReply;

    public const TITLE = 'Điều phối ca';

    public const EMPTY_TEXT = 'Chưa có ca nào. Bấm Mở ca khi bắt đầu buổi bán — từ lúc đó mọi lượt thu chi tiền mặt được ghi vào sổ của ca.';

    public const PAGE_SIZES = [20, 50, 100];

    public const STATUSES = [
        'dang_mo' => 'Đang mở',
        'da_dong' => 'Đã đóng',
    ];

    public const DIRECTIONS = [
        'in' => 'Thu',
        'out' => 'Chi',
    ];

    /** Dịch cột reference_type của dòng sổ quỹ ra tiếng người. */
    public const SOURCES = [
        'order' => 'Bán hàng',
        'order_return' => 'Trả hàng',
        'manual' => 'Ghi tay',
    ];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        if ($ve = $this->veQuayNeuThieuView('v2::pos.work-shift')) {
            return $ve;
        }

        $filters = $this->filters($request);
        $list = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->caLamViec($filters);
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách ca.';
            }
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai danh sach ca that bai', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách ca. Kiểm tra kết nối API.';
        }

        $view = view('v2::pos.work-shift', compact('list', 'filters', 'meta'));

        return $error ? $view->with('error', $error) : $view;
    }

    /** Một ca kèm toàn bộ dòng sổ quỹ — màn dùng để truy một khoản chênh. */
    public function show(int $id)
    {
        if ($ve = $this->veQuayNeuThieuView('v2::pos.work-shift-detail')) {
            return $ve;
        }

        try {
            $res = $this->api->caChiTiet($id);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai chi tiet ca that bai', ['id' => $id, 'msg' => $e->getMessage()]);
            abort(404);
        }
        abort_unless($res->successful(), 404);

        return view('v2::pos.work-shift-detail', [
            'ca' => $res->json('data.ca') ?? [],
            'soQuy' => $res->json('data.so_quy') ?? [],
        ]);
    }

    /**
     * JSON: ca đang mở của chi nhánh đang làm việc.
     *
     * Hỏng thì trả null chứ không trả lỗi: quầy vẫn phải bán được khi cụm ca trục
     * trặc — ca là thứ ghi chép, không phải thứ gác cửa.
     */
    public function hienTai()
    {
        try {
            $res = $this->api->caHienTai();
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data')]);
            }
        } catch (\Throwable $e) {
            Log::info('Thu ngan: tai ca hien tai that bai', ['msg' => $e->getMessage()]);
        }

        return response()->json(['data' => null]);
    }

    public function moCa(Request $request)
    {
        $data = $request->validate([
            'opening_cash' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ], [
            'opening_cash.required' => 'Vui lòng đếm và nhập số tiền đang có trong két.',
        ]);

        return $this->jsonTuApi(fn () => $this->api->moCa([
            'opening_cash' => (float) $data['opening_cash'],
            'note' => trim((string) ($data['note'] ?? '')),
        ]), 'Không mở được ca.');
    }

    public function dongCa(Request $request)
    {
        $data = $request->validate([
            'counted_cash' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ], [
            'counted_cash.required' => 'Vui lòng đếm và nhập số tiền thực tế trong két.',
        ]);

        return $this->jsonTuApi(fn () => $this->api->dongCa([
            'counted_cash' => (float) $data['counted_cash'],
            'note' => trim((string) ($data['note'] ?? '')),
        ]), 'Không đóng được ca.');
    }

    public function ghiSoQuy(Request $request)
    {
        $data = $request->validate([
            'direction' => 'required|in:'.implode(',', array_keys(self::DIRECTIONS)),
            'amount' => 'required|numeric|gt:0',
            'reason' => 'required|string|max:255',
        ], [
            'amount.gt' => 'Số tiền phải lớn hơn 0.',
            'reason.required' => 'Vui lòng ghi lý do — một khoản ra khỏi két không có lý do thì đúng bằng mất tiền.',
        ]);

        return $this->jsonTuApi(fn () => $this->api->ghiSoQuy([
            'direction' => $data['direction'],
            'amount' => (float) $data['amount'],
            'reason' => trim($data['reason']),
        ]), 'Không ghi được sổ quỹ.');
    }

    protected function filters(Request $request): array
    {
        $trangThai = (string) $request->query('status', '');
        $coTrang = (int) $request->query('page_size', 20);
        $ngay = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';

        return [
            'status' => isset(self::STATUSES[$trangThai]) ? $trangThai : '',
            'from_date' => $ngay($request->query('from_date')),
            'to_date' => $ngay($request->query('to_date')),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($coTrang, self::PAGE_SIZES, true) ? $coTrang : 20,
        ];
    }
}
