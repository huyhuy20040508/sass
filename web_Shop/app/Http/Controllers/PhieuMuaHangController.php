<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use App\Support\XlsxDon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Phiếu mua hàng — chứng từ mua vào, dựng theo màn cùng tên của order v2.
 *
 * MỘT loại chứng từ cho cả chiều mua, không tách phiếu đặt với phiếu nhập như
 * ba màn cũ. Vòng đời còn hai nước: lưu tạm → duyệt (hàng vào kho) hoặc → huỷ.
 *
 * Trang này KHÔNG tự đoán luật: duyệt/sửa/xoá được hay không do API trả về
 * (`can_edit`, `can_approve`, `can_pay`) — chép luật vào giao diện là sớm muộn
 * lệch với server.
 */
class PhieuMuaHangController extends Controller
{
    /** Nhãn ngắn cho thanh điều hướng. */
    public const TITLE = 'Phiếu mua hàng';

    public const TITLE_PAGE = 'Danh sách phiếu mua hàng';

    public const EMPTY_TEXT = 'Chưa có phiếu mua hàng nào. Bấm "Lập phiếu mua" để ghi lô hàng đầu tiên.';

    /** Trạng thái phiếu — khớp hằng số bên API (domain/phieu_mua_hang.go). */
    public const TRANG_THAI = [
        'draft' => 'Lưu tạm',
        'approved' => 'Đã duyệt',
        'cancelled' => 'Đã huỷ',
    ];

    /** Màu của từng trạng thái trong bảng. */
    public const MAU_TRANG_THAI = [
        'draft' => 'warn',
        'approved' => 'ok',
        'cancelled' => 'off',
    ];

    /** Cùng ba trạng thái đó, nhưng bằng class chữ của khung v2. */
    public const CHU_TRANG_THAI = [
        'draft' => 'text-danger',
        'approved' => 'text-primary',
        'cancelled' => 'text-secondary',
    ];

    public const TRANG_THAI_TRA = [
        'unpaid' => 'Chưa trả',
        'partial' => 'Trả một phần',
        'paid' => 'Đã trả đủ',
    ];

    public const MAU_TRANG_THAI_TRA = [
        'unpaid' => 'off',
        'partial' => 'warn',
        'paid' => 'ok',
    ];

    public const CHU_TRANG_THAI_TRA = [
        'unpaid' => 'text-secondary',
        'partial' => 'text-success',
        'paid' => 'text-primary',
    ];

    /**
     * Trạng thái nhập kho — ô lọc riêng của bản v2.
     *
     * Không phải một cột trong sổ: phiếu duyệt xong là hàng vào kho, nên trạng
     * thái kho SUY RA từ trạng thái phiếu. Giữ ô lọc riêng vì bên v2 có, và vì
     * người dùng nghĩ theo hai câu hỏi khác nhau ("phiếu đã chốt chưa" và "hàng
     * về chưa"), dù ở đây hai câu ấy cho cùng một đáp án.
     */
    public const TRANG_THAI_KHO = [
        'in' => 'Đã nhập kho',
        'not_in' => 'Chưa nhập kho',
    ];

    public const CHU_TRANG_THAI_KHO = [
        'in' => 'text-primary',
        'not_in' => 'text-secondary',
    ];

    /** Mỗi trạng thái kho ứng với những trạng thái phiếu nào. */
    protected const KHO_THEO_TRANG_THAI = [
        'in' => ['approved'],
        'not_in' => ['draft'],
    ];

    /** Cách khai thuế — bản v2 gọi là allow_vat_purchase. */
    public const KIEU_VAT = [
        'order' => 'Một mức cho cả phiếu',
        'goods' => 'Mỗi dòng hàng một mức',
    ];

    public const SAP_XEP = [
        'newest' => 'Mới lập nhất',
        'oldest' => 'Cũ nhất',
        'document_desc' => 'Ngày chứng từ mới nhất',
        'total_desc' => 'Tiền nhiều nhất',
        'total_asc' => 'Tiền ít nhất',
    ];

    /** Nhãn hình thức trả tiền — khớp hằng bên API (domain.PurchasePayMethod*). */
    public const CACH_TRA = [
        'cash' => 'Tiền mặt',
        'transfer' => 'Chuyển khoản',
    ];

    public const SO_DONG_MOI_TRANG = 10;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    /** Các cột tắt/bật được ngoài bảng; lựa chọn lưu ở localStorage. */
    public const COT_BANG = [
        'code' => 'Mã phiếu',
        'supplier' => 'Nhà cung cấp',
        'docdate' => 'Ngày chứng từ',
        'items' => 'Tiền hàng',
        'total' => 'Tổng tiền',
        'debt' => 'Còn nợ',
        'status' => 'Trạng thái',
        'warehouse' => 'Trạng thái kho',
        'pay' => 'Thanh toán',
        'creator' => 'Người tạo',
        'note' => 'Ghi chú',
    ];

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
        $thongKe = ['total' => 0, 'draft' => 0, 'approved' => 0, 'cancelled' => 0,
            'purchased_amount' => 0, 'debt_amount' => 0];

