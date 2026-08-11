# Triển khai Selliotech lên máy chủ

Đưa ba thành phần lên VPS `103.78.2.230` dưới tên miền `selliotech.store`.

| Tên miền | Trỏ tới | Là gì |
|---|---|---|
| `api.selliotech.store` | Go API (nội bộ `127.0.0.1:8080`) | Service duy nhất chạm MySQL |
| `admin.selliotech.store` | Laravel `admin/` | Shop Admin — khu quản lý bán hàng |
| `app.selliotech.store` | Laravel `saas/` | SaaS Admin — khu điều hành nền tảng |
| `selliotech.store` | *(chưa dùng)* | Để dành cho trang giới thiệu bán phần mềm |

Máy chủ: Ubuntu 24.04, đã có sẵn nginx 1.24.

## Máy chủ này đang dùng chung với dự án khác

VPS `103.78.2.230` còn chạy `thejerseylab.shop` và `jerseyhouse.id.vn` (thư mục `/var/www/football`, `/var/www/football-prod`). Bộ triển khai này cố ý **không đụng** vào chúng, và có ba chỗ được thiết kế riêng vì lý do đó:

| Chỗ | Selliotech dùng | Vì sao |
|---|---|---|
| Cổng Go API | `8090` | 8080 và 8081 đã bị hai `football-api` chiếm. Đặt trùng thì systemd không lên nổi, mà nginx vẫn trả lời bằng ứng dụng của dự án kia — nhìn tưởng chạy, thực ra gọi nhầm chương trình |
| PHP-FPM | Pool riêng `selliotech`, socket `php8.3-fpm-selliotech.sock` | Pool mặc định `www.conf` đã bị dự án cũ đổi sang chạy dưới người dùng `football`; mượn nó thì Laravel không ghi nổi `storage/` và mọi trang trả 500 **mà không để lại log nào** |
| Giới hạn upload PHP | Đặt trong pool, không đặt ở `conf.d/` | `conf.d/` áp cho mọi pool, tức là sửa luôn cấu hình của dự án kia |

---

## Bước 1 — DNS ở Hostinger

Vào **hPanel → Domains → selliotech.store → DNS / Nameservers → DNS Zone Editor**, thêm **3 bản ghi A**:

| Type | Name | Points to | TTL |
|---|---|---|---|
| A | `api` | `103.78.2.230` | 300 |
| A | `admin` | `103.78.2.230` | 300 |
| A | `app` | `103.78.2.230` | 300 |

Ba điều dễ vấp:

- **Ô Name chỉ điền `api`, không điền `api.selliotech.store`.** Hostinger tự nối phần đuôi; điền cả tên đầy đủ sẽ thành `api.selliotech.store.selliotech.store`.
- **Kiểm tra xem zone có bản ghi `*` (wildcard) không.** Nếu có, nó tóm luôn cả ba subdomain và trỏ về trang park, ba bản ghi vừa thêm thành vô nghĩa. Có thì xoá đi.
- **Đừng đụng vào bản ghi `@` và `www`** — cứ để trỏ về Hostinger như hiện tại. Tên miền gốc chưa dùng tới.

TTL 300 giây là cố ý: đang lúc dựng, sai thì sửa lại thấy hiệu lực sau 5 phút thay vì phải chờ 4 tiếng. Xong xuôi nâng lên 14400 cũng được.

Chờ vài phút rồi kiểm từ máy bạn:

```bash
nslookup api.selliotech.store 8.8.8.8
# Address phải là 103.78.2.230, không phải 2.57.91.91
```

**Phải thấy đúng địa chỉ VPS rồi mới sang bước bật HTTPS**, vì Let's Encrypt xác minh quyền sở hữu bằng cách gọi ngược vào tên miền.

---

## Bước 2 — Cài đặt máy chủ (một lần duy nhất)

SSH vào VPS rồi:

```bash
ssh root@103.78.2.230

# Tải riêng hai script (lúc này mã nguồn chưa có trên máy)
curl -fsSL -o 01-cai-may-chu.sh \
  https://raw.githubusercontent.com/huyhuy20040508/sass/main/deploy/scripts/01-cai-may-chu.sh

sudo bash 01-cai-may-chu.sh
```

Script cài: PHP 8.3-FPM (kèm **gd** cho phần thu nhỏ ảnh), MySQL 8, Go 1.25, Composer, certbot, tường lửa; tạo người dùng `selliotech`, database `selliotech` và tài khoản MySQL riêng.

**Chép lại `DB_PASSWORD` nó in ra ở cuối** — chuỗi đó không hiện lại lần thứ hai.

---

## Bước 3 — Tạo ba tệp `.env`

Mã nguồn chưa có trên máy. Chạy script triển khai một lần cho nó tự tải về — thiếu `.env` thì nó dừng lại và nhắc, chưa đụng gì tới hệ thống:

```bash
curl -fsSL -o 02-trien-khai.sh \
  https://raw.githubusercontent.com/huyhuy20040508/sass/main/deploy/scripts/02-trien-khai.sh

sudo bash 02-trien-khai.sh     # sẽ dừng ở bước 2/9 và nhắc thiếu .env
```

Giờ tạo ba tệp:

```bash
cd /var/www/selliotech
sudo cp deploy/env/api.env.example   api/.env
sudo cp deploy/env/admin.env.example admin/.env
sudo cp deploy/env/saas.env.example  saas/.env

# Sinh khoá ký token — KHÔNG bê chuỗi ở máy cá nhân lên
openssl rand -base64 48

sudo nano api/.env      # điền DB_PASSWORD (bước 2) và JWT_SECRET (dòng trên)
```

