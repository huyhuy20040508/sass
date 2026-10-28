#!/usr/bin/env bash
# =====================================================================
# Thêm (hoặc mở lại) một shop cho web bán hàng, kèm HTTPS cho địa chỉ của nó.
# 05-web-ban.sh cài script này thành /usr/local/sbin/selliotech-web-them-shop.
#
#   sudo selliotech-web-them-shop <mã cửa hàng POS> "<tên shop>"
#
# Shop có địa chỉ <mã>.web.selliotech.store; chủ tiệm vào <địa chỉ>/admin bằng
# tài khoản POS. Cần bản ghi DNS *.web → máy chủ này trước khi chạy.
#
# Chứng chỉ selliotech-web không phải wildcard (wildcard đòi xác minh DNS bằng
# tay mỗi 90 ngày) — mỗi shop thêm một địa chỉ vào chứng chỉ, certbot tự gia hạn.
# =====================================================================

set -euo pipefail

APP_USER="selliotech"
APP_DIR="/var/www/selliotech"
WEB="$APP_DIR/ecommerce_web"
BASE_DOMAIN="web.selliotech.store"
CERT="selliotech-web"
SITE="/etc/nginx/sites-available/web.selliotech.store"

xanh() { printf '\033[32m%s\033[0m\n' "$*"; }
chet() { printf '\033[1;31mLỖI: %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -eq 0 ]] || chet "Phải chạy bằng root: sudo $0 <mã> \"<tên>\""
[[ $# -eq 2 ]] || chet "Cách dùng: sudo $(basename "$0") <mã cửa hàng POS> \"<tên shop>\""
[[ -f "$WEB/backend/.env" ]] || chet "Web bán hàng chưa được dựng — chạy 02-deploy.sh trước"

MA="$(tr '[:upper:]' '[:lower:]' <<<"$1")"
sudo -u "$APP_USER" php "$WEB/backend/bin/create-shop.php" "$MA" "$2"

# Mọi địa chỉ mặc định đang có (kể cả shop cũ) vào CÙNG một chứng chỉ: certbot
# --expand thay chứng chỉ bằng đúng danh sách được đưa, thiếu tên nào là mất tên đó.
mapfile -t HOSTS < <(mysql -N -B selliotech_web -e \
    "SELECT d.host FROM shop_domains d WHERE d.host LIKE '%.$BASE_DOMAIN' ORDER BY d.id")
ARGS=()
for h in "${HOSTS[@]}"; do ARGS+=(-d "$h"); done

install -d -m 755 /var/www/letsencrypt
certbot certonly --webroot -w /var/www/letsencrypt --cert-name "$CERT" --expand \
    --non-interactive --agree-tos --register-unsafely-without-email "${ARGS[@]}" \
    || chet "Không xin được chứng chỉ. Kiểm DNS: dig +short $MA.$BASE_DOMAIN phải ra IP máy chủ này"

# Lần đầu có chứng chỉ thì chuyển nginx sang bản HTTPS.
if ! grep -q "listen 443" "$SITE" 2>/dev/null; then
    cp "$APP_DIR/deploy/nginx/web-ban/https.conf" "$SITE"
fi
nginx -t >/dev/null 2>&1 || { nginx -t; chet "nginx không hợp lệ"; }
systemctl reload nginx

xanh "Xong: https://$MA.$BASE_DOMAIN  (quản trị: https://$MA.$BASE_DOMAIN/admin)"
