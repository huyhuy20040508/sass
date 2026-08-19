<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quy tắc đánh số CÓ HIỆU LỰC ở các màn nhập liệu.
 *
 * Bật quy tắc mà màn hình vẫn tự bịa mã của nó thì cấu hình chỉ là một trang
 * trang trí. Hai màn có tự đặt mã là Hàng hoá (SKU ghép từ đội bóng · loại áo ·
 * mùa giải) và Nhóm hàng hoá (dải NH0001) — bài này canh đúng hai chỗ đó.
 *
 * Mã sinh ra thế nào là việc của API (api/internal/apitest/sinh_ma_test.go).
 */
class MaTuSinhTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    /** $batQuyTac = cửa hàng đã bật quy tắc mã hàng hoá. */
    protected function fakeApi(bool $batQuyTac): void
    {
        Http::fake([
            '*/admin/quy-tac-ma*' => Http::response(['data' => [
                'loai' => [['ma' => 'hang-hoa', 'ten' => 'Hàng hóa', 'dung_chung' => true, 'tien_to_goi_y' => 'HH']],
                'quy_tac' => $batQuyTac ? [[
                    'shop_id' => 0, 'doc_type' => 'hang-hoa', 'prefix' => 'HH',
                    'value_part' => 'so-thu-tu', 'length' => 6, 'suffix' => '', 'is_active' => true,
                ]] : [],
            ]]),
            '*' => Http::response(['data' => ['id' => 9]], 201),
        ]);
    }

    protected function donHangHoa(): array
    {
        return [
            'name' => 'Áo sân nhà Arsenal',
            'team' => 'Arsenal',
            'season' => '2026',
            'kit_type' => 'fan',
            'category_id' => 3,
            'base_price' => 500000,
            // Cờ của modal: "màn hình đã nắm danh sách biến thể", không có nó thì
            // controller cố ý bỏ khoá variants ra khỏi payload.
            'variants_loaded' => 1,
            'variants' => [
                ['id' => 0, 'size' => 'M', 'color' => 'Đỏ', 'barcode' => '', 'price' => '', 'cost_price' => ''],
            ],
        ];
    }

    /** Bỏ trống SKU + đã bật quy tắc: gửi mã rỗng để máy chủ đặt. */
    public function test_bat_quy_tac_thi_khong_tu_ghep_sku(): void
    {
        $this->fakeApi(batQuyTac: true);

        $this->withSession($this->phienQuanTri())->post('/admin/products', $this->donHangHoa());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }

            // Mã sản phẩm VÀ mã biến thể đều để trống — cả hai do máy chủ đặt.
            return ($request->data()['sku'] ?? null) === ''
                && ($request->data()['variants'][0]['sku'] ?? null) === '';
        });
    }

    /** Chưa bật quy tắc: giữ nguyên cách ghép mã cũ của màn hình. */
    public function test_chua_bat_quy_tac_thi_van_ghep_sku_nhu_cu(): void
    {
        $this->fakeApi(batQuyTac: false);

        $this->withSession($this->phienQuanTri())->post('/admin/products', $this->donHangHoa());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/products') || $request->method() !== 'POST') {
                return false;
            }
            $sku = $request->data()['sku'] ?? '';

            return $sku !== '' && str_contains($sku, 'ARSE');
        });
    }

    /** Ô SKU trên modal khoá lại khi quy tắc đang bật. */
    public function test_modal_khoa_o_sku_khi_bat_quy_tac(): void
    {
        $this->fakeApi(batQuyTac: true);

        $html = $this->withSession($this->phienQuanTri())->get('/admin/products')->getContent();

        $this->assertStringContainsString('const MA_TU_SINH = true;', $html);
    }

    /** Thêm nhóm hàng hoá: trang quản trị KHÔNG tự đặt mã nữa, để máy chủ đặt. */
    public function test_them_nhom_hang_hoa_khong_gui_ma(): void
    {
        $this->fakeApi(batQuyTac: false);

        $this->withSession($this->phienQuanTri())->post('/admin/categories', [
            'name' => 'Nhóm mới',
            'parent_id' => 1,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/categories') || $request->method() !== 'POST') {
                return false;
            }

            return ! array_key_exists('slug', $request->data());
        });
    }
}
