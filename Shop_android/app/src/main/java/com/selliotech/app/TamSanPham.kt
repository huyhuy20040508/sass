package com.selliotech.app

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.BottomSheetDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.composables.icons.lucide.ArrowDown
import com.composables.icons.lucide.ArrowUp
import com.composables.icons.lucide.Copy
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.Pencil
import com.composables.icons.lucide.Trash2
import com.selliotech.app.ui.AnhVuong
import com.selliotech.app.ui.ChipChon
import com.selliotech.app.ui.DongChon
import com.selliotech.app.ui.Huy
import com.selliotech.app.ui.HangNut
import com.selliotech.app.ui.NutChinh
import com.selliotech.app.ui.NutNguyHiem
import com.selliotech.app.ui.ONutViec
import com.selliotech.app.ui.Sac
import com.selliotech.app.ui.tienVN
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.mauPhu
import kotlinx.coroutines.launch

// =====================================================================
//  BA TẤM TRƯỢT CỦA TRANG HÀNG HOÁ
//
//  Bề ngang điện thoại không đủ cho thanh công cụ của bản web, mà xếp
//  nó thành bốn dòng chồng lên nhau thì danh sách bị đẩy xuống quá nửa
//  màn. Nên bộ lọc, cách sắp xếp và chi tiết một mặt hàng đều lui vào
//  tấm trượt; trên màn chỉ còn một nút lọc có con số và một nút sắp xếp.
// =====================================================================

