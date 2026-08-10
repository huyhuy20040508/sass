package mailer

import (
	"bytes"
	"fmt"
	"html/template"
	"strconv"
	"strings"
)

// VerificationData dữ liệu đổ vào email mã xác thực.
//
// Bốn trường chữ ở dưới (Badge, Intro, CodeLabel, Warn) để DÙNG LẠI đúng khuôn
// thư này cho việc khác — hiện là đặt lại mật khẩu. Để trống thì rơi về lời văn
// của email đăng ký, nên chỗ gọi cũ không phải sửa gì.
//
// Vì sao không viết hẳn một khuôn thư thứ hai: hai thư khác nhau đúng bốn câu
// chữ, còn lại giống hệt từ thanh thương hiệu tới chân thư. Nhân đôi ra thì lần
// sau đổi màu thương hiệu là phải nhớ sửa hai chỗ, và chắc chắn sẽ quên một chỗ.
type VerificationData struct {
	StoreName string
	StoreURL  string
	Name      string
	Code      string
	Minutes   int
	Hotline   string
	Year      int

	// Badge — chữ nhỏ góc phải thanh thương hiệu.
	Badge string
	// Intro — câu mở đầu, nói rõ vì sao khách nhận được thư này.
	Intro string
	// CodeLabel — nhãn phía trên ô mã.
	CodeLabel string
	// Warn — câu dặn dò cuối, gồm cả việc phải làm gì nếu không phải mình yêu cầu.
	Warn string
}

// Bốn hàm dưới đây trả về chữ đã khai, hoặc lời văn mặc định của email đăng ký.
// Template gọi các hàm này chứ không đọc thẳng trường, nên bỏ trống là an toàn.

func (d VerificationData) BadgeText() string {
	if strings.TrimSpace(d.Badge) != "" {
		return d.Badge
	}
	return "Xác thực email"
}

func (d VerificationData) IntroText() string {
	if strings.TrimSpace(d.Intro) != "" {
		return d.Intro
	}
	return "Cảm ơn bạn đã đăng ký tài khoản tại " + d.StoreName + ". Nhập mã bên dưới để hoàn tất và bắt đầu mua sắm."
}

func (d VerificationData) CodeLabelText() string {
	if strings.TrimSpace(d.CodeLabel) != "" {
		return d.CodeLabel
	}
	return "Mã xác thực"
}

func (d VerificationData) WarnText() string {
	if strings.TrimSpace(d.Warn) != "" {
		return d.Warn
	}
	return "Vì lý do an toàn, vui lòng không chia sẻ mã này cho bất kỳ ai — kể cả người tự xưng là nhân viên " +
		d.StoreName + ". Nếu bạn không thực hiện đăng ký, hãy bỏ qua email này."
}

// ShowLink cho biết có nên chèn nút/link vào thư hay không.
// Link tới localhost / 127.0.0.1 / IP nội bộ là tín hiệu spam rất mạnh
// (địa chỉ người nhận không mở được), nên khi chạy máy cá nhân thì bỏ hẳn nút đi.
func (d VerificationData) ShowLink() bool {
	u := strings.ToLower(strings.TrimSpace(d.StoreURL))
	if u == "" || !strings.HasPrefix(u, "http") {
		return false
	}
	for _, bad := range []string{"localhost", "127.0.0.1", "0.0.0.0", "://192.168.", "://10.", "://172."} {
		if strings.Contains(u, bad) {
			return false
		}
	}
	return true
}

// OrderItemData là một dòng hàng hiển thị trong email xác nhận đơn.
type OrderItemData struct {
	Name    string
	Options string // "Size M / Đỏ" — rỗng nếu sản phẩm không có biến thể
	Custom  string // tên & số in trên áo, rỗng nếu không in
	Qty     int
	Price   float64 // đơn giá
	Total   float64 // thành tiền của dòng
}

// PriceText / TotalText: định dạng tiền ngay trong template, tránh phải nhét
// chuỗi đã format vào struct ở tầng service.
func (i OrderItemData) PriceText() string { return FormatVND(i.Price) }
func (i OrderItemData) TotalText() string { return FormatVND(i.Total) }

