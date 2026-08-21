<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Điều phối ca & sổ quỹ — nơi chủ tiệm đối chiếu TIỀN TRONG KÉT với SỔ.
 *
 * Cả trang này chỉ phục vụ đúng một câu hỏi cuối ngày: két có khớp sổ không, và
 * nếu lệch thì lệch trong lượt trực của ai. Thứ gì không trả lời câu đó thì
 * không thuộc về đây — nên không có bộ lọc chi li, không có biểu đồ.
 *
 * CHỈ TIỀN MẶT. Chuyển khoản không đi qua két nên không nằm trong sổ này; gộp
 * vào là con số đối chiếu không còn khớp tiền đếm được, tức hỏng đúng thứ trang
 * này sinh ra để phục vụ.
 *
 * Nằm ở nhóm `admin` (nhân viên vào được): người trực két là nhân viên, và cả
 * cụm vô nghĩa nếu chỉ chủ mới mở/đóng ca được.
 */
class CaLamViecController extends Controller
{
    public const TITLE = 'Điều phối ca';

    public const EMPTY_TEXT = 'Chưa có ca nào. Mở ca ở màn hình Bán hàng khi bắt đầu buổi bán — từ lúc đó mọi lượt thu chi tiền mặt được ghi vào sổ của ca.';

    public const PAGE_SIZES = [20, 50, 100];

    public const STATUSES = [
        'dang_mo' => 'Đang mở',
        'da_dong' => 'Đã đóng',
    ];

    /** Chiều tiền của một dòng sổ quỹ. */
    public const DIRECTIONS = [
        'in' => 'Thu',
        'out' => 'Chi',
    ];

    /** Nguồn gốc dòng sổ quỹ — dịch cột reference_type ra tiếng người. */
    public const SOURCES = [
        'order' => 'Bán hàng',
        'order_return' => 'Trả hàng',
        'manual' => 'Ghi tay',
    ];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
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
            Log::error('Load ca lam viec failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách ca. Kiểm tra kết nối API.';
        }

        $view = view('thu-ngan.ca-lam-viec', compact('list', 'filters', 'meta'));

        return $error ? $view->with('error', $error) : $view;
    }

    /** Chi tiết một ca kèm toàn bộ dòng sổ quỹ — màn hình dùng để truy một khoản chênh. */
    public function show(int $id)
    {
        try {
            $res = $this->api->caChiTiet($id);
            if (! $res->successful()) {
                abort(404);
            }
            $data = $res->json('data') ?? [];
        } catch (\Throwable $e) {
            Log::error('Load ca detail failed', ['id' => $id, 'msg' => $e->getMessage()]);
            abort(404);
        }

        return view('thu-ngan.ca-chi-tiet', [
            'ca' => $data['ca'] ?? [],
            'soQuy' => $data['so_quy'] ?? [],
        ]);
    }

    /** JSON: ca đang mở của chi nhánh đang làm việc. Màn hình quầy hỏi đường này. */
    public function hienTai()
    {
        try {
            $res = $this->api->caHienTai();
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data')]);
            }
        } catch (\Throwable $e) {
            Log::info('Load ca hien tai failed', ['msg' => $e->getMessage()]);
        }

        // Hỏng thì trả null chứ không trả lỗi: màn hình quầy vẫn phải bán được
        // khi cụm ca làm việc trục trặc — nó là thứ ghi chép, không phải thứ gác cửa.
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

        return $this->goi(fn () => $this->api->moCa([
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

        return $this->goi(fn () => $this->api->dongCa([
            'counted_cash' => (float) $data['counted_cash'],
            'note' => trim((string) ($data['note'] ?? '')),
        ]), 'Không đóng được ca.');
    }

    public function ghiSoQuy(Request $request)
    {
        $data = $request->validate([
            'direction' => 'required|in:in,out',
            'amount' => 'required|numeric|gt:0',
            'reason' => 'required|string|max:255',
        ], [
            'amount.gt' => 'Số tiền phải lớn hơn 0.',
            'reason.required' => 'Vui lòng ghi lý do — một khoản ra khỏi két không có lý do thì đúng bằng mất tiền.',
        ]);

        return $this->goi(fn () => $this->api->ghiSoQuy([
            'direction' => $data['direction'],
            'amount' => (float) $data['amount'],
            'reason' => trim($data['reason']),
        ]), 'Không ghi được sổ quỹ.');
    }

    /**
     * Gọi API rồi trả JSON, giữ NGUYÊN câu lỗi của API.
     *
     * Những câu đó đã nói rõ việc cần làm ("chi nhánh này đang có ca mở"), thay
     * bằng một câu chung chung là bắt người dùng đoán.
     */
    protected function goi(callable $call, string $fallback)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Ca lam viec API call failed', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API.'], 502);
        }

        if (! $res->successful()) {
            return response()->json(
                ['message' => $res->json('message') ?: $fallback],
                $res->status() === 500 ? 502 : $res->status()
            );
        }

        return response()->json(['data' => $res->json('data')]);
    }

    protected function filters(Request $request): array
    {
        $st = (string) $request->query('status', '');
        $psize = (int) $request->query('page_size', 20);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';

        return [
            'status' => isset(self::STATUSES[$st]) ? $st : '',
            'from_date' => $date($request->query('from_date')),
            'to_date' => $date($request->query('to_date')),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($psize, self::PAGE_SIZES, true) ? $psize : 20,
        ];
    }
}
