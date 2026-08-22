<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Nhà cung cấp — danh mục đầu mối mua vào, dựng lại theo màn "Quản lý nhà cung
 * cấp" của bản order v2 (khu Kho).
 *
 * MỚI CÓ GIAO DIỆN. Bảng dữ liệu và các đường API đã bị gỡ ở migration 0038, nên
 * trang gọi /admin/nha-cung-cap không thấy thì hiện bảng rỗng kèm một dòng báo.
 * Tên ô của form đặt đúng tên trường bên v2 (`3rd_suppliers`) để lúc dựng lại
 * backend không phải dịch tên ở giữa.
 *
 * Ba cột tiền — Tổng mua / Đã trả / Còn nợ — do API tổng hợp sẵn từ phiếu mua và
 * sổ nợ; trang chỉ hiển thị, không tự cộng.
 */
class NhaCungCapController extends Controller
{
    /** Nhãn ngắn cho thanh điều hướng; tiêu đề trang là TITLE_PAGE. */
    public const TITLE = 'Nhà cung cấp';

    public const TITLE_PAGE = 'Danh sách nhà cung cấp';

    public const EMPTY_TEXT = 'Chưa có nhà cung cấp nào. Bấm "Thêm nhà cung cấp" để khai đầu mối nhập hàng.';

    /** Cột `status` bên v2: 1 là còn nhập hàng, 0 là đã dừng. */
    public const DANG_HOP_TAC = 1;

    public const NGUNG_HOP_TAC = 0;

    public const TRANG_THAI = [
        self::DANG_HOP_TAC => 'Đang hợp tác',
        self::NGUNG_HOP_TAC => 'Ngừng hợp tác',
    ];

    /** Lọc phụ hay dùng nhất trên trang này: đi soát tiền còn nợ. */
    public const CONG_NO = [
        'con_no' => 'Còn nợ',
        'het_no' => 'Không nợ',
    ];

    public const SAP_XEP = [
        'moi_nhat' => 'Mới thêm nhất',
        'ten_az' => 'Tên A → Z',
        'ten_za' => 'Tên Z → A',
        'ma_az' => 'Mã tăng dần',
        'no_nhieu' => 'Còn nợ nhiều nhất',
        'mua_nhieu' => 'Mua nhiều nhất',
    ];

    public const SO_DONG_MOI_TRANG = 20;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    /** Chín cột của file nhập, đúng thứ tự bản v2 — đổi thứ tự là vỡ lượt nhập. */
    public const COT_NHAP = [
        'STT', 'Mã nhà cung cấp', 'Tên nhà cung cấp', 'Mã số thuế', 'Điện thoại',
        'Email', 'Địa chỉ', 'Địa chỉ 2', 'Trạng thái',
    ];

    public const IMPORT_MAX_ROWS = 2000;

    /**
     * Các cột bày được ngoài bảng, đúng bộ của v2. Người dùng tự tắt bớt và lựa
     * chọn nằm ở localStorage của trình duyệt (bản v2 lưu vào DB theo user).
     */
    public const COT_BANG = [
        'code' => 'Mã NCC',
        'name' => 'Tên nhà cung cấp',
        'tax' => 'Mã số thuế',
        'phone' => 'Điện thoại',
        'email' => 'Email',
        'addr' => 'Địa chỉ',
        'addr2' => 'Địa chỉ 2',
        'mua' => 'Tổng mua',
        'tra' => 'Đã trả',
        'no' => 'Còn nợ',
        'status' => 'Trạng thái',
    ];

    public function __construct(protected ApiClient $api) {}

    // ---------------------------------------------------------------------
    // Danh sách
    // ---------------------------------------------------------------------

    /**
     * Danh mục nhà cung cấp của một cửa hàng đếm bằng hàng chục, nên API trả cả
     * danh sách một lượt còn lọc / sắp / cắt trang làm ngay tại đây.
     */
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

        $loc = $this->locSapXep($all, $filters);
        $tong = count($loc);
        $soTrang = max(1, (int) ceil($tong / $filters['page_size']));
        $trang = min($filters['page'], $soTrang);

        $view = view('nha-cung-cap.index', [
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

    /** Xuất đúng phần đang lọc, 12 cột như file Excel của bản v2. */
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

        $list = $this->locSapXep($all, $filters);
        $ten = 'nha-cung-cap-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($list) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'STT', 'Mã nhà cung cấp', 'Tên nhà cung cấp', 'Mã số thuế', 'Điện thoại',
                'Email', 'Địa chỉ', 'Địa chỉ 2', 'Tổng mua', 'Đã trả', 'Còn nợ', 'Trạng thái',
            ]);

