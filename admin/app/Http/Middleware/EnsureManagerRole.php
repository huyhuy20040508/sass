<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn thu ngân (staff) khỏi mọi thứ ngoài cụm quầy.
 *
 * Vai trò `staff` chỉ còn mở được Tổng quan, Bán tại quầy, Đơn hàng, Ca làm việc
 * và hồ sơ của chính mình. Hàng hoá, tiếp thị, kho, mua vào, trả hàng, khách
 * hàng, người dùng, báo cáo và cấu hình đều nằm sau middleware này.
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
    /** Vai trò được phép quản lý người dùng, khách hàng và cấu hình. */
    protected array $allowedRoles = ['super_admin', 'admin'];

    public function handle(Request $request, Closure $next): Response
    {
        $role = data_get(session('api.user'), 'role.name');

        if (in_array($role, $this->allowedRoles, true)) {
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
