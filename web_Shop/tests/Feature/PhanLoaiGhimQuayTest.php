<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cài đặt → Quầy bán hàng: ô ghim phân loại cho dãy viên chọn ở hộp lập phiếu thu chi của quầy. */
class PhanLoaiGhimQuayTest extends TestCase
{
    protected function phien(): array
    {
        return [
            'api.access_token' => 'token-thu',
            'api.refresh_token' => 'refresh-thu',
            'api.user' => ['id' => 7, 'full_name' => 'Quản lý Hùng', 'role' => ['name' => 'admin'], 'access_areas' => 'quan_ly,thu_ngan'],
        ];
    }

    protected function fake(string $ghim, bool $coDanhSach = true): void
    {
        Http::fake([
            '*/admin/settings*' => Http::response(['data' => [
                'values' => ['pos_cashbook_pinned_types' => $ghim],
                'fields' => [['key' => 'pos_cashbook_pinned_types', 'group' => 'pos', 'type' => 'id_list', 'label' => 'Phân loại ghim ở hộp lập phiếu thu chi (quầy)', 'max_len' => 200]],
            ]]),
            '*/admin/loai-thu-chi' => $coDanhSach
                ? Http::response(['data' => [
                    ['id' => 2, 'type' => 0, 'name' => 'Các khoản thu khác'],
                    ['id' => 4, 'type' => 1, 'name' => 'Lương, thưởng'],
                    ['id' => 24, 'type' => 1, 'name' => 'Vận chuyển, giao hàng'],
                ]])
                : Http::response(['message' => 'Không có quyền'], 403),
            '*' => Http::response(['data' => []]),
        ]);
    }

    public function test_o_ghim_dung_vien_tick_theo_thu_chi(): void
    {
        $this->fake('24,2');
        $html = preg_replace('/\s+/', ' ', $this->withSession($this->phien())->get(route('admin.settings.page', 'pos'))->assertOk()->getContent());
        $this->assertStringContainsString('<input type="hidden" name="items[pos_cashbook_pinned_types][]" value="">', $html);
        $this->assertMatchesRegularExpression('#<span class="set-pins-title">Phiếu thu</span> <label class="set-pin"> <input type="checkbox" name="items\[pos_cashbook_pinned_types\]\[\]" value="2" checked> <span>Các khoản thu khác</span>#', $html);
        $this->assertMatchesRegularExpression('#<span class="set-pins-title">Phiếu chi</span> <label class="set-pin"> <input type="checkbox" name="items\[pos_cashbook_pinned_types\]\[\]" value="4" > <span>Lương, thưởng</span>#', $html);
        $this->assertMatchesRegularExpression('#value="24" checked> <span>Vận chuyển, giao hàng</span>#', $html);
        $this->assertStringContainsString('tối đa 6 mỗi bên', $html);
    }

    public function test_luu_gui_chuoi_id(): void
    {
        $this->fake('');
        $this->withSession($this->phien())->put(route('admin.settings.update', 'pos'), ['items' => ['pos_cashbook_pinned_types' => ['', '24', '2']]])
            ->assertRedirect(route('admin.settings.page', 'pos'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && ($r['items']['pos_cashbook_pinned_types'] ?? null) === '24,2');
    }

    public function test_bo_het_tick_la_xoa_danh_sach(): void
    {
        $this->fake('24');
        $this->withSession($this->phien())->put(route('admin.settings.update', 'pos'), ['items' => ['pos_cashbook_pinned_types' => ['']]]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && ($r['items']['pos_cashbook_pinned_types'] ?? null) === '');
    }

    public function test_khong_tai_duoc_loai_thu_chi_thi_lui_ve_o_go_ma(): void
    {
        $this->fake('24,2', coDanhSach: false);
        $html = $this->withSession($this->phien())->get(route('admin.settings.page', 'pos'))->assertOk()->getContent();
        $this->assertStringNotContainsString('class="set-pins"', $html);
        $this->assertMatchesRegularExpression('#<input type="text" name="items\[pos_cashbook_pinned_types\]" id="set_pos_cashbook_pinned_types"\s+class="set-input "\s+value="24,2"#', $html);
    }
}
