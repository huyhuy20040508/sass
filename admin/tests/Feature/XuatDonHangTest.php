<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Xuất danh sách đơn hàng ra CSV.
 *
 * Trang đơn có hai đường xuất khác nhau và chúng đi hai nhánh code khác nhau:
 * xuất THEO BỘ LỌC (không có ?ids) gọi fetchAll, còn xuất CÁC ĐƠN ĐÃ CHỌN
 * (?ids=1,2,3) gọi fetchOrdersForPrint. Bài kiểm ở đây đi cả hai, vì nhánh thứ
 * hai từng gãy suốt mà không ai biết — nó chỉ chạy khi người dùng tick vào vài
 * dòng rồi bấm Xuất, một thao tác không có trong đường đi thường ngày.
 */
class XuatDonHangTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    /** Một đơn đủ trường để dựng một dòng CSV. */
    protected function donMau(int $id, string $ma, string $kenh = 'web'): array
    {
        return ['data' => [
            'id' => $id,
            'order_code' => $ma,
            'channel' => $kenh,
            'created_at' => '2026-08-17T10:00:00+07:00',
            'recipient_name' => 'Chị Lan',
            'recipient_phone' => '0900000001',
            'shipping_address' => '12 Lê Lợi',
            'subtotal_amount' => 100000,
            'discount_amount' => 0,
            'shipping_fee' => 0,
            'total_amount' => 100000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
            'items' => [['quantity' => 2]],
        ]];
    }

    /**
     * Xuất CÁC ĐƠN ĐÃ CHỌN.
     *
     * Đây là đường đã gãy: fetchOrdersForPrint nhận ba tham số bắt buộc
     * (request, id, việc-đang-làm) nhưng chỗ gọi trong export() chỉ truyền hai —
     * PHP ném ArgumentCountError và người dùng nhận trang lỗi 500 ngay khi bấm
     * Xuất trên vài đơn vừa tick.
     */
    public function test_xuat_cac_don_da_chon(): void
    {
        Http::fake([
            '*/admin/orders/11' => Http::response($this->donMau(11, 'DH011', 'pos')),
            '*/admin/orders/12' => Http::response($this->donMau(12, 'DH012')),
        ]);

        $res = $this->withSession($this->phienQuanTri())
            ->get(route('admin.orders.export', ['ids' => '11,12']))
            ->assertOk();

        $csv = $res->streamedContent();
        $this->assertStringContainsString('DH011', $csv);
        $this->assertStringContainsString('DH012', $csv);
        // Cột Kênh của đợt bán tại quầy phải có mặt trong tệp xuất ra.
        $this->assertStringContainsString('Bán tại quầy', $csv);
        $this->assertStringContainsString('Đơn giao hàng', $csv);
    }

    /** Xuất theo BỘ LỌC — nhánh còn lại, không có ?ids. */
    public function test_xuat_theo_bo_loc(): void
    {
        Http::fake(['*/admin/orders*' => Http::response([
            'data' => [$this->donMau(11, 'DH011', 'pos')['data']],
            'meta' => ['total_pages' => 1],
        ])]);

        $csv = $this->withSession($this->phienQuanTri())
            ->get(route('admin.orders.export', ['channel' => 'pos']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('DH011', $csv);
    }

    /** Chọn toàn id không có thật thì nói rõ là chưa chọn được đơn nào. */
    public function test_khong_co_don_nao_thi_bao_404(): void
    {
        Http::fake(['*/admin/orders/99' => Http::response([], 404)]);

        $this->withSession($this->phienQuanTri())
            ->get(route('admin.orders.export', ['ids' => '99']))
            ->assertNotFound();
    }
}
