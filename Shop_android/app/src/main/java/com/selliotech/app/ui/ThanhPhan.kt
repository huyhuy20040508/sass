package com.selliotech.app.ui

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsFocusedAsState
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.offset
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.VerticalDivider
import android.widget.Toast
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.Nhip
import com.selliotech.app.ui.theme.Noi
import com.selliotech.app.ui.theme.mauPhu
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.Locale
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit
import kotlin.math.abs
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.TrendingDown
import com.composables.icons.lucide.TrendingUp
import com.composables.icons.lucide.Check
import com.composables.icons.lucide.ChevronDown
import com.composables.icons.lucide.ChevronUp
import com.composables.icons.lucide.Search
import com.composables.icons.lucide.SlidersHorizontal
import com.composables.icons.lucide.X

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
    cao: Dp = CaoCham.ToiThieu,
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
            .height(cao),
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
    // Đứng một mình thì 48 là đủ; đứng CẠNH nút chính thì phải truyền 56 vào,
    // hai cái nút cùng hàng mà lệch nhau 8dp là nhìn ra ngay.
    cao: Dp = CaoCham.ToiThieu,
) {
    OutlinedButton(
        onClick = onBam,
        enabled = moKhoa,
        shape = Bo.Nut,
        border = BorderStroke(1.dp, mauPhu.do_.copy(alpha = 0.5f)),
        colors = ButtonDefaults.outlinedButtonColors(contentColor = mauPhu.do_),
        modifier = modifier
            .fillMaxWidth()
            .height(cao),
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

// ---------------------------------------------------------------------
//  Vạch ưu tiên và khung xương
// ---------------------------------------------------------------------

/**
 * Vạch màu đứng ở đầu một dòng danh sách, thay cho ô icon tròn.
 *
 * Ô icon tròn có màu là lối quen tay: nó chiếm 42dp bề ngang để nói đúng một
 * việc — dòng này thuộc nhóm màu gì. Cái vạch nói y hệt bằng 3dp, và vì mọi
 * vạch thẳng hàng nhau nên lướt dọc danh sách là đọc được cả cột màu một lượt.
 * Chỗ tiết kiệm được trả lại cho tên hàng, thứ người ta thật sự đọc.
 */
@Composable
fun VachUuTien(mau: Color, modifier: Modifier = Modifier) {
    Box(
        modifier = modifier
            .width(Noi.DayVach)
            .height(Noi.CaoVach)
            .background(mau, Bo.Tron),
    )
}

/**
 * Ô xám thế chỗ nội dung trong lúc chờ máy chủ.
 *
 * Thay cho vòng xoay giữa màn. Vòng xoay nói "đang bận", khung xương nói "chỗ
 * này sắp có một con số to và ba dòng bên dưới" — màn không nhảy dựng lên khi
 * dữ liệu về, vì bố cục đã đứng sẵn ở đó rồi.
 *
 * Nhấp nháy chậm để phân biệt với màn chết: đứng im hoàn toàn thì sau vài giây
 * người ta bắt đầu nghi app treo.
 */
@Composable
fun OXuong(modifier: Modifier = Modifier, bo: Shape = Bo.O) {
    val nhip = rememberInfiniteTransition(label = "xuong")
    val dam by nhip.animateFloat(
        initialValue = 0.45f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(tween(900), RepeatMode.Reverse),
        label = "damNhat",
    )

    Box(modifier.background(mauPhu.vienNhat.copy(alpha = dam), bo))
}

// ---------------------------------------------------------------------
//  Chip chọn và mức thay đổi
// ---------------------------------------------------------------------

/**
 * Chip chọn một trong nhiều — dùng cho dải chọn kỳ.
 *
 * Cái đang chọn TÔ ĐẶC màu chính, mấy cái kia là thẻ trắng viền mảnh. Chỉ đổi
 * độ đậm của chữ thì trên màn nắng ngoài chợ nhìn không ra cái nào đang bật.
 */
@Composable
fun ChipChon(chu: String, chon: Boolean, onBam: () -> Unit, modifier: Modifier = Modifier) {
    Surface(
        color = if (chon) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surface,
        shape = Bo.Tron,
        border = if (chon) null else BorderStroke(1.dp, mauPhu.vienNhat),
        modifier = modifier.clip(Bo.Tron).clickable(onClick = onBam),
    ) {
        Text(
            text = chu,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.SemiBold,
            color = if (chon) MaterialTheme.colorScheme.onPrimary else mauPhu.chuThuong,
            modifier = Modifier.padding(horizontal = Cach.Chuan, vertical = Cach.Gan + 2.dp),
        )
    }
}

/**
 * Mức thay đổi so với kỳ trước, tính bằng phần trăm.
 *
 * Trả null khi kỳ trước bằng 0: không chia được cho 0, mà in "tăng 100%" hay
 * "tăng vô cực" thì đều là bịa. Chỗ gọi thấy null thì đừng vẽ gì cả — không có
 * gì để so là một câu trả lời đúng.
 */
fun mucThayDoi(gio: Double, truoc: Double): Double? =
    if (truoc <= 0.0) null else (gio - truoc) / truoc * 100.0

/**
 * Huy hiệu tăng/giảm.
 *
 * `tren` cho biết nó đang nằm trên nền nào: trên mảng xanh của thẻ tiền thì
 * xanh lá với đỏ đều chìm, phải chuyển sang trắng trên nền trắng mờ.
 */
@Composable
fun HuyThayDoi(muc: Double, nenXanh: Boolean = false, modifier: Modifier = Modifier) {
    val len = muc >= 0
    val mau = when {
        nenXanh -> Color.White
        len -> mauPhu.luc
        else -> mauPhu.do_
    }
    val nen = if (nenXanh) Color.White.copy(alpha = 0.18f) else Color.Transparent

    Surface(color = nen, shape = Bo.Tron, modifier = modifier) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.padding(
                horizontal = if (nenXanh) Cach.Gan else 0.dp,
                vertical = if (nenXanh) Cach.Sat else 0.dp,
            ),
        ) {
            Icon(
                imageVector = if (len) Lucide.TrendingUp else Lucide.TrendingDown,
                contentDescription = if (len) "Tăng" else "Giảm",
                tint = mau,
                modifier = Modifier.size(Cach.Chuan),
            )
            Spacer(Modifier.width(Cach.Sat))
            Text(
                text = phanTram(abs(muc)),
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = mau,
            )
        }
    }
}

