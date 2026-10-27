<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Thu nợ ngay ở khung chi tiết khách của quầy (KB #22): đường đọc đơn còn nợ đi thẳng
 * sang API quầy, và hai màn (Khách hàng, Lịch sử) dùng CHUNG một hộp Thu nợ.
 */
class ThuNoKhungKhachTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu', 'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff'], 'access_areas' => 'thu_ngan'],
        ];
    }

    public function test_doc_don_con_no_qua_duong_quay(): void
    {
        $don = [['id' => 9, 'order_code' => 'DH9', 'con_no' => 60000, 'thu_duoc' => true]];
        Http::fake(['*/admin/orders/pos/khach-hang/128/con-no' => Http::response(['data' => ['tong_con_no' => 60000, 'don' => $don]])]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.crm.conNo', ['id' => 128]))
            ->assertOk()->assertJsonPath('data.tong_con_no', 60000)->assertJsonPath('data.don.0.order_code', 'DH9');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/admin/orders/pos/khach-hang/128/con-no'));
    }

    public function test_loi_api_giu_cau_va_ma(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bạn không có quyền'], 403)]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.crm.conNo', ['id' => 128]))
            ->assertStatus(403)->assertJsonPath('message', 'Bạn không có quyền');
    }

    public function test_man_khach_hang_co_hop_thu_no_chung(): void
    {
        Http::fake([
            '*/admin/orders/pos/khach-hang*' => Http::response(['data' => [['id' => 128, 'full_name' => 'Nguyễn Thị Mai', 'still_in_debt' => 25030000]], 'meta' => ['page' => 1, 'total' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);
        $html = $this->withSession($this->phien())->get(route('thu-ngan.crm.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="hsDebt"'));
        $this->assertStringContainsString('data-debt-url="'.route('thu-ngan.don-hang.thuNo', ['id' => 0]).'"', $html);
        $this->assertStringContainsString('v2/css/pos-v3/thu-no.css', $html);
        $this->assertStringContainsString('window.posThuNo', $html);
        preg_match('#<script type="application/json" id="crDuLieu">(.*?)</script>#s', $html, $m);
        $this->assertSame(route('thu-ngan.crm.conNo', ['id' => 0]), json_decode($m[1], true)['url']['conNo']);
        $this->assertStringNotContainsString('Thu nợ ở Lịch sử', $html, 'thu ngay tại khung, không dẫn sang Lịch sử');
        $this->assertStringContainsString('data-nhanh="debt"', $html, 'số đếm "Nợ nhiều nhất" đổi được tại chỗ');
    }

    public function test_man_lich_su_dung_lai_dung_hop_ay(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0, 'total_pages' => 1]])]);
        $html = $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="hsDebt"'));
        $this->assertStringContainsString('v2/css/pos-v3/thu-no.css', $html);
        $this->assertStringContainsString('window.posThuNo(r,', $html);
    }
}
