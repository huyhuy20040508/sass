package com.selliotech.app

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.composables.icons.lucide.ChevronRight
import com.composables.icons.lucide.CircleCheck
import com.composables.icons.lucide.Lucide
import com.selliotech.app.ui.ChipChon
import com.selliotech.app.ui.HuyThayDoi
import com.selliotech.app.ui.OXuong
import com.selliotech.app.ui.The
import com.selliotech.app.ui.VachUuTien
import com.selliotech.app.ui.mucThayDoi
import com.selliotech.app.ui.tienVN
import com.selliotech.app.ui.theme.Bieu
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.Noi
import com.selliotech.app.ui.theme.XanhDam
import com.selliotech.app.ui.theme.XanhSang
import com.selliotech.app.ui.theme.mauPhu
import java.time.LocalDate

/**
 * Kỳ đang xem. Ba mốc, không hơn.
 *
 * "Hôm nay" vẫn kéo về bảy ngày: một cái cột đứng một mình không phải xu hướng.
 * Con số lớn là của riêng hôm nay, còn dải cột phía dưới cho biết hôm nay đang
 * cao hay thấp so với mấy hôm vừa rồi — đó mới là câu người ta thật sự hỏi khi
 * mở app lúc chiều tối.
 */
private enum class Ky(val nhan: String, val soNgay: Long) {
    HOM_NAY("Hôm nay", 7),
    TUAN("7 ngày", 7),
    THANG("30 ngày", 30),
}

/** Tất cả số của một lần tải, gói lại để màn khỏi giữ sáu biến rời. */
private data class SoLieu(
    val ky: SoKy,
    val kyTruoc: SoKy,
    val moc: List<MocNgay>,
    val banChay: List<HangBanChay>,
    val kho: ThongKeKho?,
)

/**
 * Tab Tổng quan của khu QUẢN TRỊ.
 *
 * MÀN NÀY TRẢ LỜI BA CÂU, theo đúng thứ tự người ta hỏi khi mở app:
 *
 * 1. Hôm nay vào bao nhiêu, hơn hay kém mọi hôm? — thẻ tiền, có mức tăng giảm
 *    so với kỳ trước và dải cột theo ngày ngay bên trong nó.
 * 2. Việc gì đang sai? — CẦN XỬ LÝ, mỗi dòng bấm vào là mở đúng danh sách đã
 *    lọc sẵn. Đây là truy vấn thật (`stock=out`, `stock=low`, `cost=missing`),
 *    không phải nhãn trang trí; dòng nào bằng 0 thì biến mất.
 * 3. Cái gì đang chạy? — BÁN CHẠY, kèm tồn hiện tại của chính mặt hàng đó.
 *
 * DẢI CỘT NẰM TRONG THẺ TIỀN chứ không tách ra một thẻ trắng riêng. Con số và
 * cái dáng lên xuống của nó là MỘT vật: tách ra là mắt phải chạy hai chặng để
 * ghép lại điều đáng ra đọc một lần là xong.
 *
 * Mọi đường gọi thuộc nhóm `manage` bên API nên đòi cửa `quan_ly`.
 */
