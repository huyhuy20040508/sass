<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CuaVao;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke test toàn bộ trang quản trị.
 *
 * App này KHÔNG nối DB — mọi dữ liệu đi qua Go API — nên test cần API chạy thật
 * ở `API_BASE_URL`. Không có API thì test tự bỏ qua (skipped) chứ không báo đỏ.
 *
 * Tài khoản dùng để đăng nhập lấy từ biến môi trường, mặc định là tài khoản
 * kiểm thử cục bộ:
 *   SMOKE_ADMIN_EMAIL=smoke.test@local.invalid
 *   SMOKE_ADMIN_PASSWORD=SmokeTest@123
 *
 * Tạo lại tài khoản mặc định trên MySQL cục bộ (KHÔNG chạy trên prod):
 *   INSERT INTO users (role_id, full_name, email, phone, password_hash, status,
 *                      email_verified_at, created_at, updated_at)
 *   VALUES (2, 'Smoke Test Admin', 'smoke.test@local.invalid', '0900000777',
 *           '$2y$10$XhAfMWGzW5W88/VvRUGSJuib4rJ4gOGClkPcy5UEkDRTMWqN2U3RS',
 *           'active', NOW(3), NOW(3), NOW(3));
 *
 * Test chỉ gọi các route GET (đọc dữ liệu). KHÔNG đụng POST/PUT/DELETE để
 * không làm bẩn dữ liệu của máy đang chạy.
 */
class AdminSmokeTest extends TestCase
{
    /**
     * Bài kiểm DUY NHẤT được gọi API thật — đó là toàn bộ lý do nó tồn tại.
     *
     * Chốt preventStrayRequests trong Tests\TestCase chặn mọi lượt gọi không giả
     * lập; để nguyên thì lượt đăng nhập dưới đây ném lỗi, rơi vào catch, và bài
     * kiểm khói lặng lẽ "skipped" mãi mãi — đúng kiểu hỏng mà nó sinh ra để bắt.
     */
    protected bool $choPhepGoiApiThat = true;

    /** Session giả lập sau khi đăng nhập (dùng lại cho mọi test). */
    protected static ?array $adminSession = null;

    /** Lý do bỏ qua (API không chạy / sai mật khẩu). */
    protected static ?string $skipReason = null;

    /** Id thật lấy từ API để dựng các route có tham số. */
    protected static array $ids = [];

    /**
     * Route redirect theo thiết kế (không phải trang nội dung) => đích mong đợi.
     * Kiểm riêng trong test_route_trung_chuyen_nhay_dung_cho.
     */
    protected const REDIRECT_ROUTES = [
        'admin.' => '/admin/dashboard',
        // Trang chủ đang đỗ trong đợt chuyển sang khu v2: chưa dựng lại thì dồn
        // về màn v2 đầu tiên thay vì mở bản cũ.
        'admin.dashboard' => '/admin/suppliers',
        'admin.reports.index' => '/admin/reports/revenue',
        'admin.settings.index' => '/admin/settings/general',
        // Thông số chung là một cụm tab; đường trần nhảy vào tab đầu.
        'admin.thong-so-chung.index' => '/admin/parameters/numbering-rules',
        'thu-ngan.' => '/cashier/sales',
        // Bốn đường /admin cũ của khu quầy — máy ở quầy còn đặt sẵn chúng làm
        // trang chủ trình duyệt, nên chúng phải nhảy đúng sang URL mới.
        'admin.cu.ban-tai-quay' => '/cashier/sales',
        'admin.cu.ban-tai-quay.phieu' => '/cashier/sales/{id}/receipt',
        'admin.cu.ca-lam-viec' => '/cashier/shifts',
        'admin.cu.ca-lam-viec.show' => '/cashier/shifts/{id}',
    ];

    /** Id thật để dựng đường chuyển hướng có tham số: đường-lấy#tên-cột. */
    protected const ID_TRUNG_CHUYEN = [
        'admin.cu.ban-tai-quay.phieu' => '/admin/orders#id',
        'admin.cu.ca-lam-viec.show' => '/admin/ca-lam-viec#id',
    ];

