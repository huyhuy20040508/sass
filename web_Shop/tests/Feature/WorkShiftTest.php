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
        Http::fake(['*/admin/ca-lam-viec/dong' => Http::response(['data' => ['difference' => -5000]])]);

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

    // ------------------------------------------------------------ trang danh sách ca / chi tiết

    public function test_trang_danh_sach_ca_nhan_list_va_meta(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec?*' => Http::response([
                'data' => [['id' => 3, 'difference' => -5000]],
                'meta' => ['page' => 1, 'page_size' => 20, 'total' => 1, 'total_pages' => 1],
            ]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index'))
            ->assertOk()
            ->assertViewIs('v2::pos.work-shift')
            ->assertViewHas('list', fn ($list) => count($list) === 1 && $list[0]['id'] === 3)
            ->assertViewHas('meta', fn ($meta) => $meta['total'] === 1)
            ->assertViewMissing('error');
    }

    /** Rác trên URL không thành lỗi 422 của API: chỉ giá trị hợp lệ được chuyển xuống. */
    public function test_loc_ca_chi_chuyen_tiep_gia_tri_hop_le(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index', ['status' => 'bay-ba', 'page_size' => 7, 'from_date' => 'hom-qua', 'to_date' => '2026-09-13', 'page' => -4]))
            ->assertOk()
            ->assertViewHas('filters', ['status' => '', 'from_date' => '', 'to_date' => '2026-09-13', 'page' => 1, 'page_size' => 20]);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/admin/ca-lam-viec?')
            && str_contains($req->url(), 'status=&') && str_contains($req->url(), 'page_size=20'));
    }

    public function test_api_loi_thi_trang_ca_nhan_cau_loi(): void
    {
        Http::fake(['*/admin/ca-lam-viec?*' => Http::response(['message' => 'Bạn không có quyền xem ca.'], 403), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.index'))
            ->assertOk()
            ->assertViewHas('error', 'Bạn không có quyền xem ca.')
            ->assertViewHas('list', []);
    }

    public function test_trang_chi_tiet_ca_nhan_ca_va_so_quy(): void
    {
        Http::fake([
            '*/admin/ca-lam-viec/3' => Http::response(['data' => [
                'ca' => ['id' => 3, 'difference' => -5000],
                'so_quy' => [['reason' => 'Bán hàng DH001'], ['reason' => 'Mua nước cho quầy']],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.ca-lam-viec.show', 3))
            ->assertOk()
            ->assertViewIs('v2::pos.work-shift-detail')
            ->assertViewHas('ca', fn ($ca) => $ca['id'] === 3)
            ->assertViewHas('soQuy', fn ($s) => array_column($s, 'reason') === ['Bán hàng DH001', 'Mua nước cho quầy']);
    }

    public function test_chi_tiet_ca_khong_co_la_404(): void
    {
        Http::fake(['*/admin/ca-lam-viec/77' => Http::response(['message' => 'Không tìm thấy ca'], 404), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.show', 77))->assertNotFound();
    }
}
