<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Khách hàng — màn dựng theo khuôn v2 cũ (3rd/customer).
 *
 * Khác v2 ở gốc: bên mình khách hàng LÀ tài khoản đăng nhập (`users` vai
 * customer), không phải sổ đối tác `3rd_customers`. Nên loại Cá nhân/Doanh
 * nghiệp, nhóm khách, CCCD/MST, điểm, hạng và sổ nợ khách CHƯA có ở API —
 * màn bày đủ ô theo v2 nhưng mấy ô đó khoá lại, chờ nối.
 */
class CustomerController extends Controller
{
    use \App\Http\Controllers\Concerns\DialogReply;

    // Gọi đúng chữ trên nút ("Tạo"), không gọi tên khác — một hành động, một tên.
    public const EMPTY_TEXT = 'Chưa có khách hàng nào. Bấm "Tạo" để khai khách đầu tiên.';

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

    /**
     * Bốn cột sắp được của màn CRM (bấm tiêu đề cột) → khoá `sort` của API.
     * Tên cột giữ đúng `sort_by` của v2 để link cũ còn dùng được.
     */
    public const SAP_XEP_CRM = [
        'orders_count' => 'orders',
        'total_purchases' => 'spent',
        'total_paid' => 'paid',
        'total_debt' => 'debt',
    ];

    /** Mốc nhanh của hai khối "Sinh nhật gần nhất" và "Giao dịch lần cuối". */
    public const MOC_NGAY = [7 => '7 ngày', 14 => '14 ngày', 30 => '30 ngày'];

    // Năm mức của v2, cũng là mức mọi màn v2 khác bên mình đang dùng.
    public const PAGE_SIZES = [10, 20, 30, 40, 50];

    /**
     * Màn CRM → Khách hàng: cột TẮT SẴN lúc mở màn.
     *
     * Mười ba cột để một dòng (luật "không xuống dòng, không cắt chữ") cần
     * 1521px, trong khi khung khổ 1280 chỉ có 999px và khổ 1536 có 1212px —
     * tức là bày hết thì cột Hành động nằm ngoài màn ở mọi khổ dưới 1920.
     *
     * Ba cột tiền này tắt trước vì chúng là số liệu TRA CỨU SÂU, xem trong hộp
     * chi tiết của khách là đủ; còn "Tổng mua hàng" giữ lại vì đó là con số
     * chủ tiệm nhìn để xếp hạng khách. Bật lại bất cứ lúc nào ở menu chọn cột.
     */
    public const COT_CRM_MAC_DINH_TAT = ['total_paid', 'still_in_debt', 'last_payment'];

    /**
     * Bề rộng tối thiểu từng cột (px) — ĐO THẬT ở cỡ chữ đầy đủ 13,5px.
     *
     * Dùng cho hai việc, nên không được lệch nhau: tính `min-width` của bảng
     * theo đúng cột đang bật, và suy ra % của từng cột (px chia tổng). Nhờ vậy
     * ở bề rộng nhỏ nhất mà bảng nhận, mỗi cột vẫn đủ chỗ cho chữ dài nhất —
     * không ô nào phải xuống dòng hay bị cắt.
     */
    public const COT_CRM_RONG_TOI_THIEU = [
        '_tick' => 36, '_stt' => 50,
        'code' => 128, 'name' => 132, 'phone' => 113, 'address' => 192, 'type' => 137,
        'orders_count' => 82, 'total_purchases' => 142, 'total_paid' => 170,
        'still_in_debt' => 83, 'last_payment' => 141, 'action' => 115,
    ];

    public function __construct(protected ApiClient $api) {}

    /**
     * Danh sách khách hàng — khuôn `3rd/customer` của v2, nằm ở module Thống kê.
     *
     * Bốn khối lọc (Tìm kiếm · Điểm · Loại khách hàng · Cấp độ thành viên) và
     * bảng 11 cột. Lọc + cắt trang do API lo, trừ ba ô chưa có dữ liệu để đối
     * chiếu thì chặn ngay tại đây.
     */
    public function index(Request $request)
    {
        return $this->danhSach($request, false);
    }

