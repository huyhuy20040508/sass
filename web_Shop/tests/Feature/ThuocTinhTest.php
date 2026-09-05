<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hàng hóa → Thuộc tính.
 *
 * API giả lập (Http::fake) nên bài này chạy được cả khi không có Go API. Phần
 * nghiệp vụ thật (đồng bộ danh sách giá trị, trùng mã/tên, cô lập giữa hai cửa
 * hàng) do api/internal/apitest kiểm trên MySQL thật; bài này chỉ gác phần của
 * trang quản trị: bảng dựng đúng, bộ lọc chạy ở đâu, và payload gửi đi có đúng
 * hình dạng API cần không.
 */
class ThuocTinhTest extends TestCase
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
            '*/admin/thuoc-tinh*' => Http::response(['data' => [
                [
                    'id' => 1, 'code' => 'SIZE', 'name' => 'Kích cỡ',
                    'is_active' => true, 'in_use' => true,
                    'values' => [
                        ['id' => 11, 'code' => 'SIZE01', 'name' => 'Nhỏ'],
                        ['id' => 12, 'code' => 'SIZE02', 'name' => 'Lớn'],
                    ],
                ],
                [
                    'id' => 2, 'code' => 'MAU', 'name' => 'Màu sắc',
                    'is_active' => false, 'in_use' => false,
                    'values' => [],
                ],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Bảng bày mã, tên và các giá trị con. */
    public function test_hien_bang_thuoc_tinh(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/attributes');

        $res->assertOk();
        $res->assertSee('Kích cỡ', false);
        $res->assertSee('SIZE', false);
        // Giá trị con bày thành chip ngay trên dòng.
        $res->assertSee('Nhỏ', false);
        $res->assertSee('Lớn', false);
        // Cột Chi tiết gộp tên các giá trị con thành một chuỗi.
        $res->assertSee('Nhỏ, Lớn', false);
    }

    /** Dòng đang được dùng thì nút xoá bị khoá, không cho bấm rồi mới báo lỗi. */
    public function test_dong_dang_duoc_dung_thi_khoa_nut_xoa(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/attributes')->getContent();

        $this->assertStringContainsString('Đang được biến thể hoặc định lượng dùng', $html);
        // Dòng không bị dùng vẫn còn nút xoá bấm được (JS đọc data-id của dòng).
        $this->assertStringContainsString('dele_bt delete-item', $html);
    }

    /** Lọc trạng thái và lọc cờ định lượng làm ở trang, không phiền tới API. */
    public function test_loc_trang_thai(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/attributes?status=inactive');
        $res->assertOk();
        $res->assertSee('Màu sắc', false);
        $res->assertDontSee('data-tt-edit data-tt="{&quot;id&quot;:1', false);
    }

    /** Ô tìm kiếm thì gửi thẳng sang API — API tìm trên toàn danh sách. */
    public function test_tu_khoa_gui_sang_api(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->get('/admin/attributes?keyword=size')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'keyword=size'));
    }

    /** Thêm: payload mang cả danh sách giá trị, dòng bỏ trống tên bị loại. */
    public function test_them_gui_dung_hinh_dang_payload(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->post('/admin/attributes', [
            'code' => 'size',
            'name' => 'Kích cỡ',
            'is_active' => 1,
            'values' => [
                ['id' => '', 'code' => 's', 'name' => 'Nhỏ'],
                // Dòng người dùng bấm thêm rồi để đấy — không gửi sang API.
                ['id' => '', 'code' => '', 'name' => '  '],
                ['id' => '', 'code' => '', 'name' => 'Lớn'],
            ],
        ]);

        $res->assertRedirect('/admin/attributes');
        $res->assertSessionHas('success');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/thuoc-tinh') || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return $body['code'] === 'SIZE'
                && count($body['values']) === 2
                && $body['values'][0]['code'] === 'S'
                && $body['values'][1]['name'] === 'Lớn'
                // Dòng mới KHÔNG mang khoá id.
                && ! array_key_exists('id', $body['values'][0]);
        });
    }

    /** Sửa: dòng cũ giữ id, dòng mới không có — API dựa vào đó để đồng bộ. */
    public function test_sua_giu_id_cua_dong_cu(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->put('/admin/attributes/1', [
            'name' => 'Kích cỡ',
            'is_active' => 1,
            'values' => [
                ['id' => '11', 'code' => 'SIZE01', 'name' => 'Cỡ nhỏ'],
                ['id' => '', 'code' => '', 'name' => 'Siêu lớn'],
            ],
        ]);

        $res->assertRedirect('/admin/attributes');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/thuoc-tinh/1') || $request->method() !== 'PUT') {
                return false;
            }
            $body = $request->data();

            return $body['values'][0]['id'] === 11
                && $body['values'][0]['name'] === 'Cỡ nhỏ'
                && ! array_key_exists('id', $body['values'][1]);
        });
    }

    /** Mã có khoảng trắng bị chặn ngay ở form, không phiền tới API. */
    public function test_ma_co_ky_tu_la_bi_chan(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->post('/admin/attributes', [
            'code' => 'SIZE 2',
            'name' => 'Kích cỡ',
        ]);

        $res->assertSessionHasErrors('code');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    /** Công tắc gửi ĐÚNG một trường sang đường trạng thái. */
    public function test_cong_tac_chi_gui_trang_thai(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->put('/admin/attributes/1/status', [
            'is_active' => 0,
        ]);

        $res->assertRedirect('/admin/attributes');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/thuoc-tinh/1/trang-thai')) {
                return false;
            }

            return $request->data() === ['is_active' => false];
        });
    }

    /** Xoá nhiều dòng: API chỉ có đường xoá một dòng nên gọi lần lượt. */
    public function test_xoa_nhieu_dong_goi_lan_luot(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->post('/admin/attributes/bulk-destroy', ['ids' => [1, 2, 2]]);

        $res->assertRedirect('/admin/attributes');
        $res->assertSessionHas('success');

        // Id trùng bị gộp lại: đúng hai lượt xoá chứ không phải ba.
        $daXoa = collect(Http::recorded())
            ->map(fn ($cap) => $cap[0])
            ->filter(fn ($req) => $req->method() === 'DELETE')
            ->map(fn ($req) => $req->url())
            ->values();

        $this->assertCount(2, $daXoa);
        $this->assertStringEndsWith('/admin/thuoc-tinh/1', $daXoa[0]);
        $this->assertStringEndsWith('/admin/thuoc-tinh/2', $daXoa[1]);
    }

    /** API hỏng thì trang vẫn mở được, kèm câu báo lỗi. */
    public function test_api_hong_van_mo_duoc_trang(): void
    {
        Http::fake(['*' => Http::response(['message' => 'API sập'], 500)]);

        $res = $this->withSession($this->phienQuanTri())->get('/admin/attributes');

        $res->assertOk();
        $res->assertSee('API sập', false);
    }

    /** Xoá một thuộc tính. */
    public function test_xoa_mot_thuoc_tinh(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->delete('/admin/attributes/2');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/thuoc-tinh/2')
            && $request->method() === 'DELETE');
    }

    /**
     * Lưu xong quay về ĐÚNG trang đang đứng.
     *
     * Trang gửi kèm `return`; bỏ qua nó là mỗi lượt Lưu lại ném người dùng về
     * trang 1 chưa lọc — đang sửa dở dòng thứ 40 thì phải lọc và lật lại từ đầu.
     */
    public function test_luu_xong_ve_dung_trang_dang_dung(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->put('/admin/attributes/1', [
            'name' => 'Kích cỡ',
            'is_active' => 1,
            'return' => '/admin/attributes?keyword=size&page=3',
        ]);

        $res->assertRedirect('/admin/attributes?keyword=size&page=3');
    }

    /** `return` đến từ trình duyệt: chỉ nhận đường nội bộ, không nhận đường ngoài. */
    public function test_khong_nhan_duong_ve_ra_ngoai(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->put('/admin/attributes/1', [
            'name' => 'Kích cỡ',
            'is_active' => 1,
            'return' => 'https://trang-la.example/cuop-phien',
        ]);

        $res->assertRedirect(route('admin.thuoc-tinh.index'));
    }

    /**
     * Lưu HỎNG từ hộp thoại thì trả 422 JSON, KHÔNG chuyển hướng.
     *
     * Hộp thoại đọc `success` để quyết định đóng hay giữ lại. Trả chuyển hướng
     * là trang tải lại, hộp biến mất rồi toast mới hiện — mất trắng mọi thứ vừa
     * gõ và người dùng phải khai lại từ đầu mới biết mình sai chỗ nào.
     */
    public function test_luu_hong_tu_hop_thoai_tra_422_khong_chuyen_huong(): void
    {
        Http::fake([
            '*/admin/thuoc-tinh' => Http::response([
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => ['name' => 'Tên thuộc tính này đã có trong cửa hàng'],
            ], 422),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phienQuanTri())
            ->postJson('/admin/attributes', ['name' => 'Trung ten', 'is_active' => 1]);

        $res->assertStatus(422);
        $res->assertJson(['success' => false]);
        // Lấy câu theo TỪNG Ô chứ không lấy `message` chung chung.
        $res->assertJsonPath('message', 'Tên thuộc tính này đã có trong cửa hàng');
    }

    /** Lưu ĐƯỢC thì trả 200 kèm câu để bắn toast xanh rồi đóng hộp. */
    public function test_luu_xong_tu_hop_thoai_tra_200(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->postJson('/admin/attributes', ['name' => 'Trung ten', 'is_active' => 1]);

        $res->assertOk();
        $res->assertJson(['success' => true]);
    }
}
