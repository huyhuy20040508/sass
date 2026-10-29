<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Cây nhóm hàng của shop cho menu web, đọc từ POS bằng khoá tích hợp (giữ bản sao
 * vài phút qua BoNhoTep).
 */
final class NhomHangWeb
{
    private const SONG_GIAY = 120;

    /** @return list<array{id: int, ten: string, slug: string, anh: string, con: list<array<string, mixed>>}> */
    public static function cay(int $shopId, string $ip): array
    {
        return BoNhoTep::lay($shopId, 'nhom-hang', self::SONG_GIAY, fn () => self::hoiPos($shopId, $ip));
    }

    /** @return list<array<string, mixed>>|null null = POS không trả được (lỗi, bảo trì) — giữ bản cũ; [] = chưa kết nối */
    private static function hoiPos(int $shopId, string $ip): ?array
    {
        $khoa = PosKetNoi::khoa($shopId);
        if ($khoa === null) {
            return [];
        }
        try {
            $res = PosClient::send('GET', '/categories', null, $ip, ['key' => $khoa]);
        } catch (HttpException) {
            return null;
        }
        if ($res['status'] !== 200) {
            // Khoá bị thu hồi / cửa hàng khoá: không hiện menu cũ của một shop đã ngừng.
            return in_array($res['status'], [401, 403], true) ? [] : null;
        }

        return self::dungCay($res['body']['data'] ?? []);
    }

    /**
     * POS trả danh sách phẳng có parent_id. Nhóm có cha không nằm trong danh sách
     * (cha đã tắt) thì đưa lên làm gốc để hàng trong đó vẫn tìm được từ menu.
     *
     * @param list<array<string, mixed>> $ds
     * @return list<array<string, mixed>>
     */
    private static function dungCay(array $ds): array
    {
        $nut = [];
        foreach ($ds as $c) {
            if (!($c['is_active'] ?? true)) {
                continue;
            }
            $nut[(int) $c['id']] = [
                'id' => (int) $c['id'],
                'ten' => (string) $c['name'],
                'slug' => (string) $c['slug'],
                'anh' => (string) ($c['image'] ?? ''),
                'cha' => isset($c['parent_id']) ? (int) $c['parent_id'] : 0,
                'thu_tu' => (int) ($c['sort_order'] ?? 0),
                'con' => [],
            ];
        }
        uasort($nut, fn ($a, $b) => [$a['thu_tu'], $a['ten']] <=> [$b['thu_tu'], $b['ten']]);

        $con = [];
        foreach ($nut as $id => $n) {
            $con[isset($nut[$n['cha']]) ? $n['cha'] : 0][] = $id;
        }
        $dung = function (int $cha, int $sau) use (&$dung, $con, $nut): array {
            if ($sau > 5) {
                return []; // dữ liệu vòng lặp cha-con thì cắt, không treo trang
            }
            $kq = [];
            foreach ($con[$cha] ?? [] as $id) {
                $kq[] = ['id' => $id, 'ten' => $nut[$id]['ten'], 'slug' => $nut[$id]['slug'], 'anh' => $nut[$id]['anh'], 'con' => $dung($id, $sau + 1)];
            }
            return $kq;
        };

        return $dung(0, 0);
    }
}
