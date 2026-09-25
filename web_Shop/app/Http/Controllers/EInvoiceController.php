<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Thống kê → Hoá đơn điện tử — sổ mọi tờ hoá đơn đã phát hành, đứng ngay cạnh
 * Quản lý đơn hàng như bản v2 (system/etax-invoice).
 *
 * Màn này chỉ ĐỌC sổ. Mọi lượt thao tác trên một tờ (đồng bộ, ký, phát hành
 * lại, tải PDF) đi qua đúng các đường của đơn hàng đã có — /orders/{id}/etax/…
 * — vì hoá đơn là của một đơn, và hai cửa cho cùng một việc là hai chỗ lệch.
 */
class EInvoiceController extends Controller
{
    public const TITLE = 'Hoá đơn điện tử';

    /**
     * Bốn trạng thái của tờ hoá đơn (domain.HoaDon*), theo thứ tự hàng nút lọc.
     *
     * Bên v2 bày tám mã của nhà cung cấp; cổng của mình gom còn bốn, và bốn cái
     * này mới là thứ quyết định việc phải làm: tờ nháp thì ký, tờ đã gửi thì chờ
     * cơ quan thuế cấp mã, tờ hỏng thì phát hành lại.
     */
    public const TRANG_THAI = [
        'issued' => 'Đã cấp mã',
        'sent' => 'Chờ CQT cấp mã',
        'draft' => 'Nháp — chưa ký',
        'failed' => 'Lỗi',
    ];

    public const MAU_TRANG_THAI = [
        'issued' => 'text-success',
        'sent' => 'text-primary',
        'draft' => 'text-warning',
        'failed' => 'text-danger',
    ];

    /**
     * Loại tờ (`doc_status`, domain.To*) — in NHỎ dưới trạng thái. Tờ gốc (0)
     * không in gì: đó là trường hợp thường, dán nhãn cho nó chỉ thêm chữ lặp.
     * Tờ đã bị thay thế hay bị điều chỉnh thì PHẢI nói ra: nó vẫn "đã cấp mã"
     * nhưng không còn là tờ có hiệu lực của đơn nữa.
     */
    public const LOAI_TO = [
        1 => 'Đã huỷ',
        2 => 'Tờ điều chỉnh',
        3 => 'Tờ thay thế',
        5 => 'Bị điều chỉnh',
        6 => 'Bị thay thế',
    ];

    /** Cột bật/tắt được — đúng bộ cột của v2 (ký hiệu, số, mã, trạng thái CQT, …). */
    public const COT_BANG = [
        'symbol' => 'Ký hiệu',
        'invoice_no' => 'Số hoá đơn',
        'tax_code' => 'Mã CQT',
        'order_code' => 'Mã đơn',
        'status' => 'Trạng thái CQT',
        'issued_at' => 'Ngày phát hành',
        'customer' => 'Khách hàng',
        'email' => 'Email',
        'vat' => 'Tiền thuế',
        'total' => 'Tổng tiền',
        'creator' => 'Người tạo',
    ];

