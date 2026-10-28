<?php

declare(strict_types=1);

// Tạo shop, hoặc đổi tên + mở lại shop đã có: php bin/create-shop.php <mã> "<tên>"
// <mã> phải trùng mã cửa hàng bên POS. Shop mới có luôn địa chỉ <nhãn>.<WEB_BASE_DOMAIN>.

use App\Core\Db;
use App\Core\Env;

require dirname(__DIR__) . '/src/bootstrap.php';

[, $code, $name] = $argv + [null, '', ''];
$code = strtolower(trim((string) $code));
$name = trim((string) $name);
// Cùng luật mã cửa hàng bên POS (trial_service.go).
if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,29}$/', $code) || $name === '') {
    fwrite(STDERR, "Cách dùng: php bin/create-shop.php <mã cửa hàng POS> \"<tên shop>\"\n");
    exit(1);
}
$base = strtolower(trim(Env::get('WEB_BASE_DOMAIN'), '.'));
if ($base === '') {
    fwrite(STDERR, "Chưa khai WEB_BASE_DOMAIN trong .env\n");
    exit(1);
}

Db::exec(
    'INSERT INTO shops (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1',
    [$code, $name],
);
$shopId = (int) Db::one('SELECT id FROM shops WHERE code = ?', [$code])['id'];

// Mã POS cho phép "." và "_", nhãn tên miền thì không: đổi thành "-".
$host = trim((string) preg_replace('/[._]+/', '-', $code), '-') . '.' . $base;
$owner = Db::one('SELECT shop_id FROM shop_domains WHERE host = ?', [$host]);
if ($owner !== null && (int) $owner['shop_id'] !== $shopId) {
    fwrite(STDERR, "Đã lưu shop $code nhưng địa chỉ $host đang thuộc shop khác — gắn tên miền khác bằng bin/shop-domain.php\n");
    exit(1);
}
if ($owner === null) {
    $hasPrimary = Db::one('SELECT 1 FROM shop_domains WHERE shop_id = ? AND is_primary = 1', [$shopId]) !== null;
    Db::exec('INSERT INTO shop_domains (shop_id, host, is_primary) VALUES (?, ?, ?)', [$shopId, $host, $hasPrimary ? 0 : 1]);
}
echo "Đã lưu shop $code — https://$host\n";
