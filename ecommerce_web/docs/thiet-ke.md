# Thiết kế web bán hàng (ecommerce_web)

Bản thiết kế để duyệt trước khi code. Mô hình tham chiếu: web bán hàng của KiotViet —
mỗi tiệm một website riêng, hàng/giá/tồn lấy từ POS, đơn đổ về POS, quản trị bằng tài khoản POS,
giao diện chọn theme rồi tự chỉnh.

## 1. Đã chốt

| Việc | Chốt | Ngày |
|---|---|---|
| Mỗi shop một địa chỉ | `<mã>.web.selliotech.store` + gắn được tên miền riêng; shop nhận theo Host | 28/10 |
| Quản trị | `<địa chỉ shop>/admin`, đăng nhập 3 ô bằng tài khoản POS (cửa Quản lý); hết hạn POS là đóng | 28/10 |
| Bán thêm | Khách nào mua mới mở web (`selliotech-web-them-shop`) | 28/10 |
| Trang khách | **Next.js** (dựng HTML phía máy chủ), theme = bộ khối React + cấu hình JSON của từng shop | 28/10 |
| Backend | Giữ **PHP thuần** làm API (shop, theme, đơn web, cầu nối POS) | 27/10 |
| Xử lý đơn | Đơn đặt trên web về thẳng màn **Bán online ở Thu ngân** (POS) — xác nhận, đóng gói, giao đều làm ở đó; admin web **không có** màn đơn hàng | 28/10 |

## 2. Kiến trúc

```
 Trình duyệt ──► nginx  *.web.selliotech.store / tên miền riêng
                  │
                  ├─ /api/*  ──► PHP-FPM  backend/public/index.php ──► MySQL selliotech_web
                  │                                         │
                  │                                         └──► POS Go API (127.0.0.1:8090)
                  │                                              khoá tích hợp của shop
                  └─ mọi đường khác ──► Next.js (127.0.0.1:3100, systemd)
                                          ├─ trang khách: dựng HTML theo theme + dữ liệu
                                          └─ /admin: trang quản trị + màn chỉnh giao diện (React)
                                          (Next gọi PHP qua 127.0.0.1, chuyển nguyên Host của shop)
```

- **Next.js không chạm database, không gọi thẳng POS.** Mọi dữ liệu đi qua PHP: một chỗ duy nhất
  giữ khoá tích hợp POS, một chỗ kiểm quyền. Next chỉ dựng giao diện và cache.
- **Thay app Vite hiện tại bằng app Next** (`ecommerce_web/storefront/`). Trang đăng nhập + khung
  admin đã làm chuyển sang thành component client trong `app/admin/`; logic giữ nguyên.
- Viết bằng **TypeScript** (khai báo khối theme cần kiểu dữ liệu rõ, sai là biết ngay lúc build).

## 3. App Next.js

```
storefront/
├── proxy.ts                 đọc Host → gắn shop cho lượt này (Next 16 gọi middleware là proxy)
├── app/
│   ├── (shop)/              trang khách — dựng theo theme của shop
│   │   ├── page.tsx                   /
│   │   ├── danh-muc/[slug]/page.tsx   /danh-muc/ao-thun
│   │   ├── san-pham/[slug]/page.tsx   /san-pham/ao-thun-trang
│   │   ├── tim-kiem/page.tsx          /tim-kiem?q=
│   │   ├── gio-hang/page.tsx
│   │   ├── thanh-toan/page.tsx
│   │   └── don-hang/[ma]/page.tsx     tra cứu đơn
│   ├── admin/               trang quản trị: đăng nhập, hàng, giao diện, cài đặt
│   ├── api/xem-truoc/       bật chế độ xem bản nháp (draft mode) sau khi kiểm phiên admin qua PHP
│   ├── api/lam-moi/         PHP gọi vào để xoá cache khi shop xuất bản / đổi hàng (có khoá bí mật)
│   ├── sitemap.ts, robots.ts   theo từng shop
├── themes/
│   ├── registry.ts          danh sách theme + khối
│   └── mac-dinh/            theme đầu tiên (xem mục 4)
└── lib/api.ts               gọi PHP phía máy chủ, kèm Host của shop
```

