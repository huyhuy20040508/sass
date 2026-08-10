package service

import (
	"context"
	"encoding/json"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// ContactService — nghiệp vụ nhận và xử lý yêu cầu khách gửi từ storefront
// (form Liên hệ, form Thu mua) cùng danh sách email đăng ký nhận tin.
type ContactService interface {
	// Create nhận một yêu cầu mới từ khách. ip chỉ để lần nguồn khi bị dội rác.
	Create(ctx context.Context, req dto.CreateContactRequest, ip string) (*dto.ContactRequestResponse, error)
	List(ctx context.Context, f domain.ContactFilter) ([]dto.ContactRequestResponse, int64, error)
	Get(ctx context.Context, id uint) (*dto.ContactRequestResponse, error)
	UpdateStatus(ctx context.Context, id uint, req dto.UpdateContactStatusRequest, userID uint) (*dto.ContactRequestResponse, error)
	Delete(ctx context.Context, id uint) error
	Stats(ctx context.Context) (map[string]int64, error)

	// Subscribe thêm một email vào danh sách nhận tin. Email đã có thì bật lại
	// chứ không báo lỗi — xem chú thích trong thân hàm.
	Subscribe(ctx context.Context, req dto.SubscribeNewsletterRequest, ip string) error
	ListSubscribers(ctx context.Context, f domain.NewsletterFilter) ([]domain.NewsletterSubscriber, int64, error)
	Unsubscribe(ctx context.Context, id uint) error
}

type contactService struct {
	repo domain.ContactRepository
	news domain.NewsletterRepository
}

func NewContactService(repo domain.ContactRepository, news domain.NewsletterRepository) ContactService {
	return &contactService{repo: repo, news: news}
}

func (s *contactService) Create(ctx context.Context, req dto.CreateContactRequest, ip string) (*dto.ContactRequestResponse, error) {
	loai := strings.TrimSpace(req.Type)
	if loai == "" {
		loai = domain.ContactTypeContact
	}

	// Ảnh cất dưới dạng chuỗi JSON để khớp kiểu cột. Không có ảnh thì để NULL —
	// bắt buộc, vì chuỗi rỗng không phải JSON hợp lệ và MySQL sẽ chặn lệnh ghi.
	var anh *string
	if len(req.Images) > 0 {
		b, err := json.Marshal(req.Images)
		if err != nil {
			return nil, err
		}
		s := string(b)
		anh = &s
	}

	rec := &domain.ContactRequest{
		Type:     loai,
		FullName: strings.TrimSpace(req.FullName),
		Phone:    strings.TrimSpace(req.Phone),
		Email:    normalizeEmail(req.Email),
		Address:  strings.TrimSpace(req.Address),
		Subject:  strings.TrimSpace(req.Subject),
		Content:  strings.TrimSpace(req.Content),
		Images:   anh,
		Status:   domain.ContactStatusNew,
		IP:       ip,
	}
	if err := s.repo.Create(ctx, rec); err != nil {
		return nil, err
	}

	out := toContactResponse(*rec)
	return &out, nil
}

func (s *contactService) List(ctx context.Context, f domain.ContactFilter) ([]dto.ContactRequestResponse, int64, error) {
	list, total, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, 0, err
	}

	out := make([]dto.ContactRequestResponse, 0, len(list))
	for _, rec := range list {
		out = append(out, toContactResponse(rec))
	}
	return out, total, nil
}

func (s *contactService) Get(ctx context.Context, id uint) (*dto.ContactRequestResponse, error) {
	rec, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	out := toContactResponse(*rec)
	return &out, nil
}

func (s *contactService) UpdateStatus(ctx context.Context, id uint, req dto.UpdateContactStatusRequest, userID uint) (*dto.ContactRequestResponse, error) {
	rec, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}

	rec.Status = req.Status
	rec.AdminNote = strings.TrimSpace(req.AdminNote)

	// Ghi lại AI xử lý và LÚC NÀO, nhưng chỉ khi chuyển sang "đã xong": kéo về
	// lại "mới" nghĩa là chưa xong, giữ nguyên dấu vết cũ ở đó là nói dối.
	if req.Status == domain.ContactStatusDone {
		now := time.Now()
		rec.HandledAt = &now
		if userID > 0 {
			rec.HandledBy = &userID
		}
	} else {
		rec.HandledAt = nil
		rec.HandledBy = nil
	}

	if err := s.repo.Update(ctx, rec); err != nil {
		return nil, err
	}

	// Đọc lại để lấy kèm tên nhân viên vừa gán (Save không tự nạp quan hệ).
	fresh, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	out := toContactResponse(*fresh)
	return &out, nil
}

