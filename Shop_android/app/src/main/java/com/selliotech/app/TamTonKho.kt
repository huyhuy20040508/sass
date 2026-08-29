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
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.Package
import com.selliotech.app.ui.ChipChon
import com.selliotech.app.ui.DongChon
import com.selliotech.app.ui.HangNut
import com.selliotech.app.ui.HangThongTin
import com.selliotech.app.ui.Huy
import com.selliotech.app.ui.NutChinh
import com.selliotech.app.ui.NutNguyHiem
import com.selliotech.app.ui.Sac
import com.selliotech.app.ui.VachNgan
import com.selliotech.app.ui.ngayGon
import com.selliotech.app.ui.tienVN
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.mauPhu

// =====================================================================
//  BA TẤM TRƯỢT CỦA MÀN HÀNG HOÁ
//
//  Trên web ba việc này là ba cụm nút nằm sẵn trên thanh công cụ. Bề
//  ngang điện thoại không đủ cho một thanh như thế, mà nhét chúng thành
//  ba dòng xếp chồng thì danh sách — thứ người ta mở màn này để xem —
//  bị đẩy xuống quá nửa màn hình. Nên chúng lui vào tấm trượt, còn trên
//  màn chỉ để lại một nút lọc có con số và một nút sắp xếp.
// =====================================================================

