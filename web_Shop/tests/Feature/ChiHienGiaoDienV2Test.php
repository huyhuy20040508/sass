<?php

namespace Tests\Feature;

use App\Http\Middleware\ChiHienGiaoDienV2;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cổng "chỉ hiện giao diện v2".
 *
 * Đang trong đợt chuyển sang khu v2: màn nào chưa dựng lại thì KHÔNG mở bản cũ
 * mà dồn về màn v2 gần nhất — không thì trong một phiên người dùng thấy hai
 * giao diện lẫn lộn, bấm nhầm một đường dẫn là lạc sang bản cũ.
 *
 * Bài kiểm của từng màn cố ý ĐI VÒNG qua cổng này (xem Tests\TestCase): bắt
 * chúng đi qua thì chúng đo cái cổng chứ không đo màn, và mọi khẳng định về nội
 * dung trang đều đỏ với cùng một lý do "302". Nên hành vi của chính cổng phải
 * có chỗ gác riêng, và đây là chỗ đó.
 */
class ChiHienGiaoDienV2Test extends TestCase
{
    /** Bài này ĐI QUA cổng — nó sinh ra để đo chính cổng. */
    protected bool $quaCongV2 = true;

    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function fakeApi(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => []])]);
    }

    /** Màn ĐÃ có bản v2 thì mở thẳng, không bị dồn đi đâu. */
    public function test_man_da_co_v2_thi_mo_duoc(): void
    {
        $this->fakeApi();

        foreach (['/admin/suppliers', '/admin/products', '/admin/categories',
            '/admin/units', '/admin/attributes', '/admin/taxes',
            '/admin/purchase-orders', '/admin/supplier-returns'] as $duong) {
            $this->withSession($this->phienQuanTri())->get($duong)->assertOk();
        }
    }

    /** Màn CHƯA port thì dồn về màn v2 gần nhất, không mở bản cũ. */
    public function test_man_chua_port_thi_don_ve_khu_v2(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/customers');

        $res->assertRedirect(route('admin.nha-cung-cap.index'));
    }

    /**
     * Chỉ soi lượt MỞ TRANG.
     *
     * Chặn cả POST/PUT/DELETE là gãy mọi thao tác của màn chưa port; chặn cả
     * request ngầm (fetch/ajax) thì phần xử lý lỗi của trang nhận về HTML của
     * một trang khác thay vì JSON nó đang đợi.
     */
    public function test_khong_chan_thao_tac_va_request_ngam(): void
    {
        $this->fakeApi();

        // Lượt ngầm của chính màn chưa port: phải đi lọt.
        $this->withSession($this->phienQuanTri())
            ->get('/admin/staff', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();
    }

    /**
     * Đường TẢI TỆP / GỌI NGẦM của màn ĐÃ port cũng phải lọt.
     *
     * Chúng mang tên route cùng tiền tố với màn ấy nhưng không vẽ trang nào;
     * chặn chúng là gãy nút Xuất Excel và mọi lượt gọi ngầm của khung v2.
     */
    public function test_duong_khong_ve_trang_van_lot(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->get('/admin/products/import-template')
            ->assertOk();
    }

    /** Danh sách màn đã port phải khớp với route thật, không gõ tay lệch tên. */
    public function test_ten_route_trong_danh_sach_deu_co_that(): void
    {
        foreach (ChiHienGiaoDienV2::DA_CO_V2 as $ten) {
            $this->assertTrue(
                app('router')->has($ten),
                'DA_CO_V2 khai route "'.$ten.'" nhưng không có route nào tên vậy — cổng sẽ chặn nhầm màn đã port.'
            );
        }
    }
}
