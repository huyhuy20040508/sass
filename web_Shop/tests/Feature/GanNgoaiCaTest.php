<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Gắn khoản tiền mặt "ngoài ca" vào ca đang mở (Điều phối ca → hộp Tiền mặt ngoài ca). */
class GanNgoaiCaTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Quản lý Hùng', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
            'api.tenant' => ['code' => 'quochuy', 'name' => 'Tiệm Quốc Huy'],
        ];
    }

    /**
     * Ca #9 đang mở (hoặc không), ca trước đóng lúc 08:00 hôm nay. Ngoài ca có 3 khoản:
     * #31 lúc 07:30 (trước khi ca trước đóng), #32 10:15, #33 11:40.
     */
    protected function fake(bool $coCa = true, bool $coQuyen = true, array $soQuy = []): void
    {
        $ngay = now()->format('Y-m-d');
        $ca = ['id' => 9, 'shop_id' => 2, 'opened_at' => "{$ngay}T09:00:00+07:00", 'closed_at' => null,
            'opening_cash' => 200000, 'opened_by' => 7, 'opened_by_name' => 'Quản lý Hùng', 'tong_thu' => 0, 'tong_chi' => 0, 'so_don_tien_mat' => 0];
        $dong = fn ($id, $gio, $tien, $ly) => ['id' => $id, 'shop_id' => 2, 'shift_id' => null, 'direction' => 'in', 'amount' => $tien,
            'reason' => $ly, 'reference_type' => 'order', 'reference_code' => 'DH0000'.$id, 'created_at' => "{$ngay}T{$gio}:00+07:00"];
        $ngoai = [$dong(31, '07:30', 50000, 'Bán hàng sớm'), $dong(32, '10:15', 120000, 'Bán hàng'), $dong(33, '11:40', 60000, 'Bán hàng')];
        Http::fake(function (Request $r) use ($ca, $coCa, $coQuyen, $ngoai, $ngay, $soQuy) {
            $u = urldecode($r->url());
            if (str_contains($u, '/admin/ca-lam-viec/ngoai-ca/gan')) {
                return Http::response(['success' => true, 'message' => 'Đã gắn 2 khoản vào ca #9.', 'data' => $ca]);
            }
            if (str_contains($u, '/admin/ca-lam-viec/ngoai-ca')) {
                return Http::response(['data' => $ngoai, 'meta' => ['co_the_gan' => $coQuyen, 'gan_tu' => "{$ngay}T08:00:00+07:00"]]);
            }
            if (str_contains($u, '/admin/ca-lam-viec/hien-tai')) {
                return Http::response(['data' => $coCa ? $ca : null]);
            }
            if (str_contains($u, '/admin/ca-lam-viec/9')) {
                return Http::response(['data' => ['ca' => $ca, 'so_quy' => $soQuy, 'doanh_thu' => []]]);
            }
            if (str_contains($u, '/admin/ca-lam-viec?') && str_contains($u, 'status=dang_mo')) {
                return Http::response(['data' => $coCa ? [$ca] : [], 'meta' => ['total' => $coCa ? 1 : 0]]);
            }

            return Http::response(['data' => null]);
        });
    }

    protected function trang(): string
    {
        return $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index'))->assertOk()->getContent();
    }

    public function test_moi_dong_sau_moc_co_nut_gan_va_co_gan_tat_ca(): void
    {
        $this->fake();
        $html = $this->trang();
        $this->assertStringContainsString('data-gan-ngoai="32">Gắn vào ca #9</button>', $html);
        $this->assertStringContainsString('data-gan-ngoai="33">Gắn vào ca #9</button>', $html);
        // #31 phát sinh trước khi ca trước đóng → không có nút, ghi rõ vì sao.
        $this->assertStringNotContainsString('data-gan-ngoai="31"', $html);
        $this->assertStringContainsString('Trước khi ca trước đóng — thuộc ca trước, không gắn được', $html);
        $this->assertStringContainsString('data-gan-ngoai="32,33">Gắn tất cả (2) vào ca #9</button>', $html);
    }

    public function test_khong_quyen_so_quy_sua_thi_khong_co_nut(): void
    {
        $this->fake(coQuyen: false);
        $html = $this->trang();
        $this->assertStringNotContainsString('data-gan-ngoai=', $html);
        $this->assertStringContainsString('Cần quyền <b>Sổ quỹ · Sửa</b>', $html);
    }

    public function test_chua_mo_ca_thi_khong_co_nut(): void
    {
        $this->fake(coCa: false);
        $html = $this->trang();
        $this->assertStringNotContainsString('data-gan-ngoai=', $html);
        $this->assertStringContainsString('Mở ca rồi mới gắn được các khoản này vào ca.', $html);
    }

    public function test_so_quy_ghi_ro_ai_gan_luc_nao(): void
    {
        $ngay = now()->format('Y-m-d');
        $this->fake(soQuy: [['id' => 32, 'shift_id' => 9, 'direction' => 'in', 'amount' => 120000, 'reason' => 'Bán hàng',
            'reference_type' => 'order', 'reference_code' => 'DH000032', 'created_at' => "{$ngay}T10:15:00+07:00",
            'attached_by' => 7, 'attached_at' => "{$ngay}T12:05:00+07:00", 'attached_by_name' => 'Quản lý Hùng']]);
        $html = $this->trang();
        $this->assertStringContainsString('<small class="ca-gan-sau" title="Khoản ngoài ca, gắn vào ca sau">Gắn sau · Quản lý Hùng · 12:05</small>', $html);
    }

    public function test_gan_chuyen_dung_ids_len_api(): void
    {
        $this->fake();
        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.ganNgoaiCa'), ['ids' => ['32', 33]])
            ->assertOk()->assertJsonPath('data.id', 9);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/ca-lam-viec/ngoai-ca/gan')
            && $r->method() === 'POST' && $r['ids'] === [32, 33]);
    }

    public function test_gan_rong_bi_chan_truoc_khi_goi_api(): void
    {
        $this->fake();
        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.ganNgoaiCa'), ['ids' => []])
            ->assertStatus(422)->assertJsonPath('message', 'Chọn ít nhất một khoản để gắn.');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/ngoai-ca/gan'));
    }

    public function test_loi_api_tra_nguyen_cau(): void
    {
        Http::fake(['*/admin/ca-lam-viec/ngoai-ca/gan' => Http::response(['success' => false,
            'message' => 'Có khoản đã được gắn vào ca hoặc không thuộc chi nhánh này — tải lại danh sách rồi thử lại.'], 422)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.ca-lam-viec.ganNgoaiCa'), ['ids' => [32]])
            ->assertStatus(422)->assertJsonPath('message', 'Có khoản đã được gắn vào ca hoặc không thuộc chi nhánh này — tải lại danh sách rồi thử lại.');
    }
}
