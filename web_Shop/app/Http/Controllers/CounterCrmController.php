<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiJsonReply;
use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CRM ở quầy — Quản trị → CRM → Danh sách khách hàng cắt bớt cho thu ngân.
 *
 * KHÔNG có dữ liệu hay luật riêng: danh sách, xem, thêm, sửa đi qua CHÍNH handler
 * khách hàng của Quản trị (mở thêm ở /admin/orders/pos/khach-hang/*), nút lọc nhanh
 * là bộ lọc của danh sách Quản trị (giống các link "Cần chăm sóc" ở CRM → Tổng
 * quan), lịch sử giao dịch là cùng sổ /admin/orders?user_id= của hộp Chi tiết. Thu
 * nợ mở ngay tại khung bằng hộp Thu nợ của màn Lịch sử (v2::pos._thu-no, cùng đường
 * thu-ngan.don-hang.thuNo). Sửa vẫn theo quyền khach-hang.sua.
 */
class CounterCrmController extends Controller
{
    use ApiJsonReply;

    public const TITLE = 'Khách hàng';

    /** Nút lọc nhanh = tham số danh sách Quản trị. `dem` = có hỏi số đếm không. */
    public const NHANH = [
        '' => ['ten' => 'Tất cả', 'icon' => 'fa-users', 'dem' => false],
        'birthday' => ['ten' => 'Sinh nhật 7 ngày', 'icon' => 'fa-cake-candles', 'dem' => true],
        'lapsed' => ['ten' => 'Lâu chưa quay lại', 'icon' => 'fa-user-clock', 'dem' => true],
        'debt' => ['ten' => 'Nợ nhiều nhất', 'icon' => 'fa-hand-holding-dollar', 'dem' => true],
        'hen' => ['ten' => 'Có hẹn hôm nay', 'icon' => 'fa-calendar-check', 'dem' => true],
    ];

    public const LOAI = ['' => 'Tất cả', '0' => 'Cá nhân', '1' => 'Doanh nghiệp'];

    public const GIOI_TINH = ['male' => 'Nam', 'female' => 'Nữ', 'other' => 'Khác'];

    public const PAGE_SIZE = 30;

    /** Ô mà hộp sửa ở quầy KHÔNG có — giữ nguyên giá trị đang lưu khi gửi bản sửa. */
    private const GIU_NGUYEN = ['avatar', 'status', 'citizen_id', 'representative_name', 'representative_phone'];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $f = $this->filters($request);
        $chung = array_filter([
            'keyword' => $f['q'],
            'rank_id' => $f['rank'] ?: null,
            'types' => $f['type'] !== '' ? $f['type'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        $rows = [];
        $meta = [];
        $error = null;
        try {
            $res = $this->api->posKhachHang($chung + $this->thamSoNhanh($f['nhanh']) + ['page' => $f['page'], 'page_size' => self::PAGE_SIZE]);
            if ($res->successful()) {
                $rows = $res->json('data') ?? [];
                $meta = $res->json('meta') ?? [];
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách khách.';
            }
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai khach that bai', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách khách. Kiểm tra kết nối API.';
        }

        $dem = [];
        if (! $error) {
            foreach (self::NHANH as $k => $n) {
                if ($n['dem']) {
                    $dem[$k] = (int) ($this->doc(fn () => $this->api->posKhachHang($chung + $this->thamSoNhanh($k) + ['page_size' => 1]), null, 'meta.total') ?? 0);
                }
            }
        }

        $thanhVien = $this->doc(fn () => $this->api->posThanhVien(), [], 'data') ?? [];
        $nhom = $this->doc(fn () => $this->api->posNhomKhach(['active' => 'true']), [], 'data') ?? [];

        $view = view('v2::pos.crm', compact('rows', 'meta', 'f', 'dem', 'thanhVien', 'nhom'));

        return $error ? $view->with('error', $error) : $view;
    }

    /** JSON: tab "Lịch sử giao dịch" — cùng sổ với hộp Chi tiết của Quản trị. */
    public function donHang(int $id)
    {
        return $this->jsonTuApi(fn () => $this->api->orders(['user_id' => $id, 'page' => 1, 'page_size' => 10]), 'Không đọc được lịch sử giao dịch.');
    }

    /** JSON: đơn còn nợ của khách — nút Thu từng đơn và "Thu nợ" đơn cũ nhất. */
    public function conNo(int $id)
    {
        return $this->jsonTuApi(fn () => $this->api->posConNoKhach($id), 'Không đọc được công nợ của khách.');
    }

    /** JSON: sổ ghi chú của khách (tab Ghi chú), mới trước. */
    public function ghiChu(int $id)
    {
        return $this->jsonTuApi(fn () => $this->api->posGhiChuKhach($id), 'Không đọc được ghi chú của khách.');
    }

    public function themGhiChu(Request $request, int $id)
    {
        $data = $request->validate([
            'loai' => 'required|in:cham-soc,hen,canh-bao',
            'noi_dung' => 'required|string|max:500',
            'ngay_hen' => 'nullable|required_if:loai,hen|date_format:Y-m-d',
        ], [
            'noi_dung.required' => 'Nhập nội dung ghi chú.',
            'noi_dung.max' => 'Ghi chú tối đa 500 ký tự.',
            'ngay_hen.required_if' => 'Chọn ngày hẹn.',
        ]);
        if ($data['loai'] !== 'hen') {
            $data['ngay_hen'] = '';
        }

        return $this->jsonTuApi(fn () => $this->api->posThemGhiChuKhach($id, $data), 'Không lưu được ghi chú.');
    }

    public function xoaGhiChu(int $id, int $note)
    {
        return $this->jsonTuApi(fn () => $this->api->posXoaGhiChuKhach($id, $note), 'Không xoá được ghi chú.');
    }

    /** JSON: "Tạo mã sinh nhật" — mã mới, hoặc 409 kèm mốc cũ để màn nói đã tặng gì. */
    public function tangMaSinhNhat(int $id)
    {
        return $this->quaSinhNhat(fn () => $this->api->posTangMaSinhNhat($id), 'Không tạo được mã sinh nhật.');
    }

    public function daChucSinhNhat(int $id)
    {
        return $this->quaSinhNhat(fn () => $this->api->posDaChucSinhNhat($id), 'Không ghi được đã chúc.');
    }

    /** Như jsonTuApi nhưng giữ `data` của 409 (mốc đã chúc trước đó). */
    private function quaSinhNhat(callable $goi, string $cauMacDinh)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Thu ngan: qua sinh nhat that bai', ['msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }
        if ($res->status() === 409) {
            return response()->json(['message' => $res->json('message') ?: $cauMacDinh, 'errors' => ['ma' => 'DA_CHUC'], 'data' => $res->json('data')], 409);
        }

        return $this->jsonTuApi(fn () => $res, $cauMacDinh);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        // Trùng SĐT: chặn ở đây, mở hồ sơ cũ thay vì đẻ thêm một hồ sơ — như nút thêm
        // nhanh ở Bán hàng (POST /orders/pos/khach-hang trả existed=true).
        if (($sdt = $this->chuanSo($data['phone'])) !== '' && ($cu = $this->timTheoSo($sdt))) {
            return response()->json([
                'message' => 'Số '.$data['phone'].' đã có hồ sơ '.$cu['full_name'].' ('.($cu['customer_code'] ?? '').').',
                'errors' => ['ma' => 'TRUNG_SDT'],
                'data' => ['id' => (int) $cu['id']],
            ], 409);
        }

        return $this->jsonTuApi(fn () => $this->api->posTaoKhachDayDu($data + ['status' => 'active']), 'Không thêm được khách.');
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);
        try {
            $cu = $this->api->posKhachHangChiTiet($id);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }
        if (! $cu->successful()) {
            return response()->json(['message' => $cu->json('message') ?: 'Không tìm thấy khách.'], $cu->status());
        }
        if (($sdt = $this->chuanSo($data['phone'])) !== '' && ($trung = $this->timTheoSo($sdt)) && (int) $trung['id'] !== $id) {
            return response()->json(['message' => 'Số '.$data['phone'].' đã là của '.$trung['full_name'].'.', 'errors' => ['ma' => 'TRUNG_SDT']], 409);
        }

        $giu = array_map(fn ($k) => (string) ($cu->json('data.'.$k) ?? ''), array_combine(self::GIU_NGUYEN, self::GIU_NGUYEN));
        $giu['status'] = $giu['status'] ?: 'active';

        $res = null;
        $ket = $this->jsonTuApi(function () use ($id, $data, $giu, &$res) {
            return $res = $this->api->posSuaKhach($id, $data + $giu);
        }, 'Không lưu được khách.');
        if ($res && $res->status() === 403) {
            return response()->json(['message' => 'Tài khoản chưa được cấp quyền sửa khách (Khách hàng → Sửa). Nhờ quản lý cấp ở Quản trị → Nhân sự.'], 403);
        }

        return $ket;
    }

    /** Cùng luật với hộp Thêm / Sửa của Quản trị (CustomerController::validated), bớt các ô quầy không có. */
    protected function validated(Request $request): array
    {
        $v = $request->validate([
            'full_name' => 'required|string|max:150',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date|before_or_equal:today',
            'address' => 'nullable|string|max:255',
            'customer_type' => 'nullable|in:0,1',
            'customer_group_id' => 'nullable|integer|min:0',
            'tax_code' => 'nullable|string|max:20',
            'customer_note' => 'nullable|string|max:500',
        ], [
            'full_name.required' => 'Vui lòng nhập tên khách.',
            'email.email' => 'Địa chỉ email không hợp lệ.',
            'date_of_birth.date' => 'Ngày sinh không hợp lệ.',
            'date_of_birth.before_or_equal' => 'Ngày sinh không được ở tương lai.',
            'tax_code.max' => 'Mã số thuế tối đa 20 ký tự.',
        ]);
        $dn = (int) ($v['customer_type'] ?? 0) === 1;

        return [
            'full_name' => trim($v['full_name']),
            'email' => trim((string) ($v['email'] ?? '')),
            'phone' => trim((string) ($v['phone'] ?? '')),
            'gender' => $dn ? '' : (string) ($v['gender'] ?? ''),
            'date_of_birth' => $dn ? '' : (string) ($v['date_of_birth'] ?? ''),
            'address' => trim((string) ($v['address'] ?? '')),
            'customer_type' => $dn ? 1 : 0,
            'customer_group_id' => (int) ($v['customer_group_id'] ?? 0),
            'tax_code' => $dn ? trim((string) ($v['tax_code'] ?? '')) : '',
            'customer_note' => trim((string) ($v['customer_note'] ?? '')),
        ];
    }

    /** Tham số của nút lọc nhanh — đúng bộ lọc mà CRM → Tổng quan dẫn sang danh sách. */
    protected function thamSoNhanh(string $nhanh): array
    {
        return match ($nhanh) {
            // Bỏ khách đã chúc / đã tặng mã mùa này — danh sách là việc còn phải làm.
            'birthday' => ['birthday_days' => 7, 'bo_da_chuc' => 1],
            'lapsed' => ['last_tx_from' => '2000-01-01', 'last_tx_to' => now()->subDays(61)->toDateString()],
            // Chỉ khách còn nợ rồi mới xếp nợ giảm dần — chỉ sắp xếp thì cả danh
            // sách khách nợ 0 cũng hiện, đọc thành "ai cũng đang nợ" (KB #95).
            'debt' => ['only_debt' => 1, 'sort' => 'debt_desc'],
            // Ghi chú loại "hẹn" có ngày = hôm nay (API tự lấy ngày của cửa hàng).
            'hen' => ['hen_hom_nay' => 1],
            default => [],
        };
    }

    private function chuanSo(string $s): string
    {
        return preg_replace('/\D/', '', $s) ?? '';
    }

    private function timTheoSo(string $so): ?array
    {
        $ds = $this->doc(fn () => $this->api->posKhachHang(['keyword' => $so, 'page_size' => 10]), [], 'data') ?? [];

        return collect($ds)->first(fn ($c) => $this->chuanSo((string) ($c['phone'] ?? '')) === $so);
    }

    protected function filters(Request $request): array
    {
        $nhanh = (string) $request->query('nhanh', '');
        $type = (string) $request->query('type', '');

        return [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'nhanh' => isset(self::NHANH[$nhanh]) ? $nhanh : '',
            'type' => isset(self::LOAI[$type]) ? $type : '',
            'rank' => max(0, (int) $request->query('rank', 0)),
            'page' => max(1, (int) $request->query('page', 1)),
            'khach' => max(0, (int) $request->query('khach', 0)),
        ];
    }

    protected function doc(callable $goi, mixed $macDinh, string $khoa): mixed
    {
        try {
            $res = $goi();

            return $res->successful() ? $res->json($khoa) : $macDinh;
        } catch (\Throwable $e) {
            Log::info('Thu ngan: doc du lieu khach that bai', ['msg' => $e->getMessage()]);

            return $macDinh;
        }
    }
}
