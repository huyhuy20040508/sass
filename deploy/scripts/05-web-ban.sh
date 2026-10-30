#!/usr/bin/env bash
# =====================================================================
# 05 — Web bán hàng (ecommerce_web): dựng / cập nhật trên máy chủ
# =====================================================================
#
# 02-deploy.sh gọi script này như một TIẾN TRÌNH RIÊNG và chỉ cảnh báo nếu nó
# hỏng: web bán hàng lỗi không được chặn lượt triển khai POS. (Gọi hàm trong
# `|| vang` thì bash tắt `set -e` cho cả hàm — bước hỏng vẫn chạy tiếp như
# không có gì, nên mới tách thành tệp riêng.)
#
# Lần đầu: tạo database + .env (mật khẩu và APP_KEY sinh ngẫu nhiên).
# Mỗi lượt: nạp lược đồ, build Next.js + khởi động lại dịch vụ, dựng nginx (HTTPS nếu đã có chứng chỉ).
#
# Dùng:  sudo bash deploy/scripts/05-web-ban.sh
# =====================================================================

set -euo pipefail

APP_USER="selliotech"
APP_DIR="/var/www/selliotech"
WEB="$APP_DIR/ecommerce_web"
BASE_DOMAIN="web.selliotech.store"
# Web KHÔNG có database riêng: đọc thẳng DB bán hàng của POS, và dữ liệu riêng của web nằm ở
# các bảng web_ trong chính DB đó (migration của POS tạo). Tên DB theo đúng 01/03/04-*.sh.
DB_POS="selliotech"
DB_NEN_TANG="selliotech_platform"
# Tài khoản MySQL của web, KHÁC user 'ecw' của bản dev: prod và dev chung một máy MySQL, tài
# khoản web dev không được với tới DB prod.
DB_USER="ecw_prod"
# Mật khẩu nằm ngoài git và ngoài $APP_DIR: .env bị dựng lại mỗi lượt, mất mật khẩu là web chết.
MK_FILE="/root/.config/ecw-prod-db.env"
CERT="selliotech-web"
SITE="/etc/nginx/sites-available/web.selliotech.store"
# Cache npm phải nằm NGOÀI $APP_DIR, cùng lý do với cache Go ở 02-deploy.sh:
# bước "Quyền thư mục" chmod 644 cả $APP_DIR.
NPM_CACHE="/var/cache/selliotech/npm"

