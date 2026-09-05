<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hàng hóa → Nhóm hàng hóa: đủ vòng CRUD, kể cả cụm nhóm con trong hộp thoại.
 *
 * API giả lập nên bài chạy được cả khi không bật Go API. Bài này gác ĐƯỜNG DÂY
 * của trang quản trị: mỗi nút đi tới đâu, mang hình dạng gì, và hai luật riêng
 * của màn — nhóm gốc cố định không sửa/xoá được, và hộp thoại lưu nhóm con bằng
 * nhiều lượt gọi chứ API không nhận cả cụm một lần.
 */
class NhomHangHoaTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    /** Hai nhóm gốc cố định + một nhóm thường + một nhóm con của nó. */
    protected function fakeApi(): void
    {
        $ds = [
            ['id' => 1, 'name' => 'Hàng bán', 'slug' => 'hang-ban', 'parent_id' => null, 'is_active' => true],
            ['id' => 2, 'name' => 'Hàng hóa khác', 'slug' => 'hang-hoa-khac', 'parent_id' => null, 'is_active' => true],
            ['id' => 5, 'name' => 'Đồ uống', 'slug' => 'do-uong', 'parent_id' => 1, 'is_active' => true],
            ['id' => 6, 'name' => 'Cà phê', 'slug' => 'ca-phe', 'parent_id' => 5, 'is_active' => true],
        ];

        // ĐỌC đi đường công khai `/categories`, GHI đi đường `/admin/categories`
        // — hai gốc khác nhau, giả lập lẫn là bảng ra rỗng mà không rõ vì sao.
        Http::fake([
            '*/v1/categories/5' => Http::response(['data' => $ds[2]]),
            '*/v1/categories/1' => Http::response(['data' => $ds[0]]),
            '*/v1/categories*' => Http::response(['data' => $ds]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Đọc: cây và bảng dựng từ danh sách phẳng của API. */
    public function test_trang_hien_cay_nhom(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/categories');

        $res->assertOk();
        // Cây dựng bằng JS từ danh sách nhúng trong trang, mà @json thoát tiếng
        // Việt ra dạng \uXXXX — so bằng chính json_encode chứ đừng gõ tay.
        $html = $res->getContent();
        $this->assertStringContainsString(json_encode('Đồ uống'), $html);
        $this->assertStringContainsString(json_encode('Cà phê'), $html);
    }

    /** Thêm: gửi tên + nhóm cha; mã do máy chủ tự sinh nên KHÔNG gửi slug. */
    public function test_them_nhom(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->post('/admin/categories', [
            'name' => 'Bánh ngọt',
            'parent_id' => 1,
            'is_active' => 1,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/categories') || $request->method() !== 'POST') {
                return false;
            }
            $d = $request->data();

            return $d['name'] === 'Bánh ngọt'
                && $d['parent_id'] === 1
                && ! array_key_exists('slug', $d);
        });
    }

    /**
     * Sửa: mã và ảnh KHÔNG có ô trên màn này, nên controller chuyển tiếp giá trị
     * cũ. Gửi rỗng là mỗi lượt đổi tên lại xoá mất mã nhóm.
     */
    public function test_sua_nhom_giu_lai_ma_cu(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/categories/5', [
            'name' => 'Đồ uống nóng',
            'parent_id' => 1,
            'is_active' => 1,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/categories/5') || $request->method() !== 'PUT') {
                return false;
            }
            $d = $request->data();

            return $d['name'] === 'Đồ uống nóng' && $d['slug'] === 'do-uong';
        });
    }

    /**
     * Nhóm con khai ngay trong hộp thoại nhóm cha. API không nhận cả cụm một
     * lượt nên trang gọi từng nhóm con — dòng có id thì PUT, dòng mới thì POST.
     */
    public function test_luu_kem_nhom_con(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/categories/5', [
            'name' => 'Đồ uống',
            'parent_id' => 1,
            'is_active' => 1,
            'children' => [
                ['id' => 6, 'name' => 'Cà phê sữa', 'is_active' => 1],
                ['id' => '', 'name' => 'Trà', 'is_active' => 1],
            ],
        ]);

        // Nhóm con đã có -> PUT vào chính nó.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/categories/6')
            && $request->method() === 'PUT'
            && $request->data()['name'] === 'Cà phê sữa');

        // Nhóm con mới -> POST, gắn parent_id là nhóm đang sửa.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/admin/categories')
            && ($request->data()['name'] ?? '') === 'Trà'
            && ($request->data()['parent_id'] ?? 0) === 5);
    }

    /** Nhóm gốc cố định: chặn tại trang, không gọi API. */
    public function test_khong_sua_duoc_nhom_goc(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->from('/admin/categories')
            ->put('/admin/categories/1', ['name' => 'Đổi tên', 'parent_id' => null]);

        $res->assertSessionHas('error');
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_khong_xoa_duoc_nhom_goc(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->from('/admin/categories')
            ->delete('/admin/categories/1');

        $res->assertSessionHas('error');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    /** Xoá một nhóm thường. */
    public function test_xoa_mot_nhom(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->delete('/admin/categories/6');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/categories/6')
            && $request->method() === 'DELETE');
    }

    /** Xoá nhiều: API chỉ xoá một dòng mỗi lượt nên trang gọi lần lượt. */
    public function test_xoa_nhieu_nhom(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())
            ->post('/admin/categories/bulk-destroy', ['ids' => [5, 6]]);

        foreach ([5, 6] as $id) {
            Http::assertSent(fn ($request) => str_contains($request->url(), "/admin/categories/{$id}")
                && $request->method() === 'DELETE');
        }
    }

    /** Xoá cả nhánh con của một nhóm — đường riêng, không đụng chính nhóm đó. */
    public function test_xoa_ca_nhanh_con(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->delete('/admin/categories/5/children');

        // Con của 5 là 6 -> bị xoá; chính nhóm 5 thì không.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/categories/6')
            && $request->method() === 'DELETE');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/admin/categories/5'));
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
            '*/admin/categories' => Http::response([
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => ['name' => 'Tên nhóm hàng hoá này đã có trong cửa hàng'],
            ], 422),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phienQuanTri())
            ->postJson('/admin/categories', ['name' => 'Trung ten', 'parent_id' => 1, 'is_active' => 1]);

        $res->assertStatus(422);
        $res->assertJson(['success' => false]);
        // Lấy câu theo TỪNG Ô chứ không lấy `message` chung chung.
        $res->assertJsonPath('message', 'Tên nhóm hàng hoá này đã có trong cửa hàng');
    }

    /** Lưu ĐƯỢC thì trả 200 kèm câu để bắn toast xanh rồi đóng hộp. */
    public function test_luu_xong_tu_hop_thoai_tra_200(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->postJson('/admin/categories', ['name' => 'Trung ten', 'parent_id' => 1, 'is_active' => 1]);

        $res->assertOk();
        $res->assertJson(['success' => true]);
    }
}
