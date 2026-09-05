<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Trả hàng nhà cung cấp — dựng lại nguyên màn "Phiếu trả hàng nhà cung cấp"
 * của order v2.
 *
 * Luồng lập phiếu giữ đúng dây chuyền bên đó: chọn NCC → chọn phiếu mua đã
 * duyệt của NCC ấy → lưới hàng tự đổ ra từ phiếu mua, chỉ sửa số lượng trả.
 *
 * Trang này KHÔNG tự đoán luật: trả được bao nhiêu, sửa/xoá/duyệt được hay
 * không đều do API trả về (`returnable`, `can_edit`, `can_approve`).
 */
class TraHangNhaCungCapController extends Controller
{
    /** Nhãn ngắn cho thanh điều hướng. */
    public const TITLE = 'Trả hàng nhà cung cấp';

    public const TITLE_PAGE = 'Danh sách phiếu trả hàng nhà cung cấp';

    public const EMPTY_TEXT = 'Chưa có phiếu trả hàng nào. Bấm "Lập phiếu trả" để trả lô hàng đầu tiên về nhà cung cấp.';

    /** Trạng thái phiếu — v2 chỉ dùng hai nước: lưu tạm và đã duyệt. */
    public const TRANG_THAI = [
        'draft' => 'Lưu tạm',
        'approved' => 'Đã duyệt',
    ];

    public const MAU_TRANG_THAI = [
        'draft' => 'off',
        'approved' => 'ok',
    ];

    /** Cùng hai trạng thái đó, bằng class chữ của khung v2. */
    public const CHU_TRANG_THAI = [
        'draft' => 'text-secondary',
        'approved' => 'text-primary',
    ];

    /** Trạng thái kho suy từ trạng thái phiếu, đúng như cột bên v2. */
    public const TRANG_THAI_KHO = [
        'draft' => 'Chưa xuất',
        'approved' => 'Đã xuất',
    ];

    public const MAU_TRANG_THAI_KHO = [
        'draft' => 'off',
        'approved' => 'ok',
    ];

    public const CHU_TRANG_THAI_KHO = [
        'draft' => 'text-secondary',
        'approved' => 'text-success',
    ];

    public const SAP_XEP = [
        'newest' => 'Mới lập nhất',
        'oldest' => 'Cũ nhất',
        'document_desc' => 'Ngày chứng từ mới nhất',
        'total_desc' => 'Tiền nhiều nhất',
        'total_asc' => 'Tiền ít nhất',
    ];

    /**
     * Số dòng mỗi trang khi chưa ai chọn gì.
     *
     * 10 chứ không phải 20: bên v2 ô "Hiển thị N" không đánh dấu `selected` dòng
     * nào nên trình duyệt lấy dòng ĐẦU — tức 10 — và màn Phiếu mua hàng cạnh đây
     * cũng vậy. Để 20 thì cùng một sổ mà bản mới ra nửa số trang của bản cũ.
     */
    public const SO_DONG_MOI_TRANG = 10;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    /** Cột tắt/bật được ngoài bảng; lựa chọn lưu ở localStorage. */
    public const COT_BANG = [
        'code' => 'Mã phiếu',
        'suppliercode' => 'Mã nhà cung cấp',
        'supplier' => 'Nhà cung cấp',
        'docdate' => 'Ngày chứng từ',
        'branch' => 'Chi nhánh',
        'items' => 'Tổng tiền hàng',
        'total' => 'Tổng tiền (VAT)',
        'status' => 'Trạng thái phiếu',
        'stock' => 'Trạng thái kho',
        'creator' => 'Người lập',
        'note' => 'Ghi chú',
    ];

    /** Loại chứng từ — ô khoá trên hộp lập phiếu, y hệt v2. */
    public const LOAI_CHUNG_TU = 'Phiếu trả hàng nhà cung cấp';

    /** Trạng thái "đang làm" của hồ sơ nhân viên — GẠCH DƯỚI, khớp API. */
    public const NHAN_SU_DANG_LAM = 'dang_lam';

    public function __construct(protected ApiClient $api) {}

