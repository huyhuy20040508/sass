package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// PHIẾU ĐIỀU CHUYỂN — đường DUY NHẤT để hàng đi từ kho này sang kho kia.
//
// Bài kiểm ở đây canh đúng một câu: SAU KHI DUYỆT, kho gửi vơi đi bao nhiêu thì
// kho nhận đầy lên đúng bấy nhiêu. Mọi thứ khác của phiếu (mã, trạng thái, khoá
// sau duyệt) chỉ có nghĩa khi câu ấy đúng — hàng bốc hơi hoặc tự sinh ra là kiểu
// sai không lộ ra ở đâu cho tới lúc có người đếm hàng thật.

const duongDieuChuyen = "/api/v1/admin/phieu-dieu-chuyen"

// lapDieuChuyen lập một phiếu điều chuyển, trả về (mã HTTP, id phiếu).
func lapDieuChuyen(t *testing.T, h *heThong, c *cuaHang, than map[string]any) (int, uint) {
	t.Helper()

	res := h.goiChiNhanh(t, c.token, c.chiNhanh, http.MethodPost, duongDieuChuyen, than)

	var body struct {
		Data struct {
			ID uint `json:"id"`
		} `json:"data"`
	}
	_ = json.Unmarshal([]byte(res.than), &body)

	return res.ma, body.Data.ID
}

// Đường đi bình thường: nhập hàng vào kho A, chuyển 4 sang kho B, duyệt.
//
// Tổng hàng của cửa hàng KHÔNG đổi — đó là điểm khác biệt của phiếu này so với
// mọi chứng từ khác: nó không tạo ra hay tiêu đi món hàng nào, chỉ dời chỗ.
func TestDieuChuyen_DuyetThiHangDoiKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC", 10, "2030-06-30")

	truocA := tonCua(t, h, a, a.chiNhanh, a.bienThe)
	truocB := tonCua(t, h, a, kho2, a.bienThe)
	if truocA != 10 {
		t.Fatalf("kho gửi phải có 10 trước khi chuyển, nhận %d", truocA)
	}

	ma, id := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": a.chiNhanh,
		"to_shop_id":   kho2,
		"note":         "chuyển bớt sang kho hai",
		"items": []any{map[string]any{
			"variant_id": a.bienThe, "quantity": 4, "lot_number": "LO-DC",
		}},
	})
	if ma != http.StatusCreated {
		t.Fatalf("lập phiếu điều chuyển trả %d", ma)
	}
	_ = id

	// Lưu tạm CHƯA đụng tới kho — hàng chỉ đổi kho lúc duyệt.
	if giua := tonCua(t, h, a, a.chiNhanh, a.bienThe); giua != truocA {
		t.Fatalf("phiếu lưu tạm không được đụng vào kho: trước %d, sau khi lập %d", truocA, giua)
	}

	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost,
		fmt.Sprintf("%s/%d/duyet", duongDieuChuyen, id), nil)
	if res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu trả %d\n%s", res.ma, catBot(res.than))
	}

	sauA := tonCua(t, h, a, a.chiNhanh, a.bienThe)
	sauB := tonCua(t, h, a, kho2, a.bienThe)

	if sauA != truocA-4 {
		t.Fatalf("kho gửi phải vơi 4: trước %d, sau %d", truocA, sauA)
	}
	if sauB != truocB+4 {
		t.Fatalf("kho nhận phải đầy thêm 4: trước %d, sau %d", truocB, sauB)
	}
	// Câu quan trọng nhất: cửa hàng không mất và không sinh ra món hàng nào.
	if (sauA + sauB) != (truocA + truocB) {
		t.Fatalf("tổng hàng của cửa hàng phải giữ nguyên: trước %d, sau %d",
			truocA+truocB, sauA+sauB)
	}
}

