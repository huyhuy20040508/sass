package com.selliotech.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Bộ lọc của trang Hàng hoá.
 *
 * Chỗ hỏng lặng lẽ nhất nằm ở `all=true`: thiếu nó thì API chỉ trả mặt hàng đang
 * bán, bộ lọc "Tạm ẩn" luôn ra rỗng mà vẫn đáp 200 — trông y hệt một cửa hàng
 * không có mặt hàng ẩn nào. Không ai phát hiện ra cho tới lúc đi tìm một món đã
 * ẩn và không thấy đâu.
 */
class LocMatHangTest {

    @Test
    fun `mac dinh gui du trang, co trang, sap xep va all`() {
        assertEquals(
            "/products?page=1&page_size=20&sort=newest&all=true",
            duongMatHang(LocMatHang()),
        )
    }

    @Test
    fun `nhom hang chon nhieu, gui ngan bang dau phay`() {
        val d = duongMatHang(
            LocMatHang(nhom = listOf(DanhMuc(3, "Đồ uống"), DanhMuc(7, "Bánh kẹo"))),
        )

        assertTrue(d.contains("&category_ids=3,7"))
    }

    @Test
    fun `trang thai chon nhieu, luon dung mot thu tu`() {
        // Set không hứa thứ tự. Hai bộ lọc giống hệt nhau mà ra hai đường khác
        // nhau là bộ nhớ đệm trượt, và lúc soi log thì không đối chiếu được.
        val xuoi = duongMatHang(
            LocMatHang(trangThai = setOf(TrangThaiHang.DANG_BAN, TrangThaiHang.NGUNG)),
        )
        val nguoc = duongMatHang(
            LocMatHang(trangThai = setOf(TrangThaiHang.NGUNG, TrangThaiHang.DANG_BAN)),
        )

        assertEquals(xuoi, nguoc)
        assertTrue(xuoi.contains("&statuses=active,discontinued"))
    }

    @Test
    fun `chua gan vi tri la mot lua chon that`() {
        // 'none' phải đi nguyên dạng chuỗi tới API. Ép sang số là nó hoá 0 và bộ
        // lọc "chưa gán vị trí" biến mất trong im lặng.
        assertTrue(duongMatHang(LocMatHang(viTri = "none")).contains("&location_id=none"))
        assertTrue(duongMatHang(LocMatHang(viTri = "12")).contains("&location_id=12"))
    }

    @Test
    fun `gop du dieu kien`() {
        val d = duongMatHang(
            LocMatHang(
                nhom = listOf(DanhMuc(2, "Điện thoại")),
                trangThai = setOf(TrangThaiHang.TAM_AN),
                donViId = 4,
                viTri = "9",
                nhieuBienThe = true,
                xep = XepHangHoa.GIA_GIAM,
            ),
            tuKhoa = "bút bi",
            trang = 3,
        )

        assertTrue(d.contains("page=3"))
        assertTrue(d.contains("&sort=price_desc"))
        assertTrue(d.contains("&keyword=b%C3%BAt+bi"))
        assertTrue(d.contains("&category_ids=2"))
        assertTrue(d.contains("&statuses=hidden"))
        assertTrue(d.contains("&unit_id=4"))
        assertTrue(d.contains("&location_id=9"))
        assertTrue(d.contains("&multi_variant=true"))
    }

    @Test
    fun `dieu kien tat khong gui gi`() {
        val d = duongMatHang(LocMatHang())

        assertTrue(!d.contains("keyword"))
        assertTrue(!d.contains("category_ids"))
        assertTrue(!d.contains("statuses"))
        assertTrue(!d.contains("unit_id"))
        assertTrue(!d.contains("location_id"))
        assertTrue(!d.contains("multi_variant"))
    }

    @Test
    fun `dem dung so dieu kien, sap xep khong tinh`() {
        assertEquals(0, LocMatHang().soDieuKien)
        assertEquals(0, LocMatHang(xep = XepHangHoa.BAN_CHAY).soDieuKien)
        assertEquals(
            4,
            LocMatHang(
                nhom = listOf(DanhMuc(1, "A"), DanhMuc(2, "B")),
                trangThai = setOf(TrangThaiHang.DANG_BAN),
                donViId = 5,
            ).soDieuKien,
        )
    }

    @Test
    fun `moi nhom va moi trang thai la mot chip go duoc rieng`() {
        val loc = LocMatHang(
            nhom = listOf(DanhMuc(1, "Đồ uống"), DanhMuc(2, "Bánh kẹo")),
            trangThai = setOf(TrangThaiHang.TAM_AN),
            xep = XepHangHoa.TEN_AZ,
        )
        val chips = loc.chips()

        assertEquals(listOf("Đồ uống", "Bánh kẹo", "Tạm ẩn"), chips.map { it.nhan })
        // Gỡ chip đầu thì nhóm còn lại, trạng thái và kiểu sắp xếp giữ nguyên.
        assertEquals(listOf("Bánh kẹo"), chips[0].con.nhom.map { it.ten })
        assertEquals(setOf(TrangThaiHang.TAM_AN), chips[0].con.trangThai)
        assertEquals(XepHangHoa.TEN_AZ, chips[0].con.xep)
    }

    @Test
    fun `hai so am cua VAT la ma chu khong phai phan tram`() {
        assertEquals("KCT", chuVAT(-1))
        assertEquals("KKKNT", chuVAT(-2))
        assertEquals("0%", chuVAT(0))
        assertEquals("10%", chuVAT(10))
    }

    @Test
    fun `ma trang thai la coi nhu dang an`() {
        // Không bịa ra "đang bán" cho một mã không hiểu: đoán sai chiều đó là
        // bày ra ngoài cửa hàng một món đáng lẽ đang ẩn.
        assertEquals(TrangThaiHang.DANG_BAN, TrangThaiHang.tu("active"))
        assertEquals(TrangThaiHang.NGUNG, TrangThaiHang.tu("discontinued"))
        assertEquals(TrangThaiHang.TAM_AN, TrangThaiHang.tu(""))
        assertEquals(TrangThaiHang.TAM_AN, TrangThaiHang.tu("mot-ma-la"))
    }
}
