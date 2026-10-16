<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chi nhánh chưa mở ca thì quầy không thu được tiền: màn bán khoá ngay khi mở,
 * và lỗi CHUA_MO_CA của API đi nguyên mã về để màn khoá lại giữa chừng.
 */
class PosShiftLockTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff']],
        ];
    }

    protected function manQuay(): string
    {
        return $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.index'))->assertOk()->getContent();
    }

    protected function dangKhoa(string $html): bool
    {
        $this->assertMatchesRegularExpression('/id="posCaKhoa"/', $html);

        return ! preg_match('/id="posCaKhoa"\s+hidden/', $html);
    }

    public function test_chua_mo_ca_thi_man_quay_khoa_va_co_o_mo_ca(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => null]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->manQuay();

        $this->assertTrue($this->dangKhoa($html));
        $this->assertStringContainsString('data-ca-mo="0"', $html);
        $this->assertStringContainsString('id="posCaTien"', $html);
        $this->assertStringContainsString('data-mo-ca-url="'.route('thu-ngan.ca-lam-viec.mo').'"', $html);
    }

    public function test_dang_co_ca_thi_khong_khoa(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => ['id' => 4, 'opening_cash' => 500000]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->manQuay();

        $this->assertFalse($this->dangKhoa($html));
        $this->assertStringContainsString('data-ca-mo="1"', $html);
    }

    /** Không hỏi được ca thì không tự khoá: API vẫn chặn lúc chốt đơn. */
    public function test_khong_hoi_duoc_ca_thi_khong_tu_khoa(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['message' => 'lỗi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->assertFalse($this->dangKhoa($this->manQuay()));
    }

    public function test_chot_don_va_doi_hinh_thuc_tra_nguyen_ma_chua_mo_ca(): void
    {
        $chan = Http::response(['message' => 'Chi nhánh chưa mở ca — mở ca (nhập tiền đầu ca) rồi mới bán / thu tiền được.', 'errors' => ['ma' => 'CHUA_MO_CA']], 409);
        Http::fake([
            '*/admin/orders/pos/9/doi-hinh-thuc' => $chan,
            '*/admin/orders/pos' => $chan,
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.store'), [
            'payment_method' => 'cash', 'items' => [['product_variant_id' => 1, 'quantity' => 1]],
        ])->assertStatus(409)->assertJsonPath('errors.ma', 'CHUA_MO_CA');

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.doiHinhThuc', ['id' => 9]), [
            'payment_method' => 'cash',
        ])->assertStatus(409)->assertJsonPath('errors.ma', 'CHUA_MO_CA');
    }
}
