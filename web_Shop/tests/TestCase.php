<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * KHÔNG bài kiểm nào được gọi ra API thật.
     *
     * Đây là chốt cho một lớp lỗi đã cắn hai lần trong cùng một ngày, và cả hai
     * lần đều mất công truy vì triệu chứng chẳng liên quan gì tới nguyên nhân:
     *
     *   Bài kiểm giả lập vài đường (mẫu "…/admin/orders…") rồi quên phần còn lại.
     *   Middleware trên đường đi gọi một đường KHÁC — hạn hợp đồng chẳng hạn —
     *   lượt đó đi thẳng ra Go API đang chạy trên máy người sửa. Token trong bài
     *   kiểm là token giả nên API trả 401, và ApiClient phản ứng đúng như thiết
     *   kế: dọn sạch phiên. GIỮA CHỪNG request.
     *
     *   Từ đó trở đi trang vẫn dựng ra được, nhưng `session('api.user')` là null
     *   — nên bài kiểm đang đo màn hình của một người CHƯA ĐĂNG NHẬP mà vẫn xanh.
     *   Nó chỉ đỏ khi có ai đó viết một tính năng biết đọc phiên, và lúc ấy lỗi
     *   trông như nằm ở tính năng mới.
     *
     * Tệ nhất là bài kiểm xanh hay đỏ tuỳ theo máy đang chạy API hay không.
     *
     * preventStrayRequests đảo chiều: lượt gọi không giả lập NÉM LỖI ngay, kèm
     * đúng đường dẫn bị bỏ sót. Bài kiểm thiếu fake thì hỏng ngay tại chỗ thiếu.
     */
    /**
     * Bài kiểm nào ĐƯỢC PHÉP gọi API thật thì bật cờ này.
     *
     * Đúng một loại được: bài kiểm khói (AdminSmokeTest), vốn tồn tại để nói câu
     * "Shop Admin và Go API còn khớp nhau" — giả lập API trong đó là hỏi chính
     * mình rồi tự trả lời. Nó tự bỏ qua khi không gọi được API.
     *
     * Đừng bật cho bài kiểm thường: bật rồi thì bài kiểm ấy xanh hay đỏ tuỳ máy
     * người chạy có dựng API hay không, và đó là thứ chốt này sinh ra để chặn.
     */
    protected bool $choPhepGoiApiThat = false;

    /**
     * Bài kiểm này có đi qua cổng "chỉ hiện giao diện v2" không.
     *
     * Mặc định KHÔNG. Cổng ấy là cơ chế TRIỂN KHAI TỪNG ĐỢT: màn nào chưa dựng
     * lại theo v2 thì dồn về màn v2 gần nhất, để trong một phiên không thấy hai
     * giao diện lẫn lộn. Nó không phải nghiệp vụ của màn nào cả.
     *
     * Bắt bài kiểm của từng màn đi qua cổng thì chúng đo cái cổng chứ không đo
     * màn: mọi khẳng định về nội dung trang đều đỏ với cùng một lý do "302", và
     * lỗi thật của màn ấy chìm nghỉm giữa đám đỏ đó.
     *
     * Hành vi của chính cổng được gác riêng ở ChiHienGiaoDienV2Test.
     */
    protected bool $quaCongV2 = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->choPhepGoiApiThat) {
            Http::preventStrayRequests();
        }

        if (! $this->quaCongV2) {
            $this->withoutMiddleware(\App\Http\Middleware\ChiHienGiaoDienV2::class);
        }
    }
}
