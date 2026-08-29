package com.selliotech.app

import org.json.JSONObject
import java.net.URLEncoder
import java.text.SimpleDateFormat
import java.time.LocalDate
import java.util.Date
import java.util.Locale

/**
 * Các đường của khu QUẢN TRỊ. Toàn bộ nằm dưới nhóm `manage` bên API, tức phải
 * có cửa `quan_ly` — người chỉ đứng quầy gọi vào đây sẽ nhận 403, và đó là đúng.
 */

/**
 * Số của MỘT kỳ. Dùng chung cho kỳ đang xem lẫn kỳ trước, nên so hai kỳ là so
 * hai vật cùng loại chứ không phải đối chiếu hai đống trường rời.
 */
data class SoKy(
    val doanhThu: Double,
    val soDon: Long,
    val soMon: Long,
    val laiGop: Double,
) {
    /** Trung bình một đơn. Tự chia chứ không đọc `aov`: mốc theo ngày không có trường đó. */
    val binhQuanDon: Double get() = if (soDon > 0) doanhThu / soDon else 0.0
}

/** Một mốc trên trục thời gian của biểu đồ. `nhan` là ngày dạng YYYY-MM-DD. */
data class MocNgay(val nhan: String, val so: SoKy)

/**
 * Báo cáo doanh thu: chuỗi theo ngày, tổng kỳ này, tổng kỳ trước.
 *
 * `moc` LUÔN đủ ngày của cả kỳ — ngày không có đơn vẫn có mặt với giá trị 0.
 * Máy chủ đã lo phần đó, và phải như vậy thì biểu đồ mới không nối thẳng qua
 * chỗ trống rồi vẽ sai đường.
 */
data class BaoCaoDoanhThu(
    val moc: List<MocNgay>,
    val ky: SoKy,
    val kyTruoc: SoKy,
)

/** Ảnh chụp toàn kho, không phụ thuộc bộ lọc đang xem. */
data class ThongKeKho(
    val tongBienThe: Long,
    val conHang: Long,
    val sapHet: Long,
    val hetHang: Long,
    val giaTriKho: Double,
    /** Số biến thể chưa khai giá vốn — chính là phần làm giá trị kho bị hụt. */
    val thieuGiaVon: Long,
)

/**
 * Một dòng hàng trong kho. Khớp domain.InventoryItem bên API.
 *
 * Giữ luôn giá vốn, giá trị tồn và lần phát sinh cuối — một lượt gọi đã trả sẵn
 * cả ba, nên tấm chi tiết mở lên là có ngay, không phải gọi thêm một lượt nữa
 * chỉ để bày mấy dòng chữ.
 */
data class DongTon(
    val bienTheId: Long,
    val sanPhamId: Long,
    val ten: String,
    val sku: String,
    val tenBienThe: String,
    val donVi: String,
    val danhMuc: String,
    val ton: Int,
    val gia: Double,
    /** null = CHƯA KHAI giá vốn, khác hẳn giá vốn bằng 0. */
    val giaVon: Double?,
    /** Tồn × giá vốn. Hàng chưa khai giá vốn cho 0 ₫ ở đây. */
    val giaTriKho: Double,
    val anh: String,
    val dangBan: Boolean,
    /** Lần cuối có bút toán kho, dạng ISO của máy chủ. Rỗng = chưa từng. */
    val lanCuoi: String,
) {
    /** Tình trạng tồn, dùng cho màu vạch và huy hiệu. */
    fun mucTon(nguong: Int = NGUONG_SAP_HET): MucTon = when {
        ton <= 0 -> MucTon.HET
        ton <= nguong -> MucTon.SAP_HET
        else -> MucTon.CON
    }
}

/** Ba mức tồn mà cả app nói chung một ngôn ngữ. */
enum class MucTon(val nhan: String) { HET("Hết hàng"), SAP_HET("Sắp hết"), CON("Còn hàng") }

/**
 * Ngưỡng "sắp hết" mặc định của cả app.
 *
 * Một con số duy nhất cho cả bộ lọc, màu vạch và ô thống kê. Ba chỗ tự gõ 5 là
 * ba chỗ có thể lệch nhau, mà lúc lệch thì người dùng lọc ra "sắp hết" rồi thấy
 * dòng trong danh sách tô màu xanh.
 */
const val NGUONG_SAP_HET = 5

/** Ngày hôm nay theo định dạng API nhận: YYYY-MM-DD. */
private fun homNay(): String =
    SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())

/** Ngày cách hôm nay `lui` hôm, dạng API nhận. */
fun ngayTruoc(lui: Long): String = LocalDate.now().minusDays(lui).toString()

