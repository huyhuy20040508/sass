<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Cài đặt → Thông số chung → Cài đặt thiết bị, theo chi nhánh đang làm.
 *
 * Dựng theo system/parameter/setup-store của ordertable v2 (bản thiết kế đã
 * duyệt: design/html/thiet-bi.html). Một trang, bốn khung — Tổng quan, Máy in,
 * Mẫu in, Máy quẹt thẻ — đổi khung bằng ?v= không tải lại trang. Mọi luật nằm
 * ở API; ở đây chỉ chuyển tiếp và trả JSON.
 *
 * Máy quẹt thẻ là payment_devices của Phương thức thanh toán: đọc và ghi qua
 * chính các đường của PaymentMethodController, không có bản thứ hai.
 */
class DeviceSettingController extends Controller
{
    public const TITLE = 'Cài đặt thiết bị';

    /** Bốn khung của trang — khoá là giá trị ?v=. */
    public const VIEWS = ['tong-quan', 'may-in', 'mau-in', 'quet-the'];

    public function __construct(protected ApiClient $api) {}

    public function index()
    {
        [$data, $loiTai] = $this->doc(fn () => $this->api->thietBi(), 'Không tải được cài đặt thiết bị.');

        // Máy quẹt thẻ cần quyền Phương thức thanh toán — thiếu quyền thì riêng
        // khung đó báo, ba khung còn lại vẫn dùng được.
        [$pm, $loiPM] = $this->doc(fn () => $this->api->phuongThucTT(), 'Không tải được máy quẹt thẻ.');

        return view('v2::settings.devices', [
            'data' => $data,
            'pm' => $pm,
            'loiTai' => $loiTai,
            'loiPM' => $loiPM,
            'tenChiNhanh' => $this->tenChiNhanh((int) ($data['shop_id'] ?? 0)),
            'arrTitle' => [['name' => ParameterController::TITLE, 'route' => ''], ['name' => self::TITLE, 'route' => '']],
        ]);
    }

    /** Nạp lại sau mỗi lượt ghi: thiết bị + máy quẹt thẻ, đúng như lúc mở trang. */
    public function data(): JsonResponse
    {
        [$data, $loi] = $this->doc(fn () => $this->api->thietBi(), 'Không tải được cài đặt thiết bị.');
        if ($loi !== null) {
            return response()->json(['message' => $loi], 502);
        }
        [$pm, $loiPM] = $this->doc(fn () => $this->api->phuongThucTT(), 'Không tải được máy quẹt thẻ.');

        return response()->json(['data' => $data, 'pm' => $pm, 'loi_pm' => $loiPM]);
    }

    public function taoMayIn(Request $request): JsonResponse
    {
        return $this->json(fn () => $this->api->taoMayIn($this->mayIn($request)), 'Đã thêm máy in.');
    }

    public function suaMayIn(Request $request, int $id): JsonResponse
    {
        return $this->json(fn () => $this->api->suaMayIn($id, $this->mayIn($request)), 'Đã lưu máy in.');
    }

    public function trangThaiMayIn(Request $request, int $id): JsonResponse
    {
        $bat = $request->boolean('is_active');

        return $this->json(fn () => $this->api->batTatMayIn($id, $bat), $bat ? 'Đã bật máy in.' : 'Đã tắt máy in.');
    }

    public function xoaMayIn(int $id): JsonResponse
    {
        return $this->json(fn () => $this->api->xoaMayIn($id), 'Đã xoá máy in.');
    }

    public function taoGanPhieu(Request $request): JsonResponse
    {
        return $this->json(fn () => $this->api->taoGanPhieu($this->ganPhieu($request)), 'Đã gán phiếu.');
    }

    public function suaGanPhieu(Request $request, int $id): JsonResponse
    {
        return $this->json(fn () => $this->api->suaGanPhieu($id, $this->ganPhieu($request)), 'Đã lưu.');
    }

    public function trangThaiGanPhieu(Request $request, int $id): JsonResponse
    {
        return $this->json(fn () => $this->api->batTatGanPhieu($id, $request->boolean('is_active')), 'Đã lưu.');
    }

    public function xoaGanPhieu(int $id): JsonResponse
    {
        return $this->json(fn () => $this->api->xoaGanPhieu($id), 'Đã gỡ.');
    }

    public function luuNutIn(Request $request, string $ma): JsonResponse
    {
        $phieu = array_values(array_filter((array) $request->input('slips', []), 'is_string'));

        return $this->json(fn () => $this->api->luuNutIn($ma, [
            'slips' => $phieu,
            'is_active' => $request->boolean('is_active'),
        ]), 'Đã lưu nút in.');
    }

