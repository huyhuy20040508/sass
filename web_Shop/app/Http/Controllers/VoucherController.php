<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Quản lý voucher — dựng theo đúng khuôn các trang danh sách khác:
 * [ header + thẻ số liệu ] + [ thanh lọc realtime ] + [ bảng ] + [ chân trang ] + modal CRUD.
 *
 * Bốn điểm riêng của module này, đều là chỗ người bán hay hiểu nhầm:
 *
 * 1. KHÁC KHUYẾN MÃI Ở CHỖ NÀO. Chương trình khuyến mãi giảm TỰ ĐỘNG trên TỪNG
 *    SẢN PHẨM; voucher là mã khách phải TỰ GÕ lúc thanh toán và giảm trên TỔNG
 *    ĐƠN. Hai thứ chạy song song và cộng được với nhau, nên trang này nói thẳng
 *    điều đó ra thay vì để người bán tự đoán.
 *
 * 2. TRẠNG THÁI CÓ NĂM NHÓM, KHÔNG PHẢI BẬT/TẮT. Một mã bật sẵn nhưng chưa tới
 *    ngày thì chưa ai dùng được; một mã còn hạn nhưng đã phát hết lượt cũng vậy —
 *    mà cách sửa hai trường hợp đó hoàn toàn khác nhau (dời ngày vs nâng lượt).
 *    Trạng thái do API tính, trang này chỉ hiển thị.
 *
 * 3. THỜI HẠN ĐƯỢC PHÉP BỎ TRỐNG. Để trống ngày bắt đầu = dùng được ngay, để
 *    trống ngày kết thúc = dùng mãi tới khi tắt tay. Khác chương trình khuyến mãi
 *    vốn bắt buộc cả hai mốc.
 *
 * 4. SỐ LƯỢT ĐÃ DÙNG KHÔNG SỬA ĐƯỢC. Nó do đơn hàng ghi ra. Biểu mẫu chỉ đặt được
 *    TỔNG lượt, và API chặn hạ tổng xuống dưới số đã phát.
 */
class VoucherController extends Controller
{
    public const TITLE = 'Voucher';

    /** Trạng thái thực tế, khớp `voucherStatus` bên API. */
    public const STATUSES = [
        'running' => 'Đang phát',
        'scheduled' => 'Chờ tới ngày',
        'ended' => 'Đã hết hạn',
        'used_up' => 'Hết lượt',
        'paused' => 'Tạm dừng',
    ];

    public const DISCOUNT_TYPES = [
        'percentage' => 'Giảm theo %',
        'fixed' => 'Giảm số tiền',
    ];

    public const SORTS = [
        'newest' => 'Mới nhất',
        'oldest' => 'Cũ nhất',
        'code_asc' => 'Mã A → Z',
        'used_desc' => 'Dùng nhiều nhất',
        'end_asc' => 'Sắp hết hạn nhất',
    ];