/**
 * Tấm LỌC.
 *
 * Đổi là ăn NGAY, không có nút "Áp dụng". Đúng quy tắc trang danh sách của cả
 * hệ thống: bấm một điều kiện rồi phải bấm thêm một nút nữa mới thấy kết quả là
 * hai lần chạm cho một ý định, và người dùng luôn quên lần thứ hai.
 *
 * Đổi lại tấm này KHÔNG che hết màn: nó cao vừa phải nên vài dòng đầu của danh
 * sách vẫn ló ra phía trên, đổi một chip là thấy danh sách phía sau đổi theo.
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun TamLocTon(
    loc: LocTon,
    /** Số dòng đang khớp — nhảy theo từng cú bấm chip. */
    tong: Long,
    dangDem: Boolean,
    /** null = chưa lấy được (chưa gọi, hoặc gọi hỏng). Rỗng = thật sự chưa khai nhóm nào. */
    dsNhom: List<DanhMuc>?,
    dangTaiNhom: Boolean,
    onDoi: (LocTon) -> Unit,
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
            // Tiêu đề mang theo số dòng đang khớp: tấm này che mất danh sách
            // phía sau, không có con số ở đây thì bấm xong phải đóng tấm lại mới
            // biết mình vừa lọc ra cái gì.
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = "Lọc tồn kho",
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

            MucLoc("Tồn kho") {
                ChipChon("Tất cả", loc.ton.isBlank(), onBam = { onDoi(loc.copy(ton = "")) })
                ChipChon("Còn hàng", loc.ton == "in", onBam = { onDoi(loc.copy(ton = "in")) })
                ChipChon("Sắp hết", loc.ton == "low", onBam = { onDoi(loc.copy(ton = "low")) })
                ChipChon("Hết hàng", loc.ton == "out", onBam = { onDoi(loc.copy(ton = "out")) })
            }

            // "Đang bán" ở đây là cờ của BIẾN THỂ, đúng thứ API lọc được. Một
            // biến thể đang bật mà mặt hàng cha bị ẩn thì dòng đó vẫn lọt vào
            // nhóm "Đang bán" nhưng mang huy hiệu "Ngừng bán" — huy hiệu nói
            // đúng hơn bộ lọc, vì nó xét cả hai cờ.
            MucLoc("Trạng thái") {
                ChipChon("Tất cả", loc.dangBan == null, onBam = { onDoi(loc.copy(dangBan = null)) })
                ChipChon("Đang bán", loc.dangBan == true, onBam = { onDoi(loc.copy(dangBan = true)) })
                ChipChon("Ngừng bán", loc.dangBan == false, onBam = { onDoi(loc.copy(dangBan = false)) })
            }

            // Giá vốn là bộ lọc của người đi soát SỐ LIỆU, không phải của người
            // soạn hàng — nhưng nó đứng cùng chỗ vì màn Tổng quan mở thẳng vào
            // đây bằng dòng "Chưa khai giá vốn".
            MucLoc("Giá vốn") {
                ChipChon("Tất cả", loc.giaVon.isBlank(), onBam = { onDoi(loc.copy(giaVon = "")) })
                ChipChon("Chưa khai", loc.giaVon == "missing", onBam = { onDoi(loc.copy(giaVon = "missing")) })
                ChipChon("Đã khai", loc.giaVon == "set", onBam = { onDoi(loc.copy(giaVon = "set")) })
            }

            NhanMuc("Nhóm hàng hoá")

            when {
                dangTaiNhom -> Row(
                    verticalAlignment = Alignment.CenterVertically,
                    modifier = Modifier.padding(vertical = Cach.Gan),
                ) {
                    CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                    Spacer(Modifier.width(Cach.Vua))
                    Text(
                        text = "Đang lấy danh sách nhóm...",
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuMo,
                    )
                }

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

                dsNhom.isEmpty() -> Text(
                    text = "Chưa khai nhóm hàng nào.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = mauPhu.chuMo,
                    modifier = Modifier.padding(vertical = Cach.Gan),
                )

                else -> FlowRow(
                    horizontalArrangement = Arrangement.spacedBy(Cach.Gan),
                    verticalArrangement = Arrangement.spacedBy(Cach.Gan),
                ) {
                    ChipChon(
                        chu = "Tất cả nhóm",
                        chon = loc.danhMucId == 0L,
                        onBam = { onDoi(loc.copy(danhMucId = 0, danhMucTen = "")) },
                    )
                    dsNhom.forEach { n ->
                        // Dấu gạch đầu dòng theo bậc: nhóm con phải nhìn ra là
                        // con của ai, không thì "Nước ngọt" với "Đồ uống" trông
                        // như hai nhóm ngang hàng.
                        ChipChon(
                            chu = "— ".repeat(n.bac) + n.ten,
                            chon = loc.danhMucId == n.id,
                            onBam = { onDoi(loc.copy(danhMucId = n.id, danhMucTen = n.ten)) },
                        )
                    }
                }
            }

            Spacer(Modifier.height(Cach.Khoi))

            HangNut {
                if (loc.coLoc) {
                    NutNguyHiem(
                        chu = "Xoá lọc",
                        // Giữ nguyên kiểu sắp xếp: người ta bấm "xoá lọc" để xem
                        // lại cả kho, không phải để đảo thứ tự đang xem.
                        onBam = { onDoi(LocTon(xep = loc.xep)) },
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

/** Một mục lọc: nhãn nhỏ rồi tới dải chip xuống dòng được. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun MucLoc(nhan: String, chip: @Composable () -> Unit) {
    NhanMuc(nhan)
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(Cach.Gan),
        verticalArrangement = Arrangement.spacedBy(Cach.Gan),
    ) {
        chip()
    }
    Spacer(Modifier.height(Cach.Khoi))
}

@Composable
private fun NhanMuc(nhan: String) {
    Text(
        text = nhan,
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.SemiBold,
        color = mauPhu.chuMo,
        modifier = Modifier.padding(bottom = Cach.Vua),
    )
}

/**
 * Tấm SẮP XẾP.
 *
 * Chọn một cái là tấm tự đóng luôn: đây là lựa chọn một-trong-nhiều, chọn xong
 * là hết việc, bắt bấm thêm nút "Xong" chỉ để đóng một tấm đã hết tác dụng.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TamXepTon(dangChon: XepTon, onChon: (XepTon) -> Unit, onDong: () -> Unit) {
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

            XepTon.entries.forEach { x ->
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
 * đọc /admin/inventory thay vì /products.
 *
 * KHÔNG có nút Sửa hay Xoá. Bày một cái nút rồi báo "chưa làm được" là cách
 * nhanh nhất để người dùng thôi tin mấy cái nút còn lại.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TamChiTietTon(d: DongTon, onDong: () -> Unit) {
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
            Text(
                text = d.ten,
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onSurface,
            )

            if (d.tenBienThe.isNotBlank()) {
                Spacer(Modifier.height(Cach.Sat))
                Text(
                    text = d.tenBienThe,
                    style = MaterialTheme.typography.bodyMedium,
                    color = mauPhu.chuThuong,
                )
            }

            Spacer(Modifier.height(Cach.Vua))

            Row(horizontalArrangement = Arrangement.spacedBy(Cach.Gan)) {
                val muc = d.mucTon()
                Huy(
                    chu = muc.nhan,
                    sac = when (muc) {
                        MucTon.HET -> Sac.DO
                        MucTon.SAP_HET -> Sac.CAM
                        MucTon.CON -> Sac.LUC
                    },
                )
                Huy(
                    chu = if (d.dangBan) "Đang bán" else "Ngừng bán",
                    sac = if (d.dangBan) Sac.LAM else Sac.XAM,
                )
            }

            Spacer(Modifier.height(Cach.Khoi))

            // Con số tồn làm nhân vật chính: mở tấm này ra là để hỏi "còn bao
            // nhiêu", mấy dòng dưới chỉ là phần trả lời thêm.
            Text(
                text = nhanTon(d),
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
                color = mauTon(d.mucTon()),
            )

            Spacer(Modifier.height(Cach.Khoi))

            HangThongTin("Mã hàng", d.sku.ifBlank { "—" })
            VachNgan()
            HangThongTin("Nhóm hàng", d.danhMuc.ifBlank { "Chưa xếp nhóm" })
            VachNgan()
            HangThongTin("Đơn vị tính", d.donVi.ifBlank { "Chưa khai" })
            VachNgan()
            HangThongTin("Giá bán", tienVN(d.gia))
            VachNgan()
            // Chưa khai giá vốn thì nói thẳng bằng chữ và tô cam, không ghi
            // "0 ₫": số 0 đọc như đã khai và hàng này lãi bằng cả giá bán.
            HangThongTin(
                nhan = "Giá vốn",
                gia = d.giaVon?.let { tienVN(it) } ?: "Chưa khai",
                mauGia = if (d.giaVon == null) mauPhu.cam else null,
            )
            VachNgan()
            HangThongTin("Giá trị tồn", tienVN(d.giaTriKho))
            VachNgan()
            HangThongTin("Phát sinh cuối", ngayGon(d.lanCuoi).ifBlank { "Chưa từng" })

            Spacer(Modifier.height(Cach.Khoi))

            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    imageVector = Lucide.Package,
                    contentDescription = null,
                    tint = mauPhu.chuMo,
                    modifier = Modifier.size(Cach.Chuan),
                )
                Spacer(Modifier.width(Cach.Gan))
                Text(
                    text = "Sửa mặt hàng, đổi giá và chỉnh kho làm trên Shop Admin.",
                    style = MaterialTheme.typography.bodySmall,
                    color = mauPhu.chuMo,
                )
            }

            Spacer(Modifier.height(Cach.Lon))
        }
    }
}
