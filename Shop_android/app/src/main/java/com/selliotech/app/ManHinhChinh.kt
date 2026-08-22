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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import com.selliotech.app.ui.Huy
import com.selliotech.app.ui.Sac
import com.selliotech.app.ui.The
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.mauPhu

/**
 * Màn chính sau khi đăng nhập.
 *
 * Chỉ đặt nút cho chức năng đã có thật. Bày sẵn một lưới ô "Báo cáo / Kho /
 * Khách hàng" cho đẹp rồi bấm vào không ra gì là cách nhanh nhất để người
 * dùng hết tin vào app.
 *
 * Quét mã đã dời xuống nút tròn trên thanh nổi, nên trong thân màn không còn
 * nút nào: nó là việc chính của cả khu Thu ngân, mà việc chính thì phải luôn
 * nằm dưới ngón cái ở MỌI tab, không phải chỉ khi đang đứng đúng màn này.
 */
@Composable
fun ManHinhChinh(
    phien: Phien,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .verticalScroll(rememberScrollState())
            .padding(start = Cach.Chuan, end = Cach.Chuan, top = demTren, bottom = Cach.Chuan),
        verticalArrangement = Arrangement.spacedBy(Cach.Chuan),
    ) {
        MuTrang("Bán hàng")

        TheCuaHang(phien)

        if (phien.cuaHangKhoa) {
            TheHetHan()
        }

        Spacer(Modifier.height(demDuoi))
    }
}

/**
 * Thẻ ca đang trực: ai đang đứng quầy và tiệm còn hạn hay không.
 *
 * KHÔNG in lại tên tiệm ở đây — mũ app đã nói rồi, và nó nói ở mọi tab. Thẻ
 * này chỉ giữ phần mũ không nói được.
 *
 * Cũng KHÔNG tô chuyển sắc nữa: trong app này mảng xanh chuyển sắc dành riêng
 * cho tiền. Tô nó lên một thẻ hồ sơ là dạy mắt bỏ qua nó, để rồi hôm nào con
 * số doanh thu thật cũng bị lướt qua nốt.
 */
@Composable
private fun TheCuaHang(phien: Phien) {
    The {
        Row(verticalAlignment = Alignment.CenterVertically) {
            ChuCaiDau(phien.tenDangNhap)

            Spacer(Modifier.size(Cach.Vua))

            Column(Modifier.weight(1f)) {
                Text(
                    phien.tenDangNhap.ifBlank { "—" },
                    style = MaterialTheme.typography.titleMedium,
                    color = MaterialTheme.colorScheme.onSurface,
                )
                Text(
                    phien.vaiTro.ifBlank { "Đang trực quầy" },
                    style = MaterialTheme.typography.bodySmall,
                    color = mauPhu.chuMo,
                )
            }

            Huy(
                if (phien.cuaHangKhoa) "Hết hạn" else "Đang hoạt động",
                if (phien.cuaHangKhoa) Sac.DO else Sac.LUC,
            )
        }
    }
}

/** Ô chữ cái đầu — chỗ của ảnh đại diện khi nào API có trả về. */
@Composable
private fun ChuCaiDau(ten: String) {
    Box(
        modifier = Modifier
            .size(CaoCham.O)
            .background(mauPhu.lamNen, Bo.O),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            ten.take(1).uppercase().ifBlank { "?" },
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = MaterialTheme.colorScheme.primary,
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
