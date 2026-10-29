<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Secret;

/**
 * Cầu nối POS của một shop: khoá tích hợp POS cấp cho web (xem migration 0099 bên POS).
 *
 * Kết nối / ngắt dùng phiên POS của chủ tiệm đang bấm; mọi lượt đọc hàng về sau dùng
 * khoá — không cần ai đăng nhập.
 */
final class PosKetNoi
{
    private const DUONG_KHOA = '/admin/tich-hop/web-ban-hang';

    /** Khoá đang lưu của shop; null = chưa kết nối hoặc không giải mã được. */
    public static function khoa(int $shopId): ?string
    {
        $row = Db::one('SELECT key_sealed FROM pos_integrations WHERE shop_id = ?', [$shopId]);

        return $row === null ? null : Secret::open((string) $row['key_sealed']);
    }

    /**
     * Trạng thái kèm một lượt gọi THẬT sang POS bằng khoá — "đã lưu khoá" chưa chắc
     * là "đang kết nối được" (POS có thể đã thu hồi, cửa hàng có thể hết hạn).
     *
     * @return array<string, mixed>
     */
    public static function trangThai(int $shopId, string $ip): array
    {
        $row = Db::one('SELECT key_sealed, key_prefix, connected_by, connected_at FROM pos_integrations WHERE shop_id = ?', [$shopId]);
        if ($row === null) {
            return ['ket_noi' => false];
        }
        $kq = [
            'ket_noi' => true,
            'key_prefix' => $row['key_prefix'],
            'ket_noi_boi' => $row['connected_by'],
            'ket_noi_luc' => $row['connected_at'],
        ];
        $khoa = Secret::open((string) $row['key_sealed']);
        if ($khoa === null) {
            return $kq + ['tinh_trang' => 'loi', 'thong_bao' => 'Khoá lưu ở web không đọc được nữa — bấm Kết nối lại'];
        }

        try {
            $nhom = PosClient::send('GET', '/categories', null, $ip, ['key' => $khoa]);
            $hang = $nhom['status'] === 200 ? PosClient::send('GET', '/products?page_size=1', null, $ip, ['key' => $khoa]) : $nhom;
        } catch (HttpException $e) {
            return $kq + ['tinh_trang' => 'pos_khong_tra_loi', 'thong_bao' => $e->getMessage()];
        }

        return $kq + match (true) {
            $hang['status'] === 200 => [
                'tinh_trang' => 'tot',
                'so_nhom' => count($nhom['body']['data'] ?? []),
                'so_hang' => (int) ($hang['body']['meta']['total'] ?? 0),
            ],
            $hang['status'] === 401 => ['tinh_trang' => 'khoa_bi_thu_hoi', 'thong_bao' => 'POS đã thu hồi khoá của web — bấm Kết nối lại'],
            $hang['status'] === 403 => ['tinh_trang' => 'cua_hang_khoa', 'thong_bao' => (string) ($hang['body']['message'] ?? 'Cửa hàng đang tạm khoá bên POS')],
            default => ['tinh_trang' => 'loi', 'thong_bao' => (string) ($hang['body']['message'] ?? 'POS trả lỗi ' . $hang['status'])],
        };
    }

    /** POS cấp khoá mới (khoá cũ của web bị POS thu hồi luôn) rồi cất mã hoá. */
    public static function ketNoi(int $shopId, string $nguoiBam, string $posAccess, string $ip): void
    {
        $res = PosClient::send('POST', self::DUONG_KHOA, null, $ip, ['bearer' => $posAccess]);
        $khoa = (string) ($res['body']['data']['khoa'] ?? '');
        if ($res['status'] === 403) {
            throw new HttpException(403, 'Tài khoản của bạn không có quyền Cấu hình cửa hàng bên POS nên không kết nối được');
        }
        if ($res['status'] !== 201 || $khoa === '') {
            throw new HttpException(502, (string) ($res['body']['message'] ?? 'POS không cấp được khoá, vui lòng thử lại'));
        }

        Db::exec(
            'INSERT INTO pos_integrations (shop_id, key_sealed, key_prefix, connected_by, connected_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE key_sealed = VALUES(key_sealed), key_prefix = VALUES(key_prefix),
                                     connected_by = VALUES(connected_by), connected_at = VALUES(connected_at)',
            [$shopId, Secret::seal($khoa), (string) ($res['body']['data']['key_prefix'] ?? substr($khoa, 0, 10)), $nguoiBam],
        );
        BoNhoTep::xoa($shopId);
    }

    /** Thu hồi khoá ở POS rồi mới xoá bản ở web — làm ngược lại thì POS còn một khoá sống không ai giữ. */
    public static function ngat(int $shopId, string $posAccess, string $ip): void
    {
        $res = PosClient::send('DELETE', self::DUONG_KHOA, null, $ip, ['bearer' => $posAccess]);
        if ($res['status'] === 403) {
            throw new HttpException(403, 'Tài khoản của bạn không có quyền Cấu hình cửa hàng bên POS nên không ngắt được');
        }
        if ($res['status'] !== 200) {
            throw new HttpException(502, (string) ($res['body']['message'] ?? 'POS không thu hồi được khoá, vui lòng thử lại'));
        }
        Db::exec('DELETE FROM pos_integrations WHERE shop_id = ?', [$shopId]);
        BoNhoTep::xoa($shopId);
    }

    /**
     * Một trang hàng đọc từ POS bằng khoá, rút gọn cho màn quản trị.
     *
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function hang(int $shopId, int $page, string $keyword, string $ip): array
    {
        $khoa = self::khoa($shopId) ?? throw new HttpException(409, 'Shop chưa kết nối POS');
        $q = http_build_query(array_filter(['page' => $page, 'page_size' => 20, 'keyword' => $keyword], fn ($v) => $v !== ''));
        $res = PosClient::send('GET', '/products?' . $q, null, $ip, ['key' => $khoa]);
        if ($res['status'] !== 200) {
            throw new HttpException($res['status'] === 401 ? 409 : 502, $res['status'] === 401
                ? 'POS đã thu hồi khoá của web — bấm Kết nối lại'
                : (string) ($res['body']['message'] ?? 'POS trả lỗi ' . $res['status']));
        }

        $items = [];
        foreach ($res['body']['data'] ?? [] as $p) {
            $bienThe = $p['variants'] ?? [];
            $gia = array_values(array_filter(array_map(fn ($v) => (float) ($v['price'] ?? 0), $bienThe)));
            $items[] = [
                'id' => (int) $p['id'],
                'ten' => (string) ($p['name'] ?? ''),
                'sku' => (string) ($p['sku'] ?? ''),
                'anh' => (string) ($p['thumbnail'] ?? ''),
                'nhom' => (string) ($p['category']['name'] ?? ''),
                'gia_thap' => $gia ? min($gia) : (float) ($p['sale_price'] ?? $p['base_price'] ?? 0),
                'gia_cao' => $gia ? max($gia) : (float) ($p['sale_price'] ?? $p['base_price'] ?? 0),
                'so_bien_the' => count($bienThe),
                'ton' => array_sum(array_map(fn ($v) => (int) ($v['stock_quantity'] ?? 0), $bienThe)),
                'dang_ban' => ($p['status'] ?? '') === 'active',
            ];
        }

        return ['items' => $items, 'meta' => $res['body']['meta'] ?? []];
    }
}
