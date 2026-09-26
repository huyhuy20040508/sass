<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use App\Services\WorkspaceModule;
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
class ChooseWorkspaceController extends Controller
{
    /**
     * Hiện màn chọn khi còn thứ để chọn; không còn gì thì đi thẳng.
     *
     * Còn để chọn = từ hai khu vực, HOẶC từ hai chi nhánh. Thiếu cả hai thì màn
     * này chỉ là một nút bấm thừa giữa lượt đăng nhập và ca làm.
     *
     * Vì sao chi nhánh cũng tính: vỏ Thu ngân KHÔNG cho đổi chi nhánh (xem
     * v2/layouts/pos — "Đổi chi nhánh là việc của khu quản trị"), nên với
     * tiệm nhiều kho thì đây là chỗ duy nhất thu ngân chọn được kho hôm nay. Bỏ
     * màn này đi là khoá họ vào đúng một kho.
     *
     * Đường đăng xuất lúc gõ nhầm tài khoản thì không mất: vỏ Thu ngân có sẵn
     * trong menu tài khoản.
     */
    public function index()
    {
        $ds = WorkspaceModule::danhSach();

        if ($ds === []) {
            return redirect()->to(WorkspaceModule::trangChuCuaPhien());
        }

        // Tiệm một chi nhánh không có gì để chọn — cùng luật với ô chọn trên hai
        // thanh trên cùng (xem partials/topbar và v2/layouts/pos).
        $cn = CurrentBranch::danhSach();

        if (count($ds) === 1 && count($cn['ds']) <= 1) {
            return redirect()->to(array_values($ds)[0]['href']);
        }

        return view('auth.choose-workspace', [
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
        $chon = WorkspaceModule::timTheoMa($du['module']);

        if ($chon === null) {
            return redirect()->route('chon-cua')
                ->with('error', 'Tài khoản của bạn không được giao khu vực này.');
        }

        // Chi nhánh: chỉ ghi khi biểu mẫu có gửi lên. Tiệm một chi nhánh không in
        // ô chọn nào, và lúc đó ghi bừa số 0 vào phiên là lặng lẽ bỏ chi nhánh
        // người ta đang làm việc dở.
        //
        // KHÔNG tự xác minh id ở đây, cùng lý do với BranchController::dangLam:
        // API mới là nơi tra sổ và từ chối chi nhánh của cửa hàng khác. Chép luật
        // sang đây là để hai bản lệch nhau.
        if ($request->has('chi_nhanh')) {
            // 0 = xem gộp mọi chi nhánh. GHI SỐ 0 chứ không bỏ khoá: bỏ khoá là
            // "chưa chọn", và CurrentBranch sẽ ghim chi nhánh đầu tiên đè lên —
            // mục "Tất cả chi nhánh" chọn xong lại rơi về chi nhánh 1. Số 0 trong
            // phiên thì ApiClient không đính header (API đọc gộp) và không ai ghim
            // lại nữa.
            session([ApiClient::KHOA_CHI_NHANH => max(0, (int) ($du['chi_nhanh'] ?? 0))]);
        }

        return redirect()->to($chon['href']);
    }
}
