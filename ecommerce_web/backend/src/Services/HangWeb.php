<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/** Hàng đọc từ POS cho trang khách (lưới hàng trang chủ…), giữ bản sao ngắn qua BoNhoTep. */
final class HangWeb
{
    // Giá / tồn đổi ở POS thì web chậm nhất chừng này mới theo.
    private const SONG_GIAY = 60;

    /**
     * @param 'moi'|'ban_chay'|'giam'|'nhom' $nguon
     * @return list<array<string, mixed>>
     */
    public static function luoi(int $shopId, string $nguon, int $nhomId, int $so, string $ip): array
    {
        $q = ['page_size' => $so, 'sort' => $nguon === 'ban_chay' ? 'best_selling' : 'newest'];
        if ($nguon === 'nhom') {
            $q['category_id'] = $nhomId;
        }
        if ($nguon === 'giam') {
            $q['on_sale'] = 'true';
        }

        return BoNhoTep::lay($shopId, 'hang-' . md5(http_build_query($q)), self::SONG_GIAY, fn () => self::hoiPos($shopId, $q, $ip));
    }

    /** @return list<array<string, mixed>>|null */
    private static function hoiPos(int $shopId, array $q, string $ip): ?array
    {
        $khoa = PosKetNoi::khoa($shopId);
        if ($khoa === null) {
            return [];
        }
        try {
            $res = PosClient::send('GET', '/products?' . http_build_query($q), null, $ip, ['key' => $khoa]);
        } catch (HttpException) {
            return null;
        }
        if ($res['status'] !== 200) {
            return in_array($res['status'], [401, 403], true) ? [] : null;
        }

        $kq = [];
        foreach ($res['body']['data'] ?? [] as $p) {
            if (($p['status'] ?? '') !== 'active') {
                continue;
            }
            $bienThe = $p['variants'] ?? [];
            $gia = array_values(array_filter(array_map(fn ($v) => (float) ($v['price'] ?? 0), $bienThe)));
            $giaGoc = (float) ($p['sale_price'] ?? $p['base_price'] ?? 0);
            // Giá gạch ngang: chỉ hàng một giá có giá khuyến mãi thấp hơn giá gốc ở POS.
            $giaCu = !$gia && isset($p['sale_price']) && (float) $p['base_price'] > (float) $p['sale_price'] ? (float) $p['base_price'] : 0.0;
            $kq[] = [
                'id' => (int) $p['id'],
                'ten' => (string) ($p['name'] ?? ''),
                'slug' => (string) ($p['slug'] ?? ''),
                'anh' => (string) ($p['thumbnail'] ?? ''),
                'gia' => $gia ? min($gia) : $giaGoc,
                'gia_cu' => $giaCu,
                'nhieu_gia' => $gia && min($gia) !== max($gia),
                'het_hang' => $bienThe && array_sum(array_map(fn ($v) => (int) ($v['stock_quantity'] ?? 0), $bienThe)) <= 0,
            ];
        }

        return $kq;
    }
}
