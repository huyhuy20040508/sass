# Quy chuẩn giao diện — Selliotech Android

Đọc tệp này trước khi dựng màn mới. Mã nguồn của chuẩn nằm ở
`app/src/main/java/com/selliotech/app/ui/`:

| Tệp | Giữ gì |
|---|---|
| `theme/Color.kt` | Toàn bộ màu |
| `theme/Type.kt` | Tám cỡ chữ |
| `theme/Kich.kt` | Khoảng cách, bo góc, chiều cao chạm |
| `theme/Theme.kt` | Bảng màu sáng/tối, màu phụ |
| `ThanhPhan.kt` | Nút, thẻ, huy hiệu, vạch ưu tiên, khung xương, định dạng tiền |

Khung ngoài (mũ app + thanh điều hướng nổi) ở `KhungApp.kt`, tấm module ở
`TamUngDung.kt`.

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

### Ngọc — ngoại lệ duy nhất

| Tên | Mã | Dùng ở đâu |
|---|---|---|
| `NgocSang` | `#7FE6F4` | Chóp quả cầu trên thanh nổi |
| `Ngoc` | `#35C9E0` | Thân quả cầu, quầng sáng, ô đầu của dấu ứng dụng |
| `NgocDam` | `#17ACC6` | Mép khuất của quả cầu, icon nút tròn rời |

Ba màu này **chỉ được sống trong `KhungApp.kt`**. Chúng có mặt vì mẫu thiết kế
của thanh nổi đòi xanh ngọc, và vì thanh nổi là thứ duy nhất trong app KHÔNG
thuộc về một trang nào — nó là lớp trên, đứng ngoài mọi màn.

Nó cũng không mang nghĩa hành động: bấm một tab là ĐI chỗ khác, không phải xác
nhận hay lưu gì. Nên nó không giành vai với `Xanh` — #1890ff vẫn là màu hành
động duy nhất của cả hệ thống, y hệt bên web.

**Cấm mang ngọc ra khỏi thanh nổi.** Một cái nút màu ngọc là lập tức người dùng
có hai màu "bấm được" và phải đoán xem cái nào thật.

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

## Chữ — tám cỡ, không hơn

| Vai trò | Cỡ / dòng | Đậm |
|---|---|---|
| Số tiền lớn | 34 / 42 | Bold |
| Tiêu đề màn | 22 / 30 | Bold |
| Tiêu đề mục | 17 / 24 | SemiBold |
| Nội dung | 15 / 22 | Regular |
| Nội dung phụ | 13 / 19 | Regular |
| Nhãn, huy hiệu | 12 / 16 | Medium |
| Chữ trên nút | 16 / 20 | SemiBold |
| Chữ mào (in hoa, trên một con số) | 11 / 15 | SemiBold |

Mỗi cỡ thêm vào là một lần người sau phải đoán nên dùng cái nào. Chữ mào là cỡ
thứ tám và là cỡ cuối cùng: nó phải nhỏ hơn nhãn thì mới ra vai mào.

`Type.kt` khai **mọi** ô của Material 3, kể cả ô app chưa dùng. Bỏ trống một ô
là để nó rơi về mặc định của Material — và mặc định đó không nằm trong bảng
này. Một màn lỡ gọi `titleLarge` là tự nhiên mọc ra một cỡ chữ không ai duyệt.

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

