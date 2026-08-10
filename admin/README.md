# Football Admin

Giao diện quản trị (Laravel 12 + Blade + Bootstrap 5). **Không kết nối MySQL trực tiếp** — mọi dữ liệu đi qua Backend Go API (`football-api`).

## Yêu cầu
- PHP 8.2+, Composer
- `football-api` đang chạy (mặc định `http://localhost:8080`)

## Cài đặt
```bash
composer install
cp .env.example .env
php artisan key:generate   # nếu .env chưa có APP_KEY
php artisan serve --port=8001
```

## Cấu hình (.env)
| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `API_BASE_URL` | `http://localhost:8080/api/v1` | Endpoint gốc của Go API |
| `API_TIMEOUT` | `15` | Timeout mỗi request (giây) |
| `SESSION_DRIVER` | `file` | Không cần DB cục bộ |

## Kiến trúc hiện tại
- `App\Services\ApiClient` — gọi Go API, tự đính kèm Bearer token từ session.
- Đăng nhập: gọi `POST /auth/login`, chỉ chấp nhận vai trò `super_admin` / `admin`, lưu token vào session.
- Middleware `admin.auth` (`EnsureAdminAuthenticated`) bảo vệ mọi route quản trị.
- Trang: `/login`, `/dashboard`.

## Tài khoản đăng nhập
Dùng tài khoản admin có sẵn trong `database/seed.sql` — `admin@selliotech.local` / `Admin@123`.

> Các module (Sản phẩm, Danh mục, Thương hiệu, Đơn hàng...) sẽ bổ sung ở bước tiếp theo.
