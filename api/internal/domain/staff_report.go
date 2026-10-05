package domain

// Bao cao nhan vien

const (
	CaLoaiCa      = "shift"
	CaLoaiNgoaiCa = "none"
	CaLoaiOnline  = "online"
)

// filter bao cao ca
type StaffReportFilter struct {
	// nhan vien theo account
	Area   string
	UserID uint
	// tim theo ma nhan vien / ten ho so nhan su hoac ten tai khoan
	Keyword string
}

// StaffShiftRow — một dòng (ngày × ca × nhân viên), hoặc dòng tổng
type StaffShiftRow struct {
	Date string `json:"date"`
	// Kind: CaLoaiCa | CaLoaiNgoaiCa | CaLoaiOnline. ShiftID chỉ có khi Kind = ca
	Kind         string  `json:"kind"`
	ShiftID      uint    `json:"shift_id"`
	UserID       uint    `json:"user_id"`
	EmployeeCode string  `json:"employee_code"`
	Name         string  `json:"name"`
	OrderCount   int64   `json:"order_count"`
	Revenue      float64 `json:"revenue"`
	AvgOrder     float64 `json:"avg_order"`
}

// TinhTB điền giá trị trung bình một đơn
func (r *StaffShiftRow) TinhTB() {
	r.AvgOrder = 0
	if r.OrderCount > 0 {
		r.AvgOrder = r.Revenue / float64(r.OrderCount)
	}
}

// StaffRevenue — doanh thu cả kỳ của một nhân viên (một cột của biểu đồ)
type StaffRevenue struct {
	UserID     uint    `json:"user_id"`
	Name       string  `json:"name"`
	OrderCount int64   `json:"order_count"`
	Revenue    float64 `json:"revenue"`
	AvgOrder   float64 `json:"avg_order"`
}

type StaffReport struct {
	From    string          `json:"from"`
	To      string          `json:"to"`
	Rows    []StaffShiftRow `json:"rows"`
	Totals  StaffShiftRow   `json:"totals"`
	ByStaff []StaffRevenue  `json:"by_staff"`
}
