<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * KhachHangOrderController — khu "QLTK khách hàng order" của khu điều hành.
 *
 * Năm màn hình, tất cả đóng khung trong ĐÚNG MỘT phần mềm: Sellio Order (mã
 * `order`). Nền tảng bán nhiều phần mềm, nên mọi lần gọi API ở đây đều kèm
 * ?app=order — bỏ tham số đó ra thì màn hình lặng lẽ gộp thêm khách của phần
 * mềm khác vào, và cái sai đó không hiện ra ở đâu cả.
 *
 * Nguồn dữ liệu (không có bảng nào ở Laravel, app này không nối MySQL):
 *   Người dùng thử       GET /platform/subscriptions?app=order&nhom=dung_thu
 *   Người dùng chính thức GET /platform/subscriptions?app=order&nhom=chinh_thuc
 *   Các gói dịch vụ      GET /platform/plans?app=order
 *   Tính năng gói        GET /platform/plans?app=order  (dựng bảng so sánh)
 *                        PUT /platform/plans/{id}/features
 *   Database             GET /platform/tenants?app=order
 *
 * "Database" ở đây là SỔ KHÁCH HÀNG — bản ghi từng khách của phần mềm Order,
 * đứng độc lập với hợp đồng: một khách có thể có nhiều hợp đồng nối nhau, và
 * vẫn còn trong sổ sau khi hợp đồng cuối đã hết. Không phải màn hình quản trị
 * cơ sở dữ liệu máy chủ; Go API không mở đường nào cho việc đó.
 */
class KhachHangOrderController extends Controller
{
    /** Mã phần mềm của cả khu này. Xem chú thích đầu lớp về việc vì sao luôn phải kèm. */
    public const APP = 'order';

    public function __construct(protected ApiClient $api) {}

    /**
     * Người dùng thử — hợp đồng đang trong thời gian dùng thử.
     *
     * Màn hình này khác "Người dùng chính thức" ở chỗ nó còn TẠO được: nút "Thêm
     * tài khoản dùng thử" cần bảng giá để dựng bộ chọn gói, nên trang gọi thêm
     * một lượt /platform/plans. Trang kia không có nút đó nên không gọi.
     *
     * Bảng giá hỏng KHÔNG chặn màn hình: danh sách khách vẫn là việc chính, và
     * mất bộ chọn gói chỉ nghĩa là tạm thời chưa mở tài khoản mới được. Vì vậy
     * lỗi của lượt gọi này gom riêng chứ không trộn vào $loi của bảng.
     */
    public function nguoiDungThu()
    {
        [$dataGoi, $loiGoi] = $this->goi('bảng giá', fn () => $this->api->plans(self::APP));

        // Chỉ gói ĐANG BÁN mới ký mới được — API cũng từ chối gói 'retired', nên
        // để chúng trong bộ chọn là mời người ta chọn một thứ chắc chắn hỏng.
        $plans = collect(data_get($dataGoi, 'plans') ?: [])
            ->filter(fn ($p) => data_get($p, 'status') === 'active')
            ->values()
            ->all();

        return $this->manHinhHopDong(
            nhom: 'dung_thu',
            tieuDe: 'Người dùng thử',
            moTa: 'Mọi khách đã mở tài khoản dùng thử Sellio Order — kể cả kỳ thử đã hết hạn hoặc đã huỷ. Sắp theo ngày hết hạn gần nhất trước.',
            them: [
                'plans' => $plans,
                'loiGoi' => $loiGoi,
            ],
        );
    }

    /**
     * Mở tài khoản dùng thử: cửa hàng + tài khoản đăng nhập + hợp đồng thử.
     *
     * Kiểm hình dạng Ở ĐÂY chứ không phó mặc cho API, dù API vẫn kiểm lại. Lý do
     * không phải là an toàn mà là CÂU CHỮ: lỗi binding của Go trả về tên trường
     * Go ("MaCuaHang"), còn form thì gắn lỗi theo tên ô ("ma_cua_hang"). Chặn
     * những lỗi hình dạng thường gặp ở đây nghĩa là ô sai được tô đỏ đúng chỗ.
     *
     * Ba quy tắc dưới đây phải khớp Go (service.maCuaHangRe / usernameRe) và
     * `cmd/tao-admin`: lệch nhau thì cửa hàng tạo ở đây không đăng nhập được bên
     * Shop Admin.
     */
    public function taoDungThu(Request $request)
    {
        $du_lieu = $request->validate([
            'plan_id' => ['required', 'integer', 'min:1'],
            'ma_cua_hang' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{1,29}$/'],
            'ten_cua_hang' => ['required', 'string', 'max:150'],
            'ten_dang_nhap' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,50}$/'],
            'mat_khau' => ['required', 'string', 'min:6'],
            // KHÔNG có ô "họ tên người quản trị": máy chủ lấy theo `nguoi_lien_he`
            // — chủ tiệm vừa là người mình gọi vừa là người ngồi gõ phần mềm, bắt
            // gõ hai lần chỉ tạo ra hai cái tên lệch nhau một chữ.
            //
            // KHÔNG có ô email cho tài khoản đăng nhập: khách vào Sellio Order
            // bằng ba ô (mã cửa hàng · tên đăng nhập · mật khẩu). Cột
            // `users.email` do máy chủ tự đặt. Ô dưới đây là email THẬT của khách,
            // ghi vào sổ khách hàng để liên lạc — hai thứ khác nhau.
            'nguoi_lien_he' => ['nullable', 'string', 'max:150'],
            'dien_thoai' => ['nullable', 'string', 'max:20'],
            'email_lien_he' => ['nullable', 'email', 'max:150'],
            // BẮT BUỘC ở form này, dù API vẫn nhận null (null = lấy theo bảng giá).
            // Người bán phải nhìn thấy và xác nhận con số mình đang hứa với khách:
            // ô trống rồi để máy tự điền nghĩa là bán một thời hạn mà chính người
            // bán không biết là bao nhiêu. Bộ chọn gói tự điền sẵn số của gói, nên
            // "bắt buộc" ở đây không thành thêm việc — chỉ thành một lần nhìn.
            'so_ngay_dung_thu' => ['required', 'integer', 'min:1', 'max:180'],
            'ghi_chu' => ['nullable', 'string', 'max:500'],
        ], [
            'ma_cua_hang.regex' => 'Mã cửa hàng gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (2–30 ký tự).',
            'ten_dang_nhap.regex' => 'Tên đăng nhập gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (3–50 ký tự).',
        ]);

