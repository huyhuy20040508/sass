package com.selliotech.app

import androidx.compose.foundation.background
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.composables.icons.lucide.ArrowUpDown
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.Package
import com.composables.icons.lucide.PackageX
import com.composables.icons.lucide.SearchX
import com.composables.icons.lucide.TriangleAlert
import com.selliotech.app.ui.ChipGo
import com.selliotech.app.ui.Huy
import com.selliotech.app.ui.OTimLoc
import com.selliotech.app.ui.OXuong
import com.selliotech.app.ui.Sac
import com.selliotech.app.ui.TrangRong
import com.selliotech.app.ui.tienVN
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.Noi
import com.selliotech.app.ui.theme.mauPhu
import kotlinx.coroutines.FlowPreview
import kotlinx.coroutines.flow.debounce
import kotlinx.coroutines.flow.distinctUntilChanged

/** Số dòng mỗi lượt gọi. Đủ kín một màn dài mà không kéo về cả kho. */
private const val CO_TRANG = 30

/**
 * Còn cách đáy bấy nhiêu dòng thì gọi trang sau.
 *
 * Bốn dòng là vừa đủ để trang mới về trước khi ngón tay chạm đáy, mà cũng chưa
 * sớm tới mức lướt qua một hai dòng đã kéo thêm cả trang không ai đọc.
 */
private const val CACH_DAY = 4

/**
 * TỒN KHO.
 *
 * Trả lời đúng MỘT câu: món này còn mấy cái. Đây KHÔNG phải trang Hàng hoá —
 * bên web hai thứ là hai trang riêng, và giữ chúng riêng ở đây cũng vì lý do
 * ấy: người đi đếm kho cần tồn, ngưỡng sắp hết, giá vốn; người khai hàng cần
 * mã, nhóm, thuế, giá bán, trạng thái. Nhồi chung một màn thì ai cũng phải lội
 * qua nửa số cột mình không dùng.
 *
 * Đọc /admin/inventory, đơn vị là BIẾN THỂ chứ không phải mặt hàng — cùng một
 * mặt hàng ba dung lượng là ba dòng, vì kho đếm theo tổ hợp thuộc tính.
 *
 * Ô tìm và cụm lọc ĐỨNG YÊN dưới mũ trang, chỉ danh sách cuộn. Đang lọc kho mà
 * phải kéo ngược lên đầu mới gõ được từ khoá là mất một nhịp mỗi lần tìm.
 */
