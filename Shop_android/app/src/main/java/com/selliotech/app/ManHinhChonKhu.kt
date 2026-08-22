package com.selliotech.app

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.ChuMoSang
import com.selliotech.app.ui.theme.ChuSang
import com.selliotech.app.ui.theme.ChuThuongSang
import com.selliotech.app.ui.theme.Do
import com.selliotech.app.ui.theme.MatSang
import com.selliotech.app.ui.theme.NenSang
import com.selliotech.app.ui.theme.VienNhatSang

// Tấm chọn khu LUÔN tông sáng, cùng lý do với tấm đăng nhập: nó là tấm trắng
// đặt trên ảnh. Vẫn lấy từ bảng màu chung, không gõ mã màu tại đây.
private val NEN_TAM = MatSang
private val NEN_O = NenSang
private val CHU = ChuSang
private val CHU_THUONG = ChuThuongSang
private val CHU_MO = ChuMoSang

/**
 * Chọn khu làm việc sau khi đăng nhập — chặng giữa màn đăng nhập và chỗ làm việc.
 *
 * Hiện cho MỌI người, kể cả ai chỉ được giao một cửa: đúng luật của web
 * (ChonCuaVaoController). Với họ ô khu không còn là câu hỏi, nhưng tên tiệm vừa
 * đăng nhập và chỗ thoát khi gõ nhầm tài khoản thì còn. Không có cửa nào mới đi
 * thẳng — lúc đó màn này trống thật.
 *
 * Ảnh tiệm làm bìa trên, tấm trắng bo góc ôm phần chọn — cùng bố cục với màn
 * đăng nhập ngay trước đó. KHÔNG đặt chữ lên ảnh: chỗ ảnh giáp tấm trắng là mảng
 * kệ hàng sáng và đầy nhãn màu, phủ tối cỡ nào chữ trắng đọc cũng mệt. Phần nhận
 * diện vì thế nằm trong tấm, đúng như dải nhận diện của web.
 *
 * KHÔNG phải chốt bảo mật: cửa vào do API quyết theo `users.access_areas`.
 */
@Composable
fun ManHinhChonKhu(
    phien: Phien,
    modifier: Modifier = Modifier,
    onChon: (String) -> Unit,
    onDangXuat: () -> Unit,
) {
    Box(modifier = modifier.fillMaxSize()) {
        AnhBia()

        Column(
            modifier = Modifier.fillMaxSize(),
            verticalArrangement = Arrangement.Bottom,
        ) {
            TamChon(phien = phien, onChon = onChon, onDangXuat = onDangXuat)
        }
    }
}

/** Thu ngân trước, quản trị sau — giữ đúng thứ tự ModuleLamViec bên web. */
private fun thuTuKhu(cua: List<String>): List<String> =
    listOf(Khu.THU_NGAN, Khu.QUAN_LY).filter { it in cua }

/** Ảnh tiệm phủ kín phía sau, tối nhẹ cho tấm trắng nổi lên. */
@Composable
private fun AnhBia() {
    Image(
        painter = painterResource(R.drawable.nen_dang_nhap),
        contentDescription = null,
        contentScale = ContentScale.Crop,
        modifier = Modifier.fillMaxSize(),
    )
    Box(
        Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    0f to Color.Black.copy(alpha = 0.26f),
                    1f to Color.Black.copy(alpha = 0.52f),
                ),
            ),
    )
}

/** Tấm trắng bo góc trên — cùng hình dáng với tấm của màn đăng nhập. */
@Composable
private fun TamChon(phien: Phien, onChon: (String) -> Unit, onDangXuat: () -> Unit) {
    Surface(color = NEN_TAM, shape = Bo.Tam, shadowElevation = 16.dp) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = Cach.Khoi, vertical = Cach.Rong),
        ) {
            // Thanh nắm ở mép trên: cùng dấu hiệu với tấm đăng nhập.
            Box(
                Modifier
                    .align(Alignment.CenterHorizontally)
                    .width(38.dp)
                    .height(4.dp)
                    .clip(Bo.Tron)
                    .background(VienNhatSang),
            )

            Spacer(Modifier.height(Cach.Rong))

            DaiNhanDien(phien)

            Spacer(Modifier.height(Cach.Rong))

            Text(
                text = "Hôm nay bạn làm việc ở đâu?",
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.SemiBold,
                color = CHU,
            )

            Spacer(Modifier.height(Cach.Sat))

            Text(
                text = if (phien.cuaVao.size > 1) {
                    "Chọn nhầm cũng không sao, đổi lại được bất cứ lúc nào."
                } else {
                    "Sai tài khoản hay sai tiệm thì đăng xuất rồi vào lại."
                },
                style = MaterialTheme.typography.bodyMedium,
                color = CHU_MO,
            )

            Spacer(Modifier.height(Cach.Rong))

            if (phien.cuaVao.isEmpty()) {
                ChuaGiaoCua()
            } else {
                thuTuKhu(phien.cuaVao).forEach { ma ->
                    OKhu(ma = ma, onBam = { onChon(ma) })
                    Spacer(Modifier.height(Cach.Vua))
                }
            }

            Spacer(Modifier.height(Cach.Gan))

            Text(
                text = "Đăng xuất",
                style = MaterialTheme.typography.labelLarge,
                color = Do,
                modifier = Modifier
                    .align(Alignment.CenterHorizontally)
                    .clip(Bo.Nut)
                    .clickable(onClick = onDangXuat)
                    .padding(horizontal = Cach.Khoi, vertical = Cach.Vua),
            )

            Spacer(Modifier.height(Cach.Gan))
        }
    }
}

