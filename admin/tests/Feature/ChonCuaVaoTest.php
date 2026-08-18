<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\ChiNhanhDangLam;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Màn CHỌN CỬA VÀO — ngã ba giữa trang đăng nhập và chỗ làm việc.
 *
 * Bốn thứ dễ hỏng ở một màn hình kiểu này, và cả bốn đều hỏng lặng lẽ:
 *
 *  1. Nó hiện ra với người chỉ có MỘT khu — thành một màn hình có đúng một ô để
 *     bấm, lấy lại đúng cái click mà lần tách module vừa bỏ đi.
 *  2. Nó nhận bừa mã khu gửi lên, để người dùng bấm xong bị `admin.cua` đá ngược
 *     về mà không hiểu vì sao.
 *  3. Chi nhánh vừa chọn rơi mất trên đường vào module — hàng đi ra khỏi kho khác
 *     và không ai biết cho tới lúc kiểm kê.
 *  4. Nó mở cho người chưa đăng nhập.
 *
 * API được giả lập (Http::fake) nên bài này chạy được cả khi không có Go API.
 */
class ChonCuaVaoTest extends TestCase
{
    /**
     * Xoá bộ nhớ TRONG-MỘT-REQUEST của ChiNhanhDangLam trước mỗi bài.
     *
     * Lớp đó cất danh sách chi nhánh vào một biến static để layout gọi mấy lần
     * cũng chỉ tốn một lượt API. Ngoài đời mỗi request là một tiến trình PHP mới
     * nên không sao, nhưng cả lớp test này chạy trong CÙNG một tiến trình: bài
     * trước giả lập tiệm một chi nhánh thì bài sau nhận lại đúng danh sách ấy,
     * dù Http::fake đã đổi.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->quenChiNhanh();
    }

    protected function quenChiNhanh(): void
    {
        $nho = new \ReflectionProperty(ChiNhanhDangLam::class, 'nho');
        $nho->setAccessible(true);
        $nho->setValue(null, null);
    }

    /** Phiên của người được giao đúng những cửa liệt kê. */
    protected function phien(string $vaiTro, string $cua): array
    {
        return [
            'api.access_token' => 'token-'.$vaiTro,
            'api.refresh_token' => 'refresh-'.$vaiTro,
            'api.user' => [
                'id' => 7,
                'full_name' => 'Nguyễn Quốc Huy',
                'role' => ['name' => $vaiTro],
                'access_areas' => $cua,
            ],
            'api.tenant' => ['code' => 'quochuy', 'name' => 'Tiệm Quốc Huy'],
        ];
    }

