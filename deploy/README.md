# Triển khai Selliotech lên máy chủ

Đưa bốn thành phần lên VPS `103.78.2.230` dưới tên miền `selliotech.store`.

| Tên miền | Trỏ tới | Là gì |
|---|---|---|
| `selliotech.store` | Thư mục tĩnh `landing/` | Trang giới thiệu bán phần mềm |
| `api.selliotech.store` | Go API (nội bộ `127.0.0.1:8090`) | Service duy nhất chạm MySQL |
| `order.selliotech.store` | Laravel `admin/` | Shop Admin — khu quản lý bán hàng |
| `admin.selliotech.store` | Laravel `saas/` | SaaS Admin — khu điều hành nền tảng |
| `app.selliotech.store` | — | Tên miền cũ của SaaS Admin, chỉ chuyển hướng 301 sang `admin.*` |

> **Hai tên miền đã đổi chỗ.** Trước đây `admin.*` là khu bán hàng và `app.*` là khu điều hành.
> Nay khu bán hàng dời sang `order.*`, còn `admin.*` thuộc về khu điều hành. Ai quen bản cũ mà mở
> `admin.selliotech.store` sẽ thấy màn hình đăng nhập của **khu điều hành nền tảng** — không phải
> lỗi cấu hình, và không chuyển hướng riêng cho họ được vì cùng một tên miền giờ là app khác.

`www.selliotech.store` nhận cùng một trang giới thiệu, không chuyển hướng — lý do ghi trong `deploy/nginx/selliotech.store.conf`.

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

Zone nằm ở Hostinger (nameserver `atlas.dns-parking.com` / `hyperion.dns-parking.com`), nên sửa trong **hPanel → Domains → selliotech.store → DNS / Nameservers → DNS Zone Editor**.

Đích cần đạt:

| Type | Name | Points to | TTL |
|---|---|---|---|
| A | `@` | `103.78.2.230` | 300 |
| CNAME | `www` | `selliotech.store` | *(để nguyên)* |
| A | `api` | `103.78.2.230` | 300 |
| A | `order` | `103.78.2.230` | 300 |
| A | `admin` | `103.78.2.230` | 300 |
| A | `app` | `103.78.2.230` | 300 |

`app` giữ lại dù không còn app nào đứng sau: nó chỉ chuyển hướng sang `admin`. Xoá bản ghi
DNS thì người còn giữ dấu trang cũ nhận về lỗi "không tìm thấy máy chủ", chẳng biết đi đâu tiếp.

**`www` không cần bản ghi A.** Hostinger dựng sẵn nó thành CNAME trỏ về `selliotech.store`, tức là nó bám theo `@` — sửa `@` xong thì `www` tự đi theo. Cũng đừng đổi nó thành A: một Name không thể vừa có CNAME vừa có A, và giữ CNAME thì sau này đổi địa chỉ máy chủ chỉ phải sửa một chỗ.

Bốn điều dễ vấp:

- **Ô Name chỉ điền `api`, không điền `api.selliotech.store`.** Hostinger tự nối phần đuôi; điền cả tên đầy đủ sẽ thành `api.selliotech.store.selliotech.store`.
- **`@` mặc định trỏ về Hostinger (`2.57.91.91`) — phải SỬA dòng có sẵn, đừng bấm Add Record.** Thêm bản ghi A thứ hai cho cùng một Name làm DNS trả về luân phiên hai địa chỉ: cứ vài lần vào thì một lần rơi lại trang park, triệu chứng rất khó lần ra nguyên nhân.
- **Kiểm tra xem zone có bản ghi `*` (wildcard) không.** Nếu có, nó tóm luôn mọi subdomain và trỏ về trang park, các bản ghi kia thành vô nghĩa. Có thì xoá đi. Kiểm bằng cách tra một tên bịa: `nslookup khong-ton-tai.selliotech.store 8.8.8.8` phải báo không tìm thấy.
- **Nếu sau này gắn email cho tên miền**, bản ghi `MX` và `TXT` không liên quan gì tới `@`: đổi địa chỉ A của `@` không làm hỏng email.

TTL 300 giây là cố ý: đang lúc dựng, sai thì sửa lại thấy hiệu lực sau 5 phút thay vì phải chờ 4 tiếng. Xong xuôi nâng lên 14400 cũng được.

