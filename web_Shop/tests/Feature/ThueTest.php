<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cài đặt → Thuế suất.
 *
 * Màn này CỐ Ý không có Thêm / Xoá: bốn loại thuế khai cứng trong mã nguồn API
 * (domain.DanhMucLoaiThue), cửa hàng chỉ tick trong mỗi loại những mức nào cho
 * hiện ra. Bài này gác đúng hai thao tác có thật — sửa bộ mức và bật/tắt loại —
 * và gác luôn việc màn KHÔNG bày nút thêm/xoá, để lần sau chép giao diện v2 về
 * không ai vô tình đắp lại cái nút không có đường đi.
 */
class ThueTest extends TestCase
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
            '*/admin/thue*' => Http::response(['data' => [
                [
                    'id' => 1, 'ma' => 'mac-dinh', 'ten' => 'Thuế mặc định',
                    'mo_ta' => 'Áp cho nhóm hàng hóa và từng mặt hàng.',
                    'is_active' => true,
                    'muc' => [0, 8, 10],
                    'chon_duoc' => [
                        ['gia_tri' => 0, 'nhan' => '0%'],
                        ['gia_tri' => 8, 'nhan' => '8%'],
                        ['gia_tri' => 10, 'nhan' => '10%'],
                        ['gia_tri' => -1, 'nhan' => 'KCT'],
                    ],
                ],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Đọc: bảng bày tên loại và các mức đang bật. */
    public function test_bang_hien_loai_thue(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/taxes');

        $res->assertOk();
        $res->assertSee('Thuế mặc định', false);
    }

    /** Sửa bộ mức: gửi mảng số, lọc trùng trước khi đi. */
    public function test_luu_bo_muc(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/taxes/1', [
            'muc' => ['0', '8', '8', '10'],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/thue/1') || $request->method() !== 'PUT') {
                return false;
            }

            return $request->data() === ['muc' => [0, 8, 10]];
        });
    }

    /** Bỏ hết mức thì chặn tại trang: một loại thuế không còn mức nào là ô chọn rỗng. */
    public function test_bo_het_muc_thi_khong_goi_api(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->from('/admin/taxes')
            ->put('/admin/taxes/1', ['muc' => []]);

        $res->assertSessionHasErrors('muc');
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    /** Công tắc trên bảng: chỉ gửi cờ, đi đường trạng thái riêng. */
    public function test_cong_tac_doi_trang_thai(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->put('/admin/taxes/1/status', ['is_active' => 0]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/thue/1/trang-thai')
            && $request->method() === 'PUT'
            && $request->data() === ['is_active' => false]);
    }

    /**
     * Màn không có Thêm / Xoá vì API không có đường ấy. Nút chết trên giao diện
     * còn tệ hơn thiếu nút: bấm vào không có gì xảy ra và không ai biết vì sao.
     */
    public function test_khong_bay_nut_them_hay_xoa(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())->get('/admin/taxes')->getContent();

        $this->assertStringNotContainsString('id="deleteItem"', $html);
        $this->assertStringNotContainsString('mass-delete', $html);
        $this->assertStringNotContainsString(__('message.create_new'), $html);
    }
}
