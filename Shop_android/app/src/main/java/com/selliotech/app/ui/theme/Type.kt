package com.selliotech.app.ui.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.LineHeightStyle
import androidx.compose.ui.unit.sp

// =====================================================================
//  CHỮ — bảy cỡ, không hơn.
//
//  Mỗi cỡ thêm vào là một lần người sau phải đoán nên dùng cái nào. Bảy
//  cỡ đủ cho mọi màn: số tiền, tiêu đề màn, tiêu đề mục, nội dung, phụ,
//  nhãn, nút. Cỡ thứ tám duy nhất được phép là CHỮ MÀO (11sp) — dòng chữ
//  in hoa nhỏ trên đầu một con số, nó phải nhỏ hơn nhãn thì mới ra vai mào.
//
//  Chữ Việt có dấu nên chiều cao dòng rộng hơn thông lệ một nhịp: dấu
//  ngã trên chữ hoa mà dòng chật là chạm vào dòng trên.
//
//  MỌI ô của Material 3 đều được khai ở dưới, kể cả ô app chưa dùng tới.
//  Bỏ trống một ô là để nó rơi về mặc định của Material — và mặc định đó
//  không nằm trong bảy cỡ này. Một màn lỡ gọi `titleLarge` là tự nhiên
//  mọc ra một cỡ chữ không ai duyệt.
// =====================================================================

private val CanDeu = LineHeightStyle(
    alignment = LineHeightStyle.Alignment.Center,
    trim = LineHeightStyle.Trim.None,
)

private fun kieu(
    co: Int,
    dong: Int,
    dam: FontWeight,
    gian: Double = 0.0,
) = TextStyle(
    fontFamily = FontFamily.Default,
    fontWeight = dam,
    fontSize = co.sp,
    lineHeight = dong.sp,
    letterSpacing = gian.sp,
    lineHeightStyle = CanDeu,
)

// Bảy cỡ. Đây là bảng gốc — phần dưới chỉ gán chúng vào ô của Material.
private val SoTienLon = kieu(34, 42, FontWeight.Bold, -0.5)
private val TieuDeMan = kieu(22, 30, FontWeight.Bold, -0.2)
private val TieuDeMuc = kieu(17, 24, FontWeight.SemiBold)
private val NoiDung = kieu(15, 22, FontWeight.Normal)
private val NoiDungDam = kieu(15, 22, FontWeight.SemiBold)
private val NoiDungPhu = kieu(13, 19, FontWeight.Normal)
private val ChuThich = kieu(12, 16, FontWeight.Normal)
private val Nhan = kieu(12, 16, FontWeight.Medium, 0.1)
private val ChuNut = kieu(16, 20, FontWeight.SemiBold)
private val ChuMao = kieu(11, 15, FontWeight.SemiBold, 1.2)

val Typography = Typography(
    // Số tiền — con số là nhân vật chính của màn hình, cho nó to hẳn.
    displayLarge = SoTienLon,
    displayMedium = SoTienLon,
    displaySmall = SoTienLon,

    // Tiêu đề màn.
    headlineLarge = TieuDeMan,
    headlineMedium = TieuDeMan,
    headlineSmall = TieuDeMan,
    titleLarge = TieuDeMan,

    // Tiêu đề mục / tên thẻ.
    titleMedium = TieuDeMuc,

    // Cỡ nội dung nhưng đậm: con số trong dòng danh sách, giá tiền bên phải.
    titleSmall = NoiDungDam,

    // Nội dung.
    bodyLarge = NoiDung,
    bodyMedium = NoiDungPhu,
    bodySmall = ChuThich,

    // Chữ trên nút.
    labelLarge = ChuNut,

    // Nhãn, huy hiệu, tên cột.
    labelMedium = Nhan,

    // Chữ mào: "DOANH THU HÔM NAY" đứng trên con số.
    labelSmall = ChuMao,
)