    public const PAGE_SIZES = [20, 50, 100];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $hoaDon = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1, 'dem' => []];
        $error = null;

        try {
            $res = $this->api->soHoaDonDienTu($this->truyVan($filters));
            if ($res->successful()) {
                $hoaDon = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load etax invoices failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được sổ hoá đơn điện tử.';
            }
        } catch (\Throwable $e) {
            Log::error('Load etax invoices failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được sổ hoá đơn điện tử. Kiểm tra kết nối API.';
        }

        $view = view('v2::e-invoices.index', compact('hoaDon', 'filters', 'meta'))
            ->with('nhanVien', $this->danhMucNhanVien());

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Xuất sổ hoá đơn theo bộ lọc đang bật — cùng cột với bảng, như bản xuất
     * Excel của v2 (ETaxInvoiceExport).
     */
    public function export(Request $request)
    {
        $rows = $this->docHet($this->filters($request));
        $fileName = 'hoa-don-dien-tu-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['STT', 'Ký hiệu', 'Số hoá đơn', 'Mã CQT', 'Mã đơn', 'Trạng thái CQT', 'Ngày phát hành',
                'Khách hàng', 'Email', 'Số điện thoại', 'Tiền thuế', 'Tổng tiền', 'Người tạo']);

            foreach ($rows as $i => $h) {
                fputcsv($out, [
                    $i + 1,
                    $h['symbol'] ?? '', $h['invoice_no'] ?? '', $h['tax_auth_code'] ?? '', $h['order_code'] ?? '',
                    self::TRANG_THAI[$h['status'] ?? ''] ?? ($h['status'] ?? ''),
                    self::ngayPhatHanh($h, 'd/m/Y H:i'),
                    $h['customer_name'] ?? '', $h['customer_email'] ?? '', $h['customer_phone'] ?? '',
                    (float) ($h['vat_amount'] ?? 0), (float) ($h['total_amount'] ?? 0),
                    $h['nguoi_tao'] ?? '',
                ]);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Ngày in ra cột "Ngày phát hành": lúc được cấp số; tờ chưa được cấp (nháp,
     * hỏng) thì lúc lập lượt phát hành — đúng mốc mà bộ lọc ngày của API dùng.
     */
    public static function ngayPhatHanh(array $h, string $dang = 'd/m/Y'): string
    {
        $moc = ($h['issued_at'] ?? null) ?: ($h['created_at'] ?? null);

        return $moc ? Carbon::parse($moc)->format($dang) : '';
    }

    protected function filters(Request $request): array
    {
        $psize = (int) $request->query('page_size', 20);
        $tt = (string) $request->query('status', 'all');

        return [
            'symbol' => trim((string) $request->query('symbol', '')),
            'invoice_no' => trim((string) $request->query('invoice_no', '')),
            'code' => trim((string) $request->query('code', '')),
            'customer' => trim((string) $request->query('customer', '')),
            // Hàng nút lọc chọn MỘT trạng thái một lúc, như v2 (`filter`).
            'status' => isset(self::TRANG_THAI[$tt]) ? $tt : 'all',
            'created_by' => $this->locNhieuSo($request->query('created_by')),
            'from_date' => $this->ngayLoc($request->query('from_date')),
            'to_date' => $this->ngayLoc($request->query('to_date')),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($psize, self::PAGE_SIZES, true) ? $psize : 20,
        ];
    }

    /** Tham số gửi sang API — bỏ các ô trống để URL gọn và nhật ký dễ đọc. */
    protected function truyVan(array $filters): array
    {
        return array_filter($filters, fn ($v) => $v !== '' && $v !== 'all');
    }

    /** Đọc hết các trang cho bản xuất. Chặn ở 100 trang × 100 dòng. */
    protected function docHet(array $filters): array
    {
        $all = [];
        $query = array_merge($this->truyVan($filters), ['page' => 1, 'page_size' => 100]);
        $totalPages = 1;
        try {
            do {
                $res = $this->api->soHoaDonDienTu($query);
                if (! $res->successful()) {
                    break;
                }
                $all = array_merge($all, $res->json('data') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $totalPages && $query['page'] <= 100);
        } catch (\Throwable $e) {
            Log::error('Export etax invoices failed', ['msg' => $e->getMessage()]);
        }

        return $all;
    }

    /**
     * Danh sách nhân viên cho ô lọc "Người tạo". Hỏng thì trả rỗng: thiếu một ô
     * lọc còn xem được sổ, mất cả sổ thì không.
     */
    protected function danhMucNhanVien(): array
    {
        try {
            $res = $this->api->users(['status' => 'active', 'page_size' => 100]);

            return $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('Load danh muc nhan vien cho loc hoa don failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Ô lọc mang id (Người tạo): bỏ giá trị lạ, gộp bằng dấu phẩy; trống = 'all'. */
    protected function locNhieuSo($v): string
    {
        $chon = array_filter(
            array_map('intval', explode(',', (string) $v)),
            fn ($x) => $x > 0
        );

        return $chon ? implode(',', $chon) : 'all';
    }

    /** Ô ngày của khung lọc v2 gửi dd-mm-yyyy; API đọc yyyy-mm-dd. Nhận cả hai. */
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
}
