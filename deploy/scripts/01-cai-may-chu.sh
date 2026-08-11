#!/usr/bin/env bash
#
# 01 — Cài đặt máy chủ (chạy MỘT LẦN, bằng quyền root)
#
#   Ubuntu 24.04 · nginx · PHP 8.3-FPM · MySQL 8 · Go 1.25 · certbot
#
# Dùng:
#     sudo bash 01-cai-may-chu.sh
#
# Chạy lại nhiều lần được: mọi bước đều kiểm tra trước khi làm.
#
set -euo pipefail

GO_VERSION="1.25.6"
APP_USER="selliotech"
APP_DIR="/var/www/selliotech"
DB_NAME="selliotech"
DB_USER="selliotech"

xanh() { printf '\033[32m%s\033[0m\n' "$*"; }
vang() { printf '\033[33m%s\033[0m\n' "$*"; }
buoc() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

[[ $EUID -eq 0 ]] || { echo "Phải chạy bằng root: sudo bash $0"; exit 1; }

# Composer hỏi "Do not run as root — Continue [yes]?" và ĐỨNG CHỜ trả lời, kể
# cả với lệnh vô hại như `composer --version`. Trong một script chạy tự động
# thì đó là treo cứng giữa chừng. Biến này là cách chính thức để nói "biết rồi".
export COMPOSER_ALLOW_SUPERUSER=1

# ---------------------------------------------------------------------
buoc "1/8  Gói hệ thống"
# ---------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

# php8.3-gd BẮT BUỘC: ImageStore thu nhỏ và nén ảnh bằng GD. Thiếu nó thì mọi
# ảnh tải lên được lưu nguyên kích thước gốc, trang sản phẩm nặng gấp hàng chục lần.
# php8.3-intl và php8.3-bcmath là yêu cầu chung của Laravel 12.
apt-get install -y -qq \
    nginx \
    php8.3-fpm php8.3-cli php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-mysql \
    mysql-server \
    certbot python3-certbot-nginx \
    git unzip curl ca-certificates

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if [[ "$PHP_VER" != "8.3" ]]; then
    vang "CẢNH BÁO: PHP trên máy là $PHP_VER, nhưng các tệp nginx trong deploy/nginx"
    vang "          trỏ tới /run/php/php8.3-fpm.sock. Sửa lại đường dẫn socket cho khớp."
fi
xanh "  nginx, PHP $PHP_VER, MySQL, certbot: xong"

# ---------------------------------------------------------------------
buoc "2/8  Composer"
# ---------------------------------------------------------------------
if command -v composer >/dev/null 2>&1; then
    xanh "  đã có: $(composer --version | head -1)"
else
    EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    # Đối chiếu chữ ký trước khi chạy: đây là tệp PHP tải từ Internet rồi thực
    # thi bằng quyền root, tải nhầm bản bị sửa là mất máy chủ.
    [[ "$EXPECTED" == "$ACTUAL" ]] || { echo "Chữ ký bộ cài Composer không khớp, dừng."; exit 1; }
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
    xanh "  đã cài: $(composer --version | head -1)"
fi

# ---------------------------------------------------------------------
buoc "3/8  Go $GO_VERSION"
# ---------------------------------------------------------------------
# Không dùng `apt install golang-go`: Ubuntu 24.04 mới tới Go 1.22, mà go.mod
# của dự án yêu cầu 1.25 — build sẽ báo lỗi phiên bản chứ không chạy.
if [[ -x /usr/local/go/bin/go ]] && /usr/local/go/bin/go version | grep -q "go$GO_VERSION"; then
    xanh "  đã có: $(/usr/local/go/bin/go version)"
else
    TARBALL="go${GO_VERSION}.linux-amd64.tar.gz"
    curl -fsSL -o "/tmp/$TARBALL" "https://go.dev/dl/$TARBALL"
    rm -rf /usr/local/go
    tar -C /usr/local -xzf "/tmp/$TARBALL"
    rm -f "/tmp/$TARBALL"
    xanh "  đã cài: $(/usr/local/go/bin/go version)"
fi
# Cho mọi phiên đăng nhập thấy lệnh go.
printf 'export PATH=$PATH:/usr/local/go/bin\n' > /etc/profile.d/go.sh
chmod 644 /etc/profile.d/go.sh

# ---------------------------------------------------------------------
buoc "4/8  Vùng nhớ tạm (swap)"
# ---------------------------------------------------------------------
# `go build` của dự án này ngốn khoảng 1GB. VPS gói rẻ thường chỉ 1–2GB RAM,
# build giữa chừng bị nhân hệ thống giết vì hết bộ nhớ, và thông báo lỗi nhận
# được chỉ là "signal: killed" — rất khó đoán ra nguyên nhân thật.
RAM_MB="$(free -m | awk '/^Mem:/{print $2}')"
SWAP_MB="$(free -m | awk '/^Swap:/{print $2}')"
if (( RAM_MB < 2048 && SWAP_MB < 1024 )); then
    if [[ ! -f /swapfile ]]; then
        fallocate -l 2G /swapfile
        chmod 600 /swapfile
        mkswap -q /swapfile
        swapon /swapfile
        grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
        xanh "  RAM ${RAM_MB}MB — đã tạo 2GB swap"
    fi
