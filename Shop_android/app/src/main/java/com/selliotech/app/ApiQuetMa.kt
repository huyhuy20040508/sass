package com.selliotech.app

import org.json.JSONObject
import java.net.URLEncoder

/** Kết quả tra một mã vừa quét. */
sealed interface KetQuaQuetMa {
    data class ThayHang(val ten: String, val gia: String, val ton: String, val nguyenVan: String) : KetQuaQuetMa

    data class KhongThay(val loi: String, val nguyenVan: String) : KetQuaQuetMa
}

/**
 * Gọi GET /admin/orders/pos/scan?code=... — đường quét mã tại quầy đã có sẵn bên API.
 * Giữ nguyên văn JSON để còn nhìn tận mắt máy chủ trả về những trường gì.
 */
suspend fun quetMa(kho: KhoPhien, ma: String): KetQuaQuetMa {
    val duong = "/admin/orders/pos/scan?code=" + URLEncoder.encode(ma, "UTF-8")
    val traLoi = goiCoToken(kho, duong)
    val nguyenVan = "HTTP ${traLoi.ma}\n${traLoi.than}"

    val json = traLoi.json()
        ?: return KetQuaQuetMa.KhongThay(traLoi.cauLoi(), nguyenVan)

    if (!traLoi.xuoi || !json.optBoolean("success")) {
        return KetQuaQuetMa.KhongThay(traLoi.cauLoi(), nguyenVan)
    }

    // Chưa biết chắc hình dạng `data`, nên dò vài tên trường quen thuộc rồi vẫn
    // in nguyên văn ra màn hình để đối chiếu.
    val data = json.optJSONObject("data")

    return KetQuaQuetMa.ThayHang(
        ten = data?.doTim("name", "ten", "product_name", "ten_hang").orEmpty(),
        gia = data?.doTim("price", "gia", "sale_price", "gia_ban").orEmpty(),
        ton = data?.doTim("stock", "ton", "quantity", "so_luong").orEmpty(),
        nguyenVan = nguyenVan,
    )
}

/** Trả giá trị của tên trường đầu tiên có mặt, kể cả khi nó nằm trong `product`. */
private fun JSONObject.doTim(vararg ten: String): String {
    val trong = optJSONObject("product") ?: optJSONObject("san_pham")
    for (t in ten) {
        if (has(t) && !isNull(t)) return optString(t)
        if (trong != null && trong.has(t) && !trong.isNull(t)) return trong.optString(t)
    }

    return ""
}
