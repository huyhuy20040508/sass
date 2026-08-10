<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sau khi deploy, app nằm sau Nginx nên mọi request tới PHP đều là HTTP
        // từ 127.0.0.1. Không tin proxy thì asset()/url() sinh link http:// trên
        // trang https:// và trình duyệt chặn hết CSS/JS vì mixed content — kể cả
        // URL ảnh sản phẩm mà trang này ghi vào API lúc tải ảnh lên.
        // Nginx đứng cùng máy nên tin toàn bộ header X-Forwarded-* là an toàn.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\EnsureAdminAuthenticated::class,
            // Gắn thêm cho các trang quản lý người & cấu hình — nhân viên không vào.
            'admin.manage' => \App\Http\Middleware\EnsureManagerRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
