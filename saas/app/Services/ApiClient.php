<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * ApiClient — lớp gọi Backend Go API.
 *
 * Giống hệt vai trò của ApiClient bên Shop Admin: app này KHÔNG kết nối MySQL,
 * mọi dữ liệu đều đi qua REST API. Access token lấy từ session sau khi đăng nhập
 * và tự đính kèm dạng Bearer.
 *
 * Hai mục: "Auth" (đăng nhập khu điều hành) và "Nền tảng" (nhóm /platform/* —
 * sổ khách hàng, hợp đồng, bảng giá, tính năng gói). Đường nào chưa có bên Go
 * API thì KHÔNG viết sẵn hàm gọi ở đây: hàm như thế trông như đã chạy được, tới
 * lúc bấm mới lộ ra là 404 và mất công dò ngược.
 */
class ApiClient
{
    protected string $baseUrl;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = (string) config('api.base_url');
        $this->timeout = (int) config('api.timeout');
    }

    /**
     * Tạo request nền, tự đính kèm token (mặc định lấy từ session).
     * Truyền $token = false để chủ động bỏ qua token (vd: lúc đăng nhập).
     */
    public function request(string|false|null $token = null): PendingRequest
    {
        $req = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();

        if ($token !== false) {
            $token = $token ?: session('api.access_token');
            if ($token) {
                $req = $req->withToken($token);
            }
        }

        return $req;
    }

    public function get(string $uri, array $query = []): Response
    {
        return $this->send('GET', $uri, $query);
    }

    public function post(string $uri, array $data = []): Response
    {
        return $this->send('POST', $uri, $data);
    }

    public function put(string $uri, array $data = []): Response
    {
        return $this->send('PUT', $uri, $data);
    }

    public function delete(string $uri): Response
    {
        return $this->send('DELETE', $uri);
    }

    /**
     * Gửi request có đính token; nếu bị 401 (access token hết hạn) thì tự động
     * gọi /auth/refresh bằng refresh token rồi thử lại đúng một lần.
     */
    protected function send(string $method, string $uri, array $payload = []): Response
    {
        $res = $this->dispatch($method, $uri, $payload);

        if ($res->status() === 401 && $this->refreshToken()) {
            $res = $this->dispatch($method, $uri, $payload);
        }

        return $res;
    }

    /** Thực thi một HTTP request đơn lẻ (token lấy từ session tại thời điểm gọi). */
    protected function dispatch(string $method, string $uri, array $payload): Response
    {
        $req = $this->request();

        return match (strtoupper($method)) {
            'POST' => $req->post($uri, $payload),
            'PUT' => $req->put($uri, $payload),
            'DELETE' => $req->delete($uri),
            default => $req->get($uri, $payload),
        };
    }

    /**
     * Dùng refresh token lấy cặp token mới, cập nhật lại session.
     * Trả về true nếu làm mới thành công. Không đính access token cũ để tránh vòng lặp.
     */
    protected function refreshToken(): bool
    {
        $refresh = session('api.refresh_token');
        if (! $refresh) {
            return false;
        }

        try {
            // ĐƯỜNG RIÊNG của khu điều hành. /auth/refresh cố ý từ chối mọi token
            // không thuộc cửa hàng nào, mà token của khu điều hành thì luôn như
            // vậy — gọi vào đó chỉ nhận 401 và người dùng bị đá ra màn hình đăng
            // nhập mỗi 15 phút.
            $res = $this->request(false)->post('/auth/platform-refresh', [
                'refresh_token' => $refresh,
            ]);
        } catch (\Throwable $e) {
            return false;
        }

        if (! $res->successful()) {
            return false;
        }

        $data = $res->json('data');
        $access = data_get($data, 'access_token');
        if (! $access) {
            return false;
        }

        session([
            'api.access_token' => $access,
            'api.refresh_token' => data_get($data, 'refresh_token') ?: $refresh,
        ]);
        if ($user = data_get($data, 'user')) {
            session(['api.user' => $user]);
        }

        return true;
    }

    // ---------- Auth ----------

    /** Đăng nhập, trả về Response thô để controller xử lý. */
    /**
     * Đăng nhập khu điều hành nền tảng.
     *
     * Đi đường /auth/platform-login chứ KHÔNG phải /auth/login: đường kia dành
     * cho khách mua sắm nên nó bắt buộc phải biết đang đứng ở cửa hàng nào (lấy
     * theo tên miền), mà khu điều hành thì không thuộc cửa hàng nào — gọi vào đó
     * chỉ nhận về 401 "Chưa xác định được cửa hàng cho yêu cầu này".
     *
     * Tài khoản dùng ở đây nằm trong sổ RIÊNG của nền tảng (`platform_users`),
     * do `cmd/nguoi-dieu-hanh` tạo trên máy chủ. Tài khoản của một cửa hàng —
     * kể cả super_admin của tiệm đó — không đăng nhập được vào đây.
     */
    public function login(string $email, string $password): Response
    {
        return $this->request(false)->post('/auth/platform-login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /** Kiểm tra API có sống không — dùng cho ô trạng thái ở Dashboard. */
    public function health(): bool
    {
        try {
            return $this->request(false)->get('/health')->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---------- Nền tảng ----------
    //
    // Nhóm /platform/* của Go API. Mọi đường nằm sau middleware XacThucNenTang
    // bên đó, tức là chỉ nhận token của sổ `platform_users` — không đường nào ở
    // đây dùng lại được cho Shop Admin.
    //
    // Ba đường GHI cuối mục (dùng thử · gia hạn · huỷ) còn qua một cửa nữa: vai
    // trò `support` gọi vào nhận 403. Màn hình xét lại vai trò trước khi hiện
    // nút, nhưng API mới là chốt chặn thật.
    //
    // Cả nhóm chỉ được đăng ký khi API nối được tới control plane. Nối hụt thì
    // nó VẮNG MẶT hẳn và mọi đường trả 404, không phải 500 — controller phải đọc
    // 404 ở đây là "chưa cấu hình control plane" chứ không phải "không có dữ liệu".

    /**
     * Bảng giá của nền tảng.
     *
     * Trả về CẢ `plans` lẫn `fields` — `fields` là siêu dữ liệu từng khoá tính
     * năng (kiểu, nhãn, đơn vị, trần) do registry bên service quyết định. Màn
     * hình dựng ô nhập từ đó chứ không chép lại bảng khoá lần thứ hai ở Blade.
     */
    public function plans(string $app = ''): Response
    {
        return $this->get('/platform/plans', $this->loc(['app' => $app]));
    }

    /**
     * Ghi tính năng của một gói.
     *
     * $items là map khoá => giá trị. Khoá không gửi lên giữ nguyên; gửi CHUỖI
     * RỖNG là xoá khoá đó ("bảng giá không quy định"), khác hẳn gửi "0". Khoá lạ
     * làm cả yêu cầu bị từ chối 422 chứ không ghi xuống một phần.
     *
     * Chỉ owner/operator ghi được; support gọi vào đây nhận 403.
     */
    public function updatePlanFeatures(int $planId, array $items): Response
    {
        return $this->put("/platform/plans/{$planId}/features", ['items' => $items]);
    }

    /**
     * Sửa MỘT mức giá: tên, mô tả, giá, số ngày dùng thử, còn bán hay không.
     *
     * Khác updatePlanFeatures: đường kia ghi ĐIỀU KHOẢN của gói (hạn mức chi
     * nhánh, tài khoản...), còn đây ghi chính dòng bảng giá.
     *
     * `price` phải gửi null — chứ không phải chuỗi rỗng — khi muốn "Liên hệ":
     * null là chưa công bố giá, 0 là miễn phí, và API phân biệt hai thứ đó.
     *
     * Mã gói, app và chu kỳ không nằm trong $body vì API không nhận: bộ ba đó là
     * danh tính của dòng, hợp đồng đã ký tra tên gói về theo mã.
     *
     * Chỉ owner/operator ghi được; support gọi vào đây nhận 403.
     */
    public function suaGoi(int $planId, array $body): Response
    {
        return $this->put("/platform/plans/{$planId}", $body);
    }

    // ---------- Cấu hình của nhà cung cấp ----------

    /**
     * Cấu hình của CHÍNH MÌNH — hôm nay là thông tin nhận chuyển khoản để khách
     * gia hạn.
     *
     * KHÁC hẳn cấu hình của một cửa hàng (bên Shop Admin): đây là bộ duy nhất cho
     * cả nền tảng, không có tenant nào. Trả về `values` (map khoá => giá trị, đã
     * ghép mặc định) và `fields` (siêu dữ liệu từng ô) để màn hình dựng form mà
     * không chép lại bảng khoá.
     *
     * Cả ba vai trò đều ĐỌC được — người trực hỗ trợ cần đọc số tài khoản để trả
     * lời khách đang gọi.
     */
    public function cauHinh(): Response
    {
        return $this->get('/platform/cau-hinh');
    }

    /**
     * Ghi cấu hình. $items là map khoá => giá trị; khoá không gửi lên giữ nguyên.
     *
     * BẬT nhận chuyển khoản mà thiếu ngân hàng / số tài khoản / chủ tài khoản /
     * mẫu nội dung thì API từ chối cả yêu cầu (422) — bật hình thức nhận tiền lên
     * mà khách không biết chuyển vào đâu là kiểu hỏng tốn tiền thật.
     *
     * Chỉ owner/operator ghi được; support gọi vào đây nhận 403.
     */
    public function luuCauHinh(array $items): Response
    {
        return $this->put('/platform/cau-hinh', ['items' => $items]);
    }

    /**
     * Sổ khách hàng của một phần mềm.
     *
     * Chỉ khách CÓ hợp đồng: quan hệ "khách của phần mềm này" tồn tại qua hợp
     * đồng chứ không qua bảng khách hàng. Bỏ trống $trangThai thì API tự bỏ hợp
     * đồng đã huỷ.
     */
    public function khachHang(string $app = '', string $trangThai = ''): Response
    {
        return $this->get('/platform/tenants', $this->loc([
            'app' => $app,
            'trang_thai' => $trangThai,
        ]));
    }

    /**
     * Hợp đồng đã ký.
     *
     * $nhom: dung_thu | chinh_thuc — LOẠI hợp đồng, thứ không đổi theo thời
     * gian. Đây là bộ lọc của hai màn hình "Người dùng thử" / "Người dùng chính
     * thức", chứ KHÔNG phải $trangThai: trạng thái đổi khi hợp đồng hết hạn
     * (past_due) hay bị huỷ (canceled), nên lọc theo nó thì khách biến mất khỏi
     * màn hình đúng lúc cần nhìn thấy nhất.
     *
     * $trangThai: trial | active | past_due | canceled — lọc thêm bên trong một
     * nhóm, để trống là mọi trạng thái.
     *
     * Sắp theo ngày hết hạn gần nhất trước (hợp đồng đã huỷ xuống cuối),
     * `con_lai_ngay` âm là ĐÃ QUÁ HẠN.
     */
    public function hopDong(string $app = '', string $trangThai = '', string $nhom = ''): Response
    {
        return $this->get('/platform/subscriptions', $this->loc([
            'app' => $app,
            'trang_thai' => $trangThai,
            'nhom' => $nhom,
        ]));
    }

    /**
     * Mở tài khoản dùng thử cho một khách MỚI.
     *
     * Một lượt gọi dựng cả ba thứ: cửa hàng + tài khoản quản trị (data plane) và
     * hợp đồng dùng thử (control plane). Không có đường nào tạo riêng từng phần
     * — tạo được một nửa khách hàng là trạng thái không ai dọn được từ giao diện.
     *
     * $du_lieu đi thẳng lên API: khoá phải đúng tên trường JSON của
     * dto.TaoDungThuRequest (ma_cua_hang, ten_dang_nhap, plan_id...). Controller
     * đã kiểm hình dạng trước rồi, ở đây không lọc lại — lọc hai lần thì lần thứ
     * hai sẽ lệch và âm thầm nuốt mất một ô.
     *
     * Lỗi hay gặp: 422 kèm `errors` theo từng ô (mã cửa hàng trùng, bảng giá
     * chưa khai đủ hạn mức), 409 khi khách đã có hợp đồng còn hiệu lực.
     */
    public function taoDungThu(array $du_lieu): Response
    {
        return $this->post('/platform/dung-thu', $du_lieu);
    }

    /**
     * Tiền ĐÃ THU theo từng cửa hàng, gộp từ sổ `invoices`.
     *
     * Đây là tiền đã vào thật, KHÔNG phải tiền đáng lẽ phải thu theo hợp đồng.
     * Cộng `subscriptions.price` thay cho nó chỉ đúng vào tháng mà mọi khách trả
     * đủ, đúng hạn, không ai bỏ giữa chừng.
     *
     * Lọc theo NGÀY TIỀN VÀO: `$tu` tính từ 00:00 ngày đó, `$den` là mốc chặn
     * trên KHÔNG bao gồm — muốn trọn tháng 8 thì tu=2026-08-01, den=2026-09-01.
     */
    public function doanhThu(string $app = '', string $tu = '', string $den = ''): Response
    {
        return $this->get('/platform/doanh-thu', $this->loc([
            'app' => $app,
            'tu' => $tu,
            'den' => $den,
        ]));
    }

    /**
     * Cửa hàng đã có ở database bán hàng nhưng CHƯA có hợp đồng còn hiệu lực —
     * ứng viên để ký hợp đồng chính thức.
     */
    public function cuaHangChuaKy(string $app = ''): Response
    {
        return $this->get('/platform/cua-hang-chua-ky', $this->loc(['app' => $app]));
    }

    /**
     * Ký hợp đồng CHÍNH THỨC cho một cửa hàng đã tồn tại.
     *
     * Khác `taoDungThu`: đường kia dựng cả một khách hàng mới (cửa hàng + tài
     * khoản đăng nhập), còn đây chỉ ghi hợp đồng cho khách đã có.
     */
    public function kyHopDong(array $du_lieu): Response
    {
        return $this->post('/platform/hop-dong', $du_lieu);
    }

    /**
     * Ghi nhận MỘT LẦN TIỀN VÀO sổ thu.
     *
     * KHÔNG đẩy hạn hợp đồng — đó là việc của `giaHanHopDong`. Hai thứ tách rời
     * cố ý: gộp lại thì mỗi lần gia hạn báo một khoản doanh thu chưa ai trả.
     *
     * Chu kỳ bỏ trống thì API tự tính nối tiếp kỳ đã thu gần nhất; số tiền bỏ
     * trống thì lấy đúng giá đã ký của hợp đồng.
     */
    public function thuTien(int $hopDong, array $du_lieu): Response
    {
        return $this->post("/platform/subscriptions/{$hopDong}/thu-tien", $du_lieu);
    }

    /** Chi tiết một hợp đồng kèm hồ sơ khách đứng sau nó. */
    public function hopDongChiTiet(int $id): Response
    {
        return $this->get("/platform/subscriptions/{$id}");
    }

    /**
     * Sửa THÔNG TIN khách và ghi chú của hợp đồng — không sửa ĐIỀU KHOẢN.
     *
     * Gói, chu kỳ, giá và ba hạn mức không gửi lên được: chúng đã chốt lúc ký và
     * API không nhận ô nào cho chúng. Bán thêm quyền lợi cho một khách vẫn là
     * việc của `cmd/thue-bao` trên máy chủ.
     *
     * `het_han` chỉ có tác dụng khi hợp đồng đang dùng thử; bỏ trống là giữ
     * nguyên hạn hiện tại.
     */
    public function suaHopDong(int $id, array $du_lieu): Response
    {
        return $this->put("/platform/subscriptions/{$id}", $du_lieu);
    }

    /**
     * Đặt lại mật khẩu tài khoản quản trị của khách.
     *
     * Có đường này vì tài khoản quản trị cửa hàng KHÔNG có cách tự khôi phục:
     * quên-mật-khẩu-qua-email chỉ tồn tại trong cụm storefront dành cho khách
     * mua sắm. Khách quên mật khẩu thì gọi nhà cung cấp, và đây là chỗ nhà cung
     * cấp làm được việc đó.
     *
     * Gửi MỘT ô: ô "xác nhận" là lưới chặn gõ nhầm, và nó ở lại phía màn hình.
     * Câu trả lời kèm tên đăng nhập để đọc lại cho khách nghe.
     */
    public function doiMatKhauKhach(int $hopDong, string $matKhau): Response
    {
        return $this->post("/platform/subscriptions/{$hopDong}/doi-mat-khau", [
            'mat_khau' => $matKhau,
        ]);
    }

    /**
     * Thêm khách mới kèm hợp đồng CHÍNH THỨC.
     *
     * Cùng một đường với `taoDungThu` — cũng dựng cửa hàng + tài khoản đăng nhập
     * + hợp đồng trong một lượt — khác đúng một chỗ: hợp đồng ra `active` ngay,
     * không có giai đoạn dùng thử, và thời hạn tính bằng THÁNG.
     */
    public function taoChinhThuc(array $du_lieu): Response
    {
        return $this->post('/platform/chinh-thuc', $du_lieu);
    }

    /**
     * Gia hạn hợp đồng thêm $soThang tháng.
     *
     * CŨNG LÀ đường chuyển dùng thử sang chính thức: API đưa trạng thái về
     * `active` và xoá mốc hết dùng thử. Không có endpoint riêng cho việc chuyển
     * — hai việc là một, và tách ra thì bản thứ hai sẽ quên một trong hai vế.
     *
     * Mốc cộng là ngày hết hạn HOẶC hôm nay, lấy cái muộn hơn: hợp đồng đã quá
     * hạn ba tháng mà cộng dồn từ ngày cũ thì khách trả tiền xong vẫn quá hạn.
     */
    public function giaHanHopDong(int $id, int $soThang): Response
    {
        return $this->post("/platform/subscriptions/{$id}/gia-han", ['so_thang' => $soThang]);
    }

    /**
     * Huỷ hợp đồng, ghi lý do vào ghi chú của chính hợp đồng đó.
     *
     * Không xoá dòng: khách cũ vẫn phải tra được mình đã dùng gì, và doanh thu
     * của những tháng đã qua phải đứng nguyên. Huỷ xong mới ký lại được cho
     * khách đổi gói.
     */
    public function huyHopDong(int $id, string $lyDo = ''): Response
    {
        return $this->post("/platform/subscriptions/{$id}/huy", ['ly_do' => $lyDo]);
    }

    /**
     * Bỏ các tham số rỗng khỏi query.
     *
     * Không dùng array_filter: nó cũng vứt luôn "0" và "false", mà sau này có
     * thêm tham số nào nhận số 0 thì lỗi ấy im lặng. Ở đây chỉ rỗng mới bị bỏ,
     * vì với API "vắng mặt" mang nghĩa không lọc — khác hẳn lọc bằng chuỗi rỗng.
     */
    protected function loc(array $query): array
    {
        return array_filter($query, fn ($v) => $v !== '' && $v !== null);
    }
}
