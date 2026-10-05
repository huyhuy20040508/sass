<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM → Danh sách đơn hàng — khuôn crm/orders của v2: cùng sổ đơn với Quản lý
 * đơn hàng, bảng mở đầu bằng Mã khách hàng + Tên khách hàng.
 */
class CrmOrderTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    /**
     * BẢNG VỪA KHUNG THẺ Ở MỌI KHỔ, KHÔNG BẺ DÒNG, KHÔNG CẮT CHỮ.
     *
     * Mười bốn cột để một dòng cần 1296px, trong khi khung rộng 999px (khổ
     * 1280) tới 1532px (khổ 1920). Cách cũ — `table-layout: auto` + nén chữ
     * theo khổ màn — vỡ kiểu nhảy cóc: vừa ở 1366 (đang nén chữ) và 1920 (khung
     * đủ rộng) nhưng thừa 84–137px ở 1440/1536/1600, vì bảng tự co theo nội
     * dung nên cỡ chữ đổi một nhịp là bề rộng nhảy theo.
     */
    public function test_bang_crm_don_hang_chia_phan_tram_du_100(): void
    {
        Http::fake([
            '*/admin/orders*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanTri())
            ->get(route('admin.crm.orders.index'))->assertOk()->getContent();

        $dau = strpos($html, 'table.table-don-hang.none_mobile {');
        $this->assertNotFalse($dau, 'không thấy khối CSS của bảng');
        $khoi = substr($html, $dau, 3000);

        $this->assertStringContainsString('table-layout: fixed', $khoi);
        $this->assertStringNotContainsString('table-layout: auto', $khoi);
        $this->assertStringNotContainsString('text-overflow', $khoi);
        $this->assertStringNotContainsString('line-clamp', $khoi);

        preg_match_all('/none_mobile th[^{]*\{ width: ([0-9.]+)%/', $khoi, $m);
        $this->assertCount(14, $m[1], 'phải khai đủ 14 cột');
        $this->assertEqualsWithDelta(100.0, array_sum(array_map('floatval', $m[1])), 0.01);
    }

    /** Ba cột tách nhỏ tắt sẵn, sàn bề rộng tính theo đúng cột đang bật. */
    public function test_cot_tat_san_va_san_rong_theo_cot_dang_bat(): void
    {
        Http::fake([
            '*/admin/orders*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);

        $C = \App\Http\Controllers\OrderController::class;
        $url = route('admin.crm.orders.index');

        $html = $this->withSession($this->phienQuanTri())->get($url)->assertOk()->getContent();
        foreach ($C::COT_CRM_MAC_DINH_TAT as $cot) {
            $this->assertMatchesRegularExpression('/<th class="[^"]*show_'.$cot.'\s+hide"/', $html, "cột $cot phải tắt sẵn");
        }
        $this->assertMatchesRegularExpression('/<th class="[^"]*show_total\s*"/', $html, 'Tổng tiền phải bật sẵn');

        $rongMacDinh = array_sum($C::COT_CRM_RONG_TOI_THIEU)
            - array_sum(array_map(fn ($c) => $C::COT_CRM_RONG_TOI_THIEU[$c], $C::COT_CRM_MAC_DINH_TAT));
        $this->assertStringContainsString('--dh-rong: '.$rongMacDinh.'px', $html);
        // Vừa khung thẻ khổ 1440 (1132px) ở cỡ chữ đầy đủ.
        $this->assertLessThan(1132, $rongMacDinh);

        // `hide=` rỗng = bật hết, không được quay về bộ tắt sẵn.
        $html = $this->withSession($this->phienQuanTri())->get($url.'?hide=')->assertOk()->getContent();
        $this->assertStringNotContainsString(' hide"', $html);
        $this->assertStringContainsString('--dh-rong: '.array_sum($C::COT_CRM_RONG_TOI_THIEU).'px', $html);
    }

    /**
     * BẢNG HÀNG TRONG HỘP CHI TIẾT phải vừa cột trái của hộp.
     *
     * Hộp rộng cố định 1100px, cột trái chứa bảng chỉ 716px. Để `auto` thì một
     * tên hàng dài (biến thể nhiều thuộc tính + SKU dính liền) đẩy bảng ra
     * 842px — thừa 126px, cắt mất hai cột cuối "Số lượng" và "Thành tiền", và
     * dòng "Tổng tiền hàng" cũng cụt. Hộp thoại hay lọt lưới vì phải mở ra mới
     * thấy.
     *
     * Luật khai theo `.modal` chứ không theo id một hộp: hộp phiếu trả dùng
     * chung class `bang-hang`, khai riêng là sửa một chỗ quên chỗ kia.
     */
    public function test_bang_hang_trong_hop_vua_cot(): void
    {
        Http::fake([
            '*/admin/orders*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);

        foreach (['admin.crm.orders.index', 'admin.orders.index'] as $ten) {
            $html = $this->withSession($this->phienQuanTri())->get(route($ten))->assertOk()->getContent();

            $this->assertStringContainsString('.modal table.bang-hang { width: 100%; table-layout: fixed; }', $html, $ten);

            preg_match_all('/\.modal table\.bang-hang th:nth-child\(\d\) \{ width: ([0-9.]+)%/', $html, $m);
            $this->assertCount(5, $m[1], "$ten: bảng hàng phải khai đủ 5 cột");
            $this->assertEqualsWithDelta(100.0, array_sum(array_map('floatval', $m[1])), 0.01, $ten);

            // Tên hàng + dòng SKU được xuống dòng, kể cả ngắt giữa từ.
            $this->assertMatchesRegularExpression(
                '/td:nth-child\(2\)[^{]*\{\s*white-space: normal; overflow-wrap: anywhere;/s',
                $html,
                "$ten: ô tên hàng phải được xuống dòng"
            );
        }
    }

    /** Ô cỡ trang bày đúng bộ của v2, và dòng rỗng trải đúng số cột đang bật. */
    public function test_co_trang_dung_bo_v2_va_colspan_theo_cot(): void
    {
        Http::fake([
            '*/admin/orders*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);

        $url = route('admin.crm.orders.index');
        $html = $this->withSession($this->phienQuanTri())->get($url)->assertOk()->getContent();

        foreach ([10, 20, 30, 40, 50] as $muc) {
            $this->assertStringContainsString('value="'.$muc.'"', $html, "thiếu mức $muc dòng/trang");
        }
        $this->assertStringNotContainsString('value="100"', $html, '100 dòng/trang không thuộc bộ của v2');

        // 2 cột cố định + 12 cột bật/tắt − 3 cột tắt sẵn = 11.
        $this->assertStringContainsString('colspan="11"', $html);

        $html = $this->withSession($this->phienQuanTri())->get($url.'?hide=')->assertOk()->getContent();
        $this->assertStringContainsString('colspan="14"', $html);
    }

    public function test_bang_mo_dau_bang_hai_cot_khach(): void
    {
        Http::fake([
            '*/admin/orders/so-don*' => Http::response([
                'data' => [
                    ['loai' => 'don', 'id' => 31, 'ma' => 'DH031', 'kenh' => 'pos', 'ma_khach' => 'cus-00007',
                        'khach_hang' => 'Nguyễn An', 'tong_tien' => 100000, 'trang_thai' => 'paid', 'xuat_duoc_hoa_don' => true],
                    // Khách vãng lai: không hồ sơ, không tên.
                    ['loai' => 'don', 'id' => 32, 'ma' => 'DH032', 'kenh' => 'pos', 'ma_khach' => '',
                        'khach_hang' => '', 'tong_tien' => 50000, 'trang_thai' => 'paid'],
                ],
                'meta' => ['page' => 1, 'page_size' => 20, 'total' => 2, 'total_pages' => 1],
            ]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phienQuanTri())
            ->get(route('admin.crm.orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/sub-nav-btn active">\s*Danh sách đơn hàng/', $html);
        $this->assertStringContainsString('cus-00007', $html);
        $this->assertStringContainsString('Bán cho người tiêu dùng', $html);
        $this->assertStringContainsString('show_customer_code', $html);
        // Nút xuất HĐĐT và hộp của nó đi theo như màn Quản lý đơn hàng.
        $this->assertSame(1, substr_count($html, 'class="xuat-hddt"'));
        $this->assertStringContainsString('id="modalXuatHddt"', $html);
    }

    public function test_man_quan_ly_don_hang_khong_doi(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1]])]);

        $html = $this->withSession($this->phienQuanTri())->get(route('admin.orders.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('show_customer_code', $html);
    }
}
