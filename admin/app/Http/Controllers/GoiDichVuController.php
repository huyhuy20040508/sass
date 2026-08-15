<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\HanSuDung;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GoiDichVuController — trang "Các gói dịch vụ" của khu quản trị.
 *
 * Đây là chỗ CHỦ TIỆM tự trả lời ba câu mà trước nay phải gọi điện mới biết: tôi
 * đang dùng gói nào, còn bao nhiêu ngày, gia hạn hết bao nhiêu. Trước khi có
 * trang này, dấu hiệu duy nhất của việc hết hạn là một hôm đăng nhập không được
 * nữa — hợp đồng chỉ hiện trong khu điều hành của nhà cung cấp.
 *
 * CHỈ ĐỌC. Không có nút gia hạn ở đây, và đó là chủ ý: gia hạn đi kèm tiền vào
 * sổ thu, việc đó nằm ở khu điều hành. Trang này dừng ở chỗ nói rõ phải liên hệ
 * ai — một nút "Gia hạn" bấm xong không có gì xảy ra còn tệ hơn là không có nút.
 *
 * Nằm trong nhóm route `admin.manage` (nhân viên KHÔNG vào), cùng mức riêng tư
 * với Khách hàng và Cài đặt: đây là chuyện hợp đồng và tiền giữa chủ tiệm với
 * nhà cung cấp phần mềm. API cũng chặn đúng như vậy.
 */
class GoiDichVuController extends Controller
{
    public const TITLE = 'Các gói dịch vụ';

    /**
     * Đầu mối hỗ trợ — cùng địa chỉ in ở màn hình đăng nhập và trang quên mật
     * khẩu, để người cần gọi không phải đi tìm ở ba chỗ ra ba câu trả lời.
     */
    public const SUPPORT_EMAIL = 'nhuy08052004@gmail.com';

    public function __construct(protected ApiClient $api) {}

