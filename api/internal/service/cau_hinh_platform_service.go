package service

import (
	"context"
	"errors"
	"strconv"
	"strings"

	"sass-api/internal/domain"
	"sass-api/internal/dto"
	"sass-api/pkg/bimat"
)

// Kiểu dữ liệu của một ô cấu hình nền tảng — quyết định cách kiểm giá trị và
// loại ô nhập mà màn hình dựng ra.
const (
	CauHinhChu       = "text"
	CauHinhBool      = "bool"
	CauHinhAnh       = "image"
	CauHinhNhieuHang = "textarea"
)

// Mã các khoá cấu hình của NHÀ CUNG CẤP.
//
// Tiền tố `ck_` = chuyển khoản. Hôm nay nền tảng nhận tiền gia hạn bằng đúng một
// hình thức, và bộ khoá này là toàn bộ những gì cần để khách chuyển đúng chỗ,
// đúng nội dung.
//
// Khoá API của cổng thanh toán (PayOS/SePay) KHÔNG có ở đây và không được thêm
// vào: chúng là bí mật, còn bảng này thì nằm nguyên văn trong mọi bản sao lưu
// database — xem migration 0015.
const (
	CauHinhCKBat         = "ck_bat"
	CauHinhCKNganHangMa  = "ck_ngan_hang_ma"
	CauHinhCKNganHangTen = "ck_ngan_hang_ten"
	CauHinhCKSoTaiKhoan  = "ck_so_tai_khoan"
	CauHinhCKChuTaiKhoan = "ck_chu_tai_khoan"
	CauHinhCKNoiDungMau  = "ck_noi_dung_mau"
	CauHinhCKAnhQR       = "ck_anh_qr"
	CauHinhCKHuongDan    = "ck_huong_dan"
)

// Khoá của cổng PAYOS.
//
// Ba khoá dưới là BÍ MẬT: cất trong database ở dạng đã mã hoá (xem pkg/bimat),
// và KHÔNG bao giờ trả nguyên văn ra API — màn hình chỉ nhận bản che.
//
// Khác hẳn bộ `ck_*` ngay trên: số tài khoản ngân hàng là thông tin công khai,
// còn ai cầm được ba khoá này thì tạo được link thu tiền đứng tên mình và ký giả
// được webhook báo "đã trả tiền".
const (
	CauHinhPayOSBat         = "payos_bat"
	CauHinhPayOSClientID    = "payos_client_id"
	CauHinhPayOSAPIKey      = "payos_api_key"
	CauHinhPayOSChecksumKey = "payos_checksum_key"
)

// ChoMaCuaHang là chỗ điền MÃ CỬA HÀNG trong mẫu nội dung chuyển khoản.
//
// Bắt buộc phải có trong mẫu, và đó là ràng buộc đáng giá nhất của cả màn hình
// này: nội dung chuyển khoản là thứ DUY NHẤT nói tiền vừa vào là của khách nào.
// Thiếu nó thì sao kê ngân hàng chỉ còn một cột số tiền, và người bán phải đoán
// — hoặc gọi điện hỏi từng khách vừa chuyển bao nhiêu.
const ChoMaCuaHang = "{ma_cua_hang}"

// cauHinhNenTangDef khai báo MỘT ô cấu hình được phép tồn tại.
//
// Registry này là NGUỒN SỰ THẬT DUY NHẤT, và nó gánh phần việc database đã thôi
// không gánh: cột `value` là TEXT nên lược đồ không giữ hộ kiểu dữ liệu. Khoá
// không có ở đây thì API từ chối ghi, dù database có sẵn dòng đó.
type cauHinhNenTangDef struct {
	Key   string
	Type  string
	Label string
	// Gợi ý in dưới ô nhập. Viết cho người bán đọc, không phải cho lập trình viên.
	GoiY string
	// MacDinh là giá trị khi chưa ai khai.
	MacDinh string
	// BatBuocKhiBat = true: bỏ trống ô này mà vẫn bật hình thức thanh toán TƯƠNG
	// ỨNG (xem CongTac) thì API từ chối lưu. Đây là chốt chặn cho đúng một tình
	// huống — bật hình thức nhận tiền lên mà khách không biết chuyển vào đâu.
	BatBuocKhiBat bool
	// CongTac là khoá bật/tắt chi phối ô này. Rỗng với chính ô công tắc.
	//
	// Có mặt từ khi màn hình có HAI hình thức: thiếu nó thì luật "bắt buộc khi
	// bật" không biết đang hỏi về hình thức nào, và tắt PayOS đi vẫn bị đòi khai
	// số tài khoản ngân hàng.
	CongTac string
	// BiMat = true: giá trị được MÃ HOÁ trước khi ghi, và không bao giờ trả
	// nguyên văn ra ngoài — API chỉ trả bản che (••••1234).
	BiMat bool
	Max   int
}

