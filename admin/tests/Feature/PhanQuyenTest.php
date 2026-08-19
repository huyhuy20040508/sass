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
            // Cây ba tầng: KHU -> nhóm -> mục. Khu là ranh giới bảng tick khoá theo,
            // nên bài nào nói về khoá cũng phải đi qua đúng hình dạng này.
            '*/admin/nhom-quyen/danh-muc*' => Http::response(['data' => [
                [
                    'ma' => 'quan_ly', 'ten' => 'Quản trị', 'mo_ta' => 'Chỉ người có cửa Quản lý.',
                    'nhom' => [
                        ['ten' => 'Hàng hoá', 'mucs' => [
                            ['prefix' => 'san-pham', 'ten' => 'Sản phẩm', 'viec' => ['xem', 'them', 'sua', 'xoa']],
                        ]],
                    ],
                ],
                [
                    'ma' => 'thu_ngan', 'ten' => 'Thu ngân', 'mo_ta' => 'Việc ở quầy.',
                    'nhom' => [
                        ['ten' => 'Bán tại quầy', 'mucs' => [
                            [
                                'prefix' => 'don-hang', 'ten' => 'Đơn hàng',
                                'viec' => ['xem', 'them', 'sua'],
                                'le' => [['ma' => 'doanh-thu', 'ten' => 'Xem doanh thu']],
                            ],
                            ['prefix' => 'so-quy', 'ten' => 'Sổ quỹ', 'viec' => ['them']],
                        ]],
                    ],
                ],
            ]]),
            // Quyền riêng của tài khoản 41 — bảng tick dựng từ đây.
            '*/admin/users/41/quyen' => Http::response(['data' => [
                'toan_quyen' => false,
                'quyen' => ['don-hang.xem', 'so-quy.them'],
            ]]),
            // Thu ngân 42: đang mang một quyền khu quản trị từ trước, thứ bảng
            // tick phải giữ lại chứ không được lặng lẽ đánh rơi lúc Lưu.
            '*/admin/users/42/quyen' => Http::response(['data' => [
                'toan_quyen' => false,
                'quyen' => ['don-hang.xem', 'san-pham.xem'],
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
                    'quyen' => ['quan_ly', 'thu_ngan'],
                ],
                [
                    'id' => 15, 'code' => 'NV0004', 'full_name' => 'Lê Thu Ngân', 'shop_id' => 5,
                    'shop_name' => 'Kho miền Bắc', 'user_id' => 42, 'username' => 'ngan.tn',
                    'user_status' => 'active', 'status' => 'dang_lam',
                    'quyen' => ['thu_ngan'],
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
                    'quyen' => ['quan_ly', 'thu_ngan'],
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
        // Hai mục lớn: việc của quầy không nằm lẫn trong khu quản trị nữa.
        $res->assertSee('data-pq-khu-toggle="0"', false);
        $res->assertSee('data-pq-khu-toggle="1"', false);
        $res->assertSee('Quản trị', false);
        $res->assertSee('Bán tại quầy', false);
        // Gập sẵn CẢ HAI tầng dưới: mở trang ra chỉ thấy hai dòng mục lớn.
        $res->assertSee('class="pq-sec is-hidden"', false);
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

    /**
     * Thu ngân: ô của khu quản trị KHOÁ ngay trên bảng, không cho tick.
     *
     * Người chỉ có cửa quầy mà tick được "Sản phẩm" thì dòng quyền ấy ghi xuống
     * thật nhưng không mở thêm trang nào — nhóm route `manage` bên API đòi cửa
     * `quan_ly` trước khi hỏi tới quyền. Khoá tại chỗ, và nói ra lý do.
     */
    public function test_thu_ngan_khong_tick_duoc_viec_khu_quan_tri(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen?nv=15');

        $res->assertOk();
        $res->assertSee('chỉ được giao khu', false);

        $oSanPham = $this->the($res->getContent(), 'san-pham.xem');
        $this->assertStringContainsString('disabled', $oSanPham);
        // Không mang tên trường thì lượt Lưu không thể gửi nó lên.
        $this->assertStringNotContainsString('name="quyen[]"', $oSanPham);

        // Việc ở quầy thì vẫn tick được như thường.
        $oDonHang = $this->the($res->getContent(), 'don-hang.them');
        $this->assertStringNotContainsString('disabled', $oDonHang);
        $this->assertStringContainsString('name="quyen[]"', $oDonHang);
    }

    /**
     * Quyền khu quản trị mà thu ngân ĐANG CÓ thì lượt Lưu không được đánh rơi.
     *
     * Ô khoá là ô disabled, mà ô disabled không đi theo form. Không giữ lại thì
     * chỉ mở trang lên rồi bấm Lưu là quyền cũ của họ biến mất, không tick gì và
     * cũng không báo gì.
     */
    public function test_giu_lai_quyen_quan_tri_thu_ngan_dang_co(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen?nv=15');

        $res->assertOk();
        $res->assertSee('<input type="hidden" name="quyen[]" value="san-pham.xem">', false);
    }

    /** Người có cửa Quản lý thì bảng mở hết, không ô nào bị khoá. */
    public function test_nguoi_co_cua_quan_ly_tick_duoc_moi_o(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen?nv=12');

        $res->assertOk();
        $res->assertDontSee('chỉ được giao khu', false);

        $oSanPham = $this->the($res->getContent(), 'san-pham.xem');
        $this->assertStringNotContainsString('disabled', $oSanPham);
        $this->assertStringContainsString('name="quyen[]"', $oSanPham);
    }

    /** Thẻ <input> của một mã quyền — thuộc tính xuống dòng nên phải dò cả thẻ. */
    protected function the(string $html, string $ma): string
    {
        $khop = preg_match('/<input[^>]*data-pq-perm="'.preg_quote($ma, '/').'"[^>]*>/', $html, $m);
        $this->assertSame(1, $khop, "không tìm thấy ô tick của quyền $ma");

        return $m[0];
    }

    /**
     * API cũ hơn trang quản trị: nói thẳng, không vẽ một bảng vô nghĩa.
     *
     * Hình dạng cũ (`{nhom, quay}`) lặp ra vẫn được hai dòng — không tên, không
     * con, không tick được gì. Người dùng nhìn vào đó không có cách nào đoán ra
     * là máy chủ chưa khởi động lại.
     */
    public function test_api_tra_hinh_dang_cu_thi_noi_ro(): void
    {
        Http::fake([
            '*/admin/nhom-quyen/danh-muc*' => Http::response(['data' => [
                'nhom' => [['ten' => 'Bán hàng', 'mucs' => []]],
                'quay' => ['don-hang.xem'],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phienQuanTri())->get('/admin/phan-quyen');

        $res->assertOk();
        $res->assertSee('Khởi động lại API', false);
        $res->assertDontSee('data-pq-khu-toggle', false);
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
