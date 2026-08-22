package com.selliotech.app.ui.theme

import android.app.Activity
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat

/**
 * Màu KHÔNG có chỗ trong bảng màu của Material: trạng thái, chữ mờ, viền.
 *
 * Material 3 gộp hết vào `error`/`onSurfaceVariant`, nhưng một app bán hàng
 * cần phân biệt "xong" với "chờ" với "hỏng" — ba thứ khác nhau.
 */
data class MauPhu(
    val luc: Color,
    val lucNen: Color,
    val cam: Color,
    val camNen: Color,
    val do_: Color,
    val doNen: Color,
    val lam: Color,
    val lamNen: Color,
    val vang: Color,
    val chuThuong: Color,
    val chuMo: Color,
    val vien: Color,
    val vienNhat: Color,
    val matChim: Color,
)

private val PhuSang = MauPhu(
    luc = Luc, lucNen = LucNen,
    cam = Cam, camNen = CamNen,
    do_ = Do, doNen = DoNen,
    lam = Lam, lamNen = LamNen,
    vang = VangSellio,
    chuThuong = ChuThuongSang,
    chuMo = ChuMoSang,
    vien = VienSang,
    vienNhat = VienNhatSang,
    matChim = MatChimSang,
)

private val PhuToi = MauPhu(
    luc = Luc, lucNen = Color(0xFF16301C),
    cam = Cam, camNen = Color(0xFF352A0D),
    do_ = DoNhat, doNen = Color(0xFF3A1E1F),
    lam = Lam, lamNen = Color(0xFF14283D),
    vang = VangSellio,
    chuThuong = ChuThuongToi,
    chuMo = ChuMoToi,
    vien = VienToi,
    vienNhat = VienToi,
    matChim = MatChimToi,
)

val LocalMauPhu = staticCompositionLocalOf { PhuSang }

private val BangSang = lightColorScheme(
    primary = Xanh,
    onPrimary = Color.White,
    primaryContainer = XanhNen,
    onPrimaryContainer = XanhDam,
    secondary = XanhDam,
    onSecondary = Color.White,
    background = NenSang,
    onBackground = ChuSang,
    surface = MatSang,
    onSurface = ChuSang,
    surfaceVariant = MatChimSang,
    onSurfaceVariant = ChuThuongSang,
    outline = VienSang,
    outlineVariant = VienNhatSang,
    error = Do,
    onError = Color.White,
    errorContainer = DoNen,
    onErrorContainer = Do,
)

private val BangToi = darkColorScheme(
    primary = XanhSang,
    onPrimary = Color(0xFF06213A),
    primaryContainer = Color(0xFF14283D),
    onPrimaryContainer = Color(0xFFCCE6FF),
    secondary = Xanh,
    onSecondary = Color.White,
    background = NenToi,
    onBackground = ChuToi,
    surface = MatToi,
    onSurface = ChuToi,
    surfaceVariant = MatChimToi,
    onSurfaceVariant = ChuThuongToi,
    outline = VienToi,
    outlineVariant = VienToi,
    error = DoNhat,
    onError = Color(0xFF3A1E1F),
    errorContainer = Color(0xFF3A1E1F),
    onErrorContainer = DoNhat,
)

/**
 * KHÔNG có màu động theo hình nền máy (`dynamicColor`).
 *
 * Đó là mặc định của bản mẫu Android Studio và là thứ phải bỏ đầu tiên: app
 * mang màu thương hiệu, không đổi tông theo ảnh nền của từng người. Chưa kể
 * mỗi máy một màu thì chụp màn hình gửi nhau không ai đối chiếu được.
 */
@Composable
fun SelliotechTheme(
    // LUÔN tông sáng, KHÔNG theo chế độ tối của máy: Shop Admin trên web không có
    // chế độ tối, mà cùng một người sáng dùng web ở quầy chiều cầm điện thoại đi
    // kiểm kho. Máy để chế độ tối là app đổi hẳn bộ mặt so với web — thành hai
    // phần mềm khác nhau trong đầu họ.
    //
    // Vẫn để tham số chứ không xoá: bảng màu tối đã dựng sẵn, ngày nào web có chế
    // độ tối thì mở lại bằng isSystemInDarkTheme() ở đây.
    darkTheme: Boolean = false,
    content: @Composable () -> Unit,
) {
    val bang = if (darkTheme) BangToi else BangSang
    val phu = if (darkTheme) PhuToi else PhuSang

    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val cua = (view.context as Activity).window
            WindowCompat.getInsetsController(cua, view).isAppearanceLightStatusBars = !darkTheme
        }
    }

    CompositionLocalProvider(LocalMauPhu provides phu) {
        MaterialTheme(
            colorScheme = bang,
            typography = Typography,
            content = content,
        )
    }
}

/** Lối tắt gọi màu phụ: `mauPhu.luc` thay vì `LocalMauPhu.current.luc`. */
val mauPhu: MauPhu
    @Composable get() = LocalMauPhu.current
