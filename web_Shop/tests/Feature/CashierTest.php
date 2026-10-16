<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\FakeCashierViews;
use Tests\TestCase;

/**
 * CashierController (Lịch sử đơn quầy): khoá kênh pos, mặc định lọc hôm nay, gõ mã
 * đơn thì bỏ kẹp ngày, đọc hết sổ của kỳ rồi tự đếm / cộng / lọc trạng thái và
 * người bán; chi tiết đơn, thu nợ và bản xuất của quầy.
 */
class CashierTest extends TestCase
{
    use FakeCashierViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giaLapViewThuNgan();
    }

    /**
     * API trả sổ đơn rỗng. KHÔNG khai ở setUp: Http::fake so khớp theo thứ tự đăng ký,
     * một mẫu bắt-tất-cả khai trước sẽ nuốt mọi mẫu riêng mà bài kiểm khai sau.
     */
    protected function apiRong(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0, 'total_pages' => 1]])]);
    }

    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff']],
        ];
    }

    protected function laHoiSo($req): bool
    {
        return str_contains($req->url(), '/admin/orders/so-don?');
    }

    /** Một dòng sổ đơn theo đúng khuôn domain.DongSoDon. */
    protected function dong(array $d): array
    {
        return array_merge([
            'loai' => 'don', 'id' => 1, 'ma' => 'DH000001', 'kenh' => 'pos', 'khach_hang' => '', 'so_dien_thoai' => '',
            'tien_hang' => 0, 'giam_gia' => 0, 'phi_giao' => 0, 'tong_tien' => 0, 'phuong_thuc' => 'cash', 'da_thu' => 0,
            'tien_mat' => 0, 'chuyen_khoan' => 0, 'quet_the' => 0, 'qr_tu_dong' => 0, 'khac' => 0, 'con_no' => 0,
            'co_cong_no' => false, 'xuat_duoc_hoa_don' => false, 'trang_thai' => 'paid', 'nguoi_tao' => 'Thu Hà',
            'created_at' => '2026-10-13T09:00:00+07:00',
        ], $d);
    }

    /** Sổ mẫu: 1 đơn TM, 1 đơn CK còn nợ, 1 đơn huỷ, 1 đơn chờ QR, 1 phiếu trả hoàn TM. */
    protected function soMau(): array
    {
        return [
            $this->dong(['id' => 1, 'ma' => 'DH000001', 'tong_tien' => 500000, 'da_thu' => 500000, 'tien_mat' => 500000]),
            $this->dong(['id' => 2, 'ma' => 'DH000002', 'phuong_thuc' => 'bank_transfer', 'tong_tien' => 3000000, 'da_thu' => 1000000,
                'chuyen_khoan' => 1000000, 'con_no' => 2000000, 'co_cong_no' => true, 'trang_thai' => 'partial', 'nguoi_tao' => 'Minh Khoa']),
            $this->dong(['id' => 3, 'ma' => 'DH000003', 'tong_tien' => 200000, 'trang_thai' => 'cancelled']),
            $this->dong(['id' => 4, 'ma' => 'DH000004', 'phuong_thuc' => 'sepay', 'tong_tien' => 700000, 'con_no' => 700000, 'trang_thai' => 'unpaid']),
            $this->dong(['loai' => 'tra-hang', 'id' => 1, 'ma' => 'TH000001', 'tong_tien' => 270000, 'tien_mat' => 270000, 'trang_thai' => 'returned']),
        ];
    }

    protected function apiSo(array $rows, int $tongTrang = 1): void
    {
        Http::fake(['*/admin/orders/so-don*' => Http::response(['data' => $rows, 'meta' => ['total' => count($rows), 'total_pages' => $tongTrang]])]);
    }

    /** Một đơn giao hàng lọt vào đây thì người trực tưởng mình phải xử lý nó. */
    public function test_don_quay_luon_khoa_kenh_pos(): void
    {
        $this->apiRong();
        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['channel' => 'web']))
            ->assertOk()
            ->assertViewIs('v2::pos.orders');

        Http::assertSent(fn ($req) => $this->laHoiSo($req) && str_contains($req->url(), 'channel=pos') && ! str_contains($req->url(), 'channel=web'));
    }

    public function test_mac_dinh_loc_hom_nay(): void
    {
        $this->apiRong();
        $homNay = Carbon::now()->format('Y-m-d');

        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index'))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['period'] === 'today' && $f['from_date'] === $homNay && $f['to_date'] === $homNay && $f['q'] === '');

        Http::assertSent(fn ($req) => $this->laHoiSo($req)
            && str_contains($req->url(), 'from_date='.$homNay) && str_contains($req->url(), 'to_date='.$homNay));
    }

    /** Tuần bắt đầu thứ Hai; tháng từ ngày 1 — tới hôm nay. */
    public function test_ky_tuan_va_thang(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00'); // thứ Năm
        $this->apiRong();
        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['period' => 'week']))
            ->assertViewHas('filters', fn ($f) => $f['from_date'] === '2026-10-12' && $f['to_date'] === '2026-10-15');
        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['period' => 'month']))
            ->assertViewHas('filters', fn ($f) => $f['from_date'] === '2026-10-01' && $f['to_date'] === '2026-10-15');
        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['period' => 'yesterday']))
            ->assertViewHas('filters', fn ($f) => $f['from_date'] === '2026-10-14' && $f['to_date'] === '2026-10-14');
        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['period' => 'last7']))
            ->assertViewHas('filters', fn ($f) => $f['from_date'] === '2026-10-09' && $f['to_date'] === '2026-10-15');
        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['period' => 'lastmonth']))
            ->assertViewHas('filters', fn ($f) => $f['from_date'] === '2026-09-01' && $f['to_date'] === '2026-09-30');
        // Tuỳ chọn chọn ngược đầu thì đảo lại.
        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['period' => 'custom', 'from_date' => '2026-10-10', 'to_date' => '2026-10-02']))
            ->assertViewHas('filters', fn ($f) => $f['from_date'] === '2026-10-02' && $f['to_date'] === '2026-10-10');
        // Kỳ lạ thì về hôm nay, không 500.
        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['period' => 'nam-ngoai']))
            ->assertOk()->assertViewHas('filters', fn ($f) => $f['period'] === 'today' && $f['from_date'] === '2026-10-15');
        Carbon::setTestNow();
    }

    /** Khách cầm phiếu hôm trước quay lại: gõ mã đơn là tìm ở MỌI ngày, gửi vào ô `keyword`. */
    public function test_tim_theo_ma_don_thi_khong_kep_ngay(): void
    {
        $this->apiRong();
        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['keyword' => '  DH202608170088 ']))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['q'] === 'DH202608170088' && $f['from_date'] === '' && $f['to_date'] === '');

        Http::assertSent(fn ($req) => $this->laHoiSo($req) && str_contains($req->url(), 'keyword=DH202608170088')
            && str_contains($req->url(), 'customer=&') && str_contains($req->url(), 'from_date=&') && str_contains($req->url(), 'to_date=&'));
    }

    /** SĐT / tên khách đi vào ô `customer` — `keyword` của sổ chỉ khớp mã đơn. */
    public function test_tim_theo_sdt_va_ten_gui_vao_o_khach(): void
    {
        $this->apiRong();
        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['q' => '0912345678']))->assertOk();
        Http::assertSent(fn ($req) => $this->laHoiSo($req) && str_contains($req->url(), 'customer=0912345678') && str_contains($req->url(), 'keyword=&'));

        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['q' => 'Phạm Hùng']))->assertOk();
        Http::assertSent(fn ($req) => $this->laHoiSo($req) && str_contains(urldecode($req->url()), 'customer=Phạm Hùng'));
    }

    /** Chọn một đầu ngày thì giữ đúng đầu đó — không tự kẹp hôm nay vào đầu kia. */
    public function test_chon_mot_dau_ngay_thi_giu_nguyen(): void
    {
        $this->apiRong();
        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['from_date' => '2026-09-01', 'to_date' => 'khong-phai-ngay']))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['period'] === 'custom' && $f['from_date'] === '2026-09-01' && $f['to_date'] === '');
    }

    public function test_api_loi_thi_trang_nhan_cau_loi(): void
    {
        Http::fake(['*/admin/orders/so-don*' => Http::response(['message' => 'API đang bảo trì.'], 503), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index'))
            ->assertOk()
            ->assertViewHas('error', 'API đang bảo trì.')
            ->assertViewHas('list', []);
    }

    /**
     * Đếm tab và thẻ tổng: "Đã thu" bỏ đơn huỷ; tiền HOÀN của phiếu trả đứng riêng
     * ở "Đã hoàn" chứ không cộng vào cột Tiền mặt như hàng tổng của sổ.
     */
    public function test_dem_va_cong_tren_ca_ky(): void
    {
        $this->apiSo($this->soMau());

        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index'))
            ->assertOk()
            ->assertViewHas('dem', fn ($d) => $d[''] === 5 && $d['paid'] === 1 && $d['partial'] === 1 && $d['cancelled'] === 1 && $d['unpaid'] === 1 && $d['returned'] === 1)
            ->assertViewHas('tong', fn ($t) => $t['so_don'] === 4 && $t['da_thu'] == 1500000 && $t['theo']['cash'] == 500000
                && $t['theo']['bank_transfer'] == 1000000 && $t['da_hoan'] == 270000 && $t['con_no'] == 2700000)
            ->assertViewHas('nguoiBan', ['Minh Khoa', 'Thu Hà'])
            // Một ngày: 5 dòng, thu ròng = 1.500.000 − 270.000 hoàn.
            ->assertViewHas('theoNgay', fn ($n) => $n['2026-10-13']['so'] === 5 && $n['2026-10-13']['thu'] == 1230000);
    }

    /** Lọc trạng thái và người bán làm ở Laravel; số trên tab vẫn đếm trên cả kỳ. */
    public function test_loc_trang_thai_va_nguoi_ban(): void
    {
        $this->apiSo($this->soMau());

        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['status' => 'partial']))
            ->assertViewHas('list', fn ($l) => count($l) === 1 && $l[0]['ma'] === 'DH000002')
            ->assertViewHas('dem', fn ($d) => $d[''] === 5)
            ->assertViewHas('tong', fn ($t) => $t['da_thu'] == 1000000 && $t['con_no'] == 2000000);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['staff' => 'Minh Khoa']))
            ->assertViewHas('list', fn ($l) => count($l) === 1)
            ->assertViewHas('dem', fn ($d) => $d[''] === 1 && $d['partial'] === 1)
            ->assertViewHas('nguoiBan', ['Minh Khoa', 'Thu Hà']);

        // Giá trị lạ bị bỏ, không lọc nhầm ra bảng rỗng.
        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['status' => 'xoa-het', 'pm' => 'vang', 'einv' => 'x']))
            ->assertViewHas('filters', fn ($f) => $f['status'] === '' && $f['pm'] === '' && $f['einv'] === '')
            ->assertViewHas('list', fn ($l) => count($l) === 5);
    }

    /** Hình thức và HĐĐT gửi thẳng cho API (sổ đã nhận `payment_method`, `etax`). */
    public function test_hinh_thuc_va_hoa_don_gui_api(): void
    {
        $this->apiRong();
        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index', ['pm' => 'auto_qr', 'einv' => 'co']))->assertOk();
        Http::assertSent(fn ($req) => $this->laHoiSo($req) && str_contains($req->url(), 'payment_method=auto_qr') && str_contains($req->url(), 'etax=co'));

        $this->withSession($this->phien())->get(route('thu-ngan.don-hang.index'))->assertOk();
        Http::assertSent(fn ($req) => $this->laHoiSo($req) && str_contains($req->url(), 'payment_method=all') && str_contains($req->url(), 'etax=all'));
    }

    /** Đọc HẾT các trang của sổ, rồi tự chia trang 20 dòng. */
    public function test_doc_het_cac_trang_roi_tu_chia_trang(): void
    {
        $trang = fn (int $p) => array_map(fn ($i) => $this->dong(['id' => $p * 100 + $i, 'ma' => sprintf('DH%06d', $p * 100 + $i), 'tong_tien' => 1000, 'da_thu' => 1000, 'tien_mat' => 1000]), range(1, $p === 1 ? 100 : 5));
        Http::fake(['*/admin/orders/so-don*' => Http::sequence()
            ->push(['data' => $trang(1), 'meta' => ['total' => 105, 'total_pages' => 2]])
            ->push(['data' => $trang(2), 'meta' => ['total' => 105, 'total_pages' => 2]])]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['page' => 6]))
            ->assertViewHas('meta', fn ($m) => $m['total'] === 105 && $m['total_pages'] === 6 && $m['page'] === 6)
            ->assertViewHas('list', fn ($l) => count($l) === 5)
            ->assertViewHas('tong', fn ($t) => $t['da_thu'] == 105000)
            ->assertViewHas('biCat', false);

        Http::assertSentCount(2);
    }

    public function test_chi_tiet_chi_tra_don_quay(): void
    {
        Http::fake([
            '*/admin/orders/5/etax' => Http::response(['data' => ['invoice_no' => '0000131', 'status' => 'issued']]),
            '*/admin/orders/5' => Http::response(['data' => ['id' => 5, 'channel' => 'pos', 'order_code' => 'DH000005']]),
            '*/admin/orders/6*' => Http::response(['data' => ['id' => 6, 'channel' => 'web']]),
        ]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.don-hang.chiTiet', 5))
            ->assertOk()->assertJsonPath('data.order_code', 'DH000005')->assertJsonPath('etax.invoice_no', '0000131');
        $this->withSession($this->phien())->getJson(route('thu-ngan.don-hang.chiTiet', 6))->assertNotFound();
    }

    public function test_thu_no_kiem_o_roi_goi_api(): void
    {
        Http::fake(['*/admin/orders/9/payments' => Http::response(['data' => ['id' => 9]], 200)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.don-hang.thuNo', 9), ['amount' => 0, 'payment_method' => 'auto_qr'])
            ->assertStatus(422)->assertJsonValidationErrors(['amount', 'payment_method']);

        $this->withSession($this->phien())->postJson(route('thu-ngan.don-hang.thuNo', 9), ['amount' => 150000, 'payment_method' => 'cash', 'note' => 'trả nốt'])
            ->assertOk();
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/admin/orders/9/payments')
            && $req['amount'] == 150000 && $req['payment_method'] === 'cash' && $req['note'] === 'trả nốt');
    }

    /** API từ chối (thu quá nợ) thì câu của API tới tay thu ngân. */
    public function test_thu_no_api_tu_choi_thi_tra_cau_loi(): void
    {
        Http::fake(['*/admin/orders/9/payments' => Http::response(['message' => 'Số tiền vượt phần còn nợ'], 422)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.don-hang.thuNo', 9), ['amount' => 9999999, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('message', 'Số tiền vượt phần còn nợ');
    }

    public function test_xuat_csv_theo_bo_loc(): void
    {
        $this->apiSo($this->soMau());

        $res = $this->withSession($this->phien())->get(route('thu-ngan.don-hang.xuat', ['status' => 'partial']));
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('DH000002', $csv);
        $this->assertStringNotContainsString('DH000001', $csv);
        $this->assertStringContainsString('Còn nợ', $csv);
    }
}
