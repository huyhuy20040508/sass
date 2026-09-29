package domain

import (
	"context"
	"errors"
	"math"
	"time"
)

// THẺ THÀNH VIÊN — khuôn pmt_setting_ranks / pmt_setting_point_converts của v2.
// Xem migration 0072.
//
// Khách tích điểm theo tiền thực trả (EarnMoney đ = EarnPoint điểm). Điểm TÍCH
// LUỸ trọn đời (User.TotalPoints) quyết định hạng; điểm CÒN DÙNG (User.Points)
// đổi được ra tiền ở quầy (RedeemPoint điểm = RedeemMoney đ).

const (
	RankGiamTien     = "money"
	RankGiamPhanTram = "percent"

	DiemTich    = "earn"
	DiemDoi     = "redeem"
	DiemHoanLai = "revert"
)

var (
	// ErrKhongDuDiem: khách dùng nhiều điểm hơn số đang có (hai máy quầy cùng
	// tiêu điểm của một khách, hoặc số trên màn đã cũ).
	ErrKhongDuDiem = errors.New("Khách không đủ điểm để đổi, vui lòng tải lại số điểm.")
	// ErrHangTrungDiem: hai hạng cùng một mức điểm (v2 để UNIQUE).
	ErrHangTrungDiem = errors.New("Đã có hạng thành viên với mức điểm này.")
)

type MembershipRank struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	Name                string   `json:"name"`
	Point               uint     `json:"point"`
	DiscountType        string   `json:"discount_type"`
	DiscountValue       float64  `json:"discount_value"`
	Status              bool     `json:"status"`
	ApplyAllOrderValues bool     `json:"apply_all_order_values"`
	MinOrderValue       *float64 `json:"min_order_value"`
	MaxOrderValue       *float64 `json:"max_order_value"`
	// MemberCount KHÔNG phải cột: repository đếm khách đang ở hạng này.
	MemberCount int64     `json:"member_count" gorm:"-"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`
}

func (MembershipRank) TableName() string { return "membership_ranks" }

// GiamCho — tiền giảm theo hạng cho một đơn có tiền hàng orderValue (v2
// MembershipRank::discountAmountForOrderValue): hạng tắt hoặc đơn ngoài khoảng
// áp dụng thì 0; % kẹp trong [0, 100]; không bao giờ quá tiền hàng.
func (r MembershipRank) GiamCho(orderValue float64) float64 {
	if !r.Status || orderValue <= 0 || r.DiscountValue <= 0 {
		return 0
	}
	if !r.ApplyAllOrderValues {
		if r.MinOrderValue != nil && orderValue < *r.MinOrderValue {
			return 0
		}
		if r.MaxOrderValue != nil && *r.MaxOrderValue > 0 && orderValue > *r.MaxOrderValue {
			return 0
		}
	}
	giam := r.DiscountValue
	if r.DiscountType == RankGiamPhanTram {
		giam = orderValue * math.Min(100, r.DiscountValue) / 100
	}

	return math.Round(math.Min(giam, orderValue))
}

type PointConversion struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	EarnMoney     float64   `json:"earn_money"`
	EarnPoint     uint      `json:"earn_point"`
	EarnEnabled   bool      `json:"earn_enabled"`
	RedeemPoint   uint      `json:"redeem_point"`
	RedeemMoney   float64   `json:"redeem_money"`
	RedeemEnabled bool      `json:"redeem_enabled"`
	CreatedAt     time.Time `json:"created_at"`
	UpdatedAt     time.Time `json:"updated_at"`
}

func (PointConversion) TableName() string { return "point_conversions" }

// DiemTu — số điểm tích được từ số tiền thực trả (làm tròn xuống: chưa đủ tiền
// cho một điểm thì chưa có điểm). 0 khi chưa bật tích điểm.
func (c PointConversion) DiemTu(tien float64) uint {
	if !c.EarnEnabled || c.EarnMoney <= 0 || c.EarnPoint == 0 || tien <= 0 {
		return 0
	}

	return uint(math.Floor(tien / c.EarnMoney * float64(c.EarnPoint)))
}

// TienMoiDiem — số tiền đổi được từ MỘT điểm. 0 khi chưa bật đổi điểm.
func (c PointConversion) TienMoiDiem() float64 {
	if !c.RedeemEnabled || c.RedeemPoint == 0 || c.RedeemMoney <= 0 {
		return 0
	}

	return c.RedeemMoney / float64(c.RedeemPoint)
}

type PointHistory struct {
	ID uint `json:"id" gorm:"primaryKey"`
	TenantOwned
	UserID    uint      `json:"user_id"`
	OrderID   *uint     `json:"order_id"`
	Kind      string    `json:"kind"`
	Points    int       `json:"points"`
	Amount    float64   `json:"amount"`
	Balance   int       `json:"balance"`
	CreatedAt time.Time `json:"created_at"`
}

func (PointHistory) TableName() string { return "point_histories" }

// RankMember — một khách trong trang chi tiết hạng.
type RankMember struct {
	ID          uint   `json:"id"`
	Code        string `json:"customer_code"`
	FullName    string `json:"full_name"`
	Phone       string `json:"phone"`
	Address     string `json:"address"`
	TotalPoints uint   `json:"total_points"`
	Points      uint   `json:"points"`
}

// KhachDiem — điểm và hạng của một khách, cho quầy tính giảm theo hạng / đổi điểm.
type KhachDiem struct {
	UserID      uint
	TotalPoints uint
	Points      uint
	Rank        *MembershipRank
}

type MembershipRepository interface {
	ListRanks(ctx context.Context) ([]MembershipRank, error)
	FindRank(ctx context.Context, id uint) (*MembershipRank, error)
	SaveRank(ctx context.Context, r *MembershipRank) error
	DeleteRanks(ctx context.Context, ids []uint) error
	// XepHangLai tính lại hạng của MỌI khách sau khi bảng hạng đổi.
	XepHangLai(ctx context.Context) error
	Members(ctx context.Context, rankID uint, keyword string, page, pageSize int) ([]RankMember, int64, error)

	Conversion(ctx context.Context) (*PointConversion, error)
	SaveConversion(ctx context.Context, c *PointConversion) error

	KhachDiem(ctx context.Context, userID uint) (*KhachDiem, error)
	// TenHang: id hạng → tên, cho bảng khách hàng.
	TenHang(ctx context.Context) (map[uint]string, error)
}
