<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Kiểm tra bộ khung dựng đúng: trang đăng nhập lên được, và khu điều hành đóng
 * với người chưa đăng nhập.
 *
 * Cố ý KHÔNG gọi Go API: đây là test của tầng khung, chạy được cả khi chưa bật
 * API — thứ hay hỏng ở tầng này là route/middleware/view, không phải mạng.
 */
class PlatformSmokeTest extends TestCase
{
    public function test_trang_dang_nhap_hien_thi(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Khu điều hành nền tảng', false);
    }

    public function test_chua_dang_nhap_thi_khong_vao_duoc_tong_quan(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_goc_chuyen_ve_tong_quan(): void
    {
        $this->get('/')->assertRedirect(route('platform.dashboard'));
    }

    /**
     * Vai trò của cửa hàng KHÔNG được vào khu điều hành nền tảng. Đây là ranh
     * giới quan trọng nhất của app này nên phải có test canh: nới nhầm một dòng
     * trong EnsurePlatformAuthenticated là nhân viên cửa hàng nhìn thấy dữ liệu
     * của toàn bộ khách hàng khác.
     */
    public function test_vai_tro_cua_hang_bi_chan(): void
    {
        foreach (['admin', 'staff', 'customer'] as $role) {
            $this->flushSession();
            $this->withSession([
                'api.access_token' => 'token-gia',
                'api.user' => ['role' => ['name' => $role]],
            ])->get('/dashboard')->assertRedirect('/login');
        }
    }
}
