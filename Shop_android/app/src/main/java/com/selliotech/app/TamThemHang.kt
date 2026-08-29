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
import androidx.compose.material3.Icon
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.X
import com.selliotech.app.ui.ChipChon
import com.selliotech.app.ui.CongTac
import com.selliotech.app.ui.HangNut
import com.selliotech.app.ui.MucGap
import com.selliotech.app.ui.NutChinh
import com.selliotech.app.ui.NutNguyHiem
import com.selliotech.app.ui.ONhap
import com.selliotech.app.ui.ONhapAnh
import com.selliotech.app.ui.ONhapTien
import com.selliotech.app.ui.duoiTuMime
import com.selliotech.app.ui.tienVN
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.mauPhu
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Mấy mức thuế hay dùng, bày sẵn thành chip. `null` = để theo mức của nhóm hàng. */
private val MUC_THUE = listOf<Int?>(null, 0, 5, 8, 10, -1, -2)

/** Mọi danh sách chọn mà biểu mẫu cần. Gom một chỗ cho khỏi bảy tham số rời. */
data class OChonKhaiHang(
    val nhom: List<DanhMuc> = emptyList(),
    val donVi: List<OChon> = emptyList(),
    val viTri: List<OChon> = emptyList(),
    val chiNhanh: List<OChon> = emptyList(),
    val the: List<OChon> = emptyList(),
    val thuocTinh: List<ThuocTinhHang> = emptyList(),
)

