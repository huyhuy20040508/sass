<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Mọi nút xổ trong khu v2 phải thật sự xổ được.
 *
 * Bootstrap 5 chỉ gắn hành vi cho phần tử có `data-bs-toggle="dropdown"`; class
 * `.dropdown-toggle` một mình chỉ vẽ cái mũi tên. Thiếu thuộc tính đó thì nút
 * vẫn hiện, vẫn đúng màu, trang vẫn 200 — bấm vào không ra gì. Không có lượt
 * kiểm nào của dự án bắt được kiểu hỏng ấy, và nút "Nâng cao" của màn Hàng hoá
 * đã nằm im như thế cho tới lúc có người bấm thử.
 */
class NutXoDuocTest extends TestCase
{
    public function test_moi_nut_xo_deu_co_cong_tac(): void
    {
        $loi = [];

        foreach ($this->tepBladeCuaV2() as $tep) {
            $noiDung = (string) file_get_contents($tep);

            preg_match_all('/<(?:button|a)\b[^>]*\bdropdown-toggle\b[^>]*>/i', $noiDung, $khop, PREG_OFFSET_CAPTURE);

            foreach ($khop[0] as [$the, $viTri]) {
                if (str_contains($the, 'data-bs-toggle')) {
                    continue;
                }

                $dong = substr_count(substr($noiDung, 0, $viTri), "\n") + 1;
                $loi[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $tep).':'.$dong;
            }
        }

        $this->assertSame([], $loi,
            "Nút mang .dropdown-toggle nhưng thiếu data-bs-toggle=\"dropdown\" — bấm vào không xổ ra gì:\n"
            .implode("\n", $loi));
    }

    /** @return list<string> */
    protected function tepBladeCuaV2(): array
    {
        $ra = [];
        $duyet = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('v2'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($duyet as $tep) {
            if ($tep->isFile() && str_ends_with($tep->getFilename(), '.blade.php')) {
                $ra[] = $tep->getPathname();
            }
        }

        return $ra;
    }
}
