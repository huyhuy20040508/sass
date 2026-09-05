<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trang Trả hàng nhà cung cấp (khuôn v2) dựng bằng API GIẢ.
 *
 * Không cần API thật chạy: bài này canh phần Shop Admin tự làm — đọc envelope
 * của API, dựng bảng và hộp lập phiếu theo đúng khuôn v2, giữ bộ lọc trên URL
 * và xuất CSV. Phần nghiệp vụ (trần trả hàng, trừ kho lúc duyệt) do apitest bên
 * Go canh.
 */
class TraHangNhaCungCapTest extends TestCase
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
            '*/admin/tra-hang-nha-cung-cap/stats*' => Http::response(['data' => [
                'total' => 9, 'draft' => 2, 'approved' => 7, 'returned_amount' => 3200000,
            ]], 200),
            '*/admin/tra-hang-nha-cung-cap*' => Http::response([
                'data' => [[
                    'id' => 11, 'return_code' => 'PTH20260901001', 'supplier_id' => 4,
                    'supplier_code' => 'NCC001', 'supplier_name' => 'Công ty TNHH An Bình',
                    'status' => 'draft', 'document_date' => '2026-09-01', 'branch_name' => 'Chi nhánh 1',
                    'items_amount' => 1200000, 'total_amount' => 1296000,
                    'creator_name' => 'Trần Thu Hà', 'note' => 'Hàng lỗi bao bì',
                ], [
                    'id' => 12, 'return_code' => 'PTH20260901002', 'supplier_id' => 5,
                    'supplier_code' => 'NCC002', 'supplier_name' => 'Cơ sở Minh Phát',
                    'status' => 'approved', 'document_date' => '2026-09-02', 'branch_name' => 'Chi nhánh 1',
                    'items_amount' => 2000000, 'total_amount' => 2160000,
                    'creator_name' => 'Trần Thu Hà', 'note' => '',
                ]],
                'meta' => ['page' => 1, 'page_size' => 20, 'total' => 2, 'total_pages' => 1],
            ], 200),
            '*/admin/nha-cung-cap*' => Http::response(['data' => [
                ['id' => 4, 'code' => 'NCC001', 'name' => 'Công ty TNHH An Bình'],
            ]], 200),
            '*/admin/nhan-su*' => Http::response(['data' => [
                ['id' => 7, 'code' => 'NV0007', 'full_name' => 'Trần Thu Hà'],
            ]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);
    }

    public function test_trang_dung_duoc(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/supplier-returns');

        $res->assertOk();

        // Bảng
        $res->assertSee('PTH20260901001');
        $res->assertSee('Công ty TNHH An Bình');
        $res->assertSee('Lưu tạm');
        $res->assertSee('Đã duyệt');
        $res->assertSee('Chưa xuất');
        $res->assertSee('Đã xuất');

        // Con số đầu trang
        $res->assertSee('3.200.000₫');

        // Tab trong hàng tab của module Kho, và nó đang sáng. Quên bật cờ trong
        // header là màn dựng xong mà không có đường nào vào.
        $res->assertSee('class="sub-nav-btn active"', false);
        $this->assertStringContainsString('Trả hàng',
            $this->tabDangSang($res->getContent()));

        // Nút và tiện ích trên thanh tiêu đề của khuôn v2
        $res->assertSee('Tạo mới');
        $res->assertSee('Xuất Excel');
        $res->assertSee('Nâng cao');

        // Mười ba cột của bảng
        $res->assertSee('Mã phiếu');
        $res->assertSee('Mã nhà cung cấp');
        $res->assertSee('Trạng thái kho');
        $res->assertSee('Người tạo');

        // Hộp lập phiếu — hai cột thông tin của bản v2
        $res->assertSee('Nhà cung cấp');
        $res->assertSee('Ngày chứng từ');
        $res->assertSee('Nhân viên mua hàng');
        $res->assertSee('Phiếu trả hàng nhà cung cấp');
        $res->assertSee('Trần Thu Hà');

        // Lưới hàng: ba cột số lượng là thứ riêng của màn trả hàng
        $res->assertSee('Thông tin hàng hóa');
        $res->assertSee('Số lượng nhập');
        $res->assertSee('Số lượng còn lại');
        $res->assertSee('Số lô');

        // Khối tiền ba dòng
        $res->assertSee('Tổng tiền thuế (VAT)');
    }

    /** Cắt lấy chữ trong tab đang sáng của hàng tab module. */
    protected function tabDangSang(string $html): string
    {
        $dau = strpos($html, 'class="sub-nav-btn active"');
        $this->assertNotFalse($dau, 'Hàng tab không có tab nào đang sáng.');
        $dau = strpos($html, '>', $dau);

        return substr($html, $dau, strpos($html, '</a>', $dau) - $dau);
    }

    /** Bộ lọc nằm trên URL nên chia sẻ được, và ô lọc mở ra vẫn đúng như đã chọn. */
    public function test_bo_loc_giu_tren_url(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())
            ->get('/admin/supplier-returns?keyword=PTH&status[]=draft&supplier_id=4&from_date=01-09-2026&to_date=30-09-2026');

        $res->assertOk();
        $res->assertSee('value="PTH"', false);
        $res->assertSee('value="01-09-2026"', false);

        // Ngày gõ theo DD-MM-YYYY của ô lịch v2 phải tới API dưới khuôn ISO.
        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/admin/tra-hang-nha-cung-cap?')
            || (str_contains($req->url(), 'from_date=2026-09-01')
                && str_contains($req->url(), 'to_date=2026-09-30')));
    }

    /**
     * Phân trang: dựng đúng khuôn bootstrap-4 mà bản v2 in ra, và STT đánh số
     * tiếp theo trang chứ không quay về 1.
     */
    public function test_phan_trang(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = [
                'id' => $i, 'return_code' => 'PTH'.$i, 'supplier_id' => 4,
                'supplier_code' => 'NCC001', 'supplier_name' => 'An Binh', 'status' => 'draft',
                'document_date' => '2026-09-01', 'branch_name' => 'CN1',
                'items_amount' => 1000, 'total_amount' => 1080, 'creator_name' => 'A', 'note' => '',
            ];
        }
        Http::fake([
            '*/admin/tra-hang-nha-cung-cap/stats*' => Http::response(['data' => []], 200),
            '*/admin/tra-hang-nha-cung-cap*' => Http::response([
                'data' => $rows,
                'meta' => ['page' => 3, 'page_size' => 10, 'total' => 45, 'total_pages' => 5],
            ], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $res = $this->withSession($this->phien())->get('/admin/supplier-returns?page=3');
        $res->assertOk();
        $html = $res->getContent();

        // Khuôn của v2: <nav><ul class="pagination">, mũi tên ‹ ›, trang đang xem
        // là <span> chứ không phải <a>.
        $this->assertMatchesRegularExpression('#<nav>\s*<ul class="pagination">#', $html);
        $res->assertSee('&lsaquo;', false);
        $res->assertSee('&rsaquo;', false);
        $res->assertSee('<li class="page-item active" aria-current="page"><span class="page-link">3</span></li>', false);
        $res->assertSee('supplier-returns?page=2', false);
        $res->assertSee('supplier-returns?page=4', false);

        // STT của trang 3 với cỡ trang 10 phải bắt đầu từ 21, không quay về 1.
        $this->assertStringContainsString('>21</td>', preg_replace('/\s+/', '', $html));
    }

    /**
     * Chưa ai chọn cỡ trang thì lấy 10 — đúng dòng ĐẦU của ô "Hiển thị N" bên v2
     * (bên đó không đánh dấu selected dòng nào nên trình duyệt lấy dòng đầu).
     */
    public function test_co_trang_mac_dinh_la_10(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/supplier-returns');

        $res->assertOk();
        $res->assertSee('<option value="10" selected>', false);

        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/admin/tra-hang-nha-cung-cap?')
            || str_contains($req->url(), 'page_size=10'));
    }

    /** Xuất mang theo đúng bộ lọc đang xem. */
    public function test_xuat_csv(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phien())->get('/admin/supplier-returns/export');

        $res->assertOk();
        $this->assertStringContainsString('.csv', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('PTH20260901001', $res->streamedContent());
    }
}
