<?php

namespace App\Support;

/**
 * Mã vạch Code 128 (bộ B) dạng SVG, cho dòng "mã vạch mã đơn" trên phiếu in.
 *
 * Vẽ ngay ở máy chủ thay vì nạp thư viện JS: phiếu gọi lệnh in khi trang tải
 * xong, thư viện tải chậm hay hỏng mạng là tờ phiếu ra thiếu mã vạch. Code 128
 * vì máy quét nào cũng đọc, và bộ B nhận đủ chữ hoa, chữ thường, số của mã đơn.
 */
class MaVach
{
    /** Độ rộng 4 vạch-khoảng của từng mã 0–106 (106 = STOP, thêm 2 vạch kết). */
    protected const MAU = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    protected const START_B = 104;

    /**
     * SVG co giãn theo bề ngang khung chứa. Chữ ngoài bộ B (dấu tiếng Việt…) bị
     * bỏ qua thay vì làm hỏng cả mã — mã đơn vốn chỉ có chữ số và chữ Latinh.
     */
    public static function svg(string $noiDung, int $caoModule = 40): string
    {
        $ma = [self::START_B];
        $tong = self::START_B;
        $viTri = 1;
        foreach (str_split($noiDung) as $kyTu) {
            $o = ord($kyTu);
            if ($o < 32 || $o > 126) {
                continue;
            }
            $ma[] = $o - 32;
            $tong += ($o - 32) * $viTri++;
        }
        if (count($ma) === 1) {
            return '';
        }
        $ma[] = $tong % 103;
        $ma[] = 106;

        $vach = '';
        $x = 10; // Vùng trắng 10 module mỗi bên: thiếu thì máy quét không bắt được đầu mã.
        foreach ($ma as $m) {
            foreach (str_split(self::MAU[$m]) as $i => $rong) {
                if ($i % 2 === 0) {
                    $vach .= 'M'.$x.' 0h'.$rong.'v'.$caoModule.'h-'.$rong.'z';
                }
                $x += (int) $rong;
            }
        }
        $rongTong = $x + 10;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$rongTong.' '.$caoModule.'" preserveAspectRatio="none" shape-rendering="crispEdges" aria-hidden="true">'
            .'<path d="'.$vach.'" fill="#000"/></svg>';
    }
}