// cauHinhNenTangRegistry — thứ tự khai báo cũng là thứ tự hiển thị trên màn hình.
var cauHinhNenTangRegistry = []cauHinhNenTangDef{
	{
		Key: CauHinhCKBat, Type: CauHinhBool,
		Label:   "Nhận thanh toán bằng chuyển khoản",
		GoiY:    "Tắt thì trang gia hạn của khách không hiện thông tin tài khoản nào.",
		MacDinh: "0",
	},
	{
		Key: CauHinhCKNganHangTen, CongTac: CauHinhCKBat, Type: CauHinhChu,
		Label: "Tên ngân hàng", GoiY: "Ví dụ: Vietcombank",
		BatBuocKhiBat: true, Max: 100,
	},
	{
		// Mã chuẩn VietQR (VCB, TCB, MB…). CHƯA chỗ nào dùng tới, và vẫn thu ngay
		// từ bây giờ: ngày dựng QR động — mã QR mang sẵn số tiền và nội dung — thì
		// nó cần đúng con số này, và đi hỏi lại từng người bán sau đó thì tốn hơn
		// nhiều so với một ô nhập thêm hôm nay.
		Key: CauHinhCKNganHangMa, CongTac: CauHinhCKBat, Type: CauHinhChu,
		Label: "Mã ngân hàng (VietQR)", GoiY: "Ví dụ: VCB, TCB, MB. Dùng để tạo mã QR có sẵn số tiền.",
		Max: 20,
	},
	{
		Key: CauHinhCKSoTaiKhoan, CongTac: CauHinhCKBat, Type: CauHinhChu,
		Label: "Số tài khoản", GoiY: "Chỉ gồm chữ số.",
		BatBuocKhiBat: true, Max: 32,
	},
	{
		Key: CauHinhCKChuTaiKhoan, CongTac: CauHinhCKBat, Type: CauHinhChu,
		Label: "Chủ tài khoản", GoiY: "Viết hoa không dấu, đúng như trên sao kê.",
		BatBuocKhiBat: true, Max: 100,
	},
	{
		Key: CauHinhCKNoiDungMau, CongTac: CauHinhCKBat, Type: CauHinhChu,
		Label:         "Mẫu nội dung chuyển khoản",
		GoiY:          "Phải chứa " + ChoMaCuaHang + " — đây là thứ duy nhất nói tiền vừa vào là của khách nào.",
		MacDinh:       "GIAHAN " + ChoMaCuaHang,
		BatBuocKhiBat: true, Max: 120,
	},
	{
		Key: CauHinhCKAnhQR, CongTac: CauHinhCKBat, Type: CauHinhAnh,
		Label: "Ảnh mã QR", GoiY: "Không bắt buộc. Mã QR tĩnh của tài khoản; khách vẫn phải tự gõ số tiền.",
		Max: 500,
	},
	{
		Key: CauHinhCKHuongDan, CongTac: CauHinhCKBat, Type: CauHinhNhieuHang,
		Label: "Hướng dẫn thêm cho khách",
		GoiY:  "Hiện dưới khối chuyển khoản trên trang gia hạn. Ví dụ: thời gian xác nhận, số điện thoại hỗ trợ.",
		Max:   500,
	},

	// ----- PayOS: khách bấm trả tiền, cổng tự báo về -----
	//
	// Khác chuyển khoản tay ở chỗ nó ĐỐI SOÁT ĐƯỢC: PayOS gọi webhook báo đơn nào
	// vừa trả, nên hạn hợp đồng đẩy được ngay mà không ai phải nhìn sao kê. Ba
	// khoá dưới lấy ở trang quản trị của PayOS (my.payos.vn).
	{
		Key: CauHinhPayOSBat, Type: CauHinhBool,
		Label:   "Nhận thanh toán qua PayOS",
		GoiY:    "Khách bấm trả tiền và được xác nhận tự động. Cần khai ba khoá bên dưới.",
		MacDinh: "0",
	},
	{
		Key: CauHinhPayOSClientID, CongTac: CauHinhPayOSBat, Type: CauHinhChu,
		Label: "Client ID", GoiY: "Lấy ở my.payos.vn → Kênh thanh toán → Thông tin kết nối.",
		BatBuocKhiBat: true, BiMat: true, Max: 200,
	},
	{
		Key: CauHinhPayOSAPIKey, CongTac: CauHinhPayOSBat, Type: CauHinhChu,
		Label: "API Key", GoiY: "Bí mật — lưu ở dạng mã hoá, sau khi lưu chỉ hiện bốn ký tự cuối.",
		BatBuocKhiBat: true, BiMat: true, Max: 200,
	},
	{
		Key: CauHinhPayOSChecksumKey, CongTac: CauHinhPayOSBat, Type: CauHinhChu,
		Label: "Checksum Key", GoiY: "Dùng để kiểm chữ ký webhook. Sai khoá này thì mọi báo có của PayOS đều bị từ chối.",
		BatBuocKhiBat: true, BiMat: true, Max: 200,
	},
}

