package com.selliotech.app

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.combinedClickable
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
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.composables.icons.lucide.ArrowUpDown
import com.composables.icons.lucide.Check
import com.composables.icons.lucide.Ellipsis
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.Package
import com.composables.icons.lucide.PackageX
import com.composables.icons.lucide.Plus
import com.composables.icons.lucide.SearchX
import com.composables.icons.lucide.TriangleAlert
import com.selliotech.app.ui.AnhVuong
import com.selliotech.app.ui.BaoNhanh
import com.selliotech.app.ui.ChipGo
import com.selliotech.app.ui.HoiXacNhan
import com.selliotech.app.ui.HopDangChay
import com.selliotech.app.ui.ghiVaChiaTep
import com.selliotech.app.ui.tenTepXuat
import com.selliotech.app.ui.Huy
import com.selliotech.app.ui.OTimLoc
import com.selliotech.app.ui.OXuong
import com.selliotech.app.ui.Sac
import com.selliotech.app.ui.TrangRong
import com.selliotech.app.ui.tienVN
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.mauPhu
import kotlinx.coroutines.FlowPreview
import kotlinx.coroutines.launch
import kotlinx.coroutines.flow.debounce
import kotlinx.coroutines.flow.distinctUntilChanged

/** Số dòng mỗi lượt gọi. Bằng mặc định của bản web cho khỏi lệch cách đếm. */
private const val CO_TRANG_HANG = 20

/** Còn cách đáy bấy nhiêu dòng thì gọi trang sau. */
private const val CACH_DAY_HANG = 4

/**
 * DANH SÁCH HÀNG HOÁ — bản mobile của trang `/products` bên web.
 *
 * KHÔNG có tồn kho ở đây, đúng như bên web: tồn là trang riêng (xem
 * `ManHinhTonKho`). Trang này trả lời "cửa hàng đang bán món gì, giá bao nhiêu,
 * món nào đang ẩn"; trang kia trả lời "món ấy còn mấy cái".
 *
 * Bản web là một thanh công cụ dài cộng bảng mười một cột. Bê nguyên sang điện
 * thoại là hỏng: bảng phải cuộn hai chiều, thanh công cụ ăn hết nửa màn trên.
 * Nên bố cục đổi, còn CHỨC NĂNG thì giữ đủ:
 *
 * | Web | Ở đây |
 * |---|---|
 * | Ô tìm tên / mã hàng / mã vạch | Y hệt, lọc REALTIME, không nút bấm |
 * | Chọn nhiều nhóm hàng hoá | Y hệt — API nhận `category_ids` ngăn phẩy |
 * | Ô tick nhiều trạng thái | Y hệt — `statuses` ngăn phẩy |
 * | Nâng cao: vị trí, ĐVT, biến thể | Y hệt, nằm trong tấm lọc |
 * | Tám kiểu sắp xếp | Y hệt, trong tấm sắp xếp |
 * | Công tắc trạng thái trên bảng | Đổi trong tấm chi tiết, có đủ ba mức |
 * | Phân trang + chọn số dòng | Cuộn tới đâu tải tới đó |
 * | Tạo mới, sửa, xoá, nhập/xuất file | Không có — việc của bản web |
 *
 * Vì sao công tắc trạng thái lui vào tấm chi tiết chứ không nằm ngay trên dòng:
 * bảng web có cả một cột rộng cho nó, còn dòng trên điện thoại thì không, mà
 * một cái công tắc nhỏ nằm sát mép trong danh sách đang cuộn là chỗ để gạt nhầm.
 * Đổi vào tấm chi tiết còn được thêm chỗ ghi rõ ba mức khác nhau chỗ nào.
 */
