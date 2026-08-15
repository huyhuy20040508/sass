package service

import (
	"context"
	"strconv"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
)

// Kiểu dữ liệu của một khoá tính năng — quyết định cách kiểm giá trị và loại ô
// nhập mà màn hình Tính năng gói dựng ra.
const (
	// PlanFeatureSo là hạn mức dạng SỐ NGUYÊN KHÔNG ÂM. Có thể kèm "vo_han".
	//
	// Chỉ số nguyên, không nhận số thập phân: mọi hạn mức ở đây đều là thứ đếm
	// được (chi nhánh, tài khoản, sản phẩm), và "2,5 tài khoản" là một câu vô
	// nghĩa mà không có gì phía sau chặn lại.
	PlanFeatureSo = "so"
	// PlanFeatureCoKhong là khoá bật/tắt, nhận đúng "1" hoặc "0".
	PlanFeatureCoKhong = "co_khong"
)

// planFeatureDef khai báo MỘT khoá được phép tồn tại trong bảng plan_features.
//
// REGISTRY NÀY LÀ NGUỒN SỰ THẬT DUY NHẤT, và nó gánh phần việc mà database đã
// thôi không gánh: cột `value` là VARCHAR nên lược đồ không còn giữ hộ kiểu dữ
// liệu như hồi `max_shops` còn là SMALLINT (xem migration 0005). Khoá không có ở
// đây thì API từ chối ghi, dù database có sẵn dòng đó.
type planFeatureDef struct {
	Key   string
	Type  string
	Label string
	// DonVi in sau ô nhập cho người đọc biết đang đếm cái gì. Rỗng với khoá
	// bật/tắt.
	DonVi string
	// KhongCoDong là điều người đọc thấy khi gói KHÔNG có dòng cho khoá này.
	//
	// KHÁC hẳn "giá trị mặc định" của bảng cấu hình cửa hàng: ở đó khoá thiếu
	// dòng vẫn có một giá trị đúng để dùng. Ở đây, với hạn mức, thiếu dòng nghĩa
	// là BẢNG GIÁ KHÔNG QUY ĐỊNH — số cụ thể chốt lúc ký hợp đồng — nên giá trị
	// là chuỗi rỗng và không được điền hộ số nào.
	//
	// Với khoá bật/tắt thì "0": gói mới thêm vào bảng giá không tự nhiên kèm
	// tính năng nào (xem migration 0004).
	KhongCoDong string
	// ChoVoHan = true: khoá này nhận thêm giá trị domain.VoHan.
	ChoVoHan bool
	// MaxNum là trần khi lưu (0 = không chặn).
	//
	// Có trần vì đây là ĐIỀU KHOẢN BÁN HÀNG: gõ thừa một chữ số vào số chi nhánh
	// là bán ra một gói không ai định bán, và không có gì báo động. Trần đặt cao
	// hơn hẳn mức thật để không cản việc bán hàng, chỉ chặn cái gõ nhầm.
	MaxNum float64
}

// planFeatureRegistry — thứ tự khai báo cũng là thứ tự hiển thị trên màn hình.
//
// Bốn khoá này là bản dịch của bảng giá đang công khai trên landing (mục
// #bang-gia) sang dạng máy đọc được. Thêm khoá mới ở đây là ĐỦ để nó xuất hiện
// trên màn hình và ghi được xuống database — không cần migration, đó chính là
// lý do bảng plan_features tồn tại.
var planFeatureRegistry = []planFeatureDef{
	{
		Key: domain.FeatureMaxShops, Type: PlanFeatureSo,
		Label: "Số chi nhánh", DonVi: "cửa hàng",
		// Gói Chuỗi cố ý KHÔNG có dòng này: landing ghi "từ hai cửa hàng trở lên",
		// số cụ thể chốt lúc ký. Đây là con số người lập hợp đồng chép sang
		// subscriptions.max_shops — quên chép thì mặc định bên đó là 1.
		KhongCoDong: "", ChoVoHan: true, MaxNum: 1_000,
	},
	{
		Key: domain.FeatureMaxUsers, Type: PlanFeatureSo,
		Label: "Số tài khoản", DonVi: "tài khoản",
		KhongCoDong: "", ChoVoHan: true, MaxNum: 10_000,
	},
	{
		Key: domain.FeatureMaxProducts, Type: PlanFeatureSo,
		Label: "Số sản phẩm", DonVi: "sản phẩm",
		KhongCoDong: "", ChoVoHan: true, MaxNum: 1_000_000,
	},
	{
		Key: domain.FeatureOwnDomain, Type: PlanFeatureCoKhong,
		Label: "Tên miền riêng",
		// Tắt là mặc định, và mặc định đó tốn tiền thật nếu bật nhầm: mỗi tên miền
		// cấp ra là một bản ghi DNS, một server block nginx và một chứng chỉ phải
		// gia hạn. Nơi ép luật là `cmd/ten-mien`, không phải màn hình này.
		KhongCoDong: "0",
	},
}

