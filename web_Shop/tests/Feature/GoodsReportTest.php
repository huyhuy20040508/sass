<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Hàng hóa.
 *
 * Soi: bộ lọc đi sang /admin/reports/goods đúng tên (kỳ, chi nhánh, nguồn, nhóm,
 * mặt hàng, ô tìm, hai ô Top), bảng đúng khuôn v2 (5 cột, mã bấm được, chân
 * bảng), ô Hàng hóa chỉ mở khi đã chọn nhóm, dữ liệu biểu đồ sang JS, hộp chi
 * tiết đổi nhãn đúng, và tab trong trang sáng đúng chỗ.
 */
class GoodsReportTest extends TestCase
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
        return ['data' => [
            'from' => '2026-05-01', 'to' => '2026-05-10',
            'rows' => [
                ['product_id' => 451, 'code' => 'HH0000003701', 'name' => 'Cà phê chế biến', 'quantity' => 2, 'amount' => 60000, 'total' => 60000],
                ['product_id' => 80, 'code' => 'PR0525000301', 'name' => 'Matcha Latte', 'quantity' => 12, 'amount' => 90000, 'total' => 96750],
                ['product_id' => 0, 'code' => 'CU-01', 'name' => 'Món đã xoá', 'quantity' => 1, 'amount' => 10000, 'total' => 10000],
            ],
            'totals' => ['product_id' => 0, 'code' => '', 'name' => '', 'quantity' => 15, 'amount' => 160000, 'total' => 166750],
            'top' => [
                ['product_id' => 80, 'code' => 'PR0525000301', 'name' => 'Matcha Latte', 'quantity' => 12, 'amount' => 90000, 'total' => 96750],
                ['product_id' => 451, 'code' => 'HH0000003701', 'name' => 'Cà phê chế biến', 'quantity' => 2, 'amount' => 60000, 'total' => 60000],
            ],
            'by_hour' => [['key' => '11', 'orders' => 3, 'units' => 15, 'revenue' => 166750]],
            'by_month' => [['key' => '5', 'orders' => 3, 'units' => 15, 'revenue' => 166750]],
            'by_weekday' => [['product_id' => 80, 'name' => 'Matcha Latte', 'data' => [0, 4, 0, 0, 8, 0, 0]]],
        ]];
    }

    protected function fakeApi(?array $bao = null): void
    {
        Http::fake([
            '*/admin/reports/goods/orders*' => Http::response(['data' => [
                ['id' => 51, 'code' => 'SO0000050901', 'channel' => 'pos', 'item_code' => 'PR0525000301',
                    'item_name' => 'Matcha Latte', 'quantity' => 2, 'total' => 32250],
                ['id' => 52, 'code' => 'DH52', 'channel' => 'web', 'item_code' => 'PR0525000301',
                    'item_name' => 'Matcha Latte', 'quantity' => 1, 'total' => 16125],
            ]]),
            '*/admin/reports/goods*' => Http::response($bao ?? $this->baoMau()),
            '*/categories*' => Http::response(['data' => [
                ['id' => 3, 'name' => 'Đồ uống', 'parent_id' => null],
                ['id' => 4, 'name' => 'Đồ ăn', 'parent_id' => null],
                ['id' => 7, 'name' => 'Cà phê', 'parent_id' => 3],
            ]]),
            '*/products*' => Http::response(['data' => [['id' => 80, 'name' => 'Matcha Latte'], ['id' => 451, 'name' => 'Cà phê chế biến']]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function thamSoGoi(string $duong = '/admin/reports/goods?'): array
    {
        $req = collect(Http::recorded())->map(fn ($r) => $r[0])
            ->first(fn (Request $r) => str_contains($r->url(), $duong));
        $this->assertNotNull($req, 'không gọi '.$duong);
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    protected function trang(string $url = '/admin/reports/goods'): string
    {
        return $this->withSession($this->phien())->get($url)->assertOk()->getContent();
    }

    public function test_bo_loc_di_sang_api(): void
    {
        $this->fakeApi();

        $this->trang('/admin/reports/goods?from_date=01-05-2026&to_date=10-05-2026&shop_id=0&channel=pos'
            .'&category_id=3&product_id=80&keyword=matcha&top=10&top_sort=asc&top_weekday=15');

        $q = $this->thamSoGoi();
        $this->assertSame('2026-05-01', $q['from']);
        $this->assertSame('2026-05-10', $q['to']);
        $this->assertSame('0', $q['shop_id']);
        $this->assertSame('pos', $q['channel']);
        $this->assertSame('3', $q['category_id']);
        $this->assertSame('80', $q['product_id']);
        $this->assertSame('matcha', $q['keyword']);
        $this->assertSame('10', $q['top']);
        $this->assertSame('asc', $q['sort']);
        $this->assertSame('15', $q['top_weekday']);
    }

    public function test_mo_lan_dau_va_tham_so_la(): void
    {
        $this->fakeApi();

        // Chưa chọn nhóm thì bỏ mặt hàng; Top lạ lùi về 5; nguồn lạ bỏ.
        $html = $this->trang('/admin/reports/goods?product_id=80&top=7&channel=zalo');

        $q = $this->thamSoGoi();
        $this->assertSame(Carbon::today()->format('Y-m-d'), $q['from']);
        $this->assertArrayNotHasKey('product_id', $q);
        $this->assertArrayNotHasKey('channel', $q);
        $this->assertSame('5', $q['top']);
        $this->assertSame('desc', $q['sort']);
        // Ô Hàng hóa khoá khi chưa chọn nhóm, như v2.
        $this->assertMatchesRegularExpression('#<select name="product_id" class="form-select" data-loc\s+disabled>#', $html);
        $this->assertFalse(collect(Http::recorded())->contains(fn ($r) => str_contains($r[0]->url(), '/products')),
            'chưa chọn nhóm thì không cần tải danh sách hàng');
    }

    public function test_chon_nhom_thi_mo_o_hang_hoa(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/goods?category_id=3&product_id=80');

        $this->assertMatchesRegularExpression('#<option value="3" selected>Đồ uống</option>#u', $html);
        // Nhóm con đứng ngay dưới cha, lùi đầu dòng.
        $this->assertLessThan(strpos($html, '>Đồ ăn</option>'), strpos($html, '>— Cà phê</option>'));
        $this->assertDoesNotMatchRegularExpression('#name="product_id"[^>]*disabled#', $html);
        $this->assertStringContainsString('<option value="80" selected>Matcha Latte</option>', $html);
        $q = $this->thamSoGoi('/products');
        $this->assertSame('3', $q['category_id']);
    }

    public function test_bang_dung_khuon_v2(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        foreach (['Mã hàng hóa', 'Tên hàng hóa', 'Số lượng', 'Tổng giá bán', 'Thành tiền (VAT)'] as $nhan) {
            $this->assertMatchesRegularExpression('#>'.preg_quote($nhan, '#').' <a class="btn_sort_table#u', $html);
        }
        // Mã bấm được mở hộp chi tiết; hàng đã xoá thì không.
        $this->assertStringContainsString('class="handle-data-modal" data-id="80" data-name="Matcha Latte">PR0525000301</div>', $html);
        $this->assertStringNotContainsString('data-id="0"', $html);
        $this->assertMatchesRegularExpression('#show_total\s*">96\.750</td>#', $html);

        $chan = substr($html, strpos($html, '<tfoot>'));
        $chan = substr($chan, 0, strpos($chan, '</tfoot>'));
        $this->assertStringContainsString('colspan="3">Tổng cộng</th>', $chan);
        $this->assertMatchesRegularExpression('#show_quantity\s*">15</th>#', $chan);
        $this->assertMatchesRegularExpression('#show_total\s*">166\.750</th>#', $chan);
    }

    public function test_sap_xep_va_an_cot(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/goods?sort_field=quantity&sort_type=desc&hide=stt,code');
        $this->assertLessThan(strpos($html, '>Cà phê chế biến</td>'), strpos($html, '>Matcha Latte</td>'));
        $this->assertStringContainsString('colspan="1">Tổng cộng</th>', $html);
        $this->assertMatchesRegularExpression('#<th class="show_code hide">Mã hàng hóa#u', $html);

    }

    public function test_sap_cot_chu_theo_tieng_viet(): void
    {
        $bao = $this->baoMau();
        $bao['data']['rows'] = [
            ['product_id' => 1, 'code' => 'A', 'name' => 'Nón', 'quantity' => 1, 'amount' => 1, 'total' => 1],
            ['product_id' => 2, 'code' => 'B', 'name' => 'Áo thun', 'quantity' => 1, 'amount' => 1, 'total' => 1],
        ];
        $this->fakeApi($bao);

        // So byte thì "Á" (0xC3) đứng sau "N" — người dùng tìm "Áo" ở cuối bảng.
        $html = $this->trang('/admin/reports/goods?sort_field=name&sort_type=asc');
        $this->assertLessThan(strpos($html, '>Nón</td>'), strpos($html, '>Áo thun</td>'));
    }

    public function test_bieu_do_nhan_du_lieu(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/goods?show=chart&top=10');

        $this->assertMatchesRegularExpression('#id="show-chart"\s+checked#', $html);
        $this->assertStringContainsString('"thu":[{"product_id":80,"name":"Matcha Latte","data":[0,4,0,0,8,0,0]}]', $html);
        // Tiêu đề top = cộng số lượng các món trong top.
        $this->assertStringContainsString('Top hàng hóa bán chạy (<span class="text-success">14 sp</span>)', $html);
        $this->assertStringContainsString('<option value="10" selected>Top 10</option>', $html);
        $this->assertMatchesRegularExpression('#<div class="row\s*" data-dang="chart">#', $html);
    }

    public function test_hop_chi_tiet_hang(): void
    {
        $this->fakeApi();

        $r = $this->withSession($this->phien())
            ->getJson('/admin/reports/goods/orders?product_id=80&name=Matcha&from_date=01-05-2026&to_date=10-05-2026&channel=pos')
            ->assertOk();

        $q = $this->thamSoGoi('/admin/reports/goods/orders');
        $this->assertSame('80', $q['product_id']);
        $this->assertSame('2026-05-01', $q['from']);
        $this->assertSame('pos', $q['channel']);

        $r->assertJsonPath('tieu_de', 'Chi tiết hàng hóa - MATCHA LATTE (01-05-2026 00:00 / 10-05-2026 23:59)')
            ->assertJsonPath('data.0.nguon', 'Tại quầy')
            ->assertJsonPath('data.1.nguon', 'Online');

        $this->withSession($this->phien())->getJson('/admin/reports/goods/orders')->assertStatus(422);
    }

    public function test_tab_trong_trang_va_header(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        $dayTab = substr($html, strpos($html, '<ul class="nav nav-tabs cus-nav-tabs'));
        $dayTab = substr($dayTab, 0, strpos($dayTab, '</ul>'));
        $this->assertMatchesRegularExpression('#nav-link active" data-type="products"#', $dayTab);
        $this->assertMatchesRegularExpression('#data-type="sales" href="[^"]*/admin/reports/sales"#', $dayTab);
        $this->assertMatchesRegularExpression('#reports/sales"\s+class="sub-nav-btn active"#', $html);
        // Ô tìm nằm ngoài khối được nạp lại.
        $this->assertLessThan(strpos($html, 'class="mx-0 list"'), strpos($html, 'placeholder="Tìm theo tên/mã hàng hóa"'));
    }

    public function test_xuat_theo_bang(): void
    {
        $this->fakeApi();

        $csv = $this->withSession($this->phien())->get('/admin/reports/goods?xuat=excel')->assertOk()->streamedContent();

        $this->assertStringContainsString('"Thành tiền (VAT)"', $csv);
        $this->assertStringContainsString('PR0525000301', $csv);
        $this->assertStringContainsString('"Tổng cộng",15,160000,166750', $csv);
    }

    public function test_api_loi_van_ra_trang(): void
    {
        Http::fake([
            '*/admin/reports/goods*' => Http::response(['message' => 'Hỏng rồi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/reports/goods')
            ->assertOk()->assertSee('Hỏng rồi')->assertSee('Kỳ này chưa bán mặt hàng nào.');
    }
}