        try {
            $res = $this->api->phieuMuaHang($this->query($filters));
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách phiếu mua hàng.';
            }

            $resTk = $this->api->phieuMuaHangThongKe();
            if ($resTk->successful()) {
                $thongKe = array_merge($thongKe, $resTk->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::error('Load phieu mua hang failed', ['msg' => $e->getMessage()]);
            $error = 'Chưa nối được API phiếu mua hàng — trang đang hiện bảng rỗng.';
        }

        $view = view('v2::phieu-mua-hang.index', [
            'thueTrucTiep' => $this->thueTrucTiep(),
            'list' => $list,
            'filters' => $filters,
            'meta' => $meta,
            'thongKe' => $thongKe,
            'nhaCungCap' => $this->danhMucNhaCungCap(),
            'nhanVien' => $this->danhMucNhanVien(),
            'nhomHang' => $this->danhMucNhomHang(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Ô tìm hàng trong hộp thoại lập phiếu.
     *
     * Đi qua Laravel chứ không gọi thẳng API từ trình duyệt: token nằm trong
     * session phía server, đẩy nó ra JavaScript là đưa chìa khoá cho mọi tiện
     * ích mở rộng đang chạy trên tab đó.
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
            Log::error('Tim mat hang cho phieu mua failed', ['msg' => $e->getMessage()]);

            return response()->json(['data' => [], 'message' => 'Không tìm được mặt hàng.'], 502);
        }
    }

    /** Chi tiết một phiếu — hộp Xem và hộp Sửa cùng đọc đường này. */
    public function show(Request $request, int $id)
    {
        try {
            $res = $this->api->phieuMuaHangChiTiet($id);
            if (! $res->successful()) {
                return response()->json(['message' => $res->json('message') ?: 'Không đọc được phiếu.'], 404);
            }

            return response()->json(['data' => $res->json('data')]);
        } catch (\Throwable $e) {
            Log::error('Doc phieu mua hang failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API.'], 502);
        }
    }

    /**
     * Xuất danh sách đang lọc ra .xlsx.
     *
     * .xlsx THẬT chứ không phải .csv đội tên: nút trên màn ghi "Xuất Excel", mà
     * CSV thì Excel hỏi lại một lượt về dấu phân cách, số tiền dài bị hiểu thành
     * ngày, và cột nào cũng là chữ nên không cộng được. Xem App\Support\XlsxDon
     * — dựng bằng ZipArchive sẵn có của PHP, không kéo thêm thư viện nào.
     *
     * Lấy hết trang chứ không chỉ trang đang xem: người ta lọc ra một nhóm rồi
     * mới bấm xuất, mà mặc định mỗi trang chỉ mười dòng.
     */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);
        $query['page'] = 1;
        $query['page_size'] = 1000;

        try {
            $res = $this->api->phieuMuaHang($query);
            $list = $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Export phieu mua hang failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API để xuất tệp.');
        }

        $hang = [[
            'STT', 'Mã phiếu', 'Nhà cung cấp', 'Ngày chứng từ', 'Ngày lập',
            'Tiền hàng', 'Chiết khấu', 'Thuế GTGT', 'Tổng tiền', 'Đã trả', 'Còn nợ',
            'Trạng thái', 'Thanh toán', 'Ghi chú',
        ]];

        foreach ($list as $i => $p) {
            $tong = (float) ($p['total_amount'] ?? 0);
            $daTra = (float) ($p['paid_amount'] ?? 0);
            $hang[] = [
                $i + 1,
                (string) ($p['po_code'] ?? ''),
                (string) ($p['supplier_name'] ?? ''),
                $this->ngay($p['document_date'] ?? null),
                $this->ngay($p['created_at'] ?? null),
                (float) ($p['items_amount'] ?? 0),
                (float) ($p['discount_amount'] ?? 0),
                (float) ($p['vat_amount'] ?? 0),
                $tong,
                $daTra,
                max(0, $tong - $daTra),
                self::TRANG_THAI[$p['status'] ?? ''] ?? '',
                self::TRANG_THAI_TRA[$p['payment_status'] ?? ''] ?? '',
                (string) ($p['note'] ?? ''),
            ];
        }

