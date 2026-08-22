package com.selliotech.app.ui.theme

import androidx.compose.ui.graphics.Color

// =====================================================================
//  BẢNG MÀU — lấy nguyên của Shop Admin trên web (nền Ant Design).
//
//  App và web PHẢI cùng một bảng màu: cùng một người sáng dùng web ở quầy,
//  chiều cầm điện thoại đi kiểm kho. Hai tông màu khác nhau là hai phần mềm
//  khác nhau trong đầu họ.
//
//  Quy tắc cứng: KHÔNG viết Color(0xFF...) trong tệp màn hình. Cần màu nào
//  chưa có thì thêm vào đây rồi mới dùng.
// =====================================================================

// ---- Xanh chủ đạo ---------------------------------------------------
/** #1890ff — màu hành động chính, giống hệt `--au-primary` bên web. */
val Xanh = Color(0xFF1890FF)
val XanhSang = Color(0xFF40A9FF)
val XanhDam = Color(0xFF0E7CE0)
/** Nền xanh rất nhạt cho vùng được chọn. */
val XanhNen = Color(0xFFF0F5FF)

// ---- Thương hiệu ----------------------------------------------------
/** Tím Sellio. CHỈ dùng cho logo và nhận diện, không phải màu hành động. */
val TimSellio = Color(0xFF3A1266)
/** Vàng Sellio. Dùng dè: điểm nhấn, huy hiệu — không tô nền nút. */
val VangSellio = Color(0xFFFFC20F)

// ---- Sáng -----------------------------------------------------------
val NenSang = Color(0xFFF5F6FA)
val MatSang = Color(0xFFFFFFFF)
val MatChimSang = Color(0xFFF5F6FA)
val VienSang = Color(0xFFD9D9D9)
val VienNhatSang = Color(0xFFE5E7EB)

val ChuSang = Color(0xFF262626)
val ChuThuongSang = Color(0xFF4B5563)
val ChuMoSang = Color(0xFF8C8C8C)

// ---- Tối ------------------------------------------------------------
//  Web không có chế độ tối, nên phần này tự dựng: giữ đúng xanh chủ đạo,
//  chỉ hạ nền và nâng chữ.
val NenToi = Color(0xFF111318)
val MatToi = Color(0xFF1B1E24)
val MatChimToi = Color(0xFF242830)
val VienToi = Color(0xFF343945)

val ChuToi = Color(0xFFF2F3F5)
val ChuThuongToi = Color(0xFFC3C7CF)
val ChuMoToi = Color(0xFF8A8F99)

// ---- Trạng thái -----------------------------------------------------
val Luc = Color(0xFF52C41A)
val LucNen = Color(0xFFF2FBEB)
val Cam = Color(0xFFFAAD14)
val CamNen = Color(0xFFFFF9E6)
val Do = Color(0xFFFF4D4F)
val DoNhat = Color(0xFFFF7875)
val DoNen = Color(0xFFFFF1F0)
val Lam = Xanh
val LamNen = XanhNen

// ---- Ngọc — màu RIÊNG của thanh nổi ---------------------------------
//
//  NGOẠI LỆ DUY NHẤT của quy tắc "app và web cùng một bảng màu", và chỉ được
//  phép sống trong `KhungApp.kt`.
//
//  Vì sao mở ngoại lệ: thanh nổi là thứ duy nhất trong app KHÔNG thuộc về một
//  trang nào — nó là lớp trên, dựng theo một mẫu thiết kế riêng, và mẫu đó đòi
//  xanh ngọc. Nó cũng không mang nghĩa hành động: bấm vào một tab là ĐI chỗ
//  khác, không phải xác nhận hay lưu cái gì. Vậy nên nó không giành vai với
//  `Xanh` — xanh #1890ff vẫn là màu hành động duy nhất của cả hệ thống, y hệt
//  bên web.
//
//  Cấm mang ba màu này ra khỏi thanh nổi. Một cái nút màu ngọc là lập tức
//  người dùng có hai màu "bấm được" và phải đoán cái nào thật.
/** Chóp quả cầu, gần bạc. */
val NgocSang = Color(0xFF7FE6F4)
/** Thân quả cầu và màu quầng sáng. */
val Ngoc = Color(0xFF35C9E0)
/** Mép dưới quả cầu, chỗ khuất sáng. */
val NgocDam = Color(0xFF17ACC6)

// ---- Thanh nổi ------------------------------------------------------
//  Thanh điều hướng không nằm dán đáy nữa mà nổi lên trên nội dung, nên nó
//  cần hai màu mà thẻ thường không cần: một viền sáng để tách khỏi nền xám,
//  và một màu bóng ngả xanh thay cho bóng đen.

/** Vành sáng phía trong mép kính, chỗ mặt cong bắt sáng. */
val VienKinhSang = Color(0xFFE8EBF2)

/**
 * Đường bao ngoài của thanh nổi.
 *
 * Đậm hơn viền thẻ hẳn một bậc, và đó là chủ ý: thanh kính nằm đè lên đủ thứ
 * nền — lúc trên nền xám, lúc trên thẻ trắng. Trên thẻ trắng mà không có đường
 * bao thật thì cả cái thanh tan biến, người dùng mất luôn chỗ bấm.
 */
val VienKinh = Color(0xFFC6D2E0)

/** Màu bóng của thanh nổi — xanh ám chứ không phải đen. Cần API 28 trở lên. */
val BongNoi = Color(0xFF1B3A5C)

// Thân kính của thanh nổi, lạnh dần từ mép trên xuống mép dưới.
//
// LẠNH HƠN CẢ NỀN LẪN THẺ, chứ không phải màu trắng. Kính mờ pha trắng nằm trên
// thẻ trắng thì tan biến — người dùng mất luôn chỗ bấm. Một tấm kính thật bao
// giờ cũng ngả xám hơn thứ nằm dưới nó, và chính cái lệch tông đó mới là thứ
// nói cho mắt biết ở đây có một lớp khác.
val KinhTren = Color(0xFFE4EBF5)
val KinhDuoi = Color(0xFFCFDBEA)

/**
 * Độ đục của lớp kính.
 *
 * RẤT MỎNG — bốn phần năm những gì nằm dưới thanh vẫn lọt lên, chỉ là đã nhoè.
 * Đọc không ra chữ nhưng nhận ra ngay là có chữ ở dưới.
 *
 * Giữ được điều đó mà thanh vẫn hiện rõ là nhờ ĐƯỜNG BAO sắc nét, rồi mới tới
 * màu màng — chứ tuyệt đối không nhờ độ dày. Đây là chỗ đã đi sai hai lần: lần
 * đầu pha trắng nên thanh tan biến trên thẻ trắng, lần sau chữa bằng cách phủ
 * dày lên thì mất luôn cái nhìn xuyên.
 *
 * Máy dưới Android 12 không có làm mờ nền, phải phủ gần kín (0.94): trong mà
 * không mờ thì chữ dưới thanh lòi lên lẫn vào icon, trông như lỗi chứ không
 * như kính.
 */
const val DamKinh = 0.20f
const val DamKinhCu = 0.94f
