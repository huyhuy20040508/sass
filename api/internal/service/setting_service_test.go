package service

import (
	"context"
	"errors"
	"testing"

	"sass-api/internal/domain"
)

// fakeSettingRepo là bảng settings trong bộ nhớ — đủ để kiểm phần logic của
// service (registry, validate, snapshot) mà không cần MySQL.
type fakeSettingRepo struct {
	rows map[string]domain.Setting
}

func newFakeSettingRepo() *fakeSettingRepo {
	return &fakeSettingRepo{rows: map[string]domain.Setting{}}
}

func (r *fakeSettingRepo) Map(_ context.Context) (map[string]string, error) {
	out := make(map[string]string, len(r.rows))
	for k, row := range r.rows {
		out[k] = row.Value
	}
	return out, nil
}

func (r *fakeSettingRepo) Upsert(_ context.Context, items []domain.Setting) error {
	for _, item := range items {
		r.rows[item.Key] = item
	}
	return nil
}

// Khoá không khai trong registry phải bị từ chối thẳng. Nếu ghi bừa xuống database
// thì sau này không ai đọc, mà người sửa lại tưởng đã cấu hình xong.
func TestSettingUpdateTuChoiKhoaLa(t *testing.T) {
	repo := newFakeSettingRepo()
	svc := NewSettingService(repo)

	_, err := svc.Update(context.Background(), map[string]string{"khoa_bia_dat": "1"})
	if err == nil {
		t.Fatal("khoá lạ phải bị từ chối")
	}
	var ve *SettingValidationError
	if !errors.As(err, &ve) {
		t.Fatalf("phải là lỗi validate, nhận: %v", err)
	}
	if _, ok := ve.Fields["khoa_bia_dat"]; !ok {
		t.Fatalf("lỗi phải chỉ đúng khoá sai, nhận: %v", ve.Fields)
	}
	if len(repo.rows) != 0 {
		t.Fatalf("không được ghi gì xuống database, nhận %d dòng", len(repo.rows))
	}
}

// Một khoá sai làm cả lần ghi bị huỷ — không lưu nửa vời, vì phí ship lưu được mà
// ngưỡng miễn phí thì không sẽ cho ra bảng giá không ai định đặt ra.
func TestSettingUpdateTatCaHoacKhongGi(t *testing.T) {
	repo := newFakeSettingRepo()
	svc := NewSettingService(repo)

	_, err := svc.Update(context.Background(), map[string]string{
		SettingDefaultShippingFee:    "45000",
		SettingFreeShippingThreshold: "khong-phai-so",
	})
	if err == nil {
		t.Fatal("giá trị sai kiểu phải bị từ chối")
	}
	if len(repo.rows) != 0 {
		t.Fatalf("một khoá sai thì không khoá nào được ghi, nhận %d dòng", len(repo.rows))
	}
}

// Số âm và số vượt trần đều bị chặn: đây là tiền, gõ thừa một chữ số là mọi đơn
// sau đó tính sai mà không có gì báo động.
func TestSettingValidateGioiHanSo(t *testing.T) {
	repo := newFakeSettingRepo()
	svc := NewSettingService(repo)
	ctx := context.Background()

	for _, tc := range []struct {
		name  string
		value string
	}{
		{"số âm", "-1"},
		{"vượt trần", "999999999"},
	} {
		if _, err := svc.Update(ctx, map[string]string{SettingDefaultShippingFee: tc.value}); err == nil {
			t.Fatalf("%s phải bị từ chối", tc.name)
		}
	}

	if _, err := svc.Update(ctx, map[string]string{SettingDefaultShippingFee: "45000"}); err != nil {
		t.Fatalf("giá trị hợp lệ phải lưu được: %v", err)
	}
	if got := svc.Float(SettingDefaultShippingFee); got != 45000 {
		t.Fatalf("snapshot phải cập nhật sau khi ghi, nhận %v", got)
	}
}

