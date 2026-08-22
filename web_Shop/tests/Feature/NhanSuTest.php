<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nhân sự — màn hình hồ sơ nhân viên.
 *
 * API được giả lập (Http::fake) nên bài này chạy được cả khi không có Go API —
 * khác AdminSmokeTest, vốn tự bỏ qua khi không gọi được API thật. Phần nghiệp vụ
 * thật (mã tự sinh, tách theo cửa hàng, cấp tài khoản đăng nhập được) do
 * api/internal/apitest/nhan_su_test.go kiểm trên MySQL thật.
 *
 * Ở đây chốt phần việc của Shop Admin: gửi đúng payload lên API, kiểm dữ liệu
 * trước khi gửi, và không còn lối vào trang "Người dùng & vai trò" cũ.
 */
class NhanSuTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function fakeApi(array $nhanSu = []): void
    {
        Http::fake([
            '*/admin/nhan-su*' => Http::response(['data' => $nhanSu]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 3, 'name' => 'Kho miền Bắc', 'code' => 'kho-bac', 'is_active' => true],
            ]]),
            '*/admin/roles*' => Http::response(['data' => [
                ['id' => 1, 'display_name' => 'Super Admin'],
                ['id' => 2, 'display_name' => 'Quản trị viên'],
                ['id' => 3, 'display_name' => 'Thu ngân'],
                ['id' => 4, 'display_name' => 'Khách hàng'],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Danh sách đổ từ API, kèm hai ô chọn lấy dữ liệu thật (chi nhánh, vai trò). */
    public function test_trang_hien_danh_sach_tu_api(): void
    {
        $this->fakeApi([[
            'id' => 12,
            'code' => 'NV0001',
            'full_name' => 'Nguyễn Văn An',
            'phone' => '0912345678',
            'position' => 'thu_ngan',
            'status' => 'dang_lam',
            'shop_name' => 'Kho miền Bắc',
            'hired_on' => '2026-08-17T00:00:00Z',
            'salary' => 8000000,
            'username' => 'an.nv',
        ]]);

        $res = $this->withSession($this->phienQuanTri())->get('/admin/staff');

        $res->assertOk();
        $res->assertSee('NV0001', false);
        $res->assertSee('Nguyễn Văn An', false);
        $res->assertSee('an.nv', false);
        // Cột Phân quyền: ô TICK (không phải danh sách chọn một) và chỉ hai vai
        // cấp được từ hồ sơ nhân sự.
        $res->assertSee('name="quyen[]" value="3"', false);
        $res->assertSee('name="quyen[]" value="2"', false);
        $res->assertDontSee('>Khách hàng</option>', false);
        $res->assertDontSee('>Super Admin</option>', false);
    }

    /**
     * Bảng có cột STT và cột Mã NV RIÊNG, không nhét mã xuống dòng phụ dưới tên.
     *
     * Mã nhân viên là thứ để đối chiếu với bảng lương và bảng chấm công, nên phải
     * xếp thẳng hàng mà dò. Nhét xuống dòng phụ thì mắt phải nhảy zíc-zắc khi dò
     * một danh sách mã — đúng việc người ta mở bảng này ra để làm.
     */
    public function test_bang_co_cot_stt_va_cot_ma_rieng(): void
    {
        $this->fakeApi([
            ['id' => 12, 'code' => 'NV0001', 'full_name' => 'Người thứ nhất', 'status' => 'dang_lam'],
            ['id' => 13, 'code' => 'NV0002', 'full_name' => 'Người thứ hai', 'status' => 'dang_lam'],
        ]);

        $html = $this->withSession($this->phienQuanTri())
            ->get('/admin/staff')->assertOk()->getContent();

        $this->assertStringContainsString('>STT<', $html);
        $this->assertStringContainsString('>Mã NV<', $html);
        $this->assertStringContainsString('>Họ tên<', $html);

        // Mã nằm trong ô riêng của nó, không phải trong khối chữ phụ dưới tên.
        $this->assertStringContainsString('<code class="nsu-code">NV0001</code>', $html);
        $this->assertStringNotContainsString('nsu-small">NV0001', $html);

        // Số thứ tự đếm từ 1.
        $this->assertMatchesRegularExpression('#<td class="nsu-c-stt nsu-muted">\s*1\s*</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td class="nsu-c-stt nsu-muted">\s*2\s*</td>#u', $html);
    }

    /**
     * Bảng hiện ĐÚNG những cửa đã tích — tích hai ô ra hai huy hiệu, tích một ô
     * ra một.
     *
     * Đây là chỗ dễ làm sai nhất của cả module: `users.role_id` chỉ có một con số
     * nên rất dễ suy huy hiệu ra từ nó, và lúc ấy người chỉ được tích "Quản lý"
     * lại hiện thêm huy hiệu "Thu ngân" mà chủ tiệm chưa từng tích. Huy hiệu phải
     * đọc từ `users.access_areas` — thứ ghi lại đúng lượt tích (migration 0015).
     */
    public function test_bang_hien_dung_nhung_cua_da_tich(): void
    {
        $this->fakeApi([
            [
                'id' => 12, 'code' => 'NV0001', 'full_name' => 'Người kiêm hai việc',
                'status' => 'dang_lam', 'username' => 'kiem.nv', 'role_id' => 2,
                'quyen' => ['quan_ly', 'thu_ngan'],
            ],
            [
                'id' => 13, 'code' => 'NV0002', 'full_name' => 'Người chỉ quản lý',
                'status' => 'dang_lam', 'username' => 'quanly.nv', 'role_id' => 2,
                'quyen' => ['quan_ly'],
            ],
            [
                'id' => 14, 'code' => 'NV0003', 'full_name' => 'Người thu ngân',
                'status' => 'dang_lam', 'username' => 'thungan.nv', 'role_id' => 3,
                'quyen' => ['thu_ngan'],
            ],
        ]);

        $res = $this->withSession($this->phienQuanTri())->get('/admin/staff');
        $html = $res->assertOk()->getContent();

        // Hai người có cửa quản lý, hai người có cửa quầy — đếm đúng số huy hiệu.
        $this->assertSame(2, substr_count($html, 'is-cua-quan_ly">Quản lý<'));
        $this->assertSame(2, substr_count($html, 'is-cua-thu_ngan">Thu ngân<'));
    }

    /**
     * TÍCH GÌ VÀO ĐƯỢC NẤY — người chỉ được tích "Quản lý" không mở được quầy.
     *
     * Trước migration 0015, vai `admin` đi qua cả hai khu nên chủ tiệm không có
     * cách nào đóng quầy lại với một người quản lý. Giờ cửa đọc từ
     * `users.access_areas`, và middleware phải chặn thật chứ không chỉ ẩn nút đi.
     */
    public function test_chi_tich_quan_ly_thi_khong_vao_quay(): void
    {
        $this->fakeApi();

        $phien = $this->phienQuanTri();
        $phien['api.user']['access_areas'] = 'quan_ly';

        $this->withSession($phien)->get('/cashier/sales')
            ->assertRedirect(route('admin.dashboard'));

        // Còn khu quản trị thì vẫn vào bình thường.
        $this->withSession($phien)->get('/admin/staff')->assertOk();
    }

    /** Chiều ngược lại: chỉ tích "Thu ngân" thì khu quản trị đóng. */
    public function test_chi_tich_thu_ngan_thi_khong_vao_khu_quan_tri(): void
    {
        $this->fakeApi();

        $phien = $this->phienQuanTri();
        $phien['api.user']['role'] = ['name' => 'staff'];
        $phien['api.user']['access_areas'] = 'thu_ngan';

        // Về QUẦY chứ không về Tổng quan: Tổng quan cũng nằm sau cửa `quan_ly`,
        // đưa họ tới đó là bắt đi thêm một vòng để nhận cùng câu từ chối.
        $this->withSession($phien)->get('/admin/staff')
            ->assertRedirect(route('thu-ngan.ban-hang.index'));
    }

    /**
     * Người CHỈ đứng quầy không xem được hồ sơ của mình — menu còn đúng nút
     * Đăng xuất.
     *
     * Kiểm CẢ HAI đầu, vì ẩn mà không đóng thì gõ thẳng đường dẫn là vào:
     *   - thanh trên cùng của quầy KHÔNG in ra link "Tài khoản của tôi";
     *   - /admin/profile trả về chuyển hướng chứ không mở trang.
     */
    public function test_thu_ngan_khong_xem_duoc_ho_so(): void
    {
        $this->fakeApi();

        $phien = $this->phienQuanTri();
        $phien['api.user']['role'] = ['name' => 'staff'];
        $phien['api.user']['access_areas'] = 'thu_ngan';

        // Menu ở quầy: có Đăng xuất, không có Tài khoản của tôi.
        $quay = $this->withSession($phien)->get('/cashier/sales');
        $quay->assertSuccessful();
        $quay->assertSee('Đăng xuất', false);
        $quay->assertDontSee('Tài khoản của tôi', false);

        // Gõ thẳng đường dẫn cũng không vào.
        $this->withSession($phien)->get('/admin/profile')->assertRedirect();

        // Người có cửa quản trị thì vẫn xem hồ sơ bình thường.
        $phienQL = $this->phienQuanTri();
        $phienQL['api.user']['access_areas'] = 'quan_ly,thu_ngan';
        $this->withSession($phienQL)->get('/cashier/sales')
            ->assertSee('Tài khoản của tôi', false);
    }

    /**
     * KHÔNG có cửa nào đứng được thì về trang ĐĂNG NHẬP, không đá qua khu kia.
     *
     * Đây là cái bẫy của hai chốt đối xứng: chốt khu quản trị đưa người thiếu cửa
     * sang quầy, chốt quầy đưa ngược lại — trình duyệt quay vòng tới khi tự bỏ
     * cuộc, và trên màn hình trông như trang bị treo chứ không phải bị từ chối.
     *
     * Dựng bằng một cửa LẠ (không phải quan_ly cũng không phải thu_ngan): đó đúng
     * là hình dạng của ngày thêm cửa thứ ba — người chỉ có cửa ấy phải rơi vào
     * nhánh này chứ không phải quay vòng giữa hai khu cũ.
     */
    public function test_khong_co_cua_nao_thi_ve_dang_nhap_chu_khong_quay_vong(): void
    {
        $this->fakeApi();

        $phien = $this->phienQuanTri();
        $phien['api.user']['access_areas'] = 'kho';

        $this->withSession($phien)->get('/admin/staff')
            ->assertRedirect(route('login'));

        $this->withSession($phien)->get('/cashier/sales')
            ->assertRedirect(route('login'));
    }

    /**
     * Phiên CŨ chưa mang `access_areas` (đăng nhập từ trước lượt triển khai) thì
     * suy từ vai trò như hệ thống vẫn hành xử — không ai bị đá ra ngoài giữa ca
     * chỉ vì phần mềm vừa được cập nhật.
     */
    public function test_phien_cu_khong_co_cua_thi_suy_tu_vai(): void
    {
        $this->fakeApi();

        // phienQuanTri() cố ý KHÔNG có khoá access_areas.
        $this->withSession($this->phienQuanTri())->get('/cashier/sales')
            ->assertSuccessful();
    }

    /**
     * Ảnh nhân viên: tải lên TRƯỚC, hồ sơ chỉ mang theo đường dẫn.
     *
     * Tách hai lượt có chủ ý — bấm Lưu mà hỏng (thiếu ô bắt buộc, API từ chối) thì
     * ảnh vẫn nằm đó, không bắt người dùng chọn lại. API cũng chỉ cất chuỗi, không
     * ôm tệp: xem migration 0016.
     */
    public function test_anh_tai_len_truoc_roi_ho_so_mang_duong_dan(): void
    {
        $this->fakeApi();
        \Illuminate\Support\Facades\Storage::fake('public');

        $tai = $this->withSession($this->phienQuanTri())->post('/admin/staff/photo', [
            'anh' => \Illuminate\Http\UploadedFile::fake()->image('an.jpg', 400, 400),
        ])->assertOk();

        $duong = $tai->json('url');
        $this->assertNotEmpty($duong, 'Lượt tải ảnh phải trả về đường dẫn');

        $this->withSession($this->phienQuanTri())->post('/admin/staff', [
            'full_name' => 'Nguyễn Văn An',
            'status' => 'dang_lam',
            'shop_id' => '3',
            'avatar' => $duong,
        ])->assertRedirect(route('admin.nhan-su.index'));

        Http::assertSent(function ($request) use ($duong) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/admin/nhan-su')
                && ($request->data()['avatar'] ?? null) === $duong;
        });
    }

    /**
     * Mỗi dòng có nút XEM CHI TIẾT, dựng từ dữ liệu đã có trong trang.
     *
     * Bảng cố ý chỉ còn bảy cột, nên căn cước, địa chỉ, lương và ghi chú không nằm
     * ngoài đó nữa — phải tra được mà không bắt người xem mở form sửa.
     *
     * KHÔNG có hộp thoại thứ hai: nút này mở CHÍNH hộp thêm/sửa rồi khoá lại. Hai
     * hộp riêng thì từ hôm sau chúng trôi khỏi nhau — thêm một ô vào form thì hộp
     * xem thiếu ô đó, mà không có gì báo.
     *
     * Khẳng định luôn rằng nó KHÔNG gọi thêm API: dữ liệu đã nhúng sẵn trong dòng,
     * một lượt gọi nữa thì mở hộp phải chờ.
     */
    public function test_moi_dong_co_nut_xem_chi_tiet(): void
    {
        $this->fakeApi([[
            'id' => 12, 'code' => 'NV0001', 'full_name' => 'Nguyễn Văn An',
            'status' => 'dang_lam', 'id_number' => '079200001234',
            'address' => '12 Lê Lợi', 'salary' => 8000000,
            'work_shift' => 'sang,chieu', 'quyen' => ['thu_ngan'], 'username' => 'an.nv',
        ]]);

        $res = $this->withSession($this->phienQuanTri())->get('/admin/staff')->assertOk();

        // Nút con mắt trên dòng, mang theo nguyên hồ sơ.
        $res->assertSee('data-nsu-xem', false);
        $res->assertSee('Xem chi tiết', false);
        // KHÔNG có hộp thoại thứ hai — chỉ có đúng một hộp thêm/sửa cho cả trang.
        $res->assertSee('id="nsuOverlay"', false);
        $this->assertSame(1, substr_count($res->getContent(), 'id="nsuForm"'));
        // Mấy ô đã bỏ khỏi bảng vẫn có mặt trong dữ liệu nhúng để hộp xem dựng lên.
        $res->assertSee('079200001234', false);
        $res->assertSee('12 Lê Lợi', false);

        // Mở trang chỉ tốn đúng những lượt gọi vốn có; nút xem không thêm lượt nào.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/admin/nhan-su/12'));
    }

    /**
     * Xuất Excel: mang theo ĐÚNG bộ lọc đang xem, và KHÔNG mang theo lương.
     *
     * Hai vế, hai lý do khác nhau. Lọc: người ta lọc ra một nhóm rồi mới bấm xuất —
     * trả về cả bảng thì họ phải lọc lại trong Excel, và cái thanh lọc phía trên
     * thành ra nói dối. Lương: tệp này RỜI KHỎI phần mềm (gửi Zalo, để trên máy
     * dùng chung), nên mức lương cả cửa hàng nằm trong đó là chuyện khác hẳn với
     * việc nó nằm sau một lượt đăng nhập.
     */
    public function test_xuat_excel_theo_bo_loc_va_khong_kem_luong(): void
    {
        $this->fakeApi([[
            'id' => 12, 'code' => 'NV0001', 'full_name' => 'Nguyễn Văn An',
            'status' => 'dang_lam', 'phone' => '0912345678', 'salary' => 8000000,
            'work_shift' => 'sang,chieu', 'quyen' => ['thu_ngan'], 'username' => 'an.nv',
        ]]);

        $csv = $this->withSession($this->phienQuanTri())
            ->get(route('admin.nhan-su.export', ['work_shift' => 'sang']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('NV0001', $csv);
        $this->assertStringContainsString('Nguyễn Văn An', $csv);
        // Ca ghi bằng nhãn tiếng Việt, không phải chuỗi thô của cột SET.
        $this->assertStringContainsString('Ca sáng, Ca chiều', $csv);
        $this->assertStringNotContainsString('sang,chieu', $csv);
        // BOM: thiếu nó thì Excel trên Windows đọc tiếng Việt thành ký tự lạ.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        // Không có cột lương, và cũng không có con số lương lọt vào dòng nào.
        $this->assertStringNotContainsString('Lương', $csv);
        $this->assertStringNotContainsString('8000000', $csv);

        // Bộ lọc đi thẳng sang API, không rơi mất ở giữa.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/nhan-su')
            && str_contains($request->url(), 'work_shift=sang'));
    }

    /**
     * Hàng loạt: mỗi hồ sơ một lượt gọi API, và ô tick KHÔNG bọc bảng lại.
     *
     * Vế thứ hai là chuyện HTML: mỗi dòng đã có form riêng cho công tắc trạng thái
     * và nút xoá, mà form lồng form thì trình duyệt bỏ cái bên trong — hai nút ấy
     * chết lặng. Ô tick nối vào form hàng loạt bằng thuộc tính `form=`.
     */
    public function test_hang_loat_goi_tung_ho_so_va_khong_long_form(): void
    {
        $this->fakeApi([
            ['id' => 12, 'code' => 'NV0001', 'full_name' => 'Người A', 'status' => 'dang_lam'],
            ['id' => 13, 'code' => 'NV0002', 'full_name' => 'Người B', 'status' => 'dang_lam'],
        ]);

        $html = $this->withSession($this->phienQuanTri())
            ->get('/admin/staff')->assertOk()->getContent();

        $this->assertStringContainsString('id="nsuBulkForm"', $html);
        $this->assertStringContainsString('form="nsuBulkForm"', $html);
        $this->assertStringContainsString('id="nsuChonHet"', $html);

        // Đánh dấu nghỉ việc hai hồ sơ -> hai lượt gọi trạng thái.
        $this->withSession($this->phienQuanTri())
            ->post(route('admin.nhan-su.bulkTrangThai'), ['ids' => [12, 13], 'status' => 'da_nghi'])
            ->assertRedirect(route('admin.nhan-su.index'))
            ->assertSessionHas('success');

        foreach ([12, 13] as $id) {
            Http::assertSent(fn ($request) => $request->method() === 'PUT'
                && str_contains($request->url(), "/admin/nhan-su/{$id}/trang-thai")
                && ($request->data()['status'] ?? null) === 'da_nghi');
        }
    }

    /**
     * Hỏng MỘT PHẦN thì báo cả hai vế, kèm LÝ DO của phần hỏng.
     *
     * Chỉ đếm ("3 hồ sơ không xoá được") thì người dùng chọn lại y hệt rồi bấm lần
     * nữa, và lại 3 hồ sơ không xoá được. Câu từ chối của API đã nói rõ phải làm gì
     * — việc của lượt bulk là chuyển nguyên câu đó ra, gom theo lý do.
     */
    public function test_hang_loat_hong_mot_phan_thi_bao_ly_do(): void
    {
        Http::fake([
            '*/admin/nhan-su/12' => Http::response([], 200),
            '*/admin/nhan-su/13' => Http::response(
                ['message' => 'Nhân viên này còn một ca chưa đóng.'], 409
            ),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phienQuanTri())
            ->post(route('admin.nhan-su.bulkDestroy'), ['ids' => [12, 13]])
            ->assertRedirect(route('admin.nhan-su.index'));

        $cau = session('error');
        $this->assertNotEmpty($cau, 'Hỏng một phần phải báo bằng error, không phải nền xanh');
        $this->assertStringContainsString('1 hồ sơ', $cau);
        $this->assertStringContainsString('còn một ca chưa đóng', $cau,
            'Phải chuyển nguyên lý do của API ra, không nuốt mất');
    }

    /** Không chọn hồ sơ nào thì chặn ngay tại form, không gọi API lượt nào. */
    public function test_hang_loat_khong_chon_gi_thi_chan(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->post(route('admin.nhan-su.bulkDestroy'), ['ids' => []])
            ->assertSessionHasErrors('ids');

        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    /** Bộ lọc chuyển thẳng sang query của API, không đổi tên khoá ở giữa. */
    public function test_bo_loc_gui_dung_query_len_api(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->get('/admin/staff?keyword=an&status=dang_lam&work_shift=sang&shop_id=3')
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/nhan-su')) {
                return false;
            }

            return str_contains($request->url(), 'keyword=an')
                && str_contains($request->url(), 'status=dang_lam')
                && str_contains($request->url(), 'work_shift=sang')
                && str_contains($request->url(), 'shop_id=3');
        });
    }

    /** Thêm nhân viên: payload gửi lên API đúng tên trường, không kèm khối tài khoản. */
    public function test_them_nhan_vien_goi_api(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->post('/admin/staff', [
            'full_name' => 'Nguyễn Văn An',
            'status' => 'dang_lam',
            'shop_id' => '3',
            'phone' => '0912345678',
            'salary' => '8000000',
            'work_shift' => ['sang', 'chieu'],
        ]);

        $res->assertRedirect(route('admin.nhan-su.index'));
        $res->assertSessionHas('success');

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/admin/nhan-su')) {
                return false;
            }
            $body = $request->data();

            return $body['full_name'] === 'Nguyễn Văn An'
                // Một người trực nhiều ca: gửi lên nguyên mảng, service ghép thành
                // chuỗi cho cột SET. Chức danh KHÔNG còn gửi — màn hình đã bỏ ô đó.
                && $body['work_shift'] === ['sang', 'chieu']
                && ! array_key_exists('position', $body)
                && $body['shop_id'] === 3
                && $body['salary'] === 8000000.0
                && ! array_key_exists('tai_khoan', $body);
        });
    }

    /**
     * Bật công tắc cấp tài khoản thì payload mang thêm khối `tai_khoan` với tên
     * đăng nhập hạ chữ thường như Go chuẩn hoá.
     *
     * Quyền KHÔNG nằm trong khối đó mà đứng riêng ở `role_id`, đọc từ ô tick
     * "Phân quyền" của tab Chi tiết (tick Thu ngân -> 3).
     */
    public function test_cap_tai_khoan_gui_kem_khoi_tai_khoan(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/staff', [
            'full_name' => 'Nguyễn Văn An',
            'status' => 'dang_lam',
            'shop_id' => '3',
            'email' => 'an@cua-hang.test',
            'co_tai_khoan' => '1',
            'quyen' => ['3'],
            'username' => 'An.NV',
            'password' => 'MatKhau@123',
        ])->assertRedirect(route('admin.nhan-su.index'));

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/admin/nhan-su')) {
                return false;
            }
            $body = $request->data();
            $tk = $body['tai_khoan'] ?? null;

            return is_array($tk) && $tk['username'] === 'an.nv'
                && ! array_key_exists('role_id', $tk)
                && $body['role_id'] === 3;
        });
    }

    /**
     * TICK CẢ HAI VAI quy về Quản lý — cột "Phân quyền" cho tick nhiều ô như
     * `account_type[]` của order v2, nhưng dưới database chỉ có một `role_id`.
     *
     * Quy đổi được vì hai vai LỒNG NHAU: `admin` đi qua cả khu quản trị lẫn quầy
     * bán, `staff` chỉ có quầy. Nên "vừa quản lý vừa thu ngân" chính là Quản lý,
     * không mất quyền nào. Đây là bài kiểm giữ đúng chỗ quy đổi đó.
     */
    public function test_tick_ca_hai_vai_quy_ve_quan_ly(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/staff', [
            'full_name' => 'Nguyễn Văn An',
            'status' => 'dang_lam',
            'shop_id' => '3',
            'email' => 'an@cua-hang.test',
            'co_tai_khoan' => '1',
            'username' => 'an.nv',
            'quyen' => ['3', '2'],
        ])->assertRedirect(route('admin.nhan-su.index'));

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/admin/nhan-su')
                && ($request->data()['role_id'] ?? null) === 2;
        });
    }

    /**
     * Cấp tài khoản mà KHÔNG tick vai nào thì form chặn lại.
     *
     * Không chặn thì API nhận role_id = 0 và dựng ra một tài khoản đăng nhập được
     * nhưng không mở được cửa nào — người dùng gõ đúng mật khẩu rồi đứng trước một
     * màn hình trắng, không có gì trên đó nói cho họ biết vì sao.
     */
    public function test_cap_tai_khoan_phai_tick_quyen(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/staff', [
            'full_name' => 'Nguyễn Văn An',
            'status' => 'dang_lam',
            'shop_id' => '3',
            'email' => 'an@cua-hang.test',
            'co_tai_khoan' => '1',
            'username' => 'an.nv',
        ])->assertSessionHasErrors('quyen');
    }

    /**
     * ĐỔI Ô TICK cho hồ sơ đã có tài khoản = đổi quyền của tài khoản đó.
     *
     * Lượt sửa mang theo `role_id` mới nhưng KHÔNG mang khối `tai_khoan` (API từ
     * chối cấp cái thứ hai) — bên kia hiểu đó là lệnh đổi vai trò.
     *
     * Đây là lượt mà trước đây im lặng không làm gì: màn hình báo "đã cập nhật"
     * còn huy hiệu phân quyền ngoài bảng vẫn nguyên như cũ.
     */
    public function test_doi_o_tick_doi_luon_quyen(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/staff/12', [
            'full_name' => 'Nguyễn Văn An',
            'status' => 'dang_lam',
            'shop_id' => '3',
            'email' => 'an@cua-hang.test',
            // Thu ngân cũ được cất nhắc: tick thêm cửa quản trị.
            'quyen' => ['2'],
        ])->assertRedirect(route('admin.nhan-su.index'));

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/admin/nhan-su/12')) {
                return false;
            }
            $body = $request->data();

            return ($body['role_id'] ?? null) === 2 && ! array_key_exists('tai_khoan', $body);
        });
    }

    public function test_form_kiem_du_lieu(): void
    {
        $this->fakeApi();

        $hoSo = [
            'full_name' => 'Nguyễn Văn An',
            'status' => 'dang_lam', 'shop_id' => '3',
        ];

        $this->withSession($this->phienQuanTri())
            ->post('/admin/staff', ['status' => 'dang_lam', 'shop_id' => '3'])
            ->assertSessionHasErrors('full_name');

        // Chi nhánh bắt buộc: hồ sơ không khai nơi làm việc thì bảng chấm công và
        // báo cáo theo chi nhánh sau này không xếp người đó vào đâu được.
        $this->withSession($this->phienQuanTri())
            ->post('/admin/staff', ['full_name' => 'Nguyễn Văn An', 'status' => 'dang_lam'])
            ->assertSessionHasErrors('shop_id');

        // Ca lạ không được lọt xuống cột SET của API.
        $this->withSession($this->phienQuanTri())
            ->post('/admin/staff', array_replace($hoSo, ['work_shift' => ['ca_dem']]))
            ->assertSessionHasErrors('work_shift.0');

        // "Cả ngày" đã gồm sáng và chiều nên không đứng chung với hai ca kia. Màn
        // hình khoá ô lại, nhưng một lượt POST dựng tay thì không đi qua JS nào —
        // để lọt thì cột chứa "sang,ca_ngay", một chuỗi không trả lời được câu hỏi
        // đơn giản nhất của bảng chấm công: người này trực mấy buổi?
        $this->withSession($this->phienQuanTri())
            ->post('/admin/staff', array_replace($hoSo, ['work_shift' => ['sang', 'ca_ngay']]))
            ->assertSessionHasErrors('work_shift');

        // Một mình "cả ngày" thì bình thường.
        $this->withSession($this->phienQuanTri())
            ->post('/admin/staff', array_replace($hoSo, ['work_shift' => ['ca_ngay']]))
            ->assertSessionHasNoErrors();

        // Cấp tài khoản: thiếu tên đăng nhập, email hay ô tick quyền đều bị chặn
        // ngay tại form.
        $this->withSession($this->phienQuanTri())
            ->post('/admin/staff', $hoSo + ['co_tai_khoan' => '1'])
            ->assertSessionHasErrors(['username', 'email', 'quyen']);

        // Không bật công tắc: hai ô đó rỗng vẫn qua.
        $this->withSession($this->phienQuanTri())
            ->post('/admin/staff', $hoSo + ['username' => '', 'email' => ''])
            ->assertSessionHasNoErrors();

        // Tên đăng nhập có khoảng trắng thì chặn ngay tại form.
        $this->withSession($this->phienQuanTri())
            ->post('/admin/staff', $hoSo + [
                'co_tai_khoan' => '1', 'username' => 'an nv', 'email' => 'an@x.test',
            ])
            ->assertSessionHasErrors('username');
    }

    /**
     * Công tắc trạng thái trên bảng gửi đúng MỘT trường sang đường riêng của API.
     *
     * Nếu nó đi qua đường sửa hồ sơ thì một cú gạt sẽ mang theo cả bản dữ liệu cũ
     * đang nằm trên màn hình và ghi đè những gì người khác vừa sửa.
     */
    public function test_cong_tac_trang_thai_goi_duong_rieng(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->put('/admin/staff/12/status', ['status' => 'da_nghi']);

        $res->assertRedirect(route('admin.nhan-su.index'));
        $res->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/admin/nhan-su/12/trang-thai')
                && $request->data() === ['status' => 'da_nghi', 'mo_tai_khoan' => false];
        });

        // Trạng thái lạ thì chặn ngay ở Shop Admin, không phiền tới API.
        $this->withSession($this->phienQuanTri())
            ->put('/admin/staff/12/status', ['status' => 'nghi_choi'])
            ->assertSessionHasErrors('status');
    }

    /**
     * Nhận người cũ làm lại: câu trả lời cho lượt hỏi "mở lại tài khoản?" phải đi
     * xuống API nguyên vẹn.
     *
     * Việc KHOÁ thì API tự làm khi nhận `da_nghi` — Shop Admin không quyết định gì
     * ở chiều đó. Chỉ chiều mở lại mới cần câu trả lời của người bấm, và mặc định
     * là KHÔNG mở: quên hỏi thì tài khoản vẫn khoá, chứ không mở toang.
     */
    public function test_mo_lai_tai_khoan_gui_kem_cau_tra_loi(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->put('/admin/staff/12/status', ['status' => 'dang_lam', 'mo_tai_khoan' => '1'])
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/admin/nhan-su/12/trang-thai')
                && $request->data() === ['status' => 'dang_lam', 'mo_tai_khoan' => true];
        });

        // Không trả lời = không mở.
        $this->withSession($this->phienQuanTri())
            ->put('/admin/staff/12/status', ['status' => 'dang_lam'])
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->data() === ['status' => 'dang_lam', 'mo_tai_khoan' => false];
        });
    }

    /**
     * Danh sách phải nói ra tài khoản đang KHOÁ. Hai cột tách nhau: hồ sơ có thể
     * "đang làm" trong khi tài khoản vẫn khoá (nhận lại người cũ mà chưa mở), và
     * không nói ra thì dòng đó trông y hệt một người đăng nhập được bình thường.
     */
    public function test_danh_sach_noi_ro_tai_khoan_dang_khoa(): void
    {
        $this->fakeApi([[
            'id' => 12,
            'code' => 'NV0001',
            'full_name' => 'Nguyễn Văn An',
            'position' => 'thu_ngan',
            'status' => 'da_nghi',
            'username' => 'an.nv',
            'user_status' => 'inactive',
        ]]);

        $this->withSession($this->phienQuanTri())->get('/admin/staff')
            ->assertOk()
            ->assertSee('đã khoá', false);
    }

    /**
     * Mọi lượt hỏi trên trang đi qua HỘP CỦA TRANG, không phải confirm() của
     * trình duyệt.
     *
     * Ba câu hỏi ở đây đều phải nói ra hậu quả — tài khoản nào sắp bị khoá,
     * ai còn đăng nhập được bằng mật khẩu cũ — mà hộp xám của trình duyệt thì
     * dán tên miền lên đầu, không tách được hai đoạn chữ, và hai nút của nó
     * trông y hệt nhau đúng lúc cần nhìn ra nút nào là nút nguy hiểm.
     */
    public function test_hoi_bang_hop_thoai_cua_trang(): void
    {
        $this->fakeApi([[
            'id' => 12,
            'code' => 'NV0001',
            'full_name' => 'Nguyễn Văn An',
            'position' => 'thu_ngan',
            'status' => 'dang_lam',
            'username' => 'an.nv',
            'user_status' => 'active',
        ]]);

        $res = $this->withSession($this->phienQuanTri())->get('/admin/staff');

        $res->assertOk();
        $res->assertSee('id="nsuConfirm"', false);
        // Không còn ô phân quyền thứ hai: chức danh là chỗ duy nhất phân quyền.
        $res->assertDontSee('name="role_id"', false);
        // Xác nhận xoá cũng đi qua hộp đó: hai kiểu hộp thoại trên cùng một
        // màn hình thì cái nào cũng trông như của người khác.
        $res->assertDontSee('onsubmit="return confirm', false);
        $res->assertSee('data-nsu-xoa', false);
    }

    /** API từ chối thì in nguyên câu của nó ra, không nuốt thành "thao tác không thành công". */
    public function test_loi_tu_api_hien_nguyen_van(): void
    {
        Http::fake([
            '*/admin/nhan-su*' => Http::response(
                ['message' => 'Mã này đã có nhân viên khác dùng, vui lòng đặt mã khác'],
                422
            ),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phienQuanTri())
            ->post('/admin/staff', [
                'full_name' => 'Nguyễn Văn An', 'position' => 'thu_ngan', 'status' => 'dang_lam',
                'shop_id' => '3', 'code' => 'NV0001',
            ])
            ->assertSessionHas('error', 'Mã này đã có nhân viên khác dùng, vui lòng đặt mã khác');
    }

    /**
     * Trang "Người dùng & vai trò" đã bỏ khỏi điều hướng: chủ tiệm quản lý NHÂN
     * VIÊN, việc cấp tài khoản là một khối trong hồ sơ.
     */
    public function test_khong_con_loi_vao_trang_nguoi_dung(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->get('/admin/staff')
            ->assertDontSee('/admin/users', false);
    }

    /**
     * Thu ngân KHÔNG vào được: hồ sơ nhân sự có lương và số căn cước của đồng
     * nghiệp. Cùng mức riêng tư với Chi nhánh.
     */
    public function test_thu_ngan_khong_vao_duoc(): void
    {
        $this->fakeApi();

        $phien = $this->phienQuanTri();
        $phien['api.user'] = ['id' => 9, 'full_name' => 'Thu ngân', 'role' => ['name' => 'staff']];

        $res = $this->withSession($phien)->get('/admin/staff');

        $this->assertContains($res->getStatusCode(), [302, 403]);
    }

    /**
     * Mỗi cột khai bề rộng theo %, cộng lại ĐÚNG 100.
     *
     * Bỏ trống một cột là cột đó nuốt hết phần dư, các cột còn lại dồn cục lại
     * một bên — bảng hở ra một khoảng chết. Cộng quá 100 thì bảng tràn, cộng
     * thiếu thì cột cuối phình ra.
     */
    public function test_cot_chia_theo_phan_tram_cong_du_100(): void
    {
        $this->fakeApi([
            ['id' => 12, 'code' => 'NV0001', 'full_name' => 'Người thứ nhất', 'status' => 'dang_lam'],
        ]);

        $html = $this->withSession($this->phienQuanTri())
            ->get('/admin/staff')->assertOk()->getContent();

        // Bề rộng cột lấy từ CSS, không nhét style thẳng vào từng ô.
        preg_match_all('/td\.(nsu-c-[a-z]+)\s*\{\s*width:\s*([0-9.]+)%/', $html, $m);
        $rong = array_combine($m[1], array_map('floatval', $m[2]));

        // Đủ mười cột của bảng, không sót cột nào.
        preg_match_all('/<th class="(nsu-c-[a-z]+)"/', $html, $cot);
        $this->assertCount(10, $cot[1]);
        foreach ($cot[1] as $lop) {
            $this->assertArrayHasKey($lop, $rong, "Cột {$lop} chưa khai bề rộng");
        }

        $this->assertSame(100.0, array_sum($rong));
    }

    /** Bảng canh giữa và chia cột cố định — cùng khuôn với các trang danh sách khác. */
    public function test_bang_canh_giua_va_chia_cot_co_dinh(): void
    {
        $this->fakeApi([
            ['id' => 12, 'code' => 'NV0001', 'full_name' => 'Người thứ nhất', 'status' => 'dang_lam'],
        ]);

        $html = $this->withSession($this->phienQuanTri())
            ->get('/admin/staff')->assertOk()->getContent();

        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('padding: 14px 10px; vertical-align: middle; text-align: center;', $html);
    }
}
