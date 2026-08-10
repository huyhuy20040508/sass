package repository

import (
	"context"
	"errors"
	"strings"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"

	"sass-api/internal/domain"
)

type contactRepository struct{ db *gorm.DB }

func NewContactRepository(db *gorm.DB) domain.ContactRepository {
	return &contactRepository{db: db}
}

func (r *contactRepository) List(ctx context.Context, f domain.ContactFilter) ([]domain.ContactRequest, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.ContactRequest{})

	if f.Type != "" && f.Type != "all" {
		q = q.Where("type = ?", f.Type)
	}
	if f.Status != "" && f.Status != "all" {
		q = q.Where("status = ?", f.Status)
	}
	if f.From != nil {
		q = q.Where("created_at >= ?", *f.From)
	}
	if f.To != nil {
		q = q.Where("created_at <= ?", *f.To)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		like := "%" + kw + "%"
		q = q.Where("(full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR subject LIKE ? OR content LIKE ?)",
			like, like, like, like, like)
	}

	// Đếm TRƯỚC khi gắn Limit/Offset — gắn sau thì con số đếm được chỉ là số dòng
	// của một trang, và thanh phân trang luôn hiện đúng một trang.
	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	page, size := f.Page, f.PageSize
	if page < 1 {
		page = 1
	}
	if size < 1 || size > 200 {
		size = 20
	}

	var list []domain.ContactRequest
	err := q.Preload("Handler").
		Order("created_at DESC").
		Limit(size).Offset((page - 1) * size).
		Find(&list).Error
	if err != nil {
		return nil, 0, err
	}
	return list, total, nil
}

func (r *contactRepository) FindByID(ctx context.Context, id uint) (*domain.ContactRequest, error) {
	var c domain.ContactRequest
	err := r.db.WithContext(ctx).Preload("Handler").First(&c, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &c, err
}

func (r *contactRepository) Create(ctx context.Context, c *domain.ContactRequest) error {
	return r.db.WithContext(ctx).Create(c).Error
}

// Update ghi lại một yêu cầu.
//
// Omit(clause.Associations) là BẮT BUỘC, không phải cho gọn: bản ghi truyền vào
// đây vừa được đọc lên kèm Preload("Handler"), nên trường Handler đang trỏ tới
// một hàng users thật. Không chặn thì GORM tự "lo hộ" hai việc, cả hai đều sai:
//
//  1. Nó ghi ngược khoá ngoại từ association — HandledBy vừa gán nil bị đặt lại
//     thành Handler.ID. Đó chính là lý do kéo trạng thái từ "da-xong" về "moi"
//     xoá được handled_at nhưng handled_by vẫn dính tên nhân viên cũ: bản ghi
//     hiện "chưa xử lý" mà vẫn khoe người đã xử lý.
//  2. Nó upsert luôn hàng users kèm theo — mỗi lần đổi trạng thái một yêu cầu
//     của khách lại ghi đè nguyên hồ sơ nhân viên bằng bản đã nạp trong bộ nhớ.
//
// Bảng contact_requests không sở hữu dữ liệu nhân viên; nó chỉ trỏ tới. Nên lệnh
// ghi ở đây phải đụng ĐÚNG các cột của chính nó.
func (r *contactRepository) Update(ctx context.Context, c *domain.ContactRequest) error {
	return r.db.WithContext(ctx).Omit(clause.Associations).Save(c).Error
}

func (r *contactRepository) Delete(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Delete(&domain.ContactRequest{}, id).Error
}

func (r *contactRepository) CountByStatus(ctx context.Context) (map[string]int64, error) {
	var rows []struct {
		Status string
		Total  int64
	}
	if err := r.db.WithContext(ctx).
		Model(&domain.ContactRequest{}).
		Select("status, COUNT(*) AS total").
		Group("status").
		Scan(&rows).Error; err != nil {
		return nil, err
	}

	// Trả về đủ MỌI trạng thái, kể cả trạng thái chưa có dòng nào: bên gọi đọc
	// thẳng theo khoá mà không phải tự xử lý trường hợp thiếu khoá.
	out := map[string]int64{
		domain.ContactStatusNew:     0,
		domain.ContactStatusWorking: 0,
		domain.ContactStatusDone:    0,
	}
	for _, row := range rows {
		out[row.Status] = row.Total
	}
	return out, nil
}

// ---------------------------------------------------------------------------

type newsletterRepository struct{ db *gorm.DB }

func NewNewsletterRepository(db *gorm.DB) domain.NewsletterRepository {
	return &newsletterRepository{db: db}
}

func (r *newsletterRepository) List(ctx context.Context, f domain.NewsletterFilter) ([]domain.NewsletterSubscriber, int64, error) {
	q := r.db.WithContext(ctx).Model(&domain.NewsletterSubscriber{})

	switch f.Status {
	case "active":
		q = q.Where("is_active = ?", true)
	case "inactive":
		q = q.Where("is_active = ?", false)
	}
	if kw := strings.TrimSpace(f.Keyword); kw != "" {
		q = q.Where("email LIKE ?", "%"+kw+"%")
	}

	var total int64
	if err := q.Count(&total).Error; err != nil {
		return nil, 0, err
	}

	page, size := f.Page, f.PageSize
	if page < 1 {
		page = 1
	}
	if size < 1 || size > 500 {
		size = 20
	}

	var list []domain.NewsletterSubscriber
	err := q.Order("created_at DESC").
		Limit(size).Offset((page - 1) * size).
		Find(&list).Error
	if err != nil {
		return nil, 0, err
	}
	return list, total, nil
}

func (r *newsletterRepository) FindByID(ctx context.Context, id uint) (*domain.NewsletterSubscriber, error) {
	var s domain.NewsletterSubscriber
	err := r.db.WithContext(ctx).First(&s, id).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &s, err
}

func (r *newsletterRepository) FindByEmail(ctx context.Context, email string) (*domain.NewsletterSubscriber, error) {
	var s domain.NewsletterSubscriber
	err := r.db.WithContext(ctx).Where("email = ?", email).First(&s).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil, domain.ErrNotFound
	}
	return &s, err
}

func (r *newsletterRepository) Create(ctx context.Context, s *domain.NewsletterSubscriber) error {
	return r.db.WithContext(ctx).Create(s).Error
}

func (r *newsletterRepository) Update(ctx context.Context, s *domain.NewsletterSubscriber) error {
	return r.db.WithContext(ctx).Save(s).Error
}

func (r *newsletterRepository) Delete(ctx context.Context, id uint) error {
	return r.db.WithContext(ctx).Delete(&domain.NewsletterSubscriber{}, id).Error
}