/**
 * Doanh thu theo ngày trong khoảng, kèm tổng kỳ này và tổng KỲ TRƯỚC cùng độ dài.
 *
 * Đọc `totals` chứ không cộng tay từ `buckets`: hai chỗ này do server tính, cộng
 * lại ở đây là mở đường cho hai con số lệch nhau mà không ai biết bên nào đúng.
 *
 * `prev` cũng lấy của server luôn. Tự gọi thêm một lượt cho kỳ trước thì phải tự
 * tính lại kỳ trước dài bao nhiêu, tính lệch một ngày là mức tăng giảm sai mà
 * nhìn không ra.
 */
suspend fun layDoanhThu(
    kho: KhoPhien,
    tuNgay: String,
    denNgay: String = homNay(),
): BaoCaoDoanhThu? {
    val duong = "/admin/reports/revenue?from=$tuNgay&to=$denNgay&group_by=day"
    val traLoi = goiCoToken(kho, duong)
    val data = traLoi.json()?.optJSONObject("data")
    if (!traLoi.xuoi || data == null) return null

    val mang = data.optJSONArray("buckets")
    val moc = (0 until (mang?.length() ?: 0)).mapNotNull { i ->
        mang?.optJSONObject(i)?.let { MocNgay(nhan = it.optString("label"), so = it.thanhSoKy()) }
    }

    return BaoCaoDoanhThu(
        moc = moc,
        ky = data.optJSONObject("totals")?.thanhSoKy() ?: return null,
        kyTruoc = data.optJSONObject("prev")?.thanhSoKy() ?: SoKy(0.0, 0, 0, 0.0),
    )
}

private fun JSONObject.thanhSoKy() = SoKy(
    doanhThu = optDouble("revenue", 0.0),
    soDon = optLong("orders"),
    soMon = optLong("units"),
    laiGop = optDouble("profit", 0.0),
)

/**
 * Một dòng trong bảng bán chạy.
 *
 * `ton` là tồn HIỆN TẠI chứ không phải tồn lúc bán — chính vì thế nó đáng đặt
 * cạnh số bán: bán chạy mà sắp hết là việc phải làm ngay hôm nay.
 */
data class HangBanChay(
    val ten: String,
    val soBan: Long,
    val doanhThu: Double,
    val ton: Long,
)

/** Bảng xếp hạng mặt hàng theo doanh thu trong kỳ. */
suspend fun layHangBanChay(
    kho: KhoPhien,
    tuNgay: String,
    denNgay: String = homNay(),
    soDong: Int = 5,
): List<HangBanChay>? {
    val duong = "/admin/reports/products?from=$tuNgay&to=$denNgay&sort=revenue&limit=$soDong"
    val traLoi = goiCoToken(kho, duong)
    val data = traLoi.json()?.optJSONObject("data")
    if (!traLoi.xuoi || data == null) return null

    val mang = data.optJSONArray("items") ?: return emptyList()

    return (0 until mang.length()).mapNotNull { i ->
        mang.optJSONObject(i)?.let {
            HangBanChay(
                ten = it.optString("name"),
                soBan = it.optLong("units"),
                doanhThu = it.optDouble("revenue", 0.0),
                ton = it.optLong("stock"),
            )
        }
    }
}

/** Thống kê kho. `sapHet` đếm theo ngưỡng truyền lên, không phải ngưỡng cố định. */
suspend fun layThongKeKho(kho: KhoPhien, nguongSapHet: Int = NGUONG_SAP_HET): ThongKeKho? {
    val traLoi = goiCoToken(kho, "/admin/inventory/stats?low_stock=$nguongSapHet")
    val data = traLoi.json()?.optJSONObject("data")
    if (!traLoi.xuoi || data == null) return null

    return ThongKeKho(
        tongBienThe = data.optLong("total_variants"),
        conHang = data.optLong("in_stock"),
        sapHet = data.optLong("low_stock"),
        hetHang = data.optLong("out_of_stock"),
        giaTriKho = data.optDouble("stock_value", 0.0),
        thieuGiaVon = data.optLong("missing_cost"),
    )
}

/** Một trang danh sách hàng, kèm tổng số dòng khớp bộ lọc. */
data class TrangTon(val dong: List<DongTon>, val tong: Long, val conNua: Boolean)

/**
 * Cách xếp danh sách hàng. `ma` là giá trị API nhận, `nhan` là chữ hiện trên nút.
 *
 * Mặc định là tồn ít lên trước — mở màn kho ra thì việc cần làm phải nằm ngay
 * trên đầu, chứ không phải mặt hàng nào tình cờ khai trước.
 */
enum class XepTon(val ma: String, val nhan: String) {
    TON_IT("stock_asc", "Tồn ít nhất"),
    TON_NHIEU("stock_desc", "Tồn nhiều nhất"),
    TEN_AZ("name_asc", "Tên A → Z"),
    TEN_ZA("name_desc", "Tên Z → A"),
    GIA_TRI("value_desc", "Giá trị kho cao nhất"),
    MOI("newest", "Mới khai gần đây"),
}

