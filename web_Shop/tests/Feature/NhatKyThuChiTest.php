<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Nhật ký sửa / xoá phiếu thu chi: lý do bắt buộc ở cả hai màn, đường đọc nhật ký, dòng trong chi tiết ca. */
class NhatKyThuChiTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Quản lý Hùng', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    protected function phieu(): array
    {
        return ['type' => 1, 'amount' => 320000, 'payment_method' => 'cash', 'note' => 'Trả ship', 'category_id' => 4];
    }

    public function test_sua_thieu_ly_do_bi_chan_ca_hai_man(): void
    {
        Http::fake();
        $this->withSession($this->phien())->putJson(route('thu-ngan.thu-chi.update', 41), $this->phieu())
            ->assertStatus(422)->assertJsonValidationErrors(['reason' => 'Ghi lý do sửa phiếu.']);
        $this->withSession($this->phien())->putJson(route('admin.cashbook.update', 41), $this->phieu())
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    }

    public function test_xoa_thieu_ly_do_bi_chan_ca_hai_man(): void
    {
        Http::fake();
        $this->withSession($this->phien())->deleteJson(route('thu-ngan.thu-chi.destroy', 41))
            ->assertStatus(422)->assertJsonValidationErrors(['reason' => 'Ghi lý do xoá phiếu.']);
        $this->withSession($this->phien())->deleteJson(route('admin.cashbook.destroy', 41))->assertStatus(422);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_ly_do_di_kem_sua_va_xoa(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 41]])]);
        $this->withSession($this->phien())->putJson(route('thu-ngan.thu-chi.update', 41), $this->phieu() + ['reason' => ' Gõ thiếu số 0 '])->assertOk();
        $this->withSession($this->phien())->deleteJson(route('thu-ngan.thu-chi.destroy', 41), ['reason' => 'Lập trùng'])->assertOk();
        $this->withSession($this->phien())->deleteJson(route('admin.cashbook.destroy', 42), ['reason' => 'Nhầm chi nhánh'])->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['reason'] === 'Gõ thiếu số 0');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/admin/quay/thu-chi/41') && $r['reason'] === 'Lập trùng');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/admin/thu-chi/42') && $r['reason'] === 'Nhầm chi nhánh');
    }

    public function test_doc_nhat_ky_hai_duong(): void
    {
        $ds = [['id' => 1, 'phieu_id' => 41, 'action' => 'thu_chi.sua', 'at' => '2026-10-18T11:33:00+07:00', 'by_name' => 'Quản trị viên', 'reason' => 'Gõ thiếu số 0', 'changes' => ['so_tien_cu' => 200000, 'so_tien_moi' => 320000]]];
        Http::fake(['*/admin/quay/thu-chi/41/nhat-ky' => Http::response(['data' => $ds]), '*/admin/thu-chi/41/nhat-ky' => Http::response(['data' => $ds]), '*/admin/thu-chi/9/nhat-ky' => Http::response(['message' => 'Không tìm thấy'], 404)]);
        $this->withSession($this->phien())->getJson(route('thu-ngan.thu-chi.nhatKy', 41))->assertOk()->assertJsonPath('data.0.reason', 'Gõ thiếu số 0');
        $this->withSession($this->phien())->getJson(route('admin.cashbook.nhatKy', 41))->assertOk()->assertJsonPath('data.0.changes.so_tien_moi', 320000);
        $this->withSession($this->phien())->getJson(route('admin.cashbook.nhatKy', 9))->assertNotFound();
    }

    public function test_chi_tiet_ca_bao_phieu_bi_sua_xoa(): void
    {
        $ca = ['id' => 5, 'shop_id' => 2, 'opened_at' => '2026-10-21T07:30:00+07:00', 'closed_at' => null, 'opening_cash' => 0, 'opened_by' => 7, 'opened_by_name' => 'Quản lý Hùng', 'tong_thu' => 0, 'tong_chi' => 0];
        $sua = [
            ['id' => 2, 'phieu_id' => 42, 'action' => 'thu_chi.xoa', 'at' => '2026-10-21T10:05:00+07:00', 'by_name' => 'Thu ngân Lan', 'reason' => 'Lập trùng', 'changes' => ['ma' => 'PC00042', 'loai' => 1, 'so_tien_cu' => 50000]],
            ['id' => 1, 'phieu_id' => 41, 'action' => 'thu_chi.sua', 'at' => '2026-10-21T09:33:00+07:00', 'by_name' => 'Quản lý Hùng', 'reason' => 'Gõ thiếu số 0', 'changes' => ['ma' => 'PC00041', 'loai' => 1, 'so_tien_cu' => 20000, 'so_tien_moi' => 200000]],
        ];
        Http::fake(function (Request $r) use ($ca, $sua) {
            $u = urldecode($r->url());
            if (str_contains($u, '/admin/ca-lam-viec/5')) {
                return Http::response(['data' => ['ca' => $ca, 'so_quy' => [], 'doanh_thu' => [], 'thu_chi_sua' => $sua]]);
            }
            if (str_contains($u, '/admin/ca-lam-viec?')) {
                return Http::response(['data' => [$ca], 'meta' => ['total' => 1]]);
            }

            return Http::response(['data' => null]);
        });
        $html = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index', ['ca' => 5]))->assertOk()->getContent());
        $this->assertStringContainsString('2 lượt sửa / xoá phiếu thu chi trong ca. <button type="button" class="link-btn" data-open="caThuChiSua">Xem</button>', $html);
        $this->assertStringContainsString('<b>Xoá PC00042</b><span>10:05 · Thu ngân Lan</span>', $html);
        $this->assertMatchesRegularExpression('#<b>Sửa PC00041</b>.*?Chi <s>20\.000 đ</s> → <b>200\.000 đ</b>.*?Lý do: Gõ thiếu số 0#', $html);
    }
}
