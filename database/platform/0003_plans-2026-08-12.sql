-- =====================================================================
--  0003_plans-2026-08-12.sql
--  Ngày: 12/08/2026
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
--  PLANS — BẢNG GIÁ HIỆN HÀNH CỦA TỪNG APP
--
--  0002 nói BÁN CÁI GÌ. Tệp này nói BÁN BAO NHIÊU. Hôm nay bảng giá của
--  Sellio Order chỉ tồn tại ở đúng một chỗ: ba thẻ HTML trong
--  landing_shop/index.html. Muốn khu điều hành hiện được "gói Cửa hàng, 499k/
--  tháng" thì phải gõ lại số đó lần thứ hai, và hai chỗ gõ tay sẽ lệch
--  nhau đúng vào ngày đổi giá.
--
--  ĐỌC KỸ — ĐÂY LÀ BẢNG GIÁ, KHÔNG PHẢI HỢP ĐỒNG:
--
--    · Bảng này giữ GIÁ HIỆN HÀNH, và nó ĐƯỢC PHÉP đổi. Sửa giá ở đây là
--      sửa thẳng dòng đang có (UPDATE), không cần thêm dòng lịch sử.
--    · `subscriptions.price` / `max_shops` là giá và hạn mức ĐÃ CHỐT với
--      một khách, và chúng CHÉP giá trị ra chứ KHÔNG tra ngược về đây
--      (xem 0001). Đổi bảng giá tháng sau mà hợp đồng cũ tự đổi theo là
--      cách rất nhanh để tăng giá người đang trả tiền, hoặc cắt mất chi
--      nhánh của họ.
--    · Vì vậy TUYỆT ĐỐI KHÔNG thêm `subscriptions.plan_id` trỏ sang đây.
--      Quan hệ đúng là quan hệ TRA CỨU bằng (app_id, code): thuê bao ghi
--      mã gói 'cua_hang', còn tên và giá niêm yết thì tra ở bảng này để
--      HIỂN THỊ. Khoá ngoại sẽ biến hợp đồng đã ký thành thứ đi theo bảng
--      giá hiện hành.
--    · Lịch sử giá nằm ở `subscriptions.price` — mỗi hợp đồng là một bản
--      ghi giá tại thời điểm ký. Bảng này không cần lưu lịch sử.
--
--  MÃ GÓI PHẢI KHỚP ENUM `subscriptions.plan`:
--  `code` ở đây để VARCHAR (thêm gói mới không phải sửa lược đồ), nhưng
--  bên `subscriptions` thì `plan` là ENUM('khoi_dau','cua_hang','chuoi').
--  Thêm một mã gói mới vào bảng này mà quên mở rộng ENUM kia nghĩa là bán
--  được một gói mà KHÔNG ghi nổi thuê bao cho nó — MySQL sẽ từ chối dòng
--  subscriptions. Hai việc đó phải nằm trong CÙNG một migration.
--
--  CHƯA LÀM TRONG TỆP NÀY (cố ý):
--    · Không đụng vào `subscriptions`. Việc thêm `app_id` cho nó vẫn còn
--      nợ từ 0002, và làm sớm thì rẻ vì bảng đó chưa có dòng nào.
--    · Không chép các hạn mức khác trên landing (2/10 tài khoản, 500 sản
--      phẩm). Hôm nay KHÔNG chỗ nào trong code đọc mấy con số đó, và chép
--      xuống database một hạn mức không ai ép chỉ tạo ra chỗ thứ hai để
--      sai — cùng lý do 0002 không chép ánh xạ tên miền của nginx.
--    · Không có cột thứ tự hiển thị. Ba dòng seed dưới đây được chèn theo
--      đúng thứ tự trên landing, nên `ORDER BY id` đang là thứ tự đúng.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. PLANS — mỗi dòng là MỘT MỨC GIÁ: gói này, app này, chu kỳ này
-- =====================================================================
--  Khoá duy nhất là (app_id, code, billing_cycle) chứ không phải
--  (app_id, code): một gói bán cả theo tháng lẫn theo năm là HAI dòng, hai
--  giá. Nhét giá năm vào cột thứ hai của cùng một dòng thì tới lúc có gói
--  chỉ bán theo năm sẽ có một cột bỏ trống, và mọi câu truy vấn phải biết
--  chọn cột nào. Ở đây chu kỳ là dữ liệu, không phải tên cột.
--
--  `price` CHO PHÉP NULL, nghĩa là "Liên hệ" — gói Chuỗi trên landing
--  không niêm yết giá, mỗi khách một báo giá riêng. NULL là "chưa có giá
--  công khai", KHÁC hẳn 0 (miễn phí). Bên `subscriptions.price` thì NOT
--  NULL: ký hợp đồng là phải có con số cụ thể, không ai ký "liên hệ".
--
--  `max_shops` cũng NULL được, cùng lý do: gói Chuỗi là "từ hai cửa hàng
--  trở lên", số cụ thể chốt lúc ký. Cột này có mặt vì nó chính là thứ
--  người điền hợp đồng phải chép sang `subscriptions.max_shops` — không
--  có nó thì hạn mức chi nhánh là số nhớ trong đầu, mà mặc định bên kia
--  là 1, tức là bán gói chuỗi xong khách vẫn chỉ mở được một chi nhánh.
--
--  `status` mặc định 'active' — khác `apps` (mặc định 'planned'). Ở đó
--  dòng mới là một phần mềm CHƯA TỒN TẠI nên không được bán; ở đây dòng
--  mới là một mức giá vừa quyết, thêm vào là để bán.
--  Ngừng bán một gói thì đặt 'retired', ĐỪNG XOÁ dòng: thuê bao cũ vẫn ghi
--  mã gói đó và khu điều hành vẫn phải tra ra được tên để hiển thị.
CREATE TABLE IF NOT EXISTS plans (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_id        BIGINT UNSIGNED NOT NULL COMMENT 'bảng giá của app nào — 0002.apps',
  code          VARCHAR(30)  NOT NULL COMMENT 'mã gói, PHẢI nằm trong ENUM subscriptions.plan: khoi_dau|cua_hang|chuoi',
  name          VARCHAR(100) NOT NULL COMMENT 'tên hiển thị cho khách: Khởi đầu, Cửa hàng, Chuỗi',
  tagline       VARCHAR(255) NULL COMMENT 'một dòng "gói này dành cho ai", lấy đúng câu trên landing',
  billing_cycle ENUM('thang','nam') NOT NULL DEFAULT 'thang'
                COMMENT 'cùng bộ giá trị với subscriptions.billing_cycle',
  price         DECIMAL(12,2) NULL COMMENT 'VND cho MỘT chu kỳ. NULL = "Liên hệ", KHÁC 0 (miễn phí)',
  max_shops     SMALLINT UNSIGNED NULL COMMENT 'số chi nhánh của gói; NULL = thoả thuận riêng lúc ký',
  trial_days    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'số ngày dùng thử; 0 = không có',
  status        ENUM('active','retired') NOT NULL DEFAULT 'active'
                COMMENT 'retired = ngừng bán mới; KHÔNG xoá dòng vì thuê bao cũ còn tra tên gói ở đây',
  created_at    DATETIME(3) NULL,
  updated_at    DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_app_code_cycle (app_id, code, billing_cycle),
  KEY idx_plans_app (app_id),
  CONSTRAINT fk_plans_app FOREIGN KEY (app_id) REFERENCES apps (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control plane — bảng giá hiện hành theo app. Hợp đồng đã ký CHÉP giá ra subscriptions, không tra về đây.';

-- =====================================================================
--  2. BẢNG GIÁ ĐANG CHẠY CỦA APP 'order'
-- =====================================================================
--  Ba dòng dưới đây là bản chép ĐÚNG của bảng giá đang công khai ở
--  landing_shop/index.html (mục #bang-gia) ngày 12/08/2026:
--
--      Khởi đầu   199.000đ/tháng   1 cửa hàng, 2 tài khoản
--      Cửa hàng   499.000đ/tháng   1 cửa hàng, 10 tài khoản
--      Chuỗi      Liên hệ          từ hai cửa hàng trở lên
--
--  ĐỔI GIÁ THÌ PHẢI ĐỔI CẢ HAI CHỖ — dòng ở đây và thẻ HTML trên landing.
--  Chừng nào landing còn là trang tĩnh gõ tay thì chưa có cách nào khác;
--  ngày nó đọc giá qua API thì bảng này thành nguồn duy nhất.
--
--  Chỉ có chu kỳ 'thang': landing ghi "Trả theo tháng", chưa bán theo năm.
--  Mở bán theo năm là THÊM DÒNG (cùng code, billing_cycle='nam'), không
--  phải sửa dòng này.
--
--  Tra app_id bằng SELECT theo `code` chứ không gõ số 1: id là số tự sinh
--  của từng database, máy thật có thể đánh số khác máy này.
--
--  ON DUPLICATE KEY UPDATE code = code: chạy lại không sinh dòng thứ hai
--  và KHÔNG ghi đè giá — giá sau này sửa trong khu điều hành phải được giữ
--  nguyên, tệp migration không có quyền kéo nó về giá của hôm nay.
INSERT INTO plans (app_id, code, name, tagline, billing_cycle, price, max_shops, trial_days, status, created_at, updated_at)
SELECT a.id, t.code, t.name, t.tagline, 'thang', t.price, t.max_shops, 14, 'active', NOW(3), NOW(3)
FROM apps a
JOIN (
  SELECT 'khoi_dau' AS code, 'Khởi đầu' AS name, 'Shop một mình chủ chạy'    AS tagline, 199000.00 AS price, 1    AS max_shops
  UNION ALL
  SELECT 'cua_hang',         'Cửa hàng',        'Có nhân viên, có kho riêng',            499000.00,          1
  UNION ALL
  -- Giá NULL = "Liên hệ", max_shops NULL = số chi nhánh chốt lúc ký.
  SELECT 'chuoi',            'Chuỗi',           'Từ hai cửa hàng trở lên',               NULL,               NULL
) t
WHERE a.code = 'order'
ON DUPLICATE KEY UPDATE plans.code = plans.code;

-- =====================================================================
--  KẾT THÚC — 1 bảng, 3 dòng, 0 khoá ngoại bắc sang selliotech.
-- =====================================================================