**Cache:** dữ liệu gắn nhãn theo shop (`shop:12`, `theme:12`, `hang:12`). Xuất bản giao diện hay
đổi hàng hiện web → PHP gọi `/api/lam-moi` → Next xoá đúng nhãn của shop đó, shop khác không ảnh hưởng.
Giá/tồn sống ngắn (~60 giây) vì lấy từ POS.

**SEO:** mỗi trang có tiêu đề, mô tả, ảnh chia sẻ (Zalo/Facebook đọc được vì HTML dựng sẵn),
dữ liệu cấu trúc sản phẩm (giá, còn hàng) cho Google, sitemap riêng từng shop.

**Ảnh:** ảnh hàng là ảnh POS đã nén khi tải lên; Next tự thu nhỏ theo màn hình (`next/image`,
chỉ cho phép nguồn ảnh của POS và kho ảnh của web). Ảnh riêng của web (banner, logo) lưu ở PHP,
mỗi shop một thư mục.

## 4. Hệ theme

### Theme đổi gì, cái gì dùng chung (chốt 28/10)

| Phần | Theme quyết định | Ghi chú |
|---|---|---|
| **Trang chủ** | ✅ bố cục + khối (banner, lưới hàng, nhóm hàng…) | chỗ khác nhau nhiều nhất giữa các theme |
| **Trang nội dung** (Giới thiệu, Chính sách, Liên hệ) | ✅ bố cục | |
| Đầu trang, chân trang | ❌ **dùng chung** | nội dung (logo, liên hệ, mạng xã hội, dòng thông báo) shop nhập ở Cài đặt → Thông tin cửa hàng; menu = nhóm hàng POS |
| Màu, font | ❌ **chung của shop**, chỉnh ở Cài đặt chung của màn **Chỉnh sửa giao diện** (như KiotViet) | đổ ra biến CSS cho mọi trang; đổi theme không mất màu của shop |
| Danh mục, chi tiết hàng, tìm kiếm, giỏ hàng, thanh toán, tra cứu đơn | ❌ **dùng chung** một bản cho mọi theme | ăn màu/font của shop + vài tuỳ chọn bật/tắt (vd hiện số đã bán) |

Vì sao: luồng mua hàng giữ một bản thì sửa lỗi, thêm cổng thanh toán, tối ưu một lần là mọi shop có;
làm thêm theme chỉ tốn phần trang chủ + trang nội dung. Trang dùng chung không lệch giao diện vì ăn chung
biến màu/font/bo góc của shop.

### Gói theme (code của mình, nằm trong repo)

```
themes/mac-dinh/
├── theme.ts          tên, ảnh minh hoạ, màu/font gợi ý (shop bấm "dùng màu của theme" mới áp)
├── sections/
│   ├── banner.tsx             ảnh/ chữ/ nút, nhiều slide
│   ├── luoi-san-pham.tsx      nguồn: nhóm hàng | hàng nổi bật | bán chạy; số cột; số dòng
│   ├── nhom-hang.tsx, anh-kem-chu.tsx, cam-nhan.tsx …
└── templates/        bố cục mặc định của từng trang (danh sách khối + giá trị mặc định)
    ├── trang-chu.json, danh-muc.json, san-pham.json
```

Mỗi khối = **component React (chạy phía máy chủ) + khai báo ô chỉnh**:

```ts
export const khai = {
  ten: 'Lưới sản phẩm',
  o: [
    { khoa: 'tieu_de', loai: 'chu', mac_dinh: 'Sản phẩm nổi bật' },
    { khoa: 'nguon', loai: 'chon', lua_chon: ['noi_bat', 'ban_chay', 'nhom_hang'] },
    { khoa: 'nhom_hang', loai: 'nhom_hang', hien_khi: { nguon: 'nhom_hang' } },
    { khoa: 'so_cot', loai: 'so', min: 2, max: 6, mac_dinh: 4 },
  ],
}
```

Loại ô có sẵn: chữ, đoạn văn, ảnh, link, màu, số, bật/tắt, chọn, nhóm hàng, sản phẩm. Màn chỉnh
giao diện **tự sinh form** từ khai báo — thêm khối mới không phải viết form.