private val DINH_DANG_PT = DecimalFormat(
    "#,##0.#",
    DecimalFormatSymbols(Locale("vi", "VN")).apply {
        groupingSeparator = '.'
        decimalSeparator = ','
    },
)

/** "18,2%" — dấu phẩy thập phân theo lối Việt. */
fun phanTram(so: Double): String = DINH_DANG_PT.format(so) + "%"

// ---------------------------------------------------------------------
//  Nút icon, chip gỡ được, trạng thái rỗng
// ---------------------------------------------------------------------

/**
 * Nút vuông bo góc chỉ có icon — cùng ngôn ngữ với cột Hành động bên web.
 *
 * `so` là con số nhỏ đính góc trên phải: bộ lọc đang bật mấy điều kiện. Không
 * có con số đó thì cái nút lọc lúc đang lọc và lúc không lọc trông y hệt nhau,
 * mà đó đúng là lúc người dùng cần biết nhất — danh sách ngắn bất thường vì họ
 * lọc từ hôm qua chứ không phải vì kho hết hàng.
 */
@Composable
fun NutO(
    bieuTuong: ImageVector,
    moTa: String,
    onBam: () -> Unit,
    modifier: Modifier = Modifier,
    so: Int = 0,
    dangBat: Boolean = so > 0,
) {
    Box(modifier = modifier) {
        Surface(
            color = if (dangBat) mauPhu.lamNen else MaterialTheme.colorScheme.surface,
            shape = Bo.O,
            border = BorderStroke(1.dp, if (dangBat) mauPhu.lam.copy(alpha = 0.4f) else mauPhu.vienNhat),
            modifier = Modifier
                .size(CaoCham.O)
                .clip(Bo.O)
                .clickable(onClick = onBam),
        ) {
            Box(contentAlignment = Alignment.Center) {
                Icon(
                    imageVector = bieuTuong,
                    contentDescription = moTa,
                    tint = if (dangBat) mauPhu.lam else mauPhu.chuThuong,
                    modifier = Modifier.size(Cach.Rong),
                )
            }
        }

        if (so > 0) {
            Surface(
                color = MaterialTheme.colorScheme.primary,
                shape = Bo.Tron,
                modifier = Modifier
                    .align(Alignment.TopEnd)
                    .offset(x = 6.dp, y = (-6).dp),
            ) {
                Text(
                    text = so.toString(),
                    style = MaterialTheme.typography.labelSmall,
                    fontWeight = FontWeight.Bold,
                    color = MaterialTheme.colorScheme.onPrimary,
                    textAlign = TextAlign.Center,
                    modifier = Modifier
                        .defaultMinSize(minWidth = 18.dp)
                        .padding(horizontal = 5.dp, vertical = 1.dp),
                )
            }
        }
    }
}

