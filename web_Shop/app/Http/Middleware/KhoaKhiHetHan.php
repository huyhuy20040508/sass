<?php

namespace App\Http\Middleware;

use App\Services\ApiClient;
use App\Services\HanSuDung;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cửa hàng HẾT HẠN HỢP ĐỒNG: giam phiên lại đúng một trang.
 *
 * Go API là chốt chặn thật — nó trả 403 kèm mã `CUA_HANG_KHOA` cho mọi đường trừ
 * đường đọc gói dịch vụ (xem middleware.choPhepKhiCuaHangKhoa bên đó). Middleware
 * này KHÔNG thay thế chốt ấy; nó làm hai việc chốt kia không làm được:
 *
 *  1. NÓI RA. Không có nó, người hết hạn bấm từng mục trong thanh trái và nhận
 *     một lỗi khác nhau ở mỗi mục, chẳng mục nào nói phải làm gì tiếp.
 *
 *  2. KHOÁ ĐÚNG LÚC. Chốt bên API dựa vào `tenants.status`, mà cột đó do lượt
 *     quét nền đặt — 5 phút một lần. Nghĩa là có một khoảng tới năm phút sau khi
 *     hợp đồng chết mà API vẫn cho qua: bấm F5 cũng không có cảnh báo nào. Ở đây
 *     so thẳng ĐỒNG HỒ với mốc hết hạn nên khoá ngay tại giây đó.
 *
 * Mốc hết hạn nằm trong session (xem HanSuDung), làm mới nhiều nhất một lần mỗi
 * phút — nên phần lớn request không tốn thêm lượt gọi API nào.
 *
 * Đặt SAU EnsureAdminAuthenticated: tới đây chắc chắn đã đăng nhập.
 */
class KhoaKhiHetHan
{
    /**
     * Những route vẫn đi được khi cửa hàng đã khoá.
     *
     * ĐÚNG MỘT trang, và là trang chỉ đọc. Danh sách này phải khớp với danh sách
     * cho phép bên Go API — thêm ở đây mà bên kia không mở thì trang mới chỉ hiện
     * ra một lỗi 403, còn mở bên kia mà quên ở đây thì trang đó không tới nơi.
     */
    protected array $choPhep = [
        'admin.goi-dich-vu.index',
        // CẢ LUỒNG THANH TOÁN, không chỉ trang xem gói: đưa người hết hạn tới một
        // trang bảng giá rồi chặn đúng cái nút trả tiền thì họ vẫn phải gọi điện,
        // và cái khoá vừa dựng thành ra chặn luôn doanh thu của chính mình.
        'admin.goi-dich-vu.gia-han',
        'admin.goi-dich-vu.thanh-toan',
        'admin.goi-dich-vu.don',
    ];

    /** Vai trò đọc được hợp đồng. Nhân viên gọi vào đó chỉ nhận 403 của tầng quyền. */
    protected array $vaiTroDocDuocHopDong = ['super_admin', 'admin'];

    public function __construct(protected ApiClient $api) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenRoute = $request->route()?->getName();
        $laTrangGoi = in_array($tenRoute, $this->choPhep, true);

        // Trang gói dịch vụ tự đọc hợp đồng trong controller của nó, nên bỏ qua
        // lượt làm mới ở đây — không thì mỗi lần mở trang là hai lượt gọi API hỏi
        // đúng một câu.
        if (! $laTrangGoi) {
            $this->lamMoiHan();
        }

        if (! HanSuDung::daKhoa() || $laTrangGoi) {
            return $next($request);
        }

        $message = 'Cửa hàng đã hết hạn sử dụng. Vui lòng gia hạn để tiếp tục làm việc.';

        // AJAX (chuông thông báo, các nút gọi fetch) nhận JSON: trả về một trang
        // HTML chuyển hướng ở đây thì script bên kia parse ra rác rồi im lặng
        // hỏng, còn người dùng không thấy gì cả.
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        // Mọi trang khác — kể cả các lượt POST/PUT/DELETE — đều về trang gói dịch
        // vụ. Không phân biệt đọc với ghi: cả hai đều bị API từ chối, và đưa người
        // ta về cùng một chỗ thì họ không phải đoán trang nào còn dùng được.
        return redirect()->route('admin.goi-dich-vu.index');
    }

    /**
     * Hỏi lại API về hợp đồng, nhiều nhất mỗi phút một lần.
     *
     * Hỏng thì IM LẶNG bỏ qua: API chập chờn không được biến thành "cửa hàng hết
     * hạn". Mốc cũ trong session vẫn dùng tiếp, và chốt chặn thật bên API vẫn
     * nguyên đó.
     */
    protected function lamMoiHan(): void
    {
        if (! HanSuDung::nenHoiLai()) {
            return;
        }
        if (! in_array(data_get(session('api.user'), 'role.name'), $this->vaiTroDocDuocHopDong, true)) {
            return;
        }

        try {
            $res = $this->api->goiDichVu();
            if ($res->successful()) {
                HanSuDung::ghiNhan($res->json('data.hop_dong'));
            }
        } catch (\Throwable $e) {
            Log::warning('Khong doc duoc han hop dong', ['msg' => $e->getMessage()]);
        }
    }
}