### Phần riêng của từng shop (dữ liệu)

```jsonc
{
  "theme": "mac-dinh",
  "trang": {
    "trang-chu": [
      { "id": "a1", "khoi": "banner", "o": { "slides": [ … ] } },
      { "id": "b2", "khoi": "luoi-san-pham", "o": { "nguon": "noi_bat", "so_cot": 4 } }
    ]
  },
  "css_rieng": ""
}
```

- Lưu **bản nháp** và **bản đang chạy**; mỗi lần xuất bản giữ lại một phiên bản → quay lại được.
- Màu/font/bo góc lưu riêng khỏi dữ liệu theme (đổi theme không mất), chỉnh ở màn Trang trí cửa hàng, đổ ra **biến CSS** → đổi không cần build lại.
- Dữ liệu được **kiểm theo khai báo** ở PHP trước khi lưu (khối lạ, ô lạ, sai kiểu bị từ chối) —
  giao diện không bao giờ nhận dữ liệu rác làm vỡ trang.
- Đổi theme: giữ nội dung ô trùng tên, khối không có ở theme mới thì ẩn đi chứ không xoá.

### Màn chỉnh giao diện (`/admin/giao-dien`)

```
┌──────────────────────┬─────────────────────────────────────────┐
│ Trang: [Trang chủ ▾] │                                         │
│ ≡ Nhóm hàng nổi bật  │      trang web thật của shop             │
│ ≡ Banner        ✎ 👁 │      (khung, chế độ xem bản nháp)        │
│ ≡ Lưới sản phẩm ✎ 👁 │                                         │
│ ＋ Thêm khối          │      [💻 máy tính] [📱 điện thoại]        │
│ ─ Tuỳ chọn chung ─   │                                         │
│ Màu chủ đạo [■]      │                                         │
├──────────────────────┴─────────────────────────────────────────┤
│                          [Bỏ thay đổi] [Lưu nháp] [Xuất bản]    │
└────────────────────────────────────────────────────────────────┘
```

Sửa ô → lưu nháp (tự động, trễ ~0,5 giây) → khung xem trước tải lại phần thay đổi. Bấm vào một
vùng trong khung xem trước thì bên trái mở đúng khối đó.

### Giới hạn tuỳ biến (vì an toàn của người mua)

- Được: mọi ô khai báo, **CSS riêng** (chỉ tác động trong trang của shop), mã theo dõi đặt sẵn
  ô (Facebook Pixel, Google Analytics, chat Zalo/Messenger).
- **Không** cho chèn JavaScript/HTML tuỳ ý.
- Khách cần thiết kế riêng: mình làm thêm theme hoặc khối, gán riêng cho shop đó (dịch vụ thu phí).

## 5. Dữ liệu (database `selliotech_web`)

| Bảng | Có gì |
|---|---|
| `shops`, `shop_domains`, `admin_sessions` | ✅ đã có |
| `shop_settings` | ✅ *(28/10)* tên hiển thị, logo, liên hệ, mạng xã hội — một cục JSON, khuôn ở `Services/CaiDatShop.php`. Sau thêm: giao hàng, thanh toán, SEO, mã theo dõi. **Không** chứa màu/font |
| `pos_integrations` | ✅ khoá tích hợp POS của shop (mã hoá bằng APP_KEY), ai kết nối, lúc nào |
| `shop_themes` | ✅ *(28/10)* shop_id, theme, `nhap` + `dang_chay` (JSON), ai sửa / áp dụng lúc nào. Khuôn ở `storefront/themes/mac-dinh/theme.json`, PHP kiểm theo đó. Sau thêm: lịch sử phiên bản |
| `web_products` | lớp riêng của web gắn theo **mã hàng POS**: hiện/ẩn, thứ tự, đường dẫn, mô tả & SEO riêng cho web |
| `web_orders` | đơn đặt trên web — lưu **trước**, rồi đẩy sang POS (mục 6); giữ mã đơn POS, trạng thái, số lần thử |
| ảnh riêng | ✅ *(28/10)* không cần bảng: `backend/storage/media/<shop_id>/<băm>.png`, xem qua `/api/anh/<tên>` (cache vĩnh viễn); chỉ JPG/PNG/WEBP/GIF ≤ 2 MB, không SVG |

