<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Phiếu điều chuyển — chuyển hàng từ kho này sang kho khác, dựng lại nguyên màn
 * "Phiếu điều chuyển" của order v2 (purchase/transfer-slip).
 *
 * Vòng đời hai nước như bên đó: lưu tạm → duyệt (lúc duyệt mới xuất kho nguồn và
 * nhập kho đích).
 *
 * API cắt danh sách theo chi nhánh đang làm việc, và cắt theo CẢ HAI ĐẦU: phiếu
 * chi nhánh đó gửi đi lẫn phiếu nó nhận về. Kho nhận cũng phải tra ra được chứng
 * từ đã làm tồn của mình tăng lên.
 */
class PhieuDieuChuyenController extends Controller
{
    use Concerns\TraLoiHopThoai;

    /** Nhãn ngắn cho thanh điều hướng. */
    public const TITLE = 'Phiếu điều chuyển';

    public const TITLE_PAGE = 'Danh sách phiếu điều chuyển';

    public const EMPTY_TEXT = 'Chưa có phiếu điều chuyển nào.';

    /** Trạng thái phiếu — v2 chỉ dùng hai nước: lưu tạm và đã duyệt. */
    public const TRANG_THAI = [
        'draft' => 'Lưu tạm',
        'approved' => 'Đã được duyệt',
    ];

    /** Màu chữ trạng thái — đúng hai màu của list.blade.php bên v2. */
    public const CHU_TRANG_THAI = [
        'draft' => 'text-secondary',
        'approved' => 'text-primary',
    ];

    /** Loại chứng từ — ô khoá trên hộp lập phiếu, y hệt v2. */
    public const LOAI_CHUNG_TU = 'Phiếu điều chuyển';

    /** Trạng thái in sẵn ở ô "Trạng thái" của phiếu mới — v2 để "Phiếu mở". */
    public const TRANG_THAI_MOI = 'Phiếu mở';

    /** Loại giao dịch của mỗi dòng hàng — v2 in cứng một chữ. */
    public const LOAI_GIAO_DICH = 'Điều chuyển';

    /** Cột tắt/bật được ngoài bảng; lựa chọn lưu ở localStorage. */
    public const COT_BANG = [
        'code' => 'Số phiếu',
        'from' => 'Kho xuất',
        'to' => 'Kho nhập',
        'status' => 'Trạng thái',
        'creator' => 'Người tạo',
        'receiver' => 'Người nhận',
        'date' => 'Ngày nhập kho',
        'note' => 'Ghi chú',
    ];

    public const SO_DONG_MOI_TRANG = 10;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    /** Trạng thái "đang làm" của hồ sơ nhân viên — khớp domain.NhanSuDangLam. */
    public const NHAN_SU_DANG_LAM = 'dang_lam';

    public function __construct(protected ApiClient $api) {}

