<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cài đặt → Thông số chung → Cài đặt thiết bị, và phiếu tính tiền đọc mẫu in.
 *
 * API giả lập. Luật nghiệp vụ (trùng IP, tem trùng nhóm, xoá máy đang gán…) do
 * api/internal/apitest/device_setting_test.go kiểm trên MySQL thật.
 */
class DeviceSettingTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function thietBi(bool $sua = true): array
    {
        $mau = fn (string $ma, string $ten, array $caiDat) => [
            'ma' => $ma, 'ten' => $ten, 'ngan' => $ten, 'footer_toi_da' => 120, 'cai_dat' => $caiDat,
            'nhom' => [['ten' => 'Phần đầu', 'dong' => [['ma' => 'ten', 'ten' => 'Tên cửa hàng', 'mac_dinh' => true]]]],
            'options' => ['ten' => true], 'settings' => $caiDat, 'da_chinh' => false,
        ];

        return [
            'shop_id' => 1, 'can_edit' => $sua,
            'printers' => [['id' => 7, 'name' => 'Máy in quầy 1', 'conn_type' => 'lan', 'address' => '192.168.1.50', 'paper' => '80', 'dpi' => 203, 'printed_count' => 12, 'is_active' => true]],
            'routes' => [['id' => 3, 'slip_type' => 'hoa-don', 'printer_id' => 7, 'all_categories' => false, 'category_ids' => [], 'is_active' => true]],
            'buttons' => [['ma' => 'thanh-toan', 'ten' => 'Thanh toán', 'phim' => 'F9', 'cho_phep' => ['hoa-don', 'tem', 'hddt'], 'mac_dinh' => ['hoa-don'], 'slips' => ['hoa-don'], 'is_active' => true]],
            'templates' => [$mau('hd', 'Hoá đơn 58 / 80mm', ['kho' => '80', 'ban' => 1, 'footer_txt' => 'Cảm ơn quý khách!'])],
            'slips' => [['ma' => 'hoa-don', 'ten' => 'Hoá đơn', 'theo_nhom' => false], ['ma' => 'tem', 'ten' => 'Tem', 'theo_nhom' => true]],
            'groups' => [['id' => 4, 'name' => 'Phụ kiện']],
            'label_sizes' => ['40x30', '50x70'],
        ];
    }

    protected function fake(array $them = []): void
    {
        Http::fake($them + [
            '*/admin/thiet-bi' => Http::response(['data' => $this->thietBi()]),
            '*/admin/payment-methods' => Http::response(['data' => [
                'shop_id' => 1, 'can_edit' => true, 'banks' => [], 'methods' => [],
                'devices' => [['id' => 11, 'code' => 'NP23A0158', 'name' => 'Máy mPOS quầy 1', 'description' => null, 'is_physical' => false]],
            ]]),
            '*/admin/chi-nhanh*' => Http::response(['data' => [['id' => 1, 'name' => 'Chi nhánh trung tâm', 'is_active' => true]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    public function test_trang_dung_theo_v2_kem_du_lieu_va_tab_thong_so_chung(): void
    {
        $this->fake();

        $res = $this->withSession($this->phien())->get('/admin/parameters/devices')->assertOk();

        $res->assertViewIs('v2::settings.devices');
        $res->assertSee('id="tbData"', false);
        $res->assertSee('Máy in quầy 1', false);
        $res->assertSee('NP23A0158', false);
        $res->assertSee('Chi nhánh trung tâm');
        // Tab "Thông số chung" trong module Cài đặt, đang chọn; Phương thức thanh toán thì không.
        $this->assertMatchesRegularExpression('#admin/parameters/devices"\s+class="sub-nav-btn active"#', $res->getContent());
        $this->assertMatchesRegularExpression('#admin/payment-methods"\s+class="sub-nav-btn "#', $res->getContent());
        // Cột trái dẫn về Quy tắc đánh số; bốn khung và năm hộp thoại.
        $res->assertSee(route('admin.thong-so-chung.numbering-rule'), false);
        foreach (['tong-quan', 'may-in', 'mau-in', 'quet-the'] as $v) {
            $res->assertSee('data-view="'.$v.'"', false);
        }
        foreach (['mdMay', 'mdPhieu', 'mdNut', 'mdQuet', 'mdXoa'] as $id) {
            $res->assertSee('id="'.$id.'"', false);
        }
    }

    /**
     * Dò tìm máy in: nút nằm trong hộp Thêm máy in, gọi đúng giao thức cầu nối
     * Android của bản cũ (0 = dò; "1" LAN, "0" Bluetooth, "2" USB) và có đường
     * Web Bluetooth / WebUSB cho trình duyệt.
     */
    public function test_hop_may_in_co_do_tim(): void
    {
        $this->fake();

        $html = $this->withSession($this->phien())->get('/admin/parameters/devices')->assertOk()->getContent();

        $this->assertStringContainsString('id="fMayDo"', $html);
        $this->assertStringContainsString('id="fMayDoKq"', $html);
        $this->assertStringContainsString("window.Android.do_something(0, LOAI_APP[l], '')", $html);
        $this->assertStringContainsString("{ lan: '1', bt: '0', usb: '2' }", $html);
        $this->assertStringContainsString('navigator.bluetooth.requestDevice', $html);
        $this->assertStringContainsString('navigator.usb.requestDevice', $html);
    }

    /** Chưa chọn chi nhánh (API 409): nói câu của API, không bày bảng nào. */
    public function test_api_bao_chon_chi_nhanh_thi_hien_cau_va_nut_tai_lai(): void
    {
        $this->fake(['*/admin/thiet-bi' => Http::response(['message' => 'Chọn một chi nhánh ở thanh trên cùng để cấu hình — mỗi chi nhánh có bộ thiết bị riêng.'], 409)]);

        $this->withSession($this->phien())->get('/admin/parameters/devices')
            ->assertOk()
            ->assertSee('mỗi chi nhánh có bộ thiết bị riêng')
            ->assertSee('Tải lại')
            ->assertDontSee('id="tbData"', false)
            ->assertDontSee('id="mdMay"', false);
    }

    /** Thiếu quyền Phương thức thanh toán: riêng khung máy quẹt thẻ báo, trang vẫn mở. */
    public function test_thieu_quyen_may_quet_the_thi_chi_khung_do_bao(): void
    {
        $this->fake(['*/admin/payment-methods' => Http::response(['message' => 'Forbidden'], 403)]);

        $html = $this->withSession($this->phien())->get('/admin/parameters/devices')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<script type="application/json" id="tbData">(.*?)</script>#s', $html);
        preg_match('#<script type="application/json" id="tbData">(.*?)</script>#s', $html, $m);
        $goc = json_decode($m[1], true);
        $this->assertNull($goc['pm']);
        $this->assertSame('Tài khoản chưa được cấp quyền xem mục này.', $goc['loi_pm']);
        $this->assertSame('Máy in quầy 1', $goc['data']['printers'][0]['name']);
    }

    public function test_them_may_in_chuyen_tiep_dung_body(): void
    {
        $this->fake(['*/admin/thiet-bi/may-in' => Http::response(['data' => ['id' => 9]], 201)]);

        $this->withSession($this->phien())
            ->postJson('/admin/parameters/devices/printers', [
                'name' => '  Máy tem  ', 'conn_type' => 'usb', 'address' => '', 'paper' => '58', 'dpi' => '203', 'is_active' => false,
            ])
            ->assertOk()->assertJson(['message' => 'Đã thêm máy in.']);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/thiet-bi/may-in') && $r->method() === 'POST'
            && $r['name'] === 'Máy tem' && $r['dpi'] === 203 && $r['is_active'] === false);
    }

    /** Lỗi theo ô của API giữ nguyên mã và `errors` để hộp thoại tô đỏ đúng ô. */
    public function test_loi_theo_o_giu_nguyen_ma_va_errors(): void
    {
        $this->fake(['*/admin/thiet-bi/may-in/7' => Http::response([
            'message' => 'Dữ liệu không hợp lệ', 'errors' => ['address' => 'Địa chỉ này đã khai cho “Máy in quầy 1”.'],
        ], 422)]);

        $this->withSession($this->phien())
            ->putJson('/admin/parameters/devices/printers/7', ['name' => 'X', 'conn_type' => 'lan', 'address' => '192.168.1.50', 'paper' => '80', 'dpi' => 203])
            ->assertStatus(422)
            ->assertJsonPath('errors.address', 'Địa chỉ này đã khai cho “Máy in quầy 1”.')
            ->assertJsonPath('message', 'Thông tin chưa hợp lệ — xem lại các ô báo đỏ.');
    }

    public function test_xoa_may_dang_gan_tra_409_kem_cau_cua_api(): void
    {
        $this->fake(['*/admin/thiet-bi/may-in/7' => Http::response(['message' => 'Máy in quầy 1 đang in Hoá đơn.'], 409)]);

        $this->withSession($this->phien())->deleteJson('/admin/parameters/devices/printers/7')
            ->assertStatus(409)->assertJsonPath('message', 'Máy in quầy 1 đang in Hoá đơn.');
    }

    public function test_gan_phieu_va_nut_in_loc_du_lieu_rac(): void
    {
        $this->fake([
            '*/admin/thiet-bi/gan-phieu' => Http::response(['data' => ['id' => 5]], 201),
            '*/admin/thiet-bi/nut-in/thanh-toan' => Http::response(['data' => null]),
        ]);

        $this->withSession($this->phien())->postJson('/admin/parameters/devices/routes', [
            'slip_type' => 'tem', 'printer_id' => '7', 'all_categories' => false, 'category_ids' => ['4', 'x', 0],
        ])->assertOk();
        $this->withSession($this->phien())->putJson('/admin/parameters/devices/buttons/thanh-toan', [
            'slips' => ['hoa-don', ['lồng'], 3], 'is_active' => true,
        ])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/thiet-bi/gan-phieu')
            && $r['printer_id'] === 7 && $r['category_ids'] === [4]);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/thiet-bi/nut-in/thanh-toan')
            && $r['slips'] === ['hoa-don'] && $r['is_active'] === true);
    }

    /** Lượt tự lưu mẫu in: ô số đi dạng số, bật/tắt đi dạng bool; không toast. */
    public function test_luu_mau_in_ep_kieu_va_khong_kem_cau(): void
    {
        $this->fake(['*/admin/thiet-bi/mau-in/hd' => Http::response(['data' => ['ma' => 'hd', 'da_chinh' => true]])]);

        $this->withSession($this->phien())->putJson('/admin/parameters/devices/templates/hd', [
            'options' => ['qr' => 'true', 'khach' => false], 'settings' => ['ban' => '2', 'kho' => '58', 'footer_txt' => 'Hẹn gặp lại'],
        ])->assertOk()->assertJsonPath('data.da_chinh', true)->assertJsonMissing(['message' => 'Đã lưu.']);
        $this->assertArrayNotHasKey('message', $this->withSession($this->phien())->putJson('/admin/parameters/devices/templates/hd', [])->json());

        Http::assertSent(function (HttpRequest $r) {
            $d = json_decode($r->body(), true);

            return str_ends_with($r->url(), '/admin/thiet-bi/mau-in/hd') && $r->method() === 'PUT'
                && ($d['options']['qr'] ?? null) === true && ($d['options']['khach'] ?? null) === false
                && ($d['settings']['ban'] ?? null) === 2 && ($d['settings']['kho'] ?? null) === '58';
        });
    }

    /** Trang Quy tắc đánh số (giao diện cũ) có mục Cài đặt thiết bị ở thanh bên. */
    public function test_thanh_ben_thong_so_chung_cu_dan_toi_cai_dat_thiet_bi(): void
    {
        $this->fake(['*/admin/quy-tac-ma*' => Http::response(['data' => ['loai' => [], 'quy_tac' => []]])]);

        $this->withSession($this->phien())->get('/admin/parameters/numbering-rules')
            ->assertOk()
            ->assertSee(route('admin.thong-so-chung.devices'), false)
            ->assertSee('Cài đặt thiết bị');
    }

    // ------------------------------------------------------------ phiếu tính tiền đọc mẫu "Hoá đơn"

    protected function donVaMau(array $mau): void
    {
        Http::fake([
            '*/admin/orders/88' => Http::response(['data' => [
                'order_code' => 'DH000088', 'total_amount' => 200000, 'recipient_name' => 'Chị Lan', 'recipient_phone' => '0909123456',
                'created_by_name' => 'Thu Ngân A', 'note' => 'Gói quà giúp',
                'items' => [
                    ['product_name' => 'Ốp lưng', 'variant_name' => 'Đen', 'quantity' => 1, 'unit_price' => 200000],
                    ['product_name' => 'Cáp tặng', 'quantity' => 1, 'unit_price' => 0, 'is_gift' => true],
                ],
            ]]),
            '*/admin/orders/pos/mau-in/hd' => Http::response(['data' => $mau]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    public function test_phieu_theo_mau_an_dong_loi_chao_so_ban_kho(): void
    {
        $this->donVaMau([
            'options' => ['khach' => false, 'thungan' => false, 'bienthe' => false, 'qua' => false, 'ghichu' => false, 'sao' => true],
            'settings' => ['kho' => '58', 'ban' => 2, 'footer_txt' => 'Hẹn gặp lại!'],
        ]);

        $res = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->assertOk();

        $res->assertViewHas('khoGiay', '58')->assertViewHas('soBan', 2);
        $html = $res->getContent();
        $this->assertSame(2, substr_count($html, 'Hẹn gặp lại!'), 'hai bản in phải có hai lời chào');
        $this->assertSame(2, substr_count($html, '**********'));
        $this->assertStringContainsString('Ốp lưng', $html);
        foreach (['Chị Lan', 'Thu Ngân A', '(Đen)', 'Cáp tặng', 'Gói quà giúp', 'Cảm ơn quý khách'] as $an) {
            $this->assertStringNotContainsString($an, $html, "dòng đã tắt vẫn in: {$an}");
        }
    }

    /**
     * Phiếu KHÔNG tự xin khổ giấy. Xin khổ khác khổ của driver (Xprinter để
     * 72 × 297mm) thì Chrome thu nhỏ phiếu rồi đặt giữa tờ — giấy trắng cả trên
     * lẫn dưới (14/10/2026). Khổ do driver quyết, trang chỉ đặt lề 0 và bề ngang
     * vừa vùng in của cuộn.
     */
    public function test_phieu_khong_tu_xin_kho_giay_chi_dat_le_0(): void
    {
        $this->donVaMau(['options' => [], 'settings' => ['kho' => '58', 'ban' => 1]]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->assertOk()->getContent();

        $this->assertStringContainsString('@page { margin: 0; }', $html);
        $this->assertDoesNotMatchRegularExpression('#@page[^}]*size#', $html);
        $this->assertStringContainsString('width: 48mm;', $html);
        $this->assertStringContainsString('window.print();', $html);
    }

    /** Nút "Đổi sang khổ…" trên phiếu (?paper=) thắng khổ của mẫu. */
    public function test_paper_tren_link_thang_kho_cua_mau(): void
    {
        $this->donVaMau(['options' => [], 'settings' => ['kho' => '58', 'ban' => 1]]);

        $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88, 'paper' => '80']))
            ->assertViewHas('khoGiay', '80');
    }

    /**
     * In lại đơn khi phiên chưa chọn / đang để "Tất cả chi nhánh": không có
     * X-Chi-Nhanh thì API trả mẫu mặc định (khổ 80) dù chi nhánh bán ra đơn đã
     * chỉnh mẫu (14/10/2026). Phải hỏi theo shop_id của đơn, và chỉ lượt đó.
     */
    public function test_mau_in_theo_chi_nhanh_cua_don_khong_theo_phien(): void
    {
        Http::fake([
            '*/admin/orders/88' => Http::response(['data' => ['order_code' => 'DH1', 'shop_id' => 2, 'items' => []]]),
            '*/admin/orders/pos/mau-in/hd' => fn (HttpRequest $r) => Http::response(['data' => [
                'options' => [], 'settings' => ['kho' => $r->header('X-Chi-Nhanh') === ['2'] ? '58' : '80', 'ban' => 1],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))
            ->assertOk()->assertViewHas('khoGiay', '58');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/orders/88') && ! $r->hasHeader('X-Chi-Nhanh'));
        $this->assertSame(0, \App\Services\ApiClient::chiNhanhDangLam(), 'chi nhánh của đơn không được dính sang lượt gọi sau');
    }

    /**
     * Dòng Logo và Mã QR của mẫu: logo cửa hàng tự tải lên; QR chuyển khoản đúng
     * tổng tiền vào tài khoản mặc định của chi nhánh BÁN RA đơn (14/10/2026).
     */
    public function test_phieu_in_logo_va_qr_chuyen_khoan_theo_chi_nhanh_cua_don(): void
    {
        Http::fake([
            '*/admin/orders/88' => Http::response(['data' => ['order_code' => 'DH000088', 'shop_id' => 2, 'total_amount' => 24200000, 'payment_method' => 'bank_transfer', 'items' => []]]),
            '*/admin/orders/pos/mau-in/hd' => Http::response(['data' => ['options' => ['logo' => true, 'qr' => true], 'settings' => ['kho' => '80', 'ban' => 1]]]),
            '*/admin/orders/pos/payment-methods' => fn (HttpRequest $r) => Http::response(['data' => $r->header('X-Chi-Nhanh') === ['2'] ? [
                ['type' => 4, 'details' => [
                    ['bank_name' => 'TPBank', 'bank_account' => '111', 'is_default' => false],
                    ['bank_name' => 'MBBank', 'bank_account' => '0365671004', 'bank_account_name' => 'NGUYEN VAN A', 'is_default' => true],
                ]],
            ] : []]),
            '*/admin/settings*' => Http::response(['data' => ['values' => ['store_logo' => 'https://anh.example/logo.png']]]),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->assertOk();

        $res->assertViewHas('logo', 'https://anh.example/logo.png')
            ->assertViewHas('qr', 'https://qr.sepay.vn/img?acc=0365671004&bank=MBBank&amount=24200000&des=TT+DH000088');
        $res->assertSee('<img class="logo" src="https://anh.example/logo.png"', false)->assertSee('Quét để chuyển khoản 24.200.000đ');
    }

    /** Đơn trả tiền mặt / thẻ: không in QR dù mẫu bật — khách tưởng phải chuyển thêm. */
    public function test_don_khong_tra_bang_chuyen_khoan_thi_khong_in_qr(): void
    {
        foreach (['cash', 'card', 'sepay'] as $ht) {
            Http::fake([
                '*/admin/orders/88' => Http::response(['data' => ['order_code' => 'DH1', 'shop_id' => 2, 'total_amount' => 100000, 'payment_method' => $ht, 'items' => []]]),
                '*/admin/orders/pos/mau-in/hd' => Http::response(['data' => ['options' => ['qr' => true], 'settings' => []]]),
                '*/admin/orders/pos/payment-methods' => Http::response(['data' => [['type' => 4, 'details' => [['bank_name' => 'MBBank', 'bank_account' => '1', 'is_default' => true]]]]]),
                '*' => Http::response(['data' => []]),
            ]);

            $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->assertOk()
                ->assertViewHas('qr', '')->assertDontSee('Quét để chuyển khoản');
        }
    }

    /** Tắt dòng Logo / Mã QR trong mẫu thì phiếu không in, kể cả khi đã có logo và tài khoản. */
    public function test_tat_logo_va_qr_trong_mau_thi_khong_in(): void
    {
        Http::fake([
            '*/admin/orders/88' => Http::response(['data' => ['order_code' => 'DH1', 'shop_id' => 2, 'total_amount' => 100000, 'items' => []]]),
            '*/admin/orders/pos/mau-in/hd' => Http::response(['data' => ['options' => ['logo' => false, 'qr' => false], 'settings' => []]]),
            '*/admin/orders/pos/payment-methods' => Http::response(['data' => [['type' => 4, 'details' => [['bank_name' => 'MBBank', 'bank_account' => '1', 'is_default' => true]]]]]),
            '*/admin/settings*' => Http::response(['data' => ['values' => ['store_logo' => 'https://anh.example/logo.png']]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->assertOk()
            ->assertViewHas('logo', '')->assertViewHas('qr', '')
            ->assertDontSee('class="logo"', false)->assertDontSee('Quét để chuyển khoản');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/payment-methods'));
    }

    /** API mẫu in hỏng: in như cũ — đủ dòng, khổ 80, một bản. */
    public function test_mau_in_hong_thi_in_nhu_cu(): void
    {
        Http::fake([
            '*/admin/orders/88' => Http::response(['data' => ['order_code' => 'DH1', 'recipient_name' => 'Chị Lan', 'items' => []]]),
            '*/admin/orders/pos/mau-in/hd' => Http::response(['message' => 'lỗi'], 500),
            '*' => Http::response(['data' => []]),
        ]);

        $res = $this->withSession($this->phien())->get(route('thu-ngan.ban-hang.phieu', ['id' => 88]))->assertOk();
        $res->assertViewHas('khoGiay', '80')->assertViewHas('soBan', 1)->assertSee('Chị Lan')->assertSee('Cảm ơn quý khách!');
    }
}
