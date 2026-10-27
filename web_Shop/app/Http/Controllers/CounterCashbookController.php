<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiJsonReply;
use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Thu chi ở quầy — bản gọn của Quản trị → Thu chi cho module Thu ngân.
 *
 * Chỉ phiếu MÌNH lập tay ở chi nhánh đang làm; chưa mở ca thì không lập / sửa /
 * xoá được. Cả hai luật do API (/admin/quay/thu-chi) ép, màn này chỉ hiện ra.
 * Tồn quỹ đầu/cuối kỳ, lọc người tạo / nguồn, xuất Excel để lại màn quản trị.
 */
class CounterCashbookController extends Controller
{
    use ApiJsonReply;

    public const TITLE = 'Thu chi';

    public const KY = [
        'ca' => 'Ca này',
        'hom-nay' => 'Hôm nay',
        'thang-nay' => 'Tháng này',
        'tuy-chon' => 'Tuỳ chọn',
    ];

    public const LOAI = ['' => 'Tất cả', '0' => 'Phiếu thu', '1' => 'Phiếu chi'];

    public const PHUONG_THUC = CashbookController::PHUONG_THUC;

    /** Nhà cung cấp không có ở quầy — trả tiền NCC làm ở Mua hàng (bản cũ quầy cũng không có). */
    public const DOI_TUONG = ['quan_ly', 'thu_ngan', 'other'];

    public const PAGE_SIZE = 50;

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $ca = $this->doc(fn () => $this->api->caHienTai(), null);
        $f = $this->filters($request, $ca);

        $query = ['page' => $f['page'], 'page_size' => self::PAGE_SIZE];
        if ($f['q'] !== '') {
            // Có từ khoá thì tìm mọi ngày — như Lịch sử đơn.
            $query['q'] = $f['q'];
        } elseif ($f['ky'] === 'ca') {
            $query['shift_id'] = (int) $ca['id'];
            if ($f['pham_vi'] === 'ca') {
                $query['pham_vi'] = 'ca';
            }
        } else {
            $query += ['from_date' => $f['from_date'], 'to_date' => $f['to_date']];
        }

        $rows = [];
        $meta = [];
        $tong = [];
        $error = null;
        try {
            $res = $this->api->thuChiQuay($query + array_filter(['type' => $f['type']], fn ($v) => $v !== ''));
            if ($res->successful()) {
                $rows = $res->json('data') ?? [];
                $meta = $res->json('meta') ?? [];
            } else {
                $error = $res->json('message') ?: 'Không tải được phiếu thu chi.';
            }
            // Tab và thẻ tổng đếm trên CẢ kỳ, không theo tab đang chọn.
            $tong = $f['type'] === '' ? $meta : ($this->api->thuChiQuay(['page_size' => 1] + $query)->json('meta') ?? []);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai thu chi that bai', ['msg' => $e->getMessage()]);
            $error = 'Không tải được phiếu thu chi. Kiểm tra kết nối API.';
        }

        $coTheLap = (bool) ($meta['co_the_lap'] ?? false);
        $danhMuc = $coTheLap && $ca ? ($this->doc(fn () => $this->api->danhMucThuChiQuay(), []) ?? []) : [];
        $ketTheoSo = $ca ? (float) ($ca['opening_cash'] ?? 0) + (float) ($ca['tong_thu'] ?? 0) - (float) ($ca['tong_chi'] ?? 0) : null;

        // Hạn mức phiếu chi ở quầy: màn báo trước; chủ / quản trị không bị hỏi (API cũng thế).
        $hanMucChi = $coTheLap ? $this->api->settingInt('pos_cashbook_expense_limit', 0) : 0;
        $laChu = in_array(data_get(session('api.user'), 'role.name'), ['admin', 'super_admin'], true);

        $view = view('v2::pos.cashbook', compact('rows', 'meta', 'tong', 'f', 'ca', 'coTheLap', 'danhMuc', 'ketTheoSo', 'hanMucChi', 'laChu'));

        return $error ? $view->with('error', $error) : $view;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->jsonTuApi(fn () => $this->api->taoThuChiQuay($data), 'Không lập được phiếu.');
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validated($request) + ['reason' => $this->lyDo($request, 'sửa')];

