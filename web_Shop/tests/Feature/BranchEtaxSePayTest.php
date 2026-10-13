<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quản lý chi nhánh → Kết nối hoá đơn điện tử qua SePay.
 *
 * Khách dùng SePay chỉ dán tên đăng nhập / mật khẩu từ trang của SePay; mã số
 * thuế và mã đơn vị là ô riêng của M-Invoice. Soi: ô chọn có SePay, ô riêng của
 * M-Invoice mang data-ncc để JS ẩn, và lượt kết nối SePay không bị bắt MST.
 */
class BranchEtaxSePayTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    public function test_hop_ket_noi_co_sepay_va_bo_o_rieng(): void
    {
        Http::fake([
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 1, 'code' => 'CN01', 'name' => 'Chi nhánh trung tâm', 'is_active' => true,
                    'etax' => ['provider' => 'sepay', 'tax_code' => '0312345678']],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phienQuanTri())
            ->get('/admin/branches')
            ->assertOk()
            ->assertSee('id="etax_supplier_sepay"', false)
            ->assertDontSee('connect_account_ref', false)
            ->assertSee('<div class="col-12 col-md-3 mt-3" data-ncc="minvoice">', false)
            ->assertSee('API xuất hóa đơn điện tử (tab Live)', false)
            // Cột HĐĐT gọi đúng tên nhà cung cấp của chi nhánh.
            ->assertSee('Đang dùng SePay', false);
    }

    public function test_ket_noi_sepay_chi_can_dang_nhap(): void
    {
        Http::fake([
            '*/admin/chi-nhanh/1/etax' => Http::response(['data' => ['id' => 1, 'provider' => 'sepay']]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phienQuanTri())
            ->postJson('/admin/branches/1/etax', [
                'provider' => 'sepay',
                'tax_code' => '',
                'username' => ' EINV-TEST-ABC ',
                'password' => ' bi mat ',
            ])
            ->assertOk();

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/admin/chi-nhanh/1/etax')
            && $req->method() === 'POST'
            && $req['provider'] === 'sepay'
            && $req['tax_code'] === ''
            && $req['username'] === 'EINV-TEST-ABC'
            && $req['account_ref'] === ''
            // Secret giữ nguyên khoảng trắng — có thể là một phần của nó.
            && $req['password'] === ' bi mat ');
    }

    public function test_minvoice_van_bat_ma_so_thue(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phienQuanTri())
            ->postJson('/admin/branches/1/etax', ['provider' => 'minvoice', 'username' => 'u', 'password' => 'p'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_code');

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/etax'));
    }

    public function test_nha_cung_cap_la_bi_chan(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phienQuanTri())
            ->postJson('/admin/branches/1/etax', [
                'provider' => 'viettel',
                'tax_code' => '0312345678',
                'username' => 'u',
                'password' => 'p',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/etax'));
    }
}
