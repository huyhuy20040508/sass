<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Công nợ — Thu chi → Công nợ.
 *
 * Dựng theo màn `cashbook/debt` của bản cũ v2: khung lọc bên trái, hàng bốn nút
 * đếm số theo hạn nợ trên đầu bảng, bảng chọn được cột, hộp chi tiết bày lịch sử
 * từng lượt trả và một nút ghi lượt trả mới.
 *
 * BỐN CHỖ CỐ Ý KHÁC V2:
 *
 * 1. CHƯA CÓ NỢ KHÁCH HÀNG, và ô lọc "Đối tượng" vì thế KHÔNG được bày ra.
 *    v2 gộp hai chiều nợ vào một màn rồi cho lọc bằng ô "Đối tượng"
 *    (Khách hàng / Nhà cung cấp). Bên này chưa có bảng đơn bán nào — không nguồn
 *    nào đẻ ra nợ khách. Bày sẵn một ô tick không bao giờ trả về dòng nào là
 *    dựng cái bẫy: người dùng bỏ tick "Nhà cung cấp", thấy bảng rỗng, và không
 *    có gì nói cho họ biết vì sao. Làm bán hàng rồi thì thêm lại ô ấy.
 *
 * 2. KHÔNG CÓ MÃ CÔNG NỢ RIÊNG. v2 sinh `cab_debts.code` theo một quy tắc đánh
 *    số thứ hai, nên mỗi khoản nợ mang HAI mã cho cùng một chứng từ và người
 *    dùng phải nhớ cả hai để tra. Ở đây mã phiếu mua là mã duy nhất.
 *
 * 3. CHỈ KHOẢN ĐÃ THOẢ THUẬN CHO NỢ mới vào sổ (`is_debt`). v2 coi mọi phiếu
 *    `paid < total` là nợ, nên sổ đầy phiếu chưa hẹn ngày trả nào và cột "Hạn
 *    còn lại" bên đó in ra ngày 01/01/1970.
 *
 * 4. TRẢ NỢ ĐI BẰNG ĐƯỜNG THANH TOÁN PHIẾU MUA. v2 có đường ghi riêng
 *    (`debt/debt-payment`) cộng dồn `cab_debts.paid`, trong khi
 *    PurchaseOrderController cộng `pch_orders.payment_total_price` ở chỗ khác —
 *    hai con số cho cùng một khoản tiền, không ai đối chiếu bao giờ. Bên này chỉ
 *    một đường ghi, và nó đã khoá dòng sẵn.
 *
 * Dữ liệu API (`/admin/cong-no`): mỗi dòng {id, code, shop_id, branch_name,
 * supplier_id, supplier_name, total_amount, paid_amount, remaining, due_date,
 * contact_name, contact_phone, status, days_left, created_by, created_by_name,
 * created_at}.
 */
class CongNoController extends Controller
{
    use \App\Http\Controllers\Concerns\TraLoiHopThoai;

    public const TITLE = 'Công nợ';

    public const TITLE_PAGE = 'Công nợ';

    public const EMPTY_TEXT = 'Chưa có khoản nợ nào. Khoản nợ sinh ra khi duyệt một phiếu mua hàng có ghi nợ nhà cung cấp.';

    /**
     * Bốn mốc hạn của hàng nút đếm — đúng bốn nút của v2, đúng thứ tự ấy.
     * Khoá là giá trị gửi lên `?due=`.
     */
    public const MOC_HAN = [
        'all' => 'all',
        'near' => 'near_due',
        'over' => 'overdue',
        'today' => 'today',
    ];

    /**
     * Trạng thái trả tiền — dùng để IN RA. Khoá là giá trị `status` API trả về.
     */
    public const TRANG_THAI = [
        'unpaid' => 'not_paid',
        'partial' => 'partial_payment',
        'paid' => 'paid',
    ];