@Composable
fun ManHinhQuanTri(
    kho: KhoPhien,
    modifier: Modifier = Modifier,
    onXemHang: (LocHang) -> Unit,
) {
    var kyChon by remember { mutableStateOf(Ky.HOM_NAY) }
    var so by remember { mutableStateOf<SoLieu?>(null) }
    var dangTai by remember { mutableStateOf(true) }
    var loi by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(kyChon) {
        dangTai = true
        loi = null

        val tuNgay = ngayTruoc(kyChon.soNgay - 1)
        val bc = layDoanhThu(kho, tuNgay)
        // Bảng bán chạy đi theo đúng kỳ đang xem: xem hôm nay mà bảng liệt kê cả
        // tuần thì hai khối trên cùng một màn đang nói về hai khoảng khác nhau.
        val banChay = layHangBanChay(kho, if (kyChon == Ky.HOM_NAY) ngayTruoc(0) else tuNgay)
        val thongKe = layThongKeKho(kho)

        so = if (bc == null) {
            null
        } else {
            SoLieu(
                // Hôm nay lấy mốc cuối chuỗi và so với mốc liền trước; kỳ dài thì
                // lấy tổng kỳ và so với tổng kỳ trước mà máy chủ đã tính sẵn.
                ky = if (kyChon == Ky.HOM_NAY) bc.moc.lastOrNull()?.so ?: bc.ky else bc.ky,
                kyTruoc = if (kyChon == Ky.HOM_NAY) {
                    bc.moc.getOrNull(bc.moc.lastIndex - 1)?.so ?: SoKy(0.0, 0, 0, 0.0)
                } else {
                    bc.kyTruoc
                },
                moc = bc.moc,
                banChay = banChay.orEmpty(),
                kho = thongKe,
            )
        }
        loi = if (bc == null) "Không lấy được số liệu. Kiểm tra mạng rồi thử lại." else null
        dangTai = false
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .verticalScroll(rememberScrollState())
            // Đệm nằm SAU verticalScroll nên nó cuộn theo nội dung: khối đầu
            // tiên bắt đầu ngay dưới mũ app, rồi trôi lên luồn qua sau lớp kính.
            .padding(top = demTren, start = Cach.Chuan, end = Cach.Chuan),
        verticalArrangement = Arrangement.spacedBy(Cach.Khoi),
    ) {
        MuTrang("Tổng quan")

        DoanKy(dangChon = kyChon, onDoi = { kyChon = it })

        when {
            dangTai -> KhungXuong()

            loi != null -> The {
                Text(
                    text = loi.orEmpty(),
                    style = MaterialTheme.typography.bodyLarge,
                    color = MaterialTheme.colorScheme.onSurface,
                )
            }

            so != null -> {
                TheTien(ky = kyChon, so = so!!)
                TheChiSo(so = so!!)
                KhoiCanXuLy(so = so!!.kho, onXemHang = onXemHang)
                KhoiBanChay(ds = so!!.banChay)
                so!!.kho?.let { KhoiKho(so = it, onXemHang = onXemHang) }
            }
        }

        // Thanh điều hướng nổi đè lên đáy màn. Không chừa đúng chừng này thì
        // khối cuối cùng nằm khuất dưới nó mà người dùng không biết còn nữa.
        Spacer(Modifier.height(demDuoi - Cach.Khoi))
    }
}

/** Dải chọn kỳ. Đứng trên cùng vì nó đổi nghĩa của MỌI con số bên dưới. */
@Composable
private fun DoanKy(dangChon: Ky, onDoi: (Ky) -> Unit) {
    Row(horizontalArrangement = Arrangement.spacedBy(Cach.Gan)) {
        Ky.entries.forEach { k ->
            ChipChon(chu = k.nhan, chon = k == dangChon, onBam = { onDoi(k) })
        }
    }
}

/**
 * Thẻ tiền — khối duy nhất trong app mang nền chuyển sắc.
 *
 * CHUYỂN SẮC LÀ DÀNH CHO TIỀN. Giữ đúng một nghĩa cho một hình: thấy mảng xanh
 * là biết đang đọc tiền. Rải nó lên cả thẻ hồ sơ với thẻ cài đặt thì nó thành
 * đồ trang trí, và lần sau mắt không dừng lại ở đó nữa.
 */
