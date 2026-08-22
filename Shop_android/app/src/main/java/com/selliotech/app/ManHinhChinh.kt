package com.selliotech.app

import androidx.compose.foundation.background
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.selliotech.app.ui.Huy
import com.selliotech.app.ui.NutChinh
import com.selliotech.app.ui.NutNguyHiem
import com.selliotech.app.ui.NutPhu
import com.selliotech.app.ui.Sac
import com.selliotech.app.ui.The
import com.selliotech.app.ui.TieuDeMuc
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.Xanh
import com.selliotech.app.ui.theme.XanhDam
import com.selliotech.app.ui.theme.mauPhu
import kotlinx.coroutines.launch

/**
 * Màn chính sau khi đăng nhập.
 *
 * Chỉ đặt nút cho chức năng đã có thật. Bày sẵn một lưới ô "Báo cáo / Kho /
 * Khách hàng" cho đẹp rồi bấm vào không ra gì là cách nhanh nhất để người
 * dùng hết tin vào app.
 */
@Composable
fun ManHinhChinh(
    phien: Phien,
    kho: KhoPhien,
    modifier: Modifier = Modifier,
    onQuetMa: () -> Unit,
    onDangXuat: () -> Unit,
) {
    val pham = rememberCoroutineScope()
    var quyen by remember { mutableStateOf<String?>(null) }
    var dangGoiQuyen by remember { mutableStateOf(false) }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .verticalScroll(rememberScrollState())
            .padding(Cach.Chuan),
        verticalArrangement = Arrangement.spacedBy(Cach.Chuan),
    ) {
        Spacer(Modifier.height(Cach.Sat))

        TheCuaHang(phien)

        if (phien.cuaHangKhoa) {
            TheHetHan()
        }

        Column {
            TieuDeMuc("Chức năng")

            NutChinh(
                chu = "Quét mã tại quầy",
                onBam = onQuetMa,
                moKhoa = !phien.cuaHangKhoa,
            )

            Spacer(Modifier.height(Cach.Vua))

            NutPhu(
                chu = if (dangGoiQuyen) "Đang gọi..." else "Xem quyền của tôi",
                onBam = {
                    dangGoiQuyen = true
                    pham.launch {
                        quyen = layQuyenCuaToi(kho)
                        dangGoiQuyen = false
                    }
                },
                moKhoa = !dangGoiQuyen,
            )
        }

        quyen?.let {
            The {
                Text(
                    "Máy chủ trả về",
                    style = MaterialTheme.typography.labelMedium,
                    color = mauPhu.chuMo,
                )
                Spacer(Modifier.height(Cach.Gan))
                Text(
                    it,
                    style = MaterialTheme.typography.bodyMedium,
                    fontFamily = FontFamily.Monospace,
                    color = MaterialTheme.colorScheme.onSurface,
                )
            }
        }

        Spacer(Modifier.height(Cach.Gan))

        NutNguyHiem(chu = "Đăng xuất", onBam = onDangXuat)

        Spacer(Modifier.height(Cach.Khoi))
    }
}

/**
 * Thẻ cửa hàng — khối đầu tiên người dùng nhìn thấy.
 *
 * Nền chuyển sắc tím thay vì thẻ trắng: đây là chỗ duy nhất trong màn được
 * phép nổi bật, phần còn lại phải nhường nó.
 */
@Composable
private fun TheCuaHang(phien: Phien) {
    Surface(shape = Bo.The, color = Color.Transparent, modifier = Modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier
                .background(Brush.linearGradient(listOf(XanhDam, Xanh)))
                .padding(Cach.Rong),
        ) {
            Text(
                "Đang bán tại",
                style = MaterialTheme.typography.labelMedium,
                color = Color.White.copy(alpha = 0.7f),
            )
            Spacer(Modifier.height(Cach.Sat))
            Text(
                phien.tenCuaHang.ifBlank { "Cửa hàng ${phien.maCuaHang}" },
                style = MaterialTheme.typography.headlineSmall,
                color = Color.White,
            )

            Spacer(Modifier.height(Cach.Chuan))

            HangNguoiDung(phien)
        }
    }
}

@Composable
private fun HangNguoiDung(phien: Phien) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        ChuCaiDau(phien.tenDangNhap)
        Spacer(Modifier.size(Cach.Vua))
        Column(Modifier.weight(1f)) {
            Text(
                phien.tenDangNhap.ifBlank { "—" },
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.Medium,
                color = Color.White,
            )
            if (phien.vaiTro.isNotBlank()) {
                Text(
                    phien.vaiTro,
                    style = MaterialTheme.typography.bodyMedium,
                    color = Color.White.copy(alpha = 0.7f),
                )
            }
        }
        Huy(if (phien.cuaHangKhoa) "Hết hạn" else "Đang hoạt động", if (phien.cuaHangKhoa) Sac.DO else Sac.LUC)
    }
}

/** Vòng tròn chữ cái đầu — chỗ của ảnh đại diện khi nào API có trả về. */
@Composable
private fun ChuCaiDau(ten: String) {
    Box(
        modifier = Modifier
            .size(44.dp)
            .background(Color.White.copy(alpha = 0.18f), Bo.Tron),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            ten.take(1).uppercase().ifBlank { "?" },
            style = MaterialTheme.typography.titleMedium,
            color = Color.White,
        )
    }
}

/**
 * Cửa hàng hết hạn hợp đồng: nói thẳng ngay đây, đừng để người ta bấm quanh
 * rồi ăn 403 ở từng màn mà không hiểu vì sao.
 */
@Composable
private fun TheHetHan() {
    Surface(color = mauPhu.doNen, shape = Bo.The, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(Cach.Rong)) {
            Text(
                "Cửa hàng đã hết hạn hợp đồng",
                style = MaterialTheme.typography.titleMedium,
                color = mauPhu.do_,
            )
            Spacer(Modifier.height(Cach.Sat))
            Text(
                "Gia hạn gói dịch vụ để dùng lại. Trong lúc chờ, gần như mọi chức năng đều bị khoá.",
                style = MaterialTheme.typography.bodyMedium,
                color = mauPhu.do_,
            )
        }
    }
}
