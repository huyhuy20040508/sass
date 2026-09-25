<?php

namespace Tests\Feature;

use App\Http\Middleware\V2OnlyShell;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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
class V2OnlyShellTest extends TestCase
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

    /**
     * Màn CHƯA port thì dồn về màn v2 gần nhất, không mở bản cũ.
     *
     * Tự khai một route thử thay vì chỉ vào một màn có thật: bản trước lấy màn
     * thật làm ví dụ nên cứ port thêm một màn là bài này đỏ, mà cái đỏ ấy không
     * nói lên điều gì sai.
     */
    public function test_man_chua_port_thi_don_ve_khu_v2(): void
    {
        $this->fakeApi();
        Route::middleware('chi.v2')->get('/thu-man-chua-port', fn () => 'trang cũ');

        $this->withSession($this->phienQuanTri())->get('/thu-man-chua-port')
            ->assertRedirect(route('admin.customers.index'));
    }

    /**
     * Màn CÒN BẢN CŨ mà không có bản v2 thay thế thì vẫn phải mở được.
     *
     * Chặn chúng không phải là "dồn về màn gần nhất" mà là xoá trắng một chức
     * năng: hạn mức giảm giá của thu ngân, khuyến mãi, mã giảm giá, quy tắc đánh
     * số, ba trang báo cáo. Đúng sáu đường người dùng báo là bị ném về Khách hàng.
     */
    public function test_man_con_ban_cu_van_mo_duoc(): void
    {
        $this->fakeApi();

        foreach ([
            '/admin/settings/pos',
            '/admin/parameters/numbering-rules',
            '/admin/promotions',
            '/admin/vouchers',
            '/admin/reports/revenue',
            '/admin/reports/products',
        ] as $duong) {
            $this->withSession($this->phienQuanTri())->get($duong)
                ->assertOk($duong.' vẫn bị cổng v2 chặn.');
        }
    }

    /**
     * Chín màn quản trị còn bản cũ vẫn phải mở được.
     *
     * Phân quyền và Tài khoản đăng nhập là đường DUY NHẤT cấp quyền lẻ cho một
     * tài khoản — chặn chúng là chủ tiệm không sửa được quyền nhân viên.
     */
    public function test_chin_man_quan_tri_con_ban_cu_van_mo_duoc(): void
    {
        $this->fakeApi();

        foreach ([
            '/admin/permissions',
            '/admin/users',
            '/admin/returns',
            '/admin/locations',
            '/admin/banners',
            '/admin/contacts',
            '/admin/newsletter',
            '/admin/profile',
        ] as $duong) {
            $this->withSession($this->phienQuanTri())->get($duong)
                ->assertOk($duong.' vẫn bị cổng v2 chặn.');
        }
    }

    /**
     * Tổng quan phải MỞ RA TRANG, không chuyển hướng đi đâu.
     *
     * Đây là mục đầu tiên của menu. Có thời gian route của nó là một lệnh
     * redirect sang Khách hàng cho khỏi lạc vào giao diện cũ — bấm vào mà nhảy
     * sang màn khác thì người dùng tưởng mình bấm nhầm.
     */
    public function test_tong_quan_mo_ra_trang(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    /** Nút Xuất Excel của chính mấy màn ấy cũng phải lọt, không thì bấm là bị đá đi. */
    public function test_duong_xuat_tep_cua_man_con_ban_cu_van_lot(): void
    {
        $this->fakeApi();

        foreach (['admin.banners.export', 'admin.contacts.export', 'admin.returns.export'] as $ten) {
            $this->assertTrue(
                in_array($ten, V2OnlyShell::CON_BAN_CU, true)
                    || collect(V2OnlyShell::KHONG_PHAI_TRANG)->contains(fn ($t) => str_starts_with($ten, $t)),
                $ten.' bị cổng v2 chặn — nút Xuất Excel sẽ ném người dùng về Khách hàng.'
            );
        }
    }

    /** Thống kê → Khách hàng dựng bằng v2 nên phải mở thẳng, không dồn đi đâu. */
    public function test_thong_ke_khach_hang_la_v2(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->get('/admin/reports/customers')->assertOk();
    }

    /** Một màn chỉ được khai ở MỘT danh sách — hai chỗ cùng khai là mất dấu. */
    public function test_khong_man_nao_khai_o_ca_hai_danh_sach(): void
    {
        $trung = array_intersect(V2OnlyShell::DA_CO_V2, V2OnlyShell::CON_BAN_CU);

        $this->assertSame([], array_values($trung),
            'Màn đã port xong thì CHUYỂN khỏi CON_BAN_CU chứ đừng khai cả hai nơi: '.implode(', ', $trung));
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
        foreach (V2OnlyShell::DA_CO_V2 as $ten) {
            $this->assertTrue(
                app('router')->has($ten),
                'DA_CO_V2 khai route "'.$ten.'" nhưng không có route nào tên vậy — cổng sẽ chặn nhầm màn đã port.'
            );
        }

        foreach (V2OnlyShell::CON_BAN_CU as $ten) {
            $this->assertTrue(
                app('router')->has($ten),
                'CON_BAN_CU khai route "'.$ten.'" nhưng không có route nào tên vậy — màn đó vẫn bị chặn.'
            );
        }
    }
}
