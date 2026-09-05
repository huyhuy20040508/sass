package service

import (
	"context"
	"fmt"
	"regexp"
	"strings"
	"time"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// voucherTimeLayout khớp đúng định dạng ô <input type="datetime-local"> của trang
// quản trị, để hai bên không phải đổi qua đổi lại.
const voucherTimeLayout = "2006-01-02T15:04"

// Mã voucher là thứ khách phải GÕ TAY ở ô thanh toán. Chữ có dấu, khoảng trắng
// hay ký tự lạ đều tạo ra mã mà một phần khách gõ không trúng, rồi gọi lên hỏi
// vì sao "mã không hợp lệ".
var voucherCodeRe = regexp.MustCompile(`^[A-Z0-9_-]{3,50}$`)

// VoucherService — nghiệp vụ mã giảm giá.
//
// Gồm hai phần tách bạch:
//   - Quản trị: thêm/sửa/xoá/bật tắt mã.
//   - Khách dùng mã: kiểm hiệu lực và tính số tiền giảm. Cả lúc khách gõ mã ở giỏ
//     hàng lẫn lúc bấm đặt đều đi qua ĐÚNG một hàm (Check) — kiểm hai nơi là sớm
//     muộn số hiện trên màn hình lệch số trừ vào đơn.
type VoucherService interface {
	List(ctx context.Context, f domain.VoucherFilter) ([]dto.VoucherResponse, int64, error)
	Stats(ctx context.Context) (domain.VoucherStats, error)
	Get(ctx context.Context, id uint) (*dto.VoucherResponse, error)
	Create(ctx context.Context, req dto.VoucherRequest) (*dto.VoucherResponse, error)
	Update(ctx context.Context, id uint, req dto.VoucherRequest) (*dto.VoucherResponse, error)
	SetActive(ctx context.Context, id uint, active bool) error
	Delete(ctx context.Context, id uint) error

	// Check kiểm mã khách nhập với một đơn có tiền hàng là subtotal, trả về voucher
	// và số tiền được giảm.
	//
	// userID = 0 là khách vãng lai; khi đó phone (số điện thoại người nhận) là thứ
	// duy nhất nhận ra họ để chặn hạn mức "mỗi khách N lượt". Cả hai đều trống thì
	// hạn mức đó không kiểm được — lúc khách gõ mã ở giỏ hàng mà chưa điền số thì
	// đúng là chưa biết họ là ai, và bước đặt hàng sẽ kiểm lại lần nữa.
	//
	// Lỗi trả về là lỗi CỤ THỂ (hết hạn / hết lượt / chưa đủ đơn tối thiểu…) để
	// khách biết nên bỏ mã đi hay mua thêm cho đủ.
	Check(ctx context.Context, code string, subtotal float64, userID uint, phone string) (*domain.Voucher, float64, error)

	// Available liệt kê các mã ĐẠI TRÀ để gợi ý ngay tại ô nhập mã, kèm sẵn số tiền
	// mỗi mã giảm được cho giỏ hiện tại.
	//
	// Chỉ trả mã đã bật cờ công khai — mã gửi tay cho một người tuyệt đối không lọt
	// ra đây. Mã khách đã dùng hết lượt riêng cũng bị loại: khoe một mã rồi báo lỗi
	// khi họ bấm vào còn tệ hơn không khoe.
	Available(ctx context.Context, subtotal float64, userID uint, phone string) ([]dto.VoucherAvailableItem, error)
}

type voucherService struct {
	repo domain.VoucherRepository
}

func NewVoucherService(repo domain.VoucherRepository) VoucherService {
	return &voucherService{repo: repo}
}

func (s *voucherService) List(ctx context.Context, f domain.VoucherFilter) ([]dto.VoucherResponse, int64, error) {
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PageSize < 1 || f.PageSize > 100 {
		f.PageSize = 20
	}

	items, total, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, 0, err
	}

	now := time.Now()
	out := make([]dto.VoucherResponse, 0, len(items))
	for i := range items {
		out = append(out, toVoucherResponse(&items[i], now))
	}
	return out, total, nil
}

func (s *voucherService) Stats(ctx context.Context) (domain.VoucherStats, error) {
	return s.repo.Stats(ctx)
}

func (s *voucherService) Get(ctx context.Context, id uint) (*dto.VoucherResponse, error) {
	v, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	res := toVoucherResponse(v, time.Now())
	return &res, nil
}

