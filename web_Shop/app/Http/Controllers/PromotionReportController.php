<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Báo cáo → Báo cáo chương trình khuyến mại — khuôn report/promotion-report của
 * bản v2: cùng dãy tab (bỏ tab "Khách hàng" vì quầy shop không có chương trình
 * theo số khách, thêm Voucher/Coupon như bản CRM của v2), cùng nhãn cột, cùng
 * hộp chi tiết khi bấm tên chương trình.
 *
 * Khác v2: số liệu là của ĐƠN TRONG KỲ đang lọc (v2 lấy lượt dùng cả đời của
 * chương trình nên đổi kỳ mà số không đổi), và mỗi chương trình nhận đúng phần
 * tiền nó giảm trên đơn (order_promotions, migration 0075).
 *
 * Mọi con số gộp sẵn ở GET /admin/reports/promotions bên Go; hộp chi tiết gọi
 * /admin/reports/promotions/orders.
 */
class PromotionReportController extends Controller
{
    public const TITLE = 'Báo cáo chương trình khuyến mại';

    public const KY_MAC_DINH = 'thisMonth';

    public const TAB = [
        'hoa-don' => 'Phiếu bán hàng',
        'hang-hoa' => 'Hàng hóa',
        'nhom-hang' => 'Nhóm hàng hóa',
        'dong-gia' => 'Đồng giá',
        'voucher' => 'Voucher/Coupon',
    ];

    /** Tiêu đề hộp chi tiết của từng tab — chữ của v2. */
    public const TIEU_DE_HOP = [
        'hoa-don' => 'Chi tiết khuyến mại phiếu bán hàng',
        'hang-hoa' => 'Chi tiết khuyến mại hàng hóa',
        'nhom-hang' => 'Chi tiết khuyến mại nhóm hàng hóa',
        'dong-gia' => 'Chi tiết khuyến mại đồng giá',
        'voucher' => 'Chi tiết báo cáo khuyến mại Voucher/Coupon',
    ];

    private const COT_HANG = [
        'code' => 'Mã chương trình', 'name' => 'Tên chương trình', 'quantity' => 'Tổng hàng hóa',
        'gift_quantity' => 'Hàng tặng', 'order_count' => 'Tổng số phiếu',
        'discount' => 'Tổng giá trị khuyến mại', 'thoi_gian' => 'Thời gian',
    ];

    /** Cột bảng của từng tab, đúng thứ tự và nhãn v2: khoá dòng (xem dong()) → nhãn. */
    public const COT = [
        'hoa-don' => [
            'code' => 'Mã chương trình', 'name' => 'Tên chương trình', 'order_count' => 'Tổng số phiếu',
            'goods_amount' => 'Tổng tiền hàng', 'gift_quantity' => 'Hàng tặng',
            'discount' => 'Tổng giá trị khuyến mại', 'thoi_gian' => 'Thời gian',
        ],
        'hang-hoa' => self::COT_HANG,
        'nhom-hang' => self::COT_HANG,
        'dong-gia' => [
            'code' => 'Mã chương trình', 'name' => 'Tên chương trình', 'thoi_gian' => 'Thời gian',
            'order_count' => 'Số lượng hóa đơn', 'quantity' => 'Tổng số lượng hàng hóa',
            'gift_quantity' => 'Hàng tặng', 'discount' => 'Tổng tiền giảm giá',
        ],
        'voucher' => [
            'code' => 'Mã chương trình', 'name' => 'Tên chương trình', 'thoi_gian' => 'Thời gian',
            'code_count' => 'Số mã phát hành', 'code_used' => 'Đã sử dụng', 'con_lai' => 'Còn lại',
            'order_count' => 'Số hóa đơn', 'discount' => 'Tổng ưu đãi', 'ti_le' => 'Tỉ lệ sử dụng',
        ],
    ];

