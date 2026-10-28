<?php

declare(strict_types=1);

// Gắn tên miền riêng cho shop: php bin/shop-domain.php <mã shop> <tên miền> [--chinh]
// --chinh: đặt làm địa chỉ chính (link in lên QR, hoá đơn).
// Gỡ: php bin/shop-domain.php --go <tên miền>
// Trước đó tiệm phải trỏ DNS về máy chủ; nginx + chứng chỉ cho tên miền đó làm riêng.

use App\Core\Db;

require dirname(__DIR__) . '/src/bootstrap.php';

$args = array_slice($argv, 1);
$usage = "Cách dùng: php bin/shop-domain.php <mã shop> <tên miền> [--chinh]\n           php bin/shop-domain.php --go <tên miền>\n";
$host = static fn (string $h): string => rtrim(strtolower(trim($h)), '.');
$validHost = static fn (string $h): bool => (bool) preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h);

if (($args[0] ?? '') === '--go') {
    $h = $host($args[1] ?? '');
    $n = Db::exec('DELETE FROM shop_domains WHERE host = ?', [$h]);
    echo $n ? "Đã gỡ $h\n" : "Không có tên miền $h\n";
    exit(0);
}

[$code, $h] = [strtolower(trim($args[0] ?? '')), $host($args[1] ?? '')];
$primary = in_array('--chinh', $args, true);
if ($code === '' || !$validHost($h)) {
    fwrite(STDERR, $usage);
    exit(1);
}
$shop = Db::one('SELECT id FROM shops WHERE code = ?', [$code]);
if ($shop === null) {
    fwrite(STDERR, "Không có shop $code — tạo trước bằng bin/create-shop.php\n");
    exit(1);
}
$owner = Db::one('SELECT shop_id FROM shop_domains WHERE host = ?', [$h]);
if ($owner !== null && (int) $owner['shop_id'] !== (int) $shop['id']) {
    fwrite(STDERR, "Tên miền $h đang thuộc shop khác\n");
    exit(1);
}

Db::transaction(static function () use ($shop, $h, $owner, $primary): void {
    if ($primary) {
        Db::exec('UPDATE shop_domains SET is_primary = 0 WHERE shop_id = ?', [$shop['id']]);
    }
    if ($owner === null) {
        Db::exec('INSERT INTO shop_domains (shop_id, host, is_primary) VALUES (?, ?, ?)', [$shop['id'], $h, $primary ? 1 : 0]);
    } elseif ($primary) {
        Db::exec('UPDATE shop_domains SET is_primary = 1 WHERE host = ?', [$h]);
    }
});
echo "Đã gắn $h cho shop $code" . ($primary ? ' (địa chỉ chính)' : '') . "\n";
