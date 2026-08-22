<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Thuộc tính — Hàng hóa → Thuộc tính.
 *
 * Dựng theo màn Quản lý thuộc tính của bản cũ v2 (menu/menu-attribute): một
 * thuộc tính (Kích cỡ, Mức đá…) kèm danh sách GIÁ TRỊ của nó (S/M/L, ít đá,
 * nhiều đá…). Đây là bảng tra để khai biến thể mặt hàng và định lượng nguyên
 * vật liệu.
 *
 * Khác bản cũ ở năm chỗ, đều là chỗ bản cũ làm hỏng:
 *
 * - Lọc realtime, không có nút kính lúp (quy tắc chung của trang danh sách).
 * - Mã SỬA ĐƯỢC lúc sửa. Bản cũ khoá cứng ô mã, gõ nhầm một chữ là phải xoá đi
 *   khai lại — mà xoá thì lại vướng "đang được dùng".
 * - Xoá một giá trị chỉ bỏ dòng trên form, bấm Lưu mới ghi. Bản cũ bắn AJAX xoá
 *   NGAY lúc bấm dấu ×, nên bấm nhầm rồi đóng hộp thoại cũng không lấy lại được.
 * - Công tắc trạng thái gửi ĐÚNG một trường. Bản cũ gọi `fill($request->all())`
 *   ở đường trạng thái nên gửi kèm `name` là đổi luôn tên qua lượt gạt ấy.
 * - Mã và tên duy nhất TRONG MỘT cửa hàng, không phải toàn hệ thống.
 *
 * Nhóm route `admin.manage` (thu ngân không vào): cùng tầng Nhóm hàng hóa,
 * Đơn vị tính, Thuế.
 */
class ThuocTinhController extends Controller
{
    /** Nhãn NGẮN cho thanh điều hướng. */
    public const TITLE = 'Thuộc tính';

    /** Tiêu đề trang — khuôn "Danh sách <đối tượng>" như các trang khác. */
    public const TITLE_PAGE = 'Danh sách thuộc tính';

    public const EMPTY_TEXT = 'Chưa có thuộc tính nào. Bấm "Thêm thuộc tính" để khai cái đầu tiên.';

    public const SO_DONG_MOI_TRANG = 20;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    /** Hai lựa chọn của ô lọc trạng thái. '' = không lọc. */
    public const TRANG_THAI = [
        'active' => 'Đang dùng',
        'inactive' => 'Đã tắt',
    ];

    /** Ô lọc cờ định lượng nguyên vật liệu. '' = không lọc. */
    public const LOC_DINH_LUONG = [
        'yes' => 'Có định lượng NVL',
        'no' => 'Không định lượng NVL',
    ];

    /** Số giá trị bày thẳng trên dòng, dư ra thì gom thành "+n". */
    public const SO_GIA_TRI_HIEN = 5;

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách + hộp thoại thêm/sửa. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $list = [];