/**
 * Tấm LỌC.
 *
 * Đổi là ăn NGAY, không có nút "Áp dụng" — đúng quy tắc trang danh sách của cả
 * hệ thống. Nhóm hàng và trạng thái chọn được NHIỀU, y như bản web, vì API nhận
 * `category_ids` và `statuses` ngăn bằng dấu phẩy.
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun TamLocMatHang(
    loc: LocMatHang,
    /** Số dòng đang khớp — nhảy theo từng cú bấm chip, xem mục đầu tấm. */
    tong: Long,
    dangDem: Boolean,
    /** null = chưa lấy được (chưa gọi, hoặc gọi hỏng). Rỗng = thật sự chưa khai nhóm nào. */
    dsNhom: List<DanhMuc>?,
    dsDonVi: List<OChon>,
    dsViTri: List<OChon>,
    dangTaiChon: Boolean,
    onDoi: (LocMatHang) -> Unit,
    onThuLaiNhom: () -> Unit,
    onDong: () -> Unit,
) {
    ModalBottomSheet(
        onDismissRequest = onDong,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        shape = Bo.Tam,
        containerColor = MaterialTheme.colorScheme.surface,
        dragHandle = { BottomSheetDefaults.DragHandle(color = mauPhu.vien) },
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = Cach.Rong),
        ) {
            // Tiêu đề mang theo SỐ DÒNG ĐANG KHỚP, nhảy theo từng cú bấm chip.
            // Bộ lọc ăn ngay nhưng tấm này che mất danh sách phía sau, nên không
            // có con số ở đây thì bấm xong phải đóng tấm lại mới biết mình vừa
            // lọc ra cái gì — rồi lại mở ra sửa.
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = "Lọc hàng hoá",
                    style = MaterialTheme.typography.titleMedium,
                    color = MaterialTheme.colorScheme.onSurface,
                    modifier = Modifier.weight(1f),
                )
                Text(
                    text = if (dangDem) "Đang đếm..." else "$tong mặt hàng",
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = if (dangDem) mauPhu.chuMo else MaterialTheme.colorScheme.primary,
                )
            }

            Spacer(Modifier.height(Cach.Khoi))

            // Thứ tự mục CHÉP ĐÚNG bản web: nhóm hàng, trạng thái, rồi tới cụm
            // nâng cao. Người dùng đi lại giữa web và app suốt ngày; đảo thứ tự
            // là mỗi lần đổi máy lại phải dò xem ô mình cần nằm đâu.
            NhanMucHang("Nhóm hàng hoá")
            when {
                dangTaiChon -> DongDangTai("Đang lấy danh sách nhóm...")

                // Gọi hỏng KHÁC HẲN cửa hàng chưa khai nhóm. Nói nhầm câu thứ
                // hai là người dùng đi tạo lại đúng những nhóm họ đã có.
                dsNhom == null -> Row(
                    verticalAlignment = Alignment.CenterVertically,
                    modifier = Modifier.padding(vertical = Cach.Gan),
                ) {
                    Text(
                        text = "Không lấy được danh sách nhóm.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuMo,
                        modifier = Modifier.weight(1f),
                    )
                    Text(
                        text = "Thử lại",
                        style = MaterialTheme.typography.labelLarge,
                        color = MaterialTheme.colorScheme.primary,
                        modifier = Modifier
                            .clip(Bo.Nho)
                            .clickable(onClick = onThuLaiNhom)
                            .padding(horizontal = Cach.Vua, vertical = Cach.Gan),
                    )
                }

                dsNhom.isEmpty() -> ChuMo("Chưa khai nhóm hàng nào.")

                else -> DaiChip {
                    ChipChon(
                        chu = "Tất cả nhóm",
                        chon = loc.nhom.isEmpty(),
                        onBam = { onDoi(loc.copy(nhom = emptyList())) },
                    )
                    dsNhom.forEach { n ->
                        // Dấu gạch đầu dòng theo bậc: nhóm con phải nhìn ra là
                        // con của ai, không thì "Nước ngọt" với "Đồ uống" trông
                        // như hai nhóm ngang hàng.
                        val dangChon = loc.nhom.any { it.id == n.id }
                        ChipChon(
                            chu = "— ".repeat(n.bac) + n.ten,
                            chon = dangChon,
                            onBam = {
                                onDoi(
                                    loc.copy(
                                        nhom = if (dangChon) {
                                            loc.nhom.filterNot { it.id == n.id }
                                        } else {
                                            loc.nhom + n
                                        },
                                    ),
                                )
                            },
                        )
                    }
                }
            }

            Spacer(Modifier.height(Cach.Khoi))

            // Trạng thái CHỌN NHIỀU: bấm một cái là bật/tắt riêng nó, không tick
            // gì = xem tất cả. Đúng cụm ô tick của bản web.
            NhanMucHang("Trạng thái")
            DaiChip {
                ChipChon(
                    chu = "Tất cả",
                    chon = loc.trangThai.isEmpty(),
                    onBam = { onDoi(loc.copy(trangThai = emptySet())) },
                )
                TrangThaiHang.entries.forEach { t ->
                    ChipChon(
                        chu = t.nhan,
                        chon = t in loc.trangThai,
                        onBam = {
                            onDoi(
                                loc.copy(
                                    trangThai = if (t in loc.trangThai) {
                                        loc.trangThai - t
                                    } else {
                                        loc.trangThai + t
                                    },
                                ),
                            )
                        },
                    )
                }
            }

            Spacer(Modifier.height(Cach.Khoi))

            // Vạch "Nâng cao" thay cho cái nút gập bên web. Không gập được ở đây
            // vì tấm này vốn đã cuộn; nhưng vẫn phải có ranh giới, để người chỉ
            // cần lọc nhanh biết phần trên là đủ rồi.
            VachNangCao()

            // Đơn vị tính và vị trí đòi QUYỀN RIÊNG bên API. Tài khoản không có
            // quyền thì danh sách về rỗng và cả mục biến mất — bày một ô chọn
            // rỗng để người ta bấm mãi không ra gì còn tệ hơn là không bày.
            if (dsViTri.isNotEmpty()) {
                NhanMucHang("Vị trí để hàng")
                DaiChip {
                    ChipChon(
                        chu = "Tất cả",
                        chon = loc.viTri.isBlank(),
                        onBam = { onDoi(loc.copy(viTri = "", viTriTen = "")) },
                    )
                    // "Chưa gán vị trí" là lựa chọn CÓ THẬT chứ không phải trạng
                    // thái thiếu dữ liệu: đó đúng là câu người đi soạn hàng hỏi
                    // — còn món nào chưa biết để đâu.
                    ChipChon(
                        chu = "Chưa gán vị trí",
                        chon = loc.viTri == "none",
                        onBam = { onDoi(loc.copy(viTri = "none", viTriTen = "Chưa gán vị trí")) },
                    )
                    dsViTri.forEach { v ->
                        ChipChon(
                            chu = v.nhan,
                            chon = loc.viTri == v.id.toString(),
                            onBam = { onDoi(loc.copy(viTri = v.id.toString(), viTriTen = v.ten)) },
                        )
                    }
                }
                Spacer(Modifier.height(Cach.Khoi))
            }

            if (dsDonVi.isNotEmpty()) {
                NhanMucHang("Đơn vị tính")
                DaiChip {
                    ChipChon(
                        chu = "Tất cả",
                        chon = loc.donViId == 0L,
                        onBam = { onDoi(loc.copy(donViId = 0, donViTen = "")) },
                    )
                    dsDonVi.forEach { d ->
                        ChipChon(
                            chu = d.nhan,
                            chon = loc.donViId == d.id,
                            onBam = { onDoi(loc.copy(donViId = d.id, donViTen = d.ten)) },
                        )
                    }
                }
                Spacer(Modifier.height(Cach.Khoi))
            }

            NhanMucHang("Biến thể")
            DaiChip {
                ChipChon("Tất cả", loc.nhieuBienThe == null, onBam = { onDoi(loc.copy(nhieuBienThe = null)) })
                ChipChon("Nhiều biến thể", loc.nhieuBienThe == true, onBam = { onDoi(loc.copy(nhieuBienThe = true)) })
                ChipChon("Hàng đơn", loc.nhieuBienThe == false, onBam = { onDoi(loc.copy(nhieuBienThe = false)) })
            }

            Spacer(Modifier.height(Cach.Khoi))

            HangNut {
                if (loc.coLoc) {
                    NutNguyHiem(
                        chu = "Xoá lọc",
                        onBam = { onDoi(LocMatHang(xep = loc.xep)) },
                        modifier = Modifier.weight(1f),
                        cao = CaoCham.NutChinh,
                    )
                }
                NutChinh(chu = "Xong", onBam = onDong, modifier = Modifier.weight(1f))
            }

            Spacer(Modifier.height(Cach.Lon))
        }
    }
}