Ngoại lệ duy nhất: **thanh điều hướng nổi** (`Noi.Bong` = 18dp). Muốn một vật
trông như đang nổi thì phải có bóng, không có cách nào khác. Đổi lại mọi thẻ
còn lại giữ đúng 1dp, và bóng của thanh mang màu `BongNoi` ngả xanh chứ không
phải đen.

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
| `VachUuTien(mau)` | Vạch màu đầu một dòng danh sách |
| `OXuong(...)` | Ô xám thế chỗ nội dung lúc đang tải |
| `ChipChon(chu, chon)` | Chip chọn một trong nhiều — dải chọn kỳ, chip trong tấm lọc |
| `ChipGo(nhan, onGo)` | Chip của một điều kiện đang lọc, bấm X là gỡ đúng điều kiện đó |
| `NutO(bieuTuong, moTa)` | Nút vuông chỉ có icon, mang được con số nhỏ ở góc |
| `DongChon(nhan, chon)` | Dòng chọn một-trong-nhiều trong tấm trượt, có dấu tích |
| `TrangRong(bieuTuong, tieuDe)` | Màn trống có hình, kèm một lối ra nếu có |
| `ngayGon(iso)` | "Hôm qua" / "3 ngày trước" / `dd/MM/yyyy` |
| `ONhap(nhan, gia)` | Ô nhập có nhãn; viền xám → xanh lúc gõ → đỏ khi sai |
| `ONhapTien(nhan, so)` | Ô tiền, chỉ nhận chữ số, tự chấm phân nhóm khi gõ |
| `ONhapAnh(anh, dangTai)` | Ô ảnh: chọn bằng bộ chọn của hệ điều hành, tải lên ngay |
| `CongTac(nhan, bat)` | Công tắc gạt, CẢ DÒNG bấm được, có câu giải thích |
| `HoiXacNhan(...)` | Hộp thoại hỏi lại trước việc không lùi được |
| `AnhVuong(duong, chuThay)` | Ô ảnh vuông, chưa có ảnh thì rơi về chữ cái đầu |
| `ONutViec(bieuTuong, nhan)` | Ô thao tác: icon vuông + nhãn nhỏ, xếp NGANG thành một hàng |
| `MucGap(nhan, tomTat)` | Mục gập lại, tóm tắt phần đang chọn ngay trên hàng tiêu đề |
| `mucThayDoi(gio, truoc)` | % thay đổi, trả `null` khi không so được |
| `HuyThayDoi(muc)` | Huy hiệu ▲▼ kèm phần trăm |

Dựng nút hay thẻ tại chỗ trong tệp màn hình là cách một app trôi dần thành mười
kiểu nút khác nhau. Cần kiểu mới thì thêm vào `ThanhPhan.kt`.

---

## Mũ trang

Mở đầu mọi màn, và **CUỘN THEO nội dung** chứ không nổi. Gọi bằng
`MuTrang("Tên màn")`; tiệm/khu/chữ cái đầu do khung rót xuống qua `LocalMu`, màn
không phải chuyền tay ba tham số mà chính nó chẳng dùng tới.

```
╭─────────────────────────╮
│ [Q] quochuy · Quản trị ⌄│   chip nhận diện — ôm sát nội dung
╰─────────────────────────╯
Tổng quan                     tên màn — 22 bold
```

**Vì sao KHÔNG nổi.** Đã thử cho mũ thành thanh kính ở đỉnh cho khớp thanh dưới
— hỏng, và hỏng theo hai cách. Một viên kính tràn hết bề ngang nằm trên đầu các
thẻ có lề 16dp là hai lưới lệch nhau ngay trước mắt; còn hai viên giống hệt
nhau kẹp trên dưới thì màn hình thành cái lồng. Mũ trang là PHẦN CỦA TRANG, nên
nó cuộn cùng trang và đứng đúng lề của trang.

Nhờ vậy thanh dưới thành lớp nổi **duy nhất**, và nghĩa của lớp đó mới rõ: cái
gì lơ lửng là chỗ đi lại, mọi thứ khác là nội dung.

**Chip trên, tên màn dưới** — đọc từ hoàn cảnh xuống chủ đề: "đang ở tiệm này,
khu này" rồi mới tới "và đây là màn Tổng quan".

**Tên màn phải to và phải có.** Thanh dưới đã bỏ hết chữ, nên không còn chỗ nào
khác nói cho người dùng biết họ đang mở cái gì.

**Chip ôm sát nội dung, không kéo hết bề ngang.** Thanh tràn mép thì mũi tên
đổi khu bị đẩy sang tận rìa phải, cách xa mấy chữ mà nó nói về — nhìn như một
nút lạc chỗ. Chip ôm sát thì mũi tên dính liền tên khu, và cả cụm là một vùng
chạm gọn.

**Đổi khu được thì chip bấm được**, có mũi tên hai chiều; chỉ một khu thì không
mũi tên, không bấm. Nút "Đổi khu làm việc" trong tab Tài khoản đã gỡ — để cả
hai nơi là hai đường tới cùng một việc, rồi sẽ có ngày lệch nhau.

**Ô chữ cái đầu giữ xanh thương hiệu**, không lấy ngọc: đó là nhận diện của
tiệm, giống hệt bên web.

---

## Thanh điều hướng nổi

Chỗ đi lại **không dán đáy**, nó nổi lên trên nội dung: một viên trắng bo tròn
hết cỡ, và một nút tròn rời bên phải cho việc chính của khu.

| | |
|---|---|
| Cao thanh và nút tròn | `Noi.Cao` 64 |
| Vòng tròn dưới tab đang chọn | `Noi.CoTruot` 48 |
| Quầng sáng của vòng tròn | `Noi.QuangTruot` 12 |
| Cách mép màn và cách đáy | `Noi.Le` 12 |

