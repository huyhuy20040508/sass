<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CounterCashbookController — màn Thu chi của quầy: tham số gửi API theo kỳ, ba
 * trạng thái của nút lập phiếu (có ca / chưa mở ca / chỉ xem), payload lập phiếu
 * và câu lỗi của API đi nguyên văn.
 */
class CounterCashbookTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff'], 'access_areas' => 'thu_ngan'],
        ];
    }

    protected function duong(HttpRequest $req): string
    {
        return (string) parse_url($req->url(), PHP_URL_PATH);
    }

    protected function query(HttpRequest $req): array
    {
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    protected function phieu(array $doi = []): array
    {
        return array_merge([
            'id' => 41, 'code' => 'PC000053', 'type' => 1, 'amount' => 60000, 'payment_method' => 'cash',
            'category_id' => 4, 'category_name' => 'Điện, nước, Internet', 'payer_type' => '', 'payer_id' => null,
            'payer_name' => '', 'note' => 'Nạp thẻ điện thoại quầy', 'attachment_url' => '', 'shift_id' => 9,
            'created_at' => now()->setTime(14, 45)->toIso8601String(), 'created_by_name' => 'Nhân viên quầy',
            'locked' => false, 'locked_reason' => '',
        ], $doi);
    }

    /** @param array<string, mixed> $tuyChon ca, coTheLap, rows, listLoi */
    protected function giaApi(array $tuyChon = []): void
    {
        $ca = array_key_exists('ca', $tuyChon) ? $tuyChon['ca'] : ['id' => 9, 'opened_at' => now()->setTime(7, 58)->toIso8601String(), 'opening_cash' => 2000000, 'tong_thu' => 500000, 'tong_chi' => 60000];
        $rows = $tuyChon['rows'] ?? [$this->phieu(), $this->phieu(['id' => 40, 'code' => 'PT000015', 'type' => 0, 'amount' => 500000, 'note' => 'Bổ sung tiền lẻ'])];

        Http::fake(function (HttpRequest $req) use ($ca, $rows, $tuyChon) {
            $p = $this->duong($req);
            if (str_ends_with($p, '/admin/ca-lam-viec/hien-tai')) {
                return $ca ? Http::response(['data' => $ca]) : Http::response(['message' => 'Chưa có ca'], 404);
            }
            if (str_ends_with($p, '/admin/quay/thu-chi/danh-muc')) {
                return Http::response(['data' => ['phan_loai' => [['id' => 4, 'type' => 1, 'name' => 'Điện, nước, Internet']], 'nhan_vien' => [], 'nguoi_nop' => []]]);
            }
            if (str_ends_with($p, '/admin/quay/thu-chi') && $req->method() === 'GET') {
                if (! empty($tuyChon['listLoi'])) {
                    return Http::response(['message' => 'Lỗi đọc sổ'], 500);
                }

                return Http::response(['data' => $rows, 'meta' => [
                    'page' => 1, 'page_size' => 50, 'total' => count($rows), 'total_pages' => 1,
                    'total_income' => 500000, 'total_expense' => 60000, 'cash_income' => 500000, 'cash_expense' => 60000,
                    'income_count' => 1, 'expense_count' => 1,
                    'ca_dang_mo' => $ca['id'] ?? null, 'co_the_lap' => $tuyChon['coTheLap'] ?? true,
                ]]);
            }

            return Http::response(['data' => []]);
        });
    }

    protected function cacLuotDoc(): array
    {
        return collect(Http::recorded())
            ->map(fn ($cap) => $cap[0])
            ->filter(fn ($req) => $req->method() === 'GET' && str_ends_with($this->duong($req), '/admin/quay/thu-chi'))
            ->map(fn ($req) => $this->query($req))
            ->values()->all();
    }

    // ------------------------------------------------------------ trang

    public function test_co_ca_mac_dinh_xem_ca_nay_va_mo_duoc_hop_lap(): void
    {
        $this->giaApi();

        $html = $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.index'))
            ->assertOk()->assertViewIs('v2::pos.cashbook')->getContent();

        $doc = $this->cacLuotDoc();
        $this->assertCount(1, $doc, 'tab Tất cả thì một lượt đọc là đủ cho cả danh sách lẫn thẻ tổng');
        $this->assertSame('9', $doc[0]['shift_id'] ?? null);
        $this->assertArrayNotHasKey('from_date', $doc[0]);

        $this->assertStringContainsString('PC000053', $html);
        $this->assertStringContainsString('Ca #9 · mở 07:58 hôm nay', $html);
        $this->assertStringContainsString('Két theo sổ 2.440.000 đ', $html);
        $this->assertStringContainsString('+440.000', $html, 'tiền mặt qua két = 500.000 − 60.000');
        $this->assertMatchesRegularExpression('/data-create="out"(?![^>]*disabled)/', $html);
        $this->assertStringNotContainsString('tc-alert--lock', $html);
        Http::assertSent(fn ($r) => str_ends_with($this->duong($r), '/admin/quay/thu-chi/danh-muc'));
        // Thẻ trong khung hộp lập phiếu đủ cả hai cột.
        $this->assertStringContainsString('tcm-col--money', $html);
    }

    public function test_chua_mo_ca_thi_xem_hom_nay_va_khoa_nut_lap(): void
    {
        $this->giaApi(['ca' => null, 'rows' => []]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.index', ['ky' => 'ca']))
            ->assertOk()->getContent();

        $doc = $this->cacLuotDoc()[0];
        $this->assertSame(now()->toDateString(), $doc['from_date']);
        $this->assertSame(now()->toDateString(), $doc['to_date']);
        $this->assertArrayNotHasKey('shift_id', $doc);
        $this->assertStringContainsString('tc-alert--lock', $html);
        $this->assertMatchesRegularExpression('/data-create="out"[^>]*disabled/', $html);
        Http::assertNotSent(fn ($r) => str_ends_with($this->duong($r), '/admin/quay/thu-chi/danh-muc'));
    }

    public function test_khong_co_quyen_ghi_thi_chi_xem(): void
    {
        $this->giaApi(['coTheLap' => false]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Chỉ xem', $html);
        $this->assertStringNotContainsString('data-create=', $html);
        Http::assertNotSent(fn ($r) => str_ends_with($this->duong($r), '/admin/quay/thu-chi/danh-muc'));
    }

    public function test_tu_khoa_tim_moi_ngay_va_tab_loai_doc_them_tong(): void
    {
        $this->giaApi();

        $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.index', ['q' => ' ship ', 'type' => '1', 'ky' => 'thang-nay']))->assertOk();

        [$ds, $tong] = $this->cacLuotDoc();
        $this->assertSame('ship', $ds['q']);
        $this->assertSame('1', $ds['type']);
        $this->assertArrayNotHasKey('from_date', $ds);
        $this->assertArrayNotHasKey('shift_id', $ds);
        $this->assertArrayNotHasKey('type', $tong, 'thẻ tổng và số trên tab đếm cả hai vế');
        $this->assertSame('ship', $tong['q']);
    }

    public function test_tham_so_la_lui_ve_mac_dinh(): void
    {
        $this->giaApi();

        $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.index', ['ky' => 'bay', 'type' => '9', 'page' => '-3']))->assertOk();

        $doc = $this->cacLuotDoc()[0];
        $this->assertSame(now()->toDateString(), $doc['from_date']);
        $this->assertArrayNotHasKey('type', $doc);
        $this->assertSame('1', $doc['page']);
    }

    public function test_tuy_chon_dao_ngay(): void
    {
        $this->giaApi();

        $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.index', ['ky' => 'tuy-chon', 'from_date' => '2026-10-10', 'to_date' => '2026-10-01']))->assertOk();

        $doc = $this->cacLuotDoc()[0];
        $this->assertSame(['2026-10-01', '2026-10-10'], [$doc['from_date'], $doc['to_date']]);
    }

    public function test_api_hong_thi_trang_van_mo_va_bao_loi(): void
    {
        $this->giaApi(['listLoi' => true]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.thu-chi.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Không tải được phiếu thu chi', $html);
    }

    // ------------------------------------------------------------ ghi

    public function test_lap_phieu_chi_gui_dung_payload(): void
    {
        Http::fake(['*/admin/quay/thu-chi' => Http::response(['data' => ['id' => 55, 'code' => 'PC000055']], 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.thu-chi.store'), [
            'type' => 1, 'amount' => 120000, 'payment_method' => 'cash', 'note' => '  Trả ship  ',
            'category_id' => 12, 'payer_type' => 'other', 'payer_id' => 3, 'attachment' => '',
        ])->assertOk()->assertJsonPath('data.code', 'PC000055');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($this->duong($r), '/admin/quay/thu-chi')
            && $r['type'] === 1 && (float) $r['amount'] === 120000.0 && $r['note'] === 'Trả ship'
            && $r['category_id'] === 12 && $r['payer_type'] === 'other' && $r['payer_id'] === 3);
    }

    public function test_phieu_chi_bat_buoc_mo_ta_phieu_thu_thi_khong(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.thu-chi.store'), ['category_id' => 4, 'type' => 1, 'amount' => 5000, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        Http::assertNothingSent();

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.thu-chi.store'), ['category_id' => 4, 'type' => 0, 'amount' => 5000, 'payment_method' => 'cash'])
            ->assertOk();
    }

    public function test_quay_khong_nhan_nha_cung_cap_va_bo_loai_khi_khong_co_nguoi(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.thu-chi.store'), ['category_id' => 4, 'type' => 0, 'amount' => 5000, 'payment_method' => 'cash', 'payer_type' => 'supplier', 'payer_id' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('payer_type');

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.thu-chi.store'), ['category_id' => 4, 'type' => 0, 'amount' => 5000, 'payment_method' => 'transfer', 'payer_type' => 'thu_ngan'])
            ->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['payer_type'] === null && $r['payment_method'] === 'transfer');
    }

    /** KB #78: phiếu lập tay thiếu phân loại bị chặn ngay, không gọi API — cả lập lẫn sửa. */
    public function test_phieu_tay_bat_buoc_phan_loai(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.thu-chi.store'), ['type' => 1, 'amount' => 20000, 'payment_method' => 'cash', 'note' => 'Mua nước'])
            ->assertStatus(422)->assertJsonPath('errors.category_id.0', 'Chọn phân loại cho phiếu.');
        $this->withSession($this->phien())
            ->putJson(route('thu-ngan.thu-chi.update', ['id' => 41]), ['type' => 1, 'amount' => 20000, 'payment_method' => 'cash', 'note' => 'Mua nước', 'category_id' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');
        Http::assertNothingSent();
    }

    public function test_chua_mo_ca_giu_ma_va_cau_cua_api(): void
    {
        Http::fake(['*/admin/quay/thu-chi' => Http::response([
            'message' => 'Chi nhánh chưa mở ca — mở ca rồi mới lập phiếu thu chi ở quầy.', 'errors' => ['ma' => 'CHUA_MO_CA'],
        ], 409)]);

        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.thu-chi.store'), ['category_id' => 4, 'type' => 0, 'amount' => 5000, 'payment_method' => 'cash'])
            ->assertStatus(409)
            ->assertJsonPath('errors.ma', 'CHUA_MO_CA')
            ->assertJsonPath('message', 'Chi nhánh chưa mở ca — mở ca rồi mới lập phiếu thu chi ở quầy.');
    }

    public function test_sua_xoa_va_them_nguoi_nop_goi_dung_duong(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 41]])]);

        $this->withSession($this->phien())
            ->putJson(route('thu-ngan.thu-chi.update', ['id' => 41]), ['category_id' => 4, 'type' => 1, 'amount' => 70000, 'payment_method' => 'transfer', 'note' => 'Sửa', 'reason' => 'Đổi hình thức'])
            ->assertOk();
        $this->withSession($this->phien())->deleteJson(route('thu-ngan.thu-chi.destroy', ['id' => 41]), ['reason' => 'Lập trùng'])->assertOk();
        $this->withSession($this->phien())
            ->postJson(route('thu-ngan.thu-chi.taoNguoiNop'), ['name' => ' Anh Tú ', 'phone' => '0987112233'])
            ->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($this->duong($r), '/admin/quay/thu-chi/41') && (float) $r['amount'] === 70000.0);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($this->duong($r), '/admin/quay/thu-chi/41'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($this->duong($r), '/admin/quay/nguoi-nop-thu-chi') && $r['name'] === 'Anh Tú');
    }
}