    /**
     * Query bắt buộc của vài route: in tem / in phiếu hàng loạt đọc danh sách id
     * từ query, gọi trần không có id là 404 đúng thiết kế.
     */
    protected const REQUIRED_QUERY = [
        'admin.orders.labelBatch' => 'orders#id',
        'admin.orders.printBatch' => 'orders#id',
    ];

    /**
     * Đường JSON cho màn hình gọi bằng fetch — KHÔNG phải trang.
     *
     * Chúng đòi tham số trên query và trả 4xx khi thiếu (đúng thiết kế), nên gọi
     * trần trong lượt quét trang là bắt lỗi một thứ không hỏng.
     */
    /**
     * Query bắt buộc của vài trang CHI TIẾT: khoá#đường-lấy#tên-cột.
     *
     * Sổ kho nhìn nhiều kho cùng lúc nên nó bắt nói rõ xem kho nào; thiếu là 422
     * đúng thiết kế. Gọi trần rồi kêu trang hỏng là bắt nhầm, mà bỏ trang khỏi
     * lượt quét thì mất luôn chỗ canh.
     */
    protected const QUERY_CHI_TIET = [
        'admin.ton-kho-chi-nhanh.history' => 'shop_id#/admin/chi-nhanh#id',
    ];

    protected const KHONG_PHAI_TRANG = [
        'thu-ngan.ban-hang.scan',
        // Hai đường của màn Điều chỉnh tồn kho: ô tìm hàng và ô chọn nhóm gọi
        // bằng fetch, thiếu tham số thì trả 422 kèm câu giải thích — đúng thiết
        // kế, không phải trang hỏng.
        'admin.dieu-chinh-ton-kho.hangAm',
        'admin.dieu-chinh-ton-kho.matHangTheoNhom',
    ];

    // ---------------------------------------------------------------- helpers

    /** Đăng nhập thẳng vào API rồi trả về mảng session mà middleware cần. */
    protected function adminSession(): array
    {
        if (static::$adminSession !== null) {
            return static::$adminSession;
        }

        if (static::$skipReason !== null) {
            $this->markTestSkipped(static::$skipReason);
        }

        // Shop Admin đăng nhập bằng MÃ CỬA HÀNG + tên đăng nhập, không phải
        // email: /auth/login là đường của khách mua sắm và nó đòi biết cửa hàng
        // từ tên miền, nên gọi vào đó luôn nhận "Chưa xác định được cửa hàng".
        // Bài này vì thế đã im lặng bỏ qua suốt từ lúc API tách hai đường — mà
        // bỏ qua thì trông y hệt "không có gì hỏng".
        $shop = (string) env('SMOKE_SHOP_CODE', '');
        $user = (string) env('SMOKE_ADMIN_USERNAME', '');
        $password = (string) env('SMOKE_ADMIN_PASSWORD', '');

        if ($shop === '' || $user === '' || $password === '') {
            static::$skipReason = 'Chưa khai SMOKE_SHOP_CODE / SMOKE_ADMIN_USERNAME / '
                .'SMOKE_ADMIN_PASSWORD trong .env nên không đăng nhập được để chạy bài khói.';
            $this->markTestSkipped(static::$skipReason);
        }

        try {
            $res = Http::baseUrl(config('api.base_url'))
                ->timeout(10)->acceptJson()->asJson()
                ->post('/auth/shop-login', [
                    'shop_code' => $shop,
                    'username' => $user,
                    'password' => $password,
                ]);
        } catch (\Throwable $e) {
            static::$skipReason = 'Không gọi được API ('.config('api.base_url').'): '.$e->getMessage();
            $this->markTestSkipped(static::$skipReason);
        }

        if (! $res->successful()) {
            static::$skipReason = 'API từ chối đăng nhập tài khoản kiểm thử ('.$shop.'/'.$user.'): '.$res->body();
            $this->markTestSkipped(static::$skipReason);
        }

        // KHÔNG cần nhét sẵn bản chụp "cửa vào": CuaVao tự suy ra từ
        // `api.user.access_areas` khi phiên chưa có bản chụp nào.
        static::$adminSession = [
            'api.access_token' => $res->json('data.access_token'),
            'api.refresh_token' => $res->json('data.refresh_token'),
            'api.user' => $res->json('data.user'),
        ];

        // GHIM chi nhánh vào phiên ngay từ đây — xem chiNhanhLamViec().
        if ($cn = $this->chiNhanhLamViec()) {
            static::$adminSession[ApiClient::KHOA_CHI_NHANH] = $cn;
        }

        return static::$adminSession;
    }

