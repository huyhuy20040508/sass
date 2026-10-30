<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Hai kết nối, cùng một tài khoản MySQL (quyền ở database/quyen-db.sql):
 * - pdo(): DB bán hàng của POS. Web đọc thẳng hàng / giá / tồn / khách như kimguest; dữ liệu
 *   riêng của web nằm ở các bảng `web_` của chính DB này (được ghi riêng các bảng đó). Đơn đi
 *   qua API POS. POS dùng chung một DB cho mọi cửa hàng: câu nào cũng phải lọc theo tenant_id.
 * - nenTang(): DB nền tảng (như DB trung tâm của kimguest) — sổ tên miền, cửa hàng, gói / hạn
 *   dùng. CHỈ ĐỌC, và chỉ vài bảng được cấp.
 */
final class Db
{
    /** @var array<string, PDO> */
    private static array $ket = [];

    public static function pdo(): PDO
    {
        return self::ket('ban_hang', Env::get('DB_DATABASE'));
    }

    public static function nenTang(): PDO
    {
        return self::ket('nen_tang', Env::get('PLATFORM_DB_DATABASE'));
    }

    private static function ket(string $ten, string $db): PDO
    {
        if (!isset(self::$ket[$ten])) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306'), $db);
            self::$ket[$ten] = new PDO($dsn, Env::get('DB_USERNAME'), Env::get('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Tắt giả lập để LIMIT ? nhận số nguyên thật, không bị bọc nháy.
                PDO::ATTR_EMULATE_PREPARES => false,
                // POS ghi giờ Việt Nam: đọc ra đúng như ghi.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",
            ]);
        }

        return self::$ket[$ten];
    }

    /** @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }
}
