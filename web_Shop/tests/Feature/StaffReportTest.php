<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Báo cáo ca.
 *
 * Soi: bộ lọc đi sang /admin/reports/staff đúng tên, chọn nhóm thì ô Tên chỉ còn
 * người của nhóm, cột "Mã ca" in đúng ba loại dòng, chân bảng lấy giá trị TB của
 * API (không cộng dồn), dữ liệu biểu đồ sang JS, và tab trong trang sáng đúng chỗ.
 */
class StaffReportTest extends TestCase
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
        $dong = fn ($ngay, $loai, $ca, $id, $ma, $ten, $don, $tien) => [
            'date' => $ngay, 'kind' => $loai, 'shift_id' => $ca, 'user_id' => $id, 'employee_code' => $ma,
            'name' => $ten, 'order_count' => $don, 'revenue' => $tien, 'avg_order' => $tien / $don,
        ];

        return ['data' => [
            'from' => '2026-05-01', 'to' => '2026-05-10',
            'rows' => [
                $dong('2026-05-09', 'shift', 12, 5, 'NV0001', 'Quốc Huy', 4, 400000),
                $dong('2026-05-09', 'online', 0, 7, '', 'Thu ngân Lan', 1, 50000),
                $dong('2026-05-08', 'none', 0, 5, 'NV0001', 'Quốc Huy', 1, 90000),
            ],
            'totals' => ['date' => '', 'kind' => '', 'shift_id' => 0, 'user_id' => 0, 'employee_code' => '', 'name' => '',
                'order_count' => 6, 'revenue' => 540000, 'avg_order' => 90000],
            'by_staff' => [
                ['user_id' => 5, 'name' => 'Quốc Huy', 'order_count' => 5, 'revenue' => 490000, 'avg_order' => 98000],
                ['user_id' => 7, 'name' => 'Thu ngân Lan', 'order_count' => 1, 'revenue' => 50000, 'avg_order' => 50000],
            ],
        ]];
    }

    protected function fakeApi(?array $bao = null): void
    {
        Http::fake([
            '*/admin/reports/staff*' => Http::response($bao ?? $this->baoMau()),
            '*/admin/users*' => Http::response(['data' => [
                ['id' => 5, 'full_name' => 'Quốc Huy', 'quyen' => ['quan_ly', 'thu_ngan']],
                ['id' => 7, 'full_name' => 'Thu ngân Lan', 'quyen' => ['thu_ngan']],
                ['id' => 9, 'full_name' => 'Chưa giao cửa', 'quyen' => []],
            ]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function thamSoGoi(): array
    {
        $req = collect(Http::recorded())->map(fn ($r) => $r[0])
            ->first(fn (Request $r) => str_contains($r->url(), '/admin/reports/staff'));
        $this->assertNotNull($req, 'không gọi /admin/reports/staff');
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    protected function trang(string $url = '/admin/reports/staff'): string
    {
        return $this->withSession($this->phien())->get($url)->assertOk()->getContent();
    }

    public function test_bo_loc_di_sang_api(): void
    {
        $this->fakeApi();

        $this->trang('/admin/reports/staff?from_date=01-05-2026&to_date=10-05-2026&shop_id=0&channel=pos&area=thu_ngan&user_id=7&keyword=Lan');

        $q = $this->thamSoGoi();
        $this->assertSame('2026-05-01', $q['from']);
        $this->assertSame('2026-05-10', $q['to']);
        $this->assertSame('0', $q['shop_id']);
        $this->assertSame('pos', $q['channel']);
        $this->assertSame('thu_ngan', $q['area']);
        $this->assertSame('7', $q['user_id']);
        $this->assertSame('Lan', $q['keyword']);
    }

    public function test_tham_so_la_thi_bo(): void
    {
        $this->fakeApi();

        $this->trang('/admin/reports/staff?area=bep&user_id=abc');

        $q = $this->thamSoGoi();
        $this->assertSame(Carbon::today()->format('Y-m-d'), $q['from']);
        $this->assertArrayNotHasKey('area', $q);
        $this->assertArrayNotHasKey('user_id', $q);
    }

    public function test_chon_nhom_thi_o_ten_chi_con_nguoi_cua_nhom(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/staff?area=thu_ngan');
        $this->assertMatchesRegularExpression('#<option value="thu_ngan" selected>Thu ngân</option>#u', $html);
        $this->assertStringContainsString('<option value="7" >Thu ngân Lan</option>', $html);
        $this->assertStringContainsString('<option value="5" >Quốc Huy</option>', $html);
        $this->assertStringNotContainsString('Chưa giao cửa', $html);

        $html = $this->trang('/admin/reports/staff?area=quan_ly');
        $this->assertStringNotContainsString('Thu ngân Lan</option>', $html);

        $html = $this->trang();
        $this->assertStringContainsString('Chưa giao cửa</option>', $html);
    }

    public function test_bang_dung_khuon_v2(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        foreach (['Ngày', 'Mã ca', 'Mã nhân viên', 'Tên nhân sự', 'Tổng số đơn', 'Doanh thu(VAT)', 'Giá trị TB'] as $nhan) {
            $this->assertMatchesRegularExpression('#>'.preg_quote($nhan, '#').' <a class="btn_sort_table#u', $html);
        }
        // Ba loại dòng của cột Mã ca.
        $this->assertMatchesRegularExpression('#show_shift\s*"\s*>Ca \#12</td>#', $html);
        $this->assertMatchesRegularExpression('#show_shift ca-khac\s*"\s*>Online</td>#', $html);
        $this->assertMatchesRegularExpression('#show_shift ca-khac\s*"\s+title="Đơn bán tại quầy lúc không có ca nào mở"\s*>Ngoài ca</td>#u', $html);
        $this->assertStringContainsString('>09-05-2026</td>', $html);
        $this->assertMatchesRegularExpression('#show_avg_order\s*">100\.000</td>#', $html);

        $chan = substr($html, strpos($html, '<tfoot>'));
        $chan = substr($chan, 0, strpos($chan, '</tfoot>'));
        $this->assertStringContainsString('colspan="5">Tổng cộng</th>', $chan);
        $this->assertMatchesRegularExpression('#show_revenue\s*">540\.000</th>#', $chan);
        // Giá trị TB của API (tổng / đơn), không cộng dồn TB từng dòng.
        $this->assertMatchesRegularExpression('#show_avg_order\s*">90\.000</th>#', $chan);
    }

    public function test_sap_xep_va_an_cot(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/staff?sort_field=revenue&sort_type=asc&hide=stt,shift');
        $this->assertLessThan(strpos($html, '>90.000</td>'), strpos($html, '>50.000</td>'));
        $this->assertStringContainsString('colspan="3">Tổng cộng</th>', $html);

        $html = $this->trang('/admin/reports/staff?sort_field=shift&sort_type=asc');
        $this->assertLessThan(strpos($html, '>Ngoài ca</td>'), strpos($html, '>Ca #12</td>'));
    }

    public function test_bieu_do_nhan_du_lieu(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/staff?show=chart');

        $this->assertMatchesRegularExpression('#id="show-chart"\s+checked#', $html);
        $this->assertStringContainsString('{"user_id":7,"name":"Thu ng\\u00e2n Lan","order_count":1,"revenue":50000', $html);
        $this->assertStringContainsString('Tổng số đơn hàng (6)', $html);
        $this->assertMatchesRegularExpression('#<div class="row\s*" data-dang="chart">#', $html);
    }

    public function test_tab_trong_trang(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        $dayTab = substr($html, strpos($html, '<ul class="nav nav-tabs cus-nav-tabs'));
        $dayTab = substr($dayTab, 0, strpos($dayTab, '</ul>'));
        $this->assertMatchesRegularExpression('#nav-link active" data-type="employee"#', $dayTab);
        $this->assertMatchesRegularExpression('#data-type="expense" href="[^"]*/admin/reports/profit"#', $dayTab);
        $this->assertMatchesRegularExpression('#reports/sales"\s+class="sub-nav-btn active"#', $html);
        $this->assertLessThan(strpos($html, 'class="mx-0 list"'), strpos($html, 'placeholder="Tìm theo tên/mã nhân viên"'));
    }

    public function test_xuat_theo_bang(): void
    {
        $this->fakeApi();

        $csv = $this->withSession($this->phien())->get('/admin/reports/staff?xuat=excel')->assertOk()->streamedContent();

        $this->assertStringContainsString('"Doanh thu(VAT)"', $csv);
        $this->assertStringContainsString('1,2026-05-09,"Ca #12",NV0001,"Quốc Huy",4,400000,100000', $csv);
        $this->assertStringContainsString(',"Tổng cộng",,,,6,540000,90000', $csv);
    }

    public function test_api_loi_van_ra_trang(): void
    {
        Http::fake([
            '*/admin/reports/staff*' => Http::response(['message' => 'Hỏng rồi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/reports/staff')
            ->assertOk()->assertSee('Hỏng rồi')->assertSee('Kỳ này chưa có doanh thu theo ca.');
    }
}
