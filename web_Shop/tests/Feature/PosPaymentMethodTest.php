<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hộp Thanh toán ở quầy bày đúng các hình thức chi nhánh đang bật (Cài đặt →
 * Phương thức thanh toán) và chuyển đủ thông tin quẹt thẻ / QR tự động sang API.
 */
class PosPaymentMethodTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff']],
        ];
    }

    protected function bonPhuongThuc(): array
    {
        return [
            ['type' => 1, 'is_default' => false, 'details' => []],
            ['type' => 4, 'is_default' => false, 'details' => [
                ['id' => 31, 'detail_type' => 'bank_transfer', 'is_default' => true, 'bank_name' => 'Vietcombank', 'bank_account' => '0071', 'bank_account_name' => 'A'],
            ]],
            ['type' => 5, 'is_default' => true, 'details' => [
                ['id' => 41, 'detail_type' => 'physical_device', 'is_default' => true, 'device_name' => 'VCB · May quay 1', 'card_fee' => ['card_fee_physical' => '1.50']],
            ]],
            ['type' => 6, 'is_default' => false, 'details' => [
                ['id' => 51, 'detail_type' => 'sepay', 'is_default' => false, 'bank_name' => 'MBBank', 'bank_account' => '0868'],
            ]],
        ];
    }

    public function test_hop_thanh_toan_bay_dung_bon_hinh_thuc_cua_chi_nhanh(): void
    {
        Http::fake([
            '*/admin/orders/pos/payment-methods' => Http::response(['data' => $this->bonPhuongThuc()]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();

        foreach (['cash', 'bank_transfer', 'card', 'auto_qr'] as $ma) {
            $this->assertStringContainsString('data-method="'.$ma.'"', $html);
        }
        $this->assertStringContainsString('pay-methods--4', $html);
        // Hai nút "Sắp có" (Kết hợp, Ghi nợ) của hộp Thanh toán đã bỏ.
        $hop = $this->giuaThe($html, 'id="posPayBox"', '</dialog>');
        $this->assertStringNotContainsString('Sắp có', $hop);
        $this->assertStringNotContainsString('Ghi nợ', $hop);
        $this->assertStringNotContainsString('Kết hợp', $hop);
        // Dữ liệu cho JS: máy quẹt thẻ kèm phí, cổng QR, ô mặc định.
        $pt = json_decode(html_entity_decode($this->giuaThe($html, 'data-phuong-thuc="', '"')), true);
        $this->assertSame('card', collect($pt)->firstWhere('md', true)['ma']);
        $this->assertSame(1.5, collect($pt)->firstWhere('ma', 'card')['ct'][0]['phi']);
        $this->assertSame('sepay', collect($pt)->firstWhere('ma', 'auto_qr')['ct'][0]['loai']);
    }

    public function test_api_hong_thi_quay_van_thu_tien_mat_va_chuyen_khoan(): void
    {
        Http::fake([
            '*/admin/orders/pos/payment-methods' => Http::response(['message' => 'lỗi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-method="cash"', $html);
        $this->assertStringContainsString('data-method="bank_transfer"', $html);
        $this->assertStringNotContainsString('data-method="card"', $html);
        $this->assertStringContainsString('pay-methods--2', $html);
    }

    public function test_chot_don_quet_the_gui_kem_may(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response(['data' => ['order_id' => 9, 'card_fee' => 1500]], 201), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.store'), [
            'payment_method' => 'card', 'payment_detail_id' => 41,
            'items' => [['product_variant_id' => 1, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.card_fee', 1500);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/orders/pos')
            && $r['payment_method'] === 'card' && $r['payment_detail_id'] === 41);
    }

    public function test_tien_mat_khong_gui_chi_tiet_va_hinh_thuc_la_bi_chan(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response(['data' => ['order_id' => 9]], 201), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.store'), [
            'payment_method' => 'cash', 'payment_detail_id' => 41, 'items' => [['product_variant_id' => 1, 'quantity' => 1]],
        ])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/orders/pos') && ! isset($r['payment_detail_id']));

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.store'), [
            'payment_method' => 'bitcoin', 'items' => [['product_variant_id' => 1, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_qr_tu_dong_hoi_trang_thai_va_huy_ma(): void
    {
        Http::fake([
            '*/admin/orders/pos/payments/DHN001' => Http::response(['data' => ['paid' => true, 'status' => 'success']]),
            '*/admin/orders/pos/12/huy-qr' => Http::response(['message' => 'khách đã trả tiền — đơn đã hoàn tất, không huỷ được'], 422),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.ban-hang.qrTrangThai', 'DHN001'))
            ->assertOk()->assertJsonPath('data.paid', true);
        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.huyQr', 12))
            ->assertStatus(422)->assertJsonPath('message', 'khách đã trả tiền — đơn đã hoàn tất, không huỷ được');
    }

    public function test_doi_qr_sang_tien_mat_chuyen_dung_sang_api(): void
    {
        Http::fake(['*/admin/orders/pos/12/doi-hinh-thuc' => Http::response(['data' => ['order_id' => 12, 'order_code' => 'DHN12', 'change_amount' => 50000]]), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.doiHinhThuc', 12), [
            'payment_method' => 'cash', 'amount_tendered' => 250000, 'payment_detail_id' => 41,
        ])->assertOk()->assertJsonPath('data.order_code', 'DHN12');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/orders/pos/12/doi-hinh-thuc')
            && $r['payment_method'] === 'cash' && (float) $r['amount_tendered'] === 250000.0 && ! isset($r['payment_detail_id']));

        // Không đổi sang QR tự động qua đường này (QR thì đã là QR).
        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.doiHinhThuc', 12), ['payment_method' => 'sepay'])->assertStatus(422);
    }

    public function test_qr_mpos_gui_dung_ma_sang_api(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response(['data' => ['order_id' => 9, 'status' => 'pending', 'payment' => ['transaction_code' => 'DH9']]], 201), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.store'), [
            'payment_method' => 'mpos_qr', 'payment_detail_id' => 61, 'items' => [['product_variant_id' => 1, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.payment.transaction_code', 'DH9');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/orders/pos')
            && $r['payment_method'] === 'mpos_qr' && $r['payment_detail_id'] === 61);
    }

    public function test_momo_chua_noi_cong_khong_chao_o_quay(): void
    {
        $chiMoMo = $this->bonPhuongThuc();
        $chiMoMo[3]['details'] = [['id' => 71, 'detail_type' => 'momo', 'is_default' => true]];
        Http::fake(['*/admin/orders/pos/payment-methods' => Http::response(['data' => $chiMoMo]), '*' => Http::response(['data' => []])]);
        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();
        // Chỉ có MoMo thì cả ô QR tự động không bày ra.
        $this->assertStringNotContainsString('data-method="auto_qr"', $html);
        $this->assertStringContainsString('pay-methods--3', $html);
    }

    public function test_o_qr_bay_mpos_qr_bo_momo(): void
    {
        $coMpos = $this->bonPhuongThuc();
        $coMpos[3]['details'][] = ['id' => 71, 'detail_type' => 'momo', 'is_default' => false];
        $coMpos[3]['details'][] = ['id' => 61, 'detail_type' => 'mpos_qr', 'is_default' => false];
        Http::fake(['*/admin/orders/pos/payment-methods' => Http::response(['data' => $coMpos]), '*' => Http::response(['data' => []])]);
        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();
        $pt = json_decode(html_entity_decode($this->giuaThe($html, 'data-phuong-thuc="', '"')), true);
        $this->assertSame(['sepay', 'mpos_qr'], array_column(collect($pt)->firstWhere('ma', 'auto_qr')['ct'], 'loai'));
    }

    private function giuaThe(string $s, string $dau, string $cuoi): string
    {
        $i = strpos($s, $dau) + strlen($dau);

        return substr($s, $i, strpos($s, $cuoi, $i) - $i);
    }
}
