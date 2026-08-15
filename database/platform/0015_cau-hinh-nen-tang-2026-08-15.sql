-- =====================================================================
--  0015_cau-hinh-nen-tang-2026-08-15.sql
--  Ngày: 15/08/2026
--  LƯỢC ĐỒ: selliotech_platform (KHÔNG phải selliotech)
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  MySQL không cho DDL nằm trong transaction, nên tệp chạy dở là dở thật.
--  Viết sao cho chạy lại được nếu có thể: IF NOT EXISTS, IF EXISTS...
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  PLATFORM_SETTINGS — CẤU HÌNH CỦA NHÀ CUNG CẤP PHẦN MỀM
--
--  Khoá đầu tiên nó phục vụ: KHÁCH TRẢ TIỀN GIA HẠN VÀO ĐÂU. Tới hôm nay
--  thông tin đó chỉ nằm trong đầu người bán — trang "Các gói dịch vụ" bên
--  Shop Admin mời khách gửi email hỏi, rồi người bán nhắn lại số tài
--  khoản bằng tay, mỗi lần một kiểu. Khách tự gia hạn được thì số tài
--  khoản phải là DỮ LIỆU, không phải một câu nhắn.
--
--  VÌ SAO KHÔNG NHÉT VÀO .env như khoá PayOS/SePay của cửa hàng:
--
--    · Mấy ô này là thứ NGƯỜI BÁN tự sửa — đổi ngân hàng, đổi số tài
--      khoản, đổi mẫu nội dung chuyển khoản. Nằm ở .env thì mỗi lần đổi
--      là một lần sửa tệp trên máy chủ rồi khởi động lại API, tức là phải
--      gọi người biết kỹ thuật.
--    · Nó cần một MÀN HÌNH có kiểm tra dữ liệu: bật hình thức chuyển khoản
--      mà bỏ trống số tài khoản là khách quét QR rồi chuyển vào hư không.
--
--  VÌ SAO KHOÁ · GIÁ TRỊ chứ không phải mỗi cấu hình một cột: cùng lý do
--  với `settings` của cửa hàng và `plan_features` của bảng giá — thêm một
--  ô cấu hình mới là THÊM DÒNG, không phải chạy migration. Cái giá phải
--  trả là database không giữ hộ kiểu dữ liệu nữa, nên chỗ canh nằm ở
--  registry trong service (api/internal/service/cau_hinh_nen_tang_service.go):
--  khoá không có trong registry thì API từ chối ghi.
--
--  BẢNG NÀY KHÔNG CÓ tenant_id, và đó là điểm khác biệt với `settings`
--  bên data plane: đây là cấu hình của NHÀ CUNG CẤP, một bộ duy nhất cho
--  cả nền tảng. Nhầm hai bảng này với nhau nghĩa là đem số tài khoản của
--  mình đi ghi đè cấu hình của một cửa hàng khách.
--
--  KHÔNG CẤT BÍ MẬT Ở ĐÂY. Số tài khoản ngân hàng là thông tin công khai
--  (in trên hoá đơn, dán ở quầy), nhưng khoá API của cổng thanh toán thì
--  không: chúng ở lại .env cho tới ngày có chỗ mã hoá đàng hoàng. Thêm
--  một dòng `payos_api_key` vào bảng này là cất chìa khoá két trong một
--  bảng mà mọi lượt sao lưu database đều chép ra nguyên văn.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. PLATFORM_SETTINGS — bảng khoá · giá trị của nền tảng
-- =====================================================================
--  `setting_key` là khoá chính chứ không phải một cột UNIQUE kèm id tự
--  tăng: một khoá cấu hình chỉ có đúng một giá trị, và id ở đây không
--  dùng để làm gì — không có bảng nào trỏ tới một dòng cấu hình.
--
--  `value` là TEXT và cho phép rỗng: chuỗi rỗng mang nghĩa "chưa khai",
--  khác hẳn việc không có dòng (khoá đó không tồn tại trong registry).
--  Registry bên service mới là nơi nói khoá nào có thật và giá trị mặc
--  định của nó là gì.
CREATE TABLE IF NOT EXISTS platform_settings (
  setting_key VARCHAR(100) NOT NULL COMMENT 'mã khoá — phải có trong registry của service',
  value       TEXT         NOT NULL COMMENT 'giá trị dạng chuỗi; rỗng = chưa khai',
  updated_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cấu hình của nhà cung cấp phần mềm (control plane)';

-- =====================================================================
--  2. GIÁ TRỊ BAN ĐẦU — hình thức chuyển khoản
-- =====================================================================
--  CHỈ chèn khoá bật/tắt, và chèn với giá trị TẮT.
--
--  Không chèn sẵn dòng rỗng cho số tài khoản, tên ngân hàng…: "không có
--  dòng" và "có dòng rỗng" đọc ra cùng một thứ trên màn hình, nên thêm
--  chúng chỉ tạo việc cho lượt đọc mà không nói thêm được gì.
--
--  Mặc định TẮT vì bật lên mà chưa ai điền số tài khoản thì trang gia hạn
--  của khách hiện một khối chuyển khoản trống — tệ hơn hẳn là chưa hiện
--  gì. Người bán bật nó sau khi khai xong, ngay trên màn hình cài đặt.
INSERT INTO platform_settings (setting_key, value)
VALUES ('ck_bat', '0')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
