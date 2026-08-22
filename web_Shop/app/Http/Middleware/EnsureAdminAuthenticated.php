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
     * `staff` (Thu ngân) vào được để đứng quầy — bán tại quầy, đơn hàng, ca làm
     * việc — nhưng KHÔNG vào được hàng hoá, kho, mua vào, người dùng, khách hàng,
     * báo cáo và cấu hình. Những trang đó gắn thêm middleware `admin.manage` (xem
     * EnsureManagerRole), và API cũng chặn ở tầng của nó.
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

            // LÝ DO THẬT nếu phiên vừa bị API từ chối (ApiClient ghi lại trước khi
            // xoá session). Hay gặp nhất: hợp đồng hết hạn nên cửa hàng bị khoá —
            // và câu chung "vui lòng đăng nhập bằng tài khoản quản trị" đẩy người
            // ta đi gõ lại mật khẩu cho một việc mật khẩu không chữa được.
            //
            // pull() chứ không get(): đọc một lần rồi bỏ. Để lại thì lần đăng nhập
            // hỏng tiếp theo — vì bất kỳ lý do gì — vẫn hiện câu của lần trước.
            $lyDo = trim((string) session()->pull('phien.ly_do_thoat', ''));
            $message = $lyDo !== '' ? $lyDo : 'Vui lòng đăng nhập bằng tài khoản quản trị.';

            // Nếu request mong đợi JSON (AJAX) -> trả về 401 JSON
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->route('login')->with('error', $message);
        }

        return $next($request);
    }
}
