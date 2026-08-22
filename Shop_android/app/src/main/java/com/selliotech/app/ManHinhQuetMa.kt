package com.selliotech.app

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.annotation.OptIn
import androidx.camera.core.CameraSelector
import androidx.camera.core.ExperimentalGetImage
import androidx.camera.core.ImageAnalysis
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.google.mlkit.vision.barcode.BarcodeScanner
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.common.InputImage
import kotlinx.coroutines.launch
import java.util.concurrent.Executors
import androidx.camera.core.Preview as XemTruocCamera

/**
 * Màn quét mã tại quầy: camera đọc mã vạch rồi gửi thẳng sang /admin/orders/pos/scan.
 *
 * Có thêm ô gõ tay để thử được cả trên máy ảo — máy ảo không có mã vạch để chĩa vào.
 */
@Composable
fun ManHinhQuetMa(kho: KhoPhien, modifier: Modifier = Modifier, onQuayLai: () -> Unit) {
    val boiCanh = LocalContext.current
    val chuVongDoi = LocalLifecycleOwner.current
    val pham = rememberCoroutineScope()

    var choPhep by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(boiCanh, Manifest.permission.CAMERA) ==
                PackageManager.PERMISSION_GRANTED,
        )
    }
    val xinPhep = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        choPhep = it
    }
    LaunchedEffect(Unit) { if (!choPhep) xinPhep.launch(Manifest.permission.CAMERA) }

    var maVuaDoc by remember { mutableStateOf("") }
    var maGoTay by remember { mutableStateOf("") }
    var dangTra by remember { mutableStateOf(false) }
    var ketQua by remember { mutableStateOf<KetQuaQuetMa?>(null) }

    // Quét trúng cùng một mã liên tiếp thì bỏ qua: ML Kit bắn ra hàng chục lần
    // mỗi giây, gọi hết là dội API.
    fun tra(ma: String) {
        if (ma.isBlank() || dangTra || ma == maVuaDoc) return
        maVuaDoc = ma
        dangTra = true
        pham.launch {
            ketQua = quetMa(kho, ma)
            dangTra = false
        }
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("Quét mã tại quầy", style = MaterialTheme.typography.headlineSmall)

        if (choPhep) {
            KhungCamera(
                chuVongDoi = chuVongDoi,
                khiDocDuoc = { tra(it) },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(280.dp),
            )
        } else {
            Text("Chưa được cấp quyền camera.")
            Button(onClick = { xinPhep.launch(Manifest.permission.CAMERA) }) {
                Text("Xin quyền camera")
            }
        }

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(
                value = maGoTay,
                onValueChange = { maGoTay = it },
                label = { Text("Gõ tay mã hàng") },
                singleLine = true,
                modifier = Modifier.weight(1f),
            )
            Button(
                onClick = {
                    // Xoá mã cũ để tra lại đúng mã vừa gõ dù nó trùng lần trước.
                    maVuaDoc = ""
                    tra(maGoTay.trim())
                },
                enabled = !dangTra && maGoTay.isNotBlank(),
            ) {
                Text("Tra")
            }
        }

        if (maVuaDoc.isNotBlank()) {
            Text("Mã vừa đọc: $maVuaDoc", style = MaterialTheme.typography.bodySmall)
        }
        if (dangTra) {
            Text("Đang tra...")
        }

        when (val kq = ketQua) {
            null -> Unit
            is KetQuaQuetMa.KhongThay -> KhungKetQuaQuet("Không ra hàng — ${kq.loi}", kq.nguyenVan)
            is KetQuaQuetMa.ThayHang -> KhungKetQuaQuet(
                tieuDe = "Ra hàng: ${kq.ten.ifBlank { "(không có trường tên)" }} · " +
                    "giá ${kq.gia.ifBlank { "?" }} · tồn ${kq.ton.ifBlank { "?" }}",
                noiDung = kq.nguyenVan,
            )
        }

        OutlinedButton(onClick = onQuayLai, modifier = Modifier.fillMaxWidth()) {
            Text("Quay lại")
        }
    }
}

/** Khung xem trước của camera, có bộ đọc mã gắn sẵn vào luồng hình. */
@Composable
private fun KhungCamera(
    chuVongDoi: LifecycleOwner,
    khiDocDuoc: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    val docMa = remember { BarcodeScanning.getClient() }
    val mayDoc = remember { Executors.newSingleThreadExecutor() }
    var nhaCamera by remember { mutableStateOf<ProcessCameraProvider?>(null) }

    DisposableEffect(Unit) {
        onDispose {
            nhaCamera?.unbindAll()
            mayDoc.shutdown()
            docMa.close()
        }
    }

    AndroidView(
        modifier = modifier,
        factory = { boiCanh ->
            val khung = PreviewView(boiCanh).apply {
                scaleType = PreviewView.ScaleType.FILL_CENTER
            }

            val chuaNha = ProcessCameraProvider.getInstance(boiCanh)
            chuaNha.addListener({
                val nha = chuaNha.get()
                nhaCamera = nha

                val xemTruoc = XemTruocCamera.Builder().build().also {
                    it.setSurfaceProvider(khung.surfaceProvider)
                }
                val docHinh = ImageAnalysis.Builder()
                    // Chỉ giữ khung mới nhất: đọc chậm hơn quay thì hàng đợi phình,
                    // mã hiện lên sau khi người ta đã rút món hàng ra khỏi tầm camera.
                    .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                    .build()
                    .also { it.setAnalyzer(mayDoc, boDocMa(docMa, khiDocDuoc)) }

                nha.unbindAll()
                nha.bindToLifecycle(
                    chuVongDoi,
                    CameraSelector.DEFAULT_BACK_CAMERA,
                    xemTruoc,
                    docHinh,
                )
            }, ContextCompat.getMainExecutor(boiCanh))

            khung
        },
    )
}

/** Mỗi khung hình đưa qua ML Kit; đọc ra mã nào thì bắn lên trên. */
@OptIn(ExperimentalGetImage::class)
private fun boDocMa(docMa: BarcodeScanner, khiDocDuoc: (String) -> Unit) =
    ImageAnalysis.Analyzer { anh ->
        val goc = anh.image
        if (goc == null) {
            anh.close()

            return@Analyzer
        }

        docMa.process(InputImage.fromMediaImage(goc, anh.imageInfo.rotationDegrees))
            .addOnSuccessListener { ds -> ds.firstOrNull()?.rawValue?.let(khiDocDuoc) }
            // Không đóng ảnh là camera đứng hình sau vài khung.
            .addOnCompleteListener { anh.close() }
    }

@Composable
private fun KhungKetQuaQuet(tieuDe: String, noiDung: String) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(tieuDe, style = MaterialTheme.typography.titleSmall)
        if (noiDung.isNotBlank()) {
            Text(noiDung, style = MaterialTheme.typography.bodySmall)
        }
    }
}
