<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Chi nhánh — các ĐIỂM BÁN của chính cửa hàng này (bảng `shops` bên API).
 *
 * ĐỌC KỸ HAI CHỮ NÀY: "cửa hàng" trong cả dự án nghĩa là một khách đã mua phần
 * mềm; "chi nhánh" là điểm bán nằm TRONG cửa hàng đó. Trang này quản lý vế thứ
 * hai — kho miền Bắc, cửa hàng Quận 3, quầy trong trung tâm thương mại.
 *
 * SỐ CHI NHÁNH BỊ HỢP ĐỒNG CHẶN. `max_shops` là điều khoản đã ký, và nó chính là
 * thứ phân biệt gói Chuỗi với hai gói dưới. Hết chỗ thì API trả 409 kèm câu nói
 * rõ đang dùng bao nhiêu trên bao nhiêu — trang này in nguyên câu đó ra, vì
 * người đọc cần biết trần là bao nhiêu mới quyết được nên đóng bớt hay nâng gói.
 *
 * Nằm trong nhóm route `admin.manage` (nhân viên KHÔNG vào), cùng mức riêng tư
 * với Người dùng và Cài đặt: mở thêm một điểm bán là quyết định có tiền đứng sau.
 * API chặn đúng như vậy.
 */
class ChiNhanhController extends Controller
{
    public const TITLE = 'Chi nhánh';

    public const EMPTY_TEXT = 'Chưa có chi nhánh nào. Bấm "Mở chi nhánh" để thêm điểm bán.';

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách chi nhánh, kèm hạn mức đã ký để biết còn mấy chỗ. */
    public function index()
    {
        $error = null;
        $list = [];

        try {
            $res = $this->api->chiNhanh();
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
            } else {
                Log::warning('Load chi nhanh failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách chi nhánh.';
            }
        } catch (\Throwable $e) {
            Log::error('Load chi nhanh failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách chi nhánh. Kiểm tra kết nối API.';
        }

        $view = view('chi-nhanh.index', [
            'list' => $list,
            'hanMuc' => $this->hanMuc(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Mở thêm một chi nhánh. */
    public function store(Request $request)
    {
        return $this->send(fn () => $this->api->taoChiNhanh($this->validated($request)), 'Đã mở chi nhánh mới.');
    }

    /** Sửa thông tin một chi nhánh. */
    public function update(Request $request, int $id)
    {
        return $this->send(fn () => $this->api->suaChiNhanh($id, $this->validated($request)), 'Đã cập nhật chi nhánh.');
    }

    /**
     * Đổi CHI NHÁNH ĐANG LÀM VIỆC (ô chọn ở thanh trên cùng).
     *
     * Chỉ ghi vào phiên rồi quay lại đúng trang vừa đứng: từ lượt gọi API kế
     * tiếp, ApiClient tự đính chi nhánh này vào mọi request (xem
     * ApiClient::KHOA_CHI_NHANH), nên trang kho, trang đơn và mọi thao tác ghi
     * đều đổi theo cùng một lúc.
     *
     * KHÔNG tự xác minh chi nhánh ở đây: API mới là nơi tra sổ và từ chối chi
     * nhánh của cửa hàng khác (middleware.ChiNhanhDangLam). Chép luật sang đây
     * là để hai bản lệch nhau, mà bản ở xa người dùng hơn mới là bản quyết định.
     */
    public function dangLam(Request $request)
    {
        $du = $request->validate(['id' => ['required', 'integer', 'min:0']]);

        // 0 = xem GỘP mọi chi nhánh: bỏ hẳn khoá khỏi phiên thay vì ghi số 0, để
        // ApiClient không gửi header nào cả.
        if ((int) $du['id'] === 0) {
            session()->forget(ApiClient::KHOA_CHI_NHANH);
        } else {
            session([ApiClient::KHOA_CHI_NHANH => (int) $du['id']]);
        }

        return back();
    }

    /** Xoá một chi nhánh (xoá mềm bên API — dữ liệu cũ vẫn tra lại được). */
    public function destroy(int $id)
    {
        return $this->send(fn () => $this->api->xoaChiNhanh($id), 'Đã xoá chi nhánh.');
    }

    /**
     * Hạn mức chi nhánh của hợp đồng: đang dùng mấy trên mấy.
     *
     * Đọc từ trang gói dịch vụ (cùng một API) chứ không đếm lại ở đây: trần là
     * điều khoản đã ký, nằm bên sổ nền tảng, và tự suy ra một con số ở tầng giao
     * diện là mở đường cho hai nơi nói hai câu khác nhau.
     *
     * Hỏng thì trả null và trang chỉ bớt một dòng thông tin — không chặn ai khỏi
     * việc quản lý chi nhánh chỉ vì không tra được hợp đồng.
     */
    protected function hanMuc(): ?array
    {
        try {
            $res = $this->api->goiDichVu();
            if (! $res->successful()) {
                return null;
            }
            $tran = $res->json('data.hop_dong.chi_nhanh');
            if ($tran === null) {
                return null;
            }

            return [
                // 0 = không giới hạn (bản dịch của 'vo_han' bên bảng giá), KHÁC hẳn
                // "không được cái nào".
                'tran' => (int) $tran,
                'dang_dung' => $res->json('data.da_dung.chi_nhanh'),
                'ten_goi' => $res->json('data.hop_dong.ten_goi'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Load han muc chi nhanh failed', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Kiểm dữ liệu của form.
     *
     * Cố ý KHÔNG kiểm khuôn mã ở đây: luật của mã chi nhánh nằm bên API (chữ
     * thường không dấu, 2–30 ký tự) và nó còn phải kiểm trùng nữa. Chép luật sang
     * đây là để hai bản lệch nhau vào một ngày nào đó — trang web từ chối một mã
     * mà API chấp nhận, hoặc ngược lại.
     */
    protected function validated(Request $request): array
    {
        $du = $request->validate([
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Chưa nhập tên chi nhánh.',
            'name.max' => 'Tên chi nhánh tối đa 150 ký tự.',
            'code.max' => 'Mã chi nhánh tối đa 30 ký tự.',
        ]);

        return [
            'code' => trim((string) ($du['code'] ?? '')),
            'name' => trim($du['name']),
            'phone' => trim((string) ($du['phone'] ?? '')),
            'address' => trim((string) ($du['address'] ?? '')),
            // Ô checkbox không gửi gì khi bỏ tick, nên đọc từ request chứ không từ
            // $du: thiếu khoá nghĩa là TẮT, không phải "giữ nguyên".
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * Gọi API rồi quay lại trang danh sách kèm một câu.
     *
     * In `message` của API ra nguyên văn khi hỏng: nhóm lỗi của trang này (hết
     * hạn mức, trùng mã, chi nhánh cuối cùng) đều đã có câu giải thích riêng ở
     * đó, và mỗi câu chỉ ra một việc phải làm khác nhau. Nuốt chúng thành "thao
     * tác không thành công" là lấy đi đúng phần có ích.
     */
    protected function send(callable $call, string $success)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Chi nhanh API call failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return redirect()->route('admin.chi-nhanh.index')->with('success', $success);
        }

        // 422 trả lỗi theo từng ô. Gom lại thành một câu vì form nằm trong hộp
        // thoại — bấm xong trang tải lại và hộp thoại đã đóng, không còn ô nào để
        // gắn lỗi vào.
        $loi = $res->json('errors');
        $message = is_array($loi) && $loi
            ? implode(' ', $loi)
            : ($res->json('message') ?: 'Thao tác không thành công.');

        return back()->withInput()->with('error', $message);
    }
}
