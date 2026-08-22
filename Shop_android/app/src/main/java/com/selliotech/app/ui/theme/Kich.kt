package com.selliotech.app.ui.theme

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.unit.dp

// =====================================================================
//  KÍCH THƯỚC — khoảng cách, bo góc, chiều cao chạm.
//
//  Mọi khoảng cách là bội của 4. Gõ thẳng `13.dp` vào màn hình là sai
//  chuẩn: mắt không thấy khác, nhưng mười màn mỗi màn lệch một chút thì
//  cả app trông xộc xệch.
// =====================================================================

object Cach {
    /** 4 — khe giữa nhãn và ô của nó. */
    val Sat = 4.dp

    /** 8 — giữa các phần tử trong cùng một khối. */
    val Gan = 8.dp

    /** 12 — giữa hai dòng trong thẻ. */
    val Vua = 12.dp

    /** 16 — lề màn hình, đệm trong thẻ. Dùng nhiều nhất. */
    val Chuan = 16.dp

    /** 20 — đệm trong thẻ lớn. */
    val Rong = 20.dp

    /** 24 — giữa hai khối khác nhau. */
    val Khoi = 24.dp

    /** 32 — trên/dưới một mục lớn. */
    val Lon = 32.dp
}

object Bo {
    /** 8 — huy hiệu, chip. */
    val Nho = RoundedCornerShape(8.dp)

    /** 12 — ô nhập. */
    val O = RoundedCornerShape(12.dp)

    /** 14 — nút. */
    val Nut = RoundedCornerShape(14.dp)

    /** 18 — thẻ. Bo rộng là chỗ khác nhau rõ nhất giữa app 2026 và app 2018. */
    val The = RoundedCornerShape(18.dp)

    /** 24 — tấm trượt từ dưới lên. */
    val Tam = RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp)

    /** Bo hết cỡ — nút tròn, ảnh đại diện. */
    val Tron = RoundedCornerShape(percent = 50)
}

object CaoCham {
    /** 48 — mức tối thiểu Android quy định cho mọi thứ bấm được. */
    val ToiThieu = 48.dp

    /** 56 — nút chính. To hơn mức tối thiểu vì người bán hàng bấm khi tay đang bận. */
    val NutChinh = 56.dp

    /** 52 — ô nhập. */
    val O = 52.dp
}
