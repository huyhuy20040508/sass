<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Period — các khoảng thời gian xem nhanh, DÙNG CHUNG cho Tổng quan và Báo cáo.
 *
 * Khai một chỗ duy nhất vì "hôm qua" phải có nghĩa y hệt nhau ở mọi trang: nếu
 * mỗi controller tự tính lấy thì chỉ cần một bên quên mốc 00:00 là hai trang báo
 * hai con số khác nhau cho cùng một ngày, và không ai biết bên nào đúng.
 *
 * Mỗi preset gồm:
 *   - label  : chữ trên nút bấm (ngắn, nằm vừa một hàng nút)
 *   - phrase : cụm từ ghép vào câu ("Doanh thu " . phrase) — nên "30 ngày qua"
 *              chứ không phải "30 ngày", để câu không cụt hoặc thừa chữ
 *   - days   : độ dài kỳ, tính cả ngày cuối
 *   - offset : ngày cuối kỳ lùi bao nhiêu ngày so với hôm nay (0 = hôm nay)
 *
 * Kỳ luôn tính theo NGÀY TRỌN VẸN, mốc 00:00 tới 23:59:59 theo giờ máy chủ.
 * "Hôm qua" vì thế là một ngày đã đóng sổ: mở lúc 8h sáng hay 23h đêm đều ra
 * cùng một con số, không như "24 giờ qua" trượt theo từng phút.
 */
class Period
{
    public const PRESETS = [
        'today' => ['label' => 'Hôm nay', 'phrase' => 'hôm nay', 'days' => 1, 'offset' => 0],
        'yesterday' => ['label' => 'Hôm qua', 'phrase' => 'hôm qua', 'days' => 1, 'offset' => 1],
        '7' => ['label' => '7 ngày', 'phrase' => '7 ngày qua', 'days' => 7, 'offset' => 0],
        '30' => ['label' => '30 ngày', 'phrase' => '30 ngày qua', 'days' => 30, 'offset' => 0],
        '90' => ['label' => '90 ngày', 'phrase' => '90 ngày qua', 'days' => 90, 'offset' => 0],
        '365' => ['label' => '12 tháng', 'phrase' => '12 tháng qua', 'days' => 365, 'offset' => 0],
    ];

    /** Ngày hôm nay theo giờ máy chủ, dạng YYYY-MM-DD. */
    public static function today(): string
    {
        return Carbon::today()->format('Y-m-d');
    }

    /**
     * Danh sách nút bấm của một trang, theo đúng thứ tự truyền vào.
     *
     * Trả về MẢNG TUẦN TỰ với mã nằm trong khoá 'code', KHÔNG phải mảng lấy mã
     * làm khoá. Lý do: PHP tự ép khoá mảng dạng chuỗi-số về số nguyên, nên duyệt
     * một mảng khoá theo mã sẽ cho ra 'today' (chuỗi) nhưng 7 (số nguyên) — so
     * sánh chặt với mã đang chọn (luôn là chuỗi) sẽ trượt đúng ở các nút số, và
     * nút "7 ngày" không bao giờ sáng lên dù đang xem đúng 7 ngày.
     */
    public static function buttons(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            if (isset(self::PRESETS[$code])) {
                $out[] = ['code' => (string) $code] + self::PRESETS[$code];
            }
        }

        return $out;
    }

    /**
     * Quy một preset về khoảng ngày cụ thể: ['from' => 'Y-m-d', 'to' => 'Y-m-d'].
     * Mã lạ trả null để nơi gọi tự quyết định dùng mặc định nào.
     */
    public static function resolve(string $code): ?array
    {
        $preset = self::PRESETS[$code] ?? null;
        if ($preset === null) {
            return null;
        }

        $to = Carbon::today()->subDays($preset['offset']);

        return [
            'from' => $to->copy()->subDays($preset['days'] - 1)->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];
    }

    /**
     * Tìm preset ĐANG KHỚP với một khoảng ngày, hoặc null nếu là khoảng tự chọn.
     *
     * So bằng khoảng ngày thật chứ không nhìn tham số trên URL: người dùng bấm
     * nút "Hôm qua" hay tự chọn đúng ngày hôm qua trên lịch thì cũng phải thấy
     * nút đó sáng lên như nhau.
     *
     * $codes giới hạn trong những preset mà trang đó thực sự có nút.
     */
    public static function match(string $from, string $to, array $codes): ?string
    {
        foreach ($codes as $code) {
            $range = self::resolve($code);
            if ($range !== null && $range['from'] === $from && $range['to'] === $to) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Câu mô tả kỳ đang xem, dùng cho phụ đề trang.
     *
     * Kỳ đúng một ngày thì nói thẳng "ngày 02/08/2026" — viết "từ 02/08/2026 đến
     * 02/08/2026" là đúng nhưng đọc lên nghe như lỗi hiển thị. Trùng preset thì
     * gọi luôn tên quen thuộc của nó ("hôm qua") thay vì bắt người đọc tự đối
     * chiếu ngày tháng.
     */
    public static function describe(string $from, string $to, array $codes = []): string
    {
        $code = $codes ? self::match($from, $to, $codes) : null;
        $fromText = Carbon::parse($from)->format('d/m/Y');
        $toText = Carbon::parse($to)->format('d/m/Y');

        if ($from === $to) {
            return match ($code) {
                'today' => 'ngày hôm nay ('.$fromText.')',
                'yesterday' => 'ngày hôm qua ('.$fromText.')',
                default => 'ngày '.$fromText,
            };
        }

        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;

        return 'từ '.$fromText.' đến '.$toText.' ('.(int) $days.' ngày)';
    }
}