// planFeatureDefs tra cứu nhanh theo khoá, dựng một lần lúc nạp package.
var planFeatureDefs = func() map[string]planFeatureDef {
	m := make(map[string]planFeatureDef, len(planFeatureRegistry))
	for _, d := range planFeatureRegistry {
		m[d.Key] = d
	}
	return m
}()

// PlanFeatureValidationError gom lỗi theo từng khoá để handler trả 422 kèm chi
// tiết, giống hệt lỗi validate của binding JSON.
type PlanFeatureValidationError struct {
	Fields map[string]string
}

func (e *PlanFeatureValidationError) Error() string { return "tính năng gói không hợp lệ" }

// PlanService đọc bảng giá và ghi điều khoản của từng gói.
//
// CHẠY TRÊN CONTROL PLANE. Không có tenant nào trong ctx của các hàm dưới đây,
// và cũng không cần: bảng giá là của nền tảng, không thuộc cửa hàng nào. Đổi
// lại, service này KHÔNG được bộ lọc tenant che chắn, nên quyền phải chặn ở
// tầng trên — xem middleware.XacThucNenTang.
//
// ĐÂY LÀ BẢNG GIÁ, KHÔNG PHẢI HỢP ĐỒNG. Sửa hạn mức ở đây KHÔNG đụng tới khách
// đang dùng: thuê bao chép giá và hạn mức ra lúc ký rồi sống độc lập (xem
// domain.Subscription). Ai muốn đổi hạn mức của MỘT khách thì phải sửa thuê bao
// của khách đó, không phải sửa bảng giá.
//
// MỌI HÀM NHẬN `quyen` — tập phần mềm người gọi được phụ trách (migration
// 0010). Truyền vào chứ không đọc từ ctx: kiểu dữ liệu bắt buộc nơi gọi phải
// nghĩ tới nó, còn đọc lén từ ctx thì một handler quên gắn middleware sẽ chạy
// với quyền rỗng mà trông vẫn y hệt code đúng.
type PlanService interface {
	// Apps trả về DANH MỤC PHẦN MỀM người này được phụ trách.
	//
	// Nằm cùng service với bảng giá vì nó phục vụ chính mấy màn hình đó: bộ chọn
	// phần mềm ở đầu khu điều hành, và nó phải lọc theo cùng một phân công —
	// tách ra service riêng thì luật lọc bị chép làm hai bản.
	Apps(ctx context.Context, quyen domain.QuyenApp) (dto.AppsResponse, error)
	// List trả về bảng giá kèm điều khoản của từng dòng.
	//
	// appCode rỗng = mọi app NGƯỜI NÀY ĐƯỢC PHỤ TRÁCH, không phải mọi app trong
	// danh mục. appCode chỉ đích danh một app không được giao thì ErrKhongPhuTrachApp.
	List(ctx context.Context, quyen domain.QuyenApp, appCode string) (dto.PlansResponse, error)
	// Features trả về một dòng bảng giá kèm điều khoản của nó.
	Features(ctx context.Context, quyen domain.QuyenApp, planID uint) (dto.PlanFeaturesResponse, error)
	// UpdateFeatures ghi các khoá gửi lên; giá trị rỗng là XOÁ dòng.
	UpdateFeatures(ctx context.Context, quyen domain.QuyenApp, planID uint, items map[string]string) (dto.PlanFeaturesResponse, error)
	// Sua ghi phần thương mại của một dòng bảng giá: tên, mô tả, GIÁ, số ngày
	// dùng thử, còn bán hay không.
	//
	// Cùng lời cảnh báo như cả service này: đây là BẢNG GIÁ. Hạ giá gói ở đây
	// KHÔNG hạ tiền của khách đang dùng — thuê bao chép giá ra lúc ký. Ai muốn
	// đổi giá của MỘT khách thì sửa hợp đồng của khách đó.
	Sua(ctx context.Context, quyen domain.QuyenApp, planID uint, req dto.SuaGoiRequest) (dto.PlanFeaturesResponse, error)
}

