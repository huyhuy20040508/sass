package com.selliotech.app

/**
 * Cửa vào — hai khu làm việc của phần mềm, khớp cột SET `users.access_areas`
 * bên API. Bản Kotlin này phải đi cùng `domain.CuaVao` trong entities.go: lệch
 * một bên là app cho vào khu mà máy chủ chặn, người dùng lãnh 403 vô cớ.
 */
object Khu {
    const val QUAN_LY = "quan_ly"
    const val THU_NGAN = "thu_ngan"

    /** Tên hiện lên màn hình. Lấy đúng chữ của `DanhMucQuyen` bên API. */
    fun ten(ma: String): String = when (ma) {
        QUAN_LY -> "Quản trị"
        THU_NGAN -> "Thu ngân"
        else -> ma
    }

    fun moTa(ma: String): String = when (ma) {
        QUAN_LY -> "Hàng hoá, kho, khách hàng, báo cáo"
        THU_NGAN -> "Bán hàng, điều phối ca, sổ quỹ"
        else -> ""
    }
}

// Mã vai trò, khớp entities.go.
private const val VAI_SUPER_ADMIN = 1
private const val VAI_ADMIN = 2
private const val VAI_STAFF = 3

/**
 * Những cửa tài khoản này mở được.
 *
 * Cột rỗng = tài khoản có trước migration 0015: suy từ role_id đúng như API
 * làm, chứ trả rỗng là khoá cứng mọi tài khoản cũ ra ngoài.
 */
fun cuaVao(accessAreas: String, vaiTroId: Int): List<String> {
    if (accessAreas.isNotBlank()) {
        return accessAreas.split(",")
            .map(String::trim)
            .filter { it == Khu.QUAN_LY || it == Khu.THU_NGAN }
    }

    return when (vaiTroId) {
        VAI_SUPER_ADMIN, VAI_ADMIN -> listOf(Khu.QUAN_LY, Khu.THU_NGAN)
        VAI_STAFF -> listOf(Khu.THU_NGAN)
        else -> emptyList()
    }
}
