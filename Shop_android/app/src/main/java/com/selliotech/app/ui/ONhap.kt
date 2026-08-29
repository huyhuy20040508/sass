package com.selliotech.app.ui

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsFocusedAsState
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.OffsetMapping
import androidx.compose.ui.text.input.TransformedText
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.Nhip
import com.selliotech.app.ui.theme.mauPhu

// =====================================================================
//  Ô NHẬP CỦA BIỂU MẪU
//
//  Không dùng TextField của Material: ô đó cao cố định 56dp, luôn chừa
//  chỗ cho cái nhãn nổi mà app này không dùng, và bộ màu của nó phải
//  ghi đè tám dòng ở mọi chỗ gọi. Ở đây nhãn nằm HẲN BÊN TRÊN ô — đọc
//  được cả lúc đang gõ, chứ nhãn nổi thì lúc gõ nó co lại còn nửa cỡ.
// =====================================================================

/**
 * Một ô nhập có nhãn.
 *
 * VIỀN NÓI TRẠNG THÁI, cùng ngôn ngữ với ô tìm: xám lúc thường, xanh chủ đạo lúc
 * đang gõ, đỏ khi ô đó đang sai. Câu lỗi nằm ngay dưới ô chứ không gom hết xuống
 * chân biểu mẫu — gom xuống chân thì người dùng đọc xong phải cuộn ngược lên dò
 * xem ô nào là ô đang nói tới.
 */