/**
 * TẤM KHAI MẶT HÀNG MỚI — đủ ô của hộp thoại bên web.
 *
 * Hộp thoại web bày hai cột và một lưới bốn ô mỗi hàng; bề ngang điện thoại chỉ
 * đủ một cột, mà mười lăm ô xếp thẳng một mạch thì cuộn mãi không biết còn bao
 * xa. Nên chúng chia thành NĂM KHỐI có tên, ngăn nhau bằng vạch:
 *
 * | Khối | Ô |
 * |---|---|
 * | Thông tin chính | Ảnh đại diện, Tên*, Nhóm hàng*, Mã hàng, Bar/QR Code |
 * | Giá | Giá bán*, Giá vốn, % VAT, và dòng "Giá sau thuế" tự tính |
 * | Kho & bán hàng | Đơn vị tính, Vị trí, Chi nhánh, Thẻ hàng hoá |
 * | Tuỳ chọn | Trạng thái, In tem, Trừ kho khi bán, Quản lý theo seri |
 * | Mô tả | Mô tả ngắn, Mô tả chi tiết, Meta title, Meta description |
 *
 * Thứ tự trong khối chép đúng thứ tự bên web, để người khai hàng quen tay ở máy
 * tính thì sang điện thoại không phải học lại.
 *
 * HAI THỨ CHƯA CÓ: **quy đổi đơn vị** và **thuộc tính/biến thể** — mỗi thứ là một
 * bảng con nhiều dòng, cần màn riêng.
 *
 * Ảnh thì CÓ, nhưng nó đòi máy chủ đã cập nhật: trước đây API không có chỗ nào
 * nhận file, ảnh đi qua trang quản trị Laravel bằng phiên đăng nhập web mà app
 * không dùng lại được. Nay API có `POST /admin/products/anh` (xem
 * `handler.TepHandler` bên repo api) — chưa deploy bản API mới thì lượt tải ảnh
 * nhận 404 và app nói thẳng là cần cập nhật máy chủ.
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun TamThemHang(
    kho: KhoPhien,
    chon: OChonKhaiHang,
    dangTaiChon: Boolean,
    onXong: () -> Unit,
    onBao: (String) -> Unit,
    onDong: () -> Unit,
    /** >0 = đang SỬA mặt hàng ấy. 0 = khai mới. */
    id: Long = 0,
    /** Giá trị mở màn khi sửa. null = biểu mẫu trắng. */
    banDau: HangMoi? = null,
    /** Đang đọc lại mặt hàng để đổ vào biểu mẫu. */
    dangDoc: Boolean = false,
) {
    val pham = rememberCoroutineScope()
    val boi = LocalContext.current
    val laSua = id > 0

    // Khoá theo `banDau`: tấm sửa mở ra TRƯỚC khi đọc xong mặt hàng, nên lúc dữ
    // liệu về thì mọi ô phải nạp lại. Nhớ suông là biểu mẫu đứng trắng mãi.
    var anh by remember(banDau) { mutableStateOf(banDau?.anh.orEmpty()) }
    var dangTaiAnh by remember { mutableStateOf(false) }

    var ten by remember(banDau) { mutableStateOf(banDau?.ten.orEmpty()) }
    var nhomId by remember(banDau) { mutableStateOf(banDau?.nhomId ?: 0L) }
    var sku by remember(banDau) { mutableStateOf(banDau?.sku.orEmpty()) }
    var maVach by remember(banDau) { mutableStateOf(banDau?.maVach.orEmpty()) }

    var giaBan by remember(banDau) { mutableStateOf(banDau?.giaBan.soTron()) }
    var giaVon by remember(banDau) { mutableStateOf(banDau?.giaVon.soTron()) }
    var vat by remember(banDau) { mutableStateOf(banDau?.vat) }

    var donViId by remember(banDau) { mutableStateOf(banDau?.donViId ?: 0L) }
    var viTriId by remember(banDau) { mutableStateOf(banDau?.viTriId ?: 0L) }
    var chiNhanhIds by remember(banDau) { mutableStateOf(banDau?.chiNhanhIds.orEmpty()) }
    var theTen by remember(banDau) { mutableStateOf(banDau?.the.orEmpty()) }
    // Quy đổi giữ SỐ LƯỢNG DẠNG CHUỖI: người dùng đang gõ "1," thì chưa phải một
    // số hợp lệ, ép sang Double ngay từng phím là xoá mất dấu phẩy vừa gõ.
    var quyDoi by remember(banDau) {
        mutableStateOf(banDau?.quyDoi.orEmpty().map { it.donViId to it.soLuong.soGon() })
    }

    // Biến thể: thuộc tính nào đang tick những giá trị nào. Khoá theo id chứ
    // không giữ đối tượng — danh sách thuộc tính về sau khi tấm đã mở.
    var nhieuBienThe by remember(banDau) { mutableStateOf(false) }
    var tickGiaTri by remember(banDau) { mutableStateOf(mapOf<Long, List<Long>>()) }
    // Giá riêng của từng tổ hợp, khoá là tên tổ hợp. Rỗng = theo giá mặt hàng.
    var giaBienThe by remember(banDau) { mutableStateOf(mapOf<String, String>()) }

    var trangThai by remember(banDau) {
        mutableStateOf(banDau?.trangThai ?: TrangThaiHang.DANG_BAN)
    }
    var inTem by remember(banDau) { mutableStateOf(banDau?.inTem ?: true) }
    var truKho by remember(banDau) { mutableStateOf(banDau?.truKho ?: true) }
    var theoSeri by remember(banDau) { mutableStateOf(banDau?.theoSeri ?: false) }

    var moTaNgan by remember(banDau) { mutableStateOf(banDau?.moTaNgan.orEmpty()) }
    var moTa by remember(banDau) { mutableStateOf(banDau?.moTa.orEmpty()) }
    var metaTitle by remember(banDau) { mutableStateOf(banDau?.metaTitle.orEmpty()) }
    var metaMoTa by remember(banDau) { mutableStateOf(banDau?.metaMoTa.orEmpty()) }

    var dangLuu by remember { mutableStateOf(false) }
    // Câu lỗi của từng ô, chỉ bật lên sau khi bấm Lưu. Tô đỏ ngay từ lúc tấm mới
    // mở là mắng người ta trước khi họ kịp gõ chữ nào.
    var loiTen by remember { mutableStateOf("") }
    var loiNhom by remember { mutableStateOf("") }
    var loiGia by remember { mutableStateOf("") }

    fun luu() {
        loiTen = if (ten.isBlank()) "Nhập tên mặt hàng." else ""
        // Tên toàn ký tự lạ thì slug ra rỗng, mà API bắt buộc có slug — chặn ở
        // đây để người dùng nhận một câu đọc được, thay vì 422 từ máy chủ.
        if (loiTen.isEmpty() && slugTu(ten).isBlank()) {
            loiTen = "Tên phải có ít nhất một chữ hoặc số."
        }
        loiNhom = if (nhomId <= 0) "Chọn nhóm hàng hoá." else ""
        loiGia = if (giaBan.isBlank()) "Nhập giá bán." else ""
        if (loiTen.isNotEmpty() || loiNhom.isNotEmpty() || loiGia.isNotEmpty()) return

        dangLuu = true
        pham.launch {
            val hang = HangMoi(
                anh = anh,
                ten = ten,
                nhomId = nhomId,
                giaBan = giaBan.toDoubleOrNull() ?: 0.0,
                sku = sku,
                maVach = maVach,
                giaVon = giaVon.takeIf { it.isNotBlank() }?.toDoubleOrNull(),
                donViId = donViId,
                viTriId = viTriId,
                vat = vat,
                chiNhanhIds = chiNhanhIds,
                the = theTen,
                nhieuBienThe = if (laSua) (banDau?.nhieuBienThe ?: false) else nhieuBienThe,
                bienThe = if (laSua) {
                    emptyList()
                } else {
                    toHopBienThe(chon.thuocTinh, tickGiaTri).map { b ->
                        b.copy(gia = giaBienThe[b.ten]?.toDoubleOrNull()?.takeIf { it > 0 })
                    }
                },
                quyDoi = quyDoi.mapNotNull { (donVi, so) ->
                    val n = so.replace(',', '.').toDoubleOrNull()
                    if (donVi > 0 && n != null && n > 0) QuyDoi(donVi, n) else null
                },
                trangThai = trangThai,
                inTem = inTem,
                truKho = truKho,
                theoSeri = theoSeri,
                moTaNgan = moTaNgan,
                moTa = moTa,
                metaTitle = metaTitle,
                metaMoTa = metaMoTa,
            )

            val loi = if (laSua) suaMatHang(kho, id, hang) else taoMatHang(kho, hang)
            dangLuu = false
            if (loi == null) {
                onBao(
                    if (laSua) "Đã lưu \"${ten.trim()}\"." else "Đã thêm mặt hàng \"${ten.trim()}\".",
                )
                onXong()
            } else {
                onBao(loi)
            }
        }
    }

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
            Text(
                text = if (laSua) "Sửa hàng hoá" else "Thêm hàng hoá",
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onSurface,
            )

            if (dangDoc) {
                Spacer(Modifier.height(Cach.Chuan))
                DangLay("Đang đọc mặt hàng...")
            }

            // ---- 1. Thông tin chính ------------------------------------
            TenKhoi("Thông tin chính", dau = true)

            ONhapAnh(
                anh = anh,
                dangTai = dangTaiAnh,
                onChon = { uri ->
                    dangTaiAnh = true
                    pham.launch {
                        // Đọc file trên luồng IO: `readBytes` của một tấm ảnh
                        // vài MB đủ để luồng giao diện khựng thấy được.
                        val byte = withContext(Dispatchers.IO) {
                            runCatching {
                                boi.contentResolver.openInputStream(uri)?.use { it.readBytes() }
                            }.getOrNull()
                        }
                        if (byte == null) {
                            dangTaiAnh = false
                            onBao("Không đọc được ảnh vừa chọn.")

                            return@launch
                        }

                        val duoi = duoiTuMime(boi.contentResolver.getType(uri))
                        val kq = taiAnhLen(kho, byte, "anh$duoi")
                        dangTaiAnh = false
                        if (kq.xuoi) anh = kq.url else onBao(kq.loi)
                    }
                },
                onGo = { anh = "" },
            )

            Spacer(Modifier.height(Cach.Rong))

            ONhap(
                nhan = "Tên hàng hoá",
                gia = ten,
                doi = {
                    ten = it
                    if (loiTen.isNotEmpty()) loiTen = ""
                },
                goiY = "Ví dụ: Bút bi Thiên Long",
                batBuoc = true,
                loi = loiTen,
            )

            Spacer(Modifier.height(Cach.Rong))

            NhanO("Nhóm hàng hoá", batBuoc = true, loi = loiNhom)
            when {
                dangTaiChon -> DangLay("Đang lấy danh sách nhóm...")

                chon.nhom.isEmpty() -> Text(
                    // Không có nhóm thì KHÔNG khai được mặt hàng nào cả — nói
                    // thẳng chỗ phải đi làm, đừng để người ta ngồi tìm nút.
                    text = "Chưa lấy được nhóm hàng nào. Khai nhóm hàng hoá bên Shop Admin trước đã.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = mauPhu.chuMo,
                    modifier = Modifier.padding(vertical = Cach.Gan),
                )

                else -> DaiChon {
                    chon.nhom.forEach { n ->
                        ChipChon(
                            chu = "— ".repeat(n.bac) + n.ten,
                            chon = nhomId == n.id,
                            onBam = {
                                nhomId = n.id
                                loiNhom = ""
                            },
                        )
                    }
                }
            }

            Spacer(Modifier.height(Cach.Rong))

            ONhap(
                nhan = "Mã hàng",
                gia = sku,
                doi = { sku = it },
                goiY = if (laSua) "Để trống là giữ mã cũ" else "Bỏ trống để hệ thống tự đặt",
            )

            Spacer(Modifier.height(Cach.Rong))

            if (banDau?.nhieuBienThe == true) {
                // Hàng nhiều biến thể thì mỗi biến thể một mã vạch riêng, biểu
                // mẫu này không sửa được. Nói ra chứ đừng bày một ô sửa xong
                // không ăn — hoặc tệ hơn, ăn nhầm vào một biến thể khác.
                NhanO("Bar/QR Code")
                Text(
                    text = "Mặt hàng có nhiều biến thể, mỗi biến thể một mã vạch riêng — sửa trên Shop Admin.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = mauPhu.chuMo,
                )
            } else {
                ONhap(
                    nhan = "Bar/QR Code",
                    gia = maVach,
                    doi = { maVach = it },
                    goiY = "Mã vạch in trên hàng",
                )
            }

            // ---- 2. Giá ------------------------------------------------
            TenKhoi("Giá")

            ONhapTien(
                nhan = "Giá bán",
                so = giaBan,
                doi = {
                    giaBan = it
                    if (loiGia.isNotEmpty()) loiGia = ""
                },
                batBuoc = true,
                loi = loiGia,
            )

            Spacer(Modifier.height(Cach.Rong))

            ONhapTien(
                nhan = "Giá vốn",
                so = giaVon,
                doi = { giaVon = it },
                goiY = "Bỏ trống nếu chưa biết",
            )

            Spacer(Modifier.height(Cach.Rong))

            NhanO("% VAT")
            DaiChon {
                MUC_THUE.forEach { m ->
                    ChipChon(
                        chu = m?.let { chuVAT(it) } ?: "Theo nhóm hàng",
                        chon = vat == m,
                        onBam = { vat = m },
                    )
                }
            }

            // Giá sau thuế TỰ TÍNH, không phải ô nhập — y như bên web. Chỉ hiện
            // khi tính được: chưa chọn mức thuế thì mức thật nằm ở nhóm hàng mà
            // app chưa biết, mà hai mã KCT/KKKNT thì không có phần trăm nào.
            giaSauThue(giaBan.toDoubleOrNull() ?: 0.0, vat)?.let { sau ->
                Spacer(Modifier.height(Cach.Vua))
                Text(
                    text = "Giá sau thuế: ${tienVN(sau)}",
                    style = MaterialTheme.typography.bodySmall,
                    color = mauPhu.chuMo,
                )
            }

            // ---- 3. Kho & bán hàng -------------------------------------
            TenKhoi("Kho & bán hàng")

            if (chon.donVi.isEmpty() && chon.viTri.isEmpty() && chon.chiNhanh.isEmpty() && chon.the.isEmpty()) {
                Text(
                    // Bốn ô này đều đòi quyền RIÊNG bên API. Không có quyền thì
                    // cả khối trống trơn — nói một câu để người dùng biết đây là
                    // chuyện quyền, chứ không phải app dựng hụt.
                    text = if (dangTaiChon) {
                        "Đang lấy danh sách..."
                    } else {
                        "Chưa khai đơn vị tính, vị trí, chi nhánh hay thẻ nào — hoặc tài khoản không có quyền xem."
                    },
                    style = MaterialTheme.typography.bodyMedium,
                    color = mauPhu.chuMo,
                )
            }

            if (chon.donVi.isNotEmpty()) {
                NhanO("Đơn vị tính")
                DaiChon {
                    ChipChon("Chưa khai", donViId == 0L, onBam = { donViId = 0 })
                    chon.donVi.forEach { d ->
                        ChipChon(d.nhan, donViId == d.id, onBam = { donViId = d.id })
                    }
                }
                Spacer(Modifier.height(Cach.Rong))
            }

            // Quy đổi chỉ có nghĩa khi mặt hàng đã có đơn vị tính chính: "1 Thùng
            // = 24" thì 24 CÁI GÌ? Chưa chọn đơn vị thì nói ra chỗ thiếu.
            if (chon.donVi.isNotEmpty()) {
                NhanO("Quy đổi đơn vị")
                if (donViId <= 0) {
                    Text(
                        text = "Chọn đơn vị tính ở trên trước, rồi mới khai được quy đổi.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuMo,
                    )
                } else {
                    val tenChinh = chon.donVi.firstOrNull { it.id == donViId }?.ten.orEmpty()

                    quyDoi.forEachIndexed { i, (donVi, so) ->
                        DongQuyDoi(
                            tenDonVi = chon.donVi.firstOrNull { it.id == donVi }?.ten.orEmpty(),
                            tenChinh = tenChinh,
                            so = so,
                            onDoiSo = { moi ->
                                quyDoi = quyDoi.toMutableList().also { ds -> ds[i] = donVi to moi }
                            },
                            onGo = { quyDoi = quyDoi.filterIndexed { j, _ -> j != i } },
                        )
                        Spacer(Modifier.height(Cach.Vua))
                    }

                    // Chỉ bày những đơn vị CHƯA dùng, và bỏ luôn đơn vị chính:
                    // API chặn cả hai ("mỗi đơn vị chỉ khai một lần", "không khai
                    // quy đổi cho chính đơn vị tính"), bày ra chỉ tổ cho bấm rồi
                    // nhận lỗi.
                    val conLai = chon.donVi.filter { d ->
                        d.id != donViId && quyDoi.none { it.first == d.id }
                    }
                    if (conLai.isEmpty()) {
                        Text(
                            text = "Đã khai quy đổi cho mọi đơn vị còn lại.",
                            style = MaterialTheme.typography.bodySmall,
                            color = mauPhu.chuMo,
                        )
                    } else {
                        Text(
                            text = "Thêm dòng quy đổi:",
                            style = MaterialTheme.typography.bodySmall,
                            color = mauPhu.chuMo,
                            modifier = Modifier.padding(bottom = Cach.Gan),
                        )
                        DaiChon {
                            conLai.forEach { d ->
                                ChipChon(
                                    chu = "+ ${d.ten}",
                                    chon = false,
                                    onBam = { quyDoi = quyDoi + (d.id to "") },
                                )
                            }
                        }
                    }
                }
                Spacer(Modifier.height(Cach.Rong))
            }

            if (chon.viTri.isNotEmpty()) {
                NhanO("Vị trí")
                DaiChon {
                    ChipChon("Chưa gán", viTriId == 0L, onBam = { viTriId = 0 })
                    chon.viTri.forEach { v ->
                        ChipChon(v.nhan, viTriId == v.id, onBam = { viTriId = v.id })
                    }
                }
                Spacer(Modifier.height(Cach.Rong))
            }

            if (chon.chiNhanh.isNotEmpty()) {
                NhanO("Chi nhánh")
                DaiChon {
                    // Không chọn chi nhánh nào = bán ở MỌI chi nhánh, đúng quy
                    // ước bảng product_shops. Nói thẳng ra chứ không để trống.
                    ChipChon(
                        chu = "Mọi chi nhánh",
                        chon = chiNhanhIds.isEmpty(),
                        onBam = { chiNhanhIds = emptyList() },
                    )
                    chon.chiNhanh.forEach { c ->
                        ChipChon(
                            chu = c.ten,
                            chon = c.id in chiNhanhIds,
                            onBam = {
                                chiNhanhIds = if (c.id in chiNhanhIds) {
                                    chiNhanhIds - c.id
                                } else {
                                    chiNhanhIds + c.id
                                }
                            },
                        )
                    }
                }
                Spacer(Modifier.height(Cach.Rong))
            }

            if (chon.the.isNotEmpty()) {
                NhanO("Thẻ hàng hoá")
                DaiChon {
                    chon.the.forEach { t ->
                        ChipChon(
                            chu = t.ten,
                            chon = t.ten in theTen,
                            onBam = {
                                theTen = if (t.ten in theTen) theTen - t.ten else theTen + t.ten
                            },
                        )
                    }
                }
            }

            // ---- 4. Tuỳ chọn -------------------------------------------
            TenKhoi("Tuỳ chọn")

            NhanO("Trạng thái")
            DaiChon {
                TrangThaiHang.entries.forEach { t ->
                    ChipChon(t.nhan, trangThai == t, onBam = { trangThai = t })
                }
            }

            Spacer(Modifier.height(Cach.Vua))

            CongTac(
                nhan = "In tem",
                bat = inTem,
                onDoi = { inTem = it },
                phu = "Có in tem nhãn cho mặt hàng này không.",
            )
            CongTac(
                nhan = "Trừ kho khi bán",
                bat = truKho,
                onDoi = { truKho = it },
                phu = "Tắt cho hàng dịch vụ: bán bao nhiêu cũng không đụng vào kho.",
            )
            CongTac(
                nhan = "Quản lý theo số seri",
                bat = theoSeri,
                onDoi = { theoSeri = it },
                phu = "Dành cho hàng có IMEI hoặc số máy riêng từng cái.",
            )

            // ---- 5. Biến thể -------------------------------------------
            if (chon.thuocTinh.isNotEmpty() || banDau?.nhieuBienThe == true) {
                TenKhoi("Biến thể")

                when {
                    // SỬA thì khối này CHỈ ĐỌC, và đó là chủ ý chứ không phải
                    // làm dở: máy chủ đồng bộ biến thể theo danh sách gửi lên,
                    // mà app dựng lại tổ hợp thì mọi dòng đều mang id 0 — tức
                    // xoá sạch biến thể cũ rồi tạo lại. Tồn kho, mã vạch và sổ
                    // kho của chúng đi theo luôn.
                    laSua && banDau?.nhieuBienThe == true -> Text(
                        text = "Mặt hàng đang bán theo biến thể. Thêm, bớt hay đổi giá từng biến thể làm trên Shop Admin — sửa ở đây sẽ xoá mất tồn kho của chúng.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuMo,
                    )

                    laSua -> Text(
                        text = "Mặt hàng đang là hàng đơn. Chuyển sang bán theo biến thể làm trên Shop Admin.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuMo,
                    )

                    else -> {
                        CongTac(
                            nhan = "Bán theo biến thể",
                            bat = nhieuBienThe,
                            onDoi = { nhieuBienThe = it },
                            phu = "Cùng một món nhưng nhiều dung lượng, màu, size...",
                        )

                        if (nhieuBienThe) {
                            Spacer(Modifier.height(Cach.Vua))

                            // Mỗi thuộc tính là MỘT DÒNG GẬP LẠI, không đổ hết
                            // giá trị ra. Một cửa hàng điện máy có bảy tám
                            // thuộc tính, mỗi cái cả chục giá trị — đổ hết là
                            // cuộn mười màn mới tới nút Lưu, trong khi khai một
                            // mặt hàng thường chỉ đụng tới hai ba thuộc tính.
                            chon.thuocTinh.forEach { tt ->
                                val daTick = tickGiaTri[tt.id].orEmpty()
                                MucGap(
                                    nhan = tt.ten,
                                    // Tóm tắt ngay trên hàng tiêu đề: gập lại mà
                                    // không thấy mình đã tick gì thì phải mở
                                    // từng cái ra dò lại.
                                    tomTat = tt.giaTri
                                        .filter { it.id in daTick }
                                        .joinToString(", ") { it.ten },
                                ) {
                                    DaiChon {
                                        tt.giaTri.forEach { g ->
                                            val dangTick = g.id in daTick
                                            ChipChon(
                                                chu = g.ten,
                                                chon = dangTick,
                                                onBam = {
                                                    tickGiaTri = tickGiaTri + (
                                                        tt.id to if (dangTick) {
                                                            daTick - g.id
                                                        } else {
                                                            daTick + g.id
                                                        }
                                                        )
                                                },
                                            )
                                        }
                                    }
                                }
                                HorizontalDivider(thickness = 1.dp, color = mauPhu.vienNhat)
                            }

                            Spacer(Modifier.height(Cach.Rong))

                            val toHop = toHopBienThe(chon.thuocTinh, tickGiaTri)
                            if (toHop.isEmpty()) {
                                Text(
                                    text = "Tick giá trị ở trên để sinh ra các biến thể.",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = mauPhu.chuMo,
                                )
                            } else {
                                // Bày SẴN danh sách tổ hợp trước khi lưu: tick
                                // ba thuộc tính là ra hàng chục dòng, mà đếm
                                // trong đầu thì không ai đếm nổi.
                                // Danh sách tổ hợp cũng gập: tick ba thuộc
                                // tính là ra vài chục dòng, mỗi dòng một ô giá.
                                // Phần lớn lượt khai để giá theo mặt hàng cha
                                // nên chẳng ai mở ra sửa.
                                MucGap(
                                    nhan = "${toHop.size} biến thể sẽ được tạo",
                                    tomTat = if (giaBienThe.any { it.value.isNotBlank() }) {
                                        "Có biến thể để giá riêng"
                                    } else {
                                        "Tất cả theo giá chung"
                                    },
                                ) {
                                    toHop.forEach { b ->
                                        Row(
                                            verticalAlignment = Alignment.CenterVertically,
                                            modifier = Modifier.padding(bottom = Cach.Vua),
                                        ) {
                                            Text(
                                                text = b.ten,
                                                style = MaterialTheme.typography.bodyMedium,
                                                color = MaterialTheme.colorScheme.onSurface,
                                                modifier = Modifier.weight(1f),
                                            )
                                            Spacer(Modifier.width(Cach.Vua))
                                            ONhapTien(
                                                nhan = "",
                                                so = giaBienThe[b.ten].orEmpty(),
                                                doi = { giaBienThe = giaBienThe + (b.ten to it) },
                                                goiY = "Theo giá chung",
                                                modifier = Modifier.width(170.dp),
                                            )
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // ---- 6. Mô tả ----------------------------------------------
            TenKhoi("Mô tả")

            ONhap(
                nhan = "Mô tả ngắn",
                gia = moTaNgan,
                doi = { moTaNgan = it },
                goiY = "Một câu giới thiệu",
            )

            Spacer(Modifier.height(Cach.Rong))

            ONhap(
                nhan = "Mô tả chi tiết",
                gia = moTa,
                doi = { moTa = it },
                goiY = "Mô tả đầy đủ của mặt hàng",
                nhieuDong = true,
            )

            Spacer(Modifier.height(Cach.Rong))

            ONhap(
                nhan = "Meta title",
                gia = metaTitle,
                doi = { metaTitle = it },
                goiY = "Tiêu đề hiện trên Google",
            )

            Spacer(Modifier.height(Cach.Rong))

            ONhap(
                nhan = "Meta description",
                gia = metaMoTa,
                doi = { metaMoTa = it },
                goiY = "Đoạn tóm tắt hiện trên Google",
                nhieuDong = true,
            )

            Spacer(Modifier.height(Cach.Khoi))

            Text(
                text = if (laSua) {
                    "Biến thể của mặt hàng đã có thì sửa trên Shop Admin."
                } else {
                    "Khai xong bấm Lưu; mặt hàng mới nằm ngay đầu danh sách."
                },
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuMo,
            )

            Spacer(Modifier.height(Cach.Khoi))

            HangNut {
                NutNguyHiem(
                    chu = "Huỷ",
                    onBam = onDong,
                    modifier = Modifier.weight(1f),
                    moKhoa = !dangLuu,
                    cao = CaoCham.NutChinh,
                )
                NutChinh(
                    chu = "Lưu",
                    onBam = { luu() },
                    modifier = Modifier.weight(1f),
                    moKhoa = !dangDoc,
                    dangChay = dangLuu,
                )
            }

            Spacer(Modifier.height(Cach.Lon))
        }
    }
}

/**
 * Một dòng quy đổi: "1 Thùng = [24] Cái", kèm nút gỡ dòng.
 *
 * Con số để ô nhập RIÊNG chứ không nhét chung vào chuỗi: nó là thứ duy nhất
 * trên dòng người dùng sửa được, mà cũng là thứ sai một chữ số là lệch cả kho.
 */
@Composable
private fun DongQuyDoi(
    tenDonVi: String,
    tenChinh: String,
    so: String,
    onDoiSo: (String) -> Unit,
    onGo: () -> Unit,
) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(
            text = "1 $tenDonVi =",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurface,
        )

        Spacer(Modifier.width(Cach.Gan))

        ONhap(
            nhan = "",
            gia = so,
            // Chấm và phẩy đều cho gõ: "0.5" hay "0,5" đều là cách người Việt
            // viết nửa đơn vị, chặn một trong hai là bắt người ta đoán.
            doi = { onDoiSo(it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(10)) },
            goiY = "0",
            kieuBanPhim = KeyboardType.Number,
            duoi = tenChinh,
            modifier = Modifier.weight(1f),
        )

        Spacer(Modifier.width(Cach.Gan))

        Icon(
            imageVector = Lucide.X,
            contentDescription = "Gỡ dòng quy đổi",
            tint = mauPhu.do_,
            modifier = Modifier
                .clip(Bo.Tron)
                .clickable(onClick = onGo)
                .padding(Cach.Gan)
                .size(Cach.Chuan),
        )
    }
}

/**
 * Số quy đổi thành chuỗi gọn: 24.0 -> "24", 0.5 -> "0.5".
 *
 * Bỏ đuôi ".0" thừa — "1 Thùng = 24.0 Cái" đọc như máy nói, mà phần lớn quy đổi
 * đều là số nguyên.
 */
private fun Double.soGon(): String =
    if (this == toLong().toDouble()) toLong().toString() else toString()

/**
 * Số tiền thành chuỗi chữ số trần cho ô nhập: 25000.0 -> "25000".
 *
 * Bỏ hẳn phần thập phân: ô tiền chỉ nhận chữ số, mà tiền Việt cũng không ai gõ
 * hào. null hoặc 0 trả rỗng để ô hiện chữ gợi ý thay vì một số 0 chình ình —
 * riêng chỗ giá vốn thì "rỗng" còn mang đúng nghĩa CHƯA KHAI.
 */
private fun Double?.soTron(): String =
    if (this == null || this <= 0.0) "" else toLong().toString()

/**
 * Tên một khối của biểu mẫu, có vạch ngăn phía trên.
 *
 * Mười lăm ô xếp thẳng một mạch thì cuộn mãi không biết còn bao xa; chia khối có
 * tên là mỗi lần cuộn tới một cái vạch, người dùng biết mình vừa xong một phần.
 */
@Composable
private fun TenKhoi(chu: String, dau: Boolean = false) {
    Spacer(Modifier.height(Cach.Khoi))
    if (!dau) {
        HorizontalDivider(thickness = 1.dp, color = mauPhu.vienNhat)
        Spacer(Modifier.height(Cach.Khoi))
    }
    Text(
        text = chu.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        fontWeight = FontWeight.Bold,
        color = MaterialTheme.colorScheme.primary,
    )
    Spacer(Modifier.height(Cach.Chuan))
}

/** Nhãn của một ô chọn bằng chip — cùng dáng với nhãn của `ONhap`. */
@Composable
private fun NhanO(nhan: String, batBuoc: Boolean = false, loi: String = "") {
    Row(modifier = Modifier.padding(bottom = Cach.Gan)) {
        Text(
            text = nhan,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.SemiBold,
            color = mauPhu.chuMo,
        )
        if (batBuoc) {
            Text(
                text = " *",
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = mauPhu.do_,
            )
        }
        if (loi.isNotBlank()) {
            Text(
                text = " · $loi",
                style = MaterialTheme.typography.labelMedium,
                color = mauPhu.do_,
            )
        }
    }
}

/** Dải chip xuống dòng được. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DaiChon(noiDung: @Composable () -> Unit) {
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(Cach.Gan),
        verticalArrangement = Arrangement.spacedBy(Cach.Gan),
    ) {
        noiDung()
    }
}

@Composable
private fun DangLay(chu: String) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier.padding(vertical = Cach.Gan),
    ) {
        CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
        Spacer(Modifier.width(Cach.Vua))
        Text(chu, style = MaterialTheme.typography.bodyMedium, color = mauPhu.chuMo)
    }
}
