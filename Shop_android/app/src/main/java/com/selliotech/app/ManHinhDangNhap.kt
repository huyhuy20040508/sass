package com.selliotech.app

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsFocusedAsState
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Storefront
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextField
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.Cach
import com.selliotech.app.ui.theme.CaoCham
import com.selliotech.app.ui.theme.ChuMoSang
import com.selliotech.app.ui.theme.ChuSang
import com.selliotech.app.ui.theme.ChuThuongSang
import com.selliotech.app.ui.theme.Do
import com.selliotech.app.ui.theme.DoNen
import com.selliotech.app.ui.theme.MatSang
import com.selliotech.app.ui.theme.NenSang
import com.selliotech.app.ui.theme.VienNhatSang
import com.selliotech.app.ui.theme.Xanh
import com.selliotech.app.ui.theme.XanhDam
import com.selliotech.app.ui.theme.XanhNen
import com.selliotech.app.ui.theme.XanhSang
import kotlinx.coroutines.launch

// Tấm đăng nhập LUÔN dùng tông sáng, không theo chế độ tối của máy: nó là tấm
// trắng đặt trên ảnh, chữ đổi sang trắng theo là mất chữ.
// Vẫn lấy từ bảng màu chung, không gõ mã màu tại đây.
private val NEN_TAM = MatSang
private val NEN_O = NenSang
private val CHU = ChuSang
private val CHU_THUONG = ChuThuongSang
private val CHU_MO = ChuMoSang
private val CHINH = Xanh

/**
 * Màn đăng nhập.
 *
 * Ảnh cửa hàng chiếm phần trên, tấm trắng trượt lên từ dưới ôm lấy khung nhập —
 * bố cục quen thuộc của app ngân hàng, và quan trọng hơn: bàn phím bật lên thì
 * chỉ tấm trắng chạy, ảnh đứng yên, mắt không bị giật.
 */
