<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use App\Services\CurrentBranch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Nộp tiền về công ty giữa ca: lập ở quầy, biên nhận, tờ ca, phiếu kết ca, báo cáo, màn quản trị. */
class NopVeCongTyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CurrentBranch::quenCache();
    }

    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Thu ngân Lan', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
            ApiClient::KHOA_CHI_NHANH => 1,
        ];
    }

    protected function phieu(): array
    {
        return ['id' => 61, 'code' => 'PC00061', 'type' => 1, 'kind' => 'deposit', 'amount' => 5000000, 'payment_method' => 'cash', 'category_id' => null, 'category_name' => '',
            'payer_type' => 'quan_ly', 'payer_id' => 1, 'payer_name' => 'Chủ tiệm Huy', 'note' => 'Nộp tiền về công ty', 'shift_id' => 9, 'branch_name' => 'CN1', 'source' => 'manual',
            'created_at' => '2026-10-22T15:00:00+07:00', 'created_by' => 7, 'created_by_name' => 'Thu ngân Lan', 'locked' => false, 'locked_reason' => '', 'attachment_url' => ''];
    }

    public function test_lap_nop_ve_khong_can_phan_loai(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 61]], 201)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.thu-chi.store'), ['type' => 1, 'kind' => 'deposit', 'amount' => 5000000, 'payment_method' => 'cash',
            'note' => 'Nộp tiền về công ty', 'payer_type' => 'quan_ly', 'payer_id' => 1])->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['kind'] === 'deposit' && $r['category_id'] === 0);
        // Phiếu thường vẫn bắt phân loại ngay ở Laravel.
        $this->withSession($this->phien())->postJson(route('thu-ngan.thu-chi.store'), ['type' => 1, 'amount' => 5000, 'payment_method' => 'cash', 'note' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_bien_nhan_nop_ve(): void
    {
        Http::fake(['*/admin/quay/thu-chi/61' => Http::response(['data' => $this->phieu()]), '*' => Http::response(['data' => []])]);
        $html = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.bienNhan', 61))->assertOk()->getContent());
        $this->assertStringContainsString('<div class="tieu-de">BIÊN NHẬN NỘP TIỀN VỀ CÔNG TY</div>', $html);
        $this->assertStringContainsString('<span>Số tiền nộp</span><span>5.000.000</span>', $html);
        $this->assertStringContainsString('<span class="dam">Người nộp</span><span class="cho"></span><span class="nho">Thu ngân Lan</span></div> <div><span class="dam">Người nhận</span><span class="cho"></span><span class="nho">Chủ tiệm Huy</span>', $html);
    }

    public function test_to_ca_va_phieu_ket_ca_hien_so_da_nop(): void
    {
        $ca = ['id' => 9, 'shop_id' => 1, 'opened_at' => '2026-10-22T07:30:00+07:00', 'closed_at' => '2026-10-22T21:00:00+07:00', 'opening_cash' => 2000000, 'opened_by' => 7,
            'opened_by_name' => 'Thu ngân Lan', 'closed_by' => 7, 'closed_by_name' => 'Thu ngân Lan', 'tong_thu' => 9000000, 'tong_chi' => 5100000, 'da_nop_ve' => 5000000,
            'expected_cash' => 5900000, 'counted_cash' => 5900000, 'difference' => 0];
        Http::fake(function (Request $r) use ($ca) {
            $u = urldecode($r->url());
            if (str_contains($u, '/admin/ca-lam-viec/9')) {
                return Http::response(['data' => ['ca' => $ca, 'so_quy' => [], 'doanh_thu' => []]]);
            }
            if (str_contains($u, '/admin/ca-lam-viec?')) {
                return Http::response(['data' => [$ca], 'meta' => ['total' => 1]]);
            }

            return Http::response(['data' => null]);
        });
        $html = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.index', ['ca' => 9]))->assertOk()->getContent());
        $this->assertStringContainsString('Đã nộp về công ty trong ca: <b>5.000.000 đ</b>', $html);
        $slip = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('thu-ngan.ca-lam-viec.phieu', 9))->assertOk()->getContent());
        $this->assertStringContainsString('Đã nộp về công ty trong ca</span><span>5.000.000</span>', $slip);
    }

    public function test_bao_cao_cuoi_ngay_tach_nop_ve(): void
    {
        Http::fake([
            '*/admin/reports/summary*' => Http::response(['data' => ['date' => '2026-10-22',
                'cashbook' => ['income_count' => 0, 'expense_count' => 1, 'income' => 0, 'expense' => 20000, 'cash_income' => 0, 'cash_expense' => 5020000, 'deposit' => 5000000, 'deposit_count' => 1],
                'totals' => ['orders' => 0, 'revenue' => 0, 'units' => 0], 'by_payment_method' => [], 'by_hour' => [], 'returns' => []]]),
            '*' => Http::response(['data' => []]),
        ]);
        $html = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get('/admin/reports/summary')->assertOk()->getContent());
        $this->assertStringContainsString('Nộp về công ty</span><span class="so">1 · 5.000.000đ</span>', $html);
        $this->assertStringContainsString('Tổng thu chi<b>-20.000đ</b>', $html);
    }

    public function test_man_quan_tri_ghi_ten_loai_va_khong_cho_sua(): void
    {
        Http::fake(['*/admin/thu-chi*' => Http::response(['data' => [$this->phieu()], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 1, 'total_pages' => 1]]), '*' => Http::response(['data' => []])]);
        $html = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('admin.cashbook.index'))->assertOk()->getContent());
        $this->assertStringContainsString('>Nộp về công ty</b>', $html);
        $this->assertStringContainsString('data-kind="deposit"', $html);
        $this->assertStringNotContainsString('edit_bt edit-item"', $html);
    }
}
