<?php

declare(strict_types=1);

use App\Controllers\AdminAuthController;
use App\Controllers\HealthController;
use App\Controllers\ShopController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/api/health', [HealthController::class, 'show']);

    // Route của một shop khai `shop: true`: Router tìm shop theo tên miền đang mở trước khi vào controller.
    $r->get('/api/shop', [ShopController::class, 'show'], shop: true);

    // Route quản trị khai `admin: true`: phải có token đăng nhập của đúng shop đó.
    $r->post('/api/admin/login', [AdminAuthController::class, 'login'], shop: true);
    $r->get('/api/admin/me', [AdminAuthController::class, 'me'], admin: true);
    $r->post('/api/admin/logout', [AdminAuthController::class, 'logout'], admin: true);
};
