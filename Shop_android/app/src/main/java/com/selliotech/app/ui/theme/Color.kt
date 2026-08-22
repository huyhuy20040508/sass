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
