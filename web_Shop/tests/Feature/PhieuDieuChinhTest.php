<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Phiếu điều chỉnh cho phiếu của ca đã đóng — chuyển adjusts_id, câu lỗi của ô, biên nhận. */
class PhieuDieuChinhTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Quản lý Hùng', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    protected function phieu(array $them = []): array
    {
        return $them + ['type' => 0, 'amount' => 1800000, 'payment_method' => 'cash', 'note' => 'Điều chỉnh PC00016 (chi 2.000.000 → đúng 200.000)', 'category_id' => 4];
    }

    public function test_hai_man_chuyen_adjusts_id(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 90]], 201)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.thu-chi.store'), $this->phieu(['adjusts_id' => '16']))->assertOk();
        $this->withSession($this->phien())->postJson(route('admin.cashbook.store'), $this->phieu(['adjusts_id' => 16]));
        $gui = collect(Http::recorded())->map(fn ($c) => $c[0])->filter(fn (Request $r) => $r->method() === 'POST')->values();
        $this->assertSame([16, 16], $gui->map(fn (Request $r) => $r['adjusts_id'] ?? null)->all());
    }

    public function test_phieu_thuong_khong_gui_adjusts_id(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 90]], 201)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.thu-chi.store'), $this->phieu())->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! array_key_exists('adjusts_id', $r->data()));
    }

    public function test_cau_loi_cua_o_len_message(): void
    {
        $cau = 'Phiếu gốc chưa khoá — sửa thẳng phiếu ấy thay vì lập phiếu điều chỉnh.';
        Http::fake(['*/admin/quay/thu-chi' => Http::response(['success' => false, 'message' => 'Dữ liệu không hợp lệ', 'errors' => ['adjusts_id' => $cau]], 422)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.thu-chi.store'), $this->phieu(['adjusts_id' => 16]))
            ->assertStatus(422)->assertJsonPath('message', $cau);
    }

    public function test_bien_nhan_in_dong_dieu_chinh(): void
    {
        Http::fake(['*/admin/quay/thu-chi/90' => Http::response(['data' => ['id' => 90, 'code' => 'PT00053', 'type' => 0, 'amount' => 1800000, 'payment_method' => 'cash',
            'note' => 'Điều chỉnh', 'category_name' => 'Vận chuyển', 'shift_id' => 12, 'created_at' => '2026-10-22T10:00:00+07:00', 'created_by_name' => 'Thu ngân', 'adjusts_code' => 'PC00016']]),
            '*' => Http::response(['data' => []])]);
        $html = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.bienNhan', 90))->assertOk()->getContent());
        $this->assertStringContainsString('<span>Điều chỉnh cho</span><span>PC00016</span>', $html);
    }
}