@OptIn(FlowPreview::class, ExperimentalMaterial3Api::class)
@Composable
fun ManHinhTonKho(
    kho: KhoPhien,
    modifier: Modifier = Modifier,
    loc: LocTon = LocTon(),
) {
    // Bộ lọc do MÀN NÀY giữ; `loc` truyền vào chỉ là hạt giống ban đầu.
    //
    // `remember(loc)` chứ không phải `remember`: bấm dòng "Hết hàng" bên Tổng
    // quan là `loc` đổi, và lúc đó bộ lọc trong màn phải nhảy theo. Nhớ suông
    // thì mở màn ra vẫn nguyên bộ lọc lần trước, chip nói một đằng danh sách
    // một nẻo.
    var boLoc by remember(loc) { mutableStateOf(loc) }

    var tuKhoa by remember { mutableStateOf("") }
    // Từ khoá của lượt tải gần nhất — dùng để biết lần phát này là do người
    // dùng gõ (phải chờ) hay do đổi bộ lọc (chạy ngay).
    var tuKhoaDaTai by remember { mutableStateOf("") }

    var dong by remember { mutableStateOf<List<DongTon>>(emptyList()) }
    var tong by remember { mutableStateOf(0L) }
    var conNua by remember { mutableStateOf(false) }
    var trang by remember { mutableStateOf(1) }
    var dangTai by remember { mutableStateOf(true) }
    var dangTaiThem by remember { mutableStateOf(false) }
    var dangLamMoi by remember { mutableStateOf(false) }
    var loi by remember { mutableStateOf<String?>(null) }
    var loiTaiThem by remember { mutableStateOf(false) }

    // null = CHƯA CÓ (chưa gọi, hoặc gọi hỏng); rỗng = gọi xuôi mà không có nhóm
    // nào. Gộp hai thứ này là tấm lọc báo "chưa khai nhóm" cho một cửa hàng có
    // đủ nhóm, chỉ vì một lượt gọi hết giờ.
    var dsNhom by remember { mutableStateOf<List<DanhMuc>?>(null) }
    /** Đã thử lấy một lượt chưa. Bấm "Thử lại" là hạ cờ này xuống rồi lấy lại. */
    var daThuNhom by remember { mutableStateOf(false) }
    var dangTaiNhom by remember { mutableStateOf(false) }

    var moLoc by remember { mutableStateOf(false) }
    var moXep by remember { mutableStateOf(false) }
    var dangXem by remember { mutableStateOf<DongTon?>(null) }

    val danhSach = rememberLazyListState()
    // Đếm số lượt tải LẠI TỪ ĐẦU. Chỉ để biết lúc nào cần kéo danh sách về đầu.
    var lanTai by remember { mutableIntStateOf(0) }

    // Một lượt tải trang đầu. Dùng chung cho lần mở màn, đổi bộ lọc, gõ từ khoá
    // và kéo xuống làm mới — bốn chỗ mà viết bốn lần thì sớm muộn lệch nhau.
    suspend fun taiDau(tu: String) {
        loi = null
        loiTaiThem = false
        val kq = layTonKho(kho = kho, loc = boLoc, tuKhoa = tu, trang = 1, coTrang = CO_TRANG)
        if (kq == null) {
            loi = "Không lấy được số tồn kho."
            dong = emptyList()
            tong = 0
            conNua = false
        } else {
            dong = kq.dong
            tong = kq.tong
            conNua = kq.conNua
            trang = 1
            lanTai += 1
        }
        tuKhoaDaTai = tu
    }

    LaunchedEffect(boLoc) {
        snapshotFlow { tuKhoa }
            // Gõ thì chờ một nhịp — mỗi chữ một lượt gọi thì từ khoá 8 ký tự
            // thành 8 lượt, và lượt về sau có thể tới trước lượt trước. Còn lần
            // phát do đổi bộ lọc mang đúng từ khoá cũ, cho chạy ngay: cú bấm chip
            // phải thấy kết quả liền.
            .debounce { if (it == tuKhoaDaTai) 0L else 350L }
            .distinctUntilChanged()
            .collect { tu ->
                dangTai = true
                taiDau(tu)
                dangTai = false
            }
    }

    // Cuộn gần tới đáy là gọi trang sau. Không đặt trong khối dựng của item
    // cuối: khối đó chạy lại mỗi lần item cuộn qua màn.
    LaunchedEffect(danhSach, dong.size, conNua, loiTaiThem) {
        snapshotFlow { danhSach.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: -1 }
            .distinctUntilChanged()
            .collect { cuoi ->
                if (!conNua || dangTaiThem || dangTai || loiTaiThem) return@collect
                if (cuoi < dong.size - CACH_DAY) return@collect

                dangTaiThem = true
                val kq = layTonKho(
                    kho = kho,
                    loc = boLoc,
                    tuKhoa = tuKhoaDaTai,
                    trang = trang + 1,
                    coTrang = CO_TRANG,
                )
                if (kq == null) {
                    // Không xoá danh sách đang có: hỏng ở trang 3 thì hai trang
                    // đầu vẫn đọc được, chỉ chân danh sách mọc thêm nút thử lại.
                    loiTaiThem = true
                } else {
                    dong = dong + kq.dong
                    tong = kq.tong
                    conNua = kq.conNua
                    trang += 1
                }
                dangTaiThem = false
            }
    }

    // Danh sách nhóm lấy MỘT LẦN, ngay lúc mở tấm lọc chứ không phải lúc mở
    // màn: phần lớn lượt vào màn này là để lướt kho, không ai đụng tới nhóm.
    LaunchedEffect(moLoc, daThuNhom) {
        if (!moLoc || daThuNhom || dangTaiNhom) return@LaunchedEffect
        dangTaiNhom = true
        dsNhom = layDanhMuc(kho)
        dangTaiNhom = false
        daThuNhom = true
    }

    // Tập dòng đã khác hẳn thì phải kéo về đầu, không thì lọc xong người dùng
    // vẫn đang đứng ở dòng thứ 60 của danh sách cũ.
    //
    // Việc này phải làm SAU khi danh sách hiện ra, ở một vòng riêng — KHÔNG gọi
    // trong lượt tải. `scrollToItem` chờ LazyColumn được đo lần đầu, mà lúc còn
    // khung xương thì LazyColumn chưa có trên màn: gọi ở đó là nó chờ danh sách,
    // danh sách chờ `dangTai` tắt, `dangTai` chờ nó xong — khoá chặt cả ba, dữ
    // liệu về rồi mà màn hình đứng nguyên khung xương.
    LaunchedEffect(lanTai) {
        if (lanTai > 0 && dong.isNotEmpty() && danhSach.firstVisibleItemIndex > 0) {
            danhSach.scrollToItem(0)
        }
    }

    // QUAY LẠI APP MÀ MÀN ĐANG BÁO LỖI THÌ TỰ TẢI LẠI.
    //
    // Lượt gọi mạng đang dở lúc app xuống nền sẽ nằm chờ tới khi hết giờ 15
    // giây rồi báo hỏng — mà app xuống nền là chuyện xảy ra suốt: mở bộ chọn
    // ảnh, mở bộ chọn tệp, mở bảng chia sẻ, hay chỉ là liếc sang Zalo một lát.
    // Quay lại thì màn đứng nguyên một cái dấu cảnh báo trong khi chẳng có gì
    // hỏng thật, và lối ra duy nhất là tự kéo xuống làm mới — người dùng không
    // có cách nào đoán ra điều đó.
    //
    // CHỈ tải lại khi đang lỗi, không phải mọi lần quay lại: liếc sang app khác
    // rồi quay về mà danh sách nhảy dựng lên tải lại từ đầu là mất chỗ đang
    // đứng, và tốn một lượt gọi cho một màn vẫn đang đúng.
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) {
        if (loi != null && !dangTai && !dangLamMoi) dangLamMoi = true
    }

    LaunchedEffect(dangLamMoi) {
        if (!dangLamMoi) return@LaunchedEffect
        taiDau(tuKhoa)
        dangLamMoi = false
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .padding(top = demTren),
    ) {
        MuTrang(
            tieuDe = "Tồn kho",
            modifier = Modifier.padding(horizontal = Cach.Chuan, vertical = Cach.Gan),
        )

        OTimLoc(
            tuKhoa = tuKhoa,
            onDoi = { tuKhoa = it },
            goiY = "Tìm theo tên hoặc mã hàng",
            soLoc = boLoc.soDieuKien,
            onLoc = { moLoc = true },
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = Cach.Chuan, vertical = Cach.Gan),
        )

        // Chip nói rõ đang xem một LÁT CẮT của kho. Thiếu nó thì màn "hết hàng"
        // trông y hệt màn "kho rỗng", và người dùng đi kết luận sai về kho mình.
        val chips = boLoc.chips()
        if (chips.isNotEmpty()) {
            LazyRow(
                horizontalArrangement = Arrangement.spacedBy(Cach.Gan),
                contentPadding = PaddingValues(horizontal = Cach.Chuan),
                modifier = Modifier.padding(bottom = Cach.Gan),
            ) {
                items(chips) { c ->
                    ChipGo(nhan = c.nhan, onGo = { boLoc = c.con })
                }
                if (chips.size > 1) {
                    item {
                        // Giữ nguyên kiểu sắp xếp: bấm "xoá hết" là để xem lại
                        // cả kho, không phải để đảo thứ tự đang xem.
                        ChipGo(nhan = "Xoá hết", onGo = { boLoc = LocTon(xep = boLoc.xep) })
                    }
                }
            }
        }

        // Danh sách đứng trên một TẤM TRẮNG bo hai góc trên, không nằm trần trên
        // nền xám. Nền xám là chỗ để đặt vật lên; danh sách nằm thẳng lên đó thì
        // nó với cụm lọc ở trên thành hai thứ khác lớp.
        Surface(
            color = MaterialTheme.colorScheme.surface,
            shape = Bo.Tam,
            modifier = Modifier.fillMaxSize(),
        ) {
            Column(Modifier.fillMaxSize()) {
                // Thanh đếm đứng yên trên đỉnh tấm trắng, giống hệt trang Hàng
                // hoá: hai màn danh sách phải cùng một khung, không thì đổi màn
                // là mắt phải đi tìm lại chỗ đặt của từng thứ.
                ThanhDemTon(
                    chu = if (dangTai) "Đang đếm..." else "$tong mặt hàng",
                    xep = boLoc.xep.nhan,
                    onXep = { moXep = true },
                )

                PullToRefreshBox(
                    isRefreshing = dangLamMoi,
                    onRefresh = { dangLamMoi = true },
                    state = rememberPullToRefreshState(),
                    modifier = Modifier.fillMaxSize(),
                ) {
                    when {
                        // Lần tải đầu mới dựng khung xương. Kéo xuống làm mới thì
                        // giữ nguyên danh sách cũ — vòng xoay của chính cú kéo đã
                        // nói là đang chạy, thay hết bằng ô xám là màn chớp một cái.
                        dangTai && !dangLamMoi -> KhungXuongDanhSach()

                        loi != null -> MotManHinh {
                            TrangRong(
                                bieuTuong = Lucide.TriangleAlert,
                                tieuDe = loi.orEmpty(),
                                phu = "Kiểm tra mạng rồi thử lại.",
                                nhanNut = "Thử lại",
                                onBam = { dangLamMoi = true },
                            )
                        }

                        dong.isEmpty() -> MotManHinh { TrangTrong(tuKhoa, boLoc) { boLoc = LocTon(xep = boLoc.xep) } }

                        else -> LazyColumn(
                            state = danhSach,
                            modifier = Modifier.fillMaxSize(),
                            // Danh sách chạy tiếp XUỐNG DƯỚI thanh nổi rồi mới hết —
                            // đó là điểm được của thanh nổi. Nhưng dòng cuối phải
                            // trượt lên qua khỏi nó, không thì mãi mãi có một dòng
                            // không đọc được.
                            contentPadding = PaddingValues(bottom = demDuoi),
                        ) {
                            items(dong, key = { it.bienTheId }) { d ->
                                DongTonKho(d, onBam = { dangXem = d })
                                VachDong()
                            }

                            if (dangTaiThem) {
                                item { DongXuong() }
                            }

                            if (loiTaiThem) {
                                item {
                                    Box(Modifier.fillMaxWidth().padding(Cach.Chuan)) {
                                        TrangRong(
                                            bieuTuong = Lucide.TriangleAlert,
                                            tieuDe = "Không tải được thêm.",
                                            nhanNut = "Thử lại",
                                            // Gỡ cờ lỗi là vòng theo dõi cuộn ở trên
                                            // tự gọi lại trang dở dang.
                                            onBam = { loiTaiThem = false },
                                        )
                                    }
                                }
                            }

                            // Đã tới cuối kho thì nói ra. Danh sách cụt ngang không
                            // có gì đánh dấu thì người ta còn kéo thêm mấy nhịp nữa
                            // để chờ phần chưa về.
                            if (!conNua && !dangTaiThem && dong.size > CO_TRANG) {
                                item { HetDanhSach(tong) }
                            }
                        }
                    }
                }
            }
        }
    }

    if (moLoc) {
        TamLocTon(
            loc = boLoc,
            tong = tong,
            dangDem = dangTai,
            dsNhom = dsNhom,
            dangTaiNhom = dangTaiNhom,
            onThuLaiNhom = { daThuNhom = false },
            onDoi = { boLoc = it },
            onDong = { moLoc = false },
        )
    }

    if (moXep) {
        TamXepTon(
            dangChon = boLoc.xep,
            onChon = {
                boLoc = boLoc.copy(xep = it)
                moXep = false
            },
            onDong = { moXep = false },
        )
    }

    dangXem?.let { TamChiTietTon(d = it, onDong = { dangXem = null }) }
}

