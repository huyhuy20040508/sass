<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Quản lý thuế — bố cục theo bản cũ v2 (Menu → Quản lý thuế): bảng bốn dòng cố
 * định, mỗi dòng sửa được bộ mức thuế suất và bật/tắt.
 *
 * Không có thêm, không có xoá: bốn loại ứng với bốn điểm nghiệp vụ, danh sách
 * nằm trong Go API (domain.DanhMucLoaiThue). Bộ mức cho chọn cũng do API trả về
 * — bản v2 để bộ mức trong JavaScript nên mỗi lần luật thuế đổi là phải sửa
 * giao diện rồi phát hành lại.
 */
class ThueController extends Controller
{
    use \App\Http\Controllers\Concerns\TraLoiHopThoai;

    public function __construct(protected ApiClient $api) {}

    /** Bảng thuế suất. */
    public function index()
    {
        try {
            $res = $this->api->taxes();
        } catch (\Throwable $e) {
            Log::error('Load taxes failed', ['msg' => $e->getMessage()]);

            return view('v2::thue.index', ['taxes' => []])
                ->with('error', 'Không tải được danh sách thuế. Kiểm tra kết nối API.');
        }

        if (! $res->successful()) {
            return view('v2::thue.index', ['taxes' => []])
                ->with('error', $res->json('message') ?: 'Không tải được danh sách thuế.');
        }

        return view('v2::thue.index', ['taxes' => $res->json('data') ?? []]);
    }

    /** Lưu bộ mức của một loại thuế. */
    public function update(Request $request, int $id)
    {
        $v = $request->validate([
            'muc' => ['required', 'array', 'min:1'],
            'muc.*' => ['integer'],
        ], [
            'muc.required' => 'Chọn ít nhất một mức thuế.',
            'muc.min' => 'Chọn ít nhất một mức thuế.',
            'muc.*.integer' => 'Mức thuế không hợp lệ.',
        ]);

        // unique() trước khi gửi: ô chọn nhiều có thể trả về hai lần cùng một
        // mức nếu người dùng bấm nhanh, và API sẽ từ chối cả lượt lưu.
        $muc = collect($v['muc'])->map(fn ($m) => (int) $m)->unique()->values()->all();

        return $this->send(
            fn () => $this->api->updateTax($id, $muc),
            'Đã cập nhật mức thuế.'
        );
    }

    /** Công tắc bật/tắt trên bảng. */
    public function toggleStatus(Request $request, int $id)
    {
        $v = $request->validate([
            'is_active' => ['required', 'boolean'],
        ], [
            'is_active.required' => 'Thiếu trạng thái cần đặt.',
        ]);

        $bat = (bool) $v['is_active'];

        return $this->send(
            fn () => $this->api->toggleTaxStatus($id, $bat),
            $bat
                ? 'Đã bật loại thuế này.'
                : 'Đã tắt loại thuế này — màn nghiệp vụ sẽ thôi bày ô chọn thuế.'
        );
    }

    /** Gọi API rồi quay về bảng kèm thông báo. */
    protected function send(\Closure $call, string $successMsg, ?Request $request = null)
    {
        $request ??= request();

        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Tax API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được máy chủ API.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $successMsg, fn () => redirect()->route('admin.thue.index'))
            : $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác thất bại.'));
    }
}
