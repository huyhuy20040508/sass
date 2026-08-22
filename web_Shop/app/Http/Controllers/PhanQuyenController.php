<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Phân quyền theo chức năng — chi nhánh → nhân viên → tick từng việc họ được làm.
 *
 * Quyền của một người nằm ở bảng `user_permissions` của CHÍNH họ (migration
 * 0017), đúng lối bản ERP cũ mà cửa hàng đang quen.
 *
 * Danh mục quyền do API giữ (`api/internal/domain/quyen.go`), không khai lại ở
 * đây: thêm một quyền bên Go là bảng tick hiện ngay.
 *
 * CỬA VÀO VẪN ĐỨNG TRÊN QUYỀN. Cây quyền chia sẵn hai KHU (`domain.KhuQuyen`),
 * đúng hai module người dùng đứng vào: Quản trị và Thu ngân. Người chỉ được giao
 * cửa quầy thì cả khu Quản trị khoá cứng — muốn giao thì mở cửa Quản lý cho họ
 * trong hồ sơ Nhân sự trước.
 *
 * BỘ QUYỀN MẪU (nhóm quyền) CHƯA LÀM. API vẫn còn đủ đường
 * (`/admin/nhom-quyen`), chỉ là trang quản trị chưa mở lối vào — dựng lại một
 * tab gọi mấy đường đó là có.
 */
class PhanQuyenController extends Controller
{
    public const TITLE = 'Phân quyền';

    public const SUB = 'Chọn chi nhánh, chọn nhân viên rồi tick những việc họ được làm.';

    /** Bốn việc chuẩn — nhãn cột của bảng tick, mã khớp `domain.Quyen*` bên Go. */
    public const VIEC = [
        'xem' => 'Xem',
        'them' => 'Thêm mới',
        'sua' => 'Sửa',
        'xoa' => 'Xoá',
    ];

    public function __construct(protected ApiClient $api) {}

