<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Bản sao dữ liệu đọc từ POS, mỗi shop một thư mục storage/cache/<shop_id>/.
 *
 * Trang khách nào cũng cần menu, trang chủ cần lưới hàng — không hỏi POS mỗi lượt
 * xem. POS không trả lời thì dùng bản cũ (trang vẫn có hàng); chưa từng có bản nào
 * thì trả rỗng chứ không làm hỏng trang.
 */
final class BoNhoTep
{
    /** @param callable(): (array|null) $hoi null = POS không trả được → giữ bản cũ */
    public static function lay(int $shopId, string $ten, int $songGiay, callable $hoi): array
    {
        $tep = self::thuMuc($shopId) . "/$ten.json";
        $cu = is_file($tep) ? json_decode((string) file_get_contents($tep), true) : null;
        if (is_array($cu) && filemtime($tep) > time() - $songGiay) {
            return $cu;
        }

        $moi = $hoi();
        if ($moi === null) {
            // Chạm giờ tệp để lượt sau không hỏi lại POS ngay khi POS đang chết.
            if (is_array($cu)) {
                @touch($tep);
            }
            return is_array($cu) ? $cu : [];
        }
        if (!is_dir(dirname($tep))) {
            @mkdir(dirname($tep), 0775, true);
        }
        // Ghi tệp tạm rồi đổi tên: lượt đọc song song không bao giờ gặp tệp ghi dở.
        $tam = $tep . '.' . getmypid();
        if (@file_put_contents($tam, json_encode($moi, JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tam, $tep);
        }

        return $moi;
    }

    /** Kết nối / ngắt POS xong thì bỏ hết bản sao của shop, trang đổi theo ngay. */
    public static function xoa(int $shopId): void
    {
        foreach (glob(self::thuMuc($shopId) . '/*.json') ?: [] as $tep) {
            @unlink($tep);
        }
    }

    private static function thuMuc(int $shopId): string
    {
        return dirname(__DIR__, 2) . "/storage/cache/$shopId";
    }
}
