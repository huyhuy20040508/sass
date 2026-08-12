# selliotech-api

Backend API của nền tảng bán hàng Selliotech — **Go 1.25 + Gin + GORM**, theo **Clean Architecture**. Chứa 100% business logic; là service duy nhất kết nối MySQL.

Hai khu quản trị (`../admin` — Shop Admin, và `../saas` — SaaS Admin) đều gọi vào đây, không khu nào tự nối database.

## Kiến trúc (Clean Architecture)

```
cmd/api/            # entrypoint (main.go) — wiring dependency
internal/
  domain/           # entity (GORM models) + interface (ports) + lỗi nghiệp vụ — KHÔNG phụ thuộc framework
  repository/       # data access — hiện thực port bằng GORM/MySQL
  service/          # business logic — nơi tập trung nghiệp vụ
  handler/          # HTTP layer (Gin) — nhận request / trả JSON
  middleware/       # JWT auth, RBAC, CORS, request logger
  dto/              # request/response structs (validation)
  router/           # khai báo route + gắn middleware
pkg/                # helper dùng chung: jwt, hash (bcrypt), logger (Zap), response
config/             # nạp cấu hình (Viper)
```

Luồng phụ thuộc: `handler → service → repository → domain`. Tầng trong (domain) không biết tầng ngoài.

## Chạy dự án

```bash
# 1. Chuẩn bị cấu hình
cp .env.example .env        # rồi sửa DB_*, JWT_SECRET

# 2. Đảm bảo DB đã có lược đồ (lược đồ nằm ở ../database/migrations)
#    mysql -u root -e "CREATE DATABASE IF NOT EXISTS selliotech CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
#    go run ./cmd/migrate chay              # nạp lược đồ, đọc DB_* từ .env
#    go run ./cmd/migrate -nen-tang chay    # lược đồ THỨ HAI: control plane
#                                           # (selliotech_platform, tệp ở ../database/platform).
#                                           # Tự tạo database nếu chưa có.
#    go run ./cmd/tao-admin                 # vai trò + cửa hàng + tài khoản quản trị đầu tiên
#    (database/seed.sql đã tắt — nạp nó không tạo ra gì)

# 3. Tải dependency
go mod tidy

# 4a. Chạy thường
go run ./cmd/api

# 4b. Hoặc hot reload (cần cài Air)
#    go install github.com/air-verse/air@latest
air
```

Mặc định chạy tại `http://localhost:8080`.

## API Documentation (Swagger UI)

- Giao diện: **http://localhost:8080/swagger/index.html**
- JSON spec: `http://localhost:8080/swagger/doc.json`

Docs được sinh từ annotation (`// @Summary`, `// @Router`...) bằng [swag](https://github.com/swaggo/swag). Sau khi sửa annotation, chạy lại:

```bash
# Cài swag CLI 1 lần
go install github.com/swaggo/swag/cmd/swag@latest

# Sinh lại thư mục docs/ (docs.go, swagger.json, swagger.yaml)
swag init -g cmd/api/main.go --output docs
```

> **Đừng thêm `--parseDependency --parseInternal`.** Hai cờ đó làm swag đổi cách
> đặt tên kiểu (`dto.X` thành `sass-api_internal_dto.X`) và bung ra lỗi
> `cannot find type definition` ở `payment_handler.go` — tệp đó dùng `dto.` trong
> annotation nhưng không import gói `dto` trong mã, nên swag không có gì để tra
> alias. Lệnh trên không cần hai cờ ấy và chạy sạch.

> Thư mục `docs/` được commit vào git để `go build` chạy được mà không cần swag.
> Với endpoint cần đăng nhập: bấm **Authorize** trên Swagger UI và nhập `Bearer {access_token}`.

## API hiện có (`/api/v1`)

| Method | Endpoint | Quyền | Mô tả |
|---|---|---|---|
| GET  | `/health` | công khai | Health check |
| POST | `/auth/register` | công khai | Đăng ký khách hàng |
| POST | `/auth/login` | công khai | Đăng nhập bằng email (khách mua sắm, khu điều hành nền tảng) |
| POST | `/auth/shop-login` | công khai | Đăng nhập Shop Admin 3 ô: mã cửa hàng + tên đăng nhập + mật khẩu |
| POST | `/auth/refresh` | công khai | Làm mới access token |
| GET  | `/auth/me` | đăng nhập | Thông tin tài khoản |
| GET  | `/categories` | công khai | Danh sách danh mục |
| GET  | `/categories/:id` | công khai | Chi tiết danh mục |
| GET  | `/brands` | công khai | Danh sách thương hiệu |
| GET  | `/brands/:id` | công khai | Chi tiết thương hiệu |
| GET  | `/products` | công khai | Danh sách sản phẩm (lọc + phân trang) |
| GET  | `/products/:slug` | công khai | Chi tiết sản phẩm |
| POST/PUT/DELETE | `/admin/categories[/:id]` | admin | CRUD danh mục |
| POST/PUT/DELETE | `/admin/brands[/:id]` | admin | CRUD thương hiệu |
| POST/PUT/DELETE | `/admin/products[/:id]` | admin | CRUD sản phẩm |

## Định dạng response

```jsonc
// Thành công
{ "success": true, "message": "...", "data": {...}, "meta": {...} }
// Lỗi
{ "success": false, "message": "...", "errors": {...} }
```

## Mở rộng thêm resource mới (mẫu)

1. Thêm entity + repository interface trong `internal/domain`.
2. Hiện thực repository trong `internal/repository`.
3. Viết business logic trong `internal/service`.
4. Viết handler trong `internal/handler`, thêm DTO ở `internal/dto`.
5. Đăng ký route trong `internal/router/router.go` và wiring trong `cmd/api/main.go`.

> Tham khảo slice **Category** (đầy đủ CRUD) làm khuôn mẫu.

## Công nghệ

Gin · GORM · MySQL 8 · JWT (golang-jwt/v5) · Viper · Zap · bcrypt · go-playground/validator · Swagger (swaggo) · Air (hot reload)
