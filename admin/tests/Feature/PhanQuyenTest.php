<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phân quyền — chi nhánh → nhân viên → tick từng việc họ được làm.
 *
 * API giả lập (Http::fake) nên bài này chạy được cả khi không có Go API. Phần
 * nghiệp vụ thật (chốt quyền, cô lập giữa hai cửa hàng, không tự sửa quyền của
 * chính mình) do api/internal/apitest kiểm trên MySQL thật.
 */
class PhanQuyenTest extends TestCase
{
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
        Http::fake([
            '*/admin/nhom-quyen/danh-muc*' => Http::response(['data' => [
                ['ten' => 'Bán hàng', 'mucs' => [
                    [
                        'prefix' => 'don-hang', 'ten' => 'Đơn hàng',
                        'viec' => ['xem', 'them', 'sua'],
                        'le' => [['ma' => 'doanh-thu', 'ten' => 'Xem doanh thu']],
                    ],
                    ['prefix' => 'so-quy', 'ten' => 'Sổ quỹ', 'viec' => ['them']],
                ]],
            ]]),
            // Quyền riêng của tài khoản 41 — bảng tick dựng từ đây.
            '*/admin/users/41/quyen' => Http::response(['data' => [
                'toan_quyen' => false,
                'quyen' => ['don-hang.xem', 'so-quy.them'],
            ]]),
            '*/admin/users/*/quyen' => Http::response(['data' => ['toan_quyen' => false, 'quyen' => []]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 5, 'name' => 'Kho miền Bắc', 'code' => 'kho-bac', 'is_active' => true],
            ]]),
            '*/admin/nhan-su*' => Http::response(['data' => [
                [
                    'id' => 12, 'code' => 'NV0001', 'full_name' => 'Nguyễn Văn An', 'shop_id' => 5,
                    'shop_name' => 'Kho miền Bắc', 'user_id' => 41, 'username' => 'an.nv',
                    'user_status' => 'active', 'status' => 'dang_lam',
                ],
                [
                    'id' => 13, 'code' => 'NV0002', 'full_name' => 'Trần Thị Bình', 'shop_id' => 5,
                    'shop_name' => 'Kho miền Bắc', 'user_id' => null, 'username' => '',
                    'user_status' => '', 'status' => 'dang_lam',
                ],
                [
                    'id' => 14, 'code' => 'NV0003', 'full_name' => 'Chủ tiệm', 'shop_id' => 5,
                    'shop_name' => 'Kho miền Bắc', 'user_id' => 1, 'username' => 'admin',
                    'user_status' => 'active', 'status' => 'dang_lam',
                ],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Cây chi nhánh → nhân viên, đúng lối đi của bản ERP cũ. */
    public function test_hien_cay_chi_nhanh_va_nhan_vien(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen');

        $res->assertOk();
        $res->assertSee('Kho miền Bắc', false);
        $res->assertSee('Nguyễn Văn An', false);
        // Chưa chọn ai thì không mở sẵn form ghi của người nào cả.
        $res->assertSee('Chưa chọn ai', false);
    }

    /** Chọn một nhân viên: bảng tick dựng sẵn đúng quyền của người đó. */
    public function test_chon_nhan_vien_thi_tick_san_quyen_cua_ho(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen?nv=12');

        $res->assertOk();
        $res->assertSee('Phân quyền cho: Nguyễn Văn An', false);

        // Thuộc tính xuống dòng trong Blade nên dò bằng biểu thức, không dò chuỗi thẳng.
        $html = $res->getContent();
        $this->assertMatchesRegularExpression('/data-pq-perm="don-hang\.xem"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/data-pq-perm="so-quy\.them"\s+checked/', $html);
        // Quyền không được cấp thì để trống.
        $this->assertDoesNotMatchRegularExpression('/data-pq-perm="don-hang\.sua"\s+checked/', $html);
        // Ô tick gửi lên được, không phải bảng chỉ đọc.
        $res->assertSee('name="quyen[]"', false);
        // Khối gập sẵn — tải lại trang không bung hết bảng ra.
        $res->assertSee('class="pq-sec"', false);
        $res->assertSee('class="pq-row is-hidden"', false);
        // Cột "Xoá" của Đơn hàng bỏ trống vì danh mục không khai việc đó.
        $res->assertDontSee('data-pq-perm="don-hang.xoa"', false);
    }

    /** Lưu gọi đúng đường đặt quyền cho TÀI KHOẢN, rồi quay lại đúng người. */
    public function test_luu_quyen_cho_nhan_vien(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->put('/admin/phan-quyen/nhan-vien/41/quyen', [
                'nv' => 12,
                'quyen' => ['don-hang.xem', 'ton-kho.xem'],
            ]);

        $res->assertRedirect(route('admin.phan-quyen.index', ['nv' => 12]));
        $res->assertSessionHas('success');
        Http::assertSent(fn ($req) => $req->method() === 'PUT'
            && str_ends_with($req->url(), '/admin/users/41/quyen')
            && $req['quyen'] === ['don-hang.xem', 'ton-kho.xem']);
    }

    /**
     * Bỏ hết tick = thu sạch quyền của người đó, không phải "giữ nguyên".
     *
     * Trình duyệt không gửi khoá nào khi mọi ô đều trống, nên đây là chỗ dễ hiểu
     * nhầm nhất của cả màn hình.
     */
    public function test_bo_het_tick_gui_mang_rong(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/phan-quyen/nhan-vien/41/quyen', ['nv' => 12]);

        Http::assertSent(fn ($req) => $req->method() === 'PUT'
            && str_ends_with($req->url(), '/admin/users/41/quyen')
            && $req['quyen'] === []);
    }

    /**
     * Người chưa có tài khoản đăng nhập thì nói thẳng, không bày form ghi.
     *
     * Quyền bám vào TÀI KHOẢN; hồ sơ nhân sự chưa gắn tài khoản thì không có chỗ
     * nào để tick vào.
     */
    public function test_nhan_vien_chua_co_tai_khoan_thi_noi_ro(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen?nv=13');

        $res->assertOk();
        $res->assertSee('chưa có tài khoản đăng nhập', false);
        $res->assertDontSee('name="quyen[]"', false);
    }

    /** Không tự sửa quyền của chính mình — API cũng từ chối lượt đó. */
    public function test_khong_tu_sua_quyen_cua_chinh_minh(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen?nv=14');

        $res->assertOk();
        $res->assertSee('Không tự sửa quyền của chính mình', false);
    }

    /** Có lối vào trong menu Cài đặt — trang không có menu là trang không ai tìm ra. */
    public function test_menu_cai_dat_co_loi_vao(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen');

        $res->assertSee(route('admin.phan-quyen.index'), false);
    }

    /** API hỏng: vẫn mở được trang kèm câu nói rõ, không phải màn trắng. */
    public function test_api_hong_van_mo_duoc_trang(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Lỗi máy chủ'], 500)]);

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen');

        $res->assertOk();
        $res->assertSee('Lỗi máy chủ', false);
    }

    /**
     * API từ chối thì in NGUYÊN VĂN câu của nó.
     *
     * Mỗi câu từ chối ở đây chỉ ra một việc phải làm khác nhau; nuốt thành "thao
     * tác không thành công" là lấy đi đúng phần có ích.
     */
    public function test_api_tu_choi_thi_giu_nguyen_cau_bao(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Không tự đặt quyền cho chính mình'], 403)]);

        $res = $this->withSession($this->phienQuanTri())
            ->put('/admin/phan-quyen/nhan-vien/41/quyen', ['nv' => 12, 'quyen' => []]);

        $res->assertSessionHas('error', 'Không tự đặt quyền cho chính mình');
    }
}