/** Chip xanh nhạt của một điều kiện đang bật; bấm chữ X là gỡ đúng điều kiện đó. */
@Composable
fun ChipGo(nhan: String, onGo: () -> Unit, modifier: Modifier = Modifier) {
    Surface(
        color = mauPhu.lamNen,
        shape = Bo.Tron,
        border = BorderStroke(1.dp, mauPhu.lam.copy(alpha = 0.35f)),
        modifier = modifier.clip(Bo.Tron).clickable(onClick = onGo),
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.padding(start = Cach.Vua, end = Cach.Gan, top = 6.dp, bottom = 6.dp),
        ) {
            Text(
                text = nhan,
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.Medium,
                color = mauPhu.lam,
            )
            Spacer(Modifier.width(Cach.Sat))
            Icon(
                imageVector = Lucide.X,
                contentDescription = "Bỏ lọc $nhan",
                tint = mauPhu.lam,
                modifier = Modifier.size(14.dp),
            )
        }
    }
}

/**
 * Màn trống có hình: icon trong một vòng tròn nhạt, một câu nói rõ vì sao trống,
 * và một lối ra nếu có.
 *
 * Một dòng chữ xám giữa màn thì đọc như app đang hỏng. Cái vòng tròn nói rằng
 * chỗ này ĐANG ĐÚNG, chỉ là chưa có gì — hai chuyện khác hẳn nhau.
 */
@Composable
fun TrangRong(
    bieuTuong: ImageVector,
    tieuDe: String,
    modifier: Modifier = Modifier,
    phu: String = "",
    nhanNut: String = "",
    onBam: (() -> Unit)? = null,
) {
    Column(
        modifier = modifier.fillMaxWidth().padding(Cach.Lon),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(
            modifier = Modifier.size(72.dp).background(mauPhu.matChim, Bo.Tron),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                imageVector = bieuTuong,
                contentDescription = null,
                tint = mauPhu.chuMo,
                modifier = Modifier.size(Cach.Lon),
            )
        }

        Spacer(Modifier.height(Cach.Chuan))

        Text(
            text = tieuDe,
            style = MaterialTheme.typography.titleSmall,
            color = MaterialTheme.colorScheme.onSurface,
            textAlign = TextAlign.Center,
        )

        if (phu.isNotBlank()) {
            Spacer(Modifier.height(Cach.Gan))
            Text(
                text = phu,
                style = MaterialTheme.typography.bodyMedium,
                color = mauPhu.chuMo,
                textAlign = TextAlign.Center,
            )
        }

        if (onBam != null && nhanNut.isNotBlank()) {
            Spacer(Modifier.height(Cach.Rong))
            NutPhu(chu = nhanNut, onBam = onBam, modifier = Modifier.width(200.dp))
        }
    }
}

/**
 * Một dòng chọn trong tấm trượt: nhãn bên trái, dấu tích bên phải khi đang chọn.
 *
 * Dấu tích chứ không phải nút tròn kiểu radio: cả dòng đã là vùng bấm 48dp rồi,
 * thêm cái nút tròn nhỏ xíu bên trái chỉ tổ mời người ta nhắm vào đúng nó.
 */
