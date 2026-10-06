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
     * Gộp = tiền hàng + phụ thu (chưa trừ gì). Thuần = gộp trừ giảm giá. Ước
     * tính = đơn chưa thu + đã thu, KHÔNG gồm đơn đã hoàn tiền.
     */
    public function test_ba_o_doanh_thu_tinh_dung_cong_thuc(): void
    {
        $this->fakeApi(['subtotal' => 148_213_100, 'shipping' => 1_000_000, 'discount' => 66_600]);

        $html = $this->html();

        $this->assertStringContainsString('149.213.100', $html, 'Doanh thu gộp = tiền hàng + phụ thu');
        $this->assertStringContainsString('149.146.500', $html, 'Doanh thu thuần = gộp − giảm giá');
        // 150.000.000 + 3.274.886, KHÔNG cộng 9.000.000 của đơn đã hoàn tiền.
        $this->assertStringContainsString('153.274.886', $html, 'Ước tính = chưa thu + đã thu');
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
}
