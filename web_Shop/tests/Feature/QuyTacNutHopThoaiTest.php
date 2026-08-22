<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Quy tắc chung: cặp nút ở chân hộp thoại.
 *
 * Nút BỎ ĐI (Đóng / Huỷ) đỏ, nút ĐỒNG Ý (Xác nhận / Lưu) xanh — cho mọi màn.
 * Quy tắc nằm MỘT chỗ ở layout dùng chung; bài kiểm này canh hai việc: quy tắc
 * còn đó, và không màn nào lén khai màu riêng cho nút chân hộp thoại rồi lệch
 * dần khỏi phần còn lại.
 */
class QuyTacNutHopThoaiTest extends TestCase
{
    /** Quy tắc phải nằm ở layout dùng chung, không phải chép vào từng màn. */
    public function test_quy_tac_nam_o_layout_dung_chung(): void
    {
        $css = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('[class*="-modal-foot"] [class*="-btn-ghost"]', $css);
        $this->assertStringContainsString('[class*="-modal-foot"] [class*="-btn-primary"]', $css);
        $this->assertStringContainsString('#ff4d4f', $css);   // đỏ của nút bỏ
        $this->assertStringContainsString('#1890ff', $css);   // xanh của nút đồng ý
    }

    /**
     * Nút ở chân hộp thoại phải mang lớp mà quy tắc chung bắt được.
     *
     * Đặt tên khác đi (`xxx-btn-huy`, `xxx-close`…) là nút đó rơi ra ngoài quy
     * tắc và tự nhiên khác màu cả hệ thống — bắt ngay tại đây thay vì đợi ai đó
     * nhìn ra.
     */
    public function test_moi_nut_chan_hop_thoai_deu_theo_quy_tac(): void
    {
        $lac = [];

        foreach ($this->tepBlade() as $tep) {
            // Hộp thoại cảnh báo dùng chung (Bạn có chắc muốn xoá?) đứng NGOÀI quy
            // tắc: nút đồng ý của nó đổi màu theo mức nguy hiểm (đỏ khi xoá, vàng
            // khi cảnh báo). Tô nút Huỷ đỏ nữa là hai nút cùng đỏ, hết phân biệt.
            if (basename($tep) === 'modals.blade.php') {
                continue;
            }
            $noi = file_get_contents($tep);

            // Cắt từng khối chân hộp thoại rồi soi các nút bên trong.
            if (! preg_match_all('/class="[a-z0-9-]*-modal-foot[^"]*"(.{0,2000}?)<\/div>/su', $noi, $khoi)) {
                continue;
            }
            foreach ($khoi[1] as $than) {
                preg_match_all('/<button[^>]*class="([^"]*)"[^>]*>/', $than, $nut);
                foreach ($nut[1] as $lop) {
                    if (! preg_match('/-btn-(ghost|primary|danger|status)\b|btn-huy|btn-xacnhan/', $lop)) {
                        $lac[] = basename($tep).': '.$lop;
                    }
                }
            }
        }

        $this->assertSame([], $lac, "Nút ở chân hộp thoại không theo quy tắc chung:\n".implode("\n", $lac));
    }

    /** Không màn nào tự tô lại màu cho nút chân hộp thoại. */
    public function test_khong_man_nao_khai_mau_rieng(): void
    {
        $lac = [];

        foreach ($this->tepBlade() as $tep) {
            if (basename(dirname($tep)) === 'layouts' && basename($tep) === 'app.blade.php') {
                continue;
            }
            $noi = file_get_contents($tep);
            // Chỉ soi DÒNG KHAI CSS mà bản thân bộ chọn nhắm vào chân hộp thoại —
            // cỡ nút, bo góc riêng của từng màn thì cứ khai bình thường.
            if (preg_match('/^[ \t]*[.#][^{\n]*-(modal-foot|foot-btns)[^{\n]*-btn-[^{\n]*\{/mi', $noi)) {
                $lac[] = basename($tep);
            }
        }

        $this->assertSame([], $lac, 'Màn tự khai màu nút chân hộp thoại: '.implode(', ', $lac));
    }

    /** @return string[] */
    private function tepBlade(): array
    {
        $ra = [];
        $duyet = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($duyet as $tep) {
            if ($tep->isFile() && str_ends_with($tep->getFilename(), '.blade.php')) {
                $ra[] = $tep->getPathname();
            }
        }

        return $ra;
    }
}
