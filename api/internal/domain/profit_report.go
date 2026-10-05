package domain

// ---------- Báo cáo chi phí & lợi nhuận ----------
//
// Cùng quy ước với tab Hàng hoá: đơn còn hiệu lực, mốc là created_at.
//   - Tổng giá bán = SUM(order_items.total_price), chưa thuế.
//   - Tổng giá vốn = SUM(costExpr × số lượng).
//   - Lợi nhuận = giá bán − giá vốn, CÓ THỂ ÂM (bán lỗ).
//   - Biên lợi nhuận (%) = lợi nhuận / giá bán × 100; giá bán 0 thì 0.

// ProfitRow — một mặt hàng trong kỳ (hoặc dòng tổng). Như v2, bảng bày cả mặt
// hàng ĐANG BÁN mà kỳ này chưa bán được cái nào (mọi cột số bằng 0).
type ProfitRow struct {
	ProductID    uint    `json:"product_id"`
	Code         string  `json:"code"`
	Name         string  `json:"name"`
	CategoryName string  `json:"category_name"`
	Quantity     int64   `json:"quantity"`
	Revenue      float64 `json:"revenue"`
	Cost         float64 `json:"cost"`
	Profit       float64 `json:"profit"`
	Margin       float64 `json:"margin"`
}

// Cong cộng một dòng vào dòng tổng. Biên lãi của dòng tổng tính lại ở TinhBien.
func (t *ProfitRow) Cong(r ProfitRow) {
	t.Quantity += r.Quantity
	t.Revenue += r.Revenue
	t.Cost += r.Cost
}

// TinhBien điền Lợi nhuận và Biên lợi nhuận từ giá bán và giá vốn.
func (t *ProfitRow) TinhBien() {
	t.Profit = t.Revenue - t.Cost
	t.Margin = 0
	if t.Revenue != 0 {
		t.Margin = t.Profit / t.Revenue * 100
	}
}

// ProfitBucket — một cột của biểu đồ: tiền bán / vốn / lãi của một mốc.
type ProfitBucket struct {
	Key     string  `json:"key"` // 2026-05-01 | 2026-W18 | 2026-05
	Revenue float64 `json:"revenue"`
	Cost    float64 `json:"cost"`
	Profit  float64 `json:"profit"`
}

type ProfitReport struct {
	From    string      `json:"from"`
	To      string      `json:"to"`
	GroupBy string      `json:"group_by"` // day | week | month
	Rows    []ProfitRow `json:"rows"`
	Totals  ProfitRow   `json:"totals"`
	// Chart — ĐỦ mốc của cả kỳ, mốc không bán gì vẫn có mặt với 0.
	Chart []ProfitBucket `json:"chart"`
}