    /**
     * Chi nhánh mà CẢ BÀI KIỂM đứng — ghim một lần, không để trôi.
     *
     * Không ghim thì hai nửa của bài đứng ở hai chỗ khác nhau: id mẫu lấy từ API
     * đi "trần" nên thấy chứng từ của MỌI chi nhánh, còn trang thì chạy dưới chi
     * nhánh mà thanh trên cùng tự chọn giúp (chi nhánh đang mở đầu tiên). Ghép
     * hai thứ đó ra một URL không tồn tại với ai cả — trang đỏ vì một cảnh không
     * có thật, mà cửa hàng một chi nhánh lại không bao giờ thấy nó.
     *
     * Trong các chi nhánh ĐANG MỞ, chọn chi nhánh CÓ CHỨNG TỪ. Chọn bừa cái đầu
     * tiên (đường ChiNhanhDangLam đi cho phiên chưa chọn gì) thì trên máy có
     * nhiều chi nhánh, bài kiểm dễ đứng đúng vào kho rỗng: mọi trang chi tiết bị
     * bỏ qua vì "chưa có dữ liệu" và bài vẫn xanh — xanh mà không quét gì cả.
     */
    protected function chiNhanhLamViec(): ?int
    {
        if (array_key_exists('chi-nhanh-lam-viec', static::$ids)) {
            return static::$ids['chi-nhanh-lam-viec'];
        }

        // Gọi thẳng, KHÔNG qua apiList: apiList lại hỏi ngược chi nhánh này.
        $goi = function (string $uri, array $query = [], ?int $chiNhanh = null) {
            $req = Http::baseUrl(config('api.base_url'))->timeout(10)->acceptJson()
                ->withToken(static::$adminSession['api.access_token']);

            return ($chiNhanh ? $req->withHeaders(['X-Chi-Nhanh' => (string) $chiNhanh]) : $req)
                ->get($uri, $query);
        };

        try {
            $res = $goi('/admin/chi-nhanh', ['active' => 'true']);
            $ds = $res->successful() ? (array) ($res->json('data') ?: []) : [];

            $dauTien = null;
            foreach ($ds as $cn) {
                $id = (int) data_get($cn, 'id');
                if ($id <= 0) {
                    continue;
                }
                $dauTien ??= $id;

                // Đơn hàng và phiếu mua — hai sổ chính; kho nào có một trong hai
                // là kho đang được dùng thật.
                foreach (['/admin/orders', '/admin/phieu-mua-hang'] as $so) {
                    $r = $goi($so, ['limit' => 1], $id);
                    if ($r->successful() && ($r->json('data') ?: []) !== []) {
                        return static::$ids['chi-nhanh-lam-viec'] = $id;
                    }
                }
            }

            return static::$ids['chi-nhanh-lam-viec'] = $dauTien;
        } catch (\Throwable $e) {
            return static::$ids['chi-nhanh-lam-viec'] = null;
        }
    }

    /** Lượt gọi API bằng token quản trị, ĐỨNG ĐÚNG chi nhánh của bài kiểm. */
    protected function goiApi(string $uri, array $query = [])
    {
        $req = Http::baseUrl(config('api.base_url'))->timeout(10)->acceptJson()
            ->withToken($this->adminSession()['api.access_token']);

        if ($cn = $this->chiNhanhLamViec()) {
            $req = $req->withHeaders(['X-Chi-Nhanh' => (string) $cn]);
        }

        return $req->get($uri, $query);
    }

    /** Gọi API bằng token quản trị, trả về mảng data (rỗng nếu lỗi). */
    protected function apiList(string $uri, array $query = ['limit' => 1]): array
    {
        try {
            $res = $this->goiApi($uri, $query);
        } catch (\Throwable $e) {
            return [];
        }

        return $res->successful() ? (array) ($res->json('data') ?: []) : [];
    }

