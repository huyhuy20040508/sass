package com.selliotech.app

import android.os.Build
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.BlurEffect
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ColorFilter
import androidx.compose.ui.graphics.ColorMatrix
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.TileMode
import androidx.compose.ui.graphics.drawOutline
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.graphics.layer.GraphicsLayer
import androidx.compose.ui.graphics.lerp
import androidx.compose.ui.graphics.layer.drawLayer
import androidx.compose.ui.graphics.rememberGraphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.positionInRoot
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.composables.icons.lucide.ChevronsUpDown
import com.composables.icons.lucide.Lucide
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.BongNoi
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.Cam
import com.selliotech.app.ui.theme.DamKinh
import com.selliotech.app.ui.theme.DamKinhCu
import com.selliotech.app.ui.theme.KinhDuoi
import com.selliotech.app.ui.theme.KinhTren
import com.selliotech.app.ui.theme.Luc
import com.selliotech.app.ui.theme.Ngoc
import com.selliotech.app.ui.theme.NgocDam
import com.selliotech.app.ui.theme.NgocSang
import com.selliotech.app.ui.theme.Nhip
import com.selliotech.app.ui.theme.Noi
import com.selliotech.app.ui.theme.VienKinh
import com.selliotech.app.ui.theme.VienKinhSang
import com.selliotech.app.ui.theme.Xanh
import com.selliotech.app.ui.theme.XanhDam
import com.selliotech.app.ui.theme.XanhSang
import com.selliotech.app.ui.theme.mauPhu

/** Một tab trên thanh điều hướng. `ma` là khoá trong code, `nhan` là lời đọc màn hình. */
data class MucTab(val ma: String, val nhan: String, val bieuTuong: ImageVector)

/**
 * Nút tròn rời nằm cạnh thanh điều hướng.
 *
 * Hai dạng, vì hai khu cần hai thứ khác hẳn nhau:
 *
 * - `UngDung` — ô ứng dụng nhiều màu, mở tấm module. Khu Quản trị dùng cái này:
 *   thanh chỉ đủ chỗ cho ba tab, mà số module sẽ còn dài ra mãi, nên phải có
 *   một cửa mở ra hết.
 * - `Viec` — một icon, một việc. Khu Thu ngân dùng cái này cho Quét mã: cả ca
 *   trực họ chỉ làm đúng một việc đó, mở thêm một tấm nữa là thừa một cú chạm.
 *
 * Khu nào không có gì xứng chỗ này thì truyền null, đừng bịa nút cho cân đối.
 */
sealed interface NutNoi {
    val nhan: String
    val onBam: () -> Unit

    data class UngDung(
        override val nhan: String = "Ứng dụng",
        override val onBam: () -> Unit,
    ) : NutNoi

    data class Viec(
        override val nhan: String,
        val bieuTuong: ImageVector,
        val moKhoa: Boolean = true,
        override val onBam: () -> Unit,
    ) : NutNoi
}

/**
 * Khoảng đáy mà thanh nổi đang che.
 *
 * Thanh nổi nằm ĐÈ lên nội dung chứ không đẩy nội dung lên, nên màn cuộn phải
 * tự chừa chỗ. Truyền xuống bằng CompositionLocal thay vì thêm tham số cho
 * từng màn: đây là chuyện của cái khung, màn không cần biết vì sao đáy lại
 * thiếu mất mấy chục dp.
 */
val LocalDemDuoi = compositionLocalOf { 0.dp }

/** Khoảng đỉnh mà thanh trạng thái của máy đang chiếm. */
val LocalDemTren = compositionLocalOf { 0.dp }

/** Tiệm nào, khu nào, và có đổi khu được không. Mọi màn đọc chung từ đây. */
data class ThongTinMu(
    val tenCuaHang: String = "",
    val tenKhu: String = "",
    val chuCaiDau: String = "",
    val onDoiKhu: (() -> Unit)? = null,
)

/**
 * Khung rót thông tin nhận diện xuống, màn chỉ việc gọi `MuTrang("Tên màn")`.
 *
 * Nếu bắt từng màn nhận thêm ba tham số tiệm/khu/chữ cái đầu thì màn nào cũng
 * phải khai chúng rồi chuyền tay tiếp, trong khi chẳng màn nào dùng tới —
 * chúng chỉ đi ngang qua để tới cái mũ.
 */
val LocalMu = compositionLocalOf { ThongTinMu() }