// Tắt cả hai hình thức thanh toán = storefront không còn nút nào để đặt hàng.
func TestSettingChanTatHetHinhThucThanhToan(t *testing.T) {
	repo := newFakeSettingRepo()
	svc := NewSettingService(repo)

	_, err := svc.Update(context.Background(), map[string]string{
		SettingPaymentCODEnabled:  "0",
		SettingPaymentBankEnabled: "0",
	})
	var ve *SettingValidationError
	if !errors.As(err, &ve) {
		t.Fatalf("tắt cả hai phải bị từ chối, nhận: %v", err)
	}
	if _, ok := ve.Fields[SettingPaymentCODEnabled]; !ok {
		t.Fatalf("lỗi phải gắn vào cả hai công tắc, nhận: %v", ve.Fields)
	}
	if len(repo.rows) != 0 {
		t.Fatalf("không được ghi gì khi đã chặn, nhận %d dòng", len(repo.rows))
	}
}

// Bật chuyển khoản mà chưa khai tài khoản nhận tiền = bảo khách chuyển vào hư không.
func TestSettingChuyenKhoanPhaiCoTaiKhoan(t *testing.T) {
	svc := NewSettingService(newFakeSettingRepo())
	ctx := context.Background()

	_, err := svc.Update(ctx, map[string]string{
		SettingPaymentBankEnabled: "1",
		SettingBankName:           "Vietcombank",
	})
	var ve *SettingValidationError
	if !errors.As(err, &ve) {
		t.Fatalf("thiếu số tài khoản phải bị từ chối, nhận: %v", err)
	}
	if _, ok := ve.Fields[SettingBankAccountNumber]; !ok {
		t.Fatalf("lỗi phải chỉ đúng ô còn thiếu, nhận: %v", ve.Fields)
	}

	// Khai đủ thì lưu được, và chuyển khoản mới thực sự được chào ra.
	if _, err := svc.Update(ctx, map[string]string{
		SettingPaymentBankEnabled: "1",
		SettingBankName:           "Vietcombank",
		SettingBankAccountNumber:  "0123456789",
		SettingBankAccountName:    "NGUYEN VAN A",
	}); err != nil {
		t.Fatalf("khai đủ thì phải lưu được, nhận: %v", err)
	}
	if !paymentMethodAvailable(svc, "bank_transfer") {
		t.Fatal("khai đủ tài khoản rồi thì chuyển khoản phải dùng được")
	}

	// Tắt cờ đi thì hết chào ra, dù thông tin tài khoản vẫn còn nguyên.
	if _, err := svc.Update(ctx, map[string]string{SettingPaymentBankEnabled: "0"}); err != nil {
		t.Fatalf("tắt chuyển khoản (COD vẫn bật) phải lưu được, nhận: %v", err)
	}
	if paymentMethodAvailable(svc, "bank_transfer") {
		t.Fatal("đã tắt thì không được chào chuyển khoản nữa")
	}
}

// Bản cài mới: chuyển khoản bật sẵn nhưng CHƯA có số tài khoản — phải coi như chưa
// nhận, không được chào ra rồi để khách bấm vào một hướng dẫn trống.
func TestSettingChuyenKhoanMacDinhChuaSanSang(t *testing.T) {
	svc := NewSettingService(newFakeSettingRepo())
	if err := svc.Load(context.Background()); err != nil {
		t.Fatalf("Load lỗi: %v", err)
	}

	if !svc.Bool(SettingPaymentBankEnabled) {
		t.Fatal("mặc định phải bật cờ chuyển khoản")
	}
	if paymentMethodAvailable(svc, "bank_transfer") {
		t.Fatal("chưa khai tài khoản thì chưa được chào chuyển khoản")
	}
	if !paymentMethodAvailable(svc, "cod") {
		t.Fatal("COD mặc định phải dùng được, không thì bản cài mới không bán được gì")
	}
}

// Luật chéo của nhóm thanh toán KHÔNG được chặn việc lưu nhóm khác: cấu hình mặc
// định (bật chuyển khoản, chưa có tài khoản) sẽ khoá luôn trang Vận chuyển.
func TestSettingLuatThanhToanKhongChanNhomKhac(t *testing.T) {
	svc := NewSettingService(newFakeSettingRepo())

	if _, err := svc.Update(context.Background(), map[string]string{
		SettingDefaultShippingFee: "35000",
	}); err != nil {
		t.Fatalf("lưu nhóm vận chuyển phải chạy được, nhận: %v", err)
	}
}

