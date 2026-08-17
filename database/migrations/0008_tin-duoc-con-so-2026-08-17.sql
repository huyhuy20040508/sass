-- =====================================================================
--  0008_tin-duoc-con-so-2026-08-17.sql
--  Ngày: 17/08/2026
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
--  CHỦ SHOP TIN ĐƯỢC CON SỐ (GIAI ĐOẠN 3)
--
--  Hai giai đoạn trước làm cho quầy bán được và dùng được cả ngày. Còn
--  thiếu vế cuối: cuối ngày chủ tiệm phải đối chiếu được TIỀN TRONG KÉT
--  với SỔ, và biết mình thật sự lãi bao nhiêu chứ không chỉ thu về bao
--  nhiêu.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. Giá vốn CHỤP LẠI vào từng dòng hàng.
--
--  Báo cáo lãi gộp đang tính giá vốn bằng COALESCE(pv.cost_price,
--  p.cost_price) — tức là GIÁ VỐN HÔM NAY, không phải giá vốn lúc bán.
--  Nhập lô mới đắt hơn 20% là toàn bộ lãi của những tháng trước tự động
--  co lại trên báo cáo, dù không đơn hàng nào thay đổi. Sổ sách mà tự đổi
--  số liệu quá khứ thì không dùng để ra quyết định được.
--
--  Từ nay mỗi dòng hàng mang theo giá vốn tại đúng thời điểm bán.
--
--  NULL CÓ NGHĨA, không phải "chưa điền": nó nói "dòng này bán trước khi
--  có cột này" (hoặc đi qua đường tạo đơn thủ công — nơi người nhập gõ
--  giá bán chứ không tra giá vốn). Báo cáo lùi về giá vốn hiện tại cho
--  những dòng ấy, đúng bằng cách nó vẫn tính từ trước tới giờ.
--
--  KHÔNG NẠP SẴN dữ liệu cũ. Nạp giá vốn HÔM NAY vào đơn của tháng trước
--  là đóng dấu một con số sai và làm nó trông như số liệu thật — tệ hơn
--  hẳn việc để trống và nói rõ là đang ước lượng.
-- ---------------------------------------------------------------------
ALTER TABLE order_items
  ADD COLUMN cost_price DECIMAL(12,2) NULL
    COMMENT 'giá vốn một đơn vị TẠI THỜI ĐIỂM BÁN; NULL = dòng cũ, báo cáo lùi về giá vốn hiện tại'
    AFTER unit_price;

-- ---------------------------------------------------------------------
--  2. Ca làm việc.
--
--  Một ca là một lần có người chịu trách nhiệm về cái két: mở ca thì đếm
--  tiền đầu, đóng ca thì đếm lại và đối chiếu với sổ. Không có ca thì
--  "két thiếu 200 nghìn" là một câu không ai trả lời được, vì không biết
--  thiếu từ lúc nào và trong lượt trực của ai.
--
--  MỖI CHI NHÁNH CHỈ MỘT CA MỞ. Ràng buộc bằng cột sinh `closed_mark`
--  (NULL quy về mốc cố định) ghép với shop_id — cùng thủ thuật với
--  deleted_mark của product_variants, và vì cùng một lý do: MySQL coi mỗi
--  NULL là một giá trị khác nhau nên UNIQUE trên closed_at trần sẽ không
--  chặn được gì. Hai ca đóng cùng một mili giây tại cùng chi nhánh cũng
--  bị chặn theo, nhưng đó là chuyện không xảy ra: mỗi lúc chỉ một ca mở.
--
--  Tiền cuối ca lưu BA con số chứ không phải một:
--    counted_cash  = người ta ĐẾM ĐƯỢC bao nhiêu
--    expected_cash = SỔ nói lẽ ra phải có bao nhiêu
--    difference    = chênh lệch, tính sẵn
--  Lưu cả ba vì `expected_cash` tính từ các dòng sổ quỹ tại thời điểm
--  đóng ca; sổ có thể được ghi thêm sau đó (một khoản chi quên ghi), và
--  khi ấy con số đối chiếu của hôm đóng ca vẫn phải giữ nguyên như lúc
--  hai bên ký nhận.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS work_shifts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  shop_id       BIGINT UNSIGNED NOT NULL COMMENT 'chi nhánh của ca này',

  opened_by     BIGINT UNSIGNED NOT NULL COMMENT 'người mở ca',
  opened_at     DATETIME(3) NOT NULL,
  opening_cash  DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'tiền mặt có sẵn trong két lúc mở ca',

  closed_by     BIGINT UNSIGNED NULL,
  closed_at     DATETIME(3) NULL,
  counted_cash  DECIMAL(12,2) NULL COMMENT 'tiền đếm được lúc đóng ca',
  expected_cash DECIMAL(12,2) NULL COMMENT 'tiền theo sổ: đầu ca + thu - chi',
  difference    DECIMAL(12,2) NULL COMMENT 'đếm được - theo sổ; âm = thiếu két',

  note          VARCHAR(500) NULL,
  created_at    DATETIME(3) NULL,
  updated_at    DATETIME(3) NULL,

  closed_mark   DATETIME(3) GENERATED ALWAYS AS (IFNULL(closed_at, '1970-01-01 00:00:00.000')) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_work_shifts_open (shop_id, closed_mark),
  KEY idx_work_shifts_tenant (tenant_id),
  KEY idx_work_shifts_opened_at (opened_at),
  CONSTRAINT fk_work_shifts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_work_shifts_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id),
  CONSTRAINT fk_work_shifts_opener FOREIGN KEY (opened_by) REFERENCES users (id),
  CONSTRAINT fk_work_shifts_closer FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. Sổ quỹ — mọi lần tiền mặt vào hoặc ra khỏi két.