@Composable
fun ManHinhDangNhap(
    kho: KhoPhien,
    modifier: Modifier = Modifier,
    onXong: (Phien) -> Unit,
) {
    val daNho = remember { kho.docNho() }

    var maCuaHang by remember { mutableStateOf(daNho?.maCuaHang ?: kho.maCuaHangCu()) }
    var tenDangNhap by remember { mutableStateOf(daNho?.tenDangNhap.orEmpty()) }
    var matKhau by remember { mutableStateOf(daNho?.matKhau.orEmpty()) }
    var nhoMatKhau by remember { mutableStateOf(daNho != null) }
    var hienMatKhau by remember { mutableStateOf(false) }
    var dangGoi by remember { mutableStateOf(false) }
    var loi by remember { mutableStateOf<String?>(null) }

    // Bật sau khung hình đầu tiên để phần trượt lên có cái mà trượt.
    var daVao by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { daVao = true }

    val pham = rememberCoroutineScope()
    val banPhim = LocalSoftwareKeyboardController.current
    val rung = LocalHapticFeedback.current

    val duNhap = maCuaHang.isNotBlank() && tenDangNhap.isNotBlank() && matKhau.isNotBlank()

    fun dangNhap() {
        if (!duNhap || dangGoi) return
        rung.performHapticFeedback(HapticFeedbackType.LongPress)
        banPhim?.hide()
        dangGoi = true
        loi = null
        pham.launch {
            // Bỏ dấu hai ô chữ, mật khẩu giữ nguyên văn.
            val ma = boDau(maCuaHang)
            val ten = boDau(tenDangNhap)
            when (val kq = dangNhapCuaHang(ma, ten, matKhau)) {
                is KetQuaDangNhap.ThatBai -> {
                    loi = kq.loi
                    rung.performHapticFeedback(HapticFeedbackType.LongPress)
                }

                is KetQuaDangNhap.ThanhCong -> {
                    // Chỉ nhớ sau khi máy chủ đã nhận: nhớ một bộ sai thì lần sau
                    // vẫn điền sẵn đúng bộ sai đó.
                    if (nhoMatKhau) kho.ghiNho(ma, ten, matKhau) else kho.xoaNho()
                    kho.ghi(kq.phien)
                    onXong(kq.phien)
                }
            }
            dangGoi = false
        }
    }

    Box(modifier = modifier.fillMaxSize()) {
        AnhNen()

        Column(
            modifier = Modifier
                .fillMaxSize()
                .statusBarsPadding(),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.weight(1f))

            AnimatedVisibility(visible = daVao, enter = fadeIn(tween(700, delayMillis = 150))) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Image(
                        painter = painterResource(R.drawable.logo_sellio_sang),
                        contentDescription = "Selliotech",
                        modifier = Modifier.width(190.dp),
                    )
                    Spacer(Modifier.height(Cach.Vua))
                    Text(
                        if (daNho != null) "Chào mừng trở lại" else "Bán hàng mọi lúc, mọi nơi",
                        color = Color.White.copy(alpha = 0.85f),
                        style = MaterialTheme.typography.bodyLarge,
                    )
                    Spacer(Modifier.height(Cach.Khoi))
                }
            }

            AnimatedVisibility(
                visible = daVao,
                enter = slideInVertically(
                    // Lò xo, không phải tween: chạm tay vào thấy có sức nặng.
                    animationSpec = spring(
                        dampingRatio = Spring.DampingRatioLowBouncy,
                        stiffness = Spring.StiffnessLow,
                    ),
                    initialOffsetY = { it },
                ) + fadeIn(tween(350)),
            ) {
                TamDangNhap(
                    daVao = daVao,
                    maCuaHang = maCuaHang,
                    doiMaCuaHang = { maCuaHang = it; loi = null },
                    tenDangNhap = tenDangNhap,
                    doiTenDangNhap = { tenDangNhap = it; loi = null },
                    matKhau = matKhau,
                    doiMatKhau = { matKhau = it; loi = null },
                    hienMatKhau = hienMatKhau,
                    doiHienMatKhau = { hienMatKhau = !hienMatKhau },
                    nhoMatKhau = nhoMatKhau,
                    doiNhoMatKhau = { nhoMatKhau = it },
                    loi = loi,
                    dangGoi = dangGoi,
                    duNhap = duNhap,
                    onDangNhap = { dangNhap() },
                )
            }
        }
    }
}

/**
 * Ảnh nền phóng chậm không ngừng.
 *
 * Hai mươi hai giây cho một lượt phóng 8% — chậm tới mức không ai bắt được nó
 * đang động, chỉ thấy màn hình "sống". Nhanh hơn là thành hoạt hình rẻ tiền.
 */
@Composable
private fun AnhNen() {
    val vong = rememberInfiniteTransition(label = "nen")
    val phong by vong.animateFloat(
        initialValue = 1f,
        targetValue = 1.08f,
        animationSpec = infiniteRepeatable(
            animation = tween(22_000, easing = LinearEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "phong",
    )

    Image(
        painter = painterResource(R.drawable.nen_dang_nhap),
        contentDescription = null,
        contentScale = ContentScale.Crop,
        modifier = Modifier
            .fillMaxSize()
            .graphicsLayer {
                scaleX = phong
                scaleY = phong
            },
    )

    // Phủ tối dần xuống dưới: ảnh nguyên bản có vùng sàn rất sáng, chữ trắng
    // đặt lên đó thì mất chữ.
    Box(
        Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    0f to Color.Black.copy(alpha = 0.20f),
                    0.5f to Color.Black.copy(alpha = 0.40f),
                    1f to Color.Black.copy(alpha = 0.70f),
                ),
            ),
    )
}

