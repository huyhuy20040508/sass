<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Vị trí — Hàng hóa → Vị trí.
 *
 * Chỗ để hàng trong cửa hàng/kho: "Kệ A - Tầng 1", "Kho lạnh", "Quầy trước"…
 * Người soạn hàng đọc vị trí là biết đi thẳng tới đâu, thay vì dò cả kho.
 *
 * Bản cũ v2 KHÔNG có màn này (Menu QR chỉ tới Hoa hồng là hết; `hrm/position`
 * bên đó là CHỨC VỤ nhân sự, lại còn không route nào trỏ tới). Nên màn này dựng
 * theo khuôn Đơn vị tính — cùng là bảng tra mã + tên — và giữ nguyên bốn điều đã
 * sửa ở đó: lọc realtime, nút Thêm ở cuối thanh lọc, ô Trạng thái sửa được ngay
 * trong hộp thoại, và công tắc trạng thái chỉ gửi ĐÚNG một trường.
 *
 * Nhóm route `admin.manage` (thu ngân không vào): đây là khung phân loại hàng
 * hoá, cùng tầng với Đơn vị tính và Thuế.
 */
class ViTriController extends Controller
{
    /** Nhãn NGẮN cho thanh điều hướng. */
    public const TITLE = 'Vị trí';

    /** Tiêu đề trang — khuôn "Danh sách <đối tượng>" như các trang khác. */
    public const TITLE_PAGE = 'Danh sách vị trí';

    public const EMPTY_TEXT = 'Chưa có vị trí nào. Bấm "Thêm vị trí" để khai chỗ để hàng đầu tiên.';

    public const SO_DONG_MOI_TRANG = 20;

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
            // (dành cho ô chọn vị trí lúc khai mặt hàng), không có đường lấy riêng
            // phần đã tắt.
            $res = $this->api->viTri($filters['keyword']);
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
            } else {
                Log::warning('Load vi tri failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách vị trí.';
            }
        } catch (\Throwable $e) {
            Log::error('Load vi tri failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách vị trí. Kiểm tra kết nối API.';
        }

        // Con số ở header đếm trên TOÀN danh sách (trước khi lọc trạng thái và
        // trước khi cắt trang), vì nó trả lời "cửa hàng có bao nhiêu vị trí".
        $tong = count($list);
        $dangDung = collect($list)->where('is_active', true)->count();

        if ($filters['status'] !== '') {
            $bat = $filters['status'] === 'active';
            $list = array_values(array_filter($list, fn ($vt) => (bool) ($vt['is_active'] ?? false) === $bat));
        }

        $soDong = $this->soDongMoiTrang($request);
        $soTrang = max(1, (int) ceil(count($list) / $soDong));
        $trang = min(max(1, (int) $request->query('page', 1)), $soTrang);

        $view = view('vi-tri.index', [
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

    /** Thêm vị trí. */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->taoViTri($data),
            'Đã thêm vị trí "'.$data['name'].'".'
        );
    }

    /** Sửa vị trí. */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->suaViTri($id, $data),
            'Đã cập nhật vị trí "'.$data['name'].'".'
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
            fn () => $this->api->doiTrangThaiViTri($id, $bat),
            $bat
                ? 'Đã bật lại vị trí này.'
                : 'Đã tắt vị trí này — ô chọn vị trí lúc khai mặt hàng sẽ thôi bày nó ra.'
        );
    }

    /** Xoá một vị trí. */
    public function destroy(int $id)
    {
        return $this->send(
            fn () => $this->api->xoaViTri($id),
            'Đã xoá vị trí.'
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
            return back()->with('error', 'Chưa chọn vị trí nào để xoá.');
        }

        $ok = 0;
        $hong = 0;

        foreach ($ids as $id) {
            try {
                $res = $this->api->xoaViTri($id);
                $res->successful() ? $ok++ : $hong++;
            } catch (\Throwable $e) {
                Log::warning('Bulk delete vi tri failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $hong++;
            }
        }

        $ve = redirect()->route('admin.vi-tri.index');

        // Nói luôn lý do hay gặp nhất thay vì để người dùng đoán: vị trí còn mặt
        // hàng trỏ tới thì API từ chối, và đó gần như luôn là nguyên nhân.
        return $hong > 0
            ? $ve->with('error', "Đã xoá {$ok} vị trí; {$hong} vị trí xoá không thành công — thường là do còn mặt hàng để ở đó.")
            : $ve->with('success', "Đã xoá {$ok} vị trí.");
    }

    /** Số dòng mỗi trang, chỉ nhận các mức có trong ô chọn. */
    protected function soDongMoiTrang(Request $request): int
    {
        $n = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return in_array($n, self::MUC_SO_DONG, true) ? $n : self::SO_DONG_MOI_TRANG;
    }

    /**
     * Bộ lọc. Giá trị lạ trên URL bị bỏ đi: giữ lại thì bảng trả về rỗng, nhìn
     * giống hệt "cửa hàng chưa khai vị trí nào".
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
            // Bỏ trống = để API tự đặt mã. Gõ tay thì chỉ chữ cái và chữ số, cùng
            // luật với đơn vị tính: mã vị trí là thứ dán lên kệ và đọc nhanh, thêm
            // khoảng trắng hay gạch ngang chỉ tổ khó đọc.
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'code.regex' => 'Mã vị trí chỉ gồm chữ không dấu và số, không khoảng trắng.',
            'name.required' => 'Nhập tên vị trí.',
        ]);

        return [
            'code' => mb_strtoupper(trim($du['code'] ?? '')),
            'name' => trim($du['name']),
            'is_active' => (bool) ($du['is_active'] ?? false),
        ];
    }

    /** Gọi API rồi quay về bảng kèm thông báo. */
    protected function send(callable $call, string $success)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Vi tri API call failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return redirect()->route('admin.vi-tri.index')->with('success', $success);
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
