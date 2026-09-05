<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Quy tắc chung: TIÊU ĐỀ TRANG của khu v2.
 *
 * Cỡ chữ phải đúng bằng bản v2 gốc — `div.content_midd_title h4` bên
 * public/v2/css/custom.css khai `font-size: 18px; margin: 0`, và 16px ở khổ
 * ≤991px. Mọi màn dùng CHUNG một lớp để không màn nào lệch dần đi.
 *
 * Vì sao cần canh: trước bài này ba màn dùng ba cỡ khác nhau — .tieu-de-trang
 * để 1.5rem (24px, cỡ h4 mặc định của Bootstrap chứ không phải của v2), còn
 * hai màn kho để .h5 (20px). Không cái nào đúng 18px của bản gốc, và cái lệch
 * ấy chỉ lộ ra khi đặt hai trang cạnh nhau.
 */
class TieuDeTrangV2Test extends TestCase
{
    /** Thư mục view của khu v2. */
    protected function thuMucV2(): string
    {
        return base_path('v2');
    }

    /** Mọi tệp index.blade.php của khu v2. */
    protected function trangV2(): array
    {
        return glob($this->thuMucV2().'/*/index.blade.php') ?: [];
    }

    /** Cỡ chữ khai ở layout phải khớp con số của v2, không phải bậc heading của Bootstrap. */
    public function test_co_chu_dung_bang_ban_v2(): void
    {
        $css = file_get_contents($this->thuMucV2().'/layouts/master.blade.php');

        $this->assertMatchesRegularExpression(
            '/\.tieu-de-trang\s*\{[^}]*font-size:\s*18px/',
            $css,
            'tiêu đề trang phải 18px — đúng con số div.content_midd_title h4 của v2'
        );
        $this->assertMatchesRegularExpression(
            '/max-width:\s*991px\)\s*\{\s*\.tieu-de-trang\s*\{\s*font-size:\s*16px/',
            $css,
            'khổ ≤991px phải thu về 16px như v2 khai'
        );
    }

    /**
     * MỌI màn v2 dùng chung một lớp tiêu đề.
     *
     * Không chấp nhận `.h5`, `.h4` hay font-size khai tay ngay trên thẻ: mỗi
     * chỗ khai riêng là một chỗ để lệch, và lệch cỡ chữ tiêu đề thì không có
     * bài kiểm nào khác bắt được — trang vẫn 200, vẫn đủ chữ.
     */
    public function test_moi_man_dung_chung_mot_lop_tieu_de(): void
    {
        $trang = $this->trangV2();
        $this->assertNotEmpty($trang, 'không tìm thấy màn v2 nào để kiểm');

        $loi = [];
        foreach ($trang as $tep) {
            $html = file_get_contents($tep);

            // Chỉ soi thẻ h1 của TRANG. Mấy chuỗi h1 dựng trong JS để in phiếu
            // (`+ '<h1>'`) nằm trong một cửa sổ khác, không dính gì tới cỡ chữ
            // màn này — loại chúng bằng cách bỏ qua thẻ đứng ngay sau dấu nháy.
            if (! preg_match_all('/(?<![\'"])<h1\b[^>]*>/', $html, $m)) {
                $loi[] = basename(dirname($tep)).': không có thẻ h1 nào';

                continue;
            }

            foreach ($m[0] as $the) {
                if (! str_contains($the, 'tieu-de-trang')) {
                    $loi[] = basename(dirname($tep)).': '.$the;
                }
            }
        }

        $this->assertSame([], $loi,
            "Tiêu đề trang phải dùng class .tieu-de-trang:\n".implode("\n", $loi));
    }
}
