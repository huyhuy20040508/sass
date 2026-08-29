package com.selliotech.app

import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Phân loại một lượt trả lời của đường làm mới token.
 *
 * Kiểm riêng phần PHÂN LOẠI vì đây là chỗ đã hỏng rất nặng: bản cũ gộp "máy chủ
 * chối" với "không gọi tới được" thành cùng một giá trị null, rồi chỗ gọi thấy
 * null là xoá sạch phiên — người bán đi vào góc khuất sóng đúng lúc token hết
 * hạn thì bị đăng xuất giữa ca.
 *
 * Bộ kiểm này không gọi mạng: nó đối chiếu đúng cái luật "mã nào thì coi là
 * phiên chết", tức là chốt duy nhất ngăn lỗi ấy quay lại.
 */
class LamMoiTokenTest {

    @Test
    fun `khong cham toi may chu la KHONG TOI, khong phai phien chet`() {
        // ma = 0 nghĩa là chưa hề nói chuyện được với máy chủ.
        assertEquals(KetQuaLamMoi.KhongToi, phanLoaiLamMoi(0))
    }

    @Test
    fun `may chu dang hong la KHONG TOI`() {
        // 5xx là máy chủ đang lỗi, nó không nói gì về phiên của người dùng cả.
        assertEquals(KetQuaLamMoi.KhongToi, phanLoaiLamMoi(500))
        assertEquals(KetQuaLamMoi.KhongToi, phanLoaiLamMoi(502))
        assertEquals(KetQuaLamMoi.KhongToi, phanLoaiLamMoi(503))
    }

    @Test
    fun `may chu tra loi va choi la PHIEN CHET`() {
        assertEquals(KetQuaLamMoi.PhienChet, phanLoaiLamMoi(401))
        assertEquals(KetQuaLamMoi.PhienChet, phanLoaiLamMoi(403))
        assertEquals(KetQuaLamMoi.PhienChet, phanLoaiLamMoi(422))
    }

    @Test
    fun `refresh token rong thi khoi goi, coi nhu phien chet`() {
        assertEquals(KetQuaLamMoi.PhienChet, phanLoaiLamMoi(200, refreshRong = true))
    }

    @Test
    fun `xuoi thi khong roi vao hai nhanh hong nao`() {
        // 2xx phải đi tiếp tới bước đọc token, không được coi là hỏng.
        assertEquals(null, phanLoaiLamMoi(200))
        assertEquals(null, phanLoaiLamMoi(201))
    }
}
