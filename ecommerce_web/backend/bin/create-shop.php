<?php

declare(strict_types=1);

// Tạo shop, hoặc đổi tên + mở lại shop đã có: php bin/create-shop.php <mã> "<tên>"
// <mã> phải trùng mã cửa hàng bên POS.

use App\Core\Db;

require dirname(__DIR__) . '/src/bootstrap.php';

[, $code, $name] = $argv + [null, '', ''];
$code = strtolower(trim((string) $code));
$name = trim((string) $name);
if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,29}$/', $code) || $name === '') {
    fwrite(STDERR, "Cách dùng: php bin/create-shop.php <mã cửa hàng POS> \"<tên shop>\"\n");
    exit(1);
}

Db::exec(
    'INSERT INTO shops (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1',
    [$code, $name],
);
echo "Đã lưu shop $code\n";