@Composable
private fun TheTien(ky: Ky, so: SoLieu) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .background(Brush.linearGradient(listOf(XanhSang, XanhDam)), Bo.TheLon)
            .padding(Cach.Rong),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                text = "DOANH THU · " + ky.nhan.uppercase(),
                style = MaterialTheme.typography.labelSmall,
                color = Color.White.copy(alpha = 0.75f),
                modifier = Modifier.weight(1f),
            )
            // Không có kỳ trước để so thì không vẽ gì. In "0%" là nói dối rằng
            // đã đứng yên, mà thật ra là chưa từng có số nào.
            mucThayDoi(so.ky.doanhThu, so.kyTruoc.doanhThu)?.let {
                HuyThayDoi(muc = it, nenXanh = true)
            }
        }

        Spacer(Modifier.height(Cach.Gan))

        Text(
            text = tienVN(so.ky.doanhThu.toLong()),
            style = MaterialTheme.typography.displaySmall,
            color = Color.White,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )

        if (so.moc.size >= 2) {
            Spacer(Modifier.height(Cach.Rong))

            CotNgay(
                moc = so.moc,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(Bieu.Cao),
            )

            Spacer(Modifier.height(Cach.Gan))

            // Chỉ ghi hai đầu trục. Ghi đủ ba mươi ngày thì chữ chồng lên nhau,
            // mà cái cần biết chỉ là dải này chạy từ đâu tới đâu.
            Row(Modifier.fillMaxWidth()) {
                NhanTruc(so.moc.first().nhan)
                Spacer(Modifier.weight(1f))
                NhanTruc(so.moc.last().nhan)
            }
        }
    }
}

@Composable
private fun NhanTruc(nhan: String) {
    Text(
        text = ngayNgan(nhan),
        style = MaterialTheme.typography.bodySmall,
        color = Color.White.copy(alpha = 0.6f),
    )
}

/**
 * Dải cột doanh thu theo ngày, vẽ ngay trong thẻ tiền.
 *
 * Cột cuối TÔ TRẮNG ĐẶC, các cột trước mờ đi: đó là hôm nay, là chỗ người đọc
 * cần neo mắt vào. Tô đều một màu thì phải đếm từ đầu dải mới biết đâu là hôm
 * nay.
 *
 * Ngày không có đơn vẫn vẽ một mẩu cột thấp chứ không bỏ trống: bỏ trống thì
 * mắt tự nối hai cột hai bên lại với nhau và đọc thành một chuỗi liền, trong
 * khi thật ra hôm đó bán được 0 đồng.
 */
@Composable
private fun CotNgay(moc: List<MocNgay>, modifier: Modifier = Modifier) {
    val dinh = moc.maxOfOrNull { it.so.doanhThu } ?: 0.0

    Canvas(modifier = modifier) {
        if (moc.isEmpty()) return@Canvas

        val oRong = size.width / moc.size
        val cotRong = oRong * (1f - Bieu.TiKhe)
        val thap = Bieu.CotToiThieu.toPx()

        moc.forEachIndexed { i, m ->
            val ti = if (dinh > 0) (m.so.doanhThu / dinh).toFloat() else 0f
            val cao = (size.height * ti).coerceAtLeast(thap)

            drawRoundRect(
                color = if (i == moc.lastIndex) Color.White else Color.White.copy(alpha = 0.32f),
                topLeft = Offset(i * oRong + (oRong - cotRong) / 2, size.height - cao),
                size = Size(cotRong, cao),
                cornerRadius = CornerRadius(cotRong / 2, cotRong / 2),
            )
        }
    }
}

/**
 * Bốn chỉ số phụ trong MỘT thẻ, chia ô bằng vạch mảnh.
 *
 * Bốn thẻ trắng rời nhau thì màn có thêm bốn đường viền, bốn cái bóng, bốn
 * khoảng hở — mà bốn con số này là một bộ, đọc cùng nhau mới có nghĩa. Gom vào
 * một thẻ là bớt được ba lần chia cắt mà không mất gì.
 */
