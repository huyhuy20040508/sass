<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Quản lý chi nhánh — Cài đặt → Quản lý chi nhánh.
 *
 * "Cửa hàng" trong cả dự án là một khách đã mua phần mềm; "chi nhánh" là điểm
 * bán nằm TRONG cửa hàng đó (bảng `shops` bên API).
 *
 * Bộ cột và chức năng lấy của màn Quản lý chi nhánh bản cũ v2 (hồ sơ đầy đủ, ẩn
 * /hiện cột, xuất file, công tắc trạng thái); chỗ lưu của chúng ở migration
 * 0033. Bố cục thì theo khuôn trang danh sách hiện tại, không chép bộ lọc dọc
 * của v2. Cột "Sử dụng HĐĐT" chưa nối được: hoá đơn điện tử cần bảng riêng và
 * tích hợp nhà cung cấp.
 *
 * Nhóm route `admin.manage` — nhân viên không vào. Ngoại lệ là `dangLam`.
 */
class ChiNhanhController extends Controller
{
    /** Nhãn NGẮN cho thanh điều hướng. */
    public const TITLE = 'Quản lý chi nhánh';

    /** Tiêu đề trang — khuôn "Danh sách <đối tượng>" như các trang khác. */
    public const TITLE_PAGE = 'Danh sách chi nhánh';

    public const EMPTY_TEXT = 'Chưa có chi nhánh nào. Bấm "Thêm chi nhánh" để mở điểm bán đầu tiên.';

    public const SO_DONG_MOI_TRANG = 20;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    /** branch_type: điểm bán (mặc định) và pháp nhân — hai lựa chọn của bản v2. */
    public const LOAI_CHI_NHANH = 1;

    public const LOAI_CONG_TY = 2;

    public const LOAI = [
        self::LOAI_CHI_NHANH => 'Chi nhánh',
        self::LOAI_CONG_TY => 'Công ty',
    ];

    /** Trần ký tự của mỗi khối chữ hoá đơn — cùng con số với bản v2. */
    public const CHU_HOA_DON_TOI_DA = 255;

    /**
     * Nhà cung cấp hoá đơn điện tử đang nối được.
     *
     * Bản v2 bày sáu ô chọn nhưng chỉ ba cái chạy thật. Ở đây khai đúng cái đã
     * làm: bày một nhà cung cấp chưa nối được là để người dùng khai xong rồi
     * ngồi chờ một thứ không bao giờ phát hành nổi hoá đơn.
     */
    public const NHA_CUNG_CAP_ETAX = [
        'minvoice' => 'M-Invoice',
    ];

    /** Hai lựa chọn của ô lọc trạng thái. '' = không lọc. */
    public const TRANG_THAI = [
        '1' => 'Đang mở',
        '0' => 'Đã đóng',
    ];

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách + hộp thoại thêm/sửa/xem. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $list = [];

