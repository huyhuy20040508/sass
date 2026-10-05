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
     * BẢNG VỪA KHUNG THẺ, KHÔNG CẮT CHỮ, KHÔNG BẺ DÒNG NHÃN.
     *
     * Mười bốn cột cần ~1450px mà khung khổ 1366 chỉ có 1071px. Ba cách chữa
     * đều đã thử và đều sai một kiểu:
     *
     *   - `min-width` cứng → bảng tràn khung, cột Hành động rơi khỏi màn.
     *   - để bảng tự co + cuộn ngang → lúc nào cũng phải kéo mới thấy hai cột
     *     cuối (28/09 đo được tràn 394px ở khổ 1366).
     *   - cắt "…" → mất chữ, mà luật của dự án cấm cắt lẫn bẻ dòng.
     *
     * Cách đang dùng: bốn cột phụ TẮT SẴN, mười cột còn lại chia % đủ 100 nên
     * vừa khít khung. Bật thêm cột thì bảng rộng ra theo `min-width` tính từ
     * đúng những cột đang bật và cuộn trong thẻ — người dùng tự chọn nhiều hơn
     * chỗ có thì kéo, còn hơn bóp cho chữ chen lên nhau.
     */
    public function test_bang_vua_khung_va_khong_cat_chu(): void
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

        $this->assertStringContainsString('table-layout: fixed', $khoi);
        // Cấm cắt chữ dưới mọi hình thức.
        $this->assertStringNotContainsString('text-overflow', $khoi);
        $this->assertStringNotContainsString('line-clamp', $khoi);
        // Nhãn cột một dòng; ô dữ liệu thà xuống dòng chứ không bị nuốt mất chữ.
        $this->assertMatchesRegularExpression('/none_mobile th \{[^}]*white-space: nowrap/s', $khoi);
        $this->assertMatchesRegularExpression('/none_mobile td \{[^}]*white-space: normal/s', $khoi);

        // Phần trăm phải cộng đủ 100 — thiếu thì bảng không lấp hết khung, thừa
        // thì tràn ra ngoài.
        preg_match_all('/none_mobile th[^{]*\{ width: ([0-9.]+)%/', $khoi, $m);
        $this->assertCount(14, $m[1], 'phải khai đủ 14 cột');
        $this->assertEqualsWithDelta(100.0, array_sum(array_map('floatval', $m[1])), 0.01);
    }

    /**
     * Bốn cột phụ tắt sẵn, và `min-width` tính theo ĐÚNG cột đang bật.
     *
     * Tính theo số cột đang bật mới đúng: để một con số cứng thì lúc tắt bớt
     * cột bảng vẫn rộng như cũ và lại cuộn ngang vô cớ.
     */
    public function test_cot_phu_tat_san_va_min_width_theo_cot_dang_bat(): void
    {
        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response($this->soMau()),
            '*' => Http::response(['data' => []]),
        ]);

        $C = \App\Http\Controllers\EInvoiceController::class;
        $url = route('admin.hoa-don-dien-tu.index');

        // Lần đầu vào màn: bốn cột phụ mang lớp `hide`.
        $html = $this->withSession($this->phienQuanLy())->get($url)->assertOk()->getContent();
        foreach ($C::COT_MAC_DINH_TAT as $cot) {
            $this->assertMatchesRegularExpression('/<th class="[^"]*show_'.$cot.' hide"/', $html, "cột $cot phải tắt sẵn");
        }
        $this->assertDoesNotMatchRegularExpression('/<th class="[^"]*show_tax_code\s+hide"/', $html, 'Mã CQT phải bật sẵn');

        $rongMacDinh = array_sum($C::COT_RONG_TOI_THIEU)
            - array_sum(array_map(fn ($c) => $C::COT_RONG_TOI_THIEU[$c], $C::COT_MAC_DINH_TAT));
        $this->assertStringContainsString('min-width: '.$rongMacDinh.'px', $html);
        // Vừa khung thẻ khổ 1366 (1071px) — đây là điều kiện để không cuộn ngang.
        $this->assertLessThan(1071, $rongMacDinh);

        // `hide=` RỖNG = người dùng bật hết: không được quay về bộ tắt sẵn.
        $html = $this->withSession($this->phienQuanLy())->get($url.'?hide=')->assertOk()->getContent();
        $this->assertStringNotContainsString(' hide"', $html, 'bật hết cột mà vẫn còn cột bị tắt');
        $this->assertStringContainsString('min-width: '.array_sum($C::COT_RONG_TOI_THIEU).'px', $html);

        // Tắt tay một cột: nghe theo người dùng, và min-width hụt đúng cột đó.
        $html = $this->withSession($this->phienQuanLy())->get($url.'?hide=total')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<th class="[^"]*show_total hide"/', $html);
        $this->assertMatchesRegularExpression('/<th class="[^"]*show_customer\s*"/', $html, 'cột khác phải bật lại');
        $this->assertStringContainsString(
            'min-width: '.(array_sum($C::COT_RONG_TOI_THIEU) - $C::COT_RONG_TOI_THIEU['total']).'px',
            $html
        );
    }

    /**
     * Dòng "không có hoá đơn" trải đúng SỐ CỘT ĐANG BẬT.
     *
     * Để cứng colspan=14 thì tắt bớt cột là dòng này thừa ô, kẻ bảng lệch hẳn
     * sang phải — lỗi chỉ lộ ra khi vừa tắt cột vừa lọc không ra kết quả.
     */
    public function test_dong_rong_trai_dung_so_cot_dang_bat(): void
    {
        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 10, 'total' => 0, 'total_pages' => 1, 'dem' => []]]),
            '*' => Http::response(['data' => []]),
        ]);

        $url = route('admin.hoa-don-dien-tu.index');
        $C = \App\Http\Controllers\EInvoiceController::class;
        $soCotBang = count($C::COT_BANG);

        // Mặc định: 3 cột cố định + (tổng cột bật/tắt − số cột tắt sẵn).
        $html = $this->withSession($this->phienQuanLy())->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('colspan="'.(3 + $soCotBang - count($C::COT_MAC_DINH_TAT)).'"', $html);

        // Bật hết.
        $html = $this->withSession($this->phienQuanLy())->get($url.'?hide=')->assertOk()->getContent();
        $this->assertStringContainsString('colspan="'.(3 + $soCotBang).'"', $html);

        // Tắt thêm một cột nữa.
        $html = $this->withSession($this->phienQuanLy())->get($url.'?hide=total,email')->assertOk()->getContent();
        $this->assertStringContainsString('colspan="'.(3 + $soCotBang - 2).'"', $html);
    }

    /**
     * Bảng hàng TRONG hộp chi tiết cũng phải vừa cột chứa nó.
     *
     * Hộp thoại hay bị bỏ quên vì phải mở ra mới thấy: để `auto` thì một tên
     * hàng dài đẩy bảng rộng hơn cột bên trái của hộp và người xem phải kéo
     * ngang ngay bên trong hộp thoại.
     */
    public function test_bang_hang_trong_hop_chi_tiet_vua_cot(): void
    {
        Http::fake([
            '*/admin/etax/hoa-don*' => Http::response($this->soMau()),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanLy())
            ->get(route('admin.hoa-don-dien-tu.index'))->assertOk()->getContent();

        $this->assertStringContainsString('#modalHoaDon table.bang-hang { width: 100%; table-layout: fixed; }', $html);

        preg_match_all('/#modalHoaDon table\.bang-hang th:nth-child\(\d\) \{ width: ([0-9.]+)%/', $html, $m);
        $this->assertCount(5, $m[1], 'bảng hàng phải khai đủ 5 cột');
        $this->assertEqualsWithDelta(100.0, array_sum(array_map('floatval', $m[1])), 0.01);
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
