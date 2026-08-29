package com.selliotech.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Bộ lọc của màn Hàng hoá.
 *
 * Kiểm ở đây vì đây là chỗ hỏng lặng lẽ nhất: gửi thiếu một tham số thì API vẫn
 * đáp 200 với một danh sách rỗng, và trên máy nó nhìn y hệt một cái kho không có
 * hàng. Không có bộ kiểm này thì phải có người đi đếm hàng thật mới phát hiện ra.
 */
class LocTonKhoTest {

    @Test
    fun `mac dinh gui du trang, co trang, sap xep va nguong`() {
        val d = duongTonKho(LocTon())

        assertEquals(
            "/admin/inventory?page=1&page_size=30&sort=stock_asc&low_stock=5",
            d,
        )
    }

    @Test
    fun `loc sap het luon di kem nguong`() {
        // low_stock là NGƯỠNG của bộ lọc "sắp hết". Thiếu nó thì API lấy 0 và
        // không dòng nào lọt.
        val d = duongTonKho(LocTon(ton = "low"))

        assertTrue(d.contains("&stock=low"))
        assertTrue(d.contains("&low_stock=5"))
    }

    @Test
    fun `tu khoa duoc ma hoa`() {
        val d = duongTonKho(LocTon(), tuKhoa = "bút bi xanh")

        assertTrue(d.contains("&keyword=b%C3%BAt+bi+xanh"))
    }

    @Test
    fun `gop du dieu kien`() {
        val d = duongTonKho(
            LocTon(
                ton = "out",
                giaVon = "missing",
                danhMucId = 7,
                dangBan = false,
                xep = XepTon.GIA_TRI,
            ),
            trang = 3,
        )

        assertTrue(d.contains("page=3"))
        assertTrue(d.contains("&sort=value_desc"))
        assertTrue(d.contains("&stock=out"))
        assertTrue(d.contains("&cost=missing"))
        assertTrue(d.contains("&category_id=7"))
        assertTrue(d.contains("&is_active=false"))
    }

    @Test
    fun `dieu kien tat khong gui gi`() {
        val d = duongTonKho(LocTon())

        // "&stock=" chứ không phải "stock=": chuỗi mặc định luôn có low_stock.
        assertTrue(!d.contains("&stock="))
        assertTrue(!d.contains("&cost="))
        assertTrue(!d.contains("category_id"))
        assertTrue(!d.contains("is_active"))
    }

    @Test
    fun `dem dung so dieu kien, sap xep khong tinh`() {
        assertEquals(0, LocTon().soDieuKien)
        assertEquals(0, LocTon(xep = XepTon.TEN_AZ).soDieuKien)
        assertEquals(2, LocTon(ton = "low", dangBan = true).soDieuKien)
        assertEquals(4, LocTon(ton = "low", giaVon = "set", danhMucId = 3, dangBan = false).soDieuKien)
    }

    @Test
    fun `chip go dung dieu kien cua no, cac dieu kien khac giu nguyen`() {
        val loc = LocTon(ton = "out", danhMucId = 9, danhMucTen = "Đồ uống", xep = XepTon.MOI)
        val chips = loc.chips()

        assertEquals(listOf("Hết hàng", "Đồ uống"), chips.map { it.nhan })
        // Gỡ chip tồn thì nhóm hàng và kiểu sắp xếp còn nguyên.
        assertEquals(LocTon(danhMucId = 9, danhMucTen = "Đồ uống", xep = XepTon.MOI), chips[0].con)
        assertEquals(LocTon(ton = "out", xep = XepTon.MOI), chips[1].con)
    }

    @Test
    fun `xep cay dat con ngay sau cha`() {
        val phang = listOf(
            DanhMuc(1, "Đồ uống"),
            DanhMuc(2, "Bánh kẹo"),
            DanhMuc(3, "Nước ngọt", cha = 1),
            DanhMuc(4, "Nước có ga", cha = 3),
        )

        assertEquals(
            listOf("Đồ uống" to 0, "Nước ngọt" to 1, "Nước có ga" to 2, "Bánh kẹo" to 0),
            xepCay(phang).map { it.ten to it.bac },
        )
    }

    @Test
    fun `nhom co cha da bien mat van lot ra ngoai`() {
        // Cha bị xoá mà con vẫn còn: bỏ con đi là mất hẳn một nhóm khỏi bộ lọc
        // mà chẳng ai hiểu vì sao.
        val phang = listOf(DanhMuc(5, "Mồ côi", cha = 99))

        assertEquals(listOf("Mồ côi"), xepCay(phang).map { it.ten })
    }

    @Test
    fun `du lieu vong khong lam treo app`() {
        val phang = listOf(DanhMuc(1, "A", cha = 2), DanhMuc(2, "B", cha = 1))

        // Không dòng nào là gốc nên chẳng ra được gì — nhưng phải TRẢ VỀ, chứ
        // không phải đệ quy tới lúc tràn ngăn xếp.
        assertEquals(emptyList<DanhMuc>(), xepCay(phang))
    }
}
