package com.selliotech.app

// =====================================================================
//  CSV — ĐỌC VÀ GHI
//
//  Tự viết chứ không kéo thư viện: cả app đang không có phụ thuộc nào
//  ngoài Compose và bộ icon, mà CSV thì chỉ có đúng ba luật (dấu phẩy
//  ngăn ô, dấu nháy kép bọc ô có ký tự đặc biệt, nháy kép trong ô thì
//  gõ đôi). Ba luật ấy viết ra vừa một trang và kiểm được hết.
//
//  Định dạng bám ĐÚNG bản web: cùng bộ cột, cùng tên tệp, cùng BOM.
//  Lệch một cột là tệp xuất bên này không nhập lại được bên kia.
// =====================================================================

/**
 * BOM UTF-8 đứng đầu tệp.
 *
 * Không có nó thì Excel trên Windows đọc tệp theo bảng mã hệ thống và mọi chữ có
 * dấu thành ký tự lạ — người dùng mở ra thấy "HÃ ng hoÃ¡" rồi kết luận app hỏng.
 */
const val BOM = "﻿"

/** Tiêu đề tệp XUẤT. Đúng 14 cột của bản web, đúng thứ tự. */
val COT_XUAT = listOf(
    "Mã SP", "Mã hàng", "Tên hàng hóa", "Nhóm hàng hóa", "ĐVT", "Vị trí",
    "VAT", "Giá bán", "Giá vốn",
    "Tồn kho", "Chi nhánh", "Thẻ", "Số biến thể", "Trạng thái",
)

/** Tiêu đề tệp MẪU để nhập. Chín cột, đúng bản web. */
val COT_NHAP = listOf(
    "name", "category_id", "unit_code", "location_code",
    "vat", "base_price", "cost_price", "the", "bien_the",
)

/**
 * Hai dòng ví dụ trong tệp mẫu.
 *
 * Có ví dụ thật chứ không để tệp trắng: người mở tệp mẫu ra cần thấy ngay
 * `category_id` là một con số, `unit_code` là mã chữ, và biến thể ngăn bằng dấu
 * phẩy — ba thứ đọc tên cột không đoán ra.
 */
val VI_DU_NHAP = listOf(
    listOf("iPhone 15 128GB", "4", "CAI", "VT001", "10", "22000000", "19500000", "Bán chạy nhất", ""),
    listOf("Ốp lưng silicon", "3", "CAI", "", "8", "120000", "60000", "Món mới", "Đen,Trắng,Xanh"),
)

/**
 * Một ô CSV.
 *
 * Chỉ bọc nháy kép khi THẬT SỰ CẦN — ô có dấu phẩy, nháy kép, hoặc xuống dòng.
 * Bọc hết mọi ô thì tệp vẫn đúng nhưng mở bằng trình soạn thảo thường đọc rất
 * mệt, mà người ta hay mở tệp xuất ra để liếc chứ không phải lúc nào cũng dùng
 * Excel.
 */
fun oCsv(gia: String): String {
    val can = gia.any { it == ',' || it == '"' || it == '\n' || it == '\r' }

    return if (can) "\"" + gia.replace("\"", "\"\"") + "\"" else gia
}

/** Một dòng CSV, đã xuống dòng theo CRLF cho Excel trên Windows đọc đúng. */
fun dongCsv(o: List<String>): String = o.joinToString(",") { oCsv(it) } + "\r\n"

/**
 * Tách một dòng CSV thành các ô.
 *
 * Tự đi từng ký tự chứ không `split(",")`: tên hàng hoá có dấu phẩy ("Bút bi,
 * hộp 10 cây") là chuyện thường, mà cắt bừa theo dấu phẩy là dòng ấy lệch hết
 * cột kể từ đó — và lệch âm thầm, tệp vẫn nhập vào được với dữ liệu sai chỗ.
 */
fun tachDongCsv(dong: String): List<String> {
    val ra = mutableListOf<String>()
    val o = StringBuilder()
    var trongNhay = false
    var i = 0

    while (i < dong.length) {
        val c = dong[i]
        when {
            trongNhay && c == '"' && i + 1 < dong.length && dong[i + 1] == '"' -> {
                // Hai nháy liền nhau bên trong ô = một nháy thật.
                o.append('"')
                i++
            }
            c == '"' -> trongNhay = !trongNhay
            c == ',' && !trongNhay -> {
                ra += o.toString()
                o.clear()
            }
            else -> o.append(c)
        }
        i++
    }
    ra += o.toString()

    return ra.map { it.trim() }
}

/** Nội dung tệp mẫu để nhập hàng hoá. */
fun tepMauNhap(): String = buildString {
    append(BOM)
    append(dongCsv(COT_NHAP))
    VI_DU_NHAP.forEach { append(dongCsv(it)) }
}

/** Một dòng đọc được từ tệp nhập, đã tra xong mã đơn vị và mã vị trí. */
data class DongNhap(
    /** Số dòng trong tệp, tính cả dòng tiêu đề — để câu lỗi chỉ đúng chỗ phải sửa. */
    val soDong: Int,
    val hang: HangMoi,
)

