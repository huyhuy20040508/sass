<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SaaS Admin — khu điều hành nền tảng
|--------------------------------------------------------------------------
|
| App này chạy trên host/cổng RIÊNG với Shop Admin, nên không cần prefix /saas:
| đường dẫn để ở gốc cho gọn.
|
| Hiện mới có đăng nhập + tổng quan. Các trang quản lý cửa hàng, gói dịch vụ và
| hoá đơn thêm vào nhóm `platform.auth` bên dưới khi Go API mở nhóm /platform/*.
|
*/

Route::get('/', fn () => redirect()->route('platform.dashboard'));

// --- Khách (chưa đăng nhập) ---
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

// --- Đăng xuất (KHÔNG nằm trong platform.auth để không mất request khi session hết hạn) ---
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// --- Khu điều hành (yêu cầu tài khoản trong sổ platform_users của nền tảng) ---
Route::middleware('platform.auth')->name('platform.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
