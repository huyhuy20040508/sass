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
# Lần đầu: cài Node, tạo database + .env (mật khẩu và APP_KEY sinh ngẫu nhiên).
# Mỗi lượt: nạp lược đồ, build React, dựng nginx (HTTPS nếu đã có chứng chỉ).
#
# Dùng:  sudo bash deploy/scripts/05-web-ban.sh
# =====================================================================

set -euo pipefail

APP_USER="selliotech"
APP_DIR="/var/www/selliotech"
WEB="$APP_DIR/ecommerce_web"
BASE_DOMAIN="web.selliotech.store"
# KHÔNG đặt "ecommerce_web": máy chủ prod dùng chung MySQL với bản dev, và
# database dev mang đúng tên đó — trùng tên là script đổi mật khẩu rồi ghi
# thẳng vào dữ liệu dev. Đặt theo lối selliotech / selliotech_platform.
DB="selliotech_web"
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

# --- Node để build React ---------------------------------------------
# Ubuntu 24.04 có sẵn Node 18 — đủ cho Vite 5.
if ! command -v npm >/dev/null; then
    vang "  chưa có Node — cài nodejs + npm"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nodejs npm >/dev/null
fi

# --- Database + .env (chỉ lần đầu) -------------------------------------
ENV="$WEB/backend/.env"
if [[ ! -f "$ENV" ]]; then
    vang "  chưa có backend/.env — tạo database $DB và sinh .env"
    MATKHAU="$(openssl rand -hex 24)"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB'@'localhost' IDENTIFIED BY '$MATKHAU';
ALTER USER '$DB'@'localhost' IDENTIFIED BY '$MATKHAU';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DB'@'localhost';
FLUSH PRIVILEGES;
SQL
    # Gọi thẳng Go API ở cổng nội bộ, không vòng ra Internet.
    CONG="$(sed -n 's/^APP_PORT=//p' "$APP_DIR/api/.env" | head -1 | tr -d '[:space:]')"
    cat > "$ENV" <<ENV
APP_DEBUG=false
APP_TIMEZONE=Asia/Ho_Chi_Minh

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB
DB_USERNAME=$DB
DB_PASSWORD=$MATKHAU

WEB_BASE_DOMAIN=$BASE_DOMAIN
# Mất khoá này thì mọi phiên quản trị web phải đăng nhập lại — không mất dữ liệu.
APP_KEY=$(openssl rand -base64 32)
POS_API_URL=http://127.0.0.1:${CONG:-8090}/api/v1
ENV
fi
chown "$APP_USER:$APP_USER" "$ENV"
chmod 600 "$ENV"

# Lược đồ chỉ có CREATE TABLE IF NOT EXISTS nên nạp lại mỗi lượt không hại gì.
mysql "$DB" < "$WEB/backend/database/schema.sql"
xanh "  database $DB: lược đồ đã nạp"

# --- Build React -------------------------------------------------------
mkdir -p "$NPM_CACHE"
chown -R "$APP_USER:$APP_USER" "$NPM_CACHE" "$WEB/frontend"
# `npm ci` mỗi lượt chứ không giữ node_modules: bước "Quyền thư mục" của
# 02-deploy.sh gỡ bit thực thi của mọi tệp, lần build sau sẽ chết ở chính vite.
cd "$WEB/frontend"
sudo -u "$APP_USER" env HOME="$APP_DIR" npm_config_cache="$NPM_CACHE" npm ci --no-audit --no-fund --loglevel=error
sudo -u "$APP_USER" env HOME="$APP_DIR" npm_config_cache="$NPM_CACHE" npm run build --silent
[[ -f "$WEB/frontend/dist/index.html" ]] || chet "build xong mà không có dist/index.html"
xanh "  frontend đã build"

# --- nginx -------------------------------------------------------------
install -d -m 755 /var/www/letsencrypt
cp "$APP_DIR/deploy/nginx/web-ban/app.conf" /etc/nginx/snippets/selliotech-web-ban.conf
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
    if [[ -s "$CU" ]]; then cp "$CU" "$SITE"; else rm -f "$SITE" /etc/nginx/sites-enabled/web.selliotech.store; fi
    nginx -t
    chet "cấu hình nginx web bán hàng không hợp lệ — đã trả lại bản trước"
fi
rm -f "$CU"
xanh "  nginx: $KIEU"

install -m 700 "$APP_DIR/deploy/scripts/web-ban-them-shop.sh" /usr/local/sbin/selliotech-web-them-shop
