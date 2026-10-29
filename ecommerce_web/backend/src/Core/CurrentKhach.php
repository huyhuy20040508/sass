<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Khách mua hàng đang đăng nhập trong lượt gọi này.
 *
 * Cùng khuôn với CurrentAdmin, nhưng phiên khách chỉ cần tra trong database của
 * web: tài khoản khách do web giữ, không phải tài khoản POS, nên không có bước
 * hỏi lại POS định kỳ.
 */
final class CurrentKhach
{
    /** @var array{id: int, session_id: int, pos_customer_id: int, full_name: string, phone: string, email: string}|null */
    private static ?array $khach = null;

    public static function resolve(Request $req): void
    {
        $token = $req->bearerToken();
        $row = $token === '' ? null : Db::one(
            'SELECT s.id AS session_id, k.id, k.pos_customer_id, k.full_name, k.phone, k.email
               FROM khach_sessions s
               JOIN khach_web k ON k.id = s.khach_id
              WHERE s.token_hash = ? AND s.shop_id = ? AND s.expires_at > NOW()',
            [hash('sha256', $token), CurrentShop::id()],
        );
        if ($row === null) {
            throw new HttpException(401, 'Phiên đăng nhập đã hết, vui lòng đăng nhập lại');
        }

        self::$khach = [
            'id' => (int) $row['id'],
            'session_id' => (int) $row['session_id'],
            'pos_customer_id' => (int) $row['pos_customer_id'],
            'full_name' => (string) $row['full_name'],
            'phone' => (string) $row['phone'],
            'email' => (string) $row['email'],
        ];
    }

    /** @return array{id: int, session_id: int, pos_customer_id: int, full_name: string, phone: string, email: string} */
    public static function get(): array
    {
        return self::$khach ?? throw new \LogicException('Route này chưa khai khach: true');
    }
}
