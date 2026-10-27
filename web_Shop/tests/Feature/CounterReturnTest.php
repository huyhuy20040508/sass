<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CounterReturnController — màn Trả hàng của quầy: tham số đọc theo kỳ, ba trạng
 * thái nút lập phiếu, payload trả một bước / đổi hàng, câu lỗi của API đi nguyên văn.
 */
class CounterReturnTest extends TestCase
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
            'id' => 31, 'return_code' => 'TKH000043', 'order_id' => 9, 'exchange_order_id' => null, 'status' => 'refunded',
            'reason' => 'defective', 'reason_note' => 'Nứt góc', 'refund_method' => 'cash', 'items_amount' => 150000,
            'deduction' => 0, 'refund_amount' => 150000, 'restock' => false, 'created_at' => now()->setTime(10, 5)->toIso8601String(),
            'items' => [['product_name' => 'Kính cường lực', 'variant_name' => '', 'unit_price' => 150000, 'quantity' => 1, 'total_price' => 150000]],
            'order' => ['order_code' => 'HD001259', 'recipient_name' => 'Lê Hoàng Nam', 'recipient_phone' => '0978111222', 'payment_method' => 'cash'],
        ], $doi);
    }

    protected function giaApi(array $tuyChon = []): void
    {
        $ca = array_key_exists('ca', $tuyChon) ? $tuyChon['ca'] : ['id' => 12, 'opened_at' => now()->setTime(7, 55)->toIso8601String(), 'opening_cash' => 3000000, 'tong_thu' => 600000, 'tong_chi' => 150000];
        $rows = $tuyChon['rows'] ?? [
            $this->phieu(),
            $this->phieu(['id' => 30, 'return_code' => 'TKH000042', 'exchange_order_id' => 77, 'refund_method' => 'none', 'refund_amount' => 0, 'items_amount' => 350000, 'restock' => true,
                'exchange_order' => ['order_code' => 'HD001270', 'total_amount' => 500000, 'payment_method' => 'cash', 'items' => []]]),
        ];

        Http::fake(function (HttpRequest $req) use ($ca, $rows, $tuyChon) {
            $p = $this->duong($req);
            if (str_ends_with($p, '/admin/ca-lam-viec/hien-tai')) {
                return $ca ? Http::response(['data' => $ca]) : Http::response(['message' => 'Chưa có ca'], 404);
            }
            if (str_ends_with($p, '/admin/quay/tra-hang') && $req->method() === 'GET') {
                if (! empty($tuyChon['listLoi'])) {
                    return Http::response(['message' => 'Lỗi đọc sổ'], 500);
                }

                return Http::response(['data' => $rows, 'meta' => [
                    'page' => 1, 'page_size' => 50, 'total' => count($rows), 'total_pages' => 1,
                    'count' => 2, 'swap_count' => 1, 'item_quantity' => 2, 'restock_quantity' => 1,
                    'refund_amount' => 150000, 'cash_refund_amount' => 150000, 'co_the_tra' => $tuyChon['coTheTra'] ?? true,
                ]]);
            }

            return Http::response(['data' => []]);
        });
    }

    protected function luotDoc(): array
    {
        return collect(Http::recorded())->map(fn ($c) => $c[0])
            ->filter(fn ($r) => $r->method() === 'GET' && str_ends_with($this->duong($r), '/admin/quay/tra-hang'))
            ->map(fn ($r) => $this->query($r))->values()->all();
    }

    // ------------------------------------------------------------ trang

    public function test_co_ca_xem_tu_luc_mo_ca_va_lap_duoc_phieu(): void
    {
        $this->giaApi();

        $html = $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.index'))
            ->assertOk()->assertViewIs('v2::pos.returns')->getContent();

        $q = $this->luotDoc()[0];
        $this->assertSame(now()->format('Y-m-d').' 07:55:00', $q['from_time']);
        $this->assertArrayNotHasKey('from_date', $q);
        $this->assertStringContainsString('TKH000043', $html);
        $this->assertStringContainsString('Ca #12 · mở 07:55 hôm nay', $html);
        $this->assertStringContainsString('Két theo sổ 3.450.000 đ', $html);
        $this->assertStringContainsString('+150.000', $html, 'phiếu đổi: hàng mới 500.000 − hàng trả 350.000');
        $this->assertMatchesRegularExpression('/data-create(?![^>]*disabled)/', $html);
        $this->assertStringNotContainsString('tc-alert--lock', $html);
        $this->assertMatchesRegularExpression('/Hoàn tiền<span class="tc-tab__n">1</', $html);
    }

    public function test_chua_mo_ca_xem_hom_nay_va_khoa_nut(): void
    {
        $this->giaApi(['ca' => null, 'rows' => []]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.index', ['ky' => 'ca']))->assertOk()->getContent();

        $q = $this->luotDoc()[0];
        $this->assertSame([now()->toDateString(), now()->toDateString()], [$q['from_date'], $q['to_date']]);
        $this->assertStringContainsString('tc-alert--lock', $html);
        $this->assertMatchesRegularExpression('/data-create[^>]*disabled/', $html);
    }

    public function test_khong_co_quyen_thi_chi_xem(): void
    {
        $this->giaApi(['coTheTra' => false]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Chỉ xem', $html);
        $this->assertStringNotContainsString('btn--ret" data-create', $html);
    }

    public function test_tu_khoa_loai_va_tham_so_la(): void
    {
        $this->giaApi();

        $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.index', ['q' => ' HD001259 ', 'kind' => 'swap']))->assertOk();
        $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.index', ['ky' => 'bay', 'kind' => 'xx', 'page' => '-2']))->assertOk();

        [$tim, $la] = $this->luotDoc();
        $this->assertSame('HD001259', $tim['keyword']);
        $this->assertSame('swap', $tim['kind']);
        $this->assertArrayNotHasKey('from_time', $tim);
        $this->assertSame(now()->toDateString(), $la['from_date']);
        $this->assertArrayNotHasKey('kind', $la);
        $this->assertSame('1', $la['page']);
    }

    public function test_mo_san_don_tu_lich_su(): void
    {
        $this->giaApi();

        $html = $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.index', ['don' => 55]))->assertOk()->getContent();

        $this->assertStringContainsString('"moDon":55', $html);
    }

    public function test_api_hong_van_mo_trang(): void
    {
        $this->giaApi(['listLoi' => true]);

        $html = $this->withSession($this->phien())->get(route('thu-ngan.tra-hang.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Không tải được phiếu trả hàng', $html);
    }

    // ------------------------------------------------------------ JSON + ghi

    public function test_tim_don_va_mon_tra_goi_dung_duong(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.tra-hang.don', ['q' => ' 0987 ']))->assertOk();
        $this->withSession($this->phien())->getJson(route('thu-ngan.tra-hang.don', ['days' => 99]))->assertOk();
        $this->withSession($this->phien())->getJson(route('thu-ngan.tra-hang.mon', ['id' => 55]))->assertOk();

        $dt = collect(Http::recorded())->map(fn ($c) => $c[0]);
        $this->assertSame(['q' => '0987', 'days' => '7'], $this->query($dt[0]));
        $this->assertSame('7', $this->query($dt[1])['days'], 'số ngày lạ lùi về 7');
        $this->assertStringEndsWith('/admin/quay/tra-hang/don/55', $this->duong($dt[2]));
    }

    public function test_tra_mot_buoc_gui_dung_payload(): void
    {
        Http::fake(['*/admin/quay/tra-hang' => Http::response(['data' => ['id' => 32, 'return_code' => 'TKH000044', 'refund_amount' => 280000]], 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.tra-hang.store'), [
            'order_id' => 55, 'reason' => 'defective', 'reason_note' => '  Nứt  ', 'refund_method' => 'cash',
            'deduction' => 20000, 'restock' => false, 'items' => [['order_item_id' => 9, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('data.return_code', 'TKH000044');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($this->duong($r), '/admin/quay/tra-hang')
            && $r['order_id'] === 55 && $r['reason_note'] === 'Nứt' && $r['restock'] === false && (float) $r['deduction'] === 20000.0
            && $r['items'] === [['order_item_id' => 9, 'quantity' => 2]]);
    }

    public function test_tra_thieu_ly_do_hoac_hinh_thuc_la_khong_gui(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.tra-hang.store'), [
            'order_id' => 55, 'refund_method' => 'ewallet', 'restock' => true, 'items' => [['order_item_id' => 9, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['reason', 'refund_method']);
        Http::assertNothingSent();
    }

    public function test_doi_hang_gui_dung_duong_va_payload(): void
    {
        Http::fake(['*/admin/orders/pos/doi-hang' => Http::response(['data' => ['return_id' => 33, 'return_code' => 'TKH000045', 'chenh_lech' => 150000]], 201)]);

        $this->withSession($this->phien())->postJson(route('thu-ngan.tra-hang.doi'), [
            'order_id' => 55, 'reason' => 'wrong_size', 'payment_method' => 'cash', 'restock' => true,
            'tra' => [['order_item_id' => 9, 'quantity' => 1]], 'moi' => [['product_variant_id' => 4, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('data.return_id', 33);

        Http::assertSent(fn ($r) => str_ends_with($this->duong($r), '/admin/orders/pos/doi-hang')
            && $r['moi'] === [['product_variant_id' => 4, 'quantity' => 2]] && $r['tra'] === [['order_item_id' => 9, 'quantity' => 1]]);
    }

    public function test_loi_api_di_nguyen_van(): void
    {
        Http::fake(['*/admin/quay/tra-hang' => Http::response(['message' => 'Đơn đã có HĐĐT — trả hàng phải kèm hoá đơn điều chỉnh, làm ở Quản trị.'], 409)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.tra-hang.store'), [
            'order_id' => 55, 'reason' => 'other', 'refund_method' => 'cash', 'restock' => true, 'items' => [['order_item_id' => 9, 'quantity' => 1]],
        ])->assertStatus(409)->assertJsonPath('message', 'Đơn đã có HĐĐT — trả hàng phải kèm hoá đơn điều chỉnh, làm ở Quản trị.');
    }

    public function test_chua_mo_ca_giu_ma(): void
    {
        Http::fake(['*/admin/quay/tra-hang' => Http::response(['message' => 'Chi nhánh chưa mở ca', 'errors' => ['ma' => 'CHUA_MO_CA']], 409)]);
        $this->withSession($this->phien())->postJson(route('thu-ngan.tra-hang.store'), [
            'order_id' => 55, 'reason' => 'other', 'refund_method' => 'cash', 'restock' => true, 'items' => [['order_item_id' => 9, 'quantity' => 1]],
        ])->assertStatus(409)->assertJsonPath('errors.ma', 'CHUA_MO_CA');
    }
}