@Composable
fun ONhap(
    nhan: String,
    gia: String,
    doi: (String) -> Unit,
    modifier: Modifier = Modifier,
    goiY: String = "",
    batBuoc: Boolean = false,
    kieuBanPhim: KeyboardType = KeyboardType.Text,
    doiDang: VisualTransformation = VisualTransformation.None,
    duoi: String = "",
    loi: String = "",
    /** Ô mô tả: cao ba dòng, xuống dòng được. Ô một dòng vẫn là mặc định. */
    nhieuDong: Boolean = false,
) {
    val nguon = remember { MutableInteractionSource() }
    val dangGo by nguon.collectIsFocusedAsState()
    val sai = loi.isNotBlank()

    val mauVien by animateColorAsState(
        targetValue = when {
            sai -> mauPhu.do_
            dangGo -> MaterialTheme.colorScheme.primary
            else -> mauPhu.vien
        },
        animationSpec = tween(Nhip.DoiMau),
        label = "vienONhap",
    )

    Column(modifier) {
        Row {
            Text(
                text = nhan,
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = mauPhu.chuMo,
            )
            // Dấu sao đỏ cho ô bắt buộc. Ghi "(bắt buộc)" bằng chữ thì mỗi nhãn
            // dài thêm một dòng, mà biểu mẫu nào cũng có vài ô như thế.
            if (batBuoc) {
                Text(
                    text = " *",
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = mauPhu.do_,
                )
            }
        }

        Spacer(Modifier.height(Cach.Gan))

        Surface(
            color = MaterialTheme.colorScheme.surface,
            shape = Bo.O,
            border = BorderStroke(if (dangGo || sai) 1.5.dp else 1.dp, mauVien),
            modifier = Modifier
                .fillMaxWidth()
                .height(if (nhieuDong) CaoCham.O * 2 else CaoCham.O),
        ) {
            Row(
                // Ô nhiều dòng canh TRÊN: chữ phải bắt đầu ở mép trên ô, không
                // thì gõ dòng đầu tiên thấy nó lửng lơ giữa một ô cao gấp đôi.
                verticalAlignment = if (nhieuDong) Alignment.Top else Alignment.CenterVertically,
                modifier = Modifier.padding(horizontal = Cach.Chuan, vertical = Cach.Vua),
            ) {
                Box(Modifier.weight(1f), contentAlignment = Alignment.CenterStart) {
                    if (gia.isEmpty() && goiY.isNotBlank()) {
                        Text(
                            text = goiY,
                            style = MaterialTheme.typography.bodyMedium,
                            color = mauPhu.chuMo,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                    BasicTextField(
                        value = gia,
                        onValueChange = doi,
                        singleLine = !nhieuDong,
                        maxLines = if (nhieuDong) 3 else 1,
                        interactionSource = nguon,
                        visualTransformation = doiDang,
                        textStyle = MaterialTheme.typography.bodyLarge.copy(
                            color = MaterialTheme.colorScheme.onSurface,
                        ),
                        cursorBrush = SolidColor(MaterialTheme.colorScheme.primary),
                        keyboardOptions = KeyboardOptions(
                            keyboardType = kieuBanPhim,
                            imeAction = ImeAction.Next,
                        ),
                        modifier = Modifier.fillMaxWidth(),
                    )
                }

                if (duoi.isNotBlank()) {
                    Spacer(Modifier.width(Cach.Gan))
                    Text(
                        text = duoi,
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuMo,
                    )
                }
            }
        }

        if (sai) {
            Spacer(Modifier.height(Cach.Sat + 2.dp))
            Text(
                text = loi,
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.do_,
            )
        }
    }
}

/**
 * Ô nhập TIỀN: chỉ nhận chữ số, tự chấm phân nhóm ngay khi gõ.
 *
 * `so` là chuỗi CHỮ SỐ TRẦN ("1250000"), dấu chấm chỉ là lớp hiển thị. Giữ dấu
 * chấm thẳng trong trạng thái là lần nào đọc ra cũng phải nhớ bóc chúng đi, mà
 * quên một chỗ là "1.250.000" biến thành 1 đồng.
 */
@Composable
fun ONhapTien(
    nhan: String,
    so: String,
    doi: (String) -> Unit,
    modifier: Modifier = Modifier,
    goiY: String = "0",
    batBuoc: Boolean = false,
    loi: String = "",
) {
    ONhap(
        nhan = nhan,
        gia = so,
        // Lọc ngay tại cửa: bàn phím số trên Android vẫn gõ được dấu chấm, dấu
        // phẩy và dấu trừ, mà mấy ký tự đó lọt vào là toDouble() ném lỗi.
        doi = { moi -> doi(moi.filter(Char::isDigit).take(12)) },
        modifier = modifier,
        goiY = goiY,
        batBuoc = batBuoc,
        kieuBanPhim = KeyboardType.Number,
        doiDang = DinhDangTien,
        duoi = "₫",
        loi = loi,
    )
}

/**
 * Chấm phân nhóm nghìn cho chuỗi chữ số: "1250000" -> "1.250.000".
 *
 * Tách riêng khỏi phần giao diện để kiểm được: đây là chỗ dễ sai lặng lẽ, mà sai
 * ở đây là người bán đọc nhầm giá một chữ số.
 */
fun nhomChuSo(so: String): String {
    if (so.length <= 3) return so

    val dau = so.length % 3
    val phan = buildList {
        if (dau > 0) add(so.take(dau))
        var i = dau
        while (i < so.length) {
            add(so.substring(i, i + 3))
            i += 3
        }
    }

    return phan.joinToString(".")
}

/**
 * Số dấu chấm nằm TRƯỚC vị trí con trỏ, khi chuỗi có `n` chữ số.
 *
 * Con trỏ trong ô nhập đếm theo chuỗi GỐC (chỉ chữ số), còn thứ vẽ ra màn hình
 * đã chèn thêm dấu chấm. Không cộng bù đúng số dấu chấm này thì gõ tới số thứ tư
 * là con trỏ nhảy lùi một chỗ, và mọi chữ số sau đó vào sai vị trí.
 */
fun soChamTruoc(n: Int, viTri: Int): Int {
    if (n <= 3) return 0

    val dau = if (n % 3 == 0) 3 else n % 3
    if (viTri <= dau) return 0

    return 1 + (viTri - dau - 1) / 3
}

/** Lớp hiển thị của ô tiền: vẽ ra chuỗi có chấm, và ánh xạ vị trí con trỏ hai chiều. */
private object DinhDangTien : VisualTransformation {
    override fun filter(text: AnnotatedString): TransformedText {
        val so = text.text
        val hien = nhomChuSo(so)

        val anhXa = object : OffsetMapping {
            override fun originalToTransformed(offset: Int): Int =
                (offset + soChamTruoc(so.length, offset)).coerceIn(0, hien.length)

            override fun transformedToOriginal(offset: Int): Int =
                hien.take(offset.coerceIn(0, hien.length)).count(Char::isDigit)
        }

        return TransformedText(AnnotatedString(hien), anhXa)
    }
}
