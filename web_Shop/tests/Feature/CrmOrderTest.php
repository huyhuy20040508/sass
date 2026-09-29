<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM → Danh sách đơn hàng — khuôn crm/orders của v2: cùng sổ đơn với Quản lý
 * đơn hàng, bảng mở đầu bằng Mã khách hàng + Tên khách hàng.
 */
class CrmOrderTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    public function test_bang_mo_dau_bang_hai_cot_khach(): void
    {
        Http::fake([
            '*/admin/orders/so-don*' => Http::response([
                'data' => [
                    ['loai' => 'don', 'id' => 31, 'ma' => 'DH031', 'kenh' => 'pos', 'ma_khach' => 'cus-00007',
                        'khach_hang' => 'Nguyễn An', 'tong_tien' => 100000, 'trang_thai' => 'paid', 'xuat_duoc_hoa_don' => true],
                    // Khách vãng lai: không hồ sơ, không tên.
                    ['loai' => 'don', 'id' => 32, 'ma' => 'DH032', 'kenh' => 'pos', 'ma_khach' => '',
                        'khach_hang' => '', 'tong_tien' => 50000, 'trang_thai' => 'paid'],
                ],
                'meta' => ['page' => 1, 'page_size' => 20, 'total' => 2, 'total_pages' => 1],
            ]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanTri())
            ->get(route('admin.crm.orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/sub-nav-btn active">\s*Danh sách đơn hàng/', $html);
        $this->assertStringContainsString('cus-00007', $html);
        $this->assertStringContainsString('Bán cho người tiêu dùng', $html);
        $this->assertStringContainsString('show_customer_code', $html);
        // Nút xuất HĐĐT và hộp của nó đi theo như màn Quản lý đơn hàng.
        $this->assertSame(1, substr_count($html, 'class="xuat-hddt"'));
        $this->assertStringContainsString('id="modalXuatHddt"', $html);
    }

    public function test_man_quan_ly_don_hang_khong_doi(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1]])]);

        $html = $this->withSession($this->phienQuanTri())->get(route('admin.orders.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('show_customer_code', $html);
    }
}