**Hàng hoá:** tên, ảnh, nhóm, biến thể, **giá, tồn** đọc từ POS (cache ngắn); web chỉ lưu thứ của riêng
web (`web_products`). Giá luôn khớp POS; POS chậm hay bảo trì thì trang vẫn hiện bằng cache.

## 6. Cầu nối POS

### Phía POS cần thêm (bạn sửa theo mã mình gửi)

1. **Khoá tích hợp theo cửa hàng** ✅ *(28/10, migration 0099)*: bảng `integration_keys` chỉ lưu băm;
   `GET/POST/DELETE /admin/tich-hop/web-ban-hang` (quyền `cau-hinh.sua`) xem / cấp / thu hồi. **Không
   chép dán khoá**: chủ tiệm bấm "Kết nối" ở admin web → web dùng chính phiên POS của họ xin POS cấp
   khoá, cất mã hoá; từ đó web gọi POS bằng khoá, không cần ai đăng nhập.
2. **Middleware nhận cửa hàng từ khoá** ✅ (`X-Integration-Key`, `TenantFromIntegrationKey`), đặt **trước**
   `TenantFromHost` trong `khachLa`. Khoá sai/thu hồi → 401, cửa hàng hết hạn → 403, kèm token người
   dùng → 401. Danh mục và hàng hoá (`/categories`, `/products`) đọc được ngay; qua khoá không lộ giá vốn.
3. **Bật cụm storefront cho đường tích hợp** dù `STOREFRONT_API_ENABLED` vẫn tắt với người lạ.
4. **Đặt hàng nhận chi nhánh** (`branch_code`) — `CheckoutRequest` chưa có ô này; đơn phải về đúng
   chi nhánh của QR, và tồn kho đọc theo chi nhánh.
5. Gỡ hai ô thừa từ dự án cũ trong đơn (`custom_player_name`, `custom_player_number`) khỏi đường tích hợp.
6. **Màn Bán online ở Thu ngân** (Shop Admin, khu Thu ngân — chưa có): danh sách đơn web theo chi nhánh,
   báo đơn mới tức thì, xác nhận / đóng gói / giao / huỷ, in phiếu. Trạng thái đổi ở đây thì khách tra
   cứu đơn trên web thấy ngay (web đọc qua `GET /public/orders/lookup`).

Đường dùng lại (đã có sẵn): `GET /categories`, `GET /products`, `GET /products/:slug`,
`POST /orders/quote` (đối chiếu giỏ: giá, khuyến mãi, phí ship), `POST /orders` (tiền mặt khi nhận / chuyển
khoản / PayOS / SePay), `POST /vouchers/check`, `GET /public/orders/lookup`, `GET /payments/:code`.

### Đơn hàng: lưu trước, đẩy sau

Khách bấm Đặt hàng → PHP gọi `quote` đối chiếu lại giỏ (không tin giá từ trình duyệt) → lưu `web_orders`
→ đẩy sang POS. POS trả lỗi tạm thời (bảo trì, mạng) thì đơn vẫn được nhận, một tác vụ nền thử lại
và báo ở Tổng quan / Kết nối POS của admin web; POS từ chối hẳn (hết hàng) thì báo khách ngay.
Đã sang POS rồi thì mọi việc với đơn làm ở màn Bán online của Thu ngân, web chỉ giữ bản ghi để thử lại.

## 7. Triển khai

- **Next chạy chế độ standalone** dưới systemd `selliotech-web` (prod `127.0.0.1:3100`; dev `3101`
  cho `*.huyweb`), người dùng `selliotech`.
- `05-web-ban.sh`: thêm build Next + cài/khởi động lại service; nginx đổi thành `/api` → PHP, còn lại
  → Next. Hỏng vẫn không chặn POS (như hiện nay).
