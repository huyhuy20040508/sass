package service

import (
	"context"
	"crypto/rand"
	"math/big"
	"strings"
	"time"

	"sass-api/internal/domain"
)

// QuaSinhNhatService — nút "Tạo mã sinh nhật" / "Đã chúc" ở danh sách Sinh nhật
// 7 ngày của quầy.
type QuaSinhNhatService interface {
	// TangMa sinh MỘT mã dùng một lần cho đúng khách theo mẫu ở Cài đặt và ghi mốc.
	TangMa(ctx context.Context, customerID, actorID uint, now time.Time) (*domain.QuaSinhNhat, error)
	// DaChuc chỉ ghi mốc đã chúc, không phát mã.
	DaChuc(ctx context.Context, customerID, actorID uint, now time.Time) (*domain.QuaSinhNhat, error)
}

type quaSinhNhatService struct {
	repo   domain.QuaSinhNhatRepository
	caiDat SettingService
}

func NewQuaSinhNhatService(repo domain.QuaSinhNhatRepository, caiDat SettingService) QuaSinhNhatService {
	return &quaSinhNhatService{repo: repo, caiDat: caiDat}
}

// LoiDaChuc mang mốc cũ để màn quầy nói rõ đã chúc ngày nào, mã gì.
type LoiDaChuc struct{ Cu *domain.QuaSinhNhat }

func (e *LoiDaChuc) Error() string { return domain.ErrDaChucMuaNay.Error() }
func (e *LoiDaChuc) Unwrap() error { return domain.ErrDaChucMuaNay }

// trongMuaSinhNhat: sinh nhật (bỏ năm) cách `now` không quá CuaSoSinhNhatNgay ngày.
// 29/2 năm thường tính là 1/3, như time.Date tự đẩy.
func trongMuaSinhNhat(dob, now time.Time) bool {
	homNay := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, time.UTC)
	for _, nam := range []int{now.Year() - 1, now.Year(), now.Year() + 1} {
		sn := time.Date(nam, dob.Month(), dob.Day(), 0, 0, 0, 0, time.UTC)
		d := sn.Sub(homNay).Hours() / 24
		if d >= -domain.CuaSoSinhNhatNgay && d <= domain.CuaSoSinhNhatNgay {
			return true
		}
	}
	return false
}

// kiem trả tên khách khi được tặng.
func (s *quaSinhNhatService) kiem(ctx context.Context, customerID uint, now time.Time) (string, error) {
	dob, ten, err := s.repo.NgaySinh(ctx, customerID)
	if err != nil {
		return "", err
	}
	if dob == nil || !trongMuaSinhNhat(*dob, now) {
		return "", domain.ErrKhongTrongMuaSN
	}
	cu, err := s.repo.GanNhat(ctx, customerID, now.AddDate(0, 0, -domain.MuaSinhNhatNgay))
	if err != nil {
		return "", err
	}
	if cu != nil {
		return "", &LoiDaChuc{Cu: cu}
	}
	return ten, nil
}

func (s *quaSinhNhatService) DaChuc(ctx context.Context, customerID, actorID uint, now time.Time) (*domain.QuaSinhNhat, error) {
	if _, err := s.kiem(ctx, customerID, now); err != nil {
		return nil, err
	}
	q := &domain.QuaSinhNhat{CustomerID: customerID, CreatedBy: nguoiTao(actorID)}
	if err := s.repo.Tang(ctx, q, nil, nil); err != nil {
		return nil, err
	}
	return q, nil
}

func (s *quaSinhNhatService) TangMa(ctx context.Context, customerID, actorID uint, now time.Time) (*domain.QuaSinhNhat, error) {
	// Khách trước, mẫu sau: khách của cửa hàng khác phải ra 404 chứ không lộ
	// "chưa khai mẫu" của cửa hàng mình.
	ten, err := s.kiem(ctx, customerID, now)
	if err != nil {
		return nil, err
	}
	kieu := s.caiDat.Get(ctx, SettingBirthdayVoucherType)
	muc := s.caiDat.Float(ctx, SettingBirthdayVoucherValue)
	if muc <= 0 {
		return nil, domain.ErrChuaKhaiMauSN
	}
	if kieu != domain.DiscountFixed && (kieu != domain.DiscountPercentage || muc > 100) {
		return nil, domain.ErrMauSNSai
	}

	soNgay := s.caiDat.Int(ctx, SettingBirthdayVoucherDays)
	if soNgay < 1 {
		soNgay = 1
	}
	// Hạn tính TRỌN ngày cuối: tặng hôm nay hạn 30 ngày thì dùng tới hết ngày thứ 30.
	batDau := now
	het := time.Date(now.Year(), now.Month(), now.Day(), 23, 59, 59, 0, now.Location()).AddDate(0, 0, soNgay-1)
	mot := uint(1)
	v := &domain.Voucher{
		// Mô tả để màn Mã giảm giá của Quản trị nhận ra mã của ai, đếm được mã đã dùng.
		Description:    strings.TrimSpace("Quà sinh nhật · " + ten),
		DiscountType:   kieu,
		DiscountValue:  muc,
		MinOrderAmount: s.caiDat.Float(ctx, SettingBirthdayVoucherMinOrder),
		UsageLimit:     &mot, UsageLimitPerUser: &mot,
		StartAt: &batDau, EndAt: &het,
		IsActive: true,
	}
	if toiDa := s.caiDat.Float(ctx, SettingBirthdayVoucherMax); kieu == domain.DiscountPercentage && toiDa > 0 {
		v.MaxDiscountAmount = &toiDa
	}

	q := &domain.QuaSinhNhat{CustomerID: customerID, CreatedBy: nguoiTao(actorID)}
	if err := s.repo.Tang(ctx, q, v, maSinhNhat); err != nil {
		return nil, err
	}
	return q, nil
}

// Bỏ 0/O, 1/I/L: thu ngân đọc mã qua điện thoại cho khách chép lại.
const chuMaSN = "ABCDEFGHJKMNPQRSTUVWXYZ23456789"

func maSinhNhat() string {
	var b strings.Builder
	b.WriteString("SN")
	for i := 0; i < 6; i++ {
		n, _ := rand.Int(rand.Reader, big.NewInt(int64(len(chuMaSN))))
		b.WriteByte(chuMaSN[n.Int64()])
	}
	return b.String()
}