**Kính THẬT, nhìn xuyên qua được.** Cả viên đựng tab lẫn nút tròn rời dùng chung
`Modifier.matKinh(bo, lopNen)`.

Android không có sẵn phép "làm mờ thứ nằm sau lưng tôi" như bên web, nên cách
làm là: `KhungApp` ghi toàn bộ thân màn vào một `GraphicsLayer` ngay lúc nó vẽ;
tới lượt thanh nổi vẽ, nó cắt đúng mảnh của tấm ấy nằm sau lưng mình, dán vào
một lớp mang `BlurEffect`, rồi phủ lên một màng trắng mỏng. Cuộn danh sách là
thấy vệt hàng nhoè chạy qua dưới thanh.

| | |
|---|---|
| Bán kính làm mờ | `Noi.MoKinh` 20 |
| Độ đục màng kính | `DamKinh` 0.20 |
| Độ đục khi máy không làm mờ được | `DamKinhCu` 0.94 |
| Bóng | `Noi.Bong` 12 |
| Đường bao và vành sáng | `Noi.VanhKinh` 1.5 |
| Độ tươi kéo lên sau khi làm mờ | 1.7 |

**Kính MỜ, không phải kính bóng.** Bốn lớp, cố ý không có vệt loá:

1. Nền đã làm mờ, kéo độ tươi lên 1.7. Làm mờ mạnh thì màu trộn lẫn và xám đi
   trông thấy; kéo tươi lại là màu dưới thanh sống lại — cuộn qua thẻ xanh thì
   thấy hẳn vệt xanh, chứ không phải một mảng ghi nhờ nhờ. Đây là mẹo của lớp
   vật liệu kính bên iOS, và nó tách "kính thật" khỏi "một miếng mờ".
2. Màng kính.
3. Đường bao `VienKinh` chạy đều cả vòng.
4. Vành sáng trắng đè lên nửa trên của đường bao.

Đã thử thêm một lớp vệt loá cho ra kính bóng — **bỏ đi**. Loá là ánh đèn đập
vào một mặt nhẵn, mà thanh này cần đúng một việc: trong và nhoè. Thêm loá vào
là nó tranh mất chỗ với con trượt, thứ duy nhất trên thanh được phép sáng.

### Hai điều dễ làm hỏng

**Mờ VỪA, đừng quá tay.** Mức đúng là: chữ dưới thanh không đọc được nữa,
nhưng vẫn NHẬN RA đó là chữ — còn thấy nhịp dòng, còn thấy mảng đậm nhạt. Đã
thử tới 60: cả mảng dưới thanh trộn thành một màu trung bình duy nhất, và lúc
đó có làm màng trong đến mấy cũng chẳng nhìn xuyên thấy gì, vì phía dưới không
còn hình nào để mà xuyên qua. **Càng mờ càng mất cái nhìn xuyên.**

**Thanh hiện rõ nhờ ĐƯỜNG BAO, rồi mới tới màu màng — không bao giờ nhờ độ
dày.** Đường bao là một nét mảnh sắc, đậm đều cả vòng; màng thì lạnh hơn cả nền
xám lẫn thẻ trắng, và tuyệt đối không pha trắng. Bóng chỉ 12dp, đủ để tấm kính
lửng lơ chứ không đè xuống.

Đây là chỗ đã đi sai hai lần. Lần đầu màng pha trắng và rất mỏng: nằm trên thẻ
trắng thì cả cái thanh tan biến, người dùng mất luôn chỗ bấm. Lần sau chữa bằng
cách phủ dày lên: thanh hiện rõ thật, nhưng mất luôn cái nhìn xuyên — thành một
miếng nhựa đục. Cách đúng là giữ màng MỎNG (0.34, tức hai phần ba nền vẫn lọt
lên) và đẩy MÀU của nó lạnh hẳn đi.

Một tấm kính thật bao giờ cũng ngả xám hơn thứ nằm dưới nó, và chính cái lệch
tông đó mới nói cho mắt biết ở đây có một LỚP khác đang đè lên. Trong suốt và
nhìn thấy được là hai việc khác nhau, phải giải cả hai — và giải bằng hai công
cụ khác nhau: độ trong lo việc thứ nhất, màu lo việc thứ hai.

