<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CuaVao;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Nhân sự — HỒ SƠ NHÂN VIÊN của cửa hàng (bảng `employees` bên API).
 *
 * Đây là chỗ DUY NHẤT quản lý người của cửa hàng: trang "Người dùng & vai trò"
 * đã bỏ khỏi menu vì hai trang cùng nói về một con người thì phải khai người đó
 * hai lần và không chỗ nào biết chỗ kia. Tài khoản đăng nhập nay là một khối
 * bên trong hồ sơ — bật lên thì form gửi kèm `tai_khoan` và API dựng tài khoản
 * qua đúng luật của module tài khoản cũ.
 *
 * Tên ô của form = tên trường của API, cố ý: một lớp dịch tên ở giữa là thêm
 * một chỗ để hai bên lệch nhau.
 *
 * Nhóm route `admin.manage` (thu ngân không vào): hồ sơ có lương và căn cước.
 */
class NhanSuController extends Controller
{
    use \App\Http\Controllers\Concerns\TraLoiHopThoai;

    /** Nhãn NGẮN cho thanh điều hướng. Tiêu đề trang là TITLE_PAGE bên dưới. */
    public const TITLE = 'Nhân sự';

    /** Tiêu đề trang — khuôn "Danh sách <đối tượng>" như trang Sản phẩm. */
    public const TITLE_PAGE = 'Danh sách nhân sự';

    public const EMPTY_TEXT = 'Chưa có hồ sơ nhân viên nào. Bấm "Thêm nhân viên" để khai người đầu tiên.';

    /**
     * Ca trực — ô này thay chỗ "Chức danh", đúng ô mà bảng nhân sự order v2 có.
     *
     * Với một cửa hàng bán lẻ thì chức danh gần như không nói thêm điều gì: người
     * của tiệm hoặc đứng quầy hoặc quản lý, và điều đó đã nằm ở ô Phân quyền. Còn
     * ca làm thì ngày nào cũng phải tra — xếp lịch, đối chiếu ca bán, tính công.
     *
     * CHỌN NHIỀU như bên đó: một người trực được cả sáng lẫn chiều. Dưới database
     * là cột SET (migration 0014) chứ không phải chuỗi CSV trong VARCHAR.
     */
    public const CA_LAM = [
        'sang' => 'Ca sáng',
        'chieu' => 'Ca chiều',
        'ca_ngay' => 'Cả ngày',
    ];

    // Chức danh và loại hợp đồng đã bỏ khỏi màn hình: thuộc module HRM dựng sau,
    // cùng chấm công và bảng lương. Hai cột `employees.position` /
    // `employees.contract_type` vẫn còn dưới database, giữ nguyên dữ liệu cũ.

    /** "Tạm nghỉ" khác "Đã nghỉ việc": người nghỉ dài ngày vẫn thuộc cửa hàng. */
    public const TRANG_THAI = [
        'dang_lam' => 'Đang làm',
        'tam_nghi' => 'Tạm nghỉ',
        'da_nghi' => 'Đã nghỉ việc',
    ];

    /**
     * Hai đầu của công tắc trạng thái. "Tạm nghỉ" ở danh sách trên không đặt được
     * từ màn hình này — nó là chuyện của bảng chấm công.
     */
    public const DANG_LAM = 'dang_lam';

    /** Số dòng mỗi trang mặc định và các mức cho ô chọn. */
    public const SO_DONG_MOI_TRANG = 20;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    public const DA_NGHI = 'da_nghi';

    public const GIOI_TINH = [
        'nam' => 'Nam',
        'nu' => 'Nữ',
        'khac' => 'Khác',
    ];

    public const HINH_THUC_LUONG = [
        'thang' => 'Theo tháng',
        'ca' => 'Theo ca',
        'gio' => 'Theo giờ',
    ];

    /** `users.role_id` bên API — hai vai đặt được từ màn hình nhân sự. */
    public const VAI_QUAN_LY = 2;

    public const VAI_THU_NGAN = 3;

    /** Nhãn của `role_id` bên API. Super admin (1) và khách hàng (4) không cấp từ đây. */
    public const QUYEN = [
        self::VAI_QUAN_LY => 'Quản lý',
        self::VAI_THU_NGAN => 'Thu ngân',
    ];

    /** Mã cửa vào bên API (`users.access_areas`) ứng với từng ô tick. */
    public const CUA = [
        self::VAI_QUAN_LY => 'quan_ly',
        self::VAI_THU_NGAN => 'thu_ngan',
    ];

    /** Nhãn của từng cửa — cho huy hiệu ngoài bảng và ô tick trong hộp thoại. */
    public const NHAN_CUA = [
        'quan_ly' => 'Quản lý',
        'thu_ngan' => 'Thu ngân',
    ];

