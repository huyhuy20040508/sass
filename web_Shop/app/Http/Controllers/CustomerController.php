<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    public const STATUSES = [
        'active' => 'Hoạt động',
        'inactive' => 'Không hoạt động',
    ];

    public const GENDERS = [
        'male' => 'Nam',
        'female' => 'Nữ',
        'other' => 'Khác',
    ];

    public const SORTS = [
        'newest' => 'Mới nhất',
        'oldest' => 'Cũ nhất',
        'name_asc' => 'Tên A → Z',
        'name_desc' => 'Tên Z → A',
        'spent_desc' => 'Chi tiêu nhiều nhất',
    ];

    public const PAGE_SIZES = [10, 20, 50, 100];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $customers = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->customers($filters);
            if ($res->successful()) {
                $customers = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load customers failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách khách hàng.';
            }
        } catch (\Throwable $e) {
            Log::error('Load customers failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách khách hàng. Kiểm tra kết nối API.';
        }

        $view = view('customers.index', [
            'customers' => $customers,
            'filters' => $filters,
            'meta' => $meta,
            'activeCustomers' => $this->activeCustomers(),
            'stats' => $this->stats(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    public function store(Request $request)
    {
        $payload = $this->validated($request);
        return $this->send(
            fn () => $this->api->createCustomer($payload),
            'Đã thêm khách hàng "'.$payload['full_name'].'".',
            $request
        );
    }

    public function detail(int $id)
    {
        $customer = $this->find($id);
        if ($customer === null) {
            return response()->json(['message' => 'Không tải được thông tin khách hàng.'], 404);
        }
        return response()->json(['data' => $customer]);
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'image' => ImageStore::rules(),
        ], ImageStore::messages());

        return response()->json(['url' => ImageStore::put($request->file('image'), 'customers')]);
    }

    public function importTemplate()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['full_name', 'email', 'phone', 'gender', 'date_of_birth', 'address', 'status', 'password']);
            fputcsv($out, ['Nguyễn Văn An', 'an.nguyen@gmail.com', '0901234567', 'male', '1995-04-12', 'Số 15 Lê Lợi, Quận 1, TP. Hồ Chí Minh', 'active', '']);
            fputcsv($out, ['Trần Thị Mai', 'mai.tran@gmail.com', '0988776655', 'female', '1998-08-23', '45 Nguyễn Trãi, Quận 5, TP. Hồ Chí Minh', 'inactive', 'Khach@2026']);
            fclose($out);
        }, 'mau-nhap-khach-hang.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'file.required' => 'Vui lòng chọn file CSV.',
            'file.mimes' => 'Chỉ chấp nhận file CSV.',
            'file.max' => 'File tối đa 5MB.',
        ]);

        $lines = file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return $this->backToList($request)->with('error', 'File rỗng hoặc không đọc được.');
        }

        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $idx = array_flip($header);
        $get = fn (array $row, string $key) => isset($idx[$key]) ? trim((string) ($row[$idx[$key]] ?? '')) : '';

        $ok = 0;
        $fail = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $i => $line) {
            $row = str_getcsv($line);
            $fullName = $get($row, 'full_name');
            $email = $get($row, 'email');

            if ($fullName === '' && $email === '') {
                $skipped++;
                continue;
            }
            if ($fullName === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $fail++;
                $errors[] = 'Dòng '.($i + 2).': thiếu họ tên hoặc email không hợp lệ.';
                continue;
            }

            $gender = $get($row, 'gender');
            $status = $get($row, 'status');
            $password = $get($row, 'password');

            $payload = [
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $get($row, 'phone'),
                'gender' => isset(self::GENDERS[$gender]) ? $gender : '',
                'date_of_birth' => $get($row, 'date_of_birth'),
                'address' => $get($row, 'address'),
                'status' => isset(self::STATUSES[$status]) ? $status : 'active',
            ];
            if ($password !== '') {
                $payload['password'] = $password;
            }

            try {
                $res = $this->api->createCustomer($payload);
                if ($res->successful()) {
                    $ok++;
                } else {
                    $fail++;
                    $errors[] = 'Dòng '.($i + 2).' ('.$email.'): '.($res->json('message') ?: 'không tạo được.');
                }
            } catch (\Throwable $e) {
                Log::warning('Import customer row failed', ['email' => $email, 'msg' => $e->getMessage()]);
                $fail++;
                $errors[] = 'Dòng '.($i + 2).' ('.$email.'): không kết nối được API.';
            }
        }

        $msg = "Đã nhập {$ok} khách hàng";
        if ($fail > 0) {
            $msg .= "; {$fail} dòng lỗi";
        }
        if ($skipped > 0) {
            $msg .= "; bỏ qua {$skipped} dòng trống";
        }
        $msg .= '.';

        $redirect = $this->backToList($request);

        if ($fail > 0) {
            $detail = implode(' ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $detail .= ' (…và '.(count($errors) - 5).' dòng khác)';
            }
            return $redirect->with($ok === 0 ? 'error' : 'success', $msg.' '.$detail);
        }

        return $redirect->with('success', $msg);
    }

    public function loginAccount(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|min:1',
            'password' => 'required|string|min:6|max:72|confirmed',
        ], [
            'customer_id.required' => 'Vui lòng chọn khách hàng cần cấp tài khoản.',
            'password.required' => 'Vui lòng nhập mật khẩu đăng nhập.',
            'password.min' => 'Mật khẩu phải có ít nhất 6 ký tự.',
            'password.confirmed' => 'Xác nhận mật khẩu chưa khớp.',
        ]);

        $id = (int) $validated['customer_id'];
        $customer = $this->find($id);

        // Fail-closed: không tra được khách (không tồn tại hoặc API lỗi tạm thời) thì
        // dừng ngay, tránh đặt mật khẩu cho tài khoản không xác định/không thể đăng nhập.
        if ($customer === null) {
            return $this->backToList($request)
                ->with('error', 'Không tải được thông tin khách hàng. Vui lòng thử lại.');
        }

        if (empty($customer['email'])) {
            return $this->backToList($request)
                ->with('error', 'Khách hàng này chưa có email nên không thể đăng nhập. Hãy bổ sung email trước.');
        }

        $label = $customer['full_name'] ?? ('#'.$id);
        $login = $customer['email'] ?? '';

        return $this->send(
            fn () => $this->api->setCustomerPassword($id, $validated['password']),
            'Đã cấp tài khoản đăng nhập cho "'.$label.'"'.($login !== '' ? ' ('.$login.')' : '').'.',
            $request
        );
    }

    public function update(Request $request, int $id)
    {
        $payload = $this->validated($request);
        return $this->send(
            fn () => $this->api->updateCustomer($id, $payload),
            'Đã cập nhật thông tin khách hàng.',
            $request
        );
    }

    public function toggleStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive',
        ], [
            'status.in' => 'Trạng thái tài khoản không hợp lệ.',
        ]);

        return $this->send(
            fn () => $this->api->updateCustomerStatus($id, $validated['status']),
            $validated['status'] === 'active' ? 'Đã bật tài khoản khách hàng.' : 'Đã tắt tài khoản khách hàng.',
            $request
        );
    }

    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->deleteCustomer($id),
            'Đã xoá khách hàng.',
            $request
        );
    }

    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->all();

        if (empty($ids)) {
            return $this->backToList($request)->with('error', 'Chưa chọn khách hàng nào để xoá.');
        }

        $ok = 0;
        $fail = 0;
        foreach ($ids as $id) {
            try {
                $this->api->deleteCustomer($id)->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::error('Bulk delete customer failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $fail++;
            }
        }

        $redirect = $this->backToList($request);

        if ($fail === 0) {
            return $redirect->with('success', "Đã xoá {$ok} khách hàng.");
        }

        return $ok > 0
            ? $redirect->with('error', "Đã xoá {$ok} khách hàng, {$fail} khách hàng lỗi.")
            : $redirect->with('error', 'Không xoá được khách hàng nào.');
    }

    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $customers = $this->fetchAll($filters);
        $fileName = 'danh-sach-khach-hang-'.date('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($customers) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, [
                'ID', 'Họ và tên', 'Email', 'Số điện thoại', 'Giới tính', 'Ngày sinh',
                'Địa chỉ', 'Trạng thái', 'Số đơn', 'Tổng chi tiêu', 'Ngày đăng ký',
            ]);

            foreach ($customers as $c) {
                fputcsv($file, [
                    $c['id'] ?? '',
                    $c['full_name'] ?? '',
                    $c['email'] ?? '',
                    $c['phone'] ?? '',
                    self::GENDERS[$c['gender'] ?? ''] ?? '',
                    $c['date_of_birth'] ?? '',
                    $c['address'] ?? '',
                    self::STATUSES[$c['status'] ?? ''] ?? '',
                    (int) ($c['total_orders'] ?? 0),
                    (float) ($c['total_spent'] ?? 0),
                    !empty($c['created_at']) ? \Illuminate\Support\Carbon::parse($c['created_at'])->format('d/m/Y H:i') : '',
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $gender = (string) $request->query('gender', 'all');
        $sort = (string) $request->query('sort', 'newest');
        $pageSize = (int) $request->query('page_size', 10);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'gender' => isset(self::GENDERS[$gender]) ? $gender : 'all',
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'newest',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 10,
        ];
    }

    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:150',
            'email' => 'required|email|max:191',
            'phone' => 'required|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'address' => 'nullable|string|max:255',
            'avatar' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
        ], [
            'full_name.required' => 'Vui lòng nhập họ và tên khách hàng.',
            'phone.required' => 'Vui lòng nhập số điện thoại.',
            'email.required' => 'Email là tên đăng nhập của khách nên bắt buộc phải nhập.',
            'email.email' => 'Địa chỉ email không hợp lệ.',
            'date_of_birth.date' => 'Ngày sinh không hợp lệ.',
            'status.required' => 'Vui lòng chọn trạng thái tài khoản.',
        ]);

        return [
            'full_name' => $validated['full_name'],
            'email' => (string) ($validated['email'] ?? ''),
            'phone' => $validated['phone'],
            'gender' => (string) ($validated['gender'] ?? ''),
            'date_of_birth' => (string) ($validated['date_of_birth'] ?? ''),
            'address' => (string) ($validated['address'] ?? ''),
            'avatar' => (string) ($validated['avatar'] ?? ''),
            'status' => $validated['status'],
        ];
    }

    protected function stats(): array
    {
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0];

        try {
            $res = $this->api->customerStats();
            if ($res->successful()) {
                $stats = array_merge($stats, $res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::info('Load customer stats failed', ['msg' => $e->getMessage()]);
        }

        return $stats;
    }

    protected function find(int $id): ?array
    {
        try {
            $res = $this->api->customer($id);
            if ($res->successful()) {
                return $res->json('data');
            }
        } catch (\Throwable $e) {
            Log::info('Load customer failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return null;
    }

    protected function activeCustomers(): array
    {
        $list = $this->fetchAll([
            'keyword' => '',
            'status' => 'active',
            'gender' => 'all',
            'sort' => 'name_asc',
        ]);

        return array_map(fn ($c) => [
            'id' => $c['id'] ?? 0,
            'full_name' => $c['full_name'] ?? '',
            'email' => $c['email'] ?? '',
            'phone' => $c['phone'] ?? '',
            'address' => $c['address'] ?? '',
            'gender' => $c['gender'] ?? '',
            'last_login_at' => $c['last_login_at'] ?? '',
        ], $list);
    }

    protected function fetchAll(array $filters): array
    {
        $all = [];
        $query = array_merge($filters, ['page' => 1, 'page_size' => 100]);
        $totalPages = 1;

        try {
            do {
                $res = $this->api->customers($query);
                if (! $res->successful()) {
                    break;
                }
                $all = array_merge($all, $res->json('data') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
                // Chặn trên 100 trang để tránh vòng lặp vô hạn nếu meta.total_pages sai/lớn
                // (nhất quán với OrderController::fetchAll và ProductController::export).
            } while ($query['page'] <= $totalPages && $query['page'] <= 100);
        } catch (\Throwable $e) {
            Log::error('Export customers failed', ['msg' => $e->getMessage()]);
        }

        return $all;
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Customer API call failed', ['msg' => $e->getMessage()]);
            return $this->backToList($request)->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return $this->backToList($request)->with('success', $success);
        }

        return $this->backToList($request)
            ->with('error', $res->json('message') ?: 'Thao tác không thành công.');
    }

    protected function backToList(Request $request)
    {
        $return = $request->input('return');
        if (is_string($return) && str_starts_with($return, '/')) {
            return redirect($return);
        }

        return redirect()->route('admin.customers.index', $request->query());
    }
}
