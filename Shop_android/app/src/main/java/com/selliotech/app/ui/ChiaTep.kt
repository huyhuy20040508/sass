package com.selliotech.app.ui

import android.content.Context
import android.content.Intent
import androidx.core.content.FileProvider
import java.io.File

// =====================================================================
//  GHI TỆP RỒI CHIA RA NGOÀI
//
//  Không xin quyền ghi bộ nhớ ngoài. Tệp ghi vào bộ nhớ TẠM của chính
//  app rồi đưa ra ngoài qua FileProvider: hệ điều hành cấp quyền đọc
//  tạm cho đúng một tệp, đúng một app nhận, và thu lại khi người dùng
//  rời đi. Xin quyền ghi bộ nhớ thì được y hệt nhưng đổi lại app đọc
//  ghi được cả kho ảnh của người ta — đòi quá tay cho một việc xuất tệp.
// =====================================================================

/** Thư mục con trong bộ nhớ tạm, khớp `res/xml/duong_tep_chia_se.xml`. */
private const val THU_MUC = "tep-xuat"

/**
 * Ghi nội dung ra một tệp trong bộ nhớ tạm rồi mở bảng chia sẻ.
 *
 * Trả câu lỗi, hoặc null nếu xuôi.
 *
 * Dọn sạch thư mục trước mỗi lượt ghi: tệp xuất mang tên có giờ nên không đè lên
 * nhau, và bộ nhớ tạm thì hệ điều hành chỉ dọn khi máy gần đầy — xuất mười lần
 * là mười bản nằm lại chiếm chỗ mà chẳng ai biết.
 */
fun ghiVaChiaTep(boi: Context, tenTep: String, noiDung: String): String? = try {
    val thuMuc = File(boi.cacheDir, THU_MUC).apply {
        deleteRecursively()
        mkdirs()
    }
    val tep = File(thuMuc, tenTep)
    tep.writeText(noiDung)

    val diaChi = FileProvider.getUriForFile(boi, "${boi.packageName}.tep", tep)
    val y = Intent(Intent.ACTION_SEND).apply {
        type = "text/csv"
        putExtra(Intent.EXTRA_STREAM, diaChi)
        putExtra(Intent.EXTRA_SUBJECT, tenTep)
        // Cờ này là thứ làm cho app nhận đọc được tệp. Thiếu nó thì bảng chia sẻ
        // vẫn hiện ra bình thường, người dùng chọn Zalo, và Zalo báo không mở
        // được tệp — hỏng ở chặng cuối, sau khi mọi thứ trông đã xong.
        addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    }

    boi.startActivity(
        Intent.createChooser(y, "Gửi $tenTep").apply {
            // Bảng chia sẻ mở từ ngoài một Activity nên phải khai task mới.
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        },
    )

    null
} catch (e: Exception) {
    "Không ghi được tệp: ${e.message ?: "lỗi không rõ"}"
}

/** Tên tệp xuất, có sẵn ngày giờ để hai lượt xuất không lẫn vào nhau. */
fun tenTepXuat(luc: Long = System.currentTimeMillis()): String {
    val d = java.text.SimpleDateFormat("yyyyMMdd-HHmmss", java.util.Locale.US)

    return "hang-hoa-${d.format(java.util.Date(luc))}.csv"
}
