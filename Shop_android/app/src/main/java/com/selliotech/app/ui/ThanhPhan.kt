package com.selliotech.app.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.mauPhu
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.Locale

// =====================================================================
//  THÀNH PHẦN DÙNG CHUNG
//
//  Dựng nút hay thẻ tại chỗ trong tệp màn hình là cách một app trôi dần
//  thành mười kiểu nút khác nhau. Cần kiểu mới thì thêm vào đây.
// =====================================================================

/** Thẻ trắng bo góc rộng — khối cơ bản của mọi màn. */
@Composable
fun The(
    modifier: Modifier = Modifier,
    dem: Boolean = true,
    noiDung: @Composable ColumnScope.() -> Unit,
) {
    Surface(
        color = MaterialTheme.colorScheme.surface,
        shape = Bo.The,
        // Bóng rất nhẹ: 2026 tách lớp bằng màu nền và bo góc, không bằng bóng đổ.
        shadowElevation = 1.dp,
        modifier = modifier.fillMaxWidth(),
    ) {
        Column(
            modifier = if (dem) Modifier.padding(Cach.Rong) else Modifier,
            content = noiDung,
        )
    }
}

/** Nút hành động chính. Cao 56 vì người bán bấm khi tay đang bận. */
@Composable
fun NutChinh(
    chu: String,
    onBam: () -> Unit,
    modifier: Modifier = Modifier,
    moKhoa: Boolean = true,
    dangChay: Boolean = false,
) {
    Button(
        onClick = onBam,
        enabled = moKhoa && !dangChay,
        shape = Bo.Nut,
        colors = ButtonDefaults.buttonColors(
            containerColor = MaterialTheme.colorScheme.primary,
            contentColor = MaterialTheme.colorScheme.onPrimary,
            disabledContainerColor = MaterialTheme.colorScheme.primary.copy(alpha = 0.4f),
            disabledContentColor = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.8f),
        ),
        modifier = modifier
            .fillMaxWidth()
            .height(CaoCham.NutChinh),
    ) {
        if (dangChay) {
            CircularProgressIndicator(
                modifier = Modifier.size(20.dp),
                strokeWidth = 2.dp,
                color = MaterialTheme.colorScheme.onPrimary,
            )
        } else {
            Text(chu, style = MaterialTheme.typography.labelLarge)
        }
    }
}

/** Nút phụ — viền, không tô nền. Đặt cạnh nút chính thì nó phải nhường. */
@Composable
fun NutPhu(
    chu: String,
    onBam: () -> Unit,
    modifier: Modifier = Modifier,
    moKhoa: Boolean = true,
) {
    OutlinedButton(
        onClick = onBam,
        enabled = moKhoa,
        shape = Bo.Nut,
        border = BorderStroke(1.dp, mauPhu.vien),
        colors = ButtonDefaults.outlinedButtonColors(
            contentColor = MaterialTheme.colorScheme.onSurface,
        ),
        modifier = modifier
            .fillMaxWidth()
            .height(CaoCham.ToiThieu),
    ) {
        Text(chu, style = MaterialTheme.typography.labelLarge)
    }
}

/**
 * Nút cho việc bỏ đi / xoá / huỷ. LUÔN đỏ.
 *
 * Quy tắc cứng của cả hệ thống: bỏ đi thì đỏ, đồng ý thì xanh. Đảo lại một
 * lần là người dùng bấm nhầm ở mọi lần sau.
 */
@Composable
fun NutNguyHiem(
    chu: String,
    onBam: () -> Unit,
    modifier: Modifier = Modifier,
    moKhoa: Boolean = true,
) {
    OutlinedButton(
        onClick = onBam,
        enabled = moKhoa,
        shape = Bo.Nut,
        border = BorderStroke(1.dp, mauPhu.do_.copy(alpha = 0.5f)),
        colors = ButtonDefaults.outlinedButtonColors(contentColor = mauPhu.do_),
        modifier = modifier
            .fillMaxWidth()
            .height(CaoCham.ToiThieu),
    ) {
        Text(chu, style = MaterialTheme.typography.labelLarge)
    }
}

