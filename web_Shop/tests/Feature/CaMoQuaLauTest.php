<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Nhắc ca mở quá N giờ (Cài đặt → Quầy bán hàng): Điều phối ca, Bán hàng, Tổng quan. */
class CaMoQuaLauTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Quản lý Hùng', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
            'api.tenant' => ['code' => 'quochuy', 'name' => 'Tiệm Quốc Huy'],
        ];
    }

    /** Ca #9 mở cách đây $gio giờ; ngưỡng ở Cài đặt = $nguong (null = chưa lưu, dùng mặc định 24). */
    protected function fake(float $gio, ?int $nguong = null): void
    {
        $ca = ['id' => 9, 'shop_id' => 2, 'opened_at' => now()->subMinutes((int) round($gio * 60))->toIso8601String(), 'closed_at' => null,
            'opening_cash' => 200000, 'opened_by' => 7, 'opened_by_name' => 'Quản lý Hùng', 'tong_thu' => 0, 'tong_chi' => 0, 'so_don_tien_mat' => 0];
        Http::fake(function (Request $r) use ($ca, $nguong) {
            $u = urldecode($r->url());
            if (str_contains($u, '/admin/settings')) {
                return Http::response(['data' => ['values' => $nguong === null ? [] : ['shift_open_alert_hours' => (string) $nguong]]]);
            }
            if (str_contains($u, '/admin/ca-lam-viec/hien-tai')) {
                return Http::response(['data' => $ca]);
            }
            if (str_contains($u, '/admin/ca-lam-viec/9')) {
                return Http::response(['data' => ['ca' => $ca, 'so_quy' => [], 'doanh_thu' => []]]);
            }
            if (str_contains($u, '/admin/ca-lam-viec?') && str_contains($u, 'status=dang_mo')) {
                return Http::response(['data' => [$ca], 'meta' => ['total' => 1]]);
            }

            return Http::response(['data' => null]);
        });
    }

    public function test_dieu_phoi_ca_hien_dai_nhac_kem_nut_dong_ca(): void
    {
        $this->fake(26);
        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#class="ca-alert ca-alert--warn ca-qua-lau" role="alert">.*?Ca \#9 mở từ hôm qua \d\d:\d\d \(26 giờ\) chưa đóng — đóng ca để chốt két\. <button type="button" class="link-btn" data-act="close">Đóng ca</button>#s', $html);
    }

    public function test_chua_qua_nguong_thi_khong_nhac(): void
    {
        $this->fake(10);
        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('ca-qua-lau" role="alert"', $html);
    }

    public function test_nguong_doc_tu_cai_dat(): void
    {
        $this->fake(26, 30);
        $this->assertStringNotContainsString('ca-qua-lau" role="alert"', $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index'))->getContent());
    }

    public function test_nguong_0_la_tat_nhac(): void
    {
        $this->fake(500, 0);
        $this->assertStringNotContainsString('ca-qua-lau" role="alert"', $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index'))->getContent());
    }

    public function test_ban_hang_hien_dai_nhac_nhung_khong_khoa(): void
    {
        $this->fake(50);
        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<div class="ca-qua-lau" role="alert"><i class="fa-solid fa-clock"></i><span>Ca \#9 mở từ \d\d:\d\d \d\d/\d\d \(2 ngày\) chưa đóng — đóng ca để chốt két\.</span><a href="[^"]*/cashier/shifts">Điều phối ca</a></div>#', $html);
        // Có ca mở thì quầy không bị khoá.
        $this->assertStringContainsString('data-ca-mo="1"', $html);
    }

    public function test_tong_quan_nhac_trong_can_chu_y_va_the_ca_dang_mo(): void
    {
        $this->fake(26);
        $html = $this->withSession($this->phien())->get('/admin/dashboard')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="[^"]*/cashier/shifts\?ca=9" class="db-alert-row">.*?Ca \#9 .*?đã mở 26 giờ chưa chốt#s', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*/cashier/shifts\?ca=9" class="db-ca-lau"><i class="fa-solid fa-clock"></i> Ca \#9 mở từ hôm qua#', $html);
    }
}
