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

    /** 24 — thẻ lớn nổi hẳn lên, như dải doanh thu. */
    val TheLon = RoundedCornerShape(24.dp)

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

/**
 * THANH NỔI — thanh điều hướng và nút tròn rời bên cạnh nó.
 *
 * Có bộ số riêng chứ không dùng chung với thẻ: thanh này là thứ duy nhất
 * trong app KHÔNG nằm trong dòng chảy nội dung, nó lơ lửng bên trên. Trộn số
 * của nó vào `Cach` là mở đường cho thẻ thường cũng mọc bóng dày.
 */
object Noi {
    /** 64 — chiều cao cả thanh, và cũng là đường kính nút tròn rời. */
    val Cao = 64.dp

    /** 48 — ô chạm của một tab. Đúng mức tối thiểu Android quy định. */
    val CaoO = 48.dp

    /** 48 — đường kính vòng tròn chạy dưới tab đang chọn. */
    val CoTruot = 48.dp

    /** 8 — viền trong của thanh: phần kính còn thấy quanh con trượt. */
    val Dem = 8.dp

    /** 14 — quầng sáng của con trượt. Toả rộng hơn bóng thường vì nó là ÁNH SÁNG. */
    val QuangTruot = 14.dp

    /** 12 — thanh cách mép màn và cách đáy. */
    val Le = 12.dp

    /** 22 — icon trên thanh. To hơn icon trong danh sách vì bấm bằng ngón cái. */
    val CoIcon = 22.dp

    /**
     * 12 — bóng của thanh.
     *
     * Nhẹ thôi. Thanh này đứng được là nhờ ĐƯỜNG BAO, không nhờ bóng. Bóng dày
     * đè xuống làm tấm kính nặng như miếng mica dán lên, mất hết vẻ lửng lơ.
     */
    val Bong = 12.dp

    /**
     * 30 — bán kính làm mờ nền sau lớp kính.
     *
     * Mờ VỪA, và đó là điều dễ vặn nhầm nhất trong cả bộ.
     *
     * Đủ để chữ dưới thanh không đọc được nữa, nhưng vẫn còn NHẬN RA đó là
     * chữ — vẫn thấy nhịp dòng, vẫn thấy mảng đậm nhạt. Thử tới 60 rồi: cả
     * mảng dưới thanh trộn thành một màu trung bình duy nhất, và lúc đó có làm
     * màng trong đến mấy cũng chẳng nhìn xuyên thấy gì, vì phía dưới không còn
     * hình nào để mà xuyên qua. Càng mờ càng mất cái nhìn xuyên.
     */
    val MoKinh = 20.dp

    /** 1.5 — vành sáng chạy trong mép kính. Dày hơn viền thường vì nó là ánh sáng. */
    val VanhKinh = 1.5.dp

    /** 3 x 22 — vạch màu đứng đầu một dòng, thay cho ô icon tròn. */
    val DayVach = 3.dp
    val CaoVach = 22.dp
}

/**
 * BIỂU ĐỒ CỘT — dải cột nằm trong thẻ tiền.
 *
 * Có bộ số riêng vì nó không phải một khối bố cục: đây là kích thước của HÌNH
 * VẼ, đổi một con số ở đây là đổi dáng cả cái biểu đồ.
 */
object Bieu {
    /** 64 — chiều cao dải cột. Đủ thấy dáng lên xuống, chưa cướp chỗ của con số. */
    val Cao = 64.dp

    /** 3 — chiều cao cột của ngày bằng 0. Phải còn thấy được thì trục mới liền. */
    val CotToiThieu = 3.dp

    /** 0.34 — phần khe trong một ô cột. Còn lại 0.66 là bề rộng cột. */
    const val TiKhe = 0.34f
}

/**
 * NHỊP — thông số chuyển động dùng chung.
 *
 * Để ở đây chứ không gõ thẳng vào màn: hai khối cùng chạy mà lệch độ nảy thì
 * mắt thấy ngay là hai thứ rời nhau.
 */
object Nhip {
    /** Độ tắt của lò xo. 0.8 là nảy nhẹ một nhịp rồi đứng, không rung lâu. */
    const val LoXoTat = 0.8f

    /** Thời gian đổi màu. Đủ để mắt bắt được, chưa đủ để thấy chậm. */
    const val DoiMau = 220
}
