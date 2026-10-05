<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Chi phí & lợi nhuận.
 *
 * Soi: bộ lọc đi sang /admin/reports/profit đúng tên, bảng đúng khuôn v2 (8 cột,
 * không STT, chân bảng hai dòng), lỗ tô đỏ, mốc biểu đồ chỉ gửi khi chọn tay,
 * và tab trong trang sáng đúng chỗ.
 */
class ProfitReportTest extends TestCase
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

    protected function baoMau(): array
    {
        $dong = fn ($id, $ma, $ten, $nhom, $sl, $ban, $von) => [
            'product_id' => $id, 'code' => $ma, 'name' => $ten, 'category_name' => $nhom, 'quantity' => $sl,
            'revenue' => $ban, 'cost' => $von, 'profit' => $ban - $von, 'margin' => $ban ? ($ban - $von) / $ban * 100 : 0,
        ];

        return ['data' => [
            'from' => '2026-05-01', 'to' => '2026-05-10', 'group_by' => 'day',
            'rows' => [
                $dong(80, 'PR0525000301', 'Matcha Latte', 'TRÀ', 3, 300000, 210000),
                $dong(81, 'M-000462', 'Volka', 'RƯỢU', 2, 100000, 120000),
                $dong(82, '', 'Nước ngọt Pepsi', 'NƯỚC', 0, 0, 0),
            ],
            'totals' => $dong(0, '', '', '', 5, 400000, 330000),
            'chart' => [
                ['key' => '2026-05-09', 'revenue' => 300000, 'cost' => 210000, 'profit' => 90000],
                ['key' => '2026-05-10', 'revenue' => 100000, 'cost' => 120000, 'profit' => -20000],
            ],
        ]];
    }

    protected function fakeApi(?array $bao = null): void
    {
        Http::fake([
            '*/admin/reports/profit*' => Http::response($bao ?? $this->baoMau()),
            '*/categories*' => Http::response(['data' => [['id' => 3, 'name' => 'Đồ uống', 'parent_id' => null]]]),
            '*/products*' => Http::response(['data' => [['id' => 80, 'name' => 'Matcha Latte']]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function thamSoGoi(): array
    {
        $req = collect(Http::recorded())->map(fn ($r) => $r[0])
            ->first(fn (Request $r) => str_contains($r->url(), '/admin/reports/profit'));
        $this->assertNotNull($req, 'không gọi /admin/reports/profit');
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    protected function trang(string $url = '/admin/reports/profit'): string
    {
        return $this->withSession($this->phien())->get($url)->assertOk()->getContent();
    }

    public function test_bo_loc_di_sang_api(): void
    {
        $this->fakeApi();

        $this->trang('/admin/reports/profit?from_date=01-05-2026&to_date=10-05-2026&shop_id=0&channel=web'
            .'&category_id=3&product_id=80&keyword=matcha&group_by=week');

        $q = $this->thamSoGoi();
        $this->assertSame('2026-05-01', $q['from']);
        $this->assertSame('2026-05-10', $q['to']);
        $this->assertSame('0', $q['shop_id']);
        $this->assertSame('web', $q['channel']);
        $this->assertSame('3', $q['category_id']);
        $this->assertSame('80', $q['product_id']);
        $this->assertSame('matcha', $q['keyword']);
        $this->assertSame('week', $q['group_by']);
    }

    public function test_mo_lan_dau_khong_gui_moc(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/profit?group_by=quarter&product_id=80');

        $q = $this->thamSoGoi();
        $this->assertSame(Carbon::today()->format('Y-m-d'), $q['from']);
        // Mốc lạ hay bỏ trống thì để API tự chọn; ô mốc chỉ bày, không mang data-loc.
        $this->assertArrayNotHasKey('group_by', $q);
        $this->assertArrayNotHasKey('product_id', $q);
        $this->assertMatchesRegularExpression('#<select name="group_by" class="ln-vien" aria-label="Mốc"\s*>#u', $html);
        $this->assertStringContainsString('<option value="day" selected>Theo ngày</option>', $html);
    }

    public function test_chon_moc_tay_thi_giu_qua_cac_luot_loc(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/profit?group_by=month');

        $this->assertMatchesRegularExpression('#<select name="group_by" class="ln-vien" aria-label="Mốc" data-loc>#u', $html);
    }

    public function test_bang_dung_khuon_v2(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        foreach (['Mã', 'Tên hàng hóa', 'Danh mục', 'Số lượng bán', 'Tổng giá bán', 'Tổng giá vốn', 'Lợi nhuận', 'Biên lợi nhuận (%)'] as $nhan) {
            $this->assertMatchesRegularExpression('#>'.preg_quote($nhan, '#').' <a class="btn_sort_table#u', $html);
        }
        $this->assertStringNotContainsString('>STT</th>', $html);
        $this->assertMatchesRegularExpression('#show_profit\s*">90\.000</td>#', $html);
        $this->assertMatchesRegularExpression('#show_margin text-success\s*">30</td>#', $html);
        // Bán lỗ: lợi nhuận và biên lãi chữ đỏ.
        $this->assertMatchesRegularExpression('#show_profit text-danger\s*">-20\.000</td>#', $html);
        $this->assertMatchesRegularExpression('#show_margin text-danger\s*">-20</td>#', $html);
        // Hàng chưa bán: có dòng, mã trống bày "-".
        $this->assertMatchesRegularExpression('#show_code\s*">-</td>\s*<td class="text-left show_name\s*">Nước ngọt Pepsi#u', $html);

        $chan = substr($html, strpos($html, '<tfoot>'));
        $chan = substr($chan, 0, strpos($chan, '</tfoot>'));
        $this->assertStringContainsString('colspan="3">Tổng cộng</th>', $chan);
        $this->assertMatchesRegularExpression('#show_cost\s*">330\.000</th>#', $chan);
        $this->assertMatchesRegularExpression('#show_margin text-success\s*">17,5</th>#', $chan);
        $this->assertStringContainsString('colspan="8" class="text-right">Tổng cộng Lợi nhuận: 70.000</th>', $chan);
    }

    public function test_sap_xep_va_an_cot(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/profit?sort_field=profit&sort_type=asc&hide=code,category_name');
        $this->assertLessThan(strpos($html, '>Matcha Latte</td>'), strpos($html, '>Volka</td>'));
        $this->assertStringContainsString('colspan="1">Tổng cộng</th>', $html);
        $this->assertStringContainsString('colspan="6" class="text-right">Tổng cộng Lợi nhuận', $html);

        $html = $this->trang('/admin/reports/profit?sort_field=category_name&sort_type=asc');
        $this->assertLessThan(strpos($html, '>RƯỢU</td>'), strpos($html, '>NƯỚC</td>'));
    }

    public function test_bieu_do_nhan_du_lieu(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/profit?show=chart');

        $this->assertMatchesRegularExpression('#id="show-chart"\s+checked#', $html);
        $this->assertStringContainsString('"cot":[{"key":"2026-05-09","revenue":300000,"cost":210000,"profit":90000}', $html);
        $this->assertStringContainsString('Báo cáo chi phí lợi nhuận hàng hóa (<span class="text-success">400.000 VND</span>)', $html);
        $this->assertMatchesRegularExpression('#<div class="row\s*" data-dang="chart">#', $html);
    }

    public function test_tab_trong_trang_va_o_loc_hang(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/profit?category_id=3');

        $dayTab = substr($html, strpos($html, '<ul class="nav nav-tabs cus-nav-tabs'));
        $dayTab = substr($dayTab, 0, strpos($dayTab, '</ul>'));
        $this->assertMatchesRegularExpression('#nav-link active" data-type="expense"#', $dayTab);
        $this->assertMatchesRegularExpression('#data-type="products" href="[^"]*/admin/reports/goods"#', $dayTab);
        $this->assertMatchesRegularExpression('#reports/sales"\s+class="sub-nav-btn active"#', $html);
        $this->assertStringContainsString('<option value="80" >Matcha Latte</option>', $html);
        // Khối lọc dùng chung: một nút mở trên điện thoại, một đoạn JS đổi nhóm.
        $this->assertSame(1, substr_count($html, 'data-offcanvas-target="#filterGoods"'));
        $this->assertSame(1, substr_count($html, ".fillter-box [name=\"category_id\"]"));
    }

    public function test_xuat_theo_bang(): void
    {
        $this->fakeApi();

        $csv = $this->withSession($this->phien())->get('/admin/reports/profit?xuat=excel')->assertOk()->streamedContent();

        $this->assertStringContainsString('"Biên lợi nhuận (%)"', $csv);
        $this->assertStringContainsString('PR0525000301,"Matcha Latte",TRÀ,3,300000,210000,90000,30', $csv);
        $this->assertStringContainsString('"Tổng cộng",,,5,400000,330000,70000,17.5', $csv);
    }

    public function test_api_loi_van_ra_trang(): void
    {
        Http::fake([
            '*/admin/reports/profit*' => Http::response(['message' => 'Hỏng rồi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/reports/profit')
            ->assertOk()->assertSee('Hỏng rồi')->assertSee('Không có mặt hàng nào khớp bộ lọc.');
    }
}
