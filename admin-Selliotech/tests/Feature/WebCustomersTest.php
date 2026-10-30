<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Khu "QLTK khách hàng web": cùng màn hình với khu Order nhưng chỉ ký hợp đồng cho cửa hàng
 * POS đã có — không dựng cửa hàng / tài khoản mới, không đổi mật khẩu quản trị.
 */
class WebCustomersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/platform/subscriptions/9/web-chi-nhanh*' => Http::response(['data' => [
                'toi_da' => 1, 'dang_mo' => 1, 'chi_nhanh' => [
                    ['id' => 2, 'ma' => 'mac-dinh', 'ten' => 'Quốc Huy', 'dang_hoat_dong' => true, 'ten_mien' => 'quochuy.web.selliotech.store'],
                    ['id' => 5, 'ma' => 'chi-nhanh-2', 'ten' => 'Chi nhánh 2', 'dang_hoat_dong' => true, 'ten_mien' => null],
                    ['id' => 7, 'ma' => 'kho', 'ten' => 'Kho cũ', 'dang_hoat_dong' => false, 'ten_mien' => null],
                ],
            ]]),
            '*/platform/subscriptions/8/web-chi-nhanh*' => Http::response(
                ['success' => false, 'message' => 'Dữ liệu không hợp lệ', 'errors' => ['shop_id' => 'Hợp đồng chỉ cho mở web 1 chi nhánh — gỡ web một chi nhánh khác']], 422),
            '*/platform/plans*' => Http::response(['data' => ['plans' => [
                ['id' => 4, 'code' => 'web', 'name' => 'Website bán hàng', 'status' => 'active', 'billing_cycle' => 'thang', 'price' => 199000],
            ]]]),
            '*/platform/cua-hang-chua-ky*' => Http::response(['data' => ['cua_hang' => [
                ['ma' => 'quochuy', 'ten' => 'Quốc Huy'],
            ]]]),
            '*/platform/subscriptions/*' => Http::response(['data' => ['hop_dong' => [
                'id' => 9, 'ma_cua_hang' => 'test', 'ten_cua_hang' => 'quochuy', 'ma_app' => 'web',
                'trang_thai' => 'active', 'goi' => 'web', 'quan_tri' => ['ten_dang_nhap' => 'admin'],
            ]]]),
            '*' => Http::response(['data' => [], 'meta' => []]),
        ]);
    }

    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-nen-tang',
            'api.refresh_token' => 'refresh-nen-tang',
            'api.user' => ['id' => 1, 'full_name' => 'Chủ nền tảng', 'role' => 'owner'],
        ];
    }

    public function test_nguoi_chinh_thuc_web_chi_ky_cho_cua_hang_da_co(): void
    {
        $res = $this->withSession($this->phien())->get(route('platform.khach-hang-web.nguoi-chinh-thuc'));

        $res->assertOk()
            ->assertSee('QLTK khách hàng web')
            ->assertSee('Mọi khách đã ký hợp đồng Website bán hàng')
            ->assertSee('Ký hợp đồng')
            ->assertSee(route('platform.khach-hang-web.hop-dong.ky'), false)
            ->assertDontSee('Khách mới')
            ->assertDontSee('action="'.url('/order-customers'), false);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/platform/plans') && $r['app'] === 'web');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/platform/cua-hang-chua-ky') && $r['app'] === 'web');
        Http::assertNotSent(fn (Request $r) => ($r['app'] ?? null) === 'order');
    }

    public function test_nguoi_chinh_thuc_order_van_co_khach_moi(): void
    {
        $this->withSession($this->phien())->get(route('platform.khach-hang-order.nguoi-chinh-thuc'))
            ->assertOk()
            ->assertSee('QLTK khách hàng order')
            ->assertSee('Khách mới')
            ->assertSee('Thêm hợp đồng chính thức')
            ->assertSee(route('platform.khach-hang-order.chinh-thuc.tao'), false)
            ->assertDontSee('action="'.url('/web-customers'), false);
    }

    public function test_dung_thu_web_khong_mo_tai_khoan_moi(): void
    {
        $this->withSession($this->phien())->get(route('platform.khach-hang-web.nguoi-dung-thu'))
            ->assertOk()
            ->assertDontSee('Thêm tài khoản dùng thử');

        $this->withSession($this->phien())->get(route('platform.khach-hang-order.nguoi-dung-thu'))
            ->assertOk()
            ->assertSee('Thêm tài khoản dùng thử');
    }

    public function test_chi_tiet_web_khong_doi_mat_khau_va_lui_dung_khu(): void
    {
        $this->withSession($this->phien())->get(route('platform.khach-hang-web.hop-dong.chi-tiet', 9))
            ->assertOk()
            ->assertSee(route('platform.khach-hang-web.nguoi-chinh-thuc'), false)
            ->assertSee(route('platform.khach-hang-web.hop-dong.gia-han', ['hopDong' => '__ID__']), false)
            ->assertDontSee('sheet-matkhau', false);

        $this->withSession($this->phien())->get(route('platform.khach-hang-order.hop-dong.chi-tiet', 9))
            ->assertOk()
            ->assertSee('sheet-matkhau', false);
    }

    public function test_bang_gia_web(): void
    {
        $this->withSession($this->phien())->get(route('platform.khach-hang-web.goi-dich-vu'))
            ->assertOk()
            ->assertSee('Bảng giá đang áp cho phần mềm Website bán hàng')
            ->assertSee(route('platform.khach-hang-web.goi-dich-vu.luu', ['plan' => 4]), false);
    }

    public function test_ky_hop_dong_web_ve_dung_khu(): void
    {
        $this->withSession($this->phien())
            ->post(route('platform.khach-hang-web.hop-dong.ky'), ['plan_id' => 4, 'ma_cua_hang' => 'quochuy'])
            ->assertRedirect(route('platform.khach-hang-web.nguoi-chinh-thuc'));
    }

    public function test_khu_web_khong_co_duong_dung_cua_hang(): void
    {
        // Đối chứng: cùng đường bên khu Order có thật.
        $this->withSession($this->phien())->get('/order-customers/database')->assertOk();

        $this->withSession($this->phien())->get('/web-customers/subscribers')->assertOk();
        $this->assertContains($this->withSession($this->phien())->post('/web-customers/trials')->status(), [404, 405]);
        $this->assertContains($this->withSession($this->phien())->post('/web-customers/subscribers')->status(), [404, 405]);
        $this->withSession($this->phien())->get('/web-customers/database')->assertNotFound();
        $this->assertContains($this->withSession($this->phien())->post('/web-customers/contracts/9/password')->status(), [404, 405]);
    }

    public function test_chi_tiet_web_co_khoi_web_chi_nhanh(): void
    {
        $res = $this->withSession($this->phien())->get(route('platform.khach-hang-web.hop-dong.chi-tiet', 9));

        $res->assertOk()
            ->assertSee('Web của chi nhánh')
            ->assertSee('đang mở 1 / 1 chi nhánh')
            ->assertSee('https://quochuy.web.selliotech.store', false)
            ->assertSee('Gỡ web')
            ->assertSee('Ngừng hoạt động')
            ->assertSee('Đã mở đủ 1 chi nhánh theo hợp đồng');
        // Hết chỗ: nút Mở web của chi nhánh 2 bị khoá; chi nhánh ngừng hoạt động không có nút.
        $this->assertSame(1, substr_count($res->getContent(), '>Mở web</button>'));
        $this->assertStringContainsString('disabled title="Đã mở đủ số chi nhánh của hợp đồng"', $res->getContent());

        $this->withSession($this->phien())->get(route('platform.khach-hang-order.hop-dong.chi-tiet', 9))
            ->assertOk()
            ->assertDontSee('Web của chi nhánh');
    }

    public function test_mo_web_goi_api_va_bao_loi_nghiep_vu(): void
    {
        $this->withSession($this->phien())
            ->from(route('platform.khach-hang-web.hop-dong.chi-tiet', 9))
            ->post(route('platform.khach-hang-web.web-chi-nhanh.mo', 9), ['shop_id' => 5])
            ->assertRedirect(route('platform.khach-hang-web.hop-dong.chi-tiet', 9));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/platform/subscriptions/9/web-chi-nhanh') && $r['shop_id'] === 5);

        $this->withSession($this->phien())
            ->post(route('platform.khach-hang-web.web-chi-nhanh.mo', 8), ['shop_id' => 5])
            ->assertSessionHas('error', 'Hợp đồng chỉ cho mở web 1 chi nhánh — gỡ web một chi nhánh khác');

        $this->withSession($this->phien())
            ->post(route('platform.khach-hang-web.web-chi-nhanh.go', ['hopDong' => 9, 'shop' => 2]));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/platform/subscriptions/9/web-chi-nhanh/2'));
    }

    public function test_ky_web_co_o_dung_thu(): void
    {
        $this->withSession($this->phien())->get(route('platform.khach-hang-web.nguoi-chinh-thuc'))
            ->assertSee('name="dung_thu"', false);
        $this->withSession($this->phien())->get(route('platform.khach-hang-order.nguoi-chinh-thuc'))
            ->assertDontSee('name="dung_thu"', false);

        $this->withSession($this->phien())
            ->post(route('platform.khach-hang-web.hop-dong.ky'), ['plan_id' => 4, 'ma_cua_hang' => 'quochuy', 'dung_thu' => '1']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/platform/hop-dong') && $r['dung_thu'] === true);
    }
}