// Khoá chưa có dòng trong database vẫn phải đọc ra giá trị mặc định — đó là điều
// giữ cho bản cài mới chạy y hệt lúc các con số còn nằm cứng trong code.
func TestSettingMacDinhKhiChuaCoDuLieu(t *testing.T) {
	svc := NewSettingService(newFakeSettingRepo())
	if err := svc.Load(context.Background()); err != nil {
		t.Fatalf("Load lỗi: %v", err)
	}

	if got := svc.Float(SettingFreeShippingThreshold); got != 1_000_000 {
		t.Fatalf("ngưỡng miễn phí ship mặc định phải là 1.000.000, nhận %v", got)
	}
	if got := svc.Int(SettingLowStockThreshold); got != 5 {
		t.Fatalf("ngưỡng sắp hết mặc định phải là 5 (khớp InventoryController::LOW_STOCK), nhận %v", got)
	}
}

// Link mạng xã hội thiếu http:// phải bị từ chối: chuỗi "facebook.com/shop" khi
// đưa vào href sẽ bị trình duyệt hiểu là đường dẫn tương đối của chính website,
// khách bấm vào ra trang 404 của cửa hàng chứ không sang Facebook.
func TestSettingValidateDuongDan(t *testing.T) {
	svc := NewSettingService(newFakeSettingRepo())
	ctx := context.Background()

	for _, bad := range []string{"facebook.com/shop", "www.facebook.com", "ftp://a.com", "https://"} {
		if _, err := svc.Update(ctx, map[string]string{SettingSocialFacebook: bad}); err == nil {
			t.Fatalf("%q phải bị từ chối", bad)
		}
	}

	for _, ok := range []string{"https://facebook.com/shop", "http://fb.me/x"} {
		if _, err := svc.Update(ctx, map[string]string{SettingSocialFacebook: ok}); err != nil {
			t.Fatalf("%q phải hợp lệ, nhận: %v", ok, err)
		}
	}

	// Không bắt buộc: bỏ trống là "chưa có trang này", storefront sẽ ẩn biểu tượng.
	if _, err := svc.Update(ctx, map[string]string{SettingSocialFacebook: ""}); err != nil {
		t.Fatalf("bỏ trống phải hợp lệ, nhận: %v", err)
	}
}

// Khoá đổi nhóm (VD "sales" -> "inventory") vẫn phải đọc ra GIÁ TRỊ ĐÃ LƯU, dù
// dòng cũ trong database còn mang tên nhóm trước đó.
//
// Nếu List lọc theo cột `group` dưới database thì trang cấu hình sẽ hiện giá trị
// mặc định và người dùng tưởng cấu hình bị reset — im lặng và rất khó lần ra.
func TestSettingDocDuocKhoaDaDoiNhom(t *testing.T) {
	repo := newFakeSettingRepo()
	repo.rows[SettingLowStockThreshold] = domain.Setting{
		Key: SettingLowStockThreshold, Value: "12", Group: "sales", // tên nhóm cũ
	}

	svc := NewSettingService(repo)
	res, err := svc.List(context.Background(), SettingGroupInventory)
	if err != nil {
		t.Fatalf("List lỗi: %v", err)
	}
	if got := res.Values[SettingLowStockThreshold]; got != "12" {
		t.Fatalf("phải đọc ra giá trị đã lưu là 12, nhận %q", got)
	}
}

// Public chỉ lộ khoá đánh dấu công khai. Ngưỡng tồn kho là số liệu nội bộ, lọt ra
// storefront là kể cho khách nghe cách cửa hàng quản kho.
func TestSettingPublicKhongLoKhoaNoiBo(t *testing.T) {
	svc := NewSettingService(newFakeSettingRepo())

	pub := svc.Public()
	if _, ok := pub[SettingLowStockThreshold]; ok {
		t.Fatal("ngưỡng tồn kho không được lộ ra storefront")
	}
	if _, ok := pub[SettingContactPhone]; !ok {
		t.Fatal("hotline phải nằm trong cấu hình công khai")
	}
}