Máy dưới Android 12 (API 31) không có `BlurEffect` chạy bằng phần cứng nên phủ
gần kín — trong mà không mờ thì chữ dưới thanh lòi lên lẫn vào icon, trông như
lỗi chứ không như kính.

Hai vật phải cùng một chất thì mới ra một bộ, lệch chất là hai thứ tình cờ nằm
cạnh nhau.

Thanh dán đáy chia màn thành hai băng cứng và ăn hẳn một dải ngang suốt bề
rộng máy. Thanh nổi trả dải đó lại cho nội dung — danh sách hàng chạy tiếp
xuống dưới nó — và nói rõ bằng hình rằng chỗ đi lại KHÔNG thuộc về màn đang
xem.

**Con trượt trượt, không nhảy.** Nó đo bề ngang từng ô rồi bò sang bằng lò xo,
để mắt bám được đường đi từ tab cũ sang tab mới.

**Con trượt là QUẢ CẦU THUỶ TINH màu NGỌC**, không phải khoanh màu bẹt. Bốn
lớp: quầng toả ra ngoài (hai tầng — một loang rộng gần tan, một ôm sát mép),
thân cầu sáng lệch về trên trái, đốm ở chóp, vành sáng ôm nửa trên. Bóng đen
dưới một vật xanh làm nó trông như miếng dán — quầng phải cùng màu với nó.

**Bốn lớp đó đều để NHẸ TAY.** Quầng chỉ cần nói "cái này đang bật", không phải
làm một ngọn đèn; đốm ở chóp chỉ cần đủ để quả cầu khỏi lì như nhựa. Sáng quá
thì mắt bị con trượt kéo về suốt, mà thanh điều hướng không có việc gì đáng đòi
chú ý liên tục như vậy — nó là chỗ để LIẾC, không phải chỗ để nhìn.

**Quầng sáng vẽ NGOÀI tấm kính.** `matKinh` cắt mọi thứ theo hình viên thuốc,
nên nếu con trượt nằm trong đó thì quầng bị xén cụt bốn phía. Vì vậy `KhoiTab`
xếp ba lớp rời: tấm kính (bị cắt) → quầng sáng (tự do) → hàng icon.

**Thanh KHÔNG mang chữ** — không nhãn dưới icon, không chữ trong con trượt. Nhà
/ thùng hàng / người là ba hình ai cũng đọc được; bỏ chữ đi thì các ô giãn ra
thoáng hẳn và vòng sáng mới đủ chỗ để sáng. Tên tiệm và tên khu nằm trên mũ app.

**Nút tròn rời nằm ngoài vỏ thanh** vì nó không phải một chỗ để ĐI tới. Hai
dạng, khai bằng `NutNoi`:

| Dạng | Khu | Ruột |
|---|---|---|
| `NutNoi.UngDung` | Quản trị | Dấu bốn ô nhiều màu, mở `TamUngDung` |
| `NutNoi.Viec` | Thu ngân | Một icon, một việc — Quét mã |

Quản trị lấy ô ứng dụng vì khu này rồi sẽ có kho, khách hàng, nhà cung cấp, sổ
quỹ, báo cáo — thanh không đựng nổi, phải có cửa mở ra hết. Thu ngân lấy nút
việc vì cả ca trực họ làm đúng một việc, mở thêm một tấm nữa là thừa một cú
chạm ở chỗ đang vội nhất.

Khu nào không có gì xứng chỗ đó thì truyền `null`, đừng bịa nút cho cân đối.

**Dấu ứng dụng vẽ tay, nhiều màu, nghiêng 10°.** Mọi icon khác trong app là
CHỨC NĂNG nên đồng bộ một nét xám; cái này là CỬA VÀO chỗ chứa tất cả, phải
trông khác hẳn thì ngón tay mới tìm ra ngay. Bốn ô có chuyển sắc nhạt ở góc trên
trái, đậm dần xuống dưới phải — chỉ vừa đủ để chúng không thành bốn mảnh giấy
màu dán phẳng lên mặt kính.

**Tấm ứng dụng chỉ bày module đã chạy được.** Không có ô xám "sắp có" — bấm vào
không ra gì là cách nhanh nhất để người dùng hết tin vào app. Module đang nằm
trên thanh vẫn có mặt trong tấm: đây là danh sách ĐẦY ĐỦ, giống ngăn ứng dụng
của điện thoại, app dưới dock vẫn có trong ngăn.

**Màn cuộn phải chừa đáy.** Thanh đè lên nội dung chứ không đẩy nội dung lên,
nên mọi màn lấy `demDuoi` đặt vào Spacer cuối (hoặc `contentPadding` của
LazyColumn). Thiếu nó là dòng cuối nằm khuất vĩnh viễn.

