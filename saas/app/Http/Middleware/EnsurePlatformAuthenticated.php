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
 * Ở đây là ba vai trò của KHU ĐIỀU HÀNH — người của cửa hàng khách không có
 * việc gì bên trong, kể cả chủ cửa hàng.
 *
 * VAI TRÒ Ở ĐÂY LÀ CHUỖI, không phải đối tượng vai trò như bên cửa hàng: khu
 * điều hành không có bảng RBAC riêng, `platform_users.role` là một ENUM ba giá
 * trị. Trước đây chỗ này xét 'super_admin' — vai trò cao nhất trong MỘT cửa
 * hàng, mà tiệm nào cũng có một người như vậy, nên nó chưa bao giờ trả lời được
 * câu "ai là người của nền tảng".
 *
 * Đây là lưới THỨ HAI. Lưới thứ nhất nằm ở Go API: token của khu điều hành chỉ
 * cấp cho tài khoản trong sổ `platform_users`, và mọi request của nhóm
 * /platform đều tra lại sổ đó. Session bên này chỉ quyết định hiện hay không
 * hiện màn hình.
 */
class EnsurePlatformAuthenticated
{
    /** Vai trò được phép vào khu điều hành nền tảng — khớp ENUM của API. */
    public const VAI_TRO = ['owner', 'operator', 'support'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = session('api.access_token');
        $user = session('api.user');
        $role = $user ? data_get($user, 'role') : null;

        if (! $token || ! in_array($role, self::VAI_TRO, true)) {
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
