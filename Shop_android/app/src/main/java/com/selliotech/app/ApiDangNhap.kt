package com.selliotech.app

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL

const val BASE_URL = "https://api.selliotech.store/api/v1"

/** Một lượt gọi API: mã HTTP kèm thân trả về nguyên văn. */
data class TraLoi(val ma: Int, val than: String) {
    val xuoi: Boolean get() = ma in 200..299

    fun json(): JSONObject? = runCatching { JSONObject(than) }.getOrNull()
}

/**
 * Gọi một đường API. Chạy trên luồng IO vì Android cấm gọi mạng ở luồng giao diện.
 * ma = 0 nghĩa là chưa chạm tới máy chủ (mất mạng, sai tên miền, hết giờ chờ).
 */
suspend fun goiApi(
    duong: String,
    phuongThuc: String = "GET",
    than: String? = null,
    token: String? = null,
): TraLoi = withContext(Dispatchers.IO) {
    var ket: HttpURLConnection? = null
    try {
        ket = (URL("$BASE_URL$duong").openConnection() as HttpURLConnection).apply {
            requestMethod = phuongThuc
            setRequestProperty("Accept", "application/json")
            token?.let { setRequestProperty("Authorization", "Bearer $it") }
            connectTimeout = 15_000
            readTimeout = 15_000
            if (than != null) {
                setRequestProperty("Content-Type", "application/json")
                doOutput = true
            }
        }
        than?.let { noiDung -> ket.outputStream.use { it.write(noiDung.toByteArray()) } }

        val ma = ket.responseCode
        // Lỗi 4xx/5xx nằm ở errorStream chứ không phải inputStream.
        val luong = if (ma in 200..299) ket.inputStream else ket.errorStream
        TraLoi(ma, luong?.bufferedReader()?.use(BufferedReader::readText).orEmpty())
    } catch (e: Exception) {
        TraLoi(0, e.message.orEmpty())
    } finally {
        ket?.disconnect()
    }
}

/** Câu lỗi hợp lý cho người dùng khi máy chủ không nói gì rõ ràng. */
fun TraLoi.cauLoi(): String {
    json()?.optString("message")?.takeIf { it.isNotBlank() }?.let { return it }

    return when (ma) {
        0 -> "Không kết nối được máy chủ. Kiểm tra mạng rồi thử lại."
        401 -> "Sai mã cửa hàng, tên đăng nhập hoặc mật khẩu."
        403 -> "Tài khoản không có quyền vào ứng dụng này."
        429 -> "Thử quá nhiều lần. Đợi vài phút rồi đăng nhập lại."
        in 500..599 -> "Máy chủ đang lỗi. Thử lại sau ít phút."
        else -> "Đăng nhập không thành công (HTTP $ma)."
    }
}

sealed interface KetQuaDangNhap {
    data class ThanhCong(val phien: Phien) : KetQuaDangNhap

    data class ThatBai(val loi: String) : KetQuaDangNhap
}

/** Đăng nhập 3 ô của Shop Admin: POST /auth/shop-login. */
suspend fun dangNhapCuaHang(
    maCuaHang: String,
    tenDangNhap: String,
    matKhau: String,
): KetQuaDangNhap {
    val than = JSONObject().apply {
        put("shop_code", maCuaHang)
        put("username", tenDangNhap)
        put("password", matKhau)
    }.toString()

    val traLoi = goiApi("/auth/shop-login", "POST", than)
    val json = traLoi.json()
    if (!traLoi.xuoi || json?.optBoolean("success") != true) {
        return KetQuaDangNhap.ThatBai(traLoi.cauLoi())
    }

    val data = json.optJSONObject("data")
        ?: return KetQuaDangNhap.ThatBai("Máy chủ trả về dữ liệu không đọc được.")
    val nguoiDung = data.optJSONObject("user")

    return KetQuaDangNhap.ThanhCong(
        Phien(
            accessToken = data.optString("access_token"),
            refreshToken = data.optString("refresh_token"),
            hetHanLuc = hanTu(data.optLong("expires_in")),
            maCuaHang = maCuaHang,
            tenCuaHang = data.optJSONObject("tenant")?.optString("name").orEmpty(),
            tenDangNhap = nguoiDung?.optString("username").orEmpty(),
            vaiTro = nguoiDung?.optJSONObject("role")?.optString("name").orEmpty(),
            cuaHangKhoa = data.optBoolean("cua_hang_khoa"),
            cuaVao = cuaVao(
                accessAreas = nguoiDung?.optString("access_areas").orEmpty(),
                vaiTroId = nguoiDung?.optInt("role_id") ?: 0,
            ),
        ),
    )
}