    // ---------------------------------------------------------------------
    // Danh sách
    // ---------------------------------------------------------------------

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $list = [];
        $meta = ['page' => 1, 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $thongKe = ['total' => 0, 'draft' => 0, 'approved' => 0, 'returned_amount' => 0];

        try {
            $res = $this->api->traHangNhaCungCap($this->query($filters));
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                $error = $this->loi($res, 'Không tải được danh sách phiếu trả hàng.');
            }

            $resTk = $this->api->traHangNhaCungCapThongKe();
            if ($resTk->successful()) {
                $thongKe = array_merge($thongKe, $resTk->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::error('Load tra hang NCC failed', ['msg' => $e->getMessage()]);
            $error = 'Chưa nối được API trả hàng nhà cung cấp — trang đang hiện bảng rỗng.';
        }

        $view = view('v2::tra-hang-nha-cung-cap.index', [
            'list' => $list,
            'filters' => $filters,
            'meta' => $meta,
            'thongKe' => $thongKe,
            'nhaCungCap' => $this->danhMucNhaCungCap(),
            'nhanVien' => $this->danhMucNhanVien(),
            'chiNhanh' => $this->chiNhanhDangLam(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Phiếu mua của một nhà cung cấp — ô "Chọn phiếu mua" trong hộp lập phiếu.
     *
     * API chỉ trả phiếu ĐÃ DUYỆT: phiếu lưu tạm chưa đưa hàng vào kho nên chẳng
     * có gì để trả lại.
     */
    public function phieuMua(Request $request)
    {
        $nccId = (int) $request->query('supplier_id', 0);
        if ($nccId <= 0) {
            return response()->json(['data' => []]);
        }

        try {
            $res = $this->api->traHangNhaCungCapPhieuMua($nccId);

            // Hỏng KHÁC rỗng: trả mảng rỗng cho cả hai thì ô chọn nói "chưa có
            // phiếu mua nào" trong khi thật ra lượt gọi mới là cái hỏng.
            if (! $res->successful()) {
                return response()->json([
                    'data' => [],
                    'message' => $this->loi($res, 'Không đọc được phiếu mua của nhà cung cấp.'),
                ], 502);
            }

            return response()->json(['data' => $res->json('data') ?? []]);
        } catch (\Throwable $e) {
            Log::error('Load phieu mua cho tra hang failed', ['msg' => $e->getMessage()]);

            return response()->json(['data' => [], 'message' => 'Không đọc được phiếu mua của nhà cung cấp.'], 502);
        }
    }

    /**
     * Dòng hàng của một phiếu mua — lưới hàng của hộp lập phiếu đổ ra từ đây.
     *
     * API tính sẵn `returnable` = min(đã mua − đã trả, tồn còn lại) cho từng
     * dòng; màn hình kẹp ô nhập theo đúng con số ấy, và API kiểm lại lần nữa
     * lúc lưu rồi lúc duyệt.
     */
    public function dongPhieuMua(Request $request)
    {
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return response()->json(['data' => []]);
        }

        try {
            $res = $this->api->traHangNhaCungCapDongPhieuMua($id);
            if (! $res->successful()) {
                return response()->json([
                    'data' => [],
                    'message' => $this->loi($res, 'Không đọc được phiếu mua.'),
                ], $res->status() === 404 ? 404 : 422);
            }
        } catch (\Throwable $e) {
            Log::error('Doc dong phieu mua cho tra hang failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['data' => [], 'message' => 'Không kết nối được API.'], 502);
        }

        $ct = $res->json('data') ?? [];

        return response()->json([
            'data' => $ct['lines'] ?? [],
            'phieu' => [
                'id' => $ct['id'] ?? 0,
                'po_code' => $ct['po_code'] ?? '',
                'supplier_id' => $ct['supplier_id'] ?? 0,
                // Nhân viên mua hàng ghi trên phiếu mua — màn lập phiếu trả điền
                // sẵn ô cùng tên theo người này.
                'purchaser_id' => (int) ($ct['purchaser_id'] ?? 0),
                'vat_mode' => $ct['vat_mode'] ?? 'order',
                'vat_percent' => (int) ($ct['vat_percent'] ?? 0),
                'document_date' => $ct['document_date'] ?? '',
            ],
        ]);
    }

    /** Chi tiết một phiếu trả — hộp Xem và hộp Sửa cùng đọc đường này. */
    public function show(Request $request, int $id)
    {
        try {
            $res = $this->api->traHangNhaCungCapChiTiet($id);
            if (! $res->successful()) {
                return response()->json(['message' => $this->loi($res, 'Không đọc được phiếu trả hàng.')], 404);
            }

            return response()->json(['data' => $res->json('data')]);
        } catch (\Throwable $e) {
            Log::error('Doc phieu tra hang failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API.'], 502);
        }
    }

    /** Xuất đúng phần đang lọc — 12 cột như bản Excel của v2. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);
        $query['page'] = 1;
        $query['page_size'] = 1000;

        try {
            $res = $this->api->traHangNhaCungCap($query);
            $list = $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Export tra hang NCC failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API để xuất tệp.');
        }

        $ten = 'tra-hang-nha-cung-cap-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($list) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'STT', 'Mã phiếu', 'Mã nhà cung cấp', 'Nhà cung cấp', 'Ngày chứng từ', 'Chi nhánh',
                'Tổng tiền hàng', 'Tổng tiền (VAT)', 'Trạng thái phiếu', 'Trạng thái kho', 'Người lập', 'Ghi chú',
            ]);

            foreach ($list as $i => $p) {
                $tt = $p['status'] ?? 'draft';
                fputcsv($out, [
                    $i + 1,
                    $p['return_code'] ?? '',
                    $p['supplier_code'] ?? '',
                    $p['supplier_name'] ?? '',
                    $this->ngay($p['document_date'] ?? null),
                    $p['branch_name'] ?? '',
                    (float) ($p['items_amount'] ?? 0),
                    (float) ($p['total_amount'] ?? 0),
                    self::TRANG_THAI[$tt] ?? '',
                    self::TRANG_THAI_KHO[$tt] ?? '',
                    $p['creator_name'] ?? '',
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
     * Lập phiếu trả.
     *
     * Nút "Duyệt" gửi kèm `duyet=1`: lưu xong gọi tiếp đường duyệt — lúc duyệt
     * mới trừ kho, nên đó là một quyền riêng bên API.
     */
    public function store(Request $request)
    {
        $data = $this->duLieu($request);

        try {
            $res = $this->api->taoTraHangNhaCungCap($data);
        } catch (\Throwable $e) {
            Log::error('Tao phieu tra hang failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return back()->withInput()->with('error', $this->loi($res, 'Lập phiếu trả hàng không thành công.'));
        }

        $phieu = $res->json('data') ?? [];
        $ma = $phieu['return_code'] ?? '';

        if (! $request->boolean('duyet')) {
            return $this->veDanhSach($request)->with('success', 'Đã lưu tạm phiếu trả '.$ma.'.');
        }

        return $this->duyetSauKhiLuu($request, (int) ($phieu['id'] ?? 0), $ma);
    }

    /** Sửa phiếu — chỉ phiếu lưu tạm; nút Duyệt cũng đi kèm được. */
    public function update(Request $request, int $id)
    {
        $data = $this->duLieu($request);

        try {
            $res = $this->api->suaTraHangNhaCungCap($id, $data);
        } catch (\Throwable $e) {
            Log::error('Sua phieu tra hang failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return back()->withInput()->with('error', $this->loi($res, 'Cập nhật phiếu trả hàng không thành công.'));
        }

        $ma = $res->json('data.return_code') ?? '';

        if (! $request->boolean('duyet')) {
            return $this->veDanhSach($request)->with('success', 'Đã cập nhật phiếu trả '.$ma.'.');
        }

        return $this->duyetSauKhiLuu($request, $id, $ma);
    }

    /** Duyệt phiếu: hàng rời kho về nhà cung cấp. */
    public function approve(Request $request, int $id)
    {
        return $this->goi(
            fn () => $this->api->duyetTraHangNhaCungCap($id, ['note' => trim((string) $request->input('note', ''))]),
            'Đã duyệt phiếu trả — hàng đã xuất kho.',
            $request
        );
    }

    public function destroy(Request $request, int $id)
    {
        return $this->goi(
            fn () => $this->api->xoaTraHangNhaCungCap($id),
            'Đã xoá phiếu trả hàng.',
            $request
        );
    }

    /** Xoá nhiều phiếu — API chỉ nhận phiếu lưu tạm, phiếu đã duyệt bị từ chối. */
    public function bulkDestroy(Request $request)
    {
        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return $this->veDanhSach($request)->with('error', 'Chưa chọn phiếu nào để xoá.');
        }

        [$ok, $hong] = $this->chayHangLoat($ids, fn (int $id) => $this->api->xoaTraHangNhaCungCap($id));

        return $this->ketQuaHangLoat(
            $request, $ok, $hong,
            'Đã xoá %d phiếu trả hàng.',
            '%d phiếu không xoá được (chỉ phiếu lưu tạm mới xoá được).'
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function filters(Request $request): array
    {
        $sort = (string) $request->query('sort', 'newest');
        $size = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => $this->locNhieu($request->query('status'), array_keys(self::TRANG_THAI)),
            'supplier_id' => (int) $request->query('supplier_id', 0),
            // Mở màn là đã lọc sẵn THÁNG NÀY, đúng như bản v2 (JS bên đó điền
            // sẵn hai ô ngày là đầu tháng → hôm nay). Rẽ theo `has()` chứ không
            // theo giá trị rỗng: gửi `from_date=` rỗng là người dùng CỐ Ý bỏ lọc
            // ngày, khác hẳn với không gửi gì.
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

    protected function query(array $f): array
    {
        return [
            'keyword' => $f['keyword'],
            'status' => $f['status'],
            'supplier_id' => $f['supplier_id'] ?: '',
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

        return implode(',', array_values(array_intersect(array_map('trim', $phan), $hopLe)));
    }

    /**
     * Ngày lọc: nhận YYYY-MM-DD (link chia sẻ) lẫn DD-MM-YYYY (ô lịch của v2).
     * Sai cả hai khuôn thì bỏ qua chứ không đoán.
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

    /**
     * Payload gửi API.
     *
     * Tên ô của form = tên trường bên API. Dòng hàng tới dưới dạng JSON vì lưới
     * hàng dựng bằng JavaScript.
     */
    protected function duLieu(Request $request): array
    {
        $o = $request->validate([
            'supplier_id' => ['required', 'integer', 'min:1'],
            'purchase_order_id' => ['required', 'integer', 'min:1'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'expired_date' => ['nullable', 'date_format:Y-m-d'],
            'purchaser_id' => ['nullable', 'integer', 'min:0'],
            'receiver_delivery_note' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:200'],
            'items' => ['required', 'string', function ($o, $v, $fail) {
                $ds = json_decode((string) $v, true);
                if (! is_array($ds) || $ds === []) {
                    $fail('Phiếu chưa có dòng hàng nào.');
                }
            }],
            'updated_at' => ['nullable', 'string', 'max:40'],
        ], [
            'supplier_id.required' => 'Chưa chọn nhà cung cấp.',
            'supplier_id.min' => 'Chưa chọn nhà cung cấp.',
            'purchase_order_id.required' => 'Chưa chọn phiếu mua để trả hàng.',
            'purchase_order_id.min' => 'Chưa chọn phiếu mua để trả hàng.',
            'items.required' => 'Phiếu chưa có dòng hàng nào.',
            'document_date.date_format' => 'Ngày chứng từ không đúng định dạng.',
            'expired_date.date_format' => 'Ngày hết hạn không đúng định dạng.',
            'note.max' => 'Ghi chú tối đa 200 ký tự.',
        ]);

        $items = json_decode((string) $o['items'], true);

        return [
            // Mốc của BẢN người dùng đang xem. API so lại để phát hiện có người
            // khác vừa lưu phiếu này — xem service.kiemBanDangSua bên API.
            'updated_at' => (string) ($o['updated_at'] ?? ''),
            'supplier_id' => (int) $o['supplier_id'],
            'purchase_order_id' => (int) $o['purchase_order_id'],
            'document_date' => (string) ($o['document_date'] ?? ''),
            'expired_date' => (string) ($o['expired_date'] ?? ''),
            'purchaser_id' => (int) ($o['purchaser_id'] ?? 0),
            'receiver_delivery_note' => trim((string) ($o['receiver_delivery_note'] ?? '')),
            'note' => trim((string) ($o['note'] ?? '')),
            // Dòng hàng chỉ gửi HAI khoá: trả dòng nào và trả bao nhiêu. Giá
            // nhập, đơn vị, số lô, thuế suất API lấy lại từ dòng phiếu mua gốc —
            // gửi từ trình duyệt thì sổ kho ghi một con số không có gốc.
            'items' => array_values(array_map(fn ($it) => [
                'purchase_item_id' => (int) ($it['purchase_item_id'] ?? 0),
                'quantity' => (int) ($it['quantity'] ?? 0),
            ], $items)),
        ];
    }

    /** Lượt duyệt đi ngay sau lượt lưu — dùng chung cho store và update. */
    protected function duyetSauKhiLuu(Request $request, int $id, string $ma)
    {
        if ($id <= 0) {
            return $this->veDanhSach($request)->with('success', 'Đã lưu phiếu trả '.$ma.'.');
        }

        try {
            $res = $this->api->duyetTraHangNhaCungCap($id);
        } catch (\Throwable $e) {
            Log::error('Duyet phieu tra hang failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return $this->veDanhSach($request)->with(
                'error',
                'Đã lưu phiếu trả '.$ma.' nhưng chưa duyệt được — không kết nối được API. Mở phiếu và bấm Duyệt lại.'
            );
        }

        if ($res->successful()) {
            return $this->veDanhSach($request)->with('success', 'Đã duyệt phiếu trả '.$ma.' — hàng đã xuất kho.');
        }

        return $this->veDanhSach($request)->with(
            'error',
            'Đã lưu tạm phiếu trả '.$ma.' nhưng chưa duyệt được: '.$this->loi($res, 'API từ chối lượt duyệt.')
        );
    }

    /** Danh mục nhà cung cấp cho ô lọc và ô chọn trong hộp thoại. */
    protected function danhMucNhaCungCap(): array
    {
        try {
            $res = $this->api->nhaCungCap([]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load NCC cho tra hang failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Chỉ người ĐANG LÀM — ô "Nhân viên mua hàng" và "Người lập". */
    protected function danhMucNhanVien(): array
    {
        try {
            $res = $this->api->nhanSu(['status' => self::NHAN_SU_DANG_LAM]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load nhan su cho tra hang failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Chi nhánh đang làm việc — ô "Chi nhánh" khoá trên hộp lập phiếu. */
    protected function chiNhanhDangLam(): array
    {
        $id = ApiClient::chiNhanhDangLam();

        try {
            $res = $this->api->chiNhanh();
            $ds = $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load chi nhanh cho tra hang failed', ['msg' => $e->getMessage()]);

            return ['id' => $id, 'name' => ''];
        }

        foreach ($ds as $cn) {
            if ((int) ($cn['id'] ?? 0) === $id) {
                return ['id' => $id, 'name' => (string) ($cn['name'] ?? '')];
            }
        }

        // Chưa chọn chi nhánh nào thì đang xem gộp — nói đúng như vậy.
        return ['id' => $id, 'name' => $id > 0 ? '' : 'Mọi chi nhánh'];
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
            Log::error('Tra hang NCC API call failed', ['msg' => $e->getMessage()]);

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
                Log::error('Bulk tra hang NCC failed', ['id' => $id, 'msg' => $e->getMessage()]);
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
            : redirect()->route('admin.tra-hang-nha-cung-cap.index');
    }
}
