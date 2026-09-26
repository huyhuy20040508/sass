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

        /*
           Mốc theo LỊCH, không phải cửa sổ trượt: "tháng này" là từ ngày 1 tới
           hôm nay, khác hẳn "30 ngày qua". Chủ tiệm đối chiếu sổ sách theo tuần
           / tháng / quý / năm nên hai họ này phải sống cạnh nhau.

           `unit` là đơn vị lịch, `back` là lùi mấy kỳ (0 = kỳ đang chạy). Kỳ
           đang chạy cắt ở HÔM NAY chứ không chạy tới cuối kỳ: cộng thêm những
           ngày chưa tới chỉ làm số trung bình mỗi ngày thành vô nghĩa.
        */
        'this-week' => ['label' => 'Tuần này', 'phrase' => 'tuần này', 'unit' => 'week', 'back' => 0],
        'last-week' => ['label' => 'Tuần trước', 'phrase' => 'tuần trước', 'unit' => 'week', 'back' => 1],
        'this-month' => ['label' => 'Tháng này', 'phrase' => 'tháng này', 'unit' => 'month', 'back' => 0],
        'last-month' => ['label' => 'Tháng trước', 'phrase' => 'tháng trước', 'unit' => 'month', 'back' => 1],
        'this-quarter' => ['label' => 'Quý này', 'phrase' => 'quý này', 'unit' => 'quarter', 'back' => 0],
        'last-quarter' => ['label' => 'Quý trước', 'phrase' => 'quý trước', 'unit' => 'quarter', 'back' => 1],
        'this-year' => ['label' => 'Năm nay', 'phrase' => 'năm nay', 'unit' => 'year', 'back' => 0],
        'last-year' => ['label' => 'Năm trước', 'phrase' => 'năm trước', 'unit' => 'year', 'back' => 1],
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

        if (isset($preset['unit'])) {
            return self::theoLich($preset['unit'], (int) $preset['back']);
        }

        $to = Carbon::today()->subDays($preset['offset']);

        return [
            'from' => $to->copy()->subDays($preset['days'] - 1)->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];
    }

    /**
     * Kỳ theo mốc lịch: đầu tuần/tháng/quý/năm tới cuối kỳ đó.
     *
     * Kỳ ĐANG CHẠY dừng ở hôm nay. Kỳ đã qua lấy trọn vẹn.
     *
     * @return array{from: string, to: string}
     */
    protected static function theoLich(string $unit, int $back): array
    {
        $moc = Carbon::today();
        $moc = match ($unit) {
            'week' => $moc->subWeeks($back),
            'month' => $moc->subMonthsNoOverflow($back),
            'quarter' => $moc->subQuartersNoOverflow($back),
            default => $moc->subYears($back),
        };

        $dau = match ($unit) {
            'week' => $moc->copy()->startOfWeek(Carbon::MONDAY),
            'month' => $moc->copy()->startOfMonth(),
            'quarter' => $moc->copy()->startOfQuarter(),
            default => $moc->copy()->startOfYear(),
        };
        $cuoi = match ($unit) {
            'week' => $moc->copy()->endOfWeek(Carbon::SUNDAY),
            'month' => $moc->copy()->endOfMonth(),
            'quarter' => $moc->copy()->endOfQuarter(),
            default => $moc->copy()->endOfYear(),
        };

        $homNay = Carbon::today();

        return [
            'from' => $dau->format('Y-m-d'),
            'to' => $cuoi->greaterThan($homNay) ? $homNay->format('Y-m-d') : $cuoi->format('Y-m-d'),
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