Đỉnh màn thì lấy `demTren` — chỉ bằng thanh trạng thái của máy, vì mũ trang
cuộn theo nội dung nên nó không che gì cả.

---

## Màn Tổng quan

Bố cục trả lời ba câu, theo đúng thứ tự người ta hỏi khi mở app: *hôm nay vào
bao nhiêu, hơn hay kém mọi hôm* → *việc gì đang sai* → *cái gì đang chạy*.

| Khối | Giữ gì |
|---|---|
| Dải chọn kỳ | Hôm nay · 7 ngày · 30 ngày. Đứng trên cùng vì nó đổi nghĩa của MỌI con số bên dưới |
| Thẻ tiền | Chữ mào, con số lớn, huy hiệu tăng giảm, và dải cột theo ngày NẰM TRONG chính nó |
| Thẻ chỉ số | Bốn ô trong MỘT thẻ: Đơn · Món · Lãi gộp · Trung bình/đơn, mỗi ô kèm mức thay đổi |
| Cần xử lý | Dòng bấm được, mở đúng danh sách đã lọc sẵn |
| Bán chạy | Xếp theo tiền hàng, mỗi dòng kèm TỒN HIỆN TẠI |
| Kho | Hai con số nền, đứng cuối vì không đòi hành động |

**Dải cột nằm TRONG thẻ tiền**, không tách ra một thẻ trắng riêng. Con số và cái
dáng lên xuống của nó là một vật; tách ra là mắt phải chạy hai chặng để ghép lại
điều đáng ra đọc một lần là xong.

**Cột cuối tô trắng đặc**, các cột trước mờ đi — đó là hôm nay, chỗ cần neo mắt.
Tô đều một màu thì phải đếm từ đầu dải mới biết đâu là hôm nay.

**Ngày bằng 0 vẫn vẽ một mẩu cột thấp** (`Bieu.CotToiThieu`). Bỏ trống thì mắt
tự nối hai cột hai bên lại và đọc thành chuỗi liền, trong khi hôm đó bán 0 đồng.

**Chỉ ghi hai đầu trục ngày.** Ghi đủ ba mươi ngày thì chữ chồng lên nhau, mà
cái cần biết chỉ là dải này chạy từ đâu tới đâu.

**Kỳ trước bằng 0 thì KHÔNG vẽ huy hiệu tăng giảm.** `mucThayDoi()` trả `null`,
chỗ gọi bỏ qua. In "0%" là nói dối rằng đã đứng yên, mà thật ra là chưa từng có
số nào để so.

**Bán chạy phải kèm tồn hiện tại.** Một bảng xếp hạng trần chỉ khen quá khứ;
ghép cột tồn vào là nó chỉ ra việc của hôm nay — món chạy nhất mà còn ba cái
trong kho thì phải nhập ngay chứ không phải tuần sau.

---

## Trang danh sách

Bản web của một trang danh sách là thanh công cụ dài cộng một cái bảng mười cột.
Không bê nguyên sang được: bảng ngang phải cuộn hai chiều, còn thanh công cụ ăn
hết nửa màn trên. Màn Hàng hoá là bản mẫu, màn danh sách sau cứ theo đây.

**MỘT TRANG BÊN WEB LÀ MỘT MÀN BÊN APP.** Hàng hoá (`/products`) và Tồn kho
(`/admin/inventory`) là hai trang riêng bên web, nên ở đây cũng là hai màn riêng
— đã thử gộp một lần và hỏng: trang hàng hoá mọc thêm cột tồn thì người khai
hàng phải lội qua một cột họ không cần, còn người đi đếm kho vẫn thiếu ngưỡng
sắp hết với giá vốn. Hai trang trả lời hai câu hỏi:

| Màn | Câu hỏi | Đơn vị mỗi dòng |
|---|---|---|
| Hàng hoá | Bán món gì, giá bao nhiêu, món nào đang ẩn | Mặt hàng |
| Tồn kho | Món ấy còn mấy cái | Biến thể |

| Khối | Nằm đâu | Giữ gì |
|---|---|---|
| Mũ trang | Nền xám | Chip nhận diện, tên màn |
| Ô tìm + nút lọc | Nền xám | MỘT khối liền (`OTimLoc`), ngăn giữa bằng vạch dọc |
| Dải chip | Nền xám | Mỗi điều kiện đang bật một chip, bấm X gỡ đúng chip đó |
| Thanh đếm | **Đỉnh tấm trắng** | "N mặt hàng" bên trái, nút sắp xếp bên phải, vạch mảnh dưới chân |
| Danh sách | Tấm trắng | Cuộn dưới thanh đếm, chạy tiếp xuống dưới thanh nổi |

