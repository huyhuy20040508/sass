<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Báo cáo → Báo cáo tổng hợp.
 *
 * Soi ba thứ: bộ lọc đi sang /admin/reports/summary đúng tên và định dạng, các
 * con số rơi đúng ô (QR tự động gộp payos + sepay, hình thức web chỉ bày khi có
 * tiền), và trang vẫn đứng được khi API hỏng.
 */
class SummaryReportTest extends TestCase
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
        $gio = [];
        for ($h = 0; $h < 24; $h++) {
            $gio[] = ['key' => (string) $h, 'orders' => $h === 11 ? 3 : 0, 'revenue' => $h === 11 ? 279750 : 0];
        }

        return ['data' => [
            'date' => '2026-05-05',
            'cashbook' => ['income_count' => 2, 'expense_count' => 1, 'income' => 141750, 'expense' => 20000, 'cash_income' => 50000, 'cash_expense' => 20000],
            'totals' => ['orders' => 5, 'revenue' => 458770, 'units' => 15],
            'item_kinds' => 7,
            'by_payment_method' => [
                ['key' => 'payos', 'orders' => 2, 'revenue' => 300000],
                ['key' => 'sepay', 'orders' => 1, 'revenue' => 17020],
                ['key' => 'cash', 'orders' => 1, 'revenue' => 121750],
                ['key' => 'bank_transfer', 'orders' => 1, 'revenue' => 20000],
            ],
            'by_hour' => $gio,
            'returns' => ['returns' => 1, 'lines' => 2, 'units' => 3, 'refund' => 45000],
        ]];
    }

    protected function fakeApi(?array $bao = null): void
    {
        Http::fake([
            '*/admin/reports/summary*' => Http::response($bao ?? $this->baoMau()),
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
            ->first(fn (Request $r) => str_contains($r->url(), '/admin/reports/summary'));
        $this->assertNotNull($req, 'không gọi /admin/reports/summary');
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_mo_lan_dau_la_hom_nay_moi_nguon(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/reports/summary')->assertOk()->getContent();

        $q = $this->thamSoGoi();
        $this->assertSame(Carbon::today()->format('Y-m-d'), $q['date']);
        $this->assertArrayNotHasKey('channel', $q);
        // Không khai chi nhánh thì không gửi — API tự lấy chi nhánh đang làm việc.
        $this->assertArrayNotHasKey('shop_id', $q);
        $this->assertStringContainsString('Ngày '.Carbon::today()->format('d-m-Y').' · (Kho trung tâm)', $html);
        // Mặc định là dạng Biểu đồ, như v2.
        $this->assertMatchesRegularExpression('#id="show-chart"\s+checked#', $html);
        // Nhãn nút xem không được lộ khoá dịch thô ("Message.List").
        $this->assertStringContainsString('for="show-table">Danh sách</label>', $html);
    }

    public function test_bo_loc_di_sang_api_dung_ten(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/reports/summary?date=05-05-2026&shop_id=0&channel=pos')->assertOk()->getContent();

        $q = $this->thamSoGoi();
        $this->assertSame('2026-05-05', $q['date']);
        $this->assertSame('0', $q['shop_id']);
        $this->assertSame('pos', $q['channel']);
        $this->assertStringContainsString('Ngày 05-05-2026 · (Tất cả chi nhánh)', $html);
    }

    public function test_tham_so_la_thi_bo_qua(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->get('/admin/reports/summary?date=hom-qua&channel=zalo')->assertOk();

        $q = $this->thamSoGoi();
        $this->assertSame(Carbon::today()->format('Y-m-d'), $q['date']);
        $this->assertArrayNotHasKey('channel', $q);
    }

    public function test_danh_sach_dung_so_tung_o(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/reports/summary?show=table')
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#id="show-table"\s+checked#', $html);
        // Khối Thu/Chi: số phiếu; hàng tổng: thu − chi.
        $this->assertMatchesRegularExpression('#nhan">Thu</span><span class="so">2<#', $html);
        $this->assertMatchesRegularExpression('#nhan">Chi</span><span class="so">1<#', $html);
        $this->assertStringContainsString('Tổng thu chi<b>121.750đ</b>', $html);
        // Khối bán hàng: QR tự động = payos + sepay.
        $this->assertMatchesRegularExpression('#Tổng số hoá đơn</span><span class="so">5<#', $html);
        $this->assertMatchesRegularExpression('#Tiền mặt</span><span class="so">121.750<#', $html);
        $this->assertMatchesRegularExpression('#Chuyển khoản</span><span class="so">20.000<#', $html);
        $this->assertMatchesRegularExpression('#Quẹt thẻ</span><span class="so">0<#', $html);
        $this->assertMatchesRegularExpression('#QR tự động</span><span class="so">317.020<#', $html);
        $this->assertStringContainsString('Tổng kết bán hàng<b>458.770đ</b>', $html);
        // Hàng hoá và trả hàng.
        $this->assertMatchesRegularExpression('#Tổng số mặt hàng</span><span class="so">7<#', $html);
        $this->assertStringContainsString('Tổng số lượng hàng hoá<b>15 Hàng hoá</b>', $html);
        $this->assertMatchesRegularExpression('#Số món trả lại</span><span class="so">2<#', $html);
        $this->assertMatchesRegularExpression('#Số lượng món trả lại</span><span class="so">3<#', $html);
        $this->assertStringContainsString('Tổng tiền trả hàng<b>45.000đ</b>', $html);
        // Không có đơn COD thì không bày dòng COD.
        $this->assertStringNotContainsString('Thu hộ (COD)', $html);
    }

    public function test_hinh_thuc_web_chi_bay_khi_co_tien(): void
    {
        $bao = $this->baoMau();
        $bao['data']['by_payment_method'][] = ['key' => 'cod', 'orders' => 1, 'revenue' => 99000];
        $this->fakeApi($bao);

        $this->withSession($this->phien())->get('/admin/reports/summary?show=table')
            ->assertOk()->assertSee('Thu hộ (COD)');
    }

    public function test_bieu_do_nhan_du_so_lieu(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/reports/summary')->assertOk()->getContent();

        $this->assertStringContainsString('Thu / Chi (<span class="text-success">50.000 / 20.000 VND</span>)', $html);
        $this->assertStringContainsString('Tổng kết bán hàng (<span class="text-success">458.770 VND</span>)', $html);
        $this->assertStringContainsString('Số hoá đơn (5 đơn hàng)', $html);
        // Dữ liệu 24 giờ đi sang JS, giờ 11 có 3 đơn.
        $this->assertStringContainsString('{"don":3,"tien":279750', $html);
        // Nằm ở module BÁO CÁO, tab đầu tiên đang sáng.
        $this->assertMatchesRegularExpression('#icon-item me-xl-2 active">\s*<a href="[^"]*/admin/reports/summary"#', $html);
        $this->assertMatchesRegularExpression('#reports/summary"\s+class="sub-nav-btn active"#', $html);
        // Dãy tab chỉ còn ba trang — Doanh thu / Đơn hàng / Hàng hoá đã bỏ, tab
        // Khách hàng đổi tên thành Báo cáo cuối ngày.
        preg_match_all('#class="sub-nav-btn[^"]*"[^>]*>\s*([^<]+?)\s*</a>#u', $html, $tab);
        $this->assertSame(['Báo cáo tổng hợp', 'Báo cáo cuối ngày', 'Báo cáo kết ca'], $tab[1]);
    }

    /**
     * Lọc Nguồn đơn thì khối Thu/Chi phải NÓI là nó không theo bộ lọc ấy.
     *
     * Chọn Online ngày 29/09: "Tổng kết bán hàng" về 0 đơn / 0 đ, còn Thu/Chi vẫn
     * đếm hai phiếu thu của hai đơn bán tại quầy. Bên API cố ý như vậy — phiếu
     * thu chi là sổ của cả quầy, phần lớn phiếu chẳng dính đơn nào — nhưng ô lọc
     * nằm ngay cạnh nên người xem tưởng con số kia cũng đã lọc.
     *
     * Dòng chú thích chỉ hiện KHI ĐANG lọc: không lọc thì chẳng có gì hiểu nhầm.
     */
    public function test_loc_nguon_don_thi_bao_khoi_thu_chi_khong_theo_loc(): void
    {
        $cau = 'Không theo bộ lọc Nguồn đơn';

        $this->fakeApi();
        $khongLoc = $this->withSession($this->phien())->get('/admin/reports/summary')->assertOk()->getContent();
        $this->assertStringNotContainsString($cau, $khongLoc, 'chưa lọc nguồn thì đừng in thêm dòng nào');

        $this->fakeApi();
        $coLoc = $this->withSession($this->phien())->get('/admin/reports/summary?channel=web')->assertOk()->getContent();

        // Hiện ở CẢ hai dạng xem: thẻ biểu đồ và cột của bảng danh sách.
        $this->assertSame(2, substr_count($coLoc, $cau),
            'dòng chú thích phải đứng cạnh khối Thu/Chi ở cả dạng biểu đồ lẫn dạng danh sách');
    }

    public function test_api_loi_van_ra_trang(): void
    {
        Http::fake([
            '*/admin/reports/summary*' => Http::response(['message' => 'Hỏng rồi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/reports/summary?show=table')
            ->assertOk()
            ->assertSee('Hỏng rồi')
            ->assertSee('Tổng kết bán hàng<b>0đ</b>', false);
    }
}
