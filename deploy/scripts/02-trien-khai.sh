#!/usr/bin/env bash
#
# 02 — Triển khai mã nguồn (chạy MỖI LẦN cập nhật, bằng quyền root)
#
#   Lần đầu:  tải mã về, dựng cấu hình, nạp lược đồ, bật dịch vụ.
#   Lần sau:  kéo bản mới, build lại, đổi binary, khởi động lại.
#
# Dùng:
#     sudo bash 02-trien-khai.sh
#
set -euo pipefail

REPO="https://github.com/huyhuy20040508/sass.git"
BRANCH="main"
APP_USER="selliotech"
APP_DIR="/var/www/selliotech"
GO="/usr/local/go/bin/go"

# Cache của Go phải nằm NGOÀI $APP_DIR.
#
# Mặc định nó rơi vào $HOME/.cache/go-build, mà $HOME của người dùng selliotech
# chính là $APP_DIR. Bước "Quyền thư mục" bên dưới quét `chmod 644` toàn bộ
# $APP_DIR nên gỡ luôn bit thực thi của những tệp đã biên dịch trong cache. Lần
# triển khai sau, `go run` thấy cache còn hợp lệ nên không dựng lại, rồi chết
# với "fork/exec ...: permission denied" — lỗi trỏ vào một đường dẫn băm loằng
# ngoằng, không có gì gợi ý nguyên nhân thật.
GOCACHE_DIR="/var/cache/selliotech/go-build"
GOMODCACHE_DIR="/var/cache/selliotech/go-mod"

