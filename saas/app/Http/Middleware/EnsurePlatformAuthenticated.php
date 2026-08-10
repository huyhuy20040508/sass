<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn mọi route của SaaS Admin nếu chưa đăng nhập hoặc không đủ quyền.
 * Trạng thái đăng nhập nằm trong session (không dùng bảng users cục bộ).
 *
 * KHÁC hẳn Shop Admin: bên đó cho cả `admin` và `staff` vào để làm đơn và kho.
 * Ở đây CHỈ `super_admin` — đây là khu điều hành nền tảng, người của cửa hàng
 * khách không có việc gì bên trong, kể cả chủ cửa hàng.
 *
 * Lưu ý khi làm multi-tenant: lúc đó `super_admin` phải mang nghĩa "quản trị
 * NỀN TẢNG" chứ không còn là "vai trò cao nhất trong một cửa hàng" như hiện tại.
 * Chừng nào chưa tách, danh sách dưới đây là điểm duy nhất cần sửa.
 */
class EnsurePlatformAuthenticated
{
    /** Vai trò được phép vào khu điều hành nền tảng. */
    protected array $allowedRoles = ['super_admin'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = session('api.access_token');
        $user = session('api.user');
        $role = $user ? data_get($user, 'role.name') : null;

        if (! $token || ! in_array($role, $this->allowedRoles, true)) {
            session()->forget('api');

            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'message' => 'Vui lòng đăng nhập bằng tài khoản quản trị nền tảng.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()
                ->route('login')
                ->with('error', 'Vui lòng đăng nhập bằng tài khoản quản trị nền tảng.');
        }

        return $next($request);
    }
}
