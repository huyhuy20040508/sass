<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\View;

/**
 * Màn Thu ngân chưa có bản v2 thì đưa về quầy, đừng để trang chết.
 *
 * Đợt port sang v2 đi từng màn: controller đã trỏ hết sang `v2::pos.*`
 * nhưng mới dựng xong màn bán hàng. Thiếu view là Laravel ném 500 ngay giữa ca
 * — người đứng quầy nhận trang trắng, không có đường quay lại.
 *
 * Dựng xong cả bốn màn thì gỡ trait này.
 */
trait V2ScreenFallback
{
    protected function veQuayNeuThieuView(string $view): ?RedirectResponse
    {
        if (View::exists($view)) {
            return null;
        }

        return redirect()->route('thu-ngan.ban-hang.index')
            ->with('error', 'Màn này đang được dựng lại, tạm thời chưa mở.');
    }
}