    public function index()
    {
        $hopDong = null;
        $bangGia = [];
        $fields = [];
        $error = null;

        try {
            $res = $this->api->goiDichVu();
            if ($res->successful()) {
                $hopDong = $res->json('data.hop_dong');
                $bangGia = $res->json('data.bang_gia') ?? [];
                $fields = $res->json('data.fields') ?? [];
                // Mốc hết hạn vừa đọc được là bản mới nhất — cất vào session để
                // mọi trang khác khoá đúng giây hợp đồng chết, không phải chờ lượt
                // quét nền của máy chủ. Xem HanSuDung.
                HanSuDung::ghiNhan($hopDong);
            } elseif ($res->status() === 404) {
                // 404 ở đây KHÔNG phải "không tìm thấy hợp đồng" — hợp đồng vắng mặt
                // vẫn trả 200 kèm hop_dong = null. Nó nghĩa là nhóm route bên API
                // chưa được đăng ký, tức máy chủ chưa nối được sổ nền tảng. Nói
                // đúng câu đó ra, đừng để người đọc tưởng cửa hàng mình không có
                // hợp đồng nào.
                $error = 'Máy chủ chưa tra được sổ hợp đồng của nhà cung cấp. Thông tin gói dịch vụ tạm thời chưa xem được ở đây — vui lòng liên hệ hỗ trợ.';
            } else {
                Log::warning('Load goi dich vu failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được thông tin gói dịch vụ.';
            }
        } catch (\Throwable $e) {
            Log::error('Load goi dich vu failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được thông tin gói dịch vụ. Kiểm tra kết nối API.';
        }

        $view = view('goi-dich-vu.index', compact('hopDong', 'bangGia', 'fields'));

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Bấm gia hạn một gói → đặt đơn, rồi sang trang thanh toán.
     *
     * KHÔNG chuyển thẳng sang trang của cổng: trang thanh toán của mình còn phải
     * theo dõi đơn (hỏi lại mỗi vài giây) và nói ra kết quả sau khi khách quay
     * về. Nhảy thẳng sang cổng thì lúc khách bấm "quay lại" giữa chừng, không có
     * chỗ nào biết đơn đó đang ở đâu.
     */
    public function datGiaHan(Request $request)
    {
        $du = $request->validate([
            'plan_id' => ['required', 'integer', 'min:1'],
            'so_luong' => ['required', 'integer', 'min:1', 'max:24'],
            'don_vi' => ['required', 'in:thang,nam'],
        ], [
            'plan_id.required' => 'Chưa chọn gói cần gia hạn.',
            'so_luong.*' => 'Số kỳ gia hạn phải là số nguyên từ 1 trở lên.',
            'don_vi.in' => 'Đơn vị chỉ nhận tháng hoặc năm.',
        ]);

        // API nhận ĐƠN VỊ THÁNG cho mọi gói — đó là đơn vị của mọi lượt gia hạn
        // dưới database (`DATE_ADD(..., INTERVAL ? MONTH)`). Quy đổi ở đây chứ
        // không thêm một tham số đơn vị nữa vào API: hai nơi cùng biết cách quy
        // đổi là hai nơi để chúng lệch nhau.
        $soThang = (int) $du['so_luong'] * ($du['don_vi'] === 'nam' ? 12 : 1);

        // Trần 24 tháng do API đặt. Bắt ở đây để người gõ "5 năm" biết ngay, thay
        // vì đi hết một vòng gọi API rồi nhận lỗi.
        if ($soThang > 24) {
            return back()->with('error', 'Một lần gia hạn tối đa 24 tháng (2 năm). Cần dài hơn thì liên hệ để thoả thuận riêng.');
        }

        try {
            $res = $this->api->datGiaHan((int) $du['plan_id'], $soThang);
        } catch (\Throwable $e) {
            Log::error('Dat don gia han that bai', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được máy chủ. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            // 422 trả lỗi theo từng ô; gộp lại thành một câu vì trang này không có
            // form để gắn lỗi vào.
            $loi = $res->json('errors');
            $message = is_array($loi) && $loi
                ? implode(' ', $loi)
                : ($res->json('message') ?: 'Chưa tạo được đơn gia hạn.');

            return back()->with('error', $message);
        }

        return redirect()->route('admin.goi-dich-vu.thanh-toan', $res->json('data.id'));
    }

    /** Trang thanh toán của MỘT đơn. */
    public function thanhToan(int $id)
    {
        $don = null;
        $error = null;

        try {
            $res = $this->api->donGiaHan($id);
            if ($res->successful()) {
                $don = $res->json('data');
            } elseif ($res->status() === 404) {
                abort(404);
            } else {
                $error = $res->json('message') ?: 'Không đọc được đơn gia hạn.';
            }
        } catch (\Throwable $e) {
            Log::error('Doc don gia han that bai', ['id' => $id, 'msg' => $e->getMessage()]);
            $error = 'Không kết nối được máy chủ. Vui lòng thử lại.';
        }

        // Đơn vừa trả tiền xong: rũ cờ khoá ngay để người dùng không phải đợi lượt
        // làm mới định kỳ mới dùng lại được phần mềm (xem HanSuDung).
        if (! empty($don['da_tra'])) {
            HanSuDung::quen();
        }

        $view = view('goi-dich-vu.thanh-toan', ['don' => $don, 'id' => $id]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * JSON cho trang thanh toán hỏi lại mỗi vài giây.
     *
     * Chính lượt gọi này là thứ chốt đơn khi webhook không tới được: API hỏi thẳng
     * cổng, thấy tiền đã vào thì ghi sổ thu và đẩy hạn ngay trong request đó.
     */
    public function trangThaiDon(int $id)
    {
        try {
            $res = $this->api->donGiaHan($id);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Chưa hỏi được trạng thái.'], 503);
        }

        if (! $res->successful()) {
            return response()->json(['message' => $res->json('message') ?: 'Không đọc được đơn.'], $res->status());
        }

        $don = $res->json('data');
        if (! empty($don['da_tra'])) {
            HanSuDung::quen();
        }

        return response()->json(['data' => $don]);
    }
}
