<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phiếu in đơn hàng — khối tổng ở cuối phiếu (resources/views/orders/print.blade.php).
 *
 * Bài ở đây canh một điều duy nhất nhưng là điều quan trọng nhất của tờ phiếu:
 * CÁC DÒNG PHẢI CỘNG RA ĐÚNG "Tổng thanh toán". Khách cầm phiếu về cộng lại, lệch
 * một dòng là mất lòng tin vào cả tờ phiếu — mà phiếu cũ lệch thật: nó in Tiền
 * hàng, Giảm giá, Phí vận chuyển rồi nhảy sang Tổng, bỏ hẳn thuế và phụ thu.
 *
 * Nên bài không đi đếm có bao nhiêu dòng hay dòng nào đứng trước dòng nào, mà bóc
 * số tiền của từng dòng rồi tự cộng lại y như khách sẽ làm.
 */
class OrderPrintTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    /**
     * Một đơn đủ trường để dựng phiếu. $them ghi đè phần tiền của từng tình huống.
     *
     * Số mặc định lấy của DH000024 trên dev: 22.000.000 tiền hàng, thuế 10% cộng
     * thêm 2.200.000, tổng 24.200.000.
     */
    protected function don(array $them = []): array
    {
        return array_merge([
            'id' => 900018,
            'order_code' => 'DH000024',
            'created_at' => '2026-09-28T16:48:00+07:00',
            'recipient_name' => 'Chị Lan',
            'recipient_phone' => '0900000001',
            'shipping_address' => '12 Lê Lợi',
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'subtotal_amount' => 22000000,
            'discount_amount' => 0,
            'promotion_discount' => 0,
            'order_discount_percent' => 0,
            'order_discount_amount' => 0,
            'rank_discount' => 0,
            'points_used' => 0,
            'points_amount' => 0,
            'points_earned' => 0,
            'shipping_fee' => 0,
            'surcharge_amount' => 0,
            'surcharge_note' => '',
            'vat_amount' => 2200000,
            'total_amount' => 24200000,
            'voucher_code' => '',
            'items' => [[
                'product_name' => 'Iphone 16 Pro Max', 'variant_sku' => 'IP16PM',
                'quantity' => 1, 'unit_price' => 22000000, 'total_price' => 22000000,
            ]],
        ], $them);
    }

    protected function fakeApi(array $don): void
    {
        Http::fake([
            '*/admin/orders/*/hoa-don-dien-tu*' => Http::response(['data' => null], 404),
            '*/admin/orders/*' => Http::response(['data' => $don]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** HTML của phiếu in một đơn. */
    protected function phieu(array $don): string
    {
        $this->fakeApi($don);

        return $this->withSession($this->phien())
            ->get('/admin/orders/'.$don['id'].'/print')->assertOk()->getContent();
    }

    /**
     * Khối tổng của phiếu → ['Tiền hàng' => 22000000.0, …].
     *
     * Đọc đúng những gì MẮT KHÁCH thấy: nhãn và con số đã in, kể cả dấu trừ.
     */
    protected function khoiTong(string $html): array
    {
        $dau = strpos($html, '<div class="inv-sum">');
        $this->assertNotFalse($dau, 'phiếu không có khối tổng');
        $khoi = substr($html, $dau, strpos($html, '</div>', strpos($html, 'is-total', $dau)) - $dau);

        preg_match_all('/<span>([^<]+)<\/span><span>(-?)([\d.]+)₫<\/span>/u', $khoi, $m, PREG_SET_ORDER);

        $ra = [];
        foreach ($m as $d) {
            $ra[trim(html_entity_decode($d[1]))] = ($d[2] === '-' ? -1 : 1) * (float) str_replace('.', '', $d[3]);
        }

        return $ra;
    }

    /**
     * Đơn có thuế: phiếu in dòng "Thuế VAT", và các dòng cộng ra đúng tổng.
     *
     * Đây là chính lỗi được báo — DH000024: 22.000.000 − 0 + 0 = 22.000.000 nhưng
     * Tổng thanh toán in 24.200.000, thiếu đúng 2.200.000 tiền thuế.
     */
    public function test_khoi_tong_cong_ra_dung_tong_thanh_toan(): void
    {
        $dong = $this->khoiTong($this->phieu($this->don()));

        $this->assertArrayHasKey('Thuế VAT', $dong);
        $this->assertSame(2200000.0, $dong['Thuế VAT']);

        $tong = $dong['Tổng thanh toán'];
        unset($dong['Tổng thanh toán']);
        $this->assertSame($tong, array_sum($dong),
            'các dòng cộng lại không ra Tổng thanh toán: '.json_encode($dong, JSON_UNESCAPED_UNICODE));
        $this->assertSame(24200000.0, $tong);
    }

    /**
     * Đơn của khách quen: giảm giá gộp nhiều nguồn thì phải kê ra.
     *
     * DH000025 trên dev: giảm 9.560.000 = hạng thẻ 9.460.000 + 100 điểm đổi được
     * 100.000. Nhìn một con số 9.560.000 thì khách không biết điểm mình đổi được
     * bao nhiêu, mà đó đúng là thứ khách muốn kiểm.
     */
    public function test_don_khach_quen_ke_hang_the_va_diem(): void
    {
        $html = $this->phieu($this->don([
            'id' => 900019, 'order_code' => 'DH000025',
            'discount_amount' => 9560000, 'rank_discount' => 9460000,
            'points_used' => 100, 'points_amount' => 100000, 'points_earned' => 1368,
            'vat_amount' => 1244000, 'total_amount' => 13684000,
        ]));

        $dong = $this->khoiTong($html);
        $tong = $dong['Tổng thanh toán'];
        unset($dong['Tổng thanh toán']);
        $this->assertSame($tong, array_sum($dong),
            'các dòng cộng lại không ra Tổng thanh toán: '.json_encode($dong, JSON_UNESCAPED_UNICODE));
        $this->assertSame(13684000.0, $tong);
        $this->assertSame(-9560000.0, $dong['Giảm giá']);

        // Dòng kê: đủ cả hai nguồn, và số tiền của chúng cộng lại ra đúng số Giảm giá.
        $this->assertStringContainsString('hạng thẻ thành viên 9.460.000₫', $html);
        $this->assertStringContainsString('điểm đã dùng (100 điểm) 100.000₫', $html);

        // Điểm tích luỹ in ngoài khối tổng — nó là điểm, không phải tiền.
        $this->assertStringContainsString('Điểm tích luỹ từ đơn này', $html);
        $this->assertStringContainsString('1.368', $html);
        $this->assertArrayNotHasKey('Điểm tích luỹ từ đơn này', $dong);
    }

    /** Giảm bằng mã: API không trả riêng số tiền của mã, phần giảm còn lại là của nó. */
    public function test_giam_bang_ma_ke_ten_ma(): void
    {
        $html = $this->phieu($this->don([
            'id' => 900017, 'order_code' => 'DH000023',
            'subtotal_amount' => 28000000, 'discount_amount' => 560000,
            'voucher_code' => '20E3DSS10', 'vat_amount' => 2744000, 'total_amount' => 30184000,
        ]));

        $this->assertStringContainsString('mã 20E3DSS10 560.000₫', $html);

        $dong = $this->khoiTong($html);
        $tong = $dong['Tổng thanh toán'];
        unset($dong['Tổng thanh toán']);
        $this->assertSame($tong, array_sum($dong));
    }

    /** Phụ thu: in kèm lý do, và cộng vào tổng. */
    public function test_phu_thu_in_kem_ly_do(): void
    {
        $html = $this->phieu($this->don([
            'surcharge_amount' => 50000, 'surcharge_note' => 'Gói quà',
            'total_amount' => 24250000,
        ]));

        $dong = $this->khoiTong($html);
        $this->assertArrayHasKey('Phụ thu (Gói quà)', $dong);
        $this->assertSame(50000.0, $dong['Phụ thu (Gói quà)']);

        $tong = $dong['Tổng thanh toán'];
        unset($dong['Tổng thanh toán']);
        $this->assertSame($tong, array_sum($dong));
    }

    /**
     * Đơn không thuế, không phụ thu thì KHÔNG in hai dòng ấy.
     *
     * Đơn đặt trên web tính theo mạch khác (tiền hàng − giảm + phí vận chuyển),
     * không có thuế — in thêm hai dòng "0₫" chỉ làm tờ phiếu dài ra.
     */
    public function test_khong_thue_khong_phu_thu_thi_khong_in_dong_rong(): void
    {
        $dong = $this->khoiTong($this->phieu($this->don([
            'subtotal_amount' => 500000, 'shipping_fee' => 30000,
            'vat_amount' => 0, 'surcharge_amount' => 0, 'total_amount' => 530000,
        ])));

        $this->assertArrayNotHasKey('Thuế VAT', $dong);
        $this->assertArrayNotHasKey('Phụ thu', $dong);
        $this->assertSame(['Tiền hàng' => 500000.0, 'Phí vận chuyển' => 30000.0, 'Tổng thanh toán' => 530000.0], $dong);
    }

    /**
     * Giảm nhiều hơn tiền hàng: in đúng phần ĐÃ trừ.
     *
     * API chặn tổng ở 0 rồi mới cộng thuế và phụ thu. Nếu phiếu in nguyên con số
     * giảm to hơn tiền hàng thì khách cộng ra số âm, trong khi tổng lại dương.
     */
    public function test_giam_lon_hon_tien_hang_thi_in_dung_phan_da_tru(): void
    {
        $dong = $this->khoiTong($this->phieu($this->don([
            'subtotal_amount' => 1000000, 'discount_amount' => 1500000,
            'promotion_discount' => 1500000,
            'vat_amount' => 0, 'total_amount' => 0,
        ])));

        $this->assertSame(-1000000.0, $dong['Giảm giá']);
        $tong = $dong['Tổng thanh toán'];
        unset($dong['Tổng thanh toán']);
        $this->assertSame($tong, array_sum($dong));
        $this->assertSame(0.0, $tong);
    }

    /**
     * Kê không khớp đủ số Giảm giá thì THÔI kê.
     *
     * Đơn do nhân viên lập tay chỉ có discount_amount, không có trường nào nói
     * tiền ấy từ đâu. Một dòng "Trong đó" cộng không ra con số ở trên còn khó
     * hiểu hơn là không có dòng nào.
     */
    public function test_khong_biet_giam_tu_dau_thi_khong_ke(): void
    {
        $html = $this->phieu($this->don([
            'subtotal_amount' => 1000000, 'discount_amount' => 200000,
            'vat_amount' => 0, 'total_amount' => 800000,
        ]));

        $this->assertStringNotContainsString('Trong đó:', $html);

        $dong = $this->khoiTong($html);
        $tong = $dong['Tổng thanh toán'];
        unset($dong['Tổng thanh toán']);
        $this->assertSame($tong, array_sum($dong));
    }
}
