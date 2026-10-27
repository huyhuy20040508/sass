package repository

import (
	"context"
	"encoding/json"
	"fmt"
	"sync"
	"time"

	"gorm.io/gorm"

	"sass-api/internal/domain"
	"sass-api/internal/tenant"
)

// dongNhatKy — một dòng activity_logs kèm tên người sửa.
type dongNhatKy struct {
	ID          uint
	SubjectID   uint
	Action      string
	Description string
	Properties  string
	CreatedAt   time.Time
	TenNguoi    string
}

// docNhatKyThuChi đọc nhật ký sửa / xoá phiếu thu chi theo điều kiện `where`
// (đã gồm tenant nhờ Model), mới nhất trước.
func docNhatKyThuChi(db *gorm.DB, where string, args ...any) ([]domain.NhatKyThuChi, error) {
	var rows []dongNhatKy
	err := db.Model(&domain.ActivityLog{}).
		Select("activity_logs.id, activity_logs.subject_id, activity_logs.action, COALESCE(activity_logs.description, '') AS description, "+
			"CAST(activity_logs.properties AS CHAR) AS properties, activity_logs.created_at, COALESCE(u.full_name, '') AS ten_nguoi").
		Joins("LEFT JOIN users u ON u.id = activity_logs.user_id").
		Where("activity_logs.subject_type = ?", domain.NhatKyThuChiLoai).
		Where(where, args...).
		Order("activity_logs.id DESC").
		Scan(&rows).Error
	if err != nil {
		return nil, err
	}
	ra := make([]domain.NhatKyThuChi, 0, len(rows))
	for _, d := range rows {
		n := domain.NhatKyThuChi{ID: d.ID, PhieuID: d.SubjectID, HanhDong: d.Action, Luc: d.CreatedAt, NguoiSua: d.TenNguoi, LyDo: d.Description}
		_ = json.Unmarshal([]byte(d.Properties), &n.ThayDoi)
		ra = append(ra, n)
	}
	return ra, nil
}

// suaLanCuoiThuChi — lượt SỬA gần nhất của từng phiếu trong danh sách.
func suaLanCuoiThuChi(db *gorm.DB, list []domain.ThuChi) (map[uint]*domain.ThuChiSuaCuoi, error) {
	ra := map[uint]*domain.ThuChiSuaCuoi{}
	if len(list) == 0 {
		return ra, nil
	}
	ids := make([]uint, 0, len(list))
	for _, t := range list {
		ids = append(ids, t.ID)
	}
	ds, err := docNhatKyThuChi(db, "activity_logs.action = ? AND activity_logs.subject_id IN ?", domain.NhatKyThuChiSua, ids)
	if err != nil {
		return nil, err
	}
	for _, n := range ds {
		if _, co := ra[n.PhieuID]; co {
			continue
		}
		moi := n.ThayDoi.SoTienCu
		if n.ThayDoi.SoTienMoi != nil {
			moi = *n.ThayDoi.SoTienMoi
		}
		ra[n.PhieuID] = &domain.ThuChiSuaCuoi{Luc: n.Luc, NguoiSua: n.NguoiSua, SoTienCu: n.ThayDoi.SoTienCu, SoTienMoi: moi, LyDo: n.LyDo}
	}
	return ra, nil
}

func (r *thuChiRepository) NhatKy(ctx context.Context, phieuID uint) ([]domain.NhatKyThuChi, error) {
	return docNhatKyThuChi(r.db.WithContext(ctx), "activity_logs.subject_id = ?", phieuID)
}

// ThuChiSuaTrongCa — phiếu thu chi của ca đã bị sửa / xoá (ca ghi trong properties).
func (r *caLamViecRepository) ThuChiSuaTrongCa(ctx context.Context, caID uint) ([]domain.NhatKyThuChi, error) {
	return docNhatKyThuChi(r.db.WithContext(ctx), "JSON_EXTRACT(activity_logs.properties, '$.ca') = ?", caID)
}

// lienKetDieuChinh — mã phiếu gốc của các phiếu điều chỉnh, và các phiếu điều
// chỉnh trỏ về từng phiếu trong danh sách. Phiếu gốc đọc cả bản đã xoá mềm:
// liên kết là để đối soát, không được biến mất theo phiếu.
func lienKetDieuChinh(db *gorm.DB, list []domain.ThuChi) (map[uint]string, map[uint][]domain.ThuChiLienKet, error) {
	maGoc := map[uint]string{}
	dieuChinh := map[uint][]domain.ThuChiLienKet{}
	if len(list) == 0 || !coCot(db, &domain.ThuChi{}, "adjusts_id") {
		return maGoc, dieuChinh, nil
	}
	ids := make([]uint, 0, len(list))
	var gocIDs []uint
	for _, t := range list {
		ids = append(ids, t.ID)
		if t.AdjustsID != nil {
			gocIDs = append(gocIDs, *t.AdjustsID)
		}
	}
	if len(gocIDs) > 0 {
		var goc []domain.ThuChiLienKet
		if err := db.Unscoped().Model(&domain.ThuChi{}).Select("id, code").Where("id IN ?", gocIDs).Scan(&goc).Error; err != nil {
			return nil, nil, err
		}
		for _, g := range goc {
			maGoc[g.ID] = g.Code
		}
	}
	var con []struct {
		ID, AdjustsID uint
		Code          string
	}
	if err := db.Model(&domain.ThuChi{}).Select("id, adjusts_id, code").Where("adjusts_id IN ?", ids).Order("id").Scan(&con).Error; err != nil {
		return nil, nil, err
	}
	for _, c := range con {
		dieuChinh[c.AdjustsID] = append(dieuChinh[c.AdjustsID], domain.ThuChiLienKet{ID: c.ID, Code: c.Code})
	}
	return maGoc, dieuChinh, nil
}

// cotDaCo — DB chưa chạy migration thêm cột (0094 adjusts_id, 0096 kind) thì
// bỏ qua phần đọc cột ấy thay vì làm hỏng cả màn. Chỉ nhớ kết quả "đã có":
// chạy migration xong là dùng được ngay, không phải khởi động lại API.
var cotDaCo sync.Map

func coCot(db *gorm.DB, model any, cot string) bool {
	k := fmt.Sprintf("%T.%s", model, cot)
	if _, co := cotDaCo.Load(k); co {
		return true
	}
	// information_schema không có tenant: chạy ngoài phạm vi, bộ gác SQL thô mới cho qua.
	ctx := tenant.WithoutScope(db.Statement.Context, "kiểm lược đồ: bảng có cột "+cot+" chưa")
	if db.Session(&gorm.Session{NewDB: true}).WithContext(ctx).Migrator().HasColumn(model, cot) {
		cotDaCo.Store(k, true)
		return true
	}
	return false
}

func (r *caLamViecRepository) NopVeTrongCa(ctx context.Context, caID uint) (float64, error) {
	db := r.db.WithContext(ctx)
	if !coCot(db, &domain.ThuChi{}, "kind") {
		return 0, nil
	}
	var tong float64
	err := db.Model(&domain.ThuChi{}).Where("shift_id = ? AND kind = ?", caID, domain.ThuChiNopVe).
		Select("COALESCE(SUM(amount), 0)").Scan(&tong).Error
	return tong, err
}
