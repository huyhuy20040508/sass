<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Client\Response;

/**
 * API từ chối vì THIẾU QUYỀN thì màn phải nói đúng chuyện đó.
 *
 * Vì sao cần: màn danh sách nào cũng bắt lỗi API theo một khuôn — gọi không
 * thành công thì để danh sách rỗng rồi vẽ tiếp. Nhưng 403 không phải "chưa có
 * dữ liệu": người dùng đọc câu "Chưa có chương trình nào" sẽ tưởng cửa hàng
 * chưa lập cái nào, bấm "Tạo mới" và ăn thêm một lỗi nữa. Đúng kiểu lỗi toast
 * in mã máy hồi 07/09 — sai ở chỗ nói cho người dùng biết chuyện gì đang xảy ra.
 *
 * Trait chỉ lo phần NHẬN BIẾT và ĐẶT CÂU; việc ẩn nút Tạo / Xuất là của view,
 * vì mỗi màn có bộ nút khác nhau.
 */
trait BaoThieuQuyen
{
    /**
     * Đọc một lượt gọi hỏng, trả về ['thieuQuyen' => bool, 'error' => ?string].
     *
     * `$viec` là cụm danh từ ghép vào câu, viết thường: "chương trình khuyến
     * mại", "phiếu thu chi". Câu dựng ở đây chứ không lấy `message` của API vì
     * API nói chung chung ("Bạn không được giao việc này") — màn hình biết rõ
     * mình đang xem gì nên nói được cụ thể hơn.
     *
     * @return array{thieuQuyen: bool, error: ?string}
     */
    protected function doLoiDanhSach(Response $res, string $viec): array
    {
        if ($res->status() === 403) {
            return [
                'thieuQuyen' => true,
                'error' => 'Bạn chưa được giao quyền xem '.$viec.'. Nhờ chủ tiệm mở quyền ở màn Phân quyền.',
            ];
        }

        return [
            'thieuQuyen' => false,
            'error' => $res->json('message') ?: 'Không tải được '.$viec.'.',
        ];
    }
}