func (s *voucherService) Create(ctx context.Context, req dto.VoucherRequest) (*dto.VoucherResponse, error) {
	v, err := s.build(ctx, &domain.Voucher{}, req)
	if err != nil {
		return nil, err
	}
	if err := s.repo.Create(ctx, v); err != nil {
		return nil, err
	}
	// Ghi SAU khi có id. Rỗng cũng gọi: đó là cách gỡ hết chi nhánh để mã dùng
	// được ở mọi nơi trở lại.
	if err := s.repo.ReplaceShops(ctx, v.ID, req.ShopIDs); err != nil {
		return nil, err
	}
	// Gắn lại trước khi dựng phản hồi — xem ghi chú cùng chỗ ở promotion_service.
	v.Shops = chiNhanhTuIDs(req.ShopIDs)
	res := toVoucherResponse(v, time.Now())
	return &res, nil
}

func (s *voucherService) Update(ctx context.Context, id uint, req dto.VoucherRequest) (*dto.VoucherResponse, error) {
	existing, err := s.repo.FindByID(ctx, id)
	if err != nil {
		return nil, err
	}
	v, err := s.build(ctx, existing, req)
	if err != nil {
		return nil, err
	}
	if err := s.repo.Update(ctx, v); err != nil {
		return nil, err
	}
	if err := s.repo.ReplaceShops(ctx, v.ID, req.ShopIDs); err != nil {
		return nil, err
	}
	v.Shops = chiNhanhTuIDs(req.ShopIDs)
	res := toVoucherResponse(v, time.Now())
	return &res, nil
}

func (s *voucherService) SetActive(ctx context.Context, id uint, active bool) error {
	return s.repo.SetActive(ctx, id, active)
}

func (s *voucherService) Delete(ctx context.Context, id uint) error {
	return s.repo.Delete(ctx, id)
}

// ---------- Khách dùng mã ----------

func (s *voucherService) Check(ctx context.Context, code string, subtotal float64, userID uint, phone string) (*domain.Voucher, float64, error) {
	code = strings.ToUpper(strings.TrimSpace(code))
	if code == "" {
		return nil, 0, domain.ErrVoucherNotFound
	}
	// Giỏ rỗng thì không có gì để giảm. Chặn ở đây để phía dưới mọi mã hợp lệ đều
	// ra số tiền giảm > 0, khỏi phải có nhánh "nhận mã nhưng giảm 0đ".
	if subtotal <= 0 {
		return nil, 0, domain.ErrEmptyCart
	}

	v, err := s.repo.FindByCode(ctx, code)
	if err != nil {
		return nil, 0, err
	}

	// Thứ tự kiểm = thứ tự người dùng cần biết. Mã hết hạn thì nói hết hạn, đừng
	// nói "chưa đủ đơn tối thiểu" rồi để khách mua thêm hàng một cách vô ích.
	now := time.Now()
	switch voucherStatus(v, now) {
	case "ended":
		return nil, 0, domain.ErrVoucherExpired
	case "used_up":
		return nil, 0, domain.ErrVoucherOutOfUses
	case "paused":
		return nil, 0, domain.ErrVoucherInactive
	case "scheduled":
		return nil, 0, domain.ErrVoucherNotStarted
	}

	// Hạn mức theo từng khách: nhận diện bằng tài khoản, hoặc bằng số điện thoại
	// người nhận khi khách không đăng nhập. Chưa biết cả hai (khách gõ mã trước khi
	// điền số) thì bỏ qua ở đây — bước đặt hàng luôn có số nên sẽ kiểm lại.
	if v.UsageLimitPerUser != nil && (userID > 0 || strings.TrimSpace(phone) != "") {
		used, err := s.repo.CountUsageByUser(ctx, v.ID, userID, phone)
		if err != nil {
			return nil, 0, err
		}
		if used >= int64(*v.UsageLimitPerUser) {
			return nil, 0, domain.ErrVoucherUserLimitReached
		}
	}

	if subtotal < v.MinOrderAmount {
		// Kèm SỐ TIỀN CÒN THIẾU vào lỗi: "chưa đạt đơn tối thiểu" không cho khách
		// biết phải mua thêm bao nhiêu, mà đó đúng là câu họ đang hỏi.
		return nil, 0, fmt.Errorf("%w: %s", domain.ErrVoucherMinOrder, formatVND(v.MinOrderAmount-subtotal))
	}

	return v, v.Discount(subtotal), nil
}

