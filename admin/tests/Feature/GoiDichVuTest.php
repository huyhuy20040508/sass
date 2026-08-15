<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trang "Các gói dịch vụ" — chỗ chủ tiệm tự tra hợp đồng phần mềm của mình.
 *
 * Dùng Http::fake nên KHÔNG cần API chạy thật (khác AdminSmokeTest): thứ đang
 * kiểm là Shop Admin đọc câu trả lời của API ra màn hình có đúng không, nhất là
 * ba chỗ dễ hiển thị sai nhất — hạn mức 0 (không giới hạn, không phải "không có
 * cái nào"), hợp đồng vắng mặt (hợp lệ, không phải lỗi), và 404 của nhóm route
 * chưa đăng ký (máy chủ chưa nối sổ nền tảng, không phải "cửa hàng không có
 * hợp đồng").
 */
class GoiDichVuTest extends TestCase
{
    /** Session giả của một chủ tiệm đã đăng nhập — middleware chỉ đọc chừng này. */
    protected function phienChuTiem(): array
    {
        return [
            'api.access_token' => 'token-gia',
            'api.user' => ['id' => 1, 'role' => ['name' => 'super_admin', 'display_name' => 'Super Admin']],
        ];
    }

    protected function traLoiApi(array $data): void
    {
        Http::fake([
            '*/admin/goi-dich-vu' => Http::response(['success' => true, 'data' => $data], 200),
        ]);
    }

    public function test_hien_goi_dang_dung_va_so_ngay_con_lai(): void
    {
        $this->traLoiApi([
            'hop_dong' => [
                'ten_app' => 'Sellio Order',
                'goi' => 'cua_hang',
                'ten_goi' => 'Cửa hàng',
                'trang_thai' => 'active',
                'chu_ky' => 'thang',
                'gia' => 499000,
                'chi_nhanh' => 1,
                'tai_khoan' => 0,
                'san_pham' => 0,
                'ten_mien_rieng' => false,
                'bat_dau' => '2026-01-15T00:00:00Z',
                'het_han' => '2026-09-15T00:00:00Z',
                'con_lai_ngay' => 31,
                'da_het_han' => false,
                'dung_thu' => false,
            ],
            'bang_gia' => [],
            'fields' => [],
        ]);

        $res = $this->withSession($this->phienChuTiem())->get('/admin/goi-dich-vu');

        $res->assertOk();
        $res->assertSee('Cửa hàng');
        $res->assertSee('Đang sử dụng');
        $res->assertSee('499.000₫');
        // Vòng đếm ngược + hai đầu thanh thời gian của kỳ hiện tại.
        $res->assertSee('ngày còn lại');
        $res->assertSee('Hết hạn 15/09/2026');
        // Hạn mức 0 = KHÔNG GIỚI HẠN. In số 0 ra là nói với khách rằng họ không
        // được tài khoản nào — ngược hẳn điều khoản đã ký.
        $res->assertSee('Không giới hạn');
    }

    /** Quá hạn: con số âm phải đọc thành "đã quá hạn N ngày", kèm việc phải làm. */
    public function test_hop_dong_qua_han_noi_ro_phai_lam_gi(): void
    {
        $this->traLoiApi([
            'hop_dong' => [
                'ten_app' => 'Sellio Order',
                'goi' => 'khoi_dau',
                'ten_goi' => 'Khởi đầu',
                'trang_thai' => 'past_due',
                'chu_ky' => 'thang',
                'gia' => 0,
                'chi_nhanh' => 1,
                'tai_khoan' => 3,
                'san_pham' => 500,
                'ten_mien_rieng' => false,
                'bat_dau' => '2026-07-01T00:00:00Z',
                'het_han' => '2026-08-01T00:00:00Z',
                'con_lai_ngay' => -14,
                // `da_het_han` là câu trả lời của máy chủ, so tới từng giây — trang
                // đọc nó chứ không suy từ con_lai_ngay (xem TestHopDongCuaToi_QuanhMocHetHan
                // bên Go: quanh mốc hết hạn con số ngày bằng 0 suốt 24 giờ).
                'da_het_han' => true,
                'dung_thu' => true,
            ],
            'bang_gia' => [],
            'fields' => [],
        ]);

        $res = $this->withSession($this->phienChuTiem())->get('/admin/goi-dich-vu');

        $res->assertOk();
        $res->assertSee('Đã quá hạn');
        $res->assertSee('ngày quá hạn');
        $res->assertSee('Hợp đồng đã hết hạn từ 01/08/2026', false);
        $res->assertSee(\App\Http\Controllers\GoiDichVuController::SUPPORT_EMAIL);
    }

    /** Chưa có hợp đồng là trạng thái HỢP LỆ, không phải lỗi — và phải nói ra. */
    public function test_chua_co_hop_dong_thi_noi_ra_chu_khong_de_trong(): void
    {
        $this->traLoiApi(['hop_dong' => null, 'bang_gia' => [], 'fields' => []]);

        $res = $this->withSession($this->phienChuTiem())->get('/admin/goi-dich-vu');

        $res->assertOk();
        $res->assertSee('Chưa có hợp đồng nào trong sổ nhà cung cấp');
    }

    /**
     * 404 = nhóm route bên API chưa được đăng ký, tức máy chủ chưa nối sổ nền
     * tảng. Đọc nhầm thành "cửa hàng này không có hợp đồng" là để chủ tiệm yên
     * tâm về đúng thứ đang không tra được.
     */
    public function test_api_chua_noi_so_nen_tang_thi_noi_dung_ly_do(): void
    {
        Http::fake([
            '*/admin/goi-dich-vu' => Http::response(['success' => false, 'message' => 'not found'], 404),
        ]);

        $res = $this->withSession($this->phienChuTiem())->get('/admin/goi-dich-vu');

        $res->assertOk();
        $res->assertSee('Máy chủ chưa tra được sổ hợp đồng của nhà cung cấp', false);
        $res->assertDontSee('Chưa có hợp đồng nào trong sổ nhà cung cấp');
    }

    /** Bảng giá: gói đang dùng được đánh dấu, khoá thiếu dòng đọc thành thoả thuận. */
    public function test_bang_gia_danh_dau_goi_cua_ban(): void
    {
        $this->traLoiApi([
            'hop_dong' => [
                'ten_app' => 'Sellio Order', 'goi' => 'chuoi', 'ten_goi' => 'Chuỗi',
                'trang_thai' => 'active', 'chu_ky' => 'thang', 'gia' => 990000,
                'chi_nhanh' => 5, 'tai_khoan' => 0, 'san_pham' => 0, 'ten_mien_rieng' => true,
                'bat_dau' => '2026-01-01T00:00:00Z', 'het_han' => '2026-12-01T00:00:00Z',
                'con_lai_ngay' => 108, 'da_het_han' => false, 'dung_thu' => false,
            ],
            'bang_gia' => [
                [
                    'id' => 3, 'code' => 'chuoi', 'name' => 'Chuỗi', 'tagline' => 'Từ hai cửa hàng trở lên',
                    'billing_cycle' => 'thang', 'price' => null, 'trial_days' => 0, 'status' => 'active',
                    'features' => ['own_domain' => '1'],
                ],
            ],
            'fields' => [
                ['key' => 'max_shops', 'type' => 'so', 'label' => 'Số chi nhánh', 'don_vi' => 'cửa hàng', 'khong_co_dong' => '', 'cho_vo_han' => true, 'max_num' => 1000],
                ['key' => 'own_domain', 'type' => 'co_khong', 'label' => 'Tên miền riêng', 'don_vi' => '', 'khong_co_dong' => '0', 'cho_vo_han' => false, 'max_num' => 0],
            ],
        ]);

        $res = $this->withSession($this->phienChuTiem())->get('/admin/goi-dich-vu');

        $res->assertOk();
        $res->assertSee('Gói của bạn');
        // price = null nghĩa là "Liên hệ" (chưa công bố giá), KHÁC 0 là miễn phí.
        $res->assertSee('Liên hệ');
        // Khoá không có dòng = bảng giá không quy định, chốt lúc ký — không phải 0.
        $res->assertSee('Số chi nhánh: thoả thuận khi ký');
    }
}