        try {
            // Lọc trạng thái và lọc cờ định lượng làm ở đây chứ không gửi API:
            // hai tham số bên đó (`active`, `raw_material`) chỉ lọc được một
            // chiều "chỉ lấy cái đang bật", không có đường lấy riêng phần đã tắt.
            $res = $this->api->thuocTinh($filters['keyword']);
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
            } else {
                Log::warning('Load thuoc tinh failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách thuộc tính.';
            }
        } catch (\Throwable $e) {
            Log::error('Load thuoc tinh failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách thuộc tính. Kiểm tra kết nối API.';
        }

        // Con số ở header đếm trên TOÀN danh sách (trước khi lọc và cắt trang),
        // vì nó trả lời "cửa hàng có bao nhiêu thuộc tính".
        $tong = count($list);
        $dangDung = collect($list)->where('is_active', true)->count();

        if ($filters['status'] !== '') {
            $bat = $filters['status'] === 'active';
            $list = array_values(array_filter($list, fn ($tt) => (bool) ($tt['is_active'] ?? false) === $bat));
        }

        if ($filters['raw_material'] !== '') {
            $co = $filters['raw_material'] === 'yes';
            $list = array_values(array_filter($list, fn ($tt) => (bool) ($tt['raw_material'] ?? false) === $co));
        }

        $soDong = $this->soDongMoiTrang($request);
        $soTrang = max(1, (int) ceil(count($list) / $soDong));
        $trang = min(max(1, (int) $request->query('page', 1)), $soTrang);

        $view = view('thuoc-tinh.index', [
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

    /** Thêm thuộc tính. */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->taoThuocTinh($data),
            'Đã thêm thuộc tính "'.$data['name'].'".'
        );
    }

    /** Sửa thuộc tính. */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->suaThuocTinh($id, $data),
            'Đã cập nhật thuộc tính "'.$data['name'].'".'
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
            fn () => $this->api->doiTrangThaiThuocTinh($id, $bat),
            $bat
                ? 'Đã bật lại thuộc tính này.'
                : 'Đã tắt thuộc tính này — ô chọn thuộc tính lúc khai mặt hàng sẽ thôi bày nó ra.'
        );
    }

    /** Xoá một thuộc tính, kèm toàn bộ giá trị của nó. */
    public function destroy(int $id)
    {
        return $this->send(
            fn () => $this->api->xoaThuocTinh($id),
            'Đã xoá thuộc tính.'
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
            return back()->with('error', 'Chưa chọn thuộc tính nào để xoá.');
        }

        $ok = 0;
        $hong = 0;

        foreach ($ids as $id) {
            try {
                $res = $this->api->xoaThuocTinh($id);
                $res->successful() ? $ok++ : $hong++;
            } catch (\Throwable $e) {
                Log::warning('Bulk delete thuoc tinh failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $hong++;
            }
        }

        $ve = redirect()->route('admin.thuoc-tinh.index');

        return $hong > 0
            ? $ve->with('error', "Đã xoá {$ok} thuộc tính; {$hong} thuộc tính xoá không thành công.")
            : $ve->with('success', "Đã xoá {$ok} thuộc tính.");
    }

    /** Số dòng mỗi trang, chỉ nhận các mức có trong ô chọn. */
    protected function soDongMoiTrang(Request $request): int
    {
        $n = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return in_array($n, self::MUC_SO_DONG, true) ? $n : self::SO_DONG_MOI_TRANG;
    }

    /**
     * Bộ lọc. Giá trị lạ trên URL bị bỏ đi: giữ lại thì bảng trả về rỗng, nhìn
     * giống hệt "cửa hàng chưa khai thuộc tính nào".
     */
    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', '');
        $dinhLuong = (string) $request->query('raw_material', '');

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::TRANG_THAI[$status]) ? $status : '',
            'raw_material' => isset(self::LOC_DINH_LUONG[$dinhLuong]) ? $dinhLuong : '',
        ];
    }

    /**
     * Kiểm dữ liệu rồi dựng payload. Không thay cho API — bên đó kiểm lại tất
     * cả; lượt này chỉ để người dùng thấy lỗi ngay tại ô vừa gõ.
     *
     * `values` gửi lên NGUYÊN danh sách đang thấy trên hộp thoại: dòng có id là
     * giá trị cũ, dòng không id là mới, và giá trị cũ vắng mặt nghĩa là bị xoá.
     */
    protected function validated(Request $request): array
    {
        $du = $request->validate([
            // Bỏ trống = để API tự đặt mã theo quy tắc đánh số của cửa hàng.
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'raw_material' => ['nullable', 'boolean'],
            'values' => ['nullable', 'array', 'max:100'],
            'values.*.id' => ['nullable', 'integer'],
            'values.*.code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9]+$/'],
            'values.*.name' => ['nullable', 'string', 'max:100'],
        ], [
            'code.regex' => 'Mã thuộc tính chỉ gồm chữ không dấu và số, không khoảng trắng.',
            'name.required' => 'Nhập tên thuộc tính.',
            'values.*.code.regex' => 'Mã giá trị chỉ gồm chữ không dấu và số, không khoảng trắng.',
        ]);

        // Dòng giá trị bỏ trống tên = người dùng bấm thêm rồi để đấy, bỏ qua.
        // Bỏ luôn khoá `id` khi nó rỗng: API nhận `id` là số, gửi null sang thì
        // vẫn đọc ra 0 nhưng để đây một khoá không mang tin gì chỉ tổ khó đọc.
        $values = collect($du['values'] ?? [])
            ->map(function ($v) {
                $dong = [
                    'code' => mb_strtoupper(trim((string) ($v['code'] ?? ''))),
                    'name' => trim((string) ($v['name'] ?? '')),
                ];
                if ((int) ($v['id'] ?? 0) > 0) {
                    $dong['id'] = (int) $v['id'];
                }

                return $dong;
            })
            ->filter(fn ($v) => $v['name'] !== '')
            ->values()
            ->all();

        return [
            'code' => mb_strtoupper(trim($du['code'] ?? '')),
            'name' => trim($du['name']),
            'is_active' => (bool) ($du['is_active'] ?? false),
            'raw_material' => (bool) ($du['raw_material'] ?? false),
            'values' => $values,
        ];
    }

    /** Gọi API rồi quay về bảng kèm thông báo. */
    protected function send(callable $call, string $success)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Thuoc tinh API call failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return redirect()->route('admin.thuoc-tinh.index')->with('success', $success);
        }

        // 422 trả lỗi theo từng ô; gom thành một câu vì hộp thoại đã đóng sau khi
        // trang tải lại.
        $loi = $res->json('errors');
        $message = is_array($loi) && $loi
            ? implode(' ', $loi)
            : ($res->json('message') ?: 'Thao tác không thành công.');

        return back()->withInput()->with('error', $message);
    }
}
