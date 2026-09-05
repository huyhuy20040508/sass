<?php

namespace App\Http\Middleware;

use App\Services\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CHI NHÁNH ĐANG LÀM VIỆC LÀ CHUYỆN CỦA TỪNG TAB, KHÔNG PHẢI CỦA CẢ TRÌNH DUYỆT.
 *
 * Trước middleware này, chi nhánh nằm trong PHIÊN — một giá trị dùng chung cho
 * mọi tab. Hai hậu quả, và cái thứ hai mới là cái nguy hiểm:
 *
 *   1. Mở tab thứ hai để xem kho khác thì tab thứ nhất cũng đổi theo. Bấm F5
 *      bên tab cũ là nó "về lại" chi nhánh vừa chọn ở tab kia.
 *
 *   2. Tab cũ VẪN ĐANG HIỆN "chi nhánh 1" trên thanh trên cùng, nhưng mọi lượt
 *      ghi từ nó lại đi vào kho 2 — vì ApiClient đọc phiên tại thời điểm gửi,
 *      không phải chi nhánh mà trang ấy đã vẽ ra. Màn hình nói một đằng, hàng
 *      vào một nẻo, và không có dấu hiệu nào cả.
 *
 * CÁCH CHỮA: mỗi request tự khai chi nhánh của nó qua tham số `chi_nhanh`.
 * Trang nào cũng mang theo con số mình đã vẽ; phiên chỉ còn là GIÁ TRỊ MẶC ĐỊNH
 * cho tab mới mở.
 *
 * Vì sao không sợ "quên một chỗ" — nỗi lo đã ghi ở ApiClient::KHOA_CHI_NHANH:
 * không chỗ nào phải nhớ cả. Phía trình duyệt có đúng hai móc, đều nằm ở layout
 * dùng chung (xem khối "CHI NHÁNH THEO TAB" trong v2/layouts/master):
 *   - lượt ĐỌC: trang vẽ ra không khớp chi nhánh của tab thì tự nạp lại một lần
 *     kèm ?chi_nhanh=…, nên không link nào phải sửa;
 *   - lượt GHI: hidden input được nhét vào mọi form và mọi lượt gọi ngầm.
 *
 * KHÔNG ghi vào phiên ở đây. Ghi vào là hỏng đúng thứ vừa chữa: tab này lại kéo
 * tab kia đi theo. Chỉ nút đổi chi nhánh mới được ghi phiên, và nó ghi để tab
 * MỞ SAU thừa hưởng.
 */
class ChiNhanhTheoTab
{
    /** Tên tham số mà trình duyệt gửi lên (form và query). */
    public const THAM_SO = 'chi_nhanh';

    /** Header cho các lượt gọi ngầm — không nhét được tham số vào query. */
    public const HEADER = 'X-Chi-Nhanh-Tab';

    public function handle(Request $request, Closure $next): Response
    {
        // Nhận CẢ HAI đường: tham số (form, query — lượt mở trang và lượt gửi
        // form) và header (mọi lượt gọi ngầm bằng jQuery/fetch). Hai kiểu gọi
        // khác nhau có hai chỗ tự nhiên để đính kèm; ép cả hai về một kiểu là
        // đẻ thêm chỗ để quên.
        $raw = $request->header(self::HEADER) ?: $request->input(self::THAM_SO);
        $id = (int) $raw;

        // ĐẶT LẠI Ở MỌI REQUEST, kể cả khi không khai gì (id = 0 → null).
        //
        // Chỗ giữ là một biến STATIC, nên nó sống lâu hơn một lượt xử lý ở bất
        // cứ đâu tiến trình PHP được dùng lại: bài kiểm chạy nhiều request
        // trong cùng tiến trình, và Octane/worker cũng vậy. Chỉ ghi khi CÓ khai
        // thì request sau thừa hưởng chi nhánh của request trước — một lượt gọi
        // vô hại kéo theo cả loạt lượt sau ghi vào kho sai. Ba bài kiểm đã đỏ
        // đúng vì lỗi này, và chúng chỉ đỏ khi chạy cả bộ.
        ApiClient::datChiNhanhCuaRequest($id > 0 ? $id : null);

        // Không khai (hoặc khai bậy) thì đi tiếp bằng chi nhánh trong phiên —
        // đường của tab vừa mở, và của mọi cửa hàng một chi nhánh.
        if ($id <= 0) {
            return $next($request);
        }

        // KHÔNG xác minh id ở đây: API mới là nơi tra sổ và từ chối chi nhánh của
        // cửa hàng khác, chi nhánh đã đóng, hay chi nhánh người này không được
        // làm việc — cả ba đều trả 403 kèm câu giải thích. Kiểm lại ở đây là
        // chép luật sang chỗ thứ hai để một ngày nào đó hai bên lệch nhau.
        return $next($request);
    }
}