@Composable
private fun TheChiSo(so: SoLieu) {
    The(dem = false) {
        Row(Modifier.fillMaxWidth()) {
            OChiSo("Đơn", so.ky.soDon.toString(), so.ky.soDon.toDouble(), so.kyTruoc.soDon.toDouble(), Modifier.weight(1f))
            VachDoc()
            OChiSo("Món", so.ky.soMon.toString(), so.ky.soMon.toDouble(), so.kyTruoc.soMon.toDouble(), Modifier.weight(1f))
        }

        Box(
            Modifier
                .fillMaxWidth()
                .height(1.dp)
                .background(mauPhu.vienNhat),
        )

        Row(Modifier.fillMaxWidth()) {
            OChiSo("Lãi gộp", tienVN(so.ky.laiGop.toLong()), so.ky.laiGop, so.kyTruoc.laiGop, Modifier.weight(1f))
            VachDoc()
            OChiSo(
                "Trung bình / đơn",
                tienVN(so.ky.binhQuanDon.toLong()),
                so.ky.binhQuanDon,
                so.kyTruoc.binhQuanDon,
                Modifier.weight(1f),
            )
        }
    }
}

@Composable
private fun OChiSo(
    nhan: String,
    gia: String,
    hienTai: Double,
    truoc: Double,
    modifier: Modifier = Modifier,
) {
    Column(modifier = modifier.padding(Cach.Chuan)) {
        Text(
            text = nhan,
            style = MaterialTheme.typography.bodySmall,
            color = mauPhu.chuMo,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )

        Spacer(Modifier.height(Cach.Sat))

        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                text = gia,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.onSurface,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f, fill = false),
            )
            mucThayDoi(hienTai, truoc)?.let {
                Spacer(Modifier.width(Cach.Gan))
                HuyThayDoi(muc = it)
            }
        }
    }
}

/** Vạch dọc ngăn hai ô chỉ số. */
@Composable
private fun VachDoc() {
    Box(
        Modifier
            .width(1.dp)
            .height(Noi.Cao)
            .background(mauPhu.vienNhat),
    )
}

/** Một việc đang sai: nhãn, số lượng, và bộ lọc mở ra đúng nhóm hàng đó. */
private data class ViecCanLam(
    val nhan: String,
    val so: Long,
    val mau: Color,
    val loc: LocHang,
)

/**
 * CẦN XỬ LÝ.
 *
 * Xếp theo mức gấp: hết hàng trước (đang mất doanh thu), rồi sắp hết (sắp
 * mất), rồi thiếu giá vốn (số liệu sai chứ chưa mất tiền). KHÔNG đánh số
 * 01/02/03 vì đây không phải trình tự các bước — thứ tự ở đây là mức ưu tiên,
 * mà màu với vị trí đã nói đủ.
 */
@Composable
private fun KhoiCanXuLy(so: ThongKeKho?, onXemHang: (LocHang) -> Unit) {
    if (so == null) return

    val viec = listOfNotNull(
        ViecCanLam("Hết hàng", so.hetHang, mauPhu.do_, LocHang(ton = "out", nhan = "Hết hàng"))
            .takeIf { so.hetHang > 0 },
        ViecCanLam("Sắp hết", so.sapHet, mauPhu.cam, LocHang(ton = "low", nhan = "Sắp hết"))
            .takeIf { so.sapHet > 0 },
        ViecCanLam(
            "Chưa khai giá vốn",
            so.thieuGiaVon,
            mauPhu.lam,
            LocHang(giaVon = "missing", nhan = "Chưa khai giá vốn"),
        ).takeIf { so.thieuGiaVon > 0 },
    )

    Column {
        TieuDeKhoi("Cần xử lý")

        Spacer(Modifier.height(Cach.Vua))

        if (viec.isEmpty()) {
            KhongCoViec()
        } else {
            The(dem = false) {
                viec.forEachIndexed { i, v ->
                    DongViec(v = v, onBam = { onXemHang(v.loc) })
                    if (i < viec.lastIndex) VachDong()
                }
            }
        }
    }
}

