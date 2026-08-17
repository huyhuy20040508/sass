<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bán tại quầy — kiểm PHẦN GIÁP RANH giữa màn hình thu ngân và Go API.
 *
 * Bản thân nghiệp vụ bán hàng (trừ kho, tính tiền, thối tiền, chặn bán vượt) đã
 * có bài kiểm chạy trên API thật + MySQL thật ở `api/internal/apitest`. Chép lại
 * chúng ở đây chỉ tạo ra một bản sao sẽ lạc hậu. Cái CHƯA ai kiểm, và cũng là
 * chỗ duy nhất còn hỏng được, là hình dạng payload trang này gửi đi.
 *
 * Dùng Http::fake nên không cần API chạy và KHÔNG đụng tới dữ liệu của máy đang
 * chạy — khác AdminSmokeTest, vốn phải có API thật vì nó kiểm đường đọc.
 */
class BanTaiQuayTest extends TestCase
{
    /** Phiên giả lập của một nhân viên đã đăng nhập.
     *
     *  Trang quầy nằm trong nhóm `admin` chứ không phải `admin.manage`, nên vai
     *  trò ở đây cố tình là staff: nếu có ai vô tình siết route lại thành chỉ
     *  quản trị viên, bài kiểm này đỏ ngay. */
    protected function phienNhanVien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff']],
        ];
    }

    /** Một lượt bán hợp lệ gửi từ màn hình quầy. */
    protected function gioHang(array $ghiDe = []): array
    {
        return array_merge([
            'payment_method' => 'cash',
            'amount_tendered' => 500000,
            'items' => [
                ['product_variant_id' => 12, 'quantity' => 2],
            ],
        ], $ghiDe);
    }

    /** Phản hồi thành công mà API trả về sau khi chốt đơn. */
    protected function apiBanXong(): array
    {
        return ['data' => [
            'order_id' => 88,
            'order_code' => 'DH202608160088',
            'subtotal_amount' => 180000,
            'discount_amount' => 0,
            'total_amount' => 180000,
            'amount_tendered' => 500000,
            'change_amount' => 320000,
            'payment_method' => 'cash',
            'status' => 'completed',
            'payment_status' => 'paid',
            'message' => 'Đã thu 500.000₫, thối lại 320.000₫.',
        ]];
    }

    /**
     * Điều quan trọng nhất của cả tính năng: trang quầy KHÔNG gửi giá lên.
     *
     * Màn hình có sẵn giá (nó vừa hiển thị cho khách xem), nên gửi kèm là việc dễ
     * làm và trông vô hại. Nhưng gửi lên là mở đường cho giá trên màn hình khác
     * giá trong sổ — mà giá đúng thì API tra lại được, còn giá sai thì không ai
     * phát hiện cho tới lúc đối soát cuối tháng.
     */
    public function test_khong_gui_gia_len_api(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->apiBanXong(), 201)]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), $this->gioHang())
            ->assertOk();

        Http::assertSent(function ($req) {
            $body = $req->data();

            // Mỗi dòng hàng chỉ được mang: mua gì, mấy cái, bớt bao nhiêu phần
            // trăm. Danh sách khoá chốt cứng chứ không chỉ kiểm "không có giá" —
            // để lần sau ai đó thêm một trường tiền nào khác cũng bị bắt tại đây.
            foreach ($body['items'] as $dong) {
                $this->assertSame(
                    ['product_variant_id', 'quantity', 'discount_percent'],
                    array_keys($dong)
                );
            }

            // Và không khoá nào ở cấp đơn mang theo số tiền do màn hình tự tính.
            foreach (['unit_price', 'subtotal_amount', 'total_amount', 'discount_amount', 'shipping_fee'] as $cam) {
                $this->assertArrayNotHasKey($cam, $body, "payload không được mang $cam");
            }

            return true;
        });
    }

    /** Khách lẻ: không có user_id thì payload cũng KHÔNG được mang khoá đó.
     *
     *  Gửi user_id = 0 thì API hiểu là "có gắn tài khoản, id bằng 0" và đi tra
     *  một tài khoản không tồn tại — cả lượt bán hỏng vì một khoá thừa. */
    public function test_khach_le_khong_gui_user_id(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->apiBanXong(), 201)]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), $this->gioHang())
            ->assertOk();

        Http::assertSent(fn ($req) => ! array_key_exists('user_id', $req->data()));
    }

    /** Khách quen thì user_id đi kèm. */
    public function test_khach_quen_gui_kem_user_id(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->apiBanXong(), 201)]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), $this->gioHang(['user_id' => 42]))
            ->assertOk();

        Http::assertSent(fn ($req) => ($req->data()['user_id'] ?? null) === 42);
    }

    /** Tiền khách đưa chỉ đi kèm khi thu TIỀN MẶT.
     *
     *  Con số ấy không có nghĩa gì với một lệnh chuyển khoản; gửi kèm là ghi vào
     *  đơn một số tiền không ai kiểm được. */
    public function test_chuyen_khoan_khong_gui_tien_khach_dua(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->apiBanXong(), 201)]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), $this->gioHang([
                'payment_method' => 'bank_transfer',
                'amount_tendered' => 500000,
            ]))
            ->assertOk();

        Http::assertSent(fn ($req) => ! array_key_exists('amount_tendered', $req->data()));
    }

    /**
     * Lỗi của API phải tới được mắt người bán NGUYÊN VĂN.
     *
     * Những câu ấy đã nói rõ việc cần làm — hết hàng thì kèm tên món và số còn
     * lại, đưa thiếu thì kèm số còn thiếu. Thay bằng một câu chung chung là bắt
     * người bán đoán giữa lúc khách đang đứng đợi.
     */
    public function test_giu_nguyen_van_loi_cua_api(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response(
            ['message' => 'Không đủ hàng — Áo Real Madrid (M / Trắng) (còn 1)'], 400
        )]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), $this->gioHang())
            ->assertStatus(400)
            ->assertJson(['message' => 'Không đủ hàng — Áo Real Madrid (M / Trắng) (còn 1)']);
    }

    /** Giỏ trống thì dừng ngay tại đây, không làm phiền API. */
    public function test_gio_trong_khong_goi_api(): void
    {
        Http::fake();

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), ['payment_method' => 'cash', 'items' => []])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Hình thức thanh toán ngoài danh sách của quầy bị chặn.
     *
     *  Đơn quầy được ghi "đã thanh toán" ngay lúc tạo, nên nhận payos là ghi nhận
     *  một khoản tiền chưa chắc có. API cũng chặn — đây là chặn sớm hơn. */
    public function test_chan_hinh_thuc_thanh_toan_khong_thuoc_quay(): void
    {
        Http::fake();

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), $this->gioHang(['payment_method' => 'payos']))
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Trang quầy mở được và mang đủ đường dẫn mà màn hình cần. */
    public function test_mo_duoc_man_hinh_quay(): void
    {
        Http::fake(['*/admin/orders/pos/discount-limit' => Http::response(['data' => ['limit_percent' => 10]])]);

        $this->withSession($this->phienNhanVien())
            ->get(route('admin.ban-tai-quay.index'))
            ->assertOk()
            ->assertSee('data-store-url="'.route('admin.ban-tai-quay.store').'"', false)
            ->assertSee('data-scan-url="'.route('admin.ban-tai-quay.scan').'"', false)
            ->assertSee('data-search-url="'.route('admin.orders.searchProducts').'"', false);
    }

    // ---------------------------------------------------------------- giai đoạn 2

    /** Mức giảm giá tối đa lấy từ API, không tự suy từ vai trò trong phiên. */
    public function test_han_muc_giam_gia_lay_tu_api(): void
    {
        Http::fake(['*/admin/orders/pos/discount-limit' => Http::response(['data' => ['limit_percent' => 15]])]);

        $this->withSession($this->phienNhanVien())
            ->get(route('admin.ban-tai-quay.index'))
            ->assertOk()
            ->assertSee('data-discount-limit="15"', false);
    }

    /**
     * API hỏng thì hạn mức về 0 — nhánh CHẶT.
     *
     * Không biết người này được phép bớt bao nhiêu thì cho họ mức của người ít
     * quyền nhất. Mở toang khi không rõ là kiểu mặc định khiến một sự cố mạng
     * biến thành một lỗ hổng quyền hạn.
     */
    public function test_api_hong_thi_khong_cho_bot_dong_nao(): void
    {
        Http::fake(['*/admin/orders/pos/discount-limit' => Http::response([], 500)]);

        $this->withSession($this->phienNhanVien())
            ->get(route('admin.ban-tai-quay.index'))
            ->assertOk()
            ->assertSee('data-discount-limit="0"', false);
    }

    /** Mức giảm của từng dòng được gửi lên API. */
    public function test_gui_kem_muc_giam_tung_dong(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->apiBanXong(), 201)]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), $this->gioHang([
                'items' => [['product_variant_id' => 12, 'quantity' => 2, 'discount_percent' => 15]],
            ]))
            ->assertOk();

        Http::assertSent(fn ($req) => $req->data()['items'][0]['discount_percent'] === 15.0);
    }

    /**
     * Dòng không khai mức giảm vẫn phải gửi 0, không được vắng mặt.
     *
     * Vắng khoá thì API nhận zero-value cũng ra 0 — nhưng lúc đó "không bớt" và
     * "quên gửi" trông giống hệt nhau trong log, và đó là thứ phải phân biệt được
     * khi có người thắc mắc về một đơn cũ.
     */
    public function test_khong_bot_thi_gui_so_khong(): void
    {
        Http::fake(['*/admin/orders/pos' => Http::response($this->apiBanXong(), 201)]);

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), $this->gioHang())
            ->assertOk();

        Http::assertSent(fn ($req) => $req->data()['items'][0]['discount_percent'] === 0.0);
    }

    /** Mức giảm ngoài khoảng 0–100 bị chặn ngay, không làm phiền API. */
    public function test_chan_muc_giam_vo_ly(): void
    {
        Http::fake();

        $this->withSession($this->phienNhanVien())
            ->postJson(route('admin.ban-tai-quay.store'), $this->gioHang([
                'items' => [['product_variant_id' => 12, 'quantity' => 1, 'discount_percent' => 150]],
            ]))
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Quét mã: chuyển tiếp sang API và trả lại nguyên món hàng tìm được. */
    public function test_quet_ma_tra_ve_mon_hang(): void
    {
        Http::fake(['*/admin/orders/pos/scan*' => Http::response(['data' => [
            'product_variant_id' => 12, 'product_name' => 'Áo Real', 'size' => 'M',
            'price' => 90000, 'stock' => 7,
        ]])]);

        $this->withSession($this->phienNhanVien())
            ->getJson(route('admin.ban-tai-quay.scan', ['code' => '8938505970012']))
            ->assertOk()
            ->assertJsonPath('data.product_variant_id', 12)
            ->assertJsonPath('data.price', 90000);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'code=8938505970012'));
    }

    /** Quét mã lạ: giữ nguyên câu API trả về, không thay bằng câu chung chung. */
    public function test_quet_ma_la_giu_nguyen_cau_cua_api(): void
    {
        Http::fake(['*/admin/orders/pos/scan*' => Http::response(
            ['message' => 'Sản phẩm trong giỏ không còn bán hoặc đã đổi phiên bản, vui lòng chọn lại'], 400
        )]);

        $this->withSession($this->phienNhanVien())
            ->getJson(route('admin.ban-tai-quay.scan', ['code' => '0000']))
            ->assertStatus(400)
            ->assertJsonPath('message', 'Sản phẩm trong giỏ không còn bán hoặc đã đổi phiên bản, vui lòng chọn lại');
    }

    /** Quét mà không có mã thì dừng tại chỗ. */
    public function test_quet_khong_co_ma_thi_khong_goi_api(): void
    {
        Http::fake();

        $this->withSession($this->phienNhanVien())
            ->getJson(route('admin.ban-tai-quay.scan', ['code' => '  ']))
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * Phiếu in nhiệt dựng đủ số liệu, và số tiền hàng là số TRƯỚC khi bớt.
     *
     * subtotal_amount của đơn đã trừ phần bớt rồi, nên in thẳng nó ra là phiếu tự
     * mâu thuẫn: dòng "tiền hàng" bằng dòng "tổng cộng" trong khi ở giữa vẫn có
     * một dòng giảm giá.
     */
    public function test_phieu_in_nhiet_dung_so_lieu(): void
    {
        Http::fake(['*/admin/orders/88' => Http::response(['data' => [
            'order_code' => 'DH202608170088',
            'placed_at' => '2026-08-17T10:30:00+07:00',
            'recipient_name' => 'Chị Lan',
            'total_amount' => 162000,
            'discount_amount' => 0,
            'shipping_fee' => 0,
            'amount_tendered' => 200000,
            'change_amount' => 38000,
            'items' => [[
                'product_name' => 'Áo Real Madrid', 'size' => 'M', 'color' => 'Trắng',
                'unit_price' => 90000, 'quantity' => 2,
                'discount_percent' => 10, 'discount_amount' => 18000,
                'total_price' => 162000,
            ]],
        ]])]);

        $res = $this->withSession($this->phienNhanVien())
            ->get(route('admin.ban-tai-quay.phieu', ['id' => 88]))
            ->assertOk();

        // 2 × 90.000 = 180.000 TRƯỚC khi bớt.
        $res->assertSee('180.000₫', false);
        $res->assertSee('Bớt theo món', false);
        $res->assertSee('162.000₫', false);
        $res->assertSee('38.000₫', false);   // tiền thối
        $res->assertSee('DH202608170088', false);
    }

    /** Khổ giấy mặc định 80mm, đổi được sang 58mm qua ?kho. */
    public function test_phieu_doi_duoc_kho_giay(): void
    {
        $don = ['data' => [
            'order_code' => 'DH1', 'total_amount' => 1000, 'items' => [],
            'placed_at' => '2026-08-17T10:30:00+07:00',
        ]];
        Http::fake(['*/admin/orders/88' => Http::response($don)]);

        $this->withSession($this->phienNhanVien())
            ->get(route('admin.ban-tai-quay.phieu', ['id' => 88]))
            ->assertOk()
            ->assertSee('size: 80mm auto', false);

        $this->withSession($this->phienNhanVien())
            ->get(route('admin.ban-tai-quay.phieu', ['id' => 88, 'kho' => '58']))
            ->assertOk()
            ->assertSee('size: 58mm auto', false);
    }
}
