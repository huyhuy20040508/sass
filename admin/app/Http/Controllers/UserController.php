<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Người dùng & vai trò — tài khoản NỘI BỘ của cửa hàng (quản trị + nhân viên).
 *
 * Khách hàng KHÔNG nằm ở đây: họ có trang riêng (CustomerController) và API cũng
 * chặn hẳn — id trỏ vào một khách hàng thì /admin/users trả 404.
 *
 * Phần lớn chốt chặn nằm ở API (không tự khoá mình, không hạ vai trò super admin
 * cuối cùng, quản trị viên thường không đụng được super admin). Trang này lặp lại
 * các quy tắc đó ở mức hiển thị để nói trước lý do, chứ không thay thế API: mọi
 * lần bấm vẫn được server kiểm lại.
 */
class UserController extends Controller
{
    public const TITLE = 'Người dùng & vai trò';

    public const EMPTY_TEXT = 'Chưa có tài khoản nội bộ nào ngoài tài khoản bạn đang dùng. Bấm "Thêm người dùng" để cấp tài khoản cho nhân viên.';

    public const STATUSES = [
        'active' => 'Đang hoạt động',
        'inactive' => 'Đã khoá',
    ];

    public const SORTS = [
        'newest' => 'Mới nhất',
        'oldest' => 'Cũ nhất',
        'name_asc' => 'Tên A → Z',
        'name_desc' => 'Tên Z → A',
    ];

    public const PAGE_SIZES = [20, 50, 100];

    /** Vai trò khách hàng — không bao giờ gán được ở trang này. */
    public const CUSTOMER_ROLE_ID = 4;

    public const SUPER_ADMIN_ROLE_ID = 1;

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách tài khoản nội bộ + bảng vai trò. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $users = [];
        $meta = ['page' => 1, 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];

        try {
            $res = $this->api->users($this->query($filters));
            if ($res->successful()) {
                $users = $res->json('data') ?? [];
                $meta = $res->json('meta') ?: $meta;
            } else {
                Log::warning('Load users failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách người dùng.';
            }
        } catch (\Throwable $e) {
            Log::error('Load users failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách người dùng. Kiểm tra kết nối API.';
        }

        $view = view('settings.users', [
            'users' => $users,
            'roles' => $this->roles(),
            'filters' => $filters,
            'stats' => $this->stats(),
            'meta' => $meta,
            'me' => $this->me(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Thêm tài khoản nội bộ. */
    public function store(Request $request)
    {
        $data = $this->validated($request, creating: true);

        return $this->send(
            fn () => $this->api->createUser($data),
            'Đã thêm người dùng "'.$data['full_name'].'".',
            $request
        );
    }

    /** Sửa tài khoản nội bộ. */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request, creating: false);

        return $this->send(
            fn () => $this->api->updateUser($id, $data),
            'Đã cập nhật người dùng "'.$data['full_name'].'".',
            $request
        );
    }

    /** Khoá / mở khoá nhanh một tài khoản. */
    public function updateStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ], [
            'status.required' => 'Thiếu trạng thái cần đặt.',
            'status.in' => 'Trạng thái không hợp lệ.',
        ]);

        return $this->send(
            fn () => $this->api->updateUserStatus($id, $validated['status']),
            $validated['status'] === 'active'
                ? 'Đã mở khoá tài khoản.'
                : 'Đã khoá tài khoản — người này không đăng nhập được nữa.',
            $request
        );
    }