var cauHinhNenTangDefs = func() map[string]cauHinhNenTangDef {
	m := make(map[string]cauHinhNenTangDef, len(cauHinhNenTangRegistry))
	for _, d := range cauHinhNenTangRegistry {
		m[d.Key] = d
	}

	return m
}()

// CauHinhNenTangService đọc/ghi cấu hình của NHÀ CUNG CẤP phần mềm.
//
// CHẠY TRÊN CONTROL PLANE, không có tenant nào trong ctx và cũng không cần: đây
// là cấu hình của chính mình, một bộ duy nhất cho cả nền tảng. Đổi lại, không có
// bộ lọc nào che chắn — quyền phải chặn ở tầng trên (middleware.XacThucNenTang),
// và riêng đường GHI thì chỉ owner/operator (xem handler).
type CauHinhNenTangService interface {
	// Doc trả về giá trị hiện tại kèm siêu dữ liệu của từng ô.
	Doc(ctx context.Context) (dto.CauHinhNenTangResponse, error)
	// Ghi lưu các khoá gửi lên; khoá không gửi giữ nguyên.
	//
	// Trả về trạng thái SAU KHI ghi, đọc lại từ database chứ không dựng từ payload.
	Ghi(ctx context.Context, items map[string]string) (dto.CauHinhNenTangResponse, error)
}

type cauHinhNenTangService struct {
	repo domain.PlatformSettingRepository
	// hop mã hoá các ô BÍ MẬT trước khi ghi. Chưa khai khoá thì service vẫn chạy
	// bình thường, chỉ là lượt lưu ô bí mật bị TỪ CHỐI kèm lý do — xem pkg/bimat.
	hop *bimat.Hop
}

func NewCauHinhNenTangService(repo domain.PlatformSettingRepository, hop *bimat.Hop) CauHinhNenTangService {
	return &cauHinhNenTangService{repo: repo, hop: hop}
}

func (s *cauHinhNenTangService) Doc(ctx context.Context) (dto.CauHinhNenTangResponse, error) {
	daLuu, err := s.repo.All(ctx)
	if err != nil {
		return dto.CauHinhNenTangResponse{}, err
	}

	return dto.CauHinhNenTangResponse{
		// CHE trước khi ra khỏi service: đây là ranh giới cuối cùng còn giữ được
		// bí mật. Trả nguyên văn "chỉ cho màn hình quản trị" nghĩa là khoá PayOS
		// nằm trong HTML, trong log truy cập, trong cache của trình duyệt.
		Values:    s.che(hopNhatCauHinh(daLuu)),
		Fields:    cauHinhNenTangFields(),
		KhoaMaHoa: s.hop.SanSang(),
	}, nil
}