/** Lối tắt: `demDuoi` thay vì `LocalDemDuoi.current`. */
val demDuoi: Dp
    @Composable get() = LocalDemDuoi.current

/** Lối tắt: `demTren` thay vì `LocalDemTren.current`. */
val demTren: Dp
    @Composable get() = LocalDemTren.current

/**
 * Khung ngoài của app: mũ nhận diện ở trên, thanh điều hướng NỔI ở đáy.
 *
 * VÌ SAO THANH NỔI. Thanh dán đáy chia màn hình thành hai băng cứng và ăn hẳn
 * một dải ngang suốt chiều rộng máy. Thanh nổi trả dải đó lại cho nội dung —
 * danh sách hàng chạy tiếp xuống dưới nó — và nói rõ bằng hình rằng chỗ đi lại
 * KHÔNG thuộc về màn đang xem: nó là lớp trên, luôn ở đó, mọi màn như nhau.
 *
 * Đây cũng là chỗ DUY NHẤT trong app được dùng bóng dày. Muốn một vật trông
 * như đang nổi thì phải có bóng, không có cách nào khác; đổi lại mọi thẻ còn
 * lại giữ đúng 1dp.
 *
 * Khung KHÔNG tự biết có những tab nào — mỗi khu truyền vào danh sách của
 * mình. Thu ngân và Quản trị là hai bộ tab khác hẳn nhau.
 */
@Composable
fun KhungApp(
    tenCuaHang: String,
    tenKhu: String,
    chuCaiDau: String,
    tabs: List<MucTab>,
    maDangChon: String,
    onChonTab: (String) -> Unit,
    modifier: Modifier = Modifier,
    nutNoi: NutNoi? = null,
    onDoiKhu: (() -> Unit)? = null,
    noiDung: @Composable (Modifier) -> Unit,
) {
    val demDinh = WindowInsets.statusBars.asPaddingValues().calculateTopPadding()
    val demHeDieuHanh = WindowInsets.navigationBars.asPaddingValues().calculateBottomPadding()

    // Đỉnh chỉ phải né thanh trạng thái của máy: mũ trang CUỘN THEO nội dung
    // nên nó không che gì cả. Đáy thì phải né cả thanh nổi.
    val cheDinh = demDinh + Cach.Gan
    val cheDay = demHeDieuHanh + Noi.Cao + Noi.Le * 2

    // Ảnh chụp của toàn bộ thân màn, vẽ lại vào đây mỗi khung hình. Hai thanh kính
    // lấy đúng mảnh nằm sau lưng mình trong tấm này rồi làm mờ — đó là cách duy
    // nhất để một vật trên Android nhìn xuyên được xuống thứ nằm dưới nó.
    val lopNen = rememberGraphicsLayer()

    Box(modifier = modifier.fillMaxSize()) {
        Box(
            Modifier
                .fillMaxSize()
                .background(MaterialTheme.colorScheme.background)
                .drawWithContent {
                    lopNen.record { this@drawWithContent.drawContent() }
                    drawLayer(lopNen)
                },
        ) {
            CompositionLocalProvider(
                LocalDemTren provides cheDinh,
                LocalDemDuoi provides cheDay,
                LocalMu provides ThongTinMu(tenCuaHang, tenKhu, chuCaiDau, onDoiKhu),
            ) {
                noiDung(Modifier.fillMaxSize())
            }
        }

        ThanhNoi(
            tabs = tabs,
            maDangChon = maDangChon,
            onChonTab = onChonTab,
            nutNoi = nutNoi,
            lopNen = lopNen,
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .padding(bottom = demHeDieuHanh + Noi.Le),
        )
    }
}

/**
 * Mũ trang — CUỘN THEO nội dung, không nổi.
 *
 * VÌ SAO KHÔNG NỔI NỮA. Đã thử cho nó thành thanh kính ở đỉnh cho khớp thanh
 * dưới: hỏng. Một viên kính tràn hết bề ngang nằm trên đầu các thẻ có lề 16dp
 * là hai lưới lệch nhau ngay trước mắt, và hai viên giống hệt nhau kẹp trên
 * dưới thì màn hình thành cái lồng. Mũ trang là PHẦN CỦA TRANG, nên nó phải
 * cuộn cùng trang và đứng đúng lề của trang.
 *
 * Còn thanh dưới thì vẫn nổi, và giờ nó là lớp nổi DUY NHẤT — nghĩa của lớp đó
 * mới rõ: cái gì lơ lửng là chỗ đi lại, mọi thứ khác là nội dung.
 *
 * BỐ CỤC. Chip nhận diện nhỏ nằm trên, tên màn to nằm dưới: đọc từ hoàn cảnh
 * xuống chủ đề — "đang ở tiệm này, khu này" rồi mới tới "và đây là màn Tổng
 * quan". Tên màn phải to và phải có: thanh dưới đã bỏ hết chữ, không còn chỗ
 * nào khác nói cho người dùng biết họ đang mở cái gì.
 */
