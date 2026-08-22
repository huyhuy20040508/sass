package com.selliotech.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Scaffold
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import com.selliotech.app.ui.theme.SelliotechTheme

/** Chỗ đang đứng trong app. Bản này chưa cần thư viện điều hướng. */
private sealed interface ManHinh {
    data object DangMo : ManHinh

    data object DangNhap : ManHinh

    data class Chinh(val phien: Phien) : ManHinh

    data class QuetMa(val phien: Phien) : ManHinh
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
                        cu.conHan() -> ManHinh.Chinh(cu)
                        else -> {
                            val moi = lamMoiToken(cu.refreshToken)
                            if (moi == null) {
                                kho.xoa()
                                ManHinh.DangNhap
                            } else {
                                // Lượt làm mới không trả tên cửa hàng, giữ lấy phần cũ.
                                val gop = cu.copy(
                                    accessToken = moi.accessToken,
                                    refreshToken = moi.refreshToken,
                                    hetHanLuc = moi.hetHanLuc,
                                )
                                kho.ghiTokenMoi(gop.accessToken, gop.refreshToken, gop.hetHanLuc)
                                ManHinh.Chinh(gop)
                            }
                        }
                    }
                }

                Scaffold(modifier = Modifier.fillMaxSize()) { dem ->
                    val nen = Modifier.padding(dem)
                    when (val noi = dang) {
                        ManHinh.DangMo -> Box(
                            modifier = nen.fillMaxSize(),
                            contentAlignment = Alignment.Center,
                        ) {
                            CircularProgressIndicator()
                        }

                        // Không truyền `nen`: ảnh nền phải tràn ra sau thanh trạng
                        // thái, màn này tự chừa lề cho phần chữ.
                        ManHinh.DangNhap -> ManHinhDangNhap(
                            kho = kho,
                            onXong = { dang = ManHinh.Chinh(it) },
                        )

                        is ManHinh.Chinh -> ManHinhChinh(
                            phien = noi.phien,
                            kho = kho,
                            modifier = nen,
                            onQuetMa = { dang = ManHinh.QuetMa(noi.phien) },
                            onDangXuat = {
                                kho.xoa()
                                dang = ManHinh.DangNhap
                            },
                        )

                        is ManHinh.QuetMa -> ManHinhQuetMa(
                            kho = kho,
                            modifier = nen,
                            onQuayLai = { dang = ManHinh.Chinh(noi.phien) },
                        )
                    }
                }
            }
        }
    }
}