// publicVoucherLimit chặn số mã gợi ý. Ô nhập mã không phải trang danh mục — hiện
// hai chục mã thì khách không chọn nổi cái nào.
const publicVoucherLimit = 8

func (s *voucherService) Available(ctx context.Context, subtotal float64, userID uint, phone string) ([]dto.VoucherAvailableItem, error) {
	if subtotal <= 0 {
		return []dto.VoucherAvailableItem{}, nil
	}

	items, err := s.repo.ListPublic(ctx, time.Now(), publicVoucherLimit)
	if err != nil {
		return nil, err
	}
	if len(items) == 0 {
		return []dto.VoucherAvailableItem{}, nil
	}

	// Đếm lượt đã dùng của khách cho TẤT CẢ mã trong một truy vấn, thay vì hỏi từng
	// mã một — đây là đường đi của mọi lần khách mở trang thanh toán.
	ids := make([]uint, 0, len(items))
	for i := range items {
		if items[i].UsageLimitPerUser != nil {
			ids = append(ids, items[i].ID)
		}
	}
	usedByMe, err := s.repo.CountUsageByUserBulk(ctx, ids, userID, phone)
	if err != nil {
		return nil, err
	}

	out := make([]dto.VoucherAvailableItem, 0, len(items))
	for i := range items {
		v := &items[i]

		// Khách đã dùng hết lượt riêng của mã này thì bỏ hẳn khỏi danh sách.
		if v.UsageLimitPerUser != nil && usedByMe[v.ID] >= int64(*v.UsageLimitPerUser) {
			continue
		}

		item := dto.VoucherAvailableItem{
			Code:              v.Code,
			Description:       v.Description,
			DiscountType:      v.DiscountType,
			DiscountValue:     v.DiscountValue,
			MaxDiscountAmount: v.MaxDiscountAmount,
			MinOrderAmount:    v.MinOrderAmount,
		}
		if v.EndAt != nil {
			item.EndAt = v.EndAt.Format(voucherTimeLayout)
		}

		// Chưa đủ đơn tối thiểu thì VẪN hiện, kèm số tiền còn thiếu: đó vừa là câu
		// trả lời cho "sao mã này không bấm được", vừa là lý do để mua thêm.
		if subtotal < v.MinOrderAmount {
			item.MissingAmount = v.MinOrderAmount - subtotal
		} else {
			item.Usable = true
			item.Discount = v.Discount(subtotal)
		}
		out = append(out, item)
	}

	return out, nil
}

// build đổ dữ liệu yêu cầu vào entity và kiểm tra những ràng buộc mà thẻ binding
// không diễn đạt nổi (chúng phụ thuộc lẫn nhau hoặc phải hỏi database).
func (s *voucherService) build(ctx context.Context, v *domain.Voucher, req dto.VoucherRequest) (*domain.Voucher, error) {
	// Chuẩn hoá TRƯỚC khi kiểm tra trùng: khách gõ "sale10" phải trúng mã "SALE10",
	// nên mã lưu xuống luôn là chữ hoa và bảng chỉ chứa đúng một dạng viết.
	code := strings.ToUpper(strings.TrimSpace(req.Code))
	if !voucherCodeRe.MatchString(code) {
		return nil, domain.ErrVoucherCodeInvalid
	}
	taken, err := s.repo.CodeTaken(ctx, code, v.ID)
	if err != nil {
		return nil, err
	}
	if taken {
		return nil, domain.ErrVoucherCodeExists
	}

	start, err := parseVoucherTime(req.StartAt)
	if err != nil {
		return nil, err
	}
	end, err := parseVoucherTime(req.EndAt)
	if err != nil {
		return nil, err
	}
	// Chỉ so khi có ĐỦ hai mốc: để trống một bên là "không giới hạn phía đó", hoàn
	// toàn hợp lệ. Kết thúc trước khi bắt đầu thì mã không bao giờ dùng được, nhưng
	// nhìn danh sách vẫn thấy nó nằm đó như thật.
	if start != nil && end != nil && !end.After(*start) {
		return nil, domain.ErrVoucherTimeRange
	}

	if req.DiscountType == domain.DiscountPercentage && req.DiscountValue > 100 {
		return nil, domain.ErrVoucherPercentRange
	}

	// Hạ tổng lượt xuống dưới số lượt đã phát ra là giết mã ngay lúc lưu — nó rơi
	// thẳng vào nhóm "hết lượt" mà người khai không hề định làm vậy.
	if req.UsageLimit != nil && *req.UsageLimit < v.UsedCount {
		return nil, domain.ErrVoucherLimitBelowUsed
	}

	v.Code = code
	v.Description = strings.TrimSpace(req.Description)
	v.DiscountType = req.DiscountType
	v.DiscountValue = req.DiscountValue
	// Trần giảm chỉ có nghĩa khi giảm theo %. Giữ lại ở kiểu "giảm số tiền" thì nó
	// nằm im trong database rồi một ngày nào đó có người tưởng nó đang có tác dụng.
	if req.DiscountType == domain.DiscountPercentage {
		v.MaxDiscountAmount = req.MaxDiscountAmount
	} else {
		v.MaxDiscountAmount = nil
	}
	v.MinOrderAmount = req.MinOrderAmount
	v.UsageLimit = req.UsageLimit
	v.UsageLimitPerUser = req.UsageLimitPerUser
	v.StartAt = start
	v.EndAt = end
	v.IsActive = boolOrDefault(req.IsActive, true)
	// Mặc định GIỮ KÍN: khoe nhầm một mã đền bù ra cho cả thiên hạ thì không rút
	// lại được, còn quên bật công khai thì chỉ là mã ít người dùng.
	v.IsPublic = boolOrDefault(req.IsPublic, false)
	return v, nil
}

