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

        // Thanh tab, đúng thứ tự của v2. Hộp chi tiết dùng tab Bootstrap của v2
        // (ul.nav-detail + data-bs-target) chứ không còn tab tự vẽ.
        preg_match_all('/data-bs-toggle="tab" data-bs-target="#([a-z-]+)"/', $html, $m);
        $this->assertSame(['detail-supplier', 'transaction-history', 'debt-history'], $m[1]);

        foreach (['Chi tiết', 'Lịch sử giao dịch', 'Công nợ'] as $ten) {
            $this->assertStringContainsString($ten, $html);
        }

        // Ba khung nội dung.
        preg_match_all('/class="tab-pane fade[^"]*" id="([a-z-]+)" role="tabpanel"/', $html, $p);
        $this->assertSame(['detail-supplier', 'transaction-history', 'debt-history'], $p[1]);

        // Tab giao dịch có ô tìm và khoảng ngày; tab công nợ có chỗ đắp bảng nợ.
        $this->assertStringContainsString('id="search_purchase"', $html);
        $this->assertStringContainsString('id="from-date"', $html);
        $this->assertStringContainsString('id="to-date"', $html);
        $this->assertStringContainsString('id="list-debt"', $html);
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

        // Trả về ĐỦ phiếu: tab Lịch sử giao dịch cần thấy cả nháp lẫn huỷ.
        $this->assertCount(3, $res->json('data'));

        // Ba số tiền KHÔNG còn cộng ở đây nữa — chúng đi kèm chính dòng nhà cung
        // cấp, do API gộp bằng một câu truy vấn (chỉ tính phiếu đã duyệt; luật ấy
        // được canh ở TestNhaCungCap_BaSoTienChiTinhPhieuDaDuyet bên Go). Cộng
        // thêm một lần ở đây là hai chỗ tính, sớm muộn lệch nhau.
        $this->assertNull($res->json('tien'));

        // Xin đúng 100 phiếu: đó là TRẦN của API. Xin 200 thì bên đó không kẹp
        // xuống 100 mà rơi về mặc định 20 — hai tab mất phiếu mà không báo gì.
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'phieu-mua-hang')
            || str_contains($request->url(), 'page_size=100'));
    }

    /**
     * Lưu HỎNG từ hộp thoại thì trả 422 JSON, KHÔNG chuyển hướng.
     *
     * Hộp thoại đọc `success` để quyết định đóng hay giữ lại. Trả chuyển hướng
     * là trang tải lại, hộp biến mất rồi toast mới hiện — mất trắng mọi thứ vừa
     * gõ và người dùng phải khai lại từ đầu mới biết mình sai chỗ nào.
     */
    public function test_luu_hong_tu_hop_thoai_tra_422_khong_chuyen_huong(): void
    {
        Http::fake([
            '*/admin/nha-cung-cap' => Http::response([
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => ['name' => 'Tên nhà cung cấp này đã có trong cửa hàng'],
            ], 422),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phien())
            ->postJson('/admin/suppliers', ['name' => 'Trung ten', 'address' => 'Ha Noi', 'status' => 1]);

        $res->assertStatus(422);
        $res->assertJson(['success' => false]);
        // Lấy câu theo TỪNG Ô chứ không lấy `message` chung chung.
        $res->assertJsonPath('message', 'Tên nhà cung cấp này đã có trong cửa hàng');
    }

    /** Lưu ĐƯỢC thì trả 200 kèm câu để bắn toast xanh rồi đóng hộp. */
    public function test_luu_xong_tu_hop_thoai_tra_200(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())
            ->postJson('/admin/suppliers', ['name' => 'Trung ten', 'address' => 'Ha Noi', 'status' => 1]);

        $res->assertOk();
        $res->assertJson(['success' => true]);
    }
}