@Composable
private fun DongViec(v: ViecCanLam, onBam: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onBam)
            .padding(Cach.Chuan),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        VachUuTien(v.mau)

        Spacer(Modifier.width(Cach.Vua))

        Text(
            text = v.nhan,
            style = MaterialTheme.typography.bodyLarge,
            color = MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.weight(1f),
        )

        Text(
            text = v.so.toString(),
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = v.mau,
        )

        Spacer(Modifier.width(Cach.Gan))

        Icon(
            imageVector = Lucide.ChevronRight,
            contentDescription = null,
            tint = mauPhu.chuMo,
            modifier = Modifier.size(Cach.Rong),
        )
    }
}

/** Kho sạch. Nói ra hẳn hoi, đừng để một thẻ trống rồi người đọc tự đoán. */
@Composable
private fun KhongCoViec() {
    The {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                modifier = Modifier
                    .size(Cach.Lon)
                    .background(mauPhu.lucNen, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    imageVector = Lucide.CircleCheck,
                    contentDescription = null,
                    tint = mauPhu.luc,
                    modifier = Modifier.size(Cach.Rong),
                )
            }
            Spacer(Modifier.width(Cach.Vua))
            Text(
                text = "Không có gì cần xử lý.",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurface,
            )
        }
    }
}

/**
 * BÁN CHẠY — xếp theo tiền hàng trong kỳ.
 *
 * Mỗi dòng kèm TỒN HIỆN TẠI của chính mặt hàng đó, và đó là lý do khối này
 * đáng chỗ trên màn chính. Một bảng xếp hạng trần chỉ khen quá khứ; ghép thêm
 * cột tồn là nó chỉ ra việc của hôm nay: món đang chạy nhất mà còn ba cái
 * trong kho thì phải nhập ngay chứ không phải tuần sau.
 */
@Composable
private fun KhoiBanChay(ds: List<HangBanChay>) {
    Column {
        TieuDeKhoi("Bán chạy")

        Spacer(Modifier.height(Cach.Vua))

        if (ds.isEmpty()) {
            The {
                Text(
                    text = "Kỳ này chưa bán được món nào.",
                    style = MaterialTheme.typography.bodyLarge,
                    color = mauPhu.chuThuong,
                )
            }
        } else {
            The(dem = false) {
                ds.forEachIndexed { i, h ->
                    DongBanChay(hang = h, thuTu = i + 1)
                    if (i < ds.lastIndex) VachDong()
                }
            }
        }
    }
}

@Composable
private fun DongBanChay(hang: HangBanChay, thuTu: Int) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(Cach.Chuan),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text = thuTu.toString(),
            style = MaterialTheme.typography.titleSmall,
            color = mauPhu.chuMo,
            modifier = Modifier.width(Cach.Rong),
        )

        Spacer(Modifier.width(Cach.Gan))

        Column(Modifier.weight(1f)) {
            Text(
                text = hang.ten,
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.Medium,
                color = MaterialTheme.colorScheme.onSurface,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )

            Spacer(Modifier.height(2.dp))

            Row {
                Text(
                    text = "${hang.soBan} món",
                    style = MaterialTheme.typography.bodySmall,
                    color = mauPhu.chuMo,
                )
                Text(
                    text = " · ",
                    style = MaterialTheme.typography.bodySmall,
                    color = mauPhu.chuMo,
                )
                Text(
                    text = nhanTon(hang.ton),
                    style = MaterialTheme.typography.bodySmall,
                    fontWeight = if (hang.ton <= NGUONG_SAP_HET) FontWeight.SemiBold else FontWeight.Normal,
                    color = mauTon(hang.ton),
                )
            }
        }

        Spacer(Modifier.width(Cach.Gan))

        Text(
            text = tienVN(hang.doanhThu.toLong()),
            style = MaterialTheme.typography.titleSmall,
            color = MaterialTheme.colorScheme.onSurface,
        )
    }
}

/** Ngưỡng "sắp hết", khớp mặc định của API. */
private const val NGUONG_SAP_HET = 5L

private fun nhanTon(ton: Long): String = if (ton <= 0) "đã hết hàng" else "còn $ton"