    /**
     * Ô lọc "Trạng thái" — CHỈ HAI mục, và đây là chỗ đáng đọc kỹ nhất của lớp này.
     *
     * SỔ NÀY LÀ SỔ KHOẢN ĐANG NỢ, không phải nhật ký mọi khoản từng nợ. Lý do
     * nằm ở schema: `purchase_orders.is_debt` là thoả thuận cho nợ, và đường
     * thanh toán của API DỌN cờ ấy cùng hạn nợ, người đại diện ngay khi phiếu
     * trả đủ (xem PhieuMuaHangService.Pay — nó còn từ chối thẳng lượt ghi nào
     * vừa trả đủ vừa khai `is_debt`). Trả xong là phiếu không còn dấu vết nào
     * nói rằng nó từng là khoản nợ, nên sổ không có cách nào bày lại.
     *
     * Vì thế mục "Đã thanh toán" KHÔNG được bày ra: nó sẽ là một ô tick không
     * bao giờ trả về dòng nào. TRANG_THAI ở trên vẫn giữ đủ ba mục vì cột
     * Trạng thái phải in đúng thứ API trả về, kể cả một dòng cũ còn sót cờ.
     *
     * v2 bày đủ bốn mục (`paid`, `unpaid`, `partial`, `paid_or_partial`), mà mục
     * thứ tư còn không phải một trạng thái — nó là kết quả của việc tick hai ô
     * cùng lúc, nhưng controller v2 nhận nó như một giá trị riêng.
     *
     * CÒN THIẾU: muốn sổ giữ lại cả khoản đã tất toán thì phải có một cột nhớ
     * "phiếu này TỪNG là khoản nợ" — `is_debt` một mình không đủ vì nó bị dọn.
     * Thêm cột ấy rồi thì mở lại mục "Đã thanh toán" ở đây.
     */
    public const TRANG_THAI_LOC = [
        'unpaid' => 'not_paid',
        'partial' => 'partial_payment',
    ];

    /**
     * Hình thức trả tiền của một lượt trả. Cùng hai giá trị với phiếu mua hàng —
     * sổ này đọc chính bảng ấy nên không được khai lệch.
     */
    public const PHUONG_THUC = [
        'cash' => 'Tiền mặt',
        'transfer' => 'Chuyển khoản',
    ];

    /**
     * Cột bật/tắt được. Khoá = tên cột gửi trong ?hide=.
     *
     * ĐÚNG THỨ TỰ CỦA V2, kể cả thứ tự ba cột tiền: Chưa thanh toán → Đã thanh
     * toán → Tổng tiền. Đọc xuôi là "còn phải đòi bao nhiêu" trước, "đã thu được
     * bao nhiêu" sau — sổ nợ mở ra để trả lời câu thứ nhất.
     *
     * Bỏ đúng MỘT cột của v2: "Đối tượng" (Khách hàng / Nhà cung cấp). Bên này
     * mọi dòng đều là nhà cung cấp nên cột ấy in cùng một chữ từ trên xuống dưới.
     *
     * Cột "Mã công nợ" của v2 nhập làm một với "Mã đơn hàng": khoản nợ không có
     * mã riêng, xem chú thích ở đầu lớp.
     *
     * KHÔNG còn cột "Trạng thái" riêng — nó GỘP vào "Hạn còn" thành một ô hai
     * dòng (trạng thái ở trên, số ngày ở dưới). v2 để ba cột cùng nói về một
     * chuyện — "Hạn còn" in "Còn 5 ngày", "Ngày đáo hạn" in 12-09-2026, "Trạng
     * thái" in "Thanh toán một phần" — mà cột thứ ba thì suy thẳng ra được từ
     * hai cột tiền ngay bên trái. Ba cột cho một câu hỏi là ba lần mắt phải
     * chạy ngang.
     */
    public const COT_BANG = [
        'code' => 'order_code',
        'supplier' => 'object_name',
        'branch' => 'branch',
        'remaining' => 'not_paid',
        'paid' => 'paid',
        'total_amount' => 'total_money',
        'remaining_term' => 'remaining_term',
        'due_date' => 'due_date',
        'creator' => 'creator',
    ];

