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
        // Giao diện v2 nằm ở web_Shop/v2, NGOÀI resources/views — gọi bằng
        // namespace: view('v2::nha-cung-cap.index'), @extends('v2::layouts.master').
        View::addNamespace('v2', base_path('v2'));

        // Chia sẻ số đơn "chờ xác nhận" cho sidebar (badge nhắc admin xử lý đơn mới).
        // Cache ngắn 30s để không gọi API mỗi lần chuyển trang; im lặng nếu API lỗi.
        View::composer('partials.sidebar', function ($view) {
            $pending = 0;
            if (session('api.access_token')) {
                try {
                    $pending = Cache::remember('sidebar.orders.pending', 30, function () {
                        $res = app(ApiClient::class)->orderStats();

                        return $res->successful() ? (int) ($res->json('data.pending') ?? 0) : 0;
                    });
                } catch (\Throwable $e) {
                    Log::info('Sidebar pending orders failed', ['msg' => $e->getMessage()]);
                }
            }
            $view->with('pendingOrders', $pending);

            // Số yêu cầu khách gửi chưa ai xử lý — huy hiệu cạnh "Yêu cầu của khách".
            // Cùng cách làm như trên: cache ngắn, API hỏng thì im lặng trả 0 chứ
            // không làm vỡ sidebar của mọi trang.
            $chuaXuLy = 0;
            if (session('api.access_token')) {
                try {
                    $chuaXuLy = Cache::remember('sidebar.contacts.pending', 30, function () {
                        $res = app(ApiClient::class)->contactStats();

                        return $res->successful() ? (int) ($res->json('data.moi') ?? 0) : 0;
                    });
                } catch (\Throwable $e) {
                    Log::info('Sidebar pending contacts failed', ['msg' => $e->getMessage()]);
                }
            }
            $view->with('pendingContacts', $chuaXuLy);
        });
    }
}