Chờ vài phút rồi kiểm từ máy bạn:

```bash
for t in selliotech.store www.selliotech.store api.selliotech.store \
         order.selliotech.store admin.selliotech.store app.selliotech.store; do
  echo -n "$t -> "; nslookup "$t" 8.8.8.8 | awk '/^Address: /{print $2}' | tail -1
done
# Cả sáu dòng phải ra 103.78.2.230, không phải 2.57.91.91
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

Script cài: PHP 8.3-FPM (kèm **gd** cho phần thu nhỏ ảnh), MySQL 8, Go 1.25, Composer, certbot, tường lửa; tạo người dùng `selliotech`, **hai** database (`selliotech` cho dữ liệu bán hàng, `selliotech_platform` cho sổ cái nền tảng) và tài khoản MySQL riêng dùng chung cho cả hai.

**Chép lại `DB_PASSWORD` nó in ra ở cuối** — chuỗi đó không hiện lại lần thứ hai.

---

## Bước 3 — Tạo ba tệp `.env`

Mã nguồn chưa có trên máy. Chạy script triển khai một lần cho nó tự tải về — thiếu `.env` thì nó dừng lại và nhắc, chưa đụng gì tới hệ thống:

```bash
curl -fsSL -o 02-trien-khai.sh \
  https://raw.githubusercontent.com/huyhuy20040508/sass/main/deploy/scripts/02-trien-khai.sh

sudo bash 02-trien-khai.sh     # sẽ dừng ở bước 2/10 và nhắc thiếu .env
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

Script làm: build Go API → nạp lược đồ database → `composer install --no-dev` cho hai app Laravel → cache config/route/view → đặt quyền thư mục → cài nginx + systemd → bật sao lưu tự động → khởi động → gọi thử `/health`.

Script **không** tạo tài khoản quản trị: mật khẩu nằm trong tệp `.sh` của repo thì
ai đọc repo cũng có. Nó chỉ đếm số tài khoản nội bộ, và khi database còn trắng thì
in ra hướng dẫn chạy `selliotech-tao-admin` ở bước dưới.

Trang giới thiệu không cần bước build nào: nó là HTML tĩnh nằm sẵn trong `landing/`, script chỉ đặt tệp nginx và bước đặt quyền lo phần còn lại.

Kiểm bằng trình duyệt (vẫn còn `http://`):

```
http://selliotech.store
http://api.selliotech.store/api/v1/health
http://order.selliotech.store        <- Shop Admin (bán hàng)
http://admin.selliotech.store        <- SaaS Admin (điều hành nền tảng)
http://app.selliotech.store          <- phải nhảy sang admin.*
```

---

## Bước 5 — Bật HTTPS

```bash
sudo certbot --nginx \
  --cert-name selliotech.store \
  -d selliotech.store \
  -d www.selliotech.store \
  -d api.selliotech.store \
  -d order.selliotech.store \
  -d admin.selliotech.store \
  -d app.selliotech.store \
  --redirect
```

`--cert-name` gộp sáu tên miền vào **một** chứng chỉ mang tên `selliotech.store`, tách bạch với chứng chỉ của dự án khác trên cùng máy. `--redirect` để `http://` tự chuyển sang `https://`.

**Thêm tên miền vào danh sách là phải chạy lại đúng lệnh này** (kèm `--expand`). Chứng chỉ đang có không tự mọc thêm tên miền, mà bước 7 của `02-trien-khai.sh` chỉ `certbot install` — gắn lại chứng chỉ CÓ SẴN. Tên miền mới thiếu trong chứng chỉ thì trình duyệt báo lỗi bảo mật ngay từ lần mở đầu tiên. `order.selliotech.store` được thêm theo đúng đường đó.

**Nếu máy đã có chứng chỉ `selliotech.store` từ lần trước** (hồi đó mới có ba tên miền), certbot sẽ hỏi có mở rộng không — chọn mở rộng, hoặc chạy thẳng với `--expand`. Đừng đặt `--cert-name` khác để xin riêng cho tên miền gốc: hai chứng chỉ cho cùng một zone làm lần gia hạn sau khó lần ra cái nào đang phục vụ cái gì.