// che thay giá trị của mọi ô BÍ MẬT bằng bản che (••••1234).
//
// Ô chưa khai thì để RỖNG — màn hình phân biệt "đã lưu, không hiện" với "chưa có
// gì" bằng đúng chỗ này, và nhầm hai thứ đó là người dùng tưởng mình đã khai rồi.
func (s *cauHinhNenTangService) che(values map[string]string) map[string]string {
	for _, d := range cauHinhNenTangRegistry {
		if !d.BiMat || values[d.Key] == "" {
			continue
		}
		// Giải ra chỉ để lấy bốn ký tự cuối. Giải không được (đổi khoá mã hoá, dữ
		// liệu hỏng) thì vẫn phải nói là ĐÃ CÓ giá trị — che sạch. Trả rỗng ở đây
		// là mời người dùng khai lại một khoá vẫn đang dùng được.
		thuong, err := s.hop.Giai(values[d.Key])
		if err != nil {
			values[d.Key] = strings.Repeat("•", 8)
			continue
		}
		values[d.Key] = bimat.Che(thuong)
	}

	return values
}

func (s *cauHinhNenTangService) Ghi(ctx context.Context, items map[string]string) (dto.CauHinhNenTangResponse, error) {
	daLuu, err := s.repo.All(ctx)
	if err != nil {
		return dto.CauHinhNenTangResponse{}, err
	}

	dat, verr := kiemCauHinhNenTang(items, hopNhatCauHinh(daLuu))
	if verr != nil {
		return dto.CauHinhNenTangResponse{}, verr
	}
	if verr := s.maHoaBiMat(dat); verr != nil {
		return dto.CauHinhNenTangResponse{}, verr
	}
	if err := s.repo.Save(ctx, dat); err != nil {
		return dto.CauHinhNenTangResponse{}, err
	}

	// Đọc lại từ database chứ không dựng câu trả lời từ payload: màn hình phải
	// hiện đúng thứ đã ghi xuống, kể cả khi giá trị bị cắt khoảng trắng.
	return s.Doc(ctx)
}

// maHoaBiMat mã hoá tại chỗ các ô bí mật sắp ghi xuống.
//
// CHƯA KHAI KHOÁ THÌ TỪ CHỐI CẢ LƯỢT GHI, không ghi plaintext "tạm thời": một
// khoá PayOS nằm nguyên văn trong database sẽ nằm đó mãi, và không có gì nhắc
// lại chuyện đó. Từ chối thì người khai thấy ngay và đi khai PLATFORM_SECRET_KEY.
func (s *cauHinhNenTangService) maHoaBiMat(dat map[string]string) *PlanFeatureValidationError {
	fields := make(map[string]string)

	for _, d := range cauHinhNenTangRegistry {
		if !d.BiMat {
			continue
		}
		thuong, co := dat[d.Key]
		if !co || thuong == "" {
			continue
		}

		kin, err := s.hop.Ma(thuong)
		if err != nil {
			if errors.Is(err, bimat.ErrChuaCoKhoa) {
				fields[d.Key] = "Máy chủ chưa khai khoá mã hoá (PLATFORM_SECRET_KEY) nên chưa cất được khoá bí mật"
			} else {
				fields[d.Key] = "Không mã hoá được giá trị này"
			}

			continue
		}
		dat[d.Key] = kin
	}

	if len(fields) > 0 {
		return &PlanFeatureValidationError{Fields: fields}
	}

	return nil
}

// hopNhatCauHinh ghép giá trị đã lưu với mặc định của registry.
//
// Khoá lạ còn sót trong database (đổi tên khoá, gỡ tính năng) bị BỎ QUA: registry
// là nguồn sự thật, và trả ra một khoá không màn hình nào biết chỉ làm phình câu
// trả lời.
func hopNhatCauHinh(daLuu map[string]string) map[string]string {
	out := make(map[string]string, len(cauHinhNenTangRegistry))
	for _, d := range cauHinhNenTangRegistry {
		if v, ok := daLuu[d.Key]; ok {
			out[d.Key] = v
			continue
		}
		out[d.Key] = d.MacDinh
	}

	return out
}

