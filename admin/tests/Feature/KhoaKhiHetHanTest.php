<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CỬA HÀNG HẾT HẠN HỢP ĐỒNG — phiên bị giam lại đúng một trang.
 *
 * Chốt chặn thật nằm ở Go API (403 kèm mã CUA_HANG_KHOA cho mọi đường trừ đường
 * đọc gói dịch vụ). Bài này kiểm phần việc của Shop Admin: đưa người dùng về
 * đúng chỗ nói cho họ biết phải làm gì, thay vì để họ bấm quanh và nhận một lỗi
 * khác nhau ở mỗi mục.
 */
class KhoaKhiHetHanTest extends TestCase
{
    /** Phiên của một chủ tiệm đang bị khoá vì hết hạn. */
    protected function phienKhoa(): array
    {
        return [
            'api.access_token' => 'token-gia',
            'api.user' => ['id' => 1, 'role' => ['name' => 'super_admin', 'display_name' => 'Super Admin']],
            'phien.cua_hang_khoa' => true,
        ];
    }

    /**
     * @param  bool  $hetHan  hợp đồng đã quá mốc hay chưa — quyết định cả `het_han`
     *                        lẫn `da_het_han`, hai thứ phải kể cùng một câu chuyện.
     */
    protected function apiTraGoiDichVu(bool $hetHan = true): void
    {
        Http::fake([
            '*/admin/goi-dich-vu' => Http::response(['success' => true, 'data' => [
                'hop_dong' => [
                    'ten_app' => 'Sellio Order', 'goi' => 'cua_hang', 'ten_goi' => 'Cửa hàng',
                    'trang_thai' => $hetHan ? 'past_due' : 'active', 'chu_ky' => 'thang', 'gia' => 499000,
                    'chi_nhanh' => 1, 'tai_khoan' => 0, 'san_pham' => 0, 'ten_mien_rieng' => false,
                    'bat_dau' => '2026-05-15T00:00:00Z',
                    'het_han' => $hetHan ? '2026-08-01T00:00:00Z' : now()->addDays(30)->toIso8601String(),
                    'con_lai_ngay' => $hetHan ? -14 : 30,
                    'da_het_han' => $hetHan,
                    'dung_thu' => false,
                ],
                'bang_gia' => [], 'fields' => [],
            ]], 200),
        ]);
    }

    public function test_moi_trang_khac_deu_ve_trang_goi_dich_vu(): void
    {
        $phien = $this->phienKhoa();

        foreach (['/admin/dashboard', '/admin/orders', '/admin/products', '/admin/settings/general'] as $duong) {
            $this->withSession($phien)->get($duong)
                ->assertRedirect(route('admin.goi-dich-vu.index'));
        }
    }

    /** Cả lượt GHI cũng bị chặn — không có cửa sau nào qua POST/PUT/DELETE. */
    public function test_luot_ghi_cung_bi_chan(): void
    {
        $this->withSession($this->phienKhoa())
            ->put('/admin/products/1/toggle-status')
            ->assertRedirect(route('admin.goi-dich-vu.index'));
    }

    /** AJAX nhận JSON 403, không phải một trang HTML chuyển hướng mà script không đọc nổi. */
    public function test_ajax_nhan_json_403(): void
    {
        $res = $this->withSession($this->phienKhoa())->getJson('/admin/notifications');

        $res->assertStatus(403);
        $res->assertJsonPath('message', 'Cửa hàng đã hết hạn sử dụng. Vui lòng gia hạn để tiếp tục làm việc.');
    }

    public function test_trang_goi_dich_vu_van_mo_duoc_va_hien_hop_thoai(): void
    {
        $this->apiTraGoiDichVu();

        $res = $this->withSession($this->phienKhoa())->get('/admin/goi-dich-vu');

        $res->assertOk();
        $res->assertSee('Phần mềm đã hết hạn');
        // Hai lối ra, đúng hai việc còn làm được lúc này.
        $res->assertSee('Xem tuỳ chọn gia hạn');
        $res->assertSee('Thoát ra');
        // Thanh trái bỏ hẳn điều hướng: mọi mục trong đó đều quay về đây.
        $res->assertDontSee('Quản lý kho');
    }

    /**
     * VỪA QUÁ MỐC HẾT HẠN, mà lượt quét nền của máy chủ CHƯA chạy — nên API vẫn
     * trả 200 cho mọi thứ và chưa có cờ khoá nào trong session.
     *
     * Đây đúng là lỗi đã gặp trên máy chạy thật: hợp đồng chết lúc 10:45, tới
     * 10:47 bấm F5 vẫn không cảnh báo gì, vì tất cả đang chờ lượt quét 5 phút một
     * lần. Khoá phải tính theo ĐỒNG HỒ so với mốc hết hạn, không theo lượt quét.
     */
    public function test_qua_moc_het_han_la_khoa_ngay_khong_cho_luot_quet(): void
    {
        $this->apiTraGoiDichVu();
        $phien = $this->phienKhoa();
        unset($phien['phien.cua_hang_khoa']);

        $this->withSession($phien)->get('/admin/dashboard')
            ->assertRedirect(route('admin.goi-dich-vu.index'));
    }

    /** Cửa hàng còn hạn thì KHÔNG có gì đổi — không chặn, không hộp thoại. */
    public function test_cua_hang_con_han_khong_bi_anh_huong(): void
    {
        $this->apiTraGoiDichVu(hetHan: false);
        $phien = $this->phienKhoa();
        unset($phien['phien.cua_hang_khoa']);

        $res = $this->withSession($phien)->get('/admin/goi-dich-vu');

        $res->assertOk();
        $res->assertDontSee('Phần mềm đã hết hạn');
        $res->assertSee('Quản lý kho');
    }
}