        try {
            $res = $this->api->chiNhanh();
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
            } else {
                Log::warning('Load chi nhanh failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách chi nhánh.';
            }
        } catch (\Throwable $e) {
            Log::error('Load chi nhanh failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách chi nhánh. Kiểm tra kết nối API.';
        }

        // Đếm trên TOÀN danh sách: con số ở header trả lời "cửa hàng có bao
        // nhiêu chi nhánh", không phải "trang này có mấy dòng".
        $tong = count($list);
        $dangMo = collect($list)->filter(fn ($cn) => ! empty($cn['is_active']))->count();

        // Ô lọc "Chi nhánh" phải bày đủ mọi chi nhánh, kể cả cái vừa bị chính bộ
        // lọc loại ra — nếu không thì chọn xong rồi không chọn lại được.
        $tatCa = collect($list)
            ->map(fn ($cn) => [
                'id' => (int) ($cn['id'] ?? 0),
                'code' => (string) ($cn['code'] ?? ''),
                'name' => (string) ($cn['name'] ?? ''),
            ])
            ->all();

        // Lọc ở đây vì API chỉ nhận `active=true`, không có tham số tìm kiếm.
        $list = $this->loc($list, $filters);

        $soDong = $this->soDongMoiTrang($request);
        $soTrang = max(1, (int) ceil(count($list) / $soDong));
        $trang = min(max(1, (int) $request->query('page', 1)), $soTrang);

        $view = view('chi-nhanh.index', [
            'list' => array_slice($list, ($trang - 1) * $soDong, $soDong),
            'tatCa' => $tatCa,
            'tong' => $tong,
            'dangMo' => $dangMo,
            'stt' => ($trang - 1) * $soDong,
            'meta' => [
                'page' => $trang,
                'total_pages' => $soTrang,
                'total' => count($list),
                'page_size' => $soDong,
            ],
            'filters' => $filters,
            // Chi nhánh đang đứng — bảng đánh dấu và khoá công tắc của nó.
            'dangLamId' => (int) session(ApiClient::KHOA_CHI_NHANH, 0),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Thêm chi nhánh. */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->taoChiNhanh($data),
            'Đã thêm chi nhánh "'.$data['name'].'".'
        );
    }

    /** Sửa thông tin một chi nhánh. */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->suaChiNhanh($id, $data),
            'Đã cập nhật chi nhánh "'.$data['name'].'".'
        );
    }

    /**
     * Công tắc mở/đóng trên bảng.
     *
     * API chỉ có PUT toàn phần nên phải đọc lại bản ghi rồi gửi nguyên trạng,
     * chỉ lật cờ. Đọc lại chứ không lấy giá trị đang hiện trên trang: trang có
     * thể đã cũ, và gạt một cái công tắc không được phép ghi đè thứ người khác
     * vừa sửa.
     */
    public function toggleStatus(Request $request, int $id)
    {
        $v = $request->validate([
            'is_active' => ['required', 'boolean'],
        ], [
            'is_active.required' => 'Thiếu trạng thái cần đặt.',
        ]);

        $bat = (bool) $v['is_active'];

        try {
            $res = $this->api->chiNhanhChiTiet($id);
        } catch (\Throwable $e) {
            Log::error('Load chi nhanh detail failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return back()->with('error', $res->json('message') ?: 'Không đọc được chi nhánh này.');
        }

        $cn = $res->json('data') ?? [];

        return $this->send(
            fn () => $this->api->suaChiNhanh($id, $this->payloadTuBanGhi($cn, $bat)),
            $bat
                ? 'Đã mở lại chi nhánh này.'
                : 'Đã đóng chi nhánh này — dữ liệu cũ vẫn tra lại được, nhưng nó thôi bán.'
        );
    }

    /**
     * Đổi CHI NHÁNH ĐANG LÀM VIỆC (ô chọn ở thanh trên cùng).
     *
     * Chỉ ghi vào phiên; từ lượt gọi API kế tiếp ApiClient tự đính chi nhánh này
     * vào mọi request, nên kho, đơn và mọi thao tác ghi đổi theo cùng lúc.
     * KHÔNG tự xác minh id ở đây — API mới là nơi tra sổ và từ chối chi nhánh
     * của cửa hàng khác.
     */
    public function dangLam(Request $request)
    {
        $du = $request->validate(['id' => ['required', 'integer', 'min:0']]);

        // 0 = xem gộp mọi chi nhánh: bỏ hẳn khoá khỏi phiên để ApiClient không
        // gửi header nào cả.
        if ((int) $du['id'] === 0) {
            session()->forget(ApiClient::KHOA_CHI_NHANH);
        } else {
            session([ApiClient::KHOA_CHI_NHANH => (int) $du['id']]);
        }

        return back();
    }

    // -----------------------------------------------------------------
    // Hoá đơn điện tử
    // -----------------------------------------------------------------

    /**
     * Kết nối HĐĐT của một chi nhánh — nạp cho hộp thoại "Chi tiết".
     *
     * Trả JSON vì hộp thoại mở ngay trên bảng, không tải lại trang. 404 của API
     * (chi nhánh chưa nối) KHÔNG phải lỗi ở đây: nó là câu trả lời "chưa có".
     */
    public function etax(int $id)
    {
        try {
            $res = $this->api->etax($id);
        } catch (\Throwable $e) {
            Log::error('Load etax failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API.'], 502);
        }

        if ($res->status() === 404) {
            return response()->json(['data' => null]);
        }
        if (! $res->successful()) {
            return response()->json(['message' => $res->json('message') ?: 'Không đọc được kết nối hoá đơn điện tử.'], 422);
        }

        return response()->json(['data' => $res->json('data')]);
    }

    /** Khai tài khoản cổng HĐĐT. API đăng nhập thật trước khi lưu. */
    public function ketNoiEtax(Request $request, int $id)
    {
        $du = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_keys(self::NHA_CUNG_CAP_ETAX))],
            'tax_code' => ['required', 'string', 'max:30'],
            'username' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'ma_dvcs' => ['nullable', 'string', 'max:20'],
        ], [
            'provider.in' => 'Nhà cung cấp hoá đơn điện tử này chưa được hỗ trợ.',
            'tax_code.required' => 'Chưa nhập mã số thuế.',
            'username.required' => 'Chưa nhập tên đăng nhập.',
            'password.required' => 'Chưa nhập mật khẩu.',
        ]);

        return $this->send(
            fn () => $this->api->ketNoiEtax($id, [
                'provider' => $du['provider'],
                'tax_code' => trim($du['tax_code']),
                'username' => trim($du['username']),
                // KHÔNG trim mật khẩu: khoảng trắng đầu/cuối có thể là một phần
                // của nó, và cắt đi là lượt đăng nhập hỏng mà không ai hiểu vì sao.
                'password' => $du['password'],
                'ma_dvcs' => trim((string) ($du['ma_dvcs'] ?? '')) ?: 'VP',
            ]),
            'Đã kết nối '.(self::NHA_CUNG_CAP_ETAX[$du['provider']] ?? 'cổng hoá đơn điện tử').'.'
        );
    }

    /** Chọn ký hiệu phát hành và hai công tắc tự động. */
    public function luuCaiDatEtax(Request $request, int $id)
    {
        $du = $request->validate([
            'template_symbol' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->send(
            fn () => $this->api->luuCaiDatEtax($id, [
                'template_symbol' => trim((string) ($du['template_symbol'] ?? '')),
                // Hai ô công tắc: không tick thì trình duyệt không gửi gì, nên
                // đọc từ request — thiếu khoá là TẮT, không phải "giữ nguyên".
                'auto_release' => $request->boolean('auto_release'),
                'auto_print' => $request->boolean('auto_print'),
            ]),
            'Đã lưu cài đặt hoá đơn điện tử.'
        );
    }

    /** Kéo lại danh sách ký hiệu từ nhà cung cấp. */
    public function dongBoMauEtax(int $id)
    {
        return $this->send(
            fn () => $this->api->dongBoMauEtax($id),
            'Đã đồng bộ mẫu hoá đơn.'
        );
    }

    /** Ngắt kết nối HĐĐT. */
    public function ngatEtax(int $id)
    {
        return $this->send(
            fn () => $this->api->ngatEtax($id),
            'Đã ngắt kết nối hoá đơn điện tử.'
        );
    }

    /**
     * Tải logo chi nhánh (ảnh in trên hoá đơn tại quầy).
     *
     * Ảnh ở lại ổ đĩa Shop Admin, chỉ ĐƯỜNG DẪN đi lên API — cùng lối với ảnh
     * sản phẩm và hồ sơ nhân sự. Tải trước khi gửi form nên lượt Lưu hỏng không
     * bắt chọn lại ảnh.
     */
    public function uploadAnh(Request $request)
    {
        $request->validate(['image' => ImageStore::rules()], ImageStore::messages());

        return response()->json(['url' => ImageStore::put($request->file('image'), 'chi-nhanh')]);
    }

    /** Xoá một chi nhánh (xoá mềm bên API — dữ liệu cũ vẫn tra lại được). */
    public function destroy(int $id)
    {
        return $this->send(fn () => $this->api->xoaChiNhanh($id), 'Đã xoá chi nhánh.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Số dòng mỗi trang, chỉ nhận các mức có trong ô chọn. */
    protected function soDongMoiTrang(Request $request): int
    {
        $n = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        return in_array($n, self::MUC_SO_DONG, true) ? $n : self::SO_DONG_MOI_TRANG;
    }

    /**
     * Ba ô lọc của v2: từ khoá, chi nhánh, trạng thái. Giá trị lạ trên URL bị bỏ
     * đi — giữ lại thì bảng rỗng, nhìn y như cửa hàng chưa mở chi nhánh nào.
     */
    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', '');

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'branch' => max(0, (int) $request->query('branch', 0)),
            'status' => isset(self::TRANG_THAI[$status]) ? $status : '',
        ];
    }

    /** Lọc theo từ khoá (tên hoặc mã), theo chi nhánh đã chọn và theo trạng thái. */
    protected function loc(array $list, array $filters): array
    {
        if ($filters['keyword'] !== '') {
            $tu = $filters['keyword'];
            $list = array_filter($list, function ($cn) use ($tu) {
                return mb_stripos((string) ($cn['name'] ?? ''), $tu) !== false
                    || mb_stripos((string) ($cn['code'] ?? ''), $tu) !== false;
            });
        }

        if ($filters['branch'] > 0) {
            $id = $filters['branch'];
            $list = array_filter($list, fn ($cn) => (int) ($cn['id'] ?? 0) === $id);
        }

        if ($filters['status'] !== '') {
            $bat = $filters['status'] === '1';
            $list = array_filter($list, fn ($cn) => (bool) ($cn['is_active'] ?? false) === $bat);
        }

        return array_values($list);
    }

    /**
     * Kiểm form: chỉ ĐỘ DÀI và kiểu, tức phần người dùng thấy ngay tại ô vừa gõ.
     * Khuôn mã, khuôn toạ độ và cặp vị trí/phạm vi để API lo — chép luật sang
     * đây là hai bản lệch nhau vào một ngày nào đó.
     */
    protected function validated(Request $request): array
    {
        $du = $request->validate([
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],

            // Hồ sơ đầy đủ — xem migration 0033.
            'transaction_name' => ['nullable', 'string', 'max:150'],
            'tax_code' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:50'],
            'area_scope' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'access_link' => ['nullable', 'string', 'max:255'],
            'branch_type' => ['nullable', 'integer', 'in:1,2'],
            'image' => ['nullable', 'string', 'max:255'],
            'header_invoice_info' => ['nullable', 'string', 'max:255'],
            'wifi_invoice_info' => ['nullable', 'string', 'max:255'],
            'footer_invoice_info' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'Chưa nhập tên chi nhánh.',
            'name.max' => 'Tên chi nhánh tối đa 150 ký tự.',
            'code.max' => 'Mã chi nhánh tối đa 30 ký tự.',
            'email.email' => 'Email không đúng dạng.',
            'area_scope.integer' => 'Phạm vi hoạt động là số mét, chỉ nhập số.',
            'area_scope.min' => 'Phạm vi hoạt động phải lớn hơn 0 mét.',
            'header_invoice_info.max' => 'Khối chữ đầu hoá đơn tối đa 255 ký tự.',
            'wifi_invoice_info.max' => 'Khối chữ wifi tối đa 255 ký tự.',
            'footer_invoice_info.max' => 'Khối chữ chân hoá đơn tối đa 255 ký tự.',
        ]);

        // area_scope rỗng gửi NULL chứ không phải 0: API bắt `min=1` nên số 0 ăn
        // 400 thay vì được hiểu là "không khai".
        $phamVi = $du['area_scope'] ?? null;

        return [
            'code' => trim((string) ($du['code'] ?? '')),
            'name' => trim($du['name']),
            'phone' => trim((string) ($du['phone'] ?? '')),
            'address' => trim((string) ($du['address'] ?? '')),
            // Checkbox bỏ tick thì không gửi gì: thiếu khoá là TẮT, không phải
            // "giữ nguyên" — nên đọc từ request chứ không từ $du.
            'is_active' => $request->boolean('is_active'),

            'transaction_name' => trim((string) ($du['transaction_name'] ?? '')),
            'tax_code' => trim((string) ($du['tax_code'] ?? '')),
            'email' => trim((string) ($du['email'] ?? '')),
            'country' => trim((string) ($du['country'] ?? '')),
            'city' => trim((string) ($du['city'] ?? '')),
            'location' => trim((string) ($du['location'] ?? '')),
            'area_scope' => $phamVi === null ? null : (int) $phamVi,
            'access_link' => trim((string) ($du['access_link'] ?? '')),
            'branch_type' => (int) ($du['branch_type'] ?? self::LOAI_CHI_NHANH),
            'image' => trim((string) ($du['image'] ?? '')),
            // Không trim ba khối hoá đơn: người dùng canh lề bản in bằng chính
            // khoảng trắng đầu dòng.
            'header_invoice_info' => (string) ($du['header_invoice_info'] ?? ''),
            'wifi_invoice_info' => (string) ($du['wifi_invoice_info'] ?? ''),
            'footer_invoice_info' => (string) ($du['footer_invoice_info'] ?? ''),
        ];
    }

    /**
     * Payload PUT dựng từ bản ghi đọc về, chỉ đổi cờ mở/đóng.
     *
     * Phải gửi ĐỦ MỌI Ô: API coi request là trạng thái cuối cùng, thiếu ô nào là
     * ô đó về NULL — gạt một cái công tắc mà mất sạch hồ sơ.
     */
    protected function payloadTuBanGhi(array $cn, bool $bat): array
    {
        $phamVi = $cn['area_scope'] ?? null;

        return [
            // Mã để TRỐNG = giữ nguyên mã cũ, theo đúng hợp đồng của API.
            'code' => '',
            'name' => (string) ($cn['name'] ?? ''),
            'phone' => (string) ($cn['phone'] ?? ''),
            'address' => (string) ($cn['address'] ?? ''),
            'is_active' => $bat,

            'transaction_name' => (string) ($cn['transaction_name'] ?? ''),
            'tax_code' => (string) ($cn['tax_code'] ?? ''),
            'email' => (string) ($cn['email'] ?? ''),
            'country' => (string) ($cn['country'] ?? ''),
            'city' => (string) ($cn['city'] ?? ''),
            'location' => (string) ($cn['location'] ?? ''),
            'area_scope' => $phamVi === null ? null : (int) $phamVi,
            'access_link' => (string) ($cn['access_link'] ?? ''),
            'branch_type' => (int) ($cn['branch_type'] ?? self::LOAI_CHI_NHANH),
            'image' => (string) ($cn['image'] ?? ''),
            'header_invoice_info' => (string) ($cn['header_invoice_info'] ?? ''),
            'wifi_invoice_info' => (string) ($cn['wifi_invoice_info'] ?? ''),
            'footer_invoice_info' => (string) ($cn['footer_invoice_info'] ?? ''),
        ];
    }

    /**
     * Gọi API rồi quay lại trang danh sách kèm một câu.
     *
     * In `message` của API nguyên văn khi hỏng: trùng mã, toạ độ sai khuôn, chi
     * nhánh cuối cùng — mỗi câu chỉ ra một việc phải làm khác nhau.
     */
    protected function send(callable $call, string $success)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Chi nhanh API call failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return redirect()->route('admin.chi-nhanh.index')->with('success', $success);
        }

        // 422 trả lỗi theo từng ô; gom thành một câu vì hộp thoại đã đóng sau
        // khi trang tải lại, không còn ô nào để gắn lỗi vào.
        //
        // Bỏ khoá `ma` trước khi gom: 403 của API kèm theo một MÃ MÁY ĐỌC
        // (THIEU_QUYEN, CUA_HANG_KHOA) nằm trong `errors`, còn câu cho người đọc
        // thì ở `message`. Gom cả `ma` vào thì toast in ra đúng chữ
        // "THIEU_QUYEN" — đã gặp thật khi chạy thử.
        $loi = $res->json('errors');
        if (is_array($loi)) {
            unset($loi['ma']);
        }
        $message = is_array($loi) && $loi
            ? implode(' ', $loi)
            : ($res->json('message') ?: 'Thao tác không thành công.');

        return back()->withInput()->with('error', $message);
    }
}