- Tên miền riêng: `selliotech-web-them-ten-mien <mã> <tên miền>` — gắn vào `shop_domains`, xin chứng
  chỉ riêng, thêm vào nginx.

## 8. Lộ trình

| # | Giai đoạn | Xong khi |
|---|---|---|
| 1 | Dựng app Next thay Vite: nhận shop theo Host, chuyển đăng nhập + khung admin sang, deploy dev + prod | ✅ 28/10 trên dev (prod chờ deploy) |
| 2 | Cầu nối POS: khoá tích hợp (POS), nút Kết nối trong admin web, PHP đọc nhóm hàng/hàng | ✅ 28/10 trên dev — admin web thấy danh sách hàng của POS |
| 3a | Khung chung: đầu trang (logo, tìm kiếm, menu nhóm hàng POS 3 cấp, hotline, giỏ), chân trang, biến màu/font (đang dùng mặc định); màn Thông tin cửa hàng | ✅ 28/10 trên dev |
| 3b | Trang dùng chung đọc hàng POS: danh mục, chi tiết hàng, tìm kiếm | |
| 3c | Màn **Chỉnh sửa giao diện** theo khuôn trình trang trí KiotViet + theme `mac-dinh` (7 khối trang chủ: Banner top, Banner chính, Dịch vụ, Hàng hoá, Nhóm hàng, Giới thiệu, Banner phụ) | ✅ 28/10 trên dev — nháp tự lưu, xem trước trang thật, Áp dụng / Bỏ thay đổi |
| 3e | Theme thứ 2 **Mộc** (thời trang, mỹ phẩm, quà tặng) + Kho giao diện trong màn chỉnh: đổi mẫu vào bản nháp, chữ/ảnh khối cùng loại được mang sang | ✅ 28/10 trên dev |
| 3f | Theme thứ 3 **Mochi Pet** (thú cưng, cửa hàng dịch vụ) — chuyển từ gói mẫu pet-shop của ordersysvn; ảnh mẫu đi kèm theme (`mau:<tệp>` → `storefront/public/themes/<theme>/`), khối mới: Dải chữ chạy, Dịch vụ kèm giá, Lời mời liên hệ; dải trên ở đầu trang | ✅ 28/10 trên dev |
| 3g | Trang **Website của tôi** (`/admin/giao-dien`, như KiotViet): trang thật đang chạy trong laptop/điện thoại tự cuộn, Tổng quan lượt xem/khách theo ngày (bảng `web_truy_cap`, `web_khach_ngay`; bỏ qua máy quét, khung admin), chia sẻ + mã QR, Kho giao diện có Xem thử (`?thu_mau=` kèm mã xem trước, không đụng nháp), lối tắt thiết lập; màn chỉnh chuyển sang `/admin/giao-dien/chinh-sua` | ✅ 29/10 trên dev |
| 3d | Trang nội dung (Giới thiệu, Chính sách) theo theme; Zalo/Facebook hiện ảnh khi chia sẻ | |
| 4 | Giỏ hàng, thanh toán, đơn về POS đúng chi nhánh, tra cứu đơn; **màn Bán online ở Thu ngân** (POS) | Đặt thử một đơn, thu ngân dev nhận và xác nhận được |
| 5 | Admin web: hàng hiện web, cài đặt giao hàng/thanh toán/SEO | |
| 6 | Vận hành: tên miền riêng, mở/tắt theo gói ở SaaS Admin, lệnh tắt web | |
| 7 | Mở rộng: theme thứ 2+, blog, tài khoản khách, tích điểm | |

## 9. Còn cần bạn chốt

1. **Hàng hiện web mặc định**: mở web là hiện **tất cả** hàng đang bán của POS (chủ tiệm ẩn bớt), hay
   **không hiện gì** cho tới khi chủ tiệm chọn? — đề nghị: hiện tất cả, giống KiotViet.
2. **Tài khoản khách mua** ở giai đoạn đầu: chỉ đặt hàng không cần tài khoản (theo số điện thoại)? — đề nghị: có.
3. **Thanh toán giai đoạn 4**: tiền mặt khi nhận + chuyển khoản QR (dùng cổng SePay/PayOS chi nhánh đã nối ở POS)?