type planService struct {
	repo domain.PlanRepository
	apps domain.AppRepository
}

func NewPlanService(repo domain.PlanRepository, apps domain.AppRepository) PlanService {
	return &planService{repo: repo, apps: apps}
}

func (s *planService) Apps(ctx context.Context, quyen domain.QuyenApp) (dto.AppsResponse, error) {
	rows, err := s.apps.List(ctx)
	if err != nil {
		return dto.AppsResponse{}, err
	}

	res := dto.AppsResponse{Apps: make([]dto.AppItem, 0, len(rows))}
	for _, row := range rows {
		// Lọc theo phân công: repo trả cả danh mục, đây là chỗ cắt xuống phần
		// người này được nhìn. Người chưa được giao gì nhận danh sách RỖNG — và
		// đó là câu trả lời đúng, không phải lỗi.
		if !quyen.ChoPhep(row.Code) {
			continue
		}
		res.Apps = append(res.Apps, dto.AppItem{
			ID:           row.ID,
			Code:         row.Code,
			Name:         row.Name,
			Tagline:      string(row.Tagline),
			Status:       row.Status,
			SoGoiDangBan: row.SoGoiDangBan,
		})
	}

	return res, nil
}

func (s *planService) List(ctx context.Context, quyen domain.QuyenApp, appCode string) (dto.PlansResponse, error) {
	appCode = strings.TrimSpace(appCode)
	// Hỏi đích danh một phần mềm không được giao: trả lời thẳng là không phụ
	// trách, đừng trả danh sách rỗng. Danh sách rỗng đọc thành "phần mềm này
	// chưa có gói nào", và người ta sẽ đi tạo gói mới cho một sản phẩm không
	// phải của mình.
	if appCode != "" && !quyen.ChoPhep(appCode) {
		return dto.PlansResponse{}, domain.ErrKhongPhuTrachApp
	}

	rows, err := s.repo.List(ctx, appCode)
	if err != nil {
		return dto.PlansResponse{}, err
	}

	// Lọc theo phân công: repo trả bảng giá của cả nền tảng, và đây là chỗ cắt
	// xuống phần người này được nhìn.
	if !quyen.ToanQuyen {
		giu := make([]domain.PlanWithApp, 0, len(rows))
		for _, row := range rows {
			if quyen.ChoPhep(row.AppCode) {
				giu = append(giu, row)
			}
		}
		rows = giu
	}

	ids := make([]uint, 0, len(rows))
	for _, row := range rows {
		ids = append(ids, row.ID)
	}
	features, err := s.repo.FeaturesOf(ctx, ids)
	if err != nil {
		return dto.PlansResponse{}, err
	}

	res := dto.PlansResponse{
		Plans:  make([]dto.PlanItem, 0, len(rows)),
		Fields: planFeatureFields(),
	}
	for _, row := range rows {
		res.Plans = append(res.Plans, planItem(row, features[row.ID]))
	}

	return res, nil
}

func (s *planService) Features(ctx context.Context, quyen domain.QuyenApp, planID uint) (dto.PlanFeaturesResponse, error) {
	plan, err := s.repo.Find(ctx, planID)
	if err != nil {
		return dto.PlanFeaturesResponse{}, err
	}
	// Gói CÓ THẬT nhưng thuộc phần mềm khác: 403 chứ không 404. Người điều hành
	// là người trong nhà — nói dối họ rằng dòng đó không tồn tại chỉ khiến họ đi
	// tạo một dòng trùng.
	if !quyen.ChoPhep(plan.AppCode) {
		return dto.PlanFeaturesResponse{}, domain.ErrKhongPhuTrachApp
	}

	features, err := s.repo.Features(ctx, planID)
	if err != nil {
		return dto.PlanFeaturesResponse{}, err
	}

	return dto.PlanFeaturesResponse{
		Plan:   planItem(*plan, features),
		Fields: planFeatureFields(),
	}, nil
}

