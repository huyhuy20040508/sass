<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Mẫu phiếu nhanh: ô soạn ở Cài đặt → Quầy bán hàng, dãy nút ở hộp lập phiếu của quầy. */
class MauPhieuQuayTest extends TestCase
{
    protected const MAC_DINH = '[{"ten":"Trả ship","loai":1,"phan_loai":0,"goi_y":"vận chuyển|giao hàng|ship","mo_ta":"Trả ship đơn ","so_tien":30000}]';

    protected function phien(string $vai = 'admin'): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Quản lý Hùng', 'role' => ['name' => $vai], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    protected function fakeCaiDat(string $gia): void
    {
        Http::fake([
            '*/admin/settings*' => Http::response(['data' => [
                'values' => ['pos_cashbook_templates' => $gia],
                'fields' => [['key' => 'pos_cashbook_templates', 'group' => 'pos', 'type' => 'cashbook_templates', 'label' => 'Mẫu phiếu nhanh ở quầy', 'default' => self::MAC_DINH, 'max_len' => 4000]],
            ]]),
            '*/admin/loai-thu-chi' => Http::response(['data' => [['id' => 24, 'type' => 1, 'name' => 'Vận chuyển, giao hàng'], ['id' => 2, 'type' => 0, 'name' => 'Thu khác']]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    public function test_o_soan_mau_mang_json_mac_dinh_va_danh_muc(): void
    {
        $this->fakeCaiDat(self::MAC_DINH);
        $html = $this->withSession($this->phien())->get(route('admin.settings.page', 'pos'))->assertOk()->getContent();
        $this->assertStringContainsString('<input type="hidden" name="items[pos_cashbook_templates]" id="set_pos_cashbook_templates" value="'.e(self::MAC_DINH).'" data-mau-json>', $html);
        $this->assertStringContainsString('data-mac-dinh="'.e(self::MAC_DINH).'"', $html);
        $this->assertMatchesRegularExpression('#data-loai=\'\[\{"value":"24","label":"V\\\\u1eadn chuy\\\\u1ec3n, giao h\\\\u00e0ng","type":1\}#', $html);
        $this->assertStringContainsString('Khôi phục gợi ý mặc định', $html);
        $this->assertStringContainsString('Tối đa 6 mẫu.', $html);
    }

    public function test_gia_tri_rong_thanh_mang_rong(): void
    {
        $this->fakeCaiDat('');
        $html = $this->withSession($this->phien())->get(route('admin.settings.page', 'pos'))->assertOk()->getContent();
        $this->assertStringContainsString('name="items[pos_cashbook_templates]" id="set_pos_cashbook_templates" value="[]" data-mau-json', $html);
    }

    public function test_luu_gui_nguyen_json(): void
    {
        $this->fakeCaiDat('[]');
        $json = '[{"ten":"Mua đá","loai":1,"phan_loai":24,"mo_ta":"Mua đá","so_tien":20000}]';
        $this->withSession($this->phien())->put(route('admin.settings.update', 'pos'), ['items' => ['pos_cashbook_templates' => $json]]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && ($r['items']['pos_cashbook_templates'] ?? null) === $json);
    }

    public function test_quay_nhan_mau_tu_danh_muc(): void
    {
        $ca = ['id' => 9, 'opened_at' => now()->setTime(7, 58)->toIso8601String(), 'opening_cash' => 0, 'tong_thu' => 0, 'tong_chi' => 0];
        $mau = [['ten' => 'Trả ship', 'loai' => 1, 'phan_loai' => 24, 'mo_ta' => 'Trả ship đơn ', 'so_tien' => 30000]];
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => $ca]),
            '*/admin/quay/thu-chi/danh-muc' => Http::response(['data' => ['phan_loai' => [], 'nhan_vien' => [], 'nguoi_nop' => [], 'mau_phieu' => $mau]]),
            '*/admin/quay/thu-chi*' => Http::response(['data' => [], 'meta' => ['total' => 0, 'ca_dang_mo' => 9, 'co_the_lap' => true]]),
            '*' => Http::response(['data' => []]),
        ]);
        $html = $this->withSession($this->phien('staff'))->get(route('thu-ngan.thu-chi.index'))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="tcDuLieu">(.*?)</script>#s', $html, $m);
        $this->assertSame($mau, json_decode($m[1], true)['danhMuc']['mau_phieu']);
        $this->assertStringContainsString('<div class="tcm-mau" role="group" aria-label="Mẫu phiếu nhanh" data-f-mau hidden></div>', $html);
    }
}