// kiemCauHinhNenTang kiểm toàn bộ payload trước khi ghi: TẤT-CẢ-HOẶC-KHÔNG.
//
// hienTai là giá trị đang có, cần để trả lời câu hỏi mà một mình payload không
// trả lời được: "sau lượt ghi này thì hình thức chuyển khoản có đang bật không".
// Màn hình chỉ gửi lên những ô vừa sửa, nên xét riêng payload thì tắt/bật và số
// tài khoản có thể nằm ở hai lượt gọi khác nhau.
func kiemCauHinhNenTang(items, hienTai map[string]string) (map[string]string, *PlanFeatureValidationError) {
	fields := make(map[string]string)
	dat := make(map[string]string, len(items))

	// Khoá lạ: từ chối thẳng thay vì âm thầm ghi vào database rồi không ai đọc.
	for key := range items {
		if _, ok := cauHinhNenTangDefs[key]; !ok {
			fields[key] = "Khoá cấu hình không tồn tại"
		}
	}

	// Đi theo registry chứ không theo map để thứ tự ghi ổn định giữa các lần gọi.
	sauKhiGhi := make(map[string]string, len(hienTai))
	for k, v := range hienTai {
		sauKhiGhi[k] = v
	}
	for _, d := range cauHinhNenTangRegistry {
		raw, ok := items[d.Key]
		if !ok {
			continue
		}
		value := strings.TrimSpace(raw)

		if d.BiMat {
			// Ô BÍ MẬT: gửi RỖNG = GIỮ NGUYÊN, không phải xoá.
			//
			// Màn hình không bao giờ đổ khoá cũ vào ô nhập (nó chỉ nhận bản che),
			// nên một lượt lưu bình thường — sửa số tài khoản, bấm Lưu — sẽ gửi ô
			// PayOS rỗng. Hiểu rỗng là xoá thì mỗi lần sửa một chữ ở khối trên là
			// một lần cổng thanh toán chết.
			if value == "" {
				continue
			}
			// Bản che bị gửi ngược lên (người dùng copy, trình duyệt tự điền):
			// KHÔNG mã hoá chuỗi chấm tròn rồi ghi đè lên khoá thật.
			if strings.Contains(value, "•") {
				continue
			}
		}

		if d.Type == CauHinhBool && value != "0" && value != "1" {
			fields[d.Key] = "Giá trị chỉ nhận 0 hoặc 1"
			continue
		}
		if d.Max > 0 && len([]rune(value)) > d.Max {
			fields[d.Key] = "Tối đa " + strconv.Itoa(d.Max) + " ký tự"
			continue
		}

		dat[d.Key] = value
		sauKhiGhi[d.Key] = value
	}

	// Bật một hình thức nhận tiền thì thông tin của CHÍNH hình thức đó phải đủ.
	//
	// Xét theo CongTac của từng ô, không phải một công tắc chung: tắt PayOS đi mà
	// vẫn bị đòi khai Client ID thì không ai lưu nổi màn hình này.
	//
	// Xét trên trạng thái SAU KHI GHI, nên tắt hình thức rồi xoá trắng các ô vẫn
	// hợp lệ.
	for _, d := range cauHinhNenTangRegistry {
		if !d.BatBuocKhiBat || d.CongTac == "" || sauKhiGhi[d.CongTac] != "1" {
			continue
		}
		if strings.TrimSpace(sauKhiGhi[d.Key]) == "" {
			fields[d.Key] = "Bật hình thức này thì ô đó không được để trống"
		}
	}

	// Mẫu nội dung thiếu chỗ điền mã cửa hàng: mọi khách sẽ chuyển tiền với cùng
	// một nội dung, và sao kê không nói được tiền của ai.
	if sauKhiGhi[CauHinhCKBat] == "1" {
		if mau := sauKhiGhi[CauHinhCKNoiDungMau]; mau != "" && !strings.Contains(mau, ChoMaCuaHang) {
			fields[CauHinhCKNoiDungMau] = "Mẫu nội dung phải chứa " + ChoMaCuaHang +
				" để biết tiền vừa vào là của cửa hàng nào"
		}
	}

	if len(fields) > 0 {
		return nil, &PlanFeatureValidationError{Fields: fields}
	}

	return dat, nil
}

func cauHinhNenTangFields() []dto.CauHinhNenTangField {
	out := make([]dto.CauHinhNenTangField, 0, len(cauHinhNenTangRegistry))
	for _, d := range cauHinhNenTangRegistry {
		out = append(out, dto.CauHinhNenTangField{
			Key:           d.Key,
			Type:          d.Type,
			Label:         d.Label,
			GoiY:          d.GoiY,
			MacDinh:       d.MacDinh,
			BatBuocKhiBat: d.BatBuocKhiBat,
			CongTac:       d.CongTac,
			BiMat:         d.BiMat,
			Max:           d.Max,
		})
	}

	return out
}
