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
//  nhãn, nút.
//
//  Chữ Việt có dấu nên chiều cao dòng rộng hơn thông lệ một nhịp: dấu
//  ngã trên chữ hoa mà dòng chật là chạm vào dòng trên.
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

val Typography = Typography(
    // Số tiền lớn — con số là nhân vật chính của màn hình, cho nó to hẳn.
    displaySmall = kieu(34, 42, FontWeight.Bold, -0.5),
    // Tiêu đề màn hình.
    headlineSmall = kieu(22, 30, FontWeight.Bold, -0.2),
    // Tiêu đề mục / tên thẻ.
    titleMedium = kieu(17, 24, FontWeight.SemiBold),
    // Nội dung chính.
    bodyLarge = kieu(15, 22, FontWeight.Normal),
    // Nội dung phụ, chú thích dưới dòng.
    bodyMedium = kieu(13, 19, FontWeight.Normal),
    // Nhãn nhỏ, huy hiệu, tên cột.
    labelMedium = kieu(12, 16, FontWeight.Medium, 0.1),
    // Chữ trên nút.
    labelLarge = kieu(16, 20, FontWeight.SemiBold),
)