@Composable
fun DongChon(nhan: String, chon: Boolean, onBam: () -> Unit, modifier: Modifier = Modifier) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = modifier
            .fillMaxWidth()
            .clip(Bo.O)
            .clickable(onClick = onBam)
            .padding(horizontal = Cach.Vua, vertical = Cach.Vua),
    ) {
        Text(
            text = nhan,
            style = MaterialTheme.typography.bodyLarge,
            fontWeight = if (chon) FontWeight.SemiBold else FontWeight.Normal,
            color = if (chon) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.weight(1f),
        )
        if (chon) {
            Icon(
                imageVector = Lucide.Check,
                contentDescription = "Đang chọn",
                tint = MaterialTheme.colorScheme.primary,
                modifier = Modifier.size(Cach.Rong),
            )
        }
    }
}

// ---------------------------------------------------------------------
//  Ngày
// ---------------------------------------------------------------------

/**
 * Ngày dạng người đọc, từ chuỗi ISO của máy chủ.
 *
 * Trong tuần thì nói "3 ngày trước" — đó là câu trả lời cho "món này còn luân
 * chuyển không". Xa hơn thì ghi ngày thật, vì "87 ngày trước" bắt người ta phải
 * ngồi trừ ra mới biết là hồi nào.
 *
 * Chuỗi rỗng hoặc hỏng trả về rỗng: chỗ gọi tự quyết viết gì, chứ hàm này không
 * được bịa ra một cái ngày.
 */
fun ngayGon(iso: String): String {
    if (iso.isBlank()) return ""

    val luc = runCatching { OffsetDateTime.parse(iso) }.getOrNull() ?: return ""
    val ngay = luc.atZoneSameInstant(ZoneId.systemDefault()).toLocalDate()
    val cach = ChronoUnit.DAYS.between(ngay, LocalDate.now())

    return when {
        cach <= 0L -> "Hôm nay"
        cach == 1L -> "Hôm qua"
        cach < 7L -> "$cach ngày trước"
        else -> ngay.format(DateTimeFormatter.ofPattern("dd/MM/yyyy"))
    }
}

// ---------------------------------------------------------------------
//  Báo kết quả
// ---------------------------------------------------------------------

/**
 * Báo nhanh một câu rồi biến mất — lưu xong, lưu hỏng, không có quyền.
 *
 * Dùng bánh báo của hệ điều hành chứ không kẻ một dải chữ trong trang: quy tắc
 * của cả hệ thống là kết quả một thao tác thì bắn báo nhanh, không đóng đinh
 * một cái banner làm xô lệch bố cục rồi nằm đó tới lúc có người bấm tắt.
 *
 * Bắn đúng MỘT lần cho mỗi câu nhờ khoá `LaunchedEffect` theo chính câu đó, rồi
 * gọi `onXong` để nơi gọi xoá trạng thái — không xoá thì màn vẽ lại một cái là
 * bánh báo bật lên lần nữa.
 */
@Composable
fun BaoNhanh(chu: String, onXong: () -> Unit) {
    val boi = LocalContext.current

    LaunchedEffect(chu) {
        if (chu.isNotBlank()) Toast.makeText(boi, chu, Toast.LENGTH_SHORT).show()
        onXong()
    }
}

// ---------------------------------------------------------------------
//  Ô tìm + nút lọc
// ---------------------------------------------------------------------

/**
 * Ô TÌM VÀ NÚT LỌC — MỘT khối liền, không phải hai vật rời.
 *
 * Trước đây là một viên thuốc bo tròn đứng cạnh một ô vuông bo 12: hai hình khác
 * nhau, hai đường viền rời, và mắt đọc thành hai thứ chẳng liên quan. Chúng làm
 * CÙNG một việc — thu hẹp danh sách bên dưới — nên phải là một khối, ngăn nhau
 * bằng một vạch dọc mảnh chứ không bằng khoảng trống.
 *
 * VIỀN NÓI TRẠNG THÁI, và đây là chỗ đáng giá nhất của khối này:
 *
 * | Lúc | Viền |
 * |---|---|
 * | Bình thường | Xám `vien` — đủ đậm để tách khỏi nền xám của trang |
 * | Đang gõ | Xanh chủ đạo, dày 1.5 |
 * | Đang lọc | Xanh nhạt, và nửa bên phải tô nền xanh nhạt kèm con số |
 *
 * Bản cũ dùng `vienNhat` (#E5E7EB) trên nền #F5F6FA — lệch nhau đúng một nấc,
 * nhìn xa là cái ô tan vào nền và trang trông như thiếu mất ô tìm.
 *
 * Con số điều kiện nằm NGAY CẠNH icon lọc chứ không phải một chấm tròn đính góc:
 * chấm tròn đính vào một khối bo tròn thì hoặc bị cắt, hoặc phải thò ra ngoài
 * mép và phá mất đường bao.
 */