/** Bọc một trạng thái rỗng cho nó nằm giữa phần màn còn lại. */
@Composable
private fun MotManHinh(noiDung: @Composable () -> Unit) {
    // Cuộn được dù chỉ có một khối: PullToRefreshBox cần một vùng nhận cú kéo,
    // không thì màn lỗi là màn duy nhất không kéo lại được — đúng lúc cần nhất.
    LazyColumn(modifier = Modifier.fillMaxSize()) {
        item {
            Box(
                modifier = Modifier.fillParentMaxSize().padding(bottom = demDuoi),
                contentAlignment = Alignment.Center,
                content = { noiDung() },
            )
        }
    }
}

/**
 * Màn trống nói ĐÚNG lý do trống, và mở đúng lối ra tương ứng.
 *
 * Ba tình huống nhìn giống hệt nhau trên màn nhưng phải làm ba việc khác nhau:
 * kho rỗng thì chờ người khai hàng, gõ hụt từ khoá thì sửa từ khoá, lọc quá tay
 * thì bỏ bớt điều kiện. Một câu "Không có dữ liệu" chung cho cả ba là bỏ mặc
 * người dùng đoán.
 */
@Composable
private fun TrangTrong(tuKhoa: String, loc: LocTon, onXoaLoc: () -> Unit) {
    when {
        tuKhoa.isNotBlank() -> TrangRong(
            bieuTuong = Lucide.SearchX,
            tieuDe = "Không có mặt hàng nào khớp \"$tuKhoa\".",
            phu = "Thử gõ ngắn hơn, hoặc quét mã vạch của món hàng.",
        )

        loc.coLoc -> TrangRong(
            bieuTuong = Lucide.PackageX,
            tieuDe = "Không mặt hàng nào khớp bộ lọc.",
            phu = "Bỏ bớt điều kiện để nhìn rộng ra.",
            nhanNut = "Xoá lọc",
            onBam = onXoaLoc,
        )

        else -> TrangRong(
            bieuTuong = Lucide.Package,
            tieuDe = "Kho chưa có mặt hàng nào.",
            phu = "Khai mặt hàng bên Shop Admin, ở đây sẽ thấy ngay.",
        )
    }
}