--
--  Bán hàng thu tiền mặt ghi một dòng THU; hoàn tiền khi khách trả hàng
--  ghi một dòng CHI. Ngoài ra người trực ghi tay những khoản không đi qua
--  đơn hàng: mua nước, trả tiền ship, chủ rút bớt tiền mặt về.
--
--  shift_id CHO PHÉP NULL, và đó là quyết định có cân nhắc: bán hàng
--  KHÔNG BAO GIỜ bị chặn vì chưa ai mở ca. Chặn lại thì một buổi sáng
--  quên mở ca là cả tiệm không bán được gì — cái giá đó lớn hơn hẳn lợi
--  ích của việc ép đúng quy trình. Dòng "ngoài ca" vẫn vào sổ đầy đủ, và
--  màn hình đóng ca chỉ ra chúng để người ta biết mà xử lý.
--
--  CHỈ GHI TIỀN MẶT. Chuyển khoản không đi qua két nên không thuộc sổ
--  này; trộn vào là con số đối chiếu cuối ca không còn khớp với tiền đếm
--  được, tức là làm hỏng đúng thứ cả bảng này sinh ra để phục vụ.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cash_entries (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  shop_id        BIGINT UNSIGNED NOT NULL,
  shift_id       BIGINT UNSIGNED NULL COMMENT 'NULL = phát sinh lúc không có ca nào mở',

  direction      ENUM('in','out') NOT NULL COMMENT 'in = tiền vào két, out = tiền ra',
  amount         DECIMAL(12,2) NOT NULL COMMENT 'luôn DƯƠNG; chiều nằm ở cột direction',
  reason         VARCHAR(255) NOT NULL,

  -- Nguồn gốc: 'order' (bán hàng), 'order_return' (hoàn tiền), 'manual' (ghi tay).
  reference_type VARCHAR(30) NULL,
  reference_id   BIGINT UNSIGNED NULL,

  created_by     BIGINT UNSIGNED NULL,
  created_at     DATETIME(3) NULL,
  updated_at     DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_cash_entries_tenant (tenant_id),
  KEY idx_cash_entries_shift (shift_id),
  KEY idx_cash_entries_shop_time (shop_id, created_at),
  KEY idx_cash_entries_ref (reference_type, reference_id),
  CONSTRAINT fk_cash_entries_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id),
  CONSTRAINT fk_cash_entries_shop    FOREIGN KEY (shop_id)    REFERENCES shops (id),
  CONSTRAINT fk_cash_entries_shift   FOREIGN KEY (shift_id)   REFERENCES work_shifts (id) ON DELETE SET NULL,
  CONSTRAINT fk_cash_entries_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  4. Đổi hàng: nối phiếu trả với đơn mới.
--
--  Đổi hàng ở quầy là hai việc xảy ra cùng lúc — hàng cũ về kho, hàng mới
--  ra khỏi kho — và chỉ có nghĩa khi nhìn CÙNG NHAU. Không có mối nối
--  này thì trong sổ chúng là một phiếu trả và một đơn bán ngẫu nhiên
--  trùng giờ, và câu hỏi "khách đổi cái áo đó lấy cái gì" không tra được.
--
--  Cột nằm ở phiếu trả (bên phát sinh trước trong một lượt đổi) và trỏ
--  tới đơn mới.
-- ---------------------------------------------------------------------
ALTER TABLE order_returns
  ADD COLUMN exchange_order_id BIGINT UNSIGNED NULL
    COMMENT 'đơn mới khách lấy về trong lượt đổi hàng; NULL = trả hàng thuần, có hoàn tiền'
    AFTER order_id,
  ADD KEY idx_order_returns_exchange (exchange_order_id),
  ADD CONSTRAINT fk_order_returns_exchange FOREIGN KEY (exchange_order_id)
    REFERENCES orders (id) ON DELETE SET NULL;
