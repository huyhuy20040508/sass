<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sáu tab của Báo cáo cuối ngày dùng CHUNG một khuôn (v2::reports.end-day), và
 * chữ to đầu khối nội dung từng được ghi cứng là "Báo cáo doanh thu" — tên của
 * tab đầu tiên. Mở tab Báo cáo khách hàng thì dãy tab sáng đúng chỗ, thẻ trình
 * duyệt đúng, riêng cái tên to nhất trên màn lại là của tab khác.
 *
 * Bài đi HẾT sáu tab chứ không riêng tab bị báo: lỗi nằm ở khuôn dùng chung nên
 * tab nào cũng dính, và tab thứ bảy thêm vào sau này cũng được canh luôn.
 */
class ReportTabTitleTest extends TestCase
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

    public function test_moi_tab_bay_dung_ten_cua_chinh_no(): void
    {
        Http::fake([
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);

        foreach (ReportController::TAB_ROUTE as $ma => $duong) {
            $ten = ReportController::TAB_THONG_KE[$ma];

            $html = $this->withSession($this->phien())->get(route($duong))->assertOk()->getContent();

            preg_match('/<h1[^>]*class="[^"]*tieu-de-trang[^"]*"[^>]*>\s*([^<]+?)\s*<\/h1>/u', $html, $m);
            $this->assertNotEmpty($m, 'tab '.$ma.' không có tiêu đề nào');
            // Giải mã thực thể: "chi phí & lợi nhuận" ra HTML thành "&amp;".
            $this->assertSame($ten, html_entity_decode($m[1]), 'tab '.$ma.' bày tên của tab khác');
        }
    }
}
