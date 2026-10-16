<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Bảng đếm theo mệnh giá: gửi kèm mở / đóng ca, hiện ở tab Thông tin ca, in lên phiếu kết ca. */
class BangDemCaTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Quản lý Hùng', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    protected function caDaDong(array $them = []): array
    {
        return $them + ['id' => 5, 'shop_id' => 2, 'opened_at' => '2026-10-14T07:30:00+07:00', 'closed_at' => '2026-10-14T15:00:00+07:00',
            'opening_cash' => 705000, 'opened_by' => 7, 'closed_by' => 7, 'opened_by_name' => 'Quản lý Hùng', 'closed_by_name' => 'Quản lý Hùng',
            'tong_thu' => 0, 'tong_chi' => 0, 'expected_cash' => 705000, 'counted_cash' => 705000, 'difference' => 0];
    }

    protected function demMau(): array
    {
        return [['menh_gia' => 500000, 'so_to' => 1], ['menh_gia' => 100000, 'so_to' => 2], ['menh_gia' => 1000, 'so_to' => 5]];
    }

    protected function fakeCa(array $ca): void
    {
        Http::fake(function (Request $r) use ($ca) {
            $u = urldecode($r->url());
            if (str_contains($u, '/admin/ca-lam-viec/5')) {
                return Http::response(['data' => ['ca' => $ca, 'so_quy' => [], 'doanh_thu' => ['order_count' => 0, 'total_revenue' => 0]]]);
            }
            if (str_contains($u, '/admin/ca-lam-viec?')) {
                return Http::response(['data' => [$ca], 'meta' => ['total' => 1]]);
            }

            return Http::response(['data' => null]);
        });
    }

    public function test_mo_ca_gui_bang_dem_dung_kieu_so(): void
    {
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response(['data' => ['id' => 9]], 201)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.mo'), [
            'opening_cash' => 705000,
            'opening_count' => [['menh_gia' => '500000', 'so_to' => '1'], ['menh_gia' => 100000, 'so_to' => 2], ['menh_gia' => 1000, 'so_to' => 5]],
        ])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/admin/ca-lam-viec/mo') && $r['opening_count'] === $this->demMau());
    }

    public function test_khong_dem_theo_to_thi_khong_gui(): void
    {
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response(['data' => ['id' => 9]], 201)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 0])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/admin/ca-lam-viec/mo') && ! array_key_exists('opening_count', $r->data()));
    }

    public function test_mo_ca_loi_bang_dem_cua_api_len_mot_cau(): void
    {
        $cau = 'Bảng đếm cộng ra 705.000₫, khác số tiền đã nhập 700.000₫ — sửa một trong hai.';
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response(['success' => false, 'message' => $cau, 'errors' => ['opening_count' => $cau]], 422)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 700000, 'opening_count' => $this->demMau()])
            ->assertStatus(422)->assertJsonPath('message', $cau);
    }

    public function test_dong_ca_gui_bang_dem(): void
    {
        Http::fake(['*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => ['id' => 9, 'opening_cash' => 705000, 'tong_thu' => 0, 'tong_chi' => 0]]),
            '*/admin/ca-lam-viec/dong' => Http::response(['data' => ['ca' => ['id' => 9]]])]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.dong'), ['counted_cash' => 705000, 'counted_count' => $this->demMau()])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/admin/ca-lam-viec/dong') && $r['counted_count'] === $this->demMau());
    }

    public function test_so_to_am_bi_chan_truoc_khi_goi_api(): void
    {
        Http::fake();
        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.dong'), ['counted_cash' => 0, 'counted_count' => [['menh_gia' => 500000, 'so_to' => -1]]])
            ->assertStatus(422);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/admin/ca-lam-viec/dong'));
    }

    public function test_thong_tin_ca_hien_bang_dem_luc_mo_va_luc_dong(): void
    {
        $this->fakeCa($this->caDaDong(['opening_count' => $this->demMau(), 'counted_count' => [['menh_gia' => 200000, 'so_to' => 3], ['menh_gia' => 5000, 'so_to' => 21]]]));
        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index', ['ca' => 5]))->assertOk()->getContent();
        $phang = preg_replace('/\s+/', ' ', $html);
        $this->assertStringContainsString('<caption>Đếm theo tờ lúc mở</caption> <tbody> <tr> <td>500.000</td> <td>× 1</td> <td>500.000</td> </tr>', $phang);
        $this->assertStringContainsString('<td>1.000</td> <td>× 5</td> <td>5.000</td>', $phang);
        $this->assertStringContainsString('<caption>Đếm theo tờ lúc đóng</caption> <tbody> <tr> <td>200.000</td> <td>× 3</td> <td>600.000</td> </tr> <tr> <td>5.000</td> <td>× 21</td> <td>105.000</td>', $phang);
    }

    public function test_ca_khong_dem_theo_to_thi_khong_co_bang(): void
    {
        $this->fakeCa($this->caDaDong(['opening_count' => null, 'counted_count' => null]));
        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index', ['ca' => 5]))->assertOk()->getContent();
        $this->assertStringNotContainsString('class="ca-dem-to"', $html);
    }

    public function test_phieu_ket_ca_in_bang_dem(): void
    {
        $this->fakeCa($this->caDaDong(['opening_count' => $this->demMau(), 'counted_count' => [['menh_gia' => 200000, 'so_to' => 3], ['menh_gia' => 5000, 'so_to' => 21]]]));
        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.phieu', 5))->assertOk()->getContent();
        $phang = preg_replace('/\s+/', ' ', $html);
        $this->assertStringContainsString('<div class="nho dam">Đếm đầu ca:</div> <div class="dong nho"><span>500.000 x 1</span><span>500.000</span></div>', $phang);
        $this->assertStringContainsString('<div class="nho dam">Đếm lúc đóng:</div> <div class="dong nho"><span>200.000 x 3</span><span>600.000</span></div> <div class="dong nho"><span>5.000 x 21</span><span>105.000</span></div>', $phang);
    }
}
