<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWorkspace;
use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lịch sử đơn — những đơn do CHÍNH quầy bán ra (khuôn order-cashier/history của v2).
 *
 * Không dùng trang Đơn hàng bên quản trị: đơn quầy xong ngay lúc tạo, người
 * trực chỉ quay lại để tìm một đơn khách đang hỏi, in lại phiếu, thu nốt tiền
 * nợ hay xuất hoá đơn. Mặc định lọc HÔM NAY — người đứng quầy hỏi "đơn vừa nãy",
 * không hỏi "đơn tháng trước".
 *
 * Nguồn là SỔ CHỨNG TỪ (`/admin/orders/so-don`): dòng đã quy sẵn tiền theo hình
 * thức, số còn nợ và năm trạng thái của v2. Màn ĐỌC HẾT các dòng của kỳ rồi tự
 * đếm / cộng / lọc trạng thái và người bán, vì:
 *   - số trên năm tab trạng thái phải đếm trên cả kỳ, không riêng trang đang xem;
 *   - hàng tổng của API cộng lẫn tiền HOÀN của phiếu trả vào cột hình thức, mà
 *     thu ngân đối két cần tách "đã thu" với "đã hoàn";
 *   - lọc theo người bán bằng id cần danh bạ nhân viên, đường đó thu ngân không gọi được.
 * Kỳ mặc định là một ngày nên vài trăm dòng là nhiều; kỳ dài bị chặn ở MAX_DONG.
 */
class CashierController extends Controller
{
    use Concerns\V2ScreenFallback;

    public const TITLE = 'Lịch sử đơn hàng';

    public const PAGE_SIZE = 20;

    /** Trần số dòng đọc cho MỘT lượt xem — xem docSo(). */
    public const MAX_DONG = 2000;

    /** Kỳ nhanh trong ô chọn kỳ — thứ tự hiển thị. `custom` = tự chọn từ ngày – đến ngày. */
    public const KY = [
        'today' => 'Hôm nay', 'yesterday' => 'Hôm qua', 'last7' => '7 ngày qua', 'week' => 'Tuần này',
        'month' => 'Tháng này', 'lastmonth' => 'Tháng trước', 'custom' => 'Tuỳ chọn',
    ];

    /** Năm trạng thái của sổ — nhãn rút gọn cho tab; thứ tự như v2. */
    public const TRANG_THAI = [
        'paid' => 'Đã thanh toán', 'unpaid' => 'Chưa thu', 'partial' => 'Còn nợ',
        'returned' => 'Đã trả hàng', 'cancelled' => 'Đã huỷ',
    ];

    /** Bốn nhóm hình thức của quầy — khoá là giá trị lọc API nhận, kèm cột tiền của sổ. */
    public const HINH_THUC = [
        'cash' => ['ten' => 'Tiền mặt', 'ngan' => 'TM', 'cot' => 'tien_mat'],
        'bank_transfer' => ['ten' => 'Chuyển khoản', 'ngan' => 'CK', 'cot' => 'chuyen_khoan'],
        'card' => ['ten' => 'Quẹt thẻ', 'ngan' => 'Thẻ', 'cot' => 'quet_the'],
        'auto_qr' => ['ten' => 'QR tự động', 'ngan' => 'QR', 'cot' => 'qr_tu_dong'],
    ];

    /** Mã phương thức trên đơn thuộc nhóm QR tự động (domain.NhomQRTuDong). */
    public const MA_QR = ['payos', 'sepay', 'mpos_qr'];

    public const HOA_DON = ['co' => 'Đã xuất HĐĐT', 'khong' => 'Chưa xuất HĐĐT'];

    public function __construct(protected ApiClient $api) {}

    public function donHang(Request $request)
    {
        if ($ve = $this->veQuayNeuThieuView('v2::pos.orders')) {
            return $ve;
        }

        $filters = $this->filters($request);
        ['rows' => $tatCa, 'error' => $error, 'cat' => $biCat] = $this->docSo($filters);

        // Danh sách người bán lấy từ chính các dòng của kỳ: chỉ ra người có bán.
        $nguoiBan = collect($tatCa)->pluck('nguoi_tao')->filter()->unique()->sort(fn ($a, $b) => strcoll($a, $b))->values()->all();
        if ($filters['staff'] !== '') {
            $tatCa = array_values(array_filter($tatCa, fn ($r) => ($r['nguoi_tao'] ?? '') === $filters['staff']));
        }

        $dem = array_fill_keys(array_keys(self::TRANG_THAI), 0);
        foreach ($tatCa as $r) {
            $dem[$r['trang_thai'] ?? ''] = ($dem[$r['trang_thai'] ?? ''] ?? 0) + 1;
        }
        $dem[''] = count($tatCa);

        $rows = $filters['status'] === ''
            ? $tatCa
            : array_values(array_filter($tatCa, fn ($r) => ($r['trang_thai'] ?? '') === $filters['status']));

        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($filters['page'], $pages);
        $list = array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return view('v2::pos.orders', [
            'list' => $list,
            'filters' => $filters,
            'meta' => ['page' => $page, 'page_size' => self::PAGE_SIZE, 'total' => $total, 'total_pages' => $pages],
            'dem' => $dem,
            'tong' => self::tongKet($rows),
            'theoNgay' => self::theoNgay($rows),
            'nguoiBan' => $nguoiBan,
            'biCat' => $biCat,
            'coQuanLy' => in_array('quan_ly', EnsureWorkspace::cuaCuaPhien(), true),
            'error' => $error,
        ]);
    }

