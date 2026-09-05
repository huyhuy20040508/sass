<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ChiNhanhDangLam;
use App\Services\HanSuDung;
use App\Services\ModuleLamViec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(protected ApiClient $api) {}

    /**
     * Cookie ghi nhớ hai ô đầu của màn hình đăng nhập.
     *
     * KHÔNG bao giờ ghi mật khẩu vào đây. Mã cửa hàng và tên đăng nhập thì ghi
     * được: nhân viên gõ đúng hai chuỗi đó mỗi ca làm, mà biết chúng cũng chưa
     * vào được gì.
     */
    protected const REMEMBER_SHOP_COOKIE = 'remember_shop_code';

    protected const REMEMBER_USER_COOKIE = 'remember_username';

    /** Số ngày giữ cookie ghi nhớ. */
    protected const REMEMBER_DAYS = 30;

    /** Hiển thị form đăng nhập. */
    public function showLogin(Request $request)
    {
        if (session('api.access_token')) {
            return redirect()->to(ModuleLamViec::trangChuCuaPhien());
        }

        return view('auth.login', [
            'rememberedShopCode' => $request->cookie(self::REMEMBER_SHOP_COOKIE),
            'rememberedUsername' => $request->cookie(self::REMEMBER_USER_COOKIE),
        ]);
    }

    /** Trang quên mật khẩu (API chưa hỗ trợ đặt lại — hướng dẫn liên hệ). */
    public function forgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Xử lý đăng nhập 3 ô: gọi Go API, kiểm tra quyền, lưu session.
     *
     * Ba ô là mã cửa hàng · tên đăng nhập · mật khẩu. Không dùng email nữa: nhân
     * viên bán hàng phần lớn không có email, mà tên đăng nhập chỉ cần duy nhất
     * trong một cửa hàng nên chủ shop nào cũng đặt được 'admin' cho mình.
     */
    public function login(Request $request)
    {
        $credentials = Validator::make($request->all(), [
            'shop_code' => ['required', 'string', 'max:30'],
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ], [
            'shop_code.required' => 'Vui lòng nhập mã cửa hàng.',
            'shop_code.max' => 'Mã cửa hàng tối đa 30 ký tự.',
            'username.required' => 'Vui lòng nhập tên đăng nhập.',
            'username.max' => 'Tên đăng nhập tối đa 50 ký tự.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
        ])->stopOnFirstFailure()->validate();

        // Giữ lại hai ô đầu khi trả về form — bắt gõ lại mã cửa hàng chỉ vì sai
        // mật khẩu là hành người dùng.
        $keep = $request->only('shop_code', 'username');

        try {
            $res = $this->api->shopLogin(
                $credentials['shop_code'],
                $credentials['username'],
                $credentials['password'],
            );
        } catch (\Throwable $e) {
            Log::error('API login failed', ['msg' => $e->getMessage()]);

            return back()->withInput($keep)
                ->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            $message = $res->json('message') ?: 'Mã cửa hàng, tên đăng nhập hoặc mật khẩu không đúng.';

            return back()->withInput($keep)->with('error', $message);
        }

        $data = $res->json('data');
        $role = data_get($data, 'user.role.name');

        // Nhân viên (staff) vào được để làm đơn hàng và kho; các trang quản lý người
        // và cấu hình chặn riêng bằng middleware `admin.manage`. Khách hàng thì không
        // vào trang quản trị bằng bất kỳ đường nào (API cũng đã chặn sẵn).
        if (! in_array($role, ['super_admin', 'admin', 'staff'], true)) {
            return back()->withInput($keep)
                ->with('error', 'Tài khoản này không có quyền truy cập trang quản trị.');
        }

        $request->session()->regenerate();
        session([
            'api.access_token' => data_get($data, 'access_token'),
            'api.refresh_token' => data_get($data, 'refresh_token'),
            'api.user' => data_get($data, 'user'),
            // Cửa hàng vừa đăng nhập. Chỉ có ở lần đăng nhập này — /auth/refresh
            // không trả lại, nên cứ giữ nguyên tới lúc đăng xuất.
            'api.tenant' => data_get($data, 'tenant'),
        ]);

        // CỬA HÀNG ĐÃ HẾT HẠN HỢP ĐỒNG.
        //
        // API vẫn cấp phiên cho quản trị viên (nhân viên thì bị từ chối ngay ở
        // trên), nhưng phiên đó chỉ đọc được đúng trang Các gói dịch vụ. Cất cờ
        // vào session để middleware `admin.khoa` dồn mọi đường về trang đó —
        // xem KhoaKhiHetHan.
        //
        // Ghi cả khi FALSE: khách vừa gia hạn xong đăng nhập lại phải rũ được cờ
        // cũ, không thì họ trả tiền rồi vẫn bị giam trong trang gói dịch vụ.
        // Bắt đầu lại từ đầu: phiên trước trên cùng máy có thể để lại mốc cũ của
        // một cửa hàng khác.
        HanSuDung::quen();
        $khoa = (bool) data_get($data, 'cua_hang_khoa', false);
        session([HanSuDung::KHOA_CO => $khoa]);

        if ($khoa) {
            return redirect()->route('admin.goi-dich-vu.index');
        }

        // GHIM CHI NHÁNH LÀM VIỆC NGAY TỪ ĐÂY. Trước đây phiên mở ra không có
        // chi nhánh nào cho tới khi người dùng tự bấm ô chọn ở thanh trên cùng,
        // và trong khoảng đó mọi lượt ghi kho rơi vào một chi nhánh do API đoán.
        // `chi_nhanh_id` là chi nhánh hồ sơ nhân sự của người này được phân về.
        ChiNhanhDangLam::datLucDangNhap(
            ($cn = data_get($data, 'chi_nhanh_id')) === null ? null : (int) $cn
        );

        // ĐI ĐÂU TIẾP: hỏi ModuleLamViec, đừng đoán ở đây.
        //
        // Người được giao CẢ HAI khu dừng ở màn chọn cửa vào — họ đăng nhập lúc 7h
        // sáng có thể là để mở ca bán hàng, không phải để xem báo cáo, mà luật cũ
        // luôn thả họ vào khu quản trị rồi bắt tự tìm nút đổi module ở góc phải.
        // Người chỉ có MỘT khu thì vào thẳng, không thấy màn chọn: một màn hình chỉ
        // có đúng một ô để bấm là lấy lại đúng cái click mà lần tách module bỏ đi.
        $redirect = redirect()->intended(ModuleLamViec::sauKhiDangNhap())
            ->with('success', 'Đăng nhập thành công.');

        // Ghi nhớ mã cửa hàng + tên đăng nhập cho lần sau, hoặc xoá nếu bỏ chọn.
        $minutes = 60 * 24 * self::REMEMBER_DAYS;
        if ($request->boolean('remember')) {
            $redirect->withCookie(cookie(self::REMEMBER_SHOP_COOKIE, $credentials['shop_code'], $minutes))
                ->withCookie(cookie(self::REMEMBER_USER_COOKIE, $credentials['username'], $minutes));
        } else {
            $redirect->withCookie(cookie()->forget(self::REMEMBER_SHOP_COOKIE))
                ->withCookie(cookie()->forget(self::REMEMBER_USER_COOKIE));
        }

        return $redirect;
    }

    /** Đăng xuất: xóa session. */
    public function logout(Request $request)
    {
        $request->session()->forget('api');
        // Cờ khoá và mốc hết hạn nằm NGOÀI khoá 'api' (để sống sót qua lượt forget
        // của ApiClient), nên phải xoá riêng — bỏ sót thì người đăng nhập sau trên
        // cùng máy vẫn bị giam trong trang gói dịch vụ.
        HanSuDung::quen();
        $request->session()->regenerate();

        return redirect()->route('login')->with('success', 'Đã đăng xuất.');
    }
}