    /**
     * Id của đơn ĐÃ phát hành hoá đơn điện tử — null nếu sổ chưa có đơn nào.
     *
     * Hai trang tải PDF/XML chỉ tồn tại với đơn đã xuất hoá đơn; gọi chúng bằng
     * một đơn bất kỳ thì 404 là ĐÚNG, và bắt lỗi ở đó là bắt nhầm. Nhưng bỏ hẳn
     * hai trang khỏi lượt quét thì lúc cửa hàng có hoá đơn thật cũng không ai
     * canh nữa — nên dò đúng một đơn có hoá đơn, không có thì bỏ qua có ghi chú.
     */
    protected function donDaCoHoaDon(): ?string
    {
        $cacheKey = 'don-co-hoa-don';
        if (array_key_exists($cacheKey, static::$ids)) {
            return static::$ids[$cacheKey];
        }

        $found = null;

        foreach ($this->apiList('/admin/orders', ['limit' => 50]) as $don) {
            $id = data_get($don, 'id');
            if ($id === null) {
                continue;
            }

            try {
                $res = $this->goiApi('/admin/orders/'.$id.'/etax');
            } catch (\Throwable $e) {
                break;
            }

            if ($res->successful()) {
                $found = (string) $id;
                break;
            }
        }

        return static::$ids[$cacheKey] = $found;
    }

    /** Lấy giá trị khoá đầu tiên trong danh sách (id/code) để ghép vào URL. */
    protected function firstValue(string $uri, string $key): ?string
    {
        $cacheKey = $uri.'#'.$key;
        if (array_key_exists($cacheKey, static::$ids)) {
            return static::$ids[$cacheKey];
        }

        $rows = $this->apiList($uri);
        $value = isset($rows[0]) ? data_get($rows[0], $key) : null;

        return static::$ids[$cacheKey] = $value === null ? null : (string) $value;
    }

    /**
     * Danh sách route GET của CẢ HAI module, tách theo có/không có tham số.
     *
     * `thu-ngan.` cũng nằm trong lượt quét: module thu ngân là nơi cửa hàng thu
     * tiền, hỏng một trang ở đó thì tiệm ngừng bán — không có lý do gì để nó
     * được kiểm nhẹ hơn khu quản trị.
     */
    protected function adminGetRoutes(bool $withParams): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';
            $cuaTa = str_starts_with($name, 'admin.') || str_starts_with($name, 'thu-ngan.');

            if (! $cuaTa || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (isset(self::REDIRECT_ROUTES[$name]) || in_array($name, self::KHONG_PHAI_TRANG, true)) {
                continue;
            }
            if (str_contains($route->uri(), '{') !== $withParams) {
                continue;
            }

            $out[$name] = '/'.ltrim($route->uri(), '/');
        }

        ksort($out);

