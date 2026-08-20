<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Thông số chung → Quy tắc đánh số chứng từ.
 *
 * API giả lập (Http::fake) nên bài này chạy được cả khi không có Go API. Phần
 * nghiệp vụ thật (phạm vi dùng chung, vắng mặt là tắt, cô lập giữa hai cửa hàng)
 * do api/internal/apitest kiểm trên MySQL thật.
 */
class QuyTacDanhSoTest extends TestCase
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
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 5, 'code' => 'kho-bac', 'name' => 'Kho miền Bắc', 'phone' => '0912345678', 'address' => 'Hà Nội', 'is_active' => true],
                ['id' => 8, 'code' => 'kho-nam', 'name' => 'Kho miền Nam', 'phone' => '', 'address' => '', 'is_active' => true],
            ]]),
            '*/admin/quy-tac-ma*' => Http::response(['data' => [
                'loai' => [
                    ['ma' => 'hang-hoa', 'ten' => 'Hàng hóa', 'dung_chung' => true, 'bat_tat_duoc' => true, 'tien_to_goi_y' => 'HH'],
                    ['ma' => 'nhan-vien', 'ten' => 'Nhân viên', 'dung_chung' => true, 'bat_tat_duoc' => true, 'tien_to_goi_y' => 'NV'],
                    // Danh mục dùng chung nhưng KHÔNG bật/tắt được: mã bỏ trống thì
                    // phần mềm đặt hộ theo dải sẵn có, quy tắc chỉ đổi hình dạng.
                    ['ma' => 'thuoc-tinh', 'ten' => 'Thuộc tính', 'dung_chung' => true, 'bat_tat_duoc' => false, 'tien_to_goi_y' => 'TT'],
                    ['ma' => 'don-hang', 'ten' => 'Đơn hàng', 'dung_chung' => false, 'tien_to_goi_y' => 'DH'],
                ],
                'quy_tac' => [
                    // Danh mục dùng chung: hàng hoá đang bật, nhân viên đã tắt.
                    ['shop_id' => 0, 'doc_type' => 'hang-hoa', 'prefix' => 'HH', 'value_part' => 'so-thu-tu', 'length' => 6, 'suffix' => '', 'is_active' => true],
                    ['shop_id' => 0, 'doc_type' => 'nhan-vien', 'prefix' => 'NV', 'value_part' => 'so-thu-tu', 'length' => 4, 'suffix' => '', 'is_active' => false],
                    // Chứng từ: mỗi chi nhánh một tiền tố.
                    ['shop_id' => 5, 'doc_type' => 'don-hang', 'prefix' => 'DHB', 'value_part' => 'so-thu-tu', 'length' => 6, 'suffix' => '', 'is_active' => true],
                    ['shop_id' => 8, 'doc_type' => 'don-hang', 'prefix' => 'DHN', 'value_part' => 'thang-nam', 'length' => 8, 'suffix' => '', 'is_active' => true],
                ],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Vào khu Thông số chung mà không nói trang nào thì mở trang đầu. */
    public function test_vao_khu_thi_mo_trang_dau(): void
    {
        $this->fakeApi();

        $this->withSession($this->phienQuanTri())->get('/admin/parameters')
            ->assertRedirect('/admin/parameters/numbering-rules');
    }

    /** Bảng chi nhánh ở trên, bảng quy tắc ở dưới — đúng bố cục bản cũ. */
    public function test_hien_chi_nhanh_va_bang_quy_tac(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/parameters/numbering-rules');

        $res->assertOk();
        $res->assertSee('Kho miền Bắc', false);
        $res->assertSee('Kho miền Nam', false);
        $res->assertSee('Quy tắc mã danh mục', false);
        // Chi nhánh đầu được mở sẵn, và bảng lấy tiền tố của chính nó.
        $res->assertSee('value="DHB"', false);
        $res->assertDontSee('value="DHN"', false);
    }

    /** ?cn= mở đúng chi nhánh đó, không phải chi nhánh đầu danh sách. */
    public function test_mo_dung_chi_nhanh_duoc_chon(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())->get('/admin/parameters/numbering-rules?cn=8');

        $res->assertOk();
        $res->assertSee('value="DHN"', false);
        $res->assertSee('value="8"', false);
    }

    /** Danh mục đã tắt thì dòng bị ẩn và ô nhập disabled — trình duyệt không gửi lên. */
    public function test_danh_muc_tat_thi_an_dong_va_khoa_o_nhap(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())
            ->get('/admin/parameters/numbering-rules')->getContent();

        $this->assertMatchesRegularExpression('/data-loai="nhan-vien"[^>]*\bhidden\b/', $html);
        $this->assertMatchesRegularExpression('/data-tick="hang-hoa"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-tick="nhan-vien"\s+checked/', $html);
    }

    /**
     * Danh mục KHÔNG bật/tắt được thì không có ô tick, và dòng của nó luôn nằm
     * sẵn trong bảng quy tắc.
     *
     * Ô tick nghĩa là "tắt đi để gõ tay", mà thuộc tính / đơn vị tính / nhóm hàng
     * hoá bỏ trống mã là phần mềm vẫn đặt hộ — bày ô tick ở đó chỉ khiến người
     * dùng tưởng tắt xong là được gõ tay.
     */
    public function test_danh_muc_khong_bat_tat_duoc_thi_khong_co_o_tick(): void
    {
        $this->fakeApi();

        $html = $this->withSession($this->phienQuanTri())
            ->get('/admin/parameters/numbering-rules')->getContent();

        $this->assertStringNotContainsString('data-tick="thuoc-tinh"', $html);
        $this->assertMatchesRegularExpression('/data-loai="thuoc-tinh"(?![^>]*\bhidden\b)/', $html);
        // Và ô nhập của nó không bị khoá — sửa được ngay, không phải tick gì trước.
        $this->assertStringContainsString('name="rules[thuoc-tinh][prefix]"', $html);
        $this->assertDoesNotMatchRegularExpression('/name="rules\[thuoc-tinh\]\[prefix\]"[^>]*\bdisabled\b/', $html);
    }

    /** Lưu gửi đúng hình dạng API cần, rồi quay lại đúng chi nhánh vừa sửa. */
    public function test_luu_goi_dung_duong_api(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->put('/admin/parameters/numbering-rules', [
                'shop_id' => 8,
                'rules' => [
                    'hang-hoa' => ['prefix' => 'HH', 'value_part' => 'so-thu-tu', 'length' => 6, 'suffix' => ''],
                    'don-hang' => ['prefix' => 'DHN', 'value_part' => 'thang-nam', 'length' => 8, 'suffix' => 'X'],
                ],
            ]);

        $res->assertRedirect('/admin/parameters/numbering-rules?cn=8');
        $res->assertSessionHas('success');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/quy-tac-ma') || $request->method() !== 'PUT') {
                return false;
            }
            $body = $request->data();

            return $body['shop_id'] === 8
                && count($body['quy_tac']) === 2
                && $body['quy_tac'][0]['doc_type'] === 'hang-hoa'
                && $body['quy_tac'][1]['suffix'] === 'X';
        });
    }

    /** Số ký tự ngoài khoảng bị chặn ngay ở form, không phiền tới API. */
    public function test_so_ky_tu_ngoai_khoang_bi_chan(): void
    {
        $this->fakeApi();

        $res = $this->withSession($this->phienQuanTri())
            ->put('/admin/parameters/numbering-rules', [
                'shop_id' => 5,
                'rules' => [
                    'don-hang' => ['prefix' => 'DH', 'value_part' => 'so-thu-tu', 'length' => 99, 'suffix' => ''],
                ],
            ]);

        $res->assertSessionHasErrors('rules.don-hang.length');
    }

    /** API hỏng thì nói thẳng, không dựng form rỗng cho người dùng gõ vào rồi mất trắng. */
    public function test_api_hong_thi_bao_loi(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['message' => 'Hỏng'], 500)]);

        $res = $this->withSession($this->phienQuanTri())->get('/admin/parameters/numbering-rules');

        $res->assertOk();
        $res->assertSee('Chưa tải được dữ liệu', false);
    }
}
