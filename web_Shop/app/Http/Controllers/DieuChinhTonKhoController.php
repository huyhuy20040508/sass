<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Điều chỉnh tồn kho — chứng từ nắn lại số tồn, dựng theo màn cùng tên của
 * order v2 (warehouse/adjust).
 *
 * Vòng đời bốn nước: lưu tạm → chờ duyệt → đã duyệt (kho đổi số) hoặc → từ chối.
 * Hai loại phiếu: điều chỉnh thường và cân đối hàng âm (đưa lô âm về 0).
 */
class DieuChinhTonKhoController extends Controller
{
    public const TITLE = 'Điều chỉnh tồn kho';

    public const TITLE_PAGE = 'Danh sách phiếu điều chỉnh tồn kho';

    public const EMPTY_TEXT = 'Chưa có phiếu điều chỉnh nào. Bấm "Lập phiếu điều chỉnh" để nắn lại số tồn lần đầu.';

    /** Lô mặc định khi hàng chưa quản lý theo lô — giữ nguyên chữ của v2. */
    public const LO_KHONG_XAC_DINH = 'Không xác định';

    /** Trạng thái phiếu. */
    public const TRANG_THAI = [
        'draft' => 'Lưu tạm',
        'pending' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'rejected' => 'Từ chối',
    ];

    /** Màu chữ trạng thái — đúng bốn màu của v2: xanh lá · cam · xanh dương · đỏ. */
    public const CHU_TRANG_THAI = [
        'draft' => 'text-success',
        'pending' => 'text-warning',
        'approved' => 'text-primary',
        'rejected' => 'text-danger',
    ];

    /** Trạng thái kho — phiếu nhập/xuất sinh ra sau khi duyệt. Chữ lấy đúng bản v2. */
    public const TRANG_THAI_KHO = [
        'pending' => 'Chưa xử lý',
        'done' => 'Đã xử lý',
        'rejected' => 'Đã từ chối',
    ];

    public const CHU_TRANG_THAI_KHO = [
        'pending' => 'text-secondary',
        'done' => 'text-primary',
        'rejected' => 'text-danger',
    ];

    /** Loại phiếu — badge cột "Loại", chữ đúng bản v2 đang chạy. */
    public const LOAI_PHIEU = [
        'adjust' => 'Chỉnh tồn kho',
        'balance' => 'Cân đối hàng âm',
    ];

    /** Trạng thái nhập kho của từng dòng. */
    public const TRANG_THAI_DONG = [
        'pending' => 'Chờ duyệt',
        'approved' => 'Đã duyệt, chưa vào kho',
        'stocked' => 'Đã vào kho',
    ];

    public const SAP_XEP = [
        'newest' => 'Mới lập nhất',
        'oldest' => 'Cũ nhất',
        'code_asc' => 'Mã phiếu tăng dần',
        'code_desc' => 'Mã phiếu giảm dần',
    ];

    public const SO_DONG_MOI_TRANG = 10;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    /** Trạng thái "đang làm" của hồ sơ nhân viên — khớp domain.NhanSuDangLam. */
    public const NHAN_SU_DANG_LAM = 'dang_lam';

    public function __construct(protected ApiClient $api) {}

    // ---------------------------------------------------------------------
    // Danh sách
    // ---------------------------------------------------------------------

    /** API lọc và cắt trang sẵn — trang này chỉ dựng hình. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $list = [];
        $meta = ['page' => 1, 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];

        try {
            $res = $this->api->dieuChinhTonKho($this->query($filters));
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách phiếu điều chỉnh.';
            }
        } catch (\Throwable $e) {
            Log::error('Load dieu chinh ton kho failed', ['msg' => $e->getMessage()]);
            $error = 'Chưa nối được API điều chỉnh tồn kho — trang đang hiện bảng rỗng.';
        }

        $view = view('v2::dieu-chinh-ton-kho.index', [
            'list' => $list,
            'filters' => $filters,
            'meta' => $meta,
            'nhanVien' => $this->danhMucNhanVien(),
            'nhomHang' => $this->danhMucNhomHang(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Ô tìm hàng trong hộp lập phiếu — đi qua Laravel để token ở lại phía server.
     * Dùng chung đường mặt hàng của phiếu mua: cùng nhu cầu (mã, đơn vị, tồn).
     */
    public function matHang(Request $request)
    {
        try {
            $res = $this->api->phieuMuaHangMatHang([
                'keyword' => trim((string) $request->query('keyword', '')),
                'category_id' => (int) $request->query('category_id', 0),
                'limit' => 30,
            ]);

            return response()->json(['data' => $res->successful() ? ($res->json('data') ?? []) : []]);
        } catch (\Throwable $e) {
            Log::error('Tim mat hang cho phieu dieu chinh failed', ['msg' => $e->getMessage()]);

            return response()->json(['data' => [], 'message' => 'Không tìm được mặt hàng.'], 502);
        }
    }

