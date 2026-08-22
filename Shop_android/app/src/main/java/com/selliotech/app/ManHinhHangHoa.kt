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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.TextField
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.material3.Surface
import androidx.compose.ui.graphics.Color
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.Search
import com.composables.icons.lucide.X
import com.selliotech.app.ui.Huy
import com.selliotech.app.ui.OXuong
import com.selliotech.app.ui.NutPhu
import com.selliotech.app.ui.Sac
import com.selliotech.app.ui.VachUuTien
import com.selliotech.app.ui.tienVN
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.Noi
import com.selliotech.app.ui.theme.mauPhu
import kotlinx.coroutines.FlowPreview
import kotlinx.coroutines.flow.debounce
import kotlinx.coroutines.flow.distinctUntilChanged

/** Số dòng mỗi lượt gọi. Đủ kín một màn dài mà không kéo về cả kho. */
private const val CO_TRANG = 30

/**
 * Danh sách hàng hoá kèm tồn kho.
 *
 * Lọc REALTIME theo đúng quy tắc trang danh sách của hệ thống — không có nút
 * "Tìm". Gõ xong chờ một nhịp rồi mới gọi, chứ gõ mỗi chữ một lượt gọi thì một
 * từ khoá 8 ký tự thành 8 lượt, và lượt về sau có thể tới trước lượt trước.
 */
@OptIn(FlowPreview::class)
@Composable
fun ManHinhHangHoa(
    kho: KhoPhien,
    modifier: Modifier = Modifier,
    loc: LocHang = LocHang(),
    onBoLoc: () -> Unit = {},
) {
    var tuKhoa by remember { mutableStateOf("") }
    var dong by remember { mutableStateOf<List<DongHang>>(emptyList()) }
    var tong by remember { mutableStateOf(0L) }
    var conNua by remember { mutableStateOf(false) }
    var trang by remember { mutableStateOf(1) }
    var dangTai by remember { mutableStateOf(true) }
    var dangTaiThem by remember { mutableStateOf(false) }
    var loi by remember { mutableStateOf<String?>(null) }

    // Từ khoá HOẶC bộ lọc đổi thì về trang 1 và tải lại từ đầu.
    //
    // Khoá theo `loc` chứ không phải Unit: bỏ chip lọc hay bấm lại thẻ Hàng hoá
    // từ một lát cắt khác là `loc` đổi mà `tuKhoa` thì không — khoá Unit là chip
    // biến mất còn bảng vẫn nằm nguyên danh sách đã lọc.
    //
    // debounce chỉ để chờ người dùng gõ xong; đổi bộ lọc là một cú bấm dứt khoát
    // nên nó vẫn đi qua đúng đường ấy, chậm hơn một nhịp không đáng kể.
    LaunchedEffect(loc) {
        snapshotFlow { tuKhoa }
            .debounce(350)
            .distinctUntilChanged()
            .collect { tu ->
                dangTai = true
                loi = null
                val kq = layHangHoa(
                    kho = kho,
                    tuKhoa = tu,
                    locTon = loc.ton,
                    locGiaVon = loc.giaVon,
                    trang = 1,
                    coTrang = CO_TRANG,
                )
                if (kq == null) {
                    loi = "Không lấy được danh sách. Kiểm tra mạng rồi thử lại."
                    dong = emptyList()
                } else {
                    dong = kq.dong
                    tong = kq.tong
                    conNua = kq.conNua
                    trang = 1
                }
                dangTai = false
            }
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            // Ô tìm đứng yên ngay dưới mũ app chứ không cuộn: đang lọc kho mà
            // phải kéo ngược lên đầu mới gõ được từ khoá là mất một nhịp.
            .padding(top = demTren),
    ) {
        MuTrang(
            tieuDe = "Hàng hoá",
            modifier = Modifier.padding(horizontal = Cach.Chuan, vertical = Cach.Gan),
        )

        OTim(gia = tuKhoa, doi = { tuKhoa = it })

        Row(
            modifier = Modifier.padding(horizontal = Cach.Chuan, vertical = Cach.Sat),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            // Con chip nói rõ đang xem một LÁT CẮT của kho. Thiếu nó thì màn
            // "hết hàng" trông y hệt màn "kho rỗng".
            if (loc.coLoc) {
                ChipLoc(nhan = loc.nhan, onBo = onBoLoc)
                Spacer(Modifier.width(Cach.Gan))
            }
            if (tong > 0) {
                Text(
                    text = "$tong mặt hàng",
                    style = MaterialTheme.typography.bodySmall,
                    color = mauPhu.chuMo,
                )
            }
        }

        // Danh sách đứng trên một TẤM TRẮNG bo hai góc trên, không nằm trần trên
        // nền xám. Nền xám là chỗ để đặt vật lên; danh sách nằm thẳng lên đó thì
        // nó với ô tìm ở trên thành hai thứ khác lớp. Tấm này chạy thẳng xuống
        // hết màn và thanh nổi lơ lửng bên trên nó.
        Surface(
            color = MaterialTheme.colorScheme.surface,
            shape = Bo.Tam,
            modifier = Modifier.fillMaxSize(),
        ) {
            when {
                dangTai -> KhungXuongDanhSach()

                loi != null -> Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(Cach.Khoi),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        text = loi.orEmpty(),
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuThuong,
                    )
                }

                dong.isEmpty() -> Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(Cach.Khoi),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        text = if (tuKhoa.isBlank()) {
                            "Kho chưa có mặt hàng nào."
                        } else {
                            "Không có mặt hàng nào khớp \"$tuKhoa\"."
                        },
                        style = MaterialTheme.typography.bodyMedium,
                        color = mauPhu.chuThuong,
                    )
                }

                else -> LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    // Danh sách chạy tiếp XUỐNG DƯỚI thanh nổi rồi mới hết — đó là
                    // điểm được của thanh nổi. Nhưng dòng cuối phải trượt lên qua
                    // khỏi nó, nếu không thì mãi mãi có một dòng không đọc được.
                    contentPadding = PaddingValues(bottom = demDuoi),
                ) {
                    items(dong, key = { it.bienTheId }) { d ->
                        DongHangHoa(d)
                        VachDong()
                    }

                    if (conNua) {
                        item {
                            Box(Modifier.padding(Cach.Chuan)) {
                                NutPhu(
                                    chu = if (dangTaiThem) "Đang tải..." else "Tải thêm",
                                    onBam = { dangTaiThem = true },
                                    moKhoa = !dangTaiThem,
                                )
                            }
                        }
                    }
                }
            }
        }
    }

    // Tải thêm nằm ngoài LazyColumn: gọi mạng trong khối dựng của một item là
    // gọi lại mỗi lần item đó cuộn qua màn.
    LaunchedEffect(dangTaiThem) {
        if (!dangTaiThem) return@LaunchedEffect

        val kq = layHangHoa(
            kho = kho,
            tuKhoa = tuKhoa,
            locTon = loc.ton,
            locGiaVon = loc.giaVon,
            trang = trang + 1,
            coTrang = CO_TRANG,
        )
        if (kq != null) {
            dong = dong + kq.dong
            tong = kq.tong
            conNua = kq.conNua
            trang += 1
        }
        dangTaiThem = false
    }
}

