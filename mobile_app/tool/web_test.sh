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
# --pwa-strategy=none: bỏ service worker — có nó thì trình duyệt giữ bản cũ, người thử
# tải lại trang vẫn không thấy bản vừa build.
flutter build web --release \
  --pwa-strategy=none \
  --base-href "/$SLUG/app/" \
  --dart-define=API_BASE_URL=https://huyshop.selliotech.store/api/v1 \
  --no-web-resources-cdn

# Máy đã cài service worker của bản cũ: thay bằng bản tự xoá bộ đệm, tự gỡ rồi tải lại
# trang — lần mở sau là bản mới, không phải xoá dữ liệu trình duyệt.
cat > "$APP/build/web/flutter_service_worker.js" <<'JS'
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(
  caches.keys().then((ks) => Promise.all(ks.map((k) => caches.delete(k))))
    .then(() => self.registration.unregister())
    .then(() => self.clients.matchAll({ type: 'window' }))
    .then((cs) => cs.forEach((c) => c.navigate(c.url)))
));
JS

ln -sfn "$APP/build/web" "$ROOT/design/html/app"
chown -R huyshop:huyshop "$APP/build/web" 2>/dev/null || true

echo "Mở: https://huyshop.selliotech.store/$SLUG/app/index.html"
