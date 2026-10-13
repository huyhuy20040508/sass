<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phí quẹt thẻ (orders.card_fee) hiện ở các màn đọc nó: Báo cáo doanh thu, Báo
 * cáo tổng hợp, Chi phí & lợi nhuận, Thu chi, hộp chi tiết đơn.
 */
class CardFeeDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CurrentBranch::quenCache();
    }

    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly'],
            ApiClient::KHOA_CHI_NHANH => 1,
        ];
    }

    protected function fake(array $mau): void
    {
        Http::fake($mau + [
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function ngay(string $d, float $the, float $phi): array
    {
        return ['date' => $d, 'orders' => 1, 'revenue' => 22000000, 'revenue_vat' => 24200000, 'returns' => 0,
            'paid' => 24200000, 'cash' => 24200000 - $the, 'transfer' => 0, 'card' => $the, 'auto_qr' => 0,
            'other' => 0, 'debt' => 0, 'card_fee' => $phi];
    }

    public function test_bao_cao_doanh_thu_co_cot_phi_the(): void
    {
        $tong = $this->ngay('', 24200000, 435600);
        $this->fake(['*/admin/reports/sales*' => Http::response(['data' => [
            'days' => [$this->ngay('2026-10-12', 24200000, 435600), $this->ngay('2026-10-11', 0, 0)],
            'totals' => $tong, 'by_day' => [], 'by_hour' => [], 'by_weekday' => [], 'by_month' => [],
        ]])]);

        $html = $this->withSession($this->phien())->get('/admin/reports/sales')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<th class="show_card_fee[^"]*"\s+title="Phí quẹt thẻ cửa hàng chịu/', $html);
        $this->assertStringContainsString('Phí thẻ <a', $html);
        $this->assertMatchesRegularExpression('/show_card_fee text-danger[^"]*">435\.600</', $html);
        // Cột ngay sau Quẹt Thẻ, và bật/tắt được trong ô chọn cột.
        $this->assertMatchesRegularExpression('/Quẹt Thẻ <a.*?<\/th>\s*<th class="show_card_fee/s', $html);
        $this->assertStringContainsString('id="show_card_fee"', $html);

        $tat = $this->withSession($this->phien())->get('/admin/reports/sales?hide=card_fee')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/show_card_fee text-danger hide">435\.600</', $tat);

        $csv = $this->withSession($this->phien())->get('/admin/reports/sales?xuat=excel')->assertOk()->streamedContent();
        $dau = str_getcsv(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))[0]);
        $this->assertSame('Phí thẻ', $dau[array_search('Quẹt Thẻ', $dau, true) + 1]);
        $this->assertStringContainsString(',435600,', $csv);
    }

    public function test_hop_chi_tiet_ngay_mang_phi_the(): void
    {
        $this->fake(['*/admin/reports/sales/orders*' => Http::response(['data' => [
            ['id' => 9, 'code' => 'DH000043', 'channel' => 'pos', 'payment_method' => 'card', 'units' => 1, 'subtotal' => 22000000,
                'surcharge' => 0, 'discount' => 0, 'revenue' => 22000000, 'vat' => 2200000, 'total' => 24200000, 'paid' => 24200000,
                'card_fee' => 435600, 'debt' => 0, 'returns' => 0, 'customer_code' => '', 'customer_name' => '', 'phone' => ''],
        ]])]);

        $this->withSession($this->phien())->getJson('/admin/reports/sales/orders?date=2026-10-12')
            ->assertOk()->assertJsonPath('data.0.card_fee', 435600)->assertJsonPath('data.0.hinh_thuc', 'Quẹt Thẻ');

        $html = $this->withSession($this->phien())->get('/admin/reports/sales')->getContent();
        $this->assertStringContainsString("<th>Phí thẻ</th>", $html);
        $this->assertStringContainsString("tien(h.card_fee)", $html);
    }

    protected function tongHop(float $phi): string
    {
        $this->fake(['*/admin/reports/summary*' => Http::response(['data' => [
            'date' => '2026-10-12',
            'cashbook' => ['income_count' => 2, 'expense_count' => 2, 'income' => 48400000, 'expense' => $phi, 'cash_income' => 0, 'cash_expense' => 0],
            'totals' => ['orders' => 2, 'revenue' => 48400000, 'units' => 2, 'card_fee' => $phi],
            'item_kinds' => 1,
            'by_payment_method' => [['key' => 'card', 'orders' => 2, 'revenue' => 48400000]],
            'by_hour' => [],
            'returns' => ['returns' => 0, 'lines' => 0, 'units' => 0, 'refund' => 0],
        ]])]);

        return $this->withSession($this->phien())->get('/admin/reports/summary?show=table')->assertOk()->getContent();
    }

    public function test_tong_hop_co_dong_phi_duoi_quet_the(): void
    {
        $html = $this->tongHop(871200);
        $this->assertMatchesRegularExpression('/Quẹt thẻ<\/span><span class="so">48\.400\.000<\/span>\s*<span class="nhan th-phi"[^>]*>↳ Phí thẻ cửa hàng chịu<\/span><span class="so th-phi">-871\.200</', $html);
        // Tổng kết bán hàng vẫn là tiền khách trả.
        $this->assertStringContainsString('Tổng kết bán hàng<b>48.400.000đ</b>', $html);
    }

    public function test_tong_hop_khong_phi_thi_khong_dong(): void
    {
        $this->assertStringNotContainsString('Phí thẻ cửa hàng chịu</span>', $this->tongHop(0));
    }

    protected function loiNhuan(float $phi, string $url = '/admin/reports/profit'): \Illuminate\Testing\TestResponse
    {
        $this->fake([
            '*/admin/reports/profit*' => Http::response(['data' => [
                'from' => '2026-10-12', 'to' => '2026-10-12', 'group_by' => 'day',
                'rows' => [['product_id' => 1, 'code' => 'SP1', 'name' => 'iPhone', 'category_name' => 'ĐT', 'quantity' => 2,
                    'revenue' => 55700000, 'cost' => 47080000, 'profit' => 8620000, 'margin' => 15.48]],
                'totals' => ['product_id' => 0, 'code' => '', 'name' => '', 'category_name' => '', 'quantity' => 2,
                    'revenue' => 55700000, 'cost' => 47080000, 'profit' => 8620000, 'margin' => 15.48],
                'card_fee' => $phi,
                'chart' => [['key' => '2026-10-12', 'revenue' => 55700000, 'cost' => 47080000, 'profit' => 8620000, 'card_fee' => $phi]],
            ]]),
            '*/categories*' => Http::response(['data' => []]),
            '*/products*' => Http::response(['data' => []]),
        ]);

        return $this->withSession($this->phien())->get($url)->assertOk();
    }

    public function test_loi_nhuan_tru_phi_o_chan_bang(): void
    {
        $html = $this->loiNhuan(871200)->getContent();
        $this->assertStringContainsString('Trừ phí quẹt thẻ cửa hàng chịu: <span class="text-danger">-871.200</span>', $html);
        $this->assertMatchesRegularExpression('/text-success">Lợi nhuận sau phí thẻ: 7\.748\.800 \(13,91%\)/', $html);

        $csv = $this->loiNhuan(871200, '/admin/reports/profit?xuat=excel')->streamedContent();
        $this->assertStringContainsString('"Trừ phí quẹt thẻ cửa hàng chịu",,,,,,-871200,', $csv);
        $this->assertStringContainsString('"Lợi nhuận sau phí thẻ",,,,,,7748800,13.91', $csv);
    }

    public function test_bieu_do_loi_nhuan_nhan_phi_theo_moc(): void
    {
        $html = $this->loiNhuan(871200, '/admin/reports/profit?show=chart')->getContent();
        preg_match('#<script type="application/json" id="ln-du-lieu">(.*?)</script>#s', $html, $m);
        $du = json_decode($m[1] ?? '', true);
        $this->assertSame(871200, $du['cot'][0]['card_fee'] ?? null);
        $this->assertStringContainsString("label: 'Lợi nhuận sau phí thẻ'", $html);
        $this->assertStringContainsString("cot.some((m) => Number(m.card_fee) > 0)", $html);
    }

    public function test_loi_nhuan_khong_phi_thi_khong_dong(): void
    {
        $this->assertStringNotContainsString('class="ln-sau-phi"', $this->loiNhuan(0)->getContent());
    }

    public function test_thu_chi_loc_va_nhan_nguon_phi_the(): void
    {
        $this->fake(['*/admin/thu-chi*' => Http::response([
            'data' => [[
                'id' => 4, 'code' => 'PC00012', 'type' => 1, 'category_id' => 5, 'category_name' => 'Phí thanh toán, phí ngân hàng, phí cổng thanh toán',
                'amount' => 435600, 'branch_name' => 'Kho trung tâm', 'payer_type' => '', 'payer_name' => '', 'created_by_name' => '',
                'payment_method' => 'transfer', 'note' => 'Phí quẹt thẻ DH000043 · May VCB', 'source' => 'card_fee',
                'source_code' => 'DH000043', 'created_at' => '2026-10-12 14:27:00', 'locked' => true,
            ]],
            'meta' => ['page' => 1, 'page_size' => 10, 'total' => 1, 'total_pages' => 1],
        ])]);

        $html = $this->withSession($this->phien())->get(route('admin.cashbook.index', ['source' => 'card_fee']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="card_fee"\s+selected>Phí thẻ<\/option>/', $html);
        $this->assertStringContainsString('data-source-name="Phí thẻ"', $html);

        $req = collect(Http::recorded())->map(fn ($r) => $r[0])->first(fn ($r) => str_contains($r->url(), '/admin/thu-chi'));
        $this->assertStringContainsString('source=card_fee', urldecode($req->url()));
    }

    public function test_chi_tiet_don_chuyen_tiep_phi_the(): void
    {
        $this->fake(['*/admin/orders/43*' => Http::response(['data' => [
            'id' => 43, 'order_code' => 'DH000043', 'total_amount' => 24200000, 'payment_method' => 'card',
            'phi_the' => ['card_fee' => 435600, 'device_name' => 'May VCB', 'expense_code' => 'PC00012'],
        ]])]);

        $this->withSession($this->phien())->getJson('/admin/orders/43/detail')
            ->assertOk()->assertJsonPath('data.phi_the.card_fee', 435600)->assertJsonPath('data.phi_the.expense_code', 'PC00012');
    }
}
