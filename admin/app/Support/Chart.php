<?php

namespace App\Support;

/**
 * Chart — định dạng số và hình học biểu đồ dùng chung cho nhóm trang Báo cáo.
 *
 * Bốn trang báo cáo vẽ cùng một bộ hình (đường, cột, vòng tròn) trên các con số
 * khác nhau. Gom phần tính toán vào đây để bốn view chỉ còn việc bày bố cục —
 * nếu để mỗi view tự tính thì bốn biểu đồ cùng loại sẽ dần lệch nhau về cách làm
 * tròn trục, cách rút gọn tiền và cách xử lý dữ liệu rỗng.
 *
 * Toàn bộ là hàm THUẦN: nhận số, trả số. Không đọc request, không gọi API.
 */
class Chart
{
    /** Tiền Việt: 1.250.000₫ */
    public static function money(float|int|null $value): string
    {
        return number_format((float) $value, 0, ',', '.').'₫';
    }

    /** Số nguyên có phân cách nghìn kiểu Việt: 1.250 */
    public static function int(float|int|null $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    /** Số thập phân: 1,25 */
    public static function dec(float|int|null $value, int $decimals = 1): string
    {
        return number_format((float) $value, $decimals, ',', '.');
    }

    /** Phần trăm: 12,5% */
    public static function pct(float|int|null $value, int $decimals = 1): string
    {
        return number_format((float) $value, $decimals, ',', '.').'%';
    }

    /**
     * Tiền bản rút gọn cho nhãn trục và thẻ hẹp: 1,2tr · 350k · 900.
     * Trục chỉ cần nói ĐỘ LỚN; con số đầy đủ luôn có ở bảng bên dưới.
     */
    public static function shortMoney(float|int|null $value): string
    {
        $value = (float) $value;
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);

        if ($value >= 1000000) {
            return $sign.rtrim(rtrim(number_format($value / 1000000, 1, ',', '.'), '0'), ',').'tr';
        }
        if ($value >= 1000) {
            return $sign.round($value / 1000).'k';
        }

        return $sign.round($value);
    }

    /**
     * Mức tăng/giảm so với kỳ trước, tính bằng %.
     *
     * Trả null khi kỳ trước bằng 0 mà kỳ này có số: tăng từ 0 lên bất cứ đâu đều
     * ra vô cực, in "+∞%" hay "+100%" đều là bịa — view phải nói thẳng "kỳ trước
     * chưa có" thay vì vẽ ra một tỷ lệ không có thật.
     */
    public static function delta(float|int|null $current, float|int|null $previous): ?float
    {
        $current = (float) $current;
        $previous = (float) $previous;

        if ($previous <= 0) {
            return $current > 0 ? null : 0.0;
        }

        return ($current - $previous) / $previous * 100;
    }

    /** Tỷ lệ phần trăm an toàn: mẫu số 0 thì trả 0 chứ không chia cho 0. */
    public static function share(float|int|null $part, float|int|null $total): float
    {
        $total = (float) $total;

        return $total > 0 ? (float) $part / $total * 100 : 0.0;
    }

    /**
     * Làm tròn TRẦN TRỤC lên một số đẹp (1 / 2 / 2,5 / 5 × 10^k).
     *
     * Không có bước này thì vạch lưới rơi vào những con số như 1.732.914₫ —
     * đúng nhưng không ai đọc được độ lớn từ đó.
     */
    public static function niceCeil(float $max): float
    {
        if ($max <= 0) {
            return 1.0;
        }

        $pow = 10 ** floor(log10($max));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($max <= $m * $pow) {
                return $m * $pow;
            }
        }

