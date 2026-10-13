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

        // Đếm theo HẰNG, không ghi số cứng: thêm/bớt một cột là bài này tự theo,
        // thay vì đỏ vì đếm lệch rồi người sửa chỉ việc đổi con số cho xanh.
        $soCot = count(\App\Http\Controllers\OrderController::COT_CRM_RONG_TOI_THIEU);
        preg_match_all('/none_mobile th[^{]*\{ width: ([0-9.]+)%/', $khoi, $m);
        $this->assertCount($soCot, $m[1], 'phải khai đủ '.$soCot.' cột');
        $this->assertEqualsWithDelta(100.0, array_sum(array_map('floatval', $m[1])), 0.01);
    }

    /** Bốn cột tách nhỏ tắt sẵn, sàn bề rộng tính theo đúng cột đang bật. */
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

        // "Khác" chỉ bày khi kỳ có tiền mã cũ — kỳ rỗng ở đây thì không tính vào sàn.
        $tatCa = array_sum($C::COT_CRM_RONG_TOI_THIEU) - $C::COT_CRM_RONG_TOI_THIEU['khac'];
        $rongMacDinh = $tatCa
            - array_sum(array_map(fn ($c) => $C::COT_CRM_RONG_TOI_THIEU[$c], $C::COT_CRM_MAC_DINH_TAT));
        $this->assertStringContainsString('--dh-rong: '.$rongMacDinh.'px', $html);
        // Vừa khung thẻ khổ 1440 (1132px) ở cỡ chữ đầy đủ.
        $this->assertLessThan(1132, $rongMacDinh);

        // `hide=` rỗng = bật hết, không được quay về bộ tắt sẵn.
        $html = $this->withSession($this->phienQuanTri())->get($url.'?hide=')->assertOk()->getContent();
        $this->assertStringNotContainsString(' hide"', $html);
        $this->assertStringContainsString('--dh-rong: '.$tatCa.'px', $html);
    }

    /**
     * Kỳ có đơn mã cũ (VNPay…) thì bày cột "Khác" — cùng cách xếp với báo cáo bán
     * hàng — sàn bề rộng tính cả cột ấy, và "Đã thu" cộng cả khoản đó.
     */
    public function test_cot_khac_chi_bay_khi_co_tien_ma_cu(): void
    {
        $don = ['loai' => 'don', 'id' => 41, 'ma' => 'DH041', 'kenh' => 'web', 'khach_hang' => 'Khách cũ',
            'created_at' => '2026-10-12T10:00:00+07:00', 'tien_mat' => 0, 'chuyen_khoan' => 0, 'quet_the' => 0,
            'qr_tu_dong' => 0, 'khac' => 24200000, 'tong_tien' => 24200000, 'co_cong_no' => false, 'trang_thai' => 'paid'];
        Http::fake([
            '*/admin/orders/so-don*' => Http::response(['data' => [$don], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 1,
                'total_pages' => 1, 'tong' => ['khac' => 24200000, 'tong_tien' => 24200000]]]),
            '*' => Http::response(['data' => []]),
        ]);

        $C = \App\Http\Controllers\OrderController::class;
        $html = $this->withSession($this->phienQuanTri())->get(route('admin.crm.orders.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<th class="[^"]*show_khac/', $html);
        $this->assertMatchesRegularExpression('/show_khac">24\.200\.000</', $html);
        $this->assertMatchesRegularExpression('/show_paid\s*">24\.200\.000</', $html, '"Đã thu" phải cộng cả tiền "Khác"');
        $rong = array_sum($C::COT_CRM_RONG_TOI_THIEU)
            - array_sum(array_map(fn ($c) => $C::COT_CRM_RONG_TOI_THIEU[$c], $C::COT_CRM_MAC_DINH_TAT));
        $this->assertStringContainsString('--dh-rong: '.$rong.'px', $html);

        $html = $this->withSession($this->phienQuanTri())->get(route('admin.orders.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/show_khac">24\.200\.000</', $html);

        $csv = $this->withSession($this->phienQuanTri())->get(route('admin.orders.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"QR tự động",Khác,', $csv);
        $this->assertStringContainsString(',0,24200000,-,24200000,', $csv);
    }

    /**
     * Đơn thu bằng hình thức ĐANG TẮT không được đọc ra như chưa thu.
     *
     * CRM tắt sẵn cả bốn cột hình thức, nên đơn quẹt thẻ DH000043 (thu đủ
     * 24.200.000) hiện "Tiền mặt 0 · Chuyển khoản 0 · Công nợ —" — ba con số
     * đang bày đều bằng 0, đọc như đơn chưa thu đồng nào.
     *
     * Bày đủ bốn cột thì hết nói dối nhưng bảng tràn khung ở 1280 / 1366 / 1536
     * (đo thật: thừa 75 / 3 / 66px). Nên thay bằng MỘT cột "Đã thu" gộp — vừa
     * đúng vừa hẹp hơn hiện tại.
     */
    public function test_don_thu_bang_hinh_thuc_dang_tat_van_hien_so_da_thu(): void
    {
        Http::fake([
            '*/admin/orders*' => Http::response([
                'data' => [[
                    'id' => 900037, 'order_code' => 'DH000043', 'channel' => 'pos',
                    'created_at' => '2026-10-12T14:27:00+07:00', 'status' => 'completed',
                    'payment_status' => 'paid', 'payment_method' => 'card',
                    'total_amount' => 24200000, 'discount_amount' => 0, 'shipping_fee' => 0,
                    // Thu TRỌN bằng quẹt thẻ — cột hình thức tắt sẵn.
                    'tien_mat' => 0, 'chuyen_khoan' => 0, 'quet_the' => 24200000, 'qr_tu_dong' => 0,
                ]],
                'meta' => ['page' => 1, 'page_size' => 20, 'total' => 1, 'total_pages' => 1],
            ]),
            '*' => Http::response(['data' => []]),
        ]);

        $C = \App\Http\Controllers\OrderController::class;
        $html = $this->withSession($this->phienQuanTri())
            ->get(route('admin.crm.orders.index'))->assertOk()->getContent();

        // Cột gộp bật sẵn, bốn cột hình thức tắt sẵn.
        $this->assertMatchesRegularExpression('/<th class="[^"]*show_paid\s*"/', $html, 'cột "Đã thu" phải bật sẵn');
        foreach (['cash', 'transfer', 'card', 'auto_qr'] as $cot) {
            $this->assertContains($cot, $C::COT_CRM_MAC_DINH_TAT, "cột $cot phải tắt sẵn ở CRM");
        }

        // Và con số thật sự hiện ra, không phải 0.
        $this->assertMatchesRegularExpression('/show_paid\s*">24\.200\.000</', $html,
            'đơn thu đủ bằng quẹt thẻ mà cột "Đã thu" không ra số');

        // Ba hằng phải khai cùng một bộ cột, lệch nhau là bảng tính sai bề rộng.
        foreach (array_keys($C::COT_CRM) as $cot) {
            $this->assertArrayHasKey($cot, $C::COT_CRM_RONG_TOI_THIEU, "cột $cot thiếu bề rộng tối thiểu");
        }
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

        // colspan = 2 cột cố định (STT, Hành động) + số cột bật/tắt đang BẬT.
        $C = \App\Http\Controllers\OrderController::class;
        $batSan = count($C::COT_CRM) - count($C::COT_CRM_MAC_DINH_TAT);
        $this->assertStringContainsString('colspan="'.(2 + $batSan).'"', $html);

        $html = $this->withSession($this->phienQuanTri())->get($url.'?hide=')->assertOk()->getContent();
        $this->assertStringContainsString('colspan="'.(2 + count($C::COT_CRM)).'"', $html);
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