    public const PAGE_SIZES = [10, 20, 50, 100];

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách voucher. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $vouchers = [];
        $meta = ['page' => 1, 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $stats = ['total' => 0] + array_fill_keys(array_keys(self::STATUSES), 0);

        try {
            $res = $this->api->vouchers($this->query($filters));
            if ($res->successful()) {
                $vouchers = $res->json('data') ?? [];
                $meta = $res->json('meta') ?: $meta;
            } else {
                Log::warning('Load vouchers failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách voucher.';
            }

            $statRes = $this->api->voucherStats();
            if ($statRes->successful()) {
                $stats = array_merge($stats, $statRes->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::error('Load vouchers failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách voucher. Kiểm tra kết nối API.';
        }

        $view = view('vouchers.index', [
            'vouchers' => $vouchers,
            'filters' => $filters,
            'stats' => $stats,
            'meta' => $meta,
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    public function store(Request $request)
    {
        return $this->send(
            fn () => $this->api->createVoucher($this->validated($request)),
            'Đã tạo voucher.',
            $request
        );
    }

    public function update(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->updateVoucher($id, $this->validated($request)),
            'Đã cập nhật voucher.',
            $request
        );
    }

    /** Bật/tắt — ngừng phát một mã mà không phải sửa ngày kết thúc. */
    public function toggleStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ], [
            'is_active.required' => 'Thiếu trạng thái cần đặt.',
        ]);

        $isActive = (bool) $validated['is_active'];

        return $this->send(
            fn () => $this->api->updateVoucherStatus($id, $isActive),
            $isActive ? 'Đã bật voucher.' : 'Đã tạm dừng voucher.',
            $request
        );
    }

    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->deleteVoucher($id),
            'Đã xoá voucher.',
            $request
        );
    }

    /** Xoá nhiều voucher đã chọn. */
    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->all();

        if (empty($ids)) {
            return $this->backToList($request)->with('error', 'Chưa chọn voucher nào để xoá.');
        }

        $ok = 0;
        $fail = 0;
        foreach ($ids as $id) {
            try {
                $this->api->deleteVoucher($id)->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::error('Bulk delete voucher failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $fail++;
            }
        }

        $redirect = $this->backToList($request);

        if ($fail === 0) {
            return $redirect->with('success', "Đã xoá {$ok} voucher.");
        }

        return $redirect->with(
            $ok > 0 ? 'success' : 'error',
            $ok > 0 ? "Đã xoá {$ok} voucher, {$fail} voucher lỗi." : "Không xoá được voucher nào ({$fail} lỗi)."
        );
    }

    /** Xuất danh sách theo đúng bộ lọc đang xem. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $rows = [];

        try {
            // page_size lớn để lấy trọn bộ lọc hiện tại, không phải chỉ trang đang xem:
            // xuất file mà chỉ được 20 dòng đầu là cái bẫy im lặng.
            $res = $this->api->vouchers($this->query($filters) + ['page' => 1, 'page_size' => 100]);
            if ($res->successful()) {
                $rows = $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Export vouchers failed', ['msg' => $e->getMessage()]);
        }

        $fileName = 'voucher-'.date('Ymd-His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, [
                'ID', 'Mã', 'Mô tả', 'Kiểu giảm', 'Mức giảm', 'Trần giảm', 'Đơn tối thiểu',
                'Tổng lượt', 'Lượt mỗi khách', 'Đã dùng', 'Còn lại', 'Bắt đầu', 'Kết thúc', 'Trạng thái', 'Cách phát',
            ]);

            foreach ($rows as $v) {
                fputcsv($file, [
                    $v['id'] ?? '',
                    $v['code'] ?? '',
                    $v['description'] ?? '',
                    self::DISCOUNT_TYPES[$v['discount_type'] ?? ''] ?? '',
                    $this->discountLabel($v),
                    $v['max_discount_amount'] ?? '',
                    $v['min_order_amount'] ?? 0,
                    // Không giới hạn thì ghi chữ, đừng ghi ô trống — người mở file
                    // không đoán được ô trống nghĩa là "vô hạn" hay "quên nhập".
                    $v['usage_limit'] ?? 'Không giới hạn',
                    $v['usage_limit_per_user'] ?? 'Không giới hạn',
                    $v['used_count'] ?? 0,
                    $v['remaining'] ?? 'Không giới hạn',
                    $this->formatDate($v['start_at'] ?? null) ?: 'Không giới hạn',
                    $this->formatDate($v['end_at'] ?? null) ?: 'Không giới hạn',
                    self::STATUSES[$v['status'] ?? ''] ?? '',
                    ($v['is_public'] ?? false) ? 'Công khai' : 'Gửi tay',
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
        $type = (string) $request->query('type', 'all');
        $sort = (string) $request->query('sort', 'newest');
        $pageSize = (int) $request->query('page_size', 20);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'type' => isset(self::DISCOUNT_TYPES[$type]) ? $type : 'all',
            'from_date' => $this->cleanDate($request->query('from_date')),
            'to_date' => $this->cleanDate($request->query('to_date')),
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'newest',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 20,
        ];
    }

    /** Bộ lọc → query gửi sang API (bỏ những khoá rỗng cho URL gọn). */
    protected function query(array $filters): array
    {
        return array_filter([
            'keyword' => $filters['keyword'],
            'status' => $filters['status'] !== 'all' ? $filters['status'] : '',
            'type' => $filters['type'] !== 'all' ? $filters['type'] : '',
            'from_date' => $filters['from_date'],
            'to_date' => $filters['to_date'],
            'sort' => $filters['sort'],
            'page' => $filters['page'],
            'page_size' => $filters['page_size'],
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /** Dữ liệu voucher từ modal thêm/sửa. */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            // Cùng bộ ký tự mà API chấp nhận — chặn tại đây để lỗi hiện ngay dưới ô
            // đang gõ thay vì một dòng đỏ chung chung ở đầu trang.
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]{3,50}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', 'in:'.implode(',', array_keys(self::DISCOUNT_TYPES))],
            'discount_value' => ['required', 'numeric', 'gt:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'gt:0'],
            'min_order_amount' => ['nullable', 'numeric', 'gte:0'],
            'usage_limit' => ['nullable', 'integer', 'gt:0'],
            'usage_limit_per_user' => ['nullable', 'integer', 'gt:0'],
            'start_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            // "after:start_at" chỉ gắn khi THẬT SỰ có ngày bắt đầu: Laravel không đọc
            // được trường tham chiếu rỗng thì nó đem so với chính chuỗi "start_at",
            // và mọi voucher chỉ khai ngày kết thúc đều bị chặn oan.
            'end_at' => array_values(array_filter([
                'nullable',
                'date_format:Y-m-d\TH:i',
                $request->filled('start_at') ? 'after:start_at' : null,
            ])),
            'is_active' => ['nullable'],
            'is_public' => ['nullable'],
        ], [
            'code.required' => 'Vui lòng nhập mã voucher.',
            'code.max' => 'Mã voucher tối đa 50 ký tự.',
            'code.regex' => 'Mã chỉ gồm chữ không dấu, số, gạch ngang hoặc gạch dưới (3–50 ký tự).',
            'discount_type.required' => 'Vui lòng chọn kiểu giảm giá.',
            'discount_value.required' => 'Vui lòng nhập mức giảm.',
            'discount_value.gt' => 'Mức giảm phải lớn hơn 0.',
            'max_discount_amount.gt' => 'Trần giảm phải lớn hơn 0.',
            'min_order_amount.gte' => 'Đơn tối thiểu không được là số âm.',
            'usage_limit.gt' => 'Tổng lượt dùng phải lớn hơn 0.',
            'usage_limit_per_user.gt' => 'Số lượt mỗi khách phải lớn hơn 0.',
            'start_at.date_format' => 'Thời gian bắt đầu không hợp lệ.',
            'end_at.date_format' => 'Thời gian kết thúc không hợp lệ.',
            'end_at.after' => 'Thời gian kết thúc phải sau thời gian bắt đầu.',
        ]);

        $percent = $validated['discount_type'] === 'percentage';

        if ($percent && (float) $validated['discount_value'] > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Phần trăm giảm phải trong khoảng 1–100.',
            ]);
        }

