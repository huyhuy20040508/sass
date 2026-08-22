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
| `ChipChon(chu, chon)` | Chip chọn một trong nhiều — dải chọn kỳ |
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
- **Tấm trượt từ dưới lên, trạng thái rỗng có hình.** Chưa có thành phần chuẩn,
  dựng tới đâu thêm tới đó.
- **Kéo xuống để tải lại.** Màn Tổng quan gọi đúng một lượt lúc mở, muốn số mới
  phải rời tab rồi quay lại.
- **Bóng màu cần API 28, kính mờ cần API 31.** Máy Android 8.0/8.1 (`minSdk` 26)
  đổ bóng đen thay vì bóng ngả xanh; máy dưới Android 12 thì thanh nổi đục hẳn
  chứ không nhìn xuyên. Không sai, chỉ kém một nhịp.
- **Ghi lại cả thân màn mỗi khung hình** là cái giá của kính xuyên. Đo lại nếu
  có màn nào cuộn thấy giật.