    /** Cột của hộp chi tiết (một hoá đơn một dòng) — theo modal show-by-* của v2. */
    public const COT_DON = [
        'hoa-don' => [
            'code' => 'Mã hóa đơn', 'khach' => 'Khách hàng', 'goods_amount' => 'Tổng tiền hàng',
            'gift_quantity' => 'Hàng tặng', 'discount' => 'Giá trị giảm', 'total' => 'Tổng tiền thanh toán', 'thoi_gian' => 'Thời gian',
        ],
        'hang-hoa' => [
            'code' => 'Mã hóa đơn', 'khach' => 'Khách hàng', 'quantity' => 'Số lượng hàng hóa', 'gift_quantity' => 'Hàng tặng',
            'goods_amount' => 'Tổng tiền hàng', 'discount' => 'Giá trị giảm', 'total' => 'Tổng tiền thanh toán', 'thoi_gian' => 'Thời gian',
        ],
        'nhom-hang' => [
            'code' => 'Mã hóa đơn', 'khach' => 'Khách hàng', 'quantity' => 'Số lượng hàng hóa', 'gift_quantity' => 'Hàng tặng',
            'goods_amount' => 'Tổng tiền hàng', 'discount' => 'Giá trị giảm', 'total' => 'Tổng tiền thanh toán', 'thoi_gian' => 'Thời gian',
        ],
        'dong-gia' => [
            'code' => 'Mã hóa đơn', 'khach' => 'Khách hàng', 'quantity' => 'Số lượng', 'gift_quantity' => 'Hàng tặng',
            'base_amount' => 'Giá gốc', 'discount' => 'Giá trị giảm', 'total' => 'Tổng tiền thanh toán', 'thoi_gian' => 'Thời gian',
        ],
        'voucher' => [
            'code' => 'Mã hóa đơn', 'voucher_code' => 'Mã voucher', 'khach' => 'Khách hàng', 'goods_amount' => 'Tổng tiền hàng',
            'discount' => 'Giá trị giảm', 'total' => 'Tổng tiền thanh toán', 'thoi_gian' => 'Thời gian',
        ],
    ];

    /** Cột chữ — không cộng ở dòng tổng, canh trái. Còn lại là số, canh phải. */
    public const COT_CHU = ['code', 'name', 'thoi_gian', 'khach', 'voucher_code'];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        [$bc, $error] = $this->doc($filters);

