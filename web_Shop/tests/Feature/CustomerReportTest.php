<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Báo cáo → Báo cáo cuối ngày, tab Khách hàng — dựng theo khuôn v2.
 *
 * Soi: bộ lọc (nhóm, một khách, từ khoá, nguồn đơn, ngày dd-mm-yyyy) đi sang API,
 * các cột lấy đúng trường hồ sơ khách, dòng "Bán cho người tiêu dùng", chân bảng
 * "Tổng cộng", và dãy tab trong trang đúng chữ v2 (không có Tổng hợp, không có
 * Doanh thu theo bàn).
 */
class CustomerReportTest extends TestCase
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
            'totals' => ['orders' => 6, 'revenue' => 700000, 'guest_orders' => 3, 'guest_revenue' => 270000, 'guest_paid' => 270000],
            'top' => [
                ['user_id' => 7, 'name' => 'Duy Hoàng', 'customer_code' => 'cus-00425', 'group_name' => 'Khách vãng lai',
                    'rank_name' => 'LUXURY', 'points' => 68181, 'orders' => 2, 'revenue' => 300000, 'aov' => 150000,
                    'paid' => 185000, 'debt' => 115000],
                ['user_id' => 8, 'name' => 'kh 01', 'customer_code' => '', 'group_name' => '', 'rank_name' => '',
                    'points' => 0, 'orders' => 1, 'revenue' => 130000, 'aov' => 130000, 'paid' => 130000, 'debt' => 0],
            ],
        ]];
    }

    protected function fakeApi(?array $bao = null): void
    {
        Http::fake([
            '*/admin/reports/customers*' => Http::response($bao ?? $this->baoMau()),
            '*/admin/customer-groups*' => Http::response(['data' => [['id' => 3, 'name' => 'Khách vãng lai'], ['id' => 4, 'name' => 'Khách sỉ']]]),
            '*/admin/customers*' => Http::response(['data' => [
                ['id' => 7, 'full_name' => 'Duy Hoàng', 'customer_group_id' => 3],
                ['id' => 8, 'full_name' => 'kh 01', 'customer_group_id' => 4],
            ]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function thamSoGoi(): array
    {
        $req = collect(Http::recorded())->map(fn ($r) => $r[0])
            ->first(fn (Request $r) => str_contains($r->url(), '/admin/reports/customers'));
        $this->assertNotNull($req, 'không gọi /admin/reports/customers');
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    protected function trang(string $url = '/admin/reports/customers'): string
    {
        return $this->withSession($this->phien())->get($url)->assertOk()->getContent();
    }

    public function test_bo_loc_di_sang_api(): void
    {
        $this->fakeApi();

        $this->trang('/admin/reports/customers?from_date=01-05-2026&to_date=31-05-2026&customer_group_id=3&user_id=7&keyword=Duy&channel=pos');

        $q = $this->thamSoGoi();
        $this->assertSame('2026-05-01', $q['from']);
        $this->assertSame('2026-05-31', $q['to']);
        $this->assertSame('3', $q['customer_group_id']);
        $this->assertSame('7', $q['user_id']);
        $this->assertSame('Duy', $q['keyword']);
        $this->assertSame('pos', $q['channel']);
        // v2 không phân trang tab này — lấy đủ tới trần của API.
        $this->assertSame('100', $q['limit']);
    }

    public function test_mo_lan_dau_la_hom_nay(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/customers?channel=zalo');

        $q = $this->thamSoGoi();
        $hom = Carbon::today();
        $this->assertSame($hom->format('Y-m-d'), $q['from']);
        $this->assertArrayNotHasKey('channel', $q);
        $this->assertArrayNotHasKey('user_id', $q);
        $this->assertMatchesRegularExpression('#id="ky_today"[^>]*\schecked#', $html);
        $this->assertStringContainsString('Ngày '.$hom->format('d-m-Y').' 00:00 / '.$hom->format('d-m-Y').' 23:59 · (Kho trung tâm)', $html);
    }

    public function test_bang_dung_khuon_v2(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        // Nhãn cột đúng chữ v2, cột nào cũng có nút sắp xếp.
        foreach (['Mã khách hàng', 'Tên khách hàng', 'Tên nhóm', 'Hạng', 'Tổng chi', 'Giá trị TB',
            'Điểm tích lũy', 'Thanh toán', 'Công nợ', 'Tổng số đơn'] as $nhan) {
            $this->assertMatchesRegularExpression('#>'.preg_quote($nhan, '#').' <a class="btn_sort_table#u', $html);
        }
        foreach (['cus-00425', 'Duy Hoàng', 'Khách vãng lai', 'LUXURY', '68.181', '185.000'] as $chu) {
            $this->assertStringContainsString($chu, $html);
        }
        // Công nợ chữ đỏ; không nợ thì gạch ngang. Khách chưa có mã thì "-".
        $this->assertMatchesRegularExpression('#show_debt text-danger\s*">115\.000</td>#', $html);
        $this->assertMatchesRegularExpression('#show_debt text-danger\s*">-</td>#', $html);
        $this->assertMatchesRegularExpression('#show_code\s*">-</td>\s*<td class="text-left show_name\s*">kh 01#', $html);
    }

    public function test_dong_khach_le_va_chan_bang(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        $viTriLe = strpos($html, 'Bán cho người tiêu dùng');
        $this->assertNotFalse($viTriLe, 'thiếu dòng khách lẻ');
        $this->assertLessThan(strpos($html, 'Duy Hoàng</td>'), $viTriLe, 'dòng khách lẻ phải đứng đầu');

        $chan = substr($html, strpos($html, '<tfoot>'));
        $chan = substr($chan, 0, strpos($chan, '</tfoot>'));
        $this->assertStringContainsString('colspan="5">Tổng cộng</th>', $chan);
        // 270.000 + 300.000 + 130.000; giá trị TB = tổng / 6 đơn, không cộng dồn.
        $this->assertStringContainsString('>700.000</th>', $chan);
        $this->assertStringContainsString('>116.667</th>', $chan);
        $this->assertMatchesRegularExpression('#show_debt text-danger\s*">115\.000</th>#', $chan);
        $this->assertMatchesRegularExpression('#show_total_order\s*">6</th>#', $chan);
    }

    public function test_loc_mot_khach_thi_an_dong_khach_le(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/customers?user_id=7');

        $this->assertStringNotContainsString('Bán cho người tiêu dùng', $html);
        $this->assertMatchesRegularExpression('#<option value="7" selected>Duy Hoàng</option>#', $html);
    }

    public function test_chon_nhom_thi_o_khach_chi_con_khach_cua_nhom(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/customers?customer_group_id=4&channel=web');

        $this->assertMatchesRegularExpression('#<option value="4" selected>Khách sỉ</option>#', $html);
        $this->assertStringContainsString('<option value="8" >kh 01</option>', $html);
        $this->assertStringNotContainsString('<option value="7"', $html);
        // Chọn Online: ô Online tích, ô Tại quầy và Tất cả không tích.
        $this->assertMatchesRegularExpression('#value="web" id="kenh_web"\s+checked#', $html);
        $this->assertDoesNotMatchRegularExpression('#value="pos" id="kenh_pos"\s+checked#', $html);
        $this->assertDoesNotMatchRegularExpression('#id="kenh_all"\s+checked#', $html);
    }

    public function test_day_tab_dung_chu_v2(): void
    {
        $this->fakeApi();

        $html = $this->trang();

        $dayTab = substr($html, strpos($html, '<ul class="nav nav-tabs cus-nav-tabs'));
        $dayTab = substr($dayTab, 0, strpos($dayTab, '</ul>'));
        preg_match_all('#data-type="([^"]+)"[^>]*>([^<]+)</a>#u', $dayTab, $m);
        $this->assertSame(
            ['Báo cáo doanh thu', 'Báo cáo hàng hóa', 'Báo cáo chi phí & lợi nhuận', 'Báo cáo ca',
                'Báo cáo khách hàng', 'Báo cáo nhân viên'],
            array_map('html_entity_decode', $m[2])
        );
        $this->assertMatchesRegularExpression('#nav-link active" data-type="customer"#', $dayTab);
        $this->assertStringContainsString('for="show-table">Danh sách</label>', $html);
        // Chỉ khối bảng được V2.napLai thay — ô tìm nằm ngoài để gõ không mất chữ.
        $this->assertStringContainsString('class="table-responsive mx-0 list"', $html);
        $this->assertLessThan(strpos($html, 'table-responsive mx-0 list'), strpos($html, 'class="form-control search-bar'));
    }

    public function test_an_cot_ca_stt(): void
    {
        $this->fakeApi();

        $html = $this->trang('/admin/reports/customers?hide=stt,rank');

        $this->assertMatchesRegularExpression('#<th class="show_stt hide">STT</th>#', $html);
        $this->assertMatchesRegularExpression('#<th class="show_rank hide">Hạng#', $html);
        // Chân bảng gộp 5 ô đầu, trừ hai cột đang ẩn còn 3.
        $this->assertStringContainsString('colspan="3">Tổng cộng</th>', $html);
    }

    public function test_xuat_co_dong_khach_le(): void
    {
        $this->fakeApi();

        $csv = $this->withSession($this->phien())->get('/admin/reports/customers?xuat=excel')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Bán cho người tiêu dùng', $csv);
        $this->assertStringContainsString('cus-00425', $csv);
        $this->assertStringContainsString('"Tên nhóm"', $csv);
    }
}
