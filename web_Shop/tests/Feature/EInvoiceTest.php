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

    /**
     * BẢNG CO THEO NỘI DUNG, CUỘN NGANG TRONG THẺ — y bản gốc.
     *
     * Bản gốc (ordertable v2, system/etax-invoice/list.blade.php) để bảng tự co
     * và bọc trong khung `overflow-x: auto`. Hai lần đi chệch khỏi cách ấy đều
     * đẻ ra lỗi mà trang vẫn 200, không bài kiểm nào khác bắt được:
     *
     *   - `min-width` ép bảng rộng hơn thẻ → cột Hành động rơi ra ngoài màn ở
     *     khổ 1024–1280.
     *   - `table-layout: fixed` + chia phần trăm → mười bốn cột `nowrap` thì cột
     *     nào cũng hụt: hoặc cắt "…" (mã CQT dài gấp ba bề ngang cột, cắt xong
     *     còn "M1-…" không đối chiếu được với ai) hoặc tràn đè sang ô bên cạnh.
     */
    public function test_bang_co_theo_noi_dung_va_cuon_trong_the(): void
    {
        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response($this->soMau()),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanLy())
            ->get(route('admin.hoa-don-dien-tu.index'))->assertOk()->getContent();

        $dau = strpos($html, 'table.table-hoa-don.none_mobile { width: 100%;');
        $this->assertNotFalse($dau, 'không thấy khối CSS của bảng');
        $khoi = substr($html, $dau, strpos($html, '.hd-phu {') - $dau);

        $this->assertStringNotContainsString('table-layout: fixed', $khoi);
        $this->assertStringNotContainsString('min-width', $khoi);
        $this->assertStringNotContainsString('text-overflow', $khoi);
        $this->assertDoesNotMatchRegularExpression('/th[^{]*\{ width: [0-9.]+%/', $khoi,
            'chia phần trăm là quay lại cách ép bảng vừa khung');

        // Nhãn cột giữ một dòng: bảng cuộn ngang được thì không có cớ bẻ chữ.
        $this->assertStringContainsString('th { white-space: nowrap; }', $khoi);

        // Khung bọc phải cuộn được, nếu không bảng rộng hơn thẻ sẽ đẩy cả trang.
        $this->assertMatchesRegularExpression(
            '/<div class="[^"]*table-responsive[^"]*">\s*<table class="table-hoa-don none_mobile">/',
            $html,
            'bảng phải nằm trong khung cuộn ngang'
        );
    }

    /**
     * Ô dài vẫn mang `title`.
     *
     * Bảng co theo nội dung nên bình thường không cắt chữ, nhưng người dùng tắt
     * bớt cột hoặc thu hẹp cửa sổ là cột hẹp lại ngay. Giữ `title` để lúc ấy
     * vẫn còn đường đọc giá trị đầy đủ, khỏi phải nhớ thêm luật.
     */
    public function test_o_dai_deu_co_title(): void
    {
        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response($this->soMau()),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanLy())
            ->get(route('admin.hoa-don-dien-tu.index'))->assertOk()->getContent();

        foreach (['show_symbol', 'show_invoice_no', 'show_tax_code', 'show_order_code',
            'show_status', 'show_issued_at', 'show_customer', 'show_email', 'show_vat',
            'show_total', 'show_creator'] as $cot) {
            $this->assertMatchesRegularExpression(
                '/<td class="[^"]*'.$cot.'[^"]*"[^>]*\stitle=/',
                $html,
                "cột $cot hẹp lại là mất luôn giá trị đầy đủ nếu không có title"
            );
        }
    }

    /**
     * PHÂN TRANG — năm mức cỡ trang của v2, và dãy số trang giữ nguyên bộ lọc.
     *
     * Ba chỗ từng sai mà trang vẫn 200:
     *
     *   1. Ô "Hiển thị" bày 20/50/100 trong khi mọi màn khác (và bản v2) bày
     *      10/20/30/40/50 — đổi cỡ trang ở màn khác rồi sang đây là con số vừa
     *      chọn biến mất khỏi danh sách.
     *   2. Link số trang đánh rơi bộ lọc đang bật → bấm sang trang 2 là thấy
     *      toàn bộ sổ thay vì phần đang lọc.
     *   3. Trang đang xem phải gửi `page` sang API, không thì trang nào cũng ra
     *      cùng một mớ dòng.
     */
    public function test_phan_trang_dung_bo_cua_v2(): void
    {
        $so = $this->soMau();
        $so['meta'] = ['page' => 2, 'page_size' => 10, 'total' => 25, 'total_pages' => 3,
            'dem' => ['tat_ca' => 25, 'issued' => 25, 'sent' => 0, 'draft' => 0, 'failed' => 0]];

        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response($so),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanLy())
            ->get(route('admin.hoa-don-dien-tu.index', ['page' => 2, 'status' => 'issued', 'page_size' => 10]))
            ->assertOk()->getContent();

        // 1. Ô cỡ trang bày đúng năm mức, và mức đang dùng được chọn sẵn.
        foreach ([10, 20, 30, 40, 50] as $muc) {
            $this->assertStringContainsString('value="'.$muc.'"', $html, "thiếu mức $muc dòng/trang");
        }
        $this->assertStringNotContainsString('value="100"', $html, '100 dòng/trang không thuộc bộ của v2');
        $this->assertMatchesRegularExpression('/value="10"\s+selected/', $html);

        // 2. Link trang khác giữ nguyên trạng thái đang lọc.
        //
        // Blade in `&` thành `&amp;` nên khớp theo dấu phân cách thật của HTML,
        // không phải dấu `&` trần — bắt theo `&` trần là bài kiểm đỏ vì cách
        // thoát ký tự chứ không vì link sai.
        $this->assertMatchesRegularExpression('/href="[^"]*(\?|&amp;)status=issued/', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*(\?|&amp;)page=3/', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*(\?|&amp;)page=1/', $html);

        // Trang đang xem không phải một cái link bấm vào chính nó.
        $this->assertMatchesRegularExpression('/<li class="page-item active"[^>]*>\s*<span class="page-link">2<\/span>/', $html);

        // 3. `page` đi sang API.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/etax/hoa-don')
            && str_contains(urldecode($req->url()), 'page=2'));
    }

    /**
     * Trang quá số trang thật thì LÙI VỀ TRANG CUỐI, không bày màn trắng.
     *
     * Link cũ / nút Back giữ ?page=5 trong khi sổ đã rút còn 2 trang là chuyện
     * thường. Để nguyên thì API trả 0 dòng, màn nói "Chưa có hoá đơn điện tử
     * nào" — sai hẳn nghĩa — mà dãy số trang cũng không hiện nên hết đường bấm
     * quay lại.
     */
    public function test_trang_vuot_so_trang_thi_lui_ve_trang_cuoi(): void
    {
        $so = $this->soMau();
        $so['data'] = [];
        $so['meta'] = ['page' => 9, 'page_size' => 10, 'total' => 25, 'total_pages' => 3, 'dem' => []];

        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response($so),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phienQuanLy())
            ->get(route('admin.hoa-don-dien-tu.index', ['page' => 9, 'status' => 'issued']))
            ->assertRedirect(route('admin.hoa-don-dien-tu.index', ['page' => 3, 'status' => 'issued']));
    }

    /** Sổ RỖNG THẬT thì đứng yên, không quẩn vòng chuyển hướng. */
    public function test_so_rong_that_thi_khong_chuyen_huong(): void
    {
        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response([
                'data' => [], 'meta' => ['page' => 4, 'page_size' => 10, 'total' => 0, 'total_pages' => 1, 'dem' => []],
            ]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phienQuanLy())
            ->get(route('admin.hoa-don-dien-tu.index', ['page' => 4]))
            ->assertOk();
    }

    /** Một trang thì không bày dãy số — dãy chỉ có một nút để bấm vào chính nó. */
    public function test_mot_trang_thi_khong_bay_day_so(): void
    {
        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response($this->soMau()),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanLy())
            ->get(route('admin.hoa-don-dien-tu.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<ul class="pagination">', $html);
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