        return $out;
    }

    /** Mô tả ngắn gọn vì sao một request hỏng, để in ra cho dễ sửa. */
    protected function why($response): string
    {
        if ($response->exception) {
            return get_class($response->exception).': '.$response->exception->getMessage();
        }

        return trim(mb_substr(strip_tags($response->getContent()), 0, 120));
    }

    // ------------------------------------------------------------------ tests

    public function test_route_cong_khai_hoat_dong(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_khach_chua_dang_nhap_bi_day_ve_trang_dang_nhap(): void
    {
        $this->get('/admin/dashboard')->assertRedirect(route('login'));
        $this->get('/admin/orders')->assertRedirect(route('login'));
    }

    public function test_ajax_chua_dang_nhap_nhan_401_json(): void
    {
        $this->getJson('/admin/notifications')->assertStatus(401)
            ->assertJsonStructure(['message']);
    }

    public function test_dang_nhap_luu_dung_session(): void
    {
        $session = $this->adminSession();

        $this->assertNotEmpty($session['api.access_token'], 'API không trả access_token.');
        $this->assertContains(
            data_get($session['api.user'], 'role.name'),
            ['super_admin', 'admin', 'staff'],
            'Tài khoản kiểm thử không có quyền vào trang quản trị.'
        );
    }

    public function test_moi_trang_danh_sach_mo_duoc(): void
    {
        $session = $this->adminSession();
        $routes = $this->adminGetRoutes(withParams: false);

        $this->assertNotEmpty($routes, 'Không tìm thấy route GET nào trong khu quản trị.');

        $failed = [];
        foreach ($routes as $name => $uri) {
            // Vài trang bắt buộc có query (in hàng loạt) — lấy id thật để gọi.
            if (isset(self::REQUIRED_QUERY[$name])) {
                [$endpoint, $key] = explode('#', self::REQUIRED_QUERY[$name]);
                $id = $this->firstValue('/admin/'.$endpoint, $key);

                if ($id === null) {
                    continue;
                }

                $uri .= '?ids='.$id;
            }

            $res = $this->withSession($session)->get($uri);

            if ($res->getStatusCode() !== 200) {
                $failed[] = sprintf('%s [%s] -> %d | %s', $uri, $name, $res->getStatusCode(), $this->why($res));
            }
        }

        $this->assertSame([], $failed, count($failed).'/'.count($routes)." trang lỗi:\n".implode("\n", $failed));
    }

    public function test_moi_trang_chi_tiet_mo_duoc(): void
    {
        $session = $this->adminSession();

        [$routes, $skipped] = $this->detailPageUrls();

        $failed = [];
        foreach ($routes as $name => $target) {
            $res = $this->withSession($session)->get($target);

            if ($res->getStatusCode() !== 200) {
                $failed[] = sprintf('%s [%s] -> %d | %s', $target, $name, $res->getStatusCode(), $this->why($res));
            }
        }

        if ($skipped) {
            fwrite(STDERR, "\n  [bỏ qua vì DB chưa có dữ liệu] ".implode(', ', $skipped)."\n");
        }

        $this->assertSame([], $failed, count($failed)." trang chi tiết lỗi:\n".implode("\n", $failed));
    }

    /**
     * Dựng URL thật cho mọi route GET có tham số.
     * Trả về [ [tên route => url], [tên route bị bỏ qua vì thiếu dữ liệu] ].
     */
    protected function detailPageUrls(): array
    {
        // Tham số lấy từ dữ liệu thật của API; thiếu dữ liệu thì bỏ qua route đó.
        $params = [
            'admin.customers.detail' => ['id' => $this->firstValue('/admin/customers', 'id')],
            'admin.inventory.detail' => ['id' => $this->firstValue('/admin/inventory', 'variant_id')],
            'admin.inventory.history' => ['id' => $this->firstValue('/admin/inventory', 'variant_id')],
            'admin.orders.detail' => ['id' => $this->firstValue('/admin/orders', 'id')],
            'admin.orders.label' => ['id' => $this->firstValue('/admin/orders', 'id')],
            'admin.orders.print' => ['id' => $this->firstValue('/admin/orders', 'id')],
            'admin.products.show' => ['id' => $this->firstValue('/products', 'id')],
            'admin.returns.detail' => ['id' => $this->firstValue('/admin/returns', 'id')],
            'admin.returns.returnable' => ['orderId' => $this->firstValue('/admin/orders', 'id')],
            'admin.settings.page' => ['group' => 'general'],
            // Mấy màn dựng sau khi bài này viết ra. Thiếu khai ở đây là chúng
            // KHÔNG được quét — và bài vẫn xanh, nên phải đỏ để nhắc khai.
            'admin.chi-nhanh.etax' => ['id' => $this->firstValue('/admin/chi-nhanh', 'id')],
            'admin.dieu-chinh-ton-kho.show' => ['id' => $this->firstValue('/admin/dieu-chinh-ton-kho', 'id')],
            'admin.nha-cung-cap.phieuMua' => ['id' => $this->firstValue('/admin/nha-cung-cap', 'id')],
            'admin.nha-cung-cap.phieuMuaExport' => ['id' => $this->firstValue('/admin/nha-cung-cap', 'id')],
            'admin.phieu-mua-hang.show' => ['id' => $this->firstValue('/admin/phieu-mua-hang', 'id')],
            'admin.phieu-mua-hang.exportOne' => ['id' => $this->firstValue('/admin/phieu-mua-hang', 'id')],
            'admin.tra-hang-nha-cung-cap.show' => ['id' => $this->firstValue('/admin/tra-hang-nha-cung-cap', 'id')],
            'admin.phieu-dieu-chuyen.show' => ['id' => $this->firstValue('/admin/stock-transfers', 'id')],
            'admin.ton-kho-chi-nhanh.history' => ['id' => $this->firstValue('/admin/inventory', 'variant_id')],
            // Hoá đơn điện tử: chỉ có với đơn ĐÃ phát hành. Lấy đúng một đơn
            // như thế; sổ chưa có đơn nào thì nhánh "thiếu dữ liệu" lo.
            'admin.orders.pdfHoaDon' => ['id' => $this->donDaCoHoaDon()],
            'admin.orders.xmlHoaDon' => ['id' => $this->donDaCoHoaDon()],
            // Gói dịch vụ: hai trang này thuộc sổ nền tảng (control plane), cửa
            // hàng thường chưa có đơn gia hạn nào nên thường bị bỏ qua.
            'admin.goi-dich-vu.don' => ['id' => $this->firstValue('/admin/goi-dich-vu/don', 'id')],
            'admin.goi-dich-vu.thanh-toan' => ['id' => $this->firstValue('/admin/goi-dich-vu/don', 'id')],
            'thu-ngan.ban-hang.phieu' => ['id' => $this->firstValue('/admin/orders', 'id')],
            'thu-ngan.ca-lam-viec.show' => ['id' => $this->firstValue('/admin/ca-lam-viec', 'id')],
        ];

        $routes = $this->adminGetRoutes(withParams: true);
        $chuaKiemTra = array_diff(array_keys($routes), array_keys($params));
        $this->assertSame([], array_values($chuaKiemTra), 'Route có tham số chưa khai tham số mẫu: '.implode(', ', $chuaKiemTra));

        $urls = [];
        $skipped = [];
        foreach ($routes as $name => $uri) {
            $values = $params[$name];

            if (in_array(null, $values, true)) {
                $skipped[] = $name;

                continue;
            }

            $target = $uri;
            foreach ($values as $key => $value) {
                $target = str_replace('{'.$key.'}', rawurlencode($value), $target);
            }

            // Vài đường chi tiết còn đòi thêm query (sổ kho phải nói xem kho nào).
            if (isset(self::QUERY_CHI_TIET[$name])) {
                [$khoa, $endpoint, $lay] = explode('#', self::QUERY_CHI_TIET[$name]);
                $giaTri = $this->firstValue($endpoint, $lay);

                if ($giaTri === null) {
                    $skipped[] = $name;

                    continue;
                }

                $target .= '?'.$khoa.'='.rawurlencode($giaTri);
            }

            $urls[$name] = '/'.ltrim($target, '/');
        }

        return [$urls, $skipped];
    }

    /**
     * Nhân viên (thu ngân) chỉ mở được cụm quầy.
     *
     * Danh sách dưới đây điểm mặt TỪNG NHÓM TRANG chứ không lấy vài trang tiêu
     * biểu: chỗ hỏng trong thực tế là cả một nhóm bị quên ngoài `admin.manage`
     * khi thêm route mới, và một nhóm bị quên thì không trang nào khác đỏ lên.
     *
     * Go API chặn song song ở tầng dưới (xem TestThuNgan_ChiVaoDuocQuayBan bên
     * api/internal/apitest). Bài này chỉ kiểm phần việc của Shop Admin: đá người
     * dùng ra SỚM, tại đúng trang họ vừa bấm.
     */
    public function test_nhan_vien_bi_chan_khoi_trang_quan_ly(): void
    {
        $session = $this->adminSession();
        // Đổi vai trò thôi CHƯA đủ: từ migration 0015, cửa vào đọc từ
        // `access_areas` (chủ tiệm tích cửa nào thì vào cửa ấy), nên một phiên
        // mang vai "staff" mà vẫn còn cửa `quan_ly` thì đi qua được — đúng
        // thiết kế. Phải dựng đúng phiên của một người CHỈ có cửa quầy.
        $session['api.user'] = array_replace(
            (array) $session['api.user'],
            [
                'role' => ['name' => 'staff', 'display_name' => 'Nhân viên'],
                'access_areas' => 'thu_ngan',
                'quyen' => ['thu_ngan'],
            ]
        );
        // Bản chụp cửa vào trong phiên cũng phải theo, không thì nó thắng.
        $session[CuaVao::KHOA_CUA] = ['thu_ngan'];
        $session[CuaVao::KHOA_LUC] = time();

        $cam = [
            // Người & cấu hình — đã đóng từ trước.
            '/admin/users', '/admin/customers', '/admin/settings', '/admin/branches',
            '/admin/staff',
            '/admin/reports/revenue', '/admin/subscription',
            // Hàng hoá & tiếp thị.
            '/admin/products', '/admin/categories',
            '/admin/promotions', '/admin/vouchers', '/admin/banners',
            '/admin/contacts', '/admin/newsletter',
            // Trả hàng và kho.
            '/admin/returns', '/admin/inventory',
            // Cả ba trang này trước đây mở cho thu ngân; nay cửa đặt trên cả
            // nhóm /admin nên chúng cũng đóng.
            '/admin/dashboard', '/admin/orders', '/admin/profile',
        ];

        foreach ($cam as $uri) {
            $res = $this->withSession($session)->get($uri);

            $this->assertContains(
                $res->getStatusCode(),
                [302, 403],
                "Nhân viên KHÔNG được vào $uri nhưng nhận ".$res->getStatusCode().'.'
            );
        }

        // Chiều còn lại, và nó quan trọng ngang chiều trên: siết nhầm cả màn hình
        // bán hàng cũng là một cách làm cửa hàng ngừng bán.
        //
        // Cụm quầy nay nằm ở module Thu ngân (/thu-ngan) — nhân viên phải vào
        // được TRỌN module đó, còn trong khu quản trị thì đúng ba trang dưới đây.
        // Người chỉ đứng quầy KHÔNG mở được trang nào trong khu quản trị nữa,
        // kể cả Tổng quan và hồ sơ của chính mình: cửa `admin.cua:quan_ly` đặt
        // trên CẢ nhóm /admin (xem routes/web.php). Trước đây họ vào được vài
        // trang và gặp một thanh trái gần như trống rỗng.
        //
        // Nên chiều "phải mở được" giờ chỉ còn trọn module Thu ngân.
        $mo = ['/cashier/sales', '/cashier/shifts', '/cashier/orders'];

        foreach ($mo as $uri) {
            $res = $this->withSession($session)->get($uri);

            $this->assertSame(
                200,
                $res->getStatusCode(),
                "Nhân viên PHẢI vào được $uri nhưng nhận ".$res->getStatusCode().' | '.$this->why($res)
            );
        }
    }

    public function test_route_trung_chuyen_nhay_dung_cho(): void
    {
        $session = $this->adminSession();

        foreach (self::REDIRECT_ROUTES as $name => $dich) {
            $uri = '/'.ltrim(Route::getRoutes()->getByName($name)->uri(), '/');

            // Đường cũ có {id}: lấy id thật, sổ chưa có dữ liệu thì bỏ qua.
            if (isset(self::ID_TRUNG_CHUYEN[$name])) {
                [$endpoint, $lay] = explode('#', self::ID_TRUNG_CHUYEN[$name]);
                $id = $this->firstValue($endpoint, $lay);

                if ($id === null) {
                    continue;
                }

                $uri = str_replace('{id}', rawurlencode($id), $uri);
                $dich = str_replace('{id}', rawurlencode($id), $dich);
            }

            $this->withSession($session)->get($uri)->assertRedirect($dich);
        }
    }

    public function test_moi_trang_dung_mot_the_h1(): void
    {
        $session = $this->adminSession();

        [$chiTiet] = $this->detailPageUrls();

        $loi = [];
        foreach ($this->adminGetRoutes(withParams: false) + $chiTiet as $name => $uri) {
            // Chỉ soát TRANG. Route xuất tệp, in tem và các đường JSON cho modal
            // không có (và không cần) tiêu đề trang.
            if (isset(self::REQUIRED_QUERY[$name])) {
                continue;
            }

            $res = $this->withSession($session)->get($uri);
            if ($res->getStatusCode() !== 200
                || ! str_contains((string) $res->headers->get('content-type'), 'text/html')) {
                continue;
            }

            // Đếm bằng bộ PHÂN TÍCH HTML, không phải biểu thức chính quy.
            //
            // Trang nào dựng HTML bằng JS — bản in, hàng của bảng nạp lại bằng
            // AJAX — đều có thẻ nằm trong chuỗi kịch bản. Đó không phải thẻ của
            // tài liệu này: nó chỉ thành thẻ khi kịch bản chạy, mà bài kiểm thì
            // không chạy JS. Đếm cả vào là báo đỏ một chuyện không có thật.
            //
            // Đã thử cắt <script> bằng preg_replace và KHÔNG ăn: chỉ cần một
            // kịch bản mang chuỗi thoát của thẻ đóng là biểu thức lệch nhịp từ
            // đó trở đi. DOMDocument đọc theo đúng luật của HTML nên không vướng.
            $so = $this->demThe($res->getContent(), 'h1');
            if ($so !== 1) {
                $loi[] = "$uri có $so thẻ h1";
            }
        }

        $this->assertSame([], $loi,
            "Mỗi trang phải có đúng MỘT thẻ h1 — trình đọc màn hình lấy đó làm mốc tiêu đề cấp 1.\n"
            .implode("\n", $loi));
    }

    /** Đếm thẻ trong TÀI LIỆU; thẻ nằm trong chuỗi của <script> không tính. */
    protected function demThe(string $html, string $the): int
    {
        $truoc = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        // HTML của trang không phải XHTML hợp lệ (thẻ tự đóng, thuộc tính không
        // nháy) — DOMDocument vẫn đọc được nhưng kêu ầm lên, nên tắt tiếng.
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($truoc);

        return $doc->getElementsByTagName($the)->length;
    }

    public function test_in_hang_loat_noi_ro_ly_do_khi_khong_in_duoc(): void
    {
        $session = $this->adminSession();

        // Chưa chọn đơn nào là người dùng thiếu một bước, KHÁC hẳn với việc hệ
        // thống không tải được đơn. Gộp hai câu làm một là đổ oan cho máy chủ.
        foreach (['/admin/orders/print' => 'in hoá đơn', '/admin/orders/label' => 'in tem giao hàng'] as $uri => $viec) {
            $res = $this->withSession($session)->get($uri);

            $this->assertSame(404, $res->getStatusCode());
            $this->assertStringContainsString(
                'Chưa chọn đơn hàng nào để '.$viec,
                (string) $res->exception?->getMessage(),
                "$uri phải nói rõ là chưa chọn đơn, không đổ cho lỗi tải dữ liệu."
            );
        }

        // Còn id không tồn tại thì mới là "không tải được".
        $res = $this->withSession($session)->get('/admin/orders/print?ids=999999999');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('Không tải được đơn hàng', (string) $res->exception?->getMessage());
    }

    public function test_moi_trang_deu_nap_bo_style_cua_chinh_no(): void
    {
        // Dự án không có tệp CSS chung: style của mỗi trang nằm trong khối <style>
        // ngay trong view của trang đó. Trang nào dùng lại tên class của trang khác
        // mà quên nạp khối style tương ứng thì mở ra là trắng trơn — vẫn 200 nên
        // test "mở được" không bắt được.
        $session = $this->adminSession();
        [$chiTiet] = $this->detailPageUrls();

        $loi = [];
        foreach ($this->adminGetRoutes(withParams: false) + $chiTiet as $name => $uri) {
            if (isset(self::REQUIRED_QUERY[$name])) {
                continue;
            }

            $res = $this->withSession($session)->get($uri);
            if ($res->getStatusCode() !== 200
                || ! str_contains((string) $res->headers->get('content-type'), 'text/html')) {
                continue;
            }

            $html = $res->getContent();
            if (! preg_match('/<h1[^>]*class="([a-z0-9-]+)"/i', $html, $m)) {
                continue;
            }

            if (! preg_match('/\.'.preg_quote($m[1], '/').'\s*[,{]/', $html)) {
                $loi[] = "$uri dùng class .{$m[1]} nhưng không trang nào nạp style cho nó";
            }
        }

        $this->assertSame([], $loi, implode("\n", $loi));
    }
}