    /**
     * Một lượt tự lưu của khung Mẫu in. Không có toast: khung đó tự ghi "Đã lưu
     * lúc …" ở đầu trang, bật toast mỗi lần tick là ồn.
     */
    public function luuMauIn(Request $request, string $ma): JsonResponse
    {
        $dong = [];
        foreach ((array) $request->input('options', []) as $k => $v) {
            if (is_string($k)) {
                $dong[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            }
        }
        $st = [];
        foreach ((array) $request->input('settings', []) as $k => $v) {
            if (! is_string($k) || ! is_scalar($v)) {
                continue;
            }
            // Ô số đi dạng số — API nhận int, chuỗi "2" là lỗi khó đọc bên kia.
            $st[$k] = in_array($k, ['ban', 'cot', 'co'], true) ? (int) $v : (string) $v;
        }

        return $this->json(fn () => $this->api->luuMauIn($ma, ['options' => (object) $dong, 'settings' => (object) $st]), null);
    }

    /** Mẫu có trang in thật để xem trước; tem / kết ca vẫn vẽ bằng JS của trang. */
    public const XEM_THAT = ['hd', 'a5', 'a4', 'tra'];

    /**
     * Ô xem trước của khung Mẫu in: vẽ BẰNG CHÍNH trang phiếu thật (pos/receipt,
     * pos/invoice-a, pos/return-slip) với số liệu mẫu và các lựa chọn đang sửa —
     * chưa cần lưu. Hai bản vẽ (JS xem trước, Blade in thật) là sớm muộn lệch nhau.
     */
    public function xemTruoc(Request $request, string $ma)
    {
        abort_unless(in_array($ma, self::XEM_THAT, true), 404);
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        $mau = [
            'options' => array_map(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN), (array) $request->input('options', [])),
            'settings' => array_filter((array) $request->input('settings', []), 'is_scalar'),
        ];
        $hien = PosController::hienTheoMau($mau);
        $pos = app(PosController::class);

        if ($ma === 'tra') {
            return view('v2::pos.return-slip', [
                'tra' => self::PHIEU_TRA_MAU,
                'tenCuaHang' => $this->api->settingString('site_name', config('app.name')),
                'diaChi' => $this->api->settingString('store_address'),
                'hien' => $hien,
                'khoGiay' => PosController::khoGiay($mau['settings']['kho'] ?? '80'),
                'soBan' => 1,
                'tuIn' => false,
            ]);
        }

        // Đơn mẫu trả tiền mặt để thấy dòng khách đưa / tiền thối; dòng QR (chỉ in cho
        // đơn chuyển khoản) vẫn hiện theo công tắc để biết nó nằm đâu. Ảnh QR dùng số
        // tài khoản giả — xem trước không cần tiền vào đâu cả.
        $qr = $hien('qr') ? 'https://qr.sepay.vn/img?'.http_build_query(['acc' => '0123456789', 'bank' => 'MBBank', 'amount' => self::DON_MAU['total_amount'], 'des' => 'TT '.self::DON_MAU['order_code']]) : '';
        $du = $pos->duLieuPhieu(self::DON_MAU, $mau, $qr) + ['soBan' => 1, 'tuIn' => false];

        return $ma === 'hd'
            ? view('v2::pos.receipt', $du + ['khoGiay' => PosController::khoGiay($mau['settings']['kho'] ?? '80')])
            : view('v2::pos.invoice-a', $du + ['khoGiay' => $ma]);
    }

    /** Đơn mẫu cho ô xem trước — đủ mọi loại dòng để thấy dòng nào bật/tắt ở đâu. */
    protected const DON_MAU = [
        'order_code' => 'HD000245', 'placed_at' => '2026-10-13 10:42:00', 'payment_method' => 'cash',
        'created_by_name' => 'Nguyễn Thu Hà', 'recipient_name' => 'Trần Minh Anh', 'recipient_phone' => '0912 345 678',
        'items' => [
            ['product_name' => 'Sạc nhanh 20W USB-C', 'variant_name' => 'Trắng', 'unit_name' => 'Cái', 'quantity' => 2, 'unit_price' => 290000, 'discount_amount' => 58000, 'discount_percent' => 10],
            ['product_name' => 'Tai nghe Bluetooth Air Pro', 'unit_name' => 'Cái', 'quantity' => 1, 'unit_price' => 1290000, 'discount_amount' => 0],
            ['product_name' => 'Cáp USB-C 1m', 'unit_name' => 'Sợi', 'quantity' => 1, 'unit_price' => 0, 'is_gift' => true],
            ['product_name' => 'Ốp lưng iPhone 15', 'variant_name' => 'Trong suốt', 'unit_name' => 'Cái', 'quantity' => 1, 'unit_price' => 150000, 'discount_amount' => 0],
        ],
        'discount_amount' => 20000, 'rank_discount' => 20000, 'vat_amount' => 154000,
        'total_amount' => 1946000, 'amount_tendered' => 2000000, 'change_amount' => 54000,
        'points_earned' => 19, 'thanh_vien' => ['hang' => 'Bạc', 'diem' => 1250], 'note' => 'Gói quà giúp khách',
    ];

