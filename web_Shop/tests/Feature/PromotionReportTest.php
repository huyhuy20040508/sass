<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Báo cáo → Báo cáo chương trình khuyến mại.
 *
 * Soi: bộ lọc đi sang /admin/reports/promotions đúng tên (kỳ mặc định tháng
 * này, tab, chi nhánh, ô tìm), bảng in đúng cột của từng tab và dòng tổng lấy
 * từ API (không cộng lại số đơn — đơn áp hai chương trình sẽ bị đếm đôi), hộp
 * chi tiết, bản xuất .xlsx, và tab nằm ở module Báo cáo chứ không ở CRM.
 */
class PromotionReportTest extends TestCase
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

    protected function baoCao(string $tab = 'hoa-don'): array
    {
        return ['data' => [
            'from' => '2026-10-01', 'to' => '2026-10-31', 'tab' => $tab,
            'rows' => [
                ['program_id' => 7, 'code' => 'CTKM00007', 'name' => 'Giảm 50k đơn từ 500k', 'type' => 0,
                    'no_time_limit' => false, 'start_date' => '2026-10-01', 'end_date' => '2026-10-15',
                    'order_count' => 12, 'goods_amount' => 7200000, 'revenue' => 6600000,
                    'quantity' => 0, 'gift_quantity' => 3, 'discount' => 600000, 'code_count' => 0, 'code_used' => 0],
                ['program_id' => 8, 'code' => 'CTKM00008', 'name' => 'Tặng ly sứ', 'type' => 0, 'deleted' => true,
                    'no_time_limit' => true, 'order_count' => 2, 'goods_amount' => 1200000, 'revenue' => 1150000,
                    'gift_quantity' => 2, 'discount' => 50000],
                ['program_id' => 9, 'code' => 'CTKM00009', 'name' => 'Chưa ai dùng', 'type' => 0,
                    'no_time_limit' => true, 'order_count' => 0, 'discount' => 0],
            ],
            // 13 chứ không phải 14: một hoá đơn dùng cả hai chương trình.
            'totals' => ['order_count' => 13, 'goods_amount' => 7800000, 'revenue' => 7150000,
                'gift_quantity' => 5, 'discount' => 650000],
        ]];
    }

    protected function fakeApi(?array $bc = null): void
    {
        Http::fake([
            '*/admin/reports/promotions/orders*' => Http::response(['data' => [
                ['id' => 501, 'code' => 'DH20261005001', 'created_at' => '2026-10-05T09:15:00+07:00',
                    'customer_name' => 'Nguyễn Văn A', 'phone' => '0912000111', 'goods_amount' => 600000,
                    'discount' => 50000, 'total_discount' => 70000, 'total' => 530000],
            ]]),
            '*/admin/reports/promotions*' => Http::response($bc ?? $this->baoCao()),
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true],
                ['id' => 2, 'code' => 'CN02', 'name' => 'Kho Quận 7', 'is_active' => true],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function thamSoGoi(string $duong = '/admin/reports/promotions?'): array
    {
        $req = collect(Http::recorded())->map(fn ($r) => $r[0])
            ->first(fn (Request $r) => str_contains($r->url(), $duong));
        $this->assertNotNull($req, 'không gọi '.$duong);
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_mo_lan_dau_la_thang_nay_tab_hoa_don(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/reports/promotions')->assertOk();

        $q = $this->thamSoGoi();
        $this->assertSame(Carbon::today()->startOfMonth()->format('Y-m-d'), $q['from']);
        $this->assertSame(Carbon::today()->endOfMonth()->format('Y-m-d'), $q['to']);
        $this->assertSame('hoa-don', $q['tab']);
        $this->assertArrayNotHasKey('shop_id', $q, 'không chọn chi nhánh thì để API lấy chi nhánh đang làm việc');

        $this->assertMatchesRegularExpression('#<h1 class="[^"]*tieu-de-trang[^"]*">Báo cáo chương trình khuyến mại</h1>#u', $res->getContent());
        // Tab và cột đúng thứ tự, đúng nhãn bản v2.
        $res->assertSeeInOrder(['Phiếu bán hàng', 'Hàng hóa', 'Nhóm hàng hóa', 'Đồng giá', 'Voucher/Coupon']);
        $res->assertSeeInOrder(['Mã chương trình', 'Tên chương trình', 'Tổng số phiếu', 'Tổng tiền hàng', 'Hàng tặng', 'Tổng giá trị khuyến mại', 'Thời gian']);
        $res->assertSeeInOrder(['CTKM00007', 'Giảm 50k đơn từ 500k', '12', '7.200.000', '3', '600.000', '01-10-2026 - 15-10-2026']);
        $res->assertSee('Không giới hạn thời gian');
        $res->assertSee('Đã xoá');
        // Chương trình chưa ai dùng: có dòng nhưng không bấm xem đơn được.
        $res->assertDontSee('data-id="9"', false);
        $res->assertSee('data-id="7"', false);
    }

    /**
     * Tìm hụt: bảng và bản thẻ phải nói CÙNG một câu.
     *
     * Dưới 992px vỏ v2 giấu hẳn bảng và bày khối .km-the. Bảng nói theo ô tìm,
     * còn bản thẻ ghi cứng "Kỳ này không có chương trình khuyến mại nào" — cùng
     * một lượt gõ "khongcoai" mà máy tính bảng bảo "không khớp ô tìm" còn điện
     * thoại bảo cả kỳ trống. Người xem trên điện thoại tưởng kỳ ấy không có
     * chương trình nào, trong khi thật ra có một cái.
     *
     * Bài đếm số lần câu xuất hiện: phải đủ HAI (bảng + thẻ), và câu kia không
     * được lọt vào đâu cả.
     */
    public function test_tim_hut_thi_bang_va_ban_the_noi_cung_mot_cau(): void
    {
        $trong = $this->baoCao();
        $trong['data']['rows'] = [];
        $trong['data']['totals'] = ['order_count' => 0, 'goods_amount' => 0, 'gift_count' => 0, 'discount' => 0];

        $khop = 'Không có chương trình nào khớp ô tìm.';
        $caKy = 'Kỳ này không có chương trình khuyến mại nào.';

        // Có gõ ô tìm → cả hai chỗ nói "không khớp ô tìm".
        $this->fakeApi($trong);
        $html = $this->withSession($this->phien())
            ->get('/admin/reports/promotions?keyword=khongcoai')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, $khop), 'câu phải xuất hiện ở CẢ bảng lẫn bản thẻ');
        $this->assertStringNotContainsString($caKy, $html, 'đang tìm hụt mà lại báo cả kỳ trống');

        // Không gõ gì → cả hai chỗ nói kỳ này trống.
        $this->fakeApi($trong);
        $html = $this->withSession($this->phien())
            ->get('/admin/reports/promotions')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, $caKy));
        $this->assertStringNotContainsString($khop, $html);
    }

    /**
     * Nhãn kỳ phải hiện ở MỌI khổ màn và không bị cắt chữ.
     *
     * Bản đầu vừa `d-none d-md-block` — dưới 768px mất hẳn — vừa
     * `max-width: 50% + text-overflow: ellipsis`, nên đo thật thấy hai lỗi chứ
     * không một: 390px không có nhãn, còn 800-1000px nhãn bị cắt thành
     * "… 31-1…". Đúng khổ ấy bảng đã đổi sang bản thẻ, mà bản thẻ không có dòng
     * nào khác nói đang xem kỳ nào.
     *
     * Hai màn cùng nhóm để #header_title_info hiện ở mọi khổ; màn này theo cho
     * đồng bộ. Khoảng rộng thật thì đo bằng trình duyệt, ở đây canh CSS.
     */
    public function test_nhan_ky_hien_moi_kho_va_khong_cat_chu(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/reports/promotions?from=01-10-2026&to=31-10-2026')->assertOk()->getContent();

        // Không còn lớp Bootstrap giấu nhãn ở khổ hẹp.
        preg_match('/<div class="km-ky[^"]*"/', $html, $m);
        $this->assertNotEmpty($m, 'không tìm thấy nhãn kỳ');
        $this->assertStringNotContainsString('d-none', $m[0], 'nhãn kỳ bị giấu ở khổ hẹp');

        // Và không cắt chữ: dãy tab cho xuống dòng, nhãn bỏ ellipsis/max-width.
        $dau = strpos($html, '.km-thanh-tab {');
        $this->assertNotFalse($dau, 'không tìm thấy CSS dãy tab');
        $css = substr($html, $dau, strpos($html, '}', strpos($html, '.km-ky {', $dau)) - $dau);
        $this->assertStringContainsString('flex-wrap: wrap', $css, 'hết chỗ thì nhãn phải xuống dòng');
        $this->assertStringNotContainsString('text-overflow', $css, 'nhãn kỳ không được cắt chữ kèm "…"');
        $this->assertStringNotContainsString('max-width', $css);
    }

    public function test_dong_tong_lay_tu_api_khong_cong_lai_so_don(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/reports/promotions')->assertOk();

        // Cộng thẳng các dòng là 14 hoá đơn; API nói 13 (một đơn dùng hai chương trình).
        $html = $res->getContent();
        $dau = strpos($html, 'class="dong-tong"');
        $tfoot = substr($html, $dau, strpos($html, '</tr>', $dau) - $dau);
        $this->assertStringContainsString('>13<', $tfoot);
        $this->assertStringContainsString('650.000', $tfoot);
        $this->assertStringNotContainsString('>14<', $tfoot);
    }

    public function test_bo_loc_tab_chi_nhanh_ky_va_o_tim_di_sang_api(): void
    {
        $this->fakeApi($this->baoCao('dong-gia'));

        $this->withSession($this->phien())
            ->get('/admin/reports/promotions?tab=dong-gia&shop_id=0&from_date=01-09-2026&to_date=30-09-2026&keyword=DG01')
            ->assertOk()
            ->assertSeeInOrder(['Mã chương trình', 'Tên chương trình', 'Thời gian', 'Số lượng hóa đơn', 'Tổng số lượng hàng hóa', 'Hàng tặng', 'Tổng tiền giảm giá']);

        $q = $this->thamSoGoi();
        $this->assertSame(['2026-09-01', '2026-09-30', '0', 'dong-gia', 'DG01'],
            [$q['from'], $q['to'], $q['shop_id'], $q['tab'], $q['keyword']]);
    }

    public function test_tab_la_thi_ve_hoa_don(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->get('/admin/reports/promotions?tab=khong-co')->assertOk();

        $this->assertSame('hoa-don', $this->thamSoGoi()['tab']);
    }

    public function test_tab_voucher_tinh_ti_le_su_dung(): void
    {
        $this->fakeApi(['data' => [
            'rows' => [['program_id' => 3, 'code' => 'VC00003', 'name' => 'Voucher khai trương', 'no_time_limit' => true,
                'code_count' => 200, 'code_used' => 45, 'order_count' => 40, 'discount' => 2000000]],
            'totals' => ['code_count' => 200, 'code_used' => 45, 'order_count' => 40, 'discount' => 2000000],
        ]]);

        $this->withSession($this->phien())->get('/admin/reports/promotions?tab=voucher')->assertOk()
            ->assertSeeInOrder(['Số mã phát hành', 'Đã sử dụng', 'Còn lại', 'Số hóa đơn', 'Tổng ưu đãi', 'Tỉ lệ sử dụng'])
            ->assertSeeInOrder(['VC00003', 'Voucher khai trương', '200', '45', '155', '40', '2.000.000', '22,5%']);
    }

    public function test_hop_chi_tiet_goi_dung_chuong_trinh_va_ky(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->getJson('/admin/reports/promotions/orders?tab=hang-hoa&program_id=7&range=lastMonth')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'DH20261005001')
            ->assertJsonPath('data.0.khach', 'Nguyễn Văn A')
            ->assertJsonPath('data.0.thoi_gian', '05-10-2026 09:15');

        $q = $this->thamSoGoi('/admin/reports/promotions/orders');
        $this->assertSame(['7', 'hang-hoa'], [$q['program_id'], $q['tab']]);
        $this->assertSame(Carbon::today()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), $q['from']);
    }

    public function test_hop_chi_tiet_thieu_chuong_trinh_la_422(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->getJson('/admin/reports/promotions/orders')->assertStatus(422);
    }

    public function test_xuat_excel_dung_tab_kem_dong_tong(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/reports/promotions/export?tab=hoa-don')->assertOk();

        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('bao-cao-khuyen-mai-hoa-don-', $res->headers->get('Content-Disposition'));
    }

    public function test_tab_nhom_hang_hoa_di_sang_api(): void
    {
        $this->fakeApi($this->baoCao('nhom-hang'));

        $this->withSession($this->phien())->get('/admin/reports/promotions?tab=nhom-hang')->assertOk()
            ->assertSeeInOrder(['Mã chương trình', 'Tên chương trình', 'Tổng hàng hóa', 'Hàng tặng', 'Tổng số phiếu', 'Tổng giá trị khuyến mại', 'Thời gian']);

        $this->assertSame('nhom-hang', $this->thamSoGoi()['tab']);
    }

    public function test_xuat_excel_trong_hop_chi_tiet_la_hoa_don_cua_chuong_trinh(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/reports/promotions/export?tab=hoa-don&program_id=7')->assertOk();

        $this->assertStringContainsString('-ct7', $res->headers->get('Content-Disposition'));
        $this->assertSame('7', $this->thamSoGoi('/admin/reports/promotions/orders')['program_id']);
    }

    public function test_api_loi_thi_bao_loi_khong_vo_trang(): void
    {
        Http::fake([
            '*/admin/reports/promotions*' => Http::response(['message' => 'Lỗi lập báo cáo chương trình khuyến mại'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/reports/promotions')->assertOk()
            ->assertSee('Lỗi lập báo cáo chương trình khuyến mại');
    }

    public function test_tab_nam_o_module_bao_cao_khong_o_crm(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/reports/promotions')->assertOk();
        $res->assertSee(route('admin.reports.promotions'), false);

        // Tab mờ cùng tên bên CRM đã bỏ: mở một màn CRM không còn thấy nó.
        Http::fake(['*' => Http::response(['data' => []])]);
        $crm = $this->withSession($this->phien())->get('/admin/crm/customers');
        $this->assertStringNotContainsString('Báo cáo chương trình khuyến mại', $crm->getContent());
    }
}
