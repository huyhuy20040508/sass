<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ApiClient — lớp gọi Backend Go API.
 *
 * Toàn bộ dữ liệu của Admin đều đi qua REST API (không kết nối MySQL trực tiếp).
 * Access token được lấy từ session sau khi đăng nhập và tự đính kèm Bearer.
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
    /**
     * KHOA_CHI_NHANH — ô trong phiên giữ chi nhánh người dùng đang làm việc.
     *
     * Để trong PHIÊN chứ không trong token: đổi chi nhánh là chuyện xảy ra giữa
     * ca làm (thủ kho sang kho khác đếm hàng), mà token thì chỉ đổi lúc đăng
     * nhập. Cũng không để trong URL — mỗi trang lại phải mang theo một tham số,
     * và quên một chỗ là ghi hàng vào kho khác.
     */
    public const KHOA_CHI_NHANH = 'chi_nhanh_dang_lam';

    /**
     * Chi nhánh do CHÍNH REQUEST NÀY khai (tham số `chi_nhanh`).
     *
     * Đứng trước phiên, và chỉ sống trong một lượt xử lý. Đây là thứ làm chi
     * nhánh thành chuyện của từng TAB thay vì của cả trình duyệt — xem
     * middleware ChiNhanhTheoTab.
     *
     * null = request không khai gì, rơi về phiên như cũ.
     */
    protected static ?int $chiNhanhCuaRequest = null;

    /**
     * Ghi chi nhánh của request hiện tại. Chỉ ChiNhanhTheoTab gọi.
     *
     * null = request không khai gì. 0 = khai RÕ "xem gộp mọi chi nhánh" — khác
     * hẳn không khai: 0 thắng phiên (tab đang xem gộp không được rơi về chi
     * nhánh mà tab khác vừa chọn), còn không khai thì rơi về phiên.
     */
    public static function datChiNhanhCuaRequest(?int $id): void
    {
        self::$chiNhanhCuaRequest = $id !== null && $id >= 0 ? $id : null;
    }

    /**
     * Người dùng ĐÃ chọn chi nhánh chưa — kể cả chọn "Tất cả" (0)?
     *
     * "Chưa chọn" (phiên không có khoá) và "chọn Tất cả" (khoá = 0) cùng làm
     * chiNhanhDangLam() trả 0, nhưng phải xử lý khác nhau: chưa chọn thì
     * ChiNhanhDangLam ghim chi nhánh đầu tiên vào phiên, còn Tất cả là lựa chọn
     * của người dùng và phải được giữ nguyên.
     */
    public static function daKhaiChiNhanh(): bool
    {
        return self::$chiNhanhCuaRequest !== null || session()->has(self::KHOA_CHI_NHANH);
    }

    /**
     * Chi nhánh đang có hiệu lực: của REQUEST trước, của PHIÊN sau.
     *
     * Mọi nơi cần biết "đang đứng ở kho nào" phải hỏi qua đây, đừng đọc thẳng
     * session — đọc thẳng là bỏ qua phần khai của tab và quay lại đúng lỗi cũ.
     */
    public static function chiNhanhDangLam(): int
    {
        return self::$chiNhanhCuaRequest ?? (int) session(self::KHOA_CHI_NHANH, 0);
    }

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

        // Chi nhánh ĐANG LÀM VIỆC đi kèm MỌI lượt gọi, không phải chỉ mấy trang
        // kho: đơn hàng, phiếu nhập và phiếu trả đều ghi lại chi nhánh phát sinh
        // (xem domain.Order.ShopID bên API). Gắn ở đây — chỗ duy nhất mọi request
        // đi qua — thay vì nhớ thêm tham số ở từng controller.
        //
        // Ưu tiên chi nhánh do CHÍNH REQUEST khai (tab nào đứng ở kho nấy), rồi
        // mới tới phiên. Không có cả hai thì KHÔNG gửi gì, và API tự suy ra —
        // đường đi của cửa hàng một chi nhánh, nơi màn hình không có ô chọn nào.
        if ($shopID = self::chiNhanhDangLam()) {
            $req = $req->withHeaders(['X-Chi-Nhanh' => (string) $shopID]);
        }

        // IP THẬT CỦA TRÌNH DUYỆT, chuyển tiếp cho API.
        //
        // PHP gọi API từ chính máy chủ (API_BASE_URL trỏ 127.0.0.1), nên nếu không
        // gửi gì thì với API mọi người dùng của mọi cửa hàng đều là một địa chỉ duy
        // nhất. Hạn mức đăng nhập "10 lượt / 5 phút" vì thế thành hạn mức CHUNG của
        // cả nền tảng: ca sáng năm người đăng nhập, ai gõ sai vài lần là cả tiệm
        // ăn 429.
        //
        // API chỉ tin header này khi bên gọi nằm trong TRUSTED_PROXIES (mặc định
        // 127.0.0.1) — đúng trường hợp này, và không đúng với client ngoài Internet.
        if ($ip = self::ipNguoiDung()) {
            $req = $req->withHeaders(['X-Forwarded-For' => $ip]);
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

        if ($res->status() !== 401) {
            $this->ghiNhanKhoaCuaHang($res);

            return $res;
        }

        if ($this->refreshToken($chet)) {
            $res = $this->dispatch($method, $uri, $payload);
            // Lượt thử lại cũng phải đi qua bộ dò cờ khoá: hợp đồng hết hạn giữa
            // lúc token cũ vừa chết thì tín hiệu 403 nằm ở đúng lượt gọi này.
            $this->ghiNhanKhoaCuaHang($res);

            return $res;
        }

        // Làm mới hỏng vì lý do TẠM THỜI (mạng chớp, API vừa khởi động lại, 5xx):
        // giữ nguyên phiên và trả 401 về cho nơi gọi. Xoá phiên ở đây là một cú
        // nấc mạng nửa giây đá người dùng ra màn hình đăng nhập — và đá luôn cả
        // những tab khác đang mở dở việc, vì phiên là của cả trình duyệt.
        if (! $chet) {
            return $res;
        }

        // Tới đây thì API đã nói rõ: refresh token cũng không còn giá trị. Xoá
        // session để lượt vào trang tiếp theo bị EnsureAdminAuthenticated đẩy về
        // màn hình đăng nhập.
        //
        // Không xoá thì phiên hỏng nằm lại trong session tới lúc hết hạn, và người
        // dùng chỉ thấy mọi trang báo lỗi mà không hiểu phải làm gì — đúng cảnh
        // xảy ra sau khi API đổi định dạng token (token cũ không mang mã cửa hàng
        // nên bị từ chối hết).
        session()->forget('api');

        // GIỮ LẠI LÝ DO API vừa nói, và đây không phải chi tiết trang trí.
        //
        // Go API trả về ba câu khác nhau cho ba tình huống khác hẳn nhau: "cửa
        // hàng đang tạm khoá, liên hệ nhà cung cấp phần mềm" (hết hạn hợp đồng),
        // "tài khoản đang không hoạt động, liên hệ cửa hàng", "token đã hết hạn".
        // Vứt hết đi rồi hiện câu chung "vui lòng đăng nhập bằng tài khoản quản
        // trị" thì người bị khoá vì hết hạn sẽ ngồi gõ lại mật khẩu — và gõ đúng
        // vẫn không vào được, vì mật khẩu chưa bao giờ là vấn đề. Xem
        // EnsureAdminAuthenticated, nơi câu này được đọc ra.
        //
        // Đặt NGOÀI khoá 'api' để nó sống sót qua lượt forget ngay trên.
        if ($lyDo = trim((string) $res->json('message'))) {
            session(['phien.ly_do_thoat' => $lyDo]);
        }

        return $res;
    }

    /**
     * Bắt tín hiệu CỬA HÀNG HẾT HẠN HỢP ĐỒNG và cất vào session.
     *
     * API trả 403 kèm `errors.ma = CUA_HANG_KHOA` cho mọi đường trừ đường đọc gói
     * dịch vụ. Mã máy đọc được chứ không phải câu chữ: so khớp thông báo tiếng
     * Việt thì một lần sửa chính tả bên kia là một lần hỏng lặng lẽ bên này.
     *
     * KHÔNG xoá session như nhánh 401: phiên này vẫn còn dùng được cho trang gói
     * dịch vụ, và đá người ta ra màn hình đăng nhập là lấy mất đúng chỗ nói cho
     * họ biết phải gia hạn bao nhiêu. Middleware `admin.khoa` đọc cờ này ở lượt
     * vào trang tiếp theo.
     */
    protected function ghiNhanKhoaCuaHang(Response $res): void
    {
        if ($res->status() !== 403) {
            return;
        }
        if ($res->json('errors.ma') !== 'CUA_HANG_KHOA') {
            return;
        }

        session([HanSuDung::KHOA_CO => true]);
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
     *
     * Trả true nếu làm mới thành công. Không đính access token cũ để tránh vòng lặp.
     *
     * $chet nói cho nơi gọi biết vì sao HỎNG, và đó là khác biệt quan trọng:
     * true  = API đã trả lời và từ chối (refresh token hết hạn, tài khoản bị khoá,
     *         cửa hàng ngừng hoạt động) — phiên này hết đường cứu;
     * false = chưa hỏi được (mạng chớp, API 5xx hoặc vừa khởi động lại) — phiên
     *         vẫn còn nguyên giá trị, lượt sau thử lại là xong.
     */
    protected function refreshToken(?bool &$chet = null): bool
    {
        $chet = false;

        $refresh = session('api.refresh_token');
        if (! $refresh) {
            // Không có gì để làm mới — phiên này đúng là đã hỏng.
            $chet = true;

            return false;
        }

        try {
            $res = $this->request(false)->post('/auth/refresh', [
                'refresh_token' => $refresh,
            ]);
        } catch (\Throwable $e) {
            return false;
        }

        if (! $res->successful()) {
            // 4xx là câu trả lời DỨT KHOÁT của API; 5xx chỉ là bên kia đang trục
            // trặc, không phải phán quyết về phiên của người dùng.
            $chet = $res->status() >= 400 && $res->status() < 500;

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
        // Làm mới token là lượt duy nhất API tra lại tình trạng cửa hàng bằng
        // refresh token, nên câu trả lời của nó là bản mới nhất về việc còn hạn
        // hay không. Ghi cả khi false: khách vừa gia hạn thì cờ phải rũ được ra,
        // không bắt họ đăng xuất rồi đăng nhập lại mới dùng tiếp được.
        session([HanSuDung::KHOA_CO => (bool) data_get($data, 'cua_hang_khoa', false)]);

        // Ghi xuống NGAY, đừng đợi cuối request. Phiên lưu bằng tệp và không khoá
        // đọc-sửa-ghi, nên một request song song của cùng người dùng (tab khác,
        // lượt gọi ngầm) kết thúc sau sẽ ghi đè bản nó đọc lúc đầu — tức là chép
        // token CŨ đè lên token vừa lấy. Ghi sớm thu hẹp khe hở đó.
        session()->save();

        return true;
    }

    // ---------- Auth ----------

    /**
     * Đăng nhập 3 ô: mã cửa hàng + tên đăng nhập + mật khẩu.
     *
     * Đường riêng chứ không dùng /auth/login: đường kia dành cho khách mua sắm
     * (đăng nhập bằng email) và có hạn mức chống dò mật khẩu riêng.
     */
    public function shopLogin(string $shopCode, string $username, string $password): Response
    {
        return $this->request(false)->post('/auth/shop-login', [
            'shop_code' => $shopCode,
            'username' => $username,
            'password' => $password,
        ]);
    }

    /**
     * IP của người đang dùng trình duyệt, hoặc null khi không chạy trong request
     * (lệnh console, hàng đợi).
     *
     * Tin được vì bootstrap/app.php chỉ trust proxy nội bộ: nginx đưa thẳng
     * REMOTE_ADDR qua fastcgi nên Laravel bỏ qua mọi X-Forwarded-For do client tự
     * khai. Trust '*' như trước thì con số này do chính kẻ dò mật khẩu chọn.
     */
    protected static function ipNguoiDung(): ?string
    {
        if (! app()->runningInConsole() && ($req = request()) !== null) {
            return $req->ip();
        }

        return null;
    }

    /** Lấy thông tin tài khoản hiện tại theo access token. */
    public function me(): Response
    {
        return $this->get('/auth/me');
    }

    /**
     * Trả access token còn hiệu lực để trình duyệt mở luồng SSE.
     *
     * Gọi /auth/me trước để đi qua cơ chế tự làm mới token của send(): nếu token
     * trong session đã hết hạn thì nó được thay bằng token mới ngay tại đây, thay
     * vì để trình duyệt mở stream rồi bị 401 và quay vòng kết nối lại.
     * Trả null khi phiên đã chết hẳn.
     */
    public function streamToken(): ?string
    {
        try {
            if (! $this->me()->successful()) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return session('api.access_token') ?: null;
    }

    // ---------- Hồ sơ của tôi ----------

    /**
     * Hồ sơ tài khoản đang đăng nhập, dạng đầy đủ (vai trò, lần đăng nhập gần nhất).
     *
     * Khác me(): /auth/me trả bản rút gọn dùng chung với storefront, còn đường này
     * trả đúng cấu trúc của trang Người dùng nên hai trang hiển thị giống hệt nhau.
     * Nhân viên gọi được — API để nhóm này ở tầng quyền `admin`, không phải `manage`.
     */
    public function profile(): Response
    {
        return $this->get('/admin/me');
    }

    /** Sửa hồ sơ của chính mình. Vai trò, trạng thái và email KHÔNG đi qua đây. */
    public function updateProfile(array $data): Response
    {
        return $this->put('/admin/me', $data);
    }

    /** Tự đổi mật khẩu — phải kèm mật khẩu hiện tại. */
    public function changePassword(string $current, string $new): Response
    {
        return $this->put('/admin/me/password', [
            'current_password' => $current,
            'new_password' => $new,
        ]);
    }

    // ---------- Notifications ----------

    /** Danh sách thông báo của kênh quản trị (kèm số chưa đọc). */
    public function notifications(array $query = []): Response
    {
        return $this->get('/notifications', $query);
    }

    /** Đánh dấu một thông báo đã đọc. */
    public function readNotification(int $id): Response
    {
        return $this->put("/notifications/{$id}/read");
    }

    /** Đánh dấu toàn bộ thông báo đã đọc. */
    public function readAllNotifications(): Response
    {
        return $this->post('/notifications/read-all');
    }

    // ---------- Categories ----------

    /** Danh sách danh mục ($all = true để lấy cả danh mục ẩn). */
    /**
     * Danh sách nhóm hàng hoá.
     *
     * $coHang = true thì chỉ trả nhóm ĐANG có mặt hàng — dành cho Ô LỌC đứng
     * cạnh một bảng hàng hoá. Ô CHỌN NHÓM lúc khai mặt hàng thì để false, nếu
     * không nhóm vừa lập (chưa có hàng nào) biến mất và không khai được hàng
     * đầu tiên vào đó.
     */
    public function categories(bool $all = true, bool $coHang = false): Response
    {
        $query = $all ? ['all' => 'true'] : [];
        if ($coHang) {
            $query['has_products'] = 'true';
        }

        return $this->get('/categories', $query);
    }

    /** Lấy 1 danh mục theo id. */
    public function category(int $id): Response
    {
        return $this->get("/categories/{$id}");
    }

    public function createCategory(array $data): Response
    {
        return $this->post('/admin/categories', $data);
    }

    public function updateCategory(int $id, array $data): Response
    {
        return $this->put("/admin/categories/{$id}", $data);
    }

    public function deleteCategory(int $id): Response
    {
        return $this->delete("/admin/categories/{$id}");
    }

    // ---------- Đơn vị tính (Hàng hóa -> Đơn vị) ----------

    /**
     * Danh sách đơn vị tính. $onlyActive = true để chỉ lấy đơn vị đang bật
     * (ô chọn đơn vị lúc khai mặt hàng dùng cái này).
     *
     * API cố ý không phân trang — danh sách chỉ vài chục dòng nên trang quản
     * trị tự cắt trang, và ô tìm kiếm vẫn tìm trên TOÀN bộ danh sách.
     */
    public function donViTinh(string $keyword = '', bool $onlyActive = false): Response
    {
        return $this->get('/admin/don-vi-tinh', array_filter([
            'keyword' => $keyword,
            'active' => $onlyActive ? 'true' : null,
        ]));
    }

    public function taoDonViTinh(array $payload): Response
    {
        return $this->post('/admin/don-vi-tinh', $payload);
    }

    public function suaDonViTinh(int $id, array $payload): Response
    {
        return $this->put("/admin/don-vi-tinh/{$id}", $payload);
    }

    /** Công tắc bật/tắt trên bảng. */
    public function doiTrangThaiDonViTinh(int $id, bool $isActive): Response
    {
        return $this->put("/admin/don-vi-tinh/{$id}/trang-thai", ['is_active' => $isActive]);
    }

    public function xoaDonViTinh(int $id): Response
    {
        return $this->delete("/admin/don-vi-tinh/{$id}");
    }

    // ---------- Vị trí (Hàng hóa -> Vị trí) ----------

    /**
     * Danh sách vị trí để hàng. $onlyActive = true để chỉ lấy vị trí đang bật
     * (ô chọn vị trí lúc khai mặt hàng dùng cái này).
     *
     * API cố ý không phân trang, cùng lý do với đơn vị tính.
     */
    public function viTri(string $keyword = '', bool $onlyActive = false): Response
    {
        return $this->get('/admin/vi-tri', array_filter([
            'keyword' => $keyword,
            'active' => $onlyActive ? 'true' : null,
        ]));
    }

    public function taoViTri(array $payload): Response
    {
        return $this->post('/admin/vi-tri', $payload);
    }

    public function suaViTri(int $id, array $payload): Response
    {
        return $this->put("/admin/vi-tri/{$id}", $payload);
    }

    /** Công tắc bật/tắt trên bảng. */
    public function doiTrangThaiViTri(int $id, bool $isActive): Response
    {
        return $this->put("/admin/vi-tri/{$id}/trang-thai", ['is_active' => $isActive]);
    }

    public function xoaViTri(int $id): Response
    {
        return $this->delete("/admin/vi-tri/{$id}");
    }

    // ---------- Thuộc tính (Hàng hóa -> Thuộc tính) ----------

    /**
     * Danh sách thuộc tính, mỗi dòng kèm luôn `values` là các giá trị con.
     * $onlyActive = true để chỉ lấy thuộc tính đang bật (ô chọn lúc khai mặt
     * hàng dùng cái này).
     *
     * API cố ý không phân trang, cùng lý do với đơn vị tính: danh sách chỉ vài
     * chục dòng nên trang quản trị tự cắt trang, và ô tìm kiếm vẫn tìm trên
     * TOÀN bộ danh sách.
     */
    public function thuocTinh(string $keyword = '', bool $onlyActive = false): Response
    {
        return $this->get('/admin/thuoc-tinh', array_filter([
            'keyword' => $keyword,
            'active' => $onlyActive ? 'true' : null,
        ]));
    }

    public function taoThuocTinh(array $payload): Response
    {
        return $this->post('/admin/thuoc-tinh', $payload);
    }

    /**
     * Sửa thuộc tính. `values` trong payload là trạng thái CUỐI CÙNG của bảng
     * giá trị: dòng có id thì sửa, không id thì thêm, giá trị cũ vắng mặt thì
     * xoá. Bỏ hẳn khoá `values` là không đụng tới bảng giá trị.
     */
    public function suaThuocTinh(int $id, array $payload): Response
    {
        return $this->put("/admin/thuoc-tinh/{$id}", $payload);
    }

    /** Công tắc bật/tắt trên bảng. */
    public function doiTrangThaiThuocTinh(int $id, bool $isActive): Response
    {
        return $this->put("/admin/thuoc-tinh/{$id}/trang-thai", ['is_active' => $isActive]);
    }

    public function xoaThuocTinh(int $id): Response
    {
        return $this->delete("/admin/thuoc-tinh/{$id}");
    }

    // ---------- Thuế suất ----------

    /**
     * Bốn dòng thuế của cửa hàng, mỗi dòng kèm bộ mức đang bật và bộ mức cho chọn.
     * Lượt gọi đầu tiên của một cửa hàng mới sẽ dựng sẵn bốn dòng theo mức mặc định.
     */
    public function taxes(): Response
    {
        return $this->get('/admin/thue');
    }

    /** Sửa bộ mức của một loại thuế ($muc là mảng số nguyên). */
    public function updateTax(int $id, array $muc): Response
    {
        return $this->put("/admin/thue/{$id}", ['muc' => array_values($muc)]);
    }

    /** Bật/tắt một loại thuế từ công tắc trên bảng. */
    public function toggleTaxStatus(int $id, bool $isActive): Response
    {
        return $this->put("/admin/thue/{$id}/trang-thai", ['is_active' => $isActive]);
    }

    // ---------- Banners ----------

    /**
     * Danh sách banner cho trang quản trị — gồm cả banner đang tắt và banner hẹn
     * lịch cho đợt sau (khác đường công khai /banners mà storefront dùng).
     */
    public function banners(string $position = ''): Response
    {
        return $this->get('/admin/banners', $position !== '' ? ['position' => $position] : []);
    }

    public function createBanner(array $data): Response
    {
        return $this->post('/admin/banners', $data);
    }

    public function updateBanner(int $id, array $data): Response
    {
        return $this->put("/admin/banners/{$id}", $data);
    }

    /** Bật/tắt hiển thị — không đụng tới nội dung và lịch chạy của banner. */
    public function updateBannerStatus(int $id, bool $isActive): Response
    {
        return $this->put("/admin/banners/{$id}/status", ['is_active' => $isActive]);
    }

    /** Sắp xếp lại: thứ tự phần tử trong $ids là thứ tự hiển thị. */
    public function sortBanners(array $ids): Response
    {
        return $this->put('/admin/banners/sort', ['ids' => array_values($ids)]);
    }

    public function deleteBanner(int $id): Response
    {
        return $this->delete("/admin/banners/{$id}");
    }

    // ---------- Chương trình khuyến mãi ----------

    /** Danh sách chương trình (lọc/tìm/phân trang phía API). */
    public function promotions(array $query = []): Response
    {
        return $this->get('/admin/promotions', $query);
    }

    /** Số đếm theo trạng thái cho dải thẻ đầu trang — không phụ thuộc bộ lọc. */
    public function promotionStats(): Response
    {
        return $this->get('/admin/promotions/stats');
    }

    public function createPromotion(array $data): Response
    {
        return $this->post('/admin/promotions', $data);
    }

    public function updatePromotion(int $id, array $data): Response
    {
        return $this->put("/admin/promotions/{$id}", $data);
    }

    /** Bật/tắt — dừng một đợt giữa chừng mà không phải sửa ngày kết thúc. */
    public function updatePromotionStatus(int $id, bool $isActive): Response
    {
        return $this->put("/admin/promotions/{$id}/status", ['is_active' => $isActive]);
    }

    public function deletePromotion(int $id): Response
    {
        return $this->delete("/admin/promotions/{$id}");
    }

    // ---------- Voucher ----------

    /** Danh sách voucher (lọc/tìm/phân trang phía API). */
    public function vouchers(array $query = []): Response
    {
        return $this->get('/admin/vouchers', $query);
    }

    /** Số đếm theo trạng thái cho dải thẻ đầu trang — không phụ thuộc bộ lọc. */
    public function voucherStats(): Response
    {
        return $this->get('/admin/vouchers/stats');
    }

    public function createVoucher(array $data): Response
    {
        return $this->post('/admin/vouchers', $data);
    }

    public function updateVoucher(int $id, array $data): Response
    {
        return $this->put("/admin/vouchers/{$id}", $data);
    }

    /** Bật/tắt — ngừng phát một mã mà không phải sửa ngày kết thúc. */
    public function updateVoucherStatus(int $id, bool $isActive): Response
    {
        return $this->put("/admin/vouchers/{$id}/status", ['is_active' => $isActive]);
    }

    public function deleteVoucher(int $id): Response
    {
        return $this->delete("/admin/vouchers/{$id}");
    }

    // ---------- Products ----------

    /**
     * Danh sách sản phẩm (lọc/tìm/phân trang phía server).
     * $query hỗ trợ: keyword, category_id, location_id, unit_id, multi_variant,
     * active, all, featured, status, min_price, max_price, sort, page, page_size.
     *
     * keyword tìm cả trên MÃ VẠCH của biến thể — đó là đường dùng nhiều nhất
     * ngoài quầy, người bán quét mã dán trên món hàng chứ không gõ tên.
     */
    public function products(array $query = []): Response
    {
        return $this->get('/products', $query);
    }

    public function createProduct(array $data): Response
    {
        return $this->post('/admin/products', $data);
    }

    /** Chi tiết sản phẩm theo ID (dành cho admin). */
    public function product(int $id): Response
    {
        return $this->get("/admin/products/{$id}");
    }

    public function updateProduct(int $id, array $data): Response
    {
        return $this->put("/admin/products/{$id}", $data);
    }

    /** Bật/tắt trạng thái bán — chỉ gửi is_active, không đụng biến thể/ảnh. */
    /** Đổi trạng thái kinh doanh: active (đang bán) | hidden (tạm ẩn) | discontinued (ngừng kinh doanh). */
    public function setProductStatus(int $id, string $status): Response
    {
        return $this->put("/admin/products/{$id}/status", ['status' => $status]);
    }

    /**
     * Thẻ hàng hóa của cửa hàng ("Bán chạy nhất", "Món mới"…).
     *
     * Chỉ có đường ĐỌC: thẻ sinh ra từ chính lượt lưu mặt hàng — gõ tên mới ở ô
     * thẻ trong hộp thoại là máy chủ mở thẻ mới.
     */
    public function theHangHoa(): Response
    {
        return $this->get('/admin/the-hang-hoa');
    }

    /**
     * Bật/tắt bán hàng bằng CỜ — công tắc trạng thái ngoài bảng danh sách.
     *
     * Khác setProductStatus ở chỗ không nói mức nào: tắt mà mặt hàng đang "ngừng
     * kinh doanh" thì API giữ nguyên mức ấy, chỉ hàng đang bán mới xuống "tạm ẩn".
     */
    public function setProductActive(int $id, bool $active): Response
    {
        return $this->put("/admin/products/{$id}/status", ['is_active' => $active]);
    }

    /**
     * Đổi thứ tự mặt hàng trên bảng danh sách: đưa lên trên (`up`) hoặc xuống
     * dưới (`down`) một bậc. API đổi chỗ hai giá trị sort, không đánh số lại.
     */
    public function moveProductSort(int $id, string $huong): Response
    {
        return $this->put("/admin/products/{$id}/sort", ['huong' => $huong]);
    }

    /** Ghi lại trình tự hàng hoá sau một lượt kéo thả. $ids theo thứ tự hiển thị. */
    public function sapXepProducts(array $ids): Response
    {
        return $this->put('/admin/products/sap-xep', ['ids' => array_values($ids)]);
    }

    /** Xoá nhiều sản phẩm trong MỘT lượt gọi (API chạy trong một giao dịch). */
    public function bulkDeleteProducts(array $ids): Response
    {
        return $this->post('/admin/products/bulk-delete', ['ids' => array_values($ids)]);
    }

    public function deleteProduct(int $id): Response
    {
        return $this->delete("/admin/products/{$id}");
    }

    public function duplicateProduct(int $id): Response
    {
        return $this->post("/admin/products/{$id}/duplicate");
    }

    // ---------- Customers ----------

    /**
     * Danh sách khách hàng (lọc/tìm/sắp xếp/phân trang phía server).
     * $query hỗ trợ: keyword, status, gender, sort, page, page_size.
     */
    public function customers(array $query = []): Response
    {
        return $this->get('/admin/customers', $query);
    }

    /** Thống kê số khách hàng theo trạng thái tài khoản. */
    public function customerStats(): Response
    {
        return $this->get('/admin/customers/stats');
    }

    public function customer(int $id): Response
    {
        return $this->get("/admin/customers/{$id}");
    }

    public function createCustomer(array $data): Response
    {
        return $this->post('/admin/customers', $data);
    }

    public function updateCustomer(int $id, array $data): Response
    {
        return $this->put("/admin/customers/{$id}", $data);
    }

    /** Bật/tắt nhanh tài khoản khách hàng (active|inactive). */
    public function updateCustomerStatus(int $id, string $status): Response
    {
        return $this->put("/admin/customers/{$id}/status", ['status' => $status]);
    }

    /** Cấp (hoặc đặt lại) mật khẩu đăng nhập storefront cho khách hàng. */
    public function setCustomerPassword(int $id, string $password): Response
    {
        return $this->put("/admin/customers/{$id}/password", ['password' => $password]);
    }

    public function deleteCustomer(int $id): Response
    {
        return $this->delete("/admin/customers/{$id}");
    }

    // ---------- Orders ----------

    /**
     * Danh sách đơn hàng (lọc/tìm/sắp xếp/phân trang phía server).
     * $query hỗ trợ: keyword, status, payment_status, payment_method, user_id,
     * from_date, to_date, sort, page, page_size.
     */
    public function orders(array $query = []): Response
    {
        return $this->get('/admin/orders', $query);
    }

    /** Thống kê đơn hàng theo nhóm trạng thái + doanh thu. */
    public function orderStats(): Response
    {
        return $this->get('/admin/orders/stats');
    }

    /** Chi tiết đơn hàng (kèm sản phẩm, lịch sử trạng thái, trạng thái kế tiếp). */
    public function order(int $id): Response
    {
        return $this->get("/admin/orders/{$id}");
    }

    /** Tạo đơn hàng thủ công cho khách hàng có sẵn (payload theo OrderCreateRequest). */
    public function createOrder(array $payload): Response
    {
        return $this->post('/admin/orders', $payload);
    }

    /**
     * Bán một lượt TẠI QUẦY (payload theo POSCheckoutRequest).
     *
     * Khác createOrder ở chỗ payload KHÔNG mang giá: mỗi dòng chỉ nói mua biến
     * thể nào và mấy cái, còn giá do API tra lại từ database rồi áp khuyến mãi.
     * Đơn trả về đã hoàn tất và đã thu tiền.
     */
    public function posCheckout(array $payload): Response
    {
        return $this->post('/admin/orders/pos', $payload);
    }

    /** Quét một mã vạch (hoặc SKU) — trả món hàng kèm giá đã trừ khuyến mãi và tồn chi nhánh. */
    public function posScan(string $code): Response
    {
        return $this->get('/admin/orders/pos/scan', ['code' => $code]);
    }

    /**
     * Mức giảm giá tối đa NGƯỜI ĐANG ĐĂNG NHẬP được tự bấm cho một dòng hàng.
     *
     * Hỏi API chứ không tự suy từ vai trò trong phiên: luật nằm ở một chỗ duy
     * nhất, và chỗ đó cũng là chỗ chặn thật khi chốt đơn.
     */
    public function posDiscountLimit(): Response
    {
        return $this->get('/admin/orders/pos/discount-limit');
    }

    // ---------- Ca làm việc & sổ quỹ ----------

    /** Ca đang mở của chi nhánh đang làm việc. `data` = null nghĩa là chưa mở ca. */
    public function caHienTai(): Response
    {
        return $this->get('/admin/ca-lam-viec/hien-tai');
    }

    public function moCa(array $payload): Response
    {
        return $this->post('/admin/ca-lam-viec/mo', $payload);
    }

    public function dongCa(array $payload): Response
    {
        return $this->post('/admin/ca-lam-viec/dong', $payload);
    }

    public function caLamViec(array $query = []): Response
    {
        return $this->get('/admin/ca-lam-viec', $query);
    }

    public function caChiTiet(int $id): Response
    {
        return $this->get("/admin/ca-lam-viec/{$id}");
    }

    /** Ghi tay một khoản thu/chi tiền mặt vào sổ quỹ. */
    public function ghiSoQuy(array $payload): Response
    {
        return $this->post('/admin/so-quy', $payload);
    }

    /** Sửa thông tin & danh sách sản phẩm của đơn có sẵn (payload theo OrderUpdateRequest). */
    public function updateOrder(int $id, array $payload): Response
    {
        return $this->put("/admin/orders/{$id}", $payload);
    }

    /** Chuyển trạng thái đơn hàng; $note là lý do khi huỷ đơn. */
    public function updateOrderStatus(int $id, string $status, string $note = ''): Response
    {
        return $this->put("/admin/orders/{$id}/status", [
            'status' => $status,
            'note' => $note,
        ]);
    }

    /** Cập nhật tình trạng thanh toán của đơn. */
    public function updateOrderPayment(int $id, string $paymentStatus): Response
    {
        return $this->put("/admin/orders/{$id}/payment", ['payment_status' => $paymentStatus]);
    }

    /** Lưu ghi chú nội bộ trên đơn hàng. */
    public function updateOrderNote(int $id, string $note): Response
    {
        return $this->put("/admin/orders/{$id}/note", ['admin_note' => $note]);
    }

    /** Cập nhật đơn vị vận chuyển & mã vận đơn của đơn. */
    public function updateOrderShipping(int $id, string $shippingMethod, string $trackingNumber): Response
    {
        return $this->put("/admin/orders/{$id}/shipping", [
            'shipping_method' => $shippingMethod,
            'tracking_number' => $trackingNumber,
        ]);
    }

    // ---------- Returns (trả hàng) ----------

    /**
     * Danh sách phiếu trả hàng (lọc/tìm/sắp xếp/phân trang phía server).
     * $query hỗ trợ: keyword, status, reason, order_id, user_id, from_date,
     * to_date, sort, page, page_size.
     */
    public function returns(array $query = []): Response
    {
        return $this->get('/admin/returns', $query);
    }

    /** Thống kê phiếu trả hàng theo trạng thái + tổng tiền đã hoàn. */
    public function returnStats(): Response
    {
        return $this->get('/admin/returns/stats');
    }

    /** Chi tiết phiếu trả hàng (kèm món trả, đơn gốc, lịch sử, trạng thái kế tiếp). */
    public function orderReturn(int $id): Response
    {
        return $this->get("/admin/returns/{$id}");
    }

    /**
     * Các dòng hàng của một đơn kèm SỐ CÒN TRẢ ĐƯỢC — màn hình lập phiếu dựng
     * form từ đây thay vì tự trừ số đã nằm trong phiếu khác.
     */
    public function returnableItems(int $orderId): Response
    {
        return $this->get("/admin/orders/{$orderId}/returnable");
    }

    /** Nhân viên lập phiếu trả hàng (payload theo ReturnAdminCreateRequest). */
    public function createReturn(array $payload): Response
    {
        return $this->post('/admin/returns', $payload);
    }

    /** Chuyển trạng thái phiếu trả; $note là lý do khi từ chối. */
    public function updateReturnStatus(int $id, string $status, string $note = ''): Response
    {
        return $this->put("/admin/returns/{$id}/status", [
            'status' => $status,
            'note' => $note,
        ]);
    }

    /** Chốt số tiền hoàn của phiếu (payload theo ReturnSettleRequest). */
    public function settleReturn(int $id, array $payload): Response
    {
        return $this->put("/admin/returns/{$id}/settle", $payload);
    }

    /** Lưu ghi chú nội bộ trên phiếu trả hàng. */
    public function updateReturnNote(int $id, string $note): Response
    {
        return $this->put("/admin/returns/{$id}/note", ['admin_note' => $note]);
    }

    // ---------- Inventory (Tồn kho) ----------

    /**
     * Danh sách tồn kho theo BIẾN THỂ sản phẩm (lọc/sắp xếp/phân trang phía server).
     * $query hỗ trợ: keyword, category_id, stock, is_active, low_stock,
     * sort, page, page_size.
     */
    public function inventory(array $query = []): Response
    {
        return $this->get('/admin/inventory', $query);
    }

    /**
     * Tồn kho TÁCH RA theo từng chi nhánh — màn "Tồn kho chi nhánh".
     *
     * Khác inventory() ở chỗ một biến thể trả về nhiều dòng, mỗi chi nhánh một
     * dòng. `shops` là danh sách id ngăn bằng dấu phẩy; bỏ trống thì API lấy mọi
     * chi nhánh đang mở.
     */
    public function tonKhoChiNhanh(array $query = []): Response
    {
        return $this->get('/admin/inventory/chi-nhanh', $query);
    }

    /** Thống kê tồn kho toàn hệ thống (không phụ thuộc bộ lọc đang áp). */
    public function inventoryStats(int $lowStock = 5): Response
    {
        return $this->get('/admin/inventory/stats', ['low_stock' => $lowStock]);
    }

    /** Chi tiết một biến thể kèm 20 bút toán kho gần nhất. */
    public function inventoryItem(int $variantId): Response
    {
        return $this->get("/admin/inventory/{$variantId}");
    }

    /** Sổ kho đầy đủ của một biến thể (có phân trang). */
    public function inventoryHistory(int $variantId, array $query = []): Response
    {
        return $this->get("/admin/inventory/{$variantId}/history", $query);
    }

    /** Chỉnh tồn kho một biến thể (payload theo InventoryAdjustRequest). */
    public function adjustInventory(int $variantId, array $payload): Response
    {
        return $this->put("/admin/inventory/{$variantId}", $payload);
    }

    /** Chỉnh tồn kho nhiều biến thể trong một lần kiểm kê (tất-cả-hoặc-không). */
    public function bulkAdjustInventory(array $payload): Response
    {
        return $this->post('/admin/inventory/adjust', $payload);
    }

    /** Khai giá vốn cho nhiều biến thể (payload theo InventoryCostRequest). */
    public function setInventoryCosts(array $payload): Response
    {
        return $this->put('/admin/inventory/cost', $payload);
    }

    // ---------- Nhà cung cấp ----------
    //
    // Danh mục đầu mối mua vào, dựng lại theo bản order v2. Các đường dưới đây
    /**
     * Danh sách nhà cung cấp. $query hỗ trợ: keyword, active.
     */
    public function nhaCungCap(array $query = []): Response
    {
        return $this->get('/admin/nha-cung-cap', array_filter($query));
    }

    public function nhaCungCapChiTiet(int $id): Response
    {
        return $this->get("/admin/nha-cung-cap/{$id}");
    }

    /** Thêm nhà cung cấp. Bỏ trống `code` thì API tự đặt theo quy tắc đánh số. */
    public function taoNhaCungCap(array $data): Response
    {
        return $this->post('/admin/nha-cung-cap', $data);
    }

    /** Sửa nhà cung cấp. Bỏ trống `code` = giữ nguyên mã cũ. */
    public function suaNhaCungCap(int $id, array $data): Response
    {
        return $this->put("/admin/nha-cung-cap/{$id}", $data);
    }

    /** Bật/tắt hợp tác — chỉ gửi đúng một trường, không đụng các cột khác. */
    public function trangThaiNhaCungCap(int $id, int $status): Response
    {
        return $this->put("/admin/nha-cung-cap/{$id}/trang-thai", ['is_active' => $status === 1]);
    }

    public function xoaNhaCungCap(int $id): Response
    {
        return $this->delete("/admin/nha-cung-cap/{$id}");
    }

    // ---------- Phiếu mua hàng ----------
    //
    // Chứng từ mua vào, một loại duy nhất theo màn cùng tên của bản order v2.
    // Duyệt đi đường riêng vì đó là lúc hàng vào kho thật và API canh quyền
    // `phieu-mua-hang.duyet` ở đúng đường đó.

    /**
     * Danh sách phiếu. $query hỗ trợ: keyword, status, payment_status,
     * supplier_id, variant_id, from_date, to_date, sort, page, page_size.
     */
    public function phieuMuaHang(array $query = []): Response
    {
        return $this->get('/admin/phieu-mua-hang', array_filter($query, fn ($v) => $v !== '' && $v !== null));
    }

    /** Con số đầu trang: đếm phiếu theo trạng thái, tiền đã mua, tiền còn nợ. */
    public function phieuMuaHangThongKe(): Response
    {
        return $this->get('/admin/phieu-mua-hang/stats');
    }

    /** Tìm mặt hàng để đưa vào phiếu — kèm giá vốn gợi ý, tồn kho và đơn vị mua được. */
    public function phieuMuaHangMatHang(array $query = []): Response
    {
        return $this->get('/admin/phieu-mua-hang/mat-hang', array_filter($query));
    }

    /** Nhóm hàng CÓ hàng mua được — ô lọc nhóm trong hộp lập phiếu. */
    public function phieuMuaHangNhomHang(): Response
    {
        return $this->get('/admin/phieu-mua-hang/nhom-hang');
    }

    public function phieuMuaHangChiTiet(int $id): Response
    {
        return $this->get("/admin/phieu-mua-hang/{$id}");
    }

    /** Lập phiếu. Phiếu mới LUÔN là phiếu lưu tạm, chưa đụng tới kho. */
    public function taoPhieuMuaHang(array $data): Response
    {
        return $this->post('/admin/phieu-mua-hang', $data);
    }

    /** Sửa phiếu — API chỉ nhận phiếu lưu tạm. */
    public function suaPhieuMuaHang(int $id, array $data): Response
    {
        return $this->put("/admin/phieu-mua-hang/{$id}", $data);
    }

    /**
     * Duyệt phiếu: hàng vào kho, phiếu khoá lại.
     *
     * LUÔN kèm khoá `note` kể cả khi rỗng. Mảng PHP rỗng mã hoá thành `[]` —
     * một MẢNG JSON, không phải object — và bên Go bind nó vào struct thì trượt,
     * trả 422 "dữ liệu không hợp lệ" cho một lượt gọi chẳng có dữ liệu nào sai.
     */
    public function duyetPhieuMuaHang(int $id, array $data = []): Response
    {
        return $this->post("/admin/phieu-mua-hang/{$id}/duyet", $data + ['note' => '']);
    }

    /** Huỷ phiếu lưu tạm — API bắt buộc có lý do. */
    public function huyPhieuMuaHang(int $id, string $lyDo): Response
    {
        return $this->post("/admin/phieu-mua-hang/{$id}/huy", ['note' => $lyDo]);
    }

    /** Ghi nhận tiền đã trả NCC. `paid_amount` là số LUỸ KẾ, không phải số vừa trả thêm. */
    /**
     * Ghi nhận tiền trả nhà cung cấp.
     *
     * `$daTra` là số LUỸ KẾ. `$them` mang phần thoả thuận nợ (hình thức trả, hạn
     * nợ, người đại diện, ảnh chứng từ) — server tự soát, xem
     * PurchasePaymentRequest bên API.
     */
    public function traTienPhieuMuaHang(int $id, float $daTra, string $ghiChu = '', array $them = []): Response
    {
        return $this->post("/admin/phieu-mua-hang/{$id}/thanh-toan", $them + [
            'paid_amount' => $daTra,
            'note' => $ghiChu,
        ]);
    }

    public function xoaPhieuMuaHang(int $id): Response
    {
        return $this->delete("/admin/phieu-mua-hang/{$id}");
    }

    // ---------- Điều chỉnh tồn kho ----------
    //
    // Chứng từ nắn lại số tồn. Duyệt là lúc kho đổi số nên đi đường riêng,
    // giống phiếu mua.

    /**
     * Danh sách phiếu. $query hỗ trợ: keyword, type, status, warehouse_status,
     * created_by, from_date, to_date, sort, page, page_size.
     */
    public function dieuChinhTonKho(array $query = []): Response
    {
        return $this->get('/admin/dieu-chinh-ton-kho', array_filter($query, fn ($v) => $v !== '' && $v !== null));
    }

    public function dieuChinhTonKhoChiTiet(int $id): Response
    {
        return $this->get("/admin/dieu-chinh-ton-kho/{$id}");
    }

    /** Hàng đang âm chờ cân đối — nguồn của hộp "Cân đối hàng âm". */
    public function dieuChinhTonKhoHangAm(): Response
    {
        return $this->get('/admin/dieu-chinh-ton-kho/hang-am');
    }

    /** Lập phiếu. `status` nói phiếu dừng ở lưu tạm, gửi duyệt hay duyệt luôn. */
    public function taoDieuChinhTonKho(array $data): Response
    {
        return $this->post('/admin/dieu-chinh-ton-kho', $data);
    }

    /** Sửa phiếu — API chỉ nhận phiếu lưu tạm. */
    public function suaDieuChinhTonKho(int $id, array $data): Response
    {
        return $this->put("/admin/dieu-chinh-ton-kho/{$id}", $data);
    }

    public function guiDuyetDieuChinhTonKho(int $id): Response
    {
        return $this->post("/admin/dieu-chinh-ton-kho/{$id}/gui-duyet", ['note' => '']);
    }

    /** Duyệt phiếu: số tồn đổi theo phiếu. Luôn kèm `note` kể cả khi rỗng. */
    public function duyetDieuChinhTonKho(int $id, string $ghiChu = ''): Response
    {
        return $this->post("/admin/dieu-chinh-ton-kho/{$id}/duyet", ['note' => $ghiChu]);
    }

    /** Từ chối phiếu chờ duyệt — API bắt buộc có lý do. */
    public function tuChoiDieuChinhTonKho(int $id, string $lyDo): Response
    {
        return $this->post("/admin/dieu-chinh-ton-kho/{$id}/tu-choi", ['reject_reason' => $lyDo]);
    }

    public function xoaDieuChinhTonKho(int $id): Response
    {
        return $this->delete("/admin/dieu-chinh-ton-kho/{$id}");
    }

    // ---------- Trả hàng nhà cung cấp ----------
    //
    // Chiều ngược của phiếu mua: hàng đã nhập trả lại bên bán. Duyệt là lúc kho
    // bị TRỪ nên đi đường riêng, giống phiếu mua.

    /**
     * Danh sách phiếu trả. $query hỗ trợ: keyword, status, supplier_id,
     * from_date, to_date, sort, page, page_size.
     */
    public function traHangNhaCungCap(array $query = []): Response
    {
        return $this->get('/admin/tra-hang-nha-cung-cap', array_filter($query, fn ($v) => $v !== '' && $v !== null));
    }

    /** Con số đầu trang: đếm phiếu theo trạng thái và tổng tiền đã trả lại. */
    public function traHangNhaCungCapThongKe(): Response
    {
        return $this->get('/admin/tra-hang-nha-cung-cap/stats');
    }

    public function traHangNhaCungCapChiTiet(int $id): Response
    {
        return $this->get("/admin/tra-hang-nha-cung-cap/{$id}");
    }

    /** Lập phiếu trả. Phiếu mới LUÔN là phiếu lưu tạm, chưa đụng tới kho. */
    public function taoTraHangNhaCungCap(array $data): Response
    {
        return $this->post('/admin/tra-hang-nha-cung-cap', $data);
    }

    /** Sửa phiếu trả — API chỉ nhận phiếu lưu tạm. */
    public function suaTraHangNhaCungCap(int $id, array $data): Response
    {
        return $this->put("/admin/tra-hang-nha-cung-cap/{$id}", $data);
    }

    /**
     * Phiếu mua ĐÃ DUYỆT của một nhà cung cấp — ô "Chọn phiếu mua".
     *
     * Đi đường của module trả hàng chứ không mượn /admin/phieu-mua-hang: người
     * chỉ được giao việc trả hàng thì không nhất thiết có quyền xem phiếu mua.
     */
    public function traHangNhaCungCapPhieuMua(int $supplierID): Response
    {
        return $this->get('/admin/tra-hang-nha-cung-cap/phieu-mua', ['supplier_id' => $supplierID]);
    }

    /** Dòng của một phiếu mua, kèm `returned` / `stock` / `returnable`. */
    public function traHangNhaCungCapDongPhieuMua(int $purchaseID): Response
    {
        return $this->get('/admin/tra-hang-nha-cung-cap/dong-phieu-mua', ['purchase_id' => $purchaseID]);
    }

    /** Duyệt: hàng rời kho. Luôn kèm khoá `note` để Go bind được struct. */
    public function duyetTraHangNhaCungCap(int $id, array $data = []): Response
    {
        return $this->post("/admin/tra-hang-nha-cung-cap/{$id}/duyet", $data + ['note' => '']);
    }

    public function xoaTraHangNhaCungCap(int $id): Response
    {
        return $this->delete("/admin/tra-hang-nha-cung-cap/{$id}");
    }

    // -----------------------------------------------------------------
    // Phiếu điều chuyển — chuyển hàng giữa hai kho của cùng cửa hàng
    // -----------------------------------------------------------------

    /**
     * Danh sách phiếu điều chuyển.
     *
     * API cắt theo chi nhánh đang làm việc và cắt theo CẢ HAI ĐẦU (kho gửi lẫn
     * kho nhận) — không phải truyền gì thêm, header chi nhánh đã đi kèm mọi lượt
     * gọi.
     */
    public function phieuDieuChuyen(array $query = []): Response
    {
        return $this->get('/admin/phieu-dieu-chuyen', array_filter($query, fn ($v) => $v !== '' && $v !== null));
    }

    /** Con số đầu trang: đếm phiếu theo trạng thái và giá trị hàng đã chuyển. */
    public function phieuDieuChuyenThongKe(array $query = []): Response
    {
        return $this->get('/admin/phieu-dieu-chuyen/stats', array_filter($query, fn ($v) => $v !== '' && $v !== null));
    }

    public function phieuDieuChuyenChiTiet(int $id): Response
    {
        return $this->get("/admin/phieu-dieu-chuyen/{$id}");
    }

    /** Lập phiếu. Phiếu mới LUÔN là phiếu lưu tạm, chưa đụng tới kho. */
    public function taoPhieuDieuChuyen(array $data): Response
    {
        return $this->post('/admin/phieu-dieu-chuyen', $data);
    }

    /** Sửa phiếu — API chỉ nhận phiếu lưu tạm. */
    public function suaPhieuDieuChuyen(int $id, array $data): Response
    {
        return $this->put("/admin/phieu-dieu-chuyen/{$id}", $data);
    }

    /** Duyệt: hàng rời kho gửi và vào kho nhận. Luôn kèm `note` để Go bind được. */
    public function duyetPhieuDieuChuyen(int $id, array $data = []): Response
    {
        return $this->post("/admin/phieu-dieu-chuyen/{$id}/duyet", $data + ['note' => '']);
    }

    public function xoaPhieuDieuChuyen(int $id): Response
    {
        return $this->delete("/admin/phieu-dieu-chuyen/{$id}");
    }

    // ---------- Settings (cấu hình hệ thống) ----------

    /**
     * Cấu hình hệ thống: trả về `values` (map key → value) kèm `fields` (siêu dữ
     * liệu từng khoá theo registry của API). $group rỗng = lấy mọi nhóm.
     */
    public function settings(string $group = ''): Response
    {
        return $this->get('/admin/settings', $group !== '' ? ['group' => $group] : []);
    }

    /**
     * Ghi nhiều khoá cấu hình trong một lần gọi. Khoá không gửi lên giữ nguyên;
     * một khoá sai làm cả lần ghi bị từ chối (422) chứ không lưu nửa vời.
     */
    public function updateSettings(array $items): Response
    {
        return $this->put('/admin/settings', ['items' => $items]);
    }

    // ---------- Yêu cầu của khách + Đăng ký nhận tin ----------

    /**
     * Hộp thư đến từ storefront (form Liên hệ, form Thu mua).
     * $query hỗ trợ: keyword, type, status, from, to, page, page_size.
     */
    public function contactRequests(array $query = []): Response
    {
        return $this->get('/admin/contact-requests', $query);
    }

    /** Đếm yêu cầu theo trạng thái — huy hiệu "chưa xử lý" ở sidebar đọc số này. */
    public function contactStats(): Response
    {
        return $this->get('/admin/contact-requests/stats');
    }

    /** Đổi trạng thái xử lý một yêu cầu ($payload: status, admin_note). */
    public function updateContactStatus(int $id, array $payload): Response
    {
        return $this->put("/admin/contact-requests/{$id}/status", $payload);
    }

    /** Xoá một yêu cầu (bên API là xoá mềm, sổ vẫn giữ để còn phục hồi). */
    public function deleteContactRequest(int $id): Response
    {
        return $this->delete("/admin/contact-requests/{$id}");
    }

    /** Danh sách email đăng ký nhận tin. $query hỗ trợ: keyword, page, page_size. */
    public function newsletterSubscribers(array $query = []): Response
    {
        return $this->get('/admin/newsletter', $query);
    }

    /** Gỡ một email khỏi danh sách nhận tin. */
    public function unsubscribeNewsletter(int $id): Response
    {
        return $this->put("/admin/newsletter/{$id}/unsubscribe", []);
    }

    // ---------- Users & Roles (tài khoản nội bộ) ----------

    /**
     * Danh sách tài khoản NỘI BỘ (quản trị + nhân viên), lọc/phân trang phía server.
     * $query hỗ trợ: keyword, role_id, status, from_date, to_date, sort, page, page_size.
     *
     * Khách hàng KHÔNG nằm trong kết quả — họ có customers() riêng.
     */
    public function users(array $query = []): Response
    {
        return $this->get('/admin/users', $query);
    }

    /** Đếm tài khoản nội bộ theo trạng thái (không phụ thuộc bộ lọc đang xem). */
    public function userStats(): Response
    {
        return $this->get('/admin/users/stats');
    }

    /** Thêm tài khoản nội bộ (bỏ trống password thì API cấp mật khẩu mặc định). */
    public function createUser(array $data): Response
    {
        return $this->post('/admin/users', $data);
    }

    /** Sửa tài khoản nội bộ (không đụng tới mật khẩu). */
    public function updateUser(int $id, array $data): Response
    {
        return $this->put("/admin/users/{$id}", $data);
    }

    /** Khoá / mở khoá một tài khoản nội bộ. */
    public function updateUserStatus(int $id, string $status): Response
    {
        return $this->put("/admin/users/{$id}/status", ['status' => $status]);
    }

    /** Đặt lại mật khẩu đăng nhập trang quản trị. */
    public function setUserPassword(int $id, string $password): Response
    {
        return $this->put("/admin/users/{$id}/password", ['password' => $password]);
    }

    /** Xoá mềm một tài khoản nội bộ. */
    public function deleteUser(int $id): Response
    {
        return $this->delete("/admin/users/{$id}");
    }

    // ---------- Nhân sự (hồ sơ người đi làm) ----------
    //
    // Đây là bảng `employees` bên API — CON NGƯỜI, khác hẳn users() ở trên (tài
    // khoản đăng nhập). Một nhân viên có thể không có tài khoản nào; việc cấp
    // tài khoản là khối `tai_khoan` gửi kèm trong hồ sơ.

    /**
     * Danh sách hồ sơ nhân viên.
     * $query hỗ trợ: keyword, status, position, contract_type, shop_id.
     *
     * Không phân trang — số người của một cửa hàng đếm bằng hàng chục.
     */
    public function nhanSu(array $query = []): Response
    {
        return $this->get('/admin/nhan-su', $query);
    }

    /** Thêm hồ sơ nhân viên. Bỏ trống `code` thì API tự đặt (NV0001, NV0002…). */
    public function taoNhanSu(array $data): Response
    {
        return $this->post('/admin/nhan-su', $data);
    }

    /** Sửa hồ sơ nhân viên. Bỏ trống `code` = giữ nguyên mã cũ. */
    public function suaNhanSu(int $id, array $data): Response
    {
        return $this->put("/admin/nhan-su/{$id}", $data);
    }

    /**
     * Bật/tắt nhanh trạng thái làm việc — đường riêng cho công tắc trên bảng.
     *
     * Không dùng lại suaNhanSu(): bấm một công tắc mà gửi lên cả hồ sơ thì chỉ cần
     * trang đang giữ một bản cũ là lượt bấm đó ghi đè ngược mọi thứ vừa sửa.
     *
     * `da_nghi` luôn khoá tài khoản đăng nhập gắn kèm (API tự làm). $moTaiKhoan
     * chỉ có nghĩa ở chiều ngược lại — nhận người cũ làm lại thì mở luôn tài
     * khoản hay để khoá, màn hình hỏi trước rồi gửi câu trả lời xuống đây.
     */
    public function doiTrangThaiNhanSu(int $id, string $status, bool $moTaiKhoan = false): Response
    {
        return $this->put("/admin/nhan-su/{$id}/trang-thai", [
            'status' => $status,
            'mo_tai_khoan' => $moTaiKhoan,
        ]);
    }

    // ---------------------------------------------------------------------
    // Phân quyền theo chức năng
    // ---------------------------------------------------------------------
    //
    // Quyền gán THẲNG cho từng tài khoản (bảng `user_permissions`), khác
    // `roles()` bên dưới vốn là bốn vai trò dùng chung cả nền tảng. Vai trò trả
    // lời "anh là loại người nào", quyền trả lời "anh bấm được nút nào".
    //
    // API còn nhóm quyền (`/admin/nhom-quyen`) làm bộ mẫu, nhưng trang quản trị
    // chưa dùng tới nên không khai ở đây.

    /**
     * Cây quyền của phần mềm — mọi việc có thể tick, xếp theo nhóm hiển thị.
     *
     * Danh mục nằm trong mã nguồn Go (`internal/domain/quyen.go`) chứ không phải
     * database, nên trang này không khai lại: thêm quyền bên đó là bảng tick hiện ngay.
     */
    public function danhMucQuyen(): Response
    {
        return $this->get('/admin/nhom-quyen/danh-muc');
    }

    /** Quyền đã tick cho một tài khoản, kèm cờ toàn quyền. */
    public function quyenCuaNguoi(int $userID): Response
    {
        return $this->get("/admin/users/{$userID}/quyen");
    }

    /**
     * Thay TOÀN BỘ quyền của một tài khoản.
     *
     * Mảng rỗng = thu hết quyền: người đó vẫn đăng nhập được, chỉ là không mở
     * được trang nào. API từ chối lượt tự đặt cho chính mình.
     */
    public function datQuyenChoNguoi(int $userID, array $quyen): Response
    {
        return $this->put("/admin/users/{$userID}/quyen", [
            'quyen' => array_values(array_map('strval', $quyen)),
        ]);
    }

    /** Xoá (mềm) một hồ sơ nhân viên. Tài khoản đăng nhập của người đó giữ nguyên. */
    public function xoaNhanSu(int $id): Response
    {
        return $this->delete("/admin/nhan-su/{$id}");
    }

    // ---------- Chi nhánh (điểm bán của cửa hàng) ----------
    //
    // "Chi nhánh" là bảng `shops` bên API: các điểm bán NẰM TRONG cửa hàng của
    // mình, không phải khách hàng của nhà cung cấp. Số lượng bị hạn mức
    // `max_shops` của hợp đồng chặn — mở quá thì API trả 409 kèm câu giải thích.

    /** Danh sách chi nhánh. $onlyActive = true để bỏ chi nhánh đã đóng. */
    public function chiNhanh(bool $onlyActive = false): Response
    {
        return $this->get('/admin/chi-nhanh', $onlyActive ? ['active' => 'true'] : []);
    }

    /**
     * Một chi nhánh theo id.
     *
     * Dùng cho công tắc trạng thái trên bảng: API chỉ có PUT TOÀN PHẦN, không có
     * đường đổi riêng một cột, nên phải đọc lại bản ghi rồi gửi nguyên trạng và
     * chỉ lật cờ `is_active`. Đọc lại thay vì lấy giá trị đang hiện trên trang:
     * trang có thể đã cũ, và ghi đè tên/địa chỉ bằng bản cũ chỉ vì ai đó gạt một
     * cái công tắc là mất dữ liệu người khác vừa sửa.
     */
    public function chiNhanhChiTiet(int $id): Response
    {
        return $this->get("/admin/chi-nhanh/{$id}");
    }

    /** Mở thêm một chi nhánh. Bỏ trống `code` thì API tự đặt mã. */
    public function taoChiNhanh(array $data): Response
    {
        return $this->post('/admin/chi-nhanh', $data);
    }

    /** Sửa thông tin một chi nhánh. */
    public function suaChiNhanh(int $id, array $data): Response
    {
        return $this->put("/admin/chi-nhanh/{$id}", $data);
    }

    /** Xoá (mềm) một chi nhánh. */
    public function xoaChiNhanh(int $id): Response
    {
        return $this->delete("/admin/chi-nhanh/{$id}");
    }

    // ---------- Hoá đơn điện tử của chi nhánh ----------
    //
    // Tài khoản cổng HĐĐT gắn với TỪNG chi nhánh (chuỗi nhiều pháp nhân thì mỗi
    // điểm bán một mã số thuế). API không bao giờ trả mật khẩu hay token ra.

    /** Kết nối HĐĐT của một chi nhánh, kèm danh sách ký hiệu. 404 = chưa nối. */
    public function etax(int $shopID): Response
    {
        return $this->get("/admin/chi-nhanh/{$shopID}/etax");
    }

    /** Khai tài khoản cổng HĐĐT. API đăng nhập thật trước khi lưu. */
    public function ketNoiEtax(int $shopID, array $data): Response
    {
        return $this->post("/admin/chi-nhanh/{$shopID}/etax", $data);
    }

    /** Đổi ký hiệu phát hành và hai công tắc tự phát hành / tự in. */
    public function luuCaiDatEtax(int $shopID, array $data): Response
    {
        return $this->put("/admin/chi-nhanh/{$shopID}/etax", $data);
    }

    /** Kéo lại danh sách ký hiệu từ nhà cung cấp. */
    public function dongBoMauEtax(int $shopID): Response
    {
        return $this->post("/admin/chi-nhanh/{$shopID}/etax/sync", []);
    }

    /** Ngắt kết nối — xoá hẳn tài khoản khỏi sổ. */
    public function ngatEtax(int $shopID): Response
    {
        return $this->delete("/admin/chi-nhanh/{$shopID}/etax");
    }

    /** Hoá đơn điện tử đã phát hành của một đơn. 404 = chưa phát hành. */
    public function hoaDonCuaDon(int $orderID): Response
    {
        return $this->get("/admin/orders/{$orderID}/etax");
    }

    /** Phát hành hoá đơn cho một đơn hàng. */
    public function phatHanhHoaDon(int $orderID): Response
    {
        return $this->post("/admin/orders/{$orderID}/etax", []);
    }

    /** Ký tờ nháp rồi gửi cơ quan thuế. Chỉ chạy với chữ ký số mềm. */
    public function kyHoaDon(int $orderID): Response
    {
        return $this->post("/admin/orders/{$orderID}/etax/sign", []);
    }

    /** Hỏi lại cổng xem cơ quan thuế đã cấp mã chưa. */
    public function dongBoHoaDon(int $orderID): Response
    {
        return $this->post("/admin/orders/{$orderID}/etax/sync", []);
    }

    /** Xuất một tờ THAY CHO tờ hiện tại, dựng lại từ đơn hàng hôm nay. */
    public function thayTheHoaDon(int $orderID, array $data): Response
    {
        return $this->post("/admin/orders/{$orderID}/etax/replace", $data);
    }

    /** Điều chỉnh tờ hiện tại; không gửi `dong` = điều chỉnh về 0. */
    public function dieuChinhHoaDon(int $orderID, array $data): Response
    {
        return $this->post("/admin/orders/{$orderID}/etax/adjust", $data);
    }

    /** Bản PDF của hoá đơn. Trả về tệp, không phải JSON. */
    public function pdfHoaDon(int $orderID, bool $chuyenDoi = false): Response
    {
        return $this->get("/admin/orders/{$orderID}/etax/pdf", $chuyenDoi ? ['chuyen_doi' => '1'] : []);
    }

    /** Bản XML gốc đã ký của hoá đơn. */
    public function xmlHoaDon(int $orderID): Response
    {
        return $this->get("/admin/orders/{$orderID}/etax/xml");
    }

    /** Tra tên và địa chỉ đăng ký của một mã số thuế. */
    public function traCuuMST(string $mst): Response
    {
        return $this->get('/admin/etax/tra-cuu-mst', ['mst' => $mst]);
    }

    // ---------- Quy tắc đánh số chứng từ ----------

    /** Danh mục loại đánh số được + mọi quy tắc đã lưu của cửa hàng. */
    public function quyTacMa(): Response
    {
        return $this->get('/admin/quy-tac-ma');
    }

    /**
     * Lưu bảng quy tắc của MỘT chi nhánh.
     *
     * Gửi trạng thái cuối cùng: loại có trong $quyTac là bật, loại vắng mặt là
     * tắt. API tự xếp dòng nào thuộc chi nhánh, dòng nào dùng chung toàn cửa hàng.
     */
    public function luuQuyTacMa(int $shopID, array $quyTac): Response
    {
        return $this->put('/admin/quy-tac-ma', [
            'shop_id' => $shopID,
            'quy_tac' => array_values($quyTac),
        ]);
    }

    /** Danh sách vai trò kèm số tài khoản đang mang vai trò đó. */
    public function roles(): Response
    {
        return $this->get('/admin/roles');
    }

    /** Sửa vai trò — chỉ tên hiển thị và mô tả, mã vai trò không đổi được. */
    public function updateRole(int $id, array $data): Response
    {
        return $this->put("/admin/roles/{$id}", $data);
    }

    // ---------- Reports ----------
    //
    // Bốn báo cáo nhận CÙNG một bộ tham số: from, to (YYYY-MM-DD), group_by
    // (day|week|month); riêng sản phẩm và khách hàng nhận thêm limit, sản phẩm
    // nhận thêm sort. Không kiểm tra gì ở đây — API tự chuẩn hoá tham số sai.

    /** Báo cáo doanh thu: chuỗi theo mốc + tổng kỳ này/kỳ trước + cơ cấu thanh toán. */
    public function reportRevenue(array $query = []): Response
    {
        return $this->get('/admin/reports/revenue', $query);
    }

    /** Báo cáo đơn hàng: trạng thái, tỷ lệ huỷ, khung giờ, thứ, khu vực, kênh bán. */
    public function reportOrders(array $query = []): Response
    {
        return $this->get('/admin/reports/orders', $query);
    }

    /** Báo cáo sản phẩm: xếp hạng bán chạy/lợi nhuận + cơ cấu danh mục, thương hiệu, size. */
    public function reportProducts(array $query = []): Response
    {
        return $this->get('/admin/reports/products', $query);
    }

    /** Báo cáo khách hàng: khách mới / quay lại, hội viên / vãng lai, xếp hạng chi tiêu. */
    public function reportCustomers(array $query = []): Response
    {
        return $this->get('/admin/reports/customers', $query);
    }

    // ---------- Gói dịch vụ của cửa hàng ----------

    /**
     * Hợp đồng phần mềm của CHÍNH cửa hàng này, kèm bảng giá đang bán.
     *
     * Đây là đường duy nhất Shop Admin đọc được sổ nền tảng, và nó chỉ đọc: gia
     * hạn vẫn là việc của nhà cung cấp (tiền phải vào sổ thu trước), nên trang
     * gói dịch vụ chỉ mời liên hệ chứ không có nút tự đẩy hạn.
     *
     * Trả 404 khi máy chủ API chưa nối được sổ nền tảng — nhóm route bên đó
     * không được đăng ký. Nơi gọi phải nói đúng câu đó ra thay vì hiện trang
     * trống; xem GoiDichVuController.
     */
    public function goiDichVu(): Response
    {
        return $this->get('/admin/goi-dich-vu');
    }

    /**
     * Đặt đơn gia hạn và lấy link thanh toán.
     *
     * KHÔNG gửi số tiền: giá do API tra từ bảng giá rồi chốt vào đơn. Gửi số tiền
     * từ đây nghĩa là trang web tự khai mình phải trả bao nhiêu.
     */
    public function datGiaHan(int $planId, int $soThang): Response
    {
        return $this->post('/admin/goi-dich-vu/dat', [
            'plan_id' => $planId,
            'so_thang' => $soThang,
        ]);
    }

    /**
     * Trạng thái một đơn gia hạn.
     *
     * Đường này KHÔNG chỉ đọc: đơn còn chờ thì API hỏi thẳng cổng thanh toán xem
     * tiền vào chưa, và chốt đơn ngay tại đó nếu đã vào. Nhờ vậy khách vẫn được
     * gia hạn kể cả khi webhook không tới được máy chủ (máy chạy ở localhost).
     */
    public function donGiaHan(int $id): Response
    {
        return $this->get("/admin/goi-dich-vu/don/{$id}");
    }

    /** Khoá cache của bảng giá trị cấu hình (xem settingValues). */
    /**
     * Tiền tố khoá cache cài đặt. Khoá THẬT còn kèm mã cửa hàng — xem khoaCacheSettings.
     *
     * Một khoá cố định dùng chung cho mọi người là đúng cho tới ngày một bản chạy
     * phục vụ hai cửa hàng: lúc đó người vào trước nạp cache, và người của cửa
     * hàng kia đọc nhầm cài đặt ấy suốt 5 phút.
     */
    public const SETTINGS_CACHE_KEY = 'admin.settings.values';

    /**
     * Khoá cache cài đặt của CỬA HÀNG đang đăng nhập.
     *
     * `api.tenant` do lượt đăng nhập cất vào (xem AuthController) và sống tới lúc
     * đăng xuất. Chưa đăng nhập thì không có gì để phân biệt — dùng khoá trần,
     * đằng nào lượt gọi cũng không có token và sẽ hỏng.
     */
    public static function khoaCacheSettings(): string
    {
        $id = (int) session('api.tenant.id', 0);

        return $id > 0 ? self::SETTINGS_CACHE_KEY.'.'.$id : self::SETTINGS_CACHE_KEY;
    }

    /**
     * Giá trị cấu hình dạng map key → value, cache 5 phút.
     *
     * Dành cho các trang chỉ CẦN ĐỌC một khoá (trang Tồn kho lấy ngưỡng sắp hết
     * chẳng hạn) — không đáng thêm một lượt gọi API cho mỗi lần mở trang. Trang
     * Cài đặt thì gọi settings() trực tiếp để luôn thấy dữ liệu mới nhất, và xoá
     * cache này ngay sau khi lưu.
     *
     * Không bao giờ ném lỗi: API hỏng thì trả mảng rỗng, nơi gọi tự dùng mặc định.
     */
    public function settingValues(): array
    {
        try {
            $values = Cache::remember(self::khoaCacheSettings(), 300, function () {
                $res = $this->settings();

                // Trả null khi hỏng: Cache::remember không giữ null nên lần sau thử lại.
                return $res->successful() ? ($res->json('data.values') ?: null) : null;
            });
        } catch (\Throwable $e) {
            Log::warning('Load settings cache failed', ['msg' => $e->getMessage()]);

            return [];
        }

        return is_array($values) ? $values : [];
    }

    /**
     * Đọc MỘT khoá cấu hình dạng chuỗi; thiếu hoặc rỗng thì trả $default.
     *
     * Rỗng cũng rơi về mặc định vì các khoá dùng ở đây (logo, favicon) hiểu "bỏ
     * trống" là "dùng ảnh mặc định của giao diện", chứ không phải "không hiện gì".
     */
    public function settingString(string $key, string $default = ''): string
    {
        $value = trim((string) ($this->settingValues()[$key] ?? ''));

        return $value !== '' ? $value : $default;
    }

    /**
     * Đọc MỘT khoá cấu hình dạng công tắc.
     *
     * API lưu bool thành chuỗi "1"/"0" (bảng settings là key-value thuần), nên chỉ
     * "1" mới là bật. Khoá chưa có dòng, hay API hỏng, đều rơi về $default — mấy
     * công tắc này đổi hình dạng cả màn hình, đoán bừa là hại hơn.
     */
    public function settingBool(string $key, bool $default = false): bool
    {
        $raw = $this->settingValues()[$key] ?? null;

        return $raw === null ? $default : (string) $raw === '1';
    }

    /**
     * Đọc MỘT khoá cấu hình dạng số nguyên dương; thiếu hoặc hỏng thì trả $default.
     * Bọc sẵn ở đây để mọi nơi gọi không phải lặp lại đoạn ép kiểu và kiểm tra.
     */
    public function settingInt(string $key, int $default): int
    {
        $raw = $this->settingValues()[$key] ?? null;
        if ($raw === null || ! is_numeric($raw)) {
            return $default;
        }

        $value = (int) $raw;

        return $value >= 0 ? $value : $default;
    }

    /**
     * Thông tin cửa hàng dùng cho các trang IN (hoá đơn, tem giao hàng, phiếu kiểm kê).
     *
     * Ba trang đó trước đây ghi cứng tên, địa chỉ và hotline của một cửa hàng cụ
     * thể, nên giấy in ra luôn mang thông tin cửa hàng đó dù người dùng đã khai
     * khác trong Cài đặt. Gom về một chỗ để ba trang không lệch nhau, và để khi
     * thêm trang in mới thì không phải chép lại đoạn đọc cấu hình.
     *
     * `contact` ghép hotline với website thành một dòng, bỏ phần nào chưa khai —
     * in ra "Hotline:  — " giữa tờ hoá đơn trông như lỗi hệ thống.
     */
    public function shopInfo(): array
    {
        $phone = $this->settingString('contact_phone');
        $website = $this->settingString('store_website');

        $contact = array_filter([
            $phone !== '' ? 'Hotline: '.$phone : '',
            $website,
        ]);

        return [
            'name' => $this->settingString('site_name', config('app.name')),
            'address' => $this->settingString('store_address'),
            'phone' => $phone,
            'contact' => implode(' — ', $contact),
        ];
    }
}