/**
 * Bộ lọc đang áp lên danh sách hàng.
 *
 * Một vật duy nhất giữ TẤT CẢ điều kiện, kể cả kiểu sắp xếp. Tách ra mỗi thứ một
 * biến trạng thái là mỗi lần đổi một thứ lại phải nhớ gom đủ mấy thứ còn lại để
 * gọi lại API, quên một cái là danh sách về sai mà không ai thấy.
 */
data class LocTon(
    /** Tồn: out = hết, low = sắp hết, in = còn nhiều. Rỗng = không lọc. */
    val ton: String = "",
    /** Giá vốn: missing = chưa khai, set = đã khai. Rỗng = không lọc. */
    val giaVon: String = "",
    val danhMucId: Long = 0,
    /** Tên nhóm, giữ sẵn để vẽ con chip mà không phải tra lại danh sách nhóm. */
    val danhMucTen: String = "",
    /** null = cả hai, true = đang bán, false = ngừng bán. */
    val dangBan: Boolean? = null,
    val xep: XepTon = XepTon.TON_IT,
) {
    val coLoc: Boolean get() = soDieuKien > 0

    /** Số điều kiện đang bật — con số nhỏ trên nút lọc. Sắp xếp KHÔNG tính. */
    val soDieuKien: Int
        get() = listOf(
            ton.isNotBlank(),
            giaVon.isNotBlank(),
            danhMucId > 0,
            dangBan != null,
        ).count { it }
}

/** Một điều kiện đang bật: chữ trên chip, và bộ lọc còn lại sau khi gỡ nó ra. */
data class ChipTon(val nhan: String, val con: LocTon)

/**
 * Các chip của một bộ lọc.
 *
 * Mỗi chip tự mang theo bộ lọc CÒN LẠI sau khi gỡ chính nó, nên chỗ vẽ chỉ việc
 * gán thẳng — không phải viết thêm một nhánh `when` nữa để biết bỏ chip này thì
 * trạng thái mới là gì.
 */
fun LocTon.chips(): List<ChipTon> = buildList {
    when (ton) {
        "out" -> add(ChipTon("Hết hàng", copy(ton = "")))
        "low" -> add(ChipTon("Sắp hết", copy(ton = "")))
        "in" -> add(ChipTon("Còn hàng", copy(ton = "")))
    }
    when (giaVon) {
        "missing" -> add(ChipTon("Chưa khai giá vốn", copy(giaVon = "")))
        "set" -> add(ChipTon("Đã khai giá vốn", copy(giaVon = "")))
    }
    if (danhMucId > 0) {
        add(ChipTon(danhMucTen.ifBlank { "Một nhóm hàng" }, copy(danhMucId = 0, danhMucTen = "")))
    }
    when (dangBan) {
        true -> add(ChipTon("Đang bán", copy(dangBan = null)))
        false -> add(ChipTon("Ngừng bán", copy(dangBan = null)))
        null -> Unit
    }
}

/**
 * Đường gọi của một trang danh sách hàng.
 *
 * Tách khỏi hàm gọi mạng để kiểm được bằng bộ kiểm thường: chỗ dễ sai nhất của cả
 * màn nằm ở đây — quên gửi kèm `low_stock` thì bộ lọc "sắp hết" trả về rỗng mà API
 * vẫn đáp 200, nhìn y hệt một cái kho không có hàng.
 */
fun duongTonKho(
    loc: LocTon,
    tuKhoa: String = "",
    trang: Int = 1,
    coTrang: Int = 30,
    nguongSapHet: Int = NGUONG_SAP_HET,
): String = buildString {
    append("/admin/inventory?page=").append(trang)
    append("&page_size=").append(coTrang)
    append("&sort=").append(loc.xep.ma)
    append("&low_stock=").append(nguongSapHet)
    if (tuKhoa.isNotBlank()) {
        append("&keyword=").append(URLEncoder.encode(tuKhoa, "UTF-8"))
    }
    if (loc.ton.isNotBlank()) append("&stock=").append(loc.ton)
    if (loc.giaVon.isNotBlank()) append("&cost=").append(loc.giaVon)
    if (loc.danhMucId > 0) append("&category_id=").append(loc.danhMucId)
    loc.dangBan?.let { append("&is_active=").append(it) }
}

/**
 * Danh sách hàng trong kho.
 *
 * Dùng /admin/inventory chứ không phải /products: một lượt gọi đã có sẵn tồn, giá
 * hiệu lực, giá vốn, đơn vị tính và danh mục — màn hình không phải ghép từ hai
 * nguồn, và tấm chi tiết mở lên không tốn thêm lượt gọi nào.
 */