// Duyệt hai lần: lượt thứ hai phải bị chặn, không phải chuyển thêm một lần nữa.
func TestDieuChuyen_KhongDuyetDuocHaiLan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC2", 6, "")

	_, id := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": a.chiNhanh, "to_shop_id": kho2,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 2}},
	})

	duong := fmt.Sprintf("%s/%d/duyet", duongDieuChuyen, id)
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, duong, nil); res.ma != http.StatusOK {
		t.Fatalf("lượt duyệt đầu phải được, nhận %d\n%s", res.ma, catBot(res.than))
	}

	sauLan1 := tonCua(t, h, a, kho2, a.bienThe)

	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, duong, nil); res.ma == http.StatusOK {
		t.Fatal("duyệt lần hai phải bị chặn")
	}
	if sau := tonCua(t, h, a, kho2, a.bienThe); sau != sauLan1 {
		t.Fatalf("lượt duyệt hỏng không được đụng vào kho: %d -> %d", sauLan1, sau)
	}

	// Phiếu đã duyệt cũng không sửa và không xoá được.
	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPut,
		fmt.Sprintf("%s/%d", duongDieuChuyen, id), map[string]any{
			"from_shop_id": a.chiNhanh, "to_shop_id": kho2,
			"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 1}},
		})
	if res.ma == http.StatusOK {
		t.Fatal("phiếu đã duyệt không được sửa")
	}
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodDelete,
		fmt.Sprintf("%s/%d", duongDieuChuyen, id), nil); res.ma == http.StatusOK {
		t.Fatal("phiếu đã duyệt không được xoá")
	}
}

// Kho xuất trùng kho nhập: phiếu như vậy không chuyển gì cả nhưng vẫn ghi hai
// bút toán ngược nhau vào cùng một kho và làm bẩn sổ.
func TestDieuChuyen_KhongChoTrungKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	moChiNhanhThuHai(t, h, a, "Kho hai")

	ma, _ := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": a.chiNhanh, "to_shop_id": a.chiNhanh,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 1}},
	})
	if ma == http.StatusCreated {
		t.Fatal("kho xuất trùng kho nhập phải bị từ chối")
	}
}

// Chuyển quá số hàng đang có: chặn ngay từ lúc lập, không chờ tới lúc duyệt.
func TestDieuChuyen_KhongChuyenQuaTon(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC3", 3, "")

	res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost, duongDieuChuyen, map[string]any{
		"from_shop_id": a.chiNhanh, "to_shop_id": kho2,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 99}},
	})
	if res.ma == http.StatusCreated {
		t.Fatal("chuyển quá tồn phải bị từ chối")
	}
	// Câu lỗi phải nói RA số thật: "không đủ hàng" chung chung thì người lập
	// phiếu không biết sửa xuống bao nhiêu.
	if !strings.Contains(res.than, "3") {
		t.Fatalf("câu lỗi phải nói kho xuất còn bao nhiêu, nhận: %s", catBot(res.than))
	}
}

// Danh sách phải hiện phiếu ở CẢ HAI ĐẦU: kho nhận cũng cần tra ra chứng từ đã
// làm tồn của mình tăng lên.
func TestDieuChuyen_HaiDauDeuThayPhieu(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-DC4", 5, "")

	_, id := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": a.chiNhanh, "to_shop_id": kho2,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 1}},
	})

	coPhieu := func(shopID uint) bool {
		res := h.goiChiNhanh(t, a.token, shopID, http.MethodGet, duongDieuChuyen+"?page_size=100", nil)
		if res.ma != http.StatusOK {
			t.Fatalf("đọc danh sách điều chuyển ở chi nhánh %d trả %d", shopID, res.ma)
		}

		var body struct {
			Data []struct {
				ID uint `json:"id"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(res.than), &body); err != nil {
			t.Fatalf("không đọc được danh sách: %v", err)
		}
		for _, p := range body.Data {
			if p.ID == id {
				return true
			}
		}

		return false
	}

	if !coPhieu(a.chiNhanh) {
		t.Fatal("kho GỬI phải thấy phiếu của mình")
	}
	if !coPhieu(kho2) {
		t.Fatal("kho NHẬN phải thấy phiếu sẽ làm tồn của mình tăng lên")
	}
}

// Không chuyển hàng vào một chi nhánh KHÔNG được phép giữ nó.
//
// Thiếu chốt này thì phiếu điều chuyển là lỗ hổng đi vòng qua mọi chốt khác:
// mặt hàng gán riêng chi nhánh A chuyển sang B được, rồi nằm chết ở B — kho có
// hàng thật mà màn Hàng hoá của B không bày ra nên không bán, không lập phiếu,
// không làm gì được. Đã xảy ra thật (PDC202609040001) trước khi có bài này.
func TestDieuChuyen_KhongChuyenVaoChiNhanhKhongSoHuuHang(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-CHET", 5, "")

	// Gán mặt hàng RIÊNG cho kho một. Từ lúc này kho hai không được giữ nó.
	cu := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), nil)
	if cu.ma != http.StatusOK {
		t.Fatalf("đọc mặt hàng trả %d", cu.ma)
	}
	var hh struct {
		Data struct {
			Name       string `json:"name"`
			Slug       string `json:"slug"`
			CategoryID uint   `json:"category_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(cu.than), &hh); err != nil {
		t.Fatalf("không đọc được mặt hàng: %v", err)
	}
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), map[string]any{
			"name": hh.Data.Name, "slug": hh.Data.Slug,
			"category_id": hh.Data.CategoryID, "shop_ids": []uint{a.chiNhanh},
		}); res.ma != http.StatusOK {
		t.Fatalf("gán chi nhánh cho mặt hàng trả %d\n%s", res.ma, catBot(res.than))
	}

	truoc := tonCua(t, h, a, kho2, a.bienThe)

	ma, _ := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": a.chiNhanh, "to_shop_id": kho2,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 1}},
	})
	if ma == http.StatusCreated {
		t.Fatal("chuyển hàng vào chi nhánh không sở hữu mặt hàng phải bị từ chối")
	}
	if sau := tonCua(t, h, a, kho2, a.bienThe); sau != truoc {
		t.Fatalf("lượt bị từ chối không được đụng vào kho: %d -> %d", truoc, sau)
	}
}