        return $this->taiXlsx($hang, 'phieu-mua-hang-'.date('Ymd-His'), 'Phieu mua hang');
    }

    /**
     * Xuất MỘT phiếu ra .xlsx — thông tin phiếu, từng dòng hàng, rồi tổng.
     *
     * Xuất bản ĐÃ LƯU chứ không phải thứ đang gõ dở trên màn: tệp này rời khỏi
     * phần mềm, gửi cho kế toán hay bên bán, nên nó phải khớp với chứng từ trong
     * sổ. (Nút In thì ngược lại — in đúng cái đang nhìn thấy, kể cả chưa lưu.)
     */
    public function exportOne(Request $request, int $id)
    {
        try {
            $res = $this->api->phieuMuaHangChiTiet($id);
            if (! $res->successful()) {
                return back()->with('error', 'Không đọc được phiếu để xuất tệp.');
            }
            $p = $res->json('data') ?? [];
        } catch (\Throwable $e) {
            Log::error('Export mot phieu mua hang failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API để xuất tệp.');
        }

        $trucTiep = $this->thueTrucTiep();
        $ma = (string) ($p['po_code'] ?? $id);

        $hang = [
            ['Phiếu mua hàng', $ma],
            ['Nhà cung cấp', (string) ($p['supplier_name'] ?? '')],
            ['Ngày chứng từ', $this->ngay($p['document_date'] ?? null)],
            ['Ngày hết hạn', $this->ngay($p['expected_date'] ?? null)],
            ['Trạng thái', self::TRANG_THAI[$p['status'] ?? ''] ?? ''],
            ['Ghi chú', (string) ($p['note'] ?? '')],
            [],
        ];

        // Thuế trực tiếp thì bỏ hẳn ba cột VAT, y như lưới trên màn và tờ in —
        // xuất ra một dải "0" là mâu thuẫn với chính chứng từ người ta vừa xem.
        $tieuDe = ['STT', 'Mã hàng hóa', 'Tên hàng hóa', 'Đơn vị', 'Số lượng', 'Giá nhập',
            $trucTiep ? 'Thành tiền' : 'Thành tiền (chưa VAT)'];
        if (! $trucTiep) {
            $tieuDe = array_merge($tieuDe, ['VAT %', 'Tiền thuế VAT', 'Tổng tiền sau VAT']);
        }
        $hang[] = array_merge($tieuDe, ['Số lô', 'Ngày hết hạn']);

        foreach (($p['items'] ?? []) as $i => $it) {
            $sl = (float) ($it['quantity'] ?? 0);
            $gia = (float) ($it['unit_cost'] ?? 0);
            $vat = (int) ($it['vat_percent'] ?? 0);
            $tienHang = round($gia * $sl);
            $thue = (int) round($tienHang * max(0, $vat) / 100);

            $dong = [
                $i + 1,
                (string) ($it['variant_sku'] ?? ''),
                trim(implode(' · ', array_filter([$it['product_name'] ?? '', $it['variant_name'] ?? '']))),
                (string) ($it['unit_name'] ?? ''),
                $sl,
                $gia,
                (float) $tienHang,
            ];
            if (! $trucTiep) {
                $dong = array_merge($dong, [$vat, (float) $thue, (float) ($tienHang + $thue)]);
            }
            $hang[] = array_merge($dong, [
                (string) ($it['lot_number'] ?? ''),
                $this->ngay($it['expire_date'] ?? null),
            ]);
        }

        $hang[] = [];
        if (! $trucTiep) {
            $hang[] = ['Tổng thành tiền (chưa VAT)', (float) ($p['items_amount'] ?? 0)];
            $hang[] = ['Tổng tiền thuế (VAT)', (float) ($p['vat_amount'] ?? 0)];
        }
        $hang[] = ['Tổng tiền', (float) ($p['total_amount'] ?? 0)];
        $hang[] = ['Đã trả', (float) ($p['paid_amount'] ?? 0)];

        // Sổ trả tiền đi kèm: tệp gửi cho kế toán mà chỉ có mỗi con số luỹ kế thì
        // họ vẫn phải hỏi lại đã trả mấy lượt, bằng hình thức gì.
        if (! empty($p['payments'])) {
            $hang[] = [];
            $hang[] = ['Sổ trả tiền'];
            $hang[] = ['Thời điểm', 'Số tiền', 'Hình thức', 'Luỹ kế sau lượt', 'Người ghi', 'Ghi chú'];
            foreach ($p['payments'] as $t) {
                $hang[] = [
                    $this->ngay($t['created_at'] ?? null),
                    (float) ($t['amount'] ?? 0),
                    self::CACH_TRA[$t['payment_method'] ?? ''] ?? '',
                    (float) ($t['paid_after'] ?? 0),
                    (string) ($t['created_by_name'] ?? ''),
                    (string) ($t['note'] ?? ''),
                ];
            }
        }

        return $this->taiXlsx($hang, 'phieu-mua-hang-'.$ma, 'Phieu '.$ma);
    }

    /** Đẩy một bảng ra .xlsx cho trình duyệt tải về. */
    protected function taiXlsx(array $hang, string $ten, string $tenSheet)
    {
        return response(XlsxDon::noiDung($hang, $tenSheet), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$ten.'.xlsx"',
        ]);
    }

    // ---------------------------------------------------------------------
    // Ghi
    // ---------------------------------------------------------------------

    /**
     * Lập phiếu.
     *
     * Nút "Duyệt & nhập kho" gửi kèm `duyet=1`: lập phiếu xong gọi tiếp đường
     * duyệt. Hai lượt gọi chứ không một — API cố ý tách vì duyệt có quyền riêng
     * canh. Duyệt hỏng thì phiếu vẫn nằm đó ở dạng lưu tạm, người dùng bấm lại
     * được; không có gì bị mất.
     */
    public function store(Request $request)
    {
        $data = $this->duLieu($request);

        try {
            $res = $this->api->taoPhieuMuaHang($data);
        } catch (\Throwable $e) {
            Log::error('Tao phieu mua hang failed', ['msg' => $e->getMessage()]);

            return $this->traLoiGhi($request, 'Không kết nối được API. Vui lòng thử lại.', false, 502);
        }

        if (! $res->successful()) {
            return $this->traLoiGhi($request, $this->loi($res, 'Lập phiếu không thành công.'), false);
        }

        $phieu = $res->json('data') ?? [];
        $ma = $phieu['po_code'] ?? '';

        if (! $request->boolean('duyet')) {
            return $this->traLoiGhi($request, 'Đã lưu tạm phiếu '.$ma.'.');
        }

        return $this->duyetSauKhiLuu($request, (int) ($phieu['id'] ?? 0), $ma);
    }

    /** Sửa phiếu. API chỉ nhận phiếu lưu tạm; nút Duyệt cũng đi kèm được. */
    public function update(Request $request, int $id)
    {
        $data = $this->duLieu($request);

        try {
            $res = $this->api->suaPhieuMuaHang($id, $data);
        } catch (\Throwable $e) {
            Log::error('Sua phieu mua hang failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return $this->traLoiGhi($request, 'Không kết nối được API. Vui lòng thử lại.', false, 502);
        }

        if (! $res->successful()) {
            return $this->traLoiGhi($request, $this->loi($res, 'Cập nhật phiếu không thành công.'), false);
        }

        $ma = $res->json('data.po_code') ?? '';

        if (! $request->boolean('duyet')) {
            return $this->traLoiGhi($request, 'Đã cập nhật phiếu '.$ma.'.');
        }

        return $this->duyetSauKhiLuu($request, $id, $ma);
    }

    /** Duyệt phiếu: hàng vào kho. */
    public function approve(Request $request, int $id)
    {
        return $this->goi(
            fn () => $this->api->duyetPhieuMuaHang($id, ['note' => trim((string) $request->input('note', ''))]),
            'Đã duyệt phiếu — hàng đã vào kho.',
            $request
        );
    }

    /** Huỷ phiếu lưu tạm. Lý do bắt buộc — API cũng chặn, đây chỉ là lớp đầu. */
    public function cancel(Request $request, int $id)
    {
        $lyDo = trim((string) $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ], [
            'note.required' => 'Vui lòng nói rõ vì sao huỷ phiếu.',
        ])['note']);

        return $this->goi(
            fn () => $this->api->huyPhieuMuaHang($id, $lyDo),
            'Đã huỷ phiếu mua hàng.',
            $request
        );
    }

    /** Ghi nhận tiền đã trả nhà cung cấp. */
    public function pay(Request $request, int $id)
    {
        $o = $request->validate([
            'paid_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', 'in:cash,transfer'],
            'is_debt' => ['nullable', 'boolean'],
            // Khuôn ngày của đường API là Y-m-d; ô trên màn gõ d-m-Y nên trình
            // duyệt đã đổi trước khi gửi.
            'debt_due_date' => ['nullable', 'date_format:Y-m-d'],
            'debt_contact_name' => ['nullable', 'string', 'max:150'],
            'debt_contact_phone' => ['nullable', 'string', 'max:30'],
            'payment_attachment' => ['nullable', 'string', 'max:255'],
        ], [
            'paid_amount.required' => 'Chưa nhập số tiền đã trả.',
            'paid_amount.min' => 'Số tiền đã trả không được là số âm.',
            'payment_method.in' => 'Hình thức thanh toán chỉ nhận tiền mặt hoặc chuyển khoản.',
            'debt_due_date.date_format' => 'Hạn nợ không đúng khuôn ngày.',
        ]);

        // Ba trường của thoả thuận nợ chỉ gửi đi khi CÓ ghi nợ. Gửi kèm lúc
        // không nợ thì server phải tự đoán xem nên tin cái nào — luật dọn sạch
        // nằm bên đó, đây chỉ cần nói đúng ý định.
        $coNo = $request->boolean('is_debt');
        $them = [
            'payment_method' => (string) ($o['payment_method'] ?? ''),
            'payment_attachment' => (string) ($o['payment_attachment'] ?? ''),
            'is_debt' => $coNo,
            'debt_due_date' => $coNo ? (string) ($o['debt_due_date'] ?? '') : '',
            'debt_contact_name' => $coNo ? trim((string) ($o['debt_contact_name'] ?? '')) : '',
            'debt_contact_phone' => $coNo ? trim((string) ($o['debt_contact_phone'] ?? '')) : '',
        ];

        return $this->goi(
            fn () => $this->api->traTienPhieuMuaHang(
                $id, (float) $o['paid_amount'], (string) ($o['note'] ?? ''), $them
            ),
            'Đã ghi nhận thanh toán.',
            $request
        );
    }

    public function destroy(Request $request, int $id)
    {
        return $this->goi(
            fn () => $this->api->xoaPhieuMuaHang($id),
            'Đã xoá phiếu mua hàng.',
            $request
        );
    }

    /**
     * Thêm nhanh nhà cung cấp ngay trong hộp lập phiếu (nút "+" cạnh ô chọn).
     *
     * Trả JSON chứ không quay lại danh sách như trang Nhà cung cấp: người dùng
     * đang gõ dở một phiếu, đá họ sang trang khác là mất trắng lưới hàng.
     */
    public function themNhanhNhaCungCap(Request $request)
    {
        // Cùng bộ luật với NhaCungCapController::duLieu — hộp thoại là một, thì
        // thứ nó nhận vào cũng phải là một.
        //
        // Soát bằng Validator chứ không phải $request->validate(): đường này chỉ
        // nói JSON, mà validate() lại rẽ theo header Accept của người gọi —
        // thiếu header là nó trả về một lượt CHUYỂN TRANG, và bên kia
        // res.json() vỡ với một câu lỗi chẳng liên quan gì tới ô nhập nào.
        $soat = Validator::make($request->all(), [
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'representative_name' => ['nullable', 'string', 'max:150'],
            'representative_phone' => ['nullable', 'regex:/^0[0-9]{9,10}$/'],
            'note' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ], [
            'name.required' => 'Chưa nhập tên nhà cung cấp.',
            'address.required' => 'Chưa nhập địa chỉ.',
            'code.regex' => 'Mã nhà cung cấp chỉ gồm chữ và số, không dấu cách.',
            'representative_phone.regex' => 'Số điện thoại người đại diện phải bắt đầu bằng 0 và có 10-11 chữ số.',
            'email.email' => 'Email không đúng định dạng.',
        ]);

        if ($soat->fails()) {
            return response()->json([
                'message' => $soat->errors()->first(),
                'errors' => $soat->errors()->toArray(),
            ], 422);
        }

        $o = $soat->validated();

        // API nói `is_active` như mọi danh mục khác; hộp thoại giữ tên `status`
        // của v2 — đổi tên ở đúng một chỗ, như NhaCungCapController vẫn làm.
        $o['is_active'] = (int) ($o['status'] ?? NhaCungCapController::DANG_HOP_TAC) === NhaCungCapController::DANG_HOP_TAC;
        unset($o['status']);

        try {
            $res = $this->api->taoNhaCungCap($o);
        } catch (\Throwable $e) {
            Log::error('Them nhanh NCC tu phieu mua failed', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API.'], 502);
        }

        if (! $res->successful()) {
            return response()->json(['message' => $this->loi($res, 'Thêm nhà cung cấp không thành công.')], 422);
        }

        return response()->json(['data' => $res->json('data')]);
    }

    /**
     * Ảnh chụp chứng từ bên bán.
     *
     * Tải lên ngay lúc chọn, form chỉ mang theo đường dẫn — cùng lối với ảnh
     * nhà cung cấp. Gửi kèm file trong form lập phiếu thì lưu hỏng là mất luôn
     * ảnh, mà lưới hàng gõ lại thì lâu.
     */
    public function uploadAnh(Request $request)
    {
        $request->validate(['anh' => ImageStore::rules()], ImageStore::messages());

        return response()->json(['url' => ImageStore::put($request->file('anh'), 'phieu-mua-hang')]);
    }

    /**
     * Duyệt nhiều phiếu một lượt.
     *
     * Gọi lần lượt chứ không có đường gộp bên API: mỗi phiếu là một giao dịch
     * kho riêng, và một phiếu hỏng không được kéo theo những phiếu đã vào kho
     * xong. Đếm riêng số hỏng để câu báo nói đúng chuyện gì đã xảy ra.
     */
    public function bulkApprove(Request $request)
    {
        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return $this->veDanhSach($request)->with('error', 'Chưa chọn phiếu nào để duyệt.');
        }

        [$ok, $hong] = $this->chayHangLoat($ids, fn (int $id) => $this->api->duyetPhieuMuaHang($id));

        return $this->ketQuaHangLoat(
            $request, $ok, $hong,
            'Đã duyệt %d phiếu — hàng đã vào kho.',
            '%d phiếu không duyệt được (thường vì đã duyệt hoặc đã huỷ từ trước).'
        );
    }

    /** Xoá nhiều phiếu — API chỉ nhận phiếu lưu tạm, phiếu khác bị từ chối. */
    public function bulkDestroy(Request $request)
    {
        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return $this->veDanhSach($request)->with('error', 'Chưa chọn phiếu nào để xoá.');
        }

        [$ok, $hong] = $this->chayHangLoat($ids, fn (int $id) => $this->api->xoaPhieuMuaHang($id));

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

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            // Nhiều ô tick: giữ nguyên dạng chuỗi ngăn bởi dấu phẩy như API nhận.
            'status' => $this->locNhieu($request->query('status'), array_keys(self::TRANG_THAI)),
            'payment_status' => $this->locNhieu($request->query('payment_status'), array_keys(self::TRANG_THAI_TRA)),
            'warehouse_status' => $this->locNhieu($request->query('warehouse_status'), array_keys(self::TRANG_THAI_KHO)),
            'supplier_id' => (int) $request->query('supplier_id', 0),
            // Ô "Hàng hoá" của v2 là ô chọn nhiều — giữ nguyên dạng chuỗi ngăn bởi
            // dấu phẩy như API nhận.
            'variant_id' => $this->locSoNhieu($request->query('variant_id')),
            // Mở màn là đã lọc sẵn THÁNG NÀY, đúng như bản v2 (và giống màn Điều
            // chỉnh tồn kho bên cạnh). Sổ mua hàng chạy vài năm thì mặc định "cả
            // sổ" là mỗi lượt vào trang lại đọc mấy nghìn dòng mà người dùng chỉ
            // nhìn chục dòng đầu.
            //
            // Rẽ theo `has()` chứ không theo giá trị rỗng: gửi lên `from_date=`
            // rỗng là người dùng CỐ Ý bỏ lọc ngày (nút "Mọi thời gian"), khác hẳn
            // với không gửi gì — mà nếu chỉ nhìn giá trị thì hai cái đó giống nhau
            // và không có cách nào tắt được bộ lọc mặc định.
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
            'status' => $this->trangThaiGuiDi($f),
            'payment_status' => $f['payment_status'],
            'supplier_id' => $f['supplier_id'] ?: '',
            'variant_id' => $f['variant_id'],
            'from_date' => $f['from_date'],
            'to_date' => $f['to_date'],
            'sort' => $f['sort'],
            'page' => $f['page'],
            'page_size' => $f['page_size'],
        ];
    }

    /**
     * Trạng thái phiếu gửi đi API — GỘP ô "Trạng thái đơn" với ô "Trạng thái kho".
     *
     * API chỉ biết trạng thái phiếu; trạng thái kho suy ra từ nó (xem
     * TRANG_THAI_KHO). Tick cả hai ô thì phải ra GIAO của hai tập.
     *
     * Giao rỗng (VD "Đã duyệt" + "Chưa nhập kho") trả về 'none': để chuỗi rỗng là
     * API hiểu thành "mọi trạng thái" và bảng bày ra đúng những phiếu người dùng
     * vừa loại trừ.
     */
    protected function trangThaiGuiDi(array $f): string
    {
        $phieu = array_filter(explode(',', $f['status']));
        $kho = array_filter(explode(',', $f['warehouse_status']));

        if ($kho === []) {
            return $f['status'];
        }

        $tuKho = array_merge(...array_map(fn ($k) => self::KHO_THEO_TRANG_THAI[$k], $kho));
        $giao = $phieu === [] ? $tuKho : array_intersect($phieu, $tuKho);

        return $giao === [] ? 'none' : implode(',', $giao);
    }

    /**
     * Cửa hàng là hộ kinh doanh nộp thuế TRỰC TIẾP — bản v2 gọi là
     * `admin('tax_type')->value == 'direct'`.
     *
     * Bật thì chiều mua KHÔNG có đường VAT: màn hình bỏ ô thuế của phiếu, ba cột
     * thuế trên lưới hàng và hai dòng thuế ở khối tiền, còn payload luôn ghi 0.
     *
     * Ép ở CẢ hai đầu (giao diện giấu ô, controller ghi đè) chứ không chỉ giấu ô:
     * giấu ô mà vẫn gửi con số cũ thì một cửa hàng bật công tắc này giữa chừng sẽ
     * lập ra phiếu có thuế mà trên màn hình không chỗ nào nói ra.
     */
    protected function thueTrucTiep(): bool
    {
        return $this->api->settingBool('tax_direct');
    }

    /** Danh sách id ngăn bởi dấu phẩy: bỏ phần không phải số dương. */
    protected function locSoNhieu($v): string
    {
        $phan = is_array($v) ? $v : explode(',', (string) $v);
        $sach = array_values(array_unique(array_filter(array_map('intval', $phan), fn ($n) => $n > 0)));

        return implode(',', $sach);
    }

    /**
     * Lọc nhiều giá trị: bỏ giá trị lạ rồi ghép lại bằng dấu phẩy.
     *
     * Nhận cả mảng (form gửi `status[]`) lẫn chuỗi (link chia sẻ) để URL dán vào
     * trình duyệt vẫn ra đúng bảng đó.
     */
    protected function locNhieu($v, array $hopLe): string
    {
        $phan = is_array($v) ? $v : explode(',', (string) $v);
        $sach = array_values(array_intersect(array_map('trim', $phan), $hopLe));

        return implode(',', $sach);
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
     * Tên ô của form = tên trường bên API nên gửi thẳng đi được. Dòng hàng tới
     * dưới dạng JSON vì lưới hàng dựng bằng JavaScript — mảng lồng trong form
     * thường là chỗ dễ lệch tên nhất.
     */
    protected function duLieu(Request $request): array
    {
        $o = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'min:0'],
            'supplier_name' => ['nullable', 'string', 'max:150'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
            'purchaser_id' => ['nullable', 'integer', 'min:0'],
            'supplier_delivery_code' => ['nullable', 'string', 'max:50'],
            'vat_mode' => ['nullable', 'in:order,goods'],
            'vat_percent' => ['nullable', 'integer', 'between:-2,100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'attachment' => ['nullable', 'string', 'max:255'],
            // Soát ngay tại luật để lỗi đi cùng đường với mọi ô khác — bắt ở dưới
            // rồi tự dựng redirect là một lối thoát thứ hai cho cùng một việc.
            'items' => ['required', 'string', function ($o, $v, $fail) {
                $ds = json_decode((string) $v, true);
                if (! is_array($ds) || $ds === []) {
                    $fail('Phiếu chưa có dòng hàng nào.');
                }
            }],
            'updated_at' => ['nullable', 'string', 'max:40'],
        ], [
            'items.required' => 'Phiếu chưa có dòng hàng nào.',
            'document_date.date_format' => 'Ngày chứng từ không đúng định dạng.',
            'expected_date.date_format' => 'Ngày hẹn giao không đúng định dạng.',
        ]);

        $items = json_decode((string) $o['items'], true);

        // Hộ kinh doanh nộp thuế trực tiếp: chiều mua không có đường VAT. Ghi đè ở
        // đây, không tin vào việc giao diện đã giấu ô — form gửi lại sau khi lưu
        // hỏng, hay một lượt gọi tay, đều đi qua đúng chỗ này.
        $trucTiep = $this->thueTrucTiep();

        return [
            // Mốc của BẢN người dùng đang xem. API so lại để phát hiện có người
            // khác vừa lưu phiếu này — xem service.kiemBanDangSua bên API.
            'updated_at' => (string) ($o['updated_at'] ?? ''),
            'supplier_id' => (int) ($o['supplier_id'] ?? 0),
            'supplier_name' => trim((string) ($o['supplier_name'] ?? '')),
            'document_date' => (string) ($o['document_date'] ?? ''),
            'expected_date' => (string) ($o['expected_date'] ?? ''),
            'purchaser_id' => (int) ($o['purchaser_id'] ?? 0),
            'supplier_delivery_code' => trim((string) ($o['supplier_delivery_code'] ?? '')),
            'vat_mode' => $trucTiep ? 'order' : (string) ($o['vat_mode'] ?? 'order'),
            'vat_percent' => $trucTiep ? 0 : (int) ($o['vat_percent'] ?? 0),
            'discount_amount' => (float) ($o['discount_amount'] ?? 0),
            'paid_amount' => (float) ($o['paid_amount'] ?? 0),
            'note' => trim((string) ($o['note'] ?? '')),
            'attachment' => trim((string) ($o['attachment'] ?? '')),
            // Nắn từng khoá một chứ không bê nguyên $it: dòng hàng do JavaScript
            // dựng nên nó mang theo cả tên hàng, danh sách đơn vị, khoá dòng —
            // những thứ API không nhận. Nhưng nắn thì phải nắn ĐỦ: thiếu một
            // khoá ở đây là ô đó trên màn hình gõ xong bay mất, không báo gì.
            'items' => array_values(array_map(fn ($it) => [
                'variant_id' => (int) ($it['variant_id'] ?? 0),
                'unit_id' => (int) ($it['unit_id'] ?? 0),
                'quantity' => (int) ($it['quantity'] ?? 0),
                'unit_cost' => (float) ($it['unit_cost'] ?? 0),
                'vat_percent' => $trucTiep ? 0 : (int) ($it['vat_percent'] ?? 0),
                'lot_number' => trim((string) ($it['lot_number'] ?? '')),
                'expire_date' => trim((string) ($it['expire_date'] ?? '')),
            ], $items)),
        ];
    }

    /** Lượt duyệt đi ngay sau lượt lưu — dùng chung cho store và update. */
    protected function duyetSauKhiLuu(Request $request, int $id, string $ma)
    {
        if ($id <= 0) {
            return $this->traLoiGhi($request, 'Đã lưu phiếu '.$ma.'.');
        }

        try {
            $res = $this->api->duyetPhieuMuaHang($id);
        } catch (\Throwable $e) {
            Log::error('Duyet phieu mua hang failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return $this->traLoiGhi(
                $request,
                'Đã lưu phiếu '.$ma.' nhưng chưa duyệt được — không kết nối được API. Mở phiếu và bấm Duyệt lại.',
                true, 200, true
            );
        }

        if ($res->successful()) {
            return $this->traLoiGhi($request, 'Đã duyệt phiếu '.$ma.' — hàng đã vào kho.');
        }

        // Phiếu ĐÃ lưu, chỉ lượt duyệt hỏng. Nói rõ cả hai vế: người dùng cần
        // biết dữ liệu còn nguyên, chỉ là hàng chưa vào kho.
        return $this->traLoiGhi(
            $request,
            'Đã lưu tạm phiếu '.$ma.' nhưng chưa duyệt được: '.$this->loi($res, 'API từ chối lượt duyệt.'),
            true, 200, true
        );
    }

    /** Danh mục nhà cung cấp cho ô lọc và ô chọn trong hộp thoại. */
    protected function danhMucNhaCungCap(): array
    {
        try {
            $res = $this->api->nhaCungCap([]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load nha cung cap cho phieu mua failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Nhóm hàng — ô lọc đứng cạnh ô tìm hàng trong hộp lập phiếu.
     *
     * Đi đường riêng của phiếu mua chứ không lấy cả danh mục nhóm: ô này chỉ
     * dùng để lọc ô tìm hàng, nên nó phải bày ra ĐÚNG những nhóm mà ô tìm hàng
     * tra ra được. Bày cả nhóm rỗng thì chọn vào là bảng trắng, và người dùng
     * không có cách nào biết đó là nhóm rỗng hay là lỗi.
     */
    protected function danhMucNhomHang(): ?array
    {
        try {
            $res = $this->api->phieuMuaHangNhomHang();
            if (! $res->successful()) {
                Log::error('Load nhom hang cho phieu mua failed', ['status' => $res->status()]);

                return null;
            }

            return $res->json('data') ?? [];
        } catch (\Throwable $e) {
            Log::error('Load nhom hang cho phieu mua failed', ['msg' => $e->getMessage()]);

            // null KHÁC mảng rỗng: mảng rỗng là "hỏi rồi, chưa nhóm nào có hàng",
            // còn null là "không hỏi được". Trả cùng một giá trị cho hai chuyện
            // đó thì một API cũ hay một lượt mạng chập trông y hệt một kho chưa
            // có mặt hàng nào — và người dùng đi tìm lỗi ở nhầm chỗ.
            return null;
        }
    }

    /**
     * Trạng thái "đang làm" của hồ sơ nhân viên — GẠCH DƯỚI.
     *
     * Chép từ domain.NhanSuDangLam bên API. Viết thành 'dang-lam' gạch ngang
     * thì repository ghép thẳng vào `WHERE status = ?` và không dòng nào khớp:
     * cửa hàng có đủ người mà ô chọn vẫn rỗng, lại chẳng có lỗi nào nổi lên.
     */
    public const NHAN_SU_DANG_LAM = 'dang_lam';

    /**
     * Nhân viên phụ trách mua — ô "Nhân viên mua hàng" trong hộp thoại.
     *
     * Chỉ người ĐANG LÀM: người đã nghỉ không nên nằm trong ô chọn của một
     * chứng từ lập hôm nay. Phiếu cũ vẫn tra ra tên họ vì phiếu giữ id.
     */
    protected function danhMucNhanVien(): array
    {
        try {
            $res = $this->api->nhanSu(['status' => self::NHAN_SU_DANG_LAM]);
            if (! $res->successful()) {
                Log::error('Load nhan su cho phieu mua failed', ['status' => $res->status()]);

                return [];
            }

            return $res->json('data') ?? [];
        } catch (\Throwable $e) {
            Log::error('Load nhan su cho phieu mua failed', ['msg' => $e->getMessage()]);

            return [];
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
            Log::error('Phieu mua hang API call failed', ['msg' => $e->getMessage()]);

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
                Log::error('Bulk phieu mua hang failed', ['id' => $id, 'msg' => $e->getMessage()]);
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

    /**
     * Trả lời một lượt GHI, theo đúng lối mà bên gọi đang dùng.
     *
     * Hộp thoại lưu bằng AJAX (V2.luuHop) và chờ JSON: lưu được thì nó tự đóng hộp,
     * bắn toast rồi nạp lại danh sách TẠI CHỖ — đúng cách bản v2 làm
     * (`$('#modalCreate').modal('hide'); list(1);`). Lưu hỏng thì hộp Ở NGUYÊN cùng
     * mọi thứ vừa gõ, người dùng sửa đúng chỗ sai rồi bấm lại.
     *
     * Lối cũ (form gửi thật, tải lại cả trang) vẫn giữ: mấy đường ghi khác của màn
     * này — duyệt, huỷ, thanh toán, xoá — còn dùng nó.
     */
    protected function traLoiGhi(
        Request $request,
        string $cau,
        bool $ok = true,
        int $ma = 422,
        bool $canhBao = false
    ) {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => $ok,
                // canhBao = phiếu ĐÃ lưu nhưng lượt duyệt kèm theo hỏng. Vẫn là 200
                // để hộp thoại đóng lại — để nó mở là người dùng bấm Lưu lần nữa và
                // đẻ ra phiếu thứ hai. Nhưng câu báo phải đỏ, không phải xanh.
                'warning' => $canhBao,
                'message' => $cau,
            ], $ok ? 200 : $ma);
        }

        return $ok
            ? $this->veDanhSach($request)->with($canhBao ? 'error' : 'success', $cau)
            : back()->withInput()->with('error', $cau);
    }

    /** Về đúng URL cũ nếu form có gửi kèm. */
    protected function veDanhSach(Request $request)
    {
        $ve = trim((string) $request->input('return', ''));

        return $ve !== '' && str_starts_with($ve, '/')
            ? redirect($ve)
            : redirect()->route('admin.phieu-mua-hang.index');
    }
}
