package com.selliotech.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Đọc và ghi CSV.
 *
 * Kiểm kỹ vì đây là chỗ hỏng ÂM THẦM: cắt sai một ô là cả dòng lệch cột kể từ
 * đó, tệp vẫn nhập vào được, dữ liệu vào sai chỗ mà chẳng có gì báo.
 */
class TepCsvTest {

    // ---- Ghi ------------------------------------------------------

    @Test
    fun `chi boc nhay khi that su can`() {
        assertEquals("Bút bi", oCsv("Bút bi"))
        assertEquals("\"Bút bi, hộp 10\"", oCsv("Bút bi, hộp 10"))
        assertEquals("\"Ống 5\"\"\"", oCsv("Ống 5\""))
    }

    @Test
    fun `dong ket thuc bang CRLF cho Excel`() {
        assertEquals("a,b\r\n", dongCsv(listOf("a", "b")))
    }

    // ---- Đọc ------------------------------------------------------

    @Test
    fun `dau phay trong ten hang khong lam lech cot`() {
        // Cắt bừa theo dấu phẩy là dòng này ra 3 ô và mọi cột sau đó lệch hết.
        assertEquals(
            listOf("Bút bi, hộp 10", "4", "CAI"),
            tachDongCsv("\"Bút bi, hộp 10\",4,CAI"),
        )
    }

    @Test
    fun `hai nhay lien nhau trong o la mot nhay that`() {
        assertEquals(listOf("Ống 5\"", "2"), tachDongCsv("\"Ống 5\"\"\",2"))
    }

    @Test
    fun `o rong van giu dung vi tri`() {
        assertEquals(listOf("a", "", "c"), tachDongCsv("a,,c"))
    }

    // ---- Số tiền từ ô Excel ---------------------------------------

    @Test
    fun `dau cham la phan nhom nghin, khong phai thap phan`() {
        // "22.000.000" phải ra 22 triệu. Hiểu thành 22 đồng là mặt hàng nhập vào
        // với giá sai một triệu lần mà không ai thấy.
        assertEquals(22_000_000.0, soTuChuoi("22.000.000"), 0.01)
        assertEquals(120_000.0, soTuChuoi("120,000 đ"), 0.01)
        assertEquals(0.0, soTuChuoi(""), 0.01)
    }

    // ---- Đọc cả tệp -----------------------------------------------

    private val donVi = mapOf("CAI" to 7L)
    private val viTri = mapOf("VT001" to 3L)

    private fun tep(vararg dong: String) = BOM + dongCsv(COT_NHAP) + dong.joinToString("")

    @Test
    fun `doc duoc dong day du`() {
        val kq = docTepNhap(
            tep(dongCsv(listOf("Bút bi", "4", "CAI", "VT001", "10", "5000", "3000", "Hot", "Đen,Trắng"))),
            donVi,
            viTri,
        )

        assertEquals(emptyList<String>(), kq.loi)
        assertEquals(1, kq.dong.size)
        val h = kq.dong[0].hang
        assertEquals("Bút bi", h.ten)
        assertEquals(4L, h.nhomId)
        assertEquals(7L, h.donViId)
        assertEquals(3L, h.viTriId)
        assertEquals(10, h.vat)
        assertEquals(5000.0, h.giaBan, 0.01)
        assertEquals(listOf("Hot"), h.the)
        assertEquals(listOf("Đen", "Trắng"), h.bienTheTen)
    }

    @Test
    fun `ma don vi la thi bao loi dong do, khong lang le bo trong`() {
        // Người dùng khai một đơn vị cụ thể; nhập xong mà mất thì họ không biết
        // để đi sửa.
        val kq = docTepNhap(
            tep(dongCsv(listOf("Bút bi", "4", "THUNG", "", "", "5000", "", "", ""))),
            donVi,
            viTri,
        )

        assertEquals(0, kq.dong.size)
        assertTrue(kq.loi.single().contains("THUNG"))
        assertTrue(kq.loi.single().contains("Dòng 2"))
    }

    @Test
    fun `thieu category_id thi bao loi dung so dong`() {
        val kq = docTepNhap(
            tep(
                dongCsv(listOf("Bút bi", "4", "", "", "", "5000", "", "", "")),
                dongCsv(listOf("Ốp lưng", "", "", "", "", "1000", "", "", "")),
            ),
            donVi,
            viTri,
        )

        assertEquals(1, kq.dong.size)
        assertTrue(kq.loi.single().contains("Dòng 3"))
    }

    @Test
    fun `dong trong giua tep thi bo qua im lang`() {
        // Excel hay để lại dòng trắng ở cuối; báo lỗi cho nó là gây hoang mang.
        val kq = docTepNhap(
            tep("\r\n", dongCsv(listOf("", "", "", "", "", "", "", "", ""))),
            donVi,
            viTri,
        )

        assertEquals(0, kq.dong.size)
        assertEquals(emptyList<String>(), kq.loi)
    }

    @Test
    fun `thieu cot bat buoc thi tu choi ca tep`() {
        val kq = docTepNhap(
            BOM + dongCsv(listOf("ten", "gia")) + dongCsv(listOf("Bút", "5")),
            donVi,
            viTri,
        )

        assertEquals(0, kq.dong.size)
        assertTrue(kq.loi.single().contains("name"))
    }

    @Test
    fun `tep mau co du cot va tu nhap lai duoc`() {
        val d = tepMauNhap().removePrefix(BOM).trim().split("\r\n")

        assertEquals(3, d.size)
        assertEquals(COT_NHAP, tachDongCsv(d[0]))
        // Đọc lại chính tệp mẫu phải ra được dòng dùng được — mẫu mà không tự
        // nhập lại được thì nó sai ngay từ đầu.
        val kq = docTepNhap(tepMauNhap(), donVi, viTri)
        assertEquals(2, kq.dong.size)
        assertEquals(emptyList<String>(), kq.loi)
    }
}
