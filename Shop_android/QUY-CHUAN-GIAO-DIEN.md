# Quy chuẩn giao diện — Selliotech Android

Đọc tệp này trước khi dựng màn mới. Mã nguồn của chuẩn nằm ở
`app/src/main/java/com/selliotech/app/ui/`:

| Tệp | Giữ gì |
|---|---|
| `theme/Color.kt` | Toàn bộ màu |
| `theme/Type.kt` | Bảy cỡ chữ |
| `theme/Kich.kt` | Khoảng cách, bo góc, chiều cao chạm |
| `theme/Theme.kt` | Bảng màu sáng/tối, màu phụ |
| `ThanhPhan.kt` | Nút, thẻ, huy hiệu, định dạng tiền |

---

## Bốn quy tắc cứng

**1. Không gõ giá trị thẳng vào tệp màn hình.**
Cấm `Color(0xFF...)`, cấm `13.dp`, cấm `fontSize = 15.sp`. Cần gì chưa có thì
thêm vào tệp token rồi mới dùng. Lý do: một hôm đổi tông màu là đổi ở một chỗ,
không phải lục bốn mươi tệp.

**2. Không có màu động theo hình nền máy.**
`dynamicColor` là mặc định của bản mẫu Android Studio và đã bị gỡ. App bán hàng
mang màu thương hiệu, không đổi tông theo ảnh nền của từng người. Chưa kể mỗi
máy một màu thì chụp màn hình gửi nhau không ai đối chiếu được.

**3. Nút bỏ đi luôn đỏ, nút đồng ý luôn xanh.**
Quy tắc chung của cả hệ thống, không riêng app. Đảo một lần là người dùng bấm
nhầm ở mọi lần sau. Dùng `NutNguyHiem` cho xoá/huỷ/đăng xuất.

**4. Chỉ đặt nút cho chức năng đã có thật.**
Bày sẵn lưới ô "Báo cáo · Kho · Khách hàng" cho đẹp rồi bấm vào không ra gì là
cách nhanh nhất để người dùng hết tin vào app.

---

## Màu

### Lấy nguyên bảng màu của web

| Tên | Mã | Dùng ở đâu |
|---|---|---|
| `Xanh` | `#1890ff` | Hành động chính — đúng `--au-primary` bên web |
| `XanhSang` / `XanhDam` | `#40a9ff` / `#0e7ce0` | Chuyển sắc trên nút, trạng thái nhấn |
| `Do` | `#ff4d4f` | Lỗi |
| `Cam` | `#faad14` | Cảnh báo |
| `Luc` | `#52c41a` | Xong |
| `ChuSang` | `#262626` | Chữ chính |

App và web **phải cùng một bảng màu**: cùng một người sáng dùng web ở quầy,
chiều cầm điện thoại đi kiểm kho. Hai tông màu khác nhau là hai phần mềm khác
nhau trong đầu họ.

Tím Sellio `#3A1266` và vàng `#FFC20F` **chỉ dùng cho logo và icon app**, không
phải màu hành động. Tô cả nút màu vàng là mất hết trọng lượng.

### Nền và mặt

Nền màn hình **hơi xám** (`#f5f6fa`, đúng của web), thẻ **trắng**. Trắng trên trắng là mất
lớp — đây là điểm khác nhau rõ nhất giữa app trông rẻ tiền và app trông chỉn chu.

### Trạng thái

`Luc` xong · `Cam` chờ · `Do` hỏng · `Lam` thông tin. Mỗi màu đi kèm một nền
nhạt cùng tông (`LucNen`, `CamNen`…) cho huy hiệu.

Material 3 chỉ có một ô `error`, không đủ cho app bán hàng — "xong", "chờ",
"hỏng" là ba thứ khác nhau. Nên có thêm `MauPhu`, gọi qua `mauPhu.luc`.

### Chế độ tối

Bắt buộc chạy được cả hai. Web không có chế độ tối nên phần này tự dựng: giữ
đúng xanh chủ đạo, chỉ hạ nền và nâng chữ.

Ngoại lệ duy nhất: **màn đăng nhập luôn sáng** vì thẻ trắng nằm trên ảnh nền.

---

## Chữ — bảy cỡ, không hơn