    /**
     * CRM → Danh sách khách hàng — khuôn `crm/customers` của v2.
     *
     * Cùng dữ liệu và cùng các nút Thêm/Sửa/Xoá với màn Thống kê → Khách hàng
     * (v2 cũng cho hai màn dùng chung một endpoint); khác ở khung lọc chín khối
     * và bảng hướng về chăm sóc khách: số đơn, lần thanh toán gần nhất, sắp xếp
     * theo tiền.
     */
    public function crm(Request $request)
    {
        return $this->danhSach($request, true);
    }

    protected function danhSach(Request $request, bool $laCrm)
    {
        $filters = $this->filters($request);
        $customers = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        // Loại khách: mọi khách đang là Cá nhân — bỏ tick "Cá nhân" chỉ có thể ra
        // bảng RỖNG, trả rỗng ngay. Điểm và hạng lọc thật ở API (thẻ thành viên).
        $khongTheKhop = ! in_array('0', $filters['type'], true);

        if (! $khongTheKhop) {
            try {
                $res = $this->api->customers($this->apiQuery($filters));
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
        }

        // Màn đã chuyển sang khu v2; view cũ ở resources/views/customers giữ lại
        // phòng khi cần đối chiếu, không còn route nào trỏ vào.
        $view = view($laCrm ? 'v2::crm.customers.index' : 'v2::customers.index', [
            'list' => array_map([$this, 'veKieuXem'], $customers),
            'filters' => $filters,
            'meta' => $meta,
            // Hai danh sách riêng: nhóm cá nhân và nhóm doanh nghiệp.
            'nhomCaNhan' => $this->nhomKhach(0),
            'nhomDoanhNghiep' => $this->nhomKhach(1),
            'ranks' => $this->hangThanhVien(),
            'laCrm' => $laCrm,
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Bổ sung mấy trường màn v2 cần mà API chưa trả.
     *
     * Không bịa số: cái gì chưa có sổ thì để 0 / rỗng chứ không suy ra từ trường
     * khác. Nối API xong thì xoá dần từng dòng ở đây.
     */
    protected function veKieuXem(array $c): array
    {
        // Từ migration 0061, API trả thẳng mã / loại / nhóm / đã trả / còn nợ.
        // Chỗ này chỉ còn đổi tên khoá cho khớp bảng, và đỡ cho dòng dữ liệu cũ
        // chưa kịp có mã (khách tạo trước lượt migration).
        $c['code'] = $c['customer_code']
            ?: 'cus-'.str_pad((string) ($c['id'] ?? 0), 5, '0', STR_PAD_LEFT);
        $c['type'] = (int) ($c['customer_type'] ?? 0);
        $c['group_name'] = (string) ($c['customer_group_name'] ?? '');
        $c['note'] = (string) ($c['customer_note'] ?? '');
        // Ô CCCD và Mã số thuế dùng chung một chỗ trên hộp Thêm/Sửa: khách cá
        // nhân thì là CCCD, doanh nghiệp thì là MST — API giữ riêng hai cột.
        $c['cccd'] = (string) ($c['citizen_id'] ?? '');
        $c['total_paid'] = (float) ($c['total_paid'] ?? 0);
        $c['still_in_debt'] = (float) ($c['still_in_debt'] ?? 0);
        // Thẻ thành viên (migration 0072): điểm còn dùng được và hạng hiện tại.
        $c['remaining_score'] = (int) ($c['points'] ?? 0);
        $c['total_score'] = (int) ($c['total_points'] ?? 0);
        $c['rank_name'] = (string) ($c['rank_name'] ?? '');

        return $c;
    }

    /**
     * Nhóm khách hàng cho ô chọn ở hộp Thêm/Sửa.
     *
     * $loai: 0 nhóm cá nhân · 1 nhóm doanh nghiệp — HAI DANH SÁCH TÁCH HẲN, ô
     * "Nhóm doanh nghiệp" không bày ra "Khách vãng lai" nữa (migration 0063).
     *
     * Hỏng thì trả rỗng — ô chọn trống và màn vẫn mở được, chứ không phải cả
     * trang khách hàng chết theo một bảng tra.
     */
    protected function nhomKhach(int $loai): array
    {
        try {
            $res = $this->api->nhomKhachHang(true, $loai);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::info('Load customer groups failed', ['loai' => $loai, 'msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Nút "+" cạnh ô Nhóm: khai nhanh một nhóm rồi chọn luôn. */
    public function taoNhom(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'type' => 'nullable|in:0,1',
        ], [
            'name.required' => 'Vui lòng nhập tên nhóm.',
        ]);

        $loai = (int) ($validated['type'] ?? 0);

        return $this->send(
            fn () => $this->api->taoNhomKhachHang([
                'name' => $validated['name'],
                'type' => $loai,
            ]),
            'Đã thêm '.($loai === 1 ? 'nhóm doanh nghiệp' : 'nhóm khách hàng').' "'.$validated['name'].'".',
            $request
        );
    }

    /**
     * Tab "Lịch sử giao dịch" của hộp Chi tiết — sổ đơn hàng của đúng khách này.
     *
     * Đọc thẳng /admin/orders với `user_id`; lọc và phân trang do API lo.
     */
    public function donHang(Request $request, int $id)
    {
        $pageSize = (int) $request->query('page_size', 10);
        $query = [
            'user_id' => $id,
            'keyword' => trim((string) $request->query('keyword', '')),
            'channel' => (string) $request->query('channel', ''),
            'from_date' => $this->veISO((string) $request->query('from_date', '')),
            'to_date' => $this->veISO((string) $request->query('to_date', '')),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 10,
        ];

        try {
            $res = $this->api->orders(array_filter($query, fn ($v) => $v !== '' && $v !== null));
        } catch (\Throwable $e) {
            Log::error('Load customer orders failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API đơn hàng.'], 503);
        }

        if (! $res->successful()) {
            return response()->json(
                ['message' => $res->json('message') ?: 'Không đọc được sổ đơn hàng.'],
                $res->status()
            );
        }

        // Đơn chỉ mang `shop_id`; đổi sang TÊN chi nhánh ở đây, không thì cột chi
        // nhánh hiện ra một con số chẳng nói lên gì. "Người tạo" thì đành để trống:
        // bảng orders chưa ghi ai lập đơn.
        $tenChiNhanh = $this->tenChiNhanh();
        $rows = array_map(function (array $o) use ($tenChiNhanh) {
            $o['shop_name'] = $tenChiNhanh[(int) ($o['shop_id'] ?? 0)] ?? '';
            $o['created_by_name'] = '';

            return $o;
        }, $res->json('data') ?? []);

        return response()->json([
            'data' => $rows,
            'meta' => $res->json('meta') ?? ['page' => 1, 'page_size' => $query['page_size'], 'total_pages' => 1],
        ]);
    }

    /** id chi nhánh -> tên, để bảng lịch sử khỏi in ra con số. */
    protected function tenChiNhanh(): array
    {
        try {
            $res = $this->api->chiNhanh();
            if ($res->successful()) {
                return collect($res->json('data') ?? [])
                    ->mapWithKeys(fn ($cn) => [(int) ($cn['id'] ?? 0) => (string) ($cn['name'] ?? '')])
                    ->all();
            }
        } catch (\Throwable $e) {
            Log::info('Load branches for customer history failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /** Ô ngày của v2 gõ DD-MM-YYYY, API nhận YYYY-MM-DD. */
    protected function veISO(string $ngay): string
    {
        return preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $ngay, $m) ? $m[3].'-'.$m[2].'-'.$m[1] : '';
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
        $sort = (string) $request->query('sort', 'newest');
        $pageSize = (int) $request->query('page_size', 10);

        // Loại khách: v2 tick sẵn cả hai, bỏ tick cả hai là bảng rỗng.
        $type = array_values(array_intersect(
            array_map('strval', (array) $request->query('type', ['0', '1'])),
            ['0', '1']
        ));

        $so = fn (string $ten) => trim((string) $request->query($ten, ''));
        // Ngày nhận đúng dạng Y-m-d (ô ẩn của lịch gửi lên), còn lại coi như trống.
        $ngay = fn (string $ten) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $so($ten)) ? $so($ten) : '';

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'newest',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 10,

            // Bốn khối lọc của khuôn v2. Điểm và hạng chưa có dữ liệu để đối
            // chiếu; giữ trong URL để ô lọc còn nhớ lựa chọn sau mỗi lượt nạp lại.
            'type' => $type,
            'point_from' => $so('point_from'),
            'point_to' => $so('point_to'),
            'rank' => $so('rank'),

            // Khung lọc của màn CRM. Màn Thống kê không gửi các ô này nên chúng
            // rỗng và không cắt gì.
            'gender' => $request->has('gender')
                ? array_values(array_intersect(array_map('strval', (array) $request->query('gender')), array_keys(self::GENDERS)))
                : array_keys(self::GENDERS),
            'created_from' => $ngay('created_from'),
            'created_to' => $ngay('created_to'),
            'address' => $so('address'),
            'age_from' => ctype_digit($so('age_from')) ? $so('age_from') : '',
            'age_to' => ctype_digit($so('age_to')) ? $so('age_to') : '',
            'birthday_mode' => $request->query('birthday_mode') === 'custom' ? 'custom' : 'preset',
            'birthday_preset' => isset(self::MOC_NGAY[(int) $so('birthday_preset')]) ? $so('birthday_preset') : '',
            'birthday_from' => $ngay('birthday_from'),
            'birthday_to' => $ngay('birthday_to'),
            'last_tx_mode' => $request->query('last_tx_mode') === 'custom' ? 'custom' : 'preset',
            'last_tx_preset' => isset(self::MOC_NGAY[(int) $so('last_tx_preset')]) ? $so('last_tx_preset') : '',
            'last_tx_from' => $ngay('last_tx_from'),
            'last_tx_to' => $ngay('last_tx_to'),
            'sort_by' => isset(self::SAP_XEP_CRM[$so('sort_by')]) ? $so('sort_by') : '',
            'sort_dir' => $so('sort_dir') === 'asc' ? 'asc' : 'desc',
        ];
    }

    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:150',
            // Email và SĐT KHÔNG bắt buộc từ 07/09/2026: khách hàng bỏ chức năng
            // đăng nhập storefront nên email thôi là tên đăng nhập, chỉ còn là ô
            // liên lạc như SĐT. Bắt buộc mỗi họ tên, đúng như bản v2.
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'address' => 'nullable|string|max:255',
            'avatar' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',

            // Hồ sơ khách theo khuôn v2 (migration 0061).
            'customer_type' => 'nullable|in:0,1',
            'customer_group_id' => 'nullable|integer|min:0',
            'tax_code' => 'nullable|string|max:20',
            'citizen_id' => 'nullable|string|max:12',
            'representative_name' => 'nullable|string|max:150',
            'representative_phone' => 'nullable|string|max:20',
            'customer_note' => 'nullable|string|max:500',
        ], [
            'full_name.required' => 'Vui lòng nhập họ và tên khách hàng.',
            'email.email' => 'Địa chỉ email không hợp lệ.',
            'date_of_birth.date' => 'Ngày sinh không hợp lệ.',
            'status.required' => 'Vui lòng chọn trạng thái tài khoản.',
            'citizen_id.max' => 'CCCD tối đa 12 số.',
            'tax_code.max' => 'Mã số thuế tối đa 20 ký tự.',
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

            // Mã KHÔNG gửi lên: API tự sinh lúc tạo và giữ nguyên lúc sửa. Ô mã
            // trên hộp Thêm/Sửa vẫn khoá, đúng như bản v2.
            'customer_type' => (int) ($validated['customer_type'] ?? 0),
            'customer_group_id' => (int) ($validated['customer_group_id'] ?? 0),
            'tax_code' => (string) ($validated['tax_code'] ?? ''),
            'citizen_id' => (string) ($validated['citizen_id'] ?? ''),
            'representative_name' => (string) ($validated['representative_name'] ?? ''),
            'representative_phone' => (string) ($validated['representative_phone'] ?? ''),
            'customer_note' => (string) ($validated['customer_note'] ?? ''),
        ];
    }

    /** Chỉ mấy tham số API thật sự hiểu — bỏ ba ô lọc của khuôn v2 còn chờ nối. */
    /** Hạng thành viên cho ô lọc "Hạng thành viên". Hỏng thì chỉ còn "Tất cả". */
    protected function hangThanhVien(): array
    {
        try {
            $res = $this->api->theThanhVien();

            return $res->successful() ? ($res->json('data.ranks') ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function apiQuery(array $filters): array
    {
        $q = array_intersect_key($filters, array_flip([
            'keyword', 'status', 'sort', 'page', 'page_size',
        ]));

        // Loại khách gửi dạng CHUỖI "0,1" chứ không phải mảng: Laravel dựng
        // query mảng thành `type[0]=0&type[1]=1`, mà bên Go đọc theo tên khoá
        // nên không nhặt được. Chuỗi rỗng (`types=`) vẫn khác với không gửi gì:
        // đó là "bỏ tick cả hai loại" → API trả bảng rỗng.
        if (isset($filters['type'])) {
            $q['types'] = implode(',', $filters['type']);
        }

        // ----- Màn CRM -----
        // Giới tính: tick đủ ba là không cắt; bỏ tick hết là bảng rỗng (`genders=`).
        if (isset($filters['gender']) && count($filters['gender']) < count(self::GENDERS)) {
            $q['genders'] = implode(',', $filters['gender']);
        }
        if (($filters['rank'] ?? '') !== '') {
            $q['rank_id'] = $filters['rank'];
        }
        foreach (['created_from', 'created_to', 'address', 'age_from', 'age_to', 'point_from', 'point_to'] as $k) {
            if (($filters[$k] ?? '') !== '') {
                $q[$k] = $filters[$k];
            }
        }
        // Hai khối có hai lối (mốc nhanh / tự chọn): chỉ gửi lối đang bật.
        foreach (['birthday' => 'birthday_days', 'last_tx' => 'last_tx_days'] as $khoi => $moc) {
            if (($filters[$khoi.'_mode'] ?? '') === 'custom') {
                if (($filters[$khoi.'_from'] ?? '') !== '' && ($filters[$khoi.'_to'] ?? '') !== '') {
                    $q[$khoi.'_from'] = $filters[$khoi.'_from'];
                    $q[$khoi.'_to'] = $filters[$khoi.'_to'];
                }
            } elseif (($filters[$khoi.'_preset'] ?? '') !== '') {
                $q[$moc] = $filters[$khoi.'_preset'];
            }
        }
        if (($filters['sort_by'] ?? '') !== '') {
            $q['sort'] = self::SAP_XEP_CRM[$filters['sort_by']].'_'.$filters['sort_dir'];
        }

        return $q;
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
        $query = array_merge($this->apiQuery($filters), ['page' => 1, 'page_size' => 100]);
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

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        // Hộp thoại gọi bằng AJAX thì nhận {success, message} để LƯU HỎNG LÀ GIỮ
        // HỘP LẠI; form gửi thẳng vẫn đi đường chuyển hướng như cũ.
        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $success, fn () => $this->backToList($request))
            : $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác không thành công.'), null, $res->status());
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
