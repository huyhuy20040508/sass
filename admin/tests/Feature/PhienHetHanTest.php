<?php

namespace Tests\Feature;

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phiên hết hạn (CSRF token chết) KHÔNG được dẫn tới màn hình "419 PAGE EXPIRED".
 *
 * Trang 419 mặc định trắng trơn, không nút nào, mà bấm Back thì trình duyệt gửi
 * lại đúng token đã chết nên lại 419 — người dùng kẹt hẳn ở đó. Xem callback
 * trong bootstrap/app.php.
 *
 * Test tự dựng route ném TokenMismatchException thay vì gửi form thiếu token:
 * VerifyCsrfToken tự tắt khi chạy test (runningUnitTests), nên không có cách nào
 * bắt nó ném thật từ trong đây.
 */
class PhienHetHanTest extends TestCase
{
    /** Route giả lập một request bị CSRF từ chối. */
    protected function dungRouteHetHan(): void
    {
        Route::middleware('web')->post('/_test/phien-het-han', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        });
    }

    public function test_chua_dang_nhap_thi_ve_lai_trang_dang_nhap_va_giu_o_da_go(): void
    {
        $this->dungRouteHetHan();

        $res = $this->post('/_test/phien-het-han', [
            'shop_code' => 'quochuy',
            'username' => 'admin',
            'password' => 'MatKhauThat@123',
        ]);

        $res->assertRedirect(route('login'));
        $res->assertSessionHas('error');

        // Gõ lại mã cửa hàng + tên đăng nhập chỉ vì token hết hạn là hành người dùng.
        $this->assertSame('quochuy', session('_old_input.shop_code'));
        $this->assertSame('admin', session('_old_input.username'));

        // Mật khẩu thì tuyệt đối không nằm lại trong session.
        $this->assertNull(session('_old_input.password'));
    }

    public function test_dang_o_trong_khu_quan_tri_thi_quay_lai_dung_trang_vua_dung(): void
    {
        $this->dungRouteHetHan();

        $res = $this->withSession(['api.access_token' => 'token-con-song'])
            ->from('/admin/orders')
            ->post('/_test/phien-het-han', ['ghi_chu' => 'giao truoc 5h']);

        // Đá ra /login ở đây là sai: phiên API vẫn còn, người dùng chỉ tưởng mình
        // bị đăng xuất giữa chừng.
        $res->assertRedirect('/admin/orders');
        $res->assertSessionHas('error');
        $this->assertSame('giao truoc 5h', session('_old_input.ghi_chu'));
        $this->assertNull(session('_old_input._token'));
    }

    public function test_ajax_nhan_json_419_chu_khong_phai_trang_html(): void
    {
        $this->dungRouteHetHan();

        $res = $this->postJson('/_test/phien-het-han');

        $res->assertStatus(419);
        $res->assertJsonStructure(['message']);
    }
}
