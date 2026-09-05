<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Đơn vị tính — Hàng hóa → Đơn vị.
 *
 * Dựng theo màn Đơn vị của bản cũ v2 (menu/menu-unit): bảng [mã, tên, trạng
 * thái], thêm/sửa trong hộp thoại, công tắc bật/tắt ngay trên dòng, chọn nhiều
 * dòng để xoá một lượt.
 *
 * Khác bản cũ ở bốn chỗ, đều là chỗ bản cũ làm hỏng:
 *
 * - Lọc realtime, không có nút kính lúp (quy tắc chung của mọi trang danh sách
 *   trong dự án).
 * - Ô Trạng thái trong hộp thoại sửa được. Bản cũ ẩn hẳn ô đó khi sửa
 *   (`unitStatus.addClass('d-none')`), nên muốn đổi phải đóng hộp thoại rồi gạt
 *   công tắc ngoài bảng.
 * - Công tắc trạng thái gửi ĐÚNG một trường. Bản cũ gọi `fill($request->all())`
 *   ở đường trạng thái nên gửi kèm `name` là đổi luôn tên qua lượt gạt ấy.
 * - Mã và tên duy nhất TRONG MỘT cửa hàng, không phải toàn hệ thống.
 *
 * Nhóm route `admin.manage` (thu ngân không vào): đây là khung phân loại hàng
 * hoá, cùng tầng với Nhóm hàng hóa và Thuế.
 */
class DonViTinhController extends Controller
{
    use \App\Http\Controllers\Concerns\TraLoiHopThoai;

    /** Nhãn NGẮN cho thanh điều hướng. */
    public const TITLE = 'Đơn vị tính';

    /** Tiêu đề trang — khuôn "Danh sách <đối tượng>" như các trang khác. */
    public const TITLE_PAGE = 'Danh sách đơn vị tính';

    public const EMPTY_TEXT = 'Chưa có đơn vị tính nào. Bấm "Thêm đơn vị" để khai đơn vị đầu tiên.';

    public const SO_DONG_MOI_TRANG = 10;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    /** Hai lựa chọn của ô lọc trạng thái. '' = không lọc. */
    public const TRANG_THAI = [
        'active' => 'Đang dùng',
        'inactive' => 'Đã tắt',
    ];

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách + hộp thoại thêm/sửa. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $list = [];

