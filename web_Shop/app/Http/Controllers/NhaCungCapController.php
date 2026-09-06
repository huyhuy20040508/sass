<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Nhà cung cấp — danh mục đầu mối mua vào, dựng theo màn cùng tên của order v2.
 *
 * Trang gọi /admin/nha-cung-cap; API không thấy thì hiện bảng rỗng kèm một dòng
 * báo. Không còn cột tiền: ba trang mua vào đã bị gỡ nên chẳng còn phiếu nào để
 * cộng tổng mua / đã trả / còn nợ.
 */
class NhaCungCapController extends Controller
{
    use \App\Http\Controllers\Concerns\TraLoiHopThoai;

    /** Nhãn ngắn cho thanh điều hướng. */
    public const TITLE = 'Nhà cung cấp';

    public const TITLE_PAGE = 'Danh sách nhà cung cấp';

    // Gọi đúng chữ trên nút ("Tạo"), không gọi tên khác — một hành động, một tên.
    public const EMPTY_TEXT = 'Chưa có nhà cung cấp nào. Bấm "Tạo" để khai đầu mối nhập hàng.';

    /** Cột `status` bên v2: 1 là còn nhập hàng, 0 là đã dừng. */
    public const DANG_HOP_TAC = 1;

    public const NGUNG_HOP_TAC = 0;

    public const TRANG_THAI = [
        self::DANG_HOP_TAC => 'Đang hợp tác',
        self::NGUNG_HOP_TAC => 'Ngừng hợp tác',
    ];

    public const SAP_XEP = [
        'moi_nhat' => 'Mới thêm nhất',
        'ten_az' => 'Tên A → Z',
        'ten_za' => 'Tên Z → A',
        'ma_az' => 'Mã tăng dần',
    ];

    public const SO_DONG_MOI_TRANG = 10;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    /** Chín cột của file nhập — đổi thứ tự là vỡ lượt nhập. */
    public const COT_NHAP = [
        'STT', 'Mã nhà cung cấp', 'Tên nhà cung cấp', 'Mã số thuế', 'Điện thoại',
        'Email', 'Địa chỉ', 'Địa chỉ 2', 'Trạng thái',
    ];

    public const IMPORT_MAX_ROWS = 2000;

    /** Các cột tắt/bật được ngoài bảng; lựa chọn lưu ở localStorage. */
    public const COT_BANG = [
        'code' => 'Mã NCC',
        'name' => 'Tên nhà cung cấp',
        'tax' => 'Mã số thuế',
        'phone' => 'Điện thoại',
        'email' => 'Email',
        'addr' => 'Địa chỉ',
        'addr2' => 'Địa chỉ 2',
        'status' => 'Trạng thái',
    ];

    public function __construct(protected ApiClient $api) {}

    // ---------------------------------------------------------------------
    // Danh sách
    // ---------------------------------------------------------------------

