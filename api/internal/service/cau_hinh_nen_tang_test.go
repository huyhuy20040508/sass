package service

import (
	"context"
	"strings"
	"testing"

	"sass-api/pkg/bimat"
)

// fakeCauHinhNenTang là bảng khoá · giá trị trong bộ nhớ.
type fakeCauHinhNenTang struct {
	daLuu map[string]string
	ghi   map[string]string
}

func (f *fakeCauHinhNenTang) All(context.Context) (map[string]string, error) {
	if f.daLuu == nil {
		f.daLuu = map[string]string{}
	}

	return f.daLuu, nil
}

func (f *fakeCauHinhNenTang) Save(_ context.Context, items map[string]string) error {
	f.ghi = items
	if f.daLuu == nil {
		f.daLuu = map[string]string{}
	}
	for k, v := range items {
		f.daLuu[k] = v
	}

	return nil
}

func dungCauHinh(daLuu map[string]string) (CauHinhNenTangService, *fakeCauHinhNenTang) {
	repo := &fakeCauHinhNenTang{daLuu: daLuu}

	return NewCauHinhNenTangService(repo, bimat.New("khoa-thu-nghiem")), repo
}

// dungCauHinhKhongKhoa dựng service của một máy chủ CHƯA khai PLATFORM_SECRET_KEY.
func dungCauHinhKhongKhoa() (CauHinhNenTangService, *fakeCauHinhNenTang) {
	repo := &fakeCauHinhNenTang{}

	return NewCauHinhNenTangService(repo, bimat.New("")), repo
}

// Khoá chưa có dòng vẫn phải xuất hiện, mang giá trị mặc định của registry —
// nếu không, màn hình cài đặt lần đầu mở ra là một loạt ô trống không nhãn.
func TestCauHinhNenTang_DocRaMacDinhKhiChuaKhai(t *testing.T) {
	svc, _ := dungCauHinh(nil)

	res, err := svc.Doc(context.Background())
	if err != nil {
		t.Fatalf("không mong có lỗi: %v", err)
	}
	if res.Values[CauHinhCKBat] != "0" {
		t.Errorf("mặc định phải là TẮT, nhận %q", res.Values[CauHinhCKBat])
	}
	if res.Values[CauHinhCKNoiDungMau] != "GIAHAN "+ChoMaCuaHang {
		t.Errorf("mẫu nội dung mặc định sai: %q", res.Values[CauHinhCKNoiDungMau])
	}
	if len(res.Fields) != len(cauHinhNenTangRegistry) {
		t.Errorf("phải trả đủ siêu dữ liệu của %d ô, nhận %d", len(cauHinhNenTangRegistry), len(res.Fields))
	}
}

// BẬT nhận tiền mà chưa khai tài khoản: từ chối. Đây là toàn bộ lý do màn hình
// này có phần kiểm tra — bật lên rồi để trống thì khách quét QR và chuyển tiền
// vào hư không.
func TestCauHinhNenTang_BatMaThieuTaiKhoanThiTuChoi(t *testing.T) {
	svc, repo := dungCauHinh(nil)

	_, err := svc.Ghi(context.Background(), map[string]string{CauHinhCKBat: "1"})

	ve, ok := err.(*PlanFeatureValidationError)
	if !ok {
		t.Fatalf("mong lỗi kiểm dữ liệu, nhận %v", err)
	}
	for _, khoa := range []string{CauHinhCKNganHangTen, CauHinhCKSoTaiKhoan, CauHinhCKChuTaiKhoan} {
		if _, co := ve.Fields[khoa]; !co {
			t.Errorf("thiếu %s phải bị bắt", khoa)
		}
	}
	// TẤT-CẢ-HOẶC-KHÔNG: hỏng một ô thì không ô nào được ghi xuống.
	if repo.ghi != nil {
		t.Error("payload hỏng mà vẫn ghi xuống database")
	}
}

// Mẫu nội dung thiếu {ma_cua_hang}: mọi khách chuyển cùng một nội dung, và sao
// kê không nói được tiền của ai.
func TestCauHinhNenTang_MauNoiDungPhaiCoMaCuaHang(t *testing.T) {
	svc, _ := dungCauHinh(map[string]string{
		CauHinhCKNganHangTen: "Vietcombank",
		CauHinhCKSoTaiKhoan:  "0123456789",
		CauHinhCKChuTaiKhoan: "NGUYEN QUOC HUY",
	})

	_, err := svc.Ghi(context.Background(), map[string]string{
		CauHinhCKBat:        "1",
		CauHinhCKNoiDungMau: "GIA HAN PHAN MEM",
	})

	ve, ok := err.(*PlanFeatureValidationError)
	if !ok {
		t.Fatalf("mong lỗi kiểm dữ liệu, nhận %v", err)
	}
	if _, co := ve.Fields[CauHinhCKNoiDungMau]; !co {
		t.Error("mẫu nội dung thiếu chỗ điền mã cửa hàng phải bị bắt")
	}
}