xanh() { printf '\033[32m%s\033[0m\n' "$*"; }
vang() { printf '\033[33m%s\033[0m\n' "$*"; }
buoc() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
chet() { printf '\033[1;31mLỖI: %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -eq 0 ]] || chet "Phải chạy bằng root: sudo bash $0"
[[ -x "$GO" ]] || chet "Chưa có Go ở $GO. Chạy 01-cai-may-chu.sh trước."

# Chạy từ bản sao ngoài repo.
#
# Bước 1 bên dưới `git reset --hard`, tức là GHI ĐÈ chính tệp này trong lúc bash
# còn đang đọc dở nó. Bash đọc script theo từng đoạn nên nó sẽ thực thi một mớ
# lai giữa bản cũ và bản mới — biểu hiện là script báo lỗi bằng câu chữ của bản
# đã được sửa từ lâu, không cách nào hiểu nổi nếu không biết nguyên nhân.
#
# Bản sao đứng ngoài $APP_DIR nên git không đụng tới. Lần chạy này vẫn dùng mã
# của bản bạn vừa gọi (đúng như mọi công cụ triển khai khác); bản mới kéo về sẽ
# có hiệu lực ở lần chạy sau.
if [[ "${SELLIO_REEXEC:-}" != "1" ]]; then
    SELF="$(readlink -f "$0")"
    if [[ "$SELF" == "$APP_DIR"/* ]]; then
        BANSAO="$(mktemp /tmp/selliotech-trien-khai.XXXXXX.sh)"
        cp "$SELF" "$BANSAO"
        export SELLIO_REEXEC=1
        exec bash "$BANSAO" "$@"
    fi
fi

# ---------------------------------------------------------------------
buoc "1/10  Lấy mã nguồn"
# ---------------------------------------------------------------------
if [[ ! -d "$APP_DIR/.git" ]]; then
    # $APP_DIR đã tồn tại từ script 01, mà `git clone` đòi thư mục trống. Nên
    # clone ra chỗ tạm rồi chỉ chuyển thư mục .git vào, sau đó `reset --hard`
    # để git tự trải mã nguồn ra.
    #
    # Chỗ tạm phải nằm trong /tmp: người dùng `selliotech` không có quyền ghi
    # vào /var/www (thư mục đó thuộc root).
    TMP="$(mktemp -d)"
    chown "$APP_USER:$APP_USER" "$TMP"
    sudo -u "$APP_USER" git clone --quiet --branch "$BRANCH" "$REPO" "$TMP/repo"
    mv "$TMP/repo/.git" "$APP_DIR/"
    rm -rf "$TMP"
    chown -R "$APP_USER:$APP_USER" "$APP_DIR/.git"
fi

sudo -u "$APP_USER" git -C "$APP_DIR" fetch --quiet origin "$BRANCH"
sudo -u "$APP_USER" git -C "$APP_DIR" reset --hard --quiet "origin/$BRANCH"
# Chạy dưới $APP_USER chứ không phải root: git bản mới từ chối làm việc trên
# repo thuộc quyền người khác ("detected dubious ownership") và dừng cả script.
xanh "  đang ở $(sudo -u "$APP_USER" git -C "$APP_DIR" rev-parse --short HEAD)"

# ---------------------------------------------------------------------
buoc "2/10  Kiểm tra cấu hình"
# ---------------------------------------------------------------------
# Dừng SỚM ở đây thay vì để chạy tiếp rồi hỏng ở bước cuối: thiếu .env thì Go
# API im lặng dùng toàn giá trị mặc định (database sai, JWT_SECRET rỗng) chứ
# không báo lỗi gì.
thieu=0
for f in api/.env admin/.env saas/.env; do
    if [[ ! -f "$APP_DIR/$f" ]]; then
        vang "  THIẾU $APP_DIR/$f"
        thieu=1
    fi
done
if (( thieu )); then
    cat <<HD

Chép mẫu rồi điền:
    cp $APP_DIR/deploy/env/api.env.example   $APP_DIR/api/.env
    cp $APP_DIR/deploy/env/admin.env.example $APP_DIR/admin/.env
    cp $APP_DIR/deploy/env/saas.env.example  $APP_DIR/saas/.env
    nano $APP_DIR/api/.env      # điền DB_PASSWORD và JWT_SECRET
HD
    exit 1
fi

# Chỉ soi DÒNG KHAI BÁO, không soi comment: chính tệp mẫu có câu hướng dẫn
# "điền hai chỗ <...>", quét cả comment thì lần triển khai nào cũng bị chặn.
if grep -qE '^[A-Z_]+=.*<' "$APP_DIR/api/.env"; then
    echo "  Dòng còn chỗ trống:"
    grep -nE '^[A-Z_]+=.*<' "$APP_DIR/api/.env" | sed 's/=.*/= <chưa điền>/'
    chet "api/.env còn chỗ <...> chưa điền."
fi
if ! grep -qE '^JWT_SECRET=.{16,}' "$APP_DIR/api/.env"; then
    chet "api/.env chưa có JWT_SECRET đủ dài. Sinh bằng: openssl rand -base64 48"
fi
chown "$APP_USER:$APP_USER" "$APP_DIR"/{api,admin,saas}/.env
chmod 600 "$APP_DIR"/{api,admin,saas}/.env
xanh "  api/.env, admin/.env, saas/.env: đủ và đã khoá quyền 600"

# ---------------------------------------------------------------------
buoc "3/10  Build Go API"
# ---------------------------------------------------------------------
# Build ra tên .new rồi mới đổi chỗ: nếu build hỏng, binary đang chạy vẫn nguyên
# vẹn và trang web không chết trong lúc mình đi sửa lỗi.
cd "$APP_DIR/api"
mkdir -p "$GOCACHE_DIR" "$GOMODCACHE_DIR"
chown -R "$APP_USER:$APP_USER" /var/cache/selliotech

# Gói lệnh gọi Go vào một chỗ để build (bước này) và migrate (bước sau) dùng
# đúng cùng bộ cache và cùng biến môi trường.
gochay() {
    sudo -u "$APP_USER" env \
        HOME="$APP_DIR" \
        PATH="/usr/local/go/bin:$PATH" \
        GOCACHE="$GOCACHE_DIR" \
        GOMODCACHE="$GOMODCACHE_DIR" \
        "$GO" "$@"
}

gochay build -trimpath -ldflags="-s -w" -o api.new ./cmd/api
xanh "  build xong ($(du -h api.new | cut -f1))"

# ---------------------------------------------------------------------
buoc "4/10  Lược đồ database"
# ---------------------------------------------------------------------
# Công cụ migrate đọc DB_* từ api/.env và đối chiếu với ../database/migrations.
gochay run ./cmd/migrate chay -y
xanh "  lược đồ đã khớp database/migrations"

# Bản cài trắng thì nạp vai trò + tài khoản quản trị đầu tiên.
SO_ROLE="$(mysql -N -B -D selliotech -e 'SELECT COUNT(*) FROM roles' 2>/dev/null || echo 0)"
if [[ "$SO_ROLE" == "0" ]]; then
    mysql selliotech < "$APP_DIR/database/seed.sql"
    vang "  đã nạp seed.sql — tài khoản admin@selliotech.local / Admin@123"
    vang "  ĐỔI MẬT KHẨU NGAY sau khi đăng nhập lần đầu."
else
    xanh "  database đã có dữ liệu, bỏ qua seed"
fi

# ---------------------------------------------------------------------
buoc "5/10  Hai app Laravel"
# ---------------------------------------------------------------------
for app in admin saas; do
    cd "$APP_DIR/$app"

    # Thư mục runtime của Laravel. Git KHÔNG lưu được thư mục rỗng, mà .gitignore
    # lại loại sạch nội dung bên trong storage/ — nên bản vừa clone về không có
    # chúng. Thiếu storage/framework/views thì `artisan` chết ngay từ lệnh đầu
    # với "View path not found", còn thiếu sessions thì mọi request trả 500.
    sudo -u "$APP_USER" mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

    # --no-dev: máy thật không cần phpunit, faker, debugbar.
    sudo -u "$APP_USER" env HOME="$APP_DIR" COMPOSER_ALLOW_SUPERUSER=1 \
        composer install --no-dev --optimize-autoloader --no-interaction --quiet

    grep -qE '^APP_KEY=base64:' .env || sudo -u "$APP_USER" php artisan key:generate --force --quiet

    # Xoá cache cũ TRƯỚC khi tạo cache mới: cache config giữ nguyên giá trị .env
    # của lần deploy trước, sửa .env mà quên bước này là thay đổi không có tác dụng.
    sudo -u "$APP_USER" php artisan optimize:clear --quiet
    sudo -u "$APP_USER" php artisan config:cache --quiet
    sudo -u "$APP_USER" php artisan route:cache --quiet
    sudo -u "$APP_USER" php artisan view:cache --quiet

    xanh "  $app: composer + cache xong"
done

# Shop Admin lưu ảnh người bán tải lên vào storage/app/public; symlink này là
# thứ duy nhất khiến chúng hiện ra được ở /storage/... trên web.
cd "$APP_DIR/admin"
[[ -L public/storage ]] || sudo -u "$APP_USER" php artisan storage:link --quiet
xanh "  admin: đã có symlink public/storage"

# ---------------------------------------------------------------------
buoc "6/10  Quyền thư mục"
# ---------------------------------------------------------------------
# PHP-FPM của Selliotech chạy dưới chính người dùng `selliotech` (pool riêng ở
# bước 7), nên quyền của CHỦ SỞ HỮU là đủ để Laravel ghi vào storage/.
#
# Nhóm vẫn để www-data vì nginx đọc trực tiếp ảnh người bán tải lên qua symlink
# public/storage — nó không đi qua PHP.
chown -R "$APP_USER:$APP_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} +
find "$APP_DIR" -type f -exec chmod 644 {} +
chmod 600 "$APP_DIR"/{api,admin,saas}/.env
chmod 755 "$APP_DIR/api/api.new"

for app in admin saas; do
    for d in storage bootstrap/cache; do
        chown -R "$APP_USER:www-data" "$APP_DIR/$app/$d"
        chmod -R 775 "$APP_DIR/$app/$d"
        # g+s để tệp Laravel tạo ra về sau vẫn thuộc nhóm www-data, thay vì mỗi
        # lần deploy lại phải đi sửa quyền một lượt.
        find "$APP_DIR/$app/$d" -type d -exec chmod g+s {} +
    done
done
xanh "  đã đặt quyền cho storage/ và bootstrap/cache"

# ---------------------------------------------------------------------
buoc "7/10  nginx + systemd"
# ---------------------------------------------------------------------
# Duyệt bằng TÊN MIỀN ĐẦY ĐỦ, không ghép "$site.selliotech.store": tên miền gốc
# của trang giới thiệu không có phần đầu nào để ghép.
for site in selliotech.store api.selliotech.store app.selliotech.store admin.selliotech.store; do
    cp "$APP_DIR/deploy/nginx/$site.conf" "/etc/nginx/sites-available/$site"
    ln -sfn "/etc/nginx/sites-available/$site" "/etc/nginx/sites-enabled/$site"
done
# Trang mặc định của nginx nhận mọi tên miền chưa khai; bỏ đi để tên miền lạ
# trỏ vào máy này không thấy gì cả.
rm -f /etc/nginx/sites-enabled/default

cp "$APP_DIR/deploy/systemd/selliotech-api.service" /etc/systemd/system/
systemctl daemon-reload

# Pool PHP-FPM riêng. KHÔNG dùng pool mặc định www.conf: dự án khác trên cùng
# máy có thể đã đổi `user` của nó (trên VPS này nó đang chạy dưới `football`),
# và khi đó PHP không ghi nổi vào storage/ của Selliotech — mọi trang trả 500
# mà không để lại dòng log nào, vì thứ hỏng chính là cái ghi log.
mkdir -p /var/lib/php/sessions
chmod 1733 /var/lib/php/sessions
cp "$APP_DIR/deploy/php-fpm/selliotech.conf" /etc/php/8.3/fpm/pool.d/selliotech.conf
php-fpm8.3 -t 2>&1 | tail -1
systemctl reload php8.3-fpm
# Đợi socket hiện ra trước khi nginx kiểm cấu hình, nếu không nginx -t vẫn qua
# nhưng request đầu tiên nhận 502.
for i in $(seq 1 10); do
    [[ -S /run/php/php8.3-fpm-selliotech.sock ]] && break
    sleep 1
done
[[ -S /run/php/php8.3-fpm-selliotech.sock ]] \
    || chet "Không thấy socket php8.3-fpm-selliotech.sock. Xem: journalctl -u php8.3-fpm -n 30"
xanh "  pool PHP-FPM riêng (user=selliotech) đã chạy"

nginx -t
xanh "  cấu hình nginx hợp lệ"

# ---------------------------------------------------------------------
buoc "8/10  Sao lưu tự động"
# ---------------------------------------------------------------------
# Chép script ra /usr/local/sbin chứ không để systemd gọi thẳng tệp trong
# $APP_DIR. Bước 1 ở trên `git reset --hard` đè cả thư mục đó, và lịch sao lưu
# thì không nên phụ thuộc vào tình trạng của chính thứ nó đang bảo vệ — kể cả
# lúc ai đó lỡ tay xoá /var/www.
install -m 700 "$APP_DIR/deploy/scripts/03-sao-luu.sh"  /usr/local/sbin/selliotech-sao-luu
install -m 700 "$APP_DIR/deploy/scripts/04-phuc-hoi.sh" /usr/local/sbin/selliotech-phuc-hoi

# Bản tin hiện lúc SSH vào máy. Máy chủ này không gắn email hay dịch vụ cảnh
# báo nào, nên đây là chỗ duy nhất người quản trị chắc chắn nhìn thấy khi sao
# lưu chết âm thầm.
mkdir -p /etc/update-motd.d
install -m 755 "$APP_DIR/deploy/motd/99-selliotech-sao-luu" /etc/update-motd.d/99-selliotech-sao-luu

# Bản dump chứa mật khẩu băm, thông tin khách hàng và khoá cổng thanh toán.
install -d -m 700 -o root -g root /var/backups/selliotech

cp "$APP_DIR/deploy/systemd/selliotech-sao-luu.service" /etc/systemd/system/
cp "$APP_DIR/deploy/systemd/selliotech-sao-luu.timer"   /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now selliotech-sao-luu.timer >/dev/null 2>&1 \
    || chet "Không bật được selliotech-sao-luu.timer. Xem: systemctl status selliotech-sao-luu.timer"

# Kiểm cú pháp OnCalendar ngay tại đây. Viết sai thì timer vẫn "enabled", vẫn
# không báo lỗi gì, chỉ là không bao giờ tới giờ chạy — đúng kiểu hỏng mà sáu
# tháng sau mới lộ, vào hôm cần phục hồi.
LICH="$(sed -n 's/^OnCalendar=//p' "$APP_DIR/deploy/systemd/selliotech-sao-luu.timer" | head -1)"
systemd-analyze calendar "$LICH" >/dev/null 2>&1 \
    || chet "OnCalendar không hợp lệ trong selliotech-sao-luu.timer: $LICH"
systemctl is-active --quiet selliotech-sao-luu.timer \
    || chet "Timer sao lưu chưa chạy. Xem: systemctl status selliotech-sao-luu.timer"
xanh "  timer đã bật — $(systemd-analyze calendar "$LICH" | sed -n 's/ *Next elapse: */lượt tới /p')"

# Lần cài đầu thì chạy luôn một lượt. Khoảng trống 12 tiếng giữa lúc dựng xong
# và bản sao lưu đầu tiên rơi đúng vào lúc người ta nghịch dữ liệu nhiều nhất.
if [[ ! -f /var/backups/selliotech/TRANG-THAI.txt ]]; then
    vang "  chưa có bản nào — chạy lượt đầu tiên ngay"
    /usr/local/sbin/selliotech-sao-luu chay \
        || vang "  lượt đầu thất bại. Xem: journalctl -u selliotech-sao-luu -n 50"
fi

# ---------------------------------------------------------------------
buoc "9/10  Đổi binary và khởi động lại"
# ---------------------------------------------------------------------
mv "$APP_DIR/api/api.new" "$APP_DIR/api/api"
chown "$APP_USER:$APP_USER" "$APP_DIR/api/api"
chmod 755 "$APP_DIR/api/api"
systemctl enable selliotech-api >/dev/null 2>&1 || true
systemctl restart selliotech-api
systemctl reload nginx

# ---------------------------------------------------------------------
buoc "10/10  Kiểm tra"
# ---------------------------------------------------------------------
# Đọc cổng từ .env chứ không ghi cứng: máy chủ có thể đã có dịch vụ khác giữ
# cổng mặc định, và lúc đó kiểm nhầm cổng sẽ báo "API sống" trong khi thứ trả
# lời là ứng dụng của người khác.
PORT="$(grep -E '^APP_PORT=' "$APP_DIR/api/.env" | cut -d= -f2- | tr -d '[:space:]')"
PORT="${PORT:-8080}"

# Đợi API nghe cổng — kiểm ngay lập tức thì lần nào cũng trượt.
for i in $(seq 1 20); do
    if curl -fsS --max-time 2 "http://127.0.0.1:$PORT/api/v1/health" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

if curl -fsS --max-time 3 "http://127.0.0.1:$PORT/api/v1/health"; then
    printf '\n'
    xanh "  API sống"
else
    printf '\n'
    vang "  API KHÔNG trả lời ở cổng $PORT. Xem log: journalctl -u selliotech-api -n 50 --no-pager"
    exit 1
fi

printf '\n\033[1;32m===== TRIỂN KHAI XONG =====\033[0m\n'
cat <<'HD'

Kiểm bằng trình duyệt (còn là http:// cho tới khi bật HTTPS):
    http://selliotech.store                     <- trang giới thiệu
    http://api.selliotech.store/api/v1/health
    http://admin.selliotech.store
    http://app.selliotech.store

Bật HTTPS (chỉ chạy được sau khi DNS đã trỏ về máy này):
    sudo certbot --nginx \
        --cert-name selliotech.store \
        -d selliotech.store \
        -d www.selliotech.store \
        -d api.selliotech.store \
        -d admin.selliotech.store \
        -d app.selliotech.store \
        --redirect

Sau khi có HTTPS, sửa lại 3 tệp .env cho đúng https:// rồi chạy lại script này.

Sao lưu chạy mỗi 12 giờ, tự động:
    sudo selliotech-sao-luu trang-thai      <- xem lượt gần nhất
    sudo selliotech-phuc-hoi                <- xem có những bản nào
HD
