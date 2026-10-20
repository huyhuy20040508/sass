#!/usr/bin/env bash
# Build bản web của app để thử trong trình duyệt, đọc API dev (cùng dữ liệu web huyshop).
#
# Phục vụ sau link ẩn của huyshop: https://huyshop.selliotech.store/<slug>/app/index.html
# — <slug> là đường dẫn ẩn của thư mục design (/root/.config/huyshop-design-slug),
# design/ không vào git nên bản build không lên prod. Cùng tên miền với API dev
# (/api/v1 → 127.0.0.1:8091 trong nginx) nên không vướng CORS.
#
# Chạy lại sau mỗi lần sửa mã: tool/web_test.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
APP="$ROOT/mobile_app"
SLUG="$(cat /root/.config/huyshop-design-slug)"
export PATH=/opt/flutter/bin:$PATH

cd "$APP"
# --no-web-resources-cdn: đóng gói CanvasKit vào bản build, không tải từ gstatic.
flutter build web --release \
  --base-href "/$SLUG/app/" \
  --dart-define=API_BASE_URL=https://huyshop.selliotech.store/api/v1 \
  --no-web-resources-cdn

ln -sfn "$APP/build/web" "$ROOT/design/html/app"
chown -R huyshop:huyshop "$APP/build/web" 2>/dev/null || true

echo "Mở: https://huyshop.selliotech.store/$SLUG/app/index.html"
