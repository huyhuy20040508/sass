<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sổ ghi chú khách ở quầy (KB #23): đọc / thêm / xoá đi thẳng sang API quầy, ô
 * customer_note cũ thành "ghi chú cố định", nút lọc "Có hẹn hôm nay".
 */
class GhiChuKhachQuayTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu', 'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff'], 'access_areas' => 'thu_ngan'],
        ];
    }

    protected function duong(HttpRequest $r): string
    {
        return (string) parse_url($r->url(), PHP_URL_PATH);
    }

    public function test_doc_so_ghi_chu(): void
    {
        Http::fake(['*/admin/orders/pos/khach-hang/128/ghi-chu' => Http::response(['data' => [['id' => 3, 'loai' => 'hen', 'noi_dung' => 'Hẹn lấy áo']]])]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.crm.ghiChu', ['id' => 128]))
            ->assertOk()->assertJsonPath('data.0.noi_dung', 'Hẹn lấy áo');
    }

    public function test_them_ghi_chu_gui_dung_o(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 9]], 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.themGhiChu', ['id' => 128]), ['loai' => 'hen', 'noi_dung' => 'Hẹn 17h', 'ngay_hen' => '2026-10-25'])
            ->assertOk()->assertJsonPath('data.id', 9);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($this->duong($r), '/admin/orders/pos/khach-hang/128/ghi-chu')
            && $r['loai'] === 'hen' && $r['noi_dung'] === 'Hẹn 17h' && $r['ngay_hen'] === '2026-10-25');
    }

    public function test_khong_phai_hen_thi_bo_ngay(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 9]], 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.themGhiChu', ['id' => 128]), ['loai' => 'cham-soc', 'noi_dung' => 'Thích màu xanh', 'ngay_hen' => '2026-10-25'])->assertOk();
        Http::assertSent(fn ($r) => $r['ngay_hen'] === '');
    }

    public function test_thieu_o_khong_goi_api(): void
    {
        Http::fake();
        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.themGhiChu', ['id' => 128]), ['loai' => 'hen', 'noi_dung' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['ngay_hen']);
        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.themGhiChu', ['id' => 128]), ['loai' => 'khac', 'noi_dung' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['loai', 'noi_dung']);
        Http::assertNothingSent();
    }

    public function test_xoa_cua_nguoi_khac_giu_cau_api(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Chỉ người ghi mới xoá được ghi chú này'], 403)]);

        $this->withSession($this->phien())->deleteJson(route('thu-ngan.crm.xoaGhiChu', ['id' => 128, 'note' => 5]))
            ->assertStatus(403)->assertJsonPath('message', 'Chỉ người ghi mới xoá được ghi chú này');
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($this->duong($r), '/admin/orders/pos/khach-hang/128/ghi-chu/5'));
    }

    public function test_man_co_nut_loc_hen_va_du_lieu_cho_tab(): void
    {
        Http::fake(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            if (str_ends_with($this->duong($r), '/admin/orders/pos/khach-hang')) {
                return isset($q['hen_hom_nay'])
                    ? Http::response(['data' => [], 'meta' => ['total' => 3]])
                    : Http::response(['data' => [['id' => 128, 'full_name' => 'Mai', 'customer_note' => 'Dị ứng len']], 'meta' => ['page' => 1, 'total' => 1]]);
            }

            return Http::response(['data' => []]);
        });
        $html = $this->withSession($this->phien())->get(route('thu-ngan.crm.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-nhanh="hen"[^>]*>.*?Có hẹn hôm nay<b>3</b>#s', $html, 'nút lọc đếm bằng hen_hom_nay=1');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'hen_hom_nay=1') && str_contains($r->url(), 'page_size=1'));
        preg_match('#<script type="application/json" id="crDuLieu">(.*?)</script>#s', $html, $m);
        $d = json_decode($m[1], true);
        $this->assertSame(7, $d['toi']);
        $this->assertSame(route('thu-ngan.crm.ghiChu', ['id' => 0]), $d['url']['ghiChu']);
        $this->assertStringContainsString('Ghi chú cố định', $html);
    }

    public function test_bam_nut_loc_hen_gui_tham_so(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);
        $this->withSession($this->phien())->get(route('thu-ngan.crm.index', ['nhanh' => 'hen']))->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'hen_hom_nay=1') && str_contains($r->url(), 'page_size=30'));
    }
}
