<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * DỰNG THẬT mọi trang của khu điều hành, với người đã đăng nhập.
 *
 * PlatformSmokeTest chỉ xét ranh giới đăng nhập: nó chưa bao giờ dựng xong một
 * trang nào ở trong. Vì thế lỗi 26/09 nằm im ba tuần — đợt đổi tên tệp sang
 * tiếng Anh thay luôn chuỗi TÊN ROUTE trong view (`platform.khach-hang-order.
 * goi-dich-vu` thành `platform.customer-orders.service-packages`) mà không đổi
 * bên routes/web.php, nên `route()` ném RouteNotFoundException. Chuỗi hỏng nằm
 * ở partials/sidebar.blade.php — thanh trái có trong layout dùng chung, tức là
 * CẢ app 500, kể cả trang Tổng quan.
 *
 * Bài này đi qua TỪNG route GET của nhóm `platform.` thay vì một danh sách gõ
 * tay: thêm màn mới là tự được canh, không phải nhớ cập nhật bài test.
 *
 * Không gọi Go API thật (Http::fake) — thứ cần canh ở đây là route, view và
 * middleware, chạy được cả khi chưa bật API.
 */
class PlatformPagesRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mọi lượt gọi API trả khung rỗng hợp lệ: view phải dựng được với bảng
        // trống, và chính nó là trạng thái người dùng gặp lúc API chưa bật.
        Http::fake(['*' => Http::response(['data' => [], 'meta' => []])]);
    }

    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-nen-tang',
            'api.refresh_token' => 'refresh-nen-tang',
            'api.user' => ['id' => 1, 'full_name' => 'Chủ nền tảng', 'role' => 'owner'],
        ];
    }

    /**
     * Tham số mẫu cho các route có {…}. Thiếu một tham số thì route() ném
     * UrlGenerationException — vẫn là đỏ, nên không có màn nào lọt im lặng.
     *
     * @return array<string, int|string>
     */
    protected function thamSoMau(): array
    {
        return ['hopDong' => 1, 'plan' => 1, 'id' => 1];
    }

    /**
     * Đi qua TỪNG route GET của nhóm `platform.` trong MỘT bài: danh sách route
     * chỉ có sau khi app khởi động, mà bộ cấp dữ liệu của PHPUnit thì chạy trước đó.
     *
     * Gom hết trang hỏng rồi mới báo, thay vì dừng ở trang đầu — một tên route
     * sai trong layout làm cả app 500, lúc ấy cần thấy đủ danh sách.
     */
    public function test_moi_trang_dung_duoc_khi_da_dang_nhap(): void
    {
        $mau = ['hopDong' => 1, 'plan' => 1, 'id' => 1];
        $hong = [];
        $daDi = 0;

        foreach (Route::getRoutes() as $r) {
            $ten = $r->getName();
            if (! $ten || ! str_starts_with($ten, 'platform.') || ! in_array('GET', $r->methods(), true)) {
                continue;
            }

            $thamSo = [];
            foreach ($r->parameterNames() as $p) {
                $thamSo[$p] = $mau[$p] ?? 1;
            }

            $ma = $this->withSession($this->phien())->get(route($ten, $thamSo))->getStatusCode();
            $daDi++;

            // 200 hay một lượt chuyển hướng có chủ ý đều được; 500 thì không.
            if ($ma >= 500) {
                $hong[] = "$ten ($ma)";
            }
        }

        $this->assertGreaterThan(5, $daDi, 'không dò ra route nào — bài test tự rỗng');
        $this->assertSame([], $hong, 'trang dựng hỏng (xem câu lỗi ở storage/logs): '.implode(', ', $hong));
    }

    /**
     * Thanh trái nằm trong layout dùng chung nên một tên route hỏng ở đây là
     * hỏng CẢ app. Xét riêng để câu lỗi chỉ đúng chỗ, thay vì "mọi trang 500".
     */
    public function test_moi_ten_route_trong_thanh_trai_deu_co_that(): void
    {
        $nguon = file_get_contents(resource_path('views/partials/sidebar.blade.php'));
        preg_match_all("/'(platform\.[a-z0-9._-]+)'/", $nguon, $m);

        $this->assertNotEmpty($m[1], 'không đọc được tên route nào trong thanh trái');

        $co = collect(Route::getRoutes())->map(fn ($r) => $r->getName())->filter()->all();
        foreach (array_unique($m[1]) as $ten) {
            // Mẫu `platform.cai-dat.*` là của routeIs(), không phải route().
            if (str_contains($ten, '*')) {
                continue;
            }
            $this->assertContains($ten, $co, "thanh trái gọi route `$ten` không tồn tại");
        }
    }
}