        // Ô để trống là "không khai", KHÁC chuỗi rỗng: API phân biệt hai thứ đó ở
        // `so_ngay_dung_thu` (null = lấy theo bảng giá) và ở email (rỗng = tự
        // sinh). Gửi chuỗi rỗng lên thì binding của Go từ chối cả yêu cầu.
        $du_lieu = array_filter($du_lieu, fn ($v) => $v !== null && $v !== '');
        $du_lieu['plan_id'] = (int) $du_lieu['plan_id'];
        if (isset($du_lieu['so_ngay_dung_thu'])) {
            $du_lieu['so_ngay_dung_thu'] = (int) $du_lieu['so_ngay_dung_thu'];
        }

        try {
            $res = $this->api->taoDungThu($du_lieu);
        } catch (\Throwable $e) {
            Log::error('Mở tài khoản dùng thử thất bại', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            // theoO: chỉ form này mới gắn lỗi vào từng ô. Hai hộp thoại gia hạn và
            // huỷ có đúng một ô chọn sẵn, nên lỗi của chúng đi đường toast — gắn
            // vào ô thì hộp đã đóng rồi, không ai nhìn thấy.
            return $this->traLoiGhi($res, 'Không mở được tài khoản dùng thử.', theoO: true);
        }

        $d = $res->json('data');

        // Câu này là thứ người bán đọc ra cho khách nghe ngay tại chỗ, nên nó phải
        // có đủ ba ô của màn hình đăng nhập. Mật khẩu KHÔNG in lại: nó vừa được gõ
        // ở form, và in ra đây là để nó nằm trong ảnh chụp màn hình của người khác.
        $cau = sprintf(
            'Đã mở tài khoản dùng thử %s ngày cho "%s". Đăng nhập: mã cửa hàng %s · tên đăng nhập %s. Hết hạn %s.',
            data_get($d, 'so_ngay_dung_thu'),
            data_get($d, 'ten_cua_hang'),
            data_get($d, 'ma_cua_hang'),
            data_get($d, 'ten_dang_nhap'),
            \Illuminate\Support\Carbon::parse(data_get($d, 'het_han'))->format('d/m/Y'),
        );

        return redirect()
            ->route('platform.khach-hang-order.nguoi-dung-thu')
            ->with('success', $cau);
    }

    /**
     * Thêm khách mới kèm hợp đồng CHÍNH THỨC.
     *
     * Cùng bộ ô với `taoDungThu` — cũng dựng cửa hàng + tài khoản đăng nhập +
     * hợp đồng trong một lượt — khác đúng một chỗ: thời hạn tính bằng THÁNG và
     * hợp đồng ra `active` ngay, không qua giai đoạn dùng thử.
     *
     * Ba quy tắc định dạng dưới đây phải khớp Go và `cmd/tao-admin`, y như bên
     * dùng thử: lệch nhau thì cửa hàng tạo ở đây không đăng nhập được ở kia.
     */
    public function taoChinhThuc(Request $request)
    {
        $du_lieu = $request->validate([
            'plan_id' => ['required', 'integer', 'min:1'],
            'ma_cua_hang' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{1,29}$/'],
            'ten_cua_hang' => ['required', 'string', 'max:150'],
            'ten_dang_nhap' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,50}$/'],
            'mat_khau' => ['required', 'string', 'min:6'],
            'nguoi_lien_he' => ['nullable', 'string', 'max:150'],
            'dien_thoai' => ['nullable', 'string', 'max:20'],
            'email_lien_he' => ['nullable', 'email', 'max:150'],
            'so_thang' => ['required', 'integer', 'min:1', 'max:60'],
            'ghi_chu' => ['nullable', 'string', 'max:500'],
        ], [
            'ma_cua_hang.regex' => 'Mã cửa hàng gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (2–30 ký tự).',
            'ten_dang_nhap.regex' => 'Tên đăng nhập gồm chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới (3–50 ký tự).',
        ]);

        // Ô để trống là "không khai", KHÁC chuỗi rỗng — xem chú thích ở taoDungThu.
        $du_lieu = array_filter($du_lieu, fn ($v) => $v !== null && $v !== '');
        $du_lieu['plan_id'] = (int) $du_lieu['plan_id'];
        $du_lieu['so_thang'] = (int) $du_lieu['so_thang'];

        try {
            $res = $this->api->taoChinhThuc($du_lieu);
        } catch (\Throwable $e) {
            Log::error('Thêm hợp đồng chính thức thất bại', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return $this->traLoiGhi($res, 'Không thêm được hợp đồng chính thức.', theoO: true);
        }

        $d = $res->json('data');

        // Câu này người bán đọc ra cho khách nghe ngay tại chỗ, nên phải có đủ ba
        // ô của màn hình đăng nhập. Mật khẩu KHÔNG in lại — xem taoDungThu.
        $cau = sprintf(
            'Đã thêm hợp đồng chính thức cho "%s". Đăng nhập: mã cửa hàng %s · tên đăng nhập %s. Hết hạn %s.',
            data_get($d, 'ten_cua_hang'),
            data_get($d, 'ma_cua_hang'),
            data_get($d, 'ten_dang_nhap'),
            \Illuminate\Support\Carbon::parse(data_get($d, 'het_han'))->format('d/m/Y'),
        );

        return redirect()
            ->route('platform.khach-hang-order.nguoi-chinh-thuc')
            ->with('success', $cau);
    }

