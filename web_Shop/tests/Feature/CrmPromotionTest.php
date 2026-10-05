<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM → Chương trình khuyến mãi → tab "Chương trình khuyến mại" — khuôn
 * crm/promotion-program của v2 (bậc, hàng tặng, Lưu tạm / Duyệt), và nút
 * "Khuyến mãi" ở quầy.
 */
class CrmPromotionTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function mau(): array
    {
        return [
            'id' => 5, 'code' => 'CTKM00005', 'name' => 'Khuyến mãi tháng 9', 'description' => '', 'type' => 0,
            'status' => true, 'approved' => true, 'no_time_limit' => false,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'all_shops' => false, 'shop_ids' => [2], 'used' => 0,
            'details' => [['id' => 9, 'total_apply' => 500000, 'object_id' => 0, 'object_name' => '', 'quantity' => 0,
                'formality' => 0, 'value' => 10, 'max_value' => 50000, 'gifts' => []]],
        ];
    }

    /**
     * API TỪ CHỐI VÌ THIẾU QUYỀN thì màn phải nói đúng chuyện đó.
     *
     * Trước đây mọi lượt gọi hỏng đều rơi vào một nhánh: để danh sách rỗng rồi
     * vẽ tiếp. Người vai Quản lý chưa được giao quyền khuyến mại mở màn này sẽ
     * đọc "Chưa có chương trình nào", tưởng cửa hàng chưa lập cái nào, bấm "Tạo
     * mới" rồi ăn thêm một lỗi 403 nữa.
     *
     * Ba tab của màn Khuyến mại kiểm chung một bài: cùng một khuôn, sửa một tab
     * quên hai tab kia là chuyện đã suýt xảy ra.
     */
    public function test_thieu_quyen_thi_noi_dung_va_giau_nut(): void
    {
        // So theo MARKUP CỦA NÚT, không so theo chữ: chữ "Tạo mới" còn nằm trong
        // tiêu đề hộp thoại, bắt theo chữ là bài kiểm đỏ vì hộp thoại chứ không
        // vì nút.
        $tab = [
            ['admin.crm.promotions.index', 'quyền xem chương trình khuyến mại',
                ['bt btn_green add-item', 'btn-export']],
            ['admin.crm.promotions.dongGia', 'quyền xem khuyến mại đồng giá',
                ['bt btn_green add-item']],
            ['admin.crm.promotions.voucher', 'quyền xem voucher / coupon',
                ['bt btn_green add-voucher', 'btn-export']],
        ];

        foreach ($tab as [$ten, $cau, $nut]) {
            Http::fake([
                '*/admin/chuong-trinh-khuyen-mai*' => Http::response(['message' => 'Bạn không được giao việc này', 'errors' => ['ma' => 'THIEU_QUYEN']], 403),
                '*/admin/dong-gia*' => Http::response(['message' => 'Bạn không được giao việc này'], 403),
                '*/admin/voucher*' => Http::response(['message' => 'Bạn không được giao việc này'], 403),
                '*' => Http::response(['data' => []]),
            ]);

            $html = $this->withSession($this->phien())->get(route($ten))->assertOk()->getContent();

            $this->assertStringContainsString($cau, $html, "$ten: phải nói rõ là thiếu quyền");
            $this->assertStringNotContainsString('Bấm "Tạo', $html, "$ten: không được mời bấm Tạo khi đang bị chặn");
            $this->assertStringNotContainsString('Chưa có chương trình', $html, "$ten: không được nói là chưa có dữ liệu");
            foreach ($nut as $n) {
                $this->assertStringNotContainsString($n, $html, "$ten: phải giấu nút $n");
            }
        }
    }

    /** Sổ RỖNG THẬT thì vẫn nói "chưa có", và nút Tạo vẫn còn. */
    public function test_so_rong_that_van_moi_tao_moi(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 10, 'total' => 0, 'total_pages' => 1]])]);

        $html = $this->withSession($this->phien())
            ->get(route('admin.crm.promotions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Không có chương trình khuyến mại nào.', $html);
        $this->assertStringContainsString('Tạo mới', $html);
        $this->assertStringNotContainsString('chưa được giao quyền', $html);
    }

    public function test_bang_bam_v2(): void
    {
        Http::fake([
            '*/admin/chuong-trinh-khuyen-mai/ma' => Http::response(['data' => ['CTKM00005']]),
            '*/admin/chuong-trinh-khuyen-mai*' => Http::response(['data' => [$this->mau()], 'meta' => ['page' => 1, 'page_size' => 10, 'total' => 1, 'total_pages' => 1]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 2, 'code' => 'Q7', 'name' => 'Kho Quận 7']]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.crm.promotions.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/sub-nav-btn active">\s*Chương trình khuyến mãi/', $html);
        $this->assertStringContainsString('class="active">Chương trình khuyến mại', $html);
        $this->assertStringContainsString('Danh sách các chương trình khuyến mại', $html);
        foreach (['filterTime', 'filterBranch', 'filterEnterCoupon', 'filterStatus'] as $khoi) {
            $this->assertStringContainsString('id="'.$khoi.'"', $html);
        }
        $this->assertStringContainsString('CTKM00005', $html);
        $this->assertStringContainsString('Đã được duyệt', $html);
        $this->assertStringContainsString('Q7', $html);
        $this->assertStringContainsString('id="cancelApprove"', $html);
        $this->assertStringContainsString('id="modalDuplicateObject"', $html);
    }

    public function test_loc_mac_dinh_dau_thang_va_bo_chon_trang_thai_la_rong(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 10, 'total' => 0, 'total_pages' => 1]])]);

        $this->withSession($this->phien())->get(route('admin.crm.promotions.index'))->assertOk();
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/admin/chuong-trinh-khuyen-mai?')) {
                return false;
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return ($q['from_date'] ?? '') === now()->startOfMonth()->format('Y-m-d')
                && ($q['to_date'] ?? '') === now()->format('Y-m-d') && ! isset($q['statuses']);
        });

        Http::fake(['*' => Http::response(['data' => [$this->mau()]])]);
        $html = $this->withSession($this->phien())->get(route('admin.crm.promotions.index', ['statuses' => ['']]))->assertOk()->getContent();
        $this->assertStringContainsString('Không có chương trình khuyến mại nào.', $html);
    }

    public function test_luu_gui_bac_len_api(): void
    {
        Http::fake(['*/admin/chuong-trinh-khuyen-mai' => Http::response(['data' => $this->mau()], 201)]);

        $this->withSession($this->phien())->postJson(route('admin.ctkm.store'), [
            'name' => 'Khuyến mãi tháng 9', 'type' => 0, 'approved' => 1, 'status' => 1, 'no_time_limit' => 0,
            'start_date' => '01-09-2026', 'end_date' => '30-09-2026', 'days_of_week' => [1, 2, 3],
            'all_shops' => 1, 'details' => json_encode([['total_apply' => 500000, 'formality' => 0, 'value' => 10, 'max_value' => 50000,
                'gifts' => [['product_variant_id' => 7, 'quantity' => 1]]]]),
        ])->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && ! isset($r['code']) && $r['approved'] === true
            && $r['start_date'] === '2026-09-01' && $r['details'][0]['total_apply'] === 500000.0
            && $r['details'][0]['gifts'][0]['product_variant_id'] === 7);
    }

    public function test_quay_chuyen_promotion_program_ids(): void
    {
        Http::fake([
            '*/admin/orders/pos/khuyen-mai' => Http::response(['data' => ['chuong_trinh' => [['id' => 5, 'code' => 'CTKM00005', 'name' => 'x', 'type' => 0]], 'giam' => 5000, 'theo_chuong_trinh' => [], 'qua' => []]]),
            '*/admin/orders/pos' => Http::response(['data' => ['order_id' => 1]], 201),
        ]);
        $phien = array_merge($this->phien(), ['api.user' => ['id' => 7, 'full_name' => 'Quầy', 'role' => ['name' => 'staff']]]);

        $this->withSession($phien)->postJson(route('thu-ngan.ban-hang.khuyenMai'), [
            'items' => [['product_variant_id' => 12, 'quantity' => 2]], 'program_ids' => [5],
        ])->assertOk()->assertJsonPath('data.giam', 5000);

        $this->withSession($phien)->postJson(route('thu-ngan.ban-hang.store'), [
            'payment_method' => 'bank_transfer', 'promotion_program_ids' => [5],
            'items' => [['product_variant_id' => 12, 'quantity' => 2]],
        ])->assertSuccessful();

        Http::assertSent(fn ($r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/admin/orders/pos')
            && $r['promotion_program_ids'] === [5]);
    }

    /** Màn khuyến mãi cũ (/promotions) vẫn lưu qua hộp thoại bằng JSON, không gửi mã. */
    public function test_man_khuyen_mai_cu_luu_json(): void
    {
        Http::fake(['*/admin/promotions' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->withSession($this->phien())->postJson(route('admin.promotions.store'), [
            'code' => 'kmtet', 'name' => 'Tết sale', 'discount_type' => 'fixed', 'discount_value' => 20000,
            'start_at' => '2026-09-01T00:00', 'end_at' => '2026-09-30T23:59', 'is_active' => 1, 'category_ids' => [1],
        ])->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && ! isset($r['code']) && $r['max_discount_amount'] === null);
    }
}
