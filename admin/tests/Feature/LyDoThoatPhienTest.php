<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Bị đá ra khỏi phần mềm thì phải BIẾT VÌ SAO.
 *
 * Hay gặp nhất: hợp đồng hết hạn nên cửa hàng bị khoá (xem QuetHanService bên Go
 * API). Go API trả 401 kèm đúng câu cần nói — "cửa hàng đang tạm khoá, vui lòng
 * liên hệ nhà cung cấp phần mềm" — nhưng câu đó từng bị nuốt mất trên đường:
 * ApiClient xoá session rồi middleware hiện câu chung "vui lòng đăng nhập bằng
 * tài khoản quản trị". Người bị khoá vì hết hạn ngồi gõ lại mật khẩu, gõ đúng
 * vẫn không vào được, và không có gì trên màn hình nói cho họ biết phải gọi ai.
 *
 * ApiClient ghi lý do vào `phien.ly_do_thoat` trước khi xoá session;
 * EnsureAdminAuthenticated đọc nó ra. Đây là bài kiểm cho vế thứ hai.
 */
class LyDoThoatPhienTest extends TestCase
{
    public function test_ly_do_api_noi_duoc_mang_toi_man_hinh_dang_nhap(): void
    {
        $lyDo = 'Cửa hàng đang tạm khoá, vui lòng liên hệ nhà cung cấp phần mềm';

        $res = $this->withSession(['phien.ly_do_thoat' => $lyDo])->get('/admin/dashboard');

        $res->assertRedirect(route('login'));
        $res->assertSessionHas('error', $lyDo);
    }

    public function test_ajax_nhan_dung_ly_do_do_trong_json(): void
    {
        $lyDo = 'Tài khoản đang không hoạt động, vui lòng liên hệ cửa hàng';

        $res = $this->withSession(['phien.ly_do_thoat' => $lyDo])
            ->getJson('/admin/notifications');

        $res->assertStatus(401);
        $res->assertJson(['message' => $lyDo]);
    }

    /** Đọc một lần rồi bỏ: lần đăng nhập hỏng sau đó không được đeo câu cũ. */
    public function test_ly_do_chi_hien_dung_mot_lan(): void
    {
        $this->withSession(['phien.ly_do_thoat' => 'Cửa hàng đang tạm khoá'])
            ->get('/admin/dashboard')
            ->assertRedirect(route('login'));

        $this->get('/admin/dashboard')
            ->assertSessionHas('error', 'Vui lòng đăng nhập bằng tài khoản quản trị.');
    }

    /** Không có lý do nào thì vẫn phải có một câu tử tế, không phải trang trắng. */
    public function test_khong_co_ly_do_thi_dung_cau_mac_dinh(): void
    {
        $res = $this->get('/admin/dashboard');

        $res->assertRedirect(route('login'));
        $res->assertSessionHas('error', 'Vui lòng đăng nhập bằng tài khoản quản trị.');
    }
}