/** Sắc thái của một huy hiệu trạng thái. */
enum class Sac { LUC, CAM, DO, LAM, XAM }

/** Huy hiệu trạng thái — nền nhạt, chữ đậm cùng tông. Không dùng chấm tròn đặc. */
@Composable
fun Huy(chu: String, sac: Sac, modifier: Modifier = Modifier) {
    val m = mauPhu
    val (nen, chuMau) = when (sac) {
        Sac.LUC -> m.lucNen to m.luc
        Sac.CAM -> m.camNen to m.cam
        Sac.DO -> m.doNen to m.do_
        Sac.LAM -> m.lamNen to m.lam
        Sac.XAM -> m.matChim to m.chuMo
    }

    Surface(color = nen, shape = Bo.Nho, modifier = modifier) {
        Text(
            chu,
            color = chuMau,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.padding(horizontal = Cach.Gan, vertical = Cach.Sat),
        )
    }
}

/** Tiêu đề một mục. Đặt trên thẻ, không nhét vào trong thẻ. */
@Composable
fun TieuDeMuc(chu: String, modifier: Modifier = Modifier, phai: @Composable (() -> Unit)? = null) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = modifier
            .fillMaxWidth()
            .padding(bottom = Cach.Vua),
    ) {
        Text(
            chu,
            style = MaterialTheme.typography.titleMedium,
            color = MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.weight(1f),
        )
        phai?.invoke()
    }
}

/** Một dòng nhãn — giá trị trong thẻ. Nhãn mờ bên trái, giá trị đậm bên phải. */
@Composable
fun HangThongTin(nhan: String, gia: String, modifier: Modifier = Modifier, mauGia: Color? = null) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween,
        modifier = modifier
            .fillMaxWidth()
            .padding(vertical = Cach.Gan),
    ) {
        Text(
            nhan,
            style = MaterialTheme.typography.bodyMedium,
            color = mauPhu.chuMo,
        )
        Text(
            gia,
            style = MaterialTheme.typography.bodyLarge,
            fontWeight = FontWeight.Medium,
            color = mauGia ?: MaterialTheme.colorScheme.onSurface,
        )
    }
}

/** Vạch ngăn mảnh giữa hai dòng trong thẻ. */
@Composable
fun VachNgan(modifier: Modifier = Modifier) {
    HorizontalDivider(thickness = 1.dp, color = mauPhu.vien, modifier = modifier)
}

/** Hàng hai nút ngang nhau, dùng ở chân hộp thoại. */
@Composable
fun HangNut(modifier: Modifier = Modifier, noiDung: @Composable RowScope.() -> Unit) {
    Row(
        horizontalArrangement = Arrangement.spacedBy(Cach.Vua),
        modifier = modifier.fillMaxWidth(),
        content = noiDung,
    )
}

// ---------------------------------------------------------------------
//  Tiền
// ---------------------------------------------------------------------

private val DINH_DANG = DecimalFormat(
    "#,###",
    DecimalFormatSymbols(Locale("vi", "VN")).apply { groupingSeparator = '.' },
)

/**
 * Tiền Việt: `1.250.000 ₫`.
 *
 * KHÔNG bao giờ rút gọn thành "1,2tr" trên màn có thể thao tác — người ta
 * đang đếm tiền mặt trong tay, lệch một trăm nghìn là lệch thật.
 */
fun tienVN(so: Long): String = DINH_DANG.format(so) + " ₫"

fun tienVN(so: Double): String = tienVN(so.toLong())

/** Số tiền lớn làm nhân vật chính của màn hình. */
@Composable
fun SoTien(so: Long, modifier: Modifier = Modifier, mau: Color? = null) {
    Text(
        tienVN(so),
        style = MaterialTheme.typography.displaySmall,
        color = mau ?: MaterialTheme.colorScheme.onSurface,
        modifier = modifier,
    )
}
