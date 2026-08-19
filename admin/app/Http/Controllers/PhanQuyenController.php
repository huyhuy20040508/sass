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
