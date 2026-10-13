<?php

namespace Tests\Feature;

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hộp "Thu tiền" của đơn còn nợ (v2/orders/index.blade.php).
 *
 * Ô Hình thức chỉ có ba lựa chọn THU TAY (tiền mặt / chuyển khoản / quẹt thẻ),
 * trong khi đơn có thể mang hình thức khác: QR tự động (sepay, payos) hay mã cũ
 * (vnpay, momo). Lúc ấy `.val('sepay')` không khớp option nào và ô thành TRỐNG
 * TRƠN — bấm Lưu vẫn ghi được, vì API tự lấp bằng hình thức của đơn
 * (order_number_repository.GhiLuotThu), nhưng người thu không hề biết tiền sẽ
 * vào cột nào.
 *
 * Cách chữa KHÔNG phải là chọn đại một hình thức khác: làm vậy là đổi cột tiền
 * của lượt thu mà không ai yêu cầu — đúng cái lỗi 57/58/59 vừa chữa. Thêm một ô
 * "giữ nguyên" (value rỗng, đúng nhánh API tự lấp) và nói rõ tên hình thức.
 */
class OrderCollectMoneyTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly'],
        ];
    }

    protected function trang(string $duong): string
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1]])]);

        return $this->withSession($this->phien())->get($duong)->assertOk()->getContent();
    }

    /**
     * HAI màn dùng chung hộp Thu tiền: Quản lý đơn hàng và CRM → Danh sách đơn
     * hàng. Khuôn chép đôi nên sửa một bên là bên kia vẫn trống trơn — bài chạy
     * cả hai để không ai quên nửa còn lại.
     *
     * @dataProvider manCoHopThuTien
     */
    public function test_hinh_thuc_ngoai_ba_lua_chon_thi_co_o_giu_nguyen(string $duong): void
    {
        $html = $this->trang($duong);

        // Ô "giữ nguyên" đứng trong chính ô chọn hình thức của hộp Thu tiền.
        $dau = strpos($html, 'id="tt-phuong-thuc"');
        $this->assertNotFalse($dau, 'không tìm thấy ô Hình thức của hộp Thu tiền');
        $o = substr($html, $dau, strpos($html, '</select>', $dau) - $dau);

        $this->assertStringContainsString('<option value="">', $o, 'thiếu ô "giữ nguyên" — hình thức lạ sẽ để ô trống trơn');
        foreach (array_keys(OrderController::HINH_THUC_THU_TAY) as $ma) {
            $this->assertStringContainsString('value="'.$ma.'"', $o);
        }

        // Và lượt mở hộp phải CHỌN ô ấy khi hình thức của đơn không thu tay được,
        // chứ không để .val() rơi vào khoảng không.
        $k = strpos($html, "\$('#tt-phuong-thuc')");
        $this->assertNotFalse($k, 'không tìm thấy chỗ đặt hình thức lúc mở hộp');
        $than = substr($html, $k - 600, 1800);

        $this->assertStringContainsString('thuTay.includes(ptDon)', $than, 'phải xét hình thức của đơn có thu tay được không');
        $this->assertMatchesRegularExpression("/option\\[value=\"\"\\]'\\)\\.text\\(/", $than, 'phải ghi tên hình thức vào ô "giữ nguyên"');
        $this->assertStringContainsString('DH_PAY_METHODS[ptDon]', $than, 'tên hình thức lấy từ bảng nhãn dùng chung');

        // KHÔNG được tự chọn đại một hình thức khác.
        $this->assertStringNotContainsString("\$('#tt-phuong-thuc').val(\$chiTiet.data('phuong-thuc') || 'cash')", $html,
            'chọn đại "cash" là đổi cột tiền của lượt thu mà không ai yêu cầu');
    }

    /** @return array<string, array<int, string>> */
    public static function manCoHopThuTien(): array
    {
        return [
            'Quản lý đơn hàng' => ['/admin/orders'],
            'CRM → Danh sách đơn hàng' => ['/admin/crm/orders'],
        ];
    }

    /** Ô rỗng vẫn phải lọt chốt chặn của controller — đó là nhánh "giữ nguyên". */
    public function test_de_trong_hinh_thuc_van_ghi_duoc(): void
    {
        Http::fake([
            '*/admin/orders/*/thanh-toan*' => Http::response(['data' => ['id' => 9], 'message' => 'Đã ghi lượt thu tiền.']),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->postJson(route('admin.orders.ghiLuotThu', ['id' => 900034]), [
                'amount' => 24200000,
                'payment_method' => '',
            ])
            ->assertSuccessful();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['amount'] ?? null) == 24200000);
    }
}
