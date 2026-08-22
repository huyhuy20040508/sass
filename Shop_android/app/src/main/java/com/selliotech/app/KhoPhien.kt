package com.selliotech.app

import android.content.Context
import android.content.SharedPreferences
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import org.json.JSONObject
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Một phiên đăng nhập đã cất trên máy.
 *
 * `tenCuaHang` phải cất lại: API chỉ trả về nó ở lượt đăng nhập, làm mới token
 * thì không có, mà thanh tiêu đề thì lúc nào cũng cần hiện.
 */
data class Phien(
    val accessToken: String,
    val refreshToken: String,
    /** Mốc hết hạn của access token, tính bằng millis đồng hồ máy. */
    val hetHanLuc: Long,
    val maCuaHang: String,
    val tenCuaHang: String,
    val tenDangNhap: String,
    val vaiTro: String,
    /** Cửa hàng hết hạn hợp đồng: phiên vẫn dùng được nhưng gần như mọi đường trả 403. */
    val cuaHangKhoa: Boolean,
) {
    /**
     * Còn dùng được không. Trừ hao 60 giây vì token sống có 15 phút — gọi đúng
     * lúc nó vừa hết hạn giữa đường thì người bán hàng lãnh một lỗi vô cớ.
     */
    fun conHan(): Boolean = accessToken.isNotBlank() &&
        System.currentTimeMillis() < hetHanLuc - 60_000
}

/** Ba ô đã nhớ của lần đăng nhập trước. */
data class DangNhapNho(val maCuaHang: String, val tenDangNhap: String, val matKhau: String)

/**
 * Cất phiên đã mã hoá bằng khoá nằm trong Android Keystore.
 *
 * KHÔNG dùng androidx.security:security-crypto: bản 1.1.0 vừa ra đã đánh dấu
 * ngừng dùng. Keystore là thứ thư viện đó bọc bên trong, gọi thẳng thì bớt một
 * phụ thuộc mà vẫn đúng chỗ dựa: khoá nằm trong phần cứng, đọc trộm tệp
 * SharedPreferences ra chỉ được một đống byte.
 */
class KhoPhien(boiCanh: Context) {
    private val o: SharedPreferences =
        boiCanh.getSharedPreferences("phien-dang-nhap", Context.MODE_PRIVATE)

    fun doc(): Phien? {
        val ma = o.getString(K_PHIEN, null) ?: return null
        // Giải mã hỏng = khoá đã mất (gỡ cài, khôi phục máy khác). Coi như chưa
        // đăng nhập, đừng làm app chết ngay lúc mở.
        val chu = runCatching { giaiMa(ma) }.getOrNull() ?: return null
        val j = runCatching { JSONObject(chu) }.getOrNull() ?: return null

        return Phien(
            accessToken = j.optString("access"),
            refreshToken = j.optString("refresh"),
            hetHanLuc = j.optLong("het_han"),
            maCuaHang = j.optString("ma_cua_hang"),
            tenCuaHang = j.optString("ten_cua_hang"),
            tenDangNhap = j.optString("ten_dang_nhap"),
            vaiTro = j.optString("vai_tro"),
            cuaHangKhoa = j.optBoolean("cua_hang_khoa"),
        )
    }

    fun ghi(phien: Phien) {
        val j = JSONObject().apply {
            put("access", phien.accessToken)
            put("refresh", phien.refreshToken)
            put("het_han", phien.hetHanLuc)
            put("ma_cua_hang", phien.maCuaHang)
            put("ten_cua_hang", phien.tenCuaHang)
            put("ten_dang_nhap", phien.tenDangNhap)
            put("vai_tro", phien.vaiTro)
            put("cua_hang_khoa", phien.cuaHangKhoa)
        }

        o.edit()
            .putString(K_PHIEN, maHoa(j.toString()))
            // Mã cửa hàng để trần: không phải bí mật, mà cần đọc được cả sau khi
            // đăng xuất để điền sẵn cho ca sau.
            .putString(K_MA_CUA_HANG, phien.maCuaHang)
            .apply()
    }

    /** Chỉ thay hai token sau một lượt làm mới; phần hồ sơ giữ nguyên. */
    fun ghiTokenMoi(access: String, refresh: String, hetHanLuc: Long) {
        val cu = doc() ?: return
        ghi(cu.copy(accessToken = access, refreshToken = refresh, hetHanLuc = hetHanLuc))
    }

    /** Đăng xuất. Giữ lại mã cửa hàng và phần đã nhớ để lần sau khỏi gõ lại. */
    fun xoa() {
        o.edit().remove(K_PHIEN).apply()
    }

    fun maCuaHangCu(): String = o.getString(K_MA_CUA_HANG, "").orEmpty()

    // -----------------------------------------------------------------------
    // Nhớ mật khẩu
    // -----------------------------------------------------------------------

    /**
     * Ghi cả ba ô để lần sau điền sẵn. Mật khẩu đi qua đúng lớp mã hoá của
     * phiên, và cố ý cất RIÊNG: đăng xuất thì phiên mất còn phần nhớ vẫn còn.
     */
    fun ghiNho(maCuaHang: String, tenDangNhap: String, matKhau: String) {
        val j = JSONObject().apply {
            put("ma_cua_hang", maCuaHang)
            put("ten_dang_nhap", tenDangNhap)
            put("mat_khau", matKhau)
        }
        o.edit().putString(K_NHO, maHoa(j.toString())).apply()
    }

    fun xoaNho() {
        o.edit().remove(K_NHO).apply()
    }

    fun docNho(): DangNhapNho? {
        val ma = o.getString(K_NHO, null) ?: return null
        val chu = runCatching { giaiMa(ma) }.getOrNull() ?: return null
        val j = runCatching { JSONObject(chu) }.getOrNull() ?: return null

        return DangNhapNho(
            maCuaHang = j.optString("ma_cua_hang"),
            tenDangNhap = j.optString("ten_dang_nhap"),
            matKhau = j.optString("mat_khau"),
        )
    }

    // -----------------------------------------------------------------------
    // Mã hoá
    // -----------------------------------------------------------------------

    private fun khoa(): SecretKey {
        val kho = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        (kho.getEntry(BI_DANH, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }

        val sinh = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE)
        sinh.init(
            KeyGenParameterSpec.Builder(
                BI_DANH,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .build(),
        )

        return sinh.generateKey()
    }

    /** Ghép IV vào đầu chuỗi: GCM sinh IV mới mỗi lượt, không có nó thì không giải được. */
    private fun maHoa(chu: String): String {
        val cip = Cipher.getInstance(PHEP).apply { init(Cipher.ENCRYPT_MODE, khoa()) }
        val ra = cip.doFinal(chu.toByteArray())

        return Base64.encodeToString(cip.iv + ra, Base64.NO_WRAP)
    }

    private fun giaiMa(ma: String): String {
        val byte = Base64.decode(ma, Base64.NO_WRAP)
        val cip = Cipher.getInstance(PHEP).apply {
            init(Cipher.DECRYPT_MODE, khoa(), GCMParameterSpec(128, byte, 0, DAI_IV))
        }

        return String(cip.doFinal(byte, DAI_IV, byte.size - DAI_IV))
    }

    private companion object {
        const val ANDROID_KEYSTORE = "AndroidKeyStore"
        const val BI_DANH = "selliotech-phien"
        const val PHEP = "AES/GCM/NoPadding"
        const val DAI_IV = 12
        const val K_PHIEN = "phien"
        const val K_NHO = "nho_dang_nhap"
        const val K_MA_CUA_HANG = "ma_cua_hang"
    }
}
