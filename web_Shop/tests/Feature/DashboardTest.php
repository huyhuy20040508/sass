<?php

namespace Tests\Feature;

use App\Support\Period;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Màn Tổng quan — dựng lại theo bản v2.
 *
 * Bài này gác ba thứ dễ sai nhất, đều là chỗ số liệu đi qua tay controller chứ
 * không phải API trả sẵn:
 *
 *   1. SÁU Ô KPI có ba công thức tự tính (gộp / thuần / ước tính). Sai một dấu
 *      cộng ở đây thì trang vẫn 200, vẫn có số, và không ai biết số ấy sai.
 *
 *   2. "Chi phí mua hàng" và "Số lượng nhập kho" phải CỘNG TỪ danh sách phiếu
 *      mua, vì `/phieu-mua-hang/stats` không nhận khoảng ngày. Phiếu nháp và
 *      phiếu đã huỷ không được tính.
 *
 *   3. Mốc lịch ("tháng này") khác cửa sổ trượt ("30 ngày qua"). Hai thứ trông
 *      giống nhau trên nút bấm nhưng ra hai khoảng ngày khác hẳn.
 */
class DashboardTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-admin',
            'api.refresh_token' => 'refresh-admin',
            'api.user' => ['id' => 7, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly'],
            'api.tenant' => ['code' => 'quochuy', 'name' => 'Tiệm Quốc Huy'],
        ];
    }

    /**
     * Bộ số liệu gốc. `$doanhThu` ghi đè khối totals để từng bài chỉ nói về
     * con số nó quan tâm.
     *
     * @param  array<string, mixed>  $themApi
     */
    protected function fakeApi(array $totals = [], array $themApi = []): void
    {
        $totals = array_merge(
            ['orders' => 9, 'revenue' => 153_274_886, 'subtotal' => 148_213_100,
                'discount' => 66_600, 'shipping' => 0, 'units' => 12, 'cost' => 71_700_000],
            $totals
        );

        Http::fake($themApi + [
            '*/admin/reports/revenue*' => Http::response(['data' => [
                'buckets' => [
                    ['label' => '2026-09-12', 'subtotal' => 100_000, 'shipping' => 0, 'discount' => 0, 'cost' => 60_000],
                    ['label' => '2026-09-13', 'subtotal' => 200_000, 'shipping' => 0, 'discount' => 10_000, 'cost' => 90_000],
                ],
                'totals' => $totals,
                'by_payment_method' => [
                    ['key' => 'cash', 'orders' => 6, 'revenue' => 75_378_658],
                    ['key' => 'bank_transfer', 'orders' => 3, 'revenue' => 77_896_228],
                ],
                'by_payment_status' => [
                    ['key' => 'paid', 'orders' => 8, 'revenue' => 150_000_000],
                    ['key' => 'pending', 'orders' => 1, 'revenue' => 3_274_886],
                    ['key' => 'refunded', 'orders' => 2, 'revenue' => 9_000_000],
                ],
                'by_shop' => [['shop_id' => 2, 'label' => 'Chi nhánh 1', 'orders' => 9, 'revenue' => 148_213_100]],
            ]]),

            '*/admin/reports/orders*' => Http::response(['data' => [
                'by_source' => [
                    ['key' => 'pos', 'orders' => 8, 'revenue' => 150_000_000],
                    ['key' => 'web', 'orders' => 1, 'revenue' => 3_274_886],
                ],
            ]]),

            '*/admin/reports/products*' => Http::response(['data' => [
                'items' => [
                    ['product_id' => 4, 'name' => 'iphone 15', 'units' => 3, 'revenue' => 75_000_000],
                    ['product_id' => 9, 'name' => 'Hàng chưa bán', 'units' => 0, 'revenue' => 0],
                ],
            ]]),

            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function html(string $duong = '/admin/dashboard'): string
    {
        return $this->withSession($this->phien())->get($duong)->assertOk()->getContent();
    }

    /**
     * Ba công thức tiền của sáu ô đầu trang.
     *
     * Gộp = tiền hàng + phụ thu (chưa trừ gì). Thuần = gộp trừ giảm giá. Lợi
     * nhuận gộp lấy thẳng `profit` của API, biên lãi = lợi nhuận / thuần.
     */
    public function test_ba_o_doanh_thu_tinh_dung_cong_thuc(): void
    {
        $this->fakeApi(['subtotal' => 148_213_100, 'shipping' => 1_000_000, 'discount' => 66_600, 'profit' => 77_000_000]);

        $html = $this->html();

        $this->assertStringContainsString('149.213.100', $html, 'Doanh thu gộp = tiền hàng + phụ thu');
        $this->assertStringContainsString('149.146.500', $html, 'Doanh thu thuần = gộp − giảm giá');
        $this->assertStringContainsString('77.000.000', $html, 'Lợi nhuận gộp lấy từ API');
        // 77.000.000 / 149.146.500 = 51,63%
        $this->assertStringContainsString('Biên lãi 51,6%', $html);
        $this->assertStringContainsString('Giảm giá −66.600', $html);
    }

    /** Phiếu nháp và phiếu huỷ không phải tiền đã mua — API cũng tính vậy. */
    public function test_chi_phi_mua_hang_bo_phieu_nhap_va_huy(): void
    {
        $this->fakeApi([], [
            '*/admin/phieu-mua-hang*' => Http::response([
                'data' => [
                    ['status' => 'approved', 'total_amount' => 40_000_000, 'items' => [['base_quantity' => 20]]],
                    ['status' => 'approved', 'total_amount' => 2_000_000, 'items' => [['base_quantity' => 5], ['base_quantity' => 7]]],
                    ['status' => 'draft', 'total_amount' => 999_000_000, 'items' => [['base_quantity' => 500]]],
                    ['status' => 'cancelled', 'total_amount' => 888_000_000, 'items' => [['base_quantity' => 400]]],
                ],
                'meta' => ['page' => 1, 'page_size' => 100, 'total' => 4, 'total_pages' => 1],
            ]),
        ]);

        $html = $this->html();

        $this->assertStringContainsString('42.000.000', $html, 'Chỉ cộng phiếu đã duyệt');
        $this->assertStringContainsString('>32<', $html, 'Số lượng nhập kho = tổng số lượng các dòng phiếu đã duyệt');
        $this->assertStringNotContainsString('999.000.000', $html);
    }

    /**
     * Mốc lịch khác cửa sổ trượt.
     *
     * "Tháng này" bắt đầu từ ngày 1; "30 ngày qua" lùi đúng 30 ngày. Nếu hai mã
     * này quy về cùng một khoảng thì một trong hai nút đang nói dối.
     */
    public function test_moc_lich_khac_cua_so_truot(): void
    {
        $thang = Period::resolve('this-month');
        $ba0 = Period::resolve('30');

        $this->assertSame(date('Y-m-01'), $thang['from']);
        $this->assertSame(date('Y-m-d'), $thang['to']);
        $this->assertNotSame($thang['from'], $ba0['from']);

        // Kỳ đang chạy cắt ở hôm nay, không chạy tới cuối tháng / cuối năm.
        $this->assertSame(date('Y-m-d'), Period::resolve('this-year')['to']);
        $this->assertSame(date('Y-01-01'), Period::resolve('this-year')['from']);

        // Kỳ đã qua thì lấy trọn vẹn.
        $thangTruoc = Period::resolve('last-month');
        $this->assertSame(date('Y-m-t', strtotime($thangTruoc['from'])), $thangTruoc['to']);
    }

    /** Kỳ trên URL đi thẳng vào lượt gọi API, không phải mỗi khối tự tính lấy. */
    public function test_ky_tren_url_di_vao_moi_loi_goi(): void
    {
        $this->fakeApi();

        $this->html('/admin/dashboard?from=01-09-2026&to=15-09-2026');

        foreach (['reports/revenue', 'reports/orders', 'reports/products'] as $duong) {
            Http::assertSent(fn ($req) => str_contains($req->url(), $duong)
                && str_contains(urldecode($req->url()), 'from=2026-09-01')
                && str_contains(urldecode($req->url()), 'to=2026-09-15'));
        }
    }

    /** Mặt hàng cả kỳ không bán được món nào thì không chiếm chỗ của top. */
    public function test_top_ban_chay_bo_dong_khong_ban_duoc(): void
    {
        $this->fakeApi();

        $html = $this->html();

        $this->assertStringContainsString('iphone 15', $html);
        $this->assertStringNotContainsString('Hàng chưa bán', $html);
    }

    /** Chưa ai mở ca thì nói thẳng, không bày một thẻ trống. */
    public function test_chua_mo_ca_thi_bao_ro(): void
    {
        $this->fakeApi();

        // Lấy HTML TRƯỚC rồi mới dịch. Tiếng Việt chỉ được đặt khi lượt gọi đi
        // qua EnsureAdminAuthenticated; gọi __() trong cùng một dòng thì PHP
        // tính đối số trước, lúc đó locale vẫn là mặc định của .env — CI chạy
        // với APP_LOCALE=en mà lang/ chỉ có tiếng Việt, nên __() trả về đúng
        // cái khoá "message.no_shift_selected" và không khớp trang.
        $html = $this->html();

        $this->assertStringContainsString(__('message.no_shift_selected'), $html);
    }

    /**
     * Ca đang mở: tiền mặt là tiền SỔ nói lẽ ra đang có trong két.
     *
     * Đầu ca cộng thu trừ chi — không phải chỉ lấy tiền đầu ca, mà cũng không
     * phải tổng thu của cả ngày. Danh sách ca không kèm tổng thu/chi nên số
     * phải lấy từ chi tiết từng ca.
     */
    public function test_ca_dang_mo_in_tien_mat_theo_so(): void
    {
        $this->fakeApi([], [
            '*/admin/ca-lam-viec/12*' => Http::response(['data' => ['ca' => [
                'id' => 12, 'shop_id' => 2,
                'opened_by_name' => 'Chị Lan', 'opened_at' => '2026-09-26 08:30:00',
                'opening_cash' => 500_000, 'tong_thu' => 3_000_000, 'tong_chi' => 200_000,
                'closed_at' => null, 'so_don_tien_mat' => 6,
            ]]]),
            '*/admin/ca-lam-viec*' => Http::response(['data' => [
                ['id' => 12, 'shop_id' => 2, 'opened_by_name' => 'Chị Lan', 'opening_cash' => 500_000, 'tong_thu' => 0, 'tong_chi' => 0],
            ]]),
        ]);

        $html = $this->html();

        $this->assertStringContainsString('Chị Lan', $html);
        $this->assertStringContainsString('#12', $html);
        $this->assertStringContainsString('3.300.000', $html, 'Tiền mặt = đầu ca + thu − chi');
        $this->assertStringContainsString(__('message.open'), $html);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'ca-lam-viec?')
            && str_contains($req->url(), 'status=dang_mo') && str_contains($req->url(), 'shop_id=0'));
    }

    /** Xem "Tất cả" thì mỗi chi nhánh có ca mở là một dòng, như v2. */
    public function test_tat_ca_chi_nhanh_liet_ke_moi_ca_mo(): void
    {
        $this->fakeApi([], [
            '*/admin/ca-lam-viec/12*' => Http::response(['data' => ['ca' => ['id' => 12, 'shop_id' => 2, 'opened_by_name' => 'Chị Lan', 'opening_cash' => 100_000]]]),
            '*/admin/ca-lam-viec/15*' => Http::response(['data' => ['ca' => ['id' => 15, 'shop_id' => 3, 'opened_by_name' => 'Anh Tú', 'opening_cash' => 200_000]]]),
            '*/admin/ca-lam-viec*' => Http::response(['data' => [['id' => 12, 'shop_id' => 2], ['id' => 15, 'shop_id' => 3]]]),
        ]);

        $html = $this->html();

        $this->assertStringContainsString('Chị Lan', $html);
        $this->assertStringContainsString('Anh Tú', $html);
        $this->assertSame(2, substr_count($html, 'list-history-shift active'));
    }

    /**
     * Chi nhánh là bộ lọc riêng của màn: mặc định gộp cả cửa hàng (shop_id=0),
     * chọn một chi nhánh thì MỌI lượt gọi số liệu đều mang id đó, còn id lạ thì
     * lùi về "Tất cả" chứ không gửi bừa lên API.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('chiNhanhTrenUrl')]
    public function test_chi_nhanh_di_vao_moi_loi_goi(string $q, string $shop): void
    {
        $dsChiNhanh = Http::response(['data' => [['id' => 2, 'name' => 'Chi nhánh 1'], ['id' => 3, 'name' => 'Chi nhánh 2']]]);
        $this->fakeApi([], ['*/admin/chi-nhanh*' => $dsChiNhanh]);

        $html = $this->html('/admin/dashboard'.$q);

        foreach (['reports/revenue', 'reports/orders', 'reports/products', 'phieu-mua-hang', 'ca-lam-viec?'] as $d) {
            Http::assertSent(fn ($req) => str_contains($req->url(), $d) && str_contains($req->url(), 'shop_id='.$shop));
        }
        $this->assertMatchesRegularExpression('#<option value="'.$shop.'"\s+selected#', $html);
    }

    public static function chiNhanhTrenUrl(): array
    {
        return [
            'mặc định Tất cả' => ['', '0'],
            'một chi nhánh' => ['?branch=3', '3'],
            'id không có' => ['?branch=99', '0'],
            'chữ' => ['?branch=abc', '0'],
        ];
    }

    /** Tab Thống kê: Tổng quan bấm được và đứng đầu, "Báo cáo cuối ngày" đã chuyển sang module Báo cáo. */
    public function test_tab_thong_ke(): void
    {
        $this->fakeApi();

        $html = $this->html();

        preg_match_all('#class="sub-nav-btn[^"]*"[^>]*>\s*([^<]+?)\s*</a>#u', $html, $tab);
        $this->assertSame(['Tổng quan', 'Khách hàng', 'Quản lý đơn hàng', 'Hoá đơn điện tử'], $tab[1]);
        $this->assertMatchesRegularExpression('#admin/dashboard"\s+class="sub-nav-btn active"#', $html);
        $this->assertMatchesRegularExpression('#icon-item me-xl-2 active">\s*<a href="[^"]*/admin/dashboard"#', $html);
    }

    /**
     * "Nguồn đơn hàng" đọc `by_source` (quầy / giao hàng), KHÔNG phải
     * `by_channel` (hội viên / vãng lai).
     *
     * Hai khoá nằm cạnh nhau trong cùng một phản hồi và rất dễ lấy nhầm — lấy
     * nhầm thì thẻ vẫn vẽ ra một biểu đồ đẹp, chỉ là trả lời sai câu hỏi.
     */
    public function test_nguon_don_hang_doc_by_source(): void
    {
        $this->fakeApi([], [
            '*/admin/reports/orders*' => Http::response(['data' => [
                'by_source' => [['key' => 'pos', 'orders' => 8, 'revenue' => 150_000_000]],
                'by_channel' => [['key' => 'member', 'orders' => 4, 'revenue' => 70_000_000]],
            ]]),
        ]);

        $html = $this->html();

        // Nhãn đi sang JS qua @json nên tiếng Việt nằm dưới dạng \uXXXX — so
        // chuỗi trần ở đây là bài kiểm đỏ vì cách mã hoá chứ không vì màn sai.
        $nhan = trim(json_encode(\App\Http\Controllers\OrderController::CHANNELS['pos']), '"');

        $this->assertStringContainsString($nhan, $html);
        $this->assertStringNotContainsString('member', $html);
    }

    /** API hỏng thì trang vẫn mở được — đây là màn đầu tiên mỗi sáng. */
    public function test_api_hong_van_ra_trang(): void
    {
        Http::fake(['*' => Http::response(['message' => 'lỗi'], 500)]);

        $html = $this->withSession($this->phien())->get('/admin/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString(__('message.overview'), $html);
        $this->assertStringContainsString(__('message.gross-revenue'), $html);
    }
    /** Fake báo cáo doanh thu với totals + prev tuỳ ý, phần còn lại như fakeApi. */
    protected function fakeKyTruoc(array $nay, array $truoc): void
    {
        $this->fakeApi([], [
            '*/admin/reports/revenue*' => Http::response(['data' => [
                'buckets' => [], 'by_payment_method' => [], 'by_payment_status' => [], 'by_shop' => [],
                'prev_from' => '2026-08-02', 'prev_to' => '2026-08-31',
                'totals' => $nay + ['subtotal' => 0, 'shipping' => 0, 'discount' => 0, 'orders' => 0, 'profit' => 0],
                'prev' => $truoc + ['subtotal' => 0, 'shipping' => 0, 'discount' => 0, 'orders' => 0, 'profit' => 0],
            ]]),
        ]);
    }

    /**
     * So kỳ trước: tăng in ▲ xanh, giảm in ▼ đỏ, kỳ trước bằng 0 in "Mới" chứ
     * không chia cho 0 ra một phần trăm bịa.
     */
    public function test_so_voi_ky_truoc(): void
    {
        $this->fakeKyTruoc(
            ['subtotal' => 120_000, 'profit' => 30_000, 'orders' => 9],
            ['subtotal' => 100_000, 'profit' => 40_000, 'orders' => 0],
        );

        $html = $this->html();

        $this->assertStringContainsString('▲ 20% so với kỳ trước', $html, 'Gộp 100k → 120k');
        $this->assertStringContainsString('▼ 25% so với kỳ trước', $html, 'Lợi nhuận 40k → 30k');
        $this->assertStringContainsString('▲ Mới · kỳ trước 0', $html, 'Đơn 0 → 9');
        $this->assertStringContainsString('Kỳ trước: 02/08 – 31/08/2026', $html);
    }

    /** Cả hai kỳ đều 0 thì không in dòng so sánh nào. */
    public function test_khong_ban_gi_thi_khong_so_sanh(): void
    {
        $this->fakeKyTruoc([], []);

        $html = $this->html();

        $this->assertStringNotContainsString('so với kỳ trước', $html);
        $this->assertStringNotContainsString('▲ Mới', $html);
    }

    /**
     * Nút Ngày / Tuần / Tháng đi thẳng vào `group_by`; không chọn thì kỳ dài tự
     * gộp thô hơn, còn giá trị lạ lùi về cách tự chọn chứ không gửi bừa lên API.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('chiaTrucTrenUrl')]
    public function test_chia_truc(string $q, string $nhom): void
    {
        $this->fakeApi();

        $html = $this->html('/admin/dashboard'.$q);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'reports/revenue') && str_contains($req->url(), 'group_by='.$nhom));
        $this->assertMatchesRegularExpression('#group='.$nhom.'"\s+class="is-active"#', $html);
    }

    public static function chiaTrucTrenUrl(): array
    {
        return [
            'mặc định hôm nay' => ['', 'day'],
            'năm nay tự gộp tháng' => ['?range=this-year&group=', 'month'],
            'chọn tuần' => ['?range=this-month&group=week', 'week'],
            'giá trị lạ' => ['?range=today&group=abc', 'day'],
        ];
    }

    /**
     * Mỗi cột mang khoảng ngày của nó, cắt trong kỳ đang xem: tuần bắt đầu
     * trước ngày đầu kỳ thì danh sách đơn cũng chỉ mở từ ngày đầu kỳ.
     */
    public function test_cot_bieu_do_mang_khoang_ngay(): void
    {
        $this->fakeApi([], [
            '*/admin/reports/revenue*' => Http::response(['data' => [
                'buckets' => [
                    ['label' => '2026-W37', 'subtotal' => 100_000],
                    ['label' => '2026-W38', 'subtotal' => 200_000],
                ],
                'totals' => [], 'prev' => [],
            ]]),
        ]);

        $html = $this->html('/admin/dashboard?from=10-09-2026&to=15-09-2026&group=week');

        // Tuần 37 là 07/09 – 13/09, cắt còn 10/09 – 13/09; tuần 38 là 14/09 – 20/09, cắt còn 14/09 – 15/09.
        $this->assertStringContainsString('"ranges":[["10-09-2026","13-09-2026"],["14-09-2026","15-09-2026"]]', $html);
    }

    /** Ô KPI là đường vào màn chi tiết, mang đúng kỳ đang xem. */
    public function test_o_kpi_mo_man_chi_tiet_dung_ky(): void
    {
        $this->fakeApi();

        $html = $this->html('/admin/dashboard?from=01-09-2026&to=15-09-2026');

        $this->assertStringContainsString('/admin/orders?from_date=01-09-2026&amp;to_date=15-09-2026', $html);
        $this->assertStringContainsString('/admin/reports/sales?from_date=01-09-2026&amp;to_date=15-09-2026', $html);
        $this->assertStringContainsString('/admin/reports/profit?from_date=01-09-2026&amp;to_date=15-09-2026', $html);
        // Tên hàng bán chạy mở báo cáo hàng hoá của chính mặt hàng đó.
        $this->assertStringContainsString('/admin/reports/goods?from_date=01-09-2026&amp;to_date=15-09-2026&amp;product_id=4', $html);
    }

    /**
     * Giờ cao điểm: trục luôn phủ 7h–22h, đơn ngoài khung thì nới trục ra chứ
     * không giấu; giờ đông nhất được gọi tên.
     */
    public function test_gio_cao_diem(): void
    {
        $this->fakeApi([], [
            '*/admin/reports/orders*' => Http::response(['data' => [
                'by_source' => [],
                'by_hour' => [['key' => '5', 'orders' => 2], ['key' => '20', 'orders' => 4], ['key' => '9', 'orders' => 0]],
            ]]),
        ]);

        $html = $this->html();

        $this->assertStringContainsString('đông nhất 20h', $html);
        $this->assertStringContainsString('title="5h: 2 đơn"', $html, 'Trục nới tới 5h vì có đơn lúc 5h');
        $this->assertStringContainsString('title="22h: 0 đơn"', $html);
        $this->assertStringNotContainsString('title="4h:', $html);
    }

    /** Ca mở quá 24 giờ chưa chốt thì vào thẻ "Cần chú ý"; ca vừa mở thì không. */
    public function test_canh_bao_ca_mo_qua_lau(): void
    {
        $ca = fn (int $id, string $moLuc) => Http::response(['data' => ['ca' => [
            'id' => $id, 'shop_id' => 2, 'shop_name' => 'Quầy '.$id, 'opened_at' => $moLuc, 'opening_cash' => 0,
        ]]]);
        $this->fakeApi([], [
            '*/admin/ca-lam-viec/12*' => $ca(12, date('Y-m-d H:i:s', strtotime('-3 days -1 hour'))),
            '*/admin/ca-lam-viec/15*' => $ca(15, date('Y-m-d H:i:s', strtotime('-2 hours'))),
            '*/admin/ca-lam-viec*' => Http::response(['data' => [['id' => 12], ['id' => 15]]]),
        ]);

        $html = $this->html();

        $this->assertStringContainsString('Ca #12 ở Quầy 12 đã mở 3 ngày chưa chốt', $html);
        $this->assertStringNotContainsString('Ca #15 ở', $html);
    }

    public function test_khong_co_gi_can_chu_y(): void
    {
        $this->fakeApi();

        $this->assertStringContainsString('Không có việc gì cần chú ý.', $this->html());
    }

    /**
     * Phiếu mua nhiều trang: các trang sau gọi thêm (song song) và cộng đủ, chặn
     * ở MAX_PURCHASE_PAGES.
     */
    public function test_phieu_mua_nhieu_trang_cong_du(): void
    {
        $this->fakeApi([], [
            '*/admin/phieu-mua-hang*' => function ($req) {
                $trang = (int) ($req->data()['page'] ?? 1);

                return Http::response([
                    'data' => [['status' => 'approved', 'total_amount' => 1_000_000 * $trang, 'items' => [['base_quantity' => $trang]]]],
                    'meta' => ['total_pages' => 3],
                ]);
            },
        ]);

        $html = $this->html();

        // 1 + 2 + 3 triệu, 1 + 2 + 3 món.
        $this->assertStringContainsString('6.000.000', $html);
        foreach ([1, 2, 3] as $t) {
            Http::assertSent(fn ($req) => str_contains($req->url(), 'phieu-mua-hang') && ($req->data()['page'] ?? null) == $t);
        }
    }

    /** API chưa khai khoá mục tiêu thì không bày thanh mục tiêu. */
    public function test_muc_tieu_an_khi_api_chua_co_khoa(): void
    {
        $this->fakeApi([], ['*/admin/settings*' => Http::response(['data' => ['values' => ['site_name' => 'X']]])]);

        $this->assertStringNotContainsString('Mục tiêu doanh thu tháng', $this->html('/admin/dashboard?range=this-month'));
    }

    /**
     * Có khoá thì thanh mục tiêu hiện khi xem trọn một tháng, so với doanh thu
     * THUẦN; xem tuần / khoảng lệch tháng thì không.
     */
    public function test_muc_tieu_khi_xem_tron_thang(): void
    {
        $this->fakeApi([], ['*/admin/settings*' => Http::response(['data' => ['values' => ['monthly_revenue_goal' => '200000000']]])]);

        $html = $this->html('/admin/dashboard?from=01-09-2026&to=30-09-2026');

        $this->assertStringContainsString('Mục tiêu doanh thu tháng 09/2026', $html);
        // Thuần 148.146.500 / 200.000.000 = 74,07%
        $this->assertStringContainsString('74,1%', $html);
        $this->assertStringContainsString('Còn thiếu 51.853.500 đ', $html);

        $this->assertStringNotContainsString('Mục tiêu doanh thu tháng', $this->html('/admin/dashboard?from=05-09-2026&to=30-09-2026'));
        $this->assertStringNotContainsString('Mục tiêu doanh thu tháng', $this->html('/admin/dashboard?range=7'));
    }

    public function test_muc_tieu_chua_dat_thi_moi_dat(): void
    {
        $this->fakeApi([], ['*/admin/settings*' => Http::response(['data' => ['values' => ['monthly_revenue_goal' => '0']]])]);

        $html = $this->html('/admin/dashboard?range=this-month');

        $this->assertStringContainsString('Chưa đặt mục tiêu', $html);
        $this->assertStringContainsString('Đặt mục tiêu', $html);
    }

    /** Lưu mục tiêu: bỏ dấu chấm ngăn nghìn, ghi qua PUT /settings. */
    public function test_luu_muc_tieu(): void
    {
        Http::fake(['*/admin/settings*' => Http::response(['data' => ['values' => []]])]);

        $this->withSession($this->phien())->from('/admin/dashboard?range=this-month')
            ->post('/admin/dashboard/muc-tieu', ['muc_tieu' => '250.000.000'])
            ->assertRedirect('/admin/dashboard?range=this-month')
            ->assertSessionHas('success');

        Http::assertSent(fn ($req) => $req->method() === 'PUT' && str_contains($req->url(), '/admin/settings')
            && $req->data() === ['items' => ['monthly_revenue_goal' => '250000000']]);
    }

    /** API từ chối (thiếu quyền, khoá chưa khai) thì nói lại câu của API. */
    public function test_luu_muc_tieu_bi_tu_choi(): void
    {
        Http::fake(['*/admin/settings*' => Http::response(['message' => 'Bạn không có quyền sửa cấu hình'], 403)]);

        $this->withSession($this->phien())->from('/admin/dashboard')
            ->post('/admin/dashboard/muc-tieu', ['muc_tieu' => '1000'])
            ->assertRedirect('/admin/dashboard')
            ->assertSessionHas('error', 'Bạn không có quyền sửa cấu hình');
    }
}
