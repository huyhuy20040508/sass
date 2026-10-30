# Web bán hàng (làm lại từ 30/10/2026)

Website bán hàng cho từng cửa hàng dùng POS huyshop, theo cách của kimguest:
web **đọc thẳng DB của POS** (hàng, giá, tồn theo chi nhánh), dữ liệu riêng của web nằm ngay
trong DB POS ở các bảng `web_` (như `shoptable` của kimguest), **ghi đơn qua API POS**; khách phải
**đăng nhập mới mua được**.

## Cấu trúc

```
backend/            API PHP thuần (không Composer)
  public/index.php  cửa vào, nginx chuyển mọi /api/* về đây
  src/Core/         Env, Request, Response, Router, Db (DB bán hàng POS + DB nền tảng chỉ đọc)
  src/Controllers/  HealthController — GET /api/health
  src/Services/     nghiệp vụ (chưa có)
  database/quyen-db.sql  quyền MySQL của web: SELECT DB bán hàng, ghi riêng bảng web_, đọc 5 bảng nền tảng
  .env              cấu hình máy (không vào git) — mẫu ở .env.example
storefront/         Next.js 16 (App Router) — trang khách + /admin
  app/page.tsx      trang giữ chỗ, báo API PHP, đọc DB POS, quyền ghi đúng phạm vi
  app/admin/        trang giữ chỗ của khu quản trị
  lib/api.ts        gọi API PHP từ phía máy chủ (giữ Host của shop)
```

## Chạy ở dev (máy huyshop)

- nginx `huyweb.selliotech.store`: `/api/*` → PHP-FPM pool `huyshop`; còn lại → Next ở `127.0.0.1:3101`
  (dịch vụ `huyweb-next`). Cổng nội bộ `127.0.0.1:8098` cho Next gọi PHP.
- DB: dùng chung `selliotech_dev` của POS, user `ecw` (SELECT toàn DB + ghi riêng bảng `web_`),
  và đọc DB nền tảng `selliotech_platform_dev` (tenants, tenant_domains, subscriptions, plans, plan_features).
  Bảng `web_` tạo bằng migration của POS (`database/migrations/0100_bang-web-…` ở gốc repo).
- Build và chạy lại (bằng user huyshop):

```
cd storefront && runuser -u huyshop -- npm ci && runuser -u huyshop -- npm run build
systemctl restart huyweb-next
curl -H 'Host: test.huyweb.selliotech.store' http://127.0.0.1:8098/api/health
```