// Khai đủ thì lưu được, và lượt ghi CHỈ đụng những khoá gửi lên.
func TestCauHinhNenTang_KhaiDuThiLuuDuoc(t *testing.T) {
	svc, repo := dungCauHinh(map[string]string{CauHinhCKHuongDan: "Xác nhận trong giờ hành chính"})

	res, err := svc.Ghi(context.Background(), map[string]string{
		CauHinhCKBat:         "1",
		CauHinhCKNganHangTen: "  Vietcombank  ",
		CauHinhCKSoTaiKhoan:  "0123456789",
		CauHinhCKChuTaiKhoan: "NGUYEN QUOC HUY",
		CauHinhCKNoiDungMau:  "GIAHAN " + ChoMaCuaHang,
	})
	if err != nil {
		t.Fatalf("khai đủ mà không lưu được: %v", err)
	}
	// Khoảng trắng thừa bị cắt trước khi ghi — người bán dán từ app ngân hàng ra
	// thì hay dính, và một tên ngân hàng lệch một dấu cách là một dòng hiển thị xấu.
	if res.Values[CauHinhCKNganHangTen] != "Vietcombank" {
		t.Errorf("giá trị phải được cắt khoảng trắng, nhận %q", res.Values[CauHinhCKNganHangTen])
	}
	// Ô không gửi lên giữ nguyên.
	if res.Values[CauHinhCKHuongDan] != "Xác nhận trong giờ hành chính" {
		t.Errorf("khoá không gửi lên phải giữ nguyên, nhận %q", res.Values[CauHinhCKHuongDan])
	}
	if _, co := repo.ghi[CauHinhCKHuongDan]; co {
		t.Error("không được ghi lại khoá mà người dùng không sửa")
	}
}

// TẮT hình thức đi thì xoá trắng các ô cũng hợp lệ: lúc đó không có khách nào
// nhìn thấy chúng nữa.
func TestCauHinhNenTang_TatThiKhongDoiHoiGiCa(t *testing.T) {
	svc, _ := dungCauHinh(map[string]string{CauHinhCKBat: "1", CauHinhCKSoTaiKhoan: "0123456789"})

	if _, err := svc.Ghi(context.Background(), map[string]string{
		CauHinhCKBat:        "0",
		CauHinhCKSoTaiKhoan: "",
	}); err != nil {
		t.Fatalf("tắt hình thức rồi xoá ô phải hợp lệ, nhận: %v", err)
	}
}

// Khoá lạ bị từ chối thẳng — ghi xuống một khoá không màn hình nào đọc chỉ tạo
// rác mà không ai phát hiện.
func TestCauHinhNenTang_KhoaLaBiTuChoi(t *testing.T) {
	svc, repo := dungCauHinh(nil)

	_, err := svc.Ghi(context.Background(), map[string]string{"khoa_bia_ra": "gia-tri"})
	if _, ok := err.(*PlanFeatureValidationError); !ok {
		t.Fatalf("mong lỗi kiểm dữ liệu, nhận %v", err)
	}
	if repo.ghi != nil {
		t.Error("khoá lạ mà vẫn ghi xuống database")
	}
}

// ---------- PayOS: ba khoá BÍ MẬT ----------

// Khoá bí mật ghi xuống phải ở dạng MÃ HOÁ, và không bao giờ quay ra ngoài
// nguyên văn. Trả nguyên văn "chỉ cho màn hình quản trị" nghĩa là khoá PayOS nằm
// trong HTML, trong log truy cập, trong cache trình duyệt.
func TestCauHinhPayOS_KhoaDuocMaHoaVaKhongLoRaNgoai(t *testing.T) {
	svc, repo := dungCauHinh(nil)
	khoaThat := "PAYOS-API-KEY-ABCD1234"

	res, err := svc.Ghi(context.Background(), map[string]string{
		CauHinhPayOSBat:         "1",
		CauHinhPayOSClientID:    "client-id-9999",
		CauHinhPayOSAPIKey:      khoaThat,
		CauHinhPayOSChecksumKey: "checksum-key-8888",
	})
	if err != nil {
		t.Fatalf("khai đủ mà không lưu được: %v", err)
	}

	daGhi := repo.daLuu[CauHinhPayOSAPIKey]
	if !bimat.DaMa(daGhi) {
		t.Errorf("khoá phải được mã hoá trước khi ghi, nhận %q", daGhi)
	}
	if strings.Contains(daGhi, khoaThat) {
		t.Error("khoá thật lộ nguyên văn dưới database")
	}
	if ra := res.Values[CauHinhPayOSAPIKey]; strings.Contains(ra, khoaThat) || !strings.Contains(ra, "•") {
		t.Errorf("API phải trả bản che, nhận %q", ra)
	}
	// Bốn ký tự cuối giữ lại để người bán đối chiếu với trang quản trị PayOS.
	if ra := res.Values[CauHinhPayOSAPIKey]; !strings.HasSuffix(ra, "1234") {
		t.Errorf("bản che phải giữ bốn ký tự cuối, nhận %q", ra)
	}
}

