package com.selliotech.app

import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Luật ở đây chép từ `domain.CuaVao` bên API. Hai bản lệch nhau là app cho vào
 * khu mà máy chủ chặn, nên giữ bộ kiểm này khớp với entities_test bên Go.
 */
class KhuLamViecTest {

    @Test
    fun `doc dung cot access_areas`() {
        assertEquals(listOf("quan_ly"), cuaVao("quan_ly", 0))
        assertEquals(listOf("thu_ngan"), cuaVao("thu_ngan", 0))
        assertEquals(listOf("quan_ly", "thu_ngan"), cuaVao("quan_ly,thu_ngan", 0))
    }

    @Test
    fun `bo qua cua la`() {
        assertEquals(listOf("thu_ngan"), cuaVao("ke_toan,thu_ngan", 0))
        assertEquals(emptyList<String>(), cuaVao("ke_toan", 0))
    }

    @Test
    fun `cot rong thi suy tu vai tro`() {
        // Tài khoản có trước migration 0015: suy chứ không trả rỗng, trả rỗng là
        // khoá cứng mọi tài khoản cũ ra ngoài.
        assertEquals(listOf("quan_ly", "thu_ngan"), cuaVao("", 1))
        assertEquals(listOf("quan_ly", "thu_ngan"), cuaVao("", 2))
        assertEquals(listOf("thu_ngan"), cuaVao("", 3))
        // Khách mua sắm không có cửa nào vào app bán hàng.
        assertEquals(emptyList<String>(), cuaVao("", 4))
    }

    @Test
    fun `khoang trang thua khong lam hong`() {
        assertEquals(listOf("quan_ly", "thu_ngan"), cuaVao(" quan_ly , thu_ngan ", 0))
    }
}