@Composable
fun OTimLoc(
    tuKhoa: String,
    onDoi: (String) -> Unit,
    goiY: String,
    soLoc: Int,
    onLoc: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val nguon = remember { MutableInteractionSource() }
    val dangGo by nguon.collectIsFocusedAsState()
    val dangLoc = soLoc > 0

    val mauVien by animateColorAsState(
        targetValue = when {
            dangGo -> MaterialTheme.colorScheme.primary
            dangLoc -> mauPhu.lam.copy(alpha = 0.45f)
            else -> mauPhu.vien
        },
        animationSpec = tween(Nhip.DoiMau),
        label = "vienOTim",
    )

    Surface(
        color = MaterialTheme.colorScheme.surface,
        shape = Bo.Tron,
        border = BorderStroke(if (dangGo) 1.5.dp else 1.dp, mauVien),
        modifier = modifier.height(CaoCham.ToiThieu),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier
                    .weight(1f)
                    .padding(start = Cach.Chuan, end = Cach.Gan),
            ) {
                Icon(
                    imageVector = Lucide.Search,
                    contentDescription = null,
                    tint = if (dangGo) MaterialTheme.colorScheme.primary else mauPhu.chuMo,
                    modifier = Modifier.size(18.dp),
                )

                Spacer(Modifier.width(Cach.Vua))

                Box(Modifier.weight(1f), contentAlignment = Alignment.CenterStart) {
                    if (tuKhoa.isEmpty()) {
                        Text(
                            text = goiY,
                            style = MaterialTheme.typography.bodyMedium,
                            color = mauPhu.chuMo,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                    BasicTextField(
                        value = tuKhoa,
                        onValueChange = onDoi,
                        singleLine = true,
                        interactionSource = nguon,
                        textStyle = MaterialTheme.typography.bodyMedium.copy(
                            color = MaterialTheme.colorScheme.onSurface,
                        ),
                        cursorBrush = SolidColor(MaterialTheme.colorScheme.primary),
                        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                        modifier = Modifier.fillMaxWidth(),
                    )
                }

                if (tuKhoa.isNotEmpty()) {
                    Icon(
                        imageVector = Lucide.X,
                        contentDescription = "Xoá từ khoá",
                        tint = mauPhu.chuMo,
                        modifier = Modifier
                            .clip(Bo.Tron)
                            .clickable { onDoi("") }
                            .padding(Cach.Sat)
                            .size(Cach.Chuan),
                    )
                }
            }

            VerticalDivider(
                thickness = 1.dp,
                color = mauPhu.vienNhat,
                modifier = Modifier.padding(vertical = Cach.Gan),
            )

            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier
                    .fillMaxHeight()
                    .background(if (dangLoc) mauPhu.lamNen else Color.Transparent)
                    .clickable(onClick = onLoc)
                    .padding(horizontal = Cach.Chuan),
            ) {
                Icon(
                    imageVector = Lucide.SlidersHorizontal,
                    contentDescription = "Bộ lọc",
                    tint = if (dangLoc) mauPhu.lam else mauPhu.chuThuong,
                    modifier = Modifier.size(18.dp),
                )
                if (dangLoc) {
                    Spacer(Modifier.width(Cach.Sat + 2.dp))
                    Text(
                        text = soLoc.toString(),
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.Bold,
                        color = mauPhu.lam,
                    )
                }
            }
        }
    }
}

/**
 * Công tắc gạt kèm nhãn và một câu giải thích.
 *
 * CẢ DÒNG bấm được, không phải chỉ cái nút gạt bé tí bên phải: mấy công tắc này
 * đứng trong biểu mẫu, ngón cái đang gõ chữ mà phải nhắm vào một vật rộng 32dp
 * sát mép phải màn hình là chỗ bấm trượt.
 *
 * Câu giải thích không phải để trang trí: "trừ kho khi bán" và "quản lý theo số
 * seri" là hai thứ người khai hàng lần đầu không đoán ra nghĩa, mà bật nhầm thì
 * mãi sau mới lộ ra ở sổ kho.
 */
