package com.selliotech.app

import com.selliotech.app.ui.nhomChuSo
import com.selliotech.app.ui.soChamTruoc
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Hai chỗ dễ sai lặng lẽ của biểu mẫu khai hàng: đường dẫn sinh từ tên, và dấu
 * chấm phân nhóm của ô tiền.
 *
 * Sai chỗ đầu thì API trả 422 mà người dùng không hiểu vì sao; sai chỗ sau thì
 * người bán đọc nhầm giá một chữ số — mà không có gì báo cho họ biết.
 */
class ThemHangTest {

    // ---- Đường dẫn sinh từ tên -------------------------------------

    @Test
    fun `bo dau, ha chu thuong, noi bang gach`() {
        assertEquals("but-bi-thien-long", slugTu("Bút bi Thiên Long"))
        assertEquals("ca-phe-sua-da", slugTu("Cà phê sữa đá"))
        assertEquals("iphone-16-pro-max", slugTu("Iphone 16 pro max"))
    }

    @Test
    fun `khong de gach thua o dau, cuoi hay giua`() {
        assertEquals("ao-thun", slugTu("  Áo   thun  "))
        assertEquals("ao-thun", slugTu("--Áo--thun--"))
        assertEquals("combo-2-mon", slugTu("Combo (2 món)"))
    }

    @Test
    fun `chu d co gach van thanh chu d thuong`() {
        // "đ" phải ra "d" chứ không bị rụng mất: "Đèn" mà thành "en" là đường
        // dẫn chẳng ai tra ngược lại được.
        assertEquals("den-pin", slugTu("Đèn pin"))
        assertEquals("dua-an", slugTu("Đũa ăn"))
    }

    @Test
    fun `ten khong co chu so nao thi tra rong`() {
        // Trả rỗng để nơi gọi CHẶN LẠI, chứ không bịa ra một slug ngẫu nhiên mà
        // mai mốt không ai tra ngược được về mặt hàng nào.
        assertEquals("", slugTu("???"))
        assertEquals("", slugTu("   "))
    }

    // ---- Ô nhập tiền -----------------------------------------------

    @Test
    fun `cham phan nhom nghin`() {
        assertEquals("", nhomChuSo(""))
        assertEquals("5", nhomChuSo("5"))
        assertEquals("999", nhomChuSo("999"))
        assertEquals("1.000", nhomChuSo("1000"))
        assertEquals("25.000", nhomChuSo("25000"))
        assertEquals("250.000", nhomChuSo("250000"))
        assertEquals("1.250.000", nhomChuSo("1250000"))
        assertEquals("28.000.000", nhomChuSo("28000000"))
    }

    @Test
    fun `con tro nhay dung cho qua dau cham`() {
        // "1250000" -> "1.250.000": dấu chấm nằm sau chữ số thứ 1 và thứ 4.
        assertEquals(0, soChamTruoc(7, 0))
        assertEquals(0, soChamTruoc(7, 1))
        assertEquals(1, soChamTruoc(7, 2))
        assertEquals(1, soChamTruoc(7, 4))
        assertEquals(2, soChamTruoc(7, 5))
        assertEquals(2, soChamTruoc(7, 7))
    }

    @Test
    fun `so ngan hon bon chu so thi khong co dau cham nao`() {
        assertEquals(0, soChamTruoc(3, 3))
        assertEquals(0, soChamTruoc(1, 1))
    }

    @Test
    fun `vi tri con tro luon nam trong chuoi da cham`() {
        // Cộng bù sai một nấc là gõ tới chữ số thứ tư con trỏ nhảy lùi, và mọi
        // chữ số sau đó vào sai chỗ. Kiểm mọi vị trí của vài độ dài hay gặp.
        listOf(4, 5, 6, 7, 9).forEach { n ->
            val so = "1".repeat(n)
            val hien = nhomChuSo(so)
            (0..n).forEach { i ->
                val ra = i + soChamTruoc(n, i)
                assertTrue("n=$n i=$i ra=$ra", ra in 0..hien.length)
                // Số chữ số bên trái con trỏ phải giữ nguyên sau khi đổi dạng.
                assertEquals(i, hien.take(ra).count(Char::isDigit))
            }
        }
    }

