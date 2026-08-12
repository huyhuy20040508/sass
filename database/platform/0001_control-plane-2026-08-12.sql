-- =====================================================================
--  0001_control-plane-2026-08-12.sql
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
--  CONTROL PLANE — SỔ CÁI CỦA NỀN TẢNG
--
--  Thư mục này là lược đồ THỨ HAI của hệ thống, chạy bằng:
--      cd api && go run ./cmd/migrate -nen-tang chay
--  Thư mục ../migrations là lược đồ dữ liệu bán hàng (data plane), chạy
--  bằng chính lệnh đó nhưng KHÔNG có cờ -nen-tang. Hai bộ tệp, hai bảng
--  schema_migrations, hai database.
--
--  VÌ SAO TÁCH HẲN DATABASE chứ không thêm 4 bảng vào selliotech:
--
--    · Hai vòng đời khác nhau. Dữ liệu bán hàng của khách có thể phải
--      tách ra máy chủ riêng, khôi phục riêng, hoặc xoá đi khi khách nghỉ.
--      Sổ cái nền tảng (ai đã mua gì, còn hạn tới bao giờ) thì không bao
--      giờ đi theo một khách nào cả.
--    · Ranh giới quyền. Tài khoản MySQL của khu bán hàng không cần — và
--      không nên — đọc nổi bảng tiền nong của toàn bộ khách hàng khác.
--    · Đường đi tiếp. Khi một khách lớn cần database riêng, data plane
--      nhân bản ra nhiều database, còn control plane vẫn đúng MỘT. Nhét
--      chung từ đầu thì lúc đó phải gỡ ra giữa lúc đang có người dùng.
--
--  KHÔNG CÓ KHOÁ NGOẠI NÀO BẮC QUA HAI LƯỢC ĐỒ.
--  MySQL cho phép FK trỏ sang database khác trên cùng máy chủ, nhưng ở đây
--  thì TUYỆT ĐỐI không dùng:
--    · nó biến "hai database" thành một khối dính liền — không tách máy
--      chủ được, không khôi phục riêng được, mysqldump từng cái nạp lại
--      không lên vì thiếu bảng cha;
--    · và nó khoá chết thứ tự sao lưu/phục hồi của cả hai bên.
--  Ràng buộc BÊN TRONG selliotech_platform thì vẫn dùng FK bình thường
--  (subscriptions/tenant_domains -> tenants): cùng một database, cùng một
--  bản dump, không mất gì cả.
--
--  ID CỦA TENANT LÀ SỐ CHUNG GIỮA HAI LƯỢC ĐỒ, và hôm nay nó do DATA PLANE
--  cấp. Vì vậy `tenants.id` ở đây CỐ Ý KHÔNG AUTO_INCREMENT: mỗi dòng phải
--  ghi thẳng đúng id đã có bên selliotech.tenants. Nếu để nó tự sinh thì
--  hai bên đếm độc lập, và tới ngày ghép sổ thì tenant 7 bên này là tenant
--  9 bên kia — không có cách nào lần ngược lại được.
--  Khi việc tạo cửa hàng chuyển hẳn về khu điều hành (control plane cấp id,
--  data plane nhận id), viết migration mới bật AUTO_INCREMENT lên.
--
--  HAI BẢNG `tenants` LÀ CỐ Ý, KHÔNG PHẢI TRÙNG LẶP DO SƠ SUẤT:
--    · selliotech.tenants        — bảng CHA của mọi khoá ngoại tenant_id
--                                  trong dữ liệu bán hàng, và là thứ API
--                                  đọc lúc đăng nhập 3 ô. Bỏ nó đi thì
--                                  37 bảng mất khoá ngoại.
--    · selliotech_platform.tenants — sổ đăng ký của nền tảng: khách này là
--                                  ai, đang ở trạng thái nào, gắn với gói
--                                  nào, tên miền nào.
--  Cột `status` có ở cả hai và PHẢI được ghi cả hai lượt: control plane là
--  nơi RA QUYẾT ĐỊNH (hết hạn, ngừng trả tiền), còn data plane là nơi API
--  thực sự đọc lúc chặn đăng nhập. Đổi một bên mà quên bên kia thì khách đã
--  ngừng trả tiền vẫn vào bán hàng bình thường.
--
--  CHƯA LÀM TRONG TỆP NÀY (cố ý, để đúng sự thật):
--    · Không nạp sẵn dòng nào. Cửa hàng đang chạy chưa có mặt trong sổ —
--      chúng được tạo bằng cmd/tao-admin ở data plane, mà lệnh đó chưa biết
--      tới control plane. Đăng ký các cửa hàng cũ và nối provisioning vào
--      đây là việc của đợt sau; tới lúc đó mới có dữ liệu thật ở 4 bảng này.
--    · Chưa có bảng invoices/payments của nền tảng: thu tiền khách thuê bao
--      là việc khác với ghi sổ họ đang dùng gói nào.
--    · Chưa có shop_users (nhân viên thuộc chi nhánh nào) — bảng đó thuộc
--      data plane chứ không phải ở đây.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. TENANTS — sổ đăng ký khách hàng của nền tảng
-- =====================================================================
--  `code` lặp lại selliotech.tenants.code (ô 1 của màn hình đăng nhập).
--  Giữ đúng VARCHAR(30) và UNIQUE như bên kia: hai bên lệch kiểu thì sẽ có
--  mã hợp lệ ở sổ này mà data plane không nhận.
--
--  ENUM status giữ ĐÚNG hai giá trị như data plane ('active','suspended').
--  Thêm giá trị thứ ba ở đây (kiểu 'closed') là tự tạo ra trạng thái không
--  chép sang bên kia được, và lúc đó không ai biết API sẽ xử ra sao.
--  Khách nghỉ hẳn thì vẫn là 'suspended' + ghi rõ lý do vào `note`: dữ liệu
--  bán hàng của người ta giữ nguyên, đóng tiền lại là mở.
CREATE TABLE IF NOT EXISTS tenants (
  id         BIGINT UNSIGNED NOT NULL
             COMMENT 'CÙNG MỘT SỐ với selliotech.tenants.id — ghi tay, KHÔNG tự sinh (xem đầu tệp)',
  code       VARCHAR(30)  NOT NULL COMMENT 'mã cửa hàng khách gõ lúc đăng nhập, phải khớp selliotech.tenants.code',
  name       VARCHAR(150) NOT NULL COMMENT 'tên khách hàng, để mình đọc trong khu điều hành',
  status     ENUM('active','suspended') NOT NULL DEFAULT 'active'
             COMMENT 'quyết định ở đây, nhưng PHẢI ghi sang selliotech.tenants.status thì API mới chặn được đăng nhập',
  contact_name  VARCHAR(150) NULL COMMENT 'người mình liên lạc khi cần đòi tiền hoặc báo bảo trì',
  contact_email VARCHAR(150) NULL,
  contact_phone VARCHAR(20)  NULL,
  note       VARCHAR(500) NULL COMMENT 'ghi chú nội bộ: lý do khoá, thoả thuận riêng về giá...',
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenants_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control plane — sổ đăng ký khách hàng. KHÔNG có FK sang database selliotech.';

-- =====================================================================
--  2. SUBSCRIPTIONS — khách đang dùng gói nào, tới bao giờ
-- =====================================================================
--  Ba gói khớp đúng cách chia của sản phẩm:
--    khoi_dau  — một cửa hàng, dùng thử / quy mô nhỏ
--    cua_hang  — một cửa hàng, đầy đủ chức năng
--    chuoi     — nhiều chi nhánh (max_shops > 1), dùng chung một bộ sản phẩm
--
--  `max_shops` để THẲNG Ở ĐÂY chứ không tra qua một bảng "định nghĩa gói":
--  hạn mức là thứ đã CHỐT VỚI KHÁCH lúc ký, không phải thứ đọc lại theo bảng
--  giá hiện hành. Sửa bảng giá tháng sau mà hạn mức của hợp đồng cũ tự đổi
--  theo là một cách rất nhanh để cắt mất chi nhánh của khách đang trả tiền.
--  Cùng lý do với `price`: giá chốt riêng cho khách này, chu kỳ này.
--
--  MỘT TENANT CHỈ CÓ MỘT THUÊ BAO ĐANG HIỆU LỰC — ép bằng khoá, không bằng
--  lời dặn. `current_mark` là 1 với mọi trạng thái còn hiệu lực và NULL khi
--  đã huỷ; MySQL cho nhiều dòng NULL trong UNIQUE nên lịch sử các gói cũ
--  nằm lại thoải mái, còn dòng đang chạy thì chỉ được đúng một.
--  Gia hạn = ĐẨY `ends_at` của chính dòng này. Đổi gói = huỷ dòng cũ
--  (status='canceled', ghi canceled_at) rồi thêm dòng mới.
CREATE TABLE IF NOT EXISTS subscriptions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  plan          ENUM('khoi_dau','cua_hang','chuoi') NOT NULL,
  status        ENUM('trial','active','past_due','canceled') NOT NULL DEFAULT 'trial'
                COMMENT 'trial=đang dùng thử, active=đã trả tiền, past_due=quá hạn chưa trả, canceled=đã dừng',
  billing_cycle ENUM('thang','nam') NOT NULL DEFAULT 'thang',
  price         DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'giá đã chốt cho MỘT chu kỳ, VND',
  max_shops     SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'số chi nhánh tối đa; gói chuỗi > 1',
  started_at    DATETIME(3) NOT NULL COMMENT 'bắt đầu tính từ lúc nào',
  ends_at       DATETIME(3) NOT NULL COMMENT 'hết chu kỳ hiện tại; quá ngày này mà chưa gia hạn thì chuyển past_due',
  trial_ends_at DATETIME(3) NULL COMMENT 'NULL = không có giai đoạn dùng thử',
  canceled_at   DATETIME(3) NULL,
  note          VARCHAR(500) NULL,
  created_at    DATETIME(3) NULL,
  updated_at    DATETIME(3) NULL,
  -- Cột phụ cho unique key: NULL khi đã huỷ, 1 khi còn hiệu lực. Cùng thủ
  -- thuật với deleted_mark bên data plane — MySQL coi mỗi NULL là một giá
  -- trị khác nhau nên các dòng lịch sử không đụng nhau.
  current_mark  TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'canceled', NULL, 1)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriptions_current (tenant_id, current_mark),
  KEY idx_subscriptions_tenant (tenant_id),
  -- Câu hỏi chạy hằng ngày: "gói nào sắp hết hạn / đã quá hạn mà chưa xử lý".
  KEY idx_subscriptions_han (status, ends_at),
  CONSTRAINT fk_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control plane — thuê bao của từng khách. FK chỉ trong nội bộ lược đồ này.';

