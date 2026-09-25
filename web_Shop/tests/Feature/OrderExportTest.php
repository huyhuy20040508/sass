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
class OrderExportTest extends TestCase
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
            // Bắt-tất: thiếu nó thì mấy lượt gọi khác của middleware (hạn hợp
            // đồng) đi ra API THẬT, và bài kiểm đổi màu theo việc máy đang chạy
            // API hay không — chứ không theo mã nguồn.
            '*' => Http::response(['data' => []]),
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

    /**
     * Xuất theo BỘ LỌC — bản xuất của màn hình, đọc CÙNG sổ chứng từ với bảng.
     *
     * Trước đây nhánh này đọc danh sách thực thể đơn: tick "Đã thanh toán" rồi
     * xuất là ra tệp rỗng (mã trạng thái của sổ bị đem so với `orders.status`),
     * và phiếu trả không có mặt. Bài này chốt cả hai: tham số trạng thái đi sang
     * đúng đường sổ chứng từ, và phiếu trả + hàng tổng cộng có trong tệp.
     */
    public function test_xuat_theo_bo_loc(): void
    {
        Http::fake([
            '*/admin/orders/so-don*' => Http::response([
                'data' => [
                    ['loai' => 'don', 'id' => 11, 'ma' => 'DH011', 'kenh' => 'pos', 'khach_hang' => 'Chị Lan',
                        'created_at' => '2026-08-17T10:00:00+07:00', 'tien_mat' => 30000, 'chuyen_khoan' => 20000,
                        'the_vi' => 0, 'tong_tien' => 100000, 'co_cong_no' => true, 'trang_thai' => 'partial'],
                    ['loai' => 'tra-hang', 'id' => 3, 'ma' => 'TH003', 'kenh' => 'web',
                        'created_at' => '2026-08-17T11:00:00+07:00', 'tien_mat' => 50000, 'chuyen_khoan' => 0,
                        'the_vi' => 0, 'tong_tien' => 50000, 'co_cong_no' => false, 'trang_thai' => 'returned'],
                ],
                'meta' => ['total_pages' => 1],
            ]),
            '*' => Http::response(['data' => []]),
        ]);

        $csv = $this->withSession($this->phienQuanTri())
            ->get(route('admin.orders.export', ['status' => 'partial,returned']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('DH011', $csv);
        $this->assertStringContainsString('Thanh toán một phần', $csv);
        $this->assertStringContainsString('TH003', $csv);
        $this->assertStringContainsString('Phiếu trả hàng', $csv);
        // Hàng tổng cộng như bản xuất của v2: 30.000 + 50.000 tiền mặt.
        $this->assertStringContainsString('Tổng cộng', $csv);
        $this->assertStringContainsString('80000', $csv);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/orders/so-don')
            && ($req->data()['status'] ?? null) === 'partial,returned');
        Http::assertNotSent(fn ($req) => preg_match('#/admin/orders(\?|$)#', $req->url()) === 1);
    }

    /**
     * Hai mặc định lấy theo v2: sắp xếp "vừa đụng tới" (`updated_at`) và tick ĐỦ
     * mọi phương thức = không lọc. Gửi nguyên bảy phương thức thì API vẫn lọc và
     * gạt mất phiếu trả không hoàn tiền của lượt đổi hàng.
     */
    public function test_mac_dinh_giong_v2(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total_pages' => 1]])]);

        $tatCa = implode(',', array_keys(\App\Http\Controllers\OrderController::PAYMENT_METHODS));
        $this->withSession($this->phienQuanTri())
            ->get(route('admin.orders.index', ['payment_method' => $tatCa]))
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/orders/so-don')
            && ($req->data()['sort'] ?? null) === 'updated'
            && ($req->data()['payment_method'] ?? null) === 'all');
    }

    /** Chọn toàn id không có thật thì nói rõ là chưa chọn được đơn nào. */
    public function test_khong_co_don_nao_thi_bao_404(): void
    {
        Http::fake([
            '*/admin/orders/99' => Http::response([], 404),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phienQuanTri())
            ->get(route('admin.orders.export', ['ids' => '99']))
            ->assertNotFound();
    }
}
