package com.selliotech.app

import android.os.SystemClock
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

// =====================================================================
//  TRANG HÀNG HOÁ — đọc /products, KHÔNG đụng tới tồn kho.
//
//  Đây là bản mobile của trang "Danh sách hàng hóa" bên web, và nó là
//  MỘT TRANG KHÁC với trang Tồn kho. Hai trang trả lời hai câu hỏi khác
//  nhau: trang này là "cửa hàng đang bán những món gì, giá bao nhiêu,
//  món nào còn bán món nào ẩn"; trang kia là "món ấy còn mấy cái".
//
//  Trộn chúng lại — nhét cột tồn vào danh sách hàng hoá — là chuyện đã
//  làm hỏng một lần: người khai hàng phải lội qua một cột họ không cần,
//  còn người đi đếm kho thì thiếu mọi thứ họ cần.
// =====================================================================

/** Ba trạng thái kinh doanh của một mặt hàng. Khớp `ProductController::STATUSES`. */
enum class TrangThaiHang(val ma: String, val nhan: String, val giaiThich: String) {
    DANG_BAN("active", "Đang bán", "Hiện ngoài cửa hàng, khách mua được."),
    TAM_AN("hidden", "Tạm ẩn", "Không hiện ngoài cửa hàng nhưng vẫn nhập hàng, vẫn tính vào kho."),
    NGUNG("discontinued", "Ngừng kinh doanh", "Không hiện, không nhập thêm. Đơn cũ và báo cáo vẫn tra ra được.");

    companion object {
        /** Mã lạ (hoặc rỗng) coi như đang ẩn — không bịa ra "đang bán" cho một mã không hiểu. */
        fun tu(ma: String): TrangThaiHang = entries.firstOrNull { it.ma == ma } ?: TAM_AN
    }
}

/**
 * Một mặt hàng trên trang danh sách.
 *
 * KHÔNG có trường tồn kho, và đó là chủ ý: /products không trả tồn, mà trang này
 * cũng không hỏi tới nó.
 */
data class MatHang(
    val id: Long,
    val ten: String,
    val sku: String,
    val nhom: String,
    /** % thuế. Hai số âm là MÃ chứ không phải phần trăm — xem `chuVAT`. */
    val vat: Int,
    val donVi: String,
    /** Giá niêm yết. */
    val giaGoc: Double,
    /** Giá khách THỰC TRẢ: khuyến mãi > giá giảm gõ tay > giá niêm yết. */
    val gia: Double,
    /** Tên chương trình khuyến mãi đang kéo giá xuống. Rỗng = không có. */
    val khuyenMai: String,
    /** Tên các chi nhánh quản lý mặt hàng. Rỗng = MỌI chi nhánh. */
    val chiNhanh: String,
    val trangThai: TrangThaiHang,
    /** Số biến thể đang bật. 0 = hàng đơn. */
    val soBienThe: Int,
    val anh: String,
) {
    /** Đang được giảm giá so với giá niêm yết. */
    val dangGiam: Boolean get() = gia < giaGoc
}

/** "10%", "KCT", "KKKNT" — cùng quy ước với `MucThue::chu` bên web. */
fun chuVAT(vat: Int): String = when (vat) {
    -1 -> "KCT"
    -2 -> "KKKNT"
    else -> "$vat%"
}

/** Cách xếp danh sách hàng hoá. Đúng tám kiểu của bản web. */
enum class XepHangHoa(val ma: String, val nhan: String) {
    // Chuỗi RỖNG là "thứ tự người bán tự xếp" — API rơi về `sort DESC` khi không
    // hiểu giá trị nào khác. Chỉ ở kiểu này mới đổi được thứ tự bằng hai mũi
    // tên: đang lọc hay đang sắp theo cột thì "lên một bậc" chẳng còn nghĩa gì,
    // vì mặt hàng ngay trên màn hình chưa chắc là mặt hàng liền kề trong sổ.
    TU_XEP("", "Thứ tự tự xếp"),
    MOI_NHAT("newest", "Mới nhất"),
    TEN_AZ("name_asc", "Tên A → Z"),
    TEN_ZA("name_desc", "Tên Z → A"),
    NHOM_AZ("group_asc", "Nhóm hàng hoá A → Z"),
    NHOM_ZA("group_desc", "Nhóm hàng hoá Z → A"),
    GIA_TANG("price_asc", "Giá tăng dần"),
    GIA_GIAM("price_desc", "Giá giảm dần"),
    BAN_CHAY("best_selling", "Bán chạy"),
}

/**
 * Bộ lọc của trang hàng hoá. Khớp từng ô lọc bên web.
 *
 * Nhóm hàng và trạng thái CHỌN ĐƯỢC NHIỀU — API nhận danh sách ngăn bằng dấu
 * phẩy, nên bản mobile không phải cắt bớt chức năng như trang tồn kho.
 */
data class LocMatHang(
    val nhom: List<DanhMuc> = emptyList(),
    val trangThai: Set<TrangThaiHang> = emptySet(),
    val donViId: Long = 0,
    val donViTen: String = "",
    /** "" = mọi vị trí, "none" = chưa gán vị trí, còn lại là id. */
    val viTri: String = "",
    val viTriTen: String = "",
    /** null = cả hai, true = hàng nhiều biến thể, false = hàng đơn. */
    val nhieuBienThe: Boolean? = null,
    val xep: XepHangHoa = XepHangHoa.MOI_NHAT,
) {
    val coLoc: Boolean get() = soDieuKien > 0

    /** Số điều kiện đang bật. Sắp xếp KHÔNG tính — nó không cắt bớt danh sách. */
    val soDieuKien: Int
        get() = nhom.size + trangThai.size +
            listOf(donViId > 0, viTri.isNotBlank(), nhieuBienThe != null).count { it }
}

/** Một điều kiện đang bật: chữ trên chip, và bộ lọc còn lại sau khi gỡ nó ra. */
data class ChipMatHang(val nhan: String, val con: LocMatHang)

/**
 * Các chip của một bộ lọc.
 *
 * Mỗi nhóm hàng và mỗi trạng thái là MỘT chip riêng, gỡ được từng cái. Gộp bốn
 * điều kiện thành một chip "4 bộ lọc" thì muốn bỏ một cái phải mở tấm lọc ra.
 */