// parseVoucherTime đọc một mốc thời gian có thể bỏ trống. Chuỗi rỗng → nil (không
// giới hạn), chuỗi sai định dạng → lỗi.
func parseVoucherTime(s string) (*time.Time, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil, nil
	}
	t, err := time.ParseInLocation(voucherTimeLayout, s, time.Local)
	if err != nil {
		return nil, domain.ErrVoucherTimeRange
	}
	return &t, nil
}

func toVoucherResponse(v *domain.Voucher, now time.Time) dto.VoucherResponse {
	shops := make([]uint, 0, len(v.Shops))
	for _, cn := range v.Shops {
		shops = append(shops, cn.ID)
	}

	res := dto.VoucherResponse{
		ShopIDs:           shops,
		ID:                v.ID,
		Code:              v.Code,
		Description:       v.Description,
		DiscountType:      v.DiscountType,
		DiscountValue:     v.DiscountValue,
		MaxDiscountAmount: v.MaxDiscountAmount,
		MinOrderAmount:    v.MinOrderAmount,
		UsageLimit:        v.UsageLimit,
		UsageLimitPerUser: v.UsageLimitPerUser,
		UsedCount:         v.UsedCount,
		IsActive:          v.IsActive,
		IsPublic:          v.IsPublic,
		Status:            voucherStatus(v, now),
		CreatedAt:         v.CreatedAt.Format(time.RFC3339),
	}
	if v.StartAt != nil {
		res.StartAt = v.StartAt.Format(voucherTimeLayout)
	}
	if v.EndAt != nil {
		res.EndAt = v.EndAt.Format(voucherTimeLayout)
	}
	if v.UsageLimit != nil {
		// Kẹp ở 0: used_count có thể vượt usage_limit nếu ai đó hạ giới hạn xuống sau
		// khi mã đã phát, và "còn -3 lượt" thì không nói lên điều gì.
		var left uint
		if *v.UsageLimit > v.UsedCount {
			left = *v.UsageLimit - v.UsedCount
		}
		res.Remaining = &left
	}
	return res
}

// voucherStatus quy trạng thái về đúng năm nhóm mà người bán hỏi. Tính ở MỘT chỗ
// để trang quản trị không tự suy ra một kiểu khác.
//
// Thứ tự ưu tiên phải khớp applyVoucherStatus() bên repository — lệch một bậc là
// dải thẻ đếm ra một đằng, bảng hiện một nẻo.
func voucherStatus(v *domain.Voucher, now time.Time) string {
	switch {
	case v.EndAt != nil && now.After(*v.EndAt):
		return "ended"
	case v.UsageLimit != nil && v.UsedCount >= *v.UsageLimit:
		return "used_up"
	case !v.IsActive:
		return "paused"
	case v.StartAt != nil && now.Before(*v.StartAt):
		return "scheduled"
	default:
		return "running"
	}
}