    /** Đặt lại mật khẩu đăng nhập trang quản trị. */
    public function setPassword(Request $request, int $id)
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:6', 'max:72'],
        ], [
            'password.required' => 'Vui lòng nhập mật khẩu mới.',
            'password.min' => 'Mật khẩu tối thiểu 6 ký tự.',
            'password.max' => 'Mật khẩu tối đa 72 ký tự.',
        ]);

        return $this->send(
            fn () => $this->api->setUserPassword($id, $validated['password']),
            'Đã đặt lại mật khẩu. Hãy chuyển mật khẩu mới cho người dùng qua kênh riêng.',
            $request
        );
    }

    /** Xoá mềm một tài khoản. */
    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->deleteUser($id),
            'Đã xoá tài khoản.',
            $request
        );
    }

    /**
     * Xoá nhiều tài khoản đã chọn.
     *
     * API kiểm từng tài khoản một (tự xoá mình, super admin cuối cùng, quyền của
     * người thao tác) nên ở đây gọi lần lượt và đếm kết quả — không có endpoint
     * xoá hàng loạt, và cũng không nên có: bỏ qua chốt chặn theo lô là cách dễ
     * nhất để xoá nhầm cả người đang đăng nhập.
     */
    public function bulkDestroy(Request $request)
    {
        $ids = $this->ids($request);
        if ($ids === []) {
            return $this->backToList($request)->with('error', 'Chưa chọn tài khoản nào để xoá.');
        }

        $ok = 0;
        $failures = [];
        foreach ($ids as $id) {
            try {
                $res = $this->api->deleteUser($id);
                if ($res->successful()) {
                    $ok++;
                } else {
                    $failures[] = $res->json('message') ?: 'tài khoản #'.$id.' không xoá được';
                }
            } catch (\Throwable $e) {
                Log::error('Bulk delete user failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $failures[] = 'tài khoản #'.$id.' lỗi kết nối';
            }
        }

        return $this->summarize($request, $ok, $failures, 'Đã xoá %d tài khoản.');
    }

    /** Khoá / mở khoá nhiều tài khoản đã chọn. */
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ], [
            'status.required' => 'Thiếu trạng thái cần đặt.',
            'status.in' => 'Trạng thái không hợp lệ.',
        ]);

        $ids = $this->ids($request);
        if ($ids === []) {
            return $this->backToList($request)->with('error', 'Chưa chọn tài khoản nào.');
        }

        $ok = 0;
        $failures = [];
        foreach ($ids as $id) {
            try {
                $res = $this->api->updateUserStatus($id, $validated['status']);
                if ($res->successful()) {
                    $ok++;
                } else {
                    $failures[] = $res->json('message') ?: 'tài khoản #'.$id.' không đổi được';
                }
            } catch (\Throwable $e) {
                Log::error('Bulk user status failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $failures[] = 'tài khoản #'.$id.' lỗi kết nối';
            }
        }

        return $this->summarize(
            $request,
            $ok,
            $failures,
            $validated['status'] === 'active' ? 'Đã mở khoá %d tài khoản.' : 'Đã khoá %d tài khoản.'
        );
    }

    /** Sửa tên hiển thị & mô tả của một vai trò. */
    public function updateRole(Request $request, int $id)
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'display_name.required' => 'Vui lòng nhập tên hiển thị của vai trò.',
            'display_name.max' => 'Tên hiển thị tối đa 100 ký tự.',
            'description.max' => 'Mô tả tối đa 255 ký tự.',
        ]);

        return $this->send(
            fn () => $this->api->updateRole($id, [
                'display_name' => $validated['display_name'],
                'description' => (string) ($validated['description'] ?? ''),
            ]),
            'Đã cập nhật vai trò "'.$validated['display_name'].'".',
            $request
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'newest');
        $pageSize = (int) $request->query('page_size', 20);
        $roleId = (int) $request->query('role_id', 0);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'role_id' => $roleId > 0 && $roleId !== self::CUSTOMER_ROLE_ID ? $roleId : 0,
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'from_date' => $this->date($request->query('from_date')),
            'to_date' => $this->date($request->query('to_date')),
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'newest',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 20,
        ];
    }

    /** Chỉ nhận chuỗi ngày đúng dạng YYYY-MM-DD, còn lại coi như không lọc. */
    protected function date($value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /** Bộ lọc -> query gửi API (bỏ những khoá rỗng cho URL gọn). */
    protected function query(array $filters): array
    {
        return array_filter([
            'keyword' => $filters['keyword'],
            'role_id' => $filters['role_id'] ?: null,
            'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
            'from_date' => $filters['from_date'] ?: null,
            'to_date' => $filters['to_date'] ?: null,
            'sort' => $filters['sort'],
            'page' => $filters['page'],
            'page_size' => $filters['page_size'],
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected function stats(): array
    {
        $empty = ['total' => 0, 'active' => 0, 'inactive' => 0];

        try {
            $res = $this->api->userStats();
            if ($res->successful()) {
                return array_merge($empty, $res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::error('Load user stats failed', ['msg' => $e->getMessage()]);
        }

        return $empty;
    }

    /** Danh sách vai trò kèm số tài khoản đang mang vai trò đó. */
    protected function roles(): array
    {
        try {
            $res = $this->api->roles();
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Load roles failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /** Tài khoản đang đăng nhập — view cần để không mời bạn tự khoá chính mình. */
    protected function me(): array
    {
        $user = session('api.user') ?: [];

        return [
            'id' => (int) data_get($user, 'id', 0),
            'role' => (string) data_get($user, 'role.name', ''),
        ];
    }

    protected function validated(Request $request, bool $creating): array
    {
        $rules = [
            'full_name' => ['required', 'string', 'max:150'],
            // Tên đăng nhập: ô thứ hai của màn hình đăng nhập. Bộ ký tự khớp với
            // usernameRe bên Go — kiểm ở đây chỉ để người dùng thấy lỗi ngay tại
            // form thay vì chờ API trả về.
            //
            // CHO PHÉP chữ hoa rồi hạ xuống bên dưới, đúng như Go làm: bàn phím
            // điện thoại tự viết hoa chữ đầu, từ chối thẳng là bắt người ta sửa
            // một thứ mà hệ thống thừa sức tự xử lý.
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20'],
            'avatar' => ['nullable', 'string', 'max:255'],
            'role_id' => ['required', 'integer', 'in:'.implode(',', [1, 2, 3])],
            'status' => ['required', 'in:active,inactive'],
        ];
        if ($creating) {
            $rules['password'] = ['nullable', 'string', 'min:6', 'max:72'];
        }

        $validated = $request->validate($rules, [
            'full_name.required' => 'Vui lòng nhập họ tên.',
            'full_name.max' => 'Họ tên tối đa 150 ký tự.',
            'username.required' => 'Vui lòng nhập tên đăng nhập.',
            'username.min' => 'Tên đăng nhập tối thiểu 3 ký tự.',
            'username.max' => 'Tên đăng nhập tối đa 50 ký tự.',
            'username.regex' => 'Tên đăng nhập chỉ gồm chữ không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (không khoảng trắng).',
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không đúng định dạng.',
            'phone.max' => 'Số điện thoại tối đa 20 ký tự.',
            'role_id.required' => 'Vui lòng chọn vai trò.',
            'role_id.in' => 'Vai trò không hợp lệ — trang này chỉ cấp tài khoản nội bộ.',
            'status.required' => 'Vui lòng chọn trạng thái.',
            'password.min' => 'Mật khẩu tối thiểu 6 ký tự.',
        ]);

        $data = [
            'full_name' => $validated['full_name'],
            // Hạ chữ thường ngay tại đây cho khớp cách Go chuẩn hoá: gửi 'Admin'
            // rồi lưu thành 'admin' thì người tạo tài khoản đưa nhân viên một tên
            // đăng nhập khác với tên thật sự nằm dưới CSDL.
            'username' => mb_strtolower(trim($validated['username'])),
            'email' => $validated['email'],
            'phone' => (string) ($validated['phone'] ?? ''),
            'avatar' => (string) ($validated['avatar'] ?? ''),
            'role_id' => (int) $validated['role_id'],
            'status' => $validated['status'],
        ];

        // Mật khẩu chỉ gửi khi tạo mới và có nhập. Sửa tài khoản thì không đụng
        // tới mật khẩu — đổi mật khẩu là thao tác riêng, có xác nhận riêng.
        if ($creating && filled($validated['password'] ?? null)) {
            $data['password'] = $validated['password'];
        }

        return $data;
    }

    protected function ids(Request $request): array
    {
        return collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Gộp kết quả của một loạt lời gọi thành MỘT thông báo.
     *
     * Nêu đích danh lý do của những cái hỏng (API trả câu lỗi rõ ràng: tự xoá
     * mình, super admin cuối cùng…) thay vì chỉ đếm số — biết "2 cái lỗi" mà
     * không biết vì sao thì không sửa được gì.
     */
    protected function summarize(Request $request, int $ok, array $failures, string $successFormat)
    {
        $redirect = $this->backToList($request);

        if ($failures === []) {
            return $redirect->with('success', sprintf($successFormat, $ok));
        }

        $reasons = collect($failures)->unique()->take(3)->implode('; ');
        $more = count($failures) > 3 ? ' (và '.(count($failures) - 3).' trường hợp khác)' : '';
        $done = $ok > 0 ? sprintf($successFormat, $ok).' ' : '';

        return $redirect->with($ok > 0 ? 'success' : 'error', $done.'Bỏ qua: '.$reasons.$more.'.');
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('User API call failed', ['msg' => $e->getMessage()]);

            return $this->backToList($request)->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return $this->backToList($request)->with('success', $success);
        }

        return $this->backToList($request)
            ->withInput()
            ->with('error', $res->json('message') ?: 'Thao tác không thành công.');
    }

    protected function backToList(Request $request)
    {
        $return = $request->input('return');
        if (is_string($return) && str_starts_with($return, '/')) {
            return redirect($return);
        }

        return redirect()->route('admin.users.index', $request->query());
    }
}
