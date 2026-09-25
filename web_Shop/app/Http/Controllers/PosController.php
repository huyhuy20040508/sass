<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiJsonReply;
use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bán hàng — màn chính của module thu ngân.
 *
 * GIÁ KHÔNG NẰM Ở ĐÂY. Trang chỉ gửi "mua biến thể nào, mấy cái, bớt mấy %";
 * tên, giá, khuyến mãi, tồn kho và hạn quyền bớt giá đều do API tra lại lúc bấm
 * nút. Người bán không gõ giá thì cũng không bán sai giá được.
 */
class PosController extends Controller
{
    use Concerns\V2ScreenFallback;

    use ApiJsonReply;

    public const TITLE = 'Bán hàng';

    /**
     * Chỉ hai hình thức, và đó là chủ ý: đơn quầy ghi "đã thanh toán" ngay lúc
     * tạo, nên chỉ nhận thứ tiền ĐÃ về trước khi khách rời quầy.
     */
    public const PAYMENT_METHODS = [
        'cash' => 'Tiền mặt',
        'bank_transfer' => 'Chuyển khoản',
    ];

    /** Mệnh giá bấm cộng dồn vào ô khách đưa — bấm thì không gõ thừa số 0 được. */
    public const MENH_GIA = [10000, 20000, 50000, 100000, 200000, 500000];

    public function __construct(protected ApiClient $api) {}

    public function index()
    {
        return view('v2::pos.sale', [
            'hanMucGiam' => $this->hanMucGiam(),
            'nhomHang' => $this->nhomHang(),
        ]);
    }

    /**
     * Quét mã vạch / SKU. Đi vòng qua Laravel vì token nằm trong phiên phía máy
     * chủ — đưa nó xuống trình duyệt để bớt một chặng là đổi sai thứ.
     */
    public function scan(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return response()->json(['message' => 'Chưa có mã để quét.'], 422);
        }