-- =====================================================================
--  3. PLATFORM_USERS — tài khoản của KHU ĐIỀU HÀNH, không phải của cửa hàng
-- =====================================================================
--  Đây là mình và người làm cùng: người đăng nhập vào khu điều hành nền tảng
--  để mở/khoá cửa hàng, xem thuê bao. KHÔNG phải nhân viên bán hàng của
--  khách (những người đó ở selliotech.users, đăng nhập bằng 3 ô).
--
--  VÌ SAO KHÔNG DÙNG CHUNG selliotech.users VỚI VAI TRÒ super_admin — cách
--  hiện tại đang làm: tài khoản điều hành nằm chung bảng với tài khoản của
--  MỘT cửa hàng nghĩa là nó mang tenant_id của cửa hàng đó, và khi plugin
--  GORM bắt đầu chèn `WHERE tenant_id = ?` vào mọi truy vấn thì chính người
--  điều hành trở thành người bị nhốt trong tenant 1. Tách bảng ra là cách
--  duy nhất để "người của nền tảng" không phải là "người của một khách".
--
--  Đăng nhập bằng EMAIL (không phải 3 ô): khu điều hành không thuộc cửa hàng
--  nào nên không có ô mã cửa hàng để gõ.
--
--  ĐỌC KỸ: bảng này mới có lược đồ. API chưa xác thực qua nó — khu điều hành
--  vẫn đang đăng nhập bằng /auth/login trên selliotech.users. Chuyển đường
--  đăng nhập là việc của đợt sau, và phải làm cùng lúc với việc dựng tài
--  khoản đầu tiên ở đây, nếu không là tự khoá mình ra ngoài.
CREATE TABLE IF NOT EXISTS platform_users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(150) NOT NULL,
  full_name     VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL COMMENT 'bcrypt, cùng thuật toán với selliotech.users',
  role          ENUM('owner','operator','support') NOT NULL DEFAULT 'operator'
                COMMENT 'owner=chủ nền tảng (toàn quyền), operator=điều hành, support=hỗ trợ, chỉ đọc',
  status        ENUM('active','locked') NOT NULL DEFAULT 'active',
  last_login_at DATETIME(3) NULL,
  created_at    DATETIME(3) NULL,
  updated_at    DATETIME(3) NULL,
  deleted_at    DATETIME(3) NULL,
  -- Cùng thủ thuật deleted_mark của data plane: email chỉ duy nhất giữa các
  -- dòng ĐANG SỐNG, người nghỉ việc rồi quay lại vẫn dùng lại được email cũ.
  deleted_mark  DATETIME(3) GENERATED ALWAYS AS (IFNULL(deleted_at, '1970-01-01 00:00:00.000')) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_platform_users_email (email, deleted_mark),
  KEY idx_platform_users_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control plane — tài khoản khu điều hành nền tảng, KHÔNG phải người dùng của cửa hàng.';

