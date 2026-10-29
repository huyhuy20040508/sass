<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Đếm lượt xem trang khách theo ngày (bảng web_truy_cap, web_khach_ngay). */
final class TruyCap
{
    // Máy quét của Google, Zalo, Facebook… dựng trang để lấy ảnh chia sẻ, không phải khách.
    private const MAY_RE = '/bot|crawl|spider|slurp|facebookexternalhit|zalo|headless|preview|lighthouse|curl|wget|python/i';

    public static function dem(int $shopId, string $maKhach, string $userAgent): void
    {
        if ($userAgent === '' || preg_match(self::MAY_RE, $userAgent) || !preg_match('/^[a-f0-9]{16}$/', $maKhach)) {
            return;
        }
        $ngay = date('Y-m-d');
        $moi = Db::exec('INSERT IGNORE INTO web_khach_ngay (shop_id, ngay, ma) VALUES (?, ?, ?)', [$shopId, $ngay, $maKhach]);
        Db::exec(
            'INSERT INTO web_truy_cap (shop_id, ngay, luot, khach) VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE luot = luot + 1, khach = khach + VALUES(khach)',
            [$shopId, $ngay, $moi > 0 ? 1 : 0],
        );
        // Dọn dần: chỉ cần mã khách của hôm nay để biết ai đã đếm.
        if (random_int(1, 200) === 1) {
            Db::exec('DELETE FROM web_khach_ngay WHERE ngay < CURDATE() - INTERVAL 2 DAY');
        }
    }

    /**
     * Số theo từng ngày của $soNgay ngày gần nhất (ngày không có ai vào = 0) + tổng kỳ trước để so.
     *
     * @return array<string, mixed>
     */
    public static function tongQuan(int $shopId, int $soNgay): array
    {
        $tu = date('Y-m-d', strtotime('-' . ($soNgay - 1) . ' days'));
        $truoc = date('Y-m-d', strtotime('-' . (2 * $soNgay - 1) . ' days'));
        $theoNgay = [];
        foreach (Db::all('SELECT ngay, luot, khach FROM web_truy_cap WHERE shop_id = ? AND ngay >= ?', [$shopId, $tu]) as $r) {
            $theoNgay[$r['ngay']] = ['luot' => (int) $r['luot'], 'khach' => (int) $r['khach']];
        }
        $ds = [];
        for ($i = $soNgay - 1; $i >= 0; $i--) {
            $n = date('Y-m-d', strtotime("-$i days"));
            $ds[] = ['ngay' => $n] + ($theoNgay[$n] ?? ['luot' => 0, 'khach' => 0]);
        }
        $kyTruoc = Db::one(
            'SELECT COALESCE(SUM(luot), 0) AS luot, COALESCE(SUM(khach), 0) AS khach FROM web_truy_cap WHERE shop_id = ? AND ngay >= ? AND ngay < ?',
            [$shopId, $truoc, $tu],
        );

        return [
            'ngay' => $ds,
            'tong' => ['luot' => array_sum(array_column($ds, 'luot')), 'khach' => array_sum(array_column($ds, 'khach'))],
            'ky_truoc' => ['luot' => (int) $kyTruoc['luot'], 'khach' => (int) $kyTruoc['khach']],
            // Đơn đặt trên web có ở giai đoạn 4 (giỏ hàng, thanh toán) — tới lúc đó chưa có gì để đếm.
            'don' => null,
        ];
    }
}
