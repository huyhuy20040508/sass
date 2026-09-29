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
