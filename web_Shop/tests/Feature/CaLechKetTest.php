<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Đóng ca lệch két: ô ngưỡng ở Cài đặt → Quầy bán hàng, link "Mở tờ ca" trong
 * chuông, thẻ "Ca lệch két · 7 ngày" ở Tổng quan.
 */
class CaLechKetTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-admin',
            'api.refresh_token' => 'refresh-admin',
            'api.user' => ['id' => 7, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
            'api.tenant' => ['code' => 'quochuy', 'name' => 'Tiệm Quốc Huy'],
        ];
    }

    /** Ca lệch: 3 ca trong 7 ngày, ngưỡng 50.000. */
    protected function fakeTongQuan(?array $caLech, int $nguong = 50000): void
    {
        Http::fake(function (Request $req) use ($caLech, $nguong) {
            $url = urldecode($req->url());
            if (str_contains($url, '/admin/ca-lam-viec') && str_contains($url, 'lech=1')) {
                return $caLech === null ? Http::response(['message' => 'Không có quyền'], 403)
                    : Http::response(['data' => $caLech, 'meta' => ['total' => count($caLech)]]);
            }
            if (str_contains($url, '/admin/settings')) {
                return Http::response(['data' => ['values' => ['shift_diff_alert_threshold' => (string) $nguong]]]);
            }
            if (str_contains($url, '/admin/chi-nhanh')) {
                return Http::response(['data' => [['id' => 2, 'name' => 'Chi nhánh trung tâm', 'is_active' => true]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    protected function ca(int $id, float $lech, string $truc = '', string $mo = 'Quản trị'): array
    {
        return ['id' => $id, 'shop_id' => 2, 'difference' => $lech, 'closed_at' => '2026-10-14T15:02:00+07:00',
            'employee_name' => $truc, 'opened_by_name' => $mo];
    }

    public function test_the_ca_lech_7_ngay_dem_tong_va_vuot_nguong(): void
    {
        $this->fakeTongQuan([$this->ca(21, -120000, 'Lan Thu Ngân'), $this->ca(19, 30000), $this->ca(18, 80000, 'Hùng')]);

        $html = $this->withSession($this->phien())->get('/admin/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Ca lệch két · 7 ngày', $html);
        $this->assertStringContainsString('>3 ca</span>', $html);
        $this->assertStringContainsString('Thiếu <b class="text-danger">−120.000 đ</b>', $html);
        $this->assertStringContainsString('Dư <b class="text-success">+110.000 đ</b>', $html);
        $this->assertStringContainsString('2 ca quá 50.000 đ', $html);
        // Người trực ưu tiên employee_name, ca cũ lùi về người mở; ca vượt ngưỡng có vạch đỏ; link mở tờ ca.
        $this->assertMatchesRegularExpression('#href="[^"]*/cashier/shifts\?ca=21" class="db-lech-dong is-vuot">.*?Lan Thu Ngân#s', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*/cashier/shifts\?ca=19" class="db-lech-dong ">.*?Quản trị#s', $html);
        $this->assertStringContainsString('lech=1', $html);

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'lech=1')
            && str_contains(urldecode($r->url()), 'status=da_dong')
            && str_contains(urldecode($r->url()), 'from_date='.now()->subDays(6)->format('Y-m-d')));
    }

    public function test_khong_ca_lech_thi_bao_khop(): void
    {
        $this->fakeTongQuan([]);
        $html = $this->withSession($this->phien())->get('/admin/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('7 ngày qua mọi ca đều khớp két.', $html);
    }

    public function test_khong_co_quyen_xem_ca_thi_an_the(): void
    {
        $this->fakeTongQuan(null);
        $html = $this->withSession($this->phien())->get('/admin/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('Ca lệch két · 7 ngày', $html);
    }

    public function test_chuong_co_link_mo_to_ca_cho_thong_bao_lech(): void
    {
        $this->fakeTongQuan([]);
        $html = $this->withSession($this->phien())->get('/admin/dashboard')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#const CHUONG_URL_CA = "[^"]*\\\\/cashier\\\\/shifts\?ca=__ID__"#', $html);
        $this->assertStringContainsString("n.type === 'shift_diff' && du.shift_id", $html);
    }

    public function test_o_nguong_o_cai_dat_quay_ban_hang(): void
    {
        Http::fake(['*/admin/settings*' => Http::response(['data' => [
            'values' => ['shift_diff_alert_threshold' => '50000'],
            'fields' => [['key' => 'shift_diff_alert_threshold', 'group' => 'pos', 'type' => 'number', 'label' => 'Báo quản lý khi đóng ca lệch két quá (đ)', 'required' => true]],
        ]]), '*' => Http::response(['data' => []])]);

        $html = $this->withSession($this->phien())->get(route('admin.settings.page', 'pos'))->assertOk()->getContent();
        $this->assertStringContainsString('Báo quản lý khi đóng ca lệch két quá (đ)', $html);
        $this->assertStringContainsString('Đặt 0 = lệch một đồng cũng báo.', $html);
    }
}