        return $this->jsonTuApi(fn () => $this->api->posScan($code), 'Không tìm thấy mã này.');
    }

    /** Chốt một lượt bán. Trả JSON: màn quầy giữ giỏ trong trang, tải lại là mất giỏ. */
    public function store(Request $request)
    {
        $data = $request->validate([
            // Vắng user_id = khách lẻ: quầy phải bán được cho người không có tài khoản.
            'user_id' => 'nullable|integer|min:1',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:20',
            'payment_method' => 'required|in:'.implode(',', array_keys(self::PAYMENT_METHODS)),
            'amount_tendered' => 'nullable|numeric|min:0',
            'voucher_code' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:500',
            'items' => 'required|array|min:1|max:50',
            'items.*.product_variant_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            // Chỉ kiểm khoảng hợp lệ. Mức tối đa theo vai trò là việc của API — nó
            // đọc vai trò từ token, còn ở đây vai trò nằm trong phiên.
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            // Giảm cả đơn: phần trăm HOẶC số tiền. Hạn quyền cũng là việc của API.
            'order_discount_percent' => 'nullable|numeric|min:0|max:100',
            'order_discount_amount' => 'nullable|numeric|min:0|max:1000000000',
            'surcharge_amount' => 'nullable|numeric|min:0|max:1000000000',
            'surcharge_note' => 'nullable|string|max:255',
            // Người mua lấy hoá đơn điện tử.
            'customer_email' => 'nullable|email|max:191',
            'buyer_tax_code' => 'nullable|string|max:20',
            'buyer_company' => 'nullable|string|max:255',
            'buyer_address' => 'nullable|string|max:255',
            'issue_einvoice' => 'nullable|boolean',
            // Mã đơn đã giữ trước + chữ ký. API tự kiểm chữ ký; sai thì cấp mã mới.
        ], [
            'items.required' => 'Chưa có sản phẩm nào trong giỏ.',
            'items.min' => 'Chưa có sản phẩm nào trong giỏ.',
        ]);

        // CỐ Ý không gửi tên/giá từng dòng dù màn hình có sẵn: gửi lên là mở đường
        // cho giá trên màn hình khác giá trong sổ.
        $payload = [
            'payment_method' => $data['payment_method'],
            'customer_name' => trim((string) ($data['customer_name'] ?? '')),
            'customer_phone' => trim((string) ($data['customer_phone'] ?? '')),
            'voucher_code' => trim((string) ($data['voucher_code'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
            'items' => array_map(fn ($it) => [
                'product_variant_id' => (int) $it['product_variant_id'],
                'quantity' => (int) $it['quantity'],
                'discount_percent' => (float) ($it['discount_percent'] ?? 0),
            ], $data['items']),
        ];
        if (! empty($data['user_id'])) {
            $payload['user_id'] = (int) $data['user_id'];
        }
        // Tiền khách đưa chỉ có nghĩa với tiền mặt.
        if ($data['payment_method'] === 'cash' && isset($data['amount_tendered'])) {
            $payload['amount_tendered'] = (float) $data['amount_tendered'];
        }

        // Giảm cả đơn: gửi ĐÚNG MỘT trong hai. Phần trăm thắng — nó là thứ API
        // kiểm hạn quyền trực tiếp, còn số tiền phải quy đổi.
        if ((float) ($data['order_discount_percent'] ?? 0) > 0) {
            $payload['order_discount_percent'] = (float) $data['order_discount_percent'];
        } elseif ((float) ($data['order_discount_amount'] ?? 0) > 0) {
            $payload['order_discount_amount'] = (float) $data['order_discount_amount'];
        }

        if ((float) ($data['surcharge_amount'] ?? 0) > 0) {
            $payload['surcharge_amount'] = (float) $data['surcharge_amount'];
            $payload['surcharge_note'] = trim((string) ($data['surcharge_note'] ?? ''));
        }

        foreach (['customer_email', 'buyer_tax_code', 'buyer_company', 'buyer_address'] as $o) {
            if (trim((string) ($data[$o] ?? '')) !== '') {
                $payload[$o] = trim((string) $data[$o]);
            }
        }
        if ($request->boolean('issue_einvoice')) {
            $payload['issue_einvoice'] = true;
        }
        return $this->jsonTuApi(fn () => $this->api->posCheckout($payload), 'Không hoàn tất được lượt bán.');
    }

    /**
     * Tra khách quen cho ô chọn khách của quầy. API hỏng thì danh sách rỗng — ô
     * khách là lối tắt, không phải điều kiện để bán (khách lẻ vẫn bán được).
     */
    public function khachHang(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $ds = [];
        try {
            $res = $this->api->posKhachHang(['keyword' => $q, 'page_size' => 10]);
            if ($res->successful()) {
                $ds = $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Thu ngan: tra khach that bai', ['msg' => $e->getMessage()]);
        }

        return response()->json(['data' => array_map(fn ($c) => $this->khachGon((array) $c), $ds)]);
    }

    /** Nút + "Khách mới": lưu hồ sơ gọn rồi trả về để quầy chọn luôn khách đó. */
    public function taoKhach(Request $request)
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:191',
            'address' => 'nullable|string|max:255',
        ], [
            'full_name.required' => 'Nhập tên khách.',
            'email.email' => 'Email chưa đúng dạng.',
        ]);

        $payload = array_filter(array_map(fn ($v) => trim((string) $v), $data), fn ($v) => $v !== '');

        return $this->jsonKemCau(fn () => $this->api->posTaoKhach($payload), 'Không lưu được khách hàng.', fn ($d) => [
            'customer' => $this->khachGon((array) ($d['customer'] ?? [])),
            'existed' => (bool) ($d['existed'] ?? false),
        ]);
    }

    /**
     * Giữ trước mã đơn khi hoá đơn có món đầu tiên — tab đổi từ "Hoá đơn N" sang mã
     * đơn như v2 cũ. Hỏng thì tab giữ nhãn cũ, lúc chốt API vẫn cấp mã như thường.
     */
    /** Xuất (hoặc bấm lại) hoá đơn điện tử cho một đơn quầy. */
    public function phatHanhHoaDon(int $id)
    {
        return $this->jsonKemCau(fn () => $this->api->posPhatHanhHoaDon($id), 'Phát hành hoá đơn không thành công.');
    }

    /**
     * Như jsonTuApi nhưng GIỮ CẢ CÂU BÁO THÀNH CÔNG của API ("Đã thêm khách…",
     * "Đã ký và gửi hoá đơn… — đang chờ cơ quan thuế cấp mã"): ở hai nút này câu
     * báo chính là kết quả người bán cần đọc.
     *
     * @param  callable(): \Illuminate\Http\Client\Response  $goi
     */
    protected function jsonKemCau(callable $goi, string $cauMacDinh, ?callable $gon = null)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Thu ngan: goi API that bai', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }

        if (! $res->successful()) {
            $loi = $res->json('errors');

            return response()->json(
                ['message' => is_array($loi) && $loi ? implode(' ', array_map('strval', array_values($loi))) : ($res->json('message') ?: $cauMacDinh)],
                $res->status() === 500 ? 502 : $res->status()
            );
        }

        $data = $res->json('data');

        return response()->json([
            'message' => (string) ($res->json('message') ?? ''),
            'data' => $gon ? $gon((array) $data) : $data,
        ], $res->status());
    }

    /** Khách theo đúng dáng ô chọn khách của quầy đang đọc (name/phone). */
    protected function khachGon(array $c): array
    {
        return [
            'id' => (int) ($c['id'] ?? 0),
            'name' => (string) ($c['full_name'] ?? ''),
            'phone' => (string) ($c['phone'] ?? ''),
            'email' => (string) ($c['email'] ?? ''),
            'address' => (string) ($c['address'] ?? ''),
            'tax_code' => (string) ($c['tax_code'] ?? ''),
        ];
    }

    /**
     * Phiếu tính tiền khổ in nhiệt. Trang riêng vì khổ 58/80mm cần @page riêng —
     * nhét vào màn bán hàng thì mỗi Ctrl+P vô tình cũng nhả ra một tờ phiếu.
     */
    public function phieu(int $id)
    {
        if ($ve = $this->veQuayNeuThieuView('v2::pos.receipt')) {
            return $ve;
        }

        try {
            $res = $this->api->order($id);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai phieu that bai', ['id' => $id, 'msg' => $e->getMessage()]);
            abort(404);
        }
        abort_unless($res->successful(), 404);

        return view('v2::pos.receipt', [
            'don' => $res->json('data') ?? [],
            'tenCuaHang' => $this->api->settingString('site_name', config('app.name')),
            'diaChi' => $this->api->settingString('store_address'),
            'dienThoai' => $this->api->settingString('contact_phone'),
            'khoGiay' => request()->query('warehouse') === '58' ? '58' : '80',
        ]);
    }

    /**
     * Nhóm CON TRỰC TIẾP của nhóm gốc "Hàng bán" — nhóm gốc thì ra gần hết kho,
     * nhóm cháu thì hàng nút dài phải cuộn. API hỏng: hàng nút biến mất, ô tìm
     * vẫn bán được.
     */
    protected function nhomHang(): array
    {
        try {
            $res = $this->api->categories(all: true);
            $tatCa = $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('Thu ngan: tai nhom hang that bai', ['msg' => $e->getMessage()]);

            return [];
        }

        $gocId = (int) (collect($tatCa)->firstWhere('slug', 'hang-ban')['id'] ?? 0);
        if ($gocId === 0) {
            return [];
        }

        return collect($tatCa)
            ->filter(fn ($c) => (int) ($c['parent_id'] ?? 0) === $gocId)
            ->map(fn ($c) => ['id' => (int) $c['id'], 'name' => (string) ($c['name'] ?? '')])
            ->values()
            ->all();
    }

    /**
     * % bớt tối đa người đang đăng nhập được tự bấm — hỏi API, chỗ chặn thật.
     * API hỏng thì 0: không biết được bao nhiêu thì cho mức chặt nhất.
     */
    protected function hanMucGiam(): float
    {
        try {
            $res = $this->api->posDiscountLimit();
            if ($res->successful()) {
                return (float) ($res->json('data.limit_percent') ?? 0);
            }
        } catch (\Throwable $e) {
            Log::warning('Thu ngan: tai han muc giam that bai', ['msg' => $e->getMessage()]);
        }

        return 0;
    }
}
