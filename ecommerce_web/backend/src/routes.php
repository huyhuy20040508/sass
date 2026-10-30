<?php

declare(strict_types=1);

use App\Controllers\CuaHangController;
use App\Controllers\HealthController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/api/health', [HealthController::class, 'show']);
    $r->get('/api/cua-hang', [CuaHangController::class, 'show']);
};
