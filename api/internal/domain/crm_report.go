package domain

import (
	"context"
	"time"
)

// ---------- Báo cáo CRM → Tổng quan ----------
//
// Số liệu theo CrmDashboardService của ordertable v2 (view của v2 đã mất, chỉ
// còn service). Đơn được tính: đơn CÒN HIỆU LỰC (không huỷ / hoàn) tạo trong kỳ,
// đúng chi nhánh — cùng tập đơn với mọi báo cáo khác bên mình (v2 lấy đơn đã
// thanh toán; bên mình đơn ghi nợ vẫn là doanh thu, phần nợ đếm ở Công nợ).
//
// Lọc loại khách (cá nhân / doanh nghiệp) thì chỉ còn đơn CÓ hồ sơ khách đúng
// loại — đơn khách lẻ không thuộc loại nào, như v2.
//
// Hồ sơ khách (đăng ký mới, tăng trưởng, hạng) không có chi nhánh nên không cắt
// theo chi nhánh.

const (
	CrmKhachCaNhan      = "individual"
	CrmKhachDoanhNghiep = "company"
)

type CrmFilter struct {
	CustomerType string // "" | individual | company
	Year         int    // năm của biểu đồ tăng trưởng
	RegMonth     time.Time
}

// LoaiKhach — users.customer_type tương ứng; -1 = không lọc.
func (f CrmFilter) LoaiKhach() int {
	switch f.CustomerType {
	case CrmKhachCaNhan:
		return 0
	case CrmKhachDoanhNghiep:
		return 1
	}
	return -1
}

// CrmBuyerOrder — một đơn có hồ sơ khách trong kỳ; service gộp ra số khách mua,
// số lần mua, khoảng quay lại và top khách.
type CrmBuyerOrder struct {
	UserID    uint
	CreatedAt time.Time
	Total     float64
	Unpaid    float64
}

type CrmCustomerInfo struct {
	ID           uint   `json:"user_id"`
	Name         string `json:"name"`
	Code         string `json:"code"`
	CustomerType int    `json:"customer_type"`
	RankName     string `json:"rank_name"`
	// Deleted: hồ sơ đã xoá — đơn cũ vẫn tính, nhưng không còn trong danh sách khách.
	Deleted     bool       `json:"deleted"`
	Gender      string     `json:"-"`
	DateOfBirth *time.Time `json:"-"`
}

type CrmBucket struct {
	Key   string  `json:"key"`
	Label string  `json:"label"`
	Count int64   `json:"count"`
	Value float64 `json:"value,omitempty"`
}

type CrmTopCustomer struct {
	CrmCustomerInfo
	Value  float64 `json:"value"`
	Orders int64   `json:"orders"`
}

type CrmGrowthMonth struct {
	Month string `json:"month"` // YYYY-MM
	New   int64  `json:"new"`
	Total int64  `json:"total"` // tổng khách cuối tháng
}

type CrmRankRow struct {
	RankID   uint    `json:"rank_id"` // 0 = chưa có hạng
	Name     string  `json:"name"`
	Existing int64   `json:"existing"` // hồ sơ tạo trước kỳ
	New      int64   `json:"new"`      // hồ sơ tạo trong kỳ
	Spend    float64 `json:"spend"`    // tiền đơn trong kỳ của khách đang ở hạng
}

type CrmKpis struct {
	Revenue          float64 `json:"revenue"`
	RevenueLastMonth float64 `json:"revenue_last_month"`
	RevenueLastYear  float64 `json:"revenue_last_year"`
	Orders           int64   `json:"orders"`
	OrdersPrev       int64   `json:"orders_prev"`
	AOV              float64 `json:"aov"`
	AOVPrev          float64 `json:"aov_prev"`
	Buyers           int64   `json:"buyers"`
	BuyersPrev       int64   `json:"buyers_prev"`
	Once             int64   `json:"once"`
	Twice            int64   `json:"twice"`
	RepeatRate       float64 `json:"repeat_rate"` // % khách mua từ 2 đơn
	RepeatRatePrev   float64 `json:"repeat_rate_prev"`
	NewCustomers     int64   `json:"new_customers"`
	NewCustomersPrev int64   `json:"new_customers_prev"`
}

type CrmCare struct {
	Birthdays7d int64   `json:"birthdays_7d"`
	Lapsed60d   int64   `json:"lapsed_60d"` // đã từng mua, đơn gần nhất quá 60 ngày
	Debtors     int64   `json:"debtors"`
	DebtAmount  float64 `json:"debt_amount"`
	NearRank    int64   `json:"near_rank"` // thiếu dưới 10% điểm để lên hạng kế
}

type CrmReport struct {
	From         string `json:"from"`
	To           string `json:"to"`
	PrevFrom     string `json:"prev_from"`
	PrevTo       string `json:"prev_to"`
	CustomerType string `json:"customer_type"`
	Year         int    `json:"year"`
	YearMin      int    `json:"year_min"`
	RegMonth     string `json:"reg_month"` // YYYY-MM

	Kpis          CrmKpis          `json:"kpis"`
	TopSpend      []CrmTopCustomer `json:"top_spend"`
	TopDebt       []CrmTopCustomer `json:"top_debt"`
	Growth        []CrmGrowthMonth `json:"growth"`
	VisitGap      []CrmBucket      `json:"visit_gap"`
	PurchaseCount []CrmBucket      `json:"purchase_count"`
	OrderValue    []CrmBucket      `json:"order_value"`
	RegDaily      []CrmBucket      `json:"reg_daily"`
	Ranks         []CrmRankRow     `json:"ranks"`
	Gender        []CrmBucket      `json:"gender"`
	Age           []CrmBucket      `json:"age"`
	Provinces     []CrmBucket      `json:"provinces"`
	Care          CrmCare          `json:"care"`
}

// CrmRepository — các phép gộp của màn CRM → Tổng quan (crm_report_repository.go).
type CrmRepository interface {
	CrmOrderTotals(ctx context.Context, p ReportPeriod, f CrmFilter) (orders int64, revenue float64, err error)
	CrmBuyerOrders(ctx context.Context, p ReportPeriod, f CrmFilter) ([]CrmBuyerOrder, error)
	CrmOrderValue(ctx context.Context, p ReportPeriod, f CrmFilter) ([]CrmBucket, error)
	CrmCustomerInfo(ctx context.Context, ids []uint) (map[uint]CrmCustomerInfo, error)
	CrmRegistrations(ctx context.Context, from, to time.Time, f CrmFilter) (int64, error)
	CrmRegistrationsBy(ctx context.Context, from, to time.Time, f CrmFilter, format string) (map[string]int64, error)
	CrmFirstCustomerYear(ctx context.Context, f CrmFilter) (int, error)
	CrmRanks(ctx context.Context, p ReportPeriod, f CrmFilter) ([]CrmRankRow, error)
	CrmProvinces(ctx context.Context, p ReportPeriod, f CrmFilter) ([]CrmBucket, error)
	CrmCare(ctx context.Context, p ReportPeriod, f CrmFilter, now time.Time) (CrmCare, error)
}
