-- =====================================================================
--  0043_tra-hang-nha-cung-cap-2026-08-23.sql
--  Ngày: 23/08/2026
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
--  TRẢ HÀNG NHÀ CUNG CẤP — CHIỀU NGƯỢC CỦA PHIẾU MUA
--
--  Dựng theo màn "Phiếu trả hàng nhà cung cấp" của bản order v2. Vòng
--  đời gọn đúng hai nước, y như phiếu mua:
--
--      lưu tạm ──duyệt──> đã duyệt (hàng RỜI kho ngay lúc này)
--
--  Duyệt là lúc DUY NHẤT tồn kho đổi, nên phiếu đã duyệt khoá lại: không
--  sửa, không xoá. Bản v2 cho sửa tiếp và mỗi lượt lưu lại hoàn kho rồi
--  trừ lại — một chuỗi cộng trừ mà chỉ cần một lượt hỏng giữa chừng là
--  kho lệch, không ai dò ra.
--
--  KHÁC v2 hai chỗ, cố ý:
--
--   1. Phiếu trả LUÔN gắn với một phiếu mua và từng dòng gắn với một
--      dòng của phiếu mua ấy (`purchase_order_item_id`). Trả được bao
--      nhiêu là tính theo dòng đó, cộng dồn qua MỌI phiếu trả đã duyệt —
--      v2 có đoạn tính này nhưng bị chú thích lại, nên một phiếu mua trả
--      được vô số lần vượt cả số đã mua.
--
--   2. Số lượng là số NGUYÊN. Sổ kho của hệ thống này đếm nguyên
--      (`variant_stocks.quantity` INT), nên nhận 0,5 rồi làm tròn lúc ghi
--      kho là mỗi phiếu lệch một ít — đúng cách phiếu mua đã từ chối ở
--      migration 0041.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Bảng phiếu.
--
--  `shop_id` là chi nhánh lập phiếu VÀ là kho hàng sẽ rời đi. Chốt lúc
--  lập chứ không lúc duyệt: người bấm duyệt có thể đang đứng ở chi nhánh
--  khác, mà hàng thì đi khỏi đúng kho đã nhận nó.
--
--  Cụm supplier_* / address* / *_phone là bản CHỤP hồ sơ bên bán lúc lập
--  phiếu — chứng từ in ra tháng trước không được đổi theo danh mục sửa
--  hôm nay. `supplier_id` chỉ để gom số liệu và lọc.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS supplier_returns (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  shop_id   BIGINT UNSIGNED NOT NULL COMMENT 'chi nhánh lập phiếu, cũng là kho hàng sẽ rời đi',

  return_code VARCHAR(30) NOT NULL COMMENT 'mã phiếu: PTH20260823001 hoặc do quy tắc đánh số sinh',

  -- Phiếu mua gốc. ON DELETE SET NULL chứ không CASCADE: phiếu mua bị xoá
  -- (chỉ phiếu lưu tạm mới xoá được) không kéo theo chứng từ trả hàng đã
  -- ghi vào sổ kho.
  purchase_order_id   BIGINT UNSIGNED NULL,
  purchase_order_code VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'bản chụp mã phiếu mua',

  supplier_id   BIGINT UNSIGNED NULL,
  supplier_code VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'bản chụp mã bên bán',
  supplier_name VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'bản chụp tên bên bán',

  address        VARCHAR(255) NOT NULL DEFAULT '',
  address_2      VARCHAR(255) NOT NULL DEFAULT '',
  supplier_phone VARCHAR(20)  NOT NULL DEFAULT '',
  contact_phone  VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'số máy người đại diện bên bán',

  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | approved',

  document_date DATE NULL COMMENT 'ngày ghi trên chứng từ',
  expired_date  DATE NULL COMMENT 'hạn của phiếu trả, v2 gọi là ngày hết hạn',

  purchaser_id           BIGINT UNSIGNED NULL COMMENT 'nhân viên mua hàng phụ trách',
  receiver_delivery_note VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'số phiếu giao bên nhận',

  vat_percent INT NOT NULL DEFAULT 0 COMMENT '% thuế của phiếu mua gốc, chụp lại để in phiếu',

  items_amount DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'tiền hàng trả, trước thuế',
  vat_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'tiền hàng + thuế',

  note VARCHAR(500) NOT NULL DEFAULT '',

  created_by BIGINT UNSIGNED NULL,
  handled_by BIGINT UNSIGNED NULL COMMENT 'người động vào phiếu gần nhất',

  approved_at DATETIME(3) NULL COMMENT 'mốc hàng rời kho',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_sr_code (tenant_id, return_code),
  KEY idx_sr_shop (tenant_id, shop_id, status),
  KEY idx_sr_supplier (supplier_id),
  KEY idx_sr_po (purchase_order_id),
  KEY idx_sr_created (tenant_id, created_at),
  KEY idx_sr_deleted (deleted_at),
  CONSTRAINT fk_sr_tenant   FOREIGN KEY (tenant_id)         REFERENCES tenants (id),
  CONSTRAINT fk_sr_supplier FOREIGN KEY (supplier_id)       REFERENCES suppliers (id) ON DELETE SET NULL,
  CONSTRAINT fk_sr_po       FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Dòng hàng trả.
--
--  `purchase_order_item_id` là chỗ dựa của toàn bộ luật trả hàng: trả bao
--  nhiêu là so với SỐ ĐÃ MUA của đúng dòng ấy, trừ đi phần đã trả ở các
--  phiếu ĐÃ DUYỆT trước đó. ON DELETE SET NULL để phiếu trả không chết
--  theo dòng phiếu mua bị sửa lại.
--
--  Ba cột đơn vị giữ nguyên nghĩa của phiếu mua:
--      unit_ratio    1 đơn vị trả bằng mấy đơn vị tính chính
--      quantity      số đơn vị TRẢ, đúng như ghi trên phiếu
--      base_quantity = quantity × unit_ratio, và ĐÂY là số trừ khỏi kho
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS supplier_return_items (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  supplier_return_id     BIGINT UNSIGNED NOT NULL,
  purchase_order_item_id BIGINT UNSIGNED NULL COMMENT 'dòng phiếu mua gốc — trần số lượng trả tính theo dòng này',

  product_id         BIGINT UNSIGNED NULL,
  product_variant_id BIGINT UNSIGNED NULL,

  product_name VARCHAR(255) NOT NULL DEFAULT '',
  variant_sku  VARCHAR(100) NOT NULL DEFAULT '',
  variant_name VARCHAR(255) NOT NULL DEFAULT '',
  thumbnail    VARCHAR(255) NOT NULL DEFAULT '',

  unit_id    BIGINT UNSIGNED NULL COMMENT 'đơn vị TRẢ; NULL = theo đơn vị tính chính',
  unit_name  VARCHAR(50)   NOT NULL DEFAULT '',
  unit_ratio DECIMAL(15,4) NOT NULL DEFAULT 1,

  lot_number  VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'chụp lại từ dòng phiếu mua',
  expire_date DATE NULL,

  quantity      INT NOT NULL DEFAULT 0 COMMENT 'số đơn vị TRẢ',
  base_quantity INT NOT NULL DEFAULT 0 COMMENT 'quantity × unit_ratio — số thật trừ khỏi kho',

  unit_cost   DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'giá nhập một đơn vị TRẢ, chụp từ phiếu mua',
  vat_percent INT NOT NULL DEFAULT 0,
  line_amount DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'quantity × unit_cost',
  vat_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_cost  DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'line_amount + vat_amount',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_sri_return (supplier_return_id),
  KEY idx_sri_variant (product_variant_id),
  KEY idx_sri_poi (purchase_order_item_id),
  CONSTRAINT fk_sri_tenant FOREIGN KEY (tenant_id)              REFERENCES tenants (id),
  CONSTRAINT fk_sri_return FOREIGN KEY (supplier_return_id)     REFERENCES supplier_returns (id) ON DELETE CASCADE,
  CONSTRAINT fk_sri_poi    FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Lịch sử thao tác. Tên bảng số ít, cùng quy ước với
--  purchase_order_history và order_status_history.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS supplier_return_history (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  supplier_return_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(20)  NOT NULL DEFAULT '',
  to_status   VARCHAR(20)  NOT NULL DEFAULT '',
  note        VARCHAR(500) NOT NULL DEFAULT '',
  changed_by  BIGINT UNSIGNED NULL,

  created_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_srh_return (supplier_return_id),
  CONSTRAINT fk_srh_tenant FOREIGN KEY (tenant_id)          REFERENCES tenants (id),
  CONSTRAINT fk_srh_return FOREIGN KEY (supplier_return_id) REFERENCES supplier_returns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Quyền và quy tắc đánh số KHÔNG khai ở đây — cả hai nằm trong code Go
--  (`domain.DanhMucQuyen`, `domain.DanhMucLoaiMa`). Cửa hàng tick lại
--  nhóm "Trả hàng nhà cung cấp" trong màn Phân quyền là xong; mã chưa bật
--  quy tắc thì chạy theo dải sẵn có PTH + ngày + số thứ tự.
-- ---------------------------------------------------------------------