        return 10 * $pow;
    }

    /**
     * Dựng đường + vùng tô cho MỘT chuỗi giá trị trong khung vẽ đã cho.
     *
     * Trả về đủ thứ view cần để vẽ và để JS bắt sự kiện rê chuột: 'line' (path
     * nét), 'area' (path vùng tô), 'coords' (toạ độ từng điểm), 'top' (trần trục
     * Y đã làm tròn đẹp), 'avg'/'avgY' (đường trung bình).
     *
     * `top` phải được JS dùng lại y nguyên khi tính vị trí chấm hover: tính lại
     * từ max của chuỗi sẽ ra một trần khác và chấm lệch khỏi đường.
     */
    public static function line(array $values, array $box, ?float $forceTop = null): array
    {
        $padL = $box['padL'];
        $plotW = $box['w'] - $box['padL'] - $box['padR'];
        $plotH = $box['h'] - $box['padT'] - $box['padB'];
        $baseY = $box['padT'] + $plotH;

        $n = count($values);
        $top = $forceTop ?: self::niceCeil($n ? (float) max($values) : 0);

        $coords = [];
        foreach (array_values($values) as $i => $v) {
            $x = $n > 1 ? $padL + $plotW * $i / ($n - 1) : $padL + $plotW / 2;
            $coords[] = [round($x, 2), round($baseY - ((float) $v / $top) * $plotH, 2)];
        }

        $line = '';
        foreach ($coords as $i => $c) {
            $line .= ($i ? ' L' : 'M').$c[0].','.$c[1];
        }

        $area = '';
        if ($coords) {
            $area = 'M'.$coords[0][0].','.$baseY
                .' L'.implode(' L', array_map(fn ($c) => $c[0].','.$c[1], $coords))
                .' L'.end($coords)[0].','.$baseY.' Z';
        }

        $avg = $n ? array_sum($values) / $n : 0;

        return [
            'top' => $top,
            'coords' => $coords,
            'line' => $line,
            'area' => $area,
            'avg' => $avg,
            'avgY' => round($baseY - ($avg / $top) * $plotH, 2),
            'baseY' => $baseY,
            'plotW' => $plotW,
            'plotH' => $plotH,
        ];
    }

    /**
     * Trần trục cho chuỗi ĐẾM BẰNG CÁI (số đơn, số khách): ép chia hết cho 4 để
     * 5 vạch lưới đều ra số nguyên (24/18/12/6/0) thay vì 25/18,75/12,5.
     */
    public static function countTop(array $values): float
    {
        $max = $values ? (float) max($values) : 0;

        return (float) max(4, (int) (ceil($max / 4) * 4));
    }

    /** Đường nhỏ trong thẻ chỉ số. Dưới 2 điểm thì không vẽ gì. */
    public static function spark(array $values, int $w = 132, int $h = 34): array
    {
        $values = array_values($values);
        $n = count($values);
        if ($n < 2) {
            return ['w' => $w, 'h' => $h, 'line' => '', 'area' => ''];
        }

        $max = (float) max($values);
        $min = (float) min($values);
        $span = ($max - $min) ?: 1;

        $coords = [];
        foreach ($values as $i => $v) {
            $coords[] = [
                round($w * $i / ($n - 1), 2),
                round($h - 3 - (((float) $v - $min) / $span) * ($h - 6), 2),
            ];
        }

        $line = '';
        foreach ($coords as $i => $c) {
            $line .= ($i ? ' L' : 'M').$c[0].','.$c[1];
        }

        return [
            'w' => $w, 'h' => $h, 'line' => $line,
            'area' => 'M0,'.$h.' L'.implode(' L', array_map(fn ($c) => $c[0].','.$c[1], $coords)).' L'.$w.','.$h.' Z',
        ];
    }

    /**
     * Tính dash/gap/offset của từng lát vòng tròn.
     *
     * $slices là mảng ['share' => %], trả về chính mảng đó kèm ba khoá vẽ SVG.
     * Chừa 2px hở giữa hai lát để lát nhỏ vẫn tách được khỏi lát bên cạnh.
     */
    public static function donut(array $slices, float $radius = 52): array
    {
        $circumference = 2 * M_PI * $radius;
        $offset = 0.0;

        foreach ($slices as $i => $slice) {
            $length = $circumference * ((float) ($slice['share'] ?? 0)) / 100;
            $dash = max(0, $length - 2);
            $slices[$i]['dash'] = round($dash, 2);
            $slices[$i]['gap'] = round($circumference - $dash, 2);
            $slices[$i]['offset'] = round(-$offset, 2);
            $offset += $length;
        }

        return $slices;
    }

    /**
     * Chọn những mốc được in nhãn trên trục X: tối đa $max nhãn cho $n mốc.
     *
     * Đếm NGƯỢC từ mốc cuối về đầu. Cách này bảo đảm hai điều mà cách chia xuôi
     * rồi in thêm mốc cuối không làm được:
     *   1. Mốc cuối luôn có nhãn — đó là mốc người ta nhìn đầu tiên.
     *   2. Khoảng cách giữa mọi nhãn đều bằng nhau, nên không bao giờ có hai nhãn
     *      dính nhau. Chia xuôi rồi thêm mốc cuối thì khi mốc cuối rơi sát mốc
     *      chia đều liền trước, hai chữ đè lên nhau thành một vệt không đọc được
     *      (ví dụ 30 mốc chia 8: nhãn ở mốc 28 và mốc 29 nằm chồng lên nhau).
     *
     * Đổi lại, nhãn đầu tiên có thể không nằm đúng mốc 0 mà lệch vào trong vài
     * mốc — không sao, mép trái vẫn đọc được ngày.
     */
    public static function labelIndices(int $n, int $max = 8): array
    {
        if ($n <= 0) {
            return [];
        }

        $step = max(1, (int) ceil($n / max(1, $max)));
        $out = [];
        for ($i = $n - 1; $i >= 0; $i -= $step) {
            $out[] = $i;
        }

        return array_reverse($out);
    }
}
