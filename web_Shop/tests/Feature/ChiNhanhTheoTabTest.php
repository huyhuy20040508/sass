<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CHI NHÁNH ĐANG LÀM VIỆC LÀ CHUYỆN CỦA TỪNG TAB.
 *
 * Trước bản sửa, nó nằm trong PHIÊN — một giá trị cho cả trình duyệt. Hai hậu
 * quả, và cái thứ hai mới là cái nguy hiểm:
 *
 *   1. Mở tab thứ hai xem kho khác thì tab thứ nhất cũng đổi theo; bấm F5 bên
 *      tab cũ là nó "về lại" chi nhánh vừa chọn ở tab kia.
 *   2. Tab cũ VẪN hiện "chi nhánh 1" nhưng mọi lượt ghi từ nó đi vào kho 2 —
 *      màn hình nói một đằng, hàng vào một nẻo, không dấu hiệu nào cả.
 *
 * Bài này gác đúng cái hợp đồng đã chốt: request nào khai `chi_nhanh` thì nó
 * thắng phiên, và lượt khai ấy KHÔNG được ghi đè phiên (ghi vào là tab này lại
 * kéo tab kia đi theo).
 */
class ChiNhanhTheoTabTest extends TestCase
{
    protected function phienQuanTri(int $chiNhanh = 0): array
    {
        $s = [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
        if ($chiNhanh > 0) {
            $s[ApiClient::KHOA_CHI_NHANH] = $chiNhanh;
        }

        return $s;
    }

    protected function fakeApi(): void
    {
        Http::fake([
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 1, 'code' => 'CN01', 'name' => 'Chi nhánh trung tâm', 'is_active' => true],
                ['id' => 2, 'code' => 'CN02', 'name' => 'Chi nhánh Quận 7', 'is_active' => true],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Header `X-Chi-Nhanh` gửi sang API phải là chi nhánh của TAB, không phải của phiên. */
    public function test_tham_so_chi_nhanh_thang_phien(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri(1))
            ->get('/admin/branches?chi_nhanh=2')
            ->assertOk();

        Http::assertSent(fn ($req) => $req->hasHeader('X-Chi-Nhanh', '2'));
        Http::assertNotSent(fn ($req) => $req->hasHeader('X-Chi-Nhanh', '1'));
    }

    /** Lượt gọi ngầm không nhét được tham số vào query nên dùng header riêng. */
    public function test_header_cua_tab_cung_duoc_chap_nhan(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri(1))
            ->withHeader('X-Chi-Nhanh-Tab', '2')
            ->get('/admin/branches')
            ->assertOk();

        Http::assertSent(fn ($req) => $req->hasHeader('X-Chi-Nhanh', '2'));
    }

    /**
     * Khai chi nhánh cho MỘT request KHÔNG được đổi phiên.
     *
     * Đây là điều kiện sống còn của cả cơ chế: ghi vào phiên là tab này kéo tab
     * kia đi theo, tức là quay lại đúng cái lỗi vừa chữa.
     */
    public function test_khong_ghi_de_phien(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri(1))
            ->get('/admin/branches?chi_nhanh=2')
            ->assertOk()
            ->assertSessionHas(ApiClient::KHOA_CHI_NHANH, 1);
    }

    /**
     * Khai `chi_nhanh=0` là cố ý XEM GỘP: không gửi header nào sang API, kể cả
     * khi phiên đang đứng ở một chi nhánh — và phiên không bị đổi.
     */
    public function test_khai_0_la_xem_gop_thang_phien(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri(1))
            ->get('/admin/branches?chi_nhanh=0')
            ->assertOk()
            ->assertSessionHas(ApiClient::KHOA_CHI_NHANH, 1);