// PayOS cần ĐỦ hai vế: công tắc trong trang Cài đặt và bộ khoá trong .env. Thiếu
// vế .env mà vẫn chào ra thì khách chọn xong sẽ bị API từ chối ở bước cuối.
func TestSettingPayOSCanCaCongTacVaKhoaEnv(t *testing.T) {
	ctx := context.Background()
	svc := NewSettingService(newFakeSettingRepo())
	if err := svc.Load(ctx); err != nil {
		t.Fatalf("Load lỗi: %v", err)
	}

	if svc.Bool(SettingPaymentPayOSEnabled) {
		t.Fatal("mặc định PayOS phải TẮT — bản cài mới chưa có khoá nào")
	}

	// Bản cài mới bật sẵn chuyển khoản mà chưa khai tài khoản, nên mọi lần lưu nhóm
	// thanh toán đều phải tắt nó đi cùng lúc — luật cũ, không liên quan tới PayOS.
	bat := func(payos string) map[string]string {
		return map[string]string{
			SettingPaymentPayOSEnabled: payos,
			SettingPaymentBankEnabled:  "0",
		}
	}

	// Bật công tắc nhưng .env chưa có khoá: phải nói lại cho người dùng biết là
	// hình thức này vẫn chưa hiện ra, thay vì lưu im lặng.
	if _, err := svc.Update(ctx, bat("1")); err == nil {
		t.Fatal("bật PayOS khi chưa khai khoá .env thì phải báo lại cho người dùng biết")
	}
	if paymentMethodAvailable(svc, "payos") {
		t.Fatal("chưa có khoá .env thì không được chào PayOS")
	}

	// Có khoá rồi thì bật lên là dùng được.
	svc.SetPayOSReady(true)
	if _, err := svc.Update(ctx, bat("1")); err != nil {
		t.Fatalf("có khoá .env thì bật PayOS phải lưu được, nhận: %v", err)
	}
	if !paymentMethodAvailable(svc, "payos") {
		t.Fatal("đủ cả công tắc lẫn khoá thì PayOS phải dùng được")
	}

	// Có khoá .env nhưng cửa hàng tắt công tắc thì vẫn là không nhận.
	if _, err := svc.Update(ctx, bat("0")); err != nil {
		t.Fatalf("tắt PayOS (COD vẫn bật) phải lưu được, nhận: %v", err)
	}
	if paymentMethodAvailable(svc, "payos") {
		t.Fatal("cửa hàng đã tắt thì không được chào PayOS")
	}
}

// Cấu hình công khai phải nói THẬT: công tắc bật mà .env chưa có khoá thì báo ra
// là tắt, vì storefront dựng danh sách hình thức thanh toán từ đúng map này.
func TestSettingPublicGiauPayOSKhiThieuKhoa(t *testing.T) {
	ctx := context.Background()
	svc := NewSettingService(newFakeSettingRepo())
	if err := svc.Load(ctx); err != nil {
		t.Fatalf("Load lỗi: %v", err)
	}

	svc.SetPayOSReady(true)
	if _, err := svc.Update(ctx, map[string]string{
		SettingPaymentPayOSEnabled: "1",
		SettingPaymentBankEnabled:  "0",
	}); err != nil {
		t.Fatalf("Update lỗi: %v", err)
	}
	if svc.Public()[SettingPaymentPayOSEnabled] != "1" {
		t.Fatal("đủ khoá + đã bật thì cấu hình công khai phải báo bật")
	}

	svc.SetPayOSReady(false)
	if got := svc.Public()[SettingPaymentPayOSEnabled]; got != "0" {
		t.Fatalf("mất khoá .env thì cấu hình công khai phải báo tắt, nhận: %q", got)
	}
}

