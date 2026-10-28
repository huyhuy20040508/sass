<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Shop đang được phục vụ trong lượt gọi này, nhận diện theo TÊN MIỀN đang mở
 * (như web bán hàng KiotViet: mỗi shop một địa chỉ).
 *
 * Route khai `shop: true` (và mọi route `admin: true`) thì Router gọi resolve()
 * trước controller; controller và câu truy vấn chỉ lấy shop qua id().
 */
final class CurrentShop
{
    /** @var array{id: int, code: string, name: string}|null */
    private static ?array $shop = null;

    /** @return array{id: int, code: string, name: string} */
    public static function resolve(Request $req): array
    {
        $row = $req->host === '' ? null : Db::one(
            'SELECT s.id, s.code, s.name
               FROM shop_domains d
               JOIN shops s ON s.id = d.shop_id AND s.is_active = 1
              WHERE d.host = ?',
            [$req->host],
        );
        // Tên miền lạ hay shop đã tắt đều chung một câu: không để dò ra tiệm nào từng tồn tại.
        if ($row === null) {
            throw new HttpException(404, 'Không tìm thấy cửa hàng');
        }
        $row['id'] = (int) $row['id'];

        return self::$shop = $row;
    }

    /** @return array{id: int, code: string, name: string} */
    public static function get(): array
    {
        // Lỗi lập trình (route quên `shop: true`), không phải lỗi của khách — để rơi thành 500.
        return self::$shop ?? throw new \LogicException('Route này chưa khai shop: true');
    }

    public static function id(): int
    {
        return self::get()['id'];
    }
}
