# ecommerce_web

Web bán hàng riêng cho từng shop, theo mô hình web bán hàng của KiotViet: mỗi shop một
địa chỉ, quản trị bằng chính tài khoản POS, đơn hàng gửi về POS huyshop qua API.

**Mỗi shop một địa chỉ.** Shop nhận diện theo tên miền đang mở (bảng `shop_domains`):

| Địa chỉ | Có khi |
|---|---|
| `<mã>.<WEB_BASE_DOMAIN>` — vd `kimhana.huyweb.selliotech.store` | Tự có khi `bin/create-shop.php` |
| Tên miền riêng của tiệm — vd `shop.kimhana.vn` | `bin/shop-domain.php kimhana shop.kimhana.vn [--chinh]`, rồi khai tên miền đó vào nginx + cấp chứng chỉ |
| Thêm `?branch_code=CN1` | QR riêng của từng chi nhánh; web nhớ chi nhánh cho lần sau |

Tên miền gốc (không phải shop nào) chỉ báo "Không tìm thấy cửa hàng".

**Quản trị** ở `<địa chỉ shop>/admin`, dùng chung tài khoản POS: mã cửa hàng (điền sẵn theo
địa chỉ, không sửa được) + tên đăng nhập + mật khẩu; chỉ người có cửa Quản lý bên POS. Web
không giữ mật khẩu: đăng nhập qua `POS_API_URL`, 5 phút hỏi lại POS một lần nên khoá tài
khoản hay cửa hàng hết hạn bên POS là web cũng đá ra. Phiên chỉ dùng được trên đúng shop đó.

Backend: route của một shop khai `shop: true` trong `src/routes.php`, route quản trị khai
`admin: true`; cả hai lấy shop qua `CurrentShop::id()`.

Giao diện là app **Next.js** (`storefront/`): trang khách dựng phía máy chủ (gọi PHP qua cổng nội bộ
của nginx, giữ Host của shop — `lib/shop-api.ts`), khu quản trị `/admin` chạy phía trình duyệt và gọi
API bằng `adminApi()`. Menu quản trị khai ở `lib/menu.ts`; màn chưa làm tự hiện trang giữ chỗ.
Thiết kế đầy đủ: `docs/thiet-ke.md`.

| Môi trường | Tên miền |
|---|---|
| Test | `*.huyweb.selliotech.store` (nginx: `/etc/nginx/sites-available/huyweb.selliotech.store`, Next: systemd `huyweb-next` cổng 3101, API nội bộ 8098, database `ecommerce_web`) |
| Prod | `*.web.selliotech.store`, database `selliotech_web` — triển khai cùng POS, xem `deploy/README.md` mục "Web bán hàng" |

```
ecommerce_web/
├── backend/            PHP thuần (không framework), PDO MySQL
│   ├── public/         index.php — cổng vào duy nhất, mọi /api/* đi qua đây
│   ├── src/Core/       Env, Db, Router, Request, Response, CurrentShop, CurrentAdmin, Secret
│   ├── src/Controllers/
│   ├── src/Services/   PosClient, PosAdmin — gọi API POS
│   ├── src/routes.php  khai đường dẫn
│   ├── bin/            create-shop.php, shop-domain.php
│   └── database/       schema.sql
├── storefront/         Next.js: trang khách (/) và quản trị (/admin)
│   ├── app/(shop)/     trang khách theo shop của tên miền
│   ├── app/admin/      đăng nhập + khung quản trị
│   ├── lib/            api, adminAuth, menu, shop-api
│   └── proxy.ts        nhớ chi nhánh từ QR vào cookie
└── docs/               thiet-ke.md (+ bản PDF)
```

## Chạy dev

```bash
# backend — cổng 8097
cd backend && cp .env.example .env   # điền DB, APP_KEY, POS_API_URL, WEB_BASE_DOMAIN
mysql ecommerce_web < database/schema.sql
php bin/create-shop.php kimhana "Kim Hana"            # mã phải trùng mã cửa hàng POS
php bin/shop-domain.php kimhana kimhana.localhost     # để mở http://kimhana.localhost:5180
php -S 127.0.0.1:8097 -t public public/index.php

# giao diện — cổng 5180; cần nginx cho /api (xem cấu hình dev huyweb)
cd storefront && npm install && npm run dev
```

## Chạy thật

`npm run build` ra bản standalone (`.next/standalone/server.js`) chạy dưới systemd. nginx chuyển `/api` về
`backend/public/index.php`, còn lại sang Next; thêm một cổng nội bộ 127.0.0.1 để Next gọi `/api` phía máy chủ.
Prod do `deploy/scripts/05-web-ban.sh` dựng mỗi lượt triển khai; thêm shop bằng `sudo selliotech-web-them-shop <mã> "<tên>"`.