@Composable
fun MuTrang(tieuDe: String, modifier: Modifier = Modifier) {
    val mu = LocalMu.current

    Column(modifier = modifier.fillMaxWidth()) {
        ChipNhanDien(mu)

        Spacer(Modifier.height(Cach.Vua))

        Text(
            text = tieuDe,
            style = MaterialTheme.typography.headlineSmall,
            color = MaterialTheme.colorScheme.onBackground,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

/**
 * Chip nhận diện: ô chữ cái đầu, tên tiệm, tên khu.
 *
 * ÔM SÁT NỘI DUNG, không kéo hết bề ngang. Một thanh tràn mép thì cái mũi tên
 * đổi khu bị đẩy sang tận rìa phải, cách xa mấy chữ mà nó nói về — nhìn như
 * một nút lạc chỗ. Chip ôm sát thì mũi tên dính liền tên khu, và cả cụm là một
 * vùng chạm gọn.
 *
 * Đổi khu được thì có mũi tên hai chiều và bấm được; chỉ một khu thì không.
 * Bày một nút bấm vào không đổi gì là cách mất lòng tin nhanh nhất.
 */
@Composable
private fun ChipNhanDien(mu: ThongTinMu) {
    val doiDuoc = mu.onDoiKhu != null

    Surface(
        color = MaterialTheme.colorScheme.surface,
        shape = Bo.Tron,
        border = BorderStroke(1.dp, mauPhu.vienNhat),
        modifier = Modifier
            .clip(Bo.Tron)
            .then(if (doiDuoc) Modifier.clickable(onClick = mu.onDoiKhu!!) else Modifier),
    ) {
        Row(
            modifier = Modifier.padding(
                start = Cach.Sat,
                end = if (doiDuoc) Cach.Gan else Cach.Vua,
                top = Cach.Sat,
                bottom = Cach.Sat,
            ),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            AnhCuaHang(mu.chuCaiDau)

            Spacer(Modifier.width(Cach.Gan))

            Text(
                text = mu.tenCuaHang,
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = MaterialTheme.colorScheme.onSurface,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f, fill = false),
            )

            Text(
                text = " · " + mu.tenKhu,
                style = MaterialTheme.typography.labelMedium,
                color = mauPhu.chuMo,
                maxLines = 1,
            )

            if (doiDuoc) {
                Spacer(Modifier.width(Cach.Sat))
                Icon(
                    imageVector = Lucide.ChevronsUpDown,
                    contentDescription = "Đổi khu làm việc",
                    tint = mauPhu.chuMo,
                    modifier = Modifier.size(Cach.Chuan),
                )
            }
        }
    }
}

/** Ô chữ cái đầu của tiệm. Vuông bo góc, không tròn — tròn là ảnh người. */
@Composable
private fun AnhCuaHang(chuCaiDau: String) {
    Box(
        modifier = Modifier
            .size(Cach.Khoi + Cach.Sat)
            // Xanh thương hiệu chứ không phải ngọc: đây là NHẬN DIỆN của tiệm,
            // giống hệt bên web. Ngọc chỉ dành cho trạng thái điều hướng.
            .background(Brush.linearGradient(listOf(XanhSang, XanhDam)), Bo.Nho),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            text = chuCaiDau,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.Bold,
            color = Color.White,
        )
    }
}

/** Thanh nổi hoàn chỉnh: viên kính đựng tab bên trái, nút tròn rời bên phải. */
@Composable
private fun ThanhNoi(
    tabs: List<MucTab>,
    maDangChon: String,
    onChonTab: (String) -> Unit,
    nutNoi: NutNoi?,
    lopNen: GraphicsLayer,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier
            .fillMaxWidth()
            .padding(horizontal = Noi.Le),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Noi.Le),
    ) {
        KhoiTab(
            tabs = tabs,
            maDangChon = maDangChon,
            onChonTab = onChonTab,
            lopNen = lopNen,
            modifier = Modifier.weight(1f),
        )

        // Nút rời chứ không nhét vào trong viên: nó không phải một chỗ để ĐI
        // tới mà là một việc để LÀM. Hai thứ khác nhau thì đừng nằm chung vỏ.
        nutNoi?.let { NutTronNoi(n = it, lopNen = lopNen) }
    }
}