fun LocMatHang.chips(): List<ChipMatHang> = buildList {
    nhom.forEach { n -> add(ChipMatHang(n.ten, copy(nhom = nhom - n))) }
    trangThai.forEach { t -> add(ChipMatHang(t.nhan, copy(trangThai = trangThai - t))) }
    if (donViId > 0) {
        add(ChipMatHang(donViTen.ifBlank { "Một đơn vị tính" }, copy(donViId = 0, donViTen = "")))
    }
    if (viTri.isNotBlank()) {
        add(ChipMatHang(viTriTen.ifBlank { "Một vị trí" }, copy(viTri = "", viTriTen = "")))
    }
    when (nhieuBienThe) {
        true -> add(ChipMatHang("Hàng nhiều biến thể", copy(nhieuBienThe = null)))
        false -> add(ChipMatHang("Hàng đơn", copy(nhieuBienThe = null)))
        null -> Unit
    }
}

/**
 * Đường gọi của một trang hàng hoá.
 *
 * Tách khỏi hàm gọi mạng để kiểm được bằng bộ kiểm thường. `all=true` là chỗ dễ
 * quên nhất: thiếu nó thì API chỉ trả mặt hàng đang bán, và bộ lọc "Tạm ẩn" luôn
 * ra rỗng trong khi vẫn đáp 200 — nhìn y hệt một cửa hàng không có hàng ẩn nào.
 */
fun duongMatHang(
    loc: LocMatHang,
    tuKhoa: String = "",
    trang: Int = 1,
    coTrang: Int = 20,
): String = buildString {
    append("/products?page=").append(trang)
    append("&page_size=").append(coTrang)
    append("&sort=").append(loc.xep.ma)
    append("&all=true")
    if (tuKhoa.isNotBlank()) {
        append("&keyword=").append(URLEncoder.encode(tuKhoa, "UTF-8"))
    }
    if (loc.nhom.isNotEmpty()) {
        append("&category_ids=").append(loc.nhom.joinToString(",") { it.id.toString() })
    }
    if (loc.trangThai.isNotEmpty()) {
        // Xếp theo thứ tự khai của enum để hai bộ lọc giống nhau cho ra đúng một
        // đường — Set không hứa thứ tự, mà đường gọi đổi là bộ nhớ đệm trượt.
        append("&statuses=")
        append(TrangThaiHang.entries.filter { it in loc.trangThai }.joinToString(",") { it.ma })
    }
    if (loc.donViId > 0) append("&unit_id=").append(loc.donViId)
    if (loc.viTri.isNotBlank()) append("&location_id=").append(loc.viTri)
    loc.nhieuBienThe?.let { append("&multi_variant=").append(it) }
}

/** Một trang danh sách hàng hoá, kèm tổng số dòng khớp bộ lọc. */
data class TrangMatHang(val dong: List<MatHang>, val tong: Long, val conNua: Boolean)

/** Danh sách hàng hoá. */
suspend fun layMatHang(
    kho: KhoPhien,
    loc: LocMatHang = LocMatHang(),
    tuKhoa: String = "",
    trang: Int = 1,
    coTrang: Int = 20,
): TrangMatHang? {
    val traLoi = goiCoToken(kho, duongMatHang(loc, tuKhoa, trang, coTrang))
    val json = traLoi.json()
    if (!traLoi.xuoi || json == null) return null

    val mang = json.optJSONArray("data") ?: return null
    val dong = (0 until mang.length()).mapNotNull { i -> mang.optJSONObject(i)?.thanhMatHang() }

    val meta = json.optJSONObject("meta")
    val tong = meta?.optLong("total") ?: dong.size.toLong()
    val tongTrang = meta?.optInt("total_pages") ?: 1

    return TrangMatHang(dong = dong, tong = tong, conNua = trang < tongTrang)
}

private fun JSONObject.thanhMatHang(): MatHang {
    val goc = optDouble("base_price", 0.0)

    // Giá khách THỰC TRẢ, chọn đúng thứ tự tầng thanh toán dùng: giá sau chương
    // trình khuyến mãi > giá giảm gõ tay > giá niêm yết. Đọc mỗi sale_price là
    // đang chạy khuyến mãi thì người bán nhìn thấy con số khác giá ngoài cửa hàng.
    var gia = goc
    listOf("final_price", "sale_price").forEach { khoa ->
        if (!isNull(khoa)) {
            val v = optDouble(khoa, 0.0)
            if (v > 0 && v < gia) gia = v
        }
    }

    val trangThai = if (isNull("status") || optString("status").isBlank()) {
        // Bản ghi cũ chưa có cột status: suy từ cờ is_active, đúng như bên web.
        if (optBoolean("is_active", false)) TrangThaiHang.DANG_BAN else TrangThaiHang.TAM_AN
    } else {
        TrangThaiHang.tu(optString("status"))
    }

    return MatHang(
        id = optLong("id"),
        ten = optString("name"),
        sku = chuoiJson("sku"),
        nhom = optJSONObject("category")?.let { it.optString("name") }.orEmpty(),
        vat = optInt("vat"),
        donVi = optJSONObject("unit")?.let { it.optString("name") }.orEmpty(),
        giaGoc = goc,
        gia = gia,
        khuyenMai = chuoiJson("promotion_name"),
        chiNhanh = optJSONArray("shops").tenGop(),
        trangThai = trangThai,
        // Hàng đơn vẫn có đúng một dòng biến thể mặc định — đếm ra 1 thì ghi 0,
        // không thì danh sách nói "1 biến thể" cho một món không có biến thể nào.
        soBienThe = if (optBoolean("is_multi_variant", false)) {
            optJSONArray("variants").demDangBat()
        } else {
            0
        },
        anh = chuoiJson("thumbnail"),
    )
}

/** Ghép tên các phần tử của một mảng đối tượng, bỏ phần rỗng. */
private fun JSONArray?.tenGop(): String {
    if (this == null) return ""

    return (0 until length())
        .mapNotNull { optJSONObject(it)?.optString("name") }
        .filter { it.isNotBlank() }
        .joinToString(", ")
}

/** Đếm phần tử còn bật. Thiếu cờ `is_active` thì coi như bật. */
private fun JSONArray?.demDangBat(): Int {
    if (this == null) return 0

    return (0 until length()).count { optJSONObject(it)?.optBoolean("is_active", true) ?: false }
}

/** Chuỗi của một khoá, JSON null thành rỗng. */
private fun JSONObject.chuoiJson(khoa: String): String =
    if (isNull(khoa)) "" else optString(khoa)

/**
 * Đổi trạng thái kinh doanh của một mặt hàng.
 *
 * Gửi ĐÚNG một trường status tới đường chuyên biệt, không PUT lại cả mặt hàng:
 * API ghi cả dòng khi PUT, thiếu một trường nào đó là bấm đổi trạng thái một cái
 * mất luôn dữ liệu — đúng bài học đã ghi trong ProductController bên web.
 *
 * Trả câu lỗi, hoặc null nếu xuôi.
 */
