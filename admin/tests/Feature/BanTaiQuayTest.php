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

            // Mỗi dòng hàng chỉ được có đúng hai khoá.
            foreach ($body['items'] as $dong) {
                $this->assertSame(['product_variant_id', 'quantity'], array_keys($dong));
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
        $this->withSession($this->phienNhanVien())
            ->get(route('admin.ban-tai-quay.index'))
            ->assertOk()
            ->assertSee('data-store-url="'.route('admin.ban-tai-quay.store').'"', false)
            ->assertSee('data-search-url="'.route('admin.orders.searchProducts').'"', false);
    }
}
