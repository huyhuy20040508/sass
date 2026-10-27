<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quà sinh nhật ở quầy (KB #24): nút Tạo mã / Đã chúc đi thẳng sang API quầy, 409
 * giữ mốc cũ, danh sách Sinh nhật 7 ngày bỏ khách đã chúc, Cài đặt có khối mẫu mã.
 */
class QuaSinhNhatQuayTest extends TestCase
{
    protected function phien(string $vaiTro = 'staff'): array
    {
        return [
            'api.access_token' => 'token-thu', 'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => $vaiTro], 'access_areas' => $vaiTro === 'admin' ? 'quan_ly,thu_ngan' : 'thu_ngan'],
        ];
    }

    public function test_tao_ma_tra_ma_moi(): void
    {
        Http::fake(['*/admin/orders/pos/khach-hang/128/ma-sinh-nhat' => Http::response(['data' => ['id' => 1, 'voucher' => ['code' => 'SNABC234']]], 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.tangMaSinhNhat', ['id' => 128]))
            ->assertOk()->assertJsonPath('data.voucher.code', 'SNABC234');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/admin/orders/pos/khach-hang/128/ma-sinh-nhat'));
    }

    public function test_da_chuc_truoc_do_giu_moc_cu(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Khách đã được chúc mùa sinh nhật này', 'data' => ['created_at' => '2026-10-20T09:00:00+07:00', 'voucher' => ['code' => 'SNOLD777']]], 409)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.tangMaSinhNhat', ['id' => 128]))
            ->assertStatus(409)->assertJsonPath('errors.ma', 'DA_CHUC')->assertJsonPath('data.voucher.code', 'SNOLD777');
    }

    public function test_loi_mau_giu_cau_api(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Chưa khai mẫu mã sinh nhật — nhờ chủ cửa hàng đặt mức giảm ở Cài đặt → Quầy bán hàng'], 422)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.tangMaSinhNhat', ['id' => 128]))
            ->assertStatus(422)->assertJsonPath('message', 'Chưa khai mẫu mã sinh nhật — nhờ chủ cửa hàng đặt mức giảm ở Cài đặt → Quầy bán hàng');
    }

    public function test_da_chuc_khong_ma(): void
    {
        Http::fake(['*/admin/orders/pos/khach-hang/128/da-chuc' => Http::response(['data' => ['id' => 2, 'voucher_id' => null]], 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.daChucSinhNhat', ['id' => 128]))->assertOk()->assertJsonPath('data.id', 2);
    }

    public function test_loc_sinh_nhat_bo_khach_da_chuc_va_co_nut(): void
    {
        Http::fake(function (HttpRequest $r) {
            if (str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/admin/orders/pos/khach-hang')) {
                return Http::response(['data' => [['id' => 128, 'full_name' => 'Mai', 'date_of_birth' => now()->addDays(2)->subYears(30)->toDateString()]], 'meta' => ['page' => 1, 'total' => 1]]);
            }

            return Http::response(['data' => []]);
        });
        $html = $this->withSession($this->phien())->get(route('thu-ngan.crm.index', ['nhanh' => 'birthday']))->assertOk()->getContent();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'birthday_days=7') && str_contains($r->url(), 'bo_da_chuc=1') && str_contains($r->url(), 'page_size=30'));
        $this->assertStringContainsString('<div class="cr-sn" data-sn-box>', $html);
        $this->assertStringContainsString('id="crSnHop"', $html);
    }

    public function test_danh_sach_thuong_khong_co_nut(): void
    {
        Http::fake([
            '*/admin/orders/pos/khach-hang*' => Http::response(['data' => [['id' => 128, 'full_name' => 'Mai']], 'meta' => ['page' => 1, 'total' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);
        $html = $this->withSession($this->phien())->get(route('thu-ngan.crm.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<div class="cr-sn" data-sn-box>', $html);
    }

    public function test_cai_dat_co_khoi_mau_ma_sinh_nhat(): void
    {
        $f = fn ($k, $t, $sec) => ['key' => $k, 'group' => 'pos', 'type' => $t, 'label' => $k, 'required' => true, 'section' => $sec]
            + ($t === 'select' ? ['options' => [['value' => 'percentage', 'label' => 'Phần trăm tiền hàng'], ['value' => 'fixed', 'label' => 'Số tiền cố định']]] : []);
        Http::fake(['*/admin/settings*' => Http::response(['data' => [
            'values' => ['pos_staff_discount_limit' => '10', 'birthday_voucher_type' => 'percentage', 'birthday_voucher_value' => '10', 'birthday_voucher_max' => '50000', 'birthday_voucher_min_order' => '0', 'birthday_voucher_days' => '30'],
            'fields' => [$f('pos_staff_discount_limit', 'number', ''), $f('birthday_voucher_type', 'select', 'birthday'), $f('birthday_voucher_value', 'number', 'birthday'),
                $f('birthday_voucher_max', 'number', 'birthday'), $f('birthday_voucher_min_order', 'number', 'birthday'), $f('birthday_voucher_days', 'number', 'birthday')],
        ]]), '*' => Http::response(['data' => []])]);
        $html = $this->withSession($this->phien('admin'))->get(route('admin.settings.page', 'pos'))->assertOk()->getContent();

        $this->assertStringContainsString('Mã sinh nhật', $html);
        $this->assertMatchesRegularExpression('#name="items\[birthday_voucher_max\]"[^>]*data-money#s', $html);
        $this->assertStringContainsString('Phần trăm tiền hàng', $html);
        $this->assertStringContainsString('chỉ đúng khách ấy dùng được', $html);

        $this->withSession($this->phien('admin'))->put(route('admin.settings.update', 'pos'), ['items' => ['birthday_voucher_max' => '50.000', 'birthday_voucher_days' => '30']]);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && ($r['items']['birthday_voucher_max'] ?? null) === '50000');
    }

    public function test_xem_truoc_ma_o_ban_hang_gui_khach(): void
    {
        Http::fake(['*' => Http::response(['data' => ['code' => 'SNK7P2QX', 'giam' => 10000]])]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.voucher'), ['items' => [['product_variant_id' => 1, 'quantity' => 1]], 'code' => 'snk7p2qx', 'user_id' => 128])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/orders/pos/voucher') && $r['user_id'] === 128 && $r['code'] === 'SNK7P2QX');
    }
}
