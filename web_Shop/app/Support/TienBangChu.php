<?php

namespace App\Support;

/**
 * Đọc số tiền thành chữ tiếng Việt cho dòng "Bằng chữ" của hoá đơn A5/A4:
 * 1.250.000 → "Một triệu hai trăm năm mươi nghìn đồng".
 *
 * Theo cách đọc quen của kế toán: "lẻ" khi hàng chục là 0 ("một trăm lẻ năm"),
 * "mốt" sau hàng chục từ 20 ("hai mươi mốt"), "lăm" thay "năm" ở hàng đơn vị
 * khi có hàng chục ("mười lăm"). Nhóm ba số giữa của số lớn đọc đủ "không trăm"
 * ("một triệu không trăm lẻ năm nghìn") để khỏi hiểu nhầm số.
 */
class TienBangChu
{
    protected const SO = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

    protected const HANG = ['', 'nghìn', 'triệu', 'tỷ'];

    public static function doc(float|int $tien): string
    {
        $n = (int) round(abs($tien));
        if ($n === 0) {
            return 'Không đồng';
        }

        $nhom = [];
        while ($n > 0) {
            $nhom[] = $n % 1000;
            $n = intdiv($n, 1000);
        }

        $chu = [];
        for ($i = count($nhom) - 1; $i >= 0; $i--) {
            if ($nhom[$i] === 0) {
                continue;
            }
            $chu[] = trim(self::baSo($nhom[$i], $i < count($nhom) - 1).' '.self::tenHang($i));
        }

        return ucfirst(trim(preg_replace('/\s+/', ' ', implode(' ', $chu)))).' đồng';
    }

    /** Hàng thứ i (mỗi hàng 3 chữ số): 0 rỗng, 1 nghìn, 2 triệu, 3 tỷ, 4 nghìn tỷ… */
    protected static function tenHang(int $i): string
    {
        if ($i === 0) {
            return '';
        }

        return trim(self::HANG[$i % 3 === 0 ? 3 : $i % 3].str_repeat(' tỷ', intdiv($i - 1, 3)));
    }

    protected static function baSo(int $n, bool $dayDu): string
    {
        $tram = intdiv($n, 100);
        $chuc = intdiv($n % 100, 10);
        $dv = $n % 10;
        $s = [];

        if ($tram > 0 || $dayDu) {
            $s[] = self::SO[$tram].' trăm';
        }
        if ($chuc > 1) {
            $s[] = self::SO[$chuc].' mươi';
            $s[] = match ($dv) {
                0 => '',
                1 => 'mốt',
                5 => 'lăm',
                default => self::SO[$dv],
            };
        } elseif ($chuc === 1) {
            $s[] = 'mười';
            $s[] = $dv === 5 ? 'lăm' : ($dv > 0 ? self::SO[$dv] : '');
        } elseif ($dv > 0) {
            if ($tram > 0 || $dayDu) {
                $s[] = 'lẻ';
            }
            $s[] = self::SO[$dv];
        }

        return implode(' ', array_filter($s));
    }
}