/** Tấm SẮP XẾP — đủ tám kiểu của bản web. Chọn một cái là tấm tự đóng. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TamXepMatHang(dangChon: XepHangHoa, onChon: (XepHangHoa) -> Unit, onDong: () -> Unit) {
    ModalBottomSheet(
        onDismissRequest = onDong,
        sheetState = rememberModalBottomSheetState(),
        shape = Bo.Tam,
        containerColor = MaterialTheme.colorScheme.surface,
        dragHandle = { BottomSheetDefaults.DragHandle(color = mauPhu.vien) },
    ) {
        Column(modifier = Modifier.fillMaxWidth().padding(horizontal = Cach.Vua)) {
            Text(
                text = "Sắp xếp",
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onSurface,
                modifier = Modifier.padding(start = Cach.Gan, bottom = Cach.Vua),
            )

            XepHangHoa.entries.forEach { x ->
                DongChon(nhan = x.nhan, chon = x == dangChon, onBam = { onChon(x) })
            }

            Spacer(Modifier.height(Cach.Lon))
        }
    }
}

/**
 * Tấm CHI TIẾT một mặt hàng.
 *
 * Không gọi thêm lượt mạng nào: mọi thứ trên tấm này đã nằm sẵn trong dòng danh
 * sách. Mở ra là có ngay, kể cả lúc mạng chập chờn — đó là điểm được của việc
 * đọc /products thay vì gọi lại từng mặt hàng.
 *
 * DỰNG LẠI CHO GỌN. Bản đầu xếp dọc tất: ảnh, giá, năm dòng nhãn–giá trị, ba
 * chip trạng thái kèm câu giải thích, nút Sửa chạy hết bề ngang, hàng Sao
 * chép/Xoá, rồi cả một khối "Thứ tự trong danh sách" với đoạn văn giải thích vì
 * sao chưa bấm được. Phải cuộn gần hết tấm mới thấy nút — mà nút mới là thứ
 * người ta mở tấm này ra để bấm.
 *
 * Bản này gom lại:
 *
 * | Trước | Giờ |
 * |---|---|
 * | 5 dòng nhãn–giá trị xếp dọc | Lưới 2 cột, 2 hàng |
 * | 3 hàng nút + 1 đoạn văn | MỘT hàng ô thao tác có nhãn |
 * | Câu giải thích thứ tự luôn hiện | Hai nút thứ tự chỉ hiện khi bấm được |
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TamChiTietMatHang(
    m: MatHang,
    kho: KhoPhien,
    onDoiTrangThai: (TrangThaiHang) -> Unit,
    onBao: (String) -> Unit,
    onSua: () -> Unit,
    onSaoChep: () -> Unit,
    onXoa: () -> Unit,
    /** Đổi thứ tự chỉ có nghĩa khi danh sách đang ở thứ tự tự xếp và không lọc. */
    doiThuTuDuoc: Boolean,
    onDoiThuTu: (len: Boolean) -> Unit,
    onDong: () -> Unit,
) {
    val pham = rememberCoroutineScope()
    var dangGui by remember { mutableStateOf(false) }

    ModalBottomSheet(
        onDismissRequest = onDong,
        sheetState = rememberModalBottomSheetState(),
        shape = Bo.Tam,
        containerColor = MaterialTheme.colorScheme.surface,
        dragHandle = { BottomSheetDefaults.DragHandle(color = mauPhu.vien) },
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = Cach.Rong),
        ) {
            // Ảnh, tên, mã và giá gom vào MỘT khối đầu: bốn thứ này là câu trả
            // lời cho "đang xem món nào, bao nhiêu tiền" — thứ duy nhất phải
            // đọc được mà không cần cuộn.
            Row(verticalAlignment = Alignment.CenterVertically) {
                AnhVuong(
                    duong = m.anh,
                    chuThay = m.ten,
                    bo = Bo.The,
                    mo = m.trangThai != TrangThaiHang.DANG_BAN,
                    modifier = Modifier.size(64.dp),
                )

                Spacer(Modifier.width(Cach.Chuan))

                Column(Modifier.weight(1f)) {
                    Text(
                        text = m.ten,
                        style = MaterialTheme.typography.titleMedium,
                        color = MaterialTheme.colorScheme.onSurface,
                    )
                    Spacer(Modifier.height(2.dp))
                    Text(
                        text = m.sku.ifBlank { "Chưa có mã hàng" },
                        style = MaterialTheme.typography.bodySmall,
                        color = mauPhu.chuMo,
                    )
                }
            }

            Spacer(Modifier.height(Cach.Chuan))

            Row(verticalAlignment = Alignment.Bottom) {
                Text(
                    text = tienVN(m.gia),
                    style = MaterialTheme.typography.headlineMedium,
                    fontWeight = FontWeight.Bold,
                    color = if (m.dangGiam) mauPhu.do_ else MaterialTheme.colorScheme.onSurface,
                )
                if (m.dangGiam) {
                    Spacer(Modifier.width(Cach.Vua))
                    Text(
                        text = tienVN(m.giaGoc),
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuMo,
                        textDecoration = TextDecoration.LineThrough,
                        modifier = Modifier.padding(bottom = Cach.Sat),
                    )
                }
            }

            if (m.khuyenMai.isNotBlank() || m.soBienThe > 1) {
                Spacer(Modifier.height(Cach.Vua))
                Row(horizontalArrangement = Arrangement.spacedBy(Cach.Gan)) {
                    // Nói rõ giá đang rẻ vì chương trình nào, để người bán không
                    // tưởng ai đó sửa nhầm giá niêm yết.
                    if (m.khuyenMai.isNotBlank()) Huy(chu = m.khuyenMai, sac = Sac.CAM)
                    if (m.soBienThe > 1) Huy(chu = "${m.soBienThe} biến thể", sac = Sac.LAM)
                }
            }

            Spacer(Modifier.height(Cach.Rong))

            // MỘT hàng thao tác thay cho ba hàng nút. Hai nút thứ tự chỉ có mặt
            // khi bấm được — bày nút xám kèm một đoạn văn giải thích vì sao nó
            // xám là tốn cả khối màn hình cho một thứ không dùng được.
            Row(horizontalArrangement = Arrangement.spacedBy(Cach.Gan)) {
                ONutViec(
                    bieuTuong = Lucide.Pencil,
                    nhan = "Sửa",
                    onBam = onSua,
                    moKhoa = !dangGui,
                    modifier = Modifier.weight(1f),
                )
                ONutViec(
                    bieuTuong = Lucide.Copy,
                    nhan = "Sao chép",
                    onBam = onSaoChep,
                    moKhoa = !dangGui,
                    modifier = Modifier.weight(1f),
                )
                if (doiThuTuDuoc) {
                    ONutViec(
                        bieuTuong = Lucide.ArrowUp,
                        nhan = "Lên trên",
                        onBam = { onDoiThuTu(true) },
                        moKhoa = !dangGui,
                        modifier = Modifier.weight(1f),
                    )
                    ONutViec(
                        bieuTuong = Lucide.ArrowDown,
                        nhan = "Xuống dưới",
                        onBam = { onDoiThuTu(false) },
                        moKhoa = !dangGui,
                        modifier = Modifier.weight(1f),
                    )
                }
                ONutViec(
                    bieuTuong = Lucide.Trash2,
                    nhan = "Xoá",
                    onBam = onXoa,
                    nguyHiem = true,
                    moKhoa = !dangGui,
                    modifier = Modifier.weight(1f),
                )
            }

            Spacer(Modifier.height(Cach.Rong))

            // Lưới hai cột: bốn ô này đều ngắn, xếp dọc từng dòng là phí nửa bề
            // ngang và đẩy phần dưới xuống thêm bốn dòng.
            LuoiHaiCot(
                listOf(
                    "Nhóm hàng" to m.nhom.ifBlank { "Chưa xếp nhóm" },
                    "Đơn vị tính" to m.donVi.ifBlank { "Chưa khai" },
                    "Thuế GTGT" to chuVAT(m.vat),
                    // Chưa gán chi nhánh nào = bán ở MỌI chi nhánh. Nói thẳng
                    // ra chứ không để trống: ô trống đọc như dữ liệu thiếu.
                    "Chi nhánh" to m.chiNhanh.ifBlank { "Mọi chi nhánh" },
                ),
            )

            Spacer(Modifier.height(Cach.Rong))

            NhanMucHang("Trạng thái kinh doanh")
            DaiChip {
                TrangThaiHang.entries.forEach { t ->
                    ChipChon(
                        chu = t.nhan,
                        chon = t == m.trangThai,
                        onBam = {
                            if (dangGui || t == m.trangThai) return@ChipChon
                            dangGui = true
                            pham.launch {
                                val loi = doiTrangThaiHang(kho, m.id, t)
                                dangGui = false
                                if (loi == null) {
                                    onDoiTrangThai(t)
                                    onBao(cauDaDoi(t))
                                } else {
                                    onBao(loi)
                                }
                            }
                        },
                    )
                }
            }

            Spacer(Modifier.height(Cach.Gan))

            Text(
                text = if (dangGui) "Đang lưu..." else m.trangThai.giaiThich,
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuMo,
            )

            Spacer(Modifier.height(Cach.Lon))
        }
    }
}

