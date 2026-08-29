package com.selliotech.app.ui

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.composables.icons.lucide.ImagePlus
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.X
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.mauPhu

/** Cỡ ảnh xem trước trong biểu mẫu. To hơn ô 52dp của danh sách vì đây là chỗ CHỌN ảnh. */
private val CO_XEM = 88.dp

/**
 * Ô ẢNH ĐẠI DIỆN của biểu mẫu khai hàng.
 *
 * Chọn ảnh xong là TẢI LÊN NGAY, không đợi bấm Lưu. Đợi tới lúc Lưu thì một lượt
 * bấm phải làm hai việc dài (đẩy ảnh vài MB rồi mới ghi mặt hàng), và nếu ảnh
 * hỏng thì cả mặt hàng cũng hỏng theo trong khi phần chữ chẳng có lỗi gì. Tải
 * ngay thì người dùng thấy ảnh hiện ra trước khi lưu — biết chắc mình chọn đúng
 * tấm nào.
 *
 * Dùng bộ chọn ảnh của hệ điều hành (`PickVisualMedia`) chứ không xin quyền đọc
 * bộ nhớ: bộ chọn này trả về đúng MỘT tấm người dùng chỉ, không cần quyền nào
 * cả — xin quyền đọc cả kho ảnh chỉ để lấy một tấm là đòi quá tay.
 */
@Composable
fun ONhapAnh(
    anh: String,
    dangTai: Boolean,
    onChon: (Uri) -> Unit,
    onGo: () -> Unit,
    modifier: Modifier = Modifier,
    nhan: String = "Ảnh đại diện",
) {
    val boi = LocalContext.current
    val moKho = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.PickVisualMedia(),
    ) { uri -> uri?.let(onChon) }

    fun chonAnh() {
        moKho.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
    }

    Column(modifier) {
        Text(
            text = nhan,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.SemiBold,
            color = mauPhu.chuMo,
        )

        Spacer(Modifier.height(Cach.Gan))

        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                modifier = Modifier
                    .size(CO_XEM)
                    .clip(Bo.The)
                    .background(mauPhu.matChim)
                    .clickable(enabled = !dangTai) { chonAnh() },
                contentAlignment = Alignment.Center,
            ) {
                when {
                    dangTai -> CircularProgressIndicator(
                        modifier = Modifier.size(24.dp),
                        strokeWidth = 2.dp,
                    )

                    anh.isNotBlank() -> AnhVuong(
                        duong = anh,
                        chuThay = "",
                        bo = Bo.The,
                        modifier = Modifier.size(CO_XEM),
                    )

                    else -> Icon(
                        imageVector = Lucide.ImagePlus,
                        contentDescription = "Chọn ảnh",
                        tint = mauPhu.chuMo,
                        modifier = Modifier.size(Cach.Lon),
                    )
                }
            }

            Spacer(Modifier.width(Cach.Chuan))

            Column(Modifier.weight(1f)) {
                Surface(
                    color = MaterialTheme.colorScheme.surface,
                    shape = Bo.Nut,
                    border = BorderStroke(1.dp, mauPhu.vien),
                    modifier = Modifier
                        .clip(Bo.Nut)
                        .clickable(enabled = !dangTai) { chonAnh() },
                ) {
                    Text(
                        text = when {
                            dangTai -> "Đang tải ảnh..."
                            anh.isNotBlank() -> "Đổi ảnh"
                            else -> "Chọn ảnh"
                        },
                        style = MaterialTheme.typography.labelLarge,
                        color = MaterialTheme.colorScheme.onSurface,
                        modifier = Modifier.padding(horizontal = Cach.Chuan, vertical = Cach.Vua),
                    )
                }

                if (anh.isNotBlank() && !dangTai) {
                    Spacer(Modifier.height(Cach.Gan))
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        modifier = Modifier
                            .clip(Bo.Nho)
                            .clickable(onClick = onGo)
                            .padding(horizontal = Cach.Gan, vertical = Cach.Sat),
                    ) {
                        Icon(
                            imageVector = Lucide.X,
                            contentDescription = null,
                            tint = mauPhu.do_,
                            modifier = Modifier.size(14.dp),
                        )
                        Spacer(Modifier.width(Cach.Sat))
                        Text(
                            text = "Gỡ ảnh",
                            style = MaterialTheme.typography.labelMedium,
                            color = mauPhu.do_,
                        )
                    }
                }
            }
        }

        Spacer(Modifier.height(Cach.Gan))

        Text(
            text = "Ảnh JPG, PNG hoặc WEBP, tối đa 5MB.",
            style = MaterialTheme.typography.bodySmall,
            color = mauPhu.chuMo,
        )
    }
}

/** Đuôi tệp suy từ kiểu MIME. Máy chủ chỉ nhận bốn đuôi này. */
fun duoiTuMime(mime: String?): String = when (mime) {
    "image/png" -> ".png"
    "image/webp" -> ".webp"
    else -> ".jpg"
}