func (s *contactService) Delete(ctx context.Context, id uint) error {
	if _, err := s.repo.FindByID(ctx, id); err != nil {
		return err
	}
	return s.repo.Delete(ctx, id)
}

func (s *contactService) Stats(ctx context.Context) (map[string]int64, error) {
	return s.repo.CountByStatus(ctx)
}

// Subscribe thêm email vào danh sách nhận tin.
//
// Email ĐÃ CÓ thì không báo lỗi mà bật lại dòng cũ. Lý do: người dùng không nhớ
// mình từng đăng ký hay chưa, mà báo "email này đã đăng ký rồi" ở một ô nằm dưới
// chân trang thì vừa vô ích vừa lộ ra ai đang trong danh sách. Kết quả họ mong
// đợi — "từ giờ tôi nhận được tin" — đằng nào cũng đúng.
func (s *contactService) Subscribe(ctx context.Context, req dto.SubscribeNewsletterRequest, ip string) error {
	email := normalizeEmail(req.Email)
	nguon := strings.TrimSpace(req.Source)
	if nguon == "" {
		nguon = "footer"
	}

	existing, err := s.news.FindByEmail(ctx, email)
	if err != nil && err != domain.ErrNotFound {
		return err
	}

	if existing != nil {
		if existing.IsActive {
			return nil
		}
		existing.IsActive = true
		existing.UnsubscribedAt = nil
		existing.Source = nguon
		return s.news.Update(ctx, existing)
	}

	return s.news.Create(ctx, &domain.NewsletterSubscriber{
		Email:    email,
		IsActive: true,
		Source:   nguon,
		IP:       ip,
	})
}

func (s *contactService) ListSubscribers(ctx context.Context, f domain.NewsletterFilter) ([]domain.NewsletterSubscriber, int64, error) {
	return s.news.List(ctx, f)
}

// Unsubscribe TẮT chứ không xoá dòng — xem chú thích cột is_active trong schema.
func (s *contactService) Unsubscribe(ctx context.Context, id uint) error {
	rec, err := s.news.FindByID(ctx, id)
	if err != nil {
		return err
	}
	if !rec.IsActive {
		return nil
	}

	now := time.Now()
	rec.IsActive = false
	rec.UnsubscribedAt = &now
	return s.news.Update(ctx, rec)
}

// toContactResponse tách chuỗi JSON ảnh ra mảng và làm phẳng tên người xử lý.
func toContactResponse(rec domain.ContactRequest) dto.ContactRequestResponse {
	var anh []string
	if rec.Images != nil && strings.TrimSpace(*rec.Images) != "" {
		// Hỏng JSON thì bỏ qua phần ảnh chứ không làm hỏng cả bản ghi: mất mấy
		// tấm ảnh còn hơn mất luôn tên và số điện thoại của khách.
		_ = json.Unmarshal([]byte(*rec.Images), &anh)
	}
	if anh == nil {
		anh = []string{}
	}

	out := dto.ContactRequestResponse{
		ID:        rec.ID,
		Type:      rec.Type,
		FullName:  rec.FullName,
		Phone:     rec.Phone,
		Email:     rec.Email,
		Address:   rec.Address,
		Subject:   rec.Subject,
		Content:   rec.Content,
		Images:    anh,
		Status:    rec.Status,
		AdminNote: rec.AdminNote,
		CreatedAt: rec.CreatedAt.Format(time.RFC3339),
	}
	if rec.Handler != nil {
		out.HandlerName = rec.Handler.FullName
	}
	if rec.HandledAt != nil {
		out.HandledAt = rec.HandledAt.Format(time.RFC3339)
	}
	return out
}
