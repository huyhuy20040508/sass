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
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.selliotech.app.ui.HangThongTin
import com.selliotech.app.ui.NutNguyHiem
import com.selliotech.app.ui.NutPhu
import com.selliotech.app.ui.The
import com.selliotech.app.ui.TieuDeMuc
import com.selliotech.app.ui.VachNgan
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.mauPhu
import kotlinx.coroutines.launch

/**
 * Tab Tài khoản — hồ sơ người đang đăng nhập và những việc "ra khỏi chỗ đang làm".
 *
 * Đổi khu và Đăng xuất về đây cả. Trước đây chúng nằm lẫn giữa nội dung màn
 * chính, tức là một nút bỏ đi màu đỏ đứng ngay dưới nút làm việc hằng ngày —
 * chỗ dễ bấm nhầm nhất trong khi người bán đang vội.
 */
@Composable
fun ManHinhTaiKhoan(
    phien: Phien,
    kho: KhoPhien,
    modifier: Modifier = Modifier,
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
            .padding(start = Cach.Chuan, end = Cach.Chuan, top = demTren, bottom = Cach.Chuan),
        verticalArrangement = Arrangement.spacedBy(Cach.Chuan),
    ) {
        MuTrang("Tài khoản")

        TheNguoiDung(phien)

        The {
            HangThongTin(nhan = "Cửa hàng", gia = phien.tenCuaHang.ifBlank { "—" })
            VachNgan()
            HangThongTin(nhan = "Mã cửa hàng", gia = phien.maCuaHang)
            VachNgan()
            HangThongTin(nhan = "Khu đang đứng", gia = Khu.ten(phien.khu))
        }
        // Nút "Đổi khu làm việc" đã dời lên mũ app: mũ in sẵn tên khu ở mọi
        // màn, nên đó mới là chỗ người ta đi tìm khi muốn nhảy sang khu khác.
        // Để cả hai nơi là hai đường tới cùng một việc, rồi sẽ có ngày lệch.

        Column {
            TieuDeMuc("Chẩn đoán")

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
                    text = "Máy chủ trả về",
                    style = MaterialTheme.typography.labelMedium,
                    color = mauPhu.chuMo,
                )
                Spacer(Modifier.height(Cach.Gan))
                Text(
                    text = it,
                    style = MaterialTheme.typography.bodyMedium,
                    fontFamily = FontFamily.Monospace,
                    color = MaterialTheme.colorScheme.onSurface,
                )
            }
        }

        Spacer(Modifier.height(Cach.Gan))

        NutNguyHiem(chu = "Đăng xuất", onBam = onDangXuat)

        Spacer(Modifier.height(demDuoi))
    }
}

/** Thẻ hồ sơ: chữ cái đầu, tên đăng nhập, vai trò. */
@Composable
private fun TheNguoiDung(phien: Phien) {
    The {
        Row(verticalAlignment = Alignment.CenterVertically) {
            // Vuông bo góc như ô tiệm trên mũ app, chỉ khác màu: nền nhạt chứ
            // không chuyển sắc. Chuyển sắc trong app này chỉ dành cho tiền.
            Box(
                modifier = Modifier
                    .size(CaoCham.O)
                    .background(mauPhu.lamNen, Bo.O),
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    text = phien.tenDangNhap.take(1).uppercase().ifBlank { "?" },
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                    color = MaterialTheme.colorScheme.primary,
                )
            }

            Spacer(Modifier.size(Cach.Chuan))

            Column(Modifier.fillMaxWidth()) {
                Text(
                    text = phien.tenDangNhap.ifBlank { "—" },
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = MaterialTheme.colorScheme.onSurface,
                )
                if (phien.vaiTro.isNotBlank()) {
                    Text(
                        text = phien.vaiTro,
                        style = MaterialTheme.typography.bodySmall,
                        color = mauPhu.chuMo,
                    )
                }
            }
        }
    }
}
