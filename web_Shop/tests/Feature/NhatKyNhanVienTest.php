<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Nhân sự → xem hồ sơ → tab Nhật ký (mở / đóng ca). */
class NhatKyNhanVienTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-admin',
            'api.refresh_token' => 'refresh-admin',
            'api.user' => ['id' => 7, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly'],
            'api.tenant' => ['code' => 'quochuy', 'name' => 'Tiệm Quốc Huy'],
        ];
    }

    public function test_chuyen_tiep_nhat_ky_kem_trang(): void
    {
        $data = ['tong' => ['so_ca' => 1, 'so_lan_lech' => 1, 'tong_thieu' => -50000, 'tong_du' => 0],
            'items' => [['event' => 'shift_close', 'shift_id' => 18, 'amount' => 250000, 'difference' => -50000]], 'total' => 1, 'page' => 2];
        Http::fake(['*/admin/nhan-su/12/nhat-ky*' => Http::response(['data' => $data])]);

        $this->withSession($this->phien())->getJson('/admin/staff/12/log?page=2')
            ->assertOk()->assertJsonPath('data.tong.so_lan_lech', 1)->assertJsonPath('data.items.0.shift_id', 18);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/nhan-su/12/nhat-ky') && (int) $req['page'] === 2);
    }

    public function test_api_tu_choi_thi_tra_dung_ma(): void
    {
        Http::fake(['*/admin/nhan-su/12/nhat-ky*' => Http::response(['message' => 'Bạn không có quyền'], 403)]);

        $this->withSession($this->phien())->getJson('/admin/staff/12/log')
            ->assertStatus(403)->assertJsonPath('message', 'Bạn không có quyền');
    }

    public function test_hop_xem_ho_so_co_tab_nhat_ky(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $html = $this->withSession($this->phien())->get('/admin/staff')->assertOk()->getContent();
        $this->assertStringContainsString('data-bs-target="#logEmployeeView" type="button" role="tab" id="nkTab">Nhật ký</button>', $html);
        $this->assertStringContainsString('<th>Thời gian</th><th>Sự kiện</th><th>Chi nhánh</th><th>Ca</th>', $html);
        $this->assertStringContainsString("URL_BASE + '/' + nk.id + '/log'", $html);
    }
}