suspend fun doiTrangThaiHang(kho: KhoPhien, id: Long, moi: TrangThaiHang): String? {
    val than = JSONObject().put("status", moi.ma).toString()
    val traLoi = goiCoToken(kho, "/admin/products/$id/status", "PUT", than)
    if (traLoi.xuoi) return null

    return when (traLoi.ma) {
        403 -> "Tài khoản không có quyền sửa hàng hoá."
        else -> traLoi.json()?.optString("message")?.takeIf { it.isNotBlank() }
            ?: "Không đổi được trạng thái."
    }
}

/** Một đơn vị tính hoặc một vị trí — cùng hình dạng: mã ngắn + tên. */
data class OChon(val id: Long, val ma: String, val ten: String) {
    /** "CAI · Cái" — mã đứng trước như bên web, vì người khai hàng nhớ mã. */
    val nhan: String get() = if (ma.isBlank()) ten else "$ma · $ten"
}

/**
 * Đơn vị tính đang dùng được.
 *
 * Trả null khi gọi hỏng — kể cả 403. Tài khoản chỉ có quyền xem hàng hoá mà
 * không có `don-vi-tinh.xem` là chuyện bình thường; lúc đó tấm lọc giấu hẳn mục
 * này đi chứ không bày một ô rỗng rồi để người ta bấm mãi.
 */
suspend fun layDonViTinh(kho: KhoPhien): List<OChon>? =
    layOChon(kho, "/admin/don-vi-tinh?active=true")

/** Vị trí để hàng đang dùng được. */
suspend fun layViTri(kho: KhoPhien): List<OChon>? =
    layOChon(kho, "/admin/vi-tri?active=true")

private suspend fun layOChon(kho: KhoPhien, duong: String): List<OChon>? {
    val traLoi = goiCoToken(kho, duong)
    val json = traLoi.json()
    if (!traLoi.xuoi || json == null) return null

    val mang = json.optJSONArray("data") ?: return null

    return (0 until mang.length()).mapNotNull { i ->
        mang.optJSONObject(i)?.let {
            OChon(id = it.optLong("id"), ma = it.optString("code"), ten = it.optString("name"))
        }
    }
}

// =====================================================================
//  KHAI MỘT MẶT HÀNG MỚI
// =====================================================================

/** Một thuộc tính kèm các giá trị của nó: "Dung lượng" -> 128GB, 256GB. */
data class ThuocTinhHang(val id: Long, val ten: String, val giaTri: List<OChon>)

/**
 * MỘT tổ hợp biến thể sắp khai.
 *
 * `chon` là danh sách cặp (thuộc tính, giá trị) làm nên tổ hợp này. `gia` bỏ
 * trống = lấy giá của mặt hàng cha, đúng quy ước API — khai đè chỉ khi biến thể
 * ấy thật sự bán giá khác.
 */
data class BienTheMoi(
    val chon: List<Pair<Long, Long>>,
    val ten: String,
    val gia: Double? = null,
)

/**
 * MỘT dòng quy đổi đơn vị: 1 <đơn vị này> = <soLuong> đơn vị tính chính.
 *
 * `soLuong` là số THỰC chứ không phải số nguyên — "1 Thùng = 0,5 Tạ" là chuyện
 * có thật ở hàng cân, ép số nguyên là bắt người khai đổi ngược đơn vị chính.
 */
data class QuyDoi(val donViId: Long, val soLuong: Double)

/**
 * Mặt hàng sắp khai.
 *
 * Đủ các ô của hộp thoại bên web, trừ ba thứ app không làm được:
 *
 * | Không có | Vì sao |
 * |---|---|
 * | Ảnh đại diện | Ảnh do trang web lưu trên máy chủ WEB bằng phiên của nó; app không có đường đẩy file lên đó |
 * | Quy đổi đơn vị | Một bảng con nhiều dòng — cần màn riêng, không nhét vào tấm trượt |
 * | Thuộc tính & biến thể | Tab thứ hai của hộp thoại web, cũng là một bộ soạn thảo riêng |
 *
 * Mọi ô còn lại đều có mặt và gửi đúng quy ước con trỏ của API: KHÔNG GỬI khác
 * hẳn GỬI 0 — không gửi là "để máy chủ lấy mặc định", gửi 0 là "gỡ ra".
 */
data class HangMoi(
    val ten: String,
    /** Địa chỉ ảnh đã tải lên. Rỗng = chưa có ảnh. Xem `taiAnhLen`. */
    val anh: String = "",
    val nhomId: Long,
    val giaBan: Double,
    /** Bỏ trống = để máy chủ tự đặt theo quy tắc mã hàng hoá của cửa hàng. */
    val sku: String = "",
    /** Mã vạch in trên hàng. Nằm ở BIẾN THỂ chứ không phải mặt hàng — xem `taoMatHang`. */
    val maVach: String = "",
    /** null = CHƯA KHAI giá vốn, khác hẳn khai bằng 0. */
    val giaVon: Double? = null,
    val donViId: Long = 0,
    val viTriId: Long = 0,
    /** null = lấy mức thuế MẶC ĐỊNH CỦA NHÓM, đúng quy tắc bên web. */
    val vat: Int? = null,
    /** Rỗng = MỌI chi nhánh, đúng quy ước bảng product_shops. */
    val chiNhanhIds: List<Long> = emptyList(),
    /** Quy đổi đơn vị. Rỗng = không có dòng nào (và lúc SỬA là "xoá hết dòng cũ"). */
    val quyDoi: List<QuyDoi> = emptyList(),
    /** Tổ hợp biến thể. Chỉ gửi khi `nhieuBienThe` bật, và CHỈ lúc khai mới. */
    val bienThe: List<BienTheMoi> = emptyList(),
    /**
     * TÊN biến thể, dùng cho lượt nhập từ tệp CSV.
     *
     * Khác `bienThe` ở chỗ chỉ có tên, không có tổ hợp thuộc tính — tệp CSV
     * không khai được chiều nào ứng với giá trị nào (đúng như bản web). Máy chủ
     * nhận tên trần và tự dựng dòng biến thể.
     */
    val bienTheTen: List<String> = emptyList(),
    /** TÊN thẻ chứ không phải id — tên chưa có thì máy chủ tự mở dòng mới. */
    val the: List<String> = emptyList(),
    val trangThai: TrangThaiHang = TrangThaiHang.DANG_BAN,
    /** Ba công tắc của cột trái hộp thoại web. Mặc định giống hệt bản web. */
    val inTem: Boolean = true,
    val truKho: Boolean = true,
    val theoSeri: Boolean = false,
    val moTaNgan: String = "",
    val moTa: String = "",
    val metaTitle: String = "",
    val metaMoTa: String = "",
    /**
     * Mặt hàng có nhiều biến thể không. Chỉ dùng lúc SỬA, và chỉ để quyết định
     * có được gửi mã vạch đi hay không — xem `suaMatHang`.
     */
    val nhieuBienThe: Boolean = false,
) {
    /** Đủ ba ô bắt buộc chưa. Nút Lưu khoá cho tới khi đủ. */
    val duOChinh: Boolean
        get() = ten.isNotBlank() && nhomId > 0 && giaBan >= 0
}

