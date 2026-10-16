<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\FakeCashierViews;
use Tests\TestCase;

/**
 * PosController — phần giáp ranh giữa quầy và Go API.
 *
 * Nghiệp vụ (trừ kho, tính tiền, thối tiền) đã có bài kiểm trên API thật ở
 * api/internal/apitest. Ở đây soát: payload gửi đi, câu lỗi chuyển về, các nhánh
 * "API hỏng thì sao", và view + dữ liệu mỗi trang nhận.
 */
class PosTest extends TestCase
{
    use FakeCashierViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giaLapViewThuNgan();
    }

    /** Vai staff CỐ Ý: ai siết cụm quầy thành chỉ quản trị viên là bài này đỏ. */
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff']],
        ];
    }

    protected function gio(array $ghiDe = []): array
    {
        return array_merge([
            'payment_method' => 'cash',
            'amount_tendered' => 500000,
            'items' => [['product_variant_id' => 12, 'quantity' => 2]],
        ], $ghiDe);
    }

    protected function banXong(): array
    {
        return ['data' => ['order_id' => 88, 'order_code' => 'DH202608160088', 'total_amount' => 180000, 'change_amount' => 320000]];
    }

    protected function laChotDon($req): bool
    {
        return $req->method() === 'POST' && str_ends_with((string) parse_url($req->url(), PHP_URL_PATH), '/admin/orders/pos');
    }

    // ------------------------------------------------------------ chốt đơn (POST /cashier/sales)

    /** Điều quan trọng nhất: KHÔNG gửi tên/giá lên, dù trình duyệt có gửi kèm. */
    /**
     * Ô mã giảm giá ở quầy phải HỎI API ngay, không đợi tới lúc chốt.
     *
     * Ca lỗi 05/10: giỏ 28.000.000 + VAT 10% hiện 30.800.000, gõ mã 2% rồi Áp
     * dụng thì màn hình vẫn 30.800.000 và dòng giảm vẫn 0 đ. Chốt xong đơn ghi
     * giảm 560.000, VAT 2.744.000, thực thu 30.184.000 — lệch 616.000 đ so với
     * số đã hiện, và tiền thối tính theo số sai.
     */
    public function test_ma_giam_gia_hoi_api_ngay_khi_ap_dung(): void
    {
        Http::fake([
            '*/admin/orders/pos/voucher' => Http::response(['data' => ['code' => '20E3DSS10', 'name' => 'test', 'giam' => 560000]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.voucher'), [
                'items' => [['product_variant_id' => 1, 'quantity' => 1]],
                'code' => ' 20e3dss10 ',
                'phone' => '0909123456',
            ])
            ->assertOk()
            ->assertJsonPath('data.giam', 560000);

        // Mã viết hoa và cắt khoảng trắng trước khi sang API — cùng cách API chuẩn hoá.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/orders/pos/voucher')
            && $req['code'] === '20E3DSS10'
            && $req['phone'] === '0909123456'
            && $req['items'] === [['product_variant_id' => 1, 'quantity' => 1]]);
    }

    /**
     * Mã hỏng: hộp Giảm giá phải GIỮ LẠI và in câu lỗi vào trong hộp.
     *
     * Controller đã giữ nguyên câu của API (bài ngay dưới), nhưng quầy đóng hộp
     * trước rồi mới hỏi mã, nên mã sai trông y hệt mã đúng: hộp đóng, ô mã còn
     * chữ, tổng không đổi — chỉ thiếu dòng "Giảm giá (mã …)". Câu lỗi có bắn ra
     * báo nổi, mà báo nổi ở góc dưới trong 3,5 giây thì người đang nhìn vào hộp
     * không thấy; lúc hộp còn mở thì nó còn bị chính tờ hộp che, vì <dialog> mở
     * bằng showModal() nằm ở top layer còn khay toast chỉ có z-index 50.
     *
     * Bài canh HAI điều đo được từ khuôn — việc chạy thật thì đo bằng trình duyệt:
     *   1. hộp có khối báo lỗi riêng (như hộp Khách mới);
     *   2. lượt hỏi mã diễn ra TRƯỚC khi đóng hộp.
     */
    public function test_hop_giam_gia_giu_lai_khi_ma_hong(): void
    {
        $js = file_get_contents(resource_path('../v2/pos/sale.blade.php'));

        // 1. Khối báo lỗi nằm trong hộp Giảm giá.
        $dau = strpos($js, 'id="posGiamBox"');
        $this->assertNotFalse($dau, 'không tìm thấy hộp Giảm giá');
        $hop = substr($js, $dau, strpos($js, '</dialog>', $dau) - $dau);
        $this->assertStringContainsString('id="posGiamLoi"', $hop, 'hộp Giảm giá phải có khối báo lỗi riêng');

        // 2. Trong trình xử lý Áp dụng: hỏi mã TRƯỚC, đóng hộp SAU.
        $d = strpos($js, "\$('posGiamForm').addEventListener('submit'");
        $this->assertNotFalse($d, 'không tìm thấy trình xử lý Áp dụng giảm giá');
        $than = substr($js, $d, strpos($js, "\n    });", $d) - $d);

        $hoi = strpos($than, 'await hoiMaGiam(');
        $dong = strpos($than, "hop('posGiamBox').hide()");
        $this->assertNotFalse($hoi, 'phải CHỜ API trả lời mã rồi mới quyết định đóng hộp');
        $this->assertNotFalse($dong, 'không thấy chỗ đóng hộp');
        $this->assertLessThan($dong, $hoi, 'đóng hộp trước khi hỏi mã thì mã sai không còn chỗ nào để báo');
        // Mã hỏng thì dừng hẳn, không đi tiếp xuống nhánh đóng hộp.
        $this->assertMatchesRegularExpression('/catch \(err\) \{\s*loiGiam\(err\.message\);\s*return;/', $than);
    }

    /**
     * Phụ thu ÂM: giữ hộp lại và nói ra, không lặng lẽ đổi dấu.
     *
     * Gõ "-5000" thì ô tự thành "5.000" ngay lúc gõ (soTien lọc sạch ký tự không
     * phải chữ số), Lưu xong khối tổng ghi "Phụ thu 5.000 đ" — người nhập có ý
     * TRỪ mà khách bị CỘNG thêm. Chốt chặn phụ thu âm bên API (bài
     * test_chan_email_nguoi_mua_sai_dang_va_phu_thu_am) không bao giờ chạm tới,
     * vì số gửi lên đã dương từ trước.
     *
     * Bài canh ba điều đọc được từ khuôn:
     *   1. hộp có khối báo lỗi riêng;
     *   2. ô Giá trị đọc qua soPhuThu() — hàm GIỮ dấu — chứ không phải soNhap();
     *   3. lượt Lưu chặn số âm và DỪNG HẲN trước khi ghi vào hoá đơn.
     */
    public function test_hop_phu_thu_chan_so_am(): void
    {
        $js = file_get_contents(resource_path('../v2/pos/sale.blade.php'));

        $dau = strpos($js, 'id="posPhuThuBox"');
        $this->assertNotFalse($dau, 'không tìm thấy hộp Phụ thu');
        $hop = substr($js, $dau, strpos($js, '</dialog>', $dau) - $dau);
        $this->assertStringContainsString('id="posPhuThuLoi"', $hop, 'hộp Phụ thu phải có khối báo lỗi riêng');

        $d = strpos($js, "\$('posPhuThuForm').addEventListener('submit'");
        $this->assertNotFalse($d, 'không tìm thấy trình xử lý Lưu phụ thu');
        $than = substr($js, $d, strpos($js, "\n    });", $d) - $d);

        $this->assertStringContainsString('soPhuThu(', $than, 'phải đọc ô bằng hàm giữ dấu, không phải soNhap()');
        $this->assertMatchesRegularExpression('/if \(gt < 0\) \{\s*loiPhuThu\([^)]*\);\s*return;/', $than,
            'số âm phải dừng hẳn lượt Lưu, không đi tiếp xuống chỗ ghi vào hoá đơn');

        // Hàm đọc ô dùng chung với hộp Giảm giá; bài test của hộp kia canh phần
        // ruột (soCoDau thật sự đọc dấu) nên ở đây chỉ cần nó đi qua đúng hàm.
        $this->assertStringContainsString('const soPhuThu = (v) => soCoDau(v, kieuPhuThu)', $js);
    }

    /**
     * Phụ thu % quá 100: cũng nói ra rồi giữ hộp, không lặng lẽ hạ về 100.
     *
     * Lộ ra đúng lúc chạy lại lỗi phụ thu âm: chốt chặn số âm vừa thêm nằm ngay
     * trên dòng `gt = Math.min(100, …)` — gõ 150 thì hộp đóng êm, khối tổng ghi
     * "Phụ thu 22.000.000 đ" (= 100% tiền hàng) và người nhập tin là đã lưu
     * 150%. Hộp Giảm giá cùng màn kẹp mức giảm thì có báo, phụ thu thì im.
     *
     * Giữ trần 100% chứ không bỏ — phụ thu lớn hơn cả tiền hàng gần như luôn là
     * gõ nhầm, và ai thật sự cần số lớn hơn thì đã có ô VNĐ.
     */
    public function test_hop_phu_thu_chan_phan_tram_qua_tran(): void
    {
        $js = file_get_contents(resource_path('../v2/pos/sale.blade.php'));

        $d = strpos($js, "\$('posPhuThuForm').addEventListener('submit'");
        $this->assertNotFalse($d, 'không tìm thấy trình xử lý Lưu phụ thu');
        $than = substr($js, $d, strpos($js, "\n    });", $d) - $d);

        // Quá trần thì DỪNG HẲN, không đi tiếp xuống chỗ ghi vào hoá đơn.
        $this->assertMatchesRegularExpression('/if \(gt > 100\) \{\s*loiPhuThu\([^)]*\);\s*return;/', $than,
            'quá 100% phải báo rồi giữ hộp lại, không tự hạ xuống 100');
        // Và không còn chỗ nào lặng lẽ kẹp về 100.
        $this->assertStringNotContainsString('Math.min(100', $than,
            'còn Math.min(100…) nghĩa là vẫn có đường tự hạ số mà không nói');

        // Trần nói trước ở dòng gợi ý, đỡ phải gõ sai mới biết.
        $this->assertStringContainsString('Tối đa 100%.', $js);
    }

    /**
     * Ô "Giảm trên cả đơn" cũng không được lặng lẽ đổi số.
     *
     * Quét cả màn quầy sau khi sửa phụ thu thì lòi ra ba chỗ cùng tật, đều im:
     * gõ "-5000" (VNĐ) thì ô thành "5.000" ngay lúc gõ; gõ 150 (%) thì
     * `Math.min(100, gt)` hạ xuống 100 — tức giảm TRỌN tiền hàng, khách trả 0 đ
     * mà không một câu báo; gõ -5 (%) thì `soNhap` kẹp về 0.
     *
     * Chỗ 150% nặng hơn hẳn lỗi phụ thu: một lần gõ nhầm là cho không cả đơn.
     *
     * Lượt sau còn lòi thêm nhánh VNĐ: gõ 30.000.000 trên giỏ 22.000.000 cũng bị
     * kẹp im, nhưng chỗ kẹp nằm trong giamDon() chứ không ở lượt Áp dụng.
     *
     * Bài KHÔNG đòi gỡ phép kẹp trong giamDon(): nó còn lo trường hợp bớt hàng SAU
     * khi đã đặt mức giảm, lúc ấy không ai gõ sai cả. Chỗ phải nói là lượt Áp dụng.
     */
    public function test_o_giam_ca_don_khong_lang_le_doi_so(): void
    {
        $js = file_get_contents(resource_path('../v2/pos/sale.blade.php'));

        $d = strpos($js, "\$('posGiamForm').addEventListener('submit'");
        $this->assertNotFalse($d, 'không tìm thấy trình xử lý Áp dụng giảm giá');
        $than = substr($js, $d, strpos($js, "\n    });", $d) - $d);

        // Đọc ô bằng hàm GIỮ DẤU, không phải soNhap() (hàm kẹp âm về 0).
        $this->assertStringContainsString('soCoDau(', $than, 'phải đọc ô bằng hàm giữ dấu');
        $this->assertStringNotContainsString("soNhap(\$('posGiamDon')", $than);

        // Âm và quá trần đều DỪNG HẲN, không đi tiếp xuống chỗ ghi vào hoá đơn.
        $this->assertMatchesRegularExpression('/if \(gt < 0\) \{\s*loiGiam\([^)]*\);\s*return;/', $than,
            'mức giảm âm phải báo rồi giữ hộp lại');
        $this->assertMatchesRegularExpression('/gt > 100[^)]*\) \{\s*loiGiam\([^)]*\);\s*return;/', $than,
            'giảm quá 100% phải báo rồi giữ hộp lại');
        // Nhánh VNĐ: chỗ kẹp nằm trong giamDon() nên lượt Áp dụng không thấy để mà
        // báo — gõ 30 triệu trên giỏ 22 triệu thì hộp đóng êm, khách trả 0 đ, mở lại
        // hộp ô vẫn ghi 30 triệu. Phải chặn ngay ở lượt Áp dụng.
        $this->assertMatchesRegularExpression('/gt > tienHang\(\)\) \{\s*loiGiam\([^;]*\);\s*return;/', $than,
            'giảm bằng VNĐ quá tiền hàng phải báo rồi giữ hộp lại');
        // Và không còn đường tự hạ về 100 mà không nói.
        $this->assertStringNotContainsString('Math.min(100', $than);

        // Hàm đọc ô thật sự giữ dấu chứ không chỉ mang tên như vậy.
        $k = strpos($js, 'const soCoDau = (v, kieu) =>');
        $this->assertNotFalse($k, 'không tìm thấy soCoDau()');
        $this->assertStringContainsString("startsWith('-')", substr($js, $k, 400));
    }

    /** Mã hỏng thì trả nguyên câu lỗi của API để quầy bỏ mã, không nuốt đi. */
    public function test_ma_hong_tra_nguyen_cau_loi(): void
    {
        Http::fake([
            '*/admin/orders/pos/voucher' => Http::response(['message' => 'Mã giảm giá không còn hiệu lực'], 422),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.voucher'), [
                'items' => [['product_variant_id' => 1, 'quantity' => 1]],
                'code' => 'SAIBET',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mã giảm giá không còn hiệu lực');
    }

    /** Giỏ rỗng thì chặn ngay tại Laravel, khỏi làm phiền API. */
    public function test_gio_rong_thi_chan_ngay(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.voucher'), ['items' => [], 'code' => 'ABC'])
            ->assertStatus(422);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/admin/orders/pos/voucher'));
    }

    public function test_khong_gui_gia_len_api(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio([
                'items' => [['product_variant_id' => 12, 'quantity' => 2, 'price' => 1, 'name' => 'Áo', 'unit_price' => 1]],
            ]))
            ->assertOk()
            ->assertJsonPath('data.order_code', 'DH202608160088');

        Http::assertSent(function ($req) {
            if (! $this->laChotDon($req)) {
                return false;
            }
            $dong = $req['items'][0];

            return array_keys($dong) === ['product_variant_id', 'quantity', 'discount_percent']
                && $dong['product_variant_id'] === 12 && $dong['quantity'] === 2 && (float) $dong['discount_percent'] === 0.0;
        });
    }

    public function test_khach_le_khong_gui_user_id(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.store'), $this->gio())->assertOk();

        Http::assertSent(fn ($req) => $this->laChotDon($req) && ! array_key_exists('user_id', $req->data()));
    }

    public function test_khach_quen_gui_kem_user_id_va_cat_khoang_trang(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio(['user_id' => 5, 'customer_name' => '  Chị Lan ', 'voucher_code' => ' GIAM10 ']))
            ->assertOk();

        Http::assertSent(fn ($req) => $this->laChotDon($req)
            && $req['user_id'] === 5 && $req['customer_name'] === 'Chị Lan' && $req['voucher_code'] === 'GIAM10');
    }

    public function test_tien_mat_gui_tien_khach_dua(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.store'), $this->gio())->assertOk();

        Http::assertSent(fn ($req) => $this->laChotDon($req) && (float) $req['amount_tendered'] === 500000.0);
    }

    /** Tiền khách đưa không có nghĩa gì với một lệnh chuyển khoản. */
    public function test_chuyen_khoan_khong_gui_tien_khach_dua(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio(['payment_method' => 'bank_transfer']))
            ->assertOk();

        Http::assertSent(fn ($req) => $this->laChotDon($req) && ! array_key_exists('amount_tendered', $req->data()));
    }

    public function test_gui_kem_muc_giam_tung_dong(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio(['items' => [['product_variant_id' => 12, 'quantity' => 1, 'discount_percent' => 10]]]))
            ->assertOk();

        Http::assertSent(fn ($req) => $this->laChotDon($req) && (float) $req['items'][0]['discount_percent'] === 10.0);
    }

    /** Câu lỗi của API đi NGUYÊN VĂN về màn hình — nó nói rõ phải làm gì. */
    public function test_giu_nguyen_van_loi_cua_api(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response(['message' => 'Áo Real Madrid (M) chỉ còn 1 cái.'], 422)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Áo Real Madrid (M) chỉ còn 1 cái.');
    }

    public function test_api_loi_500_thanh_502_kem_cau_mac_dinh(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response(['message' => ''], 500)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio())
            ->assertStatus(502)
            ->assertJsonPath('message', 'Không hoàn tất được lượt bán.');
    }

    public function test_mat_ket_noi_api_thi_502(): void
    {
        Http::fake(['*/admin/orders/pos' => fn () => throw new ConnectionException('mất mạng')]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio())
            ->assertStatus(502)
            ->assertJsonPath('message', 'Không kết nối được API. Vui lòng thử lại.');
    }

    public function test_gio_trong_khong_goi_api(): void
    {
        Http::fake(['*' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio(['items' => []]))
            ->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'Chưa có sản phẩm nào trong giỏ.');

        Http::assertNotSent(fn ($req) => $this->laChotDon($req));
    }

    /** Quầy chỉ nhận hình thức mà tiền ĐÃ về trước khi khách rời quầy. */
    public function test_chan_hinh_thuc_thanh_toan_khong_thuoc_quay(): void
    {
        Http::fake(['*' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio(['payment_method' => 'vnpay']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        Http::assertNotSent(fn ($req) => $this->laChotDon($req));
    }

    public function test_chan_muc_giam_va_so_luong_vo_ly(): void
    {
        Http::fake(['*' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio(['items' => [['product_variant_id' => 12, 'quantity' => 0, 'discount_percent' => 150]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity', 'items.0.discount_percent']);

        Http::assertNotSent(fn ($req) => $this->laChotDon($req));
    }

    // ------------------------------------------------------------ quét mã (GET /cashier/sales/scan)

    public function test_quet_ma_tra_ve_mon_hang(): void
    {
        Http::fake(['*/admin/orders/pos/scan*' => Http::response(['data' => ['product_variant_id' => 12, 'price' => 90000, 'stock' => 7]])]);

        $this->withSession($this->phien())
            ->getJson(route('thu-ngan.ban-hang.scan', ['code' => '8938505970012']))
            ->assertOk()
            ->assertJsonPath('data.product_variant_id', 12);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'code=8938505970012'));
    }

    public function test_quet_ma_la_giu_nguyen_cau_cua_api(): void
    {
        Http::fake(['*/admin/orders/pos/scan*' => Http::response(['message' => 'Không có hàng nào mang mã 999.'], 404)]);

        $this->withSession($this->phien())
            ->getJson(route('thu-ngan.ban-hang.scan', ['code' => '999']))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Không có hàng nào mang mã 999.');
    }

    public function test_quet_khong_co_ma_thi_khong_goi_api(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->getJson(route('thu-ngan.ban-hang.scan', ['code' => '   ']))
            ->assertStatus(422);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/pos/scan'));
    }

    // ------------------------------------------------------------ trang bán hàng (GET /cashier/sales)

    public function test_trang_ban_hang_nhan_han_muc_giam_tu_api(): void
    {
        Http::fake([
            '*/admin/orders/pos/discount-limit' => Http::response(['data' => ['limit_percent' => 15]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ban-hang.index'))
            ->assertOk()
            ->assertViewIs('v2::pos.sale')
            ->assertViewHas('hanMucGiam', 15.0);
    }

    /** API hỏng thì hạn mức về 0 — không biết thì cho mức chặt nhất, không mở toang. */
    public function test_api_hong_thi_han_muc_giam_ve_0(): void
    {
        Http::fake(['*/admin/orders/pos/discount-limit' => Http::response([], 500), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ban-hang.index'))
            ->assertOk()
            ->assertViewHas('hanMucGiam', 0.0);
    }

    /** Hàng nút nhóm chỉ lấy CON TRỰC TIẾP của nhóm gốc "Hàng bán". */
    public function test_nhom_hang_chi_lay_nhom_con_cua_hang_ban(): void
    {
        Http::fake([
            '*/categories*' => Http::response(['data' => [
                ['id' => 1, 'name' => 'Hàng bán', 'slug' => 'hang-ban', 'parent_id' => null],
                ['id' => 2, 'name' => 'Đồ uống', 'slug' => 'do-uong', 'parent_id' => 1],
                ['id' => 3, 'name' => 'Cà phê', 'slug' => 'ca-phe', 'parent_id' => 2],
                ['id' => 9, 'name' => 'Hàng hoá khác', 'slug' => 'khac', 'parent_id' => null],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ban-hang.index'))
            ->assertOk()
            ->assertViewHas('nhomHang', [['id' => 2, 'name' => 'Đồ uống']]);
    }

    /** Hàng nút nhóm là lối tắt, không phải điều kiện để bán: API danh mục hỏng thì trang vẫn mở. */
    public function test_api_danh_muc_hong_thi_van_mo_trang(): void
    {
        Http::fake(['*/categories*' => Http::response([], 500), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ban-hang.index'))
            ->assertOk()
            ->assertViewHas('nhomHang', []);
    }

    // ------------------------------------------------------------ phiếu in (GET /cashier/sales/{id}/receipt)

    public function test_phieu_nhan_don_va_thong_tin_tiem(): void
    {
        Http::fake([
            '*/admin/orders/88' => Http::response(['data' => ['order_code' => 'DH202608170088', 'total_amount' => 162000, 'items' => []]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))
            ->assertOk()
            ->assertViewIs('v2::pos.receipt')
            ->assertViewHas('don', fn ($don) => $don['order_code'] === 'DH202608170088')
            ->assertViewHas(['tenCuaHang', 'diaChi', 'dienThoai'])
            ->assertViewHas('khoGiay', '80');
    }

    /** Khổ giấy chỉ nhận 58 hoặc 80; giá trị lạ trên URL rơi về 80 chứ không lọt vào CSS. */
    public function test_phieu_kho_giay_chi_nhan_58_hoac_80(): void
    {
        Http::fake(['*/admin/orders/88' => Http::response(['data' => ['order_code' => 'DH1', 'items' => []]]), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88, 'paper' => '58']))->assertViewHas('khoGiay', '58');
        $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88, 'paper' => '99mm;}']))->assertViewHas('khoGiay', '80');
    }

    /**
     * Mọi khoản trên phiếu phải cộng ra đúng TỔNG CỘNG: 600.000 − 30.000 bớt món
     * − 20.000 mã − 57.000 giảm tay − 10.000 KM − 5.000 hạng − 3.000 điểm
     * + 20.000 phụ thu + 47.500 VAT = 542.500.
     */
    public function test_phieu_tach_tung_khoan_giam_va_cong_ra_tong(): void
    {
        Http::fake([
            '*/admin/orders/88' => Http::response(['data' => [
                'order_code' => 'DH000088', 'placed_at' => '2026-10-07T09:30:00+07:00',
                'created_by_name' => 'Thu Ngân A', 'recipient_name' => 'Chị Lan', 'recipient_phone' => '0909123456',
                'subtotal_amount' => 570000, 'discount_amount' => 95000, 'voucher_code' => 'TQ20K',
                'order_discount_percent' => 10, 'order_discount_amount' => 57000,
                'promotion_discount' => 10000, 'rank_discount' => 5000, 'points_used' => 3, 'points_amount' => 3000,
                'surcharge_amount' => 20000, 'surcharge_note' => 'Gói quà', 'vat_amount' => 47500, 'shipping_fee' => 0,
                'total_amount' => 542500, 'payment_method' => 'cash', 'amount_tendered' => 600000, 'change_amount' => 57500,
                'points_earned' => 54,
                'items' => [
                    ['product_name' => 'Áo thun', 'variant_name' => 'Đen · L', 'unit_price' => 150000, 'quantity' => 2, 'discount_percent' => 10, 'discount_amount' => 30000],
                    ['product_name' => 'Quần jean', 'variant_name' => '', 'unit_price' => 300000, 'quantity' => 1, 'discount_amount' => 0],
                    ['product_name' => 'Móc khoá', 'variant_name' => '', 'unit_price' => 0, 'quantity' => 1, 'is_gift' => true],
                ],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->assertOk()->getContent();
        $phang = $this->chuPhieu($html);

        foreach ([
            // Nhãn theo bố cục "Gọn, rõ" (14/10/2026): dòng hình thức in số khách đưa, ngay dưới là tiền thối.
            'HOÁ ĐƠN BÁN HÀNG', 'Số: DH000088', '09:30 07/10/2026', 'Thu ngân: Thu Ngân A', 'Khách: Chị Lan · 0909123456',
            'Áo thun (Đen · L)', '2 × 150.000 300.000', 'Bớt 10% -30.000', 'Móc khoá (Tặng)',
            'Tiền hàng', '600.000', 'Bớt theo món -30.000', 'Mã TQ20K -20.000', 'Giảm cả đơn 10% -57.000',
            'Khuyến mãi -10.000', 'Giảm hạng -5.000', 'Đổi 3 điểm -3.000', 'Phụ thu: Gói quà 20.000',
            'Thuế VAT 47.500', 'TỔNG CỘNG 542.500', 'Tiền mặt 600.000', 'Tiền thối 57.500',
            'Điểm tích thêm +54',
        ] as $dong) {
            $this->assertStringContainsString($dong, $phang);
        }
        $this->assertStringNotContainsString('Phí giao hàng', $phang);
    }

    /** Đơn chuyển khoản không có tiền khách đưa: không in dòng thối 0 đ gây hiểu nhầm. */
    public function test_phieu_chuyen_khoan_khong_in_tien_thoi(): void
    {
        Http::fake([
            '*/admin/orders/88' => Http::response(['data' => [
                'order_code' => 'DH000089', 'total_amount' => 100000, 'subtotal_amount' => 100000, 'payment_method' => 'bank_transfer',
                'amount_tendered' => null, 'change_amount' => null,
                'items' => [['product_name' => 'Nón', 'unit_price' => 100000, 'quantity' => 1]],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $phang = $this->chuPhieu($this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->getContent());

        $this->assertStringContainsString('Chuyển khoản 100.000', $phang);
        $this->assertStringNotContainsString('Tiền thối', $phang);
        $this->assertStringNotContainsString('Mã giảm giá', $phang);
    }

    /** Chữ trên phiếu, mỗi thẻ thành một dấu cách — hai cột của một dòng không dính vào nhau. */
    protected function chuPhieu(string $html): string
    {
        $than = preg_replace('#<(style|script)\b.*?</\1>#su', ' ', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(preg_replace('/<[^>]+>/', ' ', $than))));
    }

    public function test_phieu_cua_don_khong_co_la_404(): void
    {
        Http::fake(['*/admin/orders/404' => Http::response(['message' => 'Không tìm thấy'], 404), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 404]))->assertNotFound();
    }

    // ------------------------------------------------------------ giảm cả đơn, phụ thu, hoá đơn điện tử

    public function test_gui_giam_ca_don_phu_thu_va_nguoi_mua_lay_hoa_don(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio([
                'order_discount_amount' => 18000,
                'surcharge_amount' => 20000,
                'surcharge_note' => ' Gói quà ',
                'customer_email' => 'ketoan@abc.vn',
                'buyer_tax_code' => ' 0101234567 ',
                'buyer_company' => 'Công ty ABC',
                'buyer_address' => '',
                'issue_einvoice' => true,
            ]))
            ->assertOk();

        Http::assertSent(fn ($req) => $this->laChotDon($req)
            && (float) $req['order_discount_amount'] === 18000.0
            && ! array_key_exists('order_discount_percent', $req->data())
            && (float) $req['surcharge_amount'] === 20000.0 && $req['surcharge_note'] === 'Gói quà'
            && $req['customer_email'] === 'ketoan@abc.vn'
            && $req['buyer_tax_code'] === '0101234567' && $req['buyer_company'] === 'Công ty ABC'
            && ! array_key_exists('buyer_address', $req->data())
            && $req['issue_einvoice'] === true);
    }

    /** Gửi cả hai kiểu giảm thì chỉ phần trăm đi lên — API kiểm hạn quyền trên phần trăm. */
    public function test_giam_ca_don_phan_tram_thang_so_tien(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio(['order_discount_percent' => 5, 'order_discount_amount' => 90000]))
            ->assertOk();

        Http::assertSent(fn ($req) => $this->laChotDon($req)
            && (float) $req['order_discount_percent'] === 5.0 && ! array_key_exists('order_discount_amount', $req->data()));
    }

    /** Lượt bán thường không mang khoá nào của ba tính năng mới. */
    public function test_ban_thuong_khong_gui_khoa_moi(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.ban-hang.store'), $this->gio())->assertOk();

        Http::assertSent(fn ($req) => $this->laChotDon($req)
            && array_intersect(array_keys($req->data()), [
                'order_discount_percent', 'order_discount_amount', 'surcharge_amount', 'surcharge_note',
                'customer_email', 'buyer_tax_code', 'buyer_company', 'buyer_address', 'issue_einvoice',
            ]) === []);
    }

    public function test_chan_email_nguoi_mua_sai_dang_va_phu_thu_am(): void
    {
        Http::fake(['*' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio(['customer_email' => 'khong-phai-email', 'surcharge_amount' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_email', 'surcharge_amount']);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ khách tại quầy (/cashier/sales/customers)

    /** Tra khách đi đường CỦA QUẦY — /admin/customers là khu của chủ tiệm, thu ngân gọi thì bị chặn. */
    public function test_tim_khach_tai_quay_di_duong_cua_quay(): void
    {
        Http::fake([
            '*/admin/orders/pos/khach-hang*' => Http::response(['data' => [
                ['id' => 5, 'full_name' => 'Chị Lan', 'phone' => '0909123456', 'email' => '', 'address' => 'Q1', 'tax_code' => ''],
            ]]),
            '*' => Http::response(['message' => 'Không có quyền'], 403),
        ]);

        $this->withSession($this->phien())
            ->getJson(route('thu-ngan.ban-hang.khach', ['q' => 'Lan']))
            ->assertOk()
            ->assertJsonPath('data.0.id', 5)
            ->assertJsonPath('data.0.name', 'Chị Lan')
            ->assertJsonPath('data.0.phone', '0909123456');

        Http::assertSent(fn ($req) => str_contains(urldecode($req->url()), '/admin/orders/pos/khach-hang') && str_contains(urldecode($req->url()), 'keyword=Lan'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/admin/customers'));
    }

    public function test_them_khach_moi_giu_cau_bao_va_ho_so_co_san(): void
    {
        Http::fake(['*/admin/orders/pos/khach-hang' => Http::response([
            'message' => 'Số 0909123456 đã có hồ sơ khách Chị Lan — đã chọn khách này.',
            'data' => ['customer' => ['id' => 5, 'full_name' => 'Chị Lan', 'phone' => '0909123456'], 'existed' => true],
        ])]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.taoKhach'), ['full_name' => '  Lan ', 'phone' => '0909123456', 'email' => ''])
            ->assertOk()
            ->assertJsonPath('message', 'Số 0909123456 đã có hồ sơ khách Chị Lan — đã chọn khách này.')
            ->assertJsonPath('data.customer.id', 5)
            ->assertJsonPath('data.customer.name', 'Chị Lan')
            ->assertJsonPath('data.existed', true);

        // Ô bỏ trống không gửi lên: email rỗng mà gửi thì API kiểm dạng email và từ chối.
        Http::assertSent(fn ($req) => $req->method() === 'POST' && $req['full_name'] === 'Lan' && ! array_key_exists('email', $req->data()));
    }

    public function test_them_khach_moi_tao_moi_tra_201(): void
    {
        Http::fake(['*/admin/orders/pos/khach-hang' => Http::response([
            'message' => 'Đã thêm khách Anh Minh.',
            'data' => ['customer' => ['id' => 9, 'full_name' => 'Anh Minh', 'phone' => '0911'], 'existed' => false],
        ], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.taoKhach'), ['full_name' => 'Anh Minh', 'phone' => '0911'])
            ->assertCreated()
            ->assertJsonPath('data.customer.id', 9)
            ->assertJsonPath('data.existed', false);
    }

    /** Thêm nhanh doanh nghiệp: gửi loại khách + MST để hoá đơn điện tử lấy được về sau. */
    public function test_them_khach_doanh_nghiep_gui_loai_va_mst(): void
    {
        Http::fake(['*/admin/orders/pos/khach-hang' => Http::response(['data' => ['customer' => ['id' => 11, 'full_name' => 'Công ty ABC', 'tax_code' => '0312345678'], 'existed' => false]], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.taoKhach'), ['full_name' => 'Công ty ABC', 'customer_type' => '1', 'tax_code' => ' 0312345678 ', 'address' => '1 Lê Lợi'])
            ->assertCreated()
            ->assertJsonPath('data.customer.tax_code', '0312345678');

        Http::assertSent(fn ($req) => $req['customer_type'] === 1 && $req['tax_code'] === '0312345678' && $req['address'] === '1 Lê Lợi');
    }

    /** Khách cá nhân có lỡ gõ MST thì cũng không gửi: hồ sơ cá nhân không mang MST. */
    public function test_them_khach_ca_nhan_bo_mst(): void
    {
        Http::fake(['*/admin/orders/pos/khach-hang' => Http::response(['data' => ['customer' => ['id' => 12, 'full_name' => 'Lan'], 'existed' => false]], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.taoKhach'), ['full_name' => 'Lan', 'customer_type' => '0', 'tax_code' => '0312345678'])
            ->assertCreated();

        Http::assertSent(fn ($req) => $req['customer_type'] === 0 && ! array_key_exists('tax_code', $req->data()));
    }

    public function test_them_khach_thieu_ten_khong_goi_api(): void
    {
        Http::fake(['*' => Http::response([], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.taoKhach'), ['full_name' => ' ', 'phone' => '0909'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name']);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ hoá đơn điện tử (/cashier/sales/{id}/einvoice)

    public function test_phat_hanh_hoa_don_giu_cau_bao_cua_api(): void
    {
        Http::fake(['*/admin/orders/pos/88/hoa-don-dien-tu' => Http::response([
            'message' => 'Đã lưu hoá đơn nháp 1C26MAB — bấm Ký để phát hành',
            'data' => ['status' => 'draft', 'symbol' => '1C26MAB'],
        ])]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.hoaDon', ['id' => 88]))
            ->assertOk()
            ->assertJsonPath('message', 'Đã lưu hoá đơn nháp 1C26MAB — bấm Ký để phát hành')
            ->assertJsonPath('data.status', 'draft');
    }

    /** "Chưa nối cổng" và "đã phát hành rồi" là hai việc khác nhau — câu và mã của API đi nguyên văn. */
    public function test_phat_hanh_hoa_don_loi_giu_nguyen_ma_va_cau(): void
    {
        Http::fake(['*/admin/orders/pos/88/hoa-don-dien-tu' => Http::response(['message' => 'đơn này đã phát hành hoá đơn rồi'], 409)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.hoaDon', ['id' => 88]))
            ->assertStatus(409)
            ->assertJsonPath('message', 'đơn này đã phát hành hoá đơn rồi');
    }

    public function test_phat_hanh_hoa_don_mat_ket_noi_thi_502(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('mất mạng')]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.hoaDon', ['id' => 88]))
            ->assertStatus(502);
    }

    // ------------------------------------------------------------ lưới hàng: Bán chạy / Hàng mới

    /** Chỉ hai kiểu xếp của quầy đi lên API; khoá lạ bị bỏ để trang tạo đơn giữ thứ tự người bán tự xếp. */
    public function test_tim_hang_chi_chuyen_hai_kieu_sap_xep_cua_quay(): void
    {
        Http::fake([
            '*/products*' => Http::response(['data' => [['id' => 1, 'name' => 'Áo', 'vat' => 10, 'variants' => []]], 'meta' => []]),
            '*' => Http::response(['data' => []]),
        ]);

        foreach (['best_selling' => 'best_selling', 'created_desc' => 'created_desc', 'price_desc' => null] as $gui => $mongDoi) {
            $this->withSession($this->phien())
                ->getJson(route('admin.orders.searchProducts', ['sort' => $gui]))
                ->assertOk()
                ->assertJsonPath('data.0.vat', 10);

            $cuoi = collect(Http::recorded())->map(fn ($r) => $r[0])->filter(fn ($r) => str_contains($r->url(), '/products'))->last();
            parse_str((string) parse_url($cuoi->url(), PHP_URL_QUERY), $q);
            $this->assertSame($mongDoi, $q['sort'] ?? null, "sort={$gui}");
        }
    }

    // ------------------------------------------------------------ mã đơn

    /**
     * Quầy KHÔNG xin mã trước nữa — mã chỉ sinh ra lúc đơn vào sổ.
     *
     * Bản trước giữ mã ngay khi có món đầu tiên để tab hiện mã thật; huỷ giỏ là
     * con số bốc hơi và sổ đơn nhảy số. Bài này chốt cả hai vế: không còn đường
     * xin mã, và lượt chốt không gửi mã nào lên API.
     */
    public function test_chot_don_khong_gui_ma_giu_truoc(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->banXong(), 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ban-hang.store'), $this->gio(['order_code' => 'DH000123', 'order_code_token' => '1789.abc']))
            ->assertOk();

        Http::assertSent(fn ($req) => $this->laChotDon($req)
            && ! array_key_exists('order_code', $req->data())
            && ! array_key_exists('order_code_token', $req->data()));
    }

    /** Đường xin mã đã gỡ hẳn: không còn route nào mang tên ấy. */
    public function test_khong_con_duong_xin_ma(): void
    {
        $this->assertFalse(app('router')->has('thu-ngan.ban-hang.maDon'));
    }

    /**
     * Ô "Điểm" phải được VẼ LẠI bằng số điểm thật sự dùng được.
     *
     * Gõ 999999 vào ô điểm thì tiền bên cạnh vẫn kẹp đúng và đơn cũng lưu đúng —
     * nhưng ô giữ nguyên 999999, mà thu ngân đọc ô chứ không đọc API, rồi nói với
     * khách là đổi được 999999 điểm.
     *
     * Việc kẹp nằm trong veThanhVien() chứ không trong trình xử lý gõ, vì hàm ấy
     * còn chạy lúc MỞ hộp: bỏ bớt hàng khiến số điểm dùng được tụt xuống thì ô
     * cũng phải tụt theo. Bài canh đúng chỗ đó — gỡ dòng ghi lại ô đi là đỏ.
     */
    public function test_o_diem_ve_lai_theo_so_diem_dung_duoc(): void
    {
        $js = file_get_contents(resource_path('../v2/pos/sale.blade.php'));

        $dau = strpos($js, 'function veThanhVien()');
        $this->assertNotFalse($dau, 'không tìm thấy veThanhVien()');
        $than = substr($js, $dau, strpos($js, "\n    }", $dau) - $dau);

        $this->assertStringContainsString('diemNhap()', $than, 'veThanhVien phải lấy số điểm đã kẹp');
        $this->assertMatchesRegularExpression('/\$\(.posTvDiem.\)\.value = /', $than,
            'veThanhVien phải ghi lại ô Điểm, nếu không ô sẽ giữ nguyên số vừa gõ');

        // Và ghi lại KHÔNG ĐIỀU KIỆN. Bản hỏng cũng có dòng ghi vào ô, nhưng chỉ
        // chạy khi ô tick "Dùng tất cả điểm" đang bật — nên gõ tay 999999 thì ô
        // không ai sửa. Thiếu vế này là bài test xanh trên chính bản hỏng.
        $this->assertDoesNotMatchRegularExpression(
            '/posTvDungHet.\)\.checked\)\s*\$\(.posTvDiem.\)\.value/', $than,
            'ô Điểm chỉ được ghi lại khi tick "Dùng tất cả điểm" — gõ tay sẽ không được kẹp'
        );
    }
}
