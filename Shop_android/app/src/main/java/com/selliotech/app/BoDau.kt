package com.selliotech.app

import java.text.Normalizer

private val DAU = Regex("\\p{Mn}+")

/**
 * Bỏ dấu tiếng Việt: "tést" -> "test", "đăng" -> "dang".
 *
 * Mã cửa hàng và tên đăng nhập bên máy chủ chỉ có chữ không dấu, nên gõ nhầm khi
 * UniKey đang bật thì sửa tại đây thay vì để người dùng nhận 401 mà không hiểu vì sao.
 * Không dùng cho mật khẩu — mật khẩu phải gửi đi nguyên văn.
 */
fun boDau(chuoi: String): String =
    Normalizer.normalize(chuoi, Normalizer.Form.NFD)
        .replace(DAU, "")
        .replace('đ', 'd')
        .replace('Đ', 'D')
        .trim()
