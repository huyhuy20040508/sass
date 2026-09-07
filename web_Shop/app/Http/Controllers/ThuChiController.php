<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Quản lý thu chi — Thu chi → Quản lý thu chi.
 *
 * Dựng theo màn cùng tên của bản cũ v2 (cashbook/income-expense): khung lọc bên
 * trái, bốn ô thống kê quỹ trên đầu bảng, bảng phiếu thu/chi chọn được cột, hộp
 * thoại lập phiếu và hộp xem chi tiết.
 *
 * Khác bản cũ ở mấy chỗ đã biết là sai:
 * - Quỹ đầu kỳ do API tính, không cộng dồn hai lần khi bỏ trống ngày bắt đầu.
 * - Lọc "Đơn bán hàng" cùng một phân loại khác là phép HOẶC; bản cũ nối bằng VÀ
 *   nên luôn ra bảng rỗng.
 * - Ô lọc "Trả hàng" gồm cả trả hàng bán lẫn trả hàng NCC; bản cũ nhét trả NCC
 *   vào ô "Bán hàng" nên chọn "Trả hàng" không bao giờ thấy phiếu trả NCC.
 *
 * Dữ liệu API (`/admin/thu-chi`): mỗi dòng {id, shop_id, code, type,
 * category_id, category_name, amount, branch_name, payer_type, payer_id,
 * payer_name, created_by, created_by_name, payment_method, attachment_url,
 * note, source, source_id, source_code, shift_id, locked, locked_reason,
 * created_at, updated_at}.
 *
 * `payer_id` là id của ĐÚNG đối tượng ứng với `payer_type` — API gộp sẵn từ ba
 * cột dưới database (nhân viên / nhà cung cấp / người nộp vãng lai), nên trang
 * này không phải nhớ cột nào ứng với loại nào.
 *
 * KHÔNG có `payment_method_name`: nhãn "Tiền mặt" / "Chuyển khoản" nằm ở
 * PHUONG_THUC bên dưới, một nguồn duy nhất.
 */
class ThuChiController extends Controller
{
    use \App\Http\Controllers\Concerns\TraLoiHopThoai;

    public const TITLE = 'Quản lý thu chi';

    public const TITLE_PAGE = 'Quản lý thu chi';

    public const EMPTY_TEXT = 'Chưa có phiếu thu chi nào. Bấm "Lập phiếu" để ghi khoản đầu tiên.';

    /** 0 = phiếu THU, 1 = phiếu CHI — giữ đúng mã của v2 để dùng chung với Loại thu chi. */
    public const LOAI_THU = 0;

    public const LOAI_CHI = 1;

    public const LOAI = [
        self::LOAI_THU => 'Phiếu thu',
        self::LOAI_CHI => 'Phiếu chi',
    ];

    /** Màu chữ cột Loại — thu xanh, chi đỏ, đọc lướt là thấy tiền vào hay ra. */
    public const CHU_LOAI = [
        self::LOAI_THU => 'text-primary',
        self::LOAI_CHI => 'text-danger',
    ];

    /**
     * Nguồn phát sinh — dùng để IN RA (cột bảng, hộp chi tiết, tệp xuất).
     * Khoá là giá trị `source` mà API trả về cho từng phiếu.
     */
    public const NGUON = [
        'manual' => 'Tự tạo',
        'order' => 'Bán hàng',
        'purchase' => 'Mua hàng',
        'supplier_return' => 'Trả hàng nhà cung cấp',
        'order_return' => 'Trả hàng bán',
    ];

    /**
     * Ô lọc "Loại" — bốn lựa chọn đúng như v2.
     *
     * Khác v2 đúng một chỗ, và là chỗ v2 làm mất phiếu: "Trả hàng" bên này gồm
     * CẢ trả hàng bán lẫn trả hàng NCC. v2 chỉ lọc trả hàng bán rồi nhét trả NCC
     * vào chung ô "Bán hàng", nên chọn "Trả hàng" là không bao giờ thấy phiếu
     * trả NCC, mà chọn "Bán hàng" thì lại lẫn phiếu không phải bán hàng.
     */
    public const NGUON_LOC = [
        'order' => 'Bán hàng',
        'purchase' => 'Mua hàng',
        'manual' => 'Tự tạo',
        'return' => 'Trả hàng',
    ];

