<?php

namespace App\Services;

use App\Services\HanSuDung;
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
        // Không có trong phiên thì KHÔNG gửi gì, và API tự rơi về chi nhánh bán
        // online. Đó là đường đi của cửa hàng một chi nhánh: màn hình không có ô
        // chọn nào cả.
        if ($shopID = session(self::KHOA_CHI_NHANH)) {
            $req = $req->withHeaders(['X-Chi-Nhanh' => (string) $shopID]);
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

        if ($this->refreshToken()) {
            $res = $this->dispatch($method, $uri, $payload);
            // Lượt thử lại cũng phải đi qua bộ dò cờ khoá: hợp đồng hết hạn giữa
            // lúc token cũ vừa chết thì tín hiệu 403 nằm ở đúng lượt gọi này.
            $this->ghiNhanKhoaCuaHang($res);

            return $res;
        }

        // Làm mới không được nghĩa là phiên này hết đường cứu: token đã hỏng và
        // refresh token cũng vậy. Xoá session để lượt vào trang tiếp theo bị
        // EnsureAdminAuthenticated đẩy về màn hình đăng nhập.
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
     * Trả về true nếu làm mới thành công. Không đính access token cũ để tránh vòng lặp.
     */
    protected function refreshToken(): bool
    {
        $refresh = session('api.refresh_token');
        if (! $refresh) {
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
    public function categories(bool $all = true): Response
    {
        return $this->get('/categories', $all ? ['all' => 'true'] : []);
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

    /**
     * Doanh thu + số đơn theo từng ngày trong `days` ngày gần nhất.
     *
     * Kỳ LUÔN kết thúc ở hôm nay nên không diễn đạt được "hôm qua" hay một khoảng
     * bất kỳ. Trang Tổng quan vì thế đã chuyển sang reportRevenue() (nhận from/to);
     * giữ lại đây vì endpoint bên API vẫn còn và vẫn đúng cho nhu cầu "N ngày gần
     * nhất" — cần khoảng tuỳ ý thì dùng reportRevenue().
     */
    public function orderRevenue(int $days = 30): Response
    {
        return $this->get('/admin/orders/revenue', ['days' => $days]);
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

    // ---------- Suppliers (nhà cung cấp) ----------

    /**
     * Danh sách nhà cung cấp. $onlyActive = true để chỉ lấy bên đang hợp tác
     * (dropdown lập phiếu dùng cái này). Mỗi phần tử kèm purchase_count.
     */
    public function suppliers(string $keyword = '', bool $onlyActive = false): Response
    {
        return $this->get('/admin/suppliers', array_filter([
            'keyword' => $keyword,
            'active' => $onlyActive ? 'true' : null,
        ]));
    }

    public function supplier(int $id): Response
    {
        return $this->get("/admin/suppliers/{$id}");
    }

    /** Thêm nhà cung cấp; bỏ trống `code` thì API tự sinh mã NCC001, NCC002… */
    public function createSupplier(array $payload): Response
    {
        return $this->post('/admin/suppliers', $payload);
    }

    public function updateSupplier(int $id, array $payload): Response
    {
        return $this->put("/admin/suppliers/{$id}", $payload);
    }

    public function deleteSupplier(int $id): Response
    {
        return $this->delete("/admin/suppliers/{$id}");
    }

    // ---------- Purchase returns (trả hàng nhập) ----------

    /**
     * Danh sách phiếu trả hàng lại nhà cung cấp.
     * $query hỗ trợ: keyword, status, refund_status, reason, supplier_id,
     * from_date, to_date, sort, page, page_size. `status` nhận nhiều giá trị
     * ngăn cách bởi dấu phẩy.
     */
    public function purchaseReturns(array $query = []): Response
    {
        return $this->get('/admin/purchase-returns', $query);
    }

    public function purchaseReturnStats(): Response
    {
        return $this->get('/admin/purchase-returns/stats');
    }

    /** Chi tiết phiếu trả: dòng hàng + lịch sử + next_statuses + can_edit/can_refund. */
    public function purchaseReturn(int $id): Response
    {
        return $this->get("/admin/purchase-returns/{$id}");
    }

    /** Các dòng của phiếu đặt còn trả lại được (đã nhận trừ phần đã trả). */
    public function purchaseReturnable(int $purchaseOrderId): Response
    {
        return $this->get("/admin/purchase-returns/returnable/{$purchaseOrderId}");
    }

    public function createPurchaseReturn(array $payload): Response
    {
        return $this->post('/admin/purchase-returns', $payload);
    }

    public function updatePurchaseReturn(int $id, array $payload): Response
    {
        return $this->put("/admin/purchase-returns/{$id}", $payload);
    }

    /** Chuyển trạng thái: returned (trừ kho) | refunded | cancelled (kèm lý do). */
    public function updatePurchaseReturnStatus(int $id, string $status, string $note = ''): Response
    {
        return $this->put("/admin/purchase-returns/{$id}/status", [
            'status' => $status,
            'note' => $note,
        ]);
    }

    /** Ghi nhận tiền NCC đã hoàn — số LUỸ KẾ, không phải số vừa hoàn thêm. */
    public function updatePurchaseReturnRefund(int $id, float $amount): Response
    {
        return $this->put("/admin/purchase-returns/{$id}/refund", ['refund_amount' => $amount]);
    }

    public function deletePurchaseReturn(int $id): Response
    {
        return $this->delete("/admin/purchase-returns/{$id}");
    }

    // ---------- Goods receipts (nhập hàng) ----------

    /**
     * Danh sách ĐỢT nhập hàng (mỗi lần nhận hàng là một đợt).
     * $query hỗ trợ: keyword, supplier_id, from_date, to_date, sort, page, page_size.
     *
     * API này CHỈ ĐỌC — muốn nhập hàng thì gọi receivePurchase(): đó là đường duy
     * nhất được cộng tồn kho.
     */
    public function receipts(array $query = []): Response
    {
        return $this->get('/admin/receipts', $query);
    }

    // ---------- Yêu cầu khách gửi từ storefront ----------

    /**
     * Danh sách yêu cầu khách để lại ở form Liên hệ / form Thu mua.
     * $query hỗ trợ: keyword, type, status, from, to, page, page_size.
     */
    public function contactRequests(array $query = []): Response
    {
        return $this->get('/admin/contact-requests', $query);
    }

    /** Đếm yêu cầu theo trạng thái (moi / dang-xu-ly / da-xong). */
    public function contactStats(): Response
    {
        return $this->get('/admin/contact-requests/stats');
    }

    /** Đổi trạng thái xử lý một yêu cầu, kèm ghi chú nội bộ. */
    public function updateContactStatus(int $id, array $data): Response
    {
        return $this->put('/admin/contact-requests/'.$id.'/status', $data);
    }

    /** Xoá mềm một yêu cầu. */
    public function deleteContactRequest(int $id): Response
    {
        return $this->delete('/admin/contact-requests/'.$id);
    }

    /** Danh sách email đăng ký nhận tin ở chân trang storefront. */
    public function newsletterSubscribers(array $query = []): Response
    {
        return $this->get('/admin/newsletter', $query);
    }

    /** Gỡ một email khỏi danh sách nhận tin (tắt chứ không xoá dòng). */
    public function unsubscribeNewsletter(int $id): Response
    {
        return $this->put('/admin/newsletter/'.$id.'/unsubscribe', []);
    }

    /** Tổng đã nhập + phần nhập hôm nay + số phiếu còn chờ hàng về. */
    public function receiptStats(): Response
    {
        return $this->get('/admin/receipts/stats');
    }

    /** Chi tiết một đợt nhập theo mã đợt, VD: PO202607300001-N2. */
    public function receipt(string $code): Response
    {
        return $this->get('/admin/receipts/'.rawurlencode($code));
    }

    // ---------- Purchases (đặt hàng nhập) ----------

    /**
     * Danh sách phiếu đặt hàng nhập (lọc/sắp xếp/phân trang phía server).
     * $query hỗ trợ: keyword, status, payment_status, supplier_id, from_date,
     * to_date, sort, page, page_size.
     */
    public function purchases(array $query = []): Response
    {
        return $this->get('/admin/purchases', $query);
    }

    /** Thống kê phiếu theo trạng thái + hàng đang trên đường + công nợ. */
    public function purchaseStats(): Response
    {
        return $this->get('/admin/purchases/stats');
    }

    /** Chi tiết phiếu (kèm dòng hàng, số đã nhận, lịch sử, trạng thái kế tiếp). */
    public function purchase(int $id): Response
    {
        return $this->get("/admin/purchases/{$id}");
    }

    /**
     * Tìm BIẾN THỂ để đưa vào phiếu — kèm tồn kho hiện tại và giá vốn đang khai
     * (màn hình lập phiếu dùng làm giá nhập gợi ý). Hàng sắp hết xếp lên đầu.
     */
    public function purchaseVariants(string $keyword = '', int $limit = 20): Response
    {
        return $this->get('/admin/purchases/variants', ['keyword' => $keyword, 'limit' => $limit]);
    }

    /** Lập phiếu đặt hàng nhập (payload theo PurchaseCreateRequest). */
    public function createPurchase(array $payload): Response
    {
        return $this->post('/admin/purchases', $payload);
    }

    /** Sửa phiếu + thay toàn bộ danh sách hàng (chỉ khi chưa nhận đợt nào). */
    public function updatePurchase(int $id, array $payload): Response
    {
        return $this->put("/admin/purchases/{$id}", $payload);
    }

    /** Chuyển trạng thái phiếu (ordered | cancelled); $note là lý do khi huỷ. */
    public function updatePurchaseStatus(int $id, string $status, string $note = ''): Response
    {
        return $this->put("/admin/purchases/{$id}/status", [
            'status' => $status,
            'note' => $note,
        ]);
    }

    /** Ghi nhận MỘT đợt hàng về kho (payload theo PurchaseReceiveRequest). */
    public function receivePurchase(int $id, array $payload): Response
    {
        return $this->post("/admin/purchases/{$id}/receive", $payload);
    }

    /** Cập nhật số tiền ĐÃ TRẢ nhà cung cấp (số luỹ kế, không phải cộng thêm). */
    public function updatePurchasePayment(int $id, float $paidAmount): Response
    {
        return $this->put("/admin/purchases/{$id}/payment", ['paid_amount' => $paidAmount]);
    }

    /** Xoá phiếu NHÁP (phiếu đã gửi nhà cung cấp phải huỷ, không xoá). */
    public function deletePurchase(int $id): Response
    {
        return $this->delete("/admin/purchases/{$id}");
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
    public const SETTINGS_CACHE_KEY = 'admin.settings.values';

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
            $values = Cache::remember(self::SETTINGS_CACHE_KEY, 300, function () {
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
            $phone !== '' ? 'Hotline: ' . $phone : '',
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
