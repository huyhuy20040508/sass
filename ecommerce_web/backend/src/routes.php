<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\ShopController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/api/health', [HealthController::class, 'show']);

    // Route của một shop khai `shop: true`: Router tìm shop theo store_id trước khi vào controller.
    $r->get('/api/shop', [ShopController::class, 'show'], shop: true);
};
