<?php

namespace Tests\Feature;

use App\Support\MaVach;
use App\Support\TienBangChu;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phiếu in của quầy (14/10/2026): các dòng mới của mẫu Hoá đơn, in bằng khung ẩn
 * (?nhung=1), hoá đơn A5/A4, phiếu trả hàng, ô xem trước của Cài đặt thiết bị.
 * API giả lập — luật lưu mẫu nằm ở api/internal/apitest/print_receipt_test.go.
 */
class PrintSlipTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function don(array $them = []): array
    {
        return $them + [
            'order_code' => 'DH000088', 'shop_id' => 2, 'payment_method' => 'cash', 'total_amount' => 512000,
            'amount_tendered' => 600000, 'change_amount' => 88000, 'discount_amount' => 20000, 'rank_discount' => 20000,
            'recipient_name' => 'Chị Lan', 'thanh_vien' => ['hang' => 'Vàng', 'diem' => 1916], 'points_earned' => 51,
            'items' => [
                ['id' => 501, 'product_name' => 'Tai nghe', 'unit_name' => 'Cái', 'quantity' => 2, 'unit_price' => 290000, 'discount_amount' => 58000, 'discount_percent' => 10],
            ],
        ];
    }

    protected function gia(array $mau, array $don = []): void
    {
        Http::fake([
            '*/admin/orders/88' => Http::response(['data' => $this->don($don)]),
            '*/admin/orders/pos/mau-in/*' => fn (HttpRequest $r) => Http::response(['data' => $mau + ['ma' => basename(parse_url($r->url(), PHP_URL_PATH))]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    public function test_phieu_in_du_dong_moi_khi_bat(): void
    {
        $this->gia(['options' => ['wifi' => true, 'chinhsach' => true], 'settings' => ['kho' => '80', 'wifi_txt' => 'Shop · MK 123', 'chinhsach_txt' => 'Đổi trong 7 ngày']]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->assertOk()->getContent();

        foreach (['2 Cái × 290.000', 'Bạn đã tiết kiệm 78.000đ', 'Hạng Vàng · Điểm hiện có', '1.916', 'Shop · MK 123', 'Đổi trong 7 ngày', '<svg', 'Khách: Chị Lan · Vàng'] as $can) {
            $this->assertStringContainsString($can, $html, "thiếu: {$can}");
        }
        $this->assertStringContainsString('window.print()', $html, 'mở thẳng (không nhúng) thì trang tự in');
    }

    public function test_tat_dong_moi_thi_khong_in(): void
    {
        $this->gia(['options' => ['wifi' => false, 'chinhsach' => false, 'mavach' => false, 'tietkiem' => false, 'diemconlai' => false, 'dvt' => false],
            'settings' => ['wifi_txt' => 'Shop · MK 123', 'chinhsach_txt' => 'Đổi trong 7 ngày']]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->assertOk()->getContent();

        foreach (['Cái ×', 'tiết kiệm', 'Điểm hiện có', 'Shop · MK 123', 'Đổi trong 7 ngày', '<svg'] as $khong) {
            $this->assertStringNotContainsString($khong, $html, "dòng đã tắt vẫn in: {$khong}");
        }
    }

    /** In bằng khung ẩn: trang không tự in (màn quầy gọi), không vẽ thanh nút; ?ban= thắng mẫu. */
    public function test_che_do_nhung_va_so_ban(): void
    {
        $this->gia(['options' => [], 'settings' => ['ban' => 1]]);

        $res = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88, 'nhung' => 1, 'ban' => 3, 'paper' => '58']))->assertOk();

        $res->assertViewHas('soBan', 3)->assertViewHas('khoGiay', '58')->assertViewHas('tuIn', false);
        $this->assertStringNotContainsString('window.print', $res->getContent());
        $this->assertSame(3, substr_count($res->getContent(), 'class="ban"'));
    }

    public function test_hoa_don_a4_doc_mau_a4_va_doc_so_tien(): void
    {
        $this->gia(['options' => ['ckXuatKho' => true], 'settings' => ['ban' => 1]]);

        $res = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.hoaDonA', ['id' => 88, 'kho' => 'a4']))->assertOk();

        $res->assertViewHas('khoGiay', 'a4');
        $html = $res->getContent();
        $this->assertStringContainsString('size: A4 portrait', $html);
        $this->assertStringContainsString('Năm trăm mười hai nghìn đồng', $html);
        $this->assertStringContainsString('Người xuất kho', $html);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/orders/pos/mau-in/a4') && $r->header('X-Chi-Nhanh') === ['2']);
    }

    public function test_phieu_tra_doc_mau_tra_va_lay_dvt_tu_don_goc(): void
    {
        Http::fake([
            '*/admin/orders/pos/tra-hang/7' => Http::response(['data' => [
                'return_code' => 'TH000007', 'shop_id' => 2, 'reason' => 'defective', 'refund_method' => 'cash', 'refund_amount' => 261000,
                'items' => [['order_item_id' => 501, 'product_name' => 'Tai nghe', 'quantity' => 1, 'unit_price' => 261000, 'total_price' => 261000]],
                'order' => ['order_code' => 'DH000088', 'items' => [['id' => 501, 'unit_name' => 'Cái']]],
            ]]),
            '*/admin/orders/pos/mau-in/tra' => Http::response(['data' => ['options' => ['lydo' => true], 'settings' => ['kho' => '80']]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.phieu', ['id' => 7]))->assertOk()->getContent();

        foreach (['PHIẾU TRẢ HÀNG', 'TH000007', 'DH000088', '1 Cái × 261.000', 'Sản phẩm lỗi/hỏng', 'Tiền mặt'] as $can) {
            $this->assertStringContainsString($can, $html, "thiếu: {$can}");
        }
    }

    /**
     * Lượt ĐỔI HÀNG in ra phải nói được khoản tiền thật sự đổi tay.
     *
     * Phiếu đổi đi theo khuôn phiếu hoàn thì tờ giấy ghi "HOÀN LẠI 0 · Hình
     * thức hoàn: Không hoàn tiền (đổi hàng)" — đúng theo cột trong sổ (API đặt
     * refund_amount = 0, tiền đi qua chênh lệch) nhưng sai với việc vừa xảy ra:
     * khách cầm về 8.800.000 từ két. Không dòng nào ghi món khách lấy về, số
     * chênh, hay mã đơn mới, mà luồng quầy chỉ in đúng một tờ này.
     *
     * API chi tiết phiếu KHÔNG kèm `exchange_order` (chỉ danh sách phiếu mới
     * preload) nên màn in phải hỏi thêm một lượt — bài này canh cả lượt gọi ấy.
     */
    public function test_phieu_doi_hang_in_hang_lay_ve_va_so_chenh(): void
    {
        Http::fake([
            '*/admin/orders/pos/tra-hang/14' => Http::response(['data' => [
                'return_code' => 'TKH000012', 'shop_id' => 2, 'exchange_order_id' => 900080,
                'refund_method' => 'none', 'refund_amount' => 0, 'items_amount' => 30800000, 'deduction' => 0,
                'items' => [['order_item_id' => 501, 'product_name' => 'Iphone 16 pro max', 'quantity' => 1, 'unit_price' => 30800000, 'total_price' => 30800000]],
                'order' => ['order_code' => 'DH000085', 'items' => [['id' => 501, 'unit_name' => 'Cái']]],
            ]]),
            '*/admin/orders/900080' => Http::response(['data' => [
                'order_code' => 'DH000086', 'total_amount' => 22000000, 'payment_method' => 'cash',
                'items' => [['product_name' => 'Laptop Asus', 'variant_name' => 'RTX 3050', 'quantity' => 1, 'unit_price' => 22000000, 'total_price' => 22000000]],
            ]]),
            '*/admin/orders/pos/mau-in/tra' => Http::response(['data' => ['options' => [], 'settings' => ['kho' => '80']]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.phieu', ['id' => 14]))->assertOk()->getContent();

        foreach (['PHIẾU ĐỔI HÀNG', 'KHÁCH TRẢ LẠI', 'KHÁCH LẤY VỀ', 'Laptop Asus', 'DH000085', 'DH000086',
            'Tiền hàng trả', '30.800.000', 'Tiền hàng mới', '22.000.000', 'TRẢ LẠI KHÁCH', '8.800.000'] as $can) {
            $this->assertStringContainsString($can, $html, "thiếu: {$can}");
        }

        // Không còn hai dòng nói sai việc: hoàn 0 và "không hoàn tiền".
        $this->assertStringNotContainsString('HOÀN LẠI', $html);
        $this->assertStringNotContainsString('Không hoàn tiền', $html);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/orders/900080'));
    }

    /** Chiều ngược lại: hàng mới đắt hơn thì khách bù thêm. */
    public function test_phieu_doi_hang_mon_moi_dat_hon_thi_ghi_khach_tra_them(): void
    {
        Http::fake([
            '*/admin/orders/pos/tra-hang/15' => Http::response(['data' => [
                'return_code' => 'TKH000013', 'shop_id' => 2, 'exchange_order_id' => 900090,
                'refund_method' => 'none', 'refund_amount' => 0, 'items_amount' => 5000000,
                'items' => [['order_item_id' => 1, 'product_name' => 'Tai nghe', 'quantity' => 1, 'unit_price' => 5000000, 'total_price' => 5000000]],
                'order' => ['order_code' => 'DH000090', 'items' => []],
            ]]),
            '*/admin/orders/900090' => Http::response(['data' => [
                'order_code' => 'DH000091', 'total_amount' => 7500000, 'payment_method' => 'bank_transfer',
                'items' => [['product_name' => 'Loa', 'quantity' => 1, 'unit_price' => 7500000, 'total_price' => 7500000]],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.phieu', ['id' => 15]))->assertOk()->getContent();

        $this->assertStringContainsString('KHÁCH TRẢ THÊM', $html);
        $this->assertStringContainsString('2.500.000', $html);
        $this->assertStringContainsString('Chuyển khoản', $html, 'phải ghi tiền đi đường nào');
    }

    /** Hỏi đơn mới mà API hỏng thì vẫn in được phiếu — thiếu khối còn hơn không có tờ nào. */
    public function test_doi_hang_api_don_moi_hong_van_in_duoc(): void
    {
        Http::fake([
            '*/admin/orders/pos/tra-hang/16' => Http::response(['data' => [
                'return_code' => 'TKH000014', 'shop_id' => 2, 'exchange_order_id' => 900099,
                'refund_method' => 'none', 'refund_amount' => 0, 'items_amount' => 100000,
                'items' => [], 'order' => ['order_code' => 'DH000099', 'items' => []],
            ]]),
            '*/admin/orders/900099' => Http::response(['message' => 'Lỗi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.phieu', ['id' => 16]))->assertOk()->getContent();
        $this->assertStringContainsString('TKH000014', $html);
    }

    public function test_phieu_tra_khong_doc_duoc_thi_404(): void
    {
        Http::fake(['*/admin/orders/pos/tra-hang/7' => Http::response(['message' => 'Không tìm thấy'], 404), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.phieu', ['id' => 7]))->assertNotFound();
    }

    /** Xem trước vẽ bằng trang in thật với lựa chọn đang sửa (chưa lưu). */
    public function test_xem_truoc_dung_trang_in_that(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $s = $this->withSession($this->phien());

        $hd = $s->postJson(route('admin.thong-so-chung.devices.xemTruoc', 'hd'), ['options' => ['qr' => true, 'mavach' => 'false'], 'settings' => ['kho' => '58']])->assertOk();
        $hd->assertViewHas('khoGiay', '58')->assertViewHas('tuIn', false);
        $this->assertStringContainsString('qr.sepay.vn', $hd->getContent());
        $this->assertStringNotContainsString('<svg', $hd->getContent());

        $s->postJson(route('admin.thong-so-chung.devices.xemTruoc', 'a5'), ['options' => []])->assertOk()->assertViewIs('v2::pos.invoice-a');
        $s->postJson(route('admin.thong-so-chung.devices.xemTruoc', 'tra'), ['options' => []])->assertOk()->assertSee('PHIẾU TRẢ HÀNG');
        $s->postJson(route('admin.thong-so-chung.devices.xemTruoc', 'tem'), [])->assertNotFound();
    }

    public function test_doc_so_tien_bang_chu(): void
    {
        foreach ([
            0 => 'Không đồng', 15 => 'Mười lăm đồng', 21 => 'Hai mươi mốt đồng', 105 => 'Một trăm lẻ năm đồng',
            1005 => 'Một nghìn không trăm lẻ năm đồng', 1250000 => 'Một triệu hai trăm năm mươi nghìn đồng',
            1000000000000 => 'Một nghìn tỷ đồng',
        ] as $so => $chu) {
            $this->assertSame($chu, TienBangChu::doc($so));
        }
    }

    /** Mã vạch Code 128: bắt đầu bằng START B, kết bằng STOP; chữ ngoài bộ B bị bỏ qua. */
    public function test_ma_vach_code128(): void
    {
        $svg = MaVach::svg('DH1');
        $this->assertStringStartsWith('<svg', $svg);
        // START B (211214) bắt đầu sau 10 module trắng: vạch đầu rộng 2 tại x=10.
        $this->assertStringContainsString('d="M10 0h2v', $svg);
        $this->assertSame('', MaVach::svg('ầ'));
    }
}
