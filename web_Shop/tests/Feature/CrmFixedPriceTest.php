<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM → Chương trình khuyến mãi → tab Khuyến mại đồng giá (khuôn crm/fixed-price
 * của v2), và nút "Đồng giá" ở quầy.
 */
class CrmFixedPriceTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function mau(): array
    {
        return [
            'id' => 3, 'code' => 'DGTET', 'name' => 'Đồng giá Tết', 'description' => '', 'type' => 2,
            'status' => true, 'approved' => true, 'no_time_limit' => false,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'all_shops' => false, 'shop_ids' => [2],
            'details' => [['id' => 9, 'object_id' => 5, 'object_name' => 'Áo thun', 'quantity' => 2, 'price' => 99000,
                'gifts' => [['product_variant_id' => 7, 'name' => 'Tất (Trắng)', 'quantity' => 1]]]],
        ];
    }

    /**
     * Hai cột cùng tên "Trạng thái" thì không ai biết cột nào là cột nào.
     *
     * Bản v2 gốc đặt cả cột CHỮ ("Hoạt động / Ngừng hoạt động") lẫn cột CÔNG TẮC
     * của cùng trường `status` là `message.status`. Giữ cả hai cột vì mỗi cột
     * một việc — cột kia NÓI trạng thái, cột này BẬT/TẮT — nhưng tên phải khác
     * nhau.
     */
    public function test_cot_cong_tac_khong_trung_ten_voi_cot_trang_thai(): void
    {
        Http::fake([
            '*/admin/dong-gia/ma' => Http::response(['data' => ['DGTET']]),
            '*/admin/dong-gia*' => Http::response(['data' => [$this->mau()], 'meta' => ['page' => 1, 'page_size' => 10, 'total' => 1, 'total_pages' => 1]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 2, 'code' => 'Q7', 'name' => 'Kho Quận 7']]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())
            ->get(route('admin.crm.promotions.dongGia'))->assertOk()->getContent();

        $dau = strpos($html, '<thead>');
        $hang = substr($html, $dau, strpos($html, '</thead>', $dau) - $dau);

        preg_match_all('/<th[^>]*>\s*([^<]+?)\s*<\/th>/', $hang, $m);
        $nhan = array_map('trim', $m[1]);

        $this->assertSame(array_unique($nhan), $nhan, 'hàng tiêu đề có hai cột trùng tên: '.implode(' | ', $nhan));
        $this->assertContains('Bật/Tắt', $nhan);
        $this->assertContains(__('message.status'), $nhan);
    }

    public function test_tab_dong_gia_bay_bang_nhu_v2(): void
    {
        Http::fake([
            '*/admin/dong-gia/ma' => Http::response(['data' => ['DGTET', 'DG00002']]),
            '*/admin/dong-gia*' => Http::response(['data' => [$this->mau()], 'meta' => ['page' => 1, 'page_size' => 10, 'total' => 1, 'total_pages' => 1]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 2, 'code' => 'Q7', 'name' => 'Kho Quận 7']]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.crm.promotions.dongGia'))->assertOk()->getContent();

        // Tab CRM "Chương trình khuyến mãi" vẫn sáng, tab trong trang "Khuyến mại đồng giá" sáng.
        $this->assertMatchesRegularExpression('/sub-nav-btn active">\s*Chương trình khuyến mãi/', $html);
        $this->assertStringContainsString('class="active">Khuyến mại đồng giá', $html);
        $this->assertStringContainsString('Danh sách các chương trình khuyến mại đồng giá', $html);
        $this->assertStringContainsString('DGTET', $html);
        $this->assertStringContainsString('Đã được duyệt', $html);
        $this->assertStringContainsString('Q7', $html);
        $this->assertStringContainsString('01-09-2026', $html);
        // Cột "Trạng thái" dạng chữ cạnh công tắc, như v2.
        $this->assertStringContainsString('<b class="text-success">Hoạt động</b>', $html);
        // Ô "Mã khuyến mãi" chọn sẵn mọi mã khi chưa lọc.
        $this->assertStringContainsString('<option value="DG00002" selected>', $html);
        // Hộp Thêm/Sửa không bày bảng hàng tặng; hộp Xem thì có (cột ĐVT).
        $this->assertStringNotContainsString('add-row-gift', $html);
        $this->assertStringContainsString('<th class="w-25">ĐVT</th>', $html);
    }

    public function test_loc_mac_dinh_dau_thang_va_gui_ma_len_api(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->get(route('admin.crm.promotions.dongGia'))->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/dong-gia?')
            && $r['from_date'] === now()->startOfMonth()->format('Y-m-d') && $r['to_date'] === now()->format('Y-m-d')
            && ! isset($r['codes']));

        $this->withSession($this->phien())->get(route('admin.crm.promotions.dongGia', ['codes' => ['DGTET']]))->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/dong-gia?') && ($r['codes'] ?? '') === 'DGTET');
    }

    public function test_luu_gui_dong_va_hang_tang_len_api(): void
    {
        Http::fake(['*/admin/dong-gia' => Http::response(['data' => $this->mau()], 201)]);

        $this->withSession($this->phien())->postJson(route('admin.dong-gia.store'), [
            'code' => 'dgtet', 'name' => 'Đồng giá Tết', 'type' => 2, 'approved' => 1, 'status' => 1,
            'no_time_limit' => 0, 'start_date' => '01-09-2026', 'end_date' => '30-09-2026',
            'days_of_week' => [1, 2], 'all_shops' => 0, 'shop_ids' => [2],
            'details' => json_encode([['object_id' => 5, 'quantity' => 2, 'price' => 99000,
                'gifts' => [['product_variant_id' => 7, 'quantity' => 1]]]]),
        ])->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && ! isset($r['code']) && $r['approved'] === true
            && $r['start_date'] === '2026-09-01' && $r['shop_ids'] === [2]
            && $r['details'][0]['gifts'][0]['product_variant_id'] === 7);
    }

    public function test_quay_chuyen_fixed_price_ids_va_xem_truoc(): void
    {
        Http::fake([
            '*/admin/orders/pos/dong-gia' => Http::response(['data' => ['chuong_trinh' => [['id' => 3, 'code' => 'DGTET', 'name' => 'x', 'type' => 2]], 'gia' => [], 'qua' => []]]),
            '*/admin/orders/pos' => Http::response(['data' => ['order_id' => 1]], 201),
        ]);
        $phien = array_merge($this->phien(), ['api.user' => ['id' => 7, 'full_name' => 'Quầy', 'role' => ['name' => 'staff']]]);

        $this->withSession($phien)->postJson(route('thu-ngan.ban-hang.dongGia'), [
            'items' => [['product_variant_id' => 12, 'quantity' => 2]], 'fixed_price_ids' => [3],
        ])->assertOk()->assertJsonPath('data.chuong_trinh.0.code', 'DGTET');

        $this->withSession($phien)->postJson(route('thu-ngan.ban-hang.store'), [
            'payment_method' => 'bank_transfer', 'fixed_price_ids' => [3, 3],
            'items' => [['product_variant_id' => 12, 'quantity' => 2]],
        ])->assertSuccessful();

        Http::assertSent(fn ($r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/admin/orders/pos')
            && $r['fixed_price_ids'] === [3]);
    }
}