    // ---------------------------------------------------------------------
    // Danh sách
    // ---------------------------------------------------------------------

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $list = [];
        $meta = ['page' => 1, 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->phieuDieuChuyen([
                'status' => $filters['status'],
                'variant_id' => $filters['product_id'] ?: null,
                'from_date' => $filters['from_date'],
                'to_date' => $filters['to_date'],
                'page' => $filters['page'],
                'page_size' => $filters['page_size'],
            ]);
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = $res->json('meta') ?: $meta;
            } else {
                Log::warning('Load phieu dieu chuyen failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách phiếu điều chuyển.';
            }
        } catch (\Throwable $e) {
            Log::error('Load phieu dieu chuyen failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách phiếu điều chuyển. Kiểm tra kết nối API.';
        }

        $view = view('v2::phieu-dieu-chuyen.index', [
            'list' => $list,
            'filters' => $filters,
            'meta' => $meta,
            'chiNhanh' => $this->danhMucChiNhanh(),
            'nhanVien' => $this->danhMucNhanVien(),
            'chiNhanhDangLam' => $this->chiNhanhDangLam(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Ô tìm hàng của hộp lập phiếu và của khối lọc.
     *
     * Đi chung đường mặt hàng của phiếu mua — cùng nhu cầu: mã, tên, đơn vị, giá
     * vốn, và danh sách lô còn hàng kèm số lượng. Mỗi lô bên v2 là MỘT dòng trên
     * lưới hàng, nên dữ liệu này vừa đủ.
     */
    public function matHang(Request $request)
    {
        try {
            $res = $this->api->phieuMuaHangMatHang([
                'keyword' => trim((string) $request->query('keyword', '')),
                'limit' => 30,
            ]);

            // Lỗi của API phải TỚI được người dùng, không đổi thành danh sách rỗng: 409
            // "Chưa chọn chi nhánh làm việc" mà thành "không có hàng nào" thì người ta
            // gõ mãi không hiểu vì sao, trong khi việc phải làm là chọn kho ở thanh trên.
            if (! $res->successful()) {
                return response()->json(
                    ['data' => [], 'message' => $res->json('message') ?: 'Không tìm được mặt hàng.'],
                    $res->status() >= 400 ? $res->status() : 502,
                );
            }

            return response()->json(['data' => $res->json('data') ?? []]);
        } catch (\Throwable $e) {
            Log::error('Tim mat hang cho phieu dieu chuyen failed', ['msg' => $e->getMessage()]);

            return response()->json(['data' => [], 'message' => 'Không tìm được mặt hàng.'], 502);
        }
    }

    // ---------------------------------------------------------------------
    // Lập, sửa, duyệt, xoá
    // ---------------------------------------------------------------------

    /**
     * Lập phiếu.
     *
     * Nút "Duyệt" gửi kèm `duyet=1`: lưu xong gọi tiếp đường duyệt — lúc duyệt
     * mới đổi kho HAI ĐẦU, nên đó là một quyền riêng bên API.
     */
    public function store(Request $request)
    {
        $data = $this->duLieu($request);

        try {
            $res = $this->api->taoPhieuDieuChuyen($data);
        } catch (\Throwable $e) {
            Log::error('Tao phieu dieu chuyen failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return $this->traLoiHopThoai($request, false,
                $this->cauLoi($res, 'Lập phiếu điều chuyển không thành công.'));
        }

        $phieu = $res->json('data') ?? [];
        $ma = $phieu['transfer_code'] ?? '';

        if (! $request->boolean('duyet')) {
            return $this->traLoiHopThoai($request, true, 'Đã lưu tạm phiếu điều chuyển '.$ma.'.',
                fn () => $this->veDanhSach($request));
        }

        return $this->duyetSauKhiLuu($request, (int) ($phieu['id'] ?? 0), $ma);
    }

    /** Sửa phiếu — chỉ phiếu lưu tạm; nút Duyệt cũng đi kèm được. */
    public function update(Request $request, int $id)
    {
        $data = $this->duLieu($request);

        try {
            $res = $this->api->suaPhieuDieuChuyen($id, $data);
        } catch (\Throwable $e) {
            Log::error('Sua phieu dieu chuyen failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return $this->traLoiHopThoai($request, false,
                $this->cauLoi($res, 'Cập nhật phiếu điều chuyển không thành công.'));
        }

        $ma = $res->json('data.transfer_code') ?? '';

        if (! $request->boolean('duyet')) {
            return $this->traLoiHopThoai($request, true, 'Đã cập nhật phiếu điều chuyển '.$ma.'.',
                fn () => $this->veDanhSach($request));
        }

        return $this->duyetSauKhiLuu($request, $id, $ma);
    }

    /** Chi tiết một phiếu — hộp Sửa/Xem mở ngay trên bảng nên trả JSON. */
    public function show(int $id)
    {
        try {
            $res = $this->api->phieuDieuChuyenChiTiet($id);
        } catch (\Throwable $e) {
            Log::error('Doc phieu dieu chuyen failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API.'], 502);
        }

        if (! $res->successful()) {
            return response()->json(
                ['message' => $res->json('message') ?: 'Không đọc được phiếu.'],
                $res->status()
            );
        }

        return response()->json(['data' => $res->json('data')]);
    }

    /** Duyệt phiếu: hàng rời kho gửi và vào kho nhận. */
    public function approve(Request $request, int $id)
    {
        return $this->goi(
            $request,
            fn () => $this->api->duyetPhieuDieuChuyen($id, ['note' => trim((string) $request->input('note', ''))]),
            'Đã duyệt phiếu điều chuyển — hàng đã đổi kho.'
        );
    }

    public function destroy(Request $request, int $id)
    {
        return $this->goi(
            $request,
            fn () => $this->api->xoaPhieuDieuChuyen($id),
            'Đã xoá phiếu điều chuyển.'
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function filters(Request $request): array
    {
        $size = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return [
            'status' => $this->locNhieu($request->query('status'), array_keys(self::TRANG_THAI)),
            'product_id' => (int) $request->query('product_id', 0),
            // Mở màn là đã lọc sẵn THÁNG NÀY, đúng như các màn chứng từ khác.
            // Rẽ theo has(): gửi `from_date=` rỗng là người dùng CỐ Ý bỏ lọc ngày.
            'from_date' => $request->has('from_date')
                ? $this->ngayLoc($request->query('from_date'))
                : date('Y-m-01'),
            'to_date' => $request->has('to_date')
                ? $this->ngayLoc($request->query('to_date'))
                : date('Y-m-d'),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($size, self::MUC_SO_DONG, true) ? $size : self::SO_DONG_MOI_TRANG,
        ];
    }

    /** Lọc nhiều giá trị: bỏ giá trị lạ rồi ghép lại bằng dấu phẩy. */
    protected function locNhieu($v, array $hopLe): string
    {
        $phan = is_array($v) ? $v : explode(',', (string) $v);

        return implode(',', array_values(array_intersect(array_map('trim', $phan), $hopLe)));
    }

    /** Ngày lọc: nhận YYYY-MM-DD (link chia sẻ) lẫn DD-MM-YYYY (ô lịch của v2). */
    protected function ngayLoc($v): string
    {
        $v = trim((string) $v);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $v, $m)) {
            return $m[3].'-'.$m[2].'-'.$m[1];
        }

        return '';
    }

    /** Mọi chi nhánh — hai ô "Kho xuất" và "Kho nhập" đổ từ đây. */
    protected function danhMucChiNhanh(): array
    {
        try {
            $res = $this->api->chiNhanh();

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load chi nhanh cho phieu dieu chuyen failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Chỉ người ĐANG LÀM — ô "Người nhận". */
    protected function danhMucNhanVien(): array
    {
        try {
            $res = $this->api->nhanSu(['status' => self::NHAN_SU_DANG_LAM]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load nhan su cho phieu dieu chuyen failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Chi nhánh đang làm việc — điền sẵn ô "Kho xuất" khi lập phiếu. */
    protected function chiNhanhDangLam(): array
    {
        $id = ApiClient::chiNhanhDangLam();

        foreach ($this->danhMucChiNhanh() as $cn) {
            if ((int) ($cn['id'] ?? 0) === $id) {
                return ['id' => $id, 'name' => (string) ($cn['name'] ?? '')];
            }
        }

        return ['id' => $id, 'name' => $id > 0 ? '' : 'Mọi chi nhánh'];
    }

    /**
     * Duyệt ngay sau khi lưu — nút "Duyệt" của hộp lập phiếu.
     *
     * Lưu XONG rồi mới duyệt là hai lượt gọi, nên lượt duyệt hỏng thì phiếu vẫn
     * còn đó ở trạng thái lưu tạm. Nói rõ điều ấy thay vì báo "lưu hỏng": người
     * dùng cần biết phiếu đã có, chỉ là chưa duyệt được — không thì họ lập lại
     * một phiếu thứ hai y hệt.
     */
    protected function duyetSauKhiLuu(Request $request, int $id, string $ma)
    {
        if ($id <= 0) {
            return $this->traLoiHopThoai($request, true, 'Đã lưu tạm phiếu điều chuyển '.$ma.'.',
                fn () => $this->veDanhSach($request));
        }

        try {
            $res = $this->api->duyetPhieuDieuChuyen($id);
        } catch (\Throwable $e) {
            Log::error('Duyet phieu dieu chuyen failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false,
                'Đã lưu tạm phiếu '.$ma.' nhưng chưa duyệt được: không kết nối được API.');
        }

        if (! $res->successful()) {
            return $this->traLoiHopThoai($request, false,
                'Đã lưu tạm phiếu '.$ma.' nhưng chưa duyệt được: '.$this->cauLoi($res, 'phiếu bị từ chối.'));
        }

        return $this->traLoiHopThoai($request, true, 'Đã duyệt phiếu '.$ma.' — hàng đã đổi kho.',
            fn () => $this->veDanhSach($request));
    }

    /** Gọi API rồi trả lời theo kiểu người gọi — hộp thoại nhận JSON. */
    protected function goi(Request $request, callable $call, string $success)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Phieu dieu chuyen API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $success, fn () => $this->veDanhSach($request))
            : $this->traLoiHopThoai($request, false, $this->cauLoi($res, 'Thao tác không thành công.'));
    }

    /** Về đúng URL cũ nếu lượt gửi có kèm `return`. */
    protected function veDanhSach(Request $request)
    {
        $ve = trim((string) $request->input('return', ''));

        return $ve !== '' && str_starts_with($ve, '/')
            ? redirect($ve)
            : redirect()->route('admin.phieu-dieu-chuyen.index');
    }

    /**
     * Payload gửi lên API.
     *
     * Kiểm ở đây CHỈ phần người dùng thấy ngay tại ô vừa gõ. Hai luật thật của
     * nghiệp vụ — kho xuất khác kho nhập, và kho xuất phải đủ hàng — để API lo:
     * chép chúng sang đây là hai bản lệch nhau vào một ngày nào đó, mà bản ở đây
     * lại không biết gì về tồn kho tại thời điểm bấm nút.
     */
    protected function duLieu(Request $request): array
    {
        $du = $request->validate([
            'from_shop_id' => ['required', 'integer', 'min:1'],
            'to_shop_id' => ['required', 'integer', 'min:1'],
            'receiver_id' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.lot_number' => ['nullable', 'string', 'max:50'],
            'updated_at' => ['nullable', 'string', 'max:40'],
        ], [
            'from_shop_id.required' => 'Chưa chọn kho xuất.',
            'to_shop_id.required' => 'Chưa chọn kho nhập.',
            'items.required' => 'Phiếu chưa có dòng hàng nào.',
            'items.min' => 'Phiếu chưa có dòng hàng nào.',
            'items.*.quantity.min' => 'Số lượng điều chuyển phải lớn hơn 0.',
        ]);

        return [
            'from_shop_id' => (int) $du['from_shop_id'],
            'to_shop_id' => (int) $du['to_shop_id'],
            'receiver_id' => (int) ($du['receiver_id'] ?? 0),
            'note' => trim((string) ($du['note'] ?? '')),
            // Mốc của BẢN người dùng đang xem. API so lại để phát hiện có người
            // khác vừa lưu phiếu này — xem service.kiemBanDangSua bên API.
            'updated_at' => (string) ($du['updated_at'] ?? ''),
            'items' => array_map(fn ($d) => [
                'variant_id' => (int) $d['variant_id'],
                'quantity' => (int) $d['quantity'],
                'lot_number' => trim((string) ($d['lot_number'] ?? '')),
            ], $du['items']),
        ];
    }

    /**
     * Câu lỗi đọc được từ phản hồi API.
     *
     * 422 trả lỗi theo TỪNG Ô; gộp lại vì hộp thoại chỉ bắn được một dòng toast.
     * Bỏ khoá `ma` trước khi gom: 403 của API kèm một MÃ MÁY ĐỌC nằm trong
     * `errors`, còn câu cho người đọc thì ở `message`.
     */
    protected function cauLoi($res, string $macDinh): string
    {
        $loi = $res->json('errors');
        if (is_array($loi)) {
            unset($loi['ma']);
        }

        return is_array($loi) && $loi
            ? implode(' ', array_map('strval', $loi))
            : ($res->json('message') ?: $macDinh);
    }
}
