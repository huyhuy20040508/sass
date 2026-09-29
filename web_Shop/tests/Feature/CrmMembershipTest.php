<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM → Thẻ thành viên (khuôn crm/membership-rank của v2) và phần đổi điểm ở quầy.
 */
class CrmMembershipTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function duLieu(): array
    {
        return [
            'ranks' => [
                ['id' => 1, 'name' => 'Đồng', 'point' => 1000, 'discount_type' => 'money', 'discount_value' => 3000, 'status' => true,
                    'apply_all_order_values' => true, 'min_order_value' => null, 'max_order_value' => null, 'member_count' => 3],
                ['id' => 2, 'name' => 'LUXURY', 'point' => 20000, 'discount_type' => 'percent', 'discount_value' => 10, 'status' => true,
                    'apply_all_order_values' => false, 'min_order_value' => 100000, 'max_order_value' => null, 'member_count' => 8],
            ],
            'conversion' => ['earn_money' => 10000, 'earn_point' => 100, 'earn_enabled' => true, 'redeem_point' => 1, 'redeem_money' => 100, 'redeem_enabled' => true],
        ];
    }

    public function test_trang_bay_hang_va_quy_doi_nhu_v2(): void
    {
        Http::fake(['*/admin/the-thanh-vien' => Http::response(['data' => $this->duLieu()])]);

        $html = $this->withSession($this->phien())->get(route('admin.crm.membership.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/sub-nav-btn active">\s*Thẻ thành viên/', $html);
        $this->assertStringContainsString('Giảm giá theo xếp hạng', $html);
        $this->assertStringContainsString('Quy đổi tiền ra điểm: 10,000đ = 100 điểm', $html);
        $this->assertStringContainsString('Áp dụng quy đổi điểm: 1=100đ', $html);
        // Điểm tối thiểu kèm số tiền tương ứng: 1.000 điểm × 100đ.
        $this->assertStringContainsString('(100,000đ)', $html);
        $this->assertStringContainsString('10 %', $html);
        $this->assertStringContainsString(route('admin.crm.membership.detail', 2), $html);
    }

    public function test_trang_chi_tiet_hang(): void
    {
        Http::fake([
            '*/admin/the-thanh-vien/1/khach*' => Http::response(['data' => [['id' => 418, 'customer_code' => 'cus-00418', 'full_name' => 'Tuấn Anh Đặng',
                'phone' => '0774921898', 'address' => '', 'total_points' => 1370, 'points' => 1370]], 'meta' => ['page' => 1, 'page_size' => 10, 'total' => 1, 'total_pages' => 1]]),
            '*/admin/the-thanh-vien/1' => Http::response(['data' => $this->duLieu()['ranks'][0]]),
            '*/admin/the-thanh-vien' => Http::response(['data' => $this->duLieu()]),
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.crm.membership.detail', 1))->assertOk()->getContent();

        $this->assertStringContainsString('Chính sách về giá', $html);
        $this->assertStringContainsString('Giảm theo hạng (tối thiểu 1000 điểm)', $html);
        $this->assertStringContainsString('vô hạn', $html);
        $this->assertStringContainsString('cus-00418', $html);
        $this->assertStringContainsString('1370', $html);
    }

    public function test_luu_hang_va_quy_doi_gui_dung_than(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->withSession($this->phien())->postJson(route('admin.membership.store'), [
            'name' => 'Bạc', 'point' => 1500, 'discount_type' => 'money', 'discount_value' => '10,000',
            'apply_all_order_values' => 0, 'min_order_value' => '100,000', 'max_order_value' => '', 'status' => 1,
        ])->assertOk()->assertJsonPath('success', true);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/admin/the-thanh-vien')
            && $r['discount_value'] === 10000.0 && $r['apply_all_order_values'] === false && $r['min_order_value'] === 100000.0);

        $this->withSession($this->phien())->putJson(route('admin.membership.conversion'), [
            'kind' => 'earn', 'money' => '10,000', 'point' => 100, 'enabled' => 1,
        ])->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/quy-doi') && $r['money'] === 10000.0 && $r['point'] === 100);
    }

    public function test_quay_gui_diem_doi_khi_co_khach(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response(['data' => ['order_id' => 1]], 201)]);
        $phien = array_merge($this->phien(), ['api.user' => ['id' => 7, 'full_name' => 'Quầy', 'role' => ['name' => 'staff']]]);

        $this->withSession($phien)->postJson(route('thu-ngan.ban-hang.store'), [
            'payment_method' => 'cash', 'user_id' => 5, 'use_points' => 500,
            'items' => [['product_variant_id' => 12, 'quantity' => 1]],
        ])->assertSuccessful();
        Http::assertSent(fn ($r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/admin/orders/pos') && $r['use_points'] === 500);
    }
}
