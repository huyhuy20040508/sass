<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trang Nhà cung cấp dựng bằng API GIẢ.
 *
 * Canh phần Shop Admin tự làm: hộp chi tiết có đủ ba tab như bản order v2, và
 * ba con số tiền cộng đúng. Hai tab sau từng bị gỡ ở 557d907 vì chúng cộng từ
 * purchase_orders — bảng đó nay đã có lại.
 */
class NhaCungCapTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'tk',
            'api.refresh_token' => 'rf',
            'api.user' => ['id' => 1, 'full_name' => 'Test', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    protected function fakeApi(): void
    {
        Http::fake([
            '*/admin/nha-cung-cap*' => Http::response(['data' => [[
                'id' => 4, 'code' => 'NCC001', 'name' => 'Công ty TNHH An Bình',
                'address' => '12 Lê Lợi', 'is_active' => true,
            ]]], 200),
            '*' => Http::response(['data' => [], 'meta' => []], 200),
        ]);
    }

    public function test_hop_chi_tiet_co_du_ba_tab_nhu_v2(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())->get('/admin/suppliers')->getContent();

        // Thanh tab, đúng thứ tự của v2.
        preg_match_all('/class="ncc-tab[^"]*" data-tab="([a-z-]+)"/', $html, $m);
        $this->assertSame(['ho-so', 'giao-dich', 'cong-no'], $m[1]);

        foreach (['Chi tiết', 'Lịch sử giao dịch', 'Công nợ'] as $ten) {
            $this->assertStringContainsString($ten, $html);
        }

        // Ba khung nội dung.
        preg_match_all('/class="ncc-tab-pane[^"]*" data-pane="([a-z-]+)"/', $html, $p);
        $this->assertSame(['ho-so', 'giao-dich', 'cong-no'], $p[1]);

        // Tab giao dịch có ô tìm và khoảng ngày; tab công nợ có ba con số tiền.
        $this->assertStringContainsString('id="nccGdTim"', $html);
        $this->assertStringContainsString('id="nccGdTu"', $html);
        $this->assertStringContainsString('id="nccGdDen"', $html);
        $this->assertStringContainsString('id="nccNoTong"', $html);
    }

    /**
     * Tiền của một nhà cung cấp CHỈ cộng trên phiếu đã duyệt.
     *
     * Phiếu lưu tạm chưa mua gì, phiếu huỷ thì không bao giờ mua — cộng chúng
     * vào là dựng ra một khoản nợ không có thật.
     */
    public function test_tien_chi_cong_tren_phieu_da_duyet(): void
    {
        Http::fake([
            '*/admin/phieu-mua-hang*' => Http::response(['data' => [
                ['id' => 1, 'status' => 'approved', 'total_amount' => 2000000, 'paid_amount' => 800000],
                ['id' => 2, 'status' => 'draft', 'total_amount' => 500000, 'paid_amount' => 0],
                ['id' => 3, 'status' => 'cancelled', 'total_amount' => 900000, 'paid_amount' => 0],
            ]], 200),
            '*' => Http::response(['data' => [], 'meta' => []], 200),
        ]);

        $res = $this->withSession($this->phien())->get('/admin/suppliers/4/purchase-orders');

        $res->assertOk();
        $tien = $res->json('tien');

        $this->assertEqualsWithDelta(2000000, $tien['tong_mua'], 0.01, 'phiếu nháp và phiếu huỷ không được cộng vào tổng mua');
        $this->assertEqualsWithDelta(800000, $tien['da_tra'], 0.01);
        $this->assertEqualsWithDelta(1200000, $tien['con_no'], 0.01);

        // Vẫn trả về ĐỦ phiếu: tab Lịch sử giao dịch cần thấy cả nháp lẫn huỷ.
        $this->assertCount(3, $res->json('data'));
    }
}