**Thanh đếm thuộc về TẤM TRẮNG, không phải nền xám.** Nền xám giữ phần nhận
diện và ô tìm — thứ nói về màn hình; tấm trắng giữ dữ liệu, mà số dòng với cách
sắp xếp là hai câu nói về chính đống dữ liệu ấy. Nó cũng đứng yên trong lúc
danh sách cuộn: đang lướt giữa danh sách mà muốn đổi cách sắp xếp lại phải kéo
ngược lên tận đầu là hỏng.

**Ô tìm và nút lọc là MỘT khối, không phải hai vật rời.** Trước đây là một viên
thuốc bo tròn đứng cạnh một ô vuông bo 12 — hai hình khác nhau, hai đường viền
rời, mắt đọc thành hai thứ chẳng liên quan. Chúng làm cùng một việc là thu hẹp
danh sách bên dưới, nên phải là một khối, ngăn nhau bằng vạch dọc mảnh chứ không
bằng khoảng trống. Dùng `OTimLoc` chứ đừng dựng lại.

**Viền của khối ấy NÓI TRẠNG THÁI:** xám `vien` lúc thường, xanh chủ đạo dày 1.5
lúc đang gõ, và xanh nhạt kèm nửa bên phải tô nền khi đang lọc. Đừng dùng
`vienNhat` cho khối này — nó chỉ lệch nền trang đúng một nấc, nhìn xa là cái ô
tan vào nền và trang trông như thiếu mất ô tìm.

**Con số điều kiện nằm cạnh icon lọc**, không phải chấm tròn đính góc: chấm tròn
đính vào một khối bo tròn thì hoặc bị cắt, hoặc phải thò ra ngoài mép và phá mất
đường bao.

**Tấm lọc mang theo SỐ DÒNG ĐANG KHỚP ở tiêu đề**, nhảy theo từng cú bấm chip.
Bộ lọc ăn ngay nhưng tấm lọc che mất danh sách phía sau; không có con số đó thì
bấm xong phải đóng tấm mới biết vừa lọc ra cái gì, rồi lại mở ra sửa.

**Thứ tự mục trong tấm lọc chép đúng bản web**, và phần còn lại nằm dưới một vạch
"Nâng cao". Người dùng đi lại giữa web và app suốt ngày; đảo thứ tự là mỗi lần
đổi máy lại phải dò xem ô mình cần nằm đâu.

**Gọi hỏng KHÁC HẲN không có dữ liệu.** Danh sách nhóm lấy về `null` thì ghi
"Không lấy được danh sách nhóm" kèm nút Thử lại, chứ không được `orEmpty()` rồi
ghi "Chưa khai nhóm hàng nào" — câu ấy dành cho cửa hàng thật sự chưa khai nhóm,
nói nhầm là người dùng đi tạo lại đúng những nhóm họ đã có.

**Ô tìm lọc REALTIME, không có nút "Tìm** — đúng như trang danh sách bên web.
Chờ 350ms cho người dùng gõ xong rồi mới gọi; còn cú bấm chip thì đi ngay, vì nó
là một ý định dứt khoát chứ không phải một chuỗi đang gõ dở.

**Ô tìm và cụm lọc ĐỨNG YÊN, chỉ danh sách cuộn.** Đang lọc kho mà phải kéo
ngược lên đầu mới gõ được từ khoá là mất một nhịp ở mỗi lần tìm.

**Nút lọc mang con số.** Không có nó thì lúc đang lọc và lúc không lọc cái nút
trông y hệt nhau, mà đó đúng là lúc người dùng cần biết nhất — danh sách ngắn
bất thường vì họ lọc từ hôm qua chứ không phải vì kho hết hàng.

**Bộ lọc và sắp xếp lui vào tấm trượt**, trên màn chỉ để lại hai cái nút. Nhét
cả cụm lọc lên màn thì danh sách — thứ người ta mở màn này để xem — bị đẩy xuống
quá nửa màn hình.

**Không phân trang.** Cuộn còn cách đáy bốn dòng là gọi trang sau. Nút "Tải
thêm" bắt người ta dừng tay bấm một cái ở mỗi ba mươi dòng.

