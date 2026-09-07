-- =====================================================================
--  0058_thu-chi-2026-09-07.sql
--  Ngày: 07/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  QUẢN LÝ THU CHI — sổ phiếu thu và phiếu chi (Thu chi → Quản lý thu chi).
--
--  Port từ `cab_income_expenses` + `cab_payers` của bản cũ v2, bỏ hẳn
--  `cab_funds`. Sáu chỗ làm khác, mỗi chỗ đều vì bản cũ có lỗi thật:
--
--   1. KHÔNG CÓ BẢNG QUỸ. v2 giữ `cab_funds` (mỗi ngày một dòng
--      opening/closing) và cộng trừ nó trong hook created/updated/deleted
--      của model. Ba thứ hỏng theo: dòng quỹ `firstOrCreate` CHỈ theo ngày
--      nên hai chi nhánh cùng ngày dùng chung một dòng; phép đọc-cộng-ghi
--      không khoá nên hai phiếu song song mất update; và số dư một khi
--      lệch thì lệch vĩnh viễn vì không ai tính lại.
--      Bên này quỹ đầu kỳ / cuối kỳ CỘNG KHI ĐỌC từ chính bảng phiếu. Chậm
--      hơn một phép SUM có chỉ số, đổi lại không bao giờ trôi khỏi sự thật.
--
--   2. `shift_id` ghi thẳng lúc lập phiếu. v2 dò xem phiếu rơi vào ca nào
--      bằng cách so `created_at` với khoảng [open_time, close_time] mỗi lần
--      hiển thị — đổi giờ máy chủ hay sửa lại giờ mở ca là phiếu nhảy ca.
--      Ghi thẳng thì phiếu thuộc ca nào là chốt ngay lúc lập, và khoá
--      sửa/xoá theo ca đã đóng chỉ còn là một phép so NULL.
--
--   3. `payer_id` KHÔNG dùng chung cho ba bảng. v2 nhét id nhân viên, id
--      nhà cung cấp và id người nộp vãng lai vào cùng một cột `employee_id`
--      rồi phân biệt bằng `employee_type` — không đặt được khoá ngoại nào,
--      và tra nhầm bảng thì ra tên người khác. Bên này tách ba cột, mỗi cột
--      có khoá ngoại của nó.
--
--   4. `source` là chuỗi nói đúng nguồn. v2 dùng `type` 1..4 mà nhãn lệch
--      nghĩa: "Bán hàng" gộp cả trả hàng NCC, "Tự động tạo" thật ra là
--      phiếu nhập tay. Kèm `source_id` đa hình theo `type` nên không cột
--      nào tra ngược được.
--
--   5. `amount` là DECIMAL(15,2). v2 để DOUBLE rồi ép (int) lúc cộng quỹ —
--      mất phần lẻ, và tiền thì không được phép mất phần lẻ.
--
--   6. Có tenant_id + shop_id, bộ lọc cửa hàng bắt buộc ở tầng dưới GORM.
--
--  VÌ SAO KHÔNG CÓ UNIQUE KEY TRÊN `code`:
--  Cùng lý do đã ghi ở migration 0057 — bảng xoá mềm, khoá duy nhất trần
--  thì mã của dòng đã xoá giữ chỗ mãi mãi. Chống trùng nằm ở vòng sinh mã
--  (code_rules), và chỉ số dưới đây để TRA.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  NGƯỜI NỘP / NGƯỜI NHẬN VÃNG LAI
--
--  Người trả tiền hoặc nhận tiền mà KHÔNG phải nhân viên, cũng không phải
--  nhà cung cấp: khách vãng lai, chủ nhà cho thuê, bên vận chuyển…
--
--  v2 bày sáu ô trong hộp thêm nhanh (loại KH, giới tính, ngày sinh…)
--  nhưng chỉ GỬI bốn, và một trong bốn — email — còn không có ô nào trong
--  hộp. Bảng này giữ đúng ba thứ thật sự dùng tới.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS income_expense_payers (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  name    VARCHAR(255) NOT NULL COMMENT 'ten nguoi nop / nguoi nhan',
  phone   VARCHAR(20)  NOT NULL DEFAULT '',
  address VARCHAR(255) NOT NULL DEFAULT '',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),

  KEY idx_iep_tenant_name (tenant_id, name(100)),
  KEY idx_iep_deleted (deleted_at),

  CONSTRAINT fk_iep_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  PHIẾU THU / PHIẾU CHI
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS income_expenses (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  -- Chi nhánh phát sinh phiếu. Chốt lúc lập, không đổi về sau: mã phiếu và
  -- mọi bảng cộng dồn đều tính theo con số này.
  shop_id BIGINT UNSIGNED NOT NULL,

  code VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'ma phieu, sinh theo code_rules',

  -- 0 = phieu THU, 1 = phieu CHI. Trùng mã số của v2 và của
  -- income_expense_types.type, để hai bảng không phải dịch qua lại.
  type TINYINT UNSIGNED NOT NULL,

  category_id BIGINT UNSIGNED NULL COMMENT 'income_expense_types.id',

  amount DECIMAL(15,2) NOT NULL DEFAULT 0,

  -- Doi tuong nop/nhan tien. payer_type quyet dinh cot nao trong ba cot
  -- duoi day co gia tri; ca ba deu NULL khi khong khai doi tuong.
  --   quan_ly | thu_ngan -> employee_id (users.id, loc theo cua vao)
  --   supplier           -> supplier_id
  --   other              -> payer_id
  payer_type  VARCHAR(20)     NOT NULL DEFAULT '',
  employee_id BIGINT UNSIGNED NULL,
  supplier_id BIGINT UNSIGNED NULL,
  payer_id    BIGINT UNSIGNED NULL,

  payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' COMMENT 'cash | transfer',

  attachment VARCHAR(2048) NOT NULL DEFAULT '' COMMENT 'duong dan tep dinh kem',
  note       VARCHAR(255)  NOT NULL DEFAULT '',

  -- Nguon phat sinh. manual = nguoi dung tu lap; bon gia tri con lai la
  -- phieu TU SINH tu chung tu khac va khong ai sua/xoa duoc.
  --   manual | order | purchase | supplier_return | order_return
  source    VARCHAR(20)     NOT NULL DEFAULT 'manual',
  source_id BIGINT UNSIGNED NULL COMMENT 'id chung tu goc, NULL khi source = manual',

  -- Ca truc luc lap phieu. NULL = luc do khong ca nao mo (van ghi phieu
  -- binh thuong, giong cash_entries).
  shift_id BIGINT UNSIGNED NULL,

  created_by BIGINT UNSIGNED NULL,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),

  -- Duong doc chinh cua man danh sach: mot chi nhanh, mot khoang ngay.
  KEY idx_ie_tenant_shop_created (tenant_id, shop_id, created_at),
  -- Cong quy dau ky / cuoi ky: SUM(amount) theo (cua hang, ve thu-chi, ngay).
  KEY idx_ie_tenant_type_created (tenant_id, type, created_at),
  KEY idx_ie_code (tenant_id, code),
  KEY idx_ie_category (category_id),
  KEY idx_ie_created_by (created_by),
  KEY idx_ie_source (source, source_id),
  KEY idx_ie_shift (shift_id),
  KEY idx_ie_deleted (deleted_at),

  CONSTRAINT fk_ie_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id),
  CONSTRAINT fk_ie_shop     FOREIGN KEY (shop_id)     REFERENCES shops (id),
  CONSTRAINT fk_ie_category FOREIGN KEY (category_id) REFERENCES income_expense_types (id),
  CONSTRAINT fk_ie_employee FOREIGN KEY (employee_id) REFERENCES users (id),
  CONSTRAINT fk_ie_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_ie_payer    FOREIGN KEY (payer_id)    REFERENCES income_expense_payers (id),
  CONSTRAINT fk_ie_shift    FOREIGN KEY (shift_id)    REFERENCES work_shifts (id),
  CONSTRAINT fk_ie_creator  FOREIGN KEY (created_by)  REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  QUY TẮC MÃ cho hai loại chứng từ mới.
