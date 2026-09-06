<?php

namespace App\Http\Controllers;

use App\Support\XlsxDon;
use Illuminate\Http\Client\Response;

abstract class Controller
{
    /**
     * Đọc HẾT các trang của một đường danh sách API — cho lượt xuất tệp.
     *
     * API kẹp page_size ở 100 (vài đường 1000), và gửi số LỚN HƠN thì nó không
     * kẹp xuống mà rơi về mặc định 20: ba lượt xuất từng gửi 1000 và lặng lẽ chỉ
     * ra 20 dòng, không ai hay. Ở đây đi từng trang 100 tới hết `meta.total_pages`,
     * trần $tran dòng để một lượt bấm không quét cả sổ.
     *
     * $goi nhận mảng query (đã kèm page, page_size) và trả Response của API.
     *
     * @throws \RuntimeException kèm câu của API khi một trang trả lỗi
     */
    protected function docHetTrang(callable $goi, array $query, int $tran = 2000): array
    {
        $list = [];
        $query['page_size'] = 100;

        for ($trang = 1; count($list) < $tran; $trang++) {
            $query['page'] = $trang;
            /** @var Response $res */
            $res = $goi($query);
            if (! $res->successful()) {
                throw new \RuntimeException($res->json('message') ?: 'Không đọc được dữ liệu để xuất tệp.');
            }
            $list = array_merge($list, $res->json('data') ?? []);
            if ($trang >= (int) ($res->json('meta.total_pages') ?? 1)) {
                break;
            }
        }

        return array_slice($list, 0, $tran);
    }

    /**
     * Câu lỗi API nói ra, để bắn toast.
     *
     * Lỗi THEO Ô đứng trước câu chung: bộ khung 422 của API là
     * {"message": "Dữ liệu không hợp lệ", "errors": {"items": "Số lượng điều chỉnh
     * của X phải là số nguyên"}} — lấy `message` trước là ném đi đúng phần nói
     * cho người dùng biết phải sửa gì, họ chỉ còn thấy một câu chung chung.
     */
    protected function loi($res, string $macDinh): string
    {
        $o = $res->json('errors');
        if (is_array($o)) {
            $dau = reset($o);
            if (is_string($dau) && $dau !== '') {
                return $dau;
            }
        }
        if ($cau = $res->json('message')) {
            return $cau;
        }

        return $macDinh;
    }

    /**
     * Tệp .xlsx THẬT cho nút "Xuất Excel" — hàng đầu của $hang là tiêu đề.
     *
     * Không trả CSV đội tên: Excel mở CSV thì hỏi lại dấu phân cách, số điện
     * thoại "0912…" mất số 0 đầu, cột nào cũng là chữ nên không cộng được.
     */
    protected function taiXlsx(array $hang, string $ten, string $tenSheet)
    {
        return response(XlsxDon::noiDung($hang, $tenSheet), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$ten.'.xlsx"',
        ]);
    }
}