// OrderData dữ liệu đổ vào email xác nhận đơn hàng.
type OrderData struct {
	StoreName string
	StoreURL  string
	Hotline   string
	Year      int

	OrderCode     string
	PlacedAt      string // "14:05 27/07/2026"
	Name          string // tên người nhận
	Phone         string
	Address       string // địa chỉ đã gộp: số nhà, phường/xã, tỉnh/thành
	PaymentMethod string // mô tả cho người đọc, VD "Thanh toán khi nhận hàng (COD)"
	Note          string

	// Hướng dẫn chuyển khoản — chỉ điền khi đơn chọn chuyển khoản VÀ cửa hàng đã
	// khai đủ tài khoản nhận tiền. Để trống thì email không in khối này, vì một
	// khung "chuyển khoản tới ____" trống là tệ hơn không có khung nào.
	//
	// Nội dung chuyển khoản luôn là mã đơn, không có ô riêng: đó là thứ duy nhất
	// đối soát được với sao kê ngân hàng.
	BankName          string
	BankAccountNumber string
	BankAccountName   string
	BankTransferNote  string // dặn dò thêm của cửa hàng, có thì in, không thì thôi

	Items       []OrderItemData
	Subtotal    float64
	Discount    float64 // giảm giá do admin nhập; đơn khách tự đặt luôn là 0
	ShippingFee float64
	Total       float64
}

func (d OrderData) SubtotalText() string { return FormatVND(d.Subtotal) }
func (d OrderData) TotalText() string    { return FormatVND(d.Total) }

// HasBankTransfer: chỉ in khối hướng dẫn chuyển khoản khi có số tài khoản thật.
func (d OrderData) HasBankTransfer() bool { return strings.TrimSpace(d.BankAccountNumber) != "" }

// HasDiscount: chỉ hiện dòng giảm giá khi thực sự có giảm.
func (d OrderData) HasDiscount() bool    { return d.Discount > 0 }
func (d OrderData) DiscountText() string { return "-" + FormatVND(d.Discount) }

// ShippingText: 0đ hiển thị thành "Miễn phí" cho dễ hiểu.
func (d OrderData) ShippingText() string {
	if d.ShippingFee <= 0 {
		return "Miễn phí"
	}
	return FormatVND(d.ShippingFee)
}

// ShowLink — xem giải thích ở VerificationData.ShowLink.
func (d OrderData) ShowLink() bool {
	return VerificationData{StoreURL: d.StoreURL}.ShowLink()
}