Certbot tự chèn phần TLS vào bốn tệp trong `sites-available/` và tự gia hạn bằng timer có sẵn. Kiểm tra timer:

> **Phần TLS đó không nằm trong git.** Bốn tệp `deploy/nginx/*.conf` chỉ có block cổng 80, mà bước 7 của `02-trien-khai.sh` thì chép đè chúng lên `sites-available/` — nên mỗi lượt triển khai xoá sạch phần certbot vừa viết. Chuyện này đã xảy ra thật ngày 11/08/2026: chứng chỉ cấp lúc 14:49, lượt triển khai tối cùng ngày làm site tụt về HTTP mà không có dấu hiệu gì (nginx vẫn chạy, cổng 80 vẫn trả trang, chỉ `https://` là đứt). Script giờ tự chạy `certbot install` ngay sau vòng chép đè để gắn lại chứng chỉ **có sẵn** — không xin cấp mới nên không đụng hạn mức Let's Encrypt. Sau mỗi lần triển khai vẫn nên kiểm một câu:
>
> ```bash
> curl -s -o /dev/null -w '%{http_code}\n' https://api.selliotech.store/api/v1/health   # phải là 200
> ```

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
  -H 'Origin: https://order.selliotech.store' | grep -i access-control

# 2. Realtime (SSE): phải giữ kết nối mở, KHÔNG trả về ngay
curl -N -m 5 https://api.selliotech.store/api/v1/events
# Đúng: treo 5 giây rồi timeout. Sai: trả về tức thì (nginx đang đệm).
```

---

## Sau khi lên xong: tạo tài khoản quản trị

Database mới lên là trắng — không có vai trò, không có cửa hàng, không có tài khoản
nào. `database/seed.sql` **không** tạo gì nữa (toàn bộ câu lệnh trong tệp đã bị
comment), nên đừng nạp nó rồi chờ có tài khoản.

```bash
sudo selliotech-tao-admin
```

Lệnh hỏi lần lượt đúng ba ô của màn hình đăng nhập rồi dựng đủ bộ: bốn vai trò RBAC
(nếu bảng `roles` còn trống), cửa hàng trong bảng `tenants` kèm một chi nhánh mặc
định, và tài khoản `super_admin` với mật khẩu đã băm bcrypt.

| Ô | Là gì | Ví dụ |
|---|---|---|
| Mã cửa hàng | `tenants.code` — chuỗi mình cấp cho khách, KHÔNG phải mã chi nhánh | `quochuy` |
| Tên đăng nhập | duy nhất trong một cửa hàng, nên shop nào cũng có `admin` của riêng mình | `admin` |
| Mật khẩu | tối thiểu 6 ký tự | |

Truyền thẳng nếu không muốn mật khẩu hiện lên màn hình lúc gõ:

```bash
sudo selliotech-tao-admin --ma-cua-hang quochuy --ten-dang-nhap admin --mat-khau '<mật khẩu>'
```

Mất mật khẩu quản trị về sau: chạy lại đúng lệnh đó kèm `--doi-mat-khau` — nó đặt
mật khẩu mới và mở khoá tài khoản, không tạo thêm dòng nào.

**Đừng dùng mật khẩu `12345678` như ở máy cá nhân.** Trên máy thật nó nằm trong mọi danh sách dò mật khẩu, và hạn mức đăng nhập (10 lượt/5 phút) chỉ làm chậm chứ không chặn được.

---

## Sao lưu tự động

Chạy **mỗi 12 giờ**, 03:00 và 15:00 giờ Việt Nam (VPS đặt `Asia/Bangkok`, +07, cùng lệch với giờ ta), do systemd timer gọi. Không cần bật gì thêm: `02-trien-khai.sh` cài sẵn ở bước 8, và lần đầu cài nó chạy luôn một lượt.

```bash
sudo selliotech-sao-luu trang-thai   # lượt gần nhất lúc nào, thành công hay không
sudo selliotech-sao-luu liet-ke      # đang giữ những bản nào
sudo selliotech-sao-luu chay         # chạy một lượt ngay
```

Mỗi lần SSH vào máy, dòng đầu màn hình cho biết tình hình:

```
  Sao lưu: 2026-08-11-1500 (4 giờ trước), đang giữ 23 bản.
