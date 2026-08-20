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
- Middleware `admin.auth` (`EnsureAdminAuthenticated`) bảo vệ mọi route đã đăng nhập.
- Middleware `admin.manage` (`EnsureManagerRole`) chặn thêm một tầng nữa: `staff` là
  THU NGÂN, trong khu quản trị chỉ mở được Tổng quan, Đơn hàng và hồ sơ của chính
  mình. Hàng hoá, kho, mua vào, trả hàng, khách hàng, báo cáo và cấu hình đều nằm
  sau middleware này. Go API chặn song song ở nhóm `manage` trong
  `internal/router/router.go` — đó mới là chốt thật, tầng này chỉ để báo sớm và ẩn menu.
- Trang: `/login`, `/admin/dashboard`, `/cashier/sales`.

## Hai module
Phần mềm chia làm hai khu, người dùng đứng trong đúng một khu tại một lúc
(`App\Services\ModuleLamViec` là chỗ duy nhất khai báo chuyện này):

| Module | Đường dẫn | Gồm | Vỏ trang |
|---|---|---|---|
| **Thu ngân** | `/cashier` | Bán tại quầy, Ca làm việc & sổ quỹ, Đơn quầy | `layouts/thu-ngan` — nền tối, không sidebar |
| **Quản trị** | `/admin` | Hàng hoá, kho, khách hàng, báo cáo, cấu hình | `layouts/app` — sidebar + topbar |

Đổi qua lại bằng nút ở góc phải thanh trên cùng (`partials/module-switch`, dùng
chung cho cả hai vỏ trang). Đăng nhập xong, `staff` vào thẳng module Thu ngân,
các vai trò còn lại vào khu quản trị. Đường cũ `/admin/ban-tai-quay` và
`/admin/ca-lam-viec` được chuyển hướng sang module mới, không bỏ hẳn — máy ở quầy
thường đặt sẵn trang chủ trình duyệt là mấy đường đó.

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