    /**
     * Ô tick "Phân quyền" -> danh sách CỬA gửi lên API.
     *
     * Đây mới là thứ quyết định người này mở được khu nào, và nó lưu nguyên vẹn
     * hai ô đã tick (cột SET `users.access_areas`, migration 0015). Tích gì vào
     * được nấy: chỉ tick Quản lý thì /thu-ngan chặn lại, dù `role_id` vẫn là admin.
     */
    protected function cuaVao(array $tick): array
    {
        $cua = [];
        foreach (array_map('intval', $tick) as $id) {
            if (isset(self::CUA[$id])) {
                $cua[] = self::CUA[$id];
            }
        }

        return $cua;
    }

    /**
     * Ô tick -> `role_id`.
     *
     * `role_id` KHÔNG còn quyết định cửa nào (đó là việc của cuaVao), nhưng vẫn
     * phải gửi: nó là khoá ngoại tới `roles`, nằm trong token đăng nhập, và là
     * thứ phân biệt NGƯỜI CỦA TIỆM với KHÁCH HÀNG ở khắp nơi. Có tick Quản lý
     * thì là vai admin, còn lại là staff.
     *
     * Không tick ô nào -> 0 = "không nói gì về quyền", API bỏ qua.
     */
    protected function vaiTro(array $tick): int
    {
        $tick = array_map('intval', $tick);

        if (in_array(self::VAI_QUAN_LY, $tick, true)) {
            return self::VAI_QUAN_LY;
        }

        return in_array(self::VAI_THU_NGAN, $tick, true) ? self::VAI_THU_NGAN : 0;
    }

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách hồ sơ nhân viên + form thêm/sửa. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $list = [];

        try {
            $res = $this->api->nhanSu($this->queryApi($filters));
            if ($res->successful()) {
                $list = $this->locTaiCho($res->json('data') ?? [], $filters);
            } else {
                Log::warning('Load nhan su failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách nhân sự.';
            }
        } catch (\Throwable $e) {
            Log::error('Load nhan su failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách nhân sự. Kiểm tra kết nối API.';
        }

        // API trả cả danh sách (cố ý không phân trang), nên cắt trang tại đây.
        // Con số ở header đếm trên TOÀN danh sách, không phải trang đang xem.
        $tong = count($list);
        $dangLam = collect($list)->where('status', self::DANG_LAM)->count();
        $soDong = $this->soDongMoiTrang($request);
        $soTrang = max(1, (int) ceil($tong / $soDong));
        $trang = min(max(1, (int) $request->query('page', 1)), $soTrang);

        $view = view('v2::nhan-su.index', [
            'list' => array_slice($list, ($trang - 1) * $soDong, $soDong),
            'tong' => $tong,
            'dangLam' => $dangLam,
            'stt' => ($trang - 1) * $soDong,
            'meta' => [
                'page' => $trang,
                'total_pages' => $soTrang,
                'total' => $tong,
                'page_size' => $soDong,
            ],
            'filters' => $filters,
            'chiNhanh' => $this->chiNhanh(),
            'quyen' => self::QUYEN,
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Thêm hồ sơ nhân viên. */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->taoNhanSu($data),
            'Đã thêm hồ sơ "'.$data['full_name'].'".',
            fn (?array $hoSo) => $this->cauDoiChiNhanh($hoSo)
        );
    }

    /** Sửa hồ sơ nhân viên. */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);
        // Ảnh vừa bị thay hay bị gỡ: tệp cũ thành mồ côi. Dọn SAU khi API nhận
        // (trong $this->send) chứ không phải bây giờ — lưu hỏng mà đã xoá ảnh thì
        // hồ sơ giữ nguyên đường dẫn cũ trỏ vào một tệp không còn.
        $anhCu = trim((string) $request->input('avatar_cu', ''));
        $anhMoi = $data['avatar'] ?? '';

        return $this->send(
            fn () => $this->api->suaNhanSu($id, $data),
            'Đã cập nhật hồ sơ "'.$data['full_name'].'".',
            function (?array $hoSo) use ($anhCu, $anhMoi) {
                if ($anhCu !== '' && $anhCu !== $anhMoi) {
                    ImageStore::xoa($anhCu);
                }

                return $this->cauDoiChiNhanh($hoSo);
            }
        );
    }

