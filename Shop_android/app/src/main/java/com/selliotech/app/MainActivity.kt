package com.selliotech.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import com.composables.icons.lucide.House
import com.composables.icons.lucide.Lucide
import com.composables.icons.lucide.Package
import com.composables.icons.lucide.ScanBarcode
import com.composables.icons.lucide.Store
import com.composables.icons.lucide.User
import com.composables.icons.lucide.Warehouse
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import com.selliotech.app.ui.theme.Luc
import com.selliotech.app.ui.theme.SelliotechTheme
import com.selliotech.app.ui.theme.TimSellio
import com.selliotech.app.ui.theme.Cam
import com.selliotech.app.ui.theme.Xanh

// Mã tab. Chuỗi chứ không phải enum: mỗi khu có bộ tab riêng, một enum gộp cả
// hai thì luôn có nửa số giá trị vô nghĩa với khu đang đứng.
private const val TAB_TONG_QUAN = "tong-quan"
private const val TAB_HANG_HOA = "hang-hoa"
private const val TAB_TON_KHO = "ton-kho"
private const val TAB_BAN_HANG = "ban-hang"
private const val TAB_TAI_KHOAN = "tai-khoan"

/** Chỗ đang đứng trong app. Bản này chưa cần thư viện điều hướng. */
private sealed interface ManHinh {
    data object DangMo : ManHinh

    data object DangNhap : ManHinh

    data class ChonKhu(val phien: Phien) : ManHinh

    /** Đang làm việc: khung tab của khu, `tab` là tab đang mở. */
    data class Lam(
        val phien: Phien,
        val tab: String,
        /** Bộ lọc mà tab Tồn kho mở lên kèm — đến từ dòng Cần xử lý. */
        val loc: LocTon = LocTon(),
    ) : ManHinh

    /**
     * Quét mã chiếm trọn màn — camera không chia chỗ với thanh tab được.
     *
     * Nhớ `tabVe` vì giờ nút quét nằm trên thanh nổi, tức bấm được từ BẤT KỲ
     * tab nào. Đóng camera phải trả người ta về đúng chỗ họ đứng lúc bấm, chứ
     * không quăng sang một tab khác.
     */
    data class QuetMa(val phien: Phien, val tabVe: String) : ManHinh
}

/** Bộ tab của một khu. Thu ngân và Quản trị là hai bộ khác hẳn nhau. */
private fun tabCuaKhu(khu: String): List<MucTab> = if (khu == Khu.QUAN_LY) {
    listOf(
        MucTab(TAB_TONG_QUAN, "Tổng quan", Lucide.House),
        MucTab(TAB_HANG_HOA, "Hàng hoá", Lucide.Package),
        MucTab(TAB_TON_KHO, "Tồn kho", Lucide.Warehouse),
        MucTab(TAB_TAI_KHOAN, "Tài khoản", Lucide.User),
    )
} else {
    listOf(
        MucTab(TAB_BAN_HANG, "Bán hàng", Lucide.Store),
        MucTab(TAB_TAI_KHOAN, "Tài khoản", Lucide.User),
    )
}

/**
 * Module của một khu — bày trong tấm ứng dụng.
 *
 * Đi cùng `tabCuaKhu`: cùng một mã, cùng một chỗ đến. Ba cái đang có cũng nằm
 * cả trên thanh, nhưng tấm ứng dụng là danh sách ĐẦY ĐỦ nên chúng phải có mặt.
 * Module mới thì thêm một dòng ở đây, chưa cần đụng vào thanh.
 */