@Composable
private fun TamDangNhap(
    daVao: Boolean,
    maCuaHang: String,
    doiMaCuaHang: (String) -> Unit,
    tenDangNhap: String,
    doiTenDangNhap: (String) -> Unit,
    matKhau: String,
    doiMatKhau: (String) -> Unit,
    hienMatKhau: Boolean,
    doiHienMatKhau: () -> Unit,
    nhoMatKhau: Boolean,
    doiNhoMatKhau: (Boolean) -> Unit,
    loi: String?,
    dangGoi: Boolean,
    duNhap: Boolean,
    onDangNhap: () -> Unit,
) {
    Surface(
        color = NEN_TAM,
        shape = RoundedCornerShape(topStart = 32.dp, topEnd = 32.dp),
        shadowElevation = 24.dp,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(
            modifier = Modifier
                .widthIn(max = 520.dp)
                .verticalScroll(rememberScrollState())
                .imePadding()
                .navigationBarsPadding()
                .padding(horizontal = Cach.Khoi),
        ) {
            Spacer(Modifier.height(Cach.Vua))

            // Thanh nắm ở mép trên: dấu hiệu quen thuộc của tấm trượt, cho người
            // dùng biết đây là một lớp riêng chứ không phải nửa dưới màn hình.
            Box(
                Modifier
                    .align(Alignment.CenterHorizontally)
                    .width(44.dp)
                    .height(4.dp)
                    .background(VienNhatSang, Bo.Tron),
            )

            Spacer(Modifier.height(Cach.Rong))

            Text(
                "Đăng nhập",
                color = CHU,
                fontWeight = FontWeight.Bold,
                style = MaterialTheme.typography.headlineSmall,
            )
            Spacer(Modifier.height(Cach.Sat))
            Text(
                "Nhập thông tin cửa hàng để bắt đầu ca bán",
                color = CHU_MO,
                style = MaterialTheme.typography.bodyMedium,
            )

            Spacer(Modifier.height(Cach.Khoi))

            // Ba ô vào lần lượt chứ không cùng một lúc: mắt bám theo được thứ tự
            // phải điền, thay vì bị dội cả khối cùng lúc.
            HienDan(daVao, 260) {
                Column {
                    ODangNhap(
                        gia = maCuaHang,
                        doiGia = doiMaCuaHang,
                        nhan = "Mã cửa hàng",
                        bieuTuong = Icons.Filled.Storefront,
                        batLoi = loi != null,
                        moKhoa = !dangGoi,
                    )
                    GoiYBoDau(maCuaHang)
                }
            }

            Spacer(Modifier.height(Cach.Vua))

            HienDan(daVao, 340) {
                Column {
                    ODangNhap(
                        gia = tenDangNhap,
                        doiGia = doiTenDangNhap,
                        nhan = "Tên đăng nhập",
                        bieuTuong = Icons.Filled.Person,
                        batLoi = loi != null,
                        moKhoa = !dangGoi,
                    )
                    GoiYBoDau(tenDangNhap)
                }
            }

            Spacer(Modifier.height(Cach.Vua))

            HienDan(daVao, 420) {
                ODangNhap(
                    gia = matKhau,
                    doiGia = doiMatKhau,
                    nhan = "Mật khẩu",
                    bieuTuong = Icons.Filled.Lock,
                    batLoi = loi != null,
                    moKhoa = !dangGoi,
                    anChu = !hienMatKhau,
                    cuoiO = {
                        IconButton(onClick = doiHienMatKhau) {
                            Icon(
                                imageVector =
                                    if (hienMatKhau) Icons.Filled.VisibilityOff else Icons.Filled.Visibility,
                                contentDescription =
                                    if (hienMatKhau) "Ẩn mật khẩu" else "Hiện mật khẩu",
                                tint = CHU_MO,
                            )
                        }
                    },
                    cuoiCung = true,
                    onXong = onDangNhap,
                )
            }

            // Lỗi trượt xuống thay vì hiện đột ngột — đỡ giật cả khối bên dưới.
            AnimatedVisibility(
                visible = loi != null,
                enter = expandVertically(spring(stiffness = Spring.StiffnessMediumLow)) + fadeIn(),
                exit = shrinkVertically() + fadeOut(),
            ) {
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    modifier = Modifier
                        .padding(top = Cach.Vua)
                        .fillMaxWidth()
                        .background(DoNen, Bo.O)
                        .padding(horizontal = Cach.Vua, vertical = Cach.Vua),
                ) {
                    Text(loi.orEmpty(), color = Do, style = MaterialTheme.typography.bodyMedium)
                }
            }

            Spacer(Modifier.height(Cach.Gan))

            HienDan(daVao, 480) {
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    modifier = Modifier
                        .clip(Bo.Nho)
                        .clickable(enabled = !dangGoi) { doiNhoMatKhau(!nhoMatKhau) },
                ) {
                    Checkbox(
                        checked = nhoMatKhau,
                        onCheckedChange = doiNhoMatKhau,
                        enabled = !dangGoi,
                        colors = CheckboxDefaults.colors(
                            checkedColor = CHINH,
                            uncheckedColor = CHU_MO,
                        ),
                    )
                    Text(
                        "Nhớ mật khẩu",
                        color = CHU_THUONG,
                        style = MaterialTheme.typography.bodyLarge,
                    )
                }
            }

            Spacer(Modifier.height(Cach.Chuan))

            HienDan(daVao, 540) {
                NutDangNhap(moKhoa = duNhap, dangGoi = dangGoi, onBam = onDangNhap)
            }

            Spacer(Modifier.height(Cach.Rong))
        }
    }
}

