<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CounterCrmController — CRM ở quầy dùng đúng tham số của danh sách khách Quản trị,
 * thêm / sửa đi qua đường quầy cùng handler, sửa giữ nguyên các ô quầy không có,
 * trùng SĐT mở hồ sơ cũ thay vì tạo thêm.
 */
class CounterCrmTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu', 'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Nhân viên quầy', 'role' => ['name' => 'staff'], 'access_areas' => 'thu_ngan'],
        ];
    }

    protected function duong(HttpRequest $r): string
    {
        return (string) parse_url($r->url(), PHP_URL_PATH);
    }

    protected function query(HttpRequest $r): array
    {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    protected function khach(array $doi = []): array
    {
        return array_merge([
            'id' => 128, 'full_name' => 'Nguyễn Thị Mai', 'phone' => '0912 345 678', 'email' => 'mai@gmail.com', 'customer_code' => 'cus-00128',
            'customer_type' => 0, 'gender' => 'female', 'date_of_birth' => now()->addDays(2)->subYears(30)->toDateString(), 'address' => '12 Trần Thái Tông',
            'status' => 'active', 'avatar' => '/storage/a.png', 'citizen_id' => '001094000111', 'representative_name' => '', 'representative_phone' => '',
            'customer_group_id' => 1, 'customer_group_name' => 'Khách quen', 'customer_note' => 'Thích màu xanh', 'tax_code' => '',
            'total_points' => 5320, 'points' => 1280, 'rank_id' => 3, 'rank_name' => 'Vàng',
            'total_orders' => 3, 'total_spent' => 38035000, 'total_paid' => 38035000, 'still_in_debt' => 0, 'last_order_at' => now()->toIso8601String(),
        ], $doi);
    }

    protected function giaApi(array $ds = null): void
    {
        $ds ??= [$this->khach(), $this->khach(['id' => 131, 'full_name' => 'Trần Văn Bình', 'phone' => '0987654321', 'customer_code' => 'cus-00131', 'still_in_debt' => 300000, 'rank_id' => 1, 'rank_name' => 'Đồng', 'date_of_birth' => null])];
        Http::fake(function (HttpRequest $r) use ($ds) {
            $p = $this->duong($r);
            if (str_ends_with($p, '/admin/orders/pos/khach-hang') && $r->method() === 'GET') {
                $q = $this->query($r);
                $kq = isset($q['keyword']) && preg_match('/^\d+$/', $q['keyword']) ? array_values(array_filter($ds, fn ($c) => preg_replace('/\D/', '', $c['phone']) === $q['keyword'])) : $ds;

                return Http::response(['data' => $kq, 'meta' => ['page' => 1, 'page_size' => 30, 'total' => count($kq), 'total_pages' => 1]]);
            }
            if (str_ends_with($p, '/admin/orders/pos/thanh-vien')) {
                return Http::response(['data' => ['ranks' => [['id' => 1, 'name' => 'Đồng', 'point' => 0], ['id' => 3, 'name' => 'Vàng', 'point' => 5000], ['id' => 4, 'name' => 'Kim cương', 'point' => 15000]], 'money_per_point' => 100]]);
            }
            if (str_ends_with($p, '/admin/orders/pos/nhom-khach')) {
                return Http::response(['data' => [['id' => 1, 'type' => 0, 'name' => 'Khách quen'], ['id' => 5, 'type' => 1, 'name' => 'Đại lý']]]);
            }
            if (preg_match('#/admin/orders/pos/khach-hang/(\d+)$#', $p, $m) && $r->method() === 'GET') {
                return Http::response(['data' => collect($ds)->firstWhere('id', (int) $m[1])]);
            }

            return $r->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['data' => ['id' => 200, 'customer_code' => 'cus-00200']], $r->method() === 'POST' ? 201 : 200);
        });
    }

    protected function luotDanhSach(): array
    {
        return collect(Http::recorded())->map(fn ($c) => $c[0])
            ->filter(fn ($r) => $r->method() === 'GET' && str_ends_with($this->duong($r), '/admin/orders/pos/khach-hang'))
            ->map(fn ($r) => $this->query($r))->values()->all();
    }

    public function test_trang_doc_danh_sach_va_dem_nut_nhanh_bang_bo_loc_quan_tri(): void
    {
        $this->giaApi();

        $html = $this->withSession($this->phien())->get(route('thu-ngan.crm.index'))->assertOk()->assertViewIs('v2::pos.crm')->getContent();

        $ds = $this->luotDanhSach();
        $this->assertSame(['page' => '1', 'page_size' => '30'], $ds[0], 'mặc định: thứ tự mặc định của danh sách Quản trị');
        $this->assertSame('7', $ds[1]['birthday_days']);
        $this->assertSame('2000-01-01', $ds[2]['last_tx_from']);
        $this->assertSame(now()->subDays(61)->toDateString(), $ds[2]['last_tx_to'], 'cùng mốc với link "Lâu chưa quay lại" ở CRM → Tổng quan');
        // KB #95: nút "Nợ nhiều nhất" có số đếm, đếm bằng đúng bộ lọc chỉ-khách-còn-nợ.
        $this->assertSame(['only_debt' => '1', 'sort' => 'debt_desc', 'page_size' => '1'], $ds[3]);
        $this->assertStringContainsString('Nguyễn Thị Mai', $html);
        $this->assertStringContainsString('Nợ 300.000', $html);
        $this->assertStringContainsString('cr-cake', $html, 'sinh nhật sau 2 ngày');
        $this->assertStringContainsString('"tienMoiDiem":100', $html);
    }

    public function test_nut_nhanh_hang_loai_va_tim_ghep_dung_tham_so(): void
    {
        $this->giaApi();

        $this->withSession($this->phien())->get(route('thu-ngan.crm.index', ['nhanh' => 'debt', 'rank' => 3, 'type' => '1', 'q' => ' Mai ']))->assertOk();
        $this->withSession($this->phien())->get(route('thu-ngan.crm.index', ['nhanh' => 'xx', 'type' => '9', 'page' => '-1']))->assertOk();

        $ds = $this->luotDanhSach();
        $this->assertSame(['keyword' => 'Mai', 'rank_id' => '3', 'types' => '1', 'only_debt' => '1', 'sort' => 'debt_desc', 'page' => '1', 'page_size' => '30'], $ds[0]);
        $la = collect($ds)->last(fn ($q) => ($q['page_size'] ?? '') === '30');
        $this->assertSame(['page' => '1', 'page_size' => '30'], $la, 'tham số lạ lùi về mặc định');
    }

    public function test_them_khach_gui_dung_duong_quay(): void
    {
        $this->giaApi();

        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.store'), [
            'full_name' => ' Khách mới ', 'phone' => '0909 111 222', 'customer_type' => 0, 'gender' => 'male', 'date_of_birth' => '1990-01-02', 'customer_group_id' => 1,
        ])->assertOk()->assertJsonPath('data.customer_code', 'cus-00200');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($this->duong($r), '/admin/orders/pos/khach-hang/day-du')
            && $r['full_name'] === 'Khách mới' && $r['status'] === 'active' && $r['gender'] === 'male' && $r['customer_group_id'] === 1);
    }

    public function test_trung_sdt_khong_tao_ma_mo_ho_so_cu(): void
    {
        $this->giaApi();

        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.store'), ['full_name' => 'Ai đó', 'phone' => '0987.654.321'])
            ->assertStatus(409)->assertJsonPath('errors.ma', 'TRUNG_SDT')->assertJsonPath('data.id', 131);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_doanh_nghiep_bo_ngay_sinh_gioi_tinh(): void
    {
        $this->giaApi();

        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.store'), [
            'full_name' => 'Công ty A', 'customer_type' => 1, 'gender' => 'female', 'date_of_birth' => '1990-01-02', 'tax_code' => '0101234567',
        ])->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['customer_type'] === 1 && $r['gender'] === '' && $r['date_of_birth'] === '' && $r['tax_code'] === '0101234567');
    }

    public function test_sua_giu_nguyen_o_quay_khong_co(): void
    {
        $this->giaApi();

        $this->withSession($this->phien())->putJson(route('thu-ngan.crm.update', ['id' => 128]), [
            'full_name' => 'Nguyễn Thị Mai', 'phone' => '0912 345 678', 'customer_type' => 0, 'address' => '15 Trần Thái Tông',
        ])->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($this->duong($r), '/admin/orders/pos/khach-hang/128')
            && $r['address'] === '15 Trần Thái Tông' && $r['avatar'] === '/storage/a.png' && $r['citizen_id'] === '001094000111' && $r['status'] === 'active');
    }

    public function test_sua_thieu_quyen_noi_ro(): void
    {
        Http::fake(function (HttpRequest $r) {
            if ($r->method() === 'PUT') {
                return Http::response(['message' => 'Bạn không có quyền'], 403);
            }
            if (str_contains($this->duong($r), '/khach-hang/128')) {
                return Http::response(['data' => $this->khach()]);
            }

            return Http::response(['data' => []]);
        });

        $this->withSession($this->phien())->putJson(route('thu-ngan.crm.update', ['id' => 128]), ['full_name' => 'X'])
            ->assertStatus(403)->assertJsonPath('message', 'Tài khoản chưa được cấp quyền sửa khách (Khách hàng → Sửa). Nhờ quản lý cấp ở Quản trị → Nhân sự.');
    }

    public function test_thieu_ten_khong_gui(): void
    {
        $this->giaApi();
        $this->withSession($this->phien())->postJson(route('thu-ngan.crm.store'), ['phone' => '0909'])->assertStatus(422)->assertJsonValidationErrors('full_name');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_lich_su_giao_dich_cung_so_quan_tri(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 9, 'order_code' => 'HD1']]])]);

        $this->withSession($this->phien())->getJson(route('thu-ngan.crm.donHang', ['id' => 128]))->assertOk()->assertJsonPath('data.0.order_code', 'HD1');
        Http::assertSent(fn ($r) => str_ends_with($this->duong($r), '/admin/orders') && $this->query($r)['user_id'] === '128');
    }
}