```

### Chép những gì

Đúng ba thứ **không nằm trong git** — tức là mất là mất hẳn:

| Chép | Vì sao |
|---|---|
| database `selliotech` | Toàn bộ dữ liệu bán hàng |
| database `selliotech_platform` | Sổ cái nền tảng: khách hàng, thuê bao, tài khoản khu điều hành, tên miền. Dump ra tệp riêng (`nen-tang.sql.gz`) để phục hồi độc lập với dữ liệu bán hàng |
| `admin/storage/app/public` | Ảnh người bán tải lên |
| `api/.env`, `admin/.env`, `saas/.env` | `APP_KEY`, `JWT_SECRET`, `DB_PASSWORD`, khoá cổng thanh toán |

Mã nguồn, cấu hình nginx, systemd đều có trong git nên không chép lại — kéo repo về là có. `.env` thì khác: `DB_PASSWORD` đặt lại được, `JWT_SECRET` sinh lại được (chỉ tốn việc mọi người đăng nhập lại), nhưng **`APP_KEY` thì không** — mất nó là mọi thứ Laravel đã mã hoá trong database thành rác, dù database còn nguyên từng byte.

Mọi thứ nằm ở `/var/backups/selliotech/<năm-tháng-ngày-giờ>/`, quyền `700` của root: bản dump chứa mật khẩu băm và thông tin cá nhân khách hàng.

### Giữ bao nhiêu

Ba tầng chồng lên nhau, tổng khoảng **47 bản**:

| Tầng | Cứu được gì |
|---|---|
| 14 bản gần nhất (7 ngày, đủ cả hai lượt/ngày) | Sai sót vừa gây ra sáng nay |
| 1 bản/ngày, 30 ngày | Sai sót âm thầm cả tuần mới lộ |
| 1 bản/tháng, 12 tháng | "Hoá ra bảng này hỏng từ tháng Ba" |

Tầng cuối quan trọng hơn vẻ ngoài của nó: hỏng dữ liệu kiểu âm thầm thường chỉ bị phát hiện **sau khi** mọi bản ngắn hạn đã cuốn qua.

Kho ảnh gần như không đổi giữa hai lượt, nên nếu dấu vân tay trùng bản trước thì bản mới **hardlink** vào cùng một tệp — có đủ ảnh nhưng không tốn thêm byte nào. Vì thế cột `ẢNH` trong `liet-ke` trùng dung lượng nhau là chuyện bình thường, không phải nhân đôi trên đĩa.

### Phục hồi

```bash
sudo selliotech-phuc-hoi                              # xem có những bản nào
sudo selliotech-phuc-hoi 2026-08-11-1500              # database + ảnh
sudo selliotech-phuc-hoi 2026-08-11-1500 --chi-db     # chỉ database
sudo selliotech-phuc-hoi 2026-08-11-1500 --ca-env     # kèm ba tệp .env
```

Nó dừng API trước khi ghi (nạp database trong lúc API vẫn đang ghi thì kết quả là hỗn hợp hai thời điểm — tệ hơn cả hai bản gốc), **chụp lại hiện trạng** vào `truoc-khi-phuc-hoi-*` rồi mới ghi đè, và đòi gõ đúng chữ `PHUC HOI` để xác nhận. Phục hồi nhầm bản thì quay lại được từ chỗ đó.

Nếu bản sao lưu thuộc mã nguồn cũ hơn bản đang chạy, script nói ra sự lệch đó — lược đồ có thể đã đổi, chạy lại `02-trien-khai.sh` để migrate đưa về khớp.

### Ba cách hỏng đã được chặn sẵn

Sao lưu chỉ có giá trị vào đúng cái ngày cần nó, nên chỗ nào cũng phải giả định "hôm đó mới phát hiện thì đã muộn":

- **Tệp sao lưu dở dang mà trông như bình thường.** Bản mới dựng trong thư mục `.dang-tao-*`, chỉ được mang tên thật sau khi qua ba lớp kiểm: `gzip -t`, có dòng `-- Dump completed` ở cuối (mysqldump chỉ viết dòng này khi chạy trót lọt), và số bảng trong dump khớp số bảng thật trong database.
- **Có tệp nhưng không nạp lại được.** Mỗi tuần một lượt, script tự nạp bản mới nhất vào database tạm `selliotech_thu_phuc_hoi`, đối chiếu số bảng rồi xoá đi. Sai bộ ký tự hay câu lệnh cụt thì biết vào thứ Ba, còn sửa được.
- **Hỏng âm thầm suốt sáu tháng.** Thất bại được ghi vào ba chỗ: journal của systemd, tệp `TRANG-THAI.txt`, và bản tin lúc SSH vào máy. Trễ quá 26 giờ là hiện chữ đỏ.

Thêm hai chỗ nữa cố ý làm theo hướng "thà không có bản mới còn hơn mất bản cũ":

- **Cạn đĩa.** Kiểm chỗ trống trước khi dump và luôn chừa lại 500MB. Không đủ chỗ thì dọn bản quá hạn, vẫn không đủ thì **dừng và giữ nguyên bản cũ** — sao lưu làm đầy đĩa là tự tay giết máy chủ (MySQL không ghi nổi, nginx không ghi nổi log), mà đổi bản cũ đang bảo vệ dữ liệu lấy một bản mới chưa chắc tạo nổi thì lỗ vốn.
- **Dữ liệu teo đột ngột.** Bản mới nhỏ hơn 60% bản trước là hiện cảnh báo đỏ. Vẫn giữ bản đó (dữ liệu ít đi có thể là thật), nhưng im lặng thì vài vòng xoay nữa là mọi bản còn dữ liệu đều bị cuốn đi.

Dump dùng `--single-transaction`, tức là chụp ảnh nhất quán mà **không khoá bảng** — khách vẫn đặt hàng bình thường trong lúc sao lưu chạy.

### Lỗ còn lại: mất chính máy chủ

Sao lưu nằm cùng máy cứu được gần hết tai nạn hay xảy ra thật — xoá nhầm, migrate hỏng, một lỗi trong code quét sạch một bảng. Nó **không** cứu được trường hợp mất chính máy chủ: đĩa chết, VPS bị xoá, tài khoản nhà cung cấp bị khoá. Lúc đó dữ liệu và bản sao lưu ra đi cùng nhau.

Bịt bằng cách kéo về máy cá nhân — cũng không phụ thuộc dịch vụ nào, chỉ dùng `ssh` mà Windows đã có sẵn. Chạy trong **Git Bash trên máy bạn**, không phải trên máy chủ:

```bash
bash deploy/scripts/keo-ve-sao-luu.sh "D:/sao-luu-selliotech"
```

Mỗi tuần một lần là đủ. Lượt đầu tải cả kho ảnh; các lượt sau chỉ tải phần đổi (ảnh trùng dấu vân tay thì chép ngang từ bản đã có dưới đĩa), nên thường xong trong vài giây. Muốn tự động thì Task Scheduler của Windows — cú pháp ghi ở đầu tệp script.

Chỗ để những tệp này chứa dữ liệu khách hàng và toàn bộ khoá bí mật của hệ thống. Đừng đặt vào thư mục đang đồng bộ lên dịch vụ chia sẻ nào.

---

## Cập nhật về sau

```bash
cd /var/www/selliotech
sudo bash deploy/scripts/02-trien-khai.sh
```

Script kéo bản mới từ nhánh `main`, build lại, đổi binary rồi khởi động lại. Nếu build hỏng thì binary đang chạy vẫn nguyên — web không chết trong lúc bạn đi sửa.

### Khi bản cập nhật có sửa chính `02-trien-khai.sh`: chạy HAI lượt

Script tự chép mình ra `/tmp` rồi chạy bản chép, vì bước 1 `git reset --hard` ghi đè chính nó trong lúc bash còn đang đọc dở (lý do đầy đủ ghi ở đầu script). Hệ quả: **lượt chạy nào cũng dùng bản script đang có sẵn trên đĩa, không phải bản vừa kéo về.**

Nên khi commit mới có động vào script triển khai, lượt đầu chỉ kéo bản mới về, lượt hai mới thi hành nó:

```bash
sudo bash deploy/scripts/02-trien-khai.sh   # kéo script mới về
sudo bash deploy/scripts/02-trien-khai.sh   # chạy script mới
```

Đây đúng là chỗ đã vấp lúc thêm tên miền gốc. Lượt đầu kéo về `landing/` và `deploy/nginx/selliotech.store.conf`, nhưng vòng lặp cài nginx của **bản script cũ** chỉ biết ba tên miền con nên bỏ qua tệp mới. Cùng lúc đó script vẫn `rm -f /etc/nginx/sites-enabled/default`, thế là tên miền gốc rơi vào block đứng đầu bảng chữ cái — `admin.selliotech.store` — và trả về trang đăng nhập Shop Admin kèm cookie phiên của khu quản trị. Nhìn thì tưởng cấu hình sai, thật ra chỉ là chưa chạy lượt hai.

Dấu hiệu nhận ra: `ls -1 /etc/nginx/sites-enabled/` thiếu tệp mà bạn vừa thêm.

---

## Khi có sự cố

```bash
# API
sudo systemctl status selliotech-api
sudo journalctl -u selliotech-api -n 100 --no-pager