/** Kết quả đọc một tệp nhập: những dòng đọc được, và những dòng hỏng kèm lý do. */
data class KetQuaDocTep(val dong: List<DongNhap>, val loi: List<String>)

/**
 * Đọc nội dung tệp CSV thành danh sách mặt hàng sắp khai.
 *
 * `donViTheoMa` và `viTriTheoMa` là hai bảng tra MÃ (viết hoa) sang id, dựng sẵn
 * MỘT lần trước khi gọi — tra từng dòng là mỗi dòng hai lượt gọi mạng cho hai
 * bảng chỉ vài chục dòng.
 *
 * Mã lạ thì BÁO LỖI dòng ấy chứ không lặng lẽ nhập vào với ô trống: người dùng
 * khai một chỗ để hàng cụ thể, nhập xong mà mất thì họ không biết để đi sửa.
 */
fun docTepNhap(
    noiDung: String,
    donViTheoMa: Map<String, Long>,
    viTriTheoMa: Map<String, Long>,
): KetQuaDocTep {
    val dong = noiDung.removePrefix(BOM)
        .split("\r\n", "\n")
        .filter { it.isNotBlank() }
    if (dong.isEmpty()) return KetQuaDocTep(emptyList(), listOf("Tệp rỗng."))

    val tieuDe = tachDongCsv(dong.first()).map { it.lowercase() }
    val cot = tieuDe.withIndex().associate { (i, ten) -> ten to i }
    if ("name" !in cot || "category_id" !in cot) {
        return KetQuaDocTep(
            emptyList(),
            listOf("Tệp thiếu cột \"name\" hoặc \"category_id\". Tải file mẫu để xem đúng bộ cột."),
        )
    }

    val ra = mutableListOf<DongNhap>()
    val loi = mutableListOf<String>()

    dong.drop(1).forEachIndexed { i, raw ->
        // +2 vì: đếm từ 0, và dòng đầu tệp là tiêu đề đã bị lấy ra.
        val soDong = i + 2
        val o = tachDongCsv(raw)
        fun lay(ten: String): String = cot[ten]?.let { o.getOrNull(it) }.orEmpty().trim()

        val ten = lay("name")
        // Dòng trống giữa tệp là chuyện thường (Excel hay để lại), bỏ qua im lặng.
        if (ten.isBlank()) return@forEachIndexed

        val nhomId = lay("category_id").toLongOrNull() ?: 0
        if (nhomId <= 0) {
            loi += "Dòng $soDong ($ten): thiếu hoặc sai category_id."

            return@forEachIndexed
        }

        val maDonVi = lay("unit_code").uppercase()
        var donViId = 0L
        if (maDonVi.isNotBlank()) {
            donViId = donViTheoMa[maDonVi] ?: run {
                loi += "Dòng $soDong ($ten): không có đơn vị tính mã \"$maDonVi\"."

                return@forEachIndexed
            }
        }

        val maViTri = lay("location_code").uppercase()
        var viTriId = 0L
        if (maViTri.isNotBlank()) {
            viTriId = viTriTheoMa[maViTri] ?: run {
                loi += "Dòng $soDong ($ten): không có vị trí mã \"$maViTri\"."

                return@forEachIndexed
            }
        }

        ra += DongNhap(
            soDong = soDong,
            hang = HangMoi(
                ten = ten,
                nhomId = nhomId,
                giaBan = soTuChuoi(lay("base_price")),
                giaVon = lay("cost_price").takeIf { it.isNotBlank() }?.let { soTuChuoi(it) },
                donViId = donViId,
                viTriId = viTriId,
                vat = lay("vat").takeIf { it.isNotBlank() }?.toIntOrNull(),
                the = tachDanhSach(lay("the")),
                bienTheTen = tachDanhSach(lay("bien_the")),
            ),
        )
    }

    return KetQuaDocTep(ra, loi)
}

/**
 * Số từ một ô của tệp: bỏ mọi thứ không phải chữ số hoặc dấu chấm.
 *
 * Người ta hay gõ "22.000.000" hay "22,000,000 đ" vào Excel; ép thẳng
 * `toDouble()` là ném lỗi và cả dòng bị loại vì một chuyện chẳng đáng.
 */
fun soTuChuoi(s: String): Double {
    val sach = s.filter { it.isDigit() || it == '.' }
    // Dấu chấm ở đây gần như luôn là dấu phân nhóm nghìn của người Việt chứ
    // không phải dấu thập phân, nên bỏ hẳn: "22.000.000" phải ra 22 triệu, không
    // phải 22 đồng.
    val nguyen = sach.replace(".", "")

    return nguyen.toDoubleOrNull() ?: 0.0
}

/** Tách "Đen, Trắng; Xanh" thành ba phần, bỏ phần rỗng. */
fun tachDanhSach(s: String): List<String> =
    s.split(',', ';').map { it.trim() }.filter { it.isNotBlank() }
