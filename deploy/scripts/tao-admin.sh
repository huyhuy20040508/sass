#!/usr/bin/env bash
#
# tao-admin — tạo cửa hàng + tài khoản quản trị đầu tiên trên máy chủ thật.
#
#   sudo selliotech-tao-admin                                  # hỏi từng ô
#   sudo selliotech-tao-admin --ma-cua-hang quochuy --ten-dang-nhap admin --mat-khau '...'
#   sudo selliotech-tao-admin --ma-cua-hang quochuy --ten-dang-nhap admin --doi-mat-khau
#
# Chỉ là lớp vỏ mỏng: toàn bộ việc thật nằm ở api/cmd/tao-admin (Go), vì mật khẩu
# phải băm bằng đúng bcrypt mà API dùng để so khớp — làm bằng SQL tay là chỗ dễ
# tạo ra một dòng users không bao giờ đăng nhập được.
#
# Vỏ này lo hai thứ mà lệnh Go không tự biết trên máy chủ: chạy dưới danh nghĩa
# người dùng selliotech (chứ không phải root, nếu không cache Go và tệp tạm sẽ
# thuộc về root và lần triển khai sau gãy) và trỏ đúng bộ cache mà
# 02-trien-khai.sh đã dựng.
#
set -euo pipefail

APP_USER="selliotech"
APP_DIR="/var/www/selliotech"
GO="/usr/local/go/bin/go"
GOCACHE_DIR="/var/cache/selliotech/go-build"
GOMODCACHE_DIR="/var/cache/selliotech/go-mod"

chet() { printf '\033[1;31mLỖI: %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -eq 0 ]] || chet "Phải chạy bằng root: sudo $0 $*"
[[ -x "$GO" ]] || chet "Chưa có Go ở $GO. Chạy 01-cai-may-chu.sh trước."
[[ -f "$APP_DIR/api/.env" ]] || chet "Chưa có $APP_DIR/api/.env — chạy 02-trien-khai.sh trước."

cd "$APP_DIR/api"

# Lệnh Go đọc DB_* từ api/.env, nên nó tự vào đúng database của máy này.
exec sudo -u "$APP_USER" env \
    HOME="$APP_DIR" \
    PATH="/usr/local/go/bin:$PATH" \
    GOCACHE="$GOCACHE_DIR" \
    GOMODCACHE="$GOMODCACHE_DIR" \
    "$GO" run ./cmd/tao-admin "$@"
