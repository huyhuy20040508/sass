package com.selliotech.app

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
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
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.composables.icons.lucide.Download
import com.composables.icons.lucide.FileText
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.TriangleAlert
import com.composables.icons.lucide.Upload
import com.selliotech.app.ui.NutChinh
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.mauPhu

/**
 * Tấm NÂNG CAO — ba việc với cả TỆP, gom một chỗ.
 *
 * Bên web ba việc này nằm trong một menu thả xuống cạnh nút Tạo mới. Ở đây cũng
 * gom lại chứ không rải ra thanh công cụ: chúng hiếm dùng hơn hẳn tìm và lọc,
 * mà mỗi cái nằm riêng một nút thì thanh công cụ đầy ứ những thứ mỗi tháng bấm
 * một lần.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TamNangCao(
    dangXuat: Boolean,
    onXuat: () -> Unit,
    onNhap: () -> Unit,
    onTaiMau: () -> Unit,
    onDong: () -> Unit,
) {
    ModalBottomSheet(
        onDismissRequest = onDong,
        sheetState = rememberModalBottomSheetState(),
        shape = Bo.Tam,
        containerColor = MaterialTheme.colorScheme.surface,
        dragHandle = { BottomSheetDefaults.DragHandle(color = mauPhu.vien) },
    ) {
        Column(modifier = Modifier.fillMaxWidth().padding(horizontal = Cach.Vua)) {
            Text(
                text = "Nâng cao",
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onSurface,
                modifier = Modifier.padding(start = Cach.Gan, bottom = Cach.Vua),
            )

            DongViec(
                bieuTuong = Lucide.Download,
                nhan = if (dangXuat) "Đang xuất..." else "Xuất file (CSV)",
                phu = "Xuất đúng danh sách đang lọc, mở được bằng Excel.",
                moKhoa = !dangXuat,
                onBam = onXuat,
            )
            DongViec(
                bieuTuong = Lucide.Upload,
                nhan = "Nhập file",
                phu = "Mỗi dòng một mặt hàng, theo đúng file mẫu.",
                onBam = onNhap,
            )
            DongViec(
                bieuTuong = Lucide.FileText,
                nhan = "Tải file mẫu",
                // Để mẫu NGAY ĐÂY chứ không giấu trong màn nhập: người ta cần nó
                // TRƯỚC khi đi nhập, đúng lý do bản web cũng bày nó ra ngoài.
                phu = "Lấy trước rồi điền, khỏi đoán tên cột.",
                onBam = onTaiMau,
            )

            Spacer(Modifier.height(Cach.Lon))
        }
    }
}

/** Một dòng việc trong tấm nâng cao: icon, tên việc, và một câu nói rõ nó làm gì. */
@Composable
private fun DongViec(
    bieuTuong: ImageVector,
    nhan: String,
    phu: String,
    onBam: () -> Unit,
    moKhoa: Boolean = true,
) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier
            .fillMaxWidth()
            .clip(Bo.O)
            .clickable(enabled = moKhoa, onClick = onBam)
            .padding(horizontal = Cach.Gan, vertical = Cach.Vua),
    ) {
        Icon(
            imageVector = bieuTuong,
            contentDescription = null,
            tint = if (moKhoa) mauPhu.chuThuong else mauPhu.chuMo,
            modifier = Modifier.size(Cach.Rong),
        )

        Spacer(Modifier.width(Cach.Chuan))

        Column(Modifier.weight(1f)) {
            Text(
                text = nhan,
                style = MaterialTheme.typography.bodyLarge,
                color = if (moKhoa) MaterialTheme.colorScheme.onSurface else mauPhu.chuMo,
            )
            Spacer(Modifier.height(2.dp))
            Text(
                text = phu,
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuMo,
            )
        }
    }
}

/** Kết quả một lượt nhập tệp: đếm được bao nhiêu dòng vào, và những dòng hỏng. */
data class KetQuaNhap(val thanhCong: Int, val loi: List<String>)

/**
 * Tấm BÁO KẾT QUẢ NHẬP.
 *
 * Liệt kê TỪNG DÒNG SAI kèm lý do, không phải chỉ đếm số dòng hỏng. Với tệp vài
 * trăm dòng, "12 dòng lỗi" không cho người dùng cách nào lần ra chỗ phải sửa —
 * đúng lý do bản web cũng bày ra cả danh sách.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TamKetQuaNhap(kq: KetQuaNhap, onDong: () -> Unit) {
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
                text = "Kết quả nhập file",
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onSurface,
            )

            Spacer(Modifier.height(Cach.Chuan))

            Text(
                text = if (kq.thanhCong > 0) {
                    "Đã thêm ${kq.thanhCong} mặt hàng."
                } else {
                    "Không thêm được mặt hàng nào."
                },
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.Medium,
                color = if (kq.thanhCong > 0) mauPhu.luc else mauPhu.chuThuong,
            )

            if (kq.loi.isNotEmpty()) {
                Spacer(Modifier.height(Cach.Rong))
                HorizontalDivider(thickness = 1.dp, color = mauPhu.vienNhat)
                Spacer(Modifier.height(Cach.Rong))

                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(
                        imageVector = Lucide.TriangleAlert,
                        contentDescription = null,
                        tint = mauPhu.cam,
                        modifier = Modifier.size(Cach.Chuan),
                    )
                    Spacer(Modifier.width(Cach.Gan))
                    Text(
                        text = "${kq.loi.size} dòng không vào được",
                        style = MaterialTheme.typography.bodyLarge,
                        fontWeight = FontWeight.Medium,
                        color = mauPhu.cam,
                    )
                }

                Spacer(Modifier.height(Cach.Vua))

                kq.loi.forEach { l ->
                    Text(
                        text = "• $l",
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuThuong,
                        modifier = Modifier.padding(bottom = Cach.Gan),
                    )
                }
            }

            Spacer(Modifier.height(Cach.Khoi))

            NutChinh(chu = "Đóng", onBam = onDong)

            Spacer(Modifier.height(Cach.Lon))
        }
    }
}
