<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Công tắc "Cả ca / Của tôi" ở màn Thu chi quầy. */
class ThuChiCaCaTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff'], 'access_areas' => 'thu_ngan'],
        ];
    }

    protected function fake(bool $coCa = true): void
    {
        $ca = ['id' => 9, 'opened_at' => now()->setTime(7, 58)->toIso8601String(), 'opening_cash' => 0, 'tong_thu' => 0, 'tong_chi' => 0];
        $dong = fn ($id, $by, $ten, $khoa) => ['id' => $id, 'code' => 'PC0000'.$id, 'type' => 1, 'amount' => 50000, 'payment_method' => 'cash', 'category_name' => 'Vận chuyển',
            'payer_name' => '', 'note' => 'Trả ship', 'attachment_url' => '', 'shift_id' => 9, 'created_at' => now()->setTime(10, $id)->toIso8601String(),
            'created_by' => $by, 'created_by_name' => $ten, 'locked' => $khoa, 'locked_reason' => $khoa ? 'phiếu do người khác lập, bạn không sửa hay xoá được' : ''];
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => $coCa ? Http::response(['data' => $ca]) : Http::response(['message' => 'Chưa có ca'], 404),
            '*/admin/quay/thu-chi/danh-muc' => Http::response(['data' => ['phan_loai' => [], 'nhan_vien' => [], 'nguoi_nop' => []]]),
            '*/admin/quay/thu-chi*' => Http::response(['data' => [$dong(11, 7, 'Nhân viên quầy', false), $dong(12, 3, 'Chị Mai trưởng ca', true)],
                'meta' => ['page' => 1, 'page_size' => 50, 'total' => 2, 'total_pages' => 1, 'ca_dang_mo' => $coCa ? 9 : null, 'co_the_lap' => true]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function doc(): array
    {
        return collect(Http::recorded())->map(fn ($c) => $c[0])
            ->filter(fn ($r) => $r->method() === 'GET' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/admin/quay/thu-chi'))
            ->map(function ($r) { parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q); return $q; })->values()->all();
    }

    protected function html(string $q = ''): string
    {
        return preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.index').$q)->assertOk()->getContent());
    }

    public function test_mac_dinh_cua_toi(): void
    {
        $this->fake();
        $html = $this->html();
        $this->assertArrayNotHasKey('pham_vi', $this->doc()[0]);
        $this->assertMatchesRegularExpression('#aria-pressed="true"><i class="fa-solid fa-user"></i>Của tôi</a>#', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*pham_vi=ca[^"]*" aria-pressed="false" title="Mọi phiếu của ca \#9 — phiếu người khác chỉ xem"><i class="fa-solid fa-users"></i>Cả ca</a>#', $html);
    }

    public function test_ca_ca_gui_pham_vi_va_hien_ten_nguoi_lap(): void
    {
        $this->fake();
        $html = $this->html('?ky=ca&pham_vi=ca');
        $q = $this->doc()[0];
        $this->assertSame(['ca', '9'], [$q['pham_vi'] ?? null, $q['shift_id'] ?? null]);
        $this->assertMatchesRegularExpression('#aria-pressed="true" title="Mọi phiếu của ca \#9#', $html);
        $this->assertStringContainsString('<span class="tc-by" title="Người lập — chỉ xem">Chị Mai trưởng ca</span>', $html);
        $this->assertStringNotContainsString('tc-by" title="Người lập — chỉ xem">Nhân viên quầy', $html);
        // Chuyển trang / tab giữ chế độ Cả ca.
        $this->assertMatchesRegularExpression('#class="tc-tab" role="tab" href="[^"]*pham_vi=ca#', $html);
    }

    public function test_ky_khac_hoac_tim_kiem_thi_ve_cua_toi(): void
    {
        $this->fake();
        $this->html('?ky=hom-nay&pham_vi=ca');
        $this->html('?pham_vi=ca&q=PC00');
        foreach ($this->doc() as $q) {
            $this->assertArrayNotHasKey('pham_vi', $q);
        }
    }

    public function test_chua_mo_ca_thi_khoa_ca_ca(): void
    {
        $this->fake(coCa: false);
        $html = $this->html('?pham_vi=ca');
        $this->assertStringContainsString('<button type="button" disabled title="Chưa mở ca"><i class="fa-solid fa-users"></i>Cả ca</button>', $html);
        $this->assertArrayNotHasKey('pham_vi', $this->doc()[0]);
    }
}
