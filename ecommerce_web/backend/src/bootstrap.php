<?php

declare(strict_types=1);

// Không dùng Composer: autoload tay theo PSR-4 cho gốc App\ → src/.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Core\Env::load(dirname(__DIR__) . '/.env');

date_default_timezone_set(App\Core\Env::get('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));
