<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Khách hàng ở quầy: cờ co_the_sua từ meta API → khoá sẵn nút Sửa (KB #21). */
class CrmCoTheSuaTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu', 'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff'], 'access_areas' => 'thu_ngan'],
        ];
    }

    protected function duLieu(array $meta): array
    {
        Http::fake([
            '*/admin/orders/pos/khach-hang*' => Http::response(['data' => [['id' => 128, 'full_name' => 'Nguyễn Thị Mai', 'phone' => '0912345678']], 'meta' => $meta]),
            '*' => Http::response(['data' => []]),
        ]);
        $html = $this->withSession($this->phien())->get(route('thu-ngan.crm.index'))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="crDuLieu">(.*?)</script>#s', $html, $m);

        return json_decode($m[1], true);
    }

    public function test_khong_co_quyen_thi_co_false(): void
    {
        $this->assertFalse($this->duLieu(['page' => 1, 'total' => 1, 'co_the_sua' => false])['coTheSua']);
    }

    public function test_co_quyen_thi_co_true(): void
    {
        $this->assertTrue($this->duLieu(['page' => 1, 'total' => 1, 'co_the_sua' => true])['coTheSua']);
    }

    public function test_api_chua_tra_co_thi_giu_nut_nhu_cu(): void
    {
        $this->assertTrue($this->duLieu(['page' => 1, 'total' => 1])['coTheSua']);
    }
}
