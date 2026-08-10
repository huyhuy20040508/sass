<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(protected ApiClient $api) {}

    /** Tên cookie ghi nhớ email đăng nhập. */
    protected const REMEMBER_COOKIE = 'remember_email';

    /** Hiển thị form đăng nhập. */
    public function showLogin(Request $request)
    {
        if (session('api.access_token')) {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.login', [
            'rememberedEmail' => $request->cookie(self::REMEMBER_COOKIE),
        ]);
    }

    /** Trang quên mật khẩu (API chưa hỗ trợ đặt lại — hướng dẫn liên hệ). */
    public function forgotPassword()
    {
        return view('auth.forgot-password');
    }

    /** Xử lý đăng nhập: gọi Go API, kiểm tra quyền, lưu session. */
    public function login(Request $request)
    {
        $credentials = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không đúng định dạng.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
        ])->stopOnFirstFailure()->validate();

        try {
            $res = $this->api->login($credentials['email'], $credentials['password']);
        } catch (\Throwable $e) {
            Log::error('API login failed', ['msg' => $e->getMessage()]);

            return back()->withInput($request->only('email'))
                ->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            $message = $res->json('message') ?: 'Email hoặc mật khẩu không đúng.';

            return back()->withInput($request->only('email'))->with('error', $message);
        }

        $data = $res->json('data');
        $role = data_get($data, 'user.role.name');

        // Nhân viên (staff) vào được để làm đơn hàng và kho; các trang quản lý người
        // và cấu hình chặn riêng bằng middleware `admin.manage`. Khách hàng thì không
        // vào trang quản trị bằng bất kỳ đường nào.
        if (! in_array($role, ['super_admin', 'admin', 'staff'], true)) {
            return back()->withInput($request->only('email'))
                ->with('error', 'Tài khoản này không có quyền truy cập trang quản trị.');
        }

        $request->session()->regenerate();
        session([
            'api.access_token' => data_get($data, 'access_token'),
            'api.refresh_token' => data_get($data, 'refresh_token'),
            'api.user' => data_get($data, 'user'),
        ]);

        $redirect = redirect()->intended(route('admin.dashboard'))
            ->with('success', 'Đăng nhập thành công.');

        // Ghi nhớ email cho lần sau (30 ngày) hoặc xóa nếu bỏ chọn.
        if ($request->boolean('remember')) {
            $redirect->withCookie(cookie(self::REMEMBER_COOKIE, $credentials['email'], 60 * 24 * 30));
        } else {
            $redirect->withCookie(cookie()->forget(self::REMEMBER_COOKIE));
        }

        return $redirect;
    }

    /** Đăng xuất: xóa session. */
    public function logout(Request $request)
    {
        $request->session()->forget('api');
        $request->session()->regenerate();

        return redirect()->route('login')->with('success', 'Đã đăng xuất.');
    }
}

