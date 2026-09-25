package apitest

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Bài kiểm SỔ CÔNG NỢ qua API thật và MySQL thật.
//
// Chỗ hỏng đã gặp: sổ lấy `is_debt = 1` làm điều kiện vào sổ. Cờ ấy chỉ đặt được
// qua hộp Thanh toán của màn Phiếu mua hàng, còn lượt DUYỆT phiếu thì không —
// nên phiếu duyệt xong chưa trả đồng nào (PMH202609050001: tổng 40tr, trả 0)
// không bao giờ vào sổ, và màn ghi "Chưa có khoản nợ nào".

const duongCongNo = "/api/v1/admin/cong-no"

// khoanNo là một dòng trên sổ nợ.
type khoanNo struct {
	ID          uint    `json:"id"`
	Code        string  `json:"code"`
	TotalAmount float64 `json:"total_amount"`
	PaidAmount  float64 `json:"paid_amount"`
	Remaining   float64 `json:"remaining"`
	DueDate     *string `json:"due_date"`
	Status      string  `json:"status"`
	DaysLeft    *int    `json:"days_left"`
}

// tongKetNo là bốn nút đếm cộng tổng tiền còn nợ.
type tongKetNo struct {
	TatCa  int64   `json:"count_all"`
	Gan    int64   `json:"count_near"`
	Qua    int64   `json:"count_over"`
	HomNay int64   `json:"count_today"`
	ConNo  float64 `json:"total_remaining"`
}

// docCongNo đọc sổ. query là phần sau dấu ? (có thể rỗng).
func docCongNo(t *testing.T, h *heThong, token, query string) ([]khoanNo, tongKetNo) {
	t.Helper()

	duong := duongCongNo
	if query != "" {
		duong += "?" + query
	}

	res := h.goi(t, token, http.MethodGet, duong, nil)
	if res.ma != http.StatusOK {
		t.Fatalf("đọc sổ công nợ phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	var body struct {
		Data []khoanNo `json:"data"`
		Meta tongKetNo `json:"meta"`
	}
	if err := json.Unmarshal([]byte(res.than), &body); err != nil {
		t.Fatalf("không đọc được phản hồi: %v\n%s", err, catBot(res.than))
	}

	return body.Data, body.Meta
}

// timKhoan nhặt một khoản theo id phiếu mua.
func timKhoan(ds []khoanNo, id uint) *khoanNo {
	for i := range ds {
		if ds[i].ID == id {
			return &ds[i]
		}
	}

	return nil
}

// TestCongNo_DuyetChuaTraThiVaoSo — chỗ hỏng chính.
//
// Duyệt phiếu rồi ĐÓNG LUÔN, không mở hộp Thanh toán: hàng đã nhận, tiền chưa
// trả một đồng. Đó là khoản nợ nặng nhất trong sổ, mà bản cũ bỏ qua vì `is_debt`
// còn 0. Không hẹn hạn thì `due_date` và `days_left` để trống, không bịa ra ngày.
func TestCongNo_DuyetChuaTraThiVaoSo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, p.ID)

	ds, tk := docCongNo(t, h, a.token, "")
	got := timKhoan(ds, p.ID)
	if got == nil {
		t.Fatalf("phiếu đã duyệt chưa trả đồng nào PHẢI nằm trong sổ nợ; sổ đang có %d dòng", len(ds))
	}
	if got.Status != "unpaid" || got.Remaining != 100000 {
		t.Fatalf("chưa trả đồng nào phải là unpaid còn nợ 100.000, nhận %q còn %v", got.Status, got.Remaining)
	}
	if got.DueDate != nil || got.DaysLeft != nil {
		t.Fatalf("chưa hẹn hạn thì hạn phải để TRỐNG, nhận due_date=%v days_left=%v", got.DueDate, got.DaysLeft)
	}
	if tk.TatCa < 1 {
		t.Fatalf("nút Tất cả phải đếm cả phiếu này, nhận %d", tk.TatCa)
	}
	// Ba nút theo hạn chỉ đếm khoản CÓ hạn — phiếu này chưa hẹn ngày nào.
	if tk.Gan != 0 || tk.Qua != 0 || tk.HomNay != 0 {
		t.Fatalf("khoản chưa hẹn hạn không được rơi vào ba nút theo hạn, nhận gần %d quá %d hôm nay %d",
			tk.Gan, tk.Qua, tk.HomNay)
	}
}

