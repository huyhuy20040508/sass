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

/** Một dòng hàng trong kho. Khớp domain.InventoryItem bên API. */
data class DongHang(
    val bienTheId: Long,
    val ten: String,
    val sku: String,
    val tenBienThe: String,
    val donVi: String,
    val danhMuc: String,
    val ton: Int,
    val gia: Double,
    val anh: String,
    val dangBan: Boolean,
)

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
suspend fun layThongKeKho(kho: KhoPhien, nguongSapHet: Int = 5): ThongKeKho? {
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
data class TrangHang(val dong: List<DongHang>, val tong: Long, val conNua: Boolean)

/**
 * Danh sách hàng trong kho.
 *
 * Dùng /admin/inventory chứ không phải /products: một lượt gọi đã có sẵn tồn, giá
 * hiệu lực, đơn vị tính và danh mục — màn hình không phải ghép từ hai nguồn.
 */
suspend fun layHangHoa(
    kho: KhoPhien,
    tuKhoa: String = "",
    sapXep: String = "",
    /** Lọc theo tồn: out = hết, low = sắp hết, in = còn nhiều. Rỗng = không lọc. */
    locTon: String = "",
    /** Lọc theo giá vốn: missing = chưa khai, set = đã khai. */
    locGiaVon: String = "",
    nguongSapHet: Int = 5,
    trang: Int = 1,
    coTrang: Int = 30,
): TrangHang? {
    val duong = buildString {
        append("/admin/inventory?page=").append(trang)
        append("&page_size=").append(coTrang)
        if (tuKhoa.isNotBlank()) {
            append("&keyword=").append(URLEncoder.encode(tuKhoa, "UTF-8"))
        }
        if (sapXep.isNotBlank()) append("&sort=").append(sapXep)
        if (locTon.isNotBlank()) {
            append("&stock=").append(locTon)
            // low_stock là NGƯỠNG của bộ lọc "sắp hết", phải gửi kèm chứ không
            // thì API lấy 0 và không dòng nào lọt.
            append("&low_stock=").append(nguongSapHet)
        }
        if (locGiaVon.isNotBlank()) append("&cost=").append(locGiaVon)
    }

    val traLoi = goiCoToken(kho, duong)
    val json = traLoi.json()
    if (!traLoi.xuoi || json == null) return null

    val mang = json.optJSONArray("data") ?: return null
    val dong = (0 until mang.length()).mapNotNull { i ->
        mang.optJSONObject(i)?.thanhDongHang()
    }

    val meta = json.optJSONObject("meta")
    val tong = meta?.optLong("total") ?: dong.size.toLong()
    val tongTrang = meta?.optInt("total_pages") ?: 1

    return TrangHang(dong = dong, tong = tong, conNua = trang < tongTrang)
}

private fun JSONObject.thanhDongHang() = DongHang(
    bienTheId = optLong("variant_id"),
    ten = optString("product_name"),
    sku = optString("sku"),
    tenBienThe = optString("variant_name"),
    donVi = optString("unit_name"),
    danhMuc = optString("category_name"),
    ton = optInt("stock_quantity"),
    gia = optDouble("price", 0.0),
    anh = optString("thumbnail"),
    // product_active = false: hàng còn trong kho nhưng sản phẩm cha đang ẩn,
    // tức không bán ra được. Phải nói ra chứ không thì con số tồn gây hiểu nhầm.
    dangBan = optBoolean("is_active", true) && optBoolean("product_active", true),
)

/**
 * Bộ lọc đang áp lên danh sách hàng.
 *
 * `nhan` là chữ hiện trên con chip để người dùng biết mình đang xem một lát cắt
 * chứ không phải cả kho — thiếu nó thì màn "hết hàng" trông y hệt màn "kho rỗng".
 */
data class LocHang(
    val ton: String = "",
    val giaVon: String = "",
    val nhan: String = "",
) {
    val coLoc: Boolean get() = ton.isNotBlank() || giaVon.isNotBlank()
}
