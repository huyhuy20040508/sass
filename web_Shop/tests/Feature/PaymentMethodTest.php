<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cài đặt → Phương thức thanh toán (màn v2) và hai chỗ ăn theo: quầy đọc tài
 * khoản của chi nhánh, trang Cài đặt → Thanh toán cũ chỉ còn công tắc COD.
 */
class PaymentMethodTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function duLieu(bool $sua = true): array
    {
        return [
            'shop_id' => 1, 'web_shop_id' => 1, 'can_edit' => $sua,
            'banks' => [['bin' => '970436', 'name' => 'Vietcombank']],
            'devices' => [],
            'methods' => [
                ['id' => 11, 'type' => 1, 'status' => true, 'is_default' => false, 'details' => []],
                ['id' => 12, 'type' => 4, 'status' => true, 'is_default' => true, 'details' => [[
                    'id' => 31, 'detail_type' => 'bank_transfer', 'status' => true, 'is_default' => true, 'device_id' => null,
                    'data' => ['bank_id' => '970436', 'bank_name' => 'Vietcombank', 'bank_account' => '0071001234567', 'bank_account_name' => 'NGUYEN VAN A'],
                    'saved_secrets' => [],
                ]]],
                ['id' => 13, 'type' => 5, 'status' => false, 'is_default' => false, 'details' => []],
                ['id' => 14, 'type' => 6, 'status' => false, 'is_default' => false, 'details' => []],
            ],
        ];
    }

    protected function fake(array $them = []): void
    {
        Http::fake($them + [
            '*/admin/payment-methods' => Http::response(['data' => $this->duLieu()]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'name' => 'Chi nhánh trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    public function test_trang_dung_theo_v2_kem_du_lieu_va_tab_cai_dat(): void
    {
        $this->fake();

        $res = $this->withSession($this->phien())->get('/admin/payment-methods')->assertOk();

        $res->assertSee('Phương thức thanh toán');
        $res->assertSee('id="pmData"', false);
        $res->assertSee('0071001234567', false);
        $res->assertSee('Chi nhánh trung tâm');
        // Tab trong module Cài đặt, đang chọn.
        $this->assertMatchesRegularExpression('#admin/payment-methods"\s+class="sub-nav-btn active"#', $res->getContent());
        // Năm hộp thoại của màn.
        foreach (['modalBankTransfer', 'modalCrUdSwipeCard', 'modalQR', 'modalCrudDevice', 'deleteItem'] as $id) {
            $res->assertSee('id="'.$id.'"', false);
        }
    }

    /**
     * Câu mở đầu hộp máy quẹt thẻ phải đi THEO LOẠI MÁY.
     *
     * Trước đây một câu ghi cứng cho cả hai loại: "màn thu ngân chưa có nút Quẹt
     * thẻ và chưa nối cổng NextPay". Vế đầu nay sai — quầy v3 đã có ô Quẹt thẻ,
     * chọn máy xong hiện phí thẻ và đã bán được đơn thật — nên chủ tiệm đọc xong
     * lại không bật máy quẹt thẻ vì tưởng khai cũng chưa dùng được.
     *
     * Nhánh mPOS nay đã nối NextPay: câu nói máy tự xác nhận, kèm URL webhook
     * riêng (đường /payments/mpos/… chung cho máy và mPos QR) và nút Kiểm tra.
     */
    public function test_cau_mo_dau_hop_may_the_theo_loai_may(): void
    {
        $this->fake();

        $html = $this->withSession($this->phien())->get('/admin/payment-methods')->assertOk()->getContent();

        $dau = strpos($html, 'id="modalCrUdSwipeCard"');
        $this->assertNotFalse($dau, 'không tìm thấy hộp máy quẹt thẻ');
        $hop = substr($html, $dau, strpos($html, 'id="modalQR"', $dau) - $dau);

        // Hai câu riêng, mỗi loại một câu.
        $this->assertStringContainsString('ghi-mpos', $hop);
        $this->assertStringContainsString('ghi-doc-lap', $hop);
        $this->assertStringContainsString('Thu ngân chọn máy ở ô', $hop);
        // Và không còn câu "màn thu ngân chưa có nút Quẹt thẻ".
        $this->assertStringNotContainsString('Màn thu ngân chưa có nút', $hop);

        // Đổi loại máy thì đổi câu — cùng chỗ đã đổi các khối ô nhập.
        $k = strpos($html, 'function doiLoaiThe()');
        $this->assertNotFalse($k, 'không tìm thấy doiLoaiThe()');
        $than = substr($html, $k, 500);
        $this->assertStringContainsString(".ghi-mpos').prop('hidden', !mpos)", $than);
        $this->assertStringContainsString(".ghi-doc-lap').prop('hidden', mpos)", $than);

        $this->assertStringNotContainsString('Chưa nối cổng NextPay', $html);
        $this->assertStringContainsString('data-cong="mpos_device"', $hop);
        $this->assertStringContainsString('pm-kiem-tra', $hop);
        $this->assertStringContainsString('data-cong="mpos_qr"', $html);
        $this->assertStringContainsString("x.detail_type.indexOf('mpos') === 0 ? 'mpos'", $html);
    }

    public function test_api_hong_thi_bao_loi_va_nut_tai_lai(): void
    {
        Http::fake([
            '*/admin/payment-methods' => Http::response(['message' => 'Bạn không được giao việc này'], 403),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/payment-methods')
            ->assertOk()
            ->assertSee('Bạn không được giao việc này')
            ->assertSee('Tải lại')
            ->assertDontSee('id="pmThe"', false);
    }

    public function test_bat_tat_chuyen_tiep_dung_body_va_tra_cau_thanh_cong(): void
    {
        $this->fake(['*/admin/payment-methods/12/status' => Http::response(['data' => null])]);

        $this->withSession($this->phien())
            ->putJson('/admin/payment-methods/12/status', ['status' => false])
            ->assertOk()->assertJson(['message' => 'Đã tắt.']);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/payment-methods/12/status')
            && $r->method() === 'PUT' && $r['status'] === false);
    }

    public function test_loi_nghiep_vu_giu_nguyen_ma_va_cau(): void
    {
        $this->fake(['*/admin/payment-methods/12/details/31/status' => Http::response(
            ['message' => 'Đây là tài khoản duy nhất đang bật của Chuyển khoản.'], 409)]);

        $this->withSession($this->phien())
            ->putJson('/admin/payment-methods/12/details/31/status', ['status' => false])
            ->assertStatus(409)
            ->assertJson(['message' => 'Đây là tài khoản duy nhất đang bật của Chuyển khoản.']);
    }

    public function test_loi_theo_tung_o_di_kem_errors_bo_ma_may(): void
    {
        $this->fake(['*/admin/payment-methods/12/details' => Http::response([
            'message' => 'Dữ liệu không hợp lệ',
            'errors' => ['bank_account' => 'Tài khoản này đã có trong danh sách.', 'ma' => 'X'],
        ], 422)]);

        $this->withSession($this->phien())
            ->postJson('/admin/payment-methods/12/details', [
                'detail_type' => 'bank_transfer', 'status' => true,
                'data' => ['bank_id' => '970436', 'bank_account' => '0071001234567', 'bank_account_name' => 'A', 'la' => ['mang']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.bank_account', 'Tài khoản này đã có trong danh sách.')
            ->assertJsonMissingPath('errors.ma');

        // Ô mảng lồng bị bỏ, ô thường đi nguyên dạng chuỗi.
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/payment-methods/12/details')
            && $r['detail_type'] === 'bank_transfer' && $r['data']['bank_account'] === '0071001234567'
            && ! array_key_exists('la', $r['data']));
    }

    public function test_loai_la_khong_gui_sang_api(): void
    {
        $this->fake(['*/admin/payment-methods/14/details' => Http::response(['data' => ['id' => 9]])]);

        $this->withSession($this->phien())
            ->postJson('/admin/payment-methods/14/details', ['detail_type' => 'bitcoin', 'data' => []])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/payment-methods/14/details')
            && ! isset($r['detail_type']));
    }

    public function test_them_may_thieu_ma_bi_chan_truoc_khi_goi_api(): void
    {
        $this->fake();

        $this->withSession($this->phien())
            ->postJson('/admin/payment-devices', ['name' => 'Máy quầy 1'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/admin/payment-devices'));
    }

    public function test_sua_may_chuyen_sang_api_khong_kem_loai_may(): void
    {
        $this->fake(['*/admin/payment-devices/7' => Http::response(['data' => ['id' => 7, 'code' => 'POS-9', 'name' => 'Máy quầy 1']])]);

        $this->withSession($this->phien())
            ->putJson('/admin/payment-devices/7', ['code' => ' POS-9 ', 'name' => 'Máy quầy 1', 'is_physical' => true])
            ->assertOk()->assertJsonPath('message', 'Đã lưu máy.')->assertJsonPath('data.code', 'POS-9');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/admin/payment-devices/7')
            && $r['code'] === 'POS-9' && ! isset($r['is_physical']));
    }

    public function test_sua_may_thieu_ten_bi_chan_truoc_khi_goi_api(): void
    {
        $this->fake();

        $this->withSession($this->phien())
            ->putJson('/admin/payment-devices/7', ['code' => 'POS-9'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/admin/payment-devices'));
    }

    public function test_xoa_may_dang_gan_tra_nguyen_cau_cua_api(): void
    {
        $cau = 'Máy đang gắn với một cấu hình quẹt thẻ — xoá cấu hình đó (hoặc đổi sang máy khác) rồi mới xoá máy.';
        $this->fake(['*/admin/payment-devices/7' => Http::response(['success' => false, 'message' => $cau], 409)]);

        $this->withSession($this->phien())->deleteJson('/admin/payment-devices/7')
            ->assertStatus(409)->assertJsonPath('message', $cau);
    }

    public function test_xoa_may_chua_gan(): void
    {
        $this->fake(['*/admin/payment-devices/8' => Http::response(['data' => null])]);

        $this->withSession($this->phien())->deleteJson('/admin/payment-devices/8')
            ->assertOk()->assertJsonPath('message', 'Đã xoá máy.');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/admin/payment-devices/8'));
    }

    public function test_hop_may_co_nut_sua_va_xoa(): void
    {
        $this->fake();

        $html = $this->withSession($this->phien())->get('/admin/payment-methods')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'class="btn btn-outline-secondary edit_device"'));
        $this->assertStringContainsString('class="bt btn_red me-auto dv-xoa" hidden', $html);
        $this->assertStringContainsString('dv-dang-gan', $html);
    }

    public function test_quay_lay_tai_khoan_mac_dinh_cua_chi_nhanh(): void
    {
        $this->fake(['*/admin/orders/pos/payment-methods' => Http::response(['data' => [
            ['type' => 1, 'is_default' => true, 'details' => []],
            ['type' => 4, 'is_default' => false, 'details' => [
                ['id' => 1, 'is_default' => false, 'bank_name' => 'MBBank', 'bank_account' => '111', 'bank_account_name' => 'B'],
                ['id' => 2, 'is_default' => true, 'bank_name' => 'Vietcombank', 'bank_account' => '0071', 'bank_account_name' => 'NGUYEN VAN A'],
            ]],
        ]])]);

        $html = $this->withSession($this->phien())->get('/cashier/sales')->getContent();

        $this->assertStringContainsString(e(json_encode(['ten' => 'Vietcombank', 'so' => '0071', 'chu' => 'NGUYEN VAN A', 'anh' => ''])), $html);
    }

    public function test_quay_chi_nhanh_tat_chuyen_khoan_thi_khong_co_tai_khoan(): void
    {
        $this->fake(['*/admin/orders/pos/payment-methods' => Http::response(['data' => [
            ['type' => 1, 'is_default' => true, 'details' => []],
        ]])]);

        $html = $this->withSession($this->phien())->get('/cashier/sales')->getContent();

        $this->assertStringContainsString(e(json_encode(['ten' => '', 'so' => '', 'chu' => '', 'anh' => ''])), $html);
    }

    public function test_trang_cai_dat_thanh_toan_cu_chi_con_cod(): void
    {
        Http::fake([
            '*/admin/settings*' => Http::response(['data' => [
                'values' => ['payment_cod_enabled' => '1', 'bank_name' => 'Vietcombank'],
                'fields' => [
                    ['key' => 'payment_cod_enabled', 'type' => 'bool', 'label' => 'Thanh toán khi nhận hàng (COD)', 'section' => 'methods'],
                    ['key' => 'payment_bank_enabled', 'type' => 'bool', 'label' => 'Chuyển khoản ngân hàng', 'section' => 'methods'],
                    ['key' => 'bank_name', 'type' => 'string', 'label' => 'Ngân hàng', 'section' => 'bank'],
                ],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get('/admin/settings/payment')
            ->assertOk()
            ->assertSee('Thanh toán khi nhận hàng (COD)')
            ->assertDontSee('Chuyển khoản ngân hàng')
            ->assertDontSee('items[bank_name]', false)
            ->assertSee('Phương thức thanh toán');
    }

    public function test_kiem_tra_ket_noi_chi_nhan_url_cua_api(): void
    {
        $this->fake(['*/admin/payment-methods/14/details/51/test' => Http::response(['data' => ['ok' => true, 'message' => 'PayOS đã nhận khoá']])]);
        $goc = rtrim((string) config('api.public_url'), '/');

        $this->withSession($this->phien())
            ->postJson('/admin/payment-methods/14/details/51/test', ['webhook_url' => 'https://ke-gian.test/hook'])
            ->assertStatus(422);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/test'));

        $this->withSession($this->phien())
            ->postJson('/admin/payment-methods/14/details/51/test', ['webhook_url' => $goc.'/payments/payos/webhook/abc'])
            ->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.message', 'PayOS đã nhận khoá');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/payment-methods/14/details/51/test')
            && $r['webhook_url'] === $goc.'/payments/payos/webhook/abc');
    }

    public function test_luot_ghi_gui_dung_chi_nhanh_cua_tab(): void
    {
        $this->fake(['*/admin/payment-methods/6/status' => Http::response(['data' => null])]);

        // Phiên đang đứng chi nhánh 2, tab gửi ?chi_nhanh=5 → API phải nhận chi nhánh 5.
        $this->withSession($this->phien() + [\App\Services\ApiClient::KHOA_CHI_NHANH => 2])
            ->putJson('/admin/payment-methods/6/status?chi_nhanh=5', ['status' => true])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/payment-methods/6/status') && $r->hasHeader('X-Chi-Nhanh', '5'));
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/payment-methods/6/status') && $r->hasHeader('X-Chi-Nhanh', '2'));
    }
}
