# ecommerce_web

Web bán online cho nhiều shop trên một tên miền. Đơn hàng gửi về POS huyshop qua API.

Shop được chọn bằng link (mã shop = mã cửa hàng bên POS):

| Link | Dùng khi |
|---|---|
| `/?store_id=kimhana&branch_code=CN1` | QR riêng của từng chi nhánh |
| `/cua-hang?slug=kimhana` | Link chung của shop, chưa chọn chi nhánh |

Web nhớ shop + chi nhánh ở trình duyệt, nên lần sau vào thẳng tên miền vẫn đúng tiệm.
Backend: route của một shop khai `shop: true` trong `src/routes.php`, lấy shop qua `CurrentShop::id()`.
Frontend: link trong trang khách dùng `<StoreLink>` để giữ `store_id`.

| Môi trường | Tên miền |
|---|---|
| Test | https://huyweb.selliotech.store (nginx: `/etc/nginx/sites-available/huyweb.selliotech.store`) |
| Prod | https://web.selliotech.store (chưa dựng) |

```
ecommerce_web/
├── backend/            PHP thuần (không framework), PDO MySQL
│   ├── public/         index.php — cổng vào duy nhất, mọi /api/* đi qua đây
│   ├── src/Core/       Env, Db, Router, Request, Response, CurrentShop
│   ├── src/Controllers/
│   ├── src/Services/
│   ├── src/routes.php  khai đường dẫn
│   ├── bin/            create-shop.php
│   └── database/       schema.sql
└── frontend/           React + Vite: trang khách (/) và quản trị (/admin)
```

## Chạy dev

```bash
# backend — cổng 8097
cd backend && cp .env.example .env   # điền thông tin DB
mysql ecommerce_web < database/schema.sql
php bin/create-shop.php kimhana "Kim Hana"   # mã phải trùng mã cửa hàng POS
php -S 127.0.0.1:8097 -t public public/index.php

# frontend — cổng 5180, tự chuyển /api sang 8097
cd frontend && npm install && npm run dev
```

## Chạy thật

nginx phục vụ `frontend/dist/` (sau `npm run build`), chuyển `/api` về `backend/public/index.php`. Mọi đường khác trả `index.html` để React Router xử lý.
