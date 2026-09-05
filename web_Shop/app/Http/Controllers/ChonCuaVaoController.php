<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ChiNhanhDangLam;
use App\Services\ModuleLamViec;
use Illuminate\Http\Request;

/**
 * MÀN CHỌN CỬA VÀO — chặng giữa trang đăng nhập và chỗ làm việc.
 *
 * Hỏi đúng hai câu, và chỉ hỏi một lần mỗi lượt đăng nhập:
 *
 *   1. Hôm nay đứng ở CHI NHÁNH nào? (bỏ qua nếu tiệm chỉ có một)
 *   2. Vào QUẦY BÁN hay KHU QUẢN TRỊ? (người một cửa vẫn thấy, chỉ một ô)
 *
 * VÌ SAO CÓ MÀN NÀY. Trước đây đăng nhập xong là rơi thẳng vào một module suy từ
 * vai trò. Với người chỉ có một cửa thì đúng, nhưng chủ tiệm — người được giao cả
 * hai — luôn rơi vào khu quản trị, kể cả lúc họ đăng nhập lúc 7h sáng để mở ca
 * bán hàng. Họ phải tự tìm nút đổi module ở góc phải thanh trên cùng, một cái nút
 * chỉ hiện tên module đang mở nên không mấy ai đoán ra nó là lối đi.
 *
 * Câu chi nhánh đi kèm vì cùng một lý do, và nó là câu ĐẮT hơn: chọn nhầm kho thì
 * hàng đi ra khỏi kho khác, và không ai phát hiện cho tới lúc kiểm kê. Ô chọn chi
 * nhánh vẫn nằm trên cả hai thanh trên cùng như cũ — màn này chỉ đưa nó ra trước
 * mắt đúng lúc người ta bắt đầu ca.
 *
 * KHÔNG PHẢI chốt bảo mật. Cửa vào do Go API quyết (`users.access_areas`), và
 * `admin.cua` chặn lại lần nữa ở từng module. Đây chỉ là một ngã ba có biển chỉ
 * đường.
 */
class ChonCuaVaoController extends Controller
{
    /**
     * Hiện màn chọn — cho MỌI người, kể cả người chỉ được giao một khu.
     *
     * Với họ ô khu vực không còn là câu hỏi, nhưng ba thứ còn lại thì còn: tên
     * tiệm vừa đăng nhập, chi nhánh hôm nay đứng, và một chỗ để đăng xuất khi gõ
     * nhầm tài khoản. Thu ngân đi thẳng vào quầy là mất cả ba.
     *
     * Không có khu nào thì mới đi thẳng: lúc đó màn này trống thật.
     */
    public function index()
    {
        $ds = ModuleLamViec::danhSach();

        if ($ds === []) {
            return redirect()->to(ModuleLamViec::trangChuCuaPhien());
        }

        // Tiệm một chi nhánh không có gì để chọn — cùng luật với ô chọn trên hai
        // thanh trên cùng (xem partials/topbar và layouts/thu-ngan).
        $cn = ChiNhanhDangLam::danhSach();

        return view('auth.chon-cua-vao', [
            'modules' => $ds,
            'chiNhanh' => count($cn['ds']) > 1 ? $cn['ds'] : [],
            'chiNhanhDangChon' => $cn['dangChon'],
            'nguoiDung' => session('api.user'),
            'cuaHang' => trim((string) data_get(session('api.tenant'), 'name', '')),
        ]);
    }

    /**
     * Ghi lựa chọn rồi vào module.
     *
     * MỘT lượt gửi cho cả hai câu: chi nhánh nằm trong cùng biểu mẫu với các ô
     * module, nên người dùng bấm đúng một lần. Tách thành hai bước (đổi chi nhánh
     * → tải lại trang → bấm module) là thêm một vòng chờ vào đúng phút đầu ca, mà
     * ai lỡ bấm module trước thì lựa chọn chi nhánh rơi mất không báo gì.
     */
    public function vao(Request $request)
    {
        $du = $request->validate([
            'module' => ['required', 'string'],
            'chi_nhanh' => ['nullable', 'integer', 'min:0'],
        ]);

        // Module phải nằm trong danh sách CỦA NGƯỜI NÀY, không phải trong danh
        // sách module của phần mềm: biểu mẫu gửi lên là thứ sửa được, và nhận bừa
        // ở đây thì `admin.cua` bên trong module mới chặn — người dùng bấm một ô
        // rồi bị đá ngược về, không hiểu vì sao.
        $chon = ModuleLamViec::timTheoMa($du['module']);

        if ($chon === null) {
            return redirect()->route('chon-cua')
                ->with('error', 'Tài khoản của bạn không được giao khu vực này.');
        }

        // Chi nhánh: chỉ ghi khi biểu mẫu có gửi lên. Tiệm một chi nhánh không in
        // ô chọn nào, và lúc đó ghi bừa số 0 vào phiên là lặng lẽ bỏ chi nhánh
        // người ta đang làm việc dở.
        //
        // KHÔNG tự xác minh id ở đây, cùng lý do với ChiNhanhController::dangLam:
        // API mới là nơi tra sổ và từ chối chi nhánh của cửa hàng khác. Chép luật
        // sang đây là để hai bản lệch nhau.
        if ($request->has('chi_nhanh')) {
            // 0 = xem gộp mọi chi nhánh. GHI SỐ 0 chứ không bỏ khoá: bỏ khoá là
            // "chưa chọn", và ChiNhanhDangLam sẽ ghim chi nhánh đầu tiên đè lên —
            // mục "Tất cả chi nhánh" chọn xong lại rơi về chi nhánh 1. Số 0 trong
            // phiên thì ApiClient không đính header (API đọc gộp) và không ai ghim
            // lại nữa.
            session([ApiClient::KHOA_CHI_NHANH => max(0, (int) ($du['chi_nhanh'] ?? 0))]);
        }

        return redirect()->to($chon['href']);
    }
}