    /**
     * Loại đối tượng nộp / nhận tiền — quyết định ô "Người nộp" lấy danh sách ở đâu.
     *
     * v2 bày SÁU mục: Quản lý · Quầy bếp · Thu ngân · Nhân viên order · Nhà cung
     * cấp · Khác. Bốn mục đầu là VAI của nhân viên, chọn mục nào thì ô "Người nộp"
     * chỉ nạp người mang vai ấy.
     *
     * Bên này còn BỐN, và không phải cắt bớt cho gọn: v2 là phần mềm nhà hàng nên
     * có quầy bếp với nhân viên order bàn, còn nhân sự của Selliotech chỉ có hai
     * vai — Quản lý (`quan_ly`) và Thu ngân (`thu_ngan`), xem NhanSuController::NHAN_CUA.
     * Bày thêm hai mục nữa là dựng hai ô không bao giờ có ai, chọn vào chỉ ra
     * danh sách rỗng mà chẳng có gì nói vì sao.
     *
     * Khoá của hai mục nhân viên trùng mã cửa vào (`users.quyen`) để lọc thẳng
     * theo trường API trả về, không phải bắc thêm một bảng tra.
     */
    public const DOI_TUONG = [
        'quan_ly' => 'Quản lý',
        'thu_ngan' => 'Thu ngân',
        'supplier' => 'Nhà cung cấp',
        'other' => 'Khác',
    ];

    /** Hai mục đầu của DOI_TUONG là vai nhân viên — dùng để lọc `users.quyen`. */
    public const DOI_TUONG_NHAN_VIEN = ['quan_ly', 'thu_ngan'];

    /**
     * Phương thức thanh toán. Bản cũ khai sáu mã rồi lọc bỏ bốn ngay trên trình
     * duyệt, chỉ còn tiền mặt và chuyển khoản — bên này khai đúng hai cái đó.
     */
    public const PHUONG_THUC = [
        'cash' => 'Tiền mặt',
        'transfer' => 'Chuyển khoản',
    ];

    /**
     * Định dạng nhận cho tệp đính kèm: ảnh chụp chứng từ hoặc bản PDF.
     *
     * v2 nhận BẤT KỲ đuôi nào rồi ném thẳng lên S3. Bên này tệp nằm trên ổ đĩa
     * công khai của chính máy chủ web, nên nhận bừa là mở đường cho người ta tải
     * lên .php/.html rồi gọi lại qua trình duyệt. Chốt đúng thứ người dùng thật
     * sự đính kèm.
     */
    public const DINH_KEM_MIMES = 'jpeg,jpg,png,webp,gif,avif,pdf';

    /** Cột bật/tắt được. Khoá = tên cột gửi trong ?hide=. */
    public const COT_BANG = [
        'code' => 'receipt-code',
        'type' => 'type_of_income_expense',
        'amount' => 'amount',
        'branch' => 'branch-name',
        'payer' => 'payer',
        'creator' => 'creator',
        'category' => 'income_expense_category',
        'payment_method' => 'payment-method',
        'attachment' => 'attachment',
        'created_at' => 'recorded-time',
        'note' => 'description',
    ];

    public const SO_DONG_MOI_TRANG = 10;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    public function __construct(protected ApiClient $api) {}

    // ---------------------------------------------------------------------
    // Danh sách
    // ---------------------------------------------------------------------