/**
 * Giá sau thuế, đúng công thức ô "Giá sau thuế" bên web.
 *
 * Hai mức âm (KCT, KKKNT) là MÃ chứ không phải phần trăm — nhân giá với -1 là ra
 * một con số âm vô nghĩa. Chưa chọn mức thuế thì cũng không tính được, vì lúc ấy
 * mức thật sự nằm ở nhóm hàng mà app chưa biết.
 */
fun giaSauThue(giaBan: Double, vat: Int?): Double? {
    if (vat == null || vat <= 0) return null

    return giaBan * (1 + vat / 100.0)
}

/**
 * Đường dẫn (slug) sinh từ tên hàng: "Bút bi Thiên Long" -> "but-bi-thien-long".
 *
 * API bắt buộc có slug và bắt nó DUY NHẤT. Sinh y hệt cách bản web sinh — bỏ
 * dấu, hạ chữ thường, mọi thứ không phải chữ-số thành gạch nối — để hai bên khai
 * cùng một tên thì ra cùng một đường dẫn, không đẻ ra hai mặt hàng nhìn giống
 * nhau mà đường dẫn lệch nhau một dấu.
 *
 * Tên toàn ký tự lạ (chỉ emoji chẳng hạn) thì trả rỗng; nơi gọi phải tự lo, vì
 * bịa ra một slug ngẫu nhiên là mai mốt không ai tra ngược được về mặt hàng nào.
 */
fun slugTu(ten: String): String =
    boDau(ten)
        .lowercase()
        .map { if (it.isLetterOrDigit()) it else '-' }
        .joinToString("")
        .split('-')
        .filter { it.isNotBlank() }
        .joinToString("-")

/**
 * Khai một mặt hàng mới. Trả null = xuôi, khác null = câu lỗi để bắn ra toast.
 *
 * Chỉ gửi những trường người dùng thật sự điền. `unit_id`, `vat`, `cost_price`
 * đều là con trỏ bên API: GỬI 0 khác hẳn KHÔNG GỬI — gửi 0 là "gỡ đơn vị / thuế
 * 0% / giá vốn bằng 0", còn không gửi mới là "để máy chủ lấy mặc định".
 */
suspend fun taoMatHang(kho: KhoPhien, moi: HangMoi): String? {
    val traLoi = goiCoToken(kho, "/admin/products", "POST", thanMatHang(moi).toString())
    if (traLoi.xuoi) return null

    return traLoi.cauLoiTao()
}

/**
 * Thân JSON của một mặt hàng, dùng chung cho cả lượt khai mới lẫn lượt sửa.
 *
 * Chỉ gửi những trường người dùng thật sự điền. `unit_id`, `location_id`, `vat`,
 * `cost_price` đều là con trỏ bên API: GỬI 0 khác hẳn KHÔNG GỬI — gửi 0 là "gỡ
 * đơn vị / gỡ vị trí / thuế 0% / giá vốn bằng 0", còn không gửi mới là "để máy
 * chủ giữ nguyên hoặc lấy mặc định".
 */
private fun thanMatHang(moi: HangMoi, guiMaVach: Boolean = true): JSONObject = JSONObject().apply {
    put("category_id", moi.nhomId)
    put("name", moi.ten.trim())
    put("slug", slugTu(moi.ten))
    put("base_price", moi.giaBan)
    put("status", moi.trangThai.ma)
    put("print_label", moi.inTem)
    put("is_stock_deducted", moi.truKho)
    put("is_serial", moi.theoSeri)
    put("short_description", moi.moTaNgan.trim())
    put("description", moi.moTa.trim())
    put("meta_title", moi.metaTitle.trim())
    put("meta_description", moi.metaMoTa.trim())
    put("thumbnail", moi.anh)
    if (moi.sku.isNotBlank()) put("sku", moi.sku.trim())
    if (moi.donViId > 0) put("unit_id", moi.donViId)
    if (moi.viTriId > 0) put("location_id", moi.viTriId)
    moi.giaVon?.let { put("cost_price", it) }
    moi.vat?.let { put("vat", it) }
    // Chi nhánh: mảng RỖNG là ý muốn thật ("bán ở mọi chi nhánh"), nên vẫn gửi
    // kể cả khi rỗng. Thẻ cũng vậy.
    put("shop_ids", JSONArray(moi.chiNhanhIds))
    put("tags", JSONArray(moi.the))
    // Quy đổi: cùng quy ước với chi nhánh và thẻ — biểu mẫu này NẮM ĐƯỢC cả cụm
    // nên luôn gửi, kể cả mảng rỗng ("xoá hết dòng quy đổi").
    put(
        "unit_conversions",
        JSONArray().apply {
            moi.quyDoi.forEach {
                put(JSONObject().put("unit_id", it.donViId).put("quantity", it.soLuong))
            }
        },
    )
    // Nhập từ tệp: chỉ có TÊN biến thể, không có tổ hợp thuộc tính.
    if (moi.bienTheTen.isNotEmpty()) {
        put("is_multi_variant", true)
        put(
            "variants",
            JSONArray().apply {
                moi.bienTheTen.forEachIndexed { i, ten ->
                    put(
                        JSONObject().apply {
                            put("id", 0)
                            put("pos", i)
                            put("name", ten)
                            put("is_active", true)
                        },
                    )
                }
            },
        )

        return@apply
    }

    // Hàng NHIỀU BIẾN THỂ: gửi cả cụm tổ hợp. Mỗi dòng mang danh sách cặp
    // (thuộc tính, giá trị); tên biến thể để máy chủ tự ghép từ đó, chứ app tự
    // nghĩ ra công thức đặt tên là hàng thêm từ điện thoại và hàng thêm từ web
    // thành hai kiểu tên khác nhau.
    if (moi.nhieuBienThe && moi.bienThe.isNotEmpty()) {
        put("is_multi_variant", true)
        put(
            "variants",
            JSONArray().apply {
                moi.bienThe.forEachIndexed { i, b ->
                    put(
                        JSONObject().apply {
                            put("id", 0)
                            put("pos", i)
                            put("is_active", true)
                            b.gia?.let { put("price", it) }
                            put(
                                "attributes",
                                JSONArray().apply {
                                    b.chon.forEach { (thuocTinh, giaTri) ->
                                        put(
                                            JSONObject()
                                                .put("attribute_id", thuocTinh)
                                                .put("value_id", giaTri),
                                        )
                                    }
                                },
                            )
                        },
                    )
                }
            },
        )

        return@apply
    }

    // Mã vạch nằm ở BIẾN THỂ, không phải ở mặt hàng. Hàng đơn vẫn có đúng một
    // dòng biến thể mặc định, nên dán mã lên chính dòng đó — gửi kèm một biến
    // thể không tên, không thuộc tính, đúng như bản web làm.
    if (guiMaVach && moi.maVach.isNotBlank()) {
        put(
            "variants",
            JSONArray().put(
                JSONObject().apply {
                    put("id", 0)
                    put("barcode", moi.maVach.trim())
                    put("is_active", true)
                },
            ),
        )
    }
}