// SePay cũng cần đủ hai vế như PayOS: công tắc ở trang Cài đặt và cấu hình tài
// khoản trong .env.
func TestSettingSePayCanCaCongTacVaCauHinhEnv(t *testing.T) {
	ctx := context.Background()
	svc := NewSettingService(newFakeSettingRepo())
	if err := svc.Load(ctx); err != nil {
		t.Fatalf("Load lỗi: %v", err)
	}

	if svc.Bool(SettingPaymentSePayEnabled) {
		t.Fatal("mặc định SePay phải TẮT — bản cài mới chưa khai tài khoản nào")
	}

	// Bản cài mới bật sẵn chuyển khoản tay mà chưa khai tài khoản, nên mọi lần lưu
	// nhóm thanh toán đều phải tắt nó đi cùng lúc — luật cũ, không liên quan SePay.
	bat := func(sepay string) map[string]string {
		return map[string]string{
			SettingPaymentSePayEnabled: sepay,
			SettingPaymentBankEnabled:  "0",
		}
	}

	if _, err := svc.Update(ctx, bat("1")); err == nil {
		t.Fatal("bật SePay khi chưa khai .env thì phải báo lại cho người dùng biết")
	}
	if paymentMethodAvailable(svc, "sepay") {
		t.Fatal("chưa khai .env thì không được chào SePay")
	}

	svc.SetSePayReady(true)
	if _, err := svc.Update(ctx, bat("1")); err != nil {
		t.Fatalf("khai đủ .env thì bật SePay phải lưu được, nhận: %v", err)
	}
	if !paymentMethodAvailable(svc, "sepay") {
		t.Fatal("đủ cả công tắc lẫn cấu hình thì SePay phải dùng được")
	}
	if svc.Public()[SettingPaymentSePayEnabled] != "1" {
		t.Fatal("cấu hình công khai phải báo bật")
	}

	// Mất cấu hình .env thì cấu hình công khai phải nói thật là tắt.
	svc.SetSePayReady(false)
	if got := svc.Public()[SettingPaymentSePayEnabled]; got != "0" {
		t.Fatalf("mất cấu hình .env thì phải báo tắt, nhận: %q", got)
	}
}

// Hai cổng QR bật cùng lúc vẫn phải sống chung được: cửa hàng có thể muốn chào cả
// hai, hoặc chuyển dần từ cổng này sang cổng kia.
func TestSettingHaiCongQRSongSong(t *testing.T) {
	ctx := context.Background()
	svc := NewSettingService(newFakeSettingRepo())
	if err := svc.Load(ctx); err != nil {
		t.Fatalf("Load lỗi: %v", err)
	}
	svc.SetPayOSReady(true)
	svc.SetSePayReady(true)

	if _, err := svc.Update(ctx, map[string]string{
		SettingPaymentPayOSEnabled: "1",
		SettingPaymentSePayEnabled: "1",
		SettingPaymentBankEnabled:  "0",
		SettingPaymentCODEnabled:   "0",
	}); err != nil {
		t.Fatalf("bật cả hai cổng QR phải lưu được, nhận: %v", err)
	}
	if !paymentMethodAvailable(svc, "payos") || !paymentMethodAvailable(svc, "sepay") {
		t.Fatal("cả hai cổng phải cùng dùng được")
	}
}

// Tắt HẾT mọi hình thức thì không ai đặt được đơn nào — luật này phải tính cả
// SePay, không thì tắt hết mà hệ thống vẫn báo hợp lệ.
func TestSettingTatHetKeCaSePayBiChan(t *testing.T) {
	ctx := context.Background()
	svc := NewSettingService(newFakeSettingRepo())
	if err := svc.Load(ctx); err != nil {
		t.Fatalf("Load lỗi: %v", err)
	}
	svc.SetSePayReady(true)

	_, err := svc.Update(ctx, map[string]string{
		SettingPaymentCODEnabled:   "0",
		SettingPaymentBankEnabled:  "0",
		SettingPaymentPayOSEnabled: "0",
		SettingPaymentSePayEnabled: "0",
	})
	var ve *SettingValidationError
	if !errors.As(err, &ve) {
		t.Fatalf("tắt hết phải bị từ chối, nhận: %v", err)
	}
	if _, ok := ve.Fields[SettingPaymentSePayEnabled]; !ok {
		t.Fatalf("lỗi phải chỉ cả ô SePay, nhận: %v", ve.Fields)
	}
}
