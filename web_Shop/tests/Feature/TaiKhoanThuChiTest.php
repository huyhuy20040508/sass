<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tài khoản của phiếu thu chi chuyển khoản: chuyển id, lọc, hiện ở bảng, xuất Excel. */
class TaiKhoanThuChiTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Quản lý Hùng', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    protected function phieu(string $pt, array $them = []): array
    {
        return $them + ['type' => 1, 'amount' => 300000, 'payment_method' => $pt, 'note' => 'Trả NCC', 'category_id' => 4, 'bank_account_id' => 12];
    }

    protected function dong(): array
    {
        return ['id' => 41, 'code' => 'PC00041', 'type' => 1, 'amount' => 300000, 'payment_method' => 'transfer', 'category_name' => 'Nhập hàng', 'branch_name' => 'CN1',
            'payer_name' => '', 'created_by_name' => 'Quản lý Hùng', 'source' => 'manual', 'note' => 'Trả NCC', 'created_at' => '2026-10-22T10:00:00+07:00',
            'bank_account_id' => 12, 'bank_account_label' => 'Vietcombank · 0071001234567 · NGUYEN VAN DUC', 'locked' => false, 'locked_reason' => ''];
    }

    public function test_hai_man_chi_gui_tai_khoan_khi_chuyen_khoan(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 90]], 201)]);
        foreach (['thu-ngan.thu-chi.store', 'admin.cashbook.store'] as $r) {
            $this->withSession($this->phien())->postJson(route($r), $this->phieu('transfer'));
            $this->withSession($this->phien())->postJson(route($r), $this->phieu('cash'));
        }
        $gui = collect(Http::recorded())->map(fn ($c) => $c[0])->filter(fn (Request $r) => $r->method() === 'POST')->values();
        $this->assertSame([12, null, 12, null], $gui->map(fn (Request $r) => $r['bank_account_id'] ?? null)->all());
    }

    public function test_quan_tri_loc_theo_tai_khoan_va_hien_nhan(): void
    {
        Http::fake([
            '*/admin/thu-chi/tai-khoan*' => Http::response(['data' => [
                ['id' => 12, 'shop_id' => 2, 'branch_name' => 'CN1', 'label' => 'Vietcombank · 0071001234567 · NGUYEN VAN DUC', 'status' => true, 'is_default' => true],
                ['id' => 13, 'shop_id' => 2, 'branch_name' => 'CN1', 'label' => 'ACB · 2151 · CONG TY A', 'status' => false, 'is_default' => false],
            ]]),
            '*/admin/thu-chi*' => Http::response(['data' => [$this->dong()], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 1, 'total_pages' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);
        $html = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('admin.cashbook.index', ['bank_account_id' => 12]))->assertOk()->getContent());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/thu-chi?') && str_contains($r->url(), 'bank_account_id=12'));
        $this->assertStringContainsString('<option value="12" selected> Vietcombank · 0071001234567 · NGUYEN VAN DUC </option>', $html);
        $this->assertStringContainsString('ACB · 2151 · CONG TY A (đã tắt)', $html);
        $this->assertStringContainsString('Chuyển khoản<br><small class="text-muted">Vietcombank · 0071001234567 · NGUYEN VAN DUC</small>', $html);
        $this->assertStringContainsString('data-bank-account-id="12"', $html);
    }

    public function test_xuat_excel_co_cot_tai_khoan(): void
    {
        Http::fake([
            '*/admin/thu-chi*' => Http::response(['data' => [$this->dong()], 'meta' => ['page' => 1, 'page_size' => 100, 'total' => 1, 'total_pages' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);
        $tep = tempnam(sys_get_temp_dir(), 'tc');
        file_put_contents($tep, $this->withSession($this->phien())->get(route('admin.cashbook.export'))->assertOk()->getContent());
        $zip = new \ZipArchive;
        $zip->open($tep);
        $chu = ($zip->getFromName('xl/sharedStrings.xml') ?: '').($zip->getFromName('xl/worksheets/sheet1.xml') ?: '');
        $zip->close();
        unlink($tep);
        $this->assertStringContainsString('Tài khoản', $chu);
        $this->assertStringContainsString('Vietcombank · 0071001234567 · NGUYEN VAN DUC', $chu);
    }

    public function test_hop_lap_doc_tai_khoan_dang_bat(): void
    {
        Http::fake(['*/admin/thu-chi/tai-khoan*' => Http::response(['data' => [['id' => 12, 'label' => 'VCB', 'is_default' => true]]])]);
        $this->withSession($this->phien())->getJson(route('admin.cashbook.taiKhoan'))->assertOk()->assertJsonPath('data.0.id', 12);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cho=lap'));
    }

    public function test_bien_nhan_in_tai_khoan(): void
    {
        Http::fake(['*/admin/quay/thu-chi/41' => Http::response(['data' => $this->dong() + ['shift_id' => 9]]), '*' => Http::response(['data' => []])]);
        $html = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.bienNhan', 41))->assertOk()->getContent());
        $this->assertStringContainsString('<span>Tài khoản</span><span>Vietcombank · 0071001234567 · NGUYEN VAN DUC</span>', $html);
    }
}
