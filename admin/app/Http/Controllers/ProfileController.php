<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tài khoản của tôi — hồ sơ và mật khẩu của CHÍNH người đang đăng nhập.
 *
 * Khác UserController ở người bị tác động: trang kia là quản trị viên sửa tài
 * khoản NGƯỜI KHÁC (và bị chặn bằng middleware `admin.manage`), trang này ai đăng
 * nhập được vào trang quản trị cũng vào được — kể cả nhân viên. Không có nó thì
 * nhân viên không có đường tự đổi mật khẩu, phải nhờ quản trị viên đặt hộ rồi đọc
 * mật khẩu mới qua tin nhắn.
 *
 * Vai trò và trạng thái KHÔNG nằm trên trang này: tự nâng quyền hay tự khoá mình
 * đã bị API chặn, mở ô cho bấm rồi nhận lỗi thì chỉ tổ gây hiểu lầm.
 */
class ProfileController extends Controller
{
    public const TITLE = 'Tài khoản của tôi';

    public function __construct(protected ApiClient $api) {}

    /** Trang hồ sơ + đổi mật khẩu. */
    public function edit()
    {
        $error = null;
        $profile = [];

        try {
            $res = $this->api->profile();
            if ($res->successful()) {
                $profile = $res->json('data') ?? [];
            } else {
                Log::warning('Load profile failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được hồ sơ tài khoản.';
            }
        } catch (\Throwable $e) {
            Log::error('Load profile failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được hồ sơ tài khoản. Kiểm tra kết nối API.';
        }

        // Không đọc được hồ sơ thì lấy tạm phần đang giữ trong session để trang vẫn
        // hiện đúng người đang đăng nhập, thay vì một form trắng không rõ của ai.
        if ($profile === []) {
            $u = session('api.user') ?: [];
            $profile = [
                'full_name' => (string) data_get($u, 'full_name', ''),
                'email' => (string) data_get($u, 'email', ''),
                'phone' => (string) data_get($u, 'phone', ''),
                'avatar' => (string) data_get($u, 'avatar', ''),
                'role_display_name' => (string) data_get($u, 'role.display_name', data_get($u, 'role.name', '')),
            ];
        }

        $view = view('settings.profile', ['profile' => $profile]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Lưu họ tên và số điện thoại. */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            // Ảnh đại diện chưa có ô nhập trên trang này, nhưng API ghi đè trường
            // avatar theo đúng những gì nhận được — không gửi kèm giá trị đang có
            // thì mỗi lần đổi tên là mất ảnh. Ô ẩn trong form giữ lại giá trị đó.
            'avatar' => ['nullable', 'string', 'max:255'],
        ], [
            'full_name.required' => 'Vui lòng nhập họ tên.',
            'full_name.max' => 'Họ tên tối đa 150 ký tự.',
            'phone.max' => 'Số điện thoại tối đa 20 ký tự.',
        ]);

        $payload = [
            'full_name' => $validated['full_name'],
            'phone' => (string) ($validated['phone'] ?? ''),
            'avatar' => (string) ($validated['avatar'] ?? ''),
        ];

        try {
            $res = $this->api->updateProfile($payload);
        } catch (\Throwable $e) {
            Log::error('Update profile failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            $this->syncSession($res->json('data') ?? []);

            return redirect()->route('admin.profile.edit')->with('success', 'Đã lưu hồ sơ.');
        }

        return $this->fail($res, ['full_name', 'phone'], 'Lưu hồ sơ không thành công.');
    }

    /** Đổi mật khẩu đăng nhập của chính mình. */
    public function password(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            // confirmed đối chiếu với ô new_password_confirmation: gõ nhầm mật khẩu
            // mới mà không có ô nhắc lại thì lần đăng nhập sau mới phát hiện, lúc đó
            // đã không còn đường vào để sửa.
            'new_password' => ['required', 'string', 'min:6', 'max:72', 'confirmed'],
        ], [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
            'new_password.required' => 'Vui lòng nhập mật khẩu mới.',
            'new_password.min' => 'Mật khẩu mới tối thiểu 6 ký tự.',
            'new_password.max' => 'Mật khẩu mới tối đa 72 ký tự.',
            'new_password.confirmed' => 'Hai lần nhập mật khẩu mới không khớp.',
        ]);

        try {
            $res = $this->api->changePassword(
                $request->input('current_password'),
                $request->input('new_password')
            );
        } catch (\Throwable $e) {
            Log::error('Change password failed', ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            // Phiên hiện tại chạy bằng token đã cấp nên KHÔNG bị đăng xuất; nói rõ
            // để người dùng không tưởng là chưa ăn thua vì vẫn đang ở trong trang.
            return redirect()->route('admin.profile.edit')
                ->with('success', 'Đã đổi mật khẩu. Lần đăng nhập sau hãy dùng mật khẩu mới.');
        }

        return $this->fail($res, ['current_password', 'new_password'], 'Đổi mật khẩu không thành công.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Trả lỗi về đúng ô nhập.
     *
     * API trả 422 kèm map khoá → câu lỗi (mật khẩu hiện tại sai, mật khẩu mới trùng
     * cái đang dùng). Gắn về đúng trường thay vì đổ một câu chung ở đầu trang, người
     * dùng mới biết gõ lại ô nào. $fields lọc bớt khoá lạ để lỗi của form này không
     * hiện nhầm sang form kia trên cùng trang.
     */
    protected function fail($res, array $fields, string $fallback)
    {
        $errors = $res->json('errors');

        if ($res->status() === 422 && is_array($errors) && $errors !== []) {
            $bag = [];
            foreach ($errors as $key => $message) {
                // API trả hai kiểu khoá: lỗi nghiệp vụ dùng tên trường JSON
                // (`current_password`), còn lỗi ràng buộc của validator lại dùng tên
                // field trong struct Go (`NewPassword`). Chuẩn hoá về snake_case để
                // cả hai cùng gắn được vào ô nhập.
                $key = Str::snake((string) $key);
                if (! in_array($key, $fields, true)) {
                    continue;
                }
                $bag[$key] = [is_array($message) ? reset($message) : (string) $message];
            }

            if ($bag !== []) {
                return back()->withInput()->withErrors($bag);
            }
        }

        return back()->withInput()->with('error', $res->json('message') ?: $fallback);
    }

    /**
     * Cập nhật bản sao tài khoản trong session.
     *
     * Topbar lấy tên từ session chứ không gọi API mỗi lần dựng trang; không đồng bộ
     * thì đổi tên xong vẫn thấy tên cũ cho tới lần đăng nhập sau. Chỉ ghi đè những
     * trường vừa sửa — vai trò trong session là cấu trúc lồng (`role.name`), khác
     * dạng phẳng của response, đụng vào là hỏng phần phân quyền hiển thị.
     */
    protected function syncSession(array $data): void
    {
        $user = session('api.user') ?: [];
        foreach (['full_name', 'phone', 'avatar'] as $key) {
            if (array_key_exists($key, $data)) {
                $user[$key] = $data[$key];
            }
        }
        session(['api.user' => $user]);
    }
}
