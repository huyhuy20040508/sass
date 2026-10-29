package service

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"time"

	"sass-api/internal/domain"
)

// TichHopService cấp / thu hồi khoá tích hợp cho cửa hàng đang đăng nhập.
type TichHopService interface {
	TrangThai(ctx context.Context, app string) (*TrangThaiTichHop, error)
	// Cap trả khoá ở dạng chữ rõ — lần DUY NHẤT nó tồn tại ngoài bộ nhớ của
	// hệ thống nhận khoá; POS chỉ giữ băm.
	Cap(ctx context.Context, app string, nguoiCap uint) (*KhoaVuaCap, error)
	ThuHoi(ctx context.Context, app string) (int64, error)
}

type TrangThaiTichHop struct {
	KetNoi      bool       `json:"ket_noi"`
	KeyPrefix   string     `json:"key_prefix,omitempty"`
	CapLuc      *time.Time `json:"cap_luc,omitempty"`
	DungLanCuoi *time.Time `json:"dung_lan_cuoi,omitempty"`
}

type KhoaVuaCap struct {
	Khoa      string    `json:"khoa"`
	KeyPrefix string    `json:"key_prefix"`
	CapLuc    time.Time `json:"cap_luc"`
}

type tichHopService struct {
	repo domain.IntegrationKeyRepository
}

func NewTichHopService(repo domain.IntegrationKeyRepository) TichHopService {
	return &tichHopService{repo: repo}
}

func (s *tichHopService) TrangThai(ctx context.Context, app string) (*TrangThaiTichHop, error) {
	k, err := s.repo.DangDung(ctx, app)
	if errors.Is(err, domain.ErrNotFound) {
		return &TrangThaiTichHop{}, nil
	}
	if err != nil {
		return nil, err
	}

	return &TrangThaiTichHop{KetNoi: true, KeyPrefix: k.KeyPrefix, CapLuc: &k.CreatedAt, DungLanCuoi: k.LastUsedAt}, nil
}

func (s *tichHopService) Cap(ctx context.Context, app string, nguoiCap uint) (*KhoaVuaCap, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return nil, err
	}
	// Tiền tố "ecw_" để ai nhìn thấy khoá trong log hay tệp cấu hình cũng biết nó
	// là gì và phải thu hồi ở đâu.
	khoa := "ecw_" + base64.RawURLEncoding.EncodeToString(b)
	k := &domain.IntegrationKey{
		App:       app,
		KeyHash:   domain.BamKhoaTichHop(khoa),
		KeyPrefix: khoa[:10],
		CreatedAt: time.Now(),
	}
	if nguoiCap != 0 {
		k.CreatedBy = &nguoiCap
	}
	if err := s.repo.CapMoi(ctx, k); err != nil {
		return nil, err
	}

	return &KhoaVuaCap{Khoa: khoa, KeyPrefix: k.KeyPrefix, CapLuc: k.CreatedAt}, nil
}

func (s *tichHopService) ThuHoi(ctx context.Context, app string) (int64, error) {
	return s.repo.ThuHoi(ctx, app)
}
