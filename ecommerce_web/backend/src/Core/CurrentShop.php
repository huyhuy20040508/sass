<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Shop đang được phục vụ trong lượt gọi này.
 *
 * Route khai `shop: true` thì Router gọi resolve() trước controller; controller
 * và câu truy vấn chỉ lấy shop qua id() — không tự đọc store_id từ request,
 * để không có chỗ nào quên kiểm shop còn hoạt động.
 */
final class CurrentShop
{
    // Cùng luật mã cửa hàng bên POS (trial_service.go).
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,29}$/';

    /** @var array{id: int, code: string, name: string}|null */
    private static ?array $shop = null;

    /** @return array{id: int, code: string, name: string} */
    public static function resolve(Request $req): array
    {
        // Frontend gửi qua header cho mọi lượt gọi; query để mở thử bằng trình duyệt cũng được.
        $code = $req->header('x-store-id') ?: (string) ($req->query['store_id'] ?? $req->query['slug'] ?? '');
        $code = strtolower(trim($code));
        if ($code === '') {
            throw new HttpException(400, 'Thiếu mã cửa hàng');
        }

        // Mã sai khuôn, không có, hay đã tắt đều chung một câu: không để dò ra tiệm nào từng tồn tại.
        $row = preg_match(self::CODE_PATTERN, $code)
            ? Db::one('SELECT id, code, name FROM shops WHERE code = ? AND is_active = 1', [$code])
            : null;
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