@Composable
fun CongTac(
    nhan: String,
    bat: Boolean,
    onDoi: (Boolean) -> Unit,
    modifier: Modifier = Modifier,
    phu: String = "",
) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = modifier
            .fillMaxWidth()
            .clip(Bo.O)
            .clickable { onDoi(!bat) }
            .padding(vertical = Cach.Gan),
    ) {
        Column(Modifier.weight(1f)) {
            Text(
                text = nhan,
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurface,
            )
            if (phu.isNotBlank()) {
                Spacer(Modifier.height(2.dp))
                Text(
                    text = phu,
                    style = MaterialTheme.typography.bodySmall,
                    color = mauPhu.chuMo,
                )
            }
        }

        Spacer(Modifier.width(Cach.Vua))

        Switch(
            checked = bat,
            onCheckedChange = onDoi,
            colors = SwitchDefaults.colors(
                checkedThumbColor = MaterialTheme.colorScheme.onPrimary,
                checkedTrackColor = MaterialTheme.colorScheme.primary,
                checkedBorderColor = MaterialTheme.colorScheme.primary,
                uncheckedThumbColor = mauPhu.chuMo,
                uncheckedTrackColor = mauPhu.matChim,
                uncheckedBorderColor = mauPhu.vien,
            ),
        )
    }
}

/**
 * Hộp thoại hỏi lại trước một việc KHÔNG lùi được.
 *
 * Nút việc-nguy-hiểm để ĐỎ, nút thoát ra để XANH — đúng quy tắc cứng của hệ
 * thống (bỏ đi thì đỏ, đồng ý thì xanh), và ở đây nó còn tiện thêm một tầng:
 * cái nút to màu xanh bắt mắt nhất lại chính là nút AN TOÀN, nên bấm vội theo
 * quán tính cũng không mất gì.
 */
@Composable
fun HoiXacNhan(
    tieuDe: String,
    noiDung: String,
    nhanLam: String,
    onLam: () -> Unit,
    onThoi: () -> Unit,
    dangChay: Boolean = false,
) {
    AlertDialog(
        onDismissRequest = { if (!dangChay) onThoi() },
        shape = Bo.The,
        containerColor = MaterialTheme.colorScheme.surface,
        title = {
            Text(
                text = tieuDe,
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onSurface,
            )
        },
        text = {
            Text(
                text = noiDung,
                style = MaterialTheme.typography.bodyMedium,
                color = mauPhu.chuThuong,
            )
        },
        // Hai nút xếp NGANG NHAU ở chân hộp thoại, không dạt phải: hàng nút của
        // hệ thống này luôn canh giữa và chia đôi bề ngang.
        confirmButton = {
            HangNut {
                NutNguyHiem(
                    chu = nhanLam,
                    onBam = onLam,
                    modifier = Modifier.weight(1f),
                    moKhoa = !dangChay,
                )
                NutChinh(
                    chu = "Thôi",
                    onBam = onThoi,
                    modifier = Modifier.weight(1f),
                    moKhoa = !dangChay,
                    dangChay = dangChay,
                )
            }
        },
    )
}

/**
 * Một ô thao tác: icon trong ô vuông bo góc, nhãn nhỏ nằm dưới.
 *
 * Dùng để gom mấy việc làm được với một bản ghi thành MỘT HÀNG thay vì mỗi việc
 * một cái nút chạy hết bề ngang. Bốn nút xếp dọc ăn gần một phần ba màn hình và
 * đẩy hết phần thông tin lên trên nếp gấp; cùng bốn việc ấy nằm ngang thì vừa
 * đúng một dòng.
 *
 * Có nhãn chứ không phải icon trần: "sao chép" với "nhân đôi" vẽ ra gần giống
 * nhau, mà đây là mấy việc đổi dữ liệu thật nên đoán nhầm là hỏng thật.
 */
