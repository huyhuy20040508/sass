<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Khối "phí thẻ" trong hộp xem nhanh đơn (v2/orders/index.blade.php, vePhiThe).
 *
 * Huỷ một đơn quẹt thẻ rồi mở lại hộp: trạng thái ghi "Đã huỷ" nhưng khối phí
 * vẫn bày nguyên "Phí thẻ −435.600đ · Thực nhận 23.764.400đ". Trong khi đó Báo
 * cáo bán hàng đã loại hẳn đơn huỷ ra (deadStatuses bên API gồm cancelled và
 * returned) — hộp đơn nói có, báo cáo nói không, kèm một số "Thực nhận" không
 * bao giờ về.
 *
 * Chọn GIỮ dòng phí chứ không giấu cả khối: phí ấy đã chi thật, phiếu chi vẫn
 * nằm trong sổ quỹ. Thứ phải bỏ là "Thực nhận" — số tiền đơn này mang về, mà
 * đơn đã huỷ thì không mang về đồng nào.
 */
class OrderCardFeeTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly'],
        ];
    }

    protected function trang(): string
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1]])]);

        return $this->withSession($this->phien())->get('/admin/orders')->assertOk()->getContent();
    }

    public function test_don_huy_thi_bo_dong_thuc_nhan(): void
    {
        $html = $this->trang();

        $d = strpos($html, 'function vePhiThe(');
        $this->assertNotFalse($d, 'không tìm thấy vePhiThe()');
        $than = substr($html, $d, strpos($html, "\n        }", $d) - $d);

        // Xét ĐÚNG hai trạng thái mà báo cáo loại ra.
        $this->assertStringContainsString("o.status === 'cancelled'", $than);
        $this->assertStringContainsString("o.status === 'returned'", $than);

        // Bỏ dòng "Thực nhận" và nói ra vì sao.
        $this->assertStringContainsString("\$('#dh-v-thuc-nhan-dong')", $than, 'phải ẩn/hiện được riêng dòng Thực nhận');
        $this->assertStringContainsString('#dh-v-phi-huy', $than, 'phải có câu giải thích khi đơn không còn hiệu lực');

        // Ẩn bằng lớp d-none, KHÔNG bằng .toggle(): .d-flex của Bootstrap là
        // `display: flex !important` nên đè luôn style nội tuyến jQuery đặt — đã
        // mắc đúng lỗi này một lần, dòng vẫn hiện như thường.
        $this->assertMatchesRegularExpression("/dh-v-thuc-nhan-dong'\\)\\.toggleClass\\('d-none'/", $than);

        // Dòng phí và phiếu chi thì GIỮ: khoản ấy đã chi thật.
        $this->assertStringContainsString("\$('#dh-v-phi').text(", $than);
        $this->assertStringContainsString('dh-v-phi-phieu', $than);

        // Hộp có sẵn chỗ cho cả hai.
        $this->assertStringContainsString('id="dh-v-thuc-nhan-dong"', $html);
        $this->assertStringContainsString('id="dh-v-phi-huy"', $html);
    }
}