        // Số nguyên dương hoặc null. Ô để trống nghĩa là KHÔNG GIỚI HẠN, nên phải
        // gửi đúng null — gửi 0 thì API hiểu là "0 lượt" và mã chết ngay.
        $limit = function (?string $key) use ($validated) {
            $v = $validated[$key] ?? null;

            return $v === null || $v === '' ? null : (int) $v;
        };

        return [
            'code' => strtoupper(trim($validated['code'])),
            'description' => (string) ($validated['description'] ?? ''),
            'discount_type' => $validated['discount_type'],
            'discount_value' => (float) $validated['discount_value'],
            // Trần giảm chỉ có nghĩa khi giảm theo %. Gửi kèm ở kiểu "giảm số tiền"
            // là để lại một con số nằm im trong database rồi có người tưởng nó đang
            // có tác dụng.
            'max_discount_amount' => $percent && ($validated['max_discount_amount'] ?? null) !== null
                ? (float) $validated['max_discount_amount']
                : null,
            'min_order_amount' => (float) ($validated['min_order_amount'] ?? 0),
            'usage_limit' => $limit('usage_limit'),
            'usage_limit_per_user' => $limit('usage_limit_per_user'),
            'start_at' => (string) ($validated['start_at'] ?? ''),
            'end_at' => (string) ($validated['end_at'] ?? ''),
            'is_active' => $request->boolean('is_active'),
            'is_public' => $request->boolean('is_public'),
        ];
    }

    /** Mức giảm dạng chữ, dùng cho file xuất ra. */
    protected function discountLabel(array $v): string
    {
        $value = (float) ($v['discount_value'] ?? 0);

        return ($v['discount_type'] ?? '') === 'percentage'
            ? rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',').'%'
            : number_format($value, 0, ',', '.').'đ';
    }

    protected function cleanDate($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function formatDate(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Voucher API call failed', ['msg' => $e->getMessage()]);

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

        return redirect()->route('admin.vouchers.index', $request->query());
    }
}