/**
 * Thanh đếm — dòng tiêu đề của tấm trắng. Cùng một khuôn với trang Hàng hoá.
 */
@Composable
private fun ThanhDemTon(chu: String, xep: String, onXep: () -> Unit) {
    Column {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier
                .fillMaxWidth()
                .padding(start = Cach.Chuan, end = Cach.Vua, top = Cach.Vua, bottom = Cach.Gan),
        ) {
            Text(
                text = chu,
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = mauPhu.chuMo,
                modifier = Modifier.weight(1f),
            )
            NutXep(nhan = xep, onBam = onXep)
        }
        HorizontalDivider(thickness = 1.dp, color = mauPhu.vienNhat)
    }
}

/** Nút mở tấm sắp xếp. Mang sẵn tên kiểu đang dùng, không phải bấm vào mới biết. */
@Composable
private fun NutXep(nhan: String, onBam: () -> Unit) {
    Surface(
        color = MaterialTheme.colorScheme.surface,
        shape = Bo.Tron,
        border = BorderStroke(1.dp, mauPhu.vienNhat),
        modifier = Modifier.clip(Bo.Tron).clickable(onClick = onBam),
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.padding(horizontal = Cach.Vua, vertical = Cach.Gan),
        ) {
            Icon(
                imageVector = Lucide.ArrowUpDown,
                contentDescription = null,
                tint = mauPhu.chuMo,
                modifier = Modifier.size(14.dp),
            )
            Spacer(Modifier.width(Cach.Sat))
            Text(
                text = nhan,
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.Medium,
                color = mauPhu.chuThuong,
            )
        }
    }
}