private fun ungDungCuaKhu(khu: String): List<OUngDung> = if (khu == Khu.QUAN_LY) {
    listOf(
        OUngDung(TAB_TONG_QUAN, "Tổng quan", Lucide.House, Xanh),
        OUngDung(TAB_HANG_HOA, "Hàng hoá", Lucide.Package, Luc),
        OUngDung(TAB_TON_KHO, "Tồn kho", Lucide.Warehouse, Cam),
        OUngDung(TAB_TAI_KHOAN, "Tài khoản", Lucide.User, TimSellio),
    )
} else {
    listOf(
        OUngDung(TAB_BAN_HANG, "Bán hàng", Lucide.Store, Xanh),
        OUngDung(TAB_TAI_KHOAN, "Tài khoản", Lucide.User, TimSellio),
    )
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            SelliotechTheme {
                val kho = remember { KhoPhien(applicationContext) }
                var dang by remember { mutableStateOf<ManHinh>(ManHinh.DangMo) }

                // Mở app: còn phiên dùng được thì vào thẳng, hết hạn thì lặng lẽ
                // làm mới, hỏng hẳn mới bắt đăng nhập lại.
                LaunchedEffect(Unit) {
                    val cu = kho.doc()
                    dang = when {
                        cu == null -> ManHinh.DangNhap
                        cu.conHan() -> noiVao(kho, cu)
                        else -> {
                            when (val kq = lamMoiToken(cu.refreshToken)) {
                                is KetQuaLamMoi.Xuoi -> {
                                    // Lượt làm mới không trả tên cửa hàng, giữ lấy phần cũ.
                                    val gop = cu.copy(
                                        accessToken = kq.phien.accessToken,
                                        refreshToken = kq.phien.refreshToken,
                                        hetHanLuc = kq.phien.hetHanLuc,
                                    )
                                    kho.ghiTokenMoi(gop.accessToken, gop.refreshToken, gop.hetHanLuc)
                                    noiVao(kho, gop)
                                }

                                // Máy chủ chối refresh token: phiên chết thật.
                                KetQuaLamMoi.PhienChet -> {
                                    kho.xoa()
                                    ManHinh.DangNhap
                                }

                                // Mở app lúc không có mạng: VÀO THẲNG như thường,
                                // giữ nguyên phiên. Mấy màn bên trong sẽ báo lỗi
                                // mạng và tự tải lại khi có sóng — còn đá người ta
                                // ra màn đăng nhập là bắt gõ lại mật khẩu cho một
                                // tài khoản chẳng có vấn đề gì.
                                KetQuaLamMoi.KhongToi -> noiVao(kho, cu)
                            }
                        }
                    }
                }

                val dangXuat = {
                    kho.xoa()
                    dang = ManHinh.DangNhap
                }

                when (val noi = dang) {
                    ManHinh.DangMo -> Box(
                        modifier = Modifier.fillMaxSize(),
                        contentAlignment = Alignment.Center,
                    ) {
                        CircularProgressIndicator()
                    }

                    // Hai màn dưới đây tự tràn hết màn hình, không nằm trong khung
                    // tab: một cái là ảnh nền tràn ra sau thanh trạng thái, cái kia
                    // là khung ngắm camera.
                    ManHinh.DangNhap -> ManHinhDangNhap(
                        kho = kho,
                        onXong = { dang = ManHinh.ChonKhu(it) },
                    )

                    is ManHinh.ChonKhu -> {
                        // Chặn Back ở màn chọn khu: lùi một bước từ đây là về
                        // màn đăng nhập trong khi phiên vẫn còn — người dùng
                        // gõ lại mật khẩu cho một thứ họ chưa hề thoát ra.
                        BackHandler {}
                        ManHinhChonKhu(
                            phien = noi.phien,
                            onChon = { khu ->
                                kho.ghiKhu(khu)
                                dang = noiLamViec(noi.phien.copy(khu = khu))
                            },
                            onDangXuat = dangXuat,
                        )
                    }

                    is ManHinh.QuetMa -> {
                        // Camera chiếm trọn màn nên Back phải trả người ta về
                        // đúng tab đã đứng lúc bấm quét, y như nút Quay lại ở
                        // trên màn — chứ không phải thoát app.
                        BackHandler { dang = ManHinh.Lam(noi.phien, noi.tabVe) }
                        ManHinhQuetMa(
                            kho = kho,
                            onQuayLai = { dang = ManHinh.Lam(noi.phien, noi.tabVe) },
                        )
                    }

                    is ManHinh.Lam -> KhungLamViec(
                        phien = noi.phien,
                        tab = noi.tab,
                        kho = kho,
                        loc = noi.loc,
                        // Bấm thẳng vào tab thì xem CẢ kho, bộ lọc cũ bỏ đi.
                        onChonTab = { dang = ManHinh.Lam(noi.phien, it) },
                        onXemHang = { l -> dang = ManHinh.Lam(noi.phien, TAB_TON_KHO, l) },
                        onQuetMa = { dang = ManHinh.QuetMa(noi.phien, noi.tab) },
                        onDoiKhu = { dang = ManHinh.ChonKhu(noi.phien) },
                        onDangXuat = dangXuat,
                    )
                }
            }
        }
    }
}

