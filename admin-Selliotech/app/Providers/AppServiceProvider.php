<?php

namespace App\Providers;

use App\Services\ApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tình trạng Go API cho dải ở đáy thanh trái. Dải này có mặt ở MỌI trang
        // của khu điều hành, nên dữ liệu phải đến từ đây chứ không từ controller
        // của riêng một trang — thiếu là trang mới thêm sẽ hiện "mất kết nối" oan.
        //
        // Cache 30 giây: đổi trang liên tục không nên biến thành nhiêu đó lần gọi
        // /health. API hỏng thì trả false chứ không làm vỡ khung phần mềm.
        //
        // (Trước đây chỗ này là composer chép nguyên từ Shop Admin, gọi
        // orderStats()/contactStats() — hai hàm không có trong ApiClient của app
        // này, nên mỗi lần render đều ném lỗi rồi bị catch nuốt vào log.)
        View::composer(['partials.sidebar', 'dashboard'], function ($view) {
            $online = false;

            try {
                $online = Cache::remember('platform.api.health', 30, fn () => app(ApiClient::class)->health());
            } catch (\Throwable $e) {
                Log::info('Không kiểm tra được tình trạng Go API', ['msg' => $e->getMessage()]);
            }

            $view->with('apiOnline', $online);
        });
    }
}