        $view = view('v2::reports.promotions', [
            'filters' => $filters,
            'rows' => array_map([$this, 'dong'], $bc['rows'] ?? []),
            'tong' => $this->dong($bc['totals'] ?? []),
            'chiNhanh' => CurrentBranch::danhSach()['ds'],
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Hộp chi tiết: các hoá đơn của một chương trình trong kỳ, trả JSON. */
    public function orders(Request $request)
    {
        $filters = $this->filters($request);
        $id = (int) $request->query('program_id', 0);
        if ($id <= 0) {
            return response()->json(['message' => 'Thiếu chương trình cần xem.'], 422);
        }

        [$don, $error, $ma] = $this->docDon($filters, $id);
        if ($error !== null) {
            return response()->json(['message' => $error], $ma);
        }

        return response()->json([
            'data' => $don,
            'ky' => ReportController::kyChu($filters, CurrentBranch::danhSach()['ds']),
        ]);
    }

    /**
     * Nút "Xuất báo cáo" (bảng chương trình của tab) và "Xuất Excel" trong hộp
     * chi tiết (có program_id: các hoá đơn của chương trình) — .xlsx thật, cột số
     * ghi kiểu SỐ, dòng cuối là dòng tổng như trên màn.
     */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $tab = $filters['tab'];
        $id = (int) $request->query('program_id', 0);
        $ten = 'bao-cao-khuyen-mai-'.$tab.'-'.$filters['from_date'].'-den-'.$filters['to_date'];

        if ($id > 0) {
            [$dong] = $this->docDon($filters, $id);
            $tong = $this->congDon($dong, $tab);

            return $this->taiXlsx($this->bangXuat(self::COT_DON[$tab], $dong, $tong), $ten.'-ct'.$id, 'Chi tiet khuyen mai');
        }

        [$bc] = $this->doc($filters);

        return $this->taiXlsx(
            $this->bangXuat(self::COT[$tab], array_map([$this, 'dong'], $bc['rows'] ?? []), $this->dong($bc['totals'] ?? [])),
            $ten,
            'Bao cao khuyen mai'
        );
    }

    /** Hàng tiêu đề + từng dòng + dòng "Tổng cộng" (cột chữ để trống). */
    protected function bangXuat(array $cot, array $dong, array $tong): array
    {
        $hang = [array_merge(['STT'], array_values($cot))];
        foreach ($dong as $i => $d) {
            $hang[] = array_merge([$i + 1], array_map(fn ($k) => $d[$k] ?? '', array_keys($cot)));
        }
        $hang[] = array_merge(['Tổng cộng'], array_map(
            fn ($k) => in_array($k, self::COT_CHU, true) ? '' : ($tong[$k] ?? ''),
            array_keys($cot)
        ));

        return $hang;
    }

    /** @return array{0: array, 1: ?string} báo cáo của API và câu lỗi (nếu có) */
    protected function doc(array $filters): array
    {
        try {
            $res = $this->api->reportPromotions($this->truyVan($filters));
            if ($res->successful()) {
                return [$res->json('data') ?? [], null];
            }
            Log::warning('Load promotion report failed', ['status' => $res->status()]);

            return [[], $res->json('message') ?: 'Không tải được báo cáo chương trình khuyến mại.'];
        } catch (\Throwable $e) {
            Log::error('Load promotion report failed', ['msg' => $e->getMessage()]);

            return [[], 'Không tải được báo cáo chương trình khuyến mại. Kiểm tra kết nối API.'];
        }
    }

    /** @return array{0: array, 1: ?string, 2: int} các hoá đơn đã quy đổi, câu lỗi, mã HTTP */
    protected function docDon(array $filters, int $id): array
    {
        try {
            $res = $this->api->reportPromotionOrders($this->truyVan($filters) + ['program_id' => $id]);
        } catch (\Throwable $e) {
            Log::error('Load promotion orders failed', ['msg' => $e->getMessage()]);

            return [[], 'Không tải được hoá đơn của chương trình. Kiểm tra kết nối API.', 502];
        }
        if (! $res->successful()) {
            return [[], $res->json('message') ?: 'Không tải được hoá đơn của chương trình.', $res->status()];
        }

        return [array_map(fn ($d) => [
            'id' => (int) ($d['id'] ?? 0),
            'code' => (string) ($d['code'] ?? ''),
            'voucher_code' => (string) ($d['voucher_code'] ?? ''),
            'khach' => trim((string) ($d['customer_name'] ?? '')) ?: 'Khách lẻ',
            'phone' => (string) ($d['phone'] ?? ''),
            'thoi_gian' => isset($d['created_at']) ? Carbon::parse($d['created_at'])->format('d-m-Y H:i') : '',
            'quantity' => (int) ($d['quantity'] ?? 0),
            'gift_quantity' => (int) ($d['gift_quantity'] ?? 0),
            'goods_amount' => (float) ($d['goods_amount'] ?? 0),
            'base_amount' => (float) ($d['base_amount'] ?? 0),
            'discount' => (float) ($d['discount'] ?? 0),
            'total' => (float) ($d['total'] ?? 0),
        ], $res->json('data') ?? []), null, 200];
    }

    protected function congDon(array $dong, string $tab): array
    {
        $tong = [];
        foreach (array_keys(self::COT_DON[$tab]) as $k) {
            if (! in_array($k, self::COT_CHU, true)) {
                $tong[$k] = array_sum(array_column($dong, $k));
            }
        }

        return $tong;
    }

    /** Một chương trình của API → một dòng bảng (cả dòng tổng, khi không có program_id). */
    public function dong(array $r): array
    {
        $so = fn ($k) => (float) ($r[$k] ?? 0);
        $ngay = fn ($v) => $v ? Carbon::parse($v)->format('d-m-Y') : '…';
        $thoiGian = match (true) {
            empty($r['program_id']) => '',
            ! empty($r['no_time_limit']) => 'Không giới hạn thời gian',
            default => $ngay($r['start_date'] ?? null).' - '.$ngay($r['end_date'] ?? null),
        };
        $phat = (int) ($r['code_count'] ?? 0);
        $daDung = (int) ($r['code_used'] ?? 0);

        return [
            'program_id' => (int) ($r['program_id'] ?? 0),
            'code' => (string) ($r['code'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'da_xoa' => ! empty($r['deleted']),
            'thoi_gian' => $thoiGian,
            'order_count' => (int) ($r['order_count'] ?? 0),
            'goods_amount' => $so('goods_amount'),
            'quantity' => (int) ($r['quantity'] ?? 0),
            'gift_quantity' => (int) ($r['gift_quantity'] ?? 0),
            'discount' => $so('discount'),
            'code_count' => $phat,
            'code_used' => $daDung,
            'con_lai' => max(0, $phat - $daDung),
            'ti_le' => $phat > 0 ? round($daDung * 100 / $phat, 1) : 0.0,
        ];
    }

    /**
     * Bộ lọc chung của nhóm Báo cáo (kỳ + chi nhánh, xem locCuoiNgay) cộng tab
     * và ô tìm. Mở trang lần đầu thì xem "Tháng này" như Báo cáo kết ca —
     * chương trình khuyến mại chạy theo đợt, xem một ngày thì hầu như trống.
     */
    protected function filters(Request $request): array
    {
        if (! $request->filled('range') && ! ($request->filled('from_date') && $request->filled('to_date'))) {
            $request->query->set('range', self::KY_MAC_DINH);
        }
        $tab = (string) $request->query('tab', '');

        return ReportController::locCuoiNgay($request) + [
            'tab' => isset(self::TAB[$tab]) ? $tab : array_key_first(self::TAB),
            'keyword' => trim((string) $request->query('keyword', '')),
        ];
    }

    protected function truyVan(array $filters): array
    {
        return array_filter([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'shop_id' => $filters['shop_id'],
            'tab' => $filters['tab'],
            'keyword' => $filters['keyword'],
        ], fn ($v) => $v !== '' && $v !== null);
    }
}
