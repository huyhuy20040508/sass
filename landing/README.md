# Trang giới thiệu Sellio

Trang tĩnh giới thiệu phần mềm, dành cho tên miền gốc `selliotech.store` — chỗ mà
[`deploy/README.md`](../deploy/README.md) đã để dành sẵn. Không có bước build, không phụ thuộc
framework: mở thẳng `index.html` bằng trình duyệt là xem được.

```
landing/
├── index.html   nội dung trang
├── styles.css   toàn bộ giao diện
├── main.js      chỉ xử lý form đăng ký dùng thử
└── assets/      logo và favicon
```

## Ý tưởng giao diện

Phần mềm này in ra giấy: đơn hàng, tem dán hộp, phiếu thu, phiếu tổng kết ngày. Nên cả trang là
mặt quầy màu tím, còn thứ gì do phần mềm sinh ra thì hiện dưới dạng giấy in nhiệt. Mép răng cưa
của giấy làm bằng CSS mask (lớp `.giay`), không dùng ảnh.

Đầu trang và cuối trang là cùng một cỗ máy, chạy hai chiều:

- **Đầu trang** máy *nhả* phiếu tổng kết ngày ra — hoạt ảnh chạy khi tải trang.
- **Cuối trang** bạn điền vào tờ phiếu đăng ký rồi bấm gửi: máy *kéo* tờ phiếu vào khe, đèn nháy,
  rồi *in* biên nhận có mã phiếu trả lại. Khay co giãn theo nên trang không giật.

Trong lúc điền, mỗi dòng có nét mực tím chạy từ trái sang khi con trỏ nhảy vào và một dấu tích
đóng xuống khi dòng đã đúng; dòng nào sai thì rung một cái và chuyển đỏ. Bật "giảm chuyển động"
trong hệ điều hành thì mọi hoạt ảnh tắt hẳn, các bước vẫn chạy đủ.

Màu và chữ khai báo ở đầu `styles.css` dưới dạng biến, sửa một chỗ là đổi cả trang.

## Còn phải điền số thật trước khi phát hành

| Chỗ | Đang là | Cần |
|---|---|---|
| `main.js` → `ENDPOINT` | chuỗi rỗng | địa chỉ API nhận đăng ký; để rỗng thì form chỉ báo "đã nhận" mà không gửi đi đâu |
| Giá ba gói trong `index.html` | 199.000đ / 499.000đ / Liên hệ | giá bán thật |
| Số điện thoại | `0900 000 000` (ở chân trang và trong `main.js`) | số hotline thật |
| Email | `hello@selliotech.store` | hộp thư thật |
| Tên cửa hàng mẫu trên phiếu | Thời trang Minh Anh | giữ nguyên cũng được, đây là ví dụ |

Phần nội dung tính năng lấy đúng theo những gì Shop Admin đang có, không hứa thứ chưa làm.
Riêng mục "chuỗi nhiều cửa hàng" trong bảng giá là hướng đang làm — bỏ gói đó đi nếu chưa muốn bán.

## Đưa lên máy chủ

Trang tĩnh nên chỉ cần một `server` block trỏ `root` vào thư mục này:

```nginx
server {
    server_name selliotech.store www.selliotech.store;
    root /var/www/selliotech/landing;
    index index.html;
    location / { try_files $uri $uri/ =404; }
}
```

Rồi bật HTTPS bằng certbot như ba tên miền còn lại, xem [`deploy/README.md`](../deploy/README.md).
