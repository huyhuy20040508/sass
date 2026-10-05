<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM → Danh sách khách hàng — khuôn crm/customers của v2, dùng chung dữ liệu
 * với Thống kê → Khách hàng.
 */
class CrmCustomerTest extends TestCase
{
    protected function phienQuanTri(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 1, 'full_name' => 'Quản trị', 'role' => ['name' => 'admin']],
        ];
    }

    protected function giaApi(): void
    {
        Http::fake([
            '*/admin/customers*' => Http::response([
                'data' => [[
                    'id' => 7, 'full_name' => 'Nguyễn An', 'customer_code' => 'cus-00007', 'customer_type' => 0,
                    'phone' => '0909000111', 'address' => '1 Tràng Tiền, Hà Nội',
                    'date_of_birth' => now()->addDays(2)->subYears(30)->format('Y-m-d'),
                    'customer_group_name' => 'Khách quen',
                    'total_orders' => 3, 'total_spent' => 1500000, 'total_paid' => 1000000, 'still_in_debt' => 500000,
                    'last_payment_amount' => 250000, 'last_payment_at' => '2026-09-20T10:00:00+07:00',
                ]],
                'meta' => ['page' => 1, 'page_size' => 10, 'total' => 1, 'total_pages' => 1],
            ]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    /**
     * BẢNG VỪA KHUNG THẺ Ở MỌI KHỔ, KHÔNG BẺ DÒNG, KHÔNG CẮT CHỮ.
     *
     * Mười ba cột để một dòng cần 1521px, trong khi khung rộng 999px (khổ 1280)
     * tới 1532px (khổ 1920). Cách cũ — `table-layout: auto` + nén chữ theo khổ
     * màn — vỡ kiểu nhảy cóc: vừa ở 1366 (đang nén chữ) và 1920 (khung đủ rộng)
     * nhưng thừa 182–214px ở 1440/1536/1600, vì bảng tự co theo nội dung nên cỡ
     * chữ đổi một nhịp là bề rộng nhảy theo.
     *
     * Nay: ba cột tiền tắt sẵn + chia % suy từ bề rộng tối thiểu, nên bảng luôn
     * đúng bằng khung.
     */
    public function test_bang_crm_chia_phan_tram_du_100(): void
    {
        $this->giaApi();

        $html = $this->withSession($this->phienQuanTri())
            ->get(route('admin.crm.customers.index'))->assertOk()->getContent();

        $dau = strpos($html, 'table.table-customer.none_mobile {');
        $this->assertNotFalse($dau, 'không thấy khối CSS của bảng');
        $khoi = substr($html, $dau, strpos($html, '.kh-tab-table') - $dau);

        $this->assertStringContainsString('table-layout: fixed', $khoi);
        $this->assertStringNotContainsString('table-layout: auto', $khoi);
        // Luật của dự án: không cắt chữ dưới mọi hình thức.
        $this->assertStringNotContainsString('text-overflow', $khoi);
        $this->assertStringNotContainsString('line-clamp', $khoi);

        preg_match_all('/none_mobile th[^{]*\{ width: ([0-9.]+)%/', $khoi, $m);
        $this->assertCount(13, $m[1], 'phải khai đủ 13 cột');
        $this->assertEqualsWithDelta(100.0, array_sum(array_map('floatval', $m[1])), 0.01);
    }

    /**
     * Ba cột tiền tắt sẵn, và sàn bề rộng tính theo ĐÚNG cột đang bật.
     *
     * Tính theo cột đang bật mới đúng: để một con số cứng thì tắt bớt cột xong
     * bảng vẫn rộng như cũ và cuộn ngang vô cớ.
     */
    public function test_cot_tien_tat_san_va_san_rong_theo_cot_dang_bat(): void
    {
        $this->giaApi();

        $C = \App\Http\Controllers\CustomerController::class;
        $url = route('admin.crm.customers.index');

        $html = $this->withSession($this->phienQuanTri())->get($url)->assertOk()->getContent();
        foreach ($C::COT_CRM_MAC_DINH_TAT as $cot) {
            $this->assertMatchesRegularExpression('/<th class="[^"]*show_'.$cot.'\s+hide"/', $html, "cột $cot phải tắt sẵn");
        }
        $this->assertMatchesRegularExpression('/<th class="[^"]*show_total_purchases\s*"/', $html, 'Tổng mua hàng phải bật sẵn');

        $rongMacDinh = array_sum($C::COT_CRM_RONG_TOI_THIEU)
            - array_sum(array_map(fn ($c) => $C::COT_CRM_RONG_TOI_THIEU[$c], $C::COT_CRM_MAC_DINH_TAT));
        $this->assertStringContainsString('--kh-rong: '.$rongMacDinh.'px', $html);
        // Vừa khung thẻ khổ 1440 (1132px) ở cỡ chữ đầy đủ — hai khổ hẹp hơn do
        // luật nén chữ lo nốt.
        $this->assertLessThan(1132, $rongMacDinh);

        // `hide=` rỗng = người dùng bật hết: không được quay về bộ tắt sẵn.
        $html = $this->withSession($this->phienQuanTri())->get($url.'?hide=')->assertOk()->getContent();
        $this->assertStringNotContainsString(' hide"', $html, 'bật hết cột mà vẫn còn cột bị tắt');
        $this->assertStringContainsString('--kh-rong: '.array_sum($C::COT_CRM_RONG_TOI_THIEU).'px', $html);

        // Tắt tay một cột: nghe theo người dùng, sàn hụt đúng cột đó.
        $html = $this->withSession($this->phienQuanTri())->get($url.'?hide=address')->assertOk()->getContent();
        $this->assertStringContainsString(
            '--kh-rong: '.(array_sum($C::COT_CRM_RONG_TOI_THIEU) - $C::COT_CRM_RONG_TOI_THIEU['address']).'px',
            $html
        );
    }

    /** Dòng "không có khách hàng" trải đúng số cột đang bật. */
    public function test_dong_rong_trai_dung_so_cot(): void
    {
        Http::fake([
            '*/admin/customers*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'page_size' => 10, 'total' => 0, 'total_pages' => 1]]),
            '*' => Http::response(['data' => []]),
        ]);

        $url = route('admin.crm.customers.index');

        // Mặc định: 2 cột cố định + 11 cột có bề rộng − 3 cột tắt sẵn = 10.
        $html = $this->withSession($this->phienQuanTri())->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('colspan="10"', $html);

        $html = $this->withSession($this->phienQuanTri())->get($url.'?hide=')->assertOk()->getContent();
        $this->assertStringContainsString('colspan="13"', $html);

        $html = $this->withSession($this->phienQuanTri())->get($url.'?hide=address,phone')->assertOk()->getContent();
        $this->assertStringContainsString('colspan="11"', $html);
    }

    public function test_man_crm_bay_bang_va_tab_crm(): void
    {
        $this->giaApi();

        $html = $this->withSession($this->phienQuanTri())
            ->get(route('admin.crm.customers.index'))
            ->assertOk()
            ->getContent();

        // Tab CRM trên thanh thứ hai, tab này đang sáng.
        $this->assertMatchesRegularExpression('/sub-nav-btn active">\s*Danh sách khách hàng/', $html);
        $this->assertStringContainsString('Thẻ thành viên', $html);
        // Chín khối lọc của v2.
        foreach (['filterCreatedAt', 'filterSearch', 'filterCustomer', 'filterAddress', 'filterBirthday',
            'filterLastTx', 'filterPoint', 'filterAge', 'filterLevelMembership'] as $khoi) {
            $this->assertStringContainsString('id="'.$khoi.'"', $html);
        }
        // Cột riêng của CRM: số đơn, chi tiêu gần nhất, bánh sinh nhật, nhóm.
        $this->assertStringContainsString('table-crm', $html);
        $this->assertStringContainsString('20/09/2026', $html);
        $this->assertStringContainsString('250.000', $html);
        $this->assertStringContainsString('fa-cake-candles', $html);
        $this->assertStringContainsString('Khách quen', $html);
    }

    public function test_bo_loc_va_sap_xep_gui_dung_len_api(): void
    {
        $this->giaApi();

        $this->withSession($this->phienQuanTri())
            ->get(route('admin.crm.customers.index', [
                'gender' => ['male', 'other'], 'address' => 'Hà Nội', 'age_from' => '18', 'age_to' => '40',
                'created_from' => '2026-01-01', 'created_to' => '2026-09-01',
                'birthday_mode' => 'preset', 'birthday_preset' => '7',
                'last_tx_mode' => 'custom', 'last_tx_from' => '2026-09-01', 'last_tx_to' => '2026-09-28',
                'sort_by' => 'total_debt', 'sort_dir' => 'asc',
            ]))
            ->assertOk();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/admin/customers')) {
                return false;
            }
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return ($q['genders'] ?? null) === 'male,other'
                && ($q['address'] ?? null) === 'Hà Nội'
                && ($q['age_from'] ?? null) === '18' && ($q['age_to'] ?? null) === '40'
                && ($q['created_from'] ?? null) === '2026-01-01'
                && ($q['birthday_days'] ?? null) === '7'
                && ($q['last_tx_from'] ?? null) === '2026-09-01' && ! isset($q['last_tx_days'])
                && ($q['sort'] ?? null) === 'debt_asc';
        });
    }

    public function test_man_thong_ke_khong_gui_bo_loc_crm(): void
    {
        $this->giaApi();

        $this->withSession($this->phienQuanTri())->get(route('admin.customers.index'))->assertOk();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/admin/customers')) {
                return false;
            }
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return ! isset($q['genders']) && ! isset($q['birthday_days']) && ($q['sort'] ?? '') === 'newest';
        });
    }
}
