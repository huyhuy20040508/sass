package com.selliotech.app

import android.os.SystemClock
import android.util.Log
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
    val batDau = SystemClock.elapsedRealtime()
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
        val than = luong?.bufferedReader()?.use(BufferedReader::readText).orEmpty()
        ghiGio(phuongThuc, duong, ma, batDau, than.length)
        TraLoi(ma, than)
    } catch (e: Exception) {
        ghiGio(phuongThuc, duong, 0, batDau, 0)
        TraLoi(0, e.message.orEmpty())
    } finally {
        ket?.disconnect()
    }
}

/**
 * Ghi vào logcat mỗi lượt gọi hết bao lâu — CHỈ ở bản gỡ lỗi.
 *
 * Không có nó thì lúc người dùng kêu "màn tải lâu", chẳng ai tách được là chậm
 * ở máy chủ, ở đường truyền hay ở chính app; ai cũng chỉ ngồi đoán.
 *
 * `elapsedRealtime` chứ không phải `currentTimeMillis`: đồng hồ hệ thống nhảy
 * một cái khi máy đồng bộ giờ là ra một con số âm.
 *
 * internal chứ không private: lượt tải ảnh tự dựng kết nối multipart nên không
 * đi qua `goiApi`, mà nó lại là lượt gọi NẶNG NHẤT của cả app — thiếu đúng nó
 * trong log là lúc người dùng kêu "tải ảnh lâu" thì chẳng có gì để soi.
 */
