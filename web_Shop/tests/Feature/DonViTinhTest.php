<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hàng hóa → Đơn vị tính: đủ vòng CRUD.
 *
 * API giả lập (Http::fake) nên bài này chạy được cả khi không bật Go API. Phần
 * nghiệp vụ thật (trùng mã, đang gắn vào mặt hàng thì không cho xoá) do
 * api/internal/apitest gác trên MySQL thật; bài này gác ĐƯỜNG DÂY của trang
 * quản trị: mỗi nút trên màn đi tới đúng đường API nào, mang theo hình dạng gì.
 */
class DonViTinhTest extends TestCase
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
            '*/admin/don-vi-tinh*' => Http::response(['data' => [
                ['id' => 1, 'code' => 'CAI', 'name' => 'Cái', 'is_active' => true, 'in_use' => true],
                ['id' => 2, 'code' => 'THUNG', 'name' => 'Thùng', 'is_active' => false, 'in_use' => false],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Đọc: bảng bày mã và tên lấy từ API. */
    public function test_bang_hien_don_vi_tu_api(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/units');

        $res->assertOk();
        $res->assertSee('CAI', false);
        $res->assertSee('Thùng', false);
    }

    /** Thêm: mã viết hoa trước khi gửi, đúng luật của bảng. */
    public function test_them_don_vi(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/units', [
            'code' => 'hop',
            'name' => '  Hộp  ',
            'is_active' => 1,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/don-vi-tinh') || $request->method() !== 'POST') {
                return false;
            }

            return $request->data() === ['code' => 'HOP', 'name' => 'Hộp', 'is_active' => true];
        });
    }

    /** Sửa: đi bằng PUT tới đúng dòng. */
    public function test_sua_don_vi(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/units/2', [
            'code' => 'THUNG',
            'name' => 'Thùng carton',
            'is_active' => 1,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/don-vi-tinh/2')
            && $request->method() === 'PUT'
            && $request->data()['name'] === 'Thùng carton');
    }

    /** Tên bỏ trống thì chặn ngay tại trang, không làm phiền API. */
    public function test_thieu_ten_thi_khong_goi_api(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->from('/admin/units')
            ->post('/admin/units', ['code' => 'XX', 'name' => '']);

        $res->assertSessionHasErrors('name');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/admin/don-vi-tinh'));
    }

    /** Công tắc trên bảng: chỉ gửi cờ, đi đường trạng thái riêng. */
    public function test_cong_tac_doi_trang_thai(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/units/1/status', ['is_active' => 0]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/don-vi-tinh/1/trang-thai')
            && $request->method() === 'PUT'
            && $request->data() === ['is_active' => false]);
    }

    /** Xoá một dòng. */
    public function test_xoa_mot_don_vi(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->delete('/admin/units/2');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/don-vi-tinh/2')
            && $request->method() === 'DELETE');
    }

    /**
     * Xoá nhiều: API chỉ có đường xoá một dòng nên trang gọi lần lượt. Bài này
     * canh ĐỦ số lượt gọi — gọi hụt một lượt là người dùng tick ba dòng mà chỉ
     * mất hai, nhìn không ra.
     */
    public function test_xoa_nhieu_don_vi_goi_tung_dong(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->post('/admin/units/bulk-destroy', ['ids' => [1, 2, 2]]);

        // Trùng id bị lọc trước khi gọi. Đếm riêng lượt DELETE chứ không đếm cả
        // bộ: mỗi lượt vào trang còn một lượt gọi của lớp xác thực.
        $soXoa = collect(Http::recorded())
            ->filter(fn ($cap) => $cap[0]->method() === 'DELETE'
                && str_contains($cap[0]->url(), '/admin/don-vi-tinh/'))
            ->count();
        $this->assertSame(2, $soXoa);
        foreach ([1, 2] as $id) {
            Http::assertSent(fn ($request) => str_contains($request->url(), "/admin/don-vi-tinh/{$id}")
                && $request->method() === 'DELETE');
        }
    }

    /** Chưa tick dòng nào thì nói ra, không gọi API. */
    public function test_xoa_nhieu_khi_chua_tick_gi(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->from('/admin/units')
            ->post('/admin/units/bulk-destroy', ['ids' => []]);

        $res->assertSessionHas('error');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
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
            '*/admin/don-vi-tinh' => Http::response([
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => ['name' => 'Tên đơn vị này đã có trong cửa hàng'],
            ], 422),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phienQuanTri())
            ->postJson('/admin/units', ['name' => 'Trung ten', 'code' => 'TT', 'is_active' => 1]);

        $res->assertStatus(422);
        $res->assertJson(['success' => false]);
        // Lấy câu theo TỪNG Ô chứ không lấy `message` chung chung.
        $res->assertJsonPath('message', 'Tên đơn vị này đã có trong cửa hàng');
    }

    /** Lưu ĐƯỢC thì trả 200 kèm câu để bắn toast xanh rồi đóng hộp. */
    public function test_luu_xong_tu_hop_thoai_tra_200(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->postJson('/admin/units', ['name' => 'Trung ten', 'code' => 'TT', 'is_active' => 1]);

        $res->assertOk();
        $res->assertJson(['success' => true]);
    }
}
