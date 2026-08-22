<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public const STATUSES = [
        'pending' => 'Chờ xác nhận', 'confirmed' => 'Đã xác nhận',
        'processing' => 'Đang chuẩn bị', 'shipping' => 'Đang giao',
        'delivered' => 'Đã giao', 'completed' => 'Hoàn tất',
        'cancelled' => 'Đã huỷ', 'returned' => 'Trả hàng',
    ];

    public const STATUS_TONES = [
        'pending' => 'wait', 'confirmed' => 'info', 'processing' => 'info',
        'shipping' => 'move', 'delivered' => 'done', 'completed' => 'done',
        'cancelled' => 'stop', 'returned' => 'stop',
    ];

    public const PAYMENT_STATUSES = [
        'pending' => 'Chưa thanh toán', 'paid' => 'Đã thanh toán',
        'failed' => 'Thất bại', 'refunded' => 'Đã hoàn tiền',
    ];

    public const PAYMENT_METHODS = [
        'cod' => 'COD (khi nhận hàng)', 'vnpay' => 'VNPay',
        'momo' => 'MoMo', 'bank_transfer' => 'Chuyển khoản',
        'payos' => 'Online (PayOS)', 'sepay' => 'Chuyển khoản (SePay)',
        'cash' => 'Tiền mặt (tại quầy)',
    ];

    /** Nơi đơn phát sinh. Hai loại đơn này vận hành khác hẳn nhau — đơn 'web' còn
     *  phải soạn và giao, đơn 'pos' thì xong ngay lúc tạo — nên trộn chung một
     *  danh sách là bắt người trực đơn tự đọc lướt để bỏ qua nửa số dòng. */
    public const CHANNELS = [
        'web' => 'Đơn giao hàng', 'pos' => 'Bán tại quầy',
    ];

    public const SORTS = [
        'newest' => 'Mới nhất', 'oldest' => 'Cũ nhất',
        'total_desc' => 'Giá trị cao nhất', 'total_asc' => 'Giá trị thấp nhất',
    ];

    /** Các "khung nhìn" của trang đơn hàng.
     *  'statuses' rỗng = không giới hạn trạng thái (xem tất cả).
     *  Khung nhìn lạ trong query (?view=…) bị filters() quy về 'all', nên link cũ
     *  (?view=returned từ thời trả hàng còn là một bộ lọc của bảng này) vẫn mở
     *  được, chỉ là về danh sách đầy đủ.
     *
     *  Trả hàng nay là module riêng ở ReturnController — đừng thêm lại một khung
     *  nhìn "trả hàng" ở đây, hai chỗ cùng tên chỉ làm nhân viên bấm nhầm. */
    public const VIEWS = [
        'all' => [
            'label' => 'Danh sách đơn hàng',
            'title' => 'Quản lý đơn hàng',
            'statuses' => [],
            'empty' => 'Chưa có đơn hàng nào. Đơn sẽ xuất hiện khi khách đặt hàng trên website.',
        ],
    ];

    public const PAGE_SIZES = [20, 50, 100];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $orders = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->orders($this->apiQuery($filters));
            if ($res->successful()) {
                $orders = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load orders failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách đơn hàng.';
            }
        } catch (\Throwable $e) {
            Log::error('Load orders failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách đơn hàng. Kiểm tra kết nối API.';
        }

        $view = view('orders.index', compact('orders', 'filters', 'meta'))->with('stats', $this->stats());

        return $error ? $view->with('error', $error) : $view;
    }

    public function detail(int $id)
    {
        try {
            $res = $this->api->order($id);
            if ($res->successful()) {
                return response()->json([
                    'data' => $res->json('data'),
                    // Hoá đơn điện tử đi kèm luôn: hộp chi tiết phải biết đơn đã
                    // xuất hoá đơn chưa để khỏi bày nút phát hành lần thứ hai.
                    'etax' => $this->hoaDonCuaDon($id),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Load order detail failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không tải được chi tiết đơn hàng.'], 404);
    }

    /**
     * Hoá đơn điện tử của một đơn — null nếu chưa phát hành hoặc chưa nối cổng.
     *
     * Nuốt lỗi có chủ ý: hộp chi tiết đơn hàng KHÔNG được hỏng chỉ vì cổng hoá
     * đơn trả về một thứ lạ. Không đọc được thì coi như chưa có, nút phát hành
     * vẫn bấm được và lúc đó API mới là nơi từ chối nếu đã xuất rồi.
     */
    protected function hoaDonCuaDon(int $id): ?array
    {
        try {
            $res = $this->api->hoaDonCuaDon($id);

            return $res->successful() ? $res->json('data') : null;
        } catch (\Throwable $e) {
            Log::warning('Load etax invoice failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Phát hành hoá đơn điện tử cho một đơn.
     *
     * Trả JSON vì nút nằm trong hộp chi tiết, không tải lại trang. In `message`
     * của API ra nguyên văn: "chưa nối cổng", "chưa chọn ký hiệu" và "đã phát
     * hành rồi" là ba việc phải làm khác hẳn nhau.
     */
    public function phatHanhHoaDon(int $id)
    {
        try {
            $res = $this->api->phatHanhHoaDon($id);
        } catch (\Throwable $e) {
            Log::error('Issue etax invoice failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }

        if ($res->successful()) {
            return response()->json([
                'message' => $res->json('message') ?: 'Đã phát hành hoá đơn.',
                'data' => $res->json('data'),
            ]);
        }

        $loi = $res->json('errors');
        $message = is_array($loi) && $loi
            ? implode(' ', $loi)
            : ($res->json('message') ?: 'Phát hành hoá đơn không thành công.');

        return response()->json(['message' => $message], 422);
    }

    /** Ký tờ nháp rồi gửi cơ quan thuế. */
    public function kyHoaDon(int $id)
    {
        return $this->thaoTacHoaDon(fn () => $this->api->kyHoaDon($id), 'Ký hoá đơn không thành công.');
    }

    /** Hỏi lại cổng xem cơ quan thuế đã cấp mã chưa. */
    public function dongBoHoaDon(int $id)
    {
        return $this->thaoTacHoaDon(fn () => $this->api->dongBoHoaDon($id), 'Chưa hỏi được cổng hoá đơn.');
    }

    /** Thay thế hoá đơn — dựng lại tờ mới từ đơn hàng hôm nay. */
    public function thayTheHoaDon(Request $request, int $id)
    {
        $data = $request->validate([
            'ly_do' => ['required', 'string', 'max:250'],
            'so_van_ban' => ['nullable', 'string', 'max:250'],
        ]);

        return $this->thaoTacHoaDon(
            fn () => $this->api->thayTheHoaDon($id, $data),
            'Thay thế hoá đơn không thành công.'
        );
    }

    /**
     * Điều chỉnh hoá đơn. Không gửi dòng nào = điều chỉnh về 0 (tương đương huỷ).
     */
    public function dieuChinhHoaDon(Request $request, int $id)
    {
        $data = $request->validate([
            'ly_do' => ['required', 'string', 'max:250'],
        ]);

        return $this->thaoTacHoaDon(
            fn () => $this->api->dieuChinhHoaDon($id, $data),
            'Điều chỉnh hoá đơn không thành công.'
        );
    }

    /**
     * Bản PDF của hoá đơn, trả thẳng ra trình duyệt để mở trong tab mới.
     *
     * Hỏng thì trả về CHỮ chứ không phải một tệp PDF rỗng: người dùng mở tab mới
     * và nhìn thấy một trang trắng thì không biết chuyện gì đã xảy ra.
     */
    public function pdfHoaDon(Request $request, int $id)
    {
        try {
            $res = $this->api->pdfHoaDon($id, $request->boolean('chuyen_doi'));
        } catch (\Throwable $e) {
            Log::error('Load etax pdf failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response('Không kết nối được API.', 502);
        }

        if (! $res->successful()) {
            return response($res->json('message') ?: 'Không lấy được bản in hoá đơn.', $res->status());
        }

        return response($res->body(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="hoa-don.pdf"',
        ]);
    }

    /** Bản XML gốc đã ký — tệp kế toán lưu trữ. */
    public function xmlHoaDon(int $id)
    {
        try {
            $res = $this->api->xmlHoaDon($id);
        } catch (\Throwable $e) {
            Log::error('Load etax xml failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response('Không kết nối được API.', 502);
        }

        if (! $res->successful()) {
            return response($res->json('message') ?: 'Không lấy được bản XML hoá đơn.', $res->status());
        }

        return response($res->body(), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="hoa-don.xml"',
        ]);
    }

    /**
     * Một lượt thao tác trên tờ hoá đơn: gọi API, in nguyên văn câu của nó ra.
     *
     * Câu của cổng hoá đơn là thứ nói được phải làm gì tiếp ("chưa chọn ký
     * hiệu", "hoá đơn này đã ký rồi"), nên đừng thay nó bằng một câu chung.
     */
    protected function thaoTacHoaDon(callable $goi, string $macDinh)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Etax action failed', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }

        if ($res->successful()) {
            return response()->json([
                'message' => $res->json('message') ?: 'Đã xong.',
                'data' => $res->json('data'),
            ]);
        }

        $loi = $res->json('errors');
        $message = is_array($loi) && $loi
            ? implode(' ', $loi)
            : ($res->json('message') ?: $macDinh);

        return response()->json(['message' => $message], 422);
    }

    /** Trang in hóa đơn cho 1 hoặc NHIỀU đơn (mở tab mới, tự bật hộp thoại in).
     *  Dùng chung cho nút "In đơn" (1 đơn qua {id}) và "In hàng loạt" (?ids=1,2,3). */
    public function print(Request $request, ?int $id = null)
    {
        $orders = $this->fetchOrdersForPrint($request, $id, 'in hoá đơn', true);

        return view('orders.print', [
            'orders' => $orders,
            'STATUSES' => self::STATUSES,
            'PAY_STATUSES' => self::PAYMENT_STATUSES,
            'PAY_METHODS' => self::PAYMENT_METHODS,
        ]);
    }

    /** Trang in tem giao hàng (khổ 10×15) cho 1 hoặc NHIỀU đơn. */
    public function label(Request $request, ?int $id = null)
    {
        $orders = $this->fetchOrdersForPrint($request, $id, 'in tem giao hàng');

        return view('orders.label', [
            'orders' => $orders,
            'PAY_METHODS' => self::PAYMENT_METHODS,
            'PAY_STATUSES' => self::PAYMENT_STATUSES,
        ]);
    }

    /** Chuyển trạng thái hàng loạt: đẩy các đơn đã chọn sang trạng thái kế tiếp.
     *  Không nhận "cancelled"/"returned" vì hai thao tác này bắt buộc có lý do —
     *  phải làm trên từng đơn. Đơn không hợp luồng được bỏ qua, không tính là lỗi. */
    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'status' => 'required|in:confirmed,processing,shipping,delivered,completed',
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
                $res = $this->api->updateOrderStatus((int) $id, $target, '');
                if ($res->successful()) {
                    $ok++;
                } elseif (in_array($res->status(), [409, 422], true)) {
                    // Đơn không ở trạng thái cho phép chuyển tới đích này — bỏ qua.
                    $skipped++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::error('Bulk status failed', ['id' => $id, 'status' => $target, 'msg' => $e->getMessage()]);
                $failed++;
            }
        }

        $parts = [];
        if ($ok > 0) {
            $parts[] = "{$ok} đơn đã chuyển sang \"{$label}\"";
        }
        if ($skipped > 0) {
            $parts[] = "bỏ qua {$skipped} đơn không hợp luồng";
        }
        if ($failed > 0) {
            $parts[] = "{$failed} đơn lỗi";
        }
        $msg = empty($parts) ? 'Không có đơn nào được xử lý.' : implode(', ', $parts).'.';

        $redirect = $this->backToList($request);

        return $failed > 0 && $ok === 0
            ? $redirect->with('error', $msg)
            : $redirect->with('success', $msg);
    }

    /** Đọc danh sách đơn để in: ưu tiên ?ids=1,2,3 (in hàng loạt), nếu không có thì
     *  dùng {id} trên URL (in một đơn). Giữ đúng thứ tự ids người dùng chọn. */
    protected function fetchOrdersForPrint(Request $request, ?int $id, string $viec, bool $kemHoaDon = false): array
    {
        $ids = [];
        $raw = (string) $request->query('ids', '');
        if ($raw !== '') {
            foreach (explode(',', $raw) as $part) {
                $n = (int) trim($part);
                if ($n > 0 && ! in_array($n, $ids, true)) {
                    $ids[] = $n;
                }
            }
        } elseif ($id !== null && $id > 0) {
            $ids[] = $id;
        }
        $ids = array_slice($ids, 0, 200); // chặn in quá nhiều một lúc

        // Hai tình huống khác hẳn nhau, đừng gộp vào một câu: "chưa chọn đơn nào"
        // là người dùng thiếu một bước, còn "không tải được" là hệ thống có vấn đề.
        // Trước đây cả hai đều báo "Không tải được đơn hàng…", đọc lên tưởng API hỏng.
        abort_if($ids === [], 404, 'Chưa chọn đơn hàng nào để '.$viec.'. Hãy chọn ít nhất một đơn trong danh sách rồi bấm lại.');

        $orders = [];
        foreach ($ids as $oid) {
            try {
                $res = $this->api->order($oid);
                if ($res->successful() && ($o = $res->json('data'))) {
                    // Hoá đơn điện tử chỉ lấy cho bản IN ĐƠN: tem giao hàng không
                    // in mã cơ quan thuế, và mỗi đơn ở đây là thêm một lượt gọi.
                    if ($kemHoaDon) {
                        $o['etax'] = $this->hoaDonCuaDon($oid);
                    }
                    $orders[] = $o;
                }
            } catch (\Throwable $e) {
                Log::error('Load order for print failed', ['id' => $oid, 'msg' => $e->getMessage()]);
            }
        }

        abort_if($orders === [], 404, 'Không tải được đơn hàng để '.$viec.'. Đơn có thể đã bị xoá, hoặc máy chủ API đang không trả lời.');

        return $orders;
    }

    /** Tạo đơn thủ công cho khách hàng có sẵn. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|min:1',
            'recipient_name' => 'required|string|max:100',
            'recipient_phone' => 'required|string|max:20',
            'recipient_email' => 'nullable|email|max:100',
            'shipping_province' => 'nullable|string|max:100',
            'shipping_district' => 'nullable|string|max:100',
            'shipping_ward' => 'nullable|string|max:100',
            'shipping_address' => 'required|string|max:255',
            'payment_method' => 'required|in:cod,vnpay,momo,bank_transfer,payos,sepay',
            'shipping_method' => 'nullable|string|max:100',
            'shipping_fee' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|integer|min:1',
            'items.*.product_id' => 'nullable|integer|min:1',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.variant_sku' => 'nullable|string|max:100',
            'items.*.size' => 'nullable|string|max:50',
            'items.*.color' => 'nullable|string|max:50',
            'items.*.thumbnail' => 'nullable|string|max:500',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.custom_player_name' => 'nullable|string|max:50',
            'items.*.custom_player_number' => 'nullable|string|max:10',
        ]);

        $payload = [
            'user_id' => (int) $data['user_id'],
            'recipient_name' => $data['recipient_name'],
            'recipient_phone' => $data['recipient_phone'],
            'recipient_email' => $data['recipient_email'] ?? '',
            'shipping_province' => $data['shipping_province'] ?? '',
            'shipping_district' => $data['shipping_district'] ?? '',
            'shipping_ward' => $data['shipping_ward'] ?? '',
            'shipping_address' => $data['shipping_address'],
            'payment_method' => $data['payment_method'],
            'shipping_method' => $data['shipping_method'] ?? '',
            'shipping_fee' => (float) ($data['shipping_fee'] ?? 0),
            'discount_amount' => (float) ($data['discount_amount'] ?? 0),
            'note' => $data['note'] ?? '',
            'items' => array_map(fn ($it) => [
                'product_variant_id' => (int) $it['product_variant_id'],
                'product_id' => isset($it['product_id']) ? (int) $it['product_id'] : null,
                'product_name' => $it['product_name'],
                'variant_sku' => $it['variant_sku'] ?? '',
                'variant_name' => $it['variant_name'] ?? '',
                'thumbnail' => $it['thumbnail'] ?? '',
                'unit_price' => (float) $it['unit_price'],
                'quantity' => (int) $it['quantity'],
                'custom_player_name' => $it['custom_player_name'] ?? '',
                'custom_player_number' => $it['custom_player_number'] ?? '',
            ], array_values($data['items'])),
        ];

        return $this->send(
            fn () => $this->api->createOrder($payload),
            'Đã tạo đơn hàng mới.',
            $request
        );
    }

    /** Sửa đơn có sẵn: thông tin người nhận/giao hàng + danh sách sản phẩm. */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'recipient_name' => 'required|string|max:100',
            'recipient_phone' => 'required|string|max:20',
            'recipient_email' => 'nullable|email|max:100',
            'shipping_province' => 'nullable|string|max:100',
            'shipping_district' => 'nullable|string|max:100',
            'shipping_ward' => 'nullable|string|max:100',
            'shipping_address' => 'required|string|max:255',
            'payment_method' => 'required|in:cod,vnpay,momo,bank_transfer,payos,sepay',
            'shipping_method' => 'nullable|string|max:100',
            'shipping_fee' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|integer|min:1',
            'items.*.product_id' => 'nullable|integer|min:1',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.variant_sku' => 'nullable|string|max:100',
            'items.*.size' => 'nullable|string|max:50',
            'items.*.color' => 'nullable|string|max:50',
            'items.*.thumbnail' => 'nullable|string|max:500',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.custom_player_name' => 'nullable|string|max:50',
            'items.*.custom_player_number' => 'nullable|string|max:10',
        ]);

        $payload = [
            'recipient_name' => $data['recipient_name'],
            'recipient_phone' => $data['recipient_phone'],
            'recipient_email' => $data['recipient_email'] ?? '',
            'shipping_province' => $data['shipping_province'] ?? '',
            'shipping_district' => $data['shipping_district'] ?? '',
            'shipping_ward' => $data['shipping_ward'] ?? '',
            'shipping_address' => $data['shipping_address'],
            'payment_method' => $data['payment_method'],
            'shipping_method' => $data['shipping_method'] ?? '',
            'shipping_fee' => (float) ($data['shipping_fee'] ?? 0),
            'discount_amount' => (float) ($data['discount_amount'] ?? 0),
            'note' => $data['note'] ?? '',
            'items' => array_map(fn ($it) => [
                'product_variant_id' => (int) $it['product_variant_id'],
                'product_id' => isset($it['product_id']) ? (int) $it['product_id'] : null,
                'product_name' => $it['product_name'],
                'variant_sku' => $it['variant_sku'] ?? '',
                'variant_name' => $it['variant_name'] ?? '',
                'thumbnail' => $it['thumbnail'] ?? '',
                'unit_price' => (float) $it['unit_price'],
                'quantity' => (int) $it['quantity'],
                'custom_player_name' => $it['custom_player_name'] ?? '',
                'custom_player_number' => $it['custom_player_number'] ?? '',
            ], array_values($data['items'])),
        ];

        return $this->send(
            fn () => $this->api->updateOrder($id, $payload),
            'Đã cập nhật đơn hàng.',
            $request
        );
    }

    /** JSON: tìm khách hàng có sẵn cho ô chọn khách khi tạo đơn. */
    public function searchCustomers(Request $request)
    {
        $keyword = trim((string) $request->query('q', ''));
        $list = [];
        try {
            $res = $this->api->customers(['keyword' => $keyword, 'page_size' => 10]);
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Search customers failed', ['msg' => $e->getMessage()]);
        }

        $data = array_map(fn ($c) => [
            'id' => $c['id'] ?? 0,
            'name' => $c['full_name'] ?? '',
            'phone' => $c['phone'] ?? '',
            'email' => $c['email'] ?? '',
            'address' => $c['address'] ?? '',
        ], $list);

        return response()->json(['data' => $data]);
    }

    /**
     * JSON: tìm sản phẩm (kèm biến thể) cho ô thêm sản phẩm khi tạo đơn.
     *
     * Màn hình bán tại quầy gọi CHÍNH đường này để đổ lưới hàng, nên có thêm
     * category_id / page / page_size. Cả ba đều tuỳ chọn và mặc định giữ nguyên
     * hành vi cũ — trang tạo đơn không gửi gì thì vẫn nhận đúng 10 kết quả như trước.
     */
    public function searchProducts(Request $request)
    {
        $keyword = trim((string) $request->query('q', ''));
        $categoryId = (int) $request->query('category_id', 0);
        $page = max(1, (int) $request->query('page', 1));
        // Trần 60: lưới ở quầy tắt ảnh thì xếp được nhiều thẻ, nhưng quá số này
        // là một lần cuộn dài hơn cả việc gõ tên hàng.
        $pageSize = min(60, max(1, (int) $request->query('page_size', 10)));

        $query = [
            'keyword' => $keyword,
            'page' => $page,
            'page_size' => $pageSize,
            'status' => 'active',
        ];
        if ($categoryId > 0) {
            $query['category_id'] = $categoryId;
        }

        $list = [];
        $meta = [];
        try {
            $res = $this->api->products($query);
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = $res->json('meta') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Search products failed', ['msg' => $e->getMessage()]);
        }

        $data = array_map(function ($p) {
            $variants = array_map(fn ($v) => [
                'id' => $v['id'] ?? 0,
                'sku' => $v['sku'] ?? '',
                'name' => $v['name'] ?? '',
                'price' => $v['price'] ?? null,
                // final_price là GIÁ BÁN THẬT của biến thể: giá riêng của nó (nếu có)
                // đè giá sản phẩm, rồi chương trình khuyến mãi đang chạy trừ tiếp —
                // đúng công thức tầng thanh toán dùng để thu tiền.
                //
                // Màn hình bán tại quầy đọc trường này để con số đọc cho khách nghe
                // bằng đúng con số API sẽ thu. Không có nó thì quầy báo giá gốc trong
                // khi hệ thống tính giá đã giảm, và người bán là người phải giải thích.
                'final_price' => isset($v['final_price']) ? (float) $v['final_price'] : null,
                'stock' => $v['stock_quantity'] ?? 0,
                'image' => $v['image'] ?? '',
            ], $p['variants'] ?? []);

            return [
                'id' => $p['id'] ?? 0,
                'name' => $p['name'] ?? '',
                'thumbnail' => $p['thumbnail'] ?? '',
                'base_price' => (float) ($p['base_price'] ?? 0),
                'sale_price' => isset($p['sale_price']) && $p['sale_price'] !== null ? (float) $p['sale_price'] : 0,
                'variants' => $variants,
            ];
        }, $list);

        return response()->json(['data' => $data, 'meta' => $meta]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(self::STATUSES)),
            'note' => 'nullable|string|max:255',
        ]);
        if ($validated['status'] === 'cancelled' && trim((string) ($validated['note'] ?? '')) === '') {
            return $this->backToList($request)->with('error', 'Vui lòng nhập lý do huỷ đơn.');
        }

        return $this->send(
            fn () => $this->api->updateOrderStatus($id, $validated['status'], (string) ($validated['note'] ?? '')),
            'Đã chuyển đơn sang "'.self::STATUSES[$validated['status']].'".',
            $request
        );
    }

    public function updatePayment(Request $request, int $id)
    {
        $validated = $request->validate([
            'payment_status' => 'required|in:'.implode(',', array_keys(self::PAYMENT_STATUSES)),
        ]);

        return $this->send(
            fn () => $this->api->updateOrderPayment($id, $validated['payment_status']),
            'Đã cập nhật thanh toán: '.self::PAYMENT_STATUSES[$validated['payment_status']].'.',
            $request
        );
    }

    public function updateNote(Request $request, int $id)
    {
        $validated = $request->validate(['admin_note' => 'nullable|string|max:500']);

        return $this->send(
            fn () => $this->api->updateOrderNote($id, (string) ($validated['admin_note'] ?? '')),
            'Đã lưu ghi chú cho đơn hàng.',
            $request
        );
    }

    public function updateShipping(Request $request, int $id)
    {
        $validated = $request->validate([
            'shipping_method' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
        ]);

        return $this->send(
            fn () => $this->api->updateOrderShipping(
                $id,
                (string) ($validated['shipping_method'] ?? ''),
                (string) ($validated['tracking_number'] ?? '')
            ),
            'Đã cập nhật thông tin vận chuyển.',
            $request
        );
    }

    public function export(Request $request)
    {
        // Nếu có ?ids=... thì chỉ xuất các đơn được chọn; ngược lại xuất theo bộ lọc.
        //
        // Tham số thứ ba là VIỆC ĐANG LÀM, chèn vào câu báo lỗi khi không lấy được
        // đơn nào ("Chưa chọn đơn hàng nào để xuất tệp…"). Nó không có giá trị mặc
        // định, và chỗ gọi này từng bỏ quên — bấm Xuất trên vài đơn vừa tick là
        // nhận thẳng trang 500.
        $orders = $request->query('ids', '') !== ''
            ? $this->fetchOrdersForPrint($request, null, 'xuất tệp')
            : $this->fetchAll($this->filters($request));
        $fileName = 'don-hang-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // Cột "Kênh" đứng ngay sau ngày đặt: người mở tệp này thường đang cộng sổ
            // một ngày, mà doanh thu quầy và doanh thu giao hàng là hai khoản phải
            // tách được ra. Không có cột ấy thì đơn quầy chỉ nhận ra qua ô địa chỉ
            // trống — một dấu hiệu gián tiếp và dễ đọc nhầm.
            fputcsv($out, ['Mã đơn', 'Ngày đặt', 'Kênh', 'Người nhận', 'Số điện thoại', 'Địa chỉ giao', 'Số sản phẩm', 'Tiền hàng', 'Giảm giá', 'Phí ship', 'Tổng tiền', 'Phương thức', 'Thanh toán', 'Trạng thái']);
            foreach ($orders as $o) {
                fputcsv($out, [
                    $o['order_code'] ?? '',
                    ! empty($o['created_at']) ? Carbon::parse($o['created_at'])->format('d/m/Y H:i') : '',
                    self::CHANNELS[$o['channel'] ?? ''] ?? ($o['channel'] ?? ''),
                    $o['recipient_name'] ?? '', $o['recipient_phone'] ?? '', self::shippingAddress($o),
                    collect($o['items'] ?? [])->sum('quantity'), (float) ($o['subtotal_amount'] ?? 0), (float) ($o['discount_amount'] ?? 0),
                    (float) ($o['shipping_fee'] ?? 0), (float) ($o['total_amount'] ?? 0),
                    self::PAYMENT_METHODS[$o['payment_method'] ?? ''] ?? ($o['payment_method'] ?? ''),
                    self::PAYMENT_STATUSES[$o['payment_status'] ?? ''] ?? ($o['payment_status'] ?? ''),
                    self::STATUSES[$o['status'] ?? ''] ?? ($o['status'] ?? ''),
                ]);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Helpers

    public static function shippingAddress(array $o): string
    {
        return implode(', ', array_filter([
            $o['shipping_address'] ?? '', $o['shipping_ward'] ?? '',
            $o['shipping_district'] ?? '', $o['shipping_province'] ?? '',
        ], fn ($v) => trim((string) $v) !== ''));
    }

    protected function filters(Request $request): array
    {
        $v = (string) $request->query('view', 'all');
        $view = isset(self::VIEWS[$v]) ? $v : 'all';
        $allowed = self::VIEWS[$view]['statuses'];

        $s = (string) $request->query('status', 'all');
        // Trạng thái phải nằm trong phạm vi của khung nhìn, nếu không thì bỏ qua
        // (VD: đang ở "Trả hàng" mà truyền status=completed → coi như "all" của view đó).
        $status = isset(self::STATUSES[$s]) && (empty($allowed) || in_array($s, $allowed, true)) ? $s : 'all';

        $ps = (string) $request->query('payment_status', 'all');
        $pm = (string) $request->query('payment_method', 'all');
        $ch = (string) $request->query('channel', 'all');
        $so = (string) $request->query('sort', 'newest');
        $psize = (int) $request->query('page_size', 20);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';

        return [
            'view' => $view,
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => $status,
            'payment_status' => isset(self::PAYMENT_STATUSES[$ps]) ? $ps : 'all',
            'payment_method' => isset(self::PAYMENT_METHODS[$pm]) ? $pm : 'all',
            'channel' => isset(self::CHANNELS[$ch]) ? $ch : 'all',
            'from_date' => $date($request->query('from_date')), 'to_date' => $date($request->query('to_date')),
            'sort' => isset(self::SORTS[$so]) ? $so : 'newest',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($psize, self::PAGE_SIZES, true) ? $psize : 20,
        ];
    }

    /** Chuyển bộ lọc của trang thành query gửi cho API: bỏ khoá 'view' (chỉ dùng ở
     *  admin) và quy đổi nó thành danh sách trạng thái ngăn cách bởi dấu phẩy. */
    protected function apiQuery(array $filters): array
    {
        $query = $filters;
        $allowed = self::VIEWS[$filters['view'] ?? 'all']['statuses'] ?? [];
        unset($query['view']);

        // Người dùng chưa chọn trạng thái cụ thể → lấy trọn nhóm trạng thái của view.
        if ($filters['status'] === 'all' && ! empty($allowed)) {
            $query['status'] = implode(',', $allowed);
        }

        return $query;
    }

    protected function stats(): array
    {
        $stats = ['total' => 0, 'pending' => 0, 'processing' => 0, 'shipping' => 0, 'completed' => 0, 'cancelled' => 0, 'revenue' => 0];
        try {
            $res = $this->api->orderStats();
            if ($res->successful()) {
                $stats = array_merge($stats, $res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::info('Load order stats failed', ['msg' => $e->getMessage()]);
        }

        return $stats;
    }

    protected function fetchAll(array $filters): array
    {
        $all = [];
        $query = array_merge($this->apiQuery($filters), ['page' => 1, 'page_size' => 100]);
        $totalPages = 1;
        try {
            do {
                $res = $this->api->orders($query);
                if (! $res->successful()) {
                    break;
                } $all = array_merge($all, $res->json('data') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $totalPages && $query['page'] <= 100);
        } catch (\Throwable $e) {
            Log::error('Export orders failed', ['msg' => $e->getMessage()]);
        }

        return $all;
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Order API call failed', ['msg' => $e->getMessage()]);

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

        return redirect()->route('admin.orders.index', $request->query());
    }
}