/**
 * Câu lỗi đọc được cho một lượt khai hàng.
 *
 * Lỗi 422 để câu HỮU ÍCH trong `errors` (theo từng ô), còn `message` chỉ là
 * "Dữ liệu không hợp lệ" — đọc mỗi `message` là ném đúng câu vô nghĩa ấy ra
 * trước mặt người dùng trong khi máy chủ đã nói rõ ô nào sai.
 */
private fun TraLoi.cauLoiTao(): String {
    val json = json()

    json?.optJSONObject("errors")?.let { o ->
        val cau = o.keys().asSequence()
            .mapNotNull { k -> o.optString(k).takeIf { it.isNotBlank() } }
            .toList()
        if (cau.isNotEmpty()) return cau.joinToString("\n")
    }

    json?.optString("message")?.takeIf { it.isNotBlank() }?.let { return it }

    return when (ma) {
        0 -> "Không kết nối được máy chủ. Kiểm tra mạng rồi thử lại."
        403 -> "Tài khoản không có quyền thêm hàng hoá."
        else -> "Không lưu được mặt hàng (HTTP $ma)."
    }
}

/**
 * Chi nhánh đang mở.
 *
 * Trả null khi gọi hỏng hoặc không có quyền `chi-nhanh.xem` — nơi gọi giấu hẳn
 * mục ấy đi. Cửa hàng một chi nhánh cũng chẳng cần ô này.
 */
suspend fun layChiNhanh(kho: KhoPhien): List<OChon>? =
    layOChon(kho, "/admin/chi-nhanh?active=true")

/** Thẻ hàng hoá đã có. Người dùng chọn lại thẻ cũ; thẻ mới thì gõ trên web. */
suspend fun layTheHang(kho: KhoPhien): List<OChon>? =
    layOChon(kho, "/admin/the-hang-hoa")

// =====================================================================
//  TẢI ẢNH LÊN
// =====================================================================

/** Kết quả một lượt tải ảnh: có `url` là xuôi, có `loi` là hỏng. */
data class KetQuaAnh(val url: String = "", val loi: String = "") {
    val xuoi: Boolean get() = url.isNotBlank()
}

/**
 * Tải một tấm ảnh lên, trả về địa chỉ để gán vào `thumbnail` của mặt hàng.
 *
 * Tự dựng thân multipart bằng tay chứ không kéo thêm thư viện HTTP: cả app đang
 * gọi mạng bằng `HttpURLConnection` trần, và multipart chỉ là mấy dòng ranh giới
 * cộng phần đầu — thêm cả một tầng HTTP thứ hai cho đúng một lượt gọi là đổi
 * kiến trúc vì một việc nhỏ.
 *
 * Ghi thẳng ra luồng của kết nối (`setChunkedStreamingMode`) chứ không gom cả
 * ảnh vào một mảng byte thứ hai: ảnh điện thoại 3–5MB, gom thêm một bản nữa là
 * lúc máy yếu bộ nhớ thì đúng chỗ này chết.
 */
suspend fun taiAnhLen(kho: KhoPhien, byte: ByteArray, tenTep: String): KetQuaAnh =
    withContext(Dispatchers.IO) {
        val token = tokenSong(kho) ?: return@withContext KetQuaAnh(loi = "Phiên đăng nhập đã hết hạn.")

        val batDau = SystemClock.elapsedRealtime()
        val ranh = "----selliotech" + System.nanoTime()
        var ket: HttpURLConnection? = null
        try {
            ket = (URL("$BASE_URL/admin/products/anh").openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                doOutput = true
                setRequestProperty("Accept", "application/json")
                setRequestProperty("Authorization", "Bearer $token")
                setRequestProperty("Content-Type", "multipart/form-data; boundary=$ranh")
                connectTimeout = 15_000
                // Ảnh nặng hơn JSON cả nghìn lần, mà mạng 3G ngoài chợ thì chậm.
                // Giữ 15 giây như mọi lượt gọi khác là ảnh nào hơi lớn cũng đứt.
                readTimeout = 60_000
                setChunkedStreamingMode(16 * 1024)
            }

            ket.outputStream.use { ra ->
                ra.write(
                    (
                        "--$ranh\r\n" +
                            "Content-Disposition: form-data; name=\"file\"; filename=\"$tenTep\"\r\n" +
                            "Content-Type: application/octet-stream\r\n\r\n"
                        ).toByteArray()
                )
                ra.write(byte)
                ra.write("\r\n--$ranh--\r\n".toByteArray())
            }

            val ma = ket.responseCode
            val luong = if (ma in 200..299) ket.inputStream else ket.errorStream
            val than = luong?.bufferedReader()?.use { it.readText() }.orEmpty()
            val json = runCatching { JSONObject(than) }.getOrNull()
            ghiGio("POST", "/admin/products/anh (${byte.size}B)", ma, batDau, than.length)

            if (ma in 200..299) {
                val url = json?.optJSONObject("data")?.optString("url").orEmpty()
                if (url.isBlank()) {
                    KetQuaAnh(loi = "Máy chủ không trả về địa chỉ ảnh.")
                } else {
                    KetQuaAnh(url = url)
                }
            } else {
                KetQuaAnh(
                    loi = json?.optString("message")?.takeIf { it.isNotBlank() }
                        ?: when (ma) {
                            403 -> "Tài khoản không có quyền tải ảnh."
                            404 -> "Máy chủ chưa có đường tải ảnh — cần cập nhật API."
                            else -> "Không tải được ảnh (HTTP $ma)."
                        },
                )
            }
        } catch (e: Exception) {
            ghiGio("POST", "/admin/products/anh", 0, batDau, 0)
            KetQuaAnh(loi = "Không gửi được ảnh. Kiểm tra mạng rồi thử lại.")
        } finally {
            ket?.disconnect()
        }
    }

