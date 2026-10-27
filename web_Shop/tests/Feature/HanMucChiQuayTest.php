<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Hạn mức phiếu chi ở quầy: báo trước, hộp duyệt, chuyển tài khoản duyệt, mã lỗi đi nguyên. */
class HanMucChiQuayTest extends TestCase
{
    protected function phien(string $vai = 'staff'): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => $vai], 'access_areas' => $vai === 'admin' ? 'quan_ly,thu_ngan' : 'thu_ngan'],
        ];
    }

    protected function phieu(): array
    {
        return ['type' => 1, 'amount' => 2500000, 'payment_method' => 'cash', 'note' => 'Tạm ứng lương', 'category_id' => 4];
    }

    protected function trang(string $vai, string $han): array
    {
        $ca = ['id' => 9, 'opened_at' => now()->setTime(7, 58)->toIso8601String(), 'opening_cash' => 0, 'tong_thu' => 0, 'tong_chi' => 0];
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => $ca]),
            '*/admin/settings*' => Http::response(['data' => ['values' => ['pos_cashbook_expense_limit' => $han]]]),
            '*/admin/quay/thu-chi/danh-muc' => Http::response(['data' => ['phan_loai' => [], 'nhan_vien' => [], 'nguoi_nop' => []]]),
            '*/admin/quay/thu-chi*' => Http::response(['data' => [], 'meta' => ['total' => 0, 'ca_dang_mo' => 9, 'co_the_lap' => true]]),
            '*' => Http::response(['data' => []]),
        ]);
        $html = $this->withSession($this->phien($vai))->get(route('thu-ngan.thu-chi.index'))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="tcDuLieu">(.*?)</script>#s', $html, $m);

        return [$html, json_decode(html_entity_decode($m[1] ?? '{}'), true)];
    }

    public function test_trang_mang_han_muc_va_hop_duyet(): void
    {
        [$html, $d] = $this->trang('staff', '1000000');
        $this->assertSame(1000000.0, (float) $d['hanMucChi']);
        $this->assertFalse($d['laChu']);
        $this->assertStringContainsString('id="tcDuyet"', $html);
        $this->assertStringContainsString('autocomplete="new-password"', $html);
        $this->assertStringContainsString('data-f-han hidden', $html);
    }

    public function test_chu_thi_khong_bao_truoc(): void
    {
        [, $d] = $this->trang('admin', '1000000');
        $this->assertTrue($d['laChu']);
    }

    public function test_gui_kem_tai_khoan_duyet_khi_co(): void
    {
        Http::fake(['*/admin/quay/thu-chi' => Http::response(['data' => ['id' => 55, 'code' => 'PC000055', 'approved_by_name' => 'Chủ tiệm']], 201)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.thu-chi.store'), $this->phieu() + ['approver_username' => ' quantri ', 'approver_password' => 'mk bí mật'])
            ->assertOk()->assertJsonPath('data.approved_by_name', 'Chủ tiệm');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['approver_username'] === 'quantri' && $r['approver_password'] === 'mk bí mật');
    }

    public function test_khong_duyet_thi_khong_gui_hai_o(): void
    {
        Http::fake(['*/admin/quay/thu-chi' => Http::response(['data' => ['id' => 55]], 201)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.thu-chi.store'), $this->phieu() + ['approver_username' => '', 'approver_password' => ''])->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! array_key_exists('approver_username', $r->data()) && ! array_key_exists('approver_password', $r->data()));
    }

    public function test_ma_can_duyet_va_khoa_di_nguyen(): void
    {
        $cau = 'Phiếu chi 2.500.000₫ vượt hạn mức 1.000.000₫ ở quầy — cần chủ hoặc quản trị viên nhập tài khoản để duyệt.';
        Http::fake(['*/admin/quay/thu-chi/12' => Http::response(['success' => false, 'message' => 'Nhập sai tài khoản duyệt quá nhiều lần — thử lại sau 15 phút.', 'errors' => ['ma' => 'SAI_DUYET_CHI']], 429),
            '*/admin/quay/thu-chi' => Http::response(['success' => false, 'message' => $cau, 'errors' => ['ma' => 'CAN_DUYET_CHI']], 422)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.thu-chi.store'), $this->phieu())
            ->assertStatus(422)->assertJsonPath('errors.ma', 'CAN_DUYET_CHI')->assertJsonPath('message', $cau);
        $this->withSession($this->phien())->putJson(route('thu-ngan.thu-chi.update', 12), $this->phieu() + ['approver_username' => 'quantri', 'approver_password' => 'x', 'reason' => 'Sửa số'])
            ->assertStatus(429)->assertJsonPath('errors.ma', 'SAI_DUYET_CHI');
    }

    public function test_o_cai_dat_la_o_tien(): void
    {
        Http::fake(['*/admin/settings*' => Http::response(['data' => [
            'values' => ['pos_cashbook_expense_limit' => '2000000'],
            'fields' => [['key' => 'pos_cashbook_expense_limit', 'group' => 'pos', 'type' => 'number', 'label' => 'Hạn mức phiếu chi ở quầy (đ)', 'required' => true]],
        ]]), '*' => Http::response(['data' => []])]);
        $html = $this->withSession($this->phien('admin'))->get(route('admin.settings.page', 'pos'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#name="items\[pos_cashbook_expense_limit\]"[^>]*data-money#s', $html);
        $this->assertStringContainsString('Đặt 0 = không giới hạn.', $html);

        $this->withSession($this->phien('admin'))->put(route('admin.settings.update', 'pos'), ['items' => ['pos_cashbook_expense_limit' => '2.000.000']]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && ($r['items']['pos_cashbook_expense_limit'] ?? null) === '2000000');
    }
}