**Kéo xuống để tải lại** (`PullToRefreshBox`), kể cả trên màn lỗi và màn rỗng —
đó chính là lúc người ta muốn thử lại nhất. Lúc kéo thì GIỮ danh sách cũ, đừng
thay bằng khung xương: vòng xoay của chính cú kéo đã nói là đang chạy.

**Màn trống phải nói ĐÚNG lý do trống.** Kho rỗng, gõ hụt từ khoá, và lọc quá
tay là ba tình huống nhìn giống hệt nhau nhưng đòi ba việc khác nhau. Một câu
"Không có dữ liệu" chung cho cả ba là bỏ mặc người dùng đoán.

**Dòng mở đầu bằng vạch ưu tiên — TRỪ KHI mỗi dòng có một hình riêng.** Vạch
3dp là mặc định, và nó thắng ô icon tròn vì icon giống hệt nhau ở mọi dòng thì
chẳng nói thêm được gì. Nhưng ảnh mặt hàng thì mỗi dòng một khác, và với người
bán, cái hình là thứ nhận ra món hàng nhanh nhất — nhanh hơn đọc tên. Nên trang
Hàng hoá mở đầu bằng ô ảnh 52dp (chưa có ảnh thì rơi về ô chữ cái đầu, giữ
nguyên nhịp bố cục), còn trang Tồn kho vẫn là vạch màu vì ở đó mỗi dòng là một
con số chứ không phải một món đồ. Trạng thái lúc ấy chuyển thành ẢNH NHẠT ĐI
kèm huy hiệu, chứ không mọc thêm một cái chấm màu nữa.

**Cột phải căn TRÊN, thẳng hàng với dòng tên**, không căn giữa dòng. Tên dài hai
dòng mà giá căn giữa thì cả cột giá nhấp nhô theo độ dài của tên bên trái.

**Huy hiệu chỉ dán cho thứ KHÁC THƯỜNG.** Dán "Đang bán" lên mọi dòng, hay "1
biến thể" lên món không có biến thể nào, là huy hiệu hoá nền và mắt thôi đọc.
Dòng bình thường không có hàng huy hiệu, nên mọi dòng cao bằng nhau và cái nào
có huy hiệu thì đập vào mắt ngay.

**Bấm một dòng thì mở tấm chi tiết**, dựng từ dữ liệu ĐÃ CÓ trong dòng — không
gọi thêm lượt mạng nào. Tấm đó không có nút Sửa hay Xoá chừng nào app chưa sửa
được thật: bày một cái nút rồi báo "chưa làm được" là cách nhanh nhất để người
dùng thôi tin mấy cái nút còn lại.

---

## Biểu mẫu

Hộp thoại khai hàng bên web bày hai cột và lưới bốn ô mỗi hàng. Điện thoại chỉ
đủ một cột, mà mười lăm ô xếp thẳng một mạch thì cuộn mãi không biết còn bao xa.

**Chia thành KHỐI CÓ TÊN, ngăn nhau bằng vạch.** Tên khối viết hoa, cỡ nhỏ nhất,
màu chủ đạo. Mỗi lần cuộn tới một cái vạch là người dùng biết mình vừa xong một
phần.

**Thứ tự ô trong khối chép đúng bản web.** Người khai hàng quen tay ở máy tính,
sang điện thoại không phải học lại.

**Nhãn nằm HẲN TRÊN ô, không phải nhãn nổi.** Nhãn nổi lúc gõ co lại còn nửa cỡ,
mà đó đúng là lúc cần đọc nó nhất. Ô bắt buộc đánh dấu sao đỏ chứ không ghi
"(bắt buộc)" — mỗi nhãn dài thêm một dòng thì biểu mẫu dài thêm nửa màn.

**Viền ô nói trạng thái:** xám lúc thường, xanh chủ đạo lúc đang gõ, đỏ khi ô đó
sai. Câu lỗi nằm NGAY DƯỚI ô, không gom xuống chân biểu mẫu — gom xuống chân thì
đọc xong phải cuộn ngược lên dò xem ô nào đang nói tới.

**Chỉ tô đỏ SAU khi bấm Lưu.** Tô ngay lúc mở tấm là mắng người ta trước khi họ
kịp gõ chữ nào.

**Ô tiền giữ chuỗi CHỮ SỐ TRẦN trong trạng thái**, dấu chấm chỉ là lớp hiển thị.
Giữ dấu chấm thẳng trong trạng thái là lần nào đọc ra cũng phải nhớ bóc chúng
đi, mà quên một chỗ là "1.250.000" thành 1 đồng.

