<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Thống kê → Hoá đơn điện tử — sổ hoá đơn đứng cạnh Quản lý đơn hàng.
 *
 * Soi ba thứ: bộ lọc đi sang API đúng tên và đúng định dạng, bảng in đúng nút
 * theo trạng thái từng tờ (đồng bộ chỉ cho tờ còn chờ, tải PDF chỉ cho tờ đã
 * có bên cổng), và bản xuất đọc cùng sổ với bảng.
 */
class EInvoiceTest extends TestCase
{
    protected function phienQuanLy(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly'],
        ];
    }

    protected function soMau(): array
    {
        return [
            'data' => [
                ['id' => 2, 'order_id' => 12, 'order_code' => 'DH012', 'symbol' => '1C26TAA', 'invoice_no' => '',
                    'invoice_id' => 'abc-2', 'tax_auth_code' => '', 'status' => 'draft', 'doc_status' => null,
                    'total_amount' => 200000, 'vat_amount' => 16000, 'customer_name' => 'Anh B',
                    'customer_email' => 'b@x.vn', 'nguoi_tao' => 'Thu ngân', 'issued_at' => null,
                    'created_at' => '2026-09-11T10:00:00+07:00'],
                ['id' => 1, 'order_id' => 11, 'order_code' => 'DH011', 'symbol' => '1C26TAA', 'invoice_no' => '0000123',
                    'invoice_id' => 'abc-1', 'tax_auth_code' => 'M1-26-ABC', 'status' => 'issued', 'doc_status' => 6,
                    'total_amount' => 100000, 'vat_amount' => 8000, 'customer_name' => 'Chị A',
                    'customer_email' => 'a@x.vn', 'nguoi_tao' => '', 'issued_at' => '2026-09-10T09:00:00+07:00',
                    'created_at' => '2026-09-10T08:59:00+07:00'],
                // Tờ hỏng: chưa có gì bên cổng — không đồng bộ, không tải PDF.
                ['id' => 3, 'order_id' => 13, 'order_code' => 'DH013', 'symbol' => '1C26TAA', 'invoice_no' => '',
                    'invoice_id' => '', 'tax_auth_code' => '', 'status' => 'failed', 'doc_status' => null,
                    'error' => 'Sai mã số thuế người mua', 'total_amount' => 50000, 'vat_amount' => 0,
                    'customer_name' => 'Cô C', 'issued_at' => null, 'created_at' => '2026-09-11T11:00:00+07:00'],
            ],
            'meta' => ['page' => 1, 'page_size' => 20, 'total' => 3, 'total_pages' => 1,
                'dem' => ['tat_ca' => 7, 'issued' => 4, 'sent' => 0, 'draft' => 2, 'failed' => 1]],
        ];
    }

    public function test_trang_in_dung_so_hoa_don(): void
    {
        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response($this->soMau()),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanLy())
            ->get(route('admin.hoa-don-dien-tu.index', [
                'status' => 'draft', 'from_date' => '01-09-2026', 'to_date' => '11-09-2026', 'code' => 'DH01',
            ]))
            ->assertOk()
            ->getContent();

        // Bộ lọc sang API: ngày đổi sang Y-m-d, ô trống không gửi.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/etax/hoa-don')
            && ($req->data()['status'] ?? null) === 'draft'
            && ($req->data()['from_date'] ?? null) === '2026-09-01'
            && ($req->data()['to_date'] ?? null) === '2026-09-11'
            && ($req->data()['code'] ?? null) === 'DH01'
            && ! array_key_exists('symbol', $req->data()));

        // Hàng nút trạng thái mang số đếm của API, nút đang chọn sáng lên.
        $this->assertStringContainsString('Tất cả (7)', html_entity_decode($html));
        $this->assertStringContainsString('Đã cấp mã (4)', $html);
        $this->assertMatchesRegularExpression('/hd-tt active"\s+data-status="draft"/', $html);

        // Loại tờ in dưới trạng thái; tờ hỏng mang câu lỗi của cổng.
        $this->assertStringContainsString('Bị thay thế', $html);
        $this->assertStringContainsString('title="Sai mã số thuế người mua"', $html);

        // Đồng bộ chỉ cho tờ còn chờ (1 tờ nháp), PDF chỉ cho tờ đã có bên cổng (2 tờ).
        $this->assertSame(1, substr_count($html, 'class="hd-nut hd-dong-bo"'));
        $this->assertSame(2, substr_count($html, 'title="Tải bản in (PDF)"'));
        $this->assertStringNotContainsString('/admin/orders/13/etax/pdf', $html);

        // Tab "Hoá đơn điện tử" trên thanh Thống kê đã bấm được và đang sáng.
        $this->assertMatchesRegularExpression('#href="[^"]*/admin/hoa-don-dien-tu"\s+class="sub-nav-btn active"#', $html);
    }

    public function test_xuat_doc_cung_so_voi_bang(): void
    {
        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response($this->soMau()),
            '*' => Http::response(['data' => []]),
        ]);

        $csv = $this->withSession($this->phienQuanLy())
            ->get(route('admin.hoa-don-dien-tu.export', ['status' => 'issued']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ký hiệu', $csv);
        $this->assertStringContainsString('M1-26-ABC', $csv);
        $this->assertStringContainsString('Nháp — chưa ký', $csv);
        // Tờ chưa được cấp số thì ngày phát hành lấy lúc lập lượt phát hành.
        $this->assertStringContainsString('11/09/2026 10:00', $csv);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/etax/hoa-don')
            && ($req->data()['status'] ?? null) === 'issued'
            && (int) ($req->data()['page_size'] ?? 0) === 100);
    }
}