/**
 * Làm mới access token. Trả null nghĩa là refresh token cũng hỏng — phải bắt
 * người dùng đăng nhập lại chứ không thử lại được nữa.
 */
suspend fun lamMoiToken(refreshToken: String): Phien? {
    if (refreshToken.isBlank()) return null

    val than = JSONObject().put("refresh_token", refreshToken).toString()
    val traLoi = goiApi("/auth/refresh", "POST", than)
    val data = traLoi.json()?.optJSONObject("data")
    if (!traLoi.xuoi || data == null) return null

    val access = data.optString("access_token")
    if (access.isBlank()) return null

    // Chỉ hai token là mới; phần hồ sơ do nơi gọi giữ (đường này không trả tenant).
    return Phien(
        accessToken = access,
        refreshToken = data.optString("refresh_token").ifBlank { refreshToken },
        hetHanLuc = hanTu(data.optLong("expires_in")),
        maCuaHang = "",
        tenCuaHang = "",
        tenDangNhap = "",
        vaiTro = "",
        cuaHangKhoa = false,
    )
}

/**
 * Gọi một đường CẦN token, tự lo phần token hết hạn.
 *
 * Access token sống 15 phút còn một ca bán hàng dài mấy tiếng, nên hết hạn giữa
 * chừng là chuyện chắc chắn xảy ra chứ không phải trường hợp hiếm. Hết hạn thì
 * làm mới rồi gọi lại, người dùng không thấy gì.
 *
 * ma = 401 trả về từ đây nghĩa là refresh token cũng hỏng: phiên chết hẳn, nơi
 * gọi phải đá về màn đăng nhập.
 */
suspend fun goiCoToken(
    kho: KhoPhien,
    duong: String,
    phuongThuc: String = "GET",
    than: String? = null,
): TraLoi {
    val phien = kho.doc() ?: return TraLoi(401, "")

    val token = if (phien.conHan()) {
        phien.accessToken
    } else {
        lamMoiVaGhi(kho, phien) ?: return TraLoi(401, "")
    }

    val traLoi = goiApi(duong, phuongThuc, than, token)
    if (traLoi.ma != 401) return traLoi

    // Máy chủ vẫn chối dù token còn trong hạn theo đồng hồ máy — đồng hồ lệch,
    // hoặc phiên bị thu hồi bên kia. Thử làm mới đúng một lần.
    val moi = lamMoiVaGhi(kho, phien) ?: return TraLoi(401, "")

    return goiApi(duong, phuongThuc, than, moi)
}

/** Làm mới rồi cất lại. Trả null = phiên chết, đã xoá sạch. */
private suspend fun lamMoiVaGhi(kho: KhoPhien, cu: Phien): String? {
    val moi = lamMoiToken(cu.refreshToken)
    if (moi == null) {
        kho.xoa()

        return null
    }
    kho.ghiTokenMoi(moi.accessToken, moi.refreshToken, moi.hetHanLuc)

    return moi.accessToken
}

/** Quyền của tài khoản đang đăng nhập — dùng để ẩn/hiện chức năng trong app. */
suspend fun layQuyenCuaToi(kho: KhoPhien): String {
    val traLoi = goiCoToken(kho, "/admin/quyen-cua-toi")

    return "HTTP ${traLoi.ma}\n${traLoi.than}"
}

/** expires_in tính bằng giây; để trống thì coi như 15 phút đúng như mặc định bên API. */
private fun hanTu(giay: Long): Long =
    System.currentTimeMillis() + (if (giay > 0) giay else 900L) * 1000L

/**
 * Đọc lại cửa vào từ GET /auth/me.
 *
 * Cho phiên cất từ bản app chưa biết tới cửa vào: bắt họ đăng nhập lại chỉ vì
 * app lên đời là làm phiền vô cớ. Trả null = không hỏi được, cứ để nguyên.
 */
suspend fun layCuaVao(token: String): List<String>? {
    val traLoi = goiApi("/auth/me", token = token)
    val data = traLoi.json()?.optJSONObject("data")
    if (!traLoi.xuoi || data == null) return null

    return cuaVao(
        accessAreas = data.optString("access_areas"),
        vaiTroId = data.optInt("role_id"),
    )
}