**Ô chọn giữ ID, không giữ đối tượng.** Danh sách nhóm/đơn vị/chi nhánh tải về
SAU khi tấm đã mở; giữ đối tượng thì lúc đổ dữ liệu cũ vào chưa có gì để trỏ tới
và ô hiện ra trống trong khi mặt hàng vẫn đang thuộc nhóm đó.

**Ảnh tải lên NGAY khi chọn, không đợi bấm Lưu.** Đợi tới lúc Lưu thì một lượt
bấm phải làm hai việc dài, và ảnh hỏng kéo cả mặt hàng hỏng theo trong khi phần
chữ chẳng có lỗi gì.

**Gom việc thành MỘT HÀNG, đừng xếp dọc.** Bốn nút chạy hết bề ngang xếp chồng
ăn gần một phần ba màn hình và đẩy hết phần thông tin lên trên nếp gấp; cùng bốn
việc ấy làm `ONutViec` nằm ngang thì vừa đúng một dòng. Nút nào chưa dùng được
thì GIẤU HẲN, đừng bày nút xám kèm một đoạn văn giải thích vì sao nó xám.

**Mấy ô thông tin ngắn xếp LƯỚI HAI CỘT**, nhãn mờ trên, giá trị đậm dưới. Xếp
dọc từng dòng là phí nửa bề ngang và đẩy phần dưới xuống thêm mấy dòng.

**Danh sách chọn dài thì GẬP LẠI.** Một cửa hàng điện máy có mười hai thuộc
tính, mỗi cái cả chục giá trị — đổ hết ra là cuộn mười màn mới tới nút Lưu,
trong khi khai một mặt hàng thường chỉ đụng tới hai ba cái. Dùng `MucGap`, và
LUÔN đưa phần đang chọn lên hàng tiêu đề: gập lại mà không thấy mình đã tick gì
thì phải mở từng cái ra dò.

**Việc không lùi được thì hỏi lại**, và trong hộp thoại ấy nút VIỆC để đỏ, nút
thoát ra để xanh. Ngoài chuyện đúng quy tắc màu của hệ thống, nó còn tiện thêm
một tầng: cái nút to bắt mắt nhất lại chính là nút an toàn.

---

## Mấy quy tắc hình

**Chuyển sắc là dành cho tiền.** Mảng xanh chuyển sắc chỉ đắp lên thẻ doanh
thu. Rải nó lên thẻ hồ sơ với thẻ cài đặt thì nó thành đồ trang trí, và lần
sau mắt không dừng lại ở con số thật nữa.

**Dòng danh sách mở đầu bằng vạch ưu tiên**, không phải ô icon tròn. `VachUuTien`
là 3x22, mang màu trạng thái. Ô tròn 42dp chỉ nói được đúng một việc mà ăn một
phần tám bề ngang của tên hàng — thứ duy nhất người ta thật sự đọc khi lướt kho.

**Vạch giữa hai dòng thụt vào** thẳng hàng với chữ, và dùng `vienNhat` chứ
không phải `vien`. Vạch chạy suốt mép này sang mép kia cắt danh sách thành
từng ô rời.

**Đang tải thì dựng khung xương, không quay vòng.** `OXuong` bày đúng hình dạng
của nội dung sắp tới, nhấp nháy chậm. Dữ liệu về là chữ điền vào chỗ đã sẵn,
màn không giật một cái rồi nhảy dựng lên.

**Mũ trang nói CẢ HAI: đang ở đâu, và đang mở cái gì.** Xem mục riêng bên dưới.

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
- **Kéo xuống để tải lại ở màn Tổng quan.** Màn Hàng hoá đã có; Tổng quan vẫn
  gọi đúng một lượt lúc mở, muốn số mới phải rời tab rồi quay lại.
- **Lọc nhiều nhóm hàng cùng lúc.** Bên web chọn được nhiều nhóm, ở đây mới một
  — `category_id` của API chỉ nhận một giá trị. Sửa được thì phải sửa từ API.
- **Bóng màu cần API 28, kính mờ cần API 31.** Máy Android 8.0/8.1 (`minSdk` 26)
  đổ bóng đen thay vì bóng ngả xanh; máy dưới Android 12 thì thanh nổi đục hẳn
  chứ không nhìn xuyên. Không sai, chỉ kém một nhịp.
- **Ghi lại cả thân màn mỗi khung hình** là cái giá của kính xuyên. Đo lại nếu
  có màn nào cuộn thấy giật.