/**
 * Một dòng hàng.
 *
 * Vạch dẫn đầu MANG MÀU theo tình trạng tồn — đỏ là hết, cam là sắp hết. Mọi
 * vạch thẳng một cột nên lướt dọc là thấy ngay chỗ có vấn đề, không phải đọc
 * từng con số bên phải.
 *
 * Trước đây chỗ này là một ô tròn 42dp đựng icon thùng hàng. Icon đó giống hệt
 * nhau ở mọi dòng nên không nói thêm được gì, mà ăn mất một phần tám bề ngang
 * của tên hàng — thứ duy nhất người ta thật sự đọc khi lướt kho.
 */
@Composable
private fun DongTonKho(d: DongTon, onBam: () -> Unit) {
    val mau = mauTon(d.mucTon())

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onBam)
            .padding(horizontal = Cach.Chuan, vertical = Cach.Vua),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .width(Noi.DayVach)
                .height(Noi.CaoVach)
                .background(mau, Bo.Tron),
        )

        Spacer(Modifier.width(Cach.Vua))

        Column(Modifier.weight(1f)) {
            Text(
                text = d.ten + if (d.tenBienThe.isNotBlank()) " · ${d.tenBienThe}" else "",
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.Medium,
                color = MaterialTheme.colorScheme.onBackground,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )

            Spacer(Modifier.height(2.dp))

            Text(
                text = listOf(d.sku, d.danhMuc).filter { it.isNotBlank() }.joinToString(" · "),
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuMo,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )

            if (!d.dangBan) {
                Spacer(Modifier.height(Cach.Sat))
                Huy(chu = "Ngừng bán", sac = Sac.XAM)
            }
        }

        Spacer(Modifier.width(Cach.Gan))

        Column(horizontalAlignment = Alignment.End) {
            Text(
                text = nhanTon(d),
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.Bold,
                color = mau,
            )
            Spacer(Modifier.height(2.dp))
            Text(
                text = tienVN(d.gia.toLong()),
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuThuong,
            )
        }
    }
}

