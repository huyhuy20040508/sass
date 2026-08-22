-- =====================================================================
--  0005_tinh-nang-goi-2026-08-12.sql
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
--  PLAN_FEATURES — GÓI NÀY CHO PHÉP NHỮNG GÌ, DẠNG KHOÁ · GIÁ TRỊ
--
--  0003 dựng bảng giá và cố ý CHƯA chép các hạn mức trên landing (2/10 tài
--  khoản, 500 sản phẩm), với đúng một lý do: "hôm nay KHÔNG chỗ nào trong
--  code đọc mấy con số đó, chép xuống database một hạn mức không ai ép chỉ
--  tạo ra chỗ thứ hai để sai". Lý do đó hết hiệu lực trong đợt này: khu
--  điều hành có màn hình Tính năng gói, và API /platform/plans đọc thẳng
--  bảng dưới đây. Từ giờ mấy con số ấy có người đọc.
--
--  VÌ SAO KHOÁ · GIÁ TRỊ CHỨ KHÔNG PHẢI THÊM CỘT: mỗi hạn mức mới là một
--  cột mới, tức một migration, một lần dựng lại và triển khai API, chỉ để
--  trả lời một câu hỏi bán hàng kiểu "gói Cửa hàng cho mấy tài khoản".
--  Hạn mức là ĐIỀU KHOẢN BÁN HÀNG — thứ đổi theo tháng, do người bán hàng
--  quyết — nên nó phải là DỮ LIỆU. Cùng lý do 0004 đưa own_domain vào bảng
--  giá thay vì viết `if plan == "chuoi"` trong Go, chỉ là đi thêm một bước.
--
--  CÁI GIÁ PHẢI TRẢ, nói trước: `value` là VARCHAR nên database KHÔNG còn
--  giữ hộ kiểu dữ liệu nữa. `max_shops` từng là SMALLINT UNSIGNED, gõ chữ
--  vào là MySQL từ chối; giờ gõ gì cũng lọt. Chỗ canh thay vào đó là
--  REGISTRY trong service/plan_service.go: khoá nào tồn tại, kiểu gì, trần
--  bao nhiêu. Khoá không có trong registry thì API từ chối ghi — nhưng
--  người mở database gõ tay UPDATE thì không ai chặn được. Đổi lại được
--  điều vừa nói ở trên; đây là lựa chọn có ý thức, không phải quên.
--
--  BA TRẠNG THÁI CỦA MỘT HẠN MỨC, và đừng trộn chúng làm một:
--
--    · CÓ DÒNG, giá trị là số   — bảng giá chốt đúng con số đó.
--    · CÓ DÒNG, giá trị 'vo_han' — bán không giới hạn, đúng câu "Không
--      giới hạn sản phẩm" của gói Cửa hàng trên landing.
--    · KHÔNG CÓ DÒNG            — bảng giá KHÔNG nói gì. Đây là chỗ của
--      gói Chuỗi: "từ hai cửa hàng trở lên", số chi nhánh chốt lúc ký. Nó
--      thay cho `max_shops IS NULL` của 0003 và mang đúng nghĩa cũ.
--
--  'vo_han' KHÁC hẳn không có dòng, và cũng khác 0. Nhét cả ba vào một ô
--  trống là ngày mai không ai phân biệt nổi "bán không giới hạn" với "chưa
--  ai điền".
--
--  TỆP NÀY BỎ HAI CỘT CỦA BẢNG plans. Đó là phần đáng đọc kỹ nhất:
--  `max_shops` (0003) và `own_domain` (0004) là hạn mức và tính năng của
--  gói — đúng thứ bảng này sinh ra để giữ. Để lại thì có HAI chỗ trả lời
--  cùng một câu hỏi, và cái sai kiểu đó chỉ lộ ra khi hai chỗ lệch nhau,
--  thường là vào ngày đổi chính sách giá. Chép sang rồi bỏ cột là việc rẻ
--  đúng hôm nay: bảng giá có 3 dòng, `subscriptions` vẫn rỗng.
--
--  HỆ QUẢ PHẢI TRIỂN KHAI CÙNG LÚC: `cmd/ten-mien` đọc `plans.own_domain`.
--  Chạy tệp này mà chưa cập nhật bản dựng đó thì mọi lượt `ten-mien them`
--  gãy với "Unknown column 'p.own_domain'". Migration và mã nguồn của đợt
--  này đi thành CẶP.
--
--  KHÔNG ĐỤNG `subscriptions.max_shops`. Nó là hạn mức ĐÃ CHỐT với một
--  khách, chép ra lúc ký và sống độc lập (xem 0001/0003). Bảng dưới đây là
--  BẢNG GIÁ HIỆN HÀNH — đổi ở đây không được phép đổi hợp đồng đã ký.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. PLAN_FEATURES — mỗi dòng là MỘT điều khoản của MỘT dòng bảng giá
-- =====================================================================
--  Khoá gắn vào `plan_id` chứ không vào (app, mã gói): một gói bán theo
--  tháng và theo năm là HAI dòng plans (0003), và hai dòng đó ĐƯỢC PHÉP
--  khác hạn mức — bán năm kèm thêm tài khoản là chiêu bán hàng bình
--  thường. Gắn vào mã gói là ép hai chu kỳ dùng chung một bộ hạn mức.
--
--  `feature_key` chứ không phải `key`: `key` là từ khoá của MySQL, để tên
--  đó thì mọi câu truy vấn phải bọc backtick và sẽ có người quên.
--
--  ON DELETE CASCADE: dòng bảng giá KHÔNG được xoá (ngừng bán thì đặt
--  status='retired' — xem 0003), nên nhánh này gần như không chạy. Nó có
--  mặt cho ngày ai đó xoá thật: hạn mức của một gói không còn tồn tại là
--  rác không ai đọc, và rác đó sẽ dính vào dòng bảng giá mới nếu id được
--  cấp lại.
CREATE TABLE IF NOT EXISTS plan_features (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id     BIGINT UNSIGNED NOT NULL COMMENT 'một dòng của bảng giá — 0003.plans (gói + app + chu kỳ)',
  feature_key VARCHAR(40)  NOT NULL COMMENT 'mã khoá, phải có trong registry của service/plan_service.go: max_shops, max_users, max_products, own_domain',
  value       VARCHAR(100) NOT NULL COMMENT 'số, hoặc 1/0 với khoá bật-tắt, hoặc vo_han. KHÔNG có dòng = bảng giá không quy định',
  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plan_features_plan_key (plan_id, feature_key),
  KEY idx_plan_features_plan (plan_id),
  CONSTRAINT fk_plan_features_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control plane — hạn mức và tính năng của từng dòng bảng giá. Hợp đồng đã ký KHÔNG tra về đây.';

-- =====================================================================
--  2. CHÉP HAI CỘT CŨ SANG, TRƯỚC KHI BỎ CHÚNG
-- =====================================================================
--  Chép trước, bỏ sau — thứ tự này là thứ cứu dữ liệu nếu tệp chết giữa
--  chừng: MySQL không quay lui DDL được, nên tệp dừng ở giữa phần 2 chỉ để
--  lại dữ liệu chép dở (chạy lại là xong), còn dừng sau khi đã DROP thì
--  con số kia không còn ở đâu nữa.
--
--  Đọc từ chính cột đang có chứ KHÔNG gõ lại số của landing: từ 0003 tới
--  giờ giá và hạn mức có thể đã được sửa tay trong khu điều hành, và
--  migration không có quyền kéo chúng về giá trị của ngày viết tệp.

-- 2a. max_shops — chỉ những dòng có số. NULL nghĩa là "chốt lúc ký", và
--     cách viết điều đó ở bảng mới là KHÔNG CÓ DÒNG.
INSERT INTO plan_features (plan_id, feature_key, value, created_at, updated_at)
SELECT p.id, 'max_shops', CAST(p.max_shops AS CHAR), NOW(3), NOW(3)
  FROM plans p
 WHERE p.max_shops IS NOT NULL
ON DUPLICATE KEY UPDATE plan_features.feature_key = plan_features.feature_key;

-- 2b. own_domain — chỉ những dòng ĐANG BẬT. Tắt là mặc định của khoá này
--     (xem 0004: "gói mới thêm vào bảng giá KHÔNG tự nhiên kèm tên miền"),
--     nên chép cả dòng '0' xuống chỉ là ghi lại chính cái mặc định.
INSERT INTO plan_features (plan_id, feature_key, value, created_at, updated_at)
SELECT p.id, 'own_domain', '1', NOW(3), NOW(3)
  FROM plans p
 WHERE p.own_domain = 1
ON DUPLICATE KEY UPDATE plan_features.feature_key = plan_features.feature_key;

-- =====================================================================
--  3. HAI HẠN MỨC LANDING ĐÃ HỨA MÀ DATABASE CHƯA BAO GIỜ GHI
-- =====================================================================
--  Bản chép đúng của bảng giá đang công khai ở landing_shop/index.html
--  (mục #bang-gia) ngày 12/08/2026:
--
--      Khởi đầu   1 cửa hàng, 2 tài khoản    · 500 sản phẩm
--      Cửa hàng   1 cửa hàng, 10 tài khoản   · Không giới hạn sản phẩm
--      Chuỗi      từ hai cửa hàng trở lên    · (không nói số tài khoản)
--
--  Gói Chuỗi KHÔNG có dòng nào ở phần này, và đó là bản dịch trung thực
--  của landing: trang bán hàng không hứa con số nào cho gói đó. Điền đại
--  một số vào đây là bịa ra điều khoản chưa ai bán.
--
--  ĐỔI HẠN MỨC THÌ PHẢI ĐỔI CẢ HAI CHỖ — dòng ở đây và thẻ HTML trên
--  landing. Chừng nào landing còn là trang tĩnh gõ tay thì chưa có cách
--  nào khác; ngày nó đọc qua API thì bảng này thành nguồn duy nhất.
--
--  Tra app_id qua `apps.code` chứ không gõ số 1: id là số tự sinh của từng
--  database, máy thật có thể đánh số khác máy này.
--
--  ON DUPLICATE KEY UPDATE ... = chính nó: chạy lại không sinh dòng thứ
--  hai và KHÔNG ghi đè giá trị người ta đã sửa trong khu điều hành.
INSERT INTO plan_features (plan_id, feature_key, value, created_at, updated_at)
SELECT p.id, t.feature_key, t.value, NOW(3), NOW(3)
  FROM plans p
  JOIN apps a ON a.id = p.app_id
  JOIN (
    SELECT 'khoi_dau' AS code, 'max_users'    AS feature_key, '2'      AS value
    UNION ALL
    SELECT 'khoi_dau',         'max_products',                '500'
    UNION ALL
    SELECT 'cua_hang',         'max_users',                   '10'
    UNION ALL
    -- "Không giới hạn sản phẩm" trên landing. KHÔNG phải 0, cũng không
    -- phải bỏ trống: cả hai đều đọc thành chuyện khác.
    SELECT 'cua_hang',         'max_products',                'vo_han'
  ) t ON t.code = p.code
 WHERE a.code = 'order'
ON DUPLICATE KEY UPDATE plan_features.feature_key = plan_features.feature_key;

-- =====================================================================
--  4. BỎ HAI CỘT CŨ CỦA plans
-- =====================================================================
--  Tới đây giá trị của chúng đã nằm nguyên trong plan_features (phần 2).
--
--  KHÔNG dùng IF EXISTS: MariaDB (máy cục bộ) cho phép, MySQL 8 (máy thật)
--  thì không — câu có IF EXISTS chạy được ở nhà mà gãy trên máy thật, kiểu
--  lệch tệ nhất. Cùng lý do 0004 không dùng IF NOT EXISTS lúc thêm cột.
--
--  Bỏ trong MỘT câu ALTER chứ không hai: MySQL dựng lại bảng cho mỗi lần
--  ALTER, và quan trọng hơn — hai câu thì tệp có thể chết ở giữa và để lại
--  một bảng nửa cũ nửa mới.
ALTER TABLE plans
  DROP COLUMN max_shops,
  DROP COLUMN own_domain;

-- =====================================================================
--  KẾT THÚC — 1 bảng mới, 2 cột bị bỏ, 0 khoá ngoại bắc sang selliotech.
-- =====================================================================
