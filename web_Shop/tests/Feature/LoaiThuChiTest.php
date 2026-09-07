<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Màn Loại thu chi — bảng tra cho phiếu thu và phiếu chi.
 *
 * Hai thứ bài này gác, cả hai đều KHÔNG kiểm được bằng dữ liệu thật đang có:
 *
 *   1. Loại HỆ THỐNG (`is_default`) không được bày nút Sửa / Xoá. Mười hai dòng
 *      gieo sẵn đều `is_default = 0` nên trên máy chưa dòng nào bị khoá — cờ ấy
 *      chỉ bật khi phiếu tự sinh (bán hàng, trả hàng) bắt đầu dựng phân loại
 *      riêng. Giả lập API ở đây là cách duy nhất nhìn thấy cảnh đó trước khi nó
 *      xảy ra thật. (Chốt bên API đã có: apitest bật cờ thẳng trong DB rồi soi
 *      hai lượt sửa/xoá phải trả 409.)
 *
 *   2. Mã HTTP của lượt hỏng phải là mã API vừa nói. Sửa/xoá một id không tồn
 *      tại từng trả 422 kèm câu "Không tìm thấy dữ liệu" — mã nói một đằng, câu
 *      nói một nẻo.
 */
class LoaiThuChiTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-admin',
            'api.refresh_token' => 'refresh-admin',
            'api.user' => ['id' => 7, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly'],
            'api.tenant' => ['code' => 'quochuy', 'name' => 'Tiệm Quốc Huy'],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $ds */
    protected function fakeApi(array $ds = []): void
    {
        Http::fake([
            '*/admin/loai-thu-chi*' => Http::response(['data' => $ds]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /**
     * Loại hệ thống: không nút Sửa, không nút Xoá — ở CẢ bảng lẫn thẻ điện thoại.
     *
     * Đếm nút chứ không chỉ tìm chuỗi: dưới 992px v2 giấu hẳn bảng và bày danh
     * sách thẻ, nên một chốt chỉ làm đúng ở bảng là chốt hở đúng nửa số người dùng.
     */
    public function test_loai_he_thong_khong_co_nut_sua_xoa(): void
    {
        $this->fakeApi([
            ['id' => 1, 'type' => 0, 'name' => 'Thu của hệ thống', 'is_default' => true],
            ['id' => 2, 'type' => 0, 'name' => 'Thu tự khai', 'is_default' => false],
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.loai-thu-chi.index'))
            ->assertOk()
            ->getContent();

        // Hai bản (bảng + thẻ) × một dòng tự khai = 2 nút sửa và 2 nút xoá.
        // Dòng hệ thống không góp nút nào.
        // Đếm theo class ĐẦY ĐỦ của nút, không đếm chuỗi 'edit-item' trần: chuỗi
        // ấy còn nằm trong đoạn JS bắt sự kiện ở cuối trang.
        $this->assertSame(2, substr_count($html, 'edit_bt edit-item'), 'Số nút Sửa không khớp — loại hệ thống đang được bày nút.');
        $this->assertSame(2, substr_count($html, 'dele_bt delete-item'), 'Số nút Xoá không khớp — loại hệ thống đang được bày nút.');

        // Cả hai dòng vẫn hiện, và hiện ở cả hai bản. Đếm `data-name` chứ không đếm
        // tên trần: tên còn nằm trong chính thuộc tính ấy nên mỗi bản góp hai lượt.
        $this->assertSame(2, substr_count($html, 'data-name="Thu của hệ thống"'));
        $this->assertSame(2, substr_count($html, 'data-name="Thu tự khai"'));
    }

    /**
     * Bản thẻ cho điện thoại phải CÓ và mang đủ data-* để lọc / sửa / xoá.
     *
     * Dưới 992px v2 giấu hẳn `.table-list-container`. Thiếu khối này thì màn hình
     * chỉ còn tiêu đề với ô tìm, không dòng nào hiện — mà bảng vẫn nằm đó trong
     * HTML nên nhìn mã nguồn không thấy gì sai.
     */
    public function test_co_ban_the_cho_dien_thoai(): void
    {
        $this->fakeApi([
            ['id' => 5, 'type' => 0, 'name' => 'Thu khác', 'is_default' => false],
            ['id' => 6, 'type' => 1, 'name' => 'Tiền điện', 'is_default' => false],
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.loai-thu-chi.index'))
            ->assertOk()
            ->getContent();

        // Mỗi bảng một khối thẻ, dùng ĐÚNG khung .table-list-container-mobile của v2
        // (CSS của v2 ẩn nó ở desktop, bày ở mobile).
        $this->assertSame(2, substr_count($html, 'class="table-list-container-mobile"'));
        // Thẻ dựng SẴN ở máy chủ — v2 để khung này rỗng rồi nhờ
        // public/v2/js/script.js đổ dữ liệu, mà vỏ v2 ở đây không nạp tệp ấy nên nó
        // rỗng suốt. Mỗi dòng đúng một thẻ.
        $this->assertSame(2, substr_count($html, 'table-list-mobile-item'));
    }

    /** Sửa một id không tồn tại: API trả 404 thì màn hình cũng phải trả 404. */
    public function test_sua_id_khong_co_tra_dung_ma_404(): void
    {
        Http::fake([
            '*/admin/loai-thu-chi/*' => Http::response(['message' => 'Không tìm thấy dữ liệu'], 404),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->withHeader('Accept', 'application/json')
            ->put(route('admin.loai-thu-chi.update', 999999), ['type' => 0, 'name' => 'Đổi tên'])
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Không tìm thấy dữ liệu']);
    }

    /** Xoá một id không tồn tại: cũng vậy. */
    public function test_xoa_id_khong_co_tra_dung_ma_404(): void
    {
        Http::fake([
            '*/admin/loai-thu-chi/*' => Http::response(['message' => 'Không tìm thấy dữ liệu'], 404),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->withHeader('Accept', 'application/json')
            ->delete(route('admin.loai-thu-chi.destroy', 999999))
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    /**
     * Loại hệ thống: API trả 409, màn hình giữ nguyên 409.
     *
     * 409 chứ không 422 vì đây không phải lỗi gõ sai ô nào — dữ liệu gửi lên
     * đúng cả, chỉ là dòng ấy không cho đụng vào.
     */
    public function test_loai_he_thong_giu_nguyen_ma_409(): void
    {
        Http::fake([
            '*/admin/loai-thu-chi/*' => Http::response(['message' => 'Loại hệ thống không sửa được'], 409),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->withHeader('Accept', 'application/json')
            ->delete(route('admin.loai-thu-chi.destroy', 1))
            ->assertStatus(409)
            ->assertJson(['message' => 'Loại hệ thống không sửa được']);
    }

    /** Lỗi 5xx của API KHÔNG đội lốt lỗi người dùng: vẫn về 422 như cũ. */
    public function test_loi_may_chu_van_ve_422(): void
    {
        Http::fake([
            '*/admin/loai-thu-chi/*' => Http::response(['message' => 'Lỗi hệ thống'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->withHeader('Accept', 'application/json')
            ->delete(route('admin.loai-thu-chi.destroy', 1))
            ->assertStatus(422);
    }
}
