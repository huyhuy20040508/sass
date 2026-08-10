<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn mọi route quản trị nếu chưa đăng nhập hoặc không đủ quyền.
 * Trạng thái đăng nhập được lưu trong session (không dùng bảng users cục bộ).
 * Hỗ trợ AJAX/JSON requests trả về 401 thay vì redirect.
 */
class EnsureAdminAuthenticated
{
    /**
     * Các vai trò được phép vào trang quản trị.
     *
     * `staff` vào được để làm việc kho và đơn hàng, nhưng KHÔNG vào được các trang
     * quản lý người và cấu hình — những trang đó gắn thêm middleware `admin.manage`
     * (xem EnsureManagerRole), và API cũng chặn ở tầng của nó.
     */
    protected array $allowedRoles = ['super_admin', 'admin', 'staff'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = session('api.access_token');
        $user = session('api.user');
        $role = $user ? data_get($user, 'role.name') : null;

        // Kiểm tra token tồn tại và role hợp lệ
        if (! $token || ! in_array($role, $this->allowedRoles, true)) {
            session()->forget('api');

            // Nếu request mong đợi JSON (AJAX) -> trả về 401 JSON
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'message' => 'Vui lòng đăng nhập bằng tài khoản quản trị.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()
                ->route('login')
                ->with('error', 'Vui lòng đăng nhập bằng tài khoản quản trị.');
        }

        return $next($request);
    }
}