// Gửi RỖNG = giữ nguyên khoá cũ, KHÔNG phải xoá.
//
// Màn hình không bao giờ đổ khoá cũ vào ô nhập, nên một lượt lưu bình thường
// (sửa số tài khoản rồi bấm Lưu) sẽ gửi ô PayOS rỗng. Hiểu rỗng là xoá thì mỗi
// lần sửa một chữ ở khối trên là một lần cổng thanh toán chết.
func TestCauHinhPayOS_GuiRongThiGiuNguyenKhoaCu(t *testing.T) {
	kin, _ := bimat.New("khoa-thu-nghiem").Ma("khoa-cu")
	svc, repo := dungCauHinh(map[string]string{
		CauHinhPayOSBat:         "1",
		CauHinhPayOSClientID:    kin,
		CauHinhPayOSAPIKey:      kin,
		CauHinhPayOSChecksumKey: kin,
	})

	if _, err := svc.Ghi(context.Background(), map[string]string{
		CauHinhPayOSAPIKey: "",
		CauHinhCKHuongDan:  "sửa một ô khác thôi",
	}); err != nil {
		t.Fatalf("không mong lỗi: %v", err)
	}
	if _, co := repo.ghi[CauHinhPayOSAPIKey]; co {
		t.Error("ô bí mật gửi rỗng thì không được ghi đè")
	}
	if repo.daLuu[CauHinhPayOSAPIKey] != kin {
		t.Error("khoá cũ phải còn nguyên")
	}
}

// Bản che bị gửi ngược lên (trình duyệt tự điền, người dùng copy): không được
// mã hoá chuỗi chấm tròn rồi ghi đè lên khoá thật.
func TestCauHinhPayOS_KhongGhiDeBangBanChe(t *testing.T) {
	kin, _ := bimat.New("khoa-thu-nghiem").Ma("khoa-cu-1234")
	svc, repo := dungCauHinh(map[string]string{CauHinhPayOSAPIKey: kin})

	if _, err := svc.Ghi(context.Background(), map[string]string{
		CauHinhPayOSAPIKey: "••••••••1234",
	}); err != nil {
		t.Fatalf("không mong lỗi: %v", err)
	}
	if _, co := repo.ghi[CauHinhPayOSAPIKey]; co {
		t.Error("bản che không được ghi xuống database")
	}
}

// Bật PayOS mà thiếu khoá thì từ chối — và luật đó chỉ hỏi về NHÓM PAYOS, không
// đòi luôn số tài khoản ngân hàng của hình thức đang tắt.
func TestCauHinhPayOS_BatMaThieuKhoaThiTuChoi(t *testing.T) {
	svc, _ := dungCauHinh(nil)

	_, err := svc.Ghi(context.Background(), map[string]string{CauHinhPayOSBat: "1"})

	ve, ok := err.(*PlanFeatureValidationError)
	if !ok {
		t.Fatalf("mong lỗi kiểm dữ liệu, nhận %v", err)
	}
	for _, khoa := range []string{CauHinhPayOSClientID, CauHinhPayOSAPIKey, CauHinhPayOSChecksumKey} {
		if _, co := ve.Fields[khoa]; !co {
			t.Errorf("thiếu %s phải bị bắt", khoa)
		}
	}
	if _, co := ve.Fields[CauHinhCKSoTaiKhoan]; co {
		t.Error("bật PayOS không được đòi khai tài khoản của hình thức chuyển khoản đang tắt")
	}
}

// Máy chủ chưa khai PLATFORM_SECRET_KEY: TỪ CHỐI lưu, không ghi plaintext.
//
// Một khoá PayOS nằm nguyên văn trong database sẽ nằm đó mãi và không có gì nhắc
// lại chuyện đó; từ chối thì người khai thấy ngay và đi khai khoá mã hoá.
func TestCauHinhPayOS_ChuaCoKhoaMaHoaThiTuChoiLuu(t *testing.T) {
	svc, repo := dungCauHinhKhongKhoa()

	_, err := svc.Ghi(context.Background(), map[string]string{
		CauHinhPayOSClientID: "client-id",
	})
	if _, ok := err.(*PlanFeatureValidationError); !ok {
		t.Fatalf("mong lỗi kiểm dữ liệu, nhận %v", err)
	}
	if repo.ghi != nil {
		t.Error("chưa có khoá mã hoá mà vẫn ghi xuống database")
	}

	res, err := svc.Doc(context.Background())
	if err != nil {
		t.Fatalf("đọc cấu hình vẫn phải chạy: %v", err)
	}
	if res.KhoaMaHoa {
		t.Error("phải báo cho màn hình biết máy chủ chưa khai khoá mã hoá")
	}
}