/** "12 Hộp" chứ không phải một con số trần — đơn vị tính API đã trả sẵn. */
internal fun nhanTon(d: DongTon): String =
    if (d.donVi.isBlank()) "Tồn ${d.ton}" else "${d.ton} ${d.donVi}"

/** Hết hàng đỏ, sắp hết cam, còn lại xanh. Cùng ngưỡng với bộ lọc và ô thống kê. */
@Composable
internal fun mauTon(muc: MucTon): Color = when (muc) {
    MucTon.HET -> mauPhu.do_
    MucTon.SAP_HET -> mauPhu.cam
    MucTon.CON -> mauPhu.luc
}

/**
 * Khung xương danh sách: sáu dòng giả đúng nhịp của dòng thật.
 *
 * Sáu là đủ kín một màn. Nhiều hơn thì lúc dữ liệu về sẽ thấy phần dưới biến
 * mất — đúng cái giật mà khung xương sinh ra để tránh.
 */
@Composable
private fun KhungXuongDanhSach() {
    Column(Modifier.fillMaxSize()) {
        repeat(6) {
            DongXuong()
            VachDong()
        }
    }
}

/** Một dòng xương, dùng cả cho lần tải đầu lẫn chân danh sách khi tải thêm. */
@Composable
private fun DongXuong() {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = Cach.Chuan, vertical = Cach.Chuan),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        OXuong(
            modifier = Modifier
                .width(Noi.DayVach)
                .height(Noi.CaoVach),
            bo = Bo.Tron,
        )
        Spacer(Modifier.width(Cach.Vua))
        Column(Modifier.weight(1f)) {
            OXuong(modifier = Modifier.fillMaxWidth(0.7f).height(Cach.Chuan))
            Spacer(Modifier.height(Cach.Gan))
            OXuong(modifier = Modifier.fillMaxWidth(0.4f).height(Cach.Vua))
        }
    }
}

/** Dấu hết danh sách. Nhạt thôi — nó là một câu kết, không phải một mục. */
@Composable
private fun HetDanhSach(tong: Long) {
    Text(
        text = "Hết $tong mặt hàng",
        style = MaterialTheme.typography.bodySmall,
        color = mauPhu.chuMo,
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = Cach.Khoi),
        textAlign = TextAlign.Center,
    )
}

/**
 * Vạch giữa hai dòng hàng: nhạt hơn vạch trong thẻ, và THỤT VÀO thẳng hàng với
 * tên hàng.
 *
 * Vạch chạy suốt từ mép này sang mép kia cắt danh sách thành từng ô rời; vạch
 * thụt vào giữ cả cột tên liền một mạch, mắt lướt dọc không bị chặn ở mỗi dòng.
 */
@Composable
private fun VachDong() {
    HorizontalDivider(
        thickness = 1.dp,
        color = mauPhu.vienNhat,
        modifier = Modifier.padding(start = Cach.Lon),
    )
}
