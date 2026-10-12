<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM → Tổng quan.
 *
 * Soi: bộ lọc đi sang /admin/reports/crm đúng tên (kỳ mặc định 30 ngày, chi
 * nhánh riêng của màn, loại khách, năm, tháng), ô KPI và so kỳ trước, top khách
 * hai bảng, thẻ cần chăm sóc trỏ đúng bộ lọc của danh sách khách, trạng thái
 * trống, lỗi quyền, và tab Tổng quan của module CRM đã mở.
 */
class CrmDashboardTest extends TestCase
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

    protected function baoCao(): array
    {
        return [
            'from' => '2026-09-12', 'to' => '2026-10-11', 'prev_from' => '2026-08-13', 'prev_to' => '2026-09-11',
            'customer_type' => '', 'year' => 2026, 'year_min' => 2025, 'reg_month' => Carbon::today()->format('Y-m'),
            'kpis' => [
                'revenue' => 842560000, 'revenue_last_month' => 749600000, 'revenue_last_year' => 0,
                'orders' => 1284, 'orders_prev' => 1187, 'aov' => 656200, 'aov_prev' => 631500,
                'buyers' => 412, 'buyers_prev' => 380, 'once' => 298, 'twice' => 71,
                'repeat_rate' => 27.7, 'repeat_rate_prev' => 28.9, 'new_customers' => 96, 'new_customers_prev' => 81,
            ],
            'top_spend' => [
                ['user_id' => 12, 'name' => 'Công ty TNHH Minh Phát', 'code' => 'KH00012', 'customer_type' => 1, 'rank_name' => '', 'value' => 48250000, 'orders' => 9],
                ['user_id' => 187, 'name' => 'Nguyễn Thị Lan', 'code' => 'KH00187', 'customer_type' => 0, 'rank_name' => 'Vàng', 'value' => 21480000, 'orders' => 6],
            ],
            'top_debt' => [
                ['user_id' => 41, 'name' => 'Trần Quốc Bảo', 'code' => 'KH00041', 'customer_type' => 0, 'rank_name' => '', 'value' => 1850000, 'orders' => 1, 'deleted' => true],
            ],
            'growth' => [['month' => '2026-09', 'new' => 92, 'total' => 1800], ['month' => '2026-10', 'new' => 31, 'total' => 1831]],
            'visit_gap' => [['key' => '1w', 'label' => 'Trong 1 tuần', 'count' => 46], ['key' => 'none', 'label' => 'Chưa quay lại', 'count' => 298]],
            'purchase_count' => [['key' => '1', 'label' => 'Mua 1 lần', 'count' => 298], ['key' => '2', 'label' => 'Mua 2 lần', 'count' => 71]],
            'order_value' => [['key' => 'duoi_500k', 'label' => 'Dưới 500k', 'count' => 612]],
            'reg_daily' => [['key' => '2026-10-01', 'label' => '01/10', 'count' => 3]],
            'ranks' => [
                ['rank_id' => 0, 'name' => 'Chưa có hạng', 'existing' => 820, 'new' => 74, 'spend' => 312400000],
                ['rank_id' => 1, 'name' => 'Đồng', 'existing' => 214, 'new' => 31, 'spend' => 198700000],
            ],
            'gender' => [['key' => 'female', 'label' => 'Nữ', 'count' => 231], ['key' => 'male', 'label' => 'Nam', 'count' => 148], ['key' => 'other', 'label' => 'Chưa rõ', 'count' => 33]],
            'age' => [['key' => '25-35', 'label' => '25 – 35', 'count' => 121]],
            'provinces' => [['key' => 'Hà Nội', 'label' => 'Hà Nội', 'count' => 72]],
            'care' => ['birthdays_7d' => 14, 'lapsed_60d' => 63, 'debtors' => 9, 'debt_amount' => 18450000, 'near_rank' => 21],
        ];
    }

    protected function fakeApi($bc = null, int $ma = 200): void
    {
        Http::fake([
            '*/admin/reports/crm*' => Http::response($ma === 200 ? ['data' => $bc ?? $this->baoCao()] : ['message' => 'Không đủ quyền'], $ma),
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true],
                ['id' => 2, 'code' => 'CN02', 'name' => 'Kho Quận 7', 'is_active' => true],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function thamSoGoi(): array
    {
        $req = collect(Http::recorded())->map(fn ($r) => $r[0])
            ->first(fn (Request $r) => str_contains($r->url(), '/admin/reports/crm'));
        $this->assertNotNull($req, 'không gọi /admin/reports/crm');
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_mo_lan_dau_la_30_ngay_ca_cua_hang(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/crm')->assertOk();

        $q = $this->thamSoGoi();
        $this->assertSame(Carbon::today()->subDays(29)->format('Y-m-d'), $q['from']);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $q['to']);
        $this->assertSame('0', $q['shop_id'], 'chi nhánh là bộ lọc riêng của màn, mặc định cả cửa hàng');
        $this->assertArrayNotHasKey('customer_type', $q);

        $this->assertMatchesRegularExpression('#<h1 class="[^"]*tieu-de-trang[^"]*">Tổng quan CRM</h1>#u', $res->getContent());
        $res->assertSeeInOrder(['Doanh thu', '842.560.000', '▲ 12,4% so với tháng trước', 'Tổng số đơn', '1.284', '▲ 8,2% so với kỳ trước',
            'Giá trị TB / đơn', '656.200', 'Khách mua hàng', '412', 'Khách đăng ký mới', '96', 'Tỉ lệ quay lại', '27,7%', '▼ 1,2 điểm so với kỳ trước'], false);
        // Năm trước bằng 0: không có phần trăm để so.
        $res->assertSee('Mới phát sinh so với năm trước');
        $res->assertSee('Mua 1 lần: <b>298</b> · 2 lần: <b>71</b>', false);
    }

    public function test_top_khach_va_can_cham_soc(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/crm')->assertOk();

        $res->assertSeeInOrder(['Công ty TNHH Minh Phát', 'KH00012 · Doanh nghiệp · 9 đơn', '48.250.000', 'Nguyễn Thị Lan', 'KH00187 · Vàng · 6 đơn'], false);
        // Hồ sơ đã xoá: có tên + nhãn, không link sang danh sách khách (ở đó không còn).
        $res->assertSeeInOrder(['data-top-ds="debt"', 'Trần Quốc Bảo', 'Đã xoá', '1.850.000'], false);
        $res->assertDontSee('keyword=KH00041', false);

        $res->assertSeeInOrder(['14', 'sinh nhật trong 7 ngày tới', '63', 'chưa quay lại hơn 60 ngày', '9', 'còn nợ', '18.450.000 đ', '21', 'sắp lên hạng'], false);
        $html = $res->getContent();
        $this->assertStringContainsString('crm/customers?birthday_preset=7', $html);
        $this->assertStringContainsString('sort_by=total_debt&amp;sort_dir=desc', $html);
        $this->assertStringContainsString('last_tx_mode=custom', $html);
        // Hạng: "Chưa có hạng" ra khỏi biểu đồ, chỉ còn các hạng thật.
        $this->assertStringContainsString('"ranks":[{"rank_id":1,', $html);
    }

    public function test_bo_loc_loai_khach_chi_nhanh_ky_nam_thang(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get('/admin/crm?type=company&branch=2&range=7&year=2025&reg_month=2026-09')
            ->assertOk()
            ->assertSee('Kho Quận 7 · Khách doanh nghiệp');

        $q = $this->thamSoGoi();
        $this->assertSame(['company', '2', Carbon::today()->subDays(6)->format('Y-m-d'), '2025', '2026-09'],
            [$q['customer_type'], $q['shop_id'], $q['from'], $q['year'], $q['reg_month']]);
    }

    public function test_tu_chon_ngay_va_gia_tri_la_bi_bo(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->get('/admin/crm?from=01-09-2026&to=15-09-2026&type=xyz&branch=99&year=1900')->assertOk();

        $q = $this->thamSoGoi();
        $this->assertSame(['2026-09-01', '2026-09-15', '0'], [$q['from'], $q['to'], $q['shop_id']]);
        $this->assertArrayNotHasKey('customer_type', $q);
        $this->assertArrayNotHasKey('year', $q);
    }

    public function test_trong_thi_bao_trong_khong_ve_bieu_do_rong(): void
    {
        $bc = $this->baoCao();
        $bc['kpis'] = array_map(fn () => 0, $bc['kpis']);
        foreach (['top_spend', 'top_debt', 'age', 'provinces'] as $k) {
            $bc[$k] = [];
        }
        $bc['ranks'] = [['rank_id' => 0, 'name' => 'Chưa có hạng', 'existing' => 3, 'new' => 0, 'spend' => 0]];
        $bc['gender'] = [];
        $this->fakeApi($bc);

        $this->withSession($this->phien())->get('/admin/crm')->assertOk()
            ->assertSee('Kỳ này chưa khách nào có hồ sơ mua hàng.')
            ->assertSee('Không khách nào còn nợ đơn trong kỳ.')
            ->assertSee('Chưa khách nào có ngày sinh')
            ->assertSee('Kỳ này chưa có đơn giao hàng')
            ->assertSee('Cửa hàng chưa cài hạng thành viên')
            ->assertDontSee('id="crmSoLan"', false);
    }

    public function test_thieu_quyen_bao_ro(): void
    {
        $this->fakeApi(null, 403);

        $this->withSession($this->phien())->get('/admin/crm')->assertOk()
            ->assertSee('Bạn chưa được giao quyền xem khách hàng');
    }

    public function test_tab_tong_quan_crm_da_mo_va_module_vao_tong_quan(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/crm')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#admin/crm"\s+class="sub-nav-btn active"#', $html);
        $this->assertStringNotContainsString('onclick="return false;">Tổng quan</a>', $html);
    }

    /** Dưới 992px cột lọc ẩn: khối lọc nào cũng phải có nút mở riêng (trước đây thiếu nút Loại khách). */
    public function test_moi_khoi_loc_co_nut_tren_dien_thoai(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/crm')->assertOk()->getContent();

        foreach (['crmLocChiNhanh', 'crmLocLoai', 'crmLocKy'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
            $this->assertMatchesRegularExpression('/data-offcanvas-target="#?'.$id.'"/', $html, 'thiếu nút mở khối '.$id);
        }
    }
}
