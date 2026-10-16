<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hộp Mở ca: người trực két (khác người mở máy), giờ mở bù khi quên; phiếu kết
 * ca in cả hai người.
 */
class MoCaNguoiTrucTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Quản lý Hùng', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    protected function la(string $duoi): callable
    {
        return fn ($req) => $req->method() === 'POST' && str_ends_with((string) parse_url($req->url(), PHP_URL_PATH), $duoi);
    }

    public function test_gui_nguoi_truc_va_gio_mo_sang_api(): void
    {
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response(['data' => ['id' => 3]], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 0, 'employee_id' => 12, 'opened_at' => '2026-10-14T07:30'])
            ->assertOk();

        Http::assertSent(fn ($req) => ($this->la('/admin/ca-lam-viec/mo'))($req)
            && $req['employee_id'] === 12 && $req['opened_at'] === '2026-10-14 07:30');
    }

    public function test_khong_dung_gio_thi_khong_gui_gio(): void
    {
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response(['data' => ['id' => 3]], 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 0])->assertOk();

        Http::assertSent(fn ($req) => ($this->la('/admin/ca-lam-viec/mo'))($req)
            && ! array_key_exists('opened_at', $req->data()) && ! array_key_exists('employee_id', $req->data()));
    }

    public function test_gio_sai_dang_chan_truoc_khi_goi_api(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 0, 'opened_at' => 'hôm qua 7h'])
            ->assertStatus(422)->assertJsonPath('errors.opened_at.0', 'Giờ mở không đúng dạng.');

        Http::assertNotSent($this->la('/admin/ca-lam-viec/mo'));
    }

    public function test_loi_o_cua_api_len_thanh_cau_bao(): void
    {
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response([
            'success' => false, 'message' => 'Dữ liệu không hợp lệ',
            'errors' => ['opened_at' => 'Ca trước đóng lúc 07:45 14/10 — giờ mở không được sớm hơn.'],
        ], 422)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 0, 'opened_at' => '2026-10-14T07:30'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ca trước đóng lúc 07:45 14/10 — giờ mở không được sớm hơn.')
            ->assertJsonPath('errors.opened_at', 'Ca trước đóng lúc 07:45 14/10 — giờ mở không được sớm hơn.');
    }

    public function test_phieu_ket_ca_in_nguoi_truc_va_nguoi_mo_may(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/5' => Http::response(['data' => [
                'ca' => ['id' => 5, 'opened_at' => '2026-10-14T07:30:00+07:00', 'closed_at' => '2026-10-14T15:00:00+07:00', 'opening_cash' => 0,
                    'opened_by' => 7, 'closed_by' => 7, 'opened_by_name' => 'Quản lý Hùng', 'closed_by_name' => 'Quản lý Hùng',
                    'employee_id' => 12, 'employee_name' => 'Lan Thu Ngân', 'tong_thu' => 0, 'tong_chi' => 0, 'expected_cash' => 0, 'counted_cash' => 0, 'difference' => 0],
                'so_quy' => [], 'doanh_thu' => ['order_count' => 0, 'total_revenue' => 0],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.phieu', 5))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<span>Người trực</span><span>Lan Thu Ngân</span>#', $html);
        $this->assertMatchesRegularExpression('#<span>Người mở máy</span><span>Quản lý Hùng</span>#', $html);
    }

    public function test_phieu_ca_cu_chua_co_nguoi_truc_lui_ve_nguoi_mo(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/4' => Http::response(['data' => [
                'ca' => ['id' => 4, 'opened_at' => '2026-10-13T07:30:00+07:00', 'closed_at' => '2026-10-13T15:00:00+07:00', 'opening_cash' => 0,
                    'opened_by' => 7, 'opened_by_name' => 'Quản lý Hùng', 'employee_id' => null, 'tong_thu' => 0, 'tong_chi' => 0, 'expected_cash' => 0, 'counted_cash' => 0, 'difference' => 0],
                'so_quy' => [], 'doanh_thu' => ['order_count' => 0, 'total_revenue' => 0],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.phieu', 4))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<span>Người trực</span><span>Quản lý Hùng</span>#', $html);
    }

    public function test_o_khoa_ban_hang_chon_san_ho_so_cua_nguoi_dang_nhap(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => null]),
            '*/admin/ca-lam-viec/nhan-vien-truc' => Http::response(['data' => [
                ['id' => 12, 'code' => 'NV12', 'name' => 'Lan Thu Ngân', 'user_id' => 20],
                ['id' => 15, 'code' => 'NV15', 'name' => 'Hùng Quản Lý', 'user_id' => 7],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<option value="15"\s+selected>Hùng Quản Lý · NV15</option>#', $html);
        $this->assertStringContainsString('<option value="12" >Lan Thu Ngân · NV12</option>', $html);
        $this->assertStringContainsString('data-mo-gio', $html);
        $this->assertStringNotContainsString('tài khoản đang đăng nhập', $html);
    }

    public function test_chua_co_ho_so_thi_dong_dau_la_tai_khoan_dang_nhap(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => null]),
            '*/admin/ca-lam-viec/nhan-vien-truc' => Http::response(['data' => [['id' => 12, 'code' => '', 'name' => 'Lan Thu Ngân', 'user_id' => 20]]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="" selected>Quản lý Hùng — tài khoản đang đăng nhập</option>', $html);
    }
}