    /** @param  array<int, array{id: int, name: string}>  $chiNhanh */
    protected function fakeApi(array $chiNhanh = []): void
    {
        Http::fake([
            '*/admin/chi-nhanh*' => Http::response(['data' => $chiNhanh]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    // ------------------------------------------------------- ai thấy màn này

    /** Người có CẢ HAI cửa: thấy đủ hai ô, kèm tên cửa hàng vừa đăng nhập. */
    public function test_nguoi_hai_cua_thay_ca_hai_o(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phien('admin', 'quan_ly,thu_ngan'))
            ->get(route('chon-cua'))->assertOk();

        $html->assertSee('Thu ngân', false);
        $html->assertSee('Quản trị', false);
        $html->assertSee('value="thu-ngan"', false);
        $html->assertSee('value="quan-tri"', false);
        // Người trông nhiều tiệm gõ nhầm mã cửa hàng là chuyện có thật — đây là
        // màn hình cuối cùng còn kịp nhận ra.
        $html->assertSee('Tiệm Quốc Huy', false);
    }

    /**
     * Người CHỈ có cửa quầy không bao giờ gặp màn này — kể cả khi gõ tay đường dẫn.
     *
     * Một màn hình chỉ có đúng một ô để bấm không hỏi ai điều gì, nó chỉ bắt bấm.
     * Người trực quầy mở máy ra là để bán hàng.
     */
    public function test_nguoi_mot_cua_di_thang_vao_module_cua_minh(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien('staff', 'thu_ngan'))
            ->get(route('chon-cua'))
            ->assertRedirect(route('thu-ngan.ban-hang.index'));
    }

    /** Chưa đăng nhập thì không mở được. */
    public function test_chua_dang_nhap_thi_ve_trang_dang_nhap(): void
    {
        $this->get(route('chon-cua'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------ bấm vào ô

    /** Bấm ô Thu ngân là vào thẳng quầy. */
    public function test_chon_thu_ngan_vao_quay(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien('admin', 'quan_ly,thu_ngan'))
            ->post(route('chon-cua.vao'), ['module' => 'thu-ngan'])
            ->assertRedirect(route('thu-ngan.ban-hang.index'));
    }

    /** Bấm ô Quản trị là vào khu quản trị. */
    public function test_chon_quan_tri_vao_khu_quan_tri(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien('admin', 'quan_ly,thu_ngan'))
            ->post(route('chon-cua.vao'), ['module' => 'quan-tri'])
            ->assertRedirect(route('admin.dashboard'));
    }

    /**
     * Gửi lên một khu KHÔNG được giao: quay lại màn chọn kèm lý do, không vào.
     *
     * Biểu mẫu là thứ sửa được. Nhận bừa ở đây thì `admin.cua` bên trong module
     * mới chặn — người dùng bấm một ô rồi bị đá ngược về, không có gì nói vì sao.
     */
    public function test_khu_khong_duoc_giao_thi_bi_tu_choi(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien('staff', 'thu_ngan'))
            ->post(route('chon-cua.vao'), ['module' => 'quan-tri'])
            ->assertRedirect(route('chon-cua'))
            ->assertSessionHas('error');
    }

    /** Mã khu bịa hẳn ra cũng vậy. */
    public function test_ma_khu_la_thi_bi_tu_choi(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien('admin', 'quan_ly,thu_ngan'))
            ->post(route('chon-cua.vao'), ['module' => 'kho-bi-mat'])
            ->assertRedirect(route('chon-cua'))
            ->assertSessionHas('error');
    }

    // ----------------------------------------------------------- chi nhánh

    /**
     * Tiệm MỘT chi nhánh: không in ô chọn nào.
     *
     * Cùng luật với ô chọn trên hai thanh trên cùng — một select chỉ có đúng một
     * dòng là thứ chiếm chỗ mà không trả lời câu hỏi nào.
     */
    public function test_tiem_mot_chi_nhanh_khong_hien_o_chon(): void
    {
        $this->fakeApi([['id' => 3, 'name' => 'Kho miền Bắc', 'is_active' => true]]);

        $this->withSession($this->phien('admin', 'quan_ly,thu_ngan'))
            ->get(route('chon-cua'))->assertOk()
            ->assertDontSee('name="chi_nhanh"', false);
    }

    /**
     * Từ HAI chi nhánh trở lên: ô chọn có mặt, đủ tên.
     *
     * Tách khỏi bài trên chứ không gộp thành hai lượt trong một bài: gọi Http::fake
     * lần thứ hai chỉ THÊM stub chứ không thay, nên lượt sau vẫn khớp stub cũ và
     * bài test đỏ vì lý do chẳng liên quan gì tới màn hình này.
     */
    public function test_tiem_nhieu_chi_nhanh_hien_o_chon(): void
    {
        $this->fakeApi([
            ['id' => 3, 'name' => 'Kho miền Bắc', 'is_active' => true],
            ['id' => 5, 'name' => 'Kho miền Nam', 'is_active' => true],
        ]);

        $this->withSession($this->phien('admin', 'quan_ly,thu_ngan'))
            ->get(route('chon-cua'))->assertOk()
            ->assertSee('name="chi_nhanh"', false)
            ->assertSee('Kho miền Nam', false);
    }

    /** Chi nhánh chọn cùng lượt bấm được ghi vào phiên — từ đó ApiClient tự đính vào mọi request. */
    public function test_chi_nhanh_duoc_ghi_vao_phien(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien('admin', 'quan_ly,thu_ngan'))
            ->post(route('chon-cua.vao'), ['module' => 'thu-ngan', 'chi_nhanh' => 5])
            ->assertRedirect(route('thu-ngan.ban-hang.index'))
            ->assertSessionHas(ApiClient::KHOA_CHI_NHANH, 5);
    }

    /** 0 = xem gộp cả cửa hàng: BỎ HẲN khoá khỏi phiên để ApiClient không đính header nào. */
    public function test_chon_tat_ca_chi_nhanh_thi_bo_khoa_khoi_phien(): void
    {
        $this->fakeApi();

        $phien = $this->phien('admin', 'quan_ly,thu_ngan');
        $phien[ApiClient::KHOA_CHI_NHANH] = 5;

        $this->withSession($phien)
            ->post(route('chon-cua.vao'), ['module' => 'quan-tri', 'chi_nhanh' => 0])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionMissing(ApiClient::KHOA_CHI_NHANH);
    }

    /**
     * Biểu mẫu KHÔNG gửi ô chi nhánh (tiệm một chi nhánh) thì đừng đụng vào phiên.
     *
     * Ghi bừa số 0 vào đó là lặng lẽ bỏ chi nhánh người ta đang làm việc dở.
     */
    public function test_khong_gui_chi_nhanh_thi_giu_nguyen_chi_nhanh_cu(): void
    {
        $this->fakeApi();

        $phien = $this->phien('admin', 'quan_ly,thu_ngan');
        $phien[ApiClient::KHOA_CHI_NHANH] = 5;

        $this->withSession($phien)
            ->post(route('chon-cua.vao'), ['module' => 'thu-ngan'])
            ->assertRedirect(route('thu-ngan.ban-hang.index'))
            ->assertSessionHas(ApiClient::KHOA_CHI_NHANH, 5);
    }
}
