package apitest

import (
	"encoding/json"
	"net/http"
	"testing"
)

// Bài kiểm QUY TẮC ĐÁNH SỐ CHỨNG TỪ qua API thật và MySQL thật.
//
// Ba chỗ dễ hỏng của bảng mới này, và cả ba đều không lộ ra ở tầng service với
// sổ giả: quy tắc danh mục có thật sự rơi về phạm vi dùng chung (shop_id = 0)
// không, loại vắng mặt trong lượt lưu sau có bị tắt không, và cửa hàng khác có
// nhìn thấy quy tắc của nhau không.

// docQuyTac đọc bảng quy tắc của một cửa hàng.
func docQuyTac(t *testing.T, h *heThong, token string) []struct {
	ShopID   uint   `json:"shop_id"`
	DocType  string `json:"doc_type"`
	Prefix   string `json:"prefix"`
	Length   int    `json:"length"`
	IsActive bool   `json:"is_active"`
} {
	t.Helper()

	res := h.goi(t, token, http.MethodGet, "/api/v1/admin/quy-tac-ma", nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc quy tắc phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data struct {
			Loai []struct {
				Ma string `json:"ma"`
			} `json:"loai"`
			QuyTac []struct {
				ShopID   uint   `json:"shop_id"`
				DocType  string `json:"doc_type"`
				Prefix   string `json:"prefix"`
				Length   int    `json:"length"`
				IsActive bool   `json:"is_active"`
			} `json:"quy_tac"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}
	if len(body.Data.Loai) == 0 {
		t.Fatal("danh mục loại đánh số rỗng — màn cấu hình sẽ không có dòng nào")
	}

	return body.Data.QuyTac
}

// TestQuyTacMa_DanhMucDungChungChungTuTheoChiNhanh — phạm vi do DANH MỤC quyết
// định, không nghe theo shop_id gửi lên.
func TestQuyTacMa_DanhMucDungChungChungTuTheoChiNhanh(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
		"shop_id": a.chiNhanh,
		"quy_tac": []map[string]any{
			{"doc_type": "hang-hoa", "prefix": "HH", "value_part": "so-thu-tu", "length": 6, "suffix": ""},
			{"doc_type": "don-hang", "prefix": "DH", "value_part": "so-thu-tu", "length": 5, "suffix": "A"},
		},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lưu quy tắc phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	for _, q := range docQuyTac(t, h, a.token) {
		switch q.DocType {
		case "hang-hoa":
			if q.ShopID != 0 {
				t.Fatalf("quy tắc danh mục phải dùng chung (shop_id = 0), nhận %d", q.ShopID)
			}
		case "don-hang":
			if q.ShopID != a.chiNhanh {
				t.Fatalf("quy tắc chứng từ phải thuộc chi nhánh %d, nhận %d", a.chiNhanh, q.ShopID)
			}
			if q.Length != 5 || q.Prefix != "DH" {
				t.Fatalf("quy tắc đơn hàng ghi không đúng: %+v", q)
			}
		}
	}
}

// TestQuyTacMa_VangMatLaTat — lượt lưu sau không gửi một loại nữa thì loại đó
// tắt, chứ không nằm lại như đang bật.
func TestQuyTacMa_VangMatLaTat(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	luu := func(quyTac []map[string]any) {
		t.Helper()
		res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
			"shop_id": a.chiNhanh, "quy_tac": quyTac,
		})
		if res.ma != http.StatusOK {
			t.Fatalf("lưu quy tắc phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
		}
	}

	luu([]map[string]any{
		{"doc_type": "nhan-vien", "prefix": "NV", "value_part": "so-thu-tu", "length": 4},
		{"doc_type": "don-hang", "prefix": "DH", "value_part": "so-thu-tu", "length": 6},
	})
	// Bỏ tick "Nhân viên": chỉ còn đơn hàng được gửi lên.
	luu([]map[string]any{
		{"doc_type": "don-hang", "prefix": "DH", "value_part": "so-thu-tu", "length": 6},
	})

	for _, q := range docQuyTac(t, h, a.token) {
		if q.DocType == "nhan-vien" && q.IsActive {
			t.Fatal("loại vắng mặt trong lượt lưu sau vẫn đang bật")
		}
		if q.DocType == "don-hang" && !q.IsActive {
			t.Fatal("loại vẫn gửi lên lại bị tắt")
		}
		// Hàng cũ phải còn để tick lại là có sẵn tiền tố.
		if q.DocType == "nhan-vien" && q.Prefix != "NV" {
			t.Fatalf("tắt quy tắc không được xoá tiền tố đã khai, nhận %q", q.Prefix)
		}
	}
}

// TestQuyTacMa_KhongNhinThayCuaCuaHangKhac — hai cửa hàng, hai bộ quy tắc.
func TestQuyTacMa_KhongNhinThayCuaCuaHangKhac(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
		"shop_id": a.chiNhanh,
		"quy_tac": []map[string]any{
			{"doc_type": "hang-hoa", "prefix": "RIENGCUAA", "value_part": "so-thu-tu", "length": 6},
		},
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lưu quy tắc phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	for _, q := range docQuyTac(t, h, b.token) {
		if q.Prefix == "RIENGCUAA" {
			t.Fatal("cửa hàng B đọc được quy tắc của cửa hàng A")
		}
	}

	// Chi nhánh của cửa hàng khác cũng không ghi vào được.
	res = h.goi(t, b.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
		"shop_id": a.chiNhanh,
		"quy_tac": []map[string]any{
			{"doc_type": "don-hang", "prefix": "X", "value_part": "so-thu-tu", "length": 6},
		},
	})
	if res.ma != http.StatusNotFound {
		t.Fatalf("ghi vào chi nhánh của cửa hàng khác phải trả 404, nhận %d\n%s", res.ma, catBot(res.than))
	}
}

// TestQuyTacMa_TuChoiDuLieuSai — loại lạ, kiểu giá trị lạ và độ dài ngoài khoảng
// đều phải bị chặn ở API, không chỉ ở form.
func TestQuyTacMa_TuChoiDuLieuSai(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	xau := []map[string]any{
		{"doc_type": "khong-co-loai-nay", "value_part": "so-thu-tu", "length": 6},
		{"doc_type": "don-hang", "value_part": "kieu-la", "length": 6},
		{"doc_type": "don-hang", "value_part": "so-thu-tu", "length": 99},
		{"doc_type": "don-hang", "prefix": "ĐH có dấu", "value_part": "so-thu-tu", "length": 6},
	}
	for _, dong := range xau {
		res := h.goi(t, a.token, http.MethodPut, "/api/v1/admin/quy-tac-ma", map[string]any{
			"shop_id": a.chiNhanh, "quy_tac": []map[string]any{dong},
		})
		if res.ma != http.StatusUnprocessableEntity {
			t.Fatalf("dữ liệu sai %v phải trả 422, nhận %d\n%s", dong, res.ma, catBot(res.than))
		}
	}
}