func (s *planService) UpdateFeatures(ctx context.Context, quyen domain.QuyenApp, planID uint, items map[string]string) (dto.PlanFeaturesResponse, error) {
	// Tra gói TRƯỚC khi kiểm giá trị: sửa tính năng của một gói không tồn tại
	// phải là 404, không phải một danh sách lỗi từng ô về một màn hình không mở
	// được. Và xét phân công ngay sau đó, TRƯỚC khi ghi bất cứ thứ gì.
	plan, err := s.repo.Find(ctx, planID)
	if err != nil {
		return dto.PlanFeaturesResponse{}, err
	}
	if !quyen.ChoPhep(plan.AppCode) {
		return dto.PlanFeaturesResponse{}, domain.ErrKhongPhuTrachApp
	}

	dat, xoa, verr := validatePlanFeatures(items)
	if verr != nil {
		return dto.PlanFeaturesResponse{}, verr
	}
	if err := s.repo.SaveFeatures(ctx, planID, dat, xoa); err != nil {
		return dto.PlanFeaturesResponse{}, err
	}

	// Đọc lại từ database chứ không dựng câu trả lời từ payload: bảo đảm màn hình
	// hiện đúng thứ đã ghi xuống, kể cả khi một khoá bị xoá hay bị chuẩn hoá.
	return s.Features(ctx, quyen, planID)
}

func (s *planService) Sua(
	ctx context.Context, quyen domain.QuyenApp, planID uint, req dto.SuaGoiRequest,
) (dto.PlanFeaturesResponse, error) {
	// Tra gói TRƯỚC, y hệt UpdateFeatures: sửa một dòng không tồn tại phải là
	// 404, và phân công phải xét xong trước khi ghi bất cứ thứ gì.
	plan, err := s.repo.Find(ctx, planID)
	if err != nil {
		return dto.PlanFeaturesResponse{}, err
	}
	if !quyen.ChoPhep(plan.AppCode) {
		return dto.PlanFeaturesResponse{}, domain.ErrKhongPhuTrachApp
	}

	fields := map[string]string{}
	name := strings.TrimSpace(req.Name)
	// binding `required` đã chặn chuỗi rỗng, nhưng KHÔNG chặn chuỗi toàn dấu
	// cách — mà đó lại đúng là thứ lọt qua: tên gói thành rỗng sau khi cắt, và
	// mọi hợp đồng ký sau đó hiện một ô trắng ở cột Gói.
	if name == "" {
		fields["name"] = "Tên gói không được để trống"
	}
	if req.Status != domain.PlanStatusActive && req.Status != domain.PlanStatusRetired {
		fields["status"] = "Trạng thái chỉ nhận đang bán hoặc ngừng bán"
	}
	if len(fields) > 0 {
		return dto.PlanFeaturesResponse{}, &PlanFeatureValidationError{Fields: fields}
	}

	err = s.repo.Sua(ctx, planID, domain.SuaPlan{
		Name:      name,
		Tagline:   req.Tagline,
		Price:     req.Price,
		TrialDays: req.TrialDays,
		Status:    req.Status,
	})
	if err != nil {
		return dto.PlanFeaturesResponse{}, err
	}

	// Đọc lại từ database, cùng lý do như UpdateFeatures: màn hình phải hiện
	// đúng thứ đã ghi xuống, kể cả khi giá trị bị cắt hay chuẩn hoá.
	return s.Features(ctx, quyen, planID)
}