@Composable
private fun mauTon(ton: Long): Color = when {
    ton <= 0 -> mauPhu.do_
    ton <= NGUONG_SAP_HET -> mauPhu.cam
    else -> mauPhu.chuMo
}

/** Kho: hai con số nền, đứng cuối vì chúng không đòi hành động. */
@Composable
private fun KhoiKho(so: ThongKeKho, onXemHang: (LocHang) -> Unit) {
    Column {
        TieuDeKhoi("Kho")

        Spacer(Modifier.height(Cach.Vua))

        The(dem = false) {
            Row(Modifier.fillMaxWidth()) {
                OChiSoKho(
                    nhan = "Mặt hàng",
                    gia = so.tongBienThe.toString(),
                    bamDuoc = true,
                    modifier = Modifier
                        .weight(1f)
                        .clickable { onXemHang(LocHang()) },
                )
                VachDoc()
                OChiSoKho(
                    nhan = "Giá trị theo giá vốn",
                    gia = tienVN(so.giaTriKho.toLong()),
                    bamDuoc = false,
                    modifier = Modifier.weight(1f),
                )
            }
        }
    }
}

/**
 * Một ô số trong khối Kho.
 *
 * Ô bấm được có mũi tên, ô không bấm được thì không. Hai ô giống hệt nhau mà
 * chỉ một cái ăn ngón tay là người dùng phải thử mới biết.
 */
@Composable
private fun OChiSoKho(nhan: String, gia: String, bamDuoc: Boolean, modifier: Modifier = Modifier) {
    Column(modifier = modifier.padding(Cach.Chuan)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                text = nhan,
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuMo,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f, fill = false),
            )
            if (bamDuoc) {
                Icon(
                    imageVector = Lucide.ChevronRight,
                    contentDescription = null,
                    tint = mauPhu.chuMo,
                    modifier = Modifier.size(Cach.Chuan),
                )
            }
        }

        Spacer(Modifier.height(Cach.Sat))

        Text(
            text = gia,
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = MaterialTheme.colorScheme.onSurface,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

/** Vạch ngang giữa hai dòng trong thẻ, thụt vào thẳng hàng với chữ. */
@Composable
private fun VachDong() {
    Box(
        Modifier
            .padding(start = Cach.Lon)
            .fillMaxWidth()
            .height(1.dp)
            .background(mauPhu.vienNhat),
    )
}

/** Tiêu đề một khối. Đặt trên thẻ chứ không nhét vào trong. */
@Composable
private fun TieuDeKhoi(chu: String) {
    Text(
        text = chu,
        style = MaterialTheme.typography.titleMedium,
        color = MaterialTheme.colorScheme.onBackground,
    )
}

/** "2026-08-22" thành "22/8". Trục ngày không cần năm, cả dải cùng một năm. */
private fun ngayNgan(nhan: String): String = runCatching {
    val d = LocalDate.parse(nhan)
    "${d.dayOfMonth}/${d.monthValue}"
}.getOrDefault(nhan)

/**
 * Khung xương lúc đang tải — đúng hình dạng của màn thật.
 *
 * Vòng xoay giữa màn nói "đang bận"; khung xương nói "chỗ này sắp có một thẻ
 * tiền, rồi tới một thẻ bốn ô số, rồi một danh sách". Dữ liệu về là chữ điền
 * vào chỗ đã sẵn, màn không giật một cái rồi nhảy dựng lên.
 */
@Composable
private fun KhungXuong() {
    Column(verticalArrangement = Arrangement.spacedBy(Cach.Khoi)) {
        OXuong(
            modifier = Modifier
                .fillMaxWidth()
                .height(Noi.Cao * 3),
            bo = Bo.TheLon,
        )
        OXuong(
            modifier = Modifier
                .fillMaxWidth()
                .height(Noi.Cao * 2),
            bo = Bo.The,
        )
        OXuong(
            modifier = Modifier
                .fillMaxWidth()
                .height(Noi.Cao * 2),
            bo = Bo.The,
        )
    }
}