    /**
     * Công tắc trạng thái trên bảng — gửi lên đúng một chữ, kèm câu trả lời cho
     * lượt hỏi "mở lại tài khoản?" nếu là chiều nhận người cũ làm lại.
     */
    public function updateStatus(Request $request, int $id)
    {
        $du = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(self::TRANG_THAI))],
            'mo_tai_khoan' => ['nullable', 'boolean'],
        ], [
            'status.required' => 'Thiếu trạng thái cần đặt.',
            'status.in' => 'Trạng thái làm việc không hợp lệ.',
        ]);

        $moTaiKhoan = $request->boolean('mo_tai_khoan');

        return $this->send(
            fn () => $this->api->doiTrangThaiNhanSu($id, $du['status'], $moTaiKhoan),
            $this->cauBaoTrangThai($du['status'], $moTaiKhoan)
        );
    }

    /**
     * Đánh dấu nghỉ việc / cho đi làm lại HÀNG LOẠT.
     *
     * Đây là lượt bulk đáng có nhất của màn hình này: cuối mùa vụ, cả nhóm thời vụ
     * nghỉ cùng ngày. Gạt từng công tắc là mười mấy lượt bấm kèm mười mấy lượt hỏi
     * lại — và ai cũng sẽ bấm Đồng ý mà không đọc từ lượt thứ ba.
     *
     * Đặt `da_nghi` khoá luôn tài khoản của từng người, y như công tắc trên bảng:
     * hai đường vào cùng một việc thì không được cho ra hai kết quả khác nhau.
     */
    public function bulkTrangThai(Request $request)
    {
        $du = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'min:1'],
            'status' => ['required', 'in:'.implode(',', array_keys(self::TRANG_THAI))],
            'mo_tai_khoan' => ['nullable', 'boolean'],
        ], [
            'ids.required' => 'Chưa chọn nhân viên nào.',
            'status.in' => 'Trạng thái làm việc không hợp lệ.',
        ]);

        $moTaiKhoan = $request->boolean('mo_tai_khoan');
        [$ok, $loi] = $this->hangLoat($du['ids'], fn (int $id) => $this->api->doiTrangThaiNhanSu($id, $du['status'], $moTaiKhoan));

        return $this->ketQuaHangLoat($ok, $loi, 'Đã đặt "'.self::TRANG_THAI[$du['status']].'" cho');
    }

    /**
     * Xoá HÀNG LOẠT.
     *
     * Mỗi hồ sơ đi qua đúng những chốt của lượt xoá lẻ (còn ca chưa đóng, đã ghi
     * sổ quỹ, tài khoản của chính mình). Nên lượt này hay hỏng MỘT PHẦN — và đó
     * là lý do nó báo LÝ DO chứ không chỉ đếm: "3 hồ sơ không xoá được" thì người
     * dùng chọn lại y hệt rồi bấm lần nữa, và lại 3 hồ sơ không xoá được.
     */
    public function bulkDestroy(Request $request)
    {
        $du = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'min:1'],
        ], ['ids.required' => 'Chưa chọn nhân viên nào.']);

        [$ok, $loi] = $this->hangLoat($du['ids'], fn (int $id) => $this->api->xoaNhanSu($id));

        return $this->ketQuaHangLoat($ok, $loi, 'Đã xoá');
    }

    /**
     * Chạy một việc trên từng id, gom kết quả.
     *
     * Trả [số thành công, lý do => số lần]. Gom theo LÝ DO chứ không theo id: mấy
     * câu từ chối của API đã nói rõ phải làm gì ("đóng ca đó trước đã"), mà in ra
     * mười lần cùng một câu thì người đọc bỏ qua cả mười.
     */
    protected function hangLoat(array $ids, callable $goi): array
    {
        $ids = collect($ids)->map(fn ($v) => (int) $v)->filter()->unique()->all();

        $ok = 0;
        $loi = [];

        foreach ($ids as $id) {
            try {
                $res = $goi($id);
                if ($res->successful()) {
                    $ok++;

                    continue;
                }
                $cau = (string) ($res->json('message') ?: 'Không thực hiện được');
            } catch (\Throwable $e) {
                Log::warning('Bulk nhan su failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $cau = 'Không kết nối được API';
            }
            $loi[$cau] = ($loi[$cau] ?? 0) + 1;
        }

        return [$ok, $loi];
    }

    /** Một câu báo cho cả lượt: bao nhiêu xong, phần còn lại vì sao không. */
    protected function ketQuaHangLoat(int $ok, array $loi, string $viec)
    {
        $ve = redirect()->route('admin.nhan-su.index');

        if ($loi === []) {
            return request()->expectsJson()
                ? response()->json(['success' => true, 'message' => $viec.' '.$ok.' hồ sơ.'])
                : $ve->with('success', $viec.' '.$ok.' hồ sơ.');
        }

        $chiTiet = collect($loi)
            ->map(fn (int $so, string $cau) => $so.' hồ sơ: '.$cau)
            ->values()->implode(' · ');

        // MỘT câu cho cả lượt, và luôn nói cả hai vế khi có phần đã xong: người
        // dùng cần biết danh sách đã đổi rồi, đừng chọn lại từ đầu mà bấm lần nữa.
        //
        // Báo bằng 'error' kể cả khi có phần thành công — lượt bulk hỏng một phần
        // mà hiện lên nền xanh thì không ai đọc tới vế thứ hai.
        $cau = $ok > 0
            ? $viec.' '.$ok.' hồ sơ. Phần còn lại chưa được — '.$chiTiet
            : 'Không hồ sơ nào được xử lý — '.$chiTiet;

        return request()->expectsJson()
            ? response()->json(['success' => false, 'message' => $cau], 422)
            : $ve->with('error', $cau);
    }

    /**
     * Xuất danh sách nhân sự ra tệp mở được bằng Excel.
     *
     * .xlsx THẬT, không phải CSV đội tên nút "Xuất Excel".
     *
     * Lý do cũ ("dựng .xlsx phải kéo thêm một thư viện") không còn: App\Support\XlsxDon
     * dựng bằng ZipArchive sẵn có của PHP, và taiXlsx() ở lớp cha nay là đường chung
     * của Nhà cung cấp, Phiếu mua hàng, Điều chỉnh tồn kho, Trả hàng NCC.
     *
     * Ở màn NÀY, CSV còn hỏng đúng hai cột không được phép hỏng: Excel đọc
     * "0912345678" và số CCCD thành SỐ rồi cắt mất số 0 đầu — bảng nhân sự gửi đi
     * mất sạch đầu số điện thoại, mà người nhận không có cách nào biết. XlsxDon ghi
     * chuỗi ở dạng inlineStr nên giữ nguyên từng ký tự.
     *
     * Mang theo ĐÚNG bộ lọc đang xem: người ta lọc ra một nhóm rồi mới bấm xuất.
     *
     * KHÔNG có cột lương. Tệp này rời khỏi phần mềm — gửi qua Zalo, để trên máy
     * dùng chung — nên mức lương của cả cửa hàng nằm trong đó là chuyện khác hẳn
     * với việc nó nằm sau một lượt đăng nhập. Ai cần bảng lương thì mở từng hồ sơ.
     */
    public function export(Request $request)
    {
        $filters = $this->filters($request);

        try {
            $res = $this->api->nhanSu($this->queryApi($filters));
            $list = $res->successful() ? $this->locTaiCho($res->json('data') ?? [], $filters) : [];
        } catch (\Throwable $e) {
            Log::error('Export nhan su failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API để xuất tệp.');
        }

        $hang = [[
            'Mã NV', 'Họ tên', 'Giới tính', 'Ngày sinh', 'Điện thoại', 'CCCD',
            'Email', 'Địa chỉ', 'Chi nhánh', 'Ca làm việc', 'Ngày vào làm',
            'Phân quyền', 'Tên đăng nhập', 'Trạng thái', 'Ghi chú',
        ]];

        foreach ($list as $ns) {
            $ca = collect(explode(',', (string) ($ns['work_shift'] ?? '')))
                ->filter()->map(fn ($c) => self::CA_LAM[$c] ?? $c)->implode(', ');
            $quyen = collect((array) ($ns['quyen'] ?? []))
                ->map(fn ($c) => self::NHAN_CUA[$c] ?? $c)->implode(', ');

            // Ép chuỗi cho MỌI ô: điện thoại và CCCD toàn chữ số, để kiểu số thì
            // Excel cắt số 0 đầu và bẻ CCCD dài thành ký hiệu khoa học.
            $hang[] = [
                (string) ($ns['code'] ?? ''),
                (string) ($ns['full_name'] ?? ''),
                self::GIOI_TINH[$ns['gender'] ?? ''] ?? '',
                self::ngayGon($ns['birth_date'] ?? null),
                (string) ($ns['phone'] ?? ''),
                (string) ($ns['id_number'] ?? ''),
                (string) ($ns['email'] ?? ''),
                (string) ($ns['address'] ?? ''),
                (string) ($ns['shop_name'] ?? ''),
                $ca,
                self::ngayGon($ns['hired_on'] ?? null),
                $quyen,
                (string) ($ns['username'] ?? ''),
                self::TRANG_THAI[$ns['status'] ?? ''] ?? ($ns['status'] ?? ''),
                (string) ($ns['note'] ?? ''),
            ];
        }

        return $this->taiXlsx($hang, 'nhan-su-'.date('Ymd-His'), 'Nhan su');
    }

    /** Ngày dạng người đọc; API trả chuỗi ISO đầy đủ. */
    protected static function ngayGon(?string $ngay): string
    {
        return filled($ngay) ? Carbon::parse($ngay)->format('d/m/Y') : '';
    }

    /** Đặt lại mật khẩu mặc định cho tài khoản của hồ sơ (nút của v2). */
    public function resetPassword(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->datLaiMatKhauNhanSu($id),
            'Đã đặt lại mật khẩu mặc định cho tài khoản này.'
        );
    }

    /**
     * Nhận ảnh nhân viên, trả về đường dẫn đã lưu.
     *
     * Ảnh ở lại ổ đĩa của Shop Admin (ImageStore thu nhỏ rồi nén), chỉ ĐƯỜNG DẪN
     * đi lên API — cùng lối với ảnh sản phẩm và danh mục. Tải trước rồi mới gửi
     * hồ sơ, nên bấm Lưu mà hỏng thì ảnh vẫn còn, không phải chọn lại.
     */
    public function uploadAnh(Request $request)
    {
        $request->validate(['anh' => ImageStore::rules()], ImageStore::messages());

        return response()->json(['url' => ImageStore::put($request->file('anh'), 'nhan-su')]);
    }

    /**
     * Xoá hồ sơ nhân viên.
     *
     * Bên API là xoá MỀM và khoá luôn tài khoản đăng nhập gắn kèm; ở đây chỉ thêm
     * một việc API không làm được: dọn tệp ảnh trên ổ đĩa của Shop Admin. Dọn SAU
     * khi API nhận, vì API còn có thể từ chối (còn ca chưa đóng, đã ghi sổ quỹ).
     */
    public function destroy(Request $request, int $id)
    {
        $anh = trim((string) $request->input('avatar', ''));

        return $this->send(
            fn () => $this->api->xoaNhanSu($id),
            'Đã xoá hồ sơ nhân viên.',
            function () use ($anh) {
                ImageStore::xoa($anh);

                return null;
            }
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Câu báo sau khi gạt công tắc. Nói rõ chuyện TÀI KHOẢN đã xảy ra theo, vì đó
     * là thứ người bấm không nhìn thấy trên bảng: họ gạt một ô "đang làm / đã
     * nghỉ" và không có lý do gì để đoán rằng mật khẩu của người kia vừa hết dùng
     * được — hoặc vừa dùng lại được.
     */
    protected function cauBaoTrangThai(string $status, bool $moTaiKhoan): string
    {
        if ($status !== self::DANG_LAM) {
            return 'Đã đánh dấu nghỉ việc và khoá tài khoản đăng nhập của người này. '
                .'Hồ sơ vẫn giữ nguyên để tra lại.';
        }

        return $moTaiKhoan
            ? 'Đã đặt lại thành đang làm việc và mở lại tài khoản đăng nhập.'
            : 'Đã đặt lại thành đang làm việc. Tài khoản đăng nhập vẫn đang khoá — '
                .'mở khi nào người này cần vào phần mềm.';
    }

    /**
     * Bộ lọc — cùng tên khoá với query của API. Giá trị lạ trên URL bị bỏ đi:
     * gửi thẳng thì API trả danh sách rỗng, nhìn giống hệt "cửa hàng chưa có ai".
     */
    /** Số dòng mỗi trang, chỉ nhận các mức có trong ô chọn. */
    protected function soDongMoiTrang(Request $request): int
    {
        $n = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return in_array($n, self::MUC_SO_DONG, true) ? $n : self::SO_DONG_MOI_TRANG;
    }

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', '');
        // Ba ô lọc chọn NHIỀU (chi nhánh, ca, giới tính); nhận cả dạng một giá trị.
        $mang = fn (string $khoa, array $hopLe) => array_values(array_intersect(
            array_map('strval', (array) $request->query($khoa, [])), $hopLe
        ));

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::TRANG_THAI[$status]) ? $status : '',
            'work_shift' => $mang('work_shift', array_keys(self::CA_LAM)),
            'gender' => $mang('gender', array_keys(self::GIOI_TINH)),
            'shop_id' => array_values(array_filter(
                array_map('intval', (array) $request->query('shop_id', [])), fn ($v) => $v > 0
            )),
        ];
    }

    /** Phần bộ lọc API hiểu được: một giá trị thì gửi thẳng, chọn nhiều thì lọc tại chỗ. */
    protected function queryApi(array $filters): array
    {
        $q = ['keyword' => $filters['keyword'], 'status' => $filters['status']];
        foreach (['work_shift', 'shop_id'] as $khoa) {
            if (count($filters[$khoa]) === 1) {
                $q[$khoa] = $filters[$khoa][0];
            }
        }

        return array_filter($q, fn ($v) => $v !== '' && $v !== 0);
    }

    /** Lọc chọn nhiều trên danh sách API trả về (API chỉ nhận một ca / một chi nhánh). */
    protected function locTaiCho(array $list, array $filters): array
    {
        return array_values(array_filter($list, function (array $ns) use ($filters) {
            if ($filters['shop_id'] !== []) {
                $cua = array_map('intval', array_filter(explode(',', (string) ($ns['shop_ids'] ?? ''))));
                if ($cua === [] && (int) ($ns['shop_id'] ?? 0) > 0) {
                    $cua = [(int) $ns['shop_id']];
                }
                if (array_intersect($cua, $filters['shop_id']) === []) {
                    return false;
                }
            }
            if ($filters['gender'] !== [] && ! in_array((string) ($ns['gender'] ?? ''), $filters['gender'], true)) {
                return false;
            }
            if ($filters['work_shift'] !== []) {
                $ca = array_filter(explode(',', (string) ($ns['work_shift'] ?? '')));
                if (array_intersect($ca, $filters['work_shift']) === []) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Kiểm dữ liệu rồi dựng payload gửi API. Không thay cho API — bên đó kiểm lại
     * tất cả; lượt này chỉ để người dùng thấy lỗi ngay tại ô vừa gõ.
     */
    protected function validated(Request $request): array
    {
        $du = $request->validate([
            'code' => ['nullable', 'string', 'max:30'],
            'full_name' => ['required', 'string', 'max:150'],
            'gender' => ['nullable', 'in:'.implode(',', array_keys(self::GIOI_TINH))],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'regex:/^0[0-9]{9,10}$/'],
            // Email bắt buộc khi cấp tài khoản: bảng `users` bên API bắt buộc có và
            // đặt UNIQUE lên nó.
            'email' => ['required_if:co_tai_khoan,1', 'nullable', 'email', 'max:191'],
            'id_number' => ['nullable', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],

            // Ca trực — mảng, một người trực được nhiều ca. Không bắt buộc: hồ sơ
            // khai vội lúc tuyển chưa biết xếp ca nào.
            'work_shift' => ['nullable', 'array', function ($_, $gia, $hong) {
                // "Cả ngày" đã gồm sáng và chiều. Màn hình khoá hai ô kia lại khi
                // tick nó, nhưng luật phải nằm ở đây nữa: một lượt POST dựng tay
                // không đi qua JavaScript nào cả.
                if (in_array('ca_ngay', (array) $gia, true) && count((array) $gia) > 1) {
                    $hong('Cả ngày đã gồm cả sáng lẫn chiều — bỏ tick hai ca kia.');
                }
            }],
            'work_shift.*' => ['string', 'in:'.implode(',', array_keys(self::CA_LAM))],
            // Chi nhánh bắt buộc: báo cáo theo chi nhánh sau này phải xếp được người
            // đó vào đâu đó.
            // Chọn NHIỀU chi nhánh như v2 (shop_ids); đường gọi cũ vẫn gửi shop_id đơn.
            'shop_id' => ['required_without:shop_ids', 'nullable', 'integer', 'min:1'],
            'shop_ids' => ['required_without:shop_id', 'nullable', 'array', 'min:1'],
            'shop_ids.*' => ['integer', 'min:1'],
            'allow_outside_area' => ['nullable', 'boolean'],
            // Đổi mật khẩu cho tài khoản đã có (ô mật khẩu ở hộp sửa của v2).
            'mat_khau_moi' => ['nullable', 'string', 'min:6', 'max:72'],
            'hired_on' => ['nullable', 'date'],
            'status' => ['required', 'in:'.implode(',', array_keys(self::TRANG_THAI))],

            'salary_type' => ['nullable', 'in:'.implode(',', array_keys(self::HINH_THUC_LUONG))],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'allowance' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            // Hoa hồng theo % doanh số; quá 100 thì bán càng nhiều càng lỗ.
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Khối tài khoản: không bật thì mấy ô dưới bỏ qua hết.
            'co_tai_khoan' => ['nullable', 'boolean'],
            // Nhận người cũ làm lại: có mở lại tài khoản đang khoá hay không.
            'mo_tai_khoan' => ['nullable', 'boolean'],
            // Nhóm quyền — MẢNG, vì một người mang được nhiều nhóm cùng lúc và
            // quyền của họ là hợp của chúng. Không kiểm id có thật ở đây: API
            // kiểm lại và từ chối id của cửa hàng khác, còn form thì chỉ cần
            // chặn thứ rõ ràng không phải số.
            // Phân quyền = CỬA VÀO (khu quản trị hay quầy bán). Mảng vì màn hình
            // cho tick nhiều vai; vaiTro() quy về một role_id. Bắt buộc khi đang
            // cấp tài khoản: một tài khoản không vai là tài khoản đăng nhập được
            // mà không mở được cửa nào.
            'quyen' => ['required_if:co_tai_khoan,1', 'array'],
            'quyen.*' => ['integer', 'in:'.implode(',', array_keys(self::QUYEN))],
            // Bộ ký tự khớp usernameRe bên Go. Cho gõ hoa rồi hạ chữ thường bên
            // dưới — bàn phím điện thoại tự viết hoa chữ đầu.
            'username' => ['required_if:co_tai_khoan,1', 'nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['nullable', 'string', 'min:6', 'max:72'],

            'note' => ['nullable', 'string', 'max:500'],
            // Đường dẫn ảnh do uploadAnh trả về; ô này không nhận tệp.
            'avatar' => ['nullable', 'string', 'max:255'],
            // Ảnh CŨ của hồ sơ, do form mang theo. Chỉ dùng để dọn tệp mồ côi sau
            // khi lưu — không gửi lên API.
            'avatar_cu' => ['nullable', 'string', 'max:255'],
        ], [
            'full_name.required' => 'Chưa nhập họ tên nhân viên.',
            'full_name.max' => 'Họ tên tối đa 150 ký tự.',
            'code.max' => 'Mã nhân viên tối đa 30 ký tự.',
            'birth_date.before' => 'Ngày sinh phải là một ngày đã qua.',
            'email.email' => 'Email không đúng định dạng.',
            'work_shift.*.in' => 'Ca làm việc không hợp lệ.',
            'shop_id.required_without' => 'Chưa chọn chi nhánh làm việc.',
            'shop_ids.required_without' => 'Chưa chọn chi nhánh làm việc.',
            'phone.regex' => 'Số điện thoại phải bắt đầu bằng 0 và có 10–11 chữ số.',
            'id_number.regex' => 'CCCD chỉ gồm chữ số.',
            'mat_khau_moi.min' => 'Mật khẩu mới tối thiểu 6 ký tự.',
            'status.required' => 'Chưa chọn trạng thái làm việc.',
            'status.in' => 'Trạng thái làm việc không hợp lệ.',
            'salary.numeric' => 'Mức lương phải là một con số.',
            'allowance.numeric' => 'Phụ cấp phải là một con số.',
            'commission_rate.max' => 'Hoa hồng tính theo phần trăm doanh số, tối đa 100.',
            'quyen.required_if' => 'Đã cấp tài khoản thì phải tick ít nhất một quyền — tài khoản không quyền là tài khoản đăng nhập được mà không mở được cửa nào.',
            'username.required_if' => 'Đã cấp tài khoản thì phải có tên đăng nhập.',
            'username.min' => 'Tên đăng nhập tối thiểu 3 ký tự.',
            'username.max' => 'Tên đăng nhập tối đa 50 ký tự.',
            'username.regex' => 'Tên đăng nhập chỉ gồm chữ không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (không khoảng trắng).',
            'password.min' => 'Mật khẩu tối thiểu 6 ký tự.',
            'email.required_if' => 'Cấp tài khoản đăng nhập thì hồ sơ phải có email — đó là địa chỉ nhận thư khôi phục mật khẩu.',
        ]);

        $coTaiKhoan = $request->boolean('co_tai_khoan');
        $email = trim((string) ($du['email'] ?? ''));
        // Chi nhánh chính đứng đầu; không gửi shop_ids thì lấy shop_id.
        $chiNhanh = array_values(array_unique(array_filter(array_map('intval', (array) ($du['shop_ids'] ?? [])))));
        if ($chiNhanh === [] && (int) ($du['shop_id'] ?? 0) > 0) {
            $chiNhanh = [(int) $du['shop_id']];
        }

        $data = [
            'code' => trim((string) ($du['code'] ?? '')),
            'full_name' => trim($du['full_name']),
            'gender' => (string) ($du['gender'] ?? ''),
            'birth_date' => (string) ($du['birth_date'] ?? ''),
            'phone' => trim((string) ($du['phone'] ?? '')),
            'email' => $email,
            'id_number' => trim((string) ($du['id_number'] ?? '')),
            'address' => trim((string) ($du['address'] ?? '')),
            // Chức danh KHÔNG gửi nữa (màn hình đã thay bằng ca làm). API hiểu ô
            // trống là giữ nguyên giá trị cũ, hồ sơ mới rơi về mặc định của cột.
            'work_shift' => array_values((array) ($du['work_shift'] ?? [])),
            'shop_id' => $chiNhanh[0] ?? 0,
            'shop_ids' => $chiNhanh,
            // null = giữ nguyên; API hiểu bỏ khoá là không đổi.
            'allow_outside_area' => $request->has('allow_outside_area') ? $request->boolean('allow_outside_area') : null,
            'mat_khau_moi' => (string) ($du['mat_khau_moi'] ?? ''),
            'hired_on' => (string) ($du['hired_on'] ?? ''),
            'status' => $du['status'],
            'salary_type' => (string) ($du['salary_type'] ?? ''),
            'salary' => (float) ($du['salary'] ?? 0),
            'allowance' => (float) ($du['allowance'] ?? 0),
            'commission_rate' => (float) ($du['commission_rate'] ?? 0),
            'note' => trim((string) ($du['note'] ?? '')),
            'avatar' => trim((string) ($du['avatar'] ?? '')),
            // Đặt "đã nghỉ" thì API tự khoá tài khoản, không hỏi. Khoá này chỉ nói
            // về chiều ngược lại — ô tick chỉ hiện ra khi nó có nghĩa.
            'mo_tai_khoan' => $request->boolean('mo_tai_khoan'),
            // Vai đọc từ ô tick "Phân quyền", KHÔNG suy từ chức danh: chức danh là
            // việc người đó làm, còn đây là cửa họ mở được — cửa hàng tách hai thứ
            // ra được (quản lý ca tối chỉ đứng quầy). Gửi ở cả lượt thêm lẫn lượt
            // sửa: bên API, hồ sơ đã có tài khoản thì đây là lệnh đổi vai trò.
            'role_id' => $this->vaiTro((array) ($du['quyen'] ?? [])),
            // Cửa vào — thứ thật sự chặn. Gửi nguyên hai ô đã tick chứ không gom
            // lại thành một con số, để bảng hiện lại đúng những gì vừa tick.
            'quyen' => $this->cuaVao((array) ($du['quyen'] ?? [])),
        ];

        // Tắt công tắc thì không gửi khối tài khoản, kể cả khi trình duyệt vẫn gửi
        // mấy ô kia lên.
        if ($coTaiKhoan) {
            $data['tai_khoan'] = [
                // Hạ chữ thường như Go chuẩn hoá: gửi 'An.NV' mà lưu 'an.nv' thì chủ
                // tiệm đưa nhân viên một tên đăng nhập khác tên thật dưới CSDL.
                'username' => mb_strtolower(trim((string) ($du['username'] ?? ''))),
                'password' => (string) ($du['password'] ?? ''),
            ];
        }

        return $data;
    }

    /**
     * Chi nhánh đang mở — cho ô chọn và ô lọc. Hỏng thì trả rỗng chứ không chặn
     * cả trang.
     */
    protected function chiNhanh(): array
    {
        try {
            $res = $this->api->chiNhanh(onlyActive: true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Load chi nhanh cho nhan su failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Câu nói thêm khi hồ sơ vừa lưu KHÔNG còn thuộc chi nhánh của tab đang mở.
     *
     * Bảng chỉ bày người của chi nhánh đang đứng (xem ChiNhanhTheoTab). Sửa một
     * hồ sơ sang chi nhánh khác là dòng đó biến mất ngay lượt nạp lại — mà người
     * vừa bấm Lưu không có cách nào phân biệt "đã chuyển đi" với "vừa mất dữ
     * liệu". Gọi tên chi nhánh mới ra, thay vì để họ đoán.
     *
     * Đọc bản ghi API TRẢ VỀ chứ không đọc thứ vừa gửi lên: sau lượt lưu, chỉ
     * bên đó mới biết hồ sơ thực sự nằm ở đâu.
     *
     * Trả null khi không có gì để nói: đang xem gộp mọi chi nhánh (bảng bày hết,
     * không giấu ai), hồ sơ vẫn thuộc chi nhánh của tab, hoặc hồ sơ không khai
     * chi nhánh nào (NULL = đi đâu cũng được).
     */
    protected function cauDoiChiNhanh(?array $hoSo): ?string
    {
        $tab = ApiClient::chiNhanhDangLam();
        if ($tab <= 0 || $hoSo === null) {
            return null;
        }

        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) ($hoSo['shop_ids'] ?? ''))
        )));
        if ($ids === [] || in_array($tab, $ids, true)) {
            return null;
        }

        $ten = array_values(array_filter(array_map(
            fn ($t) => trim((string) $t),
            (array) ($hoSo['shop_names'] ?? [])
        )));

        return $ten === []
            ? 'Hồ sơ nay thuộc chi nhánh khác nên không còn hiện trong bảng của chi nhánh bạn đang đứng.'
            : 'Hồ sơ nay thuộc '.implode(', ', $ten)
                .' nên không còn hiện trong bảng của chi nhánh bạn đang đứng.';
    }

    /**
     * Gọi API rồi quay lại danh sách. In `message` của API nguyên văn khi hỏng:
     * mỗi câu bên đó chỉ ra một việc phải làm khác nhau.
     *
     * $sauKhiLuu nhận bản ghi API vừa trả về, làm nốt việc phụ (dọn ảnh mồ côi)
     * và ĐƯỢC PHÉP trả về một câu nói thêm — câu đó nối vào sau lời báo thành
     * công, không thay nó.
     */
    protected function send(callable $call, string $success, ?callable $sauKhiLuu = null)
    {
        $request = request();

        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Nhan su API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            // Việc phụ chạy SAU khi hồ sơ đã lưu, và câu nó trả về chỉ NỐI THÊM
            // chứ không nuốt mất lời báo thành công — hồ sơ đã ghi rồi, che đi
            // thì người dùng bấm Lưu lần nữa và tạo ra bản thứ hai.
            $noiThem = $sauKhiLuu ? $sauKhiLuu($res->json('data')) : null;
            $cau = trim($success.' '.((string) $noiThem));

            // Lượt lưu này có thể vừa đổi CỬA của chính người đang bấm — hỏi lại
            // API ngay, thay vì để họ nhìn một thanh điều hướng nói sai cho tới
            // lần đăng nhập sau. Đây đúng là chỗ "đáng hỏi" mà CuaVao nói tới.
            CuaVao::lamMoi();

            // Hộp thoại v2 gọi bằng AJAX: trả JSON để hộp tự đóng và bắn toast.
            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'message' => $cau]);
            }

            return redirect()->route('admin.nhan-su.index')->with('success', $cau);
        }

        return $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác không thành công.'));
    }
}