// validatePlanFeatures kiểm toàn bộ payload trước khi ghi: TẤT-CẢ-HOẶC-KHÔNG.
//
// Một khoá sai thì không khoá nào được ghi. Hạn mức nửa vời nguy hiểm hơn hạn
// mức sai hẳn: người sửa thấy báo lỗi, tưởng không có gì được lưu, trong khi
// database đã nhận một nửa.
//
// Trả về (khoá cần ghi, khoá cần xoá). Giá trị RỖNG là xoá — cách viết câu
// "bảng giá không quy định hạn mức này" (xem domain.PlanFeature).
func validatePlanFeatures(items map[string]string) (map[string]string, []string, *PlanFeatureValidationError) {
	fields := make(map[string]string)
	dat := make(map[string]string, len(items))
	xoa := make([]string, 0, len(items))

	// Đi theo registry chứ không theo map để thứ tự ghi ổn định giữa các lần gọi.
	for _, d := range planFeatureRegistry {
		raw, ok := items[d.Key]
		if !ok {
			continue
		}
		value := strings.TrimSpace(raw)
		if value == "" {
			xoa = append(xoa, d.Key)
			continue
		}
		if msg := kiemGiaTriTinhNang(d, value); msg != "" {
			fields[d.Key] = msg
			continue
		}
		dat[d.Key] = value
	}

	// Khoá lạ: từ chối thẳng thay vì âm thầm ghi vào database rồi không ai đọc.
	// Đây là chỗ duy nhất còn chặn được, vì cột `value` không còn kiểu dữ liệu.
	for key := range items {
		if _, ok := planFeatureDefs[key]; !ok {
			fields[key] = "Khoá tính năng không tồn tại"
		}
	}

	if len(fields) > 0 {
		return nil, nil, &PlanFeatureValidationError{Fields: fields}
	}

	return dat, xoa, nil
}

func kiemGiaTriTinhNang(d planFeatureDef, value string) string {
	switch d.Type {
	case PlanFeatureCoKhong:
		if value != "0" && value != "1" {
			return "Giá trị chỉ nhận 0 hoặc 1"
		}
	case PlanFeatureSo:
		if value == domain.VoHan {
			if !d.ChoVoHan {
				return "Khoá này không nhận giá trị không giới hạn"
			}
			return ""
		}
		// ParseUint chứ không ParseFloat: chặn luôn số âm và số thập phân bằng
		// chính bộ phân tích, thay vì kiểm lại bằng tay sau đó.
		n, err := strconv.ParseUint(value, 10, 64)
		if err != nil {
			if d.ChoVoHan {
				return "Giá trị phải là số nguyên không âm, hoặc " + domain.VoHan + " nếu không giới hạn"
			}
			return "Giá trị phải là số nguyên không âm"
		}
		if d.MaxNum > 0 && float64(n) > d.MaxNum {
			return "Giá trị tối đa là " + formatMaxNum(d.MaxNum) + " (gõ " + domain.VoHan + " nếu muốn không giới hạn)"
		}
	}

	return ""
}

// planItem ghép một dòng bảng giá với điều khoản của nó thành thứ màn hình đọc.
//
// features có thể nil — gói chưa có điều khoản nào. Trả về map RỖNG chứ không
// nil để phía JSON ra `{}` thay vì `null`: màn hình duyệt map đó, và null làm
// hỏng vòng lặp ở mọi ngôn ngữ khách.
func planItem(row domain.PlanWithApp, features map[string]string) dto.PlanItem {
	if features == nil {
		features = map[string]string{}
	}

	return dto.PlanItem{
		ID:           row.ID,
		AppCode:      row.AppCode,
		AppName:      row.AppName,
		Code:         row.Code,
		Name:         row.Name,
		Tagline:      string(row.Tagline),
		BillingCycle: row.BillingCycle,
		Price:        row.Price,
		TrialDays:    row.TrialDays,
		Status:       row.Status,
		Features:     features,
	}
}

func planFeatureFields() []dto.PlanFeatureField {
	out := make([]dto.PlanFeatureField, 0, len(planFeatureRegistry))
	for _, d := range planFeatureRegistry {
		out = append(out, dto.PlanFeatureField{
			Key:         d.Key,
			Type:        d.Type,
			Label:       d.Label,
			DonVi:       d.DonVi,
			KhongCoDong: d.KhongCoDong,
			ChoVoHan:    d.ChoVoHan,
			MaxNum:      d.MaxNum,
		})
	}

	return out
}