/**
 * Dải nhận diện: logo · tiệm đang đứng · ai đang đăng nhập.
 *
 * Người trông nhiều tiệm gõ nhầm mã cửa hàng là chuyện có thật, và đây là màn
 * hình cuối cùng còn kịp nhận ra trước khi bắt đầu ghi sổ vào nhầm nơi.
 */
@Composable
private fun DaiNhanDien(phien: Phien) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Image(
            painter = painterResource(R.drawable.logo_sellio),
            contentDescription = null,
            modifier = Modifier.height(24.dp),
        )

        Spacer(Modifier.width(Cach.Vua))
        Box(
            Modifier
                .height(20.dp)
                .width(1.dp)
                .background(VienNhatSang),
        )
        Spacer(Modifier.width(Cach.Vua))

        Column(Modifier.weight(1f)) {
            Text(
                text = phien.tenCuaHang.ifBlank { phien.maCuaHang },
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.SemiBold,
                color = CHU,
                maxLines = 1,
            )
            if (phien.tenDangNhap.isNotBlank()) {
                Text(
                    text = phien.tenDangNhap,
                    style = MaterialTheme.typography.bodySmall,
                    color = CHU_THUONG,
                    maxLines = 1,
                )
            }
        }
    }
}

/**
 * Một ô khu: ảnh chụp đúng thứ khu đó làm, đặt cạnh tên và mô tả.
 *
 * Ảnh để ở cỡ vuông vừa phải chứ KHÔNG trải hết bề ngang ô: tấm thu ngân chỉ có
 * 223px, kéo ra 1080 là nhoè thành một vệt màu. Ở cỡ này cả hai tấm đều còn nét.
 */
@Composable
private fun OKhu(ma: String, onBam: () -> Unit) {
    val anh = when (ma) {
        Khu.QUAN_LY -> R.drawable.cua_quan_tri
        else -> R.drawable.cua_thu_ngan
    }

    Row(
        modifier = Modifier
            .fillMaxWidth()
            // Ghim chiều cao: mô tả khu này một dòng, khu kia hai dòng — thả tự
            // do thì hai ô cao lệch nhau, nhìn ra ngay là xộc xệch.
            .heightIn(min = 104.dp)
            .clip(Bo.The)
            .background(NEN_O)
            .border(1.dp, VienNhatSang, Bo.The)
            .clickable(onClick = onBam)
            .padding(horizontal = Cach.Vua, vertical = Cach.Gan),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(76.dp)
                .clip(Bo.O),
        ) {
            Image(
                painter = painterResource(anh),
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )
            // Vệt tối rất nhẹ ở đáy ảnh: hai tấm chênh nhau về độ sáng, lớp này
            // kéo chúng về gần nhau để hai ô trông cùng một bộ.
            Box(
                Modifier
                    .fillMaxSize()
                    .background(
                        Brush.verticalGradient(
                            0f to Color.Transparent,
                            1f to Color.Black.copy(alpha = 0.22f),
                        ),
                    ),
            )
        }

        Spacer(Modifier.width(Cach.Chuan))

        Column(Modifier.weight(1f)) {
            Text(
                text = Khu.ten(ma),
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                color = CHU,
            )
            Spacer(Modifier.height(2.dp))
            Text(
                text = Khu.moTa(ma),
                style = MaterialTheme.typography.bodySmall,
                color = CHU_MO,
                lineHeight = 18.sp,
            )
        }

        Icon(
            imageVector = Icons.AutoMirrored.Filled.KeyboardArrowRight,
            contentDescription = null,
            tint = CHU_MO,
        )
    }
}

/** Tài khoản chưa được chủ tiệm tích cửa nào. Nói thẳng chứ đừng để tấm trống. */
@Composable
private fun ChuaGiaoCua() {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(Bo.The)
            .background(NEN_O)
            .padding(Cach.Rong),
    ) {
        Text(
            text = "Tài khoản chưa được giao khu làm việc nào.",
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = FontWeight.Medium,
            color = CHU,
        )
        Spacer(Modifier.height(Cach.Gan))
        Text(
            text = "Nhờ chủ cửa hàng vào Nhân sự tích cửa Quản lý hoặc Thu ngân cho bạn.",
            style = MaterialTheme.typography.bodySmall,
            color = CHU_MO,
        )
    }
}
