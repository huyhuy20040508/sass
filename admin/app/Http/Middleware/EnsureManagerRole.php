<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn thu ngân (staff) khỏi mọi thứ ngoài cụm quầy.
 *
 * Vai trò `staff` làm việc ở module Thu ngân (/thu-ngan — bán tại quầy, ca làm
 * việc, đơn quầy; cả module CỐ Ý không có middleware này). Trong khu quản trị họ
 * chỉ còn mở được Tổng quan, Đơn hàng và hồ sơ của chính mình. Hàng hoá, tiếp
 * thị, kho, mua vào, trả hàng, khách hàng, người dùng, báo cáo và cấu hình đều
 * nằm sau middleware này.
 *
 * Đây là chặn theo NHÓM TRANG dựa trên tên vai trò, KHÔNG phải phân quyền theo
 * từng chức năng — chưa có bảng permissions. API cũng chặn đúng các nhóm endpoint
 * tương ứng, nên bỏ middleware này ra thì người dùng chỉ nhận 403 muộn hơn chứ
 * không lọt dữ liệu.
 *
 * Chạy SAU EnsureAdminAuthenticated, nên tới đây chắc chắn đã đăng nhập.
 */
class EnsureManagerRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = data_get(session('api.user'), 'role.name');

        // Từ migration 0015, cửa vào đọc từ `users.access_areas` — tích gì vào được
        // nấy, nên một tài khoản vai admin mà chủ tiệm bỏ tích "Quản lý" thì không
        // vào đây nữa. EnsureCuaVao giữ luật đọc đó, kể cả đường lui cho phiên cũ.
        //
        // Super admin luôn đi qua: đó là tài khoản gốc của cửa hàng, khoá nhầm nó
        // là mất luôn đường vào để sửa (API cũng miễn trừ y như vậy).
        if ($role === 'super_admin' || in_array('quan_ly', EnsureCuaVao::cuaCuaPhien(), true)) {
            return $next($request);
        }

        $message = 'Tài khoản thu ngân không mở được mục này. Nhờ quản trị viên thực hiện giúp.';

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        // Về Tổng quan chứ không về trang đăng nhập: người dùng ĐÃ đăng nhập hợp lệ,
        // đá ra màn hình login chỉ khiến họ tưởng phiên bị hỏng.
        return redirect()->route('admin.dashboard')->with('error', $message);
    }
}