/** Android 12 trở lên mới có làm mờ nền chạy bằng phần cứng. */
private val COLAMMO = Build.VERSION.SDK_INT >= Build.VERSION_CODES.S

/**
 * Nâng độ tươi của mảng nền đã làm mờ.
 *
 * Làm mờ mạnh thì màu bị trộn lẫn và xám đi trông thấy. Kéo độ tươi lên là màu
 * dưới thanh sống lại — cuộn qua một thẻ xanh thì thấy hẳn vệt xanh, chứ không
 * phải một mảng ghi nhờ nhờ. Đây là mẹo của lớp vật liệu kính bên iOS, và nó là
 * thứ tách "kính thật" khỏi "một miếng mờ".
 */
private val LOC_TUOI = ColorFilter.colorMatrix(ColorMatrix().apply { setToSaturation(1.7f) })

/**
 * Mặt kính THẬT — nhìn xuyên qua được, nhưng mờ.
 *
 * CÁCH LÀM. Android không có sẵn phép "làm mờ thứ nằm sau lưng tôi" như bên
 * web. Nên thân màn được ghi lại vào một tấm (`lopNen`) ngay lúc nó vẽ; tới
 * lượt thanh nổi vẽ, nó cắt đúng mảnh của tấm ấy nằm sau lưng mình, dán vào
 * một lớp có `BlurEffect`, rồi phủ lên một màng trắng mỏng. Cuộn danh sách là
 * thấy vệt hàng nhoè chạy qua dưới thanh — đúng như tấm kính mờ đặt trên giấy.
 *
 * Màng trắng để MỎNG (`DamKinh`): cái nhìn xuyên qua mới là chất kính, phủ dày
 * lên là uổng công làm mờ. Máy dưới Android 12 không làm mờ được thì phủ gần
 * kín — trong mà không mờ thì chữ dưới thanh lòi lên lẫn vào icon, trông như
 * lỗi chứ không như kính.
 *
 * Cả viên tab lẫn nút tròn dùng chung hàm này. Hai vật phải cùng một chất thì
 * mắt mới đọc ra chúng là một bộ đang nổi trên cùng một lớp.
 */
@Composable
private fun Modifier.matKinh(bo: Shape, lopNen: GraphicsLayer): Modifier {
    val lopMo = rememberGraphicsLayer()
    var choDung by remember { mutableStateOf<Offset?>(null) }

    return this
        .onGloballyPositioned { choDung = it.positionInRoot() }
        // Bóng đi trước, và nó cũng là cái cắt mọi thứ vẽ sau theo đúng hình.
        .shadow(Noi.Bong, bo, ambientColor = BongNoi, spotColor = BongNoi)
        .drawBehind {
            val cho = choDung
            if (COLAMMO && cho != null) {
                val banKinh = Noi.MoKinh.toPx()
                lopMo.renderEffect = BlurEffect(banKinh, banKinh, TileMode.Clamp)
                lopMo.colorFilter = LOC_TUOI
                lopMo.record {
                    // Kéo cả tấm nền ngược lại đúng bằng chỗ mình đang đứng, để
                    // mảnh rơi vào khung ghi chính là mảnh nằm sau lưng thanh.
                    translate(-cho.x, -cho.y) { drawLayer(lopNen) }
                }
                drawLayer(lopMo)
            }

            // 2. Màng kính. MỜ chứ không bóng: không vệt loá, không đèn rọi.
            //
            // Màu màng lạnh hơn cả nền xám lẫn thẻ trắng, và đó là chỗ quyết
            // định. Pha trắng thì thanh nằm trên thẻ trắng là tan biến, người
            // dùng mất luôn chỗ bấm; ngả xám một bậc là mắt đọc ra ngay đây là
            // một LỚP khác đang đè lên, chứ không phải một mảng của trang.
            val dam = if (COLAMMO) DamKinh else DamKinhCu
            drawRect(
                Brush.verticalGradient(
                    listOf(
                        KinhTren.copy(alpha = dam),
                        KinhDuoi.copy(alpha = dam + 0.06f),
                    ),
                ),
            )

            val vien = bo.createOutline(size, layoutDirection, this)

            // 3. Đường bao thật, chạy đều cả vòng. Đây mới là thứ giữ cho thanh
            // còn hình khi nó trôi qua một thẻ trắng.
            drawOutline(
                outline = vien,
                color = VienKinh,
                style = Stroke(width = Noi.VanhKinh.toPx()),
            )

            // 4. Vành sáng đè lên nửa trên của đường bao: mép trên của một vật
            // trong suốt phải bắt sáng, không thì nó dẹt như hình vẽ.
            drawOutline(
                outline = vien,
                brush = Brush.verticalGradient(
                    0f to Color.White,
                    0.5f to Color.Transparent,
                    1f to Color.Transparent,
                ),
                style = Stroke(width = Noi.VanhKinh.toPx()),
            )
        }
}

