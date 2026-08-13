<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsurePlatformAuthenticated;
use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(protected ApiClient $api) {}

    /** Tên cookie ghi nhớ email đăng nhập. */
    protected const REMEMBER_COOKIE = 'saas_remember_email';

    /** Hiển thị form đăng nhập. */
    public function showLogin(Request $request)
    {
        if (session('api.access_token')) {
            return redirect()->route('platform.dashboard');
        }

        return view('auth.login', [
            'rememberedEmail' => $request->cookie(self::REMEMBER_COOKIE),
        ]);
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
        $role = data_get($data, 'user.role');

        // Vai trò ở đây là vai trò TRONG KHU ĐIỀU HÀNH (owner | operator |
        // support), một chuỗi chứ không phải đối tượng vai trò như bên cửa hàng.
        //
        // Trước đây chỗ này xét 'super_admin' — vai trò cao nhất trong MỘT cửa
        // hàng, mà tiệm nào cũng có một người như vậy. Nay API chỉ cấp token cho
        // tài khoản trong sổ `platform_users`, nên tài khoản cửa hàng đã bị chặn
        // từ trước khi tới đây. Dòng dưới là lưới thứ hai, phòng khi API trả về
        // một vai trò lạ.
        if (! in_array($role, EnsurePlatformAuthenticated::VAI_TRO, true)) {
            return back()->withInput($request->only('email'))
                ->with('error', 'Tài khoản này không có quyền vào khu điều hành nền tảng.');
        }

        $request->session()->regenerate();
        session([
            'api.access_token' => data_get($data, 'access_token'),
            'api.refresh_token' => data_get($data, 'refresh_token'),
            'api.user' => data_get($data, 'user'),
        ]);

        $redirect = redirect()->intended(route('platform.dashboard'))
            ->with('success', 'Đăng nhập thành công.');

        // Ghi nhớ email cho lần sau (30 ngày) hoặc xoá nếu bỏ chọn.
        if ($request->boolean('remember')) {
            $redirect->withCookie(cookie(self::REMEMBER_COOKIE, $credentials['email'], 60 * 24 * 30));
        } else {
            $redirect->withCookie(cookie()->forget(self::REMEMBER_COOKIE));
        }

        return $redirect;
    }

    /** Đăng xuất: xoá session. */
    public function logout(Request $request)
    {
        $request->session()->forget('api');
        $request->session()->regenerate();

        return redirect()->route('login')->with('success', 'Đã đăng xuất.');
    }
}