    /** API trả cả danh sách một lượt; lọc / sắp / cắt trang làm ngay tại đây. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $all = [];

        try {
            $res = $this->api->nhaCungCap(['keyword' => $filters['keyword']]);
            if ($res->successful()) {
                $all = $res->json('data') ?? [];
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách nhà cung cấp.';
            }
        } catch (\Throwable $e) {
            Log::error('Load nha cung cap failed', ['msg' => $e->getMessage()]);
            $error = 'Chưa nối được API nhà cung cấp — trang đang hiện bảng rỗng.';
        }

        $all = array_map([$this, 'veKieuXem'], $all);
        $loc = $this->locSapXep($all, $filters);
        $tong = count($loc);
        $soTrang = max(1, (int) ceil($tong / $filters['page_size']));
        $trang = min($filters['page'], $soTrang);

        // Màn đã chuyển sang khu v2; view cũ ở resources/views/nha-cung-cap giữ
        // lại phòng khi cần đối chiếu, không còn route nào trỏ vào.
        $view = view('v2::nha-cung-cap.index', [
            'list' => array_values(array_slice($loc, ($trang - 1) * $filters['page_size'], $filters['page_size'])),
            'filters' => array_merge($filters, ['page' => $trang]),
            'thongKe' => $this->thongKe($all),
            'meta' => [
                'page' => $trang,
                'page_size' => $filters['page_size'],
                'total' => $tong,
                'total_pages' => $soTrang,
            ],
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Xuất đúng phần đang lọc, 12 cột như bản v2. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);

        try {
            $res = $this->api->nhaCungCap(['keyword' => $filters['keyword']]);
            $all = $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Export nha cung cap failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API để xuất tệp.');
        }

        $list = $this->locSapXep(array_map([$this, 'veKieuXem'], $all), $filters);

        // Nút đề "Xuất Excel" thì tệp phải là .xlsx thật — trước đây trả .csv, Excel
        // mở được nhưng nhãn nói một đằng tệp ra một nẻo, và số điện thoại "0912…"
        // mở bằng CSV là Excel cắt mất số 0 đầu.
        $hang = [[
            'STT', 'Mã nhà cung cấp', 'Tên nhà cung cấp', 'Mã số thuế', 'Điện thoại',
            'Email', 'Địa chỉ', 'Địa chỉ 2', 'Trạng thái',
            'Tổng mua hàng', 'Tổng tiền thanh toán', 'Còn nợ',
        ]];

        foreach ($list as $i => $ncc) {
            $hang[] = [
                $i + 1,
                (string) ($ncc['code'] ?? ''),
                (string) ($ncc['name'] ?? ''),
                (string) ($ncc['tax_code'] ?? ''),
                (string) ($ncc['phone'] ?? ''),
                (string) ($ncc['email'] ?? ''),
                (string) ($ncc['address'] ?? ''),
                (string) ($ncc['address_line2'] ?? ''),
                self::TRANG_THAI[(int) ($ncc['status'] ?? 1)] ?? '',
                // Số trần, không dấu chấm ngăn nghìn: Excel còn cộng được.
                (float) ($ncc['total_purchases'] ?? 0),
                (float) ($ncc['total_payment'] ?? 0),
                (float) ($ncc['still_in_debt'] ?? 0),
            ];
        }

        return $this->taiXlsx($hang, 'nha-cung-cap-'.date('Ymd-His'), 'Nha cung cap');
    }

    /** File mẫu — giữ đúng thứ tự cột, vì lượt nhập có thể đọc theo vị trí. */
    public function mauNhap()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::COT_NHAP);
            fputcsv($out, [1, 'NCC001', 'Công ty TNHH An Bình', '0101234567', '0912345678',
                'kd@anbinh.vn', '12 Lê Lợi, Quận 1, TP.HCM', 'Kho Bình Tân', 1]);
            fputcsv($out, [2, '', 'Cơ sở Minh Phát', '', '0908765432',
                '', '55 Trần Hưng Đạo, Hà Nội', '', 0]);
            fclose($out);
        }, 'mau-nha-cung-cap.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Nhập CSV — soát cả file trước, một dòng sai là dừng cả lượt. */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'file.required' => 'Vui lòng chọn file CSV.',
            'file.mimes' => 'Chỉ chấp nhận file CSV. Tải file mẫu để đối chiếu.',
            'file.max' => 'File tối đa 5MB.',
        ]);

        $lines = file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return $this->veDanhSach($request)->with('error', 'File rỗng hoặc không đọc được.');
        }

        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        $viTri = $this->viTriCot(str_getcsv(array_shift($lines)));

        if ($viTri === null) {
            return $this->veDanhSach($request)->with(
                'error',
                'File thiếu cột bắt buộc: Tên nhà cung cấp và Địa chỉ. Vui lòng tải file mẫu để đối chiếu.'
            );
        }
        if (count($lines) > self::IMPORT_MAX_ROWS) {
            return $this->veDanhSach($request)->with(
                'error',
                'File có '.count($lines).' dòng, vượt mức '.self::IMPORT_MAX_ROWS.' dòng mỗi lượt nhập.'
            );
        }

        $rows = [];
        $loi = [];
        $daThayMa = [];

        foreach ($lines as $i => $line) {
            $dong = $i + 2; // +1 vì bỏ dòng tiêu đề, +1 vì người đọc đếm từ 1
            $o = str_getcsv($line);
            $lay = fn (string $khoa) => isset($viTri[$khoa]) ? trim((string) ($o[$viTri[$khoa]] ?? '')) : '';

            $row = [
                'code' => $lay('code'),
                'name' => $lay('name'),
                'tax_code' => $lay('tax_code'),
                'phone' => $lay('phone'),
                'email' => $lay('email'),
                'address' => $lay('address'),
                'address_line2' => $lay('address_line2'),
                'is_active' => $lay('status') !== '0',
            ];

            if ($row['name'] === '' && $row['address'] === '' && $row['code'] === '') {
                continue; // dòng trống giữa file
            }
            if ($row['name'] === '') {
                $loi[] = 'Dòng '.$dong.': chưa có tên nhà cung cấp.';
            }
            if ($row['address'] === '') {
                $loi[] = 'Dòng '.$dong.': chưa có địa chỉ.';
            }
            if ($row['code'] !== '' && ! preg_match('/^[A-Za-z0-9]+$/', $row['code'])) {
                $loi[] = 'Dòng '.$dong.': mã "'.$row['code'].'" chỉ được gồm chữ và số.';
            }
            // Bắt trùng mã tại đây để câu lỗi chỉ ra đúng hai dòng.
            if ($row['code'] !== '' && isset($daThayMa[$row['code']])) {
                $loi[] = 'Dòng '.$dong.': trùng mã "'.$row['code'].'" với dòng '.$daThayMa[$row['code']].'.';
            }
            if ($row['code'] !== '') {
                $daThayMa[$row['code']] = $dong;
            }
            if ($row['email'] !== '' && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $loi[] = 'Dòng '.$dong.': email "'.$row['email'].'" không đúng định dạng.';
            }

            $rows[] = $row;
        }

        if ($loi !== []) {
            return $this->veDanhSach($request)->with(
                'error',
                'Chưa nhập dòng nào. '.implode(' ', array_slice($loi, 0, 10))
                    .(count($loi) > 10 ? ' …và '.(count($loi) - 10).' lỗi nữa.' : '')
            );
        }
        if ($rows === []) {
            return $this->veDanhSach($request)->with('error', 'File không có dòng dữ liệu nào.');
        }

        $ok = 0;
        $hong = [];

        foreach ($rows as $i => $row) {
            try {
                $res = $this->api->taoNhaCungCap($row);
                if ($res->successful()) {
                    $ok++;
                } else {
                    $hong[] = 'Dòng '.($i + 2).': '.($res->json('message') ?: 'API từ chối.');
                }
            } catch (\Throwable $e) {
                Log::error('Import nha cung cap failed', ['dong' => $i + 2, 'msg' => $e->getMessage()]);
                $hong[] = 'Dòng '.($i + 2).': không gọi được API.';
            }
        }

        $ve = $this->veDanhSach($request);

        if ($hong === []) {
            return $ve->with('success', 'Đã nhập '.$ok.' nhà cung cấp từ file.');
        }

        $cau = ($ok > 0 ? 'Đã nhập '.$ok.' nhà cung cấp. ' : '')
            .implode(' ', array_slice($hong, 0, 5))
            .(count($hong) > 5 ? ' …và '.(count($hong) - 5).' dòng nữa.' : '');

        return $ve->with($ok > 0 ? 'success' : 'error', $cau);
    }

    // ---------------------------------------------------------------------
    // Ghi
    // ---------------------------------------------------------------------

    public function store(Request $request)
    {
        $data = $this->duLieu($request);

        return $this->send(
            fn () => $this->api->taoNhaCungCap($data),
            'Đã thêm nhà cung cấp "'.$data['name'].'".',
            $request
        );
    }

    public function update(Request $request, int $id)
    {
        $data = $this->duLieu($request);

        return $this->send(
            fn () => $this->api->suaNhaCungCap($id, $data),
            'Đã cập nhật nhà cung cấp "'.$data['name'].'".',
            $request
        );
    }

    /** Công tắc ngoài bảng — chỉ gửi đúng một trường. */
    public function updateStatus(Request $request, int $id)
    {
        $status = (int) $request->validate([
            'status' => 'required|integer|in:0,1',
        ])['status'];

        return $this->send(
            fn () => $this->api->trangThaiNhaCungCap($id, $status),
            $status === self::DANG_HOP_TAC
                ? 'Đã bật lại hợp tác.'
                : 'Đã ngừng hợp tác — bên này không còn hiện trong ô chọn khi lập phiếu.',
            $request
        );
    }

    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->xoaNhaCungCap($id),
            'Đã xoá nhà cung cấp.',
            $request
        );
    }

    /** Xoá nhiều — bên còn phiếu mua thì API từ chối, đếm riêng để báo lại. */
    public function bulkDestroy(Request $request)
    {
        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return $this->veDanhSach($request)->with('error', 'Chưa chọn nhà cung cấp nào để xoá.');
        }

        [$ok, $hong] = $this->chayHangLoat($ids, fn (int $id) => $this->api->xoaNhaCungCap($id));

        return $this->ketQuaHangLoat(
            $request, $ok, $hong,
            'Đã xoá %d nhà cung cấp.',
            '%d bên không xoá được (thường vì còn phiếu mua).'
        );
    }

    /** Lối thoát cho bên không xoá được: cho ngừng hợp tác hàng loạt. */
    public function bulkStatus(Request $request)
    {
        $status = (int) $request->validate([
            'status' => 'required|integer|in:0,1',
        ])['status'];

        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return $this->veDanhSach($request)->with('error', 'Chưa chọn nhà cung cấp nào.');
        }

        [$ok, $hong] = $this->chayHangLoat($ids, fn (int $id) => $this->api->trangThaiNhaCungCap($id, $status));

        return $this->ketQuaHangLoat(
            $request, $ok, $hong,
            'Đã chuyển %d nhà cung cấp sang "'.self::TRANG_THAI[$status].'".',
            '%d bên đổi không được.'
        );
    }

    /** Hai tab trong hộp Chi tiết đọc chung một đường, khác nhau ở `tab`. */
    public const TAB_PHIEU = ['lich-su', 'cong-no'];

    /** Nhãn trạng thái phiếu / thanh toán cho tệp xuất — cùng chữ với màn Phiếu mua hàng. */
    protected const NHAN_PHIEU = ['draft' => 'Lưu tạm', 'approved' => 'Đã duyệt', 'cancelled' => 'Đã huỷ'];

    protected const NHAN_TRA = ['unpaid' => 'Chưa trả', 'partial' => 'Trả một phần', 'paid' => 'Đã trả đủ'];

    /**
     * Phiếu mua của MỘT nhà cung cấp — tab "Lịch sử giao dịch" và "Công nợ".
     *
     * Đọc TOÀN CỬA HÀNG (`shop_id=0`), không cắt theo chi nhánh đang đứng. Sổ
     * của một nhà cung cấp là của cả công ty: ba con số tổng trên đầu tab đã
     * gộp mọi chi nhánh, mà bảng dưới chỉ bày phiếu của một quầy thì hai nửa
     * nói hai chuyện — đứng ở chi nhánh mới mở, tab trống trơn dù bên này còn
     * nợ mình cả trăm triệu. Thay vào đó mỗi dòng bày rõ cột "Chi nhánh", đúng
     * như bản v2.
     *
     * Tìm, lọc ngày và phân trang làm ở API, không kéo 100 phiếu về rồi lọc ở
     * trình duyệt như trước: nhà cung cấp lâu năm có cả nghìn phiếu, 100 phiếu
     * gần nhất là một lát cắt mà không ai biết mình đang nhìn lát cắt.
     */
    public function phieuMua(Request $request, int $id)
    {
        $loc = $this->locPhieu($request);

        try {
            $res = $this->api->phieuMuaHang($this->queryPhieu($id, $loc));
            if (! $res->successful()) {
                return response()->json(['message' => $res->json('message') ?: 'Không đọc được phiếu mua.'], 502);
            }
            $list = $this->kemTenChiNhanh($res->json('data') ?? []);
            $meta = (array) ($res->json('meta') ?? []);
        } catch (\Throwable $e) {
            Log::error('Load phieu mua cua NCC failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API.'], 502);
        }

        return response()->json([
            'data' => $list,
            'meta' => [
                'page' => (int) ($meta['page'] ?? $loc['page']),
                'page_size' => (int) ($meta['page_size'] ?? $loc['page_size']),
                'total' => (int) ($meta['total'] ?? count($list)),
                'total_pages' => max(1, (int) ($meta['total_pages'] ?? 1)),
            ],
            'filters' => $loc,
        ]);
    }

    /**
     * Xuất tab đang xem ra .xlsx theo đúng bộ lọc đang bật.
     *
     * API cắt trang ở 100 nên đọc từng trang tới hết (trần 2.000 phiếu — quá
     * mức đó là việc của báo cáo, không phải của một hộp chi tiết).
     */
    public function phieuMuaExport(Request $request, int $id)
    {
        $loc = $this->locPhieu($request);

        try {
            $list = $this->kemTenChiNhanh(
                $this->docHetTrang(fn (array $q) => $this->api->phieuMuaHang($q), $this->queryPhieu($id, $loc))
            );
        } catch (\Throwable $e) {
            Log::error('Export phieu mua cua NCC failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return back()->with('error', $e instanceof \RuntimeException ? $e->getMessage() : 'Không kết nối được API để xuất tệp.');
        }

        $congNo = $loc['tab'] === 'cong-no';
        $hang = [$congNo
            ? ['STT', 'Mã phiếu', 'Người lập', 'Chi nhánh', 'Ngày lập', 'Tổng tiền', 'Đã trả', 'Còn nợ', 'Thanh toán',
                'Hạn thanh toán', 'Còn hạn (ngày)', 'Ghi chú']
            : ['STT', 'Mã phiếu', 'Người lập', 'Chi nhánh', 'Ngày chứng từ', 'Ngày lập', 'Tiền hàng', 'Tổng tiền',
                'Đã trả', 'Còn nợ', 'Trạng thái', 'Thanh toán', 'Ghi chú']];

        foreach ($list as $i => $p) {
            $tong = (float) ($p['total_amount'] ?? 0);
            $daTra = (float) ($p['paid_amount'] ?? 0);
            // Còn nợ chỉ có nghĩa với phiếu đã duyệt — phiếu tạm/huỷ chưa mua gì.
            $con = ($p['status'] ?? '') === 'approved' ? max(0, $tong - $daTra) : 0;
            $chung = [$i + 1, (string) ($p['po_code'] ?? ''), (string) ($p['created_by_name'] ?? ''), (string) ($p['shop_name'] ?? '')];
            // Số trần, không dấu chấm ngăn nghìn: Excel còn cộng được.
            $hang[] = $congNo
                ? [...$chung, $this->ngayXuat($p['created_at'] ?? null), $tong, $daTra, $con,
                    self::NHAN_TRA[$p['payment_status'] ?? ''] ?? '',
                    $this->ngayXuat($p['debt_due_date'] ?? null), $this->conHan($p['debt_due_date'] ?? null),
                    (string) ($p['note'] ?? '')]
                : [...$chung, $this->ngayXuat($p['document_date'] ?? null), $this->ngayXuat($p['created_at'] ?? null),
                    (float) ($p['items_amount'] ?? 0), $tong, $daTra, $con,
                    self::NHAN_PHIEU[$p['status'] ?? ''] ?? '', self::NHAN_TRA[$p['payment_status'] ?? ''] ?? '',
                    (string) ($p['note'] ?? '')];
        }

        return $this->taiXlsx($hang, 'ncc-'.$id.'-'.($congNo ? 'cong-no' : 'lich-su-giao-dich').'-'.date('Ymd-His'),
            $congNo ? 'Cong no' : 'Lich su giao dich');
    }

    /** Bộ lọc của hai tab, đọc từ query. */
    protected function locPhieu(Request $request): array
    {
        $tab = (string) $request->query('tab', 'lich-su');
        $tab = in_array($tab, self::TAB_PHIEU, true) ? $tab : 'lich-su';
        $congNo = $tab === 'cong-no';
        $size = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return [
            'tab' => $tab,
            'keyword' => trim((string) $request->query('keyword', '')),
            // Lịch sử giao dịch mở ra là đã lọc THÁNG NÀY — quy tắc chung của mọi
            // sổ chứng từ (xem PhieuMuaHangController::filters). Gửi `from_date=`
            // rỗng là cố ý bỏ lọc, khác với không gửi gì. Tab Công nợ KHÔNG lọc
            // ngày: khoản nợ cũ mấy vẫn phải hiện, giấu đi là quên đòi.
            'from_date' => $congNo ? '' : ($request->has('from_date') ? $this->ngayLoc($request->query('from_date')) : date('Y-m-01')),
            'to_date' => $congNo ? '' : ($request->has('to_date') ? $this->ngayLoc($request->query('to_date')) : date('Y-m-d')),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($size, self::MUC_SO_DONG, true) ? $size : self::SO_DONG_MOI_TRANG,
        ];
    }

    /** Đổi bộ lọc tab thành query API. */
    protected function queryPhieu(int $id, array $loc): array
    {
        $q = [
            'supplier_id' => $id,
            // 0 = "mọi chi nhánh", phải khai tường minh — không gửi là API cắt theo
            // chi nhánh đang đứng (xem chiNhanhLoc bên API).
            'shop_id' => 0,
            'keyword' => $loc['keyword'],
            'from_date' => $loc['from_date'],
            'to_date' => $loc['to_date'],
            'sort' => 'newest',
            'page' => $loc['page'],
            'page_size' => $loc['page_size'],
        ];

        // Công nợ: CHỈ phiếu đã duyệt mà chưa trả đủ. Phiếu lưu tạm chưa mua gì,
        // phiếu huỷ thì không bao giờ mua — đưa vào là dựng ra một khoản nợ không có thật.
        if ($loc['tab'] === 'cong-no') {
            $q['status'] = 'approved';
            $q['payment_status'] = 'unpaid,partial';
        }

        return $q;
    }

    /** Gắn tên chi nhánh vào từng phiếu — API chỉ trả `shop_id`. */
    protected function kemTenChiNhanh(array $list): array
    {
        if ($list === []) {
            return $list;
        }

        $ten = [];
        try {
            $res = $this->api->chiNhanh();
            foreach ($res->successful() ? ($res->json('data') ?? []) : [] as $cn) {
                $ten[(int) ($cn['id'] ?? 0)] = (string) ($cn['name'] ?? '');
            }
        } catch (\Throwable $e) {
            Log::warning('Load ten chi nhanh cho tab NCC failed', ['msg' => $e->getMessage()]);
        }

        return array_map(function (array $p) use ($ten) {
            $p['shop_name'] = $ten[(int) ($p['shop_id'] ?? 0)] ?? '';

            return $p;
        }, $list);
    }

    /** Ô lịch của v2 gửi DD-MM-YYYY; API nhận YYYY-MM-DD. Sai khuôn là bỏ lọc. */
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

    protected function ngayXuat(?string $v): string
    {
        return $v ? date('d/m/Y', strtotime($v)) : '';
    }

    /** Số ngày tới hạn trả, âm là đã quá hạn; không ghi hạn thì để trống (Excel còn lọc/sắp được). */
    protected function conHan(?string $han): int|string
    {
        if (! $han) {
            return '';
        }

        return (int) floor((strtotime(substr($han, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
    }

    /** Ảnh tải lên ngay lúc chọn, form chỉ mang theo đường dẫn. */
    public function uploadAnh(Request $request)
    {
        $request->validate(['anh' => ImageStore::rules()], ImageStore::messages());

        return response()->json(['url' => ImageStore::put($request->file('anh'), 'nha-cung-cap')]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Bộ lọc đọc từ query, đã kẹp về giá trị hợp lệ. */
    protected function filters(Request $request): array
    {
        $sort = (string) $request->query('sort', 'moi_nhat');
        $status = (string) $request->query('status', '');
        $size = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => in_array($status, ['0', '1'], true) ? $status : '',
            'sort' => isset(self::SAP_XEP[$sort]) ? $sort : 'moi_nhat',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($size, self::MUC_SO_DONG, true) ? $size : self::SO_DONG_MOI_TRANG,
        ];
    }

    /** Lọc + sắp xếp tại chỗ. Từ khoá quét mã, tên, tên viết tắt, SĐT, MST. */
    protected function locSapXep(array $all, array $f): array
    {
        $list = array_values(array_filter($all, function ($ncc) use ($f) {
            if ($f['keyword'] !== '') {
                $dong = mb_strtolower(implode(' ', [
                    $ncc['code'] ?? '', $ncc['name'] ?? '', $ncc['short_name'] ?? '',
                    $ncc['phone'] ?? '', $ncc['tax_code'] ?? '',
                ]));
                if (! str_contains($dong, mb_strtolower($f['keyword']))) {
                    return false;
                }
            }

            return $f['status'] === '' || (int) ($ncc['status'] ?? 1) === (int) $f['status'];
        }));

        usort($list, match ($f['sort']) {
            'ten_az' => fn ($a, $b) => strcmp(mb_strtolower($a['name'] ?? ''), mb_strtolower($b['name'] ?? '')),
            'ten_za' => fn ($a, $b) => strcmp(mb_strtolower($b['name'] ?? ''), mb_strtolower($a['name'] ?? '')),
            'ma_az' => fn ($a, $b) => strcmp($a['code'] ?? '', $b['code'] ?? ''),
            default => fn ($a, $b) => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)),
        });

        return $list;
    }

    /** Một dòng tổng trên đầu trang: đếm trên TOÀN danh mục, không theo bộ lọc. */
    protected function thongKe(array $all): array
    {
        $dangHopTac = 0;

        foreach ($all as $ncc) {
            if ((int) ($ncc['status'] ?? 1) === self::DANG_HOP_TAC) {
                $dangHopTac++;
            }
        }

        return [
            'tong' => count($all),
            'dang_hop_tac' => $dangHopTac,
        ];
    }

    /**
     * Kiểm tra theo đúng validator của v2: chỉ Tên và Địa chỉ là bắt buộc.
     * MST và SĐT bên đó đã bỏ luật (comment-out) nên ở đây cũng không ép.
     */
    protected function duLieu(Request $request): array
    {
        $data = $request->validate([
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
            'status' => ['required', 'integer', 'in:0,1'],
        ], [
            'name.required' => 'Chưa nhập tên nhà cung cấp.',
            'address.required' => 'Chưa nhập địa chỉ.',
            'code.regex' => 'Mã nhà cung cấp chỉ gồm chữ và số, không dấu cách.',
            'representative_phone.regex' => 'Số điện thoại người đại diện phải bắt đầu bằng 0 và có 10-11 chữ số.',
            'email.email' => 'Email không đúng định dạng.',
        ]);

        // API nói `is_active` như mọi danh mục khác; form giữ tên `status` của v2.
        $data['is_active'] = (int) $data['status'] === self::DANG_HOP_TAC;
        unset($data['status']);

        return $data;
    }

    /**
     * Dò vị trí cột theo tiêu đề (có dấu hoặc không); tiêu đề lạ mà đủ 9 cột thì
     * đọc theo vị trí.
     *
     * @return array<string,int>|null null khi thiếu cột bắt buộc
     */
    protected function viTriCot(array $header): ?array
    {
        $chuan = fn (string $s) => preg_replace('/[^a-z0-9]/', '', $this->khongDau(mb_strtolower(trim($s))));

        $ten = [
            'code' => ['manhacungcap', 'mancc', 'ma', 'code'],
            'name' => ['tennhacungcap', 'tenncc', 'ten', 'name'],
            'tax_code' => ['masothue', 'mst', 'taxcode'],
            'phone' => ['dienthoai', 'sodienthoai', 'sdt', 'phone'],
            'email' => ['email'],
            'address' => ['diachi', 'diachi1', 'address'],
            'address_line2' => ['diachi2', 'addressline2'],
            'status' => ['trangthai', 'status'],
        ];

        $viTri = [];
        foreach ($header as $i => $o) {
            $khoa = $chuan((string) $o);
            foreach ($ten as $truong => $bidanh) {
                if (! isset($viTri[$truong]) && in_array($khoa, $bidanh, true)) {
                    $viTri[$truong] = $i;
                }
            }
        }

        // Tiêu đề lạ nhưng đủ 9 cột: đọc theo vị trí của file mẫu.
        if (! isset($viTri['name'], $viTri['address']) && count($header) >= count(self::COT_NHAP)) {
            return ['code' => 1, 'name' => 2, 'tax_code' => 3, 'phone' => 4,
                'email' => 5, 'address' => 6, 'address_line2' => 7, 'status' => 8];
        }

        return isset($viTri['name'], $viTri['address']) ? $viTri : null;
    }

    /** Bỏ dấu tiếng Việt để so tên cột. */
    protected function khongDau(string $s): string
    {
        $bang = [
            'a' => 'áàảãạăắằẳẵặâấầẩẫậ', 'e' => 'éèẻẽẹêếềểễệ', 'i' => 'íìỉĩị',
            'o' => 'óòỏõọôốồổỗộơớờởỡợ', 'u' => 'úùủũụưứừửữự', 'y' => 'ýỳỷỹỵ', 'd' => 'đ',
        ];

        foreach ($bang as $thay => $nguon) {
            $s = preg_replace('/['.$nguon.']/u', $thay, $s);
        }

        return $s;
    }

    /**
     * Đổi tên trường của API sang tên màn hình đang dùng (giữ nguyên tên của v2).
     */
    protected function veKieuXem(array $ncc): array
    {
        $ncc['status'] = ($ncc['is_active'] ?? true) ? self::DANG_HOP_TAC : self::NGUNG_HOP_TAC;

        return $ncc;
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
                Log::error('Bulk nha cung cap failed', ['id' => $id, 'msg' => $e->getMessage()]);
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

    /** Gọi API rồi quay lại danh sách, in nguyên văn lời API khi hỏng. */
    protected function send(callable $goi, string $success, Request $request)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Nha cung cap API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $success, fn () => $this->veDanhSach($request))
            : $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác không thành công.'));
    }

    /** Về đúng URL cũ nếu form có gửi kèm. */
    protected function veDanhSach(Request $request)
    {
        $ve = trim((string) $request->input('return', ''));

        return $ve !== '' && str_starts_with($ve, '/')
            ? redirect($ve)
            : redirect()->route('admin.nha-cung-cap.index');
    }
}