/**
 * Viên kính đựng tab, bên trong là VÒNG TRÒN PHÁT SÁNG chạy dưới tab đang chọn.
 *
 * Con trượt TRƯỢT chứ không nhảy: mắt bám được đường đi từ tab cũ sang tab mới.
 * Nhảy cóc thì mỗi lần đổi tab là một lần phải tìm lại xem mình đang ở đâu.
 *
 * KHÔNG có chữ, cả dưới icon lẫn trong con trượt. Thanh này chỉ còn hình: nhà,
 * thùng hàng, người — ba hình ai cũng đọc được. Bỏ chữ đi thì các ô giãn ra
 * thoáng hẳn và cái vòng sáng mới đủ chỗ để sáng. Tên tiệm với tên khu đã nằm
 * trên mũ app, còn tên tab thì nhìn hình là ra.
 */
@Composable
private fun KhoiTab(
    tabs: List<MucTab>,
    maDangChon: String,
    onChonTab: (String) -> Unit,
    lopNen: GraphicsLayer,
    modifier: Modifier = Modifier,
) {
    val doDac = LocalDensity.current

    // Chỉ cần đo đúng MỘT con số: bề ngang lòng viên. Các ô chia đều nhau vì
    // không ô nào mang chữ, nên chỗ đứng của con trượt tính thẳng ra được, khỏi
    // phải đi hỏi từng ô rồi chờ tất cả trả lời.
    var rongLong by remember { mutableStateOf(0.dp) }
    val oRong = if (tabs.isEmpty()) 0.dp else rongLong / tabs.size
    val chiSo = tabs.indexOfFirst { it.ma == maDangChon }.coerceAtLeast(0)

    val loXo = spring<Dp>(dampingRatio = Nhip.LoXoTat, stiffness = Spring.StiffnessMediumLow)
    val x by animateDpAsState(oRong * chiSo + (oRong - Noi.CoTruot) / 2, loXo, label = "truot")

    // Ba lớp rời nhau, và phải rời thì quầng sáng mới toả được RA NGOÀI mép viên.
    //
    // Trước đây cả ba nằm chung trong một Box mang `matKinh`, mà `matKinh` thì
    // cắt mọi thứ theo hình viên thuốc — nên cái quầng bị xén cụt bốn phía và
    // con trượt trông như miếng dán tròn. Tách tấm kính ra thành một lớp riêng
    // chỉ để vẽ nền là quầng được tự do loang ra nền xám, đúng như ánh sáng thật.
    Box(modifier = modifier.height(Noi.Cao)) {
        Box(
            Modifier
                .fillMaxSize()
                .matKinh(Bo.Tron, lopNen),
        )

        if (oRong > 0.dp) {
            VongSang(
                modifier = Modifier
                    .align(Alignment.CenterStart)
                    .offset(x = Noi.Dem + x - Noi.QuangTruot),
            )
        }

        Row(
            modifier = Modifier
                .fillMaxSize()
                .padding(Noi.Dem)
                .onGloballyPositioned { toaDo ->
                    val be = with(doDac) { toaDo.size.width.toDp() }
                    if (rongLong != be) rongLong = be
                },
            verticalAlignment = Alignment.CenterVertically,
        ) {
            tabs.forEach { tab ->
                OTab(
                    tab = tab,
                    dangChon = tab.ma == maDangChon,
                    onBam = { onChonTab(tab.ma) },
                    modifier = Modifier
                        .weight(1f)
                        .fillMaxHeight(),
                )
            }
        }
    }
}