    /** API lọc, cắt trang và cộng quỹ sẵn — trang này chỉ dựng hình. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $list = [];
        $meta = ['page' => 1, 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        // Bốn ô thống kê đầu bảng. API chưa trả thì để 0 chứ không tự cộng lấy:
        // cộng trên một trang thì ra số của trang, mà ô lại ghi là "tổng".
        $summary = ['begin_balance' => 0, 'total_income' => 0, 'total_expense' => 0, 'end_balance' => 0];

        try {
            $res = $this->api->thuChi($this->query($filters));
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                // Bốn ô quỹ nằm CHUNG trong `meta` với phân trang, không phải một
                // khoá `summary` riêng: chúng là thông tin về tập kết quả, và thêm
                // một trường mới vào khuôn phản hồi của API cho một màn hình thì
                // mọi đường khác cũng phải mang theo nó.
                $khoiMeta = $res->json('meta') ?? [];
                $meta = array_merge($meta, $khoiMeta);
                $summary = array_merge($summary, array_intersect_key($khoiMeta, $summary));
            } else {
                $error = $res->json('message') ?: 'Không tải được sổ thu chi.';
            }
        } catch (\Throwable $e) {
            Log::error('Load thu chi failed', ['msg' => $e->getMessage()]);
            $error = 'Chưa nối được API thu chi — trang đang hiện bảng rỗng.';
        }

        $view = view('v2::thu-chi.index', [
            'list' => $list,
            'filters' => $filters,
            'meta' => $meta,
            'summary' => $summary,
            'nhanVien' => $this->danhMucNhanVien(),
            'phanLoai' => $this->danhMucPhanLoai(),
            'nhaCungCap' => $this->danhMucNhaCungCap(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Xuất sổ thu chi ra Excel theo đúng bộ lọc đang bật.
     *
     * Khác v2 ở hai chỗ: bản cũ có hai đường xuất dùng hai bộ lọc KHÁC NHAU nên
     * số ra lệch với bảng đang xem, và đường đang chạy thì đẩy tệp lên S3 rồi
     * trả link công khai không hạn dùng. Bên này một đường, cùng bộ lọc với
     * bảng, và tệp đi thẳng về máy người bấm.
     */
    public function export(Request $request)
    {
        $query = $this->query($this->filters($request));

        try {
            $list = $this->docHetTrang(fn (array $q) => $this->api->thuChi($q), $query);
        } catch (\Throwable $e) {
            Log::error('Export thu chi failed', ['msg' => $e->getMessage()]);

            return back()->with('error', $e instanceof \RuntimeException ? $e->getMessage() : 'Không kết nối được API để xuất tệp.');
        }

        $hang = [[
            'STT', 'Mã phiếu', 'Loại thu chi', 'Số tiền', 'Chi nhánh', 'Người nộp/nhận',
            'Người tạo', 'Phân loại thu chi', 'Phương thức thanh toán', 'Nguồn phát sinh',
            'Thời gian ghi nhận', 'Mô tả',
        ]];
        foreach ($list as $i => $p) {
            $hang[] = [
                $i + 1,
                (string) ($p['code'] ?? ''),
                self::LOAI[(int) ($p['type'] ?? 0)] ?? '',
                (float) ($p['amount'] ?? 0),
                (string) ($p['branch_name'] ?? ''),
                (string) ($p['payer_name'] ?? ''),
                (string) ($p['created_by_name'] ?? ''),
                (string) ($p['category_name'] ?? ''),
                (string) ($p['payment_method_name'] ?? (self::PHUONG_THUC[$p['payment_method'] ?? ''] ?? '')),
                self::NGUON[$p['source'] ?? 'manual'] ?? '',
                ! empty($p['created_at']) ? date('d-m-Y', strtotime((string) $p['created_at'])) : '',
                (string) ($p['note'] ?? ''),
            ];
        }

        return $this->taiXlsx($hang, 'thu-chi-'.date('Ymd-His'), 'Thu chi');
    }

    // ---------------------------------------------------------------------
    // Danh mục cho hộp lập phiếu — gọi ngầm, trả JSON
    // ---------------------------------------------------------------------

    /**
     * Phân loại thu hoặc chi cho ô "Nhóm thu chi" trong hộp lập phiếu.
     * Đi qua Laravel để token API ở lại phía máy chủ.
     */
    public function phanLoai(Request $request)
    {
        $loai = $request->query('type');
        $loai = in_array((string) $loai, ['0', '1'], true) ? (int) $loai : null;

        $ds = $this->danhMucPhanLoai();
        if ($loai !== null) {
            $ds = array_values(array_filter($ds, fn ($d) => (int) ($d['type'] ?? -1) === $loai));
        }

        return response()->json(['data' => $ds]);
    }