// CHIỀU NGƯỢC LẠI VẪN PHẢI ĐI ĐƯỢC — đây là đường DỌN.
//
// Chi nhánh lỡ đang ôm hàng của một mặt hàng vừa bị gán đi nơi khác phải chuyển
// trả được số hàng có thật ra khỏi kho mình. Chặn cả đầu gửi là khoá chết nó
// vĩnh viễn, và lúc ấy không còn cách nào gỡ ngoài sửa tay database.
func TestDieuChuyen_VanChuyenTraDuocHangDangKetOSaiKho(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)
	kho2 := moChiNhanhThuHai(t, h, a, "Kho hai")

	donKho(t, h, a)
	nhapLo(t, h, a, "LO-KET", 5, "")

	// Đưa 2 sang kho hai TRƯỚC khi gán riêng — lúc này còn hợp lệ.
	_, id := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": a.chiNhanh, "to_shop_id": kho2,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 2}},
	})
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPost,
		fmt.Sprintf("%s/%d/duyet", duongDieuChuyen, id), nil); res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu chuẩn bị trả %d\n%s", res.ma, catBot(res.than))
	}

	// Giờ mới gán riêng cho kho một: kho hai thành ra đang ôm hàng "lạc".
	cu := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodGet,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), nil)
	var hh struct {
		Data struct {
			Name       string `json:"name"`
			Slug       string `json:"slug"`
			CategoryID uint   `json:"category_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(cu.than), &hh); err != nil {
		t.Fatalf("không đọc được mặt hàng: %v", err)
	}
	if res := h.goiChiNhanh(t, a.token, a.chiNhanh, http.MethodPut,
		fmt.Sprintf("/api/v1/admin/products/%d", a.sanPham), map[string]any{
			"name": hh.Data.Name, "slug": hh.Data.Slug,
			"category_id": hh.Data.CategoryID, "shop_ids": []uint{a.chiNhanh},
		}); res.ma != http.StatusOK {
		t.Fatalf("gán chi nhánh cho mặt hàng trả %d", res.ma)
	}

	// Chuyển TRẢ về kho một: phải đi được.
	maTra, idTra := lapDieuChuyen(t, h, a, map[string]any{
		"from_shop_id": kho2, "to_shop_id": a.chiNhanh,
		"items": []any{map[string]any{"variant_id": a.bienThe, "quantity": 2}},
	})
	if maTra != http.StatusCreated {
		t.Fatalf("phải chuyển trả được hàng đang kẹt ở kho sai, nhận %d", maTra)
	}
	if res := h.goiChiNhanh(t, a.token, kho2, http.MethodPost,
		fmt.Sprintf("%s/%d/duyet", duongDieuChuyen, idTra), nil); res.ma != http.StatusOK {
		t.Fatalf("duyệt phiếu chuyển trả phải được, nhận %d\n%s", res.ma, catBot(res.than))
	}

	if con := tonCua(t, h, a, kho2, a.bienThe); con != 0 {
		t.Fatalf("kho hai phải sạch hàng lạc sau khi trả, còn %d", con)
	}
}