    /** Cả một nhóm hàng đổ vào lưới — nút "Thêm tất cả hàng trong nhóm" của v2. */
    public function matHangTheoNhom(Request $request)
    {
        $nhom = (int) $request->query('category_id', 0);
        if ($nhom <= 0) {
            return response()->json(['data' => [], 'message' => 'Chưa chọn nhóm hàng.'], 422);
        }

        try {
            $res = $this->api->phieuMuaHangMatHang(['category_id' => $nhom, 'limit' => 500]);

            return response()->json(['data' => $res->successful() ? ($res->json('data') ?? []) : []]);
        } catch (\Throwable $e) {
            Log::error('Lay nhom hang cho phieu dieu chinh failed', ['msg' => $e->getMessage()]);

            return response()->json(['data' => [], 'message' => 'Không đọc được nhóm hàng.'], 502);
        }
    }

    /** Chi tiết một phiếu — hộp Xem và hộp Sửa cùng đọc đường này. */
    public function show(Request $request, int $id)
    {
        try {
            $res = $this->api->dieuChinhTonKhoChiTiet($id);
            if (! $res->successful()) {
                return response()->json(['message' => $res->json('message') ?: 'Không đọc được phiếu.'], 404);
            }

            return response()->json(['data' => $res->json('data')]);
        } catch (\Throwable $e) {
            Log::error('Doc phieu dieu chinh failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API.'], 502);
        }
    }

    /** Hàng đang âm chờ cân đối — nguồn của hộp "Cân đối hàng âm". */
    public function hangAm(Request $request)
    {
        try {
            $res = $this->api->dieuChinhTonKhoHangAm();
            if (! $res->successful()) {
                return response()->json([
                    'data' => [],
                    'message' => $res->json('message') ?: 'Không đọc được danh sách hàng âm.',
                ], 422);
            }

            return response()->json(['data' => $res->json('data') ?? []]);
        } catch (\Throwable $e) {
            Log::error('Doc hang am failed', ['msg' => $e->getMessage()]);

            return response()->json(['data' => [], 'message' => 'Không kết nối được API.'], 502);
        }
    }

    /** Xuất đúng phần đang lọc, lấy hết trang chứ không chỉ trang đang xem. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);
        $query['page'] = 1;
        $query['page_size'] = 1000;

        try {
            $res = $this->api->dieuChinhTonKho($query);
            $list = $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Export phieu dieu chinh failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API để xuất tệp.');
        }

        $ten = 'dieu-chinh-ton-kho-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($list) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'STT', 'Mã điều chỉnh', 'Loại', 'Người tạo', 'Ngày tạo', 'Người duyệt',
                'Trạng thái phiếu', 'Lý do từ chối', 'Trạng thái kho', 'Ghi chú',
            ]);

            foreach ($list as $i => $p) {
                fputcsv($out, [
                    $i + 1,
                    $p['code'] ?? '',
                    self::LOAI_PHIEU[$p['type'] ?? 'adjust'] ?? '',
                    $p['created_by_name'] ?? '',
                    $this->ngay($p['created_at'] ?? null),
                    $p['approver_name'] ?? '',
                    self::TRANG_THAI[$p['status'] ?? ''] ?? '',
                    $p['reject_reason'] ?? '',
                    self::TRANG_THAI_KHO[$p['warehouse_status'] ?? ''] ?? '',
                    $p['note'] ?? '',
                ]);
            }
            fclose($out);
        }, $ten, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------------------------------------------------------------------
    // Ghi
    // ---------------------------------------------------------------------

    /**
     * Lập phiếu. Nút nào bấm thì `status` nói ra: lưu tạm, gửi duyệt hay duyệt
     * luôn — đúng ba nút của v2.
     */
    public function store(Request $request)
    {
        $data = $this->duLieu($request);

        try {
            $res = $this->api->taoDieuChinhTonKho($data);
        } catch (\Throwable $e) {
            Log::error('Tao phieu dieu chinh failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return back()->withInput()->with('error', $this->loi($res, 'Lập phiếu không thành công.'));
        }

        $ma = $res->json('data.code') ?? '';

        return $this->veDanhSach($request)->with('success', $this->cauLuu($data['status'], $ma));
    }

    /** Sửa phiếu — API chỉ nhận phiếu lưu tạm. */
    public function update(Request $request, int $id)
    {
        $data = $this->duLieu($request);

        try {
            $res = $this->api->suaDieuChinhTonKho($id, $data);
        } catch (\Throwable $e) {
            Log::error('Sua phieu dieu chinh failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return back()->withInput()->with('error', $this->loi($res, 'Cập nhật phiếu không thành công.'));
        }

        $ma = $res->json('data.code') ?? '';

        return $this->veDanhSach($request)->with('success', $this->cauLuu($data['status'], $ma));
    }

    /** Gửi duyệt phiếu lưu tạm. */
    public function submit(Request $request, int $id)
    {
        return $this->goi(
            fn () => $this->api->guiDuyetDieuChinhTonKho($id),
            'Đã gửi phiếu đi duyệt.',
            $request
        );
    }

    /** Duyệt phiếu: số tồn đổi theo phiếu. */
    public function approve(Request $request, int $id)
    {
        return $this->goi(
            fn () => $this->api->duyetDieuChinhTonKho($id, trim((string) $request->input('note', ''))),
            'Đã duyệt phiếu — số tồn đã cập nhật.',
            $request
        );
    }

    /** Từ chối phiếu. Lý do bắt buộc — API cũng chặn, đây chỉ là lớp đầu. */
    public function reject(Request $request, int $id)
    {
        $lyDo = trim((string) $request->validate([
            'reject_reason' => ['required', 'string', 'max:500'],
        ], [
            'reject_reason.required' => 'Vui lòng nói rõ vì sao từ chối phiếu.',
        ])['reject_reason']);

        return $this->goi(
            fn () => $this->api->tuChoiDieuChinhTonKho($id, $lyDo),
            'Đã từ chối phiếu điều chỉnh.',
            $request
        );
    }

    /** Xoá phiếu — API chỉ nhận phiếu lưu tạm. */
    public function destroy(Request $request, int $id)
    {
        return $this->goi(
            fn () => $this->api->xoaDieuChinhTonKho($id),
            'Đã xoá phiếu điều chỉnh.',
            $request
        );
    }

    /** Ảnh chứng từ của một dòng — tải lên ngay lúc chọn, form chỉ mang đường dẫn. */
    public function uploadAnh(Request $request)
    {
        $request->validate(['anh' => ImageStore::rules()], ImageStore::messages());

        return response()->json(['url' => ImageStore::put($request->file('anh'), 'dieu-chinh-ton-kho')]);
    }

    /** Duyệt nhiều phiếu — gọi lần lượt, một phiếu hỏng không kéo theo phiếu khác. */
    public function bulkApprove(Request $request)
    {
        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return $this->veDanhSach($request)->with('error', 'Chưa chọn phiếu nào để duyệt.');
        }

        [$ok, $hong] = $this->chayHangLoat($ids, fn (int $id) => $this->api->duyetDieuChinhTonKho($id));

        return $this->ketQuaHangLoat(
            $request, $ok, $hong,
            'Đã duyệt %d phiếu — số tồn đã cập nhật.',
            '%d phiếu không duyệt được (thường vì đã duyệt hoặc đã bị từ chối từ trước).'
        );
    }

    /** Xoá nhiều phiếu — API chỉ nhận phiếu lưu tạm. */
    public function bulkDestroy(Request $request)
    {
        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return $this->veDanhSach($request)->with('error', 'Chưa chọn phiếu nào để xoá.');
        }

        [$ok, $hong] = $this->chayHangLoat($ids, fn (int $id) => $this->api->xoaDieuChinhTonKho($id));

        return $this->ketQuaHangLoat(
            $request, $ok, $hong,
            'Đã xoá %d phiếu.',
            '%d phiếu không xoá được (chỉ phiếu lưu tạm mới xoá được).'
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Bộ lọc đọc từ query, đã kẹp về giá trị hợp lệ. */
    protected function filters(Request $request): array
    {
        $sort = (string) $request->query('sort', 'newest');
        $size = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);
        $loai = (string) $request->query('type', '');

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'type' => isset(self::LOAI_PHIEU[$loai]) ? $loai : '',
            'status' => $this->locNhieu($request->query('status'), array_keys(self::TRANG_THAI)),
            'warehouse_status' => $this->locNhieu($request->query('warehouse_status'), array_keys(self::TRANG_THAI_KHO)),
            // Ô "Người tạo" của v2 là select2 nhiều lựa chọn — giữ dạng chuỗi ngăn bởi dấu phẩy.
            'created_by' => $this->locNhieuSo($request->query('created_by')),
            // Chưa gõ gì thì lấy tháng này, đúng như v2 tự điền sẵn hai ô ngày.
            'from_date' => $request->has('from_date')
                ? $this->ngayLoc($request->query('from_date'))
                : date('Y-m-01'),
            'to_date' => $request->has('to_date')
                ? $this->ngayLoc($request->query('to_date'))
                : date('Y-m-d'),
            'sort' => isset(self::SAP_XEP[$sort]) ? $sort : 'newest',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($size, self::MUC_SO_DONG, true) ? $size : self::SO_DONG_MOI_TRANG,
        ];
    }

    /** Đổi bộ lọc của trang thành query API. */
    protected function query(array $f): array
    {
        return [
            'keyword' => $f['keyword'],
            'type' => $f['type'],
            'status' => $f['status'],
            'warehouse_status' => $f['warehouse_status'],
            'created_by' => $f['created_by'],
            'from_date' => $f['from_date'],
            'to_date' => $f['to_date'],
            'sort' => $f['sort'],
            'page' => $f['page'],
            'page_size' => $f['page_size'],
        ];
    }

    /** Lọc nhiều giá trị: bỏ giá trị lạ rồi ghép lại bằng dấu phẩy. */
    protected function locNhieu($v, array $hopLe): string
    {
        $phan = is_array($v) ? $v : explode(',', (string) $v);
        $sach = array_values(array_intersect(array_map('trim', $phan), $hopLe));

        return implode(',', $sach);
    }

    /** Nhiều id: bỏ giá trị lạ rồi ghép lại bằng dấu phẩy. */
    protected function locNhieuSo($v): string
    {
        $phan = is_array($v) ? $v : explode(',', (string) $v);
        $id = array_values(array_filter(array_map('intval', $phan)));

        return implode(',', $id);
    }

    /**
     * Ngày lọc → YYYY-MM-DD. Nhận cả DD-MM-YYYY vì ô ngày của v2 gõ theo kiểu đó;
     * khuôn nào khác thì bỏ qua chứ không đoán.
     */
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

    protected function ngay(?string $v): string
    {
        if (! $v) {
            return '';
        }
        $t = strtotime($v);

        return $t ? date('d/m/Y', $t) : '';
    }

    /** Câu báo sau khi lưu, đổi theo nút đã bấm. */
    protected function cauLuu(string $status, string $ma): string
    {
        return match ($status) {
            'pending' => 'Đã gửi duyệt phiếu '.$ma.'.',
            'approved' => 'Đã duyệt phiếu '.$ma.' — số tồn đã cập nhật.',
            default => 'Đã lưu tạm phiếu '.$ma.'.',
        };
    }

    /**
     * Payload gửi API. Dòng hàng tới dưới dạng JSON vì lưới hàng dựng bằng
     * JavaScript — mảng lồng trong form là chỗ dễ lệch tên nhất.
     */
    protected function duLieu(Request $request): array
    {
        $o = $request->validate([
            'type' => ['nullable', 'in:adjust,balance'],
            'status' => ['required', 'in:draft,pending,approved'],
            'note' => ['nullable', 'string', 'max:200'],
            'items' => ['required', 'string', function ($o, $v, $fail) {
                $ds = json_decode((string) $v, true);
                if (! is_array($ds) || $ds === []) {
                    $fail('Phiếu chưa có dòng hàng nào.');
                }
            }],
        ], [
            'items.required' => 'Phiếu chưa có dòng hàng nào.',
            'status.required' => 'Chưa biết lưu phiếu ở trạng thái nào.',
            'note.max' => 'Ghi chú tối đa 200 ký tự.',
        ]);

        $items = json_decode((string) $o['items'], true);

        return [
            'type' => (string) ($o['type'] ?? 'adjust'),
            'status' => (string) $o['status'],
            'note' => trim((string) ($o['note'] ?? '')),
            // Nắn từng khoá chứ không bê nguyên dòng: lưới hàng mang theo cả tên
            // hàng và khoá dòng — những thứ API không nhận.
            'items' => array_values(array_map(fn ($it) => [
                'variant_id' => (int) ($it['variant_id'] ?? 0),
                'unit_id' => (int) ($it['unit_id'] ?? 0),
                'lot_number' => trim((string) ($it['lot_number'] ?? '')) ?: self::LO_KHONG_XAC_DINH,
                'expire_date' => trim((string) ($it['expire_date'] ?? '')),
                'quantity' => (float) ($it['quantity'] ?? 0),
                'adjust_quantity' => (float) ($it['adjust_quantity'] ?? 0),
                'attachment' => trim((string) ($it['attachment'] ?? '')),
            ], $items)),
        ];
    }

    /** Nhân viên đang làm — ô lọc "Người tạo". */
    protected function danhMucNhanVien(): array
    {
        try {
            $res = $this->api->nhanSu(['status' => self::NHAN_SU_DANG_LAM]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load nhan su cho phieu dieu chinh failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Nhóm hàng CÓ hàng — ô lọc cạnh ô tìm hàng.
     * null = không hỏi được, mảng rỗng = hỏi rồi mà chưa nhóm nào có hàng.
     */
    protected function danhMucNhomHang(): ?array
    {
        try {
            $res = $this->api->phieuMuaHangNhomHang();
            if (! $res->successful()) {
                Log::error('Load nhom hang cho phieu dieu chinh failed', ['status' => $res->status()]);

                return null;
            }

            return $res->json('data') ?? [];
        } catch (\Throwable $e) {
            Log::error('Load nhom hang cho phieu dieu chinh failed', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /** Lấy câu lỗi API nói ra, kể cả lỗi theo từng ô. */
    protected function loi($res, string $macDinh): string
    {
        if ($cau = $res->json('message')) {
            return $cau;
        }
        $o = $res->json('errors');
        if (is_array($o)) {
            $dau = reset($o);
            if (is_string($dau) && $dau !== '') {
                return $dau;
            }
        }

        return $macDinh;
    }

    /** Gọi API rồi quay lại danh sách, in nguyên văn lời API khi hỏng. */
    protected function goi(callable $goi, string $success, Request $request)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Dieu chinh ton kho API call failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return $this->veDanhSach($request)->with('success', $success);
        }

        return $this->veDanhSach($request)->with('error', $this->loi($res, 'Thao tác không thành công.'));
    }

    /** @return int[] */
    protected function idsFrom(Request $request): array
    {
        return collect((array) $request->input('ids', []))
            ->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
    }

    /** @return array{0:int,1:int} số lượt được và số lượt hỏng */
    protected function chayHangLoat(array $ids, callable $goi): array
    {
        $ok = 0;
        $hong = 0;

        foreach ($ids as $id) {
            try {
                $goi($id)->successful() ? $ok++ : $hong++;
            } catch (\Throwable $e) {
                Log::error('Bulk dieu chinh ton kho failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $hong++;
            }
        }

        return [$ok, $hong];
    }

    protected function ketQuaHangLoat(Request $request, int $ok, int $hong, string $mauOk, string $mauHong)
    {
        $ve = $this->veDanhSach($request);

        if ($hong === 0) {
            return $ve->with('success', sprintf($mauOk, $ok));
        }

        $cau = $ok > 0 ? sprintf($mauOk, $ok).' '.sprintf($mauHong, $hong) : sprintf($mauHong, $hong);

        return $ve->with($ok > 0 ? 'success' : 'error', $cau);
    }

    /** Về đúng URL cũ nếu form có gửi kèm. */
    protected function veDanhSach(Request $request)
    {
        $ve = trim((string) $request->input('return', ''));

        return $ve !== '' && str_starts_with($ve, '/')
            ? redirect($ve)
            : redirect()->route('admin.dieu-chinh-ton-kho.index');
    }
}
