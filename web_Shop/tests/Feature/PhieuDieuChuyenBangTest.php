<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BẢNG PHIẾU ĐIỀU CHUYỂN PHẢI ĐỌC ĐÚNG TÊN TRƯỜNG CỦA API.
 *
 * Màn này bê nguyên từ v2 cũ, nơi bảng gọi là `voucher_number` /
 * `from_warehouse_name`. API mới trả `transfer_code` / `from_shop_name`, nên
 * bảng vẽ ra trống trơn: cột Số phiếu chỉ còn dấu gạch, và người dùng đọc thành
 * "mã phiếu chưa được tạo" — dù trong sổ mã vẫn sinh ra bình thường.
 *
 * Lỗi kiểu này im lặng tuyệt đối: `?? ''` nuốt sạch, không lỗi, không log. Bài
 * này là chỗ duy nhất phát hiện ra nó.
 */
class PhieuDieuChuyenBangTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
            ApiClient::KHOA_CHI_NHANH => 1,
        ];
    }

    protected function fakeApi(): void
    {
        Http::fake([
            '*/admin/phieu-dieu-chuyen*' => Http::response(['data' => [[
                'id' => 7,
                'transfer_code' => 'PDC202609040001',
                'from_shop_id' => 1,
                'to_shop_id' => 11,
                'from_shop_name' => 'Kho trung tâm',
                'to_shop_name' => 'Kho Quận 7',
                'creator_name' => 'Quốc Huy',
                'receiver_name' => 'Thủ kho 2',
                'status' => 'approved',
                'note' => 'chuyển bù hàng',
                'created_at' => '2026-09-04T12:03:02Z',
            ]], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 1, 'total_pages' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /**
     * HTML của trang, ĐÃ BỎ khối `v2-rows`.
     *
     * Trang nhét nguyên mảng API vào một thẻ <script> cho JS mở hộp chi tiết
     * dùng. Cứ assertSee thẳng là dính vào khối đó và bài kiểm xanh kể cả khi
     * bảng trống trơn — đúng cái bẫy đã cho lỗi này lọt qua.
     */
    protected function bang(): string
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien())
            ->get('/admin/stock-transfers')
            ->assertOk()
            ->getContent();

        return preg_replace('#<script[^>]*id="v2-rows"[^>]*>.*?</script>#s', '', $html);
    }

    /** Số phiếu là thứ người dùng bấm để mở phiếu — trống là màn hình chết. */
    public function test_bang_hien_ma_phieu(): void
    {
        $this->assertStringContainsString('PDC202609040001', $this->bang());
    }

    /**
     * NÚT IN PHẢI CÓ NGƯỜI NHẬN.
     *
     * Nút In trong hộp phiếu vốn được bày ra đầy đủ — có icon, có chữ, bỏ lớp
     * d-none khi mở phiếu — nhưng không handler nào lắng nghe nó. Bấm vào thì
     * không có gì xảy ra, không cả một dòng báo lỗi, nên nhìn hệt như trình
     * duyệt đang treo.
     *
     * Bài này gác đúng khoảng trống ấy: có nút thì phải có chỗ nhận cú bấm.
     */
    public function test_nut_in_trong_hop_phieu_co_handler(): void
    {
        $html = $this->bang();

        $this->assertStringContainsString('pdc-in', $html, 'thiếu nút In trong hộp phiếu');
        $this->assertStringContainsString("'#modalCreate .pdc-in'", $html,
            'nút In không có handler nào — bấm vào sẽ không có gì xảy ra');
    }

    /** Hai đầu kho: xem phiếu mà không biết hàng đi từ đâu về đâu thì vô nghĩa. */
    public function test_bang_hien_hai_dau_kho(): void
    {
        $html = $this->bang();

        $this->assertStringContainsString('Kho trung tâm', $html);
        $this->assertStringContainsString('Kho Quận 7', $html);
    }
}