-- =====================================================================
--  4. TENANT_DOMAINS — tên miền nào thuộc cửa hàng nào
-- =====================================================================
--  Hôm nay cả nền tảng chạy trên vài tên miền cố định (order.selliotech.store
--  cho Shop Admin, admin.* cho khu điều hành) và mã cửa hàng được GÕ TAY vào
--  ô 1 lúc đăng nhập. Bảng này là đường đi tiếp: mỗi khách một tên miền
--  riêng, vào tên miền nào thì biết ngay là cửa hàng nào, không phải gõ mã.
--
--  `host` lưu CHỮ THƯỜNG, không kèm scheme, không kèm dấu / và không kèm
--  cổng — chuẩn hoá trước khi ghi, vì cái vào đây phải so khớp thẳng được
--  với header Host của request.
--
--  UNIQUE TOÀN BẢNG cho host: một tên miền không thể trỏ vào hai cửa hàng.
--  Đây chính là chỗ chặn một khách khai tên miền của khách khác.
--
--  `verified_at` là điều kiện để cấp chứng chỉ HTTPS: chưa xác minh DNS đã
--  trỏ đúng mà đã xin chứng chỉ thì Let's Encrypt từ chối, và xin hỏng nhiều
--  lần liên tiếp là bị hạn mức khoá lại cả tuần.
CREATE TABLE IF NOT EXISTS tenant_domains (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  host        VARCHAR(255) NOT NULL COMMENT 'tên miền đầy đủ, chữ thường, không scheme/cổng: cuahangabc.selliotech.store',
  kind        ENUM('subdomain','custom') NOT NULL DEFAULT 'subdomain'
              COMMENT 'subdomain=mình cấp dưới selliotech.store, custom=tên miền riêng của khách',
  is_primary  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'tên miền dùng để dựng link trong email, hoá đơn',
  verified_at DATETIME(3) NULL COMMENT 'NULL = chưa xác minh DNS, CHƯA được xin chứng chỉ HTTPS',
  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,
  -- NULL khi không phải tên miền chính; nhờ vậy UNIQUE bên dưới ép mỗi
  -- tenant có TỐI ĐA MỘT tên miền chính mà vẫn cho nhiều tên miền phụ.
  primary_mark TINYINT UNSIGNED GENERATED ALWAYS AS (IF(is_primary = 1, 1, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenant_domains_host (host),
  UNIQUE KEY uq_tenant_domains_primary (tenant_id, primary_mark),
  KEY idx_tenant_domains_tenant (tenant_id),
  CONSTRAINT fk_tenant_domains_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control plane — tên miền của từng khách. FK chỉ trong nội bộ lược đồ này.';

-- =====================================================================
--  KẾT THÚC — 4 bảng, 0 dòng dữ liệu, 0 khoá ngoại bắc sang selliotech.
-- =====================================================================
