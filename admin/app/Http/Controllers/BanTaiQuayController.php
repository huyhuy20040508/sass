<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bán tại quầy — màn hình thu ngân.
 *
 * VÌ SAO LÀ MỘT TRANG RIÊNG chứ không phải thêm nút vào trang Đơn hàng: hai việc
 * này có nhịp khác hẳn nhau. Trang Đơn hàng là nơi NGỒI XỬ LÝ đơn đã có — lọc,
 * đọc, đổi trạng thái, in tem. Còn quầy là nơi có người đứng đợi: mỗi giây nhìn
 * xuống bàn phím là một giây khách nhìn mình. Nên màn hình này bỏ hết những gì
 * không phục vụ việc tính tiền, và đổi lại thì làm rất tốt đúng ba việc: tìm
 * hàng, cộng tiền, thối tiền.
 *
 * GIÁ KHÔNG NẰM Ở ĐÂY. Trang chỉ gửi lên "mua biến thể nào, mấy cái"; tên, giá,
 * khuyến mãi và tồn kho đều do API tra lại tại thời điểm bấm nút (xem
 * POSCheckoutRequest bên API). Người bán không gõ giá thì cũng không bán sai giá
 * được, và con số ở quầy không bao giờ lệch với con số trên web.
 *
 * Nằm trong nhóm route `admin` (KHÔNG phải `admin.manage`): người đứng quầy là
 * nhân viên, cả tính năng vô nghĩa nếu chỉ chủ cửa hàng bấm được nút thu tiền.
 */
class BanTaiQuayController extends Controller
{
    public const TITLE = 'Bán tại quầy';

    /** Hình thức thanh toán của quầy.
     *
     *  Chỉ có hai, và đó là chủ ý chứ không phải làm dở: đơn quầy được ghi "đã
     *  thanh toán" ngay lúc tạo, nên chỉ nhận hình thức mà tiền ĐÃ về trước khi
     *  khách rời quầy. Cổng thanh toán online phải đợi cổng báo có, ghi nhận
     *  trước là tự tạo một khoản chưa chắc có mà sổ lại nói là đã thu. */
    public const PAYMENT_METHODS = [
        'cash' => 'Tiền mặt',
        'bank_transfer' => 'Chuyển khoản',
    ];

    /** Các mệnh giá gợi ý cho ô tiền khách đưa.
     *
     *  Có mặt vì đây là thao tác lặp lại nhiều nhất ở quầy và cũng là chỗ dễ gõ
     *  nhầm nhất: một số 0 thừa trong ô tiền khách đưa là một lần thối sai. Bấm
     *  một nút thì không gõ nhầm được. */
    public const MENH_GIA = [10000, 20000, 50000, 100000, 200000, 500000];

    public function __construct(protected ApiClient $api) {}

    public function index()
    {
        return view('ban-tai-quay.index');
    }

    /**
     * Chốt một lượt bán.
     *
     * Trả JSON chứ không redirect: màn hình quầy là một trang duy nhất giữ giỏ
     * hàng trong bộ nhớ, tải lại trang giữa chừng là mất giỏ. Bán xong thì trang
     * tự dọn giỏ và sẵn sàng cho khách tiếp theo, không rời đi đâu cả.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            // user_id vắng mặt = khách lẻ. Quầy phải bán được cho người không có
            // tài khoản, ép tạo hồ sơ chỉ để tính tiền một lần là thứ không ai làm
            // giữa lúc có người đứng đợi.
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
        ], [
            'items.required' => 'Chưa có sản phẩm nào trong giỏ.',
            'items.min' => 'Chưa có sản phẩm nào trong giỏ.',
        ]);

        // CỐ Ý không gửi tên/giá của từng dòng lên API dù màn hình có sẵn (nó vừa
        // hiển thị chúng cho khách xem). Gửi lên là mở đường cho giá trên màn
        // hình khác giá trong sổ — mà giá đúng thì API tra lại được, còn giá sai
        // thì không ai phát hiện ra cho tới lúc đối soát cuối tháng.
        $payload = [
            'payment_method' => $data['payment_method'],
            'customer_name' => trim((string) ($data['customer_name'] ?? '')),
            'customer_phone' => trim((string) ($data['customer_phone'] ?? '')),
            'voucher_code' => trim((string) ($data['voucher_code'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
            'items' => array_map(fn ($it) => [
                'product_variant_id' => (int) $it['product_variant_id'],
                'quantity' => (int) $it['quantity'],
            ], $data['items']),
        ];
        if (! empty($data['user_id'])) {
            $payload['user_id'] = (int) $data['user_id'];
        }
        // Chỉ gửi tiền khách đưa khi thu tiền mặt: con số ấy không có nghĩa gì với
        // một lệnh chuyển khoản. Gửi null thay vì bỏ hẳn cũng được, nhưng bỏ hẳn
        // thì payload nói đúng điều đang xảy ra.
        if ($data['payment_method'] === 'cash' && isset($data['amount_tendered'])) {
            $payload['amount_tendered'] = (float) $data['amount_tendered'];
        }

        try {
            $res = $this->api->posCheckout($payload);
        } catch (\Throwable $e) {
            Log::error('POS checkout failed', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }

        if (! $res->successful()) {
            Log::warning('POS checkout rejected', ['status' => $res->status(), 'body' => $res->json('message')]);

            // In NGUYÊN câu API trả về. Đây là chỗ duy nhất người bán biết vì sao
            // không bán được, và những câu ấy đã nói rõ việc cần làm: hết hàng thì
            // kèm tên món và số còn lại, đưa thiếu tiền thì kèm số còn thiếu. Thay
            // bằng một câu chung chung là bắt họ đoán giữa lúc khách đang đợi.
            return response()->json([
                'message' => $res->json('message') ?: 'Không hoàn tất được lượt bán.',
            ], $res->status() === 500 ? 502 : $res->status());
        }

        return response()->json(['data' => $res->json('data')]);
    }
}