    /**
     * Ký hợp đồng CHÍNH THỨC cho một cửa hàng đã tồn tại.
     *
     * Đối ứng của `taoDungThu`, nhưng nhẹ hơn hẳn: cửa hàng và tài khoản đăng
     * nhập đã có sẵn, việc duy nhất là ghi hợp đồng — không bắc qua hai database
     * nên cũng không có phần hụt nào.
     *
     * Không có ô nào cho giá và hạn mức: chúng chép từ bảng giá lúc ký.
     */
    public function kyHopDong(Request $request)
    {
        $du_lieu = $request->validate([
            'plan_id' => ['required', 'integer', 'min:1'],
            'ma_cua_hang' => ['required', 'string', 'max:30'],
            'so_thang' => ['nullable', 'integer', 'min:1', 'max:60'],
            'ghi_chu' => ['nullable', 'string', 'max:500'],
        ]);

        $du_lieu['plan_id'] = (int) $du_lieu['plan_id'];
        $du_lieu['so_thang'] = (int) ($du_lieu['so_thang'] ?? 0);
        $du_lieu['ghi_chu'] = (string) ($du_lieu['ghi_chu'] ?? '');

        try {
            $res = $this->api->kyHopDong($du_lieu);
        } catch (\Throwable $e) {
            Log::error('Ký hợp đồng thất bại', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return $this->traLoiGhi($res, 'Không ký được hợp đồng.', theoO: true);
        }

        return redirect()
            ->route('platform.khach-hang-order.nguoi-chinh-thuc')
            ->with('success', $res->json('message') ?: 'Đã ký hợp đồng.');
    }

    /**
     * Ghi nhận một lần tiền vào sổ thu.
     *
     * KHÔNG đẩy hạn hợp đồng — đó là nút Gia hạn. Hai việc tách rời cố ý: gộp
     * lại thì mỗi lần gia hạn báo một khoản doanh thu chưa ai trả, và khách trả
     * trước mà chưa muốn đẩy hạn thì không ghi vào đâu được.
     */
    public function thuTien(Request $request, int $hopDong)
    {
        $du_lieu = $request->validate([
            'so_tien' => ['nullable', 'numeric', 'min:0'],
            'hinh_thuc' => ['nullable', 'in:chuyen_khoan,tien_mat,khac'],
            'ma_giao_dich' => ['nullable', 'string', 'max:100'],
            'ghi_chu' => ['nullable', 'string', 'max:500'],
            // Bỏ trống là để MÁY CHỦ tự tính kỳ nối tiếp lần thu gần nhất — đó
            // mới là đường đúng. Hai ô này chỉ dùng khi thu cho một kỳ khác.
            'ky_tu' => ['nullable', 'date_format:Y-m-d'],
            'ky_den' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $gui = [
            'so_tien' => (float) ($du_lieu['so_tien'] ?? 0),
            'hinh_thuc' => (string) ($du_lieu['hinh_thuc'] ?? ''),
            'ma_giao_dich' => (string) ($du_lieu['ma_giao_dich'] ?? ''),
            'ghi_chu' => (string) ($du_lieu['ghi_chu'] ?? ''),
            'ky_tu' => (string) ($du_lieu['ky_tu'] ?? ''),
            'ky_den' => (string) ($du_lieu['ky_den'] ?? ''),
        ];

        try {
            $res = $this->api->thuTien($hopDong, $gui);
        } catch (\Throwable $e) {
            Log::error('Ghi nhận thu tiền thất bại', ['id' => $hopDong, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return $this->traLoiGhi($res, 'Không ghi được lần thu.');
        }

        $d = $res->json('data');
        $cau = sprintf(
            'Đã ghi %s ₫ vào sổ thu (kỳ %s → %s). Hợp đồng này đã thu %s ₫ qua %d lần.',
            number_format((float) data_get($d, 'so_tien'), 0, ',', '.'),
            \Illuminate\Support\Carbon::parse(data_get($d, 'ky_tu'))->format('d/m/Y'),
            \Illuminate\Support\Carbon::parse(data_get($d, 'ky_den'))->format('d/m/Y'),
            number_format((float) data_get($d, 'tong_da_thu'), 0, ',', '.'),
            (int) data_get($d, 'so_lan_thu'),
        );

        return back()->with('success', $cau);
    }

    /**
     * Xuất danh sách hợp đồng ra tệp Excel đọc được (.csv).
     *
     * CSV chứ không .xlsx: Excel mở thẳng được, không cần thư viện nào, và đây là
     * đúng khuôn `SupplierController::export` bên Shop Admin — hai app xuất ra
     * hai định dạng khác nhau thì người dùng phải nhớ cái nào ra cái gì.
     *
     * BOM UTF-8 (\xEF\xBB\xBF) ở đầu tệp là BẮT BUỘC. Thiếu nó thì Excel trên
     * Windows đọc tệp bằng bảng mã hệ thống và mọi chữ có dấu thành ký tự lạ —
     * tệp vẫn mở được nên lỗi này không báo ở đâu cả, chỉ có người nhận tệp mới
     * thấy.
     *
     * XUẤT ĐÚNG NHỮNG DÒNG ĐANG HIỆN. Màn hình lọc ở trình duyệt, nên nó gửi lên
     * danh sách id đang thấy thay vì gửi lại điều kiện lọc — chép luật lọc sang
     * PHP là dựng bản thứ hai của cùng một luật (kể cả phần bỏ dấu tiếng Việt),
     * và hai bản đó sẽ lệch nhau. Không có id nào thì xuất trọn danh sách.
     */
    public function xuatHopDong(Request $request)
    {
        $data = $request->validate([
            'nhom' => ['required', 'in:dung_thu,chinh_thuc'],
            'ids' => ['nullable', 'string', 'max:20000'],
        ]);

        [$res, $loi] = $this->goi(
            'danh sách hợp đồng',
            fn () => $this->api->hopDong(self::APP, nhom: $data['nhom'])
        );
        if ($loi) {
            return back()->with('error', $loi);
        }

        $hopDong = collect(data_get($res, 'hop_dong') ?: []);

        // Lọc theo đúng danh sách id màn hình gửi lên, và GIỮ NGUYÊN thứ tự của
        // API (sắp theo ngày hết hạn gần nhất trước) chứ không theo thứ tự id —
        // người mở tệp mong thấy đúng thứ tự vừa nhìn trên màn hình.
        $ids = collect(explode(',', (string) ($data['ids'] ?? '')))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->all();
        if ($ids) {
            $hopDong = $hopDong->filter(fn ($h) => in_array((int) data_get($h, 'id'), $ids, true));
        }

        $ten_tep = sprintf(
            '%s-%s.csv',
            $data['nhom'] === 'dung_thu' ? 'khach-dung-thu' : 'khach-chinh-thuc',
            date('Ymd-His')
        );

        return response()->streamDownload(function () use ($hopDong) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Mã cửa hàng', 'Tên cửa hàng', 'Người liên hệ', 'Điện thoại', 'Email',
                'Gói dịch vụ', 'Chu kỳ', 'Giá',
                'Chi nhánh', 'Tài khoản', 'Sản phẩm', 'Tên miền riêng',
                'Trạng thái', 'Bắt đầu', 'Hết hạn', 'Còn lại (ngày)', 'Ghi chú',
            ]);

            // 0 = KHÔNG GIỚI HẠN. Trong bảng tính thì viết hẳn ra chữ: ký hiệu ∞
            // dùng trên màn hình được vì có chú thích ngay cạnh, còn một ô Excel
            // thì đứng một mình, và số 0 ở đó đọc thành "không được cái nào".
            $han = fn ($v) => ((int) $v) ?: 'Không giới hạn';
            $luc = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y H:i') : '';

            /*
             * Bọc một ô CHỮ bằng dấu nháy đơn dẫn đầu — quy ước "ô này là chữ" của
             * Excel. Hai việc trong một:
             *
             *  1. GIỮ SỐ 0 ĐẦU. "0901234567" không bọc thì Excel đọc thành số và
             *     cắt mất số 0 — số điện thoại sai mà nhìn vẫn như số điện thoại.
             *     Nếu bản Excel nào không hiểu dấu nháy thì cùng lắm nó hiện thừa
             *     một ký tự: nhìn thấy ngay và số vẫn đúng, đổi lấy cái đó còn hơn
             *     mất số 0 một cách im lặng.
             *
             *  2. CHẶN Ô BẮT ĐẦU BẰNG = + - @ trở thành CÔNG THỨC. Ghi chú và tên
             *     là chữ người dùng gõ; một ô mở đầu bằng "=" sẽ được Excel chạy
             *     như công thức trên máy người mở tệp.
             */
            $chu = function ($v) {
                $v = (string) ($v ?? '');
                if ($v === '') {
                    return '';
                }

                return str_contains("=+-@'", $v[0]) || ctype_digit($v[0]) ? "'".$v : $v;
            };
            $trangThai = [
                'trial' => 'Đang dùng thử', 'active' => 'Chính thức',
                'past_due' => 'Quá hạn', 'canceled' => 'Đã huỷ',
            ];

            foreach ($hopDong as $h) {
                fputcsv($out, [
                    $chu(data_get($h, 'ma_cua_hang')),
                    $chu(data_get($h, 'ten_cua_hang')),
                    $chu(data_get($h, 'nguoi_lien_he')),
                    $chu(data_get($h, 'dien_thoai')),
                    $chu(data_get($h, 'email')),
                    $chu(data_get($h, 'ten_goi') ?: data_get($h, 'goi')),
                    data_get($h, 'chu_ky') === 'nam' ? 'Theo năm' : 'Theo tháng',
                    (float) data_get($h, 'gia'),
                    $han(data_get($h, 'chi_nhanh')),
                    $han(data_get($h, 'tai_khoan')),
                    $han(data_get($h, 'san_pham')),
                    data_get($h, 'ten_mien_rieng') ? 'Có' : 'Không',
                    $trangThai[data_get($h, 'trang_thai')] ?? data_get($h, 'trang_thai'),
                    $luc(data_get($h, 'bat_dau')),
                    $luc(data_get($h, 'het_han')),
                    (int) data_get($h, 'con_lai_ngay'),
                    $chu(data_get($h, 'ghi_chu_khach')),
                ]);
            }

            fclose($out);
        }, $ten_tep, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Chi tiết một hợp đồng — trang riêng, không phải hộp thoại.
     *
     * Trang riêng vì hộp thoại phải đi lấy dữ liệu bằng JS rồi tự dựng lại nội
     * dung; cả app này đang chạy bằng form HTML thường, thêm một đường AJAX chỉ
     * cho một màn hình là thêm một cách làm việc thứ hai để sau này lệch nhau.
     * Trang riêng cũng có địa chỉ URL — gửi được cho đồng nghiệp, mở được ở tab
     * mới, bấm nút Back về đúng danh sách.
     */
    public function chiTiet(int $hopDong)
    {
        [$data, $loi] = $this->goi('chi tiết hợp đồng', fn () => $this->api->hopDongChiTiet($hopDong));

        // Không đọc được thì về danh sách kèm lời giải thích, đừng dựng một trang
        // chi tiết trống trơn: trang đó không có gì để xem và cũng không nói được
        // vì sao trống.
        if ($loi) {
            return redirect()
                ->route('platform.khach-hang-order.nguoi-dung-thu')
                ->with('error', $loi);
        }

        return view('khach-hang-order.chi-tiet', [
            'hd' => data_get($data, 'hop_dong') ?: [],
            'ghiDuoc' => in_array(data_get(session('api.user'), 'role'), ['owner', 'operator'], true),
        ]);
    }

    /**
     * Lưu phần sửa được của hợp đồng: thông tin khách và ghi chú.
     *
     * KHÔNG có ô nào cho gói, giá hay hạn mức — API cũng không nhận. Đó là điều
     * khoản đã ký, và cả hệ thống dựng trên nguyên tắc chúng không đổi sau lúc ký.
     */
    public function luuChiTiet(Request $request, int $hopDong)
    {
        $du_lieu = $request->validate([
            'ten_cua_hang' => ['required', 'string', 'max:150'],
            'nguoi_lien_he' => ['nullable', 'string', 'max:150'],
            'dien_thoai' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'ghi_chu_khach' => ['nullable', 'string', 'max:500'],
            'ghi_chu_hop_dong' => ['nullable', 'string', 'max:500'],
            // Ô <input type="datetime-local"> gửi lên dạng `2026-09-30T17:30`.
            // Vẫn nhận ngày trần: API tự lấy cuối ngày, và người gọi thẳng API có
            // thể chỉ quan tâm tới ngày.
            'het_han' => ['nullable', 'date_format:Y-m-d\TH:i,Y-m-d'],
        ]);

        // Ô để trống PHẢI gửi lên dạng chuỗi rỗng chứ không được lọc bỏ: đây là
        // form sửa, và xoá trắng ô người liên hệ nghĩa là "khách không còn người
        // liên hệ nào", không phải "giữ nguyên cái cũ". Chỉ `het_han` là ngoại lệ
        // — bỏ trống ở đó mới mang nghĩa giữ nguyên (xem dto.SuaHopDongRequest).
        foreach (['nguoi_lien_he', 'dien_thoai', 'email', 'ghi_chu_khach', 'ghi_chu_hop_dong'] as $o) {
            $du_lieu[$o] = (string) ($du_lieu[$o] ?? '');
        }
        $du_lieu['het_han'] = (string) ($du_lieu['het_han'] ?? '');

        try {
            $res = $this->api->suaHopDong($hopDong, $du_lieu);
        } catch (\Throwable $e) {
            Log::error('Sửa hợp đồng thất bại', ['id' => $hopDong, 'msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return $this->traLoiGhi($res, 'Không lưu được thay đổi.', theoO: true);
        }

        return back()->with('success', $res->json('message') ?: 'Đã lưu thay đổi.');
    }

    /**
     * Đặt lại mật khẩu tài khoản quản trị của khách.
     *
     * Ô "xác nhận" kiểm Ở ĐÂY chứ không gửi lên API: nó là lưới chặn gõ nhầm của
     * người đang ngồi trước màn hình, không phải một luật nghiệp vụ. Gửi cả hai
     * lên rồi so ở máy chủ chỉ thêm một đường để hai bên lệch nhau.
     *
     * Quy tắc `confirmed` của Laravel đòi ô thứ hai tên `mat_khau_confirmation`.
     */
    public function doiMatKhauKhach(Request $request, int $hopDong)
    {
        $data = $request->validate([
            'mat_khau' => ['required', 'string', 'min:6', 'max:100', 'confirmed'],
        ], [
            'mat_khau.confirmed' => 'Hai ô mật khẩu không khớp nhau.',
        ]);

        try {
            $res = $this->api->doiMatKhauKhach($hopDong, $data['mat_khau']);
        } catch (\Throwable $e) {
            Log::error('Đổi mật khẩu khách thất bại', ['id' => $hopDong, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return $this->traLoiGhi($res, 'Không đổi được mật khẩu.');
        }

        // KHÔNG in mật khẩu vừa đặt vào thông báo: người bấm vừa gõ nó, còn câu
        // này thì nằm lại trên màn hình cho tới khi họ rời trang — đủ lâu để lọt
        // vào ảnh chụp màn hình của người khác. Tên đăng nhập thì in, vì đó là
        // thứ họ có thể không nhớ và nó phải đi cùng mật khẩu mới đăng nhập được.
        return back()->with('success', $res->json('message') ?: 'Đã đổi mật khẩu.');
    }

    /**
     * Gia hạn hợp đồng — cũng chính là chuyển dùng thử sang chính thức.
     *
     * MỘT hành động, hai cái tên tuỳ chỗ bấm: trên màn hình dùng thử nó là
     * "Chuyển sang chính thức", trên màn hình chính thức nó là "Gia hạn". Bên Go
     * cũng chỉ có một đường — trạng thái về `active` và mốc hết dùng thử bị xoá.
     *
     * KHÔNG ghi vào sổ thu. Tiền vào là việc riêng (`cmd/thue-bao thu-tien`), và
     * gộp hai thứ đó lại thì mỗi lần gia hạn sẽ báo một khoản doanh thu chưa ai
     * trả.
     */
    public function giaHan(Request $request, int $hopDong)
    {
        $data = $request->validate([
            'so_thang' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        try {
            $res = $this->api->giaHanHopDong($hopDong, (int) $data['so_thang']);
        } catch (\Throwable $e) {
            Log::error('Gia hạn hợp đồng thất bại', ['id' => $hopDong, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return $this->traLoiGhi($res, 'Không gia hạn được hợp đồng.');
        }

        return back()->with('success', $res->json('message') ?: 'Đã gia hạn hợp đồng.');
    }

    /** Huỷ hợp đồng. Lý do nối vào ghi chú của hợp đồng, không xoá dòng nào. */
    public function huy(Request $request, int $hopDong)
    {
        $data = $request->validate([
            'ly_do' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $res = $this->api->huyHopDong($hopDong, (string) ($data['ly_do'] ?? ''));
        } catch (\Throwable $e) {
            Log::error('Huỷ hợp đồng thất bại', ['id' => $hopDong, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            return $this->traLoiGhi($res, 'Không huỷ được hợp đồng.');
        }

        return back()->with('success', $res->json('message') ?: 'Đã huỷ hợp đồng.');
    }

    /**
     * Người dùng chính thức — hợp đồng đã trả tiền, đang chạy.
     *
     * Màn hình này gọi thêm HAI lượt mà trang dùng thử không cần, vì khách trả
     * tiền có hai câu hỏi mà khách dùng thử không có:
     *
     *   · "khách này đã trả bao nhiêu rồi" → /platform/doanh-thu, gộp từ sổ thu.
     *     Đây là tiền ĐÃ VÀO THẬT, không phải tiền đáng lẽ phải thu theo hợp
     *     đồng — hai con số đó chỉ bằng nhau vào tháng mà mọi khách trả đủ và
     *     đúng hạn.
     *   · "còn cửa hàng nào chưa ký không" → /platform/cua-hang-chua-ky, để dựng
     *     bộ chọn của nút "Ký hợp đồng".
     *
     * Cả hai hỏng đều KHÔNG chặn màn hình: danh sách hợp đồng vẫn là việc chính.
     * Mất doanh thu thì cột đó trống, mất danh sách cửa hàng thì tạm chưa ký mới
     * được — nói ra chứ đừng để cả trang trắng.
     */
    public function nguoiChinhThuc()
    {
        [$dataThu, $loiThu] = $this->goi('doanh thu', fn () => $this->api->doanhThu(self::APP));
        [$dataCh, $loiCh] = $this->goi('danh sách cửa hàng', fn () => $this->api->cuaHangChuaKy(self::APP));
        [$dataGoi, $loiGoi] = $this->goi('bảng giá', fn () => $this->api->plans(self::APP));

        // Đánh chỉ mục theo MÃ cửa hàng: đó là khoá chung giữa hai lượt gọi (API
        // doanh thu không trả tenant_id ra ngoài). Mã là duy nhất toàn hệ thống
        // nên không có chuyện hai khách đè lên nhau.
        // `theo_quan` chứ không phải `doanh_thu` — đó là tên trường API trả về
        // (dto.DoanhThuResponse.TheoQuan). Gõ nhầm khoá ở đây thì collect() nhận
        // mảng rỗng, cột "Đã thu" trống trơn, và KHÔNG lỗi nào nổi lên.
        $daThu = collect(data_get($dataThu, 'theo_quan') ?: [])
            ->keyBy(fn ($d) => data_get($d, 'ma_cua_hang'));

        $plans = collect(data_get($dataGoi, 'plans') ?: [])
            ->filter(fn ($p) => data_get($p, 'status') === 'active')
            ->values()
            ->all();

        return $this->manHinhHopDong(
            nhom: 'chinh_thuc',
            tieuDe: 'Người dùng chính thức',
            moTa: 'Mọi khách đã ký hợp đồng Sellio Order — kể cả hợp đồng đã quá hạn hoặc đã huỷ.',
            them: [
                'daThu' => $daThu,
                'loiThu' => $loiThu,
                'cuaHangChuaKy' => data_get($dataCh, 'cua_hang') ?: [],
                'plans' => $plans,
                // Gộp hai lỗi làm một dòng: cả hai đều dẫn tới cùng một hệ quả —
                // tạm thời chưa ký hợp đồng mới được.
                'loiGoi' => $loiCh ?: $loiGoi,
            ],
        );
    }

    /**
     * Các gói dịch vụ — bảng giá của phần mềm Order.
     *
     * Mỗi dòng là một mức giá (gói × chu kỳ), không phải một gói: "Cửa hàng"
     * bán theo tháng và theo năm là HAI dòng, hai ID khác nhau.
     */
    public function goiDichVu()
    {
        [$data, $loi] = $this->goi('bảng giá', fn () => $this->api->plans(self::APP));

        return view('khach-hang-order.goi-dich-vu', [
            'plans' => data_get($data, 'plans') ?: [],
            'loi' => $loi,
            // Cùng lý do như màn hình Tính năng gói: `support` không nên thấy nút
            // Sửa rồi mới ăn 403 lúc bấm Lưu. API vẫn là chốt chặn thật.
            'ghiDuoc' => in_array(data_get(session('api.user'), 'role'), ['owner', 'operator'], true),
        ]);
    }

    /**
     * Sửa một mức giá.
     *
     * BA THỨ ĐÁNG NHỚ ở hàm này:
     *
     * · Ô giá nhận chuỗi có dấu chấm ("499.000") vì người ta gõ tiền như vậy,
     *   nên phải bóc hết ký tự không phải chữ số trước khi gửi. Bỏ bước đó thì
     *   API nhận "499.000" và hiểu là bốn trăm chín chín phẩy không.
     *
     * · Để TRỐNG ô giá hoặc tích "Liên hệ" đều gửi null — chưa công bố giá.
     *   Muốn miễn phí thì phải gõ số 0; null và 0 là hai điều khác nhau và API
     *   phân biệt chúng.
     *
     * · Mã gói, app và chu kỳ KHÔNG có trong payload dù màn hình có hiện: bộ ba
     *   đó là danh tính của dòng, và hợp đồng đã ký tra tên gói về theo mã.
     */
    public function luuGoi(Request $request, int $plan)
    {
        $so = preg_replace('/\D/', '', (string) $request->input('gia'));

        try {
            $res = $this->api->suaGoi($plan, [
                'name' => trim((string) $request->input('name')),
                'tagline' => trim((string) $request->input('tagline')),
                'price' => $request->boolean('lien_he') || $so === '' ? null : (float) $so,
                'trial_days' => (int) $request->input('trial_days'),
                // Ép về đúng hai giá trị API nhận thay vì chuyển tiếp nguyên văn:
                // ô chọn có thể bị sửa ở trình duyệt, và một chuỗi lạ ở đây chỉ
                // tạo ra một lượt 422 vòng vo thay vì một câu trả lời rõ ràng.
                'status' => $request->input('status') === 'retired' ? 'retired' : 'active',
            ]);
        } catch (\Throwable $e) {
            Log::error('Lưu mức giá thất bại', ['plan' => $plan, 'msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            // 422 trả lỗi theo từng ô; gộp thành một câu thay vì gắn vào từng ô.
            // Hộp thoại được mở LẠI kèm dữ liệu vừa gõ (xem `_sua_id` ở Blade),
            // nên người sửa không mất công gõ lại — nhưng câu lỗi thì đi qua
            // toast, vì lỗi ở đây chỉ có hai loại và cả hai đều nói đủ trong một
            // câu.
            $loi = $res->json('errors');
            if (is_array($loi) && $loi) {
                return back()->withInput()->with('error', implode(' ', $loi));
            }

            return $this->traLoiGhi($res, 'Không lưu được mức giá.');
        }

        return back()->with('success', $res->json('message') ?: 'Đã lưu mức giá.');
    }

    /**
     * Tính năng gói — bảng so sánh hạn mức, mỗi cột một dòng bảng giá.
     *
     * Dựng từ CÙNG một lần gọi /platform/plans như màn hình trên: câu trả lời
     * đó đã kèm `features` của từng gói và `fields` mô tả từng khoá, nên không
     * cần gọi thêm /plans/{id}/features cho mỗi gói.
     *
     * `fields` là nguồn duy nhất quyết định có những hạn mức nào. Chép danh
     * sách khoá ra Blade thì thêm một hạn mức bên Go là phải nhớ sửa cả ở đây,
     * và quên là màn hình im lặng giấu mất hạn mức mới.
     */
    public function tinhNangGoi()
    {
        [$data, $loi] = $this->goi('tính năng gói', fn () => $this->api->plans(self::APP));

        return view('khach-hang-order.tinh-nang-goi', [
            'plans' => data_get($data, 'plans') ?: [],
            'fields' => data_get($data, 'fields') ?: [],
            'loi' => $loi,
            // Vai trò xét lại ở màn hình để `support` không thấy ô nhập rồi mới
            // ăn 403 lúc bấm Lưu. API vẫn là chốt chặn thật — dòng này chỉ để
            // giao diện không hứa thứ nó không làm được.
            'ghiDuoc' => in_array(data_get(session('api.user'), 'role'), ['owner', 'operator'], true),
        ]);
    }

    /**
     * Ghi tính năng của một gói.
     *
     * Ô để TRỐNG là xoá khoá đó khỏi bảng giá ("không quy định"), khác hẳn gõ
     * số 0 — nên không lọc bỏ giá trị rỗng trước khi gửi. Gửi cả bộ khoá trong
     * một lần: API ghi trọn gói hoặc từ chối cả yêu cầu (422), không có trạng
     * thái ghi được một nửa.
     */
    public function luuTinhNangGoi(Request $request, int $plan)
    {
        $items = $request->input('items');
        if (! is_array($items) || $items === []) {
            return back()->with('error', 'Không có tính năng nào để lưu.');
        }

        // ép về chuỗi: ô số của trình duyệt trả "12", nhưng checkbox/hidden có
        // thể trả kiểu khác, mà API chỉ nhận map chuỗi => chuỗi.
        $items = array_map(fn ($v) => (string) $v, $items);

        try {
            $res = $this->api->updatePlanFeatures($plan, $items);
        } catch (\Throwable $e) {
            Log::error('Lưu tính năng gói thất bại', ['plan' => $plan, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }

        if (! $res->successful()) {
            // 422 trả về lỗi theo từng khoá; gộp lại thành một câu thay vì chỉ
            // in "dữ liệu không hợp lệ" — người sửa cần biết khoá nào sai.
            $loi = $res->json('errors');
            $message = is_array($loi) && $loi
                ? implode(' ', $loi)
                : ($res->json('message') ?: 'Không lưu được tính năng gói.');

            return back()->with('error', $message);
        }

        return back()->with('success', $res->json('message') ?: 'Đã lưu tính năng gói.');
    }

    /** Database — sổ khách hàng của phần mềm Order. Xem chú thích đầu lớp. */
    public function database()
    {
        [$data, $loi] = $this->goi('sổ khách hàng', fn () => $this->api->khachHang(self::APP));

        return view('khach-hang-order.database', [
            'khachHang' => data_get($data, 'khach_hang') ?: [],
            'loi' => $loi,
        ]);
    }

    /**
     * Hai màn hình hợp đồng chỉ khác nhau ở NHÓM và lời dẫn, nên dùng chung một
     * view: tách thành hai bản Blade thì mỗi lần sửa cột phải sửa hai chỗ, và
     * chúng sẽ lệch nhau.
     *
     * $nhom (dung_thu | chinh_thuc) chứ KHÔNG phải trạng thái hợp đồng, và đây
     * là chỗ dễ hiểu nhầm nhất của cả hai màn hình. Trước đây trang dùng thử lọc
     * `trang_thai=trial`, trang chính thức lọc `trang_thai=active` — nên hợp
     * đồng hết hạn (thành `past_due`) hay vừa bấm huỷ (thành `canceled`) BIẾN
     * MẤT khỏi bảng, đúng lúc người ta cần nhìn thấy nó nhất: khách vừa hết hạn
     * là khách phải gọi điện. Nhóm thì không đổi theo thời gian, nên danh sách
     * đứng yên và trạng thái trở thành một cột để đọc.
     *
     * $them là phần chỉ MỘT trong hai màn hình cần (bảng giá cho nút "Thêm tài
     * khoản dùng thử"). View tự xoay theo $nhom; truyền qua đây để trang không
     * có nút đó khỏi phải gọi thêm một lượt API chẳng dùng vào việc gì.
     */
    protected function manHinhHopDong(string $nhom, string $tieuDe, string $moTa, array $them = [])
    {
        [$data, $loi] = $this->goi(
            'danh sách hợp đồng',
            fn () => $this->api->hopDong(self::APP, nhom: $nhom)
        );

        return view('khach-hang-order.hop-dong', array_merge([
            'hopDong' => data_get($data, 'hop_dong') ?: [],
            'tieuDe' => $tieuDe,
            'moTa' => $moTa,
            'nhom' => $nhom,
            'loi' => $loi,
            'plans' => [],
            'loiGoi' => null,
            // Mặc định rỗng — trang dùng thử không có ba thứ này, và Blade đọc
            // chúng vô điều kiện thay vì phải nhớ kiểm tra biến có tồn tại không.
            'daThu' => collect(),
            'loiThu' => null,
            'cuaHangChuaKy' => [],
            // Vai trò xét lại ở màn hình để `support` không thấy nút rồi mới ăn
            // 403 lúc bấm. API vẫn là chốt chặn thật — dòng này chỉ để giao diện
            // không hứa thứ nó không làm được.
            'ghiDuoc' => in_array(data_get(session('api.user'), 'role'), ['owner', 'operator'], true),
        ], $them));
    }

    /**
     * Dịch một câu trả lời hỏng của API thành redirect kèm lỗi.
     *
     * 422 giữ nguyên lỗi TỪNG Ô: form mở tài khoản có tám ô, và gộp chúng thành
     * một câu thì người bán phải tự dò xem mã cửa hàng trùng hay mật khẩu ngắn —
     * trong lúc đang ngồi trước mặt khách.
     *
     * withInput() ở mọi nhánh: bắt gõ lại tám ô vì một ô sai là cách chắc chắn để
     * lần thứ hai sai một ô khác.
     */
    protected function traLoiGhi(\Illuminate\Http\Client\Response $res, string $macDinh, bool $theoO = false)
    {
        $loi = $res->json('errors');
        if ($theoO && $res->status() === 422 && is_array($loi) && $loi) {
            // Khoá của `errors` là tên ô (ma_cua_hang, plan_id...) khi lỗi đến từ
            // tầng nghiệp vụ bên Go. Lỗi binding của Go thì trả tên trường Go
            // ("MaCuaHang") — không khớp ô nào, nên nó rơi xuống dải lỗi chung ở
            // đầu form thay vì biến mất.
            return back()->withInput()->withErrors($loi);
        }

        // 404 ở một đường GHI nghĩa là ĐƯỜNG ĐÓ KHÔNG TỒN TẠI trên máy chủ đang
        // chạy — không phải "không tìm thấy dữ liệu". Hai lý do, và câu dưới đây
        // nói cả hai vì từ đây không phân biệt được:
        //   · api đang chạy là bản build CŨ, chưa có nhóm đường này;
        //   · API chưa nối được control plane nên cả nhóm /platform/* vắng mặt.
        //
        // Gin trả 404 mặc định KHÔNG có JSON, nên `message` rỗng và câu chung
        // "Không mở được tài khoản dùng thử" sẽ hiện ra — đúng nhưng vô dụng:
        // người đọc đi soát lại tám ô vừa gõ trong khi lỗi nằm ở máy chủ.
        if ($res->status() === 404) {
            return back()->withInput()->with(
                'error',
                'Máy chủ API đang chạy không có đường này. Khởi động lại API để nạp bản mới '
                .'(đóng cửa sổ "api" rồi chạy lại start.bat), hoặc kiểm tra cấu hình control plane.'
            );
        }

        return back()->withInput()->with('error', $res->json('message') ?: $macDinh);
    }

    /**
     * Gọi API và trả về [dữ liệu, câu báo lỗi].
     *
     * Trả lỗi ra để view IN RA thay vì ném ngoại lệ hay redirect: mất kết nối
     * API không phải lỗi của người đang bấm, và đá họ về trang khác thì họ mất
     * luôn chỗ đang đứng. Màn hình vẫn dựng, chỉ là bảng trống kèm một dòng nói
     * rõ vì sao trống — trống không lời giải thích thì đọc như "chưa có khách".
     *
     * 404 tách riêng: nhóm /platform/* vắng mặt khi API chưa nối được control
     * plane, và câu "không tìm thấy" ở đó gây hiểu nhầm là không có dữ liệu.
     */
    protected function goi(string $viec, callable $ham): array
    {
        try {
            $res = $ham();
        } catch (\Throwable $e) {
            Log::error("Không lấy được {$viec}", ['msg' => $e->getMessage()]);

            return [null, 'Không kết nối được máy chủ API.'];
        }

        if ($res->status() === 404) {
            return [null, 'Go API chưa nối được tới cơ sở dữ liệu nền tảng nên nhóm /platform/* không tồn tại. Kiểm tra cấu hình control plane của API.'];
        }

        if (! $res->successful()) {
            return [null, $res->json('message') ?: "Không lấy được {$viec}."];
        }

        return [$res->json('data'), null];
    }
}
