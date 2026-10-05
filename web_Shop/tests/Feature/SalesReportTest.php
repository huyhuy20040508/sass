<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Doanh thu.
 *
 * Soi: bộ lọc đi sang /admin/reports/sales đúng tên (kỳ, chi nhánh, nguồn, hình
 * thức), bảng theo ngày đúng khuôn v2 (10 cột, màu, chân bảng), dữ liệu biểu đồ
 * sang JS, hộp chi tiết một ngày đổi nhãn đúng, và hai tab trong trang dẫn qua
 * lại được.
 */
class SalesReportTest extends TestCase
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
        $ngay = fn ($d, $dt, $mat, $ck, $qr, $tra = 0, $no = 0) => [
            'date' => $d, 'orders' => 2, 'revenue' => $dt - 4000, 'revenue_vat' => $dt, 'returns' => $tra,
            'paid' => $mat + $ck + $qr, 'cash' => $mat, 'transfer' => $ck, 'card' => 0, 'auto_qr' => $qr,
            'other' => 0, 'debt' => $no,
        ];

        return ['data' => [
            'from' => '2026-05-01', 'to' => '2026-05-10',
            'days' => [
                $ngay('2026-05-09', 3348000, 3348000, 0, 0),
                $ngay('2026-05-05', 458770, 121750, 20000, 317020, 45000),
            ],
            'totals' => ['date' => '', 'orders' => 4, 'revenue' => 3798770, 'revenue_vat' => 3806770, 'returns' => 45000,
                'paid' => 3806770, 'cash' => 3469750, 'transfer' => 20000, 'card' => 0, 'auto_qr' => 317020, 'other' => 0, 'debt' => 0],
            'by_day' => [['key' => '2026-05-05', 'orders' => 2, 'revenue' => 458770], ['key' => '2026-05-09', 'orders' => 2, 'revenue' => 3348000]],
            'by_hour' => [['key' => '11', 'orders' => 3, 'revenue' => 279750]],
            'by_weekday' => [['key' => '6', 'orders' => 2, 'revenue' => 3348000]],
            'by_month' => [['key' => '5', 'orders' => 4, 'revenue' => 3806770]],
        ]];
    }

    protected function fakeApi(?array $bao = null): void
    {
        Http::fake([
            '*/admin/reports/sales/orders*' => Http::response(['data' => [
                ['id' => 51, 'code' => 'SO0000051301', 'channel' => 'pos', 'payment_method' => 'cash', 'units' => 2,
                    'subtotal' => 3344444, 'surcharge' => 0, 'discount' => 0, 'revenue' => 3344444, 'vat' => 3556,
                    'total' => 3348000, 'paid' => 3348000, 'debt' => 0, 'returns' => 0,
                    'customer_code' => '', 'customer_name' => '', 'phone' => ''],
                ['id' => 52, 'code' => 'DH52', 'channel' => 'web', 'payment_method' => 'payos', 'units' => 1,
                    'subtotal' => 100000, 'surcharge' => 0, 'discount' => 0, 'revenue' => 100000, 'vat' => 0,
                    'total' => 100000, 'paid' => 100000, 'debt' => 0, 'returns' => 0,
                    'customer_code' => 'cus-00425', 'customer_name' => 'Duy Hoàng', 'phone' => '0909'],
            ]]),
            '*/admin/reports/sales*' => Http::response($bao ?? $this->baoMau()),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function thamSoGoi(string $duong = '/admin/reports/sales?'): array
    {
        $req = collect(Http::recorded())->map(fn ($r) => $r[0])
            ->first(fn (Request $r) => str_contains($r->url(), $duong));
        $this->assertNotNull($req, 'không gọi '.$duong);
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    protected function trang(string $url = '/admin/reports/sales'): string
    {
        return $this->withSession($this->phien())->get($url)->assertOk()->getContent();
    }

    public function test_bo_loc_di_sang_api(): void
    {
        $this->fakeApi();

        $this->trang('/admin/reports/sales?from_date=01-05-2026&to_date=10-05-2026&shop_id=0&channel=pos&methods=cash,auto_qr');

        $q = $this->thamSoGoi();
        $this->assertSame('2026-05-01', $q['from']);
        $this->assertSame('2026-05-10', $q['to']);
        $this->assertSame('0', $q['shop_id']);
        $this->assertSame('pos', $q['channel']);
        $this->assertSame('auto_qr,cash', $q['methods']);
    }

    public function test_tich_du_hinh_thuc_la_khong_loc(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/sales?methods=cash,bank_transfer,card,auto_qr&channel=zalo');

        $q = $this->thamSoGoi();
        $this->assertArrayNotHasKey('methods', $q);
        $this->assertArrayNotHasKey('channel', $q);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $q['from']);
        $this->assertMatchesRegularExpression('#id="ht_all"\s+checked#', $html);
    }

    public function test_bang_dung_khuon_v2(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/sales?range=thisMonth');

        foreach (['Thời gian', 'Tổng Doanh Thu', 'Tổng Doanh Thu (VAT)', 'Tổng tiền trả hàng', 'Đã thanh toán',
            'Tiền mặt', 'Chuyển khoản', 'Quẹt Thẻ', 'QR Tự động', 'Công nợ'] as $nhan) {
            $this->assertMatchesRegularExpression('#>'.preg_quote($nhan, '#').' <a class="btn_sort_table#u', $html);
        }
        // Ngày bấm được để mở hộp chi tiết; mới trước như API trả.
        $this->assertMatchesRegularExpression('#class="handle-data-modal" data-date="2026-05-09">09-05-2026</div>#', $html);
        $this->assertLessThan(strpos($html, 'data-date="2026-05-05"'), strpos($html, 'data-date="2026-05-09"'));
        // Màu cột như v2.
        $this->assertMatchesRegularExpression('#show_cash text-success\s*">3\.348\.000</td>#', $html);
        $this->assertMatchesRegularExpression('#show_returns text-danger\s*">45\.000</td>#', $html);
        $this->assertMatchesRegularExpression('#show_auto_qr text-warning\s*">317\.020</td>#', $html);
        // Chân bảng.
        $chan = substr($html, strpos($html, '<tfoot>'));
        $this->assertStringContainsString('Tổng cộng (2)</th>', $chan);
        $this->assertMatchesRegularExpression('#show_revenue_vat\s*">3\.806\.770</th>#', $chan);
        // Không có tiền "khác" thì không bày cột Khác.
        $this->assertStringNotContainsString('>Khác</th>', $html);
    }

    public function test_bieu_do_nhan_du_lieu_va_mo_dung_dang(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/sales?show=chart');

        $this->assertMatchesRegularExpression('#id="show-chart"\s+checked#', $html);
        $this->assertStringNotContainsString('id="show-chart" disabled', $html);
        $this->assertStringContainsString('"thang":[{"key":"5","orders":4,"revenue":3806770}]', $html);
        $this->assertStringContainsString('Doanh thu theo tháng (<span class="text-success">3.806.770 VND</span>)', $html);
        // Dạng Biểu đồ: khung biểu đồ hiện, bảng ẩn.
        $this->assertMatchesRegularExpression('#<div class="row\s*" data-dang="chart">#', $html);
        $this->assertMatchesRegularExpression('#class="table-responsive d-none" data-dang="table"#', $html);
    }

    public function test_hop_chi_tiet_ngay(): void
    {
        $this->fakeApi();

        $r = $this->withSession($this->phien())
            ->getJson('/admin/reports/sales/orders?date=2026-05-09&channel=pos&methods=cash&sort_field=cash')
            ->assertOk();

        $q = $this->thamSoGoi('/admin/reports/sales/orders');
        $this->assertSame('2026-05-09', $q['date']);
        $this->assertSame('pos', $q['channel']);
        $this->assertSame('cash', $q['methods']);
        $this->assertArrayNotHasKey('from', $q);

        $r->assertJsonPath('tieu_de', 'Chi tiết báo cáo bán hàng 09/05/2026')
            ->assertJsonPath('chi_nhanh', 'Kho trung tâm')
            ->assertJsonPath('data.0.nguon', 'Tại quầy')
            ->assertJsonPath('data.0.hinh_thuc', 'Tiền mặt')
            ->assertJsonPath('data.1.nguon', 'Online')
            ->assertJsonPath('data.1.hinh_thuc', 'QR tự động');
    }

    public function test_hop_chi_tiet_ngay_sai(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->getJson('/admin/reports/sales/orders?date=hom-qua')->assertStatus(422);
    }

    public function test_hai_tab_dan_qua_lai(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        $dayTab = substr($html, strpos($html, '<ul class="nav nav-tabs cus-nav-tabs'));
        $dayTab = substr($dayTab, 0, strpos($dayTab, '</ul>'));
        $this->assertMatchesRegularExpression('#nav-link active" data-type="sales"#', $dayTab);
        $this->assertMatchesRegularExpression('#data-type="customer" href="[^"]*/admin/reports/customers"#', $dayTab);
        // Header: tab "Báo cáo cuối ngày" của module Báo cáo đang sáng.
        $this->assertMatchesRegularExpression('#reports/sales"\s+class="sub-nav-btn active"#', $html);
    }

    public function test_xuat_theo_bang(): void
    {
        $this->fakeApi();

        $csv = $this->withSession($this->phien())->get('/admin/reports/sales?xuat=excel')->assertOk()->streamedContent();

        $this->assertStringContainsString('"Tổng Doanh Thu (VAT)"', $csv);
        $this->assertStringContainsString('2026-05-09', $csv);
        $this->assertStringContainsString('"Tổng cộng (2)"', $csv);
    }

    public function test_api_loi_van_ra_trang(): void
    {
        Http::fake([
            '*/admin/reports/sales*' => Http::response(['message' => 'Hỏng rồi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/reports/sales')
            ->assertOk()->assertSee('Hỏng rồi')->assertSee('Kỳ này chưa có doanh thu.');
    }
}
