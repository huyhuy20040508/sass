# Selliotech — Shop Admin

Giao diện quản trị (Laravel 12 + Blade + Bootstrap 5). **Không kết nối MySQL trực tiếp** — mọi dữ liệu đi qua Backend Go API (`api/`).

## Yêu cầu
- PHP 8.2+, Composer
- `api/` đang chạy (mặc định `http://localhost:8080`)

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
- Đăng nhập **3 ô** (mã cửa hàng · tên đăng nhập · mật khẩu): gọi `POST /auth/shop-login`,
  chỉ chấp nhận vai trò `super_admin` / `admin` / `staff`, lưu token + cửa hàng vào session.
  Mã cửa hàng là `tenants.code` (mã khách hàng được cấp), không phải mã chi nhánh.
- Middleware `admin.auth` (`EnsureAdminAuthenticated`) bảo vệ mọi route quản trị.
- Trang: `/login`, `/dashboard`.

## Tài khoản đăng nhập
Không có tài khoản mặc định — `database/seed.sql` đã tắt. Tạo cửa hàng + tài khoản
đầu tiên bằng công cụ đi kèm API (hỏi lần lượt đúng ba ô của màn hình đăng nhập):

```bash
cd api && go run ./cmd/tao-admin
# hoặc: go run ./cmd/tao-admin --ma-cua-hang quochuy --ten-dang-nhap admin --mat-khau '...'
```

Quên mật khẩu thì chạy lại lệnh đó kèm `--doi-mat-khau`. Trên máy chủ thật, lệnh
tương ứng là `sudo selliotech-tao-admin` (xem `deploy/README.md`).

> Các module (Sản phẩm, Danh mục, Thương hiệu, Đơn hàng...) sẽ bổ sung ở bước tiếp theo.