suspend fun layTonKho(
    kho: KhoPhien,
    loc: LocTon = LocTon(),
    tuKhoa: String = "",
    trang: Int = 1,
    coTrang: Int = 30,
): TrangTon? {
    val traLoi = goiCoToken(kho, duongTonKho(loc, tuKhoa, trang, coTrang))
    val json = traLoi.json()
    if (!traLoi.xuoi || json == null) return null

    val mang = json.optJSONArray("data") ?: return null
    val dong = (0 until mang.length()).mapNotNull { i ->
        mang.optJSONObject(i)?.thanhDongTon()
    }

    val meta = json.optJSONObject("meta")
    val tong = meta?.optLong("total") ?: dong.size.toLong()
    val tongTrang = meta?.optInt("total_pages") ?: 1

    return TrangTon(dong = dong, tong = tong, conNua = trang < tongTrang)
}

private fun JSONObject.thanhDongTon() = DongTon(
    bienTheId = optLong("variant_id"),
    sanPhamId = optLong("product_id"),
    ten = optString("product_name"),
    sku = optString("sku"),
    tenBienThe = chuoi("variant_name"),
    donVi = chuoi("unit_name"),
    danhMuc = chuoi("category_name"),
    ton = optInt("stock_quantity"),
    gia = optDouble("price", 0.0),
    // isNull chứ không phải optDouble có mặc định: NULL ở đây nghĩa là CHƯA KHAI
    // giá vốn, khác hẳn khai bằng 0. Nhập nhèm hai thứ là mất luôn bộ lọc "chưa
    // khai" lẫn con số giá trị kho đang hụt.
    giaVon = if (isNull("cost_price")) null else optDouble("cost_price", 0.0),
    giaTriKho = optDouble("stock_value", 0.0),
    anh = chuoi("thumbnail"),
    // product_active = false: hàng còn trong kho nhưng sản phẩm cha đang ẩn,
    // tức không bán ra được. Phải nói ra chứ không thì con số tồn gây hiểu nhầm.
    dangBan = optBoolean("is_active", true) && optBoolean("product_active", true),
    lanCuoi = chuoi("last_moved_at"),
)

/**
 * Chuỗi của một khoá, JSON null thành rỗng.
 *
 * `optString` của org.json trả về đúng bốn chữ "null" khi gặp JSON null, và bốn
 * chữ đó sẽ chạy thẳng ra màn hình. Mọi trường có thể null phải đi qua đây.
 */
private fun JSONObject.chuoi(khoa: String): String =
    if (isNull(khoa)) "" else optString(khoa)

/** Một nhóm hàng hoá. `bac` là độ sâu trong cây, dùng để thụt đầu dòng. */
data class DanhMuc(val id: Long, val ten: String, val cha: Long = 0, val bac: Int = 0)

/**
 * Danh sách nhóm hàng, đã xếp theo cây.
 *
 * API trả một mảng PHẲNG theo sort_order, cha con lẫn lộn. Bày nguyên như thế thì
 * "Nước ngọt" nằm cách "Đồ uống" mười dòng và không ai đoán ra chúng cùng một nhánh.
 */
suspend fun layDanhMuc(kho: KhoPhien): List<DanhMuc>? {
    val traLoi = goiCoToken(kho, "/categories")
    val json = traLoi.json()
    if (!traLoi.xuoi || json == null) return null

    val mang = json.optJSONArray("data") ?: return null
    val phang = (0 until mang.length()).mapNotNull { i ->
        mang.optJSONObject(i)?.let {
            DanhMuc(
                id = it.optLong("id"),
                ten = it.optString("name"),
                cha = if (it.isNull("parent_id")) 0 else it.optLong("parent_id"),
            )
        }
    }

    return xepCay(phang)
}

/**
 * Xếp mảng phẳng thành thứ tự cây: cha trước, rồi tới con của nó với `bac` sâu hơn.
 *
 * Nhóm trỏ tới một cha không còn trong danh sách vẫn phải lọt ra — coi như nhóm
 * gốc. Bỏ nó đi là mất hẳn một nhóm khỏi bộ lọc mà chẳng ai hiểu vì sao.
 */
fun xepCay(phang: List<DanhMuc>): List<DanhMuc> {
    val coMat = phang.mapTo(HashSet()) { it.id }
    val theoCha = phang.groupBy { if (it.cha != 0L && it.cha in coMat) it.cha else 0L }
    val ra = mutableListOf<DanhMuc>()

    fun duyet(cha: Long, bac: Int) {
        // Chặn ở bậc 5: dữ liệu vòng (A là cha của B, B là cha của A) sẽ đệ quy
        // vô tận và app tắt ngóm ngay khi mở bộ lọc.
        if (bac > 5) return
        theoCha[cha].orEmpty().forEach {
            ra += it.copy(bac = bac)
            duyet(it.id, bac + 1)
        }
    }
    duyet(0L, 0)

    return ra
}