    /**
     * Cây chi nhánh → nhân viên, chọn ai thì hiện bảng tick của người đó (?nv=).
     *
     * KHÔNG tự chọn người đầu danh sách: bảng bên phải là form ghi thật, mở trang
     * ra mà sẵn một người trong đó là mời bấm Lưu nhầm lên quyền của người lạ.
     */
    public function index(Request $request)
    {
        $error = null;
        $chiNhanh = [];
        $nhanVien = [];
        $danhMuc = [];

        try {
            [$chiNhanh, $error] = $this->doc(fn () => $this->api->chiNhanh(), 'danh sách chi nhánh', $error);
            [$nhanVien, $error] = $this->doc(fn () => $this->api->nhanSu(), 'danh sách nhân sự', $error);
            [$danhMuc, $error] = $this->doc(fn () => $this->api->danhMucQuyen(), 'danh mục quyền', $error);
            [$danhMuc, $error] = $this->locKhu($danhMuc, $error);
        } catch (\Throwable $e) {
            Log::error('Load phan quyen failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được dữ liệu phân quyền. Kiểm tra kết nối API.';
        }

        $chon = collect($nhanVien)->firstWhere('id', (int) $request->query('nv', 0));

        // Quyền của người đang chọn — đọc riêng, vì danh sách nhân sự không mang
        // theo (một cửa hàng ba chục người là ba chục lượt đọc cho một lần mở trang).
        $dangBat = [];
        $toanQuyen = false;
        $userId = (int) ($chon['user_id'] ?? 0);
        if ($userId > 0) {
            try {
                $resQuyen = $this->api->quyenCuaNguoi($userId);
                if ($resQuyen->successful()) {
                    $dangBat = array_flip((array) $resQuyen->json('data.quyen'));
                    $toanQuyen = (bool) $resQuyen->json('data.toan_quyen');
                } else {
                    $error ??= $resQuyen->json('message') ?: 'Không đọc được quyền của nhân viên này.';
                }
            } catch (\Throwable $e) {
                Log::error('Load quyen nhan vien failed', ['msg' => $e->getMessage()]);
                $error ??= 'Không đọc được quyền của nhân viên này. Kiểm tra kết nối API.';
            }
        }

        $view = view('phan-quyen.index', [
            'chiNhanh' => $chiNhanh,
            'nhanVien' => $nhanVien,
            'danhMuc' => $danhMuc,
            'viec' => self::VIEC,
            'chon' => $chon,
            'dangBat' => $dangBat,
            'toanQuyen' => $toanQuyen,
            'toiLaAi' => (int) session('api.user.id'),
            'chiQuay' => $chon ? $this->chiCuaQuay($chon) : false,
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Lưu bảng tick của MỘT nhân viên — thay toàn bộ quyền của tài khoản đó.
     *
     * `$id` là id TÀI KHOẢN (users), không phải id hồ sơ nhân sự; `nv` đi kèm chỉ
     * để quay lại đúng người vừa sửa. Không tick ô nào = thu sạch quyền: họ vẫn
     * đăng nhập được, chỉ là không mở được trang nào.
     */
    public function datQuyenNhanVien(Request $request, int $id)
    {
        $du = $request->validate([
            'nv' => ['nullable', 'integer', 'min:1'],
            'quyen' => ['nullable', 'array'],
            'quyen.*' => ['string', 'max:64'],
        ]);

        try {
            $res = $this->api->datQuyenChoNguoi($id, $du['quyen'] ?? []);
        } catch (\Throwable $e) {
            Log::error('Dat quyen nhan vien failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            $nv = (int) ($du['nv'] ?? 0);

            return redirect()
                ->route('admin.phan-quyen.index', $nv ? ['nv' => $nv] : [])
                ->with('success', 'Đã lưu quyền của nhân viên.');
        }

        // In `message` của API ra nguyên văn: mấy câu từ chối ở đây (quyền lạ, tự
        // sửa quyền của chính mình) mỗi câu chỉ ra một việc phải làm khác nhau.
        $loi = $res->json('errors');
        $message = is_array($loi) && $loi
            ? implode(' ', $loi)
            : ($res->json('message') ?: 'Thao tác không thành công.');

        return back()->with('error', $message);
    }

    /**
     * Chỉ giữ những mục ĐÚNG hình dạng một khu, và nói ra nếu không còn mục nào.
     *
     * Máy chủ API cũ hơn trang quản trị là chuyện có thật mỗi lượt triển khai lệch
     * nhịp. Không có chốt này thì bảng vẫn vẽ: mỗi khoá lạ thành một dòng không
     * tên, không con, và người dùng ngồi đoán xem mình vừa làm hỏng cái gì. Một
     * câu chỉ thẳng vào máy chủ đắt hơn cả màn hình đó.
     */
    protected function locKhu(array $data, ?string $loiCu): array
    {
        $khu = array_values(array_filter(
            $data,
            static fn ($k) => is_array($k) && isset($k['nhom'])
        ));

        if ($data !== [] && $khu === []) {
            return [[], $loiCu ?? 'Máy chủ API trả danh mục quyền theo hình dạng cũ (chưa chia theo khu làm việc). Khởi động lại API rồi tải lại trang.'];
        }

        return [$khu, $loiCu];
    }

    /**
     * Người này CHỈ đứng quầy — không mở được khu quản trị (`users.access_areas`).
     *
     * Với họ, cả khu Quản trị của cây quyền là chữ chết: nhóm route
     * `manage` bên API đòi cửa `quan_ly` trước khi hỏi tới quyền, nên dòng ghi
     * xuống không mở thêm được trang nào. Bảng tick khoá luôn những ô ấy thay vì
     * cho tick rồi báo lỗi sau — xem `ma-tran.blade.php`.
     *
     * KHÔNG BIẾT thì KHÔNG KHOÁ. Danh sách cửa rỗng nghĩa là payload cũ chưa mang
     * cột này, và khoá theo phỏng đoán ở đây là xám cả bảng của mọi nhân viên —
     * chủ tiệm hết đường phân quyền cho bất kỳ ai. Ngược với lối "quên gắn thì
     * đóng" của chốt chặn, vì đây không phải chốt: API vẫn từ chối như thường
     * nếu người đó thật sự không có cửa.
     */
    protected function chiCuaQuay(array $nv): bool
    {
        $cua = (array) ($nv['quyen'] ?? []);

        return $cua !== [] && ! in_array('quan_ly', $cua, true);
    }

    /**
     * Một lượt đọc: trả [dữ liệu, lỗi]. Giữ lỗi ĐẦU TIÊN — ba lượt đọc mà hỏng
     * cùng lúc thì ba câu giống nhau không nói thêm gì.
     */
    protected function doc(callable $goi, string $ten, ?string $loiCu): array
    {
        $res = $goi();
        if ($res->successful()) {
            return [$res->json('data') ?? [], $loiCu];
        }

        Log::warning('Load '.$ten.' failed', ['status' => $res->status()]);

        return [[], $loiCu ?? ($res->json('message') ?: 'Không tải được '.$ten.'.')];
    }
}
