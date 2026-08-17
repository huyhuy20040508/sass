<?php

namespace Tests\Feature;

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
        'admin.reports.index' => '/admin/reports/revenue',
        'admin.settings.index' => '/admin/settings/general',
        'thu-ngan.' => '/thu-ngan/ban-hang',
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
    protected const KHONG_PHAI_TRANG = [
        'thu-ngan.ban-hang.scan',
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

        $email = env('SMOKE_ADMIN_EMAIL', 'smoke.test@local.invalid');
        $password = env('SMOKE_ADMIN_PASSWORD', 'SmokeTest@123');

        try {
            $res = Http::baseUrl(config('api.base_url'))
                ->timeout(10)->acceptJson()->asJson()
                ->post('/auth/login', ['email' => $email, 'password' => $password]);
        } catch (\Throwable $e) {
            static::$skipReason = 'Không gọi được API ('.config('api.base_url').'): '.$e->getMessage();
            $this->markTestSkipped(static::$skipReason);
        }

        if (! $res->successful()) {
            static::$skipReason = 'API từ chối đăng nhập tài khoản kiểm thử ('.$email.'): '.$res->body();
            $this->markTestSkipped(static::$skipReason);
        }

        return static::$adminSession = [
            'api.access_token' => $res->json('data.access_token'),
            'api.refresh_token' => $res->json('data.refresh_token'),
            'api.user' => $res->json('data.user'),
        ];
    }

    /** Gọi API bằng token quản trị, trả về mảng data (rỗng nếu lỗi). */
    protected function apiList(string $uri, array $query = ['limit' => 1]): array
    {
        $token = $this->adminSession()['api.access_token'];

        try {
            $res = Http::baseUrl(config('api.base_url'))->timeout(10)
                ->acceptJson()->withToken($token)->get($uri, $query);
        } catch (\Throwable $e) {
            return [];
        }

        return $res->successful() ? (array) ($res->json('data') ?: []) : [];
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
        $this->get('/quen-mat-khau')->assertOk();
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
            'admin.purchases.detail' => ['id' => $this->firstValue('/admin/purchases', 'id')],
            'admin.purchase-returns.detail' => ['id' => $this->firstValue('/admin/purchase-returns', 'id')],
            'admin.purchase-returns.returnable' => ['purchaseId' => $this->firstValue('/admin/purchases', 'id')],
            'admin.receipts.detail' => ['code' => $this->firstValue('/admin/receipts', 'code')],
            'admin.returns.detail' => ['id' => $this->firstValue('/admin/returns', 'id')],
            'admin.returns.returnable' => ['orderId' => $this->firstValue('/admin/orders', 'id')],
            'admin.settings.page' => ['group' => 'general'],
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
        $session['api.user'] = array_replace(
            (array) $session['api.user'],
            ['role' => ['name' => 'staff', 'display_name' => 'Nhân viên']]
        );

        $cam = [
            // Người & cấu hình — đã đóng từ trước.
            '/admin/users', '/admin/customers', '/admin/settings', '/admin/chi-nhanh',
            '/admin/reports/revenue', '/admin/goi-dich-vu',
            // Hàng hoá & tiếp thị.
            '/admin/products', '/admin/categories', '/admin/brands',
            '/admin/khuyen-mai', '/admin/voucher', '/admin/banners',
            '/admin/contacts', '/admin/dang-ky-nhan-tin',
            // Trả hàng, kho và mua vào.
            '/admin/returns', '/admin/inventory', '/admin/purchases',
            '/admin/suppliers', '/admin/receipts', '/admin/purchase-returns',
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
        $mo = [
            '/admin/dashboard', '/admin/orders', '/admin/profile',
            '/thu-ngan/ban-hang', '/thu-ngan/ca-lam-viec', '/thu-ngan/don-hang',
        ];

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

            $so = preg_match_all('/<h1[\s>]/i', $res->getContent());
            if ($so !== 1) {
                $loi[] = "$uri có $so thẻ h1";
            }
        }

        $this->assertSame([], $loi,
            "Mỗi trang phải có đúng MỘT thẻ h1 — trình đọc màn hình lấy đó làm mốc tiêu đề cấp 1.\n"
            .implode("\n", $loi));
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