internal fun ghiGio(phuongThuc: String, duong: String, ma: Int, batDau: Long, coThan: Int) {
    if (!BuildConfig.DEBUG) return

    val het = SystemClock.elapsedRealtime() - batDau
    Log.d("SellioApi", "$phuongThuc $duong -> $ma, ${het}ms, ${coThan}B")
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
 * Kết quả một lượt làm mới token. BA nhánh, không phải hai.
 *
 * Đây là chỗ đã hỏng một lần và hỏng rất nặng: bản cũ trả về `Phien?`, tức gộp
 * "máy chủ CHỐI refresh token" với "không gọi tới được máy chủ" thành cùng một
 * giá trị null — rồi chỗ gọi thấy null là XOÁ SẠCH PHIÊN. Nghĩa là người bán đi
 * vào góc khuất sóng đúng lúc access token vừa hết hạn thì bị đăng xuất giữa ca,
 * phải gõ lại mã cửa hàng và mật khẩu trong khi tài khoản của họ chẳng có vấn đề
 * gì. Mất mạng là chuyện xảy ra hàng ngày; phiên chết thì hiếm.
 */
sealed interface KetQuaLamMoi {
    /** Đổi được token mới. */
    data class Xuoi(val phien: Phien) : KetQuaLamMoi

    /** Máy chủ trả lời và nói KHÔNG: refresh token hết hạn hoặc bị thu hồi. */
    data object PhienChet : KetQuaLamMoi

    /** Không gọi tới được máy chủ (mất mạng, máy chủ lỗi). Phiên vẫn còn nguyên. */
    data object KhongToi : KetQuaLamMoi
}

/**
 * Đọc MÃ HTTP của lượt làm mới thành kết luận về phiên. null = chưa kết luận
 * được, phải đọc tiếp phần thân.
 *
 * Tách khỏi hàm gọi mạng để kiểm được bằng bộ kiểm thường: cái luật "mã nào thì
 * coi là phiên chết" chính là chốt duy nhất ngăn lỗi tự đăng xuất quay lại.
 */
fun phanLoaiLamMoi(ma: Int, refreshRong: Boolean = false): KetQuaLamMoi? = when {
    // Không có refresh token thì khỏi gọi: chẳng có gì để làm mới.
    refreshRong -> KetQuaLamMoi.PhienChet
    // ma = 0 là chưa chạm tới máy chủ; 5xx là máy chủ đang hỏng. Cả hai đều
    // KHÔNG nói gì về việc phiên còn sống hay không.
    ma == 0 || ma >= 500 -> KetQuaLamMoi.KhongToi
    // Máy chủ trả lời và không cho: phiên chết thật.
    ma >= 400 -> KetQuaLamMoi.PhienChet
    else -> null
}

/**
 * Làm mới access token.
 *
 * Chỉ coi là PHIÊN CHẾT khi máy chủ thật sự trả lời và từ chối (4xx). Mọi thứ
 * khác — không nối được, hết giờ chờ, máy chủ 5xx — đều là `KhongToi`, và phiên
 * phải được giữ nguyên để lát nữa có sóng là dùng lại được.
 */
suspend fun lamMoiToken(refreshToken: String): KetQuaLamMoi {
    phanLoaiLamMoi(0, refreshRong = refreshToken.isBlank())?.let { return it }

    val than = JSONObject().put("refresh_token", refreshToken).toString()
    val traLoi = goiApi("/auth/refresh", "POST", than)
    phanLoaiLamMoi(traLoi.ma)?.let { return it }

    val data = traLoi.json()?.optJSONObject("data")
    if (data == null) return KetQuaLamMoi.PhienChet

    val access = data.optString("access_token")
    if (access.isBlank()) return KetQuaLamMoi.PhienChet

    // Chỉ hai token là mới; phần hồ sơ do nơi gọi giữ (đường này không trả tenant).
    return KetQuaLamMoi.Xuoi(
        Phien(
            accessToken = access,
            refreshToken = data.optString("refresh_token").ifBlank { refreshToken },
            hetHanLuc = hanTu(data.optLong("expires_in")),
            maCuaHang = "",
            tenCuaHang = "",
            tenDangNhap = "",
            vaiTro = "",
            cuaHangKhoa = false,
        ),
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
    val token = tokenSong(kho) ?: return TraLoi(401, "")
    val phien = kho.doc() ?: return TraLoi(401, "")

    val traLoi = goiApi(duong, phuongThuc, than, token)
    if (traLoi.ma != 401) return traLoi

    // Máy chủ vẫn chối dù token còn trong hạn theo đồng hồ máy — đồng hồ lệch,
    // hoặc phiên bị thu hồi bên kia. Thử làm mới đúng một lần.
    return when (val kq = lamMoiToken(phien.refreshToken)) {
        is KetQuaLamMoi.Xuoi -> {
            kho.ghiTokenMoi(kq.phien.accessToken, kq.phien.refreshToken, kq.phien.hetHanLuc)
            goiApi(duong, phuongThuc, than, kq.phien.accessToken)
        }

        KetQuaLamMoi.PhienChet -> {
            kho.xoa()
            TraLoi(401, "")
        }

        // Mất mạng giữa chừng: trả nguyên lượt 401 vừa nhận, KHÔNG xoá phiên.
        KetQuaLamMoi.KhongToi -> traLoi
    }
}

/**
 * Access token còn dùng được, tự làm mới nếu đã hết hạn. null = phiên chết hẳn.
 *
 * Tách ra vì không phải lượt gọi nào cũng đi qua `goiCoToken`: lượt tải ảnh phải
 * tự dựng thân multipart nên cần cầm token trong tay. Hai chỗ cùng tự đọc phiên
 * rồi tự quyết khi nào làm mới là sớm muộn một chỗ quên nhánh hết hạn.
 *
 * MẤT MẠNG THÌ TRẢ VỀ TOKEN CŨ chứ không trả null. Token ấy đã hết hạn nên lượt
 * gọi sẽ hỏng — nhưng hỏng vì mạng, và màn hình nói "kiểm tra mạng rồi thử lại"
 * đúng như chuyện đang xảy ra. Trả null ở đây thì lượt gọi biến thành 401 giả,
 * màn hình đổ cho quyền hoặc cho phiên trong khi lỗi thật là cái cột sóng.
 */
suspend fun tokenSong(kho: KhoPhien): String? {
    val phien = kho.doc() ?: return null
    if (phien.conHan()) return phien.accessToken

    return when (val kq = lamMoiToken(phien.refreshToken)) {
        is KetQuaLamMoi.Xuoi -> {
            kho.ghiTokenMoi(kq.phien.accessToken, kq.phien.refreshToken, kq.phien.hetHanLuc)
            kq.phien.accessToken
        }

        // Máy chủ CHỐI: phiên chết thật, dọn sạch rồi bắt đăng nhập lại.
        KetQuaLamMoi.PhienChet -> {
            kho.xoa()
            null
        }

        // Không tới được máy chủ: GIỮ NGUYÊN phiên, đưa token cũ đi cho lượt gọi
        // hỏng đúng kiểu lỗi mạng.
        KetQuaLamMoi.KhongToi -> phien.accessToken
    }
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
