# Selliotech — SaaS Admin

Khu điều hành **nền tảng** (Laravel 12 + Blade + Bootstrap 5). Đây là nơi bạn quản lý các cửa hàng đã mua phần mềm — **không phải** nơi bán hàng.

Phân biệt với hai app còn lại:

| App | Ai dùng | Việc |
|---|---|---|
| `admin-Selliotech/` (app này) | Bạn — chủ nền tảng | Quản lý cửa hàng khách, gói dịch vụ, hạn dùng |
| `web_Shop/` | Khách đã mua | Quản lý bán hàng của chính cửa hàng họ |
| `api/` | — | Go API, service duy nhất chạm MySQL |

**Không kết nối MySQL trực tiếp** — mọi dữ liệu đi qua Go API, giống `web_Shop/`.

## Trạng thái hiện tại

Mới có **bộ khung**: đăng nhập (chỉ `super_admin`) + trang tổng quan trống + sidebar phác ra các mục sẽ làm. Các ô số liệu để `—` chứ không hiển thị `0`, vì chưa có gì để đếm.

Chưa làm: quản lý cửa hàng, gói dịch vụ, hoá đơn. Ba thứ đó cần Go API mở nhóm `/platform/*` trước — xem danh sách việc ngay trên trang tổng quan.

## Yêu cầu
- PHP 8.2+, Composer
- Go API đang chạy (mặc định `http://localhost:8080`)

## Cài đặt

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --port=8002
```

Hoặc chạy `start.bat` ở thư mục gốc — nó bật cả MySQL, API, Shop Admin và SaaS Admin.

## Cấu hình (.env)

| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `API_BASE_URL` | `http://localhost:8080/api/v1` | Endpoint gốc của Go API |
| `API_TIMEOUT` | `15` | Timeout mỗi request (giây) |
| `SESSION_COOKIE` | `selliotech_saas_session` | **Phải khác Shop Admin** — cùng chạy trên `localhost` thì trình duyệt phân biệt phiên bằng tên cookie chứ không bằng cổng |
| `SESSION_DRIVER` | `file` | Không cần DB cục bộ |

## Kiến trúc

- `App\Services\ApiClient` — gọi Go API, tự đính Bearer token từ session, gặp 401 thì tự `/auth/refresh` rồi thử lại một lần.
- `EnsurePlatformAuthenticated` (alias `platform.auth`) — chặn mọi route, **chỉ cho `super_admin`**.
- Trang: `/login`, `/dashboard`.

## Đăng nhập

Khu điều hành nền tảng vẫn đăng nhập bằng **email + mật khẩu** (`POST /auth/login`),
khác Shop Admin đã chuyển sang 3 ô — super admin là người của nền tảng, không thuộc
cửa hàng nào nên không có "mã cửa hàng" để gõ.

Không có tài khoản mặc định (`database/seed.sql` đã tắt). Tạo tài khoản `super_admin`
đầu tiên bằng `cd api && go run ./cmd/tao-admin`, rồi đăng nhập ở đây bằng chính email
mà lệnh đó đặt cho tài khoản — mặc định là `<tên đăng nhập>@<mã cửa hàng>.local`, đổi
được bằng cờ `--email`.

> Hiện `super_admin` vẫn là vai trò dùng chung với Shop Admin — cùng tài khoản đó vào được cả hai app. Khi tách multi-tenant, vai trò nền tảng phải tách khỏi vai trò cửa hàng; chỗ cần sửa là mảng `$allowedRoles` trong `EnsurePlatformAuthenticated` và phần kiểm quyền của `AuthController::login`.