// FormatVND định dạng tiền kiểu Việt Nam: 1250000 -> "1.250.000₫".
func FormatVND(v float64) string {
	n := int64(v + 0.5)
	if n < 0 {
		n = 0
	}
	s := strconv.FormatInt(n, 10)
	var b strings.Builder
	for i, c := range s {
		if i > 0 && (len(s)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(c)
	}
	return b.String() + "₫"
}

// Email dùng bảng + style inline vì Gmail/Outlook lược bỏ <style> và không hỗ trợ flex/grid.
const verificationHTML = `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{.CodeLabelText}} {{.StoreName}}</title>
</head>
<body style="margin:0;padding:0;background:#f2f3f5;">
  <!-- dòng xem trước hiện cạnh tiêu đề trong hộp thư, ẩn khỏi nội dung -->
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Mã của bạn là {{.Code}}, hiệu lực {{.Minutes}} phút.</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f3f5;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:560px;background:#ffffff;border:1px solid #e5e5e5;border-radius:4px;overflow:hidden;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

          <!-- Thanh thương hiệu -->
          <tr>
            <td style="background:#282828;padding:20px 28px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="font-size:19px;font-weight:800;letter-spacing:1px;color:#ffffff;text-transform:uppercase;">
                    {{.StoreName}}
                  </td>
                  <td align="right" style="font-size:12px;color:#FFBB00;letter-spacing:.6px;text-transform:uppercase;font-weight:700;">
                    {{.BadgeText}}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Dải vàng -->
          <tr><td style="height:4px;background:#FFBB00;line-height:4px;font-size:0;">&nbsp;</td></tr>

          <!-- Nội dung -->
          <tr>
            <td style="padding:30px 28px 8px;">
              <p style="margin:0 0 6px;font-size:20px;font-weight:700;color:#282828;">Xin chào {{.Name}},</p>
              <p style="margin:0 0 22px;font-size:14.5px;line-height:1.65;color:#5a6169;">
                {{.IntroText}}
              </p>

              <!-- Ô mã -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                <tr>
                  <td align="center" style="background:#fff9e6;border:1px dashed #FFBB00;border-radius:4px;padding:20px 12px;">
                    <div style="font-size:11px;font-weight:700;letter-spacing:1.4px;color:#8a6d00;text-transform:uppercase;margin-bottom:8px;">
                      {{.CodeLabelText}}
                    </div>
                    <div style="font-size:38px;font-weight:800;letter-spacing:10px;color:#282828;font-family:'Courier New',Courier,monospace;">
                      {{.Code}}
                    </div>
                    <div style="font-size:12.5px;color:#8a6d00;margin-top:10px;">
                      Hiệu lực trong {{.Minutes}} phút
                    </div>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 20px;font-size:13.5px;line-height:1.65;color:#5a6169;">
                {{.WarnText}}
              </p>

              {{if .ShowLink}}
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
                <tr>
                  <td style="background:#FFBB00;border-radius:3px;">
                    <a href="{{.StoreURL}}" target="_blank"
                       style="display:inline-block;padding:13px 26px;font-size:14px;font-weight:700;color:#111111;text-decoration:none;">
                      Về cửa hàng
                    </a>
                  </td>
                </tr>
              </table>
              {{end}}
            </td>
          </tr>

          <!-- Chân thư -->
          <tr>
            <td style="border-top:1px solid #ececec;background:#fafbfc;padding:18px 28px;">
              <p style="margin:0 0 4px;font-size:12.5px;color:#6f7780;">
                Cần hỗ trợ? Gọi <a href="tel:{{.Hotline}}" style="color:#282828;font-weight:700;text-decoration:none;">{{.Hotline}}</a>
                hoặc trả lời trực tiếp email này.
              </p>
              <p style="margin:0;font-size:11.5px;color:#9aa0a7;">
                &copy; {{.Year}} {{.StoreName}} — Áo bóng đá chính hãng, giao hàng toàn quốc.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>`

var verificationTpl = template.Must(template.New("verification").Parse(verificationHTML))

// RenderVerification dựng bản HTML + bản text của email mã xác thực.
func RenderVerification(d VerificationData) (html string, text string, err error) {
	var buf bytes.Buffer
	if err = verificationTpl.Execute(&buf, d); err != nil {
		return "", "", err
	}

	lines := []string{
		fmt.Sprintf("Xin chào %s,", d.Name),
		"",
		fmt.Sprintf("%s tài khoản %s của bạn là: %s", d.CodeLabelText(), d.StoreName, d.Code),
		fmt.Sprintf("Mã có hiệu lực trong %d phút. Không chia sẻ mã này cho bất kỳ ai.", d.Minutes),
		"",
		d.WarnText(),
	}
	if d.ShowLink() {
		lines = append(lines, fmt.Sprintf("Hỗ trợ: %s — %s", d.Hotline, d.StoreURL))
	} else {
		lines = append(lines, "Hỗ trợ: "+d.Hotline)
	}

	return buf.String(), strings.Join(lines, "\n"), nil
}

// OrderStatusData dữ liệu đổ vào email báo đơn vừa đổi trạng thái.
type OrderStatusData struct {
	StoreName string
	StoreURL  string
	Hotline   string
	Year      int

	OrderCode string
	Name      string
	Address   string

	// Label là tên trạng thái mới ("Đang giao hàng"), Headline là câu chính hiện
	// to đầu thư, Detail là đoạn giải thích khách cần làm gì tiếp theo.
	Label    string
	Headline string
	Detail   string
	// Accent đổi màu dải trạng thái: đơn huỷ/hoàn dùng đỏ, còn lại dùng vàng thương hiệu.
	Danger bool

	// Reason — lý do huỷ, chỉ có khi đơn bị huỷ.
	Reason         string
	ShippingMethod string
	TrackingNumber string
	Total          float64
}

func (d OrderStatusData) TotalText() string { return FormatVND(d.Total) }

// BarColor / BarText: màu dải trạng thái. Đơn khép lại theo hướng xấu (huỷ, hoàn
// hàng) dùng đỏ để khách không lướt qua nhầm thành tin vui.
func (d OrderStatusData) BarColor() string {
	if d.Danger {
		return "#d0021b"
	}
	return "#FFBB00"
}

func (d OrderStatusData) BarText() string {
	if d.Danger {
		return "#ffffff"
	}
	return "#111111"
}

// HasShipping cho biết có thông tin vận chuyển để hiện hay không.
func (d OrderStatusData) HasShipping() bool {
	return strings.TrimSpace(d.ShippingMethod) != "" || strings.TrimSpace(d.TrackingNumber) != ""
}

// ShowLink — xem giải thích ở VerificationData.ShowLink.
func (d OrderStatusData) ShowLink() bool {
	return VerificationData{StoreURL: d.StoreURL}.ShowLink()
}

const orderStatusHTML = `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Đơn hàng {{.OrderCode}} — {{.Label}}</title>
</head>
<body style="margin:0;padding:0;background:#f2f3f5;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Đơn {{.OrderCode}}: {{.Label}}.</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f3f5;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:560px;background:#ffffff;border:1px solid #e5e5e5;border-radius:4px;overflow:hidden;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

          <tr>
            <td style="background:#282828;padding:20px 28px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="font-size:19px;font-weight:800;letter-spacing:1px;color:#ffffff;text-transform:uppercase;">
                    {{.StoreName}}
                  </td>
                  <td align="right" style="font-size:12px;color:#FFBB00;letter-spacing:.6px;text-transform:uppercase;font-weight:700;">
                    Cập nhật đơn hàng
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr><td style="height:4px;background:{{.BarColor}};line-height:4px;font-size:0;">&nbsp;</td></tr>

          <tr>
            <td style="padding:30px 28px 0;">
              <p style="margin:0 0 6px;font-size:20px;font-weight:700;color:#282828;">{{.Headline}}</p>
              <p style="margin:0 0 20px;font-size:14.5px;line-height:1.65;color:#5a6169;">{{.Detail}}</p>

              <!-- Trạng thái mới -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                <tr>
                  <td align="center" style="background:{{.BarColor}};border-radius:4px;padding:16px 12px;">
                    <div style="font-size:11px;font-weight:700;letter-spacing:1.4px;color:{{.BarText}};text-transform:uppercase;opacity:.75;">
                      Đơn {{.OrderCode}}
                    </div>
                    <div style="font-size:22px;font-weight:800;color:{{.BarText}};margin-top:6px;">{{.Label}}</div>
                  </td>
                </tr>
              </table>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fafbfc;border:1px solid #ececec;border-radius:4px;margin:0 0 8px;">
                <tr>
                  <td style="padding:14px 16px;font-size:13.5px;line-height:1.7;color:#5a6169;">
                    <div><span style="color:#9aa0a7;">Người nhận:</span> <b style="color:#282828;">{{.Name}}</b></div>
                    <div><span style="color:#9aa0a7;">Địa chỉ:</span> {{.Address}}</div>
                    <div><span style="color:#9aa0a7;">Tổng thanh toán:</span> <b style="color:#282828;">{{.TotalText}}</b></div>
                    {{if .HasShipping}}
                    <div><span style="color:#9aa0a7;">Vận chuyển:</span> {{.ShippingMethod}}{{if .TrackingNumber}} — mã vận đơn <b style="color:#282828;">{{.TrackingNumber}}</b>{{end}}</div>
                    {{end}}
                    {{if .Reason}}
                    <div><span style="color:#9aa0a7;">Lý do:</span> {{.Reason}}</div>
                    {{end}}
                  </td>
                </tr>
              </table>

              {{if .ShowLink}}
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 4px;">
                <tr>
                  <td style="background:#FFBB00;border-radius:3px;">
                    <a href="{{.StoreURL}}" target="_blank"
                       style="display:inline-block;padding:13px 26px;font-size:14px;font-weight:700;color:#111111;text-decoration:none;">
                      Về cửa hàng
                    </a>
                  </td>
                </tr>
              </table>
              {{end}}

              <p style="margin:18px 0 24px;font-size:13px;line-height:1.65;color:#8a9099;">
                Mọi thắc mắc về đơn hàng, bạn trả lời email này hoặc gọi hotline bên dưới kèm mã đơn {{.OrderCode}}.
              </p>
            </td>
          </tr>

          <tr>
            <td style="border-top:1px solid #ececec;background:#fafbfc;padding:18px 28px;">
              <p style="margin:0 0 4px;font-size:12.5px;color:#6f7780;">
                Cần hỗ trợ? Gọi <a href="tel:{{.Hotline}}" style="color:#282828;font-weight:700;text-decoration:none;">{{.Hotline}}</a>
                hoặc trả lời trực tiếp email này.
              </p>
              <p style="margin:0;font-size:11.5px;color:#9aa0a7;">
                &copy; {{.Year}} {{.StoreName}} — Áo bóng đá chính hãng, giao hàng toàn quốc.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>`

var orderStatusTpl = template.Must(template.New("orderStatus").Parse(orderStatusHTML))

// RenderOrderStatus dựng bản HTML + bản text của email báo đổi trạng thái đơn.
func RenderOrderStatus(d OrderStatusData) (html string, text string, err error) {
	var buf bytes.Buffer
	if err = orderStatusTpl.Execute(&buf, d); err != nil {
		return "", "", err
	}

	lines := []string{
		d.Headline,
		"",
		d.Detail,
		"",
		fmt.Sprintf("Đơn hàng: %s — %s", d.OrderCode, d.Label),
		"Người nhận: " + d.Name,
		"Địa chỉ: " + d.Address,
		"Tổng thanh toán: " + d.TotalText(),
	}
	if d.HasShipping() {
		line := "Vận chuyển: " + strings.TrimSpace(d.ShippingMethod)
		if t := strings.TrimSpace(d.TrackingNumber); t != "" {
			line += " — mã vận đơn " + t
		}
		lines = append(lines, line)
	}
	if strings.TrimSpace(d.Reason) != "" {
		lines = append(lines, "Lý do: "+d.Reason)
	}
	lines = append(lines, "", "Cần hỗ trợ, gọi "+d.Hotline+" kèm mã đơn "+d.OrderCode+".")
	if d.ShowLink() {
		lines = append(lines, "Cửa hàng: "+d.StoreURL)
	}

	return buf.String(), strings.Join(lines, "\n"), nil
}

// Cùng khuôn với email xác thực: bảng + style inline, thanh thương hiệu đen,
// dải vàng. Không dùng <style> hay flex/grid vì Gmail/Outlook bỏ qua.
const orderHTML = `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Xác nhận đơn hàng {{.OrderCode}}</title>
</head>
<body style="margin:0;padding:0;background:#f2f3f5;">
  <!-- dòng xem trước hiện cạnh tiêu đề trong hộp thư, ẩn khỏi nội dung -->
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Đơn {{.OrderCode}} đã được ghi nhận, tổng thanh toán {{.TotalText}}.</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f3f5;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:600px;background:#ffffff;border:1px solid #e5e5e5;border-radius:4px;overflow:hidden;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

          <!-- Thanh thương hiệu -->
          <tr>
            <td style="background:#282828;padding:20px 28px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="font-size:19px;font-weight:800;letter-spacing:1px;color:#ffffff;text-transform:uppercase;">
                    {{.StoreName}}
                  </td>
                  <td align="right" style="font-size:12px;color:#FFBB00;letter-spacing:.6px;text-transform:uppercase;font-weight:700;">
                    Xác nhận đơn hàng
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Dải vàng -->
          <tr><td style="height:4px;background:#FFBB00;line-height:4px;font-size:0;">&nbsp;</td></tr>

          <!-- Lời chào + mã đơn -->
          <tr>
            <td style="padding:30px 28px 0;">
              <p style="margin:0 0 6px;font-size:20px;font-weight:700;color:#282828;">Cảm ơn {{.Name}}!</p>
              <p style="margin:0 0 20px;font-size:14.5px;line-height:1.65;color:#5a6169;">
                {{.StoreName}} đã nhận được đơn hàng của bạn và sẽ gọi xác nhận trong giờ làm việc.
              </p>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                <tr>
                  <td align="center" style="background:#fff9e6;border:1px dashed #FFBB00;border-radius:4px;padding:16px 12px;">
                    <div style="font-size:11px;font-weight:700;letter-spacing:1.4px;color:#8a6d00;text-transform:uppercase;margin-bottom:6px;">
                      Mã đơn hàng
                    </div>
                    <div style="font-size:24px;font-weight:800;letter-spacing:2px;color:#282828;font-family:'Courier New',Courier,monospace;">
                      {{.OrderCode}}
                    </div>
                    <div style="font-size:12.5px;color:#8a6d00;margin-top:8px;">Đặt lúc {{.PlacedAt}}</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Danh sách sản phẩm -->
          <tr>
            <td style="padding:0 28px;">
              <p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:1px;color:#282828;text-transform:uppercase;">Sản phẩm</p>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #ececec;">
                {{range .Items}}
                <tr>
                  <td style="padding:12px 0;border-bottom:1px solid #f2f2f2;font-size:14px;color:#282828;line-height:1.5;">
                    <div style="font-weight:600;">{{.Name}}</div>
                    {{if .Options}}<div style="font-size:12.5px;color:#8a9099;margin-top:2px;">{{.Options}}</div>{{end}}
                    {{if .Custom}}<div style="font-size:12.5px;color:#8a9099;margin-top:2px;">In áo: {{.Custom}}</div>{{end}}
                    <div style="font-size:12.5px;color:#8a9099;margin-top:2px;">{{.PriceText}} × {{.Qty}}</div>
                  </td>
                  <td align="right" valign="top" style="padding:12px 0;border-bottom:1px solid #f2f2f2;font-size:14px;font-weight:700;color:#282828;white-space:nowrap;">
                    {{.TotalText}}
                  </td>
                </tr>
                {{end}}
              </table>

              <!-- Tổng tiền -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 24px;">
                <tr>
                  <td style="padding:4px 0;font-size:13.5px;color:#5a6169;">Tiền hàng</td>
                  <td align="right" style="padding:4px 0;font-size:13.5px;color:#282828;">{{.SubtotalText}}</td>
                </tr>
                {{if .HasDiscount}}
                <tr>
                  <td style="padding:4px 0;font-size:13.5px;color:#5a6169;">Giảm giá</td>
                  <td align="right" style="padding:4px 0;font-size:13.5px;color:#21a453;">{{.DiscountText}}</td>
                </tr>
                {{end}}
                <tr>
                  <td style="padding:4px 0;font-size:13.5px;color:#5a6169;">Phí vận chuyển</td>
                  <td align="right" style="padding:4px 0;font-size:13.5px;color:#282828;">{{.ShippingText}}</td>
                </tr>
                <tr>
                  <td style="padding:10px 0 0;border-top:1px solid #ececec;font-size:14px;font-weight:700;color:#282828;">Tổng thanh toán</td>
                  <td align="right" style="padding:10px 0 0;border-top:1px solid #ececec;font-size:18px;font-weight:800;color:#d0021b;">{{.TotalText}}</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Thông tin giao hàng -->
          <tr>
            <td style="padding:0 28px 8px;">
              <p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:1px;color:#282828;text-transform:uppercase;">Giao hàng &amp; thanh toán</p>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fafbfc;border:1px solid #ececec;border-radius:4px;">
                <tr>
                  <td style="padding:14px 16px;font-size:13.5px;line-height:1.7;color:#5a6169;">
                    <div><span style="color:#9aa0a7;">Người nhận:</span> <b style="color:#282828;">{{.Name}}</b> — {{.Phone}}</div>
                    <div><span style="color:#9aa0a7;">Địa chỉ:</span> {{.Address}}</div>
                    <div><span style="color:#9aa0a7;">Thanh toán:</span> {{.PaymentMethod}}</div>
                    {{if .Note}}<div><span style="color:#9aa0a7;">Ghi chú:</span> {{.Note}}</div>{{end}}
                  </td>
                </tr>
              </table>

              {{if .HasBankTransfer}}
              <!-- Hướng dẫn chuyển khoản: nằm ngay dưới khối thanh toán vì đây là
                   việc khách phải làm tiếp, không phải thông tin để tham khảo. -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;background:#fffdf3;border:1px solid #ffe08a;border-radius:4px;">
                <tr>
                  <td style="padding:14px 16px;font-size:13.5px;line-height:1.7;color:#5a6169;">
                    <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:1px;color:#282828;text-transform:uppercase;">Chuyển khoản</p>
                    <div><span style="color:#9aa0a7;">Ngân hàng:</span> <b style="color:#282828;">{{.BankName}}</b></div>
                    <div><span style="color:#9aa0a7;">Số tài khoản:</span> <b style="color:#282828;">{{.BankAccountNumber}}</b></div>
                    <div><span style="color:#9aa0a7;">Chủ tài khoản:</span> {{.BankAccountName}}</div>
                    <div><span style="color:#9aa0a7;">Nội dung:</span> <b style="color:#d0021b;">{{.OrderCode}}</b></div>
                    {{if .BankTransferNote}}<div style="margin-top:6px;color:#8a9099;">{{.BankTransferNote}}</div>{{end}}
                    <div style="margin-top:8px;color:#8a9099;">Ghi đúng nội dung giúp cửa hàng đối soát và xác nhận đơn nhanh hơn.</div>
                  </td>
                </tr>
              </table>
              {{end}}

              {{if .ShowLink}}
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 4px;">
                <tr>
                  <td style="background:#FFBB00;border-radius:3px;">
                    <a href="{{.StoreURL}}" target="_blank"
                       style="display:inline-block;padding:13px 26px;font-size:14px;font-weight:700;color:#111111;text-decoration:none;">
                      Tiếp tục mua sắm
                    </a>
                  </td>
                </tr>
              </table>
              {{end}}

              <p style="margin:18px 0 24px;font-size:13px;line-height:1.65;color:#8a9099;">
                Cần đổi địa chỉ, số lượng hay huỷ đơn? Trả lời email này hoặc gọi hotline trước khi đơn được giao cho đơn vị vận chuyển.
              </p>
            </td>
          </tr>

          <!-- Chân thư -->
          <tr>
            <td style="border-top:1px solid #ececec;background:#fafbfc;padding:18px 28px;">
              <p style="margin:0 0 4px;font-size:12.5px;color:#6f7780;">
                Cần hỗ trợ? Gọi <a href="tel:{{.Hotline}}" style="color:#282828;font-weight:700;text-decoration:none;">{{.Hotline}}</a>
                hoặc trả lời trực tiếp email này.
              </p>
              <p style="margin:0;font-size:11.5px;color:#9aa0a7;">
                &copy; {{.Year}} {{.StoreName}} — Áo bóng đá chính hãng, giao hàng toàn quốc.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>`

var orderTpl = template.Must(template.New("order").Parse(orderHTML))

// RenderOrderConfirmation dựng bản HTML + bản text của email xác nhận đơn hàng.
func RenderOrderConfirmation(d OrderData) (html string, text string, err error) {
	var buf bytes.Buffer
	if err = orderTpl.Execute(&buf, d); err != nil {
		return "", "", err
	}

	lines := []string{
		fmt.Sprintf("Cảm ơn %s!", d.Name),
		"",
		fmt.Sprintf("%s đã nhận được đơn hàng của bạn.", d.StoreName),
		fmt.Sprintf("Mã đơn hàng: %s (đặt lúc %s)", d.OrderCode, d.PlacedAt),
		"",
		"SẢN PHẨM",
	}
	for _, it := range d.Items {
		desc := it.Name
		if it.Options != "" {
			desc += " (" + it.Options + ")"
		}
		if it.Custom != "" {
			desc += " [in áo: " + it.Custom + "]"
		}
		lines = append(lines, fmt.Sprintf("- %s | %s x %d = %s", desc, it.PriceText(), it.Qty, it.TotalText()))
	}
	lines = append(lines, "", "Tiền hàng: "+d.SubtotalText())
	if d.HasDiscount() {
		lines = append(lines, "Giảm giá: "+d.DiscountText())
	}
	lines = append(lines,
		"Phí vận chuyển: "+d.ShippingText(),
		"Tổng thanh toán: "+d.TotalText(),
		"",
		"GIAO HÀNG & THANH TOÁN",
		fmt.Sprintf("Người nhận: %s — %s", d.Name, d.Phone),
		"Địa chỉ: "+d.Address,
		"Thanh toán: "+d.PaymentMethod,
	)
	if d.Note != "" {
		lines = append(lines, "Ghi chú: "+d.Note)
	}
	// Bản chữ thuần cũng phải có đủ số tài khoản: khách đọc thư bằng máy chặn HTML
	// mà chỉ thấy "Thanh toán: Chuyển khoản ngân hàng" thì coi như không có hướng dẫn.
	if d.HasBankTransfer() {
		lines = append(lines,
			"",
			"CHUYỂN KHOẢN",
			"Ngân hàng: "+d.BankName,
			"Số tài khoản: "+d.BankAccountNumber,
			"Chủ tài khoản: "+d.BankAccountName,
			"Nội dung: "+d.OrderCode,
		)
		if d.BankTransferNote != "" {
			lines = append(lines, d.BankTransferNote)
		}
	}
	lines = append(lines, "", "Cần đổi hoặc huỷ đơn, vui lòng trả lời email này hoặc gọi "+d.Hotline+".")
	if d.ShowLink() {
		lines = append(lines, "Cửa hàng: "+d.StoreURL)
	}

	return buf.String(), strings.Join(lines, "\n"), nil
}