@OptIn(FlowPreview::class, ExperimentalMaterial3Api::class)
@Composable
fun ManHinhSanPham(kho: KhoPhien, modifier: Modifier = Modifier) {
    var boLoc by remember { mutableStateOf(LocMatHang()) }

    var tuKhoa by remember { mutableStateOf("") }
    var tuKhoaDaTai by remember { mutableStateOf("") }

    var dong by remember { mutableStateOf<List<MatHang>>(emptyList()) }
    var tong by remember { mutableStateOf(0L) }
    var conNua by remember { mutableStateOf(false) }
    var trang by remember { mutableStateOf(1) }
    var dangTai by remember { mutableStateOf(true) }
    var dangTaiThem by remember { mutableStateOf(false) }
    var dangLamMoi by remember { mutableStateOf(false) }
    var loi by remember { mutableStateOf<String?>(null) }
    var loiTaiThem by remember { mutableStateOf(false) }

    // Ba danh sách của tấm lọc. `null` = CHƯA CÓ — hoặc chưa gọi, hoặc gọi hỏng;
    // danh sách rỗng = gọi xuôi mà thật sự không có gì.
    //
    // Đây là chỗ đã sai một lần: gọi hỏng mà `orEmpty()` thành danh sách rỗng thì
    // tấm lọc ghi "Chưa khai nhóm hàng nào" cho một cửa hàng có đủ nhóm — người
    // dùng đọc xong đi tạo lại nhóm đã có sẵn.
    var dsNhom by remember { mutableStateOf<List<DanhMuc>?>(null) }
    var dsDonVi by remember { mutableStateOf<List<OChon>?>(null) }
    var dsViTri by remember { mutableStateOf<List<OChon>?>(null) }
    // Hai danh sách chỉ biểu mẫu khai hàng cần, tấm lọc không dùng tới.
    var dsChiNhanh by remember { mutableStateOf<List<OChon>?>(null) }
    var dsThe by remember { mutableStateOf<List<OChon>?>(null) }
    var dsThuocTinh by remember { mutableStateOf<List<ThuocTinhHang>?>(null) }
    var dangTaiChon by remember { mutableStateOf(false) }
    /** Đã thử lấy một lượt chưa. Bấm "Thử lại" là hạ cờ này xuống rồi lấy lại. */
    var daThuChon by remember { mutableStateOf(false) }

    var moLoc by remember { mutableStateOf(false) }
    var moThem by remember { mutableStateOf(false) }
    var moNangCao by remember { mutableStateOf(false) }
    var dangXuatTep by remember { mutableStateOf(false) }
    var ketQuaNhap by remember { mutableStateOf<KetQuaNhap?>(null) }
    // Chữ trong hộp chờ lúc nhập tệp. null = không nhập gì cả.
    var dangNhapTep by remember { mutableStateOf<String?>(null) }
    // Chế độ chọn nhiều: rỗng = đang xem bình thường. Nhấn giữ một dòng để vào.
    var daChon by remember { mutableStateOf(setOf<Long>()) }
    var hoiXoaNhieu by remember { mutableStateOf(false) }
    // Mặt hàng đang sửa: id, dữ liệu đã đọc về, và cờ đang đọc. Tấm sửa mở ra
    // NGAY khi bấm, đọc dữ liệu song song — chờ đọc xong mới mở thì bấm một cái
    // rồi ngồi nhìn màn hình không nhúc nhích, tưởng app treo.
    // Mặt hàng đang chờ xác nhận xoá. null = không hỏi ai cả.
    var hoiXoa by remember { mutableStateOf<MatHang?>(null) }
    var dangLamThaoTac by remember { mutableStateOf(false) }

    var suaId by remember { mutableStateOf(0L) }
    var suaBanDau by remember { mutableStateOf<HangMoi?>(null) }
    var dangDocSua by remember { mutableStateOf(false) }
    var moXep by remember { mutableStateOf(false) }
    var dangXem by remember { mutableStateOf<MatHang?>(null) }
    // Câu báo sau một lượt đổi trạng thái — xuôi hay hỏng đều đi đường này.
    var bao by remember { mutableStateOf<String?>(null) }

    // Đổi thứ tự chỉ có nghĩa khi danh sách đang ở ĐÚNG cái thứ tự ấy: đang lọc,
    // đang tìm, hay đang sắp theo cột thì "lên một bậc" không còn nghĩa gì.
    val doiThuTuDuoc = boLoc.xep == XepHangHoa.TU_XEP && !boLoc.coLoc && tuKhoa.isBlank()

    val pham = rememberCoroutineScope()
    val boi = LocalContext.current
    val danhSach = rememberLazyListState()

    // Bộ chọn tệp của hệ điều hành: không xin quyền đọc bộ nhớ, người dùng chỉ
    // đường tới đúng một tệp thì app đọc được đúng tệp ấy.
    val moTep = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.OpenDocument(),
    ) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        pham.launch {
            dangNhapTep = "Đang đọc tệp..."
            val kq = nhapTepHangHoa(kho, boi, uri) { chu -> dangNhapTep = chu }
            dangNhapTep = null
            ketQuaNhap = kq
            // Tải lại DÙ CÓ THÊM ĐƯỢC DÒNG NÀO HAY KHÔNG. Mở bộ chọn tệp là app
            // xuống nền, mà lượt tải danh sách đang dở lúc ấy sẽ nằm chờ tới lúc
            // hết giờ — quay lại là thấy màn báo lỗi trong khi chẳng có gì hỏng.
            dangLamMoi = true
        }
    }
    var lanTai by remember { mutableIntStateOf(0) }

    suspend fun taiDau(tu: String) {
        loi = null
        loiTaiThem = false
        val kq = layMatHang(kho = kho, loc = boLoc, tuKhoa = tu, trang = 1, coTrang = CO_TRANG_HANG)
        if (kq == null) {
            loi = "Không lấy được danh sách hàng hoá."
            dong = emptyList()
            tong = 0
            conNua = false
        } else {
            dong = kq.dong
            tong = kq.tong
            conNua = kq.conNua
            trang = 1
            lanTai += 1
            // Danh sách đã là tập khác: bỏ hết ô đã tick. Giữ lại là người dùng
            // lọc sang nhóm khác rồi bấm Xoá, và xoá mất mấy dòng họ tick từ
            // nhóm cũ mà giờ chẳng còn nhìn thấy trên màn.
            daChon = emptySet()
        }
        tuKhoaDaTai = tu
    }

    LaunchedEffect(boLoc) {
        snapshotFlow { tuKhoa }
            // Gõ thì chờ một nhịp; còn lần phát do đổi bộ lọc mang đúng từ khoá
            // cũ nên chạy ngay — cú bấm chip phải thấy kết quả liền.
            .debounce { if (it == tuKhoaDaTai) 0L else 350L }
            .distinctUntilChanged()
            .collect { tu ->
                dangTai = true
                taiDau(tu)
                dangTai = false
            }
    }

    // Kéo danh sách về đầu khi tập dòng đã khác hẳn. Làm ở vòng RIÊNG, sau khi
    // danh sách hiện ra: `scrollToItem` chờ LazyColumn được đo lần đầu, gọi nó
    // trong lượt tải là khoá chặt — dữ liệu về rồi mà màn vẫn nguyên khung xương.
    LaunchedEffect(lanTai) {
        if (lanTai > 0 && dong.isNotEmpty() && danhSach.firstVisibleItemIndex > 0) {
            danhSach.scrollToItem(0)
        }
    }

    LaunchedEffect(danhSach, dong.size, conNua, loiTaiThem) {
        snapshotFlow { danhSach.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: -1 }
            .distinctUntilChanged()
            .collect { cuoi ->
                if (!conNua || dangTaiThem || dangTai || loiTaiThem) return@collect
                if (cuoi < dong.size - CACH_DAY_HANG) return@collect

                dangTaiThem = true
                val kq = layMatHang(
                    kho = kho,
                    loc = boLoc,
                    tuKhoa = tuKhoaDaTai,
                    trang = trang + 1,
                    coTrang = CO_TRANG_HANG,
                )
                if (kq == null) {
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

    // Ba danh sách của tấm lọc lấy MỘT LẦN, lúc mở tấm chứ không phải lúc mở
    // màn: phần lớn lượt vào đây là để tra một mặt hàng, không ai đụng bộ lọc.
    LaunchedEffect(moLoc, moThem, suaId, daThuChon) {
        val canChon = moLoc || moThem || suaId > 0
        if (!canChon || daThuChon || dangTaiChon) return@LaunchedEffect
        dangTaiChon = true
        dsNhom = layDanhMuc(kho)
        // Đơn vị tính và vị trí đòi quyền RIÊNG. Tài khoản không có quyền nhận
        // 403, và lúc ấy tấm lọc giấu hẳn mục đó đi thay vì bày một ô rỗng.
        dsDonVi = layDonViTinh(kho)
        dsViTri = layViTri(kho)
        dsChiNhanh = layChiNhanh(kho)
        dsThe = layTheHang(kho)
        dsThuocTinh = layThuocTinh(kho)
        dangTaiChon = false
        daThuChon = true
    }

    LaunchedEffect(suaId) {
        if (suaId <= 0) return@LaunchedEffect
        dangDocSua = true
        val doc = layChiTietHang(kho, suaId)
        dangDocSua = false
        if (doc == null) {
            // Đọc hỏng thì ĐÓNG tấm lại: để tấm mở với biểu mẫu trắng là người
            // dùng gõ vào rồi bấm Lưu, và cái Lưu ấy ghi đè sạch mặt hàng bằng
            // đúng những ô họ vừa gõ.
            suaId = 0
            bao = "Không đọc được mặt hàng. Kiểm tra mạng rồi thử lại."
        } else {
            suaBanDau = doc
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
            tieuDe = "Hàng hoá",
            modifier = Modifier.padding(horizontal = Cach.Chuan, vertical = Cach.Gan),
        )

        // Nút Thêm đứng CUỐI thanh lọc, đúng quy tắc trang danh sách của hệ
        // thống. Tô đặc màu chính chứ không viền trắng như khối tìm bên cạnh:
        // hai thứ này khác vai — một cái thu hẹp danh sách, một cái sinh ra dòng
        // mới — nên phải nhìn ra ngay là khác, chứ không phải đọc icon mới biết.
        Row(
            horizontalArrangement = Arrangement.spacedBy(Cach.Gan),
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.padding(horizontal = Cach.Chuan, vertical = Cach.Gan),
        ) {
            OTimLoc(
                tuKhoa = tuKhoa,
                onDoi = { tuKhoa = it },
                goiY = "Tìm tên, mã hàng hoặc mã vạch",
                soLoc = boLoc.soDieuKien,
                onLoc = { moLoc = true },
                modifier = Modifier.weight(1f),
            )

            Surface(
                color = MaterialTheme.colorScheme.primary,
                shape = Bo.O,
                modifier = Modifier
                    .size(CaoCham.ToiThieu)
                    .clip(Bo.O)
                    .clickable { moThem = true },
            ) {
                Box(contentAlignment = Alignment.Center) {
                    Icon(
                        imageVector = Lucide.Plus,
                        contentDescription = "Thêm hàng hoá",
                        tint = MaterialTheme.colorScheme.onPrimary,
                        modifier = Modifier.size(Cach.Rong),
                    )
                }
            }
        }

        val chips = boLoc.chips()
        if (chips.isNotEmpty()) {
            LazyRow(
                horizontalArrangement = Arrangement.spacedBy(Cach.Gan),
                contentPadding = PaddingValues(horizontal = Cach.Chuan),
                modifier = Modifier.padding(bottom = Cach.Gan),
            ) {
                items(chips) { c -> ChipGo(nhan = c.nhan, onGo = { boLoc = c.con }) }
                if (chips.size > 1) {
                    item {
                        // Giữ nguyên kiểu sắp xếp: bấm "xoá hết" là để xem lại cả
                        // danh sách, không phải để đảo thứ tự đang xem.
                        ChipGo(nhan = "Xoá hết", onGo = { boLoc = LocMatHang(xep = boLoc.xep) })
                    }
                }
            }
        }

        Surface(
            color = MaterialTheme.colorScheme.surface,
            shape = Bo.Tam,
            modifier = Modifier.fillMaxSize(),
        ) {
            Column(Modifier.fillMaxSize()) {
                // Thanh đếm ĐỨNG YÊN trên đỉnh tấm trắng, danh sách cuộn bên
                // dưới nó — đọc như dòng tiêu đề của một cái bảng. Để nó cuộn
                // theo thì đang lướt giữa danh sách, muốn đổi cách sắp xếp lại
                // phải kéo ngược lên tận đầu.
                if (daChon.isEmpty()) {
                    ThanhDem(
                        chu = if (dangTai) "Đang đếm..." else "$tong mặt hàng",
                        xep = boLoc.xep.nhan,
                        onXep = { moXep = true },
                        onNangCao = { moNangCao = true },
                    )
                } else {
                    // Đang chọn nhiều thì thanh đếm ĐỔI VAI: nó thành thanh thao
                    // tác của cụm đang tick. Bày thêm một thanh thứ hai là màn
                    // hình có hai hàng nút mà chỉ một hàng dùng được.
                    ThanhDaChon(
                        so = daChon.size,
                        onBoChon = { daChon = emptySet() },
                        onXoa = { hoiXoaNhieu = true },
                    )
                }

                PullToRefreshBox(
                    isRefreshing = dangLamMoi,
                    onRefresh = { dangLamMoi = true },
                    state = rememberPullToRefreshState(),
                    modifier = Modifier.fillMaxSize(),
                ) {
                    when {
                        dangTai && !dangLamMoi -> KhungXuongHang()

                        loi != null -> MotManHinhHang {
                            TrangRong(
                                bieuTuong = Lucide.TriangleAlert,
                                tieuDe = loi.orEmpty(),
                                phu = "Kiểm tra mạng rồi thử lại.",
                                nhanNut = "Thử lại",
                                onBam = { dangLamMoi = true },
                            )
                        }

                        dong.isEmpty() -> MotManHinhHang {
                            TrangTrongHang(tuKhoa, boLoc) { boLoc = LocMatHang(xep = boLoc.xep) }
                        }

                        else -> LazyColumn(
                            state = danhSach,
                            modifier = Modifier.fillMaxSize(),
                            contentPadding = PaddingValues(bottom = demDuoi),
                        ) {
                            items(dong, key = { it.id }) { m ->
                                DongMatHang(
                                    m = m,
                                    daTick = m.id in daChon,
                                    dangChonNhieu = daChon.isNotEmpty(),
                                    onBam = {
                                        // Đang trong chế độ chọn thì một cú chạm
                                        // là tick/bỏ tick, không mở tấm chi tiết
                                        // — nửa chừng mà nhảy sang màn khác là
                                        // mất cả cụm vừa tick.
                                        if (daChon.isEmpty()) {
                                            dangXem = m
                                        } else {
                                            daChon = if (m.id in daChon) daChon - m.id else daChon + m.id
                                        }
                                    },
                                    onGiu = { daChon = daChon + m.id },
                                )
                                VachHang()
                            }

                            if (dangTaiThem) item { DongXuongHang() }

                            if (loiTaiThem) {
                                item {
                                    Box(Modifier.fillMaxWidth().padding(Cach.Chuan)) {
                                        TrangRong(
                                            bieuTuong = Lucide.TriangleAlert,
                                            tieuDe = "Không tải được thêm.",
                                            nhanNut = "Thử lại",
                                            onBam = { loiTaiThem = false },
                                        )
                                    }
                                }
                            }

                            if (!conNua && !dangTaiThem && dong.size > CO_TRANG_HANG) {
                                item { HetHang(tong) }
                            }
                        }
                    }
                }
            }
        }
    }

    if (moLoc) {
        TamLocMatHang(
            loc = boLoc,
            tong = tong,
            dangDem = dangTai,
            dsNhom = dsNhom,
            dsDonVi = dsDonVi.orEmpty(),
            dsViTri = dsViTri.orEmpty(),
            dangTaiChon = dangTaiChon,
            onThuLaiNhom = { daThuChon = false },
            onDoi = { boLoc = it },
            onDong = { moLoc = false },
        )
    }

    if (moXep) {
        TamXepMatHang(
            dangChon = boLoc.xep,
            onChon = {
                boLoc = boLoc.copy(xep = it)
                moXep = false
            },
            onDong = { moXep = false },
        )
    }

    // MỘT tấm cho cả khai mới lẫn sửa — khác nhau đúng ở `id` và `banDau`.
    if (moThem || suaId > 0) {
        TamThemHang(
            kho = kho,
            chon = OChonKhaiHang(
                nhom = dsNhom.orEmpty(),
                donVi = dsDonVi.orEmpty(),
                viTri = dsViTri.orEmpty(),
                chiNhanh = dsChiNhanh.orEmpty(),
                the = dsThe.orEmpty(),
                thuocTinh = dsThuocTinh.orEmpty(),
            ),
            dangTaiChon = dangTaiChon,
            id = suaId,
            banDau = suaBanDau,
            dangDoc = dangDocSua,
            // Lưu xong thì tải lại từ đầu: mặt hàng mới nằm ngay đầu danh sách
            // (máy chủ tự đẩy lên đầu), còn mặt hàng vừa sửa thì phải đọc lại
            // mới thấy giá mới.
            onXong = {
                moThem = false
                suaId = 0
                suaBanDau = null
                dangLamMoi = true
            },
            onBao = { bao = it },
            onDong = {
                moThem = false
                suaId = 0
                suaBanDau = null
            },
        )
    }

    dangXem?.let { m ->
        TamChiTietMatHang(
            m = m,
            kho = kho,
            onDoiTrangThai = { moi ->
                // Đổi TẠI CHỖ trong danh sách chứ không tải lại cả trang: tải lại
                // là danh sách nhảy về đầu và người dùng mất chỗ đang đứng.
                dong = dong.map { if (it.id == m.id) it.copy(trangThai = moi) else it }
                dangXem = m.copy(trangThai = moi)
            },
            onBao = { bao = it },
            onSua = {
                // Đóng tấm chi tiết trước rồi mới mở tấm sửa: hai tấm trượt
                // chồng lên nhau thì cái dưới vẫn ăn cú chạm ở rìa màn.
                dangXem = null
                suaBanDau = null
                suaId = m.id
            },
            onSaoChep = {
                dangXem = null
                pham.launch {
                    val loi = saoChepMatHang(kho, m.id)
                    bao = loi ?: "Đã sao chép \"${m.ten}\". Bản sao đang tạm ẩn."
                    if (loi == null) dangLamMoi = true
                }
            },
            onXoa = {
                // Hỏi lại TRƯỚC khi xoá, và hỏi ở màn chứ không trong tấm: tấm
                // chi tiết đóng lại rồi thì hộp thoại vẫn đứng đó.
                dangXem = null
                hoiXoa = m
            },
            doiThuTuDuoc = doiThuTuDuoc,
            onDoiThuTu = { len ->
                pham.launch {
                    val loi = doiThuTuMatHang(kho, m.id, len)
                    if (loi == null) {
                        dangXem = null
                        dangLamMoi = true
                    } else {
                        // Đã ở đầu (hoặc cuối) danh sách thì API nói thẳng —
                        // bắn nguyên câu ấy ra, đừng nuốt đi rồi để người dùng
                        // bấm tiếp vì không thấy gì nhúc nhích.
                        bao = loi
                    }
                }
            },
            onDong = { dangXem = null },
        )
    }

    if (moNangCao) {
        TamNangCao(
            dangXuat = dangXuatTep,
            onXuat = {
                moNangCao = false
                dangXuatTep = true
                pham.launch {
                    val kq = xuatHangHoaCsv(kho, boLoc, tuKhoaDaTai)
                    dangXuatTep = false
                    bao = if (!kq.xuoi) {
                        kq.loi
                    } else {
                        ghiVaChiaTep(boi, tenTepXuat(), kq.noiDung)
                            ?: "Đã xuất ${kq.soDong} mặt hàng."
                    }
                }
            },
            onNhap = {
                moNangCao = false
                // Nhận cả text/csv lẫn text/comma-separated-values: máy Android
                // gán kiểu cho tệp .csv không thống nhất, khai hẹp là bộ chọn
                // hiện ra mà chính tệp cần chọn lại bị làm mờ.
                moTep.launch(arrayOf("text/csv", "text/comma-separated-values", "text/plain"))
            },
            onTaiMau = {
                moNangCao = false
                bao = ghiVaChiaTep(boi, "mau-nhap-hang-hoa.csv", tepMauNhap())
                    ?: "Đã tạo file mẫu."
            },
            onDong = { moNangCao = false },
        )
    }

    dangNhapTep?.let { chu -> HopDangChay(chu) }

    ketQuaNhap?.let { kq -> TamKetQuaNhap(kq = kq, onDong = { ketQuaNhap = null }) }

    if (hoiXoaNhieu) {
        HoiXacNhan(
            tieuDe = "Xoá ${daChon.size} mặt hàng",
            noiDung = "Xoá ${daChon.size} mặt hàng đã chọn? Đơn cũ và báo cáo vẫn tra ra được.",
            nhanLam = "Xoá hết",
            dangChay = dangLamThaoTac,
            onLam = {
                dangLamThaoTac = true
                pham.launch {
                    val loi = xoaNhieuMatHang(kho, daChon.toList())
                    dangLamThaoTac = false
                    hoiXoaNhieu = false
                    bao = loi ?: "Đã xoá ${daChon.size} mặt hàng."
                    if (loi == null) {
                        daChon = emptySet()
                        dangLamMoi = true
                    }
                }
            },
            onThoi = { hoiXoaNhieu = false },
        )
    }

    hoiXoa?.let { m ->
        HoiXacNhan(
            tieuDe = "Xoá mặt hàng",
            noiDung = "Xoá \"${m.ten}\" khỏi danh sách hàng hoá? Đơn cũ và báo cáo vẫn tra ra được.",
            nhanLam = "Xoá",
            dangChay = dangLamThaoTac,
            onLam = {
                dangLamThaoTac = true
                pham.launch {
                    val loi = xoaMatHang(kho, m.id)
                    dangLamThaoTac = false
                    hoiXoa = null
                    bao = loi ?: "Đã xoá \"${m.ten}\"."
                    if (loi == null) dangLamMoi = true
                }
            },
            onThoi = { hoiXoa = null },
        )
    }

    bao?.let { chu -> BaoNhanh(chu = chu, onXong = { bao = null }) }
}

/** Bọc một trạng thái rỗng cho nó nằm giữa phần màn còn lại và vẫn kéo lại được. */
@Composable
private fun MotManHinhHang(noiDung: @Composable () -> Unit) {
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

/** Màn trống nói ĐÚNG lý do trống, và mở đúng lối ra tương ứng. */
@Composable
private fun TrangTrongHang(tuKhoa: String, loc: LocMatHang, onXoaLoc: () -> Unit) {
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
            tieuDe = "Chưa khai mặt hàng nào.",
            phu = "Khai mặt hàng bên Shop Admin, ở đây sẽ thấy ngay.",
        )
    }
}

/** Nút mở tấm sắp xếp, mang sẵn tên kiểu đang dùng. */
@Composable
private fun NutXepHang(nhan: String, onBam: () -> Unit) {
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
 * Thanh đếm — dòng tiêu đề của tấm trắng.
 *
 * Bên trái là số dòng đang khớp bộ lọc, bên phải là cách đang sắp xếp. Hai thứ
 * này nói về CẢ DANH SÁCH chứ không về một dòng nào, nên chúng thuộc về tấm
 * trắng chứ không nằm lẫn trên nền xám cùng ô tìm — nền xám giữ phần nhận diện
 * và ô tìm, tấm trắng giữ dữ liệu.
 */
@Composable
private fun ThanhDem(chu: String, xep: String, onXep: () -> Unit, onNangCao: () -> Unit) {
    Column {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier
                .fillMaxWidth()
                .padding(start = Cach.Chuan, end = Cach.Gan, top = Cach.Vua, bottom = Cach.Gan),
        ) {
            Text(
                text = chu,
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = mauPhu.chuMo,
                modifier = Modifier.weight(1f),
            )
            NutXepHang(nhan = xep, onBam = onXep)
            // Ba chấm đứng cuối thanh đếm chứ không chen vào cụm tìm–lọc ở trên:
            // xuất, nhập, tải mẫu là ba việc mỗi tháng bấm một lần, chen chúng
            // lên cạnh ô tìm là thanh công cụ đầy ứ thứ hiếm dùng.
            Icon(
                imageVector = Lucide.Ellipsis,
                contentDescription = "Nâng cao",
                tint = mauPhu.chuThuong,
                modifier = Modifier
                    .clip(Bo.Tron)
                    .clickable(onClick = onNangCao)
                    .padding(Cach.Gan)
                    .size(Cach.Rong),
            )
        }
        HorizontalDivider(thickness = 1.dp, color = mauPhu.vienNhat)
    }
}

/**
 * Thanh của chế độ CHỌN NHIỀU, thay chỗ thanh đếm.
 *
 * Tô nền xanh nhạt để không lẫn với thanh đếm bình thường: đang ở một chế độ
 * KHÁC, mà mấy dòng bên dưới lúc này bấm vào là tick chứ không mở chi tiết.
 */
@Composable
private fun ThanhDaChon(so: Int, onBoChon: () -> Unit, onXoa: () -> Unit) {
    Column {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier
                .fillMaxWidth()
                .background(mauPhu.lamNen)
                .padding(start = Cach.Chuan, end = Cach.Gan, top = Cach.Gan, bottom = Cach.Gan),
        ) {
            Text(
                text = "$so đã chọn",
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = mauPhu.lam,
                modifier = Modifier.weight(1f),
            )

            Text(
                text = "Bỏ chọn",
                style = MaterialTheme.typography.labelLarge,
                color = mauPhu.chuThuong,
                modifier = Modifier
                    .clip(Bo.Nho)
                    .clickable(onClick = onBoChon)
                    .padding(horizontal = Cach.Vua, vertical = Cach.Gan),
            )

            Text(
                text = "Xoá",
                style = MaterialTheme.typography.labelLarge,
                fontWeight = FontWeight.SemiBold,
                color = mauPhu.do_,
                modifier = Modifier
                    .clip(Bo.Nho)
                    .clickable(onClick = onXoa)
                    .padding(horizontal = Cach.Vua, vertical = Cach.Gan),
            )
        }
        HorizontalDivider(thickness = 1.dp, color = mauPhu.vienNhat)
    }
}