    /**
     * Chi tiết một đơn cho cột bên phải — JSON, kèm tờ hoá đơn điện tử nếu có.
     *
     * Chỉ trả đơn QUẦY: mở id một đơn giao hàng ở đây là đọc được đơn của module
     * khác qua cửa Thu ngân.
     */
    public function chiTiet(int $id)
    {
        try {
            $res = $this->api->order($id);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai chi tiet don that bai', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }

        $don = $res->successful() ? (array) $res->json('data') : [];
        if (($don['channel'] ?? '') !== 'pos') {
            return response()->json(['message' => 'Không tìm thấy đơn quầy này.'], 404);
        }

        // Hoá đơn hỏng đọc thì coi như chưa có: cột chi tiết không được trắng chỉ
        // vì cổng hoá đơn trả một thứ lạ (như OrderController::hoaDonCuaDon).
        $hoaDon = null;
        try {
            $hd = $this->api->hoaDonCuaDon($id);
            $hoaDon = $hd->successful() ? $hd->json('data') : null;
        } catch (\Throwable $e) {
            Log::warning('Thu ngan: doc hoa don cua don that bai', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return response()->json(['data' => $don, 'etax' => $hoaDon]);
    }

    /**
     * Thu nợ: ghi MỘT lượt thu cho đơn còn thiếu. Thu nốt phần còn lại thì API tự
     * đóng đơn sang đã thanh toán; thu quá phần còn nợ thì API từ chối (422).
     * Không có QR tự động: tiền QR do cổng báo về và tự ghi.
     */
    public function thuNo(Request $request, int $id)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|in:cash,bank_transfer,card',
            'note' => 'nullable|string|max:255',
        ]);

        try {
            $res = $this->api->ghiLuotThu($id, [
                'amount' => (float) $data['amount'],
                'payment_method' => $data['payment_method'],
                'note' => (string) ($data['note'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: thu no that bai', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được API. Vui lòng thử lại.'], 502);
        }

        if (! $res->successful()) {
            $loi = $res->json('errors');

            return response()->json(
                ['message' => is_array($loi) && $loi ? implode(' ', array_map('strval', array_values($loi))) : ($res->json('message') ?: 'Không ghi được lượt thu.')],
                $res->status() === 500 ? 502 : $res->status()
            );
        }

        return response()->json(['message' => 'Đã ghi lượt thu.', 'data' => $res->json('data')]);
    }

    /** Xuất CSV đúng bộ lọc đang xem — mọi trang, không riêng trang hiện tại. */
    public function xuat(Request $request)
    {
        $filters = $this->filters($request);
        $rows = $this->docSo($filters)['rows'];
        if ($filters['staff'] !== '') {
            $rows = array_filter($rows, fn ($r) => ($r['nguoi_tao'] ?? '') === $filters['staff']);
        }
        if ($filters['status'] !== '') {
            $rows = array_filter($rows, fn ($r) => ($r['trang_thai'] ?? '') === $filters['status']);
        }
        $rows = array_values($rows);
        $tong = self::tongKet($rows);

        return response()->streamDownload(function () use ($rows, $tong) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['STT', 'Mã', 'Loại', 'Thời gian', 'Khách hàng', 'Số điện thoại', 'Thu ngân',
                'Tổng tiền', 'Tiền mặt', 'Chuyển khoản', 'Quẹt thẻ', 'QR tự động', 'Còn nợ', 'Trạng thái']);
            foreach ($rows as $i => $r) {
                $laTra = ($r['loai'] ?? 'don') === 'tra-hang';
                fputcsv($out, [
                    $i + 1,
                    $r['ma'] ?? '',
                    $laTra ? 'Phiếu trả hàng' : 'Đơn bán',
                    ! empty($r['created_at']) ? Carbon::parse($r['created_at'])->format('d/m/Y H:i') : '',
                    $r['khach_hang'] ?? '', $r['so_dien_thoai'] ?? '', $r['nguoi_tao'] ?? '',
                    (float) ($r['tong_tien'] ?? 0),
                    (float) ($r['tien_mat'] ?? 0), (float) ($r['chuyen_khoan'] ?? 0),
                    (float) ($r['quet_the'] ?? 0), (float) ($r['qr_tu_dong'] ?? 0),
                    (float) ($r['con_no'] ?? 0),
                    self::nhanTrangThai($r),
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['', 'Đã thu', '', '', '', '', '', $tong['da_thu'],
                $tong['theo']['cash'], $tong['theo']['bank_transfer'], $tong['theo']['card'], $tong['theo']['auto_qr'], $tong['con_no'], '']);
            fputcsv($out, ['', 'Đã hoàn', '', '', '', '', '', -$tong['da_hoan']]);
            fclose($out);
        }, 'lich-su-don-quay-'.date('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Cộng tiền của các dòng đang xem.
     *
     * "Đã thu" chỉ cộng ĐƠN BÁN còn sống — đơn huỷ không có tiền thật nào ở lại két.
     * Tiền hoàn của phiếu trả đứng riêng ở "Đã hoàn", không trộn vào cột hình thức
     * như hàng tổng của sổ (ở đó một lượt hoàn 270.000 tiền mặt làm cột Tiền mặt
     * TĂNG 270.000).
     */
    public static function tongKet(array $rows): array
    {
        $theo = array_fill_keys(array_keys(self::HINH_THUC), 0.0);
        $daThu = $daHoan = $conNo = 0.0;
        $soDon = 0;
        foreach ($rows as $r) {
            $tien = array_sum(array_map(fn ($h) => (float) ($r[$h['cot']] ?? 0), self::HINH_THUC)) + (float) ($r['khac'] ?? 0);
            if (($r['loai'] ?? 'don') === 'tra-hang') {
                $daHoan += $tien;

                continue;
            }
            $soDon++;
            if (($r['trang_thai'] ?? '') === 'cancelled') {
                continue;
            }
            $daThu += (float) ($r['da_thu'] ?? 0);
            $conNo += (float) ($r['con_no'] ?? 0);
            foreach (self::HINH_THUC as $k => $h) {
                $theo[$k] += (float) ($r[$h['cot']] ?? 0);
            }
        }

        return ['so_don' => $soDon, 'da_thu' => $daThu, 'theo' => $theo, 'da_hoan' => $daHoan, 'con_no' => $conNo];
    }

    /**
     * Số dòng và tiền thu ròng (đã thu − đã hoàn) của TỪNG NGÀY trên cả tập đang xem —
     * dòng tiêu đề ngày của danh sách đọc ở đây, không đếm riêng trang hiện tại.
     */
    public static function theoNgay(array $rows): array
    {
        $ngay = [];
        foreach ($rows as $r) {
            $k = ! empty($r['created_at']) ? Carbon::parse($r['created_at'])->toDateString() : '';
            $ngay[$k] ??= ['so' => 0, 'thu' => 0.0];
            $ngay[$k]['so']++;
            $t = self::tongKet([$r]);
            $ngay[$k]['thu'] += $t['da_thu'] - $t['da_hoan'];
        }

        return $ngay;
    }

    /** Đơn chờ tiền QR: sổ ghi "chưa thu" và phương thức trên đơn là một cổng QR. */
    public static function choQr(array $r): bool
    {
        return ($r['loai'] ?? 'don') === 'don' && ($r['trang_thai'] ?? '') === 'unpaid'
            && in_array($r['phuong_thuc'] ?? '', self::MA_QR, true);
    }

    public static function nhanTrangThai(array $r): string
    {
        if (($r['loai'] ?? 'don') === 'tra-hang') {
            return 'Phiếu trả';
        }
        if (self::choQr($r)) {
            return 'Chờ QR';
        }

        return self::TRANG_THAI[$r['trang_thai'] ?? ''] ?? (string) ($r['trang_thai'] ?? '');
    }

    /** Nhóm hình thức của một mã phương thức trên đơn / phiếu (null = ngoài bốn nhóm). */
    public static function nhomHinhThuc(?string $ma): ?string
    {
        return match (true) {
            $ma === 'cash' => 'cash',
            $ma === 'bank_transfer' => 'bank_transfer',
            $ma === 'card' => 'card',
            in_array($ma, self::MA_QR, true) => 'auto_qr',
            default => null,
        };
    }

    /**
     * Đọc hết các trang sổ của kỳ, 100 dòng một lượt, tối đa MAX_DONG.
     *
     * @return array{rows: array, error: ?string, cat: bool}
     */
    protected function docSo(array $f): array
    {
        // Ô tìm gửi vào ĐÚNG một ô của API: `keyword` chỉ khớp mã đơn / mã phiếu,
        // `customer` khớp tên + SĐT khách. Gửi cả hai thì API AND lại và không ra gì.
        $q = $f['q'];
        $laMa = $q !== '' && (preg_match('/^[A-Za-z]{1,6}[-\d]*\d$/', $q) || (ctype_digit($q) && strlen($q) < 7));
        $query = [
            // KHOÁ CỨNG channel=pos: một đơn giao hàng lọt vào đây thì người
            // trực tưởng mình phải làm gì đó với nó, mà nút xử lý ở module kia.
            'channel' => 'pos',
            'keyword' => $laMa ? $q : '',
            'customer' => $q !== '' && ! $laMa ? $q : '',
            'payment_method' => $f['pm'] !== '' ? $f['pm'] : 'all',
            'etax' => $f['einv'] !== '' ? $f['einv'] : 'all',
            'from_date' => $f['from_date'],
            'to_date' => $f['to_date'],
            'sort' => 'newest',
            'page' => 1,
            'page_size' => 100,
        ];

        $rows = [];
        $tongTrang = 1;
        try {
            do {
                $res = $this->api->soDonHang($query);
                if (! $res->successful()) {
                    return ['rows' => $rows, 'error' => $res->json('message') ?: 'Không tải được lịch sử đơn.', 'cat' => false];
                }
                $rows = array_merge($rows, $res->json('data') ?? []);
                $tongTrang = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $tongTrang && count($rows) < self::MAX_DONG);
        } catch (\Throwable $e) {
            Log::error('Thu ngan: tai so don quay that bai', ['msg' => $e->getMessage()]);

            return ['rows' => $rows, 'error' => 'Không tải được lịch sử đơn. Kiểm tra kết nối API.', 'cat' => false];
        }

        return ['rows' => array_slice($rows, 0, self::MAX_DONG), 'error' => null, 'cat' => $query['page'] <= $tongTrang];
    }

    protected function filters(Request $request): array
    {
        $ngay = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        // `keyword` là tên cũ của ô tìm — đường dẫn đã lưu vẫn mở được.
        $q = trim((string) $request->query('q', $request->query('keyword', '')));
        $ky = (string) $request->query('period', '');
        $tu = $ngay($request->query('from_date'));
        $den = $ngay($request->query('to_date'));
        if (! isset(self::KY[$ky])) {
            $ky = ($tu !== '' || $den !== '') ? 'custom' : 'today';
        }

        $homNay = Carbon::today();
        [$tu, $den] = match ($ky) {
            'today' => [$homNay->toDateString(), $homNay->toDateString()],
            'yesterday' => [$homNay->copy()->subDay()->toDateString(), $homNay->copy()->subDay()->toDateString()],
            'last7' => [$homNay->copy()->subDays(6)->toDateString(), $homNay->toDateString()],
            'week' => [$homNay->copy()->startOfWeek(Carbon::MONDAY)->toDateString(), $homNay->toDateString()],
            'lastmonth' => [$homNay->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $homNay->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'month' => [$homNay->copy()->startOfMonth()->toDateString(), $homNay->toDateString()],
            default => [$tu, $den],
        };
        if ($ky === 'custom' && $tu === '' && $den === '') {
            $tu = $den = $homNay->toDateString();
        }
        // Chọn ngược (đến ngày trước từ ngày) thì đảo lại, không trả bảng rỗng.
        if ($tu !== '' && $den !== '' && $tu > $den) {
            [$tu, $den] = [$den, $tu];
        }

        // Có từ khoá thì tìm MỌI ngày: gõ mã đơn là tìm đúng đơn đó, kẹp kỳ vào là
        // báo "không thấy" cho một đơn đang nằm ngay trong sổ.
        if ($q !== '') {
            $tu = $den = '';
        }

        $chon = fn (string $k, array $hopLe) => in_array($v = (string) $request->query($k, ''), $hopLe, true) ? $v : '';

        return [
            'q' => $q,
            'period' => $ky,
            'from_date' => $tu,
            'to_date' => $den,
            'status' => $chon('status', array_keys(self::TRANG_THAI)),
            'staff' => trim((string) $request->query('staff', '')),
            'pm' => $chon('pm', array_keys(self::HINH_THUC)),
            'einv' => $chon('einv', array_keys(self::HOA_DON)),
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }
}
