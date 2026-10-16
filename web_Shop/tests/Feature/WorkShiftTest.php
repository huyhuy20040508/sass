<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\FakeCashierViews;
use Tests\TestCase;

/**
 * WorkShiftController — payload gửi đi, câu lỗi giữ nguyên văn, bộ lọc chỉ chuyển
 * giá trị hợp lệ, và MỘT điểm thiết kế: cụm ca hỏng thì quầy vẫn bán được.
 */
class WorkShiftTest extends TestCase
{
    use FakeCashierViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giaLapViewThuNgan();
    }

    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff']],
        ];
    }

    protected function la(string $method, string $duoi): callable
    {
        return fn ($req) => $req->method() === $method && str_ends_with((string) parse_url($req->url(), PHP_URL_PATH), $duoi);
    }

    // ------------------------------------------------------------ mở / đóng ca, sổ quỹ

    public function test_mo_ca_gui_tien_dau_ca(): void
    {
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response(['data' => ['id' => 3]], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 500000, 'note' => '  Ca sáng '])
            ->assertOk()
            ->assertJsonPath('data.id', 3);

        Http::assertSent(fn ($req) => ($this->la('POST', '/admin/ca-lam-viec/mo'))($req)
            && (float) $req['opening_cash'] === 500000.0 && $req['note'] === 'Ca sáng');
    }

    public function test_mo_ca_bat_buoc_dem_tien(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.mo'), [])
            ->assertStatus(422)
            ->assertJsonPath('errors.opening_cash.0', 'Vui lòng đếm và nhập số tiền đang có trong két.');

        Http::assertNotSent($this->la('POST', '/admin/ca-lam-viec/mo'));
    }

    /** "Chi nhánh này đang có ca mở" phải tới nguyên văn — nó nói rõ phải làm gì. */
    public function test_giu_nguyen_van_loi_dang_co_ca_mo(): void
    {
        Http::fake(['*/admin/ca-lam-viec/mo' => Http::response(['message' => 'Chi nhánh này đang có ca mở, đóng ca đó trước.'], 409)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.mo'), ['opening_cash' => 0])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Chi nhánh này đang có ca mở, đóng ca đó trước.');
    }

    public function test_dong_ca_gui_tien_dem_duoc(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => ['id' => 4, 'opening_cash' => 500000, 'tong_thu' => 125000, 'tong_chi' => 0]]),
            '*/admin/ca-lam-viec/dong' => Http::response(['data' => ['difference' => -5000]]),
        ]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.dong'), ['counted_cash' => 625000])
            ->assertOk()
            ->assertJsonPath('data.difference', -5000);

        Http::assertSent(fn ($req) => ($this->la('POST', '/admin/ca-lam-viec/dong'))($req) && (float) $req['counted_cash'] === 625000.0);
    }

    public function test_dong_ca_bat_buoc_dem_tien(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.dong'), ['note' => 'quên đếm'])
            ->assertStatus(422)
            ->assertJsonPath('errors.counted_cash.0', 'Vui lòng đếm và nhập số tiền thực tế trong két.');

        Http::assertNotSent($this->la('POST', '/admin/ca-lam-viec/dong'));
    }

    public function test_ghi_so_quy_bat_buoc_co_ly_do(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.soQuy'), ['direction' => 'out', 'amount' => 50000, 'reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        Http::assertNotSent($this->la('POST', '/admin/so-quy'));
    }

    public function test_ghi_so_quy_chan_so_tien_khong_va_chieu_la(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.soQuy'), ['direction' => 'sideways', 'amount' => 0, 'reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['direction', 'amount'])
            ->assertJsonPath('errors.amount.0', 'Số tiền phải lớn hơn 0.');
    }

    public function test_ghi_so_quy_chuyen_tiep_du_truong(): void
    {
        Http::fake(['*/admin/so-quy' => Http::response(['data' => ['id' => 9]], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.soQuy'), ['direction' => 'out', 'amount' => 50000, 'reason' => '  Mua nước cho quầy '])
            ->assertOk();

        Http::assertSent(fn ($req) => ($this->la('POST', '/admin/so-quy'))($req)
            && $req['direction'] === 'out' && (float) $req['amount'] === 50000.0 && $req['reason'] === 'Mua nước cho quầy');
    }

    /** Chi vượt két: API trả KET_AM — mã đi tới màn hình để nó hỏi lại; gửi lại thì kèm cờ xác nhận. */
    public function test_ghi_so_quy_ket_am_tra_ma_va_chuyen_co_xac_nhan(): void
    {
        Http::fake(['*/admin/so-quy' => Http::sequence()
            ->push(['success' => false, 'message' => 'Két theo sổ chỉ còn 600.000 đ — khoản chi lớn hơn tiền đang có.', 'errors' => ['ma' => 'KET_AM']], 422)
            ->push(['data' => ['id' => 10]], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.soQuy'), ['direction' => 'out', 'amount' => 900000, 'reason' => 'Trả tiền hàng'])
            ->assertStatus(422)
            ->assertJsonPath('errors.ma', 'KET_AM')
            ->assertJsonPath('message', 'Két theo sổ chỉ còn 600.000 đ — khoản chi lớn hơn tiền đang có.');

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.soQuy'), ['direction' => 'out', 'amount' => 900000, 'reason' => 'Trả tiền hàng', 'xac_nhan_am' => true])
            ->assertOk();
        Http::assertSent(fn ($req) => ($this->la('POST', '/admin/so-quy'))($req) && $req['xac_nhan_am'] === true);
    }

    // ------------------------------------------------------------ ca hiện tại (JSON)

    /** Ca là thứ ghi chép, không phải thứ gác cửa: API hỏng thì vẫn trả 200 + null. */
    public function test_api_hong_thi_ca_hien_tai_tra_null_chu_khong_loi(): void
    {
        Http::fake(['*/admin/ca-lam-viec/hien-tai' => Http::response([], 500)]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.ca-lam-viec.hienTai'))->assertOk()->assertJsonPath('data', null);
    }

    public function test_chua_mo_ca_tra_null(): void
    {
        Http::fake(['*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => null])]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.ca-lam-viec.hienTai'))->assertOk()->assertJsonPath('data', null);
    }

    public function test_dang_co_ca_thi_tra_ca(): void
    {
        Http::fake(['*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => ['id' => 4, 'opening_cash' => 500000]])]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.ca-lam-viec.hienTai'))->assertOk()->assertJsonPath('data.id', 4);
    }

    /** Két lệch mà không ghi lý do thì chặn ngay ở quầy, không gửi xuống API. */
    public function test_dong_ca_lech_ma_khong_ghi_ly_do_bi_chan(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => ['id' => 4, 'opening_cash' => 500000, 'tong_thu' => 130000, 'tong_chi' => 0]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.dong'), ['counted_cash' => 625000, 'note' => '  '])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Két thiếu 5.000 đ so với sổ — ghi lý do chênh lệch trước khi đóng ca.')
            ->assertJsonValidationErrors('note');

        Http::assertNotSent($this->la('POST', '/admin/ca-lam-viec/dong'));
    }

    public function test_dong_ca_lech_co_ly_do_thi_gui(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => ['id' => 4, 'opening_cash' => 500000, 'tong_thu' => 130000, 'tong_chi' => 0]]),
            '*/admin/ca-lam-viec/dong' => Http::response(['data' => ['ca' => ['id' => 4, 'difference' => 20000]]]),
        ]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.ca-lam-viec.dong'), ['counted_cash' => 650000, 'note' => 'Dư 20 nghìn chưa rõ'])
            ->assertOk()
            ->assertJsonPath('data.ca.difference', 20000);

        Http::assertSent(fn ($req) => ($this->la('POST', '/admin/ca-lam-viec/dong'))($req) && $req['note'] === 'Dư 20 nghìn chưa rõ');
    }

    // ------------------------------------------------------------ trang Điều phối ca

    /** Dữ liệu giả đủ hình dạng API thật — trang dựng bằng view THẬT. */
    protected function fakeTrang(array $them = []): void
    {
        $gio = fn (string $hm) => now()->format('Y-m-d').'T'.$hm.':00+07:00';
        Http::fake($them + [
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => [
                'id' => 9, 'opened_at' => $gio('07:58'), 'opening_cash' => 2000000, 'closed_at' => null,
                'opened_by' => 7, 'opened_by_name' => 'Nhân viên quầy', 'tong_thu' => 950400, 'tong_chi' => 60000, 'so_don_tien_mat' => 1, 'note' => '',
            ]]),
            '*/admin/ca-lam-viec/nguoi-truc' => Http::response(['data' => [['id' => 7, 'name' => 'Nhân viên quầy'], ['id' => 8, 'name' => 'Trần Thu Hà']]]),
            '*/admin/ca-lam-viec/ngoai-ca' => Http::response(['data' => [
                ['id' => 1, 'direction' => 'in', 'amount' => 450000, 'reason' => 'Bán hàng DH0001', 'reference_type' => 'order', 'reference_code' => 'DH0001', 'created_at' => $gio('07:41')],
            ]]),
            '*/admin/ca-lam-viec/9' => Http::response(['data' => [
                'ca' => ['id' => 9, 'opened_at' => $gio('07:58'), 'opening_cash' => 2000000, 'closed_at' => null, 'opened_by' => 7, 'opened_by_name' => 'Nhân viên quầy', 'tong_thu' => 950400, 'tong_chi' => 60000, 'so_don_tien_mat' => 1, 'note' => 'Nhận két đủ'],
                'so_quy' => [
                    ['id' => 2, 'direction' => 'in', 'amount' => 950400, 'reason' => 'Bán hàng DH0002', 'reference_type' => 'order', 'reference_id' => 2, 'reference_code' => 'DH0002', 'created_at' => $gio('08:31')],
                    ['id' => 3, 'direction' => 'out', 'amount' => 60000, 'reason' => 'Mua nước', 'reference_type' => 'manual', 'created_by' => 7, 'created_by_name' => 'Nhân viên quầy', 'created_at' => $gio('10:15')],
                ],
                'doanh_thu' => ['order_count' => 3, 'total_revenue' => 2000000, 'total_cash' => 950400, 'total_transfer' => 1049600, 'total_card' => 0, 'total_auto_qr' => 0],
            ]]),
            '*/admin/ca-lam-viec/3' => Http::response(['data' => [
                'ca' => ['id' => 3, 'opened_at' => '2026-10-12T14:05:00+07:00', 'closed_at' => '2026-10-12T21:40:00+07:00', 'opening_cash' => 1500000, 'opened_by' => 8, 'closed_by' => 8,
                    'opened_by_name' => 'Trần Thu Hà', 'closed_by_name' => 'Trần Thu Hà', 'tong_thu' => 100000, 'tong_chi' => 0, 'expected_cash' => 1600000, 'counted_cash' => 1550000, 'difference' => -50000, 'close_note' => 'Thối nhầm'],
                'so_quy' => [], 'doanh_thu' => ['order_count' => 0, 'total_revenue' => 0],
            ]]),
            '*/admin/ca-lam-viec?*' => Http::response([
                'data' => [['id' => 3, 'opened_at' => '2026-10-12T14:05:00+07:00', 'closed_at' => '2026-10-12T21:40:00+07:00', 'opened_by_name' => 'Trần Thu Hà', 'expected_cash' => 1600000, 'counted_cash' => 1550000, 'difference' => -50000]],
                'meta' => ['page' => 1, 'page_size' => 10, 'total' => 1, 'total_pages' => 1],
            ]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** KB #73: ca bỏ quên mở từ tháng trước, hôm nay mới đóng nằm dưới "Hôm nay" và ghi rõ ngày mở. */
    public function test_lich_su_ca_nhom_theo_ngay_dong(): void
    {
        $dong = now()->format('Y-m-d').'T14:34:00+07:00';
        $this->fakeTrang([
            '*/admin/ca-lam-viec?*' => Http::response([
                'data' => [['id' => 1, 'opened_at' => '2026-08-17T11:57:00+07:00', 'closed_at' => $dong, 'opened_by_name' => 'Quản trị', 'expected_cash' => 0, 'counted_cash' => 0, 'difference' => 0]],
                'meta' => ['page' => 1, 'page_size' => 10, 'total' => 1, 'total_pages' => 1],
            ]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<li class="ca-day">Hôm nay <span>· '.now()->format('d/m/Y').'</span></li>#u', $html);
        $this->assertStringContainsString('<span class="ca-item__t">17/08 11:57–14:34<small>#1</small></span>', $html);
        $this->assertStringContainsString('theo giờ đóng', $html);
    }

    /** Két theo sổ âm: in đỏ kèm chữ "két âm", không in như một con số thường. */
    public function test_ket_theo_so_am_thi_to_do(): void
    {
        $gio = fn (string $hm) => now()->format('Y-m-d').'T'.$hm.':00+07:00';
        $ca = ['id' => 9, 'opened_at' => $gio('07:58'), 'opening_cash' => 500000, 'closed_at' => null, 'opened_by' => 7,
            'opened_by_name' => 'Nhân viên quầy', 'tong_thu' => 100000, 'tong_chi' => 900000, 'so_don_tien_mat' => 0, 'note' => ''];
        $this->fakeTrang([
            '*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => $ca]),
            '*/admin/ca-lam-viec/9' => Http::response(['data' => ['ca' => $ca, 'so_quy' => [], 'doanh_thu' => ['order_count' => 0, 'total_revenue' => 0]]]),
        ]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="ket-am">-300.000 đ · két âm</span>', $html);
    }

    public function test_trang_mo_ca_dang_truc_kem_so_quy_va_doanh_thu(): void
    {
        $this->fakeTrang();

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index'))
            ->assertOk()
            ->assertViewIs('v2::pos.work-shift')
            ->assertViewHas('chon', fn ($c) => $c['ca']['id'] === 9 && count($c['so_quy']) === 2)
            ->assertViewHas('list', fn ($l) => count($l) === 1 && $l[0]['id'] === 3)
            ->assertViewHas('caTruoc', fn ($c) => $c['id'] === 3)
            ->assertViewMissing('error')
            // Theo sổ = 2.000.000 + 950.400 − 60.000; tồn két dòng cuối bằng đúng số ấy.
            ->assertSee('2.890.400 đ')
            ->assertSee('DH0002')
            ->assertSee('1 khoản tiền mặt ngoài ca', false)
            ->assertSee('Thiếu 50.000 đ')
            ->assertSee('1.049.600 đ');
    }

    public function test_chon_ca_cu_qua_tham_so_ca(): void
    {
        $this->fakeTrang();

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index', ['ca' => 3]))
            ->assertOk()
            ->assertViewHas('chon', fn ($c) => $c['ca']['id'] === 3)
            ->assertSee('Thối nhầm')
            ->assertSee('In phiếu kết ca');

        Http::assertSent(fn ($req) => str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/admin/ca-lam-viec/3'));
    }

    public function test_chua_mo_ca_thi_khung_lon_moi_mo_ca(): void
    {
        $this->fakeTrang(['*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => null])]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index'))
            ->assertOk()
            ->assertViewHas('chon', null)
            ->assertSee('Chưa mở ca')
            ->assertSee('bàn giao', false);
    }

    /**
     * Câu dưới nút "Mở ca" phải nói đúng việc hiện tại. Nó từng ghi "Quầy vẫn bán
     * được khi chưa mở ca" — đúng ở bản cũ, nay sai hẳn: màn Bán hàng phủ lớp khoá
     * #posCaKhoa và khoá hai khung hàng + hoá đơn bằng `inert` khi chi nhánh không
     * có ca mở. Thu ngân đọc câu cũ thì tưởng cứ bán rồi tính sau.
     */
    public function test_chua_mo_ca_thi_noi_dung_man_ban_hang_bi_khoa(): void
    {
        $this->fakeTrang(['*/admin/ca-lam-viec/hien-tai' => Http::response(['data' => null])]);

        $html = $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index'))
            ->assertOk()
            ->assertSee('màn Bán hàng bị khoá', false)
            ->getContent();

        $this->assertStringNotContainsString('vẫn bán được khi chưa mở ca', $html,
            'câu cũ hứa quầy bán được — màn Bán hàng nay khoá hẳn');

        // Hai nửa còn lại của câu mới đều phải có thật: khoản tiền mặt lúc này ghi
        // là "ngoài ca", và mở ca xong thì gắn được vào ca.
        $this->assertStringContainsString('“ngoài ca”', $html);
        $this->assertStringContainsString('gắn vào ca', $html);
    }

    /**
     * Nửa kia của câu trên nằm ở MÀN KHÁC nên không thể kiểm bằng HTML trang ca:
     * đọc thẳng view Bán hàng. Ngày nào lớp khoá bị bỏ đi thì bài này đỏ, nhắc sửa
     * lại câu — thay vì để trang ca nói dối lần thứ hai.
     */
    public function test_man_ban_hang_that_su_co_lop_khoa_khi_chua_mo_ca(): void
    {
        $ban = file_get_contents(base_path('v2/pos/sale.blade.php'));

        $this->assertStringContainsString('id="posCaKhoa"', $ban, 'không còn lớp khoá — câu "màn Bán hàng bị khoá" thành sai');
        $this->assertStringContainsString("\$('paneGoods').inert = \$('paneBill').inert = true", $ban,
            'hai khung hàng + hoá đơn phải bị inert, không thì vẫn thêm được món sau lớp khoá');
    }

    /** Rác trên URL không thành lỗi 422 của API: chỉ giá trị hợp lệ được chuyển xuống. */
    public function test_loc_ca_chi_chuyen_tiep_gia_tri_hop_le(): void
    {
        $this->fakeTrang();

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index', ['ky' => 'bay-ba', 'opened_by' => 'abc', 'lech' => '2', 'page' => -4, 'ca' => 'x']))
            ->assertOk()
            ->assertViewHas('filters', [
                'ky' => '7-ngay', 'from_date' => now()->subDays(6)->toDateString(), 'to_date' => now()->toDateString(),
                'opened_by' => 0, 'lech' => false, 'page' => 1, 'ca' => 0,
            ]);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/ca-lam-viec?')
            && str_contains($req->url(), 'status=da_dong') && str_contains($req->url(), 'page_size=10')
            && ! str_contains($req->url(), 'opened_by') && ! str_contains($req->url(), 'lech'));
    }

    public function test_loc_tuy_chon_dao_ngay_va_chuyen_nguoi_truc_lech(): void
    {
        $this->fakeTrang();

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index', ['ky' => 'tuy-chon', 'from_date' => '2026-10-12', 'to_date' => '2026-10-01', 'opened_by' => 8, 'lech' => '1']))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['from_date'] === '2026-10-01' && $f['to_date'] === '2026-10-12');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/ca-lam-viec?')
            && str_contains($req->url(), 'from_date=2026-10-01') && str_contains($req->url(), 'to_date=2026-10-12')
            && str_contains($req->url(), 'opened_by=8') && str_contains($req->url(), 'lech=1'));
    }

    public function test_api_loi_thi_trang_ca_nhan_cau_loi(): void
    {
        $this->fakeTrang(['*/admin/ca-lam-viec?*' => Http::response(['message' => 'Bạn không có quyền xem ca.'], 403)]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index'))
            ->assertOk()
            ->assertViewHas('error', 'Bạn không có quyền xem ca.')
            ->assertViewHas('list', [])
            ->assertSee('Bạn không có quyền xem ca.');
    }

    /** Khối phụ hỏng (ngoài ca, người trực) không được làm sập cả màn. */
    public function test_khoi_phu_hong_trang_van_mo(): void
    {
        $this->fakeTrang([
            '*/admin/ca-lam-viec/ngoai-ca' => Http::response([], 500),
            '*/admin/ca-lam-viec/nguoi-truc' => Http::response([], 500),
        ]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index'))
            ->assertOk()
            ->assertViewHas('ngoaiCa', [])
            ->assertViewHas('nguoiTruc', []);
    }

    public function test_duong_chi_tiet_cu_ve_trang_voi_ca(): void
    {
        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.show', 3))
            ->assertRedirect(route('thu-ngan.ca-lam-viec.index', ['ca' => 3]));
    }

    public function test_phieu_ket_ca_va_phieu_tam(): void
    {
        $this->fakeTrang();

        $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.phieu', 3))
            ->assertOk()->assertViewIs('v2::pos.shift-slip')
            ->assertSee('PHIẾU KẾT CA')->assertSee('-50.000')->assertDontSee('PHIẾU TẠM');

        $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.phieu', ['id' => 9, 'tam' => 1]))
            ->assertOk()->assertSee('PHIẾU TẠM')->assertSee('2.890.400')->assertSee('Mua nước');
    }

    public function test_phieu_ca_khong_co_la_404(): void
    {
        Http::fake(['*/admin/ca-lam-viec/77' => Http::response(['message' => 'Không tìm thấy ca'], 404), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.phieu', 77))->assertNotFound();
    }

    public function test_phieu_tong_hop_theo_bo_loc(): void
    {
        $this->fakeTrang();

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.phieuTongHop', ['ky' => 'tuy-chon', 'from_date' => '2026-10-01', 'to_date' => '2026-10-12', 'lech' => 1]))
            ->assertOk()
            ->assertSee('TỔNG HỢP CA')
            ->assertSee('#3')
            ->assertSee('-50.000');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/ca-lam-viec?') && str_contains($req->url(), 'page_size=100') && str_contains($req->url(), 'lech=1'));
    }
}