        try {
            // Lọc trạng thái làm ở đây chứ không gửi API: API chỉ có `active=true`
            // (dành cho ô chọn đơn vị lúc khai mặt hàng), không có đường lấy riêng
            // phần đã tắt.
            $res = $this->api->donViTinh($filters['keyword']);
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
            } else {
                Log::warning('Load don vi tinh failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách đơn vị tính.';
            }
        } catch (\Throwable $e) {
            Log::error('Load don vi tinh failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách đơn vị tính. Kiểm tra kết nối API.';
        }

        // Con số ở header đếm trên TOÀN danh sách (trước khi lọc trạng thái và
        // trước khi cắt trang), vì nó trả lời "cửa hàng có bao nhiêu đơn vị".
        $tong = count($list);
        $dangDung = collect($list)->where('is_active', true)->count();

        if ($filters['status'] !== '') {
            $bat = $filters['status'] === 'active';
            $list = array_values(array_filter($list, fn ($dv) => (bool) ($dv['is_active'] ?? false) === $bat));
        }

        $soDong = $this->soDongMoiTrang($request);
        $soTrang = max(1, (int) ceil(count($list) / $soDong));
        $trang = min(max(1, (int) $request->query('page', 1)), $soTrang);

        $view = view('v2::don-vi-tinh.index', [
            'list' => array_slice($list, ($trang - 1) * $soDong, $soDong),
            'tong' => $tong,
            'dangDung' => $dangDung,
            'stt' => ($trang - 1) * $soDong,
            'meta' => [
                'page' => $trang,
                'total_pages' => $soTrang,
                'total' => count($list),
                'page_size' => $soDong,
            ],
            'filters' => $filters,
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Thêm đơn vị tính. */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->taoDonViTinh($data),
            'Đã thêm đơn vị "'.$data['name'].'".',
            $request
        );
    }

    /** Sửa đơn vị tính. */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->suaDonViTinh($id, $data),
            'Đã cập nhật đơn vị "'.$data['name'].'".',
            $request
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
            fn () => $this->api->doiTrangThaiDonViTinh($id, $bat),
            $bat
                ? 'Đã bật lại đơn vị này.'
                : 'Đã tắt đơn vị này — ô chọn đơn vị lúc khai mặt hàng sẽ thôi bày nó ra.',
            $request
        );
    }

    /** Xoá một đơn vị. */
    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->xoaDonViTinh($id),
            'Đã xoá đơn vị tính.',
            $request
        );
    }

    /**
     * Xoá nhiều dòng đã tick — API chỉ có đường xoá một dòng nên gọi lần lượt.
     * Dòng nào hỏng thì đếm riêng và nói ra, chứ không im lặng bỏ qua.
     */
    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            return back()->with('error', 'Chưa chọn đơn vị nào để xoá.');
        }

        $ok = 0;
        $hong = 0;

        foreach ($ids as $id) {
            try {
                $res = $this->api->xoaDonViTinh($id);
                $res->successful() ? $ok++ : $hong++;
            } catch (\Throwable $e) {
                Log::warning('Bulk delete don vi tinh failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $hong++;
            }
        }

        $ve = $this->veDanhSach($request);

        return $hong > 0
            ? $ve->with('error', "Đã xoá {$ok} đơn vị; {$hong} đơn vị xoá không thành công.")
            : $ve->with('success', "Đã xoá {$ok} đơn vị.");
    }

    /** Số dòng mỗi trang, chỉ nhận các mức có trong ô chọn. */
    protected function soDongMoiTrang(Request $request): int
    {
        $n = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return in_array($n, self::MUC_SO_DONG, true) ? $n : self::SO_DONG_MOI_TRANG;
    }

    /**
     * Bộ lọc. Giá trị lạ trên URL bị bỏ đi: giữ lại thì bảng trả về rỗng, nhìn
     * giống hệt "cửa hàng chưa khai đơn vị nào".
     */
    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', '');

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::TRANG_THAI[$status]) ? $status : '',
        ];
    }

    /**
     * Kiểm dữ liệu rồi dựng payload. Không thay cho API — bên đó kiểm lại tất
     * cả; lượt này chỉ để người dùng thấy lỗi ngay tại ô vừa gõ.
     */
    protected function validated(Request $request): array
    {
        $du = $request->validate([
            // Bỏ trống = để API tự đặt mã. Gõ tay thì chỉ chữ cái và chữ số, giữ
            // luật của bản cũ: mã in lên tem và đọc lẫn với số lượng, khoảng trắng
            // hay gạch ngang ở đó chỉ tổ khó đọc.
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'code.regex' => 'Mã đơn vị chỉ gồm chữ không dấu và số, không khoảng trắng.',
            'name.required' => 'Nhập tên đơn vị.',
        ]);

        return [
            'code' => mb_strtoupper(trim($du['code'] ?? '')),
            'name' => trim($du['name']),
            'is_active' => (bool) ($du['is_active'] ?? false),
        ];
    }

    /** Gọi API rồi quay về bảng kèm thông báo. */
    protected function send(callable $call, string $success, ?Request $request = null)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Don vi tinh API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $success, fn () => $this->veDanhSach($request))
            : $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác không thành công.'));
    }

    /**
     * Về đúng trang danh sách người dùng đang đứng.
     *
     * Hộp thoại gửi kèm `return` = đường dẫn hiện tại (kể cả bộ lọc và số trang).
     * Không có nó thì lưu xong là văng về trang 1 không lọc gì — người đang sửa
     * dở dòng thứ 40 phải lọc lại từ đầu sau mỗi lượt lưu.
     *
     * Chỉ nhận đường dẫn TƯƠNG ĐỐI (bắt đầu bằng '/'): nhận cả URL tuyệt đối là
     * mở đường cho người ngoài dựng link đẩy người dùng sang trang khác.
     */
    protected function veDanhSach(?Request $request)
    {
        $ve = trim((string) ($request?->input('return') ?? ''));

        return $ve !== '' && str_starts_with($ve, '/')
            ? redirect($ve)
            : redirect()->route('admin.don-vi-tinh.index');
    }
}
