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

        $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88, 'warehouse' => '58']))->assertViewHas('khoGiay', '58');
        $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88, 'warehouse' => '99mm;}']))->assertViewHas('khoGiay', '80');
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
}
