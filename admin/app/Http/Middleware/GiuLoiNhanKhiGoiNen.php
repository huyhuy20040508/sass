<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Giữ lại lời nhắn (flash) khi một LƯỢT GỌI NỀN chạy chen vào.
 *
 * VẤN ĐỀ NÓ CHỮA — đọc kỹ, vì triệu chứng không chỉ vào nguyên nhân:
 *
 * Mọi thao tác ghi của trang quản trị đều theo khuôn "POST → redirect → hiện
 * toast": controller gọi `->with('success'|'error', …)`, Laravel cất câu đó vào
 * session dưới dạng FLASH, và flash chỉ sống đúng MỘT request kế tiếp — đọc
 * xong là bị dọn.
 *
 * Nhưng mỗi trang quản trị còn tự bắn vài lượt gọi nền (chuông thông báo:
 * `GET /admin/notifications`, `/notifications/stream-token` — xem
 * public/js/realtime.js). Chúng cũng là route web, cũng mở session, và chúng
 * chạy SONG SONG với lượt tải trang. Lượt nào tới trước thì lượt đó "ăn" mất
 * flash: nếu chuông tới trước, trang hiện ra sạch trơn — không toast, không lời
 * giải thích nào.
 *
 * Hậu quả không phải chuyện thẩm mỹ. Người dùng bấm Lưu, trang tải lại y
 * nguyên, và họ không có cách nào biết hệ thống vừa TỪ CHỐI mình vì lý do gì —
 * "gói dịch vụ đã hết chỗ", "mã đã có người dùng", "đây là chi nhánh cuối
 * cùng". Câu chữ đã được viết sẵn và server đã trả về đúng, chỉ là không tới
 * được mắt người đọc. Và vì nó phụ thuộc vào việc request nào về đích trước
 * nên nó CHẬP CHỜN, kiểu lỗi khó tin nhất khi có người báo lại.
 *
 * CÁCH CHỮA: lượt gọi nền không được phép tiêu thụ lời nhắn dành cho trang.
 * `reflash()` đẩy flash hiện có sang lượt sau, nên câu đó nằm nguyên đó chờ
 * đúng lượt tải trang.
 *
 * PHÂN BIỆT BẰNG "ĐÒI JSON" chứ không bằng danh sách đường dẫn: chặn theo tên
 * route thì mỗi lần thêm một lượt gọi nền mới, người viết phải nhớ khai thêm —
 * và cái quên đó lại sinh ra đúng lỗi chập chờn này lần nữa. Trình duyệt tải
 * TRANG luôn gửi `Accept: text/html`, còn fetch() của mình luôn gửi
 * `Accept: application/json`, nên ranh giới này tự đúng cho cả những lượt gọi
 * nền viết sau này.
 */
class GiuLoiNhanKhiGoiNen
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Sau $next: để controller chạy xong đã. Session được lưu ở tầng ngoài
        // (StartSession) nên thao tác này vẫn kịp vào bản ghi.
        //
        // hasSession(): route API thuần (không có session) thì không có gì để giữ,
        // và gọi reflash() ở đó là ném lỗi cho một request lẽ ra chạy êm.
        if ($request->hasSession() && ($request->expectsJson() || $request->ajax())) {
            $request->session()->reflash();
        }

        return $response;
    }
}
