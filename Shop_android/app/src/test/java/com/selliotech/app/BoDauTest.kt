package com.selliotech.app

import org.junit.Assert.assertEquals
import org.junit.Test

class BoDauTest {

    @Test
    fun `bo dau tieng viet`() {
        assertEquals("test", boDau("tést"))
        assertEquals("admin", boDau("admín"))
        assertEquals("dang", boDau("đăng"))
        assertEquals("Dong", boDau("Đông"))
    }

    @Test
    fun `chu khong dau giu nguyen`() {
        assertEquals("test", boDau("test"))
        assertEquals("order1", boDau("order1"))
    }

    @Test
    fun `cat khoang trang thua`() {
        assertEquals("test", boDau("  test  "))
    }
}