/**
 * Ô tìm — viên thuốc trắng đặc, không viền.
 *
 * Ô viền vẽ ra một cái khung rỗng giữa nền xám; ô đặc là một VẬT nằm trên nền,
 * cùng ngôn ngữ với thẻ và với thanh nổi ở đáy. Bo tròn hết cỡ cho khớp thanh
 * đó luôn, để hai đầu màn nói cùng một hình.
 */
@Composable
private fun OTim(gia: String, doi: (String) -> Unit) {
    TextField(
        value = gia,
        onValueChange = doi,
        singleLine = true,
        shape = Bo.Tron,
        placeholder = {
            Text("Tìm theo tên hoặc SKU", style = MaterialTheme.typography.bodyLarge)
        },
        leadingIcon = {
            Icon(
                imageVector = Lucide.Search,
                contentDescription = null,
                tint = mauPhu.chuMo,
            )
        },
        trailingIcon = if (gia.isBlank()) {
            null
        } else {
            {
                Icon(
                    imageVector = Lucide.X,
                    contentDescription = "Xoá từ khoá",
                    tint = mauPhu.chuMo,
                    modifier = Modifier
                        .clip(Bo.Tron)
                        .clickable { doi("") }
                        .padding(Cach.Sat),
                )
            }
        },
        textStyle = MaterialTheme.typography.bodyLarge,
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
        colors = TextFieldDefaults.colors(
            focusedContainerColor = MaterialTheme.colorScheme.surface,
            unfocusedContainerColor = MaterialTheme.colorScheme.surface,
            focusedIndicatorColor = Color.Transparent,
            unfocusedIndicatorColor = Color.Transparent,
            disabledIndicatorColor = Color.Transparent,
            cursorColor = MaterialTheme.colorScheme.primary,
        ),
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = Cach.Chuan, vertical = Cach.Gan),
    )
}

/**
 * Con chip cho biết danh sách đang bị lọc, bấm chữ X là bỏ lọc về xem cả kho.
 */