`admin/.env` và `saas/.env` để nguyên cũng chạy được; `APP_KEY` do script tự sinh.

---

## Bước 4 — Triển khai

```bash
cd /var/www/selliotech
sudo bash deploy/scripts/02-trien-khai.sh
```

Script làm: build Go API → nạp lược đồ database → `composer install --no-dev` cho hai app Laravel → cache config/route/view → đặt quyền thư mục → cài nginx + systemd → khởi động → gọi thử `/health`.

Lần đầu chạy, nó nạp `database/seed.sql` và in ra tài khoản quản trị đầu tiên.

Kiểm bằng trình duyệt (vẫn còn `http://`):

```
http://api.selliotech.store/api/v1/health
http://admin.selliotech.store
http://app.selliotech.store
```

---

## Bước 5 — Bật HTTPS

```bash
sudo certbot --nginx \
  --cert-name selliotech.store \
  -d api.selliotech.store \
  -d admin.selliotech.store \
  -d app.selliotech.store \
  --redirect
```

`--cert-name` gộp ba tên miền vào **một** chứng chỉ mang tên `selliotech.store`, tách bạch với chứng chỉ của dự án khác trên cùng máy. `--redirect` để `http://` tự chuyển sang `https://`.

Certbot tự chèn phần TLS vào ba tệp trong `sites-available/` và tự gia hạn bằng timer có sẵn. Kiểm tra timer:

```bash
systemctl list-timers certbot.timer
sudo certbot renew --dry-run
```

---

## Bước 6 — Chốt lại cấu hình theo HTTPS

Ba tệp `.env` mẫu đã ghi sẵn `https://`, nên nếu bạn không sửa gì thì **chỉ cần nạp lại cache**:

```bash
cd /var/www/selliotech
sudo bash deploy/scripts/02-trien-khai.sh
```

Sau đó bắt buộc kiểm hai thứ hay sai nhất:

```bash
# 1. CORS: trình duyệt phải gọi được API từ hai khu quản trị
curl -si https://api.selliotech.store/api/v1/health \
  -H 'Origin: https://admin.selliotech.store' | grep -i access-control

# 2. Realtime (SSE): phải giữ kết nối mở, KHÔNG trả về ngay
curl -N -m 5 https://api.selliotech.store/api/v1/events
# Đúng: treo 5 giây rồi timeout. Sai: trả về tức thì (nginx đang đệm).
```

---

## Sau khi lên xong: đổi mật khẩu

Bản seed tạo tài khoản `admin@selliotech.local / Admin@123`. Chuỗi này nằm công khai trong repo.

1. Đăng nhập `https://app.selliotech.store` bằng tài khoản đó.
2. Vào **Shop Admin → Người dùng** tạo tài khoản thật của bạn (`super_admin` cho khu điều hành, `admin` cho khu bán hàng).
3. Khoá hoặc xoá tài khoản seed.

**Đừng dùng mật khẩu `12345678` như ở máy cá nhân.** Trên máy thật nó nằm trong mọi danh sách dò mật khẩu, và hạn mức đăng nhập (10 lượt/5 phút) chỉ làm chậm chứ không chặn được.

---

## Cập nhật về sau

```bash
cd /var/www/selliotech
sudo bash deploy/scripts/02-trien-khai.sh
```

Script kéo bản mới từ nhánh `main`, build lại, đổi binary rồi khởi động lại. Nếu build hỏng thì binary đang chạy vẫn nguyên — web không chết trong lúc bạn đi sửa.

---

## Khi có sự cố

```bash
# API
sudo systemctl status selliotech-api
sudo journalctl -u selliotech-api -n 100 --no-pager

# nginx
sudo nginx -t
sudo tail -50 /var/log/nginx/admin.selliotech.store.error.log

# Laravel
sudo tail -50 /var/www/selliotech/admin/storage/logs/laravel.log
```

| Triệu chứng | Nguyên nhân thường gặp |
|---|---|
| Trang trắng / lỗi 500 ở Laravel | `storage/` không ghi được — chạy lại bước 6 của `02-trien-khai.sh` |
| Sửa `.env` mà không thấy đổi gì | Cache config còn giữ giá trị cũ — chạy lại `02-trien-khai.sh` |
| Đăng nhập được rồi bật ra ngay | `SESSION_SECURE_COOKIE=true` nhưng đang vào bằng `http://` |
| Chuông thông báo không tự cập nhật | `API_PUBLIC_URL` sai, hoặc nginx đang đệm `/api/v1/events` |
| `go build` chết với "signal: killed" | Hết RAM — kiểm tra swap đã bật chưa (`free -m`) |
| Ảnh tải lên báo lỗi 413 | `client_max_body_size` và `upload_max_filesize` lệch nhau |

---

## Những gì bản triển khai này CHƯA có

Nói trước để không tưởng nhầm là đã xong xuôi:

- **Chưa sao lưu database.** Chưa có cron `mysqldump` nào cả. Đây là việc phải làm trước khi có dữ liệu thật của khách.
- **Chưa multi-tenant.** Shop Admin trên máy chủ này phục vụ **một** cửa hàng. Bán phần mềm cho khách thứ hai thì phải dựng thêm bản mới, hoặc làm phần tách cửa hàng.
- **SaaS Admin mới là khung** — đăng nhập và trang tổng quan, chưa quản lý được cửa hàng nào.
- **Chưa có giám sát.** Máy chủ chết lúc 3 giờ sáng thì không ai biết cho tới khi có người mở trang.
