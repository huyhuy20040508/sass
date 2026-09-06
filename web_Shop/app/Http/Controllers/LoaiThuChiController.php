<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Loại thu chi — Thu chi → Loại thu chi.
 *
 * Dựng theo màn Loại thu chi của bản cũ v2 (cashbook/type): hai bảng cạnh
 * nhau, trái là phân loại THU, phải là phân loại CHI; mỗi bên thêm/sửa trong
 * hộp thoại chỉ có một ô tên. Loại hệ thống (is_default) không sửa, không xoá.
 *
 * Khác bản cũ ở hai chỗ:
 * - Sửa cũng kiểm trùng tên trong cùng loại. Bản cũ chỉ kiểm lúc thêm, đổi tên
 *   trùng loại khác thì lọt.
 * - Bảng thu cũng giấu nút sửa/xoá của loại hệ thống. Bản cũ chỉ giấu bên chi.
 *
 * Dữ liệu API (`/admin/loai-thu-chi`): mỗi dòng {id, type, name, is_default}.
 * `type` = 0 thu · 1 chi, giữ đúng mã của v2 để phiếu thu chi sau này dùng chung.
 */
class LoaiThuChiController extends Controller
{
    use \App\Http\Controllers\Concerns\TraLoiHopThoai;

    public const TITLE = 'Loại thu chi';

    public const TITLE_PAGE = 'Loại thu chi';

    public const LOAI_THU = 0;

    public const LOAI_CHI = 1;

    public const EMPTY_THU = 'Chưa có phân loại thu nào. Bấm "Thêm" để khai phân loại đầu tiên.';

    public const EMPTY_CHI = 'Chưa có phân loại chi nào. Bấm "Thêm" để khai phân loại đầu tiên.';

    public function __construct(protected ApiClient $api) {}

    /** Trang hai bảng thu / chi + hộp thoại thêm/sửa. */
    public function index()
    {
        $error = null;
        $list = [];

        try {
            $res = $this->api->loaiThuChi();
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
            } else {
                Log::warning('Load loai thu chi failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách loại thu chi.';
            }
        } catch (\Throwable $e) {
            Log::error('Load loai thu chi failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách loại thu chi. Kiểm tra kết nối API.';
        }

        $theoLoai = fn (int $loai) => array_values(array_filter(
            $list,
            fn ($d) => (int) ($d['type'] ?? -1) === $loai
        ));

        $view = view('v2::loai-thu-chi.index', [
            'thu' => $theoLoai(self::LOAI_THU),
            'chi' => $theoLoai(self::LOAI_CHI),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Thêm một phân loại. */
    public function store(Request $request)
    {
        $data = $this->validated($request, true);

        return $this->send(
            fn () => $this->api->taoLoaiThuChi($data),
            'Đã thêm phân loại "'.$data['name'].'".',
            $request
        );
    }

    /** Đổi tên một phân loại. Không cho đổi thu thành chi: phiếu cũ đang trỏ vào. */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request, false);

        return $this->send(
            fn () => $this->api->suaLoaiThuChi($id, ['name' => $data['name']]),
            'Đã đổi tên phân loại thành "'.$data['name'].'".',
            $request
        );
    }

    /** Xoá một phân loại. */
    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->xoaLoaiThuChi($id),
            'Đã xoá phân loại.',
            $request
        );
    }

    /**
     * Kiểm dữ liệu rồi dựng payload. API kiểm lại tất cả (kể cả trùng tên);
     * lượt này chỉ để người dùng thấy lỗi ngay tại ô vừa gõ.
     */
    protected function validated(Request $request, bool $canLoai): array
    {
        $luat = ['name' => ['required', 'string', 'max:255']];
        if ($canLoai) {
            $luat['type'] = ['required', 'in:'.self::LOAI_THU.','.self::LOAI_CHI];
        }

        $du = $request->validate($luat, [
            'name.required' => 'Nhập tên phân loại.',
            'name.max' => 'Tên phân loại dài quá 255 ký tự.',
            'type.required' => 'Thiếu loại thu hay chi.',
            'type.in' => 'Loại chỉ là thu hoặc chi.',
        ]);

        return [
            'type' => $canLoai ? (int) $du['type'] : null,
            'name' => trim($du['name']),
        ];
    }

    /** Gọi API rồi trả lời hộp thoại hoặc quay về bảng kèm thông báo. */
    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Loai thu chi API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $success, fn () => redirect()->route('admin.loai-thu-chi.index'))
            : $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác không thành công.'));
    }
}
