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
     * Hình thức API nhận ở quầy. Bày ra hình thức nào là do chi nhánh bật gì ở
     * Cài đặt → Phương thức thanh toán (xem phuongThuc()). sepay / payos là QR tự
     * động: đơn tạo ra CHỜ TIỀN, cổng báo về mới hoàn tất.
     */
    public const PAYMENT_METHODS = [
        'cash' => 'Tiền mặt',
        'bank_transfer' => 'Chuyển khoản',
        'card' => 'Quẹt thẻ',
        'sepay' => 'QR SePay',
        'payos' => 'QR PayOS',
        'mpos_qr' => 'QR mPOS',
    ];

    /** Bốn ô hình thức của hộp Thanh toán, theo thứ tự của v2. */
    public const O_HINH_THUC = [
        1 => ['ma' => 'cash', 'ten' => 'Tiền mặt', 'icon' => 'fa-money-bill-wave'],
        4 => ['ma' => 'bank_transfer', 'ten' => 'Chuyển khoản', 'icon' => 'fa-building-columns'],
        5 => ['ma' => 'card', 'ten' => 'Quẹt thẻ', 'icon' => 'fa-credit-card'],
        6 => ['ma' => 'auto_qr', 'ten' => 'QR tự động', 'icon' => 'fa-qrcode'],
    ];

    /** Mệnh giá bấm cộng dồn vào ô khách đưa — bấm thì không gõ thừa số 0 được. */
    public const MENH_GIA = [10000, 20000, 50000, 100000, 200000, 500000];

    public function __construct(protected ApiClient $api) {}

    public function index()
    {
        return view('v2::pos.sale', [
            'hanMucGiam' => $this->hanMucGiam(),
            'nhomHang' => $this->nhomHang(),
            'thanhVien' => $this->thanhVien(),
            'nganHang' => $this->nganHang(),
            'phuongThuc' => $this->phuongThuc(),
            'caMo' => $this->caMo(),
            'quaLau' => WorkShiftController::caMoQuaLau($this->caDangMo, $this->api),
            // Luôn nạp: ca có thể bị đóng ở máy khác giữa giờ, lúc đó quầy khoá và cần hộp Mở ca.
            'truc' => WorkShiftController::nguoiTrucKet($this->api),
        ]);
    }

    /**
     * Chi nhánh đang có ca mở không. null = không hỏi được: khi đó màn không tự
     * khoá — API vẫn chặn lúc chốt đơn và màn khoá theo mã CHUA_MO_CA.
     */
    /** Ca đang mở đọc được ở caMo() — cho dải nhắc "ca mở quá lâu". */
    protected ?array $caDangMo = null;

    protected function caMo(): ?bool
    {
        try {
            $res = $this->api->caHienTai();
            if ($res->successful()) {
                $this->caDangMo = $res->json('data');

                return $this->caDangMo !== null;
            }
        } catch (\Throwable $e) {
            Log::info('Load current shift for POS failed', ['msg' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Hình thức chi nhánh đang bật + tài khoản / máy / cổng của từng hình thức.
     *
     * API hỏng thì lùi về hai hình thức quầy vẫn luôn nhận (tiền mặt, chuyển khoản
     * tay) — đứng quầy mà không thu được tiền mặt chỉ vì một lượt đọc cấu hình
     * trục trặc là cái giá không đáng.
     */
    protected function phuongThuc(): array
    {
        try {
            $res = $this->api->phuongThucQuay();
            if ($res->successful()) {
                $o = [];
                foreach ((array) ($res->json('data') ?? []) as $pt) {
                    $loai = (int) ($pt['type'] ?? 0);
                    if (! isset(self::O_HINH_THUC[$loai])) {
                        continue;
                    }
                    $o[] = self::O_HINH_THUC[$loai] + [
                        'md' => ! empty($pt['is_default']),
                        'ct' => array_map(fn ($d) => [
                            'id' => (int) ($d['id'] ?? 0),
                            'loai' => (string) ($d['detail_type'] ?? ''),
                            'md' => ! empty($d['is_default']),
                            'nh' => (string) ($d['bank_name'] ?? ''),
                            'so' => (string) ($d['bank_account'] ?? ''),
                            'chu' => (string) ($d['bank_account_name'] ?? ''),
                            'suaTien' => ! empty($d['edit_amount']),
                            'may' => (string) ($d['device_name'] ?? ''),
                            'phi' => (float) (($d['card_fee'] ?? [])['card_fee_physical'] ?? 0),
                        ], array_values(array_filter((array) ($pt['details'] ?? []),
                            // MoMo chưa nối cổng: không chào ra ở quầy (API cũng không nhận).
                            fn ($d) => ($d['detail_type'] ?? '') !== 'momo'))),
                    ];
                    if ($loai === 6 && end($o)['ct'] === []) {
                        array_pop($o);
                    }
                }
                if ($o !== []) {
                    return $o;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Load POS payment methods failed', ['msg' => $e->getMessage()]);
        }

        return [self::O_HINH_THUC[1] + ['md' => true, 'ct' => []], self::O_HINH_THUC[4] + ['md' => false, 'ct' => []]];
    }

    /** Màn quầy hỏi tiền của đơn QR tự động đã về chưa. */
    public function qrTrangThai(string $code)
    {
        return $this->jsonTuApi(fn () => $this->api->posQrTrangThai($code), 'Không hỏi được trạng thái thanh toán.');
    }

    /**
     * Khách đổi ý khi mã QR đang chờ: thu bằng tiền mặt / chuyển khoản / quẹt thẻ
     * ngay trên chính đơn đó — giữ mã đơn, không lập đơn huỷ rồi đơn mới.
     */
    public function doiHinhThuc(Request $request, int $id)
    {
        $data = $request->validate([
            'payment_method' => 'required|in:cash,bank_transfer,card',
            'payment_detail_id' => 'nullable|integer|min:1',
            'amount_tendered' => 'nullable|numeric|min:0',
        ]);
        $payload = ['payment_method' => $data['payment_method']];
        if (! empty($data['payment_detail_id']) && $data['payment_method'] !== 'cash') {
            $payload['payment_detail_id'] = (int) $data['payment_detail_id'];
        }
        if ($data['payment_method'] === 'cash' && isset($data['amount_tendered'])) {
            $payload['amount_tendered'] = (float) $data['amount_tendered'];
        }

        return $this->jsonTuApi(fn () => $this->api->posDoiHinhThuc($id, $payload), 'Không đổi được hình thức thanh toán.');
    }

    /** Thu ngân huỷ mã QR tự động — đơn huỷ, hàng về kho. */
    public function huyQr(int $id)
    {
        return $this->jsonTuApi(fn () => $this->api->posHuyQr($id), 'Không huỷ được mã QR.');
    }

    /**
     * Tài khoản nhận chuyển khoản ở quầy — cùng cấu hình với thanh toán online
     * (Cài đặt → Thanh toán), để khách quét QR ở quầy hay trên web đều vào một tài khoản.
     */
    protected function nganHang(): array
    {
        $anh = $this->api->settingString('bank_qr_image');
        $anh = $anh !== '' && ! preg_match('#^https?://#i', $anh) ? asset(ltrim($anh, '/')) : $anh;

        // Tài khoản mặc định của CHI NHÁNH đang đứng quầy (Cài đặt → Phương thức
        // thanh toán). Chi nhánh tắt Chuyển khoản thì trả rỗng — quầy báo chưa có
        // tài khoản thay vì in QR của một tài khoản chủ tiệm đã tắt.
        try {
            $res = $this->api->phuongThucQuay();
            if ($res->successful()) {
                foreach ((array) ($res->json('data') ?? []) as $pt) {
                    if ((int) ($pt['type'] ?? 0) !== 4) {
                        continue;
                    }
                    foreach ((array) ($pt['details'] ?? []) as $tk) {
                        if (! empty($tk['is_default'])) {
                            return [
                                'ten' => (string) ($tk['bank_name'] ?? ''),
                                'so' => (string) ($tk['bank_account'] ?? ''),
                                'chu' => (string) ($tk['bank_account_name'] ?? ''),
                                'anh' => $anh,
                            ];
                        }
                    }
                }

                return ['ten' => '', 'so' => '', 'chu' => '', 'anh' => ''];
            }
        } catch (\Throwable $e) {
            Log::warning('Load POS payment methods failed', ['msg' => $e->getMessage()]);
        }

        // API chưa có sổ phương thức (bản cũ) hoặc trục trặc: lùi về trang Cài đặt cũ.
        return [
            'ten' => $this->api->settingString('bank_name'),
            'so' => $this->api->settingString('bank_account_number'),
            'chu' => $this->api->settingString('bank_account_name'),
            'anh' => $anh,
        ];
    }

    /**
     * Hạng đang bật + số tiền mỗi điểm — màn quầy tính trước giảm theo hạng và
     * đổi điểm. Hỏng thì coi như chưa có thẻ thành viên: lúc chốt API vẫn tính.
     */
    protected function thanhVien(): array
    {
        try {
            $res = $this->api->posThanhVien();
            if ($res->successful()) {
                return ['ranks' => $res->json('data.ranks') ?? [], 'money_per_point' => (float) ($res->json('data.money_per_point') ?? 0)];
            }
        } catch (\Throwable $e) {
            Log::info('Thu ngan: doc the thanh vien that bai', ['msg' => $e->getMessage()]);
        }

        return ['ranks' => [], 'money_per_point' => 0];
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
            // Tài khoản / máy / cổng đã chọn — API kiểm nó còn bật ở chi nhánh này.
            'payment_detail_id' => 'nullable|integer|min:1',
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
            // Chương trình đồng giá thu ngân đã chọn — API tính lại giá + hàng tặng.
            'fixed_price_ids' => 'nullable|array|max:20',
            'fixed_price_ids.*' => 'integer|min:1',
            // Chương trình khuyến mại thu ngân đã chọn — API tính lại số giảm + hàng tặng.
            'promotion_program_ids' => 'nullable|array|max:20',
            'promotion_program_ids.*' => 'integer|min:1',
            'use_points' => 'nullable|integer|min:0',
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
        if (! empty($data['payment_detail_id']) && $data['payment_method'] !== 'cash') {
            $payload['payment_detail_id'] = (int) $data['payment_detail_id'];
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
        if (! empty($data['fixed_price_ids'])) {
            $payload['fixed_price_ids'] = array_values(array_unique(array_map('intval', $data['fixed_price_ids'])));
        }
        if (! empty($data['promotion_program_ids'])) {
            $payload['promotion_program_ids'] = array_values(array_unique(array_map('intval', $data['promotion_program_ids'])));
        }
        // Điểm đổi ra tiền chỉ có nghĩa khi đơn gắn khách quen.
        if (! empty($data['user_id']) && (int) ($data['use_points'] ?? 0) > 0) {
            $payload['use_points'] = (int) $data['use_points'];
        }
        return $this->jsonTuApi(fn () => $this->api->posCheckout($payload), 'Không hoàn tất được lượt bán.');
    }

    /**
     * Nút "Khuyến mãi": chương trình khuyến mại giỏ này đủ điều kiện, và nếu chọn
     * thì giảm bao nhiêu, tặng gì. Chỉ để HIỂN THỊ — lúc chốt API tính lại.
     */
    public function khuyenMai(Request $request)
    {
        $data = $request->validate([
            'items' => 'nullable|array|max:50',
            'items.*.product_variant_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'program_ids' => 'nullable|array|max:20',
            'program_ids.*' => 'integer|min:1',
        ]);

        return $this->jsonTuApi(fn () => $this->api->posKhuyenMai([
            'items' => array_map(fn ($it) => [
                'product_variant_id' => (int) $it['product_variant_id'],
                'quantity' => (int) $it['quantity'],
            ], $data['items'] ?? []),
            'program_ids' => array_values(array_map('intval', $data['program_ids'] ?? [])),
        ]), 'Không tính được khuyến mãi.');
    }

    /**
     * Ô "Mã giảm giá": gõ mã rồi Áp dụng thì trừ bao nhiêu vào giỏ NÀY.
     *
     * KHÔNG phải chỉ để hiển thị cho đẹp như hai nút kia: trước đây quầy nhận mã
     * mà không hỏi gì, màn hình vẫn bày tổng chưa trừ, tới lúc chốt API mới trừ
     * — đơn ghi một số, khách trả một số, và máy tính tiền thừa theo số sai.
     */
    public function voucher(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.product_variant_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'code' => 'required|string|max:50',
            'phone' => 'nullable|string|max:20',
        ]);

        return $this->jsonTuApi(fn () => $this->api->posVoucher([
            'items' => array_map(fn ($it) => [
                'product_variant_id' => (int) $it['product_variant_id'],
                'quantity' => (int) $it['quantity'],
            ], $data['items']),
            'code' => strtoupper(trim($data['code'])),
            'phone' => trim((string) ($data['phone'] ?? '')),
        ]), 'Không kiểm được mã giảm giá.');
    }

    /**
     * Nút "Đồng giá": chương trình giỏ này đủ điều kiện, và nếu chọn thì giá từng
     * món + hàng tặng ra sao. Chỉ để HIỂN THỊ — lúc chốt API tính lại từ đầu.
     */
    public function dongGia(Request $request)
    {
        $data = $request->validate([
            'items' => 'nullable|array|max:50',
            'items.*.product_variant_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'fixed_price_ids' => 'nullable|array|max:20',
            'fixed_price_ids.*' => 'integer|min:1',
        ]);

        return $this->jsonTuApi(fn () => $this->api->posDongGia([
            'items' => array_map(fn ($it) => [
                'product_variant_id' => (int) $it['product_variant_id'],
                'quantity' => (int) $it['quantity'],
            ], $data['items'] ?? []),
            'fixed_price_ids' => array_values(array_map('intval', $data['fixed_price_ids'] ?? [])),
        ]), 'Không tính được đồng giá.');
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
            'customer_type' => 'nullable|in:0,1',
            'tax_code' => 'nullable|string|max:20',
        ], [
            'full_name.required' => 'Nhập tên khách.',
            'email.email' => 'Email chưa đúng dạng.',
        ]);

        $payload = array_filter(array_map(fn ($v) => trim((string) $v), $data), fn ($v) => $v !== '');
        $payload['customer_type'] = (int) ($payload['customer_type'] ?? 0);
        // MST chỉ có nghĩa với doanh nghiệp: hồ sơ cá nhân mang MST là hoá đơn ghi sai người.
        if ($payload['customer_type'] !== 1) {
            unset($payload['tax_code']);
        }

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
            'code' => (string) ($c['customer_code'] ?? ''),
            'customer_type' => (int) ($c['customer_type'] ?? 0),
            'name' => (string) ($c['full_name'] ?? ''),
            'phone' => (string) ($c['phone'] ?? ''),
            'email' => (string) ($c['email'] ?? ''),
            'address' => (string) ($c['address'] ?? ''),
            'tax_code' => (string) ($c['tax_code'] ?? ''),
            // Thẻ thành viên: hạng hiện tại và điểm còn đổi được.
            'rank_id' => (int) ($c['rank_id'] ?? 0),
            'rank_name' => (string) ($c['rank_name'] ?? ''),
            'points' => (int) ($c['points'] ?? 0),
        ];
    }

    /**
     * Phiếu tính tiền khổ in nhiệt. Trang riêng vì khổ 58/80mm cần @page riêng —
     * nhét vào màn bán hàng thì mỗi Ctrl+P vô tình cũng nhả ra một tờ phiếu.
     *
     * ?nhung=1: màn quầy nạp trang vào khung ẩn rồi tự gọi in (không mở tab mới),
     * nên trang không tự in và không vẽ thanh nút.
     */
    public function phieu(int $id)
    {
        if ($ve = $this->veQuayNeuThieuView('v2::pos.receipt')) {
            return $ve;
        }
        $don = $this->donDeIn($id);
        $chiNhanh = (int) ($don['shop_id'] ?? 0);
        $mau = $this->mauIn('hd', $chiNhanh);

        return view('v2::pos.receipt', $this->duLieuPhieu($don, $mau, $this->qrCuaDon($don, $mau)) + [
            // ?paper= (nút "Đổi sang khổ…", hộp In lại) thắng khổ của mẫu.
            'khoGiay' => self::khoGiay(request()->query('paper') ?? ($mau['settings']['kho'] ?? '80')),
            'soBan' => self::soBan(request()->query('ban', 1)),
            'tuIn' => ! request()->boolean('nhung'),
        ]);
    }

    /** Hoá đơn khổ A5 / A4 (?kho=a4) cho máy in văn phòng — mẫu "Hoá đơn A5/A4". */
    public function hoaDonA(int $id)
    {
        $don = $this->donDeIn($id);
        $kho = request()->query('kho') === 'a4' ? 'a4' : 'a5';
        $mau = $this->mauIn($kho, (int) ($don['shop_id'] ?? 0));

        return view('v2::pos.invoice-a', $this->duLieuPhieu($don, $mau, $this->qrCuaDon($don, $mau)) + [
            'khoGiay' => $kho,
            'soBan' => self::soBan(request()->query('ban', 1)),
            'tuIn' => ! request()->boolean('nhung'),
        ]);
    }

    /** Phiếu trả hàng khổ nhiệt — mẫu "Phiếu trả hàng" của chi nhánh lập phiếu. */
    public function phieuTra(int $id)
    {
        $this->tatDebugbar();
        try {
            $res = $this->api->posPhieuTra($id);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai phieu tra that bai', ['id' => $id, 'msg' => $e->getMessage()]);
            abort(404);
        }
        abort_unless($res->successful(), 404);
        $tra = $res->json('data') ?? [];

        // Dòng trả không chụp ĐVT: lấy theo dòng của đơn gốc.
        $dvt = collect($tra['order']['items'] ?? [])->pluck('unit_name', 'id');
        $tra['items'] = array_map(fn ($it) => $it + ['unit_name' => (string) ($dvt[$it['order_item_id'] ?? 0] ?? '')], (array) ($tra['items'] ?? []));

        $mau = $this->mauIn('tra', (int) ($tra['shop_id'] ?? 0));
        $caiDat = (array) ($mau['settings'] ?? []);

        return view('v2::pos.return-slip', [
            'tra' => $tra,
            'tenCuaHang' => $this->api->settingString('site_name', config('app.name')),
            'diaChi' => $this->api->settingString('store_address'),
            'hien' => self::hienTheoMau($mau),
            'khoGiay' => self::khoGiay(request()->query('paper') ?? ($caiDat['kho'] ?? '80')),
            'soBan' => self::soBan(request()->query('ban', 1)),
            'tuIn' => ! request()->boolean('nhung'),
        ]);
    }

    /** Trang in đi thẳng ra máy in: debugbar (bản dev) chèn thanh công cụ vào cuối trang thì Chrome in thêm một tờ thừa. */
    protected function tatDebugbar(): void
    {
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
    }

    protected function donDeIn(int $id): array
    {
        $this->tatDebugbar();
        try {
            $res = $this->api->order($id);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai phieu that bai', ['id' => $id, 'msg' => $e->getMessage()]);
            abort(404);
        }
        abort_unless($res->successful(), 404);

        return $res->json('data') ?? [];
    }

    /**
     * Mẫu in của chi nhánh BÁN RA đơn (Cài đặt → Thông số chung → Cài đặt thiết
     * bị), không phải chi nhánh của phiên. Hỏng thì null — in như cũ, đủ mọi dòng:
     * phiếu ra được còn hơn không.
     */
    protected function mauIn(string $ma, int $chiNhanh): ?array
    {
        try {
            $r = $this->api->theoChiNhanh($chiNhanh, fn () => $this->api->mauInQuay($ma));

            return $r->successful() ? $r->json('data') : null;
        } catch (\Throwable $e) {
            Log::warning('Thu ngan: tai mau in that bai', ['ma' => $ma, 'msg' => $e->getMessage()]);

            return null;
        }
    }

    /** QR chỉ có nghĩa với đơn trả bằng chuyển khoản; đơn tiền mặt / thẻ mà in QR thì khách tưởng phải chuyển thêm. */
    protected function qrCuaDon(array $don, ?array $mau): string
    {
        return self::hienTheoMau($mau)('qr') && ($don['payment_method'] ?? '') === 'bank_transfer'
            ? $this->qrChuyenKhoan($don, (int) ($don['shop_id'] ?? 0))
            : '';
    }

    /** Dòng không có trong mẫu (API hỏng, mẫu cũ) thì in — thiếu còn tệ hơn thừa. */
    public static function hienTheoMau(?array $mau): \Closure
    {
        $dong = (array) ($mau['options'] ?? []);

        return fn (string $ma) => (bool) ($dong[$ma] ?? true);
    }

    public static function khoGiay(mixed $kho): string
    {
        return (string) $kho === '58' ? '58' : '80';
    }

    /**
     * Số bản do CHÍNH lệnh in quyết (?ban= của hộp In lại), mặc định 1 — không đọc
     * "Số bản in" của mẫu. Ô đó nằm khuất trong cài đặt: bị đặt 4 (một lượt test
     * ghi đè, 20/10/2026) là mỗi lần bấm nhả 4 tờ mà quầy không biết vì sao.
     */
    public static function soBan(mixed $ban): int
    {
        return max(1, min(99, (int) $ban));
    }

    /**
     * Phần chung của phiếu thật và ô xem trước trong Cài đặt thiết bị — một chỗ
     * quyết định dòng nào in, để xem trước không lệch tờ in ra.
     *
     * Chỉ logo cửa hàng tự tải lên: không in logo mặc định của nền tảng lên phiếu
     * của khách.
     */
    public function duLieuPhieu(array $don, ?array $mau, string $qr): array
    {
        $hien = self::hienTheoMau($mau);
        $caiDat = (array) ($mau['settings'] ?? []);

        return [
            'don' => $don,
            'tenCuaHang' => $this->api->settingString('site_name', config('app.name')),
            'diaChi' => $this->api->settingString('store_address'),
            'dienThoai' => $this->api->settingString('contact_phone'),
            'logo' => $hien('logo') ? $this->duongDanAnh($this->api->settingString('store_logo')) : '',
            'qr' => $qr,
            'hien' => $hien,
            'loiChao' => (string) ($caiDat['footer_txt'] ?? 'Cảm ơn quý khách!'),
            'wifi' => trim((string) ($caiDat['wifi_txt'] ?? '')),
            'chinhSach' => trim((string) ($caiDat['chinhsach_txt'] ?? '')),
        ];
    }

    /** Ảnh lưu đường dẫn tương đối (tải lên máy chủ web) thì đổi ra địa chỉ đầy đủ. */
    protected function duongDanAnh(string $anh): string
    {
        return $anh !== '' && ! preg_match('#^(https?:|data:)#i', $anh) ? asset(ltrim($anh, '/')) : $anh;
    }

    /**
     * Ảnh QR chuyển khoản đúng số tiền của đơn, vào tài khoản mặc định của chi
     * nhánh bán ra đơn — cùng cách màn quầy vẽ QR (qr.sepay.vn). Chưa có tài khoản
     * thì lùi về ảnh QR tĩnh đã tải lên; không có cả hai thì bỏ dòng QR.
     */
    protected function qrChuyenKhoan(array $don, int $chiNhanh): string
    {
        $tong = (int) round((float) ($don['total_amount'] ?? 0));
        $nh = $this->api->theoChiNhanh($chiNhanh, fn () => $this->nganHang());
        if ($tong <= 0 || ($nh['ten'] ?? '') === '' || ($nh['so'] ?? '') === '') {
            return (string) ($nh['anh'] ?? '');
        }

        return 'https://qr.sepay.vn/img?'.http_build_query([
            'acc' => $nh['so'], 'bank' => $nh['ten'], 'amount' => $tong, 'des' => 'TT '.($don['order_code'] ?? ''),
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