/**
 * Lưới hai cột cho mấy ô thông tin ngắn: nhãn mờ nằm trên, giá trị đậm nằm dưới.
 *
 * Khác `HangThongTin` (nhãn trái – giá trị phải) ở chỗ nó xếp DỌC trong ô, nên
 * hai ô lọt vừa một dòng. Giá trị dài thì cắt một dòng chứ không đẩy ô bên cạnh
 * méo đi.
 */
@Composable
private fun LuoiHaiCot(o: List<Pair<String, String>>) {
    Column {
        o.chunked(2).forEach { hang ->
            Row(modifier = Modifier.fillMaxWidth().padding(bottom = Cach.Chuan)) {
                hang.forEach { (nhan, gia) ->
                    Column(Modifier.weight(1f).padding(end = Cach.Vua)) {
                        Text(
                            text = nhan,
                            style = MaterialTheme.typography.labelMedium,
                            color = mauPhu.chuMo,
                        )
                        Spacer(Modifier.height(2.dp))
                        Text(
                            text = gia,
                            style = MaterialTheme.typography.bodyLarge,
                            fontWeight = FontWeight.Medium,
                            color = MaterialTheme.colorScheme.onSurface,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                }
                // Hàng lẻ thiếu ô thì chèn chỗ trống, đừng để ô còn lại giãn ra
                // gấp đôi và lệch hẳn khỏi cột bên trên.
                if (hang.size == 1) Spacer(Modifier.weight(1f))
            }
        }
    }
}

/** Câu báo sau khi đổi trạng thái. Dùng đúng lời của bản web cho khỏi lệch. */
private fun cauDaDoi(t: TrangThaiHang): String = when (t) {
    TrangThaiHang.DANG_BAN -> "Đã cho mặt hàng bán trở lại."
    TrangThaiHang.TAM_AN -> "Đã tạm ẩn mặt hàng."
    TrangThaiHang.NGUNG -> "Đã chuyển mặt hàng sang ngừng kinh doanh."
}

/** Vạch ngăn phần lọc nhanh với phần nâng cao, có chữ nằm giữa. */
@Composable
private fun VachNangCao() {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier.fillMaxWidth().padding(bottom = Cach.Rong),
    ) {
        HorizontalDivider(thickness = 1.dp, color = mauPhu.vienNhat, modifier = Modifier.weight(1f))
        Text(
            text = "Nâng cao",
            style = MaterialTheme.typography.labelSmall,
            color = mauPhu.chuMo,
            modifier = Modifier.padding(horizontal = Cach.Vua),
        )
        HorizontalDivider(thickness = 1.dp, color = mauPhu.vienNhat, modifier = Modifier.weight(1f))
    }
}

/** Dải chip xuống dòng được. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DaiChip(noiDung: @Composable () -> Unit) {
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(Cach.Gan),
        verticalArrangement = Arrangement.spacedBy(Cach.Gan),
    ) {
        noiDung()
    }
}

@Composable
private fun NhanMucHang(nhan: String) {
    Text(
        text = nhan,
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.SemiBold,
        color = mauPhu.chuMo,
        modifier = Modifier.padding(bottom = Cach.Vua),
    )
}

@Composable
private fun DongDangTai(chu: String) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier.padding(vertical = Cach.Gan),
    ) {
        CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
        Spacer(Modifier.width(Cach.Vua))
        Text(chu, style = MaterialTheme.typography.bodyMedium, color = mauPhu.chuMo)
    }
}

@Composable
private fun ChuMo(chu: String) {
    Text(
        text = chu,
        style = MaterialTheme.typography.bodyMedium,
        color = mauPhu.chuMo,
        modifier = Modifier.padding(vertical = Cach.Gan),
    )
}
