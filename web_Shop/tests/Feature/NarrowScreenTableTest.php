<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ba màn mở trong đợt lỗi 22 phải vừa khung 1136px của vỏ mới.
 *
 * Vỏ mới có menu dọc nên khung nội dung chỉ còn 1136px ở khổ 1366 — hẹp hơn vỏ
 * v2 cũ. Bảng nào còn để `table-layout: auto` thì phần trăm chỉ là gợi ý: trình
 * duyệt đo nội dung rồi tự nới, bảng phình quá khung và cột cuối rơi ra ngoài
 * màn. `min-width` còn tệ hơn: nó ép cuộn ngang ở mọi khổ dưới con số đó.
 *
 * Bài này gác ba thứ đo được từ CSS, thay cho việc mở trình duyệt:
 * `fixed`, không `min-width`, và phần trăm cộng đủ 100.
 */
class NarrowScreenTableTest extends TestCase
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

    /** @param  array<string, mixed>  $themApi */
    protected function trang(string $duong, array $themApi = []): string
    {
        Http::fake($themApi + ['*' => Http::response([
            'data' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1],
        ])]);

        return $this->withSession($this->phien())->get($duong)->assertOk()->getContent();
    }

    /**
     * Cắt lấy đúng khối CSS của một bảng.
     *
     * Soi `min-width` trên cả trang thì dính hộp thoại và ô lọc — những chỗ
     * min-width hoàn toàn đúng. Chỉ bảng mới bị cấm.
     */
    protected function khoiCss(string $html, string $dauKhoi, string $cuoiKhoi): string
    {
        $dau = strpos($html, $dauKhoi);
        $this->assertNotFalse($dau, "Không thấy khối CSS bắt đầu bằng \"$dauKhoi\".");
        $cuoi = strpos($html, $cuoiKhoi, $dau);
        $this->assertNotFalse($cuoi, "Không thấy mốc kết thúc \"$cuoiKhoi\".");

        return substr($html, $dau, $cuoi - $dau);
    }

    /**
     * Cộng phần trăm của một bộ lớp cột.
     *
     * Cộng float không bao giờ ra đúng 100.0 nên bên gọi so bằng delta.
     *
     * @param  array<int, string>  $lop
     */
    protected function tongPhanTram(string $html, array $lop): float
    {
        $tong = 0.0;
        foreach ($lop as $ten) {
            $this->assertMatchesRegularExpression(
                '/\.'.preg_quote($ten, '/').'[^{}]*\{[^{}]*width:\s*([0-9.]+)%/',
                $html,
                "Cột .$ten không khai bề rộng theo phần trăm."
            );
            preg_match('/\.'.preg_quote($ten, '/').'[^{}]*\{[^{}]*width:\s*([0-9.]+)%/', $html, $m);
            $tong += (float) $m[1];
        }

        return $tong;
    }

    /** Người dùng & vai trò — hai bảng, mỗi bảng một bộ cột riêng. */
    public function test_bang_nguoi_dung_fixed_va_du_100(): void
    {
        $html = $this->trang('/admin/users');

        $khoi = $this->khoiCss($html, '.usr-table { width: 100%;', '.usr-check {');
        $this->assertStringContainsString('table-layout: fixed', $khoi);
        $this->assertStringNotContainsString('min-width', $khoi);

        $this->assertEqualsWithDelta(100.0, $this->tongPhanTram($html, [
            'usr-table th.usr-c-check', 'usr-table th.usr-c-stt', 'usr-table th.usr-c-name',
            'usr-table th.usr-c-phone', 'usr-table th.usr-c-role', 'usr-table th.usr-c-status',
            'usr-table th.usr-c-login', 'usr-table th.usr-c-date', 'usr-table th.usr-c-act',
        ]), 0.01);

        $this->assertEqualsWithDelta(100.0, $this->tongPhanTram($html, [
            'usr-table th.usr-c-rname', 'usr-table th.usr-c-rcode', 'usr-table th.usr-c-rdesc',
            'usr-table th.usr-c-rcount', 'usr-table th.usr-c-act',
        ]), 0.01);
    }

    /**
     * Tổng quan — hai bảng "Top …" nằm trong THẺ, không phải cả trang.
     *
     * Thẻ chỉ rộng chừng 260px ở khổ 1366 nên đây là bảng chật nhất trong các
     * màn: để `auto` là một tên hàng dài đẩy bảng tràn ra khỏi thẻ.
     */
    public function test_bang_top_cua_tong_quan_fixed_va_du_100(): void
    {
        $html = $this->trang('/admin/dashboard');

        $khoi = $this->khoiCss($html, '.db-table { table-layout: fixed;', '</style>');
        $this->assertStringNotContainsString('min-width', $khoi);

        $this->assertEqualsWithDelta(100.0, $this->tongPhanTram($html, [
            'db-table .db-c-stt', 'db-table .db-c-name', 'db-table .db-c-val',
        ]), 0.01);

        // Bảng chi nhánh in tiền nên chia lại hai cột sau — vẫn phải đủ 100.
        $this->assertEqualsWithDelta(100.0 - 15.0, $this->tongPhanTram($html, [
            'db-table--money .db-c-name', 'db-table--money .db-c-val',
        ]), 0.01);
    }

    /** Banner — trước đây ép min-width 1300px, tức tràn ở cả 1366 lẫn 1440. */
    public function test_bang_banner_khong_min_width(): void
    {
        $html = $this->trang('/admin/banners');

        $khoi = $this->khoiCss($html, '.bnr-table { width: 100%;', '.bnr-check {');
        $this->assertStringContainsString('table-layout: fixed', $khoi);
        $this->assertStringNotContainsString('min-width', $khoi);

        $this->assertEqualsWithDelta(100.0, $this->tongPhanTram($html, [
            'bnr-table th.bnr-c-check', 'bnr-table th.bnr-c-order', 'bnr-table th.bnr-c-name',
            'bnr-table th.bnr-c-pos', 'bnr-table th.bnr-c-time', 'bnr-table th.bnr-c-state',
            'bnr-table th.bnr-c-status', 'bnr-table th.bnr-c-act',
        ]), 0.01);
    }

    /**
     * Phiếu kiểm kho — tờ A4 in ra giấy.
     *
     * Ở đây nhãn cột KHÔNG được cắt "…": người cầm tờ giấy đi đếm phải đọc được
     * tên cột mới biết đang điền vào ô nào. Nên `nowrap` đi kèm cột đủ rộng.
     */
    public function test_phieu_kiem_kho_nhan_cot_mot_dong(): void
    {
        // Phiếu rỗng là 404 (không in tờ giấy trắng), nên phải có ít nhất một dòng.
        $html = $this->trang('/admin/inventory/stocktake', [
            '*/admin/inventory/chi-nhanh*' => Http::response([
                'data' => ['dong' => [[
                    'shop_id' => 1, 'shop_name' => 'Chi nhánh 1', 'sku' => 'SP000123-XL',
                    'product_name' => 'Áo thun cổ tròn', 'variant_name' => 'Xanh', 'unit_name' => 'Cái',
                    'on_hand' => 12, 'cost_price' => 23700000,
                ]]],
                'meta' => ['page' => 1, 'page_size' => 200, 'total' => 1, 'total_pages' => 1],
            ]),
        ]);

        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('letter-spacing: .02em; text-align: center; white-space: nowrap;', $html);

        $this->assertEqualsWithDelta(100.0, $this->tongPhanTram($html, [
            'st-c-stt', 'st-c-sku', 'st-c-name', 'st-c-var', 'st-c-sys',
            'st-c-cost', 'st-c-count', 'st-c-diff', 'st-c-note',
        ]), 0.01);
    }
}
