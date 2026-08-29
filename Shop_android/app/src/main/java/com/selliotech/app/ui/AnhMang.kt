package com.selliotech.app.ui

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.util.LruCache
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.selliotech.app.ui.theme.Bo
import com.selliotech.app.ui.theme.mauPhu
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.ByteArrayOutputStream
import java.net.HttpURLConnection
import java.net.URL

// =====================================================================
//  ẢNH TỪ MẠNG
//
//  Tự dựng chứ không kéo thêm thư viện ảnh: cả app đang gọi mạng bằng
//  HttpURLConnection trần, thêm một thư viện mang theo cả một tầng HTTP
//  thứ hai chỉ để tải mấy cái ảnh vuông 52dp là đổi kiến trúc vì một
//  việc nhỏ. Chỗ này cần đúng ba thứ: tải, thu nhỏ, và nhớ lại.
// =====================================================================

/**
 * Ảnh đã tải, nhớ theo đường dẫn.
 *
 * 8MB là đủ cho vài trăm ô 52dp. Cùng một mặt hàng cuộn qua cuộn lại — hoặc hiện
 * cả ở danh sách lẫn tấm chi tiết — chỉ tải đúng một lần.
 *
 * Đếm theo BYTE THẬT của bitmap chứ không đếm số tấm: một tấm 2000px nặng gấp
 * nghìn lần một tấm 100px, đếm số tấm là hoặc phình bộ nhớ hoặc phí chỗ trống.
 */
private val boNhoAnh = object : LruCache<String, Bitmap>(8 * 1024 * 1024) {
    override fun sizeOf(key: String, value: Bitmap): Int = value.byteCount
}

/** Cạnh dài nhất sau khi thu nhỏ, tính bằng pixel. Ô to nhất trong app là 72dp. */
private const val CANH_TOI_DA = 256

/**
 * Ô ảnh vuông bo góc của một mặt hàng.
 *
 * Chưa có ảnh, đang tải, hay tải hỏng đều rơi về Ô CHỮ CÁI ĐẦU — một ô xám có
 * chữ cái đầu của tên hàng. Ba tình huống ấy cùng một hình là chủ ý: người dùng
 * không cần biết ảnh đang tải hay mặt hàng chưa có ảnh, họ chỉ cần dòng đó vẫn
 * đọc được và danh sách không giật lên giật xuống khi ảnh về.
 *
 * Ô chữ cái để XÁM, không tô màu suy từ tên. Màu trong app này luôn mang nghĩa
 * trạng thái; rải màu ngẫu nhiên lên ô ảnh là lần sau mắt không tin màu nữa.
 *
 * `mo` cho hàng đang ẩn: ảnh nhạt hẳn đi, lướt danh sách là thấy ngay món nào
 * không còn bán mà chưa cần đọc chữ.
 */
@Composable
fun AnhVuong(
    duong: String,
    chuThay: String,
    modifier: Modifier = Modifier,
    bo: Shape = Bo.O,
    mo: Boolean = false,
) {
    // Lấy từ bộ nhớ NGAY trong lượt dựng đầu, không đợi vòng phụ: ảnh đã có sẵn
    // mà vẫn chớp một nhịp ô xám thì cuộn ngược lại danh sách là nháy cả màn.
    var anh by remember(duong) { mutableStateOf(boNhoAnh.get(duong)) }

    LaunchedEffect(duong) {
        if (anh != null || duong.isBlank()) return@LaunchedEffect
        anh = taiAnh(duong)
    }

    Box(
        modifier = modifier
            .clip(bo)
            .background(mauPhu.matChim)
            // Viền mảnh, LUÔN có kể cả khi đã có ảnh. Nền ô chỉ nhạt hơn tấm
            // trắng một chút nên nằm trên đó là tàng hình; còn ảnh mặt hàng thì
            // gần như tấm nào cũng nền trắng, không có viền là nó trôi luôn vào
            // nền và cái ô mất hẳn hình vuông.
            .border(1.dp, mauPhu.vienNhat, bo)
            .alpha(if (mo) 0.45f else 1f),
        contentAlignment = Alignment.Center,
    ) {
        val co = anh
        if (co != null) {
            Image(
                bitmap = co.asImageBitmap(),
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )
        } else {
            Text(
                text = chuDau(chuThay),
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
                color = mauPhu.chuMo,
            )
        }
    }
}

/** Chữ cái đầu, viết hoa. Tên rỗng thì trả một dấu chấm giữa ô cho khỏi trống hoác. */
private fun chuDau(ten: String): String =
    ten.trim().firstOrNull()?.uppercase() ?: "·"

/**
 * Tải một tấm ảnh, thu nhỏ rồi cất vào bộ nhớ.
 *
 * Chỉ nhận đường TUYỆT ĐỐI: ảnh mặt hàng do trang quản trị lưu trên máy chủ web
 * và cất sẵn URL đầy đủ, khác host với API. Gặp đường tương đối thì bỏ qua chứ
 * không tự ghép với host API — ghép bừa là mỗi dòng một lượt gọi 404.
 *
 * Đọc hết vào bộ nhớ rồi mới giải mã hai lượt: lượt đầu chỉ đo khung để biết
 * phải thu nhỏ mấy lần, lượt sau mới dựng bitmap. Giải mã thẳng ảnh gốc rồi thu
 * nhỏ sau là đã trót nuốt cả chục MB cho một ô 52dp.
 */
private suspend fun taiAnh(duong: String): Bitmap? = withContext(Dispatchers.IO) {
    if (!duong.startsWith("http://") && !duong.startsWith("https://")) return@withContext null

    var ket: HttpURLConnection? = null
    try {
        ket = (URL(duong).openConnection() as HttpURLConnection).apply {
            connectTimeout = 10_000
            readTimeout = 10_000
        }
        if (ket.responseCode !in 200..299) return@withContext null

        val byte = ByteArrayOutputStream().use { gom ->
            ket.inputStream.use { it.copyTo(gom) }
            gom.toByteArray()
        }

        val khung = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(byte, 0, byte.size, khung)

        val doc = BitmapFactory.Options().apply { inSampleSize = mucThuNho(khung) }
        val ra = BitmapFactory.decodeByteArray(byte, 0, byte.size, doc)
        if (ra != null) boNhoAnh.put(duong, ra)

        ra
    } catch (e: Exception) {
        null
    } finally {
        ket?.disconnect()
    }
}

/** Số lần chia đôi để cạnh dài nhất không vượt `CANH_TOI_DA`. Luôn là luỹ thừa của 2. */
private fun mucThuNho(khung: BitmapFactory.Options): Int {
    var muc = 1
    var canh = maxOf(khung.outWidth, khung.outHeight)
    while (canh / 2 > CANH_TOI_DA) {
        canh /= 2
        muc *= 2
    }

    return muc
}
