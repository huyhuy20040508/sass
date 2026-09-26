<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\FakeCashierViews;
use Tests\TestCase;

/**
 * CashierController (Lịch sử đơn quầy): khoá kênh pos, mặc định lọc hôm nay, gõ mã
 * đơn thì bỏ kẹp ngày.
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
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);
    }

    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff']],
        ];
    }

    protected function laHoiDon($req): bool
    {
        return str_contains($req->url(), '/admin/orders?');
    }

    /** Một đơn giao hàng lọt vào đây thì người trực tưởng mình phải xử lý nó. */
    public function test_don_quay_luon_khoa_kenh_pos(): void
    {
        $this->apiRong();
        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['channel' => 'web']))
            ->assertOk()
            ->assertViewIs('v2::pos.orders');

        Http::assertSent(fn ($req) => $this->laHoiDon($req) && str_contains($req->url(), 'channel=pos') && ! str_contains($req->url(), 'channel=web'));
    }

    public function test_mac_dinh_loc_hom_nay(): void
    {
        $this->apiRong();
        $homNay = Carbon::now()->format('Y-m-d');

        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index'))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['from_date'] === $homNay && $f['to_date'] === $homNay && $f['keyword'] === '');

        Http::assertSent(fn ($req) => $this->laHoiDon($req)
            && str_contains($req->url(), 'from_date='.$homNay) && str_contains($req->url(), 'to_date='.$homNay));
    }

    /** Khách cầm phiếu hôm trước quay lại: gõ mã đơn là tìm ở MỌI ngày. */
    public function test_tim_theo_ma_don_thi_khong_kep_ngay(): void
    {
        $this->apiRong();
        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['keyword' => '  DH202608170088 ']))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['keyword'] === 'DH202608170088' && $f['from_date'] === '' && $f['to_date'] === '');

        Http::assertSent(fn ($req) => $this->laHoiDon($req) && str_contains($req->url(), 'keyword=DH202608170088')
            && str_contains($req->url(), 'from_date=&') && str_contains($req->url(), 'to_date=&'));
    }

    /** Chọn một đầu ngày thì giữ đúng đầu đó — không tự kẹp hôm nay vào đầu kia. */
    public function test_chon_mot_dau_ngay_thi_giu_nguyen(): void
    {
        $this->apiRong();
        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index', ['from_date' => '2026-09-01', 'to_date' => 'khong-phai-ngay']))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['from_date'] === '2026-09-01' && $f['to_date'] === '');
    }

    public function test_api_loi_thi_trang_nhan_cau_loi(): void
    {
        Http::fake(['*/admin/orders?*' => Http::response(['message' => 'API đang bảo trì.'], 503), '*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())
            ->get(route('thu-ngan.don-hang.index'))
            ->assertOk()
            ->assertViewHas('error', 'API đang bảo trì.')
            ->assertViewHas('list', []);
    }
}
