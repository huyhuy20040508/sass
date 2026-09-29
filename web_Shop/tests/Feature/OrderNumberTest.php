<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bảng của màn Quản lý đơn hàng — dựng theo sổ chứng từ của v2.
 *
 * Soi đúng ba thứ bảng tự in ra từ API mà không tự tính lại: ba cột tiền theo
 * phương thức, cột Công nợ (`co_cong_no`, không phải "còn phải thu"), và hai
 * hàng tổng dưới chân bảng. Cộng thêm cửa xem chi tiết của dòng phiếu trả.
 */
class OrderNumberTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    public function test_bang_in_dung_so_cua_so_chung_tu(): void
    {
        Http::fake([
            '*/admin/orders/so-don*' => Http::response([
                'data' => [
                    // Đơn thu một phần bằng HAI phương thức: 30.000 tiền mặt + 20.000 CK.
                    ['loai' => 'don', 'id' => 11, 'ma' => 'DH011', 'kenh' => 'pos',
                        'tien_mat' => 30000, 'chuyen_khoan' => 20000, 'the_vi' => 0,
                        'con_no' => 50000, 'co_cong_no' => true, 'tong_tien' => 100000, 'trang_thai' => 'partial'],
                    // Đơn COD đang giao, chưa thu đồng nào: CÒN PHẢI THU nhưng KHÔNG phải nợ.
                    ['loai' => 'don', 'id' => 12, 'ma' => 'DH012', 'kenh' => 'web',
                        'tien_mat' => 0, 'chuyen_khoan' => 0, 'the_vi' => 0,
                        'con_no' => 100000, 'co_cong_no' => false, 'tong_tien' => 100000, 'trang_thai' => 'unpaid'],
                    ['loai' => 'tra-hang', 'id' => 3, 'ma' => 'TH003', 'kenh' => 'web',
                        'tien_mat' => 40000, 'chuyen_khoan' => 0, 'the_vi' => 0,
                        'con_no' => 0, 'co_cong_no' => false, 'tong_tien' => 40000, 'trang_thai' => 'returned'],
                ],
                'meta' => ['page' => 1, 'page_size' => 20, 'total' => 3, 'total_pages' => 1,
                    'tong' => ['so_dong' => 30, 'tong_tien' => 9990000, 'tien_mat' => 1230000,
                        'chuyen_khoan' => 0, 'the_vi' => 0, 'giam_gia' => 0, 'phi_giao' => 0]],
            ]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanTri())
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->getContent();

        // Cột Công nợ: "Có" đúng MỘT lần — chỉ đơn thu một phần.
        $this->assertSame(1, substr_count($html, '<span class="text-danger">Có</span>'));

        // Hàng "trang này" cộng tại chỗ: 30.000 + 40.000 tiền mặt, 240.000 tổng.
        $this->assertStringContainsString('Tổng trang này', $html);
        $this->assertStringContainsString('70.000', $html);
        $this->assertStringContainsString('240.000', $html);
        // Hàng "tất cả" lấy từ meta.tong của API, không cộng lại từ trang.
        $this->assertStringContainsString('Tổng tất cả', $html);
        $this->assertStringContainsString('9.990.000', $html);
        $this->assertStringContainsString('1.230.000', $html);

        // Dòng phiếu trả có con mắt RIÊNG, không đi chung đường với hộp đơn hàng.
        $this->assertSame(1, substr_count($html, 'class="detail-phieu-tra"'));
        $this->assertSame(2, substr_count($html, 'class="detail-item"'));
    }

    /** Icon "Xuất HĐĐT" chỉ bày ở dòng API bật `xuat_duoc_hoa_don`. */
    public function test_nut_xuat_hddt_theo_co_cua_api(): void
    {
        Http::fake([
            '*/admin/orders/so-don*' => Http::response([
                'data' => [
                    ['loai' => 'don', 'id' => 21, 'ma' => 'DH021', 'kenh' => 'pos', 'tong_tien' => 100000,
                        'trang_thai' => 'paid', 'xuat_duoc_hoa_don' => true],
                    ['loai' => 'don', 'id' => 22, 'ma' => 'DH022', 'kenh' => 'web', 'tong_tien' => 100000,
                        'trang_thai' => 'paid', 'xuat_duoc_hoa_don' => false],
                ],
                'meta' => ['page' => 1, 'page_size' => 20, 'total' => 2, 'total_pages' => 1],
            ]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanTri())
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'class="xuat-hddt"'));
        $this->assertStringContainsString('id="modalXuatHddt"', $html);
    }

    /** Hộp Xuất HĐĐT gửi người mua lên API; nút không kèm gì thì thân vẫn rỗng. */
    public function test_phat_hanh_gui_kem_nguoi_mua(): void
    {
        Http::fake([
            '*/admin/orders/21/etax' => Http::response(['message' => 'Đã phát hành hoá đơn.', 'data' => ['status' => 'sent']]),
        ]);

        $this->withSession($this->phienQuanTri())
            ->postJson(route('admin.orders.phatHanhHoaDon', 21), [
                'buyer_type' => 'company', 'email' => 'ketoan@example.com',
                'buyer_tax_code' => '0101234567', 'buyer_company' => 'Công ty X', 'buyer_address' => '1 Tràng Tiền',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Đã phát hành hoá đơn.');

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/admin/orders/21/etax')
            && $req['buyer_type'] === 'company' && $req['buyer_tax_code'] === '0101234567');

        $this->withSession($this->phienQuanTri())
            ->postJson(route('admin.orders.phatHanhHoaDon', 21), ['buyer_type' => 'khac'])
            ->assertStatus(422);
    }
}
