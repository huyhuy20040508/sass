<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM → Chương trình khuyến mãi → tab Voucher/Coupon (khuôn crm/voucher-coupon
 * của v2): chương trình phát nhiều mã, Lưu / Phát hành, danh sách mã chi tiết.
 */
class CrmVoucherCouponTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function mau(int $trangThai = 2): array
    {
        return [
            'id' => 4, 'code' => 'VC00004', 'name' => 'Khuyến mãi 30-04', 'description' => '',
            'discount_type' => 'percentage', 'discount_value' => 10, 'max_discount_amount' => 50000, 'min_order_amount' => 2000000,
            'all_shops' => false, 'shop_ids' => [2], 'all_categories' => true, 'category_ids' => [],
            'no_time_limit' => true, 'start_date' => null, 'end_date' => null,
            'prefix' => 'KM', 'suffix' => 'ALL', 'quantity' => 10, 'usage_limit' => 1,
            'status' => $trangThai, 'created_by_name' => 'Admin master',
        ];
    }

    public function test_tab_voucher_bay_bang_nhu_v2(): void
    {
        Http::fake([
            '*/admin/voucher-coupon*' => Http::response(['data' => [$this->mau(), array_merge($this->mau(1), ['id' => 5, 'code' => 'VC00005', 'discount_type' => 'fixed', 'discount_value' => 11000])],
                'meta' => ['page' => 1, 'page_size' => 10, 'total' => 2, 'total_pages' => 1]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 2, 'code' => 'Q7', 'name' => 'Kho Quận 7']]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('admin.crm.promotions.voucher'))->assertOk()->getContent();

        $this->assertStringContainsString('class="active">Voucher/Coupon', $html);
        $this->assertStringContainsString('Danh sách chương trình', $html);
        // Đã phát hành: tên là đường mở danh sách mã, chỉ có nút xem.
        $this->assertStringContainsString('campaign-detail', $html);
        $this->assertStringContainsString('2,000,000', $html);
        $this->assertStringContainsString('Coupon', $html);
        $this->assertStringContainsString('10%', $html);
        $this->assertStringContainsString('11,000 đ', $html);
        $this->assertStringContainsString('∞', $html);
        $this->assertStringContainsString('Admin master', $html);
        // Chưa phát hành: có sửa / xoá.
        $this->assertSame(1, substr_count($html, 'class="edit_bt edit-item"'));
        // Cột mặc định của v2: Mô tả, Chi nhánh, Trạng thái ẩn.
        $this->assertStringContainsString('show_branch hide', $html);
    }

    public function test_phat_hanh_gui_dung_than_len_api(): void
    {
        Http::fake(['*/admin/voucher-coupon' => Http::response(['data' => $this->mau()], 201)]);

        $this->withSession($this->phien())->postJson(route('admin.voucher-coupon.store'), [
            'name' => 'Khuyến mãi 30-04', 'discount_type' => 'percentage', 'discount_value' => '10',
            'min_order_amount' => '2,000,000', 'max_discount_amount' => '50,000',
            'all_shops' => 0, 'shop_ids' => [2], 'all_categories' => 1,
            'no_time_limit' => 0, 'start_date' => '28-09-2026', 'end_date' => '30-09-2026',
            'prefix' => 'KM', 'suffix' => 'ALL', 'quantity' => 10, 'usage_limit' => 1, 'release' => 1,
        ])->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['release'] === true && $r['min_order_amount'] === 2000000.0
            && $r['max_discount_amount'] === 50000.0 && $r['start_date'] === '2026-09-28' && $r['shop_ids'] === [2]);
    }

    public function test_thieu_truong_bao_nhu_v2(): void
    {
        $this->withSession($this->phien())->postJson(route('admin.voucher-coupon.store'), ['discount_type' => 'percentage'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'quantity', 'usage_limit', 'min_order_amount']);
    }

    public function test_danh_sach_ma_tra_json_kem_phan_trang(): void
    {
        Http::fake(['*/admin/voucher-coupon/4/ma*' => Http::response([
            'data' => [['id' => 604, 'code' => 'KMZW20RALL', 'discount_type' => 'percentage', 'discount_value' => 10, 'usage_limit' => 1, 'used_count' => 0, 'is_active' => true]],
            'meta' => ['page' => 1, 'page_size' => 10, 'total' => 10, 'total_pages' => 1],
        ])]);

        $this->withSession($this->phien())->getJson(route('admin.voucher-coupon.codes', ['id' => 4, 'keyword' => 'KMZ']))
            ->assertOk()->assertJsonPath('data.0.code', 'KMZW20RALL')->assertJsonPath('meta.total', 10);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/voucher-coupon/4/ma') && $r['keyword'] === 'KMZ');
    }

    /**
     * Nút ghi "Xuất Excel" thì tệp phải là .xlsx THẬT.
     *
     * Trước đây nút ấy tải về voucher-coupon-*.csv kiểu text/csv — nhãn nói một
     * đằng, tệp một nẻo. Bài canh đuôi tệp, kiểu nội dung và chữ ký zip; thêm
     * hai cột số phải ghi kiểu SỐ để người nhận cộng được, và cột "Giá trị" phải
     * giữ nguyên CHỮ vì nó trộn "10%" với "11.000 đ" tuỳ dòng.
     */
    public function test_xuat_la_xlsx_that_va_ghi_so_kieu_so(): void
    {
        Http::fake([
            '*/admin/voucher-coupon*' => Http::response(['data' => [$this->mau()],
                'meta' => ['page' => 1, 'page_size' => 100, 'total' => 1, 'total_pages' => 1]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 2, 'code' => 'Q7', 'name' => 'Kho Quận 7']]]),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phien())
            ->get(route('admin.voucher-coupon.export', ['statuses' => [2]]))->assertOk();

        $this->assertStringContainsString('.xlsx', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('.csv', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $res->headers->get('Content-Type'));
        $this->assertSame('PK', substr($res->getContent(), 0, 2), 'Tệp không phải xlsx (zip).');

        $tep = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tep, $res->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tep), 'không mở được tệp xuất như zip');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tep);

        $this->assertStringContainsString('Mã chương trình', $sheet);
        $this->assertStringContainsString('VC00004', $sheet);
        // Chi nhánh in theo MÃ như trên bảng, không phải tên dài.
        $this->assertStringContainsString('>Q7<', $sheet);
        // "Áp dụng cho hóa đơn trên" và "Số lượng" là SỐ, không phải chữ đã chấm nghìn.
        $this->assertStringContainsString('<v>2000000</v>', $sheet);
        $this->assertStringContainsString('<v>10</v>', $sheet);
        $this->assertStringNotContainsString('2,000,000', $sheet);
        // Cột Giá trị vẫn là chữ, giữ nguyên dấu %.
        $this->assertStringContainsString('10%', $sheet);

        // Bộ lọc đang xem đi theo sang API, không rơi mất ở giữa.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/voucher-coupon')
            && str_contains($r->url(), 'statuses=2'));
    }
}