/** Bề ngang ô ảnh của một dòng. Vạch ngăn thụt vào đúng bằng nó cộng lề. */
private val CO_ANH = 52.dp

/**
 * Một dòng hàng hoá.
 *
 * ẢNH MẶT HÀNG dẫn đầu dòng, không phải vạch màu như mấy màn khác. Quy chuẩn
 * chung của app đúng là vạch 3dp — nó thắng cái ô icon tròn vì ô icon giống hệt
 * nhau ở mọi dòng nên chẳng nói thêm được gì. Nhưng ảnh mặt hàng thì mỗi dòng
 * một khác, và với người bán, cái hình chính là thứ nhận ra món hàng nhanh nhất,
 * nhanh hơn cả đọc tên. Chưa có ảnh thì ô rơi về chữ cái đầu, vẫn giữ đúng nhịp
 * bố cục nên danh sách không so le.
 *
 * GIÁ căn TRÊN, thẳng hàng với dòng tên chứ không căn giữa dòng: tên dài hai
 * dòng mà giá căn giữa thì cả cột giá nhấp nhô theo độ dài của tên bên trái.
 *
 * Hàng đang ẩn thì ảnh nhạt đi và tên xám lại — lướt dọc là thấy ngay, chưa cần
 * đọc tới huy hiệu.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun DongMatHang(
    m: MatHang,
    daTick: Boolean,
    dangChonNhieu: Boolean,
    onBam: () -> Unit,
    onGiu: () -> Unit,
) {
    val conBan = m.trangThai == TrangThaiHang.DANG_BAN

    Row(
        modifier = Modifier
            .fillMaxWidth()
            // Nhấn GIỮ để vào chế độ chọn — lối quen của mọi app Android. Bày
            // sẵn một ô tick ở mỗi dòng thì lúc chỉ muốn xem, cả danh sách đã
            // đầy ô vuông trống.
            .combinedClickable(onClick = onBam, onLongClick = onGiu)
            .background(if (daTick) mauPhu.lamNen else Color.Transparent)
            .padding(horizontal = Cach.Chuan, vertical = Cach.Vua),
        verticalAlignment = Alignment.Top,
    ) {
        Box {
            AnhVuong(
                duong = m.anh,
                chuThay = m.ten,
                mo = !conBan,
                modifier = Modifier.size(CO_ANH),
            )
            // Dấu tích đè lên góc ảnh chứ không chiếm thêm một cột: vào chế độ
            // chọn mà cả danh sách xô ngang một đoạn thì mắt mất chỗ neo.
            if (dangChonNhieu) {
                Box(
                    modifier = Modifier
                        .align(Alignment.TopStart)
                        .size(Cach.Rong)
                        .background(
                            if (daTick) MaterialTheme.colorScheme.primary else mauPhu.matChim,
                            Bo.Tron,
                        ),
                    contentAlignment = Alignment.Center,
                ) {
                    if (daTick) {
                        Icon(
                            imageVector = Lucide.Check,
                            contentDescription = "Đã chọn",
                            tint = MaterialTheme.colorScheme.onPrimary,
                            modifier = Modifier.size(Cach.Vua),
                        )
                    }
                }
            }
        }

        Spacer(Modifier.width(Cach.Vua))

        Column(Modifier.weight(1f)) {
            Text(
                text = m.ten,
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.Medium,
                color = if (conBan) MaterialTheme.colorScheme.onBackground else mauPhu.chuMo,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )

            Spacer(Modifier.height(3.dp))

            // Mã hàng, nhóm và số biến thể gộp một dòng mờ. Số biến thể chỉ ghi
            // khi thật sự có nhiều hơn một — "1 biến thể" dán lên mọi dòng thì
            // nó thành nền, mắt thôi đọc.
            Text(
                text = listOf(
                    m.sku,
                    m.nhom,
                    if (m.soBienThe > 1) "${m.soBienThe} biến thể" else "",
                ).filter { it.isNotBlank() }.joinToString(" · "),
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuMo,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )

            // Huy hiệu CHỈ cho thứ khác thường. Dòng bình thường không có hàng
            // này, nên mọi dòng cao bằng nhau và cái nào có huy hiệu thì đập vào
            // mắt ngay.
            val huy = buildList {
                if (!conBan) add(m.trangThai.nhan to sacTrangThai(m.trangThai))
                if (m.khuyenMai.isNotBlank()) add(m.khuyenMai to Sac.CAM)
            }
            if (huy.isNotEmpty()) {
                Spacer(Modifier.height(Cach.Gan))
                Row(horizontalArrangement = Arrangement.spacedBy(Cach.Sat)) {
                    huy.forEach { (chu, sac) -> Huy(chu = chu, sac = sac) }
                }
            }
        }

        Spacer(Modifier.width(Cach.Vua))

        Column(horizontalAlignment = Alignment.End) {
            Text(
                text = tienVN(m.gia),
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.Bold,
                color = if (m.dangGiam) mauPhu.do_ else MaterialTheme.colorScheme.onBackground,
            )
            if (m.dangGiam) {
                Spacer(Modifier.height(2.dp))
                Text(
                    text = tienVN(m.giaGoc),
                    style = MaterialTheme.typography.bodySmall,
                    color = mauPhu.chuMo,
                    textDecoration = TextDecoration.LineThrough,
                )
            } else if (m.donVi.isNotBlank()) {
                Spacer(Modifier.height(2.dp))
                Text(
                    text = "/ ${m.donVi}",
                    style = MaterialTheme.typography.bodySmall,
                    color = mauPhu.chuMo,
                )
            }
        }
    }
}

/** Sắc của huy hiệu trạng thái trong tấm chi tiết. */
internal fun sacTrangThai(t: TrangThaiHang): Sac = when (t) {
    TrangThaiHang.DANG_BAN -> Sac.LUC
    TrangThaiHang.TAM_AN -> Sac.CAM
    TrangThaiHang.NGUNG -> Sac.XAM
}

