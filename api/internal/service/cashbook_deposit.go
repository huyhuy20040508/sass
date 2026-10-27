package service

import (
	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// kiemNopVe — phiếu "nộp về công ty": tiền mặt RA khỏi két, giao tận tay một
// nhân viên (người nhận ký biên nhận). Không phải chi phí nên không cần phân loại.
func kiemNopVe(req *dto.ThuChiRequest) error {
	if req.Kind != domain.ThuChiNopVe {
		return nil
	}
	o := map[string]string{}
	if req.Type == nil || *req.Type != domain.LoaiChi {
		o["type"] = "Nộp về công ty là tiền ra khỏi két — ghi như phiếu chi."
	}
	if req.PaymentMethod != "cash" {
		o["payment_method"] = "Nộp về công ty chỉ ghi tiền mặt ra khỏi két."
	}
	nhanVien := req.PayerType == domain.ThuChiDoiTuongQuanLy || req.PayerType == domain.ThuChiDoiTuongThuNgan
	if !nhanVien || req.PayerID == 0 {
		o["payer_id"] = "Chọn người nhận trong danh sách nhân viên."
	}
	if len(o) > 0 {
		return loiO(o)
	}
	return nil
}
