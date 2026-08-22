package com.selliotech.app

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.BottomSheetDefaults
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.mauPhu

/** Một module trong tấm ứng dụng. `mau` chỉ để nhận mặt, không mang nghĩa trạng thái. */
data class OUngDung(
    val ma: String,
    val ten: String,
    val bieuTuong: ImageVector,
    val mau: Color,
)

/** Số ô một hàng. Ba là vừa: bốn thì tên module dài phải cắt cụt. */
private const val O_MOI_HANG = 3

/**
 * Tấm ứng dụng — cửa mở ra tất cả module của khu.
 *
 * VÌ SAO CÓ TẤM NÀY. Thanh nổi chỉ đựng được ba bốn chỗ; số module của một
 * phần mềm bán hàng thì còn dài ra mãi — kho, khách hàng, nhà cung cấp, sổ
 * quỹ, báo cáo. Nhồi hết vào thanh là mỗi ô còn bằng đầu đũa. Nên thanh giữ
 * mấy chỗ đi lại hằng ngày, còn tấm này giữ TẤT CẢ.
 *
 * Ba module đang có cũng nằm trong tấm chứ không bị loại vì "đã có trên thanh
 * rồi". Tấm này là danh sách đầy đủ, giống ngăn ứng dụng của điện thoại: app
 * nằm dưới dock vẫn có mặt trong ngăn. Loại chúng ra là người dùng mở tấm lên
 * rồi tự hỏi sao thiếu.
 *
 * Chỉ bày module ĐÃ CHẠY ĐƯỢC. Không có ô xám "sắp có" — bấm vào không ra gì
 * là cách nhanh nhất để người dùng hết tin vào app. Dòng chữ dưới chân nói rõ
 * là còn module đang dựng, thế là đủ.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TamUngDung(
    tenKhu: String,
    dsUngDung: List<OUngDung>,
    maDangChon: String,
    onChon: (String) -> Unit,
    onDong: () -> Unit,
) {
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
                .padding(horizontal = Cach.Rong),
        ) {
            Text(
                text = "Ứng dụng",
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onSurface,
            )
            Text(
                text = "Khu $tenKhu",
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuMo,
            )

            Spacer(Modifier.height(Cach.Rong))

            // Chia hàng bằng chunked chứ không dùng lưới cuộn: tấm này vốn đã
            // cuộn được, lồng thêm một vùng cuộn nữa vào trong là hai thứ giành
            // nhau ngón tay.
            dsUngDung.chunked(O_MOI_HANG).forEach { hang ->
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(Cach.Vua),
                ) {
                    hang.forEach { o ->
                        OModule(
                            o = o,
                            dangChon = o.ma == maDangChon,
                            onBam = { onChon(o.ma) },
                            modifier = Modifier.weight(1f),
                        )
                    }
                    // Hàng cuối thiếu ô thì chèn chỗ trống cho các ô còn lại
                    // giữ đúng bề ngang, đừng để chúng giãn ra gấp rưỡi.
                    repeat(O_MOI_HANG - hang.size) {
                        Spacer(Modifier.weight(1f))
                    }
                }

                Spacer(Modifier.height(Cach.Chuan))
            }

            Spacer(Modifier.height(Cach.Gan))

            Text(
                text = "Module mới sẽ hiện ở đây ngay khi dựng xong.",
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuMo,
            )

            Spacer(Modifier.height(Cach.Lon))
        }
    }
}

/** Một ô module: ô màu bo góc ở trên, tên ở dưới. */
@Composable
private fun OModule(
    o: OUngDung,
    dangChon: Boolean,
    onBam: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .clip(Bo.The)
            .clickable(onClick = onBam)
            .padding(vertical = Cach.Gan),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(
            modifier = Modifier
                .size(CaoCham.NutChinh)
                .background(o.mau.copy(alpha = if (dangChon) 0.2f else 0.11f), Bo.The),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                imageVector = o.bieuTuong,
                contentDescription = null,
                tint = o.mau,
                modifier = Modifier.size(Cach.Khoi),
            )
        }

        Spacer(Modifier.height(Cach.Gan))

        Text(
            text = o.ten,
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurface,
            textAlign = TextAlign.Center,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
        )
    }
}