@Composable
fun ONutViec(
    bieuTuong: ImageVector,
    nhan: String,
    onBam: () -> Unit,
    modifier: Modifier = Modifier,
    nguyHiem: Boolean = false,
    moKhoa: Boolean = true,
) {
    val mau = when {
        !moKhoa -> mauPhu.chuMo
        nguyHiem -> mauPhu.do_
        else -> mauPhu.chuThuong
    }

    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        modifier = modifier
            .clip(Bo.O)
            .clickable(enabled = moKhoa, onClick = onBam)
            .padding(vertical = Cach.Gan),
    ) {
        Surface(
            color = if (nguyHiem) mauPhu.doNen else MaterialTheme.colorScheme.surface,
            shape = Bo.O,
            border = BorderStroke(1.dp, if (nguyHiem) mauPhu.do_.copy(alpha = 0.35f) else mauPhu.vien),
            modifier = Modifier.size(CaoCham.ToiThieu),
        ) {
            Box(contentAlignment = Alignment.Center) {
                Icon(
                    imageVector = bieuTuong,
                    contentDescription = nhan,
                    tint = mau,
                    modifier = Modifier.size(Cach.Rong),
                )
            }
        }

        Spacer(Modifier.height(Cach.Sat + 2.dp))

        Text(
            text = nhan,
            style = MaterialTheme.typography.labelSmall,
            color = mau,
            maxLines = 1,
        )
    }
}

/**
 * Một mục GẬP LẠI: hàng tiêu đề bấm được, nội dung chỉ hiện khi mở.
 *
 * Sinh ra cho khối thuộc tính: một cửa hàng điện máy có bảy tám thuộc tính, mỗi
 * cái cả chục giá trị. Đổ hết ra một mạch là cuộn mười màn mới hết, trong khi
 * khai một mặt hàng thường chỉ đụng tới hai ba thuộc tính.
 *
 * `tomTat` là phần đang chọn, hiện ngay trên hàng tiêu đề — gập lại mà không
 * thấy mình đã tick gì thì phải mở từng cái ra dò.
 */
@Composable
fun MucGap(
    nhan: String,
    tomTat: String,
    modifier: Modifier = Modifier,
    noiDung: @Composable ColumnScope.() -> Unit,
) {
    var mo by remember { mutableStateOf(false) }

    Column(modifier.fillMaxWidth()) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier
                .fillMaxWidth()
                .clip(Bo.O)
                .clickable { mo = !mo }
                .padding(vertical = Cach.Vua),
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    text = nhan,
                    style = MaterialTheme.typography.bodyLarge,
                    fontWeight = FontWeight.Medium,
                    color = MaterialTheme.colorScheme.onSurface,
                )
                if (tomTat.isNotBlank()) {
                    Spacer(Modifier.height(2.dp))
                    Text(
                        text = tomTat,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.primary,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }

            Icon(
                imageVector = if (mo) Lucide.ChevronUp else Lucide.ChevronDown,
                contentDescription = if (mo) "Thu lại" else "Mở ra",
                tint = mauPhu.chuMo,
                modifier = Modifier.size(Cach.Rong),
            )
        }

        if (mo) {
            Column(Modifier.padding(bottom = Cach.Vua), content = noiDung)
        }
    }
}

/**
 * Hộp CHỜ — một việc dài đang chạy, chưa xong thì đừng bấm gì khác.
 *
 * Sinh ra cho lượt nhập tệp. Trước đó chỗ ấy chỉ bắn một cái toast rồi im: bấm
 * chọn tệp xong màn hình đứng yên mười mấy giây (app vừa ở nền nên Android hoãn
 * cả lượt gọi mạng), người dùng không biết nó đang chạy hay đã chết, và bấm
 * chọn tệp lần nữa.
 *
 * KHÔNG đóng được bằng cách chạm ra ngoài hay bấm Back: nửa chừng mà đóng thì
 * việc vẫn chạy tiếp ở dưới, và người dùng đi bấm thứ khác trong khi dữ liệu
 * đang được ghi.
 */
@Composable
fun HopDangChay(chu: String) {
    AlertDialog(
        onDismissRequest = {},
        shape = Bo.The,
        containerColor = MaterialTheme.colorScheme.surface,
        text = {
            Row(verticalAlignment = Alignment.CenterVertically) {
                CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                Spacer(Modifier.width(Cach.Chuan))
                Text(
                    text = chu,
                    style = MaterialTheme.typography.bodyLarge,
                    color = MaterialTheme.colorScheme.onSurface,
                )
            }
        },
        // Không có nút nào: việc đang chạy thì chẳng có lựa chọn nào để bày.
        confirmButton = {},
    )
}
