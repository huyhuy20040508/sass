package service

import (
	"context"
	"errors"
	"testing"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// Bài kiểm của module CHI NHÁNH — thứ gói Chuỗi bán.
//
// Hai luật được kiểm ở đây, và cả hai đều là luật nghiệp vụ chứ không phải kiểm
// tra dữ liệu: hạn mức `max_shops` của hợp đồng, và "luôn còn ít nhất một chi
// nhánh đang hoạt động".

// ---------- sổ giả ----------

type fakeChiNhanhRepo struct {
	rows   []domain.ChiNhanh
	nextID uint
	// daTao ghi lại dòng vừa được ghi xuống, để bài kiểm soi mã đã chuẩn hoá.
	daTao *domain.ChiNhanh
}

func newFakeChiNhanhRepo(rows ...domain.ChiNhanh) *fakeChiNhanhRepo {
	return &fakeChiNhanhRepo{rows: rows, nextID: uint(len(rows) + 1)}
}

func (f *fakeChiNhanhRepo) List(_ context.Context, onlyActive bool) ([]domain.ChiNhanh, error) {
	out := make([]domain.ChiNhanh, 0, len(f.rows))
	for _, r := range f.rows {
		if onlyActive && !r.IsActive {
			continue
		}
		out = append(out, r)
	}

	return out, nil
}

func (f *fakeChiNhanhRepo) FindByID(_ context.Context, id uint) (*domain.ChiNhanh, error) {
	for i := range f.rows {
		if f.rows[i].ID == id {
			ban := f.rows[i]

			return &ban, nil
		}
	}

	return nil, domain.ErrNotFound
}

func (f *fakeChiNhanhRepo) ExistsByCode(_ context.Context, code string, excludeID uint) (bool, error) {
	for _, r := range f.rows {
		if r.Code == code && r.ID != excludeID {
			return true, nil
		}
	}

	return false, nil
}

func (f *fakeChiNhanhRepo) Create(_ context.Context, cn *domain.ChiNhanh) error {
	cn.ID = f.nextID
	f.nextID++
	f.rows = append(f.rows, *cn)
	f.daTao = cn

	return nil
}

func (f *fakeChiNhanhRepo) Update(_ context.Context, cn *domain.ChiNhanh) error {
	for i := range f.rows {
		if f.rows[i].ID == cn.ID {
			f.rows[i] = *cn

			return nil
		}
	}

	return domain.ErrNotFound
}

func (f *fakeChiNhanhRepo) Delete(_ context.Context, id uint) error {
	for i := range f.rows {
		if f.rows[i].ID == id {
			f.rows = append(f.rows[:i], f.rows[i+1:]...)

			return nil
		}
	}

	return domain.ErrNotFound
}

func (f *fakeChiNhanhRepo) BanOnline(context.Context) (*domain.ChiNhanh, error) {
	for i := range f.rows {
		if f.rows[i].IsActive {
			ban := f.rows[i]

			return &ban, nil
		}
	}

	return nil, domain.ErrNotFound
}

func (f *fakeChiNhanhRepo) Count(context.Context) (int64, error) {
	return int64(len(f.rows)), nil
}

func (f *fakeChiNhanhRepo) CountActiveExcept(_ context.Context, excludeID uint) (int64, error) {
	var n int64
	for _, r := range f.rows {
		if r.IsActive && r.ID != excludeID {
			n++
		}
	}

	return n, nil
}

// hanMucChan là cửa hạn mức luôn trả lời "hết chỗ" — đủ để kiểm ChiNhanhService
// có HỎI nó hay không, mà không phải dựng lại cả sổ nền tảng.
type hanMucChan struct {
	hoi []domain.LoaiHanMuc
}

func (h *hanMucChan) ConChoTao(_ context.Context, loai domain.LoaiHanMuc) error {
	h.hoi = append(h.hoi, loai)

	return domain.ErrVuotHanMuc
}

func (h *hanMucChan) DangDung(context.Context) (domain.SoDangDung, error) {
	return domain.SoDangDung{}, nil
}

func chiNhanhGoc() domain.ChiNhanh {
	return domain.ChiNhanh{ID: 1, Code: "mac-dinh", Name: "Cửa hàng chính", IsActive: true}
}

// ---------- hạn mức ----------

// Gói Khởi đầu / Cửa hàng chốt một chi nhánh, mà cửa hàng nào cũng có sẵn một
// chi nhánh 'mac-dinh' — nên lượt mở thứ hai phải bị chặn, và đó chính là chỗ
// gói Chuỗi có lý do tồn tại.
func TestChiNhanhHetHanMucThiKhongMoDuoc(t *testing.T) {
	repo := newFakeChiNhanhRepo(chiNhanhGoc())
	hm := &hanMucChan{}
	svc := NewChiNhanhService(repo, hm)

	_, err := svc.Create(context.Background(), &dto.ChiNhanhRequest{Name: "Kho miền Bắc"})
	if !errors.Is(err, domain.ErrVuotHanMuc) {
		t.Fatalf("gói đã hết chỗ mà vẫn mở được chi nhánh: %v", err)
	}
	if len(hm.hoi) != 1 || hm.hoi[0] != domain.HanMucChiNhanh {
		t.Fatalf("phải hỏi hạn mức chi nhánh đúng một lần, thực tế hỏi %v", hm.hoi)
	}
	if repo.daTao != nil {
		t.Fatalf("bị chặn rồi mà vẫn ghi xuống database: %+v", repo.daTao)
	}
}

// Máy chủ chưa nối được control plane thì hanMuc là nil — phần bán hàng vẫn phải
// chạy bình thường, đúng như trước khi có chỗ ép.
func TestChiNhanhKhongCoCuaHanMucThiVanMoDuoc(t *testing.T) {
	repo := newFakeChiNhanhRepo(chiNhanhGoc())
	svc := NewChiNhanhService(repo, nil)

	if _, err := svc.Create(context.Background(), &dto.ChiNhanhRequest{Name: "Kho miền Bắc"}); err != nil {
		t.Fatalf("chưa nối sổ nền tảng mà lại chặn: %v", err)
	}
}

// ---------- mã chi nhánh ----------

// Mã bỏ trống lúc TẠO thì hệ thống tự đặt, và phải né mã đã có.
func TestChiNhanhTuSinhMaNeMaDaCo(t *testing.T) {
	repo := newFakeChiNhanhRepo(
		chiNhanhGoc(),
		domain.ChiNhanh{ID: 2, Code: "chi-nhanh-2", Name: "Kho 2", IsActive: true},
	)
	svc := NewChiNhanhService(repo, nil)

	cn, err := svc.Create(context.Background(), &dto.ChiNhanhRequest{Name: "Kho 3"})
	if err != nil {
		t.Fatalf("không mở được chi nhánh: %v", err)
	}
	if cn.Code != "chi-nhanh-3" {
		t.Fatalf("mã tự sinh là %q, đáng lẽ chi-nhanh-3", cn.Code)
	}
}

// "Kho-1" không sai gì cả — bắt người dùng gõ lại chỉ vì phím Shift là phiền vô
// ích. Hạ chữ thường rồi lưu một kiểu, để lượt kiểm trùng ở tầng Go nói cùng một
// câu với khoá duy nhất của MySQL (vốn không phân biệt hoa thường).
func TestChiNhanhHaChuThuongMaNguoiDungGo(t *testing.T) {
	repo := newFakeChiNhanhRepo(chiNhanhGoc())
	svc := NewChiNhanhService(repo, nil)

	cn, err := svc.Create(context.Background(), &dto.ChiNhanhRequest{Code: "  Kho-1 ", Name: "Kho 1"})
	if err != nil {
		t.Fatalf("không mở được chi nhánh: %v", err)
	}
	if cn.Code != "kho-1" {
		t.Fatalf("mã lưu xuống là %q, đáng lẽ kho-1", cn.Code)
	}
}

func TestChiNhanhTuChoiMaCoDauVaKhoangTrang(t *testing.T) {
	svc := NewChiNhanhService(newFakeChiNhanhRepo(chiNhanhGoc()), nil)

	for _, ma := range []string{"kho miền bắc", "kho#1", "k", "-kho"} {
		_, err := svc.Create(context.Background(), &dto.ChiNhanhRequest{Code: ma, Name: "Kho"})
		if !errors.Is(err, domain.ErrMaChiNhanhInvalid) {
			t.Fatalf("mã %q phải bị từ chối, nhận: %v", ma, err)
		}
	}
}

func TestChiNhanhTuChoiMaDaCo(t *testing.T) {
	svc := NewChiNhanhService(newFakeChiNhanhRepo(chiNhanhGoc()), nil)

	_, err := svc.Create(context.Background(), &dto.ChiNhanhRequest{Code: "mac-dinh", Name: "Kho"})
	if !errors.Is(err, domain.ErrMaChiNhanhDaCo) {
		t.Fatalf("mã trùng phải bị từ chối, nhận: %v", err)
	}
}

// Sửa mà bỏ trống mã = GIỮ NGUYÊN, không sinh mã mới: mã đã in trên chứng từ của
// chi nhánh này.
func TestChiNhanhSuaBoTrongMaThiGiuNguyen(t *testing.T) {
	repo := newFakeChiNhanhRepo(chiNhanhGoc())
	svc := NewChiNhanhService(repo, nil)

	cn, err := svc.Update(context.Background(), 1, &dto.ChiNhanhRequest{Name: "Cửa hàng chính (mới)"})
	if err != nil {
		t.Fatalf("không sửa được chi nhánh: %v", err)
	}
	if cn.Code != "mac-dinh" {
		t.Fatalf("mã bị đổi thành %q dù lượt sửa bỏ trống ô mã", cn.Code)
	}
}

// ---------- luôn còn một điểm bán ----------

func TestChiNhanhKhongXoaDuocCaiCuoiCung(t *testing.T) {
	repo := newFakeChiNhanhRepo(chiNhanhGoc())
	svc := NewChiNhanhService(repo, nil)

	if err := svc.Delete(context.Background(), 1); !errors.Is(err, domain.ErrChiNhanhCuoiCung) {
		t.Fatalf("xoá chi nhánh cuối cùng phải bị chặn, nhận: %v", err)
	}
	if len(repo.rows) != 1 {
		t.Fatalf("bị chặn rồi mà dòng vẫn biến mất")
	}
}

// Tắt cái cuối cùng để lại đúng hậu quả với xoá nó, nên chặn bằng cùng một luật.
func TestChiNhanhKhongTatDuocCaiHoatDongCuoiCung(t *testing.T) {
	repo := newFakeChiNhanhRepo(
		chiNhanhGoc(),
		domain.ChiNhanh{ID: 2, Code: "kho-2", Name: "Kho 2", IsActive: false},
	)
	svc := NewChiNhanhService(repo, nil)

	tat := false
	_, err := svc.Update(context.Background(), 1, &dto.ChiNhanhRequest{Name: "Cửa hàng chính", IsActive: &tat})
	if !errors.Is(err, domain.ErrChiNhanhCuoiCung) {
		t.Fatalf("tắt chi nhánh hoạt động cuối cùng phải bị chặn, nhận: %v", err)
	}
}

// Còn chi nhánh khác thì xoá được — chốt chặn trên không được chặn oan.
func TestChiNhanhXoaDuocKhiConCaiKhac(t *testing.T) {
	repo := newFakeChiNhanhRepo(
		chiNhanhGoc(),
		domain.ChiNhanh{ID: 2, Code: "kho-2", Name: "Kho 2", IsActive: true},
	)
	svc := NewChiNhanhService(repo, nil)

	if err := svc.Delete(context.Background(), 2); err != nil {
		t.Fatalf("còn chi nhánh khác mà vẫn chặn xoá: %v", err)
	}
}

// Chi nhánh ĐÃ TẮT thì xoá không làm biến mất điểm bán nào — chặn nó là chặn oan,
// kể cả khi nó là dòng cuối cùng trong danh sách.
func TestChiNhanhXoaDuocCaiDaTatDuLaDongCuoi(t *testing.T) {
	repo := newFakeChiNhanhRepo(domain.ChiNhanh{ID: 1, Code: "kho-cu", Name: "Kho cũ", IsActive: false})
	svc := NewChiNhanhService(repo, nil)

	if err := svc.Delete(context.Background(), 1); err != nil {
		t.Fatalf("chi nhánh đã tắt mà vẫn chặn xoá: %v", err)
	}
}