        return $this->jsonTuApi(fn () => $this->api->suaThuChiQuay($id, $data), 'Không lưu được phiếu.');
    }

    public function destroy(Request $request, int $id)
    {
        $lyDo = $this->lyDo($request, 'xoá');

        return $this->jsonTuApi(fn () => $this->api->xoaThuChiQuay($id, $lyDo), 'Không xoá được phiếu.');
    }

    /** JSON: nhật ký sửa / xoá của một phiếu (cùng phạm vi đọc của quầy). */
    public function nhatKy(int $id)
    {
        return $this->jsonTuApi(fn () => $this->api->nhatKyThuChiQuay($id), 'Không tải được nhật ký.');
    }

    /** Lý do sửa / xoá phiếu — bắt buộc, vào nhật ký (API kiểm lại). */
    protected function lyDo(Request $request, string $viec): string
    {
        return trim((string) $request->validate(
            ['reason' => ['required', 'string', 'max:200']],
            ['reason.required' => 'Ghi lý do '.$viec.' phiếu.', 'reason.max' => 'Lý do tối đa 200 ký tự.']
        )['reason']);
    }

    /**
     * Biên nhận khổ in nhiệt của một phiếu (?paper=58 | 80, mặc định 80 như phiếu kết ca).
     * ?nhung=1: màn quầy nạp vào khung ẩn rồi tự in — trang không tự in, không vẽ thanh nút.
     */
    public function bienNhan(Request $request, int $id)
    {
        // Debugbar (bản dev) chèn thanh công cụ cuối trang thì Chrome in thêm một tờ thừa.
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        try {
            $res = $this->api->thuChiQuayMot($id);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai phieu thu chi de in that bai', ['id' => $id, 'msg' => $e->getMessage()]);
            abort(404);
        }
        abort_unless($res->successful(), 404);
        $cn = \App\Services\CurrentBranch::danhSach();

        return view('v2::pos.cashbook-receipt', [
            'phieu' => $res->json('data') ?? [],
            'tenCuaHang' => $this->api->settingString('site_name', config('app.name')),
            'diaChi' => $this->api->settingString('store_address'),
            'tenChiNhanh' => (string) (collect($cn['ds'])->firstWhere('id', $cn['dangChon'])['name'] ?? ''),
            'khoGiay' => $request->query('paper') === '58' ? '58' : '80',
            'tuIn' => ! $request->boolean('nhung'),
        ]);
    }

    public function taoNguoiNop(Request $request)
    {
        $du = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ], [
            'name.required' => 'Nhập tên người nộp / người nhận.',
            'name.max' => 'Tên dài quá 255 ký tự.',
            'phone.max' => 'Số điện thoại dài quá 20 ký tự.',
        ]);

        return $this->jsonTuApi(fn () => $this->api->taoNguoiNopThuChiQuay([
            'name' => trim($du['name']),
            'phone' => trim((string) ($du['phone'] ?? '')),
        ]), 'Không thêm được người nộp.');
    }

    /** Đường của quản trị (admin.cashbook.dinhKem) nằm sau cửa Quản lý — quầy cần đường riêng. */
    public function dinhKem(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:'.CashbookController::DINH_KEM_MIMES, 'max:'.ImageStore::MAX_UPLOAD_KB],
        ], [
            'file.required' => 'Chưa chọn tệp nào.',
            'file.mimes' => 'Chỉ nhận ảnh (JPG, PNG, WEBP, GIF, AVIF) hoặc PDF.',
            'file.max' => 'Tệp lớn quá '.(int) (ImageStore::MAX_UPLOAD_KB / 1024).'MB.',
        ]);

        return response()->json(['url' => ImageStore::put($request->file('file'), 'cashbook')]);
    }

    protected function validated(Request $request): array
    {
        $du = $request->validate([
            'type' => ['required', 'in:0,1'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            // Nộp về công ty không phải chi phí — không có phân loại.
            'kind' => ['nullable', 'in:deposit'],
            'category_id' => ['required_unless:kind,deposit', 'nullable', 'integer', 'min:1'],
            'payer_type' => ['nullable', 'in:'.implode(',', self::DOI_TUONG)],
            'payer_id' => ['nullable', 'integer'],
            'payment_method' => ['required', 'in:'.implode(',', array_keys(self::PHUONG_THUC))],
            'attachment' => ['nullable', 'string', 'max:2048'],
            // Lập phiếu điều chỉnh cho một phiếu của ca đã đóng — API kiểm phiếu gốc.
            'adjusts_id' => ['nullable', 'integer', 'min:1'],
            // Tài khoản của phiếu chuyển khoản — API kiểm thuộc chi nhánh / bắt buộc.
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            // Phiếu chi bắt buộc mô tả: một khoản ra khỏi quầy không có lý do thì
            // không đối soát được. Luật của quầy nên kiểm ở đây, không ở API.
            'note' => ['nullable', 'required_if:type,1', 'string', 'max:200'],
            // Tài khoản chủ / quản trị duyệt phiếu chi vượt hạn mức — API kiểm, không lưu.
            'approver_username' => ['nullable', 'string', 'max:100'],
            'approver_password' => ['nullable', 'string', 'max:72'],
        ], [
            'type.required' => 'Chọn phiếu thu hay phiếu chi.',
            'amount.required' => 'Nhập số tiền.',
            'amount.gt' => 'Số tiền phải lớn hơn 0.',
            'amount.max' => 'Số tiền quá lớn.',
            'category_id.*' => 'Chọn phân loại cho phiếu.',
            'payment_method.required' => 'Chọn hình thức.',
            'note.required_if' => 'Ghi mô tả — một khoản ra khỏi quầy không có lý do thì không đối soát được.',
            'note.max' => 'Mô tả dài quá 200 ký tự.',
        ]);

        return [
            'type' => (int) $du['type'],
            'amount' => (float) $du['amount'],
            'category_id' => (int) ($du['category_id'] ?? 0),
            'payer_type' => ! empty($du['payer_id']) ? ($du['payer_type'] ?? null) : null,
            'payer_id' => (int) ($du['payer_id'] ?? 0) ?: null,
            'payment_method' => $du['payment_method'],
            'attachment' => trim((string) ($du['attachment'] ?? '')),
            'note' => trim((string) ($du['note'] ?? '')),
        ] + array_filter([
            'adjusts_id' => (int) ($du['adjusts_id'] ?? 0) ?: '',
            'kind' => ($du['kind'] ?? '') === 'deposit' ? 'deposit' : '',
            'bank_account_id' => $du['payment_method'] === 'transfer' ? ((int) ($du['bank_account_id'] ?? 0) ?: '') : '',
            'approver_username' => trim((string) ($du['approver_username'] ?? '')),
            'approver_password' => (string) ($du['approver_password'] ?? ''),
        ], fn ($v) => $v !== '');
    }

    protected function filters(Request $request, ?array $ca): array
    {
        $ngay = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        $ky = (string) $request->query('ky', $ca ? 'ca' : 'hom-nay');
        $ky = isset(self::KY[$ky]) ? $ky : 'hom-nay';
        if ($ky === 'ca' && ! $ca) {
            $ky = 'hom-nay';
        }
        $homNay = now();

        [$tu, $den] = match ($ky) {
            'thang-nay' => [$homNay->copy()->startOfMonth()->toDateString(), $homNay->toDateString()],
            'tuy-chon' => [$ngay($request->query('from_date')) ?: $homNay->copy()->subDays(6)->toDateString(), $ngay($request->query('to_date')) ?: $homNay->toDateString()],
            default => [$homNay->toDateString(), $homNay->toDateString()],
        };
        if ($tu > $den) {
            [$tu, $den] = [$den, $tu];
        }
        $type = (string) $request->query('type', '');

        return [
            'ky' => $ky,
            'from_date' => $tu,
            'to_date' => $den,
            'type' => isset(self::LOAI[$type]) ? $type : '',
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'page' => max(1, (int) $request->query('page', 1)),
            'phieu' => max(0, (int) $request->query('phieu', 0)),
            // "Cả ca" chỉ có nghĩa khi đang xem một ca (két chung của ca); tìm theo từ
            // khoá hay kỳ ngày thì vẫn là phiếu của mình.
            'pham_vi' => $request->query('pham_vi') === 'ca' && $ky === 'ca' && trim((string) $request->query('q', '')) === '' ? 'ca' : 'toi',
        ];
    }

    /** Đường đọc phụ hỏng thì trả $macDinh — thiếu một khối không đáng sập cả màn. */
    protected function doc(callable $goi, mixed $macDinh): mixed
    {
        try {
            $res = $goi();

            return $res->successful() ? $res->json('data') : $macDinh;
        } catch (\Throwable $e) {
            Log::info('Thu ngan: doc du lieu thu chi that bai', ['msg' => $e->getMessage()]);

            return $macDinh;
        }
    }
}