    protected const PHIEU_TRA_MAU = [
        'return_code' => 'TH000012', 'created_at' => '2026-10-13 15:20:00', 'reason' => 'defective', 'reason_note' => 'Sạc không vào điện',
        'refund_method' => 'cash', 'items_amount' => 261000, 'deduction' => 0, 'refund_amount' => 261000,
        'items' => [['product_name' => 'Sạc nhanh 20W USB-C', 'variant_name' => 'Trắng', 'unit_name' => 'Cái', 'quantity' => 1, 'unit_price' => 261000, 'total_price' => 261000]],
        'order' => ['order_code' => 'HD000245', 'placed_at' => '2026-10-13 10:42:00', 'created_by_name' => 'Nguyễn Thu Hà', 'recipient_name' => 'Trần Minh Anh', 'recipient_phone' => '0912 345 678', 'payment_method' => 'cash'],
    ];

    public function macDinhMauIn(string $ma): JsonResponse
    {
        return $this->json(fn () => $this->api->macDinhMauIn($ma), 'Đã đưa mẫu về mặc định.');
    }

    // ---------------------------------------------------------------------

    protected function mayIn(Request $request): array
    {
        $out = [
            'name' => trim((string) $request->input('name', '')),
            'conn_type' => (string) $request->input('conn_type', ''),
            'address' => trim((string) $request->input('address', '')),
            'paper' => (string) $request->input('paper', ''),
            'dpi' => (int) $request->input('dpi', 0),
        ];
        if ($request->has('is_active')) {
            $out['is_active'] = $request->boolean('is_active');
        }

        return $out;
    }

    protected function ganPhieu(Request $request): array
    {
        $out = [
            'slip_type' => (string) $request->input('slip_type', ''),
            'printer_id' => (int) $request->input('printer_id', 0),
            'all_categories' => $request->boolean('all_categories'),
            'category_ids' => array_values(array_filter(array_map('intval', (array) $request->input('category_ids', [])))),
        ];
        if ($request->has('is_active')) {
            $out['is_active'] = $request->boolean('is_active');
        }

        return $out;
    }

    /** @return array{0: ?array, 1: ?string} [dữ liệu, câu lỗi] */
    protected function doc(callable $call, string $loiChung): array
    {
        try {
            /** @var Response $res */
            $res = $call();
            if ($res->successful()) {
                return [$res->json('data'), null];
            }
            Log::warning('Load device settings failed', ['status' => $res->status()]);

            return [null, $res->status() === 403
                ? 'Tài khoản chưa được cấp quyền xem mục này.'
                : ($res->json('message') ?: $loiChung)];
        } catch (\Throwable $e) {
            Log::error('Load device settings failed', ['msg' => $e->getMessage()]);

            return [null, $loiChung.' Kiểm tra kết nối API.'];
        }
    }

    /**
     * Chuyển tiếp một lượt gọi API thành JSON cho trang — giữ nguyên mã và
     * `errors` theo từng ô để hộp thoại tô đỏ đúng ô.
     */
    protected function json(callable $call, ?string $ok): JsonResponse
    {
        try {
            /** @var Response $res */
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Device setting API call failed', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }

        if ($res->successful()) {
            return response()->json(array_filter([
                'message' => $ok,
                'data' => $res->json('data'),
            ], fn ($v) => $v !== null));
        }

        $loi = $res->json('errors');
        $cau = (string) ($res->json('message') ?: 'Không lưu được. Vui lòng thử lại.');
        if ($res->status() === 422 && is_array($loi) && $loi !== []) {
            $cau = 'Thông tin chưa hợp lệ — xem lại các ô báo đỏ.';
        }
        if ($res->status() === 403) {
            $cau = 'Tài khoản chưa được cấp quyền sửa cài đặt thiết bị.';
        }

        return response()->json([
            'message' => $cau,
            'errors' => is_array($loi) && $loi !== [] ? $loi : null,
        ], $res->status() >= 400 ? $res->status() : 422);
    }

    protected function tenChiNhanh(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        try {
            foreach ((array) ($this->api->chiNhanh()->json('data') ?? []) as $cn) {
                if ((int) ($cn['id'] ?? 0) === $id) {
                    return (string) ($cn['name'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Load chi nhanh name failed', ['msg' => $e->getMessage()]);
        }

        return '';
    }
}
