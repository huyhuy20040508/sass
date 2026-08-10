<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * NotificationController — chuông thông báo của trang quản trị.
 *
 * Toàn bộ endpoint ở đây trả JSON cho `public/js/realtime.js` gọi bằng fetch.
 * Dữ liệu vẫn đi qua Go API như mọi module khác; Laravel chỉ làm lớp trung gian
 * giữ access token trong session thay vì phơi ra trình duyệt.
 *
 * Ngoại lệ duy nhất là luồng SSE: trình duyệt nối THẲNG tới Go
 * (`/events`) chứ không qua Laravel — `php artisan serve` chỉ có một tiến trình,
 * một kết nối giữ mở sẽ chặn mọi request còn lại của trang quản trị. Vì thế mới
 * có endpoint streamToken() ở dưới: nó cấp riêng access token cho trình duyệt mở
 * kết nối đó.
 */
class NotificationController extends Controller
{
    public function __construct(protected ApiClient $api) {}

    /** Danh sách thông báo + số chưa đọc để vẽ chuông. */
    public function index(Request $request)
    {
        try {
            $res = $this->api->notifications([
                'page_size' => min(50, max(1, (int) $request->query('page_size', 15))),
            ]);
            if ($res->successful()) {
                return response()->json($res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::warning('Load notifications failed', ['msg' => $e->getMessage()]);
        }

        // Chuông hỏng không được làm vỡ giao diện: trả danh sách rỗng để JS vẽ
        // trạng thái "chưa có thông báo" thay vì ném lỗi ra màn hình.
        return response()->json(['items' => [], 'total' => 0, 'unread_count' => 0], 200);
    }

    /** Đánh dấu một thông báo đã đọc. */
    public function read(int $id)
    {
        try {
            $res = $this->api->readNotification($id);
            if ($res->successful()) {
                return response()->json($res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::warning('Mark notification read failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không cập nhật được thông báo.'], 422);
    }

    /** Đánh dấu tất cả đã đọc. */
    public function readAll()
    {
        try {
            $res = $this->api->readAllNotifications();
            if ($res->successful()) {
                return response()->json($res->json('data') ?? ['unread_count' => 0]);
            }
        } catch (\Throwable $e) {
            Log::warning('Mark all notifications read failed', ['msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không cập nhật được thông báo.'], 422);
    }

    /**
     * Cấp access token để trình duyệt mở luồng SSE tới Go API.
     *
     * Token có hạn ngắn và JS xin lại mỗi khi kết nối rớt, nên trang không cần
     * tải lại khi phiên được làm mới. Phiên đã chết thì trả 401 để JS ngừng thử.
     */
    public function streamToken()
    {
        $token = $this->api->streamToken();
        if (! $token) {
            return response()->json(['message' => 'Phiên đăng nhập đã hết hạn.'], 401);
        }

        return response()->json([
            'token' => $token,
            'url' => config('api.public_url').'/events',
        ]);
    }

    /**
     * Trang tự chẩn đoán realtime (/admin/notifications/check).
     *
     * Lý do tồn tại: khi realtime "im lặng không chạy" mà console không ném lỗi
     * nào thì không biết nó gãy ở khâu nào — xin token, mở EventSource, hay bị
     * CORS chặn. Trang này chạy đúng từng bước đó và in kết quả ra màn hình.
     */
    public function check()
    {
        return view('notifications.check', [
            'streamUrl' => config('api.public_url').'/events',
            'appOrigin' => request()->getSchemeAndHttpHost(),
        ]);
    }

    /** Bảo API bắn một thông báo thử vào kênh quản trị (chỉ để chẩn đoán). */
    public function test()
    {
        try {
            $res = $this->api->post('/admin/notifications/test');

            return response()->json($res->json() ?? [], $res->status());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Không gọi được API: '.$e->getMessage()], 502);
        }
    }
}
