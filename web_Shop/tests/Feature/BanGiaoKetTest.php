<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Đóng ca: giao về chủ + giữ lại cho ca sau; ca sau gợi ý theo số giữ lại; phiếu in hai dòng. */
class BanGiaoKetTest extends TestCase
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
            'opening_cash' => 500000, 'opened_by' => 7, 'closed_by' => 7, 'opened_by_name' => 'Quản lý Hùng', 'closed_by_name' => 'Quản lý Hùng',
            'tong_thu' => 0, 'tong_chi' => 0, 'expected_cash' => 500000, 'counted_cash' => 500000, 'difference' => 0];
    }

    public function test_dong_ca_gui_giao_va_giu_sang_api(): void
    {
        Http::fake(['*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => ['id' => 9, 'opening_cash' => 500000, 'tong_thu' => 0, 'tong_chi' => 0]]),
            '*/admin/ca-lam-viec/dong' => Http::response(['data' => ['ca' => ['id' => 9]]])]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.dong'), ['counted_cash' => 500000, 'delivered_cash' => 350000, 'kept_cash' => 150000])
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/admin/ca-lam-viec/dong')
            && (float) $r['delivered_cash'] === 350000.0 && (float) $r['kept_cash'] === 150000.0);
    }

    public function test_khong_khai_ban_giao_thi_khong_gui(): void
    {
        Http::fake(['*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => ['id' => 9, 'opening_cash' => 0, 'tong_thu' => 0, 'tong_chi' => 0]]),
            '*/admin/ca-lam-viec/dong' => Http::response(['data' => ['ca' => ['id' => 9]]])]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.dong'), ['counted_cash' => 0])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/admin/ca-lam-viec/dong')
            && ! array_key_exists('delivered_cash', $r->data()) && ! array_key_exists('kept_cash', $r->data()));
    }

    public function test_cau_loi_cua_api_toi_nguyen_van(): void
    {
        $cau = 'Giao về chủ + giữ lại = 450.000₫, khác tiền đếm được 500.000₫.';
        Http::fake(['*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => ['id' => 9, 'opening_cash' => 500000, 'tong_thu' => 0, 'tong_chi' => 0]]),
            '*/admin/ca-lam-viec/dong' => Http::response(['success' => false, 'message' => $cau, 'errors' => ['kept_cash' => $cau]], 422)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.dong'), ['counted_cash' => 500000, 'delivered_cash' => 300000, 'kept_cash' => 150000])
            ->assertStatus(422)->assertJsonPath('message', $cau);
    }

    public function test_phieu_in_giao_ve_chu_va_giu_lai(): void
    {
        Http::fake(['*/admin/ca-lam-viec/5' => Http::response(['data' => ['ca' => $this->caDaDong(['delivered_cash' => 350000, 'kept_cash' => 150000]),
            'so_quy' => [], 'doanh_thu' => ['order_count' => 0, 'total_revenue' => 0]]]), '*' => Http::response(['data' => []])]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.phieu', 5))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<span>Giao về chủ</span><span>350\.000</span>#', $html);
        $this->assertMatchesRegularExpression('#<span>Giữ lại cho ca sau</span><span>150\.000</span>#', $html);
    }

    public function test_phieu_ca_cu_khong_co_hai_dong(): void
    {
        Http::fake(['*/admin/ca-lam-viec/5' => Http::response(['data' => ['ca' => $this->caDaDong(),
            'so_quy' => [], 'doanh_thu' => ['order_count' => 0, 'total_revenue' => 0]]]), '*' => Http::response(['data' => []])]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.phieu', 5))->assertOk()->getContent();
        $this->assertStringNotContainsString('Giao về chủ', $html);
    }

    public function test_ca_sau_goi_y_theo_so_giu_lai(): void
    {
        Http::fake(function (Request $r) {
            $u = urldecode($r->url());
            if (str_contains($u, '/admin/ca-lam-viec?') && str_contains($u, 'status=da_dong')) {
                return Http::response(['data' => [$this->caDaDong(['delivered_cash' => 350000, 'kept_cash' => 150000])], 'meta' => ['total' => 1]]);
            }

            return Http::response(['data' => null]);
        });

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index'))->assertOk()->getContent();
        $this->assertStringContainsString('giữ lại trong két <b>150.000 đ</b>', $html);
        $this->assertStringContainsString('"banGiao":150000', $html);
        $this->assertStringContainsString('data-dong-giu', $html);
    }
}
