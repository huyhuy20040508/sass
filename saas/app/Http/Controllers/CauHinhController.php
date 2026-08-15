<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CauHinhController — màn hình Cài đặt của khu điều hành.
 *
 * Cấu hình của NHÀ CUNG CẤP, không của cửa hàng nào. Hôm nay đúng một nhóm:
 * PHƯƠNG THỨC THANH TOÁN — khách trả tiền gia hạn vào tài khoản nào, ghi nội
 * dung gì.
 *
 * Vì sao nhóm này đáng có một màn hình riêng: từ khi khách tự gia hạn được,
 * số tài khoản không còn là một câu nhắn qua Zalo nữa mà là DỮ LIỆU trang gia
 * hạn của họ đọc ra. Sai một chữ số ở đây là tiền của khách đi vào tài khoản
 * người khác, nên nó cần một chỗ có kiểm tra, có lịch sử sửa, không phải một
 * dòng trong .env.
 *
 * Không có bảng nào ở Laravel (app này không nối MySQL):
 *   Đọc  GET /platform/cau-hinh
 *   Ghi  PUT /platform/cau-hinh   (chỉ owner/operator)
 */
class CauHinhController extends Controller
{
    public function __construct(protected ApiClient $api) {}

    /** Trang "Phương thức thanh toán". */
    public function thanhToan()
    {
        $values = [];
        $fields = [];
        $khoaMaHoa = false;
        $loi = null;

        try {
            $res = $this->api->cauHinh();
            if ($res->successful()) {
                $values = $res->json('data.values') ?? [];
                $fields = $res->json('data.fields') ?? [];
                // Máy chủ đã khai PLATFORM_SECRET_KEY chưa. Chưa thì mọi ô bí mật
                // (khoá PayOS) không lưu được, và màn hình nói trước điều đó thay vì
                // để người dùng gõ xong rồi bấm Lưu và nhận lỗi.
                $khoaMaHoa = (bool) $res->json('data.khoa_ma_hoa');
            } else {
                $loi = $res->json('message') ?: 'Không đọc được cấu hình thanh toán.';
            }
        } catch (\Throwable $e) {
            Log::error('Doc cau hinh nen tang that bai', ['msg' => $e->getMessage()]);
            $loi = 'Không kết nối được máy chủ API. Vui lòng thử lại.';
        }

        return view('cai-dat.thanh-toan', [
            'values' => $values,
            'fields' => $fields,
            'khoaMaHoa' => $khoaMaHoa,
            'loi' => $loi,
            // Máy chủ mới là nơi chặn thật (support gọi PUT vào sẽ nhận 403);
            // đây chỉ để không mời người chỉ-đọc bấm một nút sẽ bị từ chối.
            'ghiDuoc' => in_array(data_get(session('api.user'), 'role'), ['owner', 'operator'], true),
        ]);
    }

    /** Lưu. Toàn bộ luật kiểm dữ liệu nằm ở API — xem service.CauHinhNenTangService. */
    public function luuThanhToan(Request $request)
    {
        $items = $request->input('items');
        if (! is_array($items) || $items === []) {
            return back()->with('error', 'Không có thay đổi nào để lưu.');
        }

        // Ô tích không gửi gì khi bỏ chọn, nên form đã kèm một hidden "0" đứng
        // trước — tới đây chỉ cần ép về chuỗi, vì API chỉ nhận map chuỗi => chuỗi.
        $items = array_map(fn ($v) => (string) $v, $items);

        try {
            $res = $this->api->luuCauHinh($items);
        } catch (\Throwable $e) {
            Log::error('Luu cau hinh nen tang that bai', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            // 422 trả lỗi theo TỪNG KHOÁ; gộp lại thành một câu thay vì chỉ in
            // "dữ liệu không hợp lệ" — người sửa cần biết ô nào sai. Giữ luôn ô
            // vừa gõ (withInput) để họ không phải điền lại từ đầu.
            $loi = $res->json('errors');
            $message = is_array($loi) && $loi
                ? implode(' ', $loi)
                : ($res->json('message') ?: 'Không lưu được cấu hình thanh toán.');

            return back()->withInput()->with('error', $message);
        }

        return redirect()->route('platform.cai-dat.thanh-toan')
            ->with('success', 'Đã lưu cấu hình thanh toán.');
    }
}