// =====================================================================
//  SỬA MỘT MẶT HÀNG ĐÃ CÓ
// =====================================================================

/**
 * Đọc lại đầy đủ một mặt hàng để đổ vào biểu mẫu sửa.
 *
 * Phải gọi riêng chứ không dùng dòng đang có trong danh sách: danh sách chỉ mang
 * những gì bảng cần bày (tên, mã, giá, trạng thái), còn mô tả, meta, ba công tắc
 * và mã vạch thì không. Đổ biểu mẫu bằng dữ liệu thiếu rồi bấm Lưu là XOÁ SẠCH
 * đúng những ô không được đổ.
 */
suspend fun layChiTietHang(kho: KhoPhien, id: Long): HangMoi? {
    val traLoi = goiCoToken(kho, "/admin/products/$id")
    val data = traLoi.json()?.optJSONObject("data")
    if (!traLoi.xuoi || data == null) return null

    // Mã vạch nằm ở biến thể. Hàng đơn có đúng một dòng mặc định nên lấy dòng
    // đầu; hàng nhiều biến thể thì mỗi biến thể một mã, biểu mẫu này không sửa
    // được nên để trống và cũng KHÔNG gửi đi (xem `suaMatHang`).
    val nhieuBienThe = data.optBoolean("is_multi_variant", false)
    val maVach = if (nhieuBienThe) {
        ""
    } else {
        data.optJSONArray("variants")?.optJSONObject(0)?.let {
            if (it.isNull("barcode")) "" else it.optString("barcode")
        }.orEmpty()
    }

    val chiNhanh = data.optJSONArray("shops")
    val the = data.optJSONArray("tags")
    val quyDoi = data.optJSONArray("unit_conversions")

    return HangMoi(
        anh = if (data.isNull("thumbnail")) "" else data.optString("thumbnail"),
        ten = data.optString("name"),
        nhomId = data.optLong("category_id"),
        giaBan = data.optDouble("base_price", 0.0),
        sku = if (data.isNull("sku")) "" else data.optString("sku"),
        maVach = maVach,
        giaVon = if (data.isNull("cost_price")) null else data.optDouble("cost_price", 0.0),
        donViId = if (data.isNull("unit_id")) 0 else data.optLong("unit_id"),
        viTriId = if (data.isNull("location_id")) 0 else data.optLong("location_id"),
        vat = if (data.isNull("vat")) null else data.optInt("vat"),
        chiNhanhIds = (0 until (chiNhanh?.length() ?: 0)).mapNotNull {
            chiNhanh?.optJSONObject(it)?.optLong("id")
        },
        the = (0 until (the?.length() ?: 0)).mapNotNull {
            the?.optJSONObject(it)?.optString("name")?.takeIf { t -> t.isNotBlank() }
        },
        quyDoi = (0 until (quyDoi?.length() ?: 0)).mapNotNull { i ->
            quyDoi?.optJSONObject(i)?.let {
                val donVi = it.optLong("unit_id")
                val so = it.optDouble("quantity", 0.0)
                if (donVi > 0 && so > 0) QuyDoi(donVi, so) else null
            }
        },
        trangThai = TrangThaiHang.tu(data.optString("status")),
        inTem = data.optBoolean("print_label", true),
        truKho = data.optBoolean("is_stock_deducted", true),
        theoSeri = data.optBoolean("is_serial", false),
        moTaNgan = if (data.isNull("short_description")) "" else data.optString("short_description"),
        moTa = if (data.isNull("description")) "" else data.optString("description"),
        metaTitle = if (data.isNull("meta_title")) "" else data.optString("meta_title"),
        metaMoTa = if (data.isNull("meta_description")) "" else data.optString("meta_description"),
        nhieuBienThe = nhieuBienThe,
    )
}

/**
 * Ghi đè một mặt hàng đã có.
 *
 * Gửi ĐÚNG những ô biểu mẫu này hỏi, không gửi gì thêm. API dùng quy ước con
 * trỏ: khoá vắng mặt = giữ nguyên. Nhờ vậy sửa giá trên điện thoại không đụng
 * tới biến thể, quy đổi đơn vị hay giá khuyến mãi — những thứ chỉ khai được
 * trên web và biểu mẫu này không nắm.
 *
 * Mã vạch của hàng NHIỀU BIẾN THỂ thì không gửi: gửi một biến thể trần lên là
 * API hiểu "đồng bộ danh sách biến thể theo đúng cái này", tức xoá sạch mọi
 * biến thể còn lại.
 */
suspend fun suaMatHang(kho: KhoPhien, id: Long, moi: HangMoi): String? {
    val than = thanMatHang(moi, guiMaVach = !moi.nhieuBienThe).toString()
    val traLoi = goiCoToken(kho, "/admin/products/$id", "PUT", than)
    if (traLoi.xuoi) return null

    return traLoi.cauLoiTao()
}

// =====================================================================
//  BA THAO TÁC CÒN LẠI CỦA CỘT "THAO TÁC" BÊN WEB
// =====================================================================

/**
 * Sao chép một mặt hàng.
 *
 * Bản sao ra ở trạng thái TẠM ẨN — đúng như bản web. Sao chép xong mà bản mới
 * đứng ngay ngoài cửa hàng với giá y hệt là bán nhầm một món chưa ai kịp sửa.
 */
suspend fun saoChepMatHang(kho: KhoPhien, id: Long): String? {
    val traLoi = goiCoToken(kho, "/admin/products/$id/duplicate", "POST", "{}")

    return if (traLoi.xuoi) null else traLoi.cauLoiTao()
}

/** Xoá một mặt hàng. Xoá mềm bên API — đơn cũ và báo cáo vẫn tra ra được. */
suspend fun xoaMatHang(kho: KhoPhien, id: Long): String? {
    val traLoi = goiCoToken(kho, "/admin/products/$id", "DELETE")

    return if (traLoi.xuoi) null else traLoi.cauLoiTao()
}

/**
 * Đưa một mặt hàng lên trên hoặc xuống dưới trong thứ tự tự xếp.
 *
 * `huong` chỉ nhận "up" hoặc "down" — API chặn giá trị khác. Đã ở đầu (hoặc
 * cuối) danh sách thì API trả 409 kèm câu nói thẳng, và app bắn nguyên câu ấy
 * ra chứ không nuốt đi: người dùng bấm mà không thấy gì nhúc nhích sẽ bấm tiếp.
 */