| Vai trò | Cỡ / dòng | Đậm |
|---|---|---|
| Số tiền lớn | 34 / 42 | Bold |
| Tiêu đề màn | 22 / 30 | Bold |
| Tiêu đề mục | 17 / 24 | SemiBold |
| Nội dung | 15 / 22 | Regular |
| Nội dung phụ | 13 / 19 | Regular |
| Nhãn, huy hiệu | 12 / 16 | Medium |
| Chữ trên nút | 16 / 20 | SemiBold |

Mỗi cỡ thêm vào là một lần người sau phải đoán nên dùng cái nào.

Chiều cao dòng rộng hơn thông lệ một nhịp vì **chữ Việt có dấu**: dấu ngã trên
chữ hoa mà dòng chật là chạm vào dòng trên.

---

## Khoảng cách và bo góc

Mọi khoảng cách là **bội của 4**: `Cach.Sat` 4 · `Gan` 8 · `Vua` 12 ·
`Chuan` 16 · `Rong` 20 · `Khoi` 24 · `Lon` 32.

Bo góc: chip 8 · ô nhập 12 · nút 14 · **thẻ 18** · tấm trượt 24.

Bo rộng là chỗ khác nhau rõ nhất giữa app 2026 và app 2018. Thẻ bo 4dp trông
già ngay lập tức.

**Bóng đổ gần như không dùng** (`shadowElevation = 1.dp`). Tách lớp bằng màu nền
và bo góc. Bóng dày là lối 2015.

---

## Chiều cao chạm

| | |
|---|---|
| Tối thiểu mọi thứ bấm được | **48dp** |
| Nút chính | **56dp** |
| Ô nhập | 52dp |

Nút chính cao hơn mức tối thiểu vì người bán hàng bấm khi một tay đang cầm hàng.

---

## Tiền

Luôn qua `tienVN()`: `1.250.000 ₫` — dấu chấm ngăn nghìn theo lối Việt.

**Không bao giờ rút gọn thành "1,2tr"** trên màn có thao tác được. Người ta đang
đếm tiền mặt trong tay, lệch một trăm nghìn là lệch thật.

---

## Thành phần dùng chung

| Gọi | Việc |
|---|---|
| `The { }` | Thẻ trắng bo 18, đệm 20 |
| `NutChinh(...)` | Nút chính, cao 56, có sẵn trạng thái đang chạy |
| `NutPhu(...)` | Nút viền, nhường nút chính |
| `NutNguyHiem(...)` | Nút đỏ cho xoá / huỷ / đăng xuất |
| `Huy(chu, Sac.LUC)` | Huy hiệu trạng thái |
| `TieuDeMuc(chu)` | Tiêu đề mục, đặt **trên** thẻ chứ không nhét vào trong |
| `HangThongTin(nhan, gia)` | Dòng nhãn — giá trị |
| `SoTien(so)` | Số tiền lớn |

Dựng nút hay thẻ tại chỗ trong tệp màn hình là cách một app trôi dần thành mười
kiểu nút khác nhau. Cần kiểu mới thì thêm vào `ThanhPhan.kt`.

---

## Chuyển động

Bốn thứ, dùng đúng chỗ:

| Việc | Cách |
|---|---|
| Khối lớn vào màn | Lò xo (`spring`), không phải `tween` — chạm vào thấy có sức nặng |
| Nhiều khối cùng vào | Vào **lần lượt**, cách nhau 60–80ms, không dội cùng lúc |
| Đổi màu nền ô, đổi màu biểu tượng | `tween(220)` |
| Bấm nút chính | Thụt còn 0.97 + rung nhẹ |

Nút chính mang **quầng sáng cùng màu với nó** (`spotColor = màu nút`), không
phải bóng đen: nút như đang phát sáng xuống nền chứ không phải một khối chặn
sáng.

Ảnh nền màn đăng nhập phóng chậm 8% trong 22 giây rồi lùi lại. Chậm tới mức
không ai bắt được nó đang động, chỉ thấy màn hình "sống" — nhanh hơn là thành
hoạt hình rẻ tiền.

---

## Còn thiếu

Ghi ra đây để không ai tưởng là đã đủ:

- **Phông chữ riêng.** Đang dùng phông hệ thống. Muốn lên hẳn một bậc thì nhúng
  Be Vietnam Pro hoặc Inter — cả hai có dấu tiếng Việt tử tế. Tốn ~300KB.
- **Thanh dưới, tấm trượt, trạng thái rỗng, khung xương lúc đang tải.** Chưa có
  thành phần chuẩn, dựng tới đâu thêm tới đó.
