package service

import (
	"context"
	"strconv"
	"strings"
	"sync"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/internal/tenant"
	"sass-api/pkg/hash"
	"sass-api/pkg/response"
)

// SettingPOSCashbookExpenseLimit — phiếu chi lập / sửa ở quầy lớn hơn số này phải
// có chủ hoặc quản trị viên duyệt; 0 = không giới hạn.
const SettingPOSCashbookExpenseLimit = "pos_cashbook_expense_limit"

// Nhập sai tài khoản duyệt quá chừng này lần trong cửa sổ thì khoá người lập một
// lúc — không có nó, ô duyệt là đường dò mật khẩu chủ tiệm mà không qua chặn đăng nhập.
const (
	duyetSaiToiDa = 5
	duyetSaiKhung = 15 * time.Minute
)

type thuSaiDuyet struct {
	n       int
	tu      time.Time
	khoaDen time.Time
}

var (
	duyetSaiMu sync.Mutex
	duyetSai   = map[string]*thuSaiDuyet{}
)

func khoaThuSai(ctx context.Context, actorID uint) string {
	tid, _ := tenant.ID(ctx)
	return strconv.FormatUint(uint64(tid), 10) + ":" + strconv.FormatUint(uint64(actorID), 10)
}

func dangKhoaDuyet(k string, now time.Time) bool {
	duyetSaiMu.Lock()
	defer duyetSaiMu.Unlock()
	d := duyetSai[k]
	return d != nil && now.Before(d.khoaDen)
}

func ghiDuyet(k string, dung bool, now time.Time) {
	duyetSaiMu.Lock()
	defer duyetSaiMu.Unlock()
	if dung {
		delete(duyetSai, k)
		return
	}
	d := duyetSai[k]
	if d == nil || now.Sub(d.tu) > duyetSaiKhung {
		d = &thuSaiDuyet{tu: now}
		duyetSai[k] = d
	}
	d.n++
	if d.n >= duyetSaiToiDa {
		d.khoaDen = now.Add(duyetSaiKhung)
	}
}

func laChuHoacQuanTri(vai string) bool {
	return vai == domain.RoleAdmin || vai == domain.RoleSuperAdmin
}

// kiemDuyetChi — phiếu CHI ở quầy vượt hạn mức thì trả id người duyệt (chủ / quản
// trị tự lập thì là chính họ). giu = phiếu đang sửa đã duyệt và không tăng tiền:
// giữ người duyệt cũ, không hỏi lại. nil = không cần duyệt.
func (s *thuChiService) kiemDuyetChi(ctx context.Context, req *dto.ThuChiRequest, actorID uint, cu *domain.ThuChi) (duyet *uint, giu bool, err error) {
	// Nộp về công ty không phải khoản chi: người nhận ký biên nhận thay cho lượt duyệt.
	if !req.Quay || *req.Type != domain.LoaiChi || req.Kind == domain.ThuChiNopVe || s.caiDat == nil {
		return nil, false, nil
	}
	han, _ := strconv.ParseFloat(s.caiDat.Get(ctx, SettingPOSCashbookExpenseLimit), 64)
	if han <= 0 || req.Amount <= han {
		return nil, false, nil
	}
	if cu != nil && cu.ApprovedBy != nil && cu.Type == domain.LoaiChi && req.Amount <= cu.Amount {
		return cu.ApprovedBy, true, nil
	}
	if laChuHoacQuanTri(req.VaiTro) {
		return &actorID, false, nil
	}

	ten, mk := strings.TrimSpace(req.ApproverUsername), req.ApproverPassword
	if ten == "" || mk == "" {
		return nil, false, &domain.LoiDuyetChi{Ma: response.MaCanDuyetChi,
			Cau: "Phiếu chi " + formatVND(req.Amount) + " vượt hạn mức " + formatVND(han) + " ở quầy — cần chủ hoặc quản trị viên nhập tài khoản để duyệt."}
	}
	now := time.Now()
	k := khoaThuSai(ctx, actorID)
	if dangKhoaDuyet(k, now) {
		return nil, false, &domain.LoiDuyetChi{Ma: response.MaSaiDuyetChi, Khoa: true,
			Cau: "Nhập sai tài khoản duyệt quá nhiều lần — thử lại sau 15 phút."}
	}
	n, err := s.repo.NguoiDuyet(ctx, ten)
	if err != nil {
		return nil, false, err
	}
	if n == nil || n.Status != "active" || !laChuHoacQuanTri(n.Role) || !hash.Check(mk, n.PasswordHash) {
		ghiDuyet(k, false, now)
		return nil, false, &domain.LoiDuyetChi{Ma: response.MaSaiDuyetChi,
			Cau: "Tài khoản duyệt không đúng, hoặc không phải chủ / quản trị viên."}
	}
	ghiDuyet(k, true, now)
	return &n.ID, false, nil
}