# nginx
sudo nginx -t
sudo tail -50 /var/log/nginx/order.selliotech.store.error.log

# Laravel
sudo tail -50 /var/www/selliotech/admin/storage/logs/laravel.log

# Sao lưu
sudo selliotech-sao-luu trang-thai
sudo journalctl -u selliotech-sao-luu -n 50 --no-pager
systemctl list-timers selliotech-sao-luu.timer
```

| Triệu chứng | Nguyên nhân thường gặp |
|---|---|
| Trang trắng / lỗi 500 ở Laravel | `storage/` không ghi được — chạy lại bước 6 của `02-trien-khai.sh` |
| Sửa `.env` mà không thấy đổi gì | Cache config còn giữ giá trị cũ — chạy lại `02-trien-khai.sh` |
| Đăng nhập được rồi bật ra ngay | `SESSION_SECURE_COOKIE=true` nhưng đang vào bằng `http://` |
| Đăng nhập báo sai mã cửa hàng / tên đăng nhập | Chưa tạo tài khoản, hoặc gõ nhầm mã — `sudo selliotech-tao-admin` để tạo, kèm `--doi-mat-khau` để đặt lại mật khẩu |
| Chuông thông báo không tự cập nhật | `API_PUBLIC_URL` sai, hoặc nginx đang đệm `/api/v1/events` |
| `go build` chết với "signal: killed" | Hết RAM — kiểm tra swap đã bật chưa (`free -m`) |
| Ảnh tải lên báo lỗi 413 | `client_max_body_size` và `upload_max_filesize` lệch nhau |
| SSH vào thấy chữ đỏ "SAO LƯU TRỄ" | Timer bị tắt hoặc máy vừa tắt lâu — `systemctl status selliotech-sao-luu.timer` |
| Sao lưu báo "Không đủ chỗ" | Đĩa đầy. Bản cũ vẫn được giữ nguyên; dọn chỗ rồi `sudo selliotech-sao-luu chay` |
| Sao lưu cảnh báo đỏ "chỉ bằng N% bản trước" | Dữ liệu vừa teo đột ngột — kiểm tra xem có ai xoá nhầm **trước khi** các bản cũ bị xoay vòng |

---

## Những gì bản triển khai này CHƯA có

Nói trước để không tưởng nhầm là đã xong xuôi:

- **Sao lưu mới ở mức máy chủ.** Bản chép nằm cùng đĩa với dữ liệu, nên mất cả máy là mất cả hai. Bịt bằng cách chạy `keo-ve-sao-luu.sh` hằng tuần (xem phần "Sao lưu tự động") — nhưng đó là việc chạy tay, không ai nhắc nếu bạn quên.
- **Chưa multi-tenant.** Shop Admin trên máy chủ này phục vụ **một** cửa hàng. Bán phần mềm cho khách thứ hai thì phải dựng thêm bản mới, hoặc làm phần tách cửa hàng.
- **SaaS Admin mới là khung** — đăng nhập và trang tổng quan, chưa quản lý được cửa hàng nào.
- **Chưa có giám sát.** Máy chủ chết lúc 3 giờ sáng thì không ai biết cho tới khi có người mở trang.