xanh() { printf '\033[32m%s\033[0m\n' "$*"; }
vang() { printf '\033[33m%s\033[0m\n' "$*"; }
chet() { printf '\033[1;31mLỖI: %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -eq 0 ]] || chet "Phải chạy bằng root"
[[ -d "$WEB" ]] || { vang "  chưa có $WEB — bỏ qua"; exit 0; }

# --- Node để build Next.js ---------------------------------------------
# Next 16 cần Node >= 20.9. Không tự cài: nodejs của Ubuntu 24.04 mới là bản 18.
command -v node >/dev/null || chet "Chưa có Node.js (cần >= 20.9) — cài từ nodesource rồi chạy lại"
node -e 'const [a,b]=process.versions.node.split(".").map(Number);process.exit(a>20||(a===20&&b>=9)?0:1)' \
    || chet "Node $(node -v) quá cũ — Next.js cần >= 20.9"
NODE_BIN="$(command -v node)"

# --- Tài khoản DB + .env --------------------------------------------------
# Bảng web_ do migration của POS tạo (02-deploy.sh chạy trước script này). GRANT trên bảng
# chưa tồn tại thì MySQL báo lỗi, nên kiểm trước để thông báo rõ thay vì chết giữa bước cấp quyền.
mysql -N -e "SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema='$DB_POS' AND table_name='web_cai_dat'" | grep -qx 1 \
    || chet "DB $DB_POS chưa có bảng web_cai_dat — chạy migration của POS (02-deploy.sh) rồi quay lại"

[[ -d "$(dirname "$MK_FILE")" ]] || install -d -m 700 "$(dirname "$MK_FILE")"
if [[ -f "$MK_FILE" ]]; then
    MATKHAU="$(sed -n 's/^DB_PASSWORD=//p' "$MK_FILE" | head -1)"
    [[ -n "$MATKHAU" ]] || chet "$MK_FILE không có DB_PASSWORD"
else
    vang "  chưa có $MK_FILE — sinh mật khẩu cho $DB_USER"
    MATKHAU="$(openssl rand -hex 24)"
    printf 'DB_PASSWORD=%s\n' "$MATKHAU" > "$MK_FILE"
    chmod 600 "$MK_FILE"
    # Tài khoản có thể đã tồn tại với mật khẩu khác (dựng tay trước đó): đặt lại cho khớp tệp.
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$MATKHAU';
              ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$MATKHAU';"
fi

# Quyền lấy từ chính tệp của web, không chép lại vào đây: thêm bảng web_ mới chỉ sửa một chỗ.
QUYEN="$WEB/backend/database/quyen-db.sql"
[[ -f "$QUYEN" ]] || chet "không thấy $QUYEN"
sed -e "s/<nguoi_dung>/$DB_USER/g" -e "s/<db_pos>/$DB_POS/g" -e "s/<db_nen_tang>/$DB_NEN_TANG/g" \
    -e "s/<mat_khau>/$MATKHAU/g" "$QUYEN" | mysql
xanh "  tài khoản DB $DB_USER: quyền đã cấp"

# Dựng lại .env mỗi lượt: mọi giá trị đều suy ra được (mật khẩu ở $MK_FILE), nên không có gì
# để giữ lại — mà giữ lại thì bản .env theo lược đồ cũ sẽ nằm mãi trên máy chủ.
ENV="$WEB/backend/.env"
CONG="$(sed -n 's/^APP_PORT=//p' "$APP_DIR/api/.env" | head -1 | tr -d '[:space:]')"
cat > "$ENV" <<ENV
APP_DEBUG=false
APP_TIMEZONE=Asia/Ho_Chi_Minh

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_POS
DB_USERNAME=$DB_USER
DB_PASSWORD=$MATKHAU

PLATFORM_DB_DATABASE=$DB_NEN_TANG

# Gọi thẳng Go API ở cổng nội bộ, không vòng ra Internet.
POS_API_URL=http://127.0.0.1:${CONG:-8090}/api/v1
ENV
chown "$APP_USER:$APP_USER" "$ENV"
chmod 600 "$ENV"
xanh "  backend/.env đã dựng"

# Ảnh shop tải lên (storage/media) và bản sao menu từ POS (storage/cache). Không nằm
# trong git (02-deploy reset --hard không đụng tới), PHP-FPM chạy bằng $APP_USER ghi vào.
mkdir -p "$WEB/backend/storage/media" "$WEB/backend/storage/cache"
chown -R "$APP_USER:$APP_USER" "$WEB/backend/storage"

# --- Build Next.js -----------------------------------------------------
mkdir -p "$NPM_CACHE"
chown -R "$APP_USER:$APP_USER" "$NPM_CACHE" "$WEB/storefront"
# `npm ci` mỗi lượt chứ không giữ node_modules: bước "Quyền thư mục" của
# 02-deploy.sh gỡ bit thực thi của mọi tệp, lần build sau sẽ chết ở chính next.
cd "$WEB/storefront"
sudo -u "$APP_USER" env HOME="$APP_DIR" npm_config_cache="$NPM_CACHE" npm ci --no-audit --no-fund --loglevel=error
sudo -u "$APP_USER" env HOME="$APP_DIR" npm_config_cache="$NPM_CACHE" NEXT_TELEMETRY_DISABLED=1 npm run build --silent
[[ -f "$WEB/storefront/.next/standalone/server.js" ]] || chet "build xong mà không có .next/standalone/server.js"
xanh "  Next.js đã build"

sed "s#__NODE__#$NODE_BIN#" "$APP_DIR/deploy/systemd/selliotech-web.service" > /etc/systemd/system/selliotech-web.service
systemctl daemon-reload
systemctl enable selliotech-web >/dev/null 2>&1 || true
systemctl restart selliotech-web
for i in $(seq 1 20); do
    curl -fsS -o /dev/null --max-time 2 http://127.0.0.1:3100/ && break
    sleep 1
done
curl -fsS -o /dev/null --max-time 3 http://127.0.0.1:3100/ \
    || chet "Next.js không trả lời ở 127.0.0.1:3100. Xem: journalctl -u selliotech-web -n 50"
xanh "  dịch vụ selliotech-web đang chạy"

# --- nginx -------------------------------------------------------------
install -d -m 755 /var/www/letsencrypt
cp "$APP_DIR/deploy/nginx/web-ban/app.conf" /etc/nginx/snippets/selliotech-web-ban.conf
cp "$APP_DIR/deploy/nginx/web-ban/noi-bo.conf" /etc/nginx/sites-available/web.selliotech.store-noi-bo
ln -sfn /etc/nginx/sites-available/web.selliotech.store-noi-bo /etc/nginx/sites-enabled/web.selliotech.store-noi-bo
CU="$(mktemp)"
[[ -f "$SITE" ]] && cp "$SITE" "$CU"
if [[ -d "/etc/letsencrypt/live/$CERT" ]]; then
    cp "$APP_DIR/deploy/nginx/web-ban/https.conf" "$SITE"
    KIEU="HTTPS"
else
    cp "$APP_DIR/deploy/nginx/web-ban/http.conf" "$SITE"
    KIEU="HTTP (chưa có chứng chỉ — chạy selliotech-web-them-shop)"
fi
ln -sfn "$SITE" /etc/nginx/sites-enabled/web.selliotech.store
if ! nginx -t >/dev/null 2>&1; then
    # Trả lại bản cũ (hoặc gỡ hẳn) để lượt reload của 02-deploy.sh không kéo sập cả các site POS.
    rm -f /etc/nginx/sites-enabled/web.selliotech.store-noi-bo
    if [[ -s "$CU" ]]; then cp "$CU" "$SITE"; else rm -f "$SITE" /etc/nginx/sites-enabled/web.selliotech.store; fi
    nginx -t
    chet "cấu hình nginx web bán hàng không hợp lệ — đã trả lại bản trước"
fi
rm -f "$CU"
xanh "  nginx: $KIEU"

install -m 700 "$APP_DIR/deploy/scripts/web-ban-them-shop.sh" /usr/local/sbin/selliotech-web-them-shop