    public const SO_DONG_MOI_TRANG = 10;

    public const MUC_SO_DONG = [10, 20, 30, 40, 50];

    public function __construct(protected ApiClient $api) {}

    // ---------------------------------------------------------------------
    // Danh sách
    // ---------------------------------------------------------------------

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $list = [];
        $meta = ['page' => 1, 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        // Bốn con số của bốn nút + tiền còn nợ. API chưa trả thì để 0 chứ không
        // tự đếm lấy: đếm trên một trang thì ra số của trang, mà nút lại ghi là
        // số của cả sổ.
        $dem = ['count_all' => 0, 'count_near' => 0, 'count_over' => 0, 'count_today' => 0, 'total_remaining' => 0];

        try {
            $res = $this->api->congNo($this->query($filters));
            if ($res->successful()) {
                $list = $res->json('data') ?? [];
                $khoiMeta = $res->json('meta') ?? [];
                $meta = array_merge($meta, $khoiMeta);
                $dem = array_merge($dem, array_intersect_key($khoiMeta, $dem));
            } else {
                $error = $res->json('message') ?: 'Không tải được sổ công nợ.';
            }
        } catch (\Throwable $e) {
            Log::error('Load cong no failed', ['msg' => $e->getMessage()]);
            $error = 'Chưa nối được API công nợ — trang đang hiện bảng rỗng.';
        }

        $view = view('v2::cong-no.index', [
            'list' => $list,
            'filters' => $filters,
            'meta' => $meta,
            'dem' => $dem,
            'nhanVien' => $this->danhMucNhanVien(),
            'nhaCungCap' => $this->danhMucNhaCungCap(),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Sổ từng lượt trả của một khoản nợ, cho hộp chi tiết.
     *
     * Đi qua Laravel thay vì gọi thẳng API từ trình duyệt để token ở lại phía
     * máy chủ — cùng luật với mọi đường nạp ngầm khác của bộ này.
     */
    public function lichSuTra(Request $request, int $id)
    {
        try {
            $res = $this->api->congNoLichSuTra($id);
        } catch (\Throwable $e) {
            Log::error('Load lich su tra no failed', ['msg' => $e->getMessage(), 'id' => $id]);

            return response()->json(['data' => [], 'message' => 'Không kết nối được API.'], 502);
        }

        // Lỗi của API phải TỚI được người dùng chứ không hoá thành sổ rỗng: sổ
        // rỗng đọc ra là "chưa trả đồng nào", một câu khác hẳn "không đọc được".
        if (! $res->successful()) {
            return response()->json(
                ['data' => [], 'message' => $res->json('message') ?: 'Không tải được lịch sử trả nợ.'],
                $res->status() >= 400 ? $res->status() : 502,
            );
        }

        return response()->json(['data' => $res->json('data') ?? []]);
    }

    /**
     * Ghi một lượt trả nợ.
     *
     * KHÔNG có đường ghi của riêng công nợ: gọi thẳng đường thanh toán phiếu mua
     * — đường ấy khoá dòng phiếu, cập nhật `paid_amount` cùng `payment_status`
     * và ghi `purchase_payments` trong MỘT giao dịch.
     *
     * Trang gửi lên số VỪA TRẢ THÊM (ô "Số tiền trả" của hộp), còn API nhận số
     * LUỸ KẾ. Phép cộng nằm ở đây, và cộng từ con số API vừa trả về chứ không từ
     * con số trình duyệt đang giữ: hai người cùng ghi một khoản thì người sau
     * phải cộng lên số mới nhất, không phải số họ nhìn thấy lúc mở hộp.
     */
    public function traNo(Request $request, int $id)
    {
        $du = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:'.implode(',', array_keys(self::PHUONG_THUC))],
            'note' => ['nullable', 'string', 'max:500'],
            // Đường dẫn ảnh, KHÔNG phải tệp: hộp thoại đã đẩy ảnh lên qua đường
            // ảnh của phiếu mua và cầm về URL trước khi bấm Lưu.
            'payment_attachment' => ['nullable', 'string', 'max:255'],
            // Người đại diện bên bán SỬA ĐƯỢC ngay trong hộp thanh toán, đúng như
            // v2: tới hạn mà bên bán đổi người phụ trách thì đây là chỗ ghi số mới,
            // chứ không phải mở lại phiếu mua để sửa.
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
        ], [
            'amount.required' => 'Nhập số tiền trả.',
            'amount.numeric' => 'Số tiền phải là số.',
            'amount.gt' => 'Số tiền phải lớn hơn 0.',
            'payment_method.required' => 'Chọn hình thức trả.',
            'payment_method.in' => 'Hình thức trả không hợp lệ.',
        ]);

        try {
            $hienTai = $this->api->phieuMuaHangChiTiet($id);
            if (! $hienTai->successful()) {
                return $this->traLoiHopThoai(
                    $request,
                    false,
                    $this->cauLoiApi($hienTai, 'Không đọc được khoản nợ.'),
                    null,
                    $hienTai->status()
                );
            }

            $daTra = (float) ($hienTai->json('data.paid_amount') ?? 0);
            $tong = (float) ($hienTai->json('data.total_amount') ?? 0);
            $moi = $daTra + (float) $du['amount'];

            // Chặn trả quá số nợ ở ĐÂY chứ không chỉ ở trình duyệt: v2 chỉ chặn
            // bằng JS trong ô nhập, nên một lượt gọi thẳng vào đường ghi là sổ
            // nhận số trả lớn hơn cả số nợ.
            if ($moi > $tong + 0.005) {
                return $this->traLoiHopThoai(
                    $request,
                    false,
                    'Số tiền trả lớn hơn số còn nợ.',
                    null,
                    422
                );
            }

            // Giữ nguyên thoả thuận nợ đang có (hạn, người đại diện): lượt này
            // chỉ ghi tiền. Không gửi lại thì server đọc ra là bỏ trống và xoá
            // mất hạn nợ — khoản nợ rơi khỏi mọi mốc lọc của chính màn này.
            $res = $this->api->traTienPhieuMuaHang($id, $moi, trim((string) ($du['note'] ?? '')), [
                'payment_method' => $du['payment_method'],
                'is_debt' => $moi < $tong - 0.005,
                'debt_due_date' => $this->ngayApi($hienTai->json('data.debt_due_date')),
                // Gõ mới thì lấy cái vừa gõ, bỏ trống thì giữ nguyên cái đang có —
                // KHÔNG ghi đè bằng chuỗi rỗng: API dọn sạch hai trường này khi
                // nhận rỗng, và khoản nợ mất luôn người để gọi khi tới hạn.
                'debt_contact_name' => trim((string) ($du['contact_name'] ?? ''))
                    ?: (string) ($hienTai->json('data.debt_contact_name') ?? ''),
                'debt_contact_phone' => trim((string) ($du['contact_phone'] ?? ''))
                    ?: (string) ($hienTai->json('data.debt_contact_phone') ?? ''),
                // Ảnh uỷ nhiệm chi của LƯỢT NÀY. Bỏ trống thì giữ ảnh cũ chứ
                // không ghi đè bằng chuỗi rỗng — mỗi lượt trả một chứng từ, xoá
                // mất chứng từ của lượt trước là mất bằng chứng đã trả.
                'payment_attachment' => trim((string) ($du['payment_attachment'] ?? ''))
                    ?: (string) ($hienTai->json('data.payment_attachment') ?? ''),
            ]);
        } catch (\Throwable $e) {
            Log::error('Tra no failed', ['msg' => $e->getMessage(), 'id' => $id]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai(
                $request,
                true,
                'Đã ghi nhận khoản trả.',
                fn () => redirect()->route('admin.cong-no.index')
            )
            : $this->traLoiHopThoai(
                $request,
                false,
                $this->cauLoiApi($res, 'Ghi nhận không thành công.'),
                null,
                $res->status()
            );
    }

    /** Xuất sổ công nợ ra Excel theo đúng bộ lọc đang bật. */
    public function export(Request $request)
    {
        $query = $this->query($this->filters($request));

        try {
            $list = $this->docHetTrang(fn (array $q) => $this->api->congNo($q), $query);
        } catch (\Throwable $e) {
            Log::error('Export cong no failed', ['msg' => $e->getMessage()]);

            return back()->with('error', $e instanceof \RuntimeException ? $e->getMessage() : 'Không kết nối được API để xuất tệp.');
        }

        $hang = [[
            'STT', 'Mã phiếu', 'Nhà cung cấp', 'Chi nhánh', 'Tổng tiền', 'Đã trả',
            'Còn nợ', 'Ngày đáo hạn', 'Hạn còn', 'Trạng thái', 'Người đại diện',
            'Điện thoại', 'Người tạo',
        ]];
        foreach ($list as $i => $c) {
            $hang[] = [
                $i + 1,
                (string) ($c['code'] ?? ''),
                (string) ($c['supplier_name'] ?? ''),
                (string) ($c['branch_name'] ?? ''),
                (float) ($c['total_amount'] ?? 0),
                (float) ($c['paid_amount'] ?? 0),
                (float) ($c['remaining'] ?? 0),
                ! empty($c['due_date']) ? date('d-m-Y', strtotime((string) $c['due_date'])) : '',
                $this->chuHan($c),
                __('message.'.(self::TRANG_THAI[$c['status'] ?? ''] ?? 'not_paid')),
                (string) ($c['contact_name'] ?? ''),
                (string) ($c['contact_phone'] ?? ''),
                (string) ($c['created_by_name'] ?? ''),
            ];
        }

        return $this->taiXlsx($hang, 'cong-no-'.date('Ymd-His'), 'Cong no');
    }

    /**
     * Câu chữ của cột "Hạn còn" — một chỗ duy nhất, dùng cho cả bảng lẫn tệp xuất.
     *
     * v2 viết luật này thẳng trong blade rồi chép lại một bản nữa cho tệp xuất,
     * và hai bản đã lệch: bản trong bảng có nhánh "Hết hạn hôm nay", bản xuất
     * Excel thì không.
     */
    public static function chuHan(array $c): string
    {
        if ((float) ($c['remaining'] ?? 0) <= 0.005) {
            return __('message.paid');
        }

        $ngay = $c['days_left'] ?? null;
        if ($ngay === null) {
            return '';
        }

        $ngay = (int) $ngay;
        if ($ngay < 0) {
            return __('message.overdue_days', ['date' => abs($ngay)]);
        }
        if ($ngay === 0) {
            return __('message.expired_today');
        }

        return __('message.remaining_days', ['date' => $ngay]);
    }

    /**
     * Cột "Hạn còn" — DÒNG TRÊN: khoản này đang ở đâu so với hạn.
     *
     * Tách khỏi số ngày để mắt bắt được ngay bằng MÀU và một từ ngắn; con số
     * "12" chỉ có nghĩa sau khi đã đọc xong từ "Quá hạn". v2 nhồi cả hai vào một
     * dòng ("Đã quá hạn 12 ngày") nên cột phải rộng ra và vẫn đọc chậm hơn.
     *
     * Chuỗi để thẳng đây chứ không qua lang: bốn nhãn này là của riêng màn công
     * nợ, cùng lối với LOAI của ThuChiController.
     */
    public static function hanTrangThai(array $c): string
    {
        if ((float) ($c['remaining'] ?? 0) <= 0.005) {
            return __('message.paid');
        }

        // Không có hạn thì gạch ngang chứ không để trắng: một ô trống trơn đọc ra
        // là trang hỏng. (API chỉ cho ghi nợ khi ĐÃ khai hạn, nên đây là lưới an
        // toàn cho dòng cũ sót lại chứ không phải cảnh thường gặp.)
        $ngay = $c['days_left'] ?? null;
        if ($ngay === null) {
            return '—';
        }

        return match (true) {
            (int) $ngay < 0 => __('message.overdue'),
            (int) $ngay === 0 => __('message.expired_today'),
            default => 'Còn hạn',
        };
    }

    /**
     * Cột "Hạn còn" — DÒNG DƯỚI: bao nhiêu ngày.
     *
     * Rỗng ở hai cảnh mà con số không nói thêm được gì: đã trả xong (hạn hết ý
     * nghĩa) và đến hạn ĐÚNG hôm nay (in "0 ngày" chỉ làm người đọc khựng lại).
     */
    public static function hanSoNgay(array $c): string
    {
        if ((float) ($c['remaining'] ?? 0) <= 0.005) {
            return '';
        }

        $ngay = $c['days_left'] ?? null;
        if ($ngay === null || (int) $ngay === 0) {
            return '';
        }

        return abs((int) $ngay).' ngày';
    }

    /** Màu chữ của cột "Hạn còn" — cùng luật với chuHan(). */
    public static function mauHan(array $c): string
    {
        if ((float) ($c['remaining'] ?? 0) <= 0.005) {
            return 'text-success';
        }

        $ngay = $c['days_left'] ?? null;
        if ($ngay === null) {
            return '';
        }

        return match (true) {
            (int) $ngay < 0 => 'text-danger',
            (int) $ngay === 0 => 'text-warning',
            default => '',
        };
    }

    // ---------------------------------------------------------------------
    // Bộ lọc
    // ---------------------------------------------------------------------

    /** Bộ lọc của trang, đã gạn giá trị lạ. */
    protected function filters(Request $request): array
    {
        $size = (int) $request->query('page_size', self::SO_DONG_MOI_TRANG);
        $due = (string) $request->query('due', 'all');

        return [
            // HAI ô tìm riêng, đúng như v2: tên đối tượng và mã. Gộp làm một ô thì
            // gõ tên nhà cung cấp cũng quét cả cột mã, và người dùng cũ mất chỗ
            // quen tay — bên đó hai ô nằm cạnh nhau ở đầu khung lọc.
            'supplier_name' => trim((string) $request->query('supplier_name', '')),
            'code' => trim((string) $request->query('code', '')),
            'supplier_id' => $this->locNhieuSo($request->query('supplier_id')),
            'created_by' => $this->locNhieuSo($request->query('created_by')),
            'status' => $this->locNhieu($request->query('status'), array_keys(self::TRANG_THAI_LOC)),
            'due' => array_key_exists($due, self::MOC_HAN) ? $due : 'all',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($size, self::MUC_SO_DONG, true) ? $size : self::SO_DONG_MOI_TRANG,
        ];
    }

    /** Đổi bộ lọc của trang thành query API. */
    protected function query(array $f): array
    {
        return [
            'supplier_name' => $f['supplier_name'],
            'code' => $f['code'],
            'supplier_id' => $f['supplier_id'],
            'created_by' => $f['created_by'],
            'status' => $f['status'],
            'due' => $f['due'],
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
            Log::error('Load tai khoan cho cong no failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Ô "Nhà cung cấp" của khung lọc. */
    protected function danhMucNhaCungCap(): array
    {
        try {
            $res = $this->api->nhaCungCap(['status' => 'active', 'page_size' => 200]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('Load nha cung cap cho cong no failed', ['msg' => $e->getMessage()]);

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

    /** Ngày API trả về (có thể kèm giờ) → YYYY-MM-DD, rỗng thì trả chuỗi rỗng. */
    protected function ngayApi($v): string
    {
        $v = trim((string) $v);

        return $v === '' ? '' : date('Y-m-d', strtotime($v));
    }
}
