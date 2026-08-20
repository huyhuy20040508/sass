<?php

namespace App\Support;

use App\Services\ApiClient;
use Illuminate\Support\Facades\Log;

/**
 * Bộ mức thuế GTGT đang bật ở màn Hàng hóa → Thuế.
 *
 * Hai màn dùng chung: Nhóm hàng hóa (mỗi nhóm mang một mức mặc định) và Hàng hóa
 * (chọn nhóm thì ô thuế tự điền theo nhóm — quy tắc của bản cũ v2).
 *
 * Đọc từ API chứ không khai cứng trong giao diện: luật thuế có đổi (8% là mức
 * giảm có hạn), khai trong giao diện thì mỗi lần đổi là sửa hai chỗ rồi phải
 * phát hành lại trang quản trị. Bản v2 để bộ mức trong JavaScript đúng kiểu đó.
 */
class MucThue
{
    /**
     * Hai mức KHÔNG phải phần trăm — đây là mã của hoá đơn điện tử.
     *
     * Giữ số âm chứ không quy về 0: quy về 0 là mất phân biệt "chịu thuế 0%" với
     * "không thuộc diện chịu thuế". Đồng bộ domain.MucKhongChiuThue bên Go API.
     */
    public const KHONG_CHIU_THUE = -1;

    public const KHONG_KE_KHAI = -2;

    /** Nhãn cho hai mã trên; các mức còn lại hiện thẳng "8%", "10%"… */
    public const NHAN = [
        self::KHONG_CHIU_THUE => 'KCT (không chịu thuế)',
        self::KHONG_KE_KHAI => 'KKKNT (không kê khai, không nộp thuế)',
    ];

    /** Bộ mức dùng khi API thuế không đọc được — vẫn khai hàng được. */
    public const MAC_DINH = [0, 8, 10];

    /** Bộ mức của loại "Thuế mặc định"; API lỗi thì lùi về MAC_DINH. */
    public static function boMuc(ApiClient $api): array
    {
        try {
            $res = $api->taxes();
            if ($res->successful()) {
                foreach ($res->json('data') ?? [] as $t) {
                    if (($t['loai'] ?? '') === 'mac-dinh' && ! empty($t['muc'])) {
                        return array_map('intval', $t['muc']);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Load tax rates failed', ['msg' => $e->getMessage()]);
        }

        return self::MAC_DINH;
    }

    /** Nhãn đọc được của một mức: "10%", "KCT", "KKKNT". */
    public static function chu($muc): string
    {
        $muc = (int) $muc;
        if ($muc === self::KHONG_CHIU_THUE) {
            return 'KCT';
        }
        if ($muc === self::KHONG_KE_KHAI) {
            return 'KKKNT';
        }

        return $muc.'%';
    }
}
