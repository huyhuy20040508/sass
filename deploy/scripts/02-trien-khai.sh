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

xanh() { printf '\033[32m%s\033[0m\n' "$*"; }
vang() { printf '\033[33m%s\033[0m\n' "$*"; }
buoc() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
chet() { printf '\033[1;31mLỖI: %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -eq 0 ]] || chet "Phải chạy bằng root: sudo bash $0"
[[ -x "$GO" ]] || chet "Chưa có Go ở $GO. Chạy 01-cai-may-chu.sh trước."

# ---------------------------------------------------------------------
buoc "1/9  Lấy mã nguồn"
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
buoc "2/9  Kiểm tra cấu hình"
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
buoc "3/9  Build Go API"
# ---------------------------------------------------------------------
# Build ra tên .new rồi mới đổi chỗ: nếu build hỏng, binary đang chạy vẫn nguyên
# vẹn và trang web không chết trong lúc mình đi sửa lỗi.
cd "$APP_DIR/api"
sudo -u "$APP_USER" env HOME="$APP_DIR" PATH="/usr/local/go/bin:$PATH" \
    "$GO" build -trimpath -ldflags="-s -w" -o api.new ./cmd/api
xanh "  build xong ($(du -h api.new | cut -f1))"

# ---------------------------------------------------------------------
buoc "4/9  Lược đồ database"
# ---------------------------------------------------------------------
# Công cụ migrate đọc DB_* từ api/.env và đối chiếu với ../database/migrations.
sudo -u "$APP_USER" env HOME="$APP_DIR" PATH="/usr/local/go/bin:$PATH" \
    "$GO" run ./cmd/migrate chay -y
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
buoc "5/9  Hai app Laravel"
# ---------------------------------------------------------------------
for app in admin saas; do
    cd "$APP_DIR/$app"

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
buoc "6/9  Quyền thư mục"
# ---------------------------------------------------------------------
# Mã nguồn thuộc về selliotech; php-fpm (www-data) chỉ cần ĐỌC. Riêng storage
# và bootstrap/cache thì phải ghi được — đó là chỗ Laravel để session, log và
# view đã biên dịch.
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
buoc "7/9  nginx + systemd"
# ---------------------------------------------------------------------
for site in api app admin; do
    cp "$APP_DIR/deploy/nginx/$site.selliotech.store.conf" "/etc/nginx/sites-available/$site.selliotech.store"
    ln -sfn "/etc/nginx/sites-available/$site.selliotech.store" "/etc/nginx/sites-enabled/$site.selliotech.store"
done
# Trang mặc định của nginx nhận mọi tên miền chưa khai; bỏ đi để tên miền lạ
# trỏ vào máy này không thấy gì cả.
rm -f /etc/nginx/sites-enabled/default

cp "$APP_DIR/deploy/systemd/selliotech-api.service" /etc/systemd/system/
systemctl daemon-reload

nginx -t
xanh "  cấu hình nginx hợp lệ"

# ---------------------------------------------------------------------
buoc "8/9  Đổi binary và khởi động lại"
# ---------------------------------------------------------------------
mv "$APP_DIR/api/api.new" "$APP_DIR/api/api"
chown "$APP_USER:$APP_USER" "$APP_DIR/api/api"
chmod 755 "$APP_DIR/api/api"
systemctl enable selliotech-api >/dev/null 2>&1 || true
systemctl restart selliotech-api
systemctl reload nginx

# ---------------------------------------------------------------------
buoc "9/9  Kiểm tra"
# ---------------------------------------------------------------------
# Đợi API nghe cổng — kiểm ngay lập tức thì lần nào cũng trượt.
for i in $(seq 1 20); do
    if curl -fsS --max-time 2 http://127.0.0.1:8080/api/v1/health >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

if curl -fsS --max-time 3 http://127.0.0.1:8080/api/v1/health; then
    printf '\n'
    xanh "  API sống"
else
    printf '\n'
    vang "  API KHÔNG trả lời. Xem log: journalctl -u selliotech-api -n 50 --no-pager"
    exit 1
fi

printf '\n\033[1;32m===== TRIỂN KHAI XONG =====\033[0m\n'
cat <<'HD'

Kiểm bằng trình duyệt (còn là http:// cho tới khi bật HTTPS):
    http://api.selliotech.store/api/v1/health
    http://admin.selliotech.store
    http://app.selliotech.store

Bật HTTPS (chỉ chạy được sau khi DNS đã trỏ về máy này):
    sudo certbot --nginx \
        -d api.selliotech.store \
        -d admin.selliotech.store \
        -d app.selliotech.store

Sau khi có HTTPS, sửa lại 3 tệp .env cho đúng https:// rồi chạy lại script này.
HD