        Http::assertNotSent(fn ($req) => $req->hasHeader('X-Chi-Nhanh'));
    }

    /**
     * Phiên đã chọn "Tất cả" (khoá = 0) thì phải GIỮ, không bị ghim lại chi nhánh
     * đầu tiên lúc vẽ trang. Đây là lỗi thật: chọn Tất cả ở màn Cửa vào, mở
     * trang đầu tiên là đã rơi về chi nhánh 1.
     */
    public function test_phien_tat_ca_khong_bi_ghim_lai(): void
    {
        $this->fakeApi();

        $s = $this->phienQuanTri();
        $s[ApiClient::KHOA_CHI_NHANH] = 0;

        $this->withSession($s)
            ->get('/admin/branches')
            ->assertOk()
            ->assertSessionHas(ApiClient::KHOA_CHI_NHANH, 0);

        Http::assertNotSent(fn ($req) => $req->hasHeader('X-Chi-Nhanh'));
    }

    /** Chưa chọn gì (phiên không có khoá) thì vẫn ghim chi nhánh đầu tiên như cũ. */
    public function test_chua_chon_thi_ghim_chi_nhanh_dau(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->get('/admin/branches')
            ->assertOk()
            ->assertSessionHas(ApiClient::KHOA_CHI_NHANH, 1);
    }

    /**
     * Mặc định phải là TRỤ SỞ, không phải chi nhánh có MÃ đứng đầu bảng chữ cái.
     *
     * API trả danh sách theo `code ASC`, nên mở thêm "cn000002" là nó đứng trước
     * "mac-dinh". Lấy `$ds[0]` thì từ lúc ấy mọi lượt đăng nhập sạch đều rơi vào
     * chi nhánh mới trống trơn thay vì kho cũ đang có hàng. Bài này dựng đúng
     * thứ tự đó để bắt: chi nhánh mới (id 2, loại Chi nhánh) đứng trước trụ sở
     * (id 1, loại Công ty).
     */
    public function test_mac_dinh_la_tru_so_khong_phai_ma_dung_dau(): void
    {
        Http::fake([
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 2, 'code' => 'cn000002', 'name' => 'Chi nhánh 2', 'branch_type' => 1, 'is_active' => true],
                ['id' => 1, 'code' => 'mac-dinh', 'name' => 'Trụ sở', 'branch_type' => 2, 'is_active' => true],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phienQuanTri())
            ->get('/admin/branches')
            ->assertOk()
            ->assertSessionHas(ApiClient::KHOA_CHI_NHANH, 1);
    }

    /** Không khai loại thì lấy id nhỏ nhất — dòng dựng cùng lúc mở tài khoản. */
    public function test_khong_khai_loai_thi_lay_id_nho_nhat(): void
    {
        Http::fake([
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 7, 'code' => 'a-kho', 'name' => 'Kho A', 'is_active' => true],
                ['id' => 3, 'code' => 'z-kho', 'name' => 'Kho Z', 'is_active' => true],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phienQuanTri())
            ->get('/admin/branches')
            ->assertOk()
            ->assertSessionHas(ApiClient::KHOA_CHI_NHANH, 3);
    }

    /** dangLam nhận id = 0 ("Tất cả") — màn Cửa vào và thanh trên cùng khu cũ gửi số này. */
    public function test_doi_sang_tat_ca_o_thanh_tren_cung(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri(2))
            ->post(route('admin.chi-nhanh.dangLam'), ['id' => 0])
            ->assertRedirect()
            ->assertSessionHas(ApiClient::KHOA_CHI_NHANH, 0);
    }

    /** Không khai gì thì vẫn chạy bằng phiên — đường của tab vừa mở. */
    public function test_khong_khai_thi_dung_phien(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri(2))
            ->get('/admin/branches')
            ->assertOk();

        Http::assertSent(fn ($req) => $req->hasHeader('X-Chi-Nhanh', '2'));
    }

    /**
     * Trang phải NÓI RA nó đã vẽ bằng chi nhánh nào.
     *
     * Khối JS bên layout đọc thẻ meta này để biết trang có khớp chi nhánh của
     * tab không; thiếu nó thì không có gì để so, và cơ chế tắt trong im lặng.
     */
    public function test_trang_dong_dau_chi_nhanh_da_ve(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri(1))
            ->get('/admin/branches?chi_nhanh=2')
            ->getContent();

        $this->assertStringContainsString('<meta name="chi-nhanh" content="2">', $html);
        $this->assertStringContainsString('v2_chi_nhanh_tab', $html,
            'layout phải mang khối JS giữ chi nhánh theo tab');
    }
}
