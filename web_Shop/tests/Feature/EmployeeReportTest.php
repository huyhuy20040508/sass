<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Báo cáo nhân viên (hoa hồng).
 *
 * Soi: bộ lọc đi sang /admin/reports/employees, bảng đúng khuôn v2 (6 cột + STT,
 * chân bảng), đơn của từng người nhúng sẵn cho hộp chi tiết, không có dạng Biểu
 * đồ, và tab "Báo cáo hoa hồng" đã ẩn khỏi dãy tab.
 */
class EmployeeReportTest extends TestCase
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
        $don = ['id' => 51, 'code' => 'DH000051', 'created_at' => '2026-05-09T14:05:00+07:00', 'customer_name' => 'Duy Hoàng',
            'products' => 'Áo thun, Nón', 'quantity' => 3, 'revenue' => 250000, 'revenue_vat' => 270000, 'returned' => 90000,
            'commission_base' => 180000, 'commission' => 9000];

        return ['data' => [
            'from' => '2026-05-01', 'to' => '2026-05-10',
            'rows' => [
                ['user_id' => 5, 'employee_code' => 'NV0001', 'name' => 'Quốc Huy', 'commission_rate' => 5, 'order_count' => 1,
                    'revenue' => 250000, 'revenue_vat' => 270000, 'returned' => 90000, 'commission_base' => 180000,
                    'commission' => 9000, 'orders' => [$don]],
                ['user_id' => 7, 'employee_code' => '', 'name' => 'Thu ngân Lan', 'commission_rate' => 0, 'order_count' => 1,
                    'revenue' => 50000, 'revenue_vat' => 50000, 'returned' => 0, 'commission_base' => 50000,
                    'commission' => 0, 'orders' => [['id' => 52, 'code' => 'DH52', 'created_at' => '2026-05-08T09:00:00+07:00',
                        'customer_name' => '', 'products' => 'Nón', 'quantity' => 1, 'revenue' => 50000, 'revenue_vat' => 50000,
                        'returned' => 0, 'commission_base' => 50000, 'commission' => 0]]],
            ],
            'totals' => ['user_id' => 0, 'employee_code' => '', 'name' => '', 'commission_rate' => 0, 'order_count' => 2,
                'revenue' => 300000, 'revenue_vat' => 320000, 'returned' => 90000, 'commission_base' => 230000, 'commission' => 9000],
        ]];
    }

    protected function fakeApi(?array $bao = null): void
    {
        Http::fake([
            '*/admin/reports/employees*' => Http::response($bao ?? $this->baoMau()),
            '*/admin/users*' => Http::response(['data' => [
                ['id' => 5, 'full_name' => 'Quốc Huy', 'quyen' => ['quan_ly', 'thu_ngan']],
                ['id' => 7, 'full_name' => 'Thu ngân Lan', 'quyen' => ['thu_ngan']],
            ]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function thamSoGoi(): array
    {
        $req = collect(Http::recorded())->map(fn ($r) => $r[0])
            ->first(fn (Request $r) => str_contains($r->url(), '/admin/reports/employees'));
        $this->assertNotNull($req, 'không gọi /admin/reports/employees');
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    protected function trang(string $url = '/admin/reports/employees'): string
    {
        return $this->withSession($this->phien())->get($url)->assertOk()->getContent();
    }

    public function test_bo_loc_di_sang_api(): void
    {
        $this->fakeApi();

        $this->trang('/admin/reports/employees?from_date=01-05-2026&to_date=10-05-2026&shop_id=0&channel=pos&area=thu_ngan&user_id=7&keyword=DH52');

        $q = $this->thamSoGoi();
        $this->assertSame('2026-05-01', $q['from']);
        $this->assertSame('2026-05-10', $q['to']);
        $this->assertSame('0', $q['shop_id']);
        $this->assertSame('pos', $q['channel']);
        $this->assertSame('thu_ngan', $q['area']);
        $this->assertSame('7', $q['user_id']);
        $this->assertSame('DH52', $q['keyword']);
    }

    public function test_mo_lan_dau_va_tham_so_la(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/employees?area=bep&user_id=x');

        $q = $this->thamSoGoi();
        $this->assertSame(Carbon::today()->format('Y-m-d'), $q['from']);
        $this->assertArrayNotHasKey('area', $q);
        $this->assertArrayNotHasKey('user_id', $q);
        // Như v2: tab này không có dạng Biểu đồ.
        $this->assertStringContainsString('id="show-chart" disabled', $html);
    }

    public function test_bang_dung_khuon_v2(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        $dau = substr($html, strpos($html, 'bang-nhan-vien'));
        $dau = substr($dau, 0, strpos($dau, '</thead>'));
        preg_match_all('#<th>([^<]+)</th>#u', $dau, $m);
        $this->assertSame(['STT', 'Tên nhân viên', 'Tổng Doanh Thu', 'Tổng doanh thu (VAT)', 'Tổng tiền trả hàng',
            'Doanh thu tính hoa hồng', 'Hoa hồng'], $m[1]);

        $this->assertStringContainsString('<span class="nv-ten" data-id="5">Quốc Huy</span>', $html);
        $this->assertStringContainsString('(NV0001)', $html);
        $this->assertMatchesRegularExpression('#col_commission_base">180\.000</td>#', $html);

        $chan = substr($html, strpos($html, '<tfoot>'));
        $chan = substr($chan, 0, strpos($chan, '</tfoot>'));
        $this->assertStringContainsString('colspan="2">Tổng cộng</th>', $chan);
        $this->assertMatchesRegularExpression('#col_revenue_vat">320\.000</th>#', $chan);
        $this->assertMatchesRegularExpression('#col_commission">9\.000</th>#', $chan);
        // Không có nút chọn cột như v2.
        $this->assertStringNotContainsString('class="form-check-input show_col"', $html);
    }

    public function test_don_cua_tung_nguoi_cho_hop_chi_tiet(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        preg_match('#<script type="application/json" id="nv-don">(.*?)</script>#s', $html, $m);
        $du = json_decode($m[1], true);
        $this->assertSame(['5', '7'], array_map('strval', array_keys($du)));
        $this->assertSame('Quốc Huy', $du['5']['name']);
        $this->assertEquals(5, $du['5']['rate']);
        $this->assertSame('DH000051', $du['5']['orders'][0]['code']);
        $this->assertSame('09-05-2026 14:05', $du['5']['orders'][0]['ngay']);
        $this->assertStringContainsString("'Báo cáo nhân viên - '", $html);
    }

    public function test_chua_co_ai_van_co_khung_rong(): void
    {
        $this->fakeApi(['data' => ['from' => '2026-05-01', 'to' => '2026-05-01', 'rows' => [], 'totals' => []]]);

        $html = $this->trang();

        $this->assertStringContainsString('Không có dữ liệu', $html);
        // Đơn nhúng là object rỗng chứ không phải mảng — JS tra theo user_id.
        $this->assertStringContainsString('id="nv-don">{}</script>', $html);
    }

    public function test_an_tab_hoa_hong(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        $dayTab = substr($html, strpos($html, '<ul class="nav nav-tabs cus-nav-tabs'));
        $dayTab = substr($dayTab, 0, strpos($dayTab, '</ul>'));
        $this->assertStringNotContainsString('Báo cáo hoa hồng', $dayTab);
        $this->assertStringNotContainsString('data-type="commission"', $dayTab);
        $this->assertMatchesRegularExpression('#nav-link active" data-type="commissionEmployee"#', $dayTab);
        $this->assertMatchesRegularExpression('#data-type="employee" href="[^"]*/admin/reports/staff"#', $dayTab);
        $this->assertMatchesRegularExpression('#reports/sales"\s+class="sub-nav-btn active"#', $html);
    }

    public function test_xuat_theo_bang(): void
    {
        $this->fakeApi();

        $csv = $this->withSession($this->phien())->get('/admin/reports/employees?xuat=excel')->assertOk()->streamedContent();

        $this->assertStringContainsString('"Doanh thu tính hoa hồng"', $csv);
        $this->assertStringContainsString('1,"Quốc Huy",250000,270000,90000,180000,9000', $csv);
        $this->assertStringContainsString('"Tổng cộng",,300000,320000,90000,230000,9000', $csv);
    }

    public function test_api_loi_van_ra_trang(): void
    {
        Http::fake([
            '*/admin/reports/employees*' => Http::response(['message' => 'Hỏng rồi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/reports/employees')
            ->assertOk()->assertSee('Hỏng rồi')->assertSee('Không có dữ liệu');
    }
}
