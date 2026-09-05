<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BÁO CÁO PHẢI CÓ ĐƯỜNG VỀ "TẤT CẢ CHI NHÁNH".
 *
 * Từ khi API cắt báo cáo theo chi nhánh đang làm việc, chủ tiệm chọn kho 2 ở
 * thanh trên cùng là mất luôn đường xem toàn công ty — mà bảng "chia theo chi
 * nhánh" ngay trong báo cáo doanh thu sinh ra chính là để so các kho với nhau,
 * và nó teo lại còn một dòng. Ô lọc này là đường về.
 *
 * Ba trạng thái, và cái đầu tiên khác cái thứ hai: bỏ trống nghĩa là "theo kho
 * đang làm việc", còn "tất cả" phải khai tường minh bằng shop_id=0.
 */
class BaoCaoChiNhanhTest extends TestCase
{
    protected function phien(int $chiNhanh = 1): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
            ApiClient::KHOA_CHI_NHANH => $chiNhanh,
        ];
    }

    protected function fakeApi(): void
    {
        Http::fake([
            '*/admin/chi-nhanh*' => Http::response(['data' => [
                ['id' => 1, 'code' => 'CN01', 'name' => 'Kho trung tâm', 'is_active' => true],
                ['id' => 2, 'code' => 'CN02', 'name' => 'Kho Quận 7', 'is_active' => true],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /** Không khai thì KHÔNG gửi shop_id — API tự cắt theo kho đang làm việc. */
    public function test_khong_khai_thi_khong_gui_shop_id(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())->get('/admin/reports/revenue')->assertOk();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/reports/revenue')) {
                return false;
            }

            return ! str_contains($req->url(), 'shop_id=0')
                && ! str_contains($req->url(), 'shop_id=1');
        });
    }

    /** Chọn "Tất cả chi nhánh" phải gửi shop_id=0 sang API. */
    public function test_tat_ca_chi_nhanh_gui_shop_id_0(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get('/admin/reports/revenue?shop_id=0')
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/reports/revenue')
            && str_contains($req->url(), 'shop_id=0'));
    }

    /** Chọn đúng một kho thì gửi đúng id ấy. */
    public function test_chon_mot_kho_thi_gui_dung_id(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get('/admin/reports/revenue?shop_id=2')
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/reports/revenue')
            && str_contains($req->url(), 'shop_id=2'));
    }

    /** Cửa hàng nhiều chi nhánh thì ô chọn phải có mặt, kèm lựa chọn "tất cả". */
    public function test_o_chon_hien_ra_khi_co_nhieu_chi_nhanh(): void
    {
        $this->fakeApi();

        $this->withSession($this->phien())
            ->get('/admin/reports/revenue')
            ->assertOk()
            ->assertSee('Tất cả chi nhánh')
            ->assertSee('name="shop_id"', false);
    }
}
