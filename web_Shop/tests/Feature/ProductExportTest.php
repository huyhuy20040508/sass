<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Xuất file hàng hoá (Hàng hoá → Xuất file).
 *
 * Giá bán THẬT của hàng nhiều phiên bản nằm ở `product_variants.price`, còn
 * `products.base_price` chỉ là giá gốc lúc tạo và không được đồng bộ khi sửa
 * giá phiên bản. Bản xuất cũ lấy base_price nên tệp ghi 18.000.000 trong khi
 * danh sách, chi tiết và quầy đều bán 22.000.000 — ai sửa tệp rồi nhập lại là
 * mang luôn cái giá sai vào hàng mới.
 *
 * Chữa bằng cách xuất MỖI PHIÊN BẢN MỘT DÒNG, đúng thứ người ta cầm đi soạn
 * hàng và dán giá.
 */
class ProductExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CurrentBranch::quenCache();
    }

    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
            ApiClient::KHOA_CHI_NHANH => 2,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function hang(): array
    {
        return [
            [
                'id' => 3, 'sku' => 'HH000002', 'name' => 'laptop asus gamming', 'status' => 'active',
                'base_price' => 18000000, 'cost_price' => 17000000, 'vat' => 0, 'is_multi_variant' => true,
                'category' => ['name' => 'Laptop'], 'unit' => ['name' => 'Cái'],
                'variants' => [
                    ['id' => 2, 'sku' => 'HH000002-RTX-3050', 'name' => 'RTX 3050 · 8GB', 'price' => 22000000, 'cost_price' => 21000000, 'stock_quantity' => 171],
                    ['id' => 9, 'sku' => 'HH000002-RTX-4060', 'name' => 'RTX 4060 · 16GB', 'price' => 31000000, 'cost_price' => 29000000, 'stock_quantity' => 4],
                ],
            ],
            [
                'id' => 5, 'sku' => 'HH000003', 'name' => 'iphone 15', 'status' => 'active',
                'base_price' => 25000000, 'cost_price' => null, 'vat' => 0, 'is_multi_variant' => false,
                'category' => ['name' => 'Điện thoại'], 'unit' => ['name' => 'Cái'],
                'variants' => [['id' => 7, 'sku' => 'HH000003', 'name' => '', 'price' => 26500000, 'cost_price' => null, 'stock_quantity' => 12]],
            ],
        ];
    }

    /** @return array<int, array<int, string>> Các dòng CSV đã tách ô. */
    protected function xuat(): array
    {
        Http::fake([
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 2, 'code' => 'CN02', 'name' => 'Kho trung tâm', 'is_active' => true]]]),
            '*/products*' => Http::response(['data' => $this->hang(), 'meta' => ['page' => 1, 'page_size' => 200, 'total' => 2, 'total_pages' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phien())->get(route('admin.products.export'))->assertOk();
        $csv = ltrim($res->streamedContent(), "\xEF\xBB\xBF");

        return array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", $csv)), 'strlen'));
    }

    public function test_hang_nhieu_phien_ban_xuat_moi_phien_ban_mot_dong(): void
    {
        $rows = $this->xuat();
        $head = $rows[0];
        $cot = array_flip($head);

        $this->assertContains('Phiên bản', $head);
        // 2 phiên bản + 1 hàng đơn = 3 dòng dữ liệu.
        $this->assertCount(4, $rows);

        $laptop = array_values(array_filter(array_slice($rows, 1), fn ($r) => $r[$cot['Tên hàng hóa']] === 'laptop asus gamming'));
        $this->assertCount(2, $laptop);

        $this->assertSame('RTX 3050 · 8GB', $laptop[0][$cot['Phiên bản']]);
        $this->assertSame('22000000', $laptop[0][$cot['Giá bán']], 'phải là giá phiên bản, không phải base_price 18.000.000');
        $this->assertSame('21000000', $laptop[0][$cot['Giá vốn']]);
        $this->assertSame('171', $laptop[0][$cot['Tồn kho (Kho trung tâm)']]);
        $this->assertSame('HH000002-RTX-3050', $laptop[0][$cot['Mã hàng']]);

        $this->assertSame('31000000', $laptop[1][$cot['Giá bán']]);
        $this->assertSame('4', $laptop[1][$cot['Tồn kho (Kho trung tâm)']]);

        // Mã SP và số biến thể giống nhau ở cả hai dòng — vẫn là một mặt hàng.
        $this->assertSame($laptop[0][$cot['Mã SP']], $laptop[1][$cot['Mã SP']]);
        $this->assertSame('2', $laptop[0][$cot['Số biến thể']]);

        // KHÔNG còn con số của base_price trong tệp.
        $this->assertStringNotContainsString('18000000', implode("\n", array_map(fn ($r) => implode(',', $r), $rows)));
    }

    /** Hàng đơn: một dòng, cột Phiên bản trống, mã hàng vẫn là mã mặt hàng. */
    public function test_hang_mot_phien_ban_van_mot_dong_va_lay_gia_phien_ban(): void
    {
        $rows = $this->xuat();
        $cot = array_flip($rows[0]);

        $don = array_values(array_filter(array_slice($rows, 1), fn ($r) => $r[$cot['Tên hàng hóa']] === 'iphone 15'));

        $this->assertCount(1, $don);
        $this->assertSame('', $don[0][$cot['Phiên bản']]);
        $this->assertSame('HH000003', $don[0][$cot['Mã hàng']]);
        $this->assertSame('26500000', $don[0][$cot['Giá bán']], 'giá của dòng mặc định, không phải base_price');
        // Chưa khai giá vốn thì để TRỐNG, không ghi 0.
        $this->assertSame('', $don[0][$cot['Giá vốn']]);
        $this->assertSame('0', $don[0][$cot['Số biến thể']]);
    }
}
