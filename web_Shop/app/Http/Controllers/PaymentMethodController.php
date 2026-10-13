<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cài đặt → Phương thức thanh toán, theo chi nhánh đang làm.
 *
 * Dựng theo màn system/payment-method của ordertable v2. Mọi luật (đúng một mặc
 * định, còn ít nhất một dòng bật, mỗi máy một cấu hình…) nằm ở API; ở đây chỉ
 * chuyển tiếp và trả JSON để trang nạp lại sau mỗi lượt bấm.
 */
class PaymentMethodController extends Controller
{
    public const TITLE = 'Phương thức thanh toán';

    /** Loại chi tiết hợp lệ — chặn sớm thứ rác trước khi tới API. */
    protected const LOAI = ['bank_transfer', 'mpos_device', 'physical_device', 'sepay', 'payos', 'mpos_qr', 'momo'];

    public function __construct(protected ApiClient $api) {}

    public function index()
    {
        $data = null;
        $error = null;

        try {
            $res = $this->api->phuongThucTT();
            if ($res->successful()) {
                $data = $res->json('data');
            } else {
                Log::warning('Load payment methods failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được phương thức thanh toán.';
            }
        } catch (\Throwable $e) {
            Log::error('Load payment methods failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được phương thức thanh toán. Kiểm tra kết nối API.';
        }

        return view('v2::settings.payment-methods', [
            'data' => $data,
            'loiTai' => $error,
            'tenChiNhanh' => $this->tenChiNhanh((int) ($data['shop_id'] ?? 0)),
            'arrTitle' => [['name' => self::TITLE, 'route' => '']],
        ]);
    }

    /** Nạp lại sau mỗi lượt ghi — trang vẽ lại từ đúng dữ liệu API đang giữ. */
    public function data(): JsonResponse
    {
        return $this->json(fn () => $this->api->phuongThucTT(), null, true);
    }

    public function trangThai(Request $request, int $id): JsonResponse
    {
        $bat = $request->boolean('status');

        return $this->json(fn () => $this->api->batTatPhuongThucTT($id, $bat), $bat ? 'Đã bật.' : 'Đã tắt.');
    }

    public function macDinh(int $id): JsonResponse
    {
        return $this->json(fn () => $this->api->macDinhPhuongThucTT($id), 'Đã đặt mặc định ở quầy.');
    }

    public function taoChiTiet(Request $request, int $id): JsonResponse
    {
        return $this->json(fn () => $this->api->taoChiTietTT($id, $this->chiTiet($request)), 'Đã thêm.');
    }

    public function suaChiTiet(Request $request, int $id, int $chiTiet): JsonResponse
    {
        return $this->json(fn () => $this->api->suaChiTietTT($id, $chiTiet, $this->chiTiet($request)), 'Đã lưu.');
    }

    public function trangThaiChiTiet(Request $request, int $id, int $chiTiet): JsonResponse
    {
        $bat = $request->boolean('status');

        return $this->json(fn () => $this->api->batTatChiTietTT($id, $chiTiet, $bat), $bat ? 'Đã bật.' : 'Đã tắt.');
    }

    public function macDinhChiTiet(int $id, int $chiTiet): JsonResponse
    {
        return $this->json(fn () => $this->api->macDinhChiTietTT($id, $chiTiet), 'Đã đặt làm mặc định.');
    }

    public function xoaChiTiet(int $id, int $chiTiet): JsonResponse
    {
        return $this->json(fn () => $this->api->xoaChiTietTT($id, $chiTiet), 'Đã xoá.');
    }

    /**
     * Nút "Kiểm tra kết nối" của cổng QR.
     *
     * Chỉ nhận URL nằm dưới địa chỉ công khai của chính API: với PayOS, URL này
     * được đăng ký thẳng làm nơi nhận báo tiền — để lọt một địa chỉ lạ vào là
     * tiền của tiệm báo về chỗ khác.
     */
    public function kiemTra(Request $request, int $id, int $chiTiet): JsonResponse
    {
        $goc = rtrim((string) config('api.public_url'), '/').'/payments/';
        $url = (string) $request->input('webhook_url', '');
        if (! str_starts_with($url, $goc) || strlen($url) > 500) {
            return response()->json(['message' => 'URL webhook không hợp lệ.'], 422);
        }

        return $this->json(fn () => $this->api->kiemTraCongTT($id, $chiTiet, $url), null, true);
    }

    public function taoMay(Request $request): JsonResponse
    {
        $may = $this->may($request) + ['is_physical' => $request->boolean('is_physical')];

        return $this->json(fn () => $this->api->taoMayQuetThe($may), 'Đã thêm máy.');
    }

    /** Loại máy (mPOS / độc lập) không đổi được: cấu hình đang gắn phụ thuộc vào nó. */
    public function suaMay(Request $request, int $id): JsonResponse
    {
        $may = $this->may($request);

        return $this->json(fn () => $this->api->suaMayQuetThe($id, $may), 'Đã lưu máy.');
    }

    public function xoaMay(int $id): JsonResponse
    {
        return $this->json(fn () => $this->api->xoaMayQuetThe($id), 'Đã xoá máy.');
    }

    protected function may(Request $request): array
    {
        $du = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'code.required' => 'Nhập POS ID.',
            'name.required' => 'Nhập tên máy.',
        ]);

        return [
            'code' => trim($du['code']),
            'name' => trim($du['name']),
            'description' => trim((string) ($du['description'] ?? '')),
        ];
    }

    // ---------------------------------------------------------------------

    /**
     * Gói dữ liệu một chi tiết. `data` chỉ nhận chuỗi: API so từng ô theo tên,
     * mảng lồng hay số lẻ kiểu float ở đây chỉ sinh ra lỗi khó đọc bên kia.
     */
    protected function chiTiet(Request $request): array
    {
        $loai = (string) $request->input('detail_type', '');
        $data = [];
        foreach ((array) $request->input('data', []) as $k => $v) {
            if (is_string($k) && (is_scalar($v) || $v === null)) {
                $data[$k] = (string) $v;
            }
        }

        $out = ['data' => $data, 'is_default' => $request->boolean('is_default')];
        if (in_array($loai, self::LOAI, true)) {
            $out['detail_type'] = $loai;
        }
        if ($request->has('status')) {
            $out['status'] = $request->boolean('status');
        }
        if ($request->filled('device_id')) {
            $out['device_id'] = (int) $request->input('device_id');
        }

        return $out;
    }

    /**
     * Chuyển tiếp một lượt gọi API thành JSON cho trang.
     *
     * Lỗi giữ nguyên mã và `errors` theo từng ô của API để hộp thoại tô đỏ đúng
     * ô; `message` là câu in ra toast.
     */
    protected function json(callable $call, ?string $ok, bool $kemData = false): JsonResponse
    {
        try {
            /** @var Response $res */
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Payment method API call failed', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }

        if ($res->successful()) {
            if (! $kemData) {
                // Website đọc cấu hình thanh toán qua bản cache 5 phút của trang quản trị.
                Cache::forget(ApiClient::khoaCacheSettings());
            }

            return response()->json(array_filter([
                'message' => $ok,
                'data' => $res->json('data'),
            ], fn ($v) => $v !== null));
        }

        $loi = $res->json('errors');
        if (is_array($loi)) {
            unset($loi['ma']);
        }
        $cau = (string) ($res->json('message') ?: 'Không lưu được. Vui lòng thử lại.');
        if ($res->status() === 422 && is_array($loi) && $loi !== []) {
            $cau = 'Thông tin chưa hợp lệ — xem lại các ô báo đỏ.';
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