// TestCongNo_LuuTamKhongVaoSo — phiếu chưa duyệt thì chưa ai nợ ai.
//
// Hàng chưa vào kho, chứng từ còn sửa được. Gộp nó vào sổ nợ là đòi tiền cho
// một lượt mua chưa xảy ra.
func TestCongNo_LuuTamKhongVaoSo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})

	ds, _ := docCongNo(t, h, a.token, "")
	if timKhoan(ds, p.ID) != nil {
		t.Fatalf("phiếu lưu tạm KHÔNG được vào sổ nợ")
	}
}

// TestCongNo_TraDuThiRoiSo — trả hết thì hết nợ.
//
// Phiếu không có thoả thuận nợ, trả nốt xong là rời sổ. Vẫn xem lại được bằng
// bộ lọc trạng thái "đã trả đủ" — chỉ là không còn nằm trong việc phải làm.
func TestCongNo_TraDuThiRoiSo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, p.ID)

	res := h.goi(t, a.token, http.MethodPost,
		fmt.Sprintf("%s/%d/thanh-toan", duongPhieuMua, p.ID),
		map[string]any{"paid_amount": 100000})
	if res.ma != http.StatusOK {
		t.Fatalf("trả đủ trả %d\n%s", res.ma, catBot(res.than))
	}

	ds, _ := docCongNo(t, h, a.token, "status=unpaid,partial")
	if timKhoan(ds, p.ID) != nil {
		t.Fatalf("trả đủ rồi thì không còn là khoản phải đòi")
	}
}

// TestCongNo_ThoaThuanNoThiCoHan — vế `is_debt` vẫn có việc của nó.
//
// Khoản HAI BÊN ĐÃ HẸN HẠN thì sổ in ra ngày đáo hạn và số ngày còn lại; khoản
// chưa hẹn thì hai ô ấy trống. Đó là toàn bộ khác biệt giữa hai loại — không
// phải là "vào sổ hay không".
//
// Trả nốt xong thì cờ ghi nợ được dọn (PhieuMuaHangService.Pay xoá cả hạn lẫn
// người đòi, vì khoản nợ ấy không còn), nên khoản rời khỏi việc phải làm y như
// mọi khoản khác.
func TestCongNo_ThoaThuanNoThiCoHan(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, p.ID)

	duong := fmt.Sprintf("%s/%d/thanh-toan", duongPhieuMua, p.ID)
	res := h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "is_debt": true, "debt_due_date": "2026-12-31",
		"debt_contact_name": "Anh Ba", "debt_contact_phone": "0900000000",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("ghi nợ trả %d\n%s", res.ma, catBot(res.than))
	}

	ds, _ := docCongNo(t, h, a.token, "")
	got := timKhoan(ds, p.ID)
	if got == nil {
		t.Fatalf("khoản đã hẹn hạn phải nằm trong sổ")
	}
	if got.DueDate == nil || got.DaysLeft == nil {
		t.Fatalf("khoản đã hẹn hạn phải có ngày đáo hạn, nhận due_date=%v days_left=%v", got.DueDate, got.DaysLeft)
	}
	if got.Status != "partial" || got.Remaining != 60000 {
		t.Fatalf("trả 40.000/100.000 phải là partial còn nợ 60.000, nhận %q còn %v", got.Status, got.Remaining)
	}

	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{"paid_amount": 100000})
	if res.ma != http.StatusOK {
		t.Fatalf("trả nốt trả %d\n%s", res.ma, catBot(res.than))
	}

	ds, _ = docCongNo(t, h, a.token, "")
	if timKhoan(ds, p.ID) != nil {
		t.Fatalf("trả nốt rồi thì khoản phải rời sổ")
	}
}

// TestCongNo_HaiCuaHangKhongThayNhau — sổ nợ cũng phải cắt theo tenant.
//
// Điều kiện vào sổ vừa nới rộng ra, nên kiểm lại: nới cái này không được kéo
// theo phiếu của cửa hàng khác.
func TestCongNo_HaiCuaHangKhongThayNhau(t *testing.T) {
	h := dungHeThong(t)
	a, b := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, p.ID)

	ds, _ := docCongNo(t, h, b.token, "")
	if timKhoan(ds, p.ID) != nil {
		t.Fatalf("cửa hàng B đọc thấy khoản nợ của cửa hàng A")
	}
}