suspend fun doiThuTuMatHang(kho: KhoPhien, id: Long, len: Boolean): String? {
    val than = JSONObject().put("huong", if (len) "up" else "down").toString()
    val traLoi = goiCoToken(kho, "/admin/products/$id/sort", "PUT", than)

    return if (traLoi.xuoi) null else traLoi.cauLoiTao()
}

/**
 * Thuộc tính đang dùng được, kèm giá trị của từng cái.
 *
 * Trả null khi gọi hỏng hoặc không có quyền `thuoc-tinh.xem` — nơi gọi giấu hẳn
 * khối biến thể đi. Thuộc tính không có giá trị nào thì bỏ luôn: bày ra một cái
 * tên không tick được gì chỉ tổ khiến người dùng tưởng màn hình hỏng.
 */
suspend fun layThuocTinh(kho: KhoPhien): List<ThuocTinhHang>? {
    val traLoi = goiCoToken(kho, "/admin/thuoc-tinh?active=true")
    val json = traLoi.json()
    if (!traLoi.xuoi || json == null) return null

    val mang = json.optJSONArray("data") ?: return null

    return (0 until mang.length()).mapNotNull { i ->
        val o = mang.optJSONObject(i) ?: return@mapNotNull null
        val gt = o.optJSONArray("values")
        val giaTri = (0 until (gt?.length() ?: 0)).mapNotNull { j ->
            gt?.optJSONObject(j)?.let {
                OChon(id = it.optLong("id"), ma = it.optString("code"), ten = it.optString("name"))
            }
        }
        if (giaTri.isEmpty()) null else ThuocTinhHang(o.optLong("id"), o.optString("name"), giaTri)
    }
}

/**
 * Sinh mọi tổ hợp từ những giá trị đã tick — tích Descartes theo đúng thứ tự
 * thuộc tính.
 *
 * Tách khỏi giao diện để kiểm được: đây là chỗ một dòng sai là khai ra thừa
 * hoặc thiếu hẳn một mặt hàng con, mà nhìn danh sách thì không ai đếm nổi.
 *
 * Thuộc tính nào không tick giá trị nào thì BỎ QUA, không nhân với rỗng — nhân
 * với rỗng là ra không tổ hợp nào và cả khối biến thể biến mất trong im lặng.
 */
fun toHopBienThe(
    dsThuocTinh: List<ThuocTinhHang>,
    daChon: Map<Long, List<Long>>,
): List<BienTheMoi> {
    val dung = dsThuocTinh.mapNotNull { tt ->
        val gt = daChon[tt.id].orEmpty().mapNotNull { id -> tt.giaTri.firstOrNull { it.id == id } }
        if (gt.isEmpty()) null else tt to gt
    }
    if (dung.isEmpty()) return emptyList()

    var ra = listOf<List<Pair<Pair<Long, Long>, String>>>(emptyList())
    dung.forEach { (tt, gt) ->
        ra = ra.flatMap { cu -> gt.map { g -> cu + ((tt.id to g.id) to g.ten) } }
    }

    return ra.map { hang ->
        BienTheMoi(
            chon = hang.map { it.first },
            // Tên ghép bằng dấu chấm giữa, đúng lối "128GB · Đen" của máy chủ —
            // đây chỉ là chữ bày ra cho người khai xem trước, còn tên thật vẫn
            // do máy chủ ghép lúc lưu.
            ten = hang.joinToString(" · ") { it.second },
        )
    }
}

// =====================================================================
//  XUẤT TỆP CSV
// =====================================================================

/** Số dòng mỗi lượt gọi lúc xuất. Bằng bản web, và cũng là mức tối đa API cho. */
private const val CO_TRANG_XUAT = 100

/**
 * Số trang tối đa một lượt xuất chịu lật.
 *
 * Chặn ở đây chứ không lật vô hạn: kho vài chục nghìn mặt hàng thì lượt xuất trở
 * thành hàng trăm lượt gọi mạng trên một cái điện thoại đang cầm tay, và người
 * dùng không có cách nào biết nó còn chạy tới bao giờ.
 */
private const val TRANG_TOI_DA_XUAT = 50

/** Kết quả một lượt xuất: nội dung tệp, hoặc câu lỗi. */
data class KetQuaXuat(val noiDung: String = "", val soDong: Int = 0, val loi: String = "") {
    val xuoi: Boolean get() = loi.isEmpty()
}

/**
 * Xuất danh sách hàng hoá ĐANG LỌC ra nội dung tệp CSV.
 *
 * Xuất theo đúng bộ lọc và từ khoá đang xem, không phải cả kho — người ta lọc ra
 * một nhóm rồi mới bấm xuất, đưa họ cả kho là sai ý.
 *
 * TỆP THIẾU DỮ LIỆU MÀ IM LẶNG LÀ TỆ NHẤT: mở ra thấy đủ cột, đủ định dạng,
 * không có cách nào biết mình đang cầm bản cắt dở. Nên đứt giữa chừng hay vượt
 * mức trang đều TRẢ LỖI, không trả về tệp nửa vời.
 */
suspend fun xuatHangHoaCsv(
    kho: KhoPhien,
    loc: LocMatHang,
    tuKhoa: String,
    onTienDo: (daLay: Int, tong: Int) -> Unit = { _, _ -> },
): KetQuaXuat {
    val hang = mutableListOf<JSONObject>()
    var trang = 1
    var tongTrang = 1
    var tong = 0

    do {
        val traLoi = goiCoToken(kho, duongMatHang(loc, tuKhoa, trang, CO_TRANG_XUAT))
        val json = traLoi.json()
        if (!traLoi.xuoi || json == null) {
            return KetQuaXuat(
                loi = "Máy chủ ngắt giữa chừng, mới lấy được ${hang.size}/$tong mặt hàng. Thử lại.",
            )
        }

        val mang = json.optJSONArray("data") ?: break
        (0 until mang.length()).forEach { i -> mang.optJSONObject(i)?.let { hang += it } }

        val meta = json.optJSONObject("meta")
        tongTrang = meta?.optInt("total_pages") ?: 1
        tong = meta?.optInt("total") ?: hang.size
        onTienDo(hang.size, tong)
        trang++
    } while (trang <= tongTrang && trang <= TRANG_TOI_DA_XUAT)

    if (tong > hang.size) {
        return KetQuaXuat(
            loi = "Bộ lọc đang có $tong mặt hàng, vượt mức ${TRANG_TOI_DA_XUAT * CO_TRANG_XUAT} " +
                "mỗi lượt xuất. Lọc hẹp lại rồi xuất thành nhiều đợt.",
        )
    }

    val noiDung = buildString {
        append(BOM)
        append(dongCsv(COT_XUAT))
        hang.forEach { append(dongCsv(it.thanhDongCsv())) }
    }

    return KetQuaXuat(noiDung = noiDung, soDong = hang.size)
}