/**
 * Vòng tròn dưới tab đang chọn — một QUẢ CẦU THUỶ TINH, không phải khoanh màu.
 *
 * Bốn lớp, xếp đúng thứ tự ánh sáng đi:
 *
 * 1. Quầng toả ra ngoài mép, cùng màu với chính nó. Bóng đen dưới một vật xanh
 *    làm nó trông như miếng dán; quầng cùng màu làm nó như đang phát sáng
 *    xuống lớp kính bên dưới.
 * 2. Thân cầu, sáng lệch về trên trái theo hướng đèn.
 * 3. Đốm loá ở chóp — chỗ đèn đập thẳng vào. Thiếu cái đốm này thì quả cầu vẫn
 *    tròn nhưng lì như nhựa; có nó là ra thuỷ tinh.
 * 4. Vành sáng mảnh ôm nửa trên, chỗ mặt cong hắt sáng ra rìa.
 */
@Composable
private fun VongSang(modifier: Modifier = Modifier) {
    Box(
        modifier = modifier
            .size(Noi.CoTruot + Noi.QuangTruot * 2)
            .drawBehind {
                val tam = center
                val banKinh = Noi.CoTruot.toPx() / 2
                val toa = banKinh + Noi.QuangTruot.toPx()

                // Quầng đi hai lớp: một lớp loang rộng gần tan hết, một lớp ôm
                // sát mép đậm hơn. Một lớp thôi thì hoặc là viền cứng, hoặc là
                // mờ tịt — hai lớp mới ra ánh sáng có tâm.
                //
                // Cả hai đều để NHẠT. Quầng chỉ cần nói "cái này đang bật", chứ
                // không phải một ngọn đèn: sáng quá thì mắt bị nó kéo về suốt,
                // mà thanh điều hướng thì không có việc gì đáng để đòi chú ý
                // liên tục như thế.
                drawCircle(
                    brush = Brush.radialGradient(
                        colors = listOf(Ngoc.copy(alpha = 0.20f), Color.Transparent),
                        center = tam,
                        radius = toa,
                    ),
                    radius = toa,
                    center = tam,
                )
                drawCircle(
                    brush = Brush.radialGradient(
                        colors = listOf(Ngoc.copy(alpha = 0.26f), Color.Transparent),
                        center = tam,
                        radius = banKinh * 1.3f,
                    ),
                    radius = banKinh * 1.3f,
                    center = tam,
                )

                // Thân cầu. Chóp có sáng lên nhưng KHÔNG bạc trắng: đủ để thấy
                // mặt cong, chưa tới mức loá. Cả dải màu cũng đẩy sâu xuống một
                // bậc — chóp cầu chỉ tới NgocSang thay vì gần trắng, thân đứng
                // ở Ngoc, chân xuống NgocDam. Nhờ vậy icon trắng ở giữa cũng ăn
                // nền đậm hơn, đọc rõ hơn hẳn.
                drawCircle(
                    brush = Brush.radialGradient(
                        colorStops = arrayOf(
                            0f to NgocSang,
                            0.5f to Ngoc,
                            1f to NgocDam,
                        ),
                        center = Offset(tam.x - banKinh * 0.34f, tam.y - banKinh * 0.46f),
                        radius = banKinh * 1.75f,
                    ),
                    radius = banKinh,
                    center = tam,
                )

                // Đốm ở chóp: giữ lại vì thiếu nó quả cầu lì như nhựa, nhưng
                // hạ hẳn xuống còn một vệt mờ. Đốm trắng đậm là thứ gây chói
                // nhất trên cả thanh.
                val domTam = Offset(tam.x - banKinh * 0.22f, tam.y - banKinh * 0.46f)
                drawCircle(
                    brush = Brush.radialGradient(
                        colors = listOf(Color.White.copy(alpha = 0.3f), Color.Transparent),
                        center = domTam,
                        radius = banKinh * 0.58f,
                    ),
                    radius = banKinh * 0.58f,
                    center = domTam,
                )

                drawCircle(
                    brush = Brush.verticalGradient(
                        colors = listOf(Color.White.copy(alpha = 0.34f), Color.Transparent),
                        startY = tam.y - banKinh,
                        endY = tam.y + banKinh * 0.2f,
                    ),
                    radius = banKinh - Noi.VanhKinh.toPx() / 2,
                    center = tam,
                    style = Stroke(width = Noi.VanhKinh.toPx()),
                )
            },
    )
}