// TestCongNo_QuyenRieng — sổ nợ đứng sau quyền CỦA RIÊNG NÓ.
//
// `cong-no.xem` không nằm trong bộ bốn việc của Thu chi, dù hai màn đứng cạnh
// nhau trong cùng một mục. Lý do ở router: sổ này bày TÊN và SỐ ĐIỆN THOẠI người
// đại diện bên bán — giao việc ghi sổ quỹ không có nghĩa là giao luôn danh bạ
// nhà cung cấp.
//
// Trên máy chủ thật bảng `user_permissions` đang rỗng nên chưa ai kiểm được điều
// này; gộp nhầm hai quyền thì không có gì báo, chỉ là một hôm nào đó cả cửa hàng
// đọc được danh bạ.
func TestCongNo_QuyenRieng(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	// Đủ BỐN việc của Thu chi, nhưng không có cong-no.xem.
	hai := themQuanLyThuHai(t, h, a, "quantri2", []string{
		"thu-chi.xem", "thu-chi.them", "thu-chi.sua", "thu-chi.xoa",
	})

	if res := h.goi(t, hai, http.MethodGet, "/api/v1/admin/thu-chi", nil); res.ma != http.StatusOK {
		t.Fatalf("có đủ quyền Thu chi thì phải đọc được sổ quỹ, nhận %d", res.ma)
	}
	if res := h.goi(t, hai, http.MethodGet, duongCongNo, nil); res.ma != http.StatusForbidden {
		t.Fatalf("quyền Thu chi KHÔNG được mở luôn sổ công nợ, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Giao đúng quyền của sổ nợ thì mở ra ngay với chính token cũ.
	giaoQuyen(t, h, a, "quantri2", "cong-no.xem")
	if res := h.goi(t, hai, http.MethodGet, duongCongNo, nil); res.ma != http.StatusOK {
		t.Fatalf("có cong-no.xem thì phải đọc được sổ nợ, nhận %d\n%s", res.ma, catBot(res.than))
	}

	// Và chiều ngược lại: sổ nợ CHỈ ĐỌC, không kèm đường ghi nào của Thu chi.
	ba := themQuanLyThuHai(t, h, a, "quantri3", []string{"cong-no.xem"})
	if res := h.goi(t, ba, http.MethodGet, duongCongNo, nil); res.ma != http.StatusOK {
		t.Fatalf("có cong-no.xem thì phải đọc được sổ nợ, nhận %d", res.ma)
	}
	if res := h.goi(t, ba, http.MethodGet, "/api/v1/admin/thu-chi", nil); res.ma != http.StatusForbidden {
		t.Fatalf("cong-no.xem KHÔNG được mở luôn sổ quỹ, nhận %d", res.ma)
	}
}

// TestCongNo_GoThoaThuanNo — ĐƯỜNG HOÀN NGUYÊN.
//
// Bật nhầm ô "ghi nợ" ở hộp Thanh toán là chuyện thường; trước đây không gỡ được
// vì hộp ấy đóng lại ngay khi phiếu có lượt trả đầu tiên, và hộp Thanh toán bên
// màn Công nợ thì ép cứng `is_debt = còn nợ`. Hạn nợ vì thế đóng băng vĩnh viễn.
//
// Bài này chốt đúng thứ giao diện dựa vào: gửi `is_debt = false` khi VẪN CÒN NỢ
// thì API dọn sạch hạn và người đại diện, mà khoản nợ KHÔNG rơi khỏi sổ — nó chỉ
// thôi có ngày phải đòi.
func TestCongNo_GoThoaThuanNo(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, p.ID)

	duong := fmt.Sprintf("%s/%d/thanh-toan", duongPhieuMua, p.ID)
	res := h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "is_debt": true, "debt_due_date": "2026-10-15",
		"debt_contact_name": "TEST Nguoi Dai Dien", "debt_contact_phone": "0909000111",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("ghi nợ trả %d\n%s", res.ma, catBot(res.than))
	}
	if got := docPhieu(t, h, a.token, p.ID); !got.IsDebt {
		t.Fatalf("bật ghi nợ xong mà cờ vẫn tắt")
	}

	// Gỡ thoả thuận: vẫn còn nợ 60.000, chỉ bỏ phần hẹn hạn.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "is_debt": false,
	})
	if res.ma != http.StatusOK {
		t.Fatalf("gỡ thoả thuận nợ phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	got := docPhieu(t, h, a.token, p.ID)
	if got.IsDebt {
		t.Fatalf("bỏ tick rồi mà cờ ghi nợ vẫn bật")
	}
	if got.DebtDueDate != nil || got.DebtName != "" || got.DebtPhone != "" {
		t.Fatalf("gỡ thoả thuận phải dọn cả hạn lẫn người đại diện, còn lại hạn=%v tên=%q số=%q",
			got.DebtDueDate, got.DebtName, got.DebtPhone)
	}

	// Khoản nợ VẪN trong sổ — chỉ là không còn ngày phải đòi.
	ds, _ := docCongNo(t, h, a.token, "")
	khoan := timKhoan(ds, p.ID)
	if khoan == nil {
		t.Fatalf("gỡ hẹn hạn KHÔNG được làm khoản nợ biến mất khỏi sổ")
	}
	if khoan.DueDate != nil || khoan.DaysLeft != nil {
		t.Fatalf("gỡ hẹn hạn rồi thì hai ô hạn phải trống, nhận due_date=%v days_left=%v",
			khoan.DueDate, khoan.DaysLeft)
	}
	if khoan.Remaining != 60000 {
		t.Fatalf("gỡ hẹn hạn không được đụng tới số tiền, còn nợ %v", khoan.Remaining)
	}
}

// TestCongNo_SuaHanKhongDeRaPhieuThuChi — sửa thoả thuận thì KHÔNG có tiền nào đi.
//
// Cảnh đã gặp: muốn đổi hạn nợ mà hộp thanh toán bắt nhập tiền, nên người dùng
// ghi một lượt trả giả rồi ghi tiếp một lượt âm để bù. Sổ nợ lãnh hai lượt trả
// bù nhau, sổ thu chi lãnh hai phiếu vô nghĩa — một phiếu chi "Trả tiền phiếu
// mua" và một phiếu thu "Chữa lại lượt trả".
//
// Bất biến mà giao diện dựa vào: gọi lại đường thanh toán với ĐÚNG số đã trả thì
// chênh lệch bằng 0, không dòng `purchase_payments` nào sinh ra và sổ thu chi
// đứng im.
func TestCongNo_SuaHanKhongDeRaPhieuThuChi(t *testing.T) {
	h := dungHeThong(t)
	a, _ := haiCuaHang(t, h)

	_, p := lapPhieu(t, h, a.token, map[string]any{
		"supplier_name": "Cong ty " + a.vet,
		"items":         []any{dongHang(a.bienThe, 10, 10000)},
	})
	duyet(t, h, a, p.ID)

	duong := fmt.Sprintf("%s/%d/thanh-toan", duongPhieuMua, p.ID)
	res := h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "payment_method": "cash",
		"is_debt": true, "debt_due_date": "2026-10-15",
		"debt_contact_name": "TEST Nguoi Dai Dien", "debt_contact_phone": "0909000111",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("lượt trả đầu trả %d\n%s", res.ma, catBot(res.than))
	}

	soTra := len(docPhieu(t, h, a.token, p.ID).Payments)
	dsTC, _ := docThuChi(t, h, a.token, "")
	soTC := len(dsTC)

	// Chỉ đổi hạn: gửi lại ĐÚNG số đã trả.
	res = h.goi(t, a.token, http.MethodPost, duong, map[string]any{
		"paid_amount": 40000, "payment_method": "cash",
		"is_debt": true, "debt_due_date": "2026-11-20",
		"debt_contact_name": "TEST Nguoi Dai Dien", "debt_contact_phone": "0909000111",
	})
	if res.ma != http.StatusOK {
		t.Fatalf("sửa hạn phải trả 200, nhận %d\n%s", res.ma, catBot(res.than))
	}

	got := docPhieu(t, h, a.token, p.ID)
	if got.DebtDueDate == nil || !strings.HasPrefix(*got.DebtDueDate, "2026-11-20") {
		t.Fatalf("hạn mới không được ghi lại, nhận %v", got.DebtDueDate)
	}
	if len(got.Payments) != soTra {
		t.Fatalf("sửa hạn mà đẻ thêm lượt trả: %d → %d", soTra, len(got.Payments))
	}
	if got.PaidAmount != 40000 {
		t.Fatalf("sửa hạn mà số đã trả đổi, nhận %v", got.PaidAmount)
	}

	dsTC, _ = docThuChi(t, h, a.token, "")
	if len(dsTC) != soTC {
		t.Fatalf("sửa hạn mà sổ thu chi đẻ thêm phiếu: %d → %d dòng", soTC, len(dsTC))
	}
}