/** Khung xương: sáu dòng giả đúng nhịp của dòng thật. */
@Composable
private fun KhungXuongHang() {
    Column(Modifier.fillMaxSize()) {
        repeat(6) {
            DongXuongHang()
            VachHang()
        }
    }
}

@Composable
private fun DongXuongHang() {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = Cach.Chuan, vertical = Cach.Vua),
        verticalAlignment = Alignment.Top,
    ) {
        OXuong(modifier = Modifier.size(CO_ANH), bo = Bo.O)
        Spacer(Modifier.width(Cach.Vua))
        Column(Modifier.weight(1f)) {
            OXuong(modifier = Modifier.fillMaxWidth(0.75f).height(Cach.Chuan))
            Spacer(Modifier.height(Cach.Gan))
            OXuong(modifier = Modifier.fillMaxWidth(0.45f).height(Cach.Vua))
        }
        Spacer(Modifier.width(Cach.Vua))
        OXuong(modifier = Modifier.width(Cach.Lon + Cach.Rong).height(Cach.Chuan))
    }
}

/** Dấu hết danh sách. Nhạt thôi — nó là một câu kết, không phải một mục. */
@Composable
private fun HetHang(tong: Long) {
    Text(
        text = "Hết $tong mặt hàng",
        style = MaterialTheme.typography.bodySmall,
        color = mauPhu.chuMo,
        textAlign = TextAlign.Center,
        modifier = Modifier.fillMaxWidth().padding(vertical = Cach.Khoi),
    )
}

/** Vạch giữa hai dòng, thụt vào thẳng hàng với tên hàng. */
@Composable
private fun VachHang() {
    HorizontalDivider(
        thickness = 1.dp,
        color = mauPhu.vienNhat,
        // Thụt qua hết ô ảnh: vạch chạy suốt mép này sang mép kia cắt danh sách
        // thành từng ô rời, còn vạch thụt giữ cả cột tên liền một mạch.
        modifier = Modifier.padding(start = Cach.Chuan + CO_ANH + Cach.Vua),
    )
}