--
--  v2 dùng hai rule riêng `cash-receipts` / `cash-payments`, giữ nguyên
--  cách tách đôi ấy: phiếu thu và phiếu chi đánh số riêng thì đọc sổ mới
--  biết ngay dải mã nào là tiền vào, dải nào là tiền ra.
--
--  Gieo cho MỌI chi nhánh đang có, giống cách các chứng từ khác được gieo.
--  `is_active = 1`: chứng từ luôn tự sinh mã, ô tick bật/tắt không dựng cho
--  loại này (xem LoaiMa.BatTatDuoc).
-- ---------------------------------------------------------------------
INSERT INTO code_rules (tenant_id, shop_id, doc_type, prefix, value_part, length, suffix, is_active, created_at, updated_at)
SELECT s.tenant_id, s.id, 'phieu-thu', 'PT', 'so-thu-tu', 5, '', 1, NOW(3), NOW(3)
FROM shops s
WHERE NOT EXISTS (
  SELECT 1 FROM code_rules r
  WHERE r.tenant_id = s.tenant_id AND r.shop_id = s.id AND r.doc_type = 'phieu-thu'
);

INSERT INTO code_rules (tenant_id, shop_id, doc_type, prefix, value_part, length, suffix, is_active, created_at, updated_at)
SELECT s.tenant_id, s.id, 'phieu-chi', 'PC', 'so-thu-tu', 5, '', 1, NOW(3), NOW(3)
FROM shops s
WHERE NOT EXISTS (
  SELECT 1 FROM code_rules r
  WHERE r.tenant_id = s.tenant_id AND r.shop_id = s.id AND r.doc_type = 'phieu-chi'
);
