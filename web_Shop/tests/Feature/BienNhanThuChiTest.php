<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** In biên nhận phiếu thu / chi ở quầy (/cashier/cash-book/{id}/receipt) — khổ 80/58, in khung ẩn. */
class BienNhanThuChiTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff'], 'access_areas' => 'thu_ngan'],
        ];
    }

    protected function fake(array $doi = [], int $ma = 200): void
    {
        $phieu = array_merge([
            'id' => 41, 'code' => 'PC000053', 'type' => 1, 'amount' => 60000, 'payment_method' => 'cash',
            'category_name' => 'Vận chuyển, giao hàng', 'payer_type' => 'other', 'payer_name' => 'Anh Tài ship',
            'note' => 'Trả ship đơn HD001250', 'shift_id' => 9, 'created_at' => '2026-10-19T14:45:00+07:00', 'created_by_name' => 'Nhân viên quầy',
        ], $doi);
        Http::fake([
            '*/admin/quay/thu-chi/41' => $ma === 200 ? Http::response(['data' => $phieu]) : Http::response(['message' => 'Không tìm thấy'], $ma),
            '*/admin/settings*' => Http::response(['data' => ['values' => ['site_name' => 'Tiệm Quốc Huy', 'store_address' => '12 Lê Lợi']]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    protected function trang(string $q = ''): string
    {
        return preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.bienNhan', 41).$q)->assertOk()->getContent());
    }

    public function test_phieu_chi_khoi_80mm_du_dong(): void
    {
        $this->fake();
        $html = $this->trang();
        $this->assertStringContainsString('width: 72mm;', $html);
        $this->assertStringContainsString('<div class="ban">', $html);
        $this->assertStringContainsString('<div class="tieu-de">BIÊN NHẬN PHIẾU CHI</div> <div class="giua dam">PC000053</div>', $html);
        $this->assertStringContainsString('<span>Ngày giờ</span><span>14:45 19/10/2026</span>', $html);
        $this->assertStringContainsString('<span>Ca</span><span>#9</span>', $html);
        $this->assertStringContainsString('<span>Loại phiếu</span><span>Phiếu chi · Tiền mặt</span>', $html);
        $this->assertStringContainsString('<span>Số tiền chi</span><span>60.000</span>', $html);
        $this->assertStringContainsString('Bằng chữ: Sáu mươi nghìn đồng.', $html);
        $this->assertStringContainsString('<b>Lý do</b>Trả ship đơn HD001250', $html);
        $this->assertStringContainsString('<span>Phân loại</span><span>Vận chuyển, giao hàng</span>', $html);
        $this->assertStringContainsString('<span>Người nhận</span><span>Anh Tài ship</span>', $html);
        $this->assertStringContainsString('<span>Người lập</span><span>Nhân viên quầy</span>', $html);
        // Phiếu chi: quầy giao tiền, người nhận ký nhận.
        $this->assertStringContainsString('<span class="dam">Người giao</span><span class="cho"></span><span class="nho">Nhân viên quầy</span></div> <div><span class="dam">Người nhận</span><span class="cho"></span><span class="nho">Anh Tài ship</span>', $html);
        $this->assertStringContainsString('Đổi sang khổ 58mm', $html);
        $this->assertStringContainsString('window.print()', $html);
    }

    public function test_phieu_thu_ngoai_ca_dao_chieu_ky(): void
    {
        $this->fake(['type' => 0, 'code' => 'PT000015', 'shift_id' => null, 'category_name' => '', 'payment_method' => 'transfer', 'payer_name' => 'Chị Lan']);
        $html = $this->trang();
        $this->assertStringContainsString('BIÊN NHẬN PHIẾU THU', $html);
        $this->assertStringContainsString('<span>Ca</span><span>Ngoài ca</span>', $html);
        $this->assertStringContainsString('<span>Loại phiếu</span><span>Phiếu thu · Chuyển khoản</span>', $html);
        $this->assertStringContainsString('<span>Phân loại</span><span>Chưa phân loại</span>', $html);
        $this->assertStringContainsString('<span>Người nộp</span><span>Chị Lan</span>', $html);
        $this->assertStringContainsString('<span class="nho">Chị Lan</span></div> <div><span class="dam">Người nhận</span><span class="cho"></span><span class="nho">Nhân viên quầy</span>', $html);
    }

    public function test_kho_58_va_trong_khung_an(): void
    {
        $this->fake();
        $this->assertStringContainsString('width: 48mm;', $this->trang('?paper=58'));
        $an = $this->trang('?nhung=1');
        $this->assertStringContainsString('<div class="ban">', $an);
        $this->assertStringNotContainsString('window.print()', $an);
        $this->assertStringNotContainsString('thanh-cong-cu">', $an);
    }

    public function test_phieu_ngoai_pham_vi_la_404(): void
    {
        $this->fake([], 404);
        $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.bienNhan', 41))->assertNotFound();
    }

    public function test_phieu_da_duyet_in_nguoi_duyet(): void
    {
        $this->fake(['approved_by_name' => 'Chủ tiệm']);
        $this->assertStringContainsString('<span>Người duyệt</span><span>Chủ tiệm</span>', $this->trang());
    }

    public function test_phieu_chua_duyet_khong_co_dong_nguoi_duyet(): void
    {
        $this->fake();
        $this->assertStringNotContainsString('Người duyệt', $this->trang());
    }
}