    // ---- Giá sau thuế ----------------------------------------------

    @Test
    fun `gia sau thue nhan them phan tram`() {
        assertEquals(110_000.0, giaSauThue(100_000.0, 10)!!, 0.01)
        assertEquals(105_000.0, giaSauThue(100_000.0, 5)!!, 0.01)
    }

    @Test
    fun `khong tinh duoc thi tra null chu khong bia so`() {
        // Chưa chọn mức thuế: mức thật nằm ở nhóm hàng mà app chưa biết.
        assertEquals(null, giaSauThue(100_000.0, null))
        // 0% thì giá sau thuế bằng giá bán, chẳng có gì để nói thêm.
        assertEquals(null, giaSauThue(100_000.0, 0))
        // KCT và KKKNT là MÃ, không phải phần trăm — nhân vào là ra số âm.
        assertEquals(null, giaSauThue(100_000.0, -1))
        assertEquals(null, giaSauThue(100_000.0, -2))
    }

    // ---- Tổ hợp biến thể -------------------------------------------

    private val dungLuong = ThuocTinhHang(
        id = 1,
        ten = "Dung lượng",
        giaTri = listOf(OChon(11, "", "128GB"), OChon(12, "", "256GB")),
    )
    private val mau = ThuocTinhHang(
        id = 2,
        ten = "Màu",
        giaTri = listOf(OChon(21, "", "Đen"), OChon(22, "", "Trắng")),
    )

    @Test
    fun `nhan hai chieu ra du bon to hop, dung thu tu`() {
        val ra = toHopBienThe(listOf(dungLuong, mau), mapOf(1L to listOf(11L, 12L), 2L to listOf(21L, 22L)))

        assertEquals(
            listOf("128GB · Đen", "128GB · Trắng", "256GB · Đen", "256GB · Trắng"),
            ra.map { it.ten },
        )
        // Mỗi tổ hợp mang đủ cặp (thuộc tính, giá trị) để máy chủ dựng lại được.
        assertEquals(listOf(1L to 11L, 2L to 21L), ra[0].chon)
    }

    @Test
    fun `thuoc tinh khong tick gi thi bo qua, khong nhan voi rong`() {
        // Nhân với rỗng là ra KHÔNG tổ hợp nào và cả khối biến thể biến mất
        // trong im lặng — người dùng bấm Lưu rồi mới biết chẳng có gì được tạo.
        val ra = toHopBienThe(listOf(dungLuong, mau), mapOf(1L to listOf(11L, 12L)))

        assertEquals(listOf("128GB", "256GB"), ra.map { it.ten })
    }

    @Test
    fun `khong tick gi ca thi khong co to hop nao`() {
        assertEquals(emptyList<BienTheMoi>(), toHopBienThe(listOf(dungLuong, mau), emptyMap()))
    }

    @Test
    fun `gia tri la khong thuoc thuoc tinh nao thi bo qua`() {
        // Id rác lọt vào (dữ liệu cũ, hoặc thuộc tính vừa bị xoá) không được
        // sinh ra một tổ hợp không tên.
        val ra = toHopBienThe(listOf(dungLuong), mapOf(1L to listOf(11L, 999L)))

        assertEquals(listOf("128GB"), ra.map { it.ten })
    }

    // ---- Ô bắt buộc ------------------------------------------------

    @Test
    fun `thieu mot o chinh la chua luu duoc`() {
        val du = HangMoi(ten = "Bút bi", nhomId = 3, giaBan = 5000.0)
        assertTrue(du.duOChinh)
        assertTrue(!du.copy(ten = " ").duOChinh)
        assertTrue(!du.copy(nhomId = 0).duOChinh)
    }
}