/** Một mặt hàng thành một dòng của tệp xuất. Đúng 14 ô, đúng thứ tự `COT_XUAT`. */
private fun JSONObject.thanhDongCsv(): List<String> {
    val bienThe = optJSONArray("variants")
    val soBienThe = bienThe?.length() ?: 0
    val ton = (0 until soBienThe).sumOf { bienThe?.optJSONObject(it)?.optInt("stock_quantity") ?: 0 }

    val viTri = optJSONObject("location")
    val chiNhanh = optJSONArray("shops")
    val the = optJSONArray("tags")

    return listOf(
        "SP" + optLong("id").toString().padStart(6, '0'),
        chuoiJson("sku"),
        optString("name"),
        optJSONObject("category")?.optString("name").orEmpty(),
        optJSONObject("unit")?.optString("name").orEmpty(),
        // Mã + tên: người cầm tệp đi soạn hàng đọc mã trên kệ, còn tên là để đối
        // chiếu khi mã dán bị mờ.
        listOfNotNull(viTri?.optString("code"), viTri?.optString("name"))
            .filter { it.isNotBlank() }
            .joinToString(" "),
        chuVAT(optInt("vat")),
        optDouble("base_price", 0.0).toLong().toString(),
        // Ô TRỐNG = chưa khai giá vốn. Không đổi thành 0, người đọc tệp sẽ tưởng
        // hàng này giá vốn bằng không.
        if (isNull("cost_price")) "" else optDouble("cost_price", 0.0).toLong().toString(),
        ton.toString(),
        // Ô trống = mọi chi nhánh, đúng như trên bảng.
        (0 until (chiNhanh?.length() ?: 0))
            .mapNotNull { chiNhanh?.optJSONObject(it)?.optString("name") }
            .filter { it.isNotBlank() }
            .joinToString(", "),
        (0 until (the?.length() ?: 0))
            .mapNotNull { the?.optJSONObject(it)?.optString("name") }
            .filter { it.isNotBlank() }
            .joinToString(", "),
        // Hàng đơn có đúng một dòng mặc định — đếm ra 1 thì ghi 0, không thì tệp
        // nói "1 biến thể" cho một món không có biến thể nào.
        if (optBoolean("is_multi_variant", false)) soBienThe.toString() else "0",
        TrangThaiHang.tu(chuoiJson("status")).nhan,
    )
}

// =====================================================================
//  NHẬP TỆP CSV
// =====================================================================

/** Số dòng tối đa một lượt nhập nhận. */
private const val DONG_TOI_DA_NHAP = 500

/**
 * Đọc một tệp CSV rồi khai từng dòng thành mặt hàng.
 *
 * Ba bước, và thứ tự của chúng là có lý do:
 *
 * 1. Dựng hai bảng tra mã đơn vị / mã vị trí — MỘT lần, trước vòng lặp. Tra
 *    từng dòng là tệp trăm dòng thành hai trăm lượt gọi mạng.
 * 2. Đọc và kiểm CẢ TỆP trước khi gửi dòng nào đi. Vừa gửi vừa kiểm thì tệp sai
 *    ở dòng 90 để lại 89 mặt hàng đã vào, người dùng phải tự đi dọn.
 * 3. Gửi từng dòng, ghi lại dòng nào hỏng vì gì.
 *
 * Vẫn có thể vào một nửa nếu MẠNG đứt giữa chừng — chuyện ấy không tránh được
 * bằng cách nào khác ngoài một đường nhập hàng loạt bên API, mà API chưa có.
 */
suspend fun nhapTepHangHoa(
    kho: KhoPhien,
    boi: android.content.Context,
    uri: android.net.Uri,
    /** Báo tiến độ ra màn: đọc tệp, tra bảng, rồi thêm từng dòng. */
    onTienDo: (String) -> Unit = {},
): KetQuaNhap {
    onTienDo("Đang đọc tệp...")
    val noiDung = withContext(Dispatchers.IO) {
        runCatching {
            boi.contentResolver.openInputStream(uri)?.use { it.readBytes().toString(Charsets.UTF_8) }
        }.getOrNull()
    } ?: return KetQuaNhap(0, listOf("Không đọc được tệp vừa chọn."))

    onTienDo("Đang tra đơn vị tính và vị trí...")
    val donVi = layDonViTinh(kho).orEmpty()
        .filter { it.ma.isNotBlank() }
        .associate { it.ma.uppercase() to it.id }
    val viTri = layViTri(kho).orEmpty()
        .filter { it.ma.isNotBlank() }
        .associate { it.ma.uppercase() to it.id }

    val doc = docTepNhap(noiDung, donVi, viTri)
    if (doc.dong.size > DONG_TOI_DA_NHAP) {
        return KetQuaNhap(
            0,
            listOf(
                "Tệp có ${doc.dong.size} dòng, vượt mức $DONG_TOI_DA_NHAP mỗi lượt nhập. " +
                    "Cắt tệp nhỏ ra rồi nhập thành nhiều đợt.",
            ),
        )
    }

    val loi = doc.loi.toMutableList()
    var xuoi = 0
    doc.dong.forEachIndexed { i, d ->
        // Đếm theo dòng ĐANG GỬI chứ không phải dòng đã xong: tệp trăm dòng thì
        // mỗi dòng một lượt gọi mạng, người dùng cần thấy con số nhích lên.
        onTienDo("Đang thêm ${i + 1}/${doc.dong.size}...")
        val cauLoi = taoMatHang(kho, d.hang)
        if (cauLoi == null) xuoi++ else loi += "Dòng ${d.soDong} (${d.hang.ten}): $cauLoi"
    }

    return KetQuaNhap(thanhCong = xuoi, loi = loi)
}

/** Xoá nhiều mặt hàng một lượt. API nhận tối đa 200 id mỗi lần. */
suspend fun xoaNhieuMatHang(kho: KhoPhien, ids: List<Long>): String? {
    if (ids.isEmpty()) return null
    if (ids.size > 200) return "Mỗi lượt chỉ xoá được tối đa 200 mặt hàng."

    val than = JSONObject().put("ids", JSONArray(ids)).toString()
    val traLoi = goiCoToken(kho, "/admin/products/bulk-delete", "POST", than)

    return if (traLoi.xuoi) null else traLoi.cauLoiTao()
}