/** Khung tab của khu đang đứng, và nội dung của tab đang mở. */
@Composable
private fun KhungLamViec(
    phien: Phien,
    tab: String,
    kho: KhoPhien,
    loc: LocTon,
    onChonTab: (String) -> Unit,
    onXemHang: (LocTon) -> Unit,
    onQuetMa: () -> Unit,
    onDoiKhu: () -> Unit,
    onDangXuat: () -> Unit,
) {
    val tenTiem = phien.tenCuaHang.ifBlank { phien.maCuaHang }
    val laQuanLy = phien.khu == Khu.QUAN_LY
    var moUngDung by remember { mutableStateOf(false) }

    // NÚT BACK: đang ở tab khác thì quay về tab ĐẦU, chỉ ở tab đầu mới thoát app.
    //
    // Trước đây app không xử lý Back chỗ nào cả, nên đứng ở Hàng hoá bấm Back là
    // rơi thẳng ra màn hình chính của máy — mất luôn chỗ đang đứng. Người dùng
    // Android quen Back là "lùi một bước", mà lùi một bước từ Hàng hoá phải là
    // Tổng quan chứ không phải ra khỏi phần mềm.
    //
    // Tấm trượt và hộp thoại KHÔNG cần lo ở đây: chúng là cửa sổ riêng và tự
    // nuốt cú Back của mình trước khi tới lượt chốt này.
    val tabDau = tabCuaKhu(phien.khu).first().ma
    BackHandler(enabled = tab != tabDau) { onChonTab(tabDau) }

    KhungApp(
        // Mũ app nói người này đang đứng ĐÂU: tiệm nào, khu nào. Người trông
        // nhiều tiệm cần thấy điều đó ở mọi màn, không phải chỉ lúc đăng nhập.
        tenCuaHang = tenTiem,
        tenKhu = Khu.ten(phien.khu),
        chuCaiDau = tenTiem.take(1).uppercase(),
        tabs = tabCuaKhu(phien.khu),
        maDangChon = tab,
        onChonTab = onChonTab,
        // Hai khu, hai thứ khác hẳn nhau ở cái nút tròn.
        //
        // Quản trị: ô ứng dụng. Khu này rồi sẽ có kho, khách hàng, nhà cung cấp,
        // sổ quỹ, báo cáo — thanh không đựng nổi, phải có cửa mở ra hết.
        //
        // Thu ngân: quét mã. Cả ca trực họ làm đúng một việc đó, mở thêm một
        // tấm nữa rồi mới bấm được là thừa một cú chạm ở chỗ đang vội nhất.
        nutNoi = if (laQuanLy) {
            NutNoi.UngDung(onBam = { moUngDung = true })
        } else {
            NutNoi.Viec(
                nhan = "Quét mã",
                bieuTuong = Lucide.ScanBarcode,
                moKhoa = !phien.cuaHangKhoa,
                onBam = onQuetMa,
            )
        },
        // Mở được nhiều khu thì cả mũ app thành nút đổi khu. Chỉ một khu thì
        // truyền null: mũ hết mũi tên, hết bấm được — không bày nút chết.
        onDoiKhu = if (phien.cuaVao.size > 1) onDoiKhu else null,
    ) { nen ->
        when (tab) {
            TAB_TONG_QUAN -> ManHinhQuanTri(
                kho = kho,
                modifier = nen,
                onXemHang = onXemHang,
            )

            TAB_HANG_HOA -> ManHinhSanPham(
                kho = kho,
                modifier = nen,
            )

            TAB_TON_KHO -> ManHinhTonKho(
                kho = kho,
                modifier = nen,
                loc = loc,
            )

            TAB_BAN_HANG -> ManHinhChinh(
                phien = phien,
                modifier = nen,
            )

            else -> ManHinhTaiKhoan(
                phien = phien,
                kho = kho,
                modifier = nen,
                onDangXuat = onDangXuat,
            )
        }
    }

    if (moUngDung) {
        TamUngDung(
            tenKhu = Khu.ten(phien.khu),
            dsUngDung = ungDungCuaKhu(phien.khu),
            maDangChon = tab,
            onChon = {
                moUngDung = false
                onChonTab(it)
            },
            onDong = { moUngDung = false },
        )
    }
}

/**
 * Chỗ vào sau khi đã có phiên: đã chọn khu và khu đó còn hợp lệ thì vào thẳng,
 * chưa chọn thì hỏi. Phiên cất từ bản app cũ chưa có cửa vào thì hỏi lại API.
 */
private suspend fun noiVao(kho: KhoPhien, phien: Phien): ManHinh {
    var p = phien
    if (p.cuaVao.isEmpty()) {
        layCuaVao(p.accessToken)?.let {
            p = p.copy(cuaVao = it)
            kho.ghi(p)
        }
    }

    return if (p.khu.isNotBlank() && p.khu in p.cuaVao) noiLamViec(p) else ManHinh.ChonKhu(p)
}

/** Khu nào thì mở tab đầu của khu đó. Đây là chỗ hai cửa rẽ ra hai hướng. */
private fun noiLamViec(phien: Phien): ManHinh =
    ManHinh.Lam(phien, tabCuaKhu(phien.khu).first().ma)