@Composable
private fun ChipLoc(nhan: String, onBo: () -> Unit) {
    Surface(
        color = mauPhu.lamNen,
        shape = Bo.Tron,
        modifier = Modifier.border(1.dp, mauPhu.lam.copy(alpha = 0.35f), Bo.Tron),
    ) {
        Row(
            modifier = Modifier
                .clickable(onClick = onBo)
                .padding(start = Cach.Vua, end = Cach.Gan, top = 6.dp, bottom = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = nhan,
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.Medium,
                color = mauPhu.lam,
            )
            Spacer(Modifier.width(Cach.Sat))
            Icon(
                imageVector = Lucide.X,
                contentDescription = "Bỏ lọc",
                tint = mauPhu.lam,
                modifier = Modifier.size(14.dp),
            )
        }
    }
}

/**
 * Một dòng hàng.
 *
 * Vạch dẫn đầu MANG MÀU theo tình trạng tồn — đỏ là hết, cam là sắp hết. Mọi
 * vạch thẳng một cột nên lướt dọc là thấy ngay chỗ có vấn đề, không phải đọc
 * từng con số bên phải.
 *
 * Trước đây chỗ này là một ô tròn 42dp đựng icon thùng hàng. Icon đó giống hệt
 * nhau ở mọi dòng nên không nói thêm được gì, mà ăn mất một phần tám bề ngang
 * của tên hàng — thứ duy nhất người ta thật sự đọc khi lướt kho.
 */
@Composable
private fun DongHangHoa(d: DongHang) {
    val mau = mauTon(d.ton)

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = Cach.Chuan, vertical = Cach.Vua),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        VachUuTien(mau)

        Spacer(Modifier.width(Cach.Vua))

        Column(Modifier.weight(1f)) {
            Text(
                text = d.ten + if (d.tenBienThe.isNotBlank()) " · ${d.tenBienThe}" else "",
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.Medium,
                color = MaterialTheme.colorScheme.onBackground,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )

            Spacer(Modifier.height(2.dp))

            Text(
                text = listOf(d.sku, d.danhMuc).filter { it.isNotBlank() }.joinToString(" · "),
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuMo,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )

            if (!d.dangBan) {
                Spacer(Modifier.height(Cach.Sat))
                Huy(chu = "Đang ẩn", sac = Sac.XAM)
            }
        }

        Spacer(Modifier.width(Cach.Gan))

        Column(horizontalAlignment = Alignment.End) {
            Text(
                text = nhanTon(d),
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.Bold,
                color = mau,
            )
            Spacer(Modifier.height(2.dp))
            Text(
                text = tienVN(d.gia.toLong()),
                style = MaterialTheme.typography.bodySmall,
                color = mauPhu.chuThuong,
            )
        }
    }
}

/** "12 Hộp" chứ không phải một con số trần — đơn vị tính API đã trả sẵn. */
private fun nhanTon(d: DongHang): String =
    if (d.donVi.isBlank()) "Tồn ${d.ton}" else "${d.ton} ${d.donVi}"

/** Hết hàng đỏ, sắp hết cam, còn lại xanh. Ngưỡng khớp mặc định của API. */
@Composable
private fun mauTon(ton: Int): Color = when {
    ton <= 0 -> mauPhu.do_
    ton <= 5 -> mauPhu.cam
    else -> mauPhu.luc
}

/**
 * Khung xương danh sách: sáu dòng giả đúng nhịp của dòng thật.
 *
 * Sáu là đủ kín một màn. Nhiều hơn thì lúc dữ liệu về sẽ thấy phần dưới biến
 * mất — đúng cái giật mà khung xương sinh ra để tránh.
 */
@Composable
private fun KhungXuongDanhSach() {
    Column(Modifier.fillMaxSize()) {
        repeat(6) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = Cach.Chuan, vertical = Cach.Chuan),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                OXuong(
                    modifier = Modifier
                        .width(Noi.DayVach)
                        .height(Noi.CaoVach),
                    bo = Bo.Tron,
                )
                Spacer(Modifier.width(Cach.Vua))
                Column(Modifier.weight(1f)) {
                    OXuong(modifier = Modifier.fillMaxWidth(0.7f).height(Cach.Chuan))
                    Spacer(Modifier.height(Cach.Gan))
                    OXuong(modifier = Modifier.fillMaxWidth(0.4f).height(Cach.Vua))
                }
            }
            VachDong()
        }
    }
}

/**
 * Vạch giữa hai dòng hàng: nhạt hơn vạch trong thẻ, và THỤT VÀO thẳng hàng với
 * tên hàng.
 *
 * Vạch chạy suốt từ mép này sang mép kia cắt danh sách thành từng ô rời; vạch
 * thụt vào giữ cả cột tên liền một mạch, mắt lướt dọc không bị chặn ở mỗi dòng.
 */
@Composable
private fun VachDong() {
    HorizontalDivider(
        thickness = 1.dp,
        color = mauPhu.vienNhat,
        modifier = Modifier.padding(start = Cach.Lon),
    )
}