/** Cho một khối vào muộn hơn khối trước, tạo nhịp thay vì dội cả trang cùng lúc. */
@Composable
private fun HienDan(daVao: Boolean, cho: Int, noiDung: @Composable () -> Unit) {
    AnimatedVisibility(
        visible = daVao,
        enter = fadeIn(tween(420, delayMillis = cho)) +
            slideInVertically(tween(420, delayMillis = cho)) { it / 3 },
    ) {
        noiDung()
    }
}

/**
 * Nút chính: nền chuyển sắc, quầng sáng cùng màu hắt xuống dưới, thụt nhẹ khi
 * ngón tay còn đè. Ba thứ nhỏ cộng lại là khác biệt giữa "nút" và "nút đẹp".
 */
@Composable
private fun NutDangNhap(moKhoa: Boolean, dangGoi: Boolean, onBam: () -> Unit) {
    val nguon = remember { MutableInteractionSource() }
    val dangDe by nguon.collectIsPressedAsState()
    val co by animateFloatAsState(
        targetValue = if (dangDe) 0.97f else 1f,
        animationSpec = spring(dampingRatio = Spring.DampingRatioMediumBouncy),
        label = "co-nut",
    )
    val batSang = moKhoa && !dangGoi

    Button(
        onClick = onBam,
        enabled = batSang,
        shape = Bo.Nut,
        interactionSource = nguon,
        colors = ButtonDefaults.buttonColors(
            containerColor = Color.Transparent,
            contentColor = Color.White,
            disabledContainerColor = Color.Transparent,
            disabledContentColor = Color.White.copy(alpha = 0.85f),
        ),
        modifier = Modifier
            .fillMaxWidth()
            .height(CaoCham.NutChinh)
            .scale(co)
            // Bóng mang màu của chính nút, không phải màu đen: nút như đang phát
            // sáng xuống nền chứ không phải một khối chặn ánh sáng.
            .shadow(
                elevation = if (batSang) 16.dp else 0.dp,
                shape = Bo.Nut,
                spotColor = CHINH,
                ambientColor = CHINH,
            )
            .background(
                brush = if (batSang) {
                    Brush.horizontalGradient(listOf(XanhDam, Xanh, XanhSang))
                } else {
                    Brush.horizontalGradient(
                        listOf(CHINH.copy(alpha = 0.32f), CHINH.copy(alpha = 0.32f)),
                    )
                },
                shape = Bo.Nut,
            ),
    ) {
        if (dangGoi) {
            CircularProgressIndicator(
                modifier = Modifier.size(22.dp),
                strokeWidth = 2.dp,
                color = Color.White,
            )
        } else {
            Text("Đăng nhập", style = MaterialTheme.typography.labelLarge)
        }
    }
}