else
    xanh "  RAM ${RAM_MB}MB, swap ${SWAP_MB}MB — không cần thêm"
fi

# ---------------------------------------------------------------------
buoc "5/8  Giới hạn tải tệp của PHP"
# ---------------------------------------------------------------------
# Giới hạn upload nay nằm trong POOL RIÊNG (deploy/php-fpm/selliotech.conf, do
# script 02 cài), không nằm ở conf.d dùng chung nữa.
#
# Lý do: conf.d áp cho MỌI pool trên máy. Máy chủ có thể đang chạy dự án khác,
# và đổi memory_limit hay max_execution_time của họ là việc mình không có quyền
# quyết định — nhất là khi họ không hề biết có ai vừa cài gì lên.
if [[ -f /etc/php/8.3/fpm/conf.d/99-selliotech.ini ]]; then
    rm -f /etc/php/8.3/fpm/conf.d/99-selliotech.ini
    vang "  đã gỡ conf.d/99-selliotech.ini của bản cũ (nay đặt trong pool riêng)"
else
    xanh "  không cần — giới hạn đặt trong pool riêng"
fi

# Nơi PHP để session của chính nó. Laravel dùng session tệp trong storage/ nên
# gần như không đụng tới, nhưng thiếu thư mục thì bất kỳ thư viện nào gọi
# session_start() cũng làm cả request chết.
mkdir -p /var/lib/php/sessions
chmod 1733 /var/lib/php/sessions

# ---------------------------------------------------------------------
buoc "6/8  Người dùng chạy ứng dụng"
# ---------------------------------------------------------------------
if id "$APP_USER" >/dev/null 2>&1; then
    xanh "  đã có người dùng $APP_USER"
else
    # Tài khoản hệ thống, không đăng nhập được — nó chỉ để chạy tiến trình API.
    adduser --system --group --home "$APP_DIR" --shell /usr/sbin/nologin "$APP_USER"
    xanh "  đã tạo người dùng $APP_USER"
fi
# php-fpm chạy dưới www-data, cần đọc mã nguồn và ghi vào storage/.
usermod -aG "$APP_USER" www-data
mkdir -p "$APP_DIR"
chown "$APP_USER:$APP_USER" "$APP_DIR"

# ---------------------------------------------------------------------
buoc "7/8  MySQL: database + tài khoản riêng"
# ---------------------------------------------------------------------
systemctl enable --now mysql >/dev/null 2>&1 || true

if mysql -N -B -e "SELECT 1 FROM mysql.user WHERE user='$DB_USER' AND host='localhost'" | grep -q 1; then
    xanh "  đã có tài khoản MySQL '$DB_USER' — giữ nguyên mật khẩu cũ"
    DB_PASS=""
else
    DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
    # Heredoc chứ không truyền qua -e: tham số dòng lệnh hiện nguyên trong
    # `ps aux`, ai đang đăng nhập cùng lúc cũng đọc được mật khẩu.
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    xanh "  đã tạo database '$DB_NAME' và tài khoản '$DB_USER'"
fi
# Database vẫn tạo kể cả khi tài khoản đã có từ trước.
mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ---------------------------------------------------------------------
buoc "8/8  Tường lửa"
# ---------------------------------------------------------------------
# MySQL cố ý KHÔNG mở ra ngoài — chỉ Go API trên cùng máy nối vào nó.
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
# sort -u vì ufw liệt kê riêng luật IPv4 và IPv6, in thô ra sẽ thấy mỗi tên hai lần.
xanh "  đang mở: $(ufw status | awk '/ALLOW/{print $1}' | sort -u | tr '\n' ' ')"

systemctl enable --now php8.3-fpm nginx >/dev/null 2>&1 || true
systemctl reload php8.3-fpm

printf '\n\033[1;32m===== XONG BƯỚC 1 =====\033[0m\n'
if [[ -n "$DB_PASS" ]]; then
    printf '\n\033[1;33mMẬT KHẨU DATABASE (chép ngay, không hiện lại lần nữa):\033[0m\n'
    printf '    DB_PASSWORD=%s\n' "$DB_PASS"
fi
cat <<'HD'

Tiếp theo:
    1. Tạo bản ghi DNS ở Hostinger (xem deploy/README.md phần "Bước 1").
    2. Chạy: sudo bash 02-trien-khai.sh
HD