    /**
     * Nhận tệp đính kèm rồi trả về đường dẫn.
     *
     * Tách khỏi lượt lưu phiếu, đúng cách các màn khác đang làm (Điều chỉnh tồn
     * kho, Hàng hoá): hộp thoại đẩy tệp lên trước, cầm lấy URL, rồi lưu phiếu
     * bằng JSON như mọi trường khác. Nhờ vậy lượt lưu không phải là multipart và
     * đường đi tới API vẫn là một payload JSON phẳng.
     */
    public function dinhKem(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:'.self::DINH_KEM_MIMES, 'max:'.ImageStore::MAX_UPLOAD_KB],
        ], [
            'file.required' => 'Chưa chọn tệp nào.',
            'file.mimes' => 'Chỉ nhận ảnh (JPG, PNG, WEBP, GIF, AVIF) hoặc PDF.',
            'file.max' => 'Tệp lớn quá '.(int) (ImageStore::MAX_UPLOAD_KB / 1024).'MB.',
        ]);

        return response()->json(['url' => ImageStore::put($request->file('file'), 'thu-chi')]);
    }

    /** Danh sách người nộp / người nhận vãng lai cho ô "Người nộp". */
    public function nguoiNop(Request $request)
    {
        try {
            $res = $this->api->nguoiNopThuChi([
                'keyword' => trim((string) $request->query('keyword', '')),
                'page_size' => 100,
            ]);

            // Lỗi của API phải TỚI được người dùng, không hoá thành danh sách
            // rỗng: "chưa chọn chi nhánh" mà đọc ra "chưa có ai" thì người ta
            // ngồi thêm mới mãi không hiểu vì sao lưu không được.
            if (! $res->successful()) {
                return response()->json(
                    ['data' => [], 'message' => $res->json('message') ?: 'Không tải được danh sách người nộp.'],
                    $res->status() >= 400 ? $res->status() : 502,
                );
            }

            return response()->json(['data' => $res->json('data') ?? []]);
        } catch (\Throwable $e) {
            Log::error('Load nguoi nop thu chi failed', ['msg' => $e->getMessage()]);

            return response()->json(['data' => [], 'message' => 'Không kết nối được API.'], 502);
        }
    }

    /** Thêm nhanh một người nộp ngay trong hộp lập phiếu. */
    public function taoNguoiNop(Request $request)
    {
        $du = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'Nhập tên người nộp / người nhận.',
            'name.max' => 'Tên dài quá 255 ký tự.',
            'phone.max' => 'Số điện thoại dài quá 20 ký tự.',
            'address.max' => 'Địa chỉ dài quá 255 ký tự.',
        ]);

        return $this->send(
            fn () => $this->api->taoNguoiNopThuChi([
                'name' => trim($du['name']),
                'phone' => trim((string) ($du['phone'] ?? '')),
                'address' => trim((string) ($du['address'] ?? '')),
            ]),
            'Đã thêm "'.trim($du['name']).'".',
            $request
        );
    }

    // ---------------------------------------------------------------------
    // Ghi
    // ---------------------------------------------------------------------

    /** Lập một phiếu thu hoặc phiếu chi. */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->taoThuChi($data),
            'Đã lập '.mb_strtolower(self::LOAI[$data['type']]).'.',
            $request
        );
    }

    /**
     * Sửa một phiếu. API chặn lại lần nữa ba trường hợp không được sửa: phiếu
     * của người khác, phiếu tự phát sinh từ đơn, và phiếu thuộc ca đã đóng.
     */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->suaThuChi($id, $data),
            'Đã lưu phiếu.',
            $request
        );
    }

    /** Xoá một phiếu. */
    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->xoaThuChi($id),
            'Đã xoá phiếu.',
            $request
        );
    }

    // ---------------------------------------------------------------------
    // Trợ giúp
    // ---------------------------------------------------------------------

    /**
     * Kiểm dữ liệu hộp lập phiếu. API kiểm lại tất cả; lượt này chỉ để người
     * dùng thấy lỗi ngay tại ô vừa gõ thay vì đợi một vòng mạng.
     */
    protected function validated(Request $request): array
    {
        $du = $request->validate([
            'type' => ['required', 'in:'.self::LOAI_THU.','.self::LOAI_CHI],
            'amount' => ['required', 'numeric', 'gt:0'],
            'category_id' => ['nullable', 'integer'],
            'payer_type' => ['nullable', 'in:'.implode(',', array_keys(self::DOI_TUONG))],
            'payer_id' => ['nullable', 'integer'],
            'payment_method' => ['required', 'in:'.implode(',', array_keys(self::PHUONG_THUC))],
            // Đường dẫn tệp, KHÔNG phải tệp: hộp thoại đã đẩy tệp lên qua dinhKem()
            // và cầm về URL trước khi bấm Lưu.
            'attachment' => ['nullable', 'string', 'max:2048'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'type.required' => 'Chọn phiếu thu hay phiếu chi.',
            'type.in' => 'Loại phiếu chỉ là thu hoặc chi.',
            'amount.required' => 'Nhập số tiền.',
            'amount.numeric' => 'Số tiền phải là số.',
            'amount.gt' => 'Số tiền phải lớn hơn 0.',
            'payment_method.required' => 'Chọn phương thức thanh toán.',
            'payment_method.in' => 'Phương thức thanh toán không hợp lệ.',
            'note.max' => 'Mô tả dài quá 200 ký tự.',
        ]);

        // CÒN THIẾU: `payment_account_id` — tài khoản ngân hàng nhận tiền khi
        // chuyển khoản. Chưa có API nào trả danh sách tài khoản nên ô ấy chưa
        // dựng; bắt buộc một ô không bao giờ điền được thì khoá luôn đường
        // Chuyển khoản. Có API rồi thì thêm lại luật và chốt bắt buộc ở đây.

        return [
            'type' => (int) $du['type'],
            'amount' => (float) $du['amount'],
            'category_id' => (int) ($du['category_id'] ?? 0) ?: null,
            'payer_type' => $du['payer_type'] ?? null,
            'payer_id' => (int) ($du['payer_id'] ?? 0) ?: null,
            'payment_method' => $du['payment_method'],
            'attachment' => trim((string) ($du['attachment'] ?? '')),
            'note' => trim((string) ($du['note'] ?? '')),
        ];
    }

    /** Bộ lọc của trang, đã gạn giá trị lạ. */
    protected function filters(Request $request): array
    {
        $size = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);

        // KHÔNG có `payment_method`: khung lọc của v2 chỉ sáu ô và không hề có ô
        // này. Controller v2 tuy đọc tham số ấy nhưng chẳng ô nào gửi lên — một
        // nhánh chết. Bảng vẫn có CỘT phương thức thanh toán, chỉ là không lọc theo.

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'type' => $this->locNhieu($request->query('type'), ['0', '1']),
            'category_id' => $this->locNhieuSo($request->query('category_id')),
            'source' => $this->locNhieu($request->query('source'), array_keys(self::NGUON_LOC)),
            'created_by' => $this->locNhieuSo($request->query('created_by')),
            // Chưa gõ gì thì lấy tháng này, đúng như v2 tự điền sẵn hai ô ngày.
            'from_date' => $request->has('from_date')
                ? $this->ngayLoc($request->query('from_date'))
                : date('Y-m-01'),
            'to_date' => $request->has('to_date')
                ? $this->ngayLoc($request->query('to_date'))
                : date('Y-m-d'),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($size, self::MUC_SO_DONG, true) ? $size : self::SO_DONG_MOI_TRANG,
        ];
    }

    /** Đổi bộ lọc của trang thành query API. */
    protected function query(array $f): array
    {
        return [
            'keyword' => $f['keyword'],
            'type' => $f['type'],
            'category_id' => $f['category_id'],
            'source' => $f['source'],
            'created_by' => $f['created_by'],
            'from_date' => $f['from_date'],
            'to_date' => $f['to_date'],
            'page' => $f['page'],
            'page_size' => $f['page_size'],
        ];
    }

    /** Ô "Người tạo" của khung lọc. */
    protected function danhMucNhanVien(): array
    {
        try {
            $res = $this->api->users(['status' => 'active', 'page_size' => 100]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load tai khoan cho thu chi failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Toàn bộ phân loại thu và chi — dùng cho cả khung lọc lẫn hộp lập phiếu. */
    protected function danhMucPhanLoai(): array
    {
        try {
            $res = $this->api->loaiThuChi();

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load loai thu chi cho thu chi failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Ô "Người nộp" khi loại đối tượng là Nhà cung cấp. */
    protected function danhMucNhaCungCap(): array
    {
        try {
            $res = $this->api->nhaCungCap(['status' => 'active', 'page_size' => 200]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load nha cung cap cho thu chi failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Lọc nhiều giá trị: bỏ giá trị lạ rồi ghép lại bằng dấu phẩy. */
    protected function locNhieu($v, array $hopLe): string
    {
        $phan = is_array($v) ? $v : explode(',', (string) $v);
        $sach = array_values(array_intersect(array_map('trim', $phan), $hopLe));

        return implode(',', $sach);
    }

    /** Nhiều id: bỏ giá trị lạ rồi ghép lại bằng dấu phẩy. */
    protected function locNhieuSo($v): string
    {
        $phan = is_array($v) ? $v : explode(',', (string) $v);
        $id = array_values(array_filter(array_map('intval', $phan)));

        return implode(',', $id);
    }

    /**
     * Ngày lọc → YYYY-MM-DD. Nhận cả DD-MM-YYYY vì ô ngày của v2 gõ theo kiểu
     * đó; khuôn nào khác thì bỏ qua chứ không đoán.
     */
    protected function ngayLoc($v): string
    {
        $v = trim((string) $v);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $v, $m)) {
            return $m[3].'-'.$m[2].'-'.$m[1];
        }

        return '';
    }

    /** Gọi API rồi trả lời hộp thoại hoặc quay về bảng kèm thông báo. */
    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Thu chi API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        // Hỏng thì trả về ĐÚNG mã của API: sửa một id không tồn tại phải là 404
        // chứ không phải 422, không thì mã nói một đằng câu nói một nẻo.
        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $success, fn () => redirect()->route('admin.thu-chi.index'))
            : $this->traLoiHopThoai(
                $request,
                false,
                $this->cauLoiApi($res, 'Thao tác không thành công.'),
                null,
                $res->status()
            );
    }
}
