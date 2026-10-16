<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Màn Thu ngân chưa có bản v2 thì đưa về quầy, KHÔNG trả 500.
 *
 * Cố ý KHÔNG dùng trait FakeCashierViews: bài này cần đúng cảnh view thật còn
 * thiếu. Các bài kiểm controller khác vẫn giả lập view để soát dữ liệu truyền
 * sang, đó là việc khác.
 */
class CashierScreenFallbackTest extends TestCase
{
    protected function phienThuNgan(): array
    {
        return [
            'api.access_token' => 'token-thu-ngan',
            'api.refresh_token' => 'refresh-thu-ngan',
            'api.user' => ['id' => 9, 'full_name' => 'Thu ngân', 'role' => ['name' => 'staff'], 'access_areas' => 'thu_ngan'],
            'api.tenant' => ['code' => 'quochuy', 'name' => 'Tiệm Quốc Huy'],
        ];
    }

    /**
     * Mọi màn quầy đã có view thật (Lịch sử, Điều phối ca), nên kiểm thẳng cơ chế
     * dự phòng với một view không tồn tại — màn quầy mới thêm sau vẫn dựa vào nó.
     */
    public function test_man_chua_co_ban_v2_thi_ve_quay(): void
    {
        $guard = new class
        {
            use \App\Http\Controllers\Concerns\V2ScreenFallback;

            public function thu(string $view)
            {
                return $this->veQuayNeuThieuView($view);
            }
        };

        $ve = $guard->thu('v2::pos.khong-co-man-nay');
        $this->assertNotNull($ve);
        $this->assertSame(route('thu-ngan.ban-hang.index'), $ve->getTargetUrl());
        $this->assertNotEmpty($ve->getSession()->get('error'));
        $this->assertNull($guard->thu('v2::pos.work-shift'));
    }

    /** Màn bán hàng đã có bản v2 nên phải mở thẳng, không bị guard đá về. */
    public function test_man_ban_hang_van_mo_duoc(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['data' => []])]);

        $this->withSession($this->phienThuNgan())
            ->get(route('thu-ngan.ban-hang.index'))
            ->assertOk();
    }
}
