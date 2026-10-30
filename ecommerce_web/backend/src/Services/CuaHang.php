<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;

/**
 * Tên miền đang mở là web của cửa hàng nào, chi nhánh nào — và web đó còn được mở không.
 *
 * Mỗi chi nhánh một web: sổ tên miền của nền tảng (tenant_domains, app 'web') trỏ host tới
 * (tenant_id, shop_id). Không đệm: sổ nhỏ, tra theo khoá unique, và đệm thì web vừa hết hạn vẫn
 * còn bán thêm một lúc.
 *
 * Lỗi ném HttpException kèm errors.ly_do để trang khách hiện đúng màn: khong_co | het_han |
 * tam_ngung | chi_nhanh_dong.
 */
final class CuaHang
{
    private const MA_APP = 'web';

    /** @var array<string, array<string, mixed>> */
    private static array $daTra = [];

    /** @return array{tenant_id: int, shop_id: int, cua_hang: array<string, mixed>, chi_nhanh: array<string, mixed>} */
    public static function theoHost(string $host): array
    {
        return self::$daTra[$host] ??= self::tra($host);
    }

    private static function tra(string $host): array
    {
        $ten = self::tenMien($host);
        if ($ten === null || $ten['shop_id'] === null) {
            if ($ten !== null) {
                error_log("web: tên miền $host chưa gắn chi nhánh (tenant_domains.shop_id NULL)");
            }
            throw self::loi(404, 'Địa chỉ này chưa có web bán hàng nào', 'khong_co');
        }
        $tenantId = (int) $ten['tenant_id'];
        $shopId = (int) $ten['shop_id'];

        // Chốt chặn thật của POS là tenants.status bên DB bán hàng (bản ở DB nền tảng chỉ là bản
        // sao cho khu điều hành) — POS khoá cửa hàng thì web đóng theo.
        $cuaHang = Db::one('SELECT id, code, name, status FROM tenants WHERE id = ?', [$tenantId]);
        if ($cuaHang === null) {
            throw self::loi(404, 'Địa chỉ này chưa có web bán hàng nào', 'khong_co');
        }
        if ($cuaHang['status'] !== 'active') {
            throw self::loi(403, 'Cửa hàng đang tạm ngừng hoạt động', 'tam_ngung');
        }

        if (!self::conHan($tenantId)) {
            throw self::loi(403, 'Web bán hàng của cửa hàng đã hết hạn sử dụng', 'het_han');
        }

        // Lọc cả tenant_id: shop_id ở sổ nền tảng không có khoá ngoại sang đây, gõ nhầm số chi
        // nhánh của khách khác thì phải là "không có", không phải mở hàng của họ ra.
        $chiNhanh = Db::one(
            'SELECT id, code, name, phone, email, address, image FROM shops
              WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL',
            [$shopId, $tenantId],
        );
        if ($chiNhanh === null) {
            throw self::loi(404, 'Chi nhánh này đã ngừng bán trên web', 'chi_nhanh_dong');
        }

        return [
            'tenant_id' => $tenantId,
            'shop_id' => $shopId,
            'cua_hang' => ['ma' => $cuaHang['code'], 'ten' => $cuaHang['name']],
            'chi_nhanh' => [
                'ma' => $chiNhanh['code'],
                'ten' => $chiNhanh['name'],
                'dien_thoai' => $chiNhanh['phone'],
                'email' => $chiNhanh['email'],
                'dia_chi' => $chiNhanh['address'],
                'anh' => $chiNhanh['image'],
            ],
        ];
    }

    /** @return array{tenant_id: int|string, shop_id: int|string|null}|null */
    private static function tenMien(string $host): ?array
    {
        $tim = static function (string $h): ?array {
            $st = Db::nenTang()->prepare(
                'SELECT d.tenant_id, d.shop_id FROM tenant_domains d
                   JOIN apps a ON a.id = d.app_id AND a.code = ?
                  WHERE d.host = ?',
            );
            $st->execute([self::MA_APP, $h]);
            $row = $st->fetch();

            return $row === false ? null : $row;
        };

        // host là khoá unique toàn bảng: "www.x" không có trong sổ thì không thuộc ai khác, trả về
        // chủ của "x" không lấn sang khách khác (cùng cách POS làm).
        return $tim($host) ?? (str_starts_with($host, 'www.') ? $tim(substr($host, 4)) : null);
    }

    /**
     * Thuê bao web còn chạy. Kiểm cả ends_at chứ không chỉ status: lượt quét chuyển past_due của
     * POS chạy 5 phút một lần, khoảng đó web không được bán quá hạn.
     */
    private static function conHan(int $tenantId): bool
    {
        $st = Db::nenTang()->prepare(
            "SELECT 1 FROM subscriptions s
               JOIN apps a ON a.id = s.app_id AND a.code = ?
              WHERE s.tenant_id = ? AND s.status IN ('trial', 'active') AND s.ends_at > NOW(3)
              LIMIT 1",
        );
        $st->execute([self::MA_APP, $tenantId]);

        return $st->fetchColumn() !== false;
    }

    private static function loi(int $status, string $thongBao, string $lyDo): HttpException
    {
        return new HttpException($status, $thongBao, ['ly_do' => $lyDo]);
    }
}