            foreach ($list as $i => $ncc) {
                fputcsv($out, [
                    $i + 1,
                    $ncc['code'] ?? '',
                    $ncc['name'] ?? '',
                    $ncc['tax_code'] ?? '',
                    $ncc['phone'] ?? '',
                    $ncc['email'] ?? '',
                    $ncc['address'] ?? '',
                    $ncc['address_line2'] ?? '',
                    (float) ($ncc['total_purchases'] ?? 0),
                    (float) ($ncc['paid'] ?? 0),
                    (float) ($ncc['debt'] ?? 0),
                    self::TRANG_THAI[(int) ($ncc['status'] ?? 1)] ?? '',
                ]);
            }
            fclose($out);
        }, $ten, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * File mẫu để nhập danh sách — ĐÚNG 9 cột theo thứ tự bản v2, vì lượt nhập
     * đọc theo vị trí cột khi tiêu đề không khớp tên nào đã biết.
     */
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

    /**
     * Nhập danh sách từ file CSV. Soát TOÀN BỘ file trước rồi mới ghi: một dòng
     * sai là dừng cả lượt và báo theo số dòng (đúng cách bản v2 làm), vì nhập
     * nửa vời để lại một danh mục không ai biết đã vào tới đâu.
     */
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
                'status' => $lay('status') === '0' ? self::NGUNG_HOP_TAC : self::DANG_HOP_TAC,
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
            // Trùng mã ngay trong file thì API chỉ báo ở dòng thứ hai, không nói
            // được nó đụng dòng nào — bắt tại đây để câu lỗi chỉ đúng hai chỗ.
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

    /** Xoá nhiều — bên nào còn phiếu mua thì API từ chối, đếm riêng để báo lại. */
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

    /** Lối thoát cho bên còn phiếu nên không xoá được: cho ngừng hợp tác hàng loạt. */
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

    /** Ảnh tải lên ngay lúc chọn, form chỉ mang theo đường dẫn trả về. */
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
        $debt = (string) $request->query('debt', '');
        $size = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => in_array($status, ['0', '1'], true) ? $status : '',
            'debt' => isset(self::CONG_NO[$debt]) ? $debt : '',
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

            if ($f['status'] !== '' && (int) ($ncc['status'] ?? 1) !== (int) $f['status']) {
                return false;
            }

            $no = (float) ($ncc['debt'] ?? 0);

            return match ($f['debt']) {
                'con_no' => $no > 0,
                'het_no' => $no <= 0,
                default => true,
            };
        }));

        $so = fn ($ncc, $khoa) => (float) ($ncc[$khoa] ?? 0);
        usort($list, match ($f['sort']) {
            'ten_az' => fn ($a, $b) => strcmp(mb_strtolower($a['name'] ?? ''), mb_strtolower($b['name'] ?? '')),
            'ten_za' => fn ($a, $b) => strcmp(mb_strtolower($b['name'] ?? ''), mb_strtolower($a['name'] ?? '')),
            'ma_az' => fn ($a, $b) => strcmp($a['code'] ?? '', $b['code'] ?? ''),
            'no_nhieu' => fn ($a, $b) => $so($b, 'debt') <=> $so($a, 'debt'),
            'mua_nhieu' => fn ($a, $b) => $so($b, 'total_purchases') <=> $so($a, 'total_purchases'),
            default => fn ($a, $b) => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)),
        });

        return $list;
    }

    /** Một dòng tổng trên đầu trang: đếm trên TOÀN danh mục, không theo bộ lọc. */
    protected function thongKe(array $all): array
    {
        $dangHopTac = 0;
        $tongMua = 0.0;
        $conNo = 0.0;

        foreach ($all as $ncc) {
            if ((int) ($ncc['status'] ?? 1) === self::DANG_HOP_TAC) {
                $dangHopTac++;
            }
            $tongMua += (float) ($ncc['total_purchases'] ?? 0);
            $conNo += (float) ($ncc['debt'] ?? 0);
        }

        return [
            'tong' => count($all),
            'dang_hop_tac' => $dangHopTac,
            'tong_mua' => $tongMua,
            'con_no' => $conNo,
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

        $data['status'] = (int) $data['status'];

        return $data;
    }

    /**
     * Dò vị trí từng cột trong dòng tiêu đề. Nhận cả tên tiếng Việt của file mẫu
     * lẫn tên không dấu; tiêu đề lạ mà đủ 9 cột thì đọc theo VỊ TRÍ như v2.
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

        // Tiêu đề không khớp tên nào nhưng đủ 9 cột: đọc theo vị trí của file mẫu.
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

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return $this->veDanhSach($request)->with('success', $success);
        }

        return back()->withInput()->with('error', $res->json('message') ?: 'Thao tác không thành công.');
    }

    /** Về đúng URL cũ (giữ bộ lọc, số trang) nếu form có gửi kèm. */
    protected function veDanhSach(Request $request)
    {
        $ve = trim((string) $request->input('return', ''));

        return $ve !== '' && str_starts_with($ve, '/')
            ? redirect($ve)
            : redirect()->route('admin.nha-cung-cap.index');
    }
}