/** Một ô tab: chỉ icon, đổi màu khi được chọn. */
@Composable
private fun OTab(
    tab: MucTab,
    dangChon: Boolean,
    onBam: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val mauIcon by animateColorAsState(
        targetValue = if (dangChon) Color.White else mauPhu.chuMo,
        animationSpec = tween(Nhip.DoiMau),
        label = "mauIcon",
    )

    Box(
        modifier = modifier
            .clip(Bo.Tron)
            .clickable(onClick = onBam),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            imageVector = tab.bieuTuong,
            contentDescription = tab.nhan,
            tint = mauIcon,
            modifier = Modifier.size(Noi.CoIcon),
        )
    }
}

/** Nút tròn rời — cùng chất kính với viên tab, chỉ khác ruột. */
@Composable
private fun NutTronNoi(n: NutNoi, lopNen: GraphicsLayer) {
    val moKhoa = n !is NutNoi.Viec || n.moKhoa

    Box(
        modifier = Modifier
            .size(Noi.Cao)
            .matKinh(CircleShape, lopNen)
            .clip(CircleShape)
            .clickable(enabled = moKhoa, onClick = n.onBam),
        contentAlignment = Alignment.Center,
    ) {
        when (n) {
            is NutNoi.UngDung -> DauUngDung(
                moTa = n.nhan,
                modifier = Modifier.size(Cach.Khoi + Cach.Gan),
            )

            is NutNoi.Viec -> Icon(
                imageVector = n.bieuTuong,
                contentDescription = n.nhan,
                // Ngọc chứ không phải xanh hành động: nút này nằm trên thanh
                // nổi, mà cả thanh đi một tông riêng.
                tint = if (n.moKhoa) NgocDam else mauPhu.chuMo,
                modifier = Modifier.size(Cach.Khoi),
            )
        }
    }
}

/**
 * Dấu ứng dụng: bốn ô vuông bo góc, bốn màu, nghiêng nhẹ.
 *
 * Vẽ tay chứ không lấy icon một nét như mọi chỗ khác, và đó là chủ ý. Mọi icon
 * khác trong app là CHỨC NĂNG nên phải đồng bộ một nét xám; cái này là CỬA VÀO
 * chỗ chứa tất cả, nó phải trông khác hẳn thì ngón tay mới tìm ra ngay. Nghiêng
 * mười độ cho khỏi thành cái bảng tính.
 */
@Composable
private fun DauUngDung(moTa: String, modifier: Modifier = Modifier) {
    // Ngọc - lam - cam - lục, đúng bốn màu của mẫu.
    val mau = listOf(Ngoc, Xanh, Cam, Luc)

    Canvas(modifier = modifier.semantics { contentDescription = moTa }) {
        val khe = size.minDimension * 0.14f
        val canh = (size.minDimension - khe) / 2
        val bo = CornerRadius(canh * 0.32f, canh * 0.32f)

        rotate(degrees = -10f) {
            listOf(
                Offset(0f, 0f),
                Offset(canh + khe, 0f),
                Offset(0f, canh + khe),
                Offset(canh + khe, canh + khe),
            ).forEachIndexed { i, goc ->
                val goc2 = Offset(goc.x + canh, goc.y + canh)

                // Thân ô: nhạt ở góc trên trái, đậm dần xuống dưới phải — cùng
                // hướng đèn với cả cái thanh, để bốn ô này không thành bốn mảnh
                // giấy màu dán lên.
                drawRoundRect(
                    brush = Brush.linearGradient(
                        colors = listOf(lerp(mau[i], Color.White, 0.34f), mau[i]),
                        start = goc,
                        end = goc2,
                    ),
                    topLeft = goc,
                    size = Size(canh, canh),
                    cornerRadius = bo,
                )

                // Loá nửa trên. Cùng một mẹo với mặt kính, thu nhỏ lại.
                drawRoundRect(
                    brush = Brush.verticalGradient(
                        colors = listOf(Color.White.copy(alpha = 0.5f), Color.Transparent),
                        startY = goc.y,
                        endY = goc.y + canh * 0.62f,
                    ),
                    topLeft = goc,
                    size = Size(canh, canh),
                    cornerRadius = bo,
                )
            }
        }
    }
}
