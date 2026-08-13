<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * ApiClient — lớp gọi Backend Go API.
 *
 * Giống hệt vai trò của ApiClient bên Shop Admin: app này KHÔNG kết nối MySQL,
 * mọi dữ liệu đều đi qua REST API. Access token lấy từ session sau khi đăng nhập
 * và tự đính kèm dạng Bearer.
 *
 * Bản này cố tình chỉ có phần auth. Các đường của tầng nền tảng (danh sách cửa
 * hàng đã mua phần mềm, gói dịch vụ, hạn dùng) chưa tồn tại ở Go API — thêm vào
 * mục "Nền tảng" cuối tệp khi API có.
 */
class ApiClient
{
    protected string $baseUrl;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = (string) config('api.base_url');
        $this->timeout = (int) config('api.timeout');
    }

    /**
     * Tạo request nền, tự đính kèm token (mặc định lấy từ session).
     * Truyền $token = false để chủ động bỏ qua token (vd: lúc đăng nhập).
     */
    public function request(string|false|null $token = null): PendingRequest
    {
        $req = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();

        if ($token !== false) {
            $token = $token ?: session('api.access_token');
            if ($token) {
                $req = $req->withToken($token);
            }
        }

        return $req;
    }

    public function get(string $uri, array $query = []): Response
    {
        return $this->send('GET', $uri, $query);
    }

    public function post(string $uri, array $data = []): Response
    {
        return $this->send('POST', $uri, $data);
    }

    public function put(string $uri, array $data = []): Response
    {
        return $this->send('PUT', $uri, $data);
    }

    public function delete(string $uri): Response
    {
        return $this->send('DELETE', $uri);
    }

    /**
     * Gửi request có đính token; nếu bị 401 (access token hết hạn) thì tự động
     * gọi /auth/refresh bằng refresh token rồi thử lại đúng một lần.
     */
    protected function send(string $method, string $uri, array $payload = []): Response
    {
        $res = $this->dispatch($method, $uri, $payload);

        if ($res->status() === 401 && $this->refreshToken()) {
            $res = $this->dispatch($method, $uri, $payload);
        }

        return $res;
    }

    /** Thực thi một HTTP request đơn lẻ (token lấy từ session tại thời điểm gọi). */
    protected function dispatch(string $method, string $uri, array $payload): Response
    {
        $req = $this->request();

        return match (strtoupper($method)) {
            'POST' => $req->post($uri, $payload),
            'PUT' => $req->put($uri, $payload),
            'DELETE' => $req->delete($uri),
            default => $req->get($uri, $payload),
        };
    }

    /**
     * Dùng refresh token lấy cặp token mới, cập nhật lại session.
     * Trả về true nếu làm mới thành công. Không đính access token cũ để tránh vòng lặp.
     */
    protected function refreshToken(): bool
    {
        $refresh = session('api.refresh_token');
        if (! $refresh) {
            return false;
        }

        try {
            // ĐƯỜNG RIÊNG của khu điều hành. /auth/refresh cố ý từ chối mọi token
            // không thuộc cửa hàng nào, mà token của khu điều hành thì luôn như
            // vậy — gọi vào đó chỉ nhận 401 và người dùng bị đá ra màn hình đăng
            // nhập mỗi 15 phút.
            $res = $this->request(false)->post('/auth/platform-refresh', [
                'refresh_token' => $refresh,
            ]);
        } catch (\Throwable $e) {
            return false;
        }

        if (! $res->successful()) {
            return false;
        }

        $data = $res->json('data');
        $access = data_get($data, 'access_token');
        if (! $access) {
            return false;
        }

        session([
            'api.access_token' => $access,
            'api.refresh_token' => data_get($data, 'refresh_token') ?: $refresh,
        ]);
        if ($user = data_get($data, 'user')) {
            session(['api.user' => $user]);
        }

        return true;
    }

    // ---------- Auth ----------

    /** Đăng nhập, trả về Response thô để controller xử lý. */
    /**
     * Đăng nhập khu điều hành nền tảng.
     *
     * Đi đường /auth/platform-login chứ KHÔNG phải /auth/login: đường kia dành
     * cho khách mua sắm nên nó bắt buộc phải biết đang đứng ở cửa hàng nào (lấy
     * theo tên miền), mà khu điều hành thì không thuộc cửa hàng nào — gọi vào đó
     * chỉ nhận về 401 "Chưa xác định được cửa hàng cho yêu cầu này".
     *
     * Tài khoản dùng ở đây nằm trong sổ RIÊNG của nền tảng (`platform_users`),
     * do `cmd/nguoi-dieu-hanh` tạo trên máy chủ. Tài khoản của một cửa hàng —
     * kể cả super_admin của tiệm đó — không đăng nhập được vào đây.
     */
    public function login(string $email, string $password): Response
    {
        return $this->request(false)->post('/auth/platform-login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /**
     * Lấy hồ sơ người điều hành hiện tại theo access token.
     *
     * /auth/platform-me chứ không phải /auth/me: đường kia đòi token của một cửa
     * hàng, và token khu điều hành cố ý không mang cửa hàng nào.
     */
    public function me(): Response
    {
        return $this->get('/auth/platform-me');
    }

    /** Kiểm tra API có sống không — dùng cho ô trạng thái ở Dashboard. */
    public function health(): bool
    {
        try {
            return $this->request(false)->get('/health')->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---------- Nền tảng (chưa có ở Go API) ----------
    //
    // Khi API mở nhóm /platform/*, các phương thức đọc/ghi cửa hàng, gói dịch vụ
    // và hạn dùng viết ở đây. Cố tình để trống thay vì viết sẵn hàm gọi vào đường
    // chưa tồn tại: hàm như thế trông như đã chạy được, tới lúc bấm mới lộ ra là
    // 404 và mất công dò ngược.
}
