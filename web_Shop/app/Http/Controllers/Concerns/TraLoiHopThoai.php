<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Trả lời lượt Lưu của một hộp thoại.
 *
 * Vì sao cần: trước đây mọi hộp thoại đều lưu bằng form ẩn — trang tải lại, hộp
 * biến mất, rồi toast mới hiện. Lưu hỏng (trùng tên, thiếu ô, API từ chối) là
 * mất trắng mọi thứ vừa gõ, mà người dùng còn phải mở hộp lại và khai từ đầu để
 * biết mình sai chỗ nào.
 *
 * Cách chữa: hộp thoại gọi bằng AJAX kèm `Accept: application/json`. Controller
 * nhận ra và trả {success, message} thay vì chuyển hướng; hộp thoại đọc `success`
 * để quyết định ĐÓNG hay GIỮ LẠI. Lượt gọi thường (form gửi thẳng, nhập tệp) vẫn
 * đi đường chuyển hướng cũ — không màn nào phải sửa hai lần.
 */
trait TraLoiHopThoai
{
    /**
     * @param  bool  $xong  true = lưu được
     * @param  string  $cau  câu để bắn toast
     * @param  \Closure|null  $veDanhSach  đường quay về cho lượt gọi thường;
     *                                     bỏ trống thì dùng back()
     * @param  int|null  $ma  mã HTTP cho lượt HỎNG; bỏ trống = 422
     */
    protected function traLoiHopThoai(
        Request $request,
        bool $xong,
        string $cau,
        ?\Closure $veDanhSach = null,
        ?int $ma = null,
    ) {
        if ($request->expectsJson()) {
            // Mặc định 422: "dữ liệu bạn gõ chưa được", một lỗi người dùng sửa
            // được — khác hẳn lỗi máy chủ.
            //
            // Nhưng khi lượt hỏng là do API TỪ CHỐI thì mã của API mới là câu trả
            // lời đúng, và người gọi truyền nó vào đây. Quy hết về 422 thì sửa
            // hay xoá một id không tồn tại nhận 422 kèm câu "Không tìm thấy dữ
            // liệu" — mã nói một đằng, câu nói một nẻo, và mọi thứ đọc mã (nhật
            // ký, giám sát, người viết bài kiểm) đều bị dẫn sai.
            return response()->json(
                ['success' => $xong, 'message' => $cau],
                $xong ? 200 : ($ma ?: 422)
            );
        }

        if (! $xong) {
            return back()->withInput()->with('error', $cau);
        }

        return $veDanhSach ? $veDanhSach()->with('success', $cau) : back()->with('success', $cau);
    }

    /**
     * Câu lỗi đọc được từ phản hồi API.
     *
     * API trả 422 kèm `errors` theo TỪNG Ô (VD `name` -> "Tên nhóm hàng hoá này
     * đã có trong cửa hàng"). Gộp chúng lại thay vì lấy `message` chung chung:
     * "Dữ liệu không hợp lệ" thì người dùng không biết phải sửa ô nào, mà hộp
     * thoại thì chỉ bắn được một dòng toast.
     */
    protected function cauLoiApi(Response $res, string $macDinh): string
    {
        $loi = $res->json('errors');
        if (is_array($loi) && $loi !== []) {
            return implode(' ', array_map('strval', $loi));
        }

        return $res->json('message') ?: $macDinh;
    }
}
