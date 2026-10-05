<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Báo cáo → Báo cáo kết ca.
 *
 * Soi ba thứ: bộ lọc đi sang /admin/reports/shifts đúng tên và định dạng, bảng
 * in đúng 19 cột (ca còn mở KHÔNG in 0 ở phần đối chiếu két), và bản xuất đọc
 * hết các trang của cùng bộ lọc.
 */
class ShiftReportTest extends TestCase
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

    protected function caMau(): array
    {
        return [
            'data' => [
                ['id' => 9, 'shop_id' => 1, 'employee_code' => 'NV001', 'employee_name' => 'Lê Thu Ngân',
                    'opened_at' => '2026-09-12T07:58:00+07:00', 'closed_at' => '2026-09-12T15:02:00+07:00',
                    'opened_by_name' => 'Lê Thu Ngân', 'closed_by_name' => 'Chủ tiệm',
                    'order_count' => 42, 'total_cash' => 3250000, 'total_transfer' => 1480000,
                    'total_card' => 0, 'total_auto_qr' => 0, 'total_revenue' => 4730000,
                    'opening_cash' => 500000, 'expected_cash' => 3700000, 'counted_cash' => 3650000,
                    'difference' => -50000, 'open_note' => 'Két đủ tiền lẻ', 'close_note' => 'Thiếu 50 nghìn'],
                // Ca còn mở: chưa đếm két — ba cột đối chiếu phải để trống.
                ['id' => 10, 'shop_id' => 1, 'employee_code' => '', 'employee_name' => '',
                    'opened_at' => '2026-09-12T15:05:00+07:00', 'closed_at' => null,
                    'opened_by_name' => 'Trần Ca Chiều', 'closed_by_name' => '',
                    'order_count' => 3, 'total_cash' => 120000, 'total_transfer' => 0,
                    'total_card' => 0, 'total_auto_qr' => 0, 'total_revenue' => 120000,
                    'opening_cash' => 3650000, 'expected_cash' => null, 'counted_cash' => null,
                    'difference' => null, 'open_note' => '', 'close_note' => ''],
            ],
            'meta' => ['page' => 1, 'page_size' => 10, 'total' => 2, 'total_pages' => 1,
                'tong' => ['total_revenue' => 9990000, 'order_count' => 45]],
        ];
    }

    protected function fakeApi(array $ca = null): void
    {
        Http::fake([
            '*/admin/reports/shifts*' => Http::response($ca ?? $this->caMau()),
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true],
                ['id' => 2, 'code' => 'CN02', 'name' => 'Kho Quận 7', 'is_active' => true],
            ]]),
            '*/admin/users*' => Http::response(['data' => [['id' => 5, 'full_name' => 'Lê Thu Ngân']]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Lượt gọi báo cáo ca đầu tiên, dạng mảng tham số. */
    protected function thamSoGoi(): array
    {
        $req = collect(Http::recorded())->map(fn ($r) => $r[0])
            ->first(fn (Request $r) => str_contains($r->url(), '/admin/reports/shifts'));
        $this->assertNotNull($req, 'không gọi /admin/reports/shifts');
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_mo_lan_dau_la_thang_nay_cua_chi_nhanh_dang_lam(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->get('/admin/shift-report')->assertOk();

        $q = $this->thamSoGoi();
        $this->assertSame(Carbon::today()->startOfMonth()->format('Y-m-d'), $q['from']);
        $this->assertSame(Carbon::today()->endOfMonth()->format('Y-m-d'), $q['to']);
        $this->assertSame('1', $q['shop_id']);
        $this->assertSame('1', $q['page']);
        $this->assertSame('10', $q['page_size']);
        $this->assertArrayNotHasKey('user_id', $q);
        $this->assertArrayNotHasKey('keyword', $q);
    }

    public function test_bo_loc_di_sang_api_dung_ten(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get('/admin/shift-report?shop_id=2&from_date=01-08-2026&to_date=31-08-2026&user_id=5&keyword=NV001&page_size=50')
            ->assertOk();

        $q = $this->thamSoGoi();
        $this->assertSame('2026-08-01', $q['from']);
        $this->assertSame('2026-08-31', $q['to']);
        $this->assertSame('2', $q['shop_id']);
        $this->assertSame('5', $q['user_id']);
        $this->assertSame('NV001', $q['keyword']);
        $this->assertSame('50', $q['page_size']);
    }

    public function test_moc_nhanh_thang_truoc(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->get('/admin/shift-report?range=lastMonth')->assertOk();

        $q = $this->thamSoGoi();
        $thangTruoc = Carbon::today()->subMonthNoOverflow();
        $this->assertSame($thangTruoc->copy()->startOfMonth()->format('Y-m-d'), $q['from']);
        $this->assertSame($thangTruoc->copy()->endOfMonth()->format('Y-m-d'), $q['to']);
    }

    public function test_bang_du_19_cot_va_so_lieu_tung_ca(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/shift-report')->assertOk()->getContent();

        foreach (\App\Http\Controllers\ShiftReportController::COT_BANG as $nhan) {
            $this->assertStringContainsString('>'.$nhan.'</th>', $html);
        }
        $this->assertStringContainsString('NV001', $html);
        $this->assertStringContainsString('07:58', $html);
        $this->assertStringContainsString('15:02', $html);
        $this->assertStringContainsString('3.250.000', $html);
        $this->assertStringContainsString('1.480.000', $html);
        $this->assertStringContainsString('3.650.000', $html);
        $this->assertStringContainsString('-50.000', $html);
        $this->assertStringContainsString('12-09-2026', $html);
        $this->assertStringContainsString('Thiếu 50 nghìn', $html);
        // Tổng tiền lấy từ tổng CẢ KỲ của API, không cộng trên trang đang xem.
        $this->assertStringContainsString('9.990.000 đ', $html);
        // Nằm ở module BÁO CÁO: ô Báo cáo sáng, ô Thống kê không, tab kết ca sáng.
        $this->assertMatchesRegularExpression('#icon-item me-xl-2 active">\s*<a href="[^"]*/admin/reports/summary"#', $html);
        $this->assertDoesNotMatchRegularExpression('#icon-item me-xl-2 active">\s*<a href="[^"]*/admin/customers"#', $html);
        $this->assertMatchesRegularExpression('#shift-report"\s+class="sub-nav-btn active"#', $html);
    }

    public function test_ca_dang_mo_de_trong_phan_doi_chieu_ket(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/shift-report')->assertOk()->getContent();

        $dong = substr($html, strpos($html, 'data-id="10"'));
        $dong = substr($dong, 0, strpos($dong, '</tr>'));
        $this->assertStringContainsString('Đang mở', $dong);
        // Không có tên nhân sự thì lấy người mở ca.
        $this->assertStringContainsString('Trần Ca Chiều', $dong);
        foreach (['closing_cash', 'cash_delivery', 'cash_difference'] as $cot) {
            $this->assertMatchesRegularExpression('#show_'.$cot.'[^"]*">\s*</td>#', $dong, $cot.' phải để trống');
        }
    }

    public function test_an_cot_theo_hide(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/shift-report?hide=open_note,auto_qr')
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<th class="show_auto_qr text-right hide">#', $html);
        $this->assertMatchesRegularExpression('#<th class="show_open_note text-left hide">#', $html);
        $this->assertMatchesRegularExpression('#<th class="show_cash text-right ">#', $html);
    }

    public function test_api_loi_van_ra_trang(): void
    {
        Http::fake([
            '*/admin/reports/shifts*' => Http::response(['message' => 'Hỏng rồi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/shift-report')
            ->assertOk()
            ->assertSee('Kỳ này chưa có ca nào.')
            ->assertSee('Hỏng rồi');
    }

    public function test_trang_qua_so_trang_thi_lui_ve_trang_cuoi(): void
    {
        $ca = $this->caMau();
        $ca['meta'] = ['page' => 5, 'page_size' => 10, 'total' => 12, 'total_pages' => 2];
        $ca['data'] = [];
        $this->fakeApi($ca);

        $this->withSession($this->phien())->get('/admin/shift-report?page=5')
            ->assertRedirect()
            ->assertRedirectContains('page=2');
    }

    public function test_xuat_doc_het_cac_trang(): void
    {
        $trang = fn ($p) => [
            'data' => [array_merge($this->caMau()['data'][0], ['id' => $p, 'employee_name' => 'Ca trang '.$p])],
            'meta' => ['page' => $p, 'page_size' => 100, 'total' => 2, 'total_pages' => 2],
        ];
        Http::fake([
            '*/admin/reports/shifts*page=1*' => Http::response($trang(1)),
            '*/admin/reports/shifts*page=2*' => Http::response($trang(2)),
            '*' => Http::response(['data' => []]),
        ]);

        $csv = $this->withSession($this->phien())->get('/admin/shift-report/export?range=thisMonth')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('"Mã nhân sự","Tên nhân sự","Giờ mở ca"', $csv);
        $this->assertStringContainsString('Ca trang 1', $csv);
        $this->assertStringContainsString('Ca trang 2', $csv);
    }
}