/**
 * Ô nhập của màn này: tô nền, KHÔNG viền, có biểu tượng dẫn.
 *
 * Dùng ô kiểu tô nền chứ không phải kiểu viền: ô viền đem tô nền thì cái nhãn
 * nổi lên khoét một mảng trắng vào giữa nền xám, nhìn như lỗi hiển thị.
 * Chạm vào ô thì nền chuyển xanh nhạt — báo đang gõ ở đâu mà không cần thêm
 * một đường kẻ nào.
 */
@Composable
private fun ODangNhap(
    gia: String,
    doiGia: (String) -> Unit,
    nhan: String,
    bieuTuong: ImageVector,
    batLoi: Boolean,
    moKhoa: Boolean,
    anChu: Boolean = false,
    cuoiO: @Composable (() -> Unit)? = null,
    cuoiCung: Boolean = false,
    onXong: (() -> Unit)? = null,
) {
    val nguon = remember { MutableInteractionSource() }
    val dangChon by nguon.collectIsFocusedAsState()
    val nenO by animateColorAsState(
        targetValue = when {
            batLoi -> DoNen
            dangChon -> XanhNen
            else -> NEN_O
        },
        animationSpec = tween(220),
        label = "nen-o",
    )
    val mauBieuTuong by animateColorAsState(
        targetValue = when {
            batLoi -> Do
            dangChon -> CHINH
            else -> CHU_MO
        },
        animationSpec = tween(220),
        label = "mau-bieu-tuong",
    )

    TextField(
        value = gia,
        onValueChange = doiGia,
        label = { Text(nhan) },
        singleLine = true,
        enabled = moKhoa,
        isError = batLoi,
        shape = Bo.O,
        interactionSource = nguon,
        leadingIcon = { Icon(bieuTuong, contentDescription = null, tint = mauBieuTuong) },
        trailingIcon = cuoiO,
        visualTransformation =
            if (anChu) PasswordVisualTransformation() else VisualTransformation.None,
        keyboardOptions = KeyboardOptions(
            keyboardType = if (anChu) KeyboardType.Password else KeyboardType.Text,
            imeAction = if (cuoiCung) ImeAction.Done else ImeAction.Next,
        ),
        keyboardActions = KeyboardActions(onDone = { onXong?.invoke() }),
        colors = TextFieldDefaults.colors(
            focusedTextColor = CHU,
            unfocusedTextColor = CHU,
            disabledTextColor = CHU_MO,
            errorTextColor = CHU,
            focusedContainerColor = nenO,
            unfocusedContainerColor = nenO,
            disabledContainerColor = nenO,
            errorContainerColor = nenO,
            // Bỏ hẳn gạch chân của ô kiểu tô nền — nền đã đủ vạch ranh giới.
            focusedIndicatorColor = Color.Transparent,
            unfocusedIndicatorColor = Color.Transparent,
            disabledIndicatorColor = Color.Transparent,
            errorIndicatorColor = Color.Transparent,
            focusedLabelColor = CHINH,
            unfocusedLabelColor = CHU_MO,
            disabledLabelColor = CHU_MO,
            errorLabelColor = Do,
            cursorColor = CHINH,
        ),
        modifier = Modifier.fillMaxWidth(),
    )
}

/** Báo trước sẽ gửi đi chuỗi nào, chỉ hiện khi bỏ dấu xong khác chuỗi đang gõ. */
@Composable
private fun GoiYBoDau(dangGo: String) {
    val sach = boDau(dangGo)
    AnimatedVisibility(
        visible = sach != dangGo.trim() && sach.isNotBlank(),
        enter = fadeIn() + expandVertically(),
        exit = fadeOut() + shrinkVertically(),
    ) {
        Text(
            "Sẽ gửi: $sach",
            color = CHU_MO,
            style = MaterialTheme.typography.labelMedium,
            modifier = Modifier.padding(start = Cach.Vua, top = Cach.Sat),
        )
    }
}
