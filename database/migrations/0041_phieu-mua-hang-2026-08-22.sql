-- =====================================================================
--  0041_phieu-mua-hang-2026-08-22.sql
--  Ngày: 22/08/2026
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
--  PHIẾU MUA HÀNG — MỘT CHỨNG TỪ THAY CHO BA MÀN CŨ
--
--  Migration 0040 gỡ cả chiều mua vào (Đặt hàng nhập / Nhập hàng / Trả
--  hàng nhập). Nay chủ dự án dựng lại theo màn "Phiếu mua hàng" của bản
--  order v2: CHỈ MỘT loại chứng từ, không tách phiếu đặt với phiếu nhập.
--
--  Vòng đời gọn lại còn hai nước:
--
--      lưu tạm ──duyệt──> đã duyệt (hàng vào kho ngay lúc này)
--         └────huỷ────> đã huỷ
--
--  Duyệt là lúc DUY NHẤT tồn kho đổi. Phiếu đã duyệt khoá lại, không sửa
--  và không huỷ: bản v2 cho sửa tiếp và mỗi lần lưu lại CỘNG KHO THÊM
--  một lần nữa mà không trừ số cũ đi — nhập một phiếu ba lần là tồn kho
--  phồng gấp ba. Muốn chữa số đã vào kho thì cân đối ở màn Tồn kho, nơi
--  có bút toán riêng nói rõ ai sửa và sửa vì sao.
--
--  Ba bảng cũ purchase_orders / purchase_order_items /
--  purchase_order_history dùng lại đúng tên: 0040 đã DROP nên không còn
--  dữ liệu nào để đụng độ, và giữ tên cũ thì cột reference_type
--  'purchase_order' trong sổ kho lại có nghĩa như trước.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Bảng phiếu.
--
--  `shop_id` là chi nhánh lập phiếu VÀ là kho hàng sẽ về. Chốt lúc lập
--  chứ không lúc duyệt: người bấm duyệt có thể đang đứng ở chi nhánh
--  khác, mà hàng thì về đúng nơi đã đặt mua.
--
--  `supplier_name` là bản chụp TÊN bên bán và là thứ in ra phiếu. Đổi
--  tên nhà cung cấp hôm nay không được phép sửa lại chứng từ ký tháng
--  trước; cột `supplier_id` chỉ để gom số liệu và lọc.
--
--  Tiền để DECIMAL chứ không FLOAT: đây là tiền, và tổng của một trăm
--  dòng float lệch được vài đồng so với số người dùng tự cộng.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  shop_id   BIGINT UNSIGNED NOT NULL COMMENT 'chi nhánh lập phiếu, cũng là kho hàng sẽ về',

  po_code     VARCHAR(30)  NOT NULL COMMENT 'mã phiếu: PMH20260822001 hoặc do quy tắc đánh số sinh',
  supplier_id BIGINT UNSIGNED NULL,
  supplier_name VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'bản chụp tên bên bán lúc lập phiếu',

  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | approved | cancelled',

  document_date DATE NULL COMMENT 'ngày ghi trên chứng từ của nhà cung cấp',
  expected_date DATE NULL COMMENT 'ngày hẹn giao',

  purchaser_id           BIGINT UNSIGNED NULL COMMENT 'nhân viên phụ trách mua',
  supplier_delivery_code VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'số phiếu giao hàng bên bán ghi',

  -- Thuế khai theo phiếu hay theo từng dòng hàng — bản v2 gọi là
  -- allow_vat_purchase. Khai theo phiếu thì `vat_percent` áp cho mọi dòng.
  vat_mode    VARCHAR(10) NOT NULL DEFAULT 'order' COMMENT 'order = một mức cho cả phiếu, goods = mỗi dòng một mức',
  vat_percent INT NOT NULL DEFAULT 0 COMMENT '% thuế của cả phiếu khi vat_mode = order',

  items_amount    DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'tiền hàng trước thuế, trước chiết khấu',
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  vat_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount    DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'tiền hàng - chiết khấu + thuế',

  paid_amount    DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'đã trả nhà cung cấp, luỹ kế',
  payment_status VARCHAR(20)   NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid | partial | paid',

  note          VARCHAR(500) NOT NULL DEFAULT '',
  attachment    VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'ảnh/bản chụp chứng từ bên bán',
  cancel_reason VARCHAR(255) NOT NULL DEFAULT '',

  created_by BIGINT UNSIGNED NULL,
  handled_by BIGINT UNSIGNED NULL COMMENT 'người động vào phiếu gần nhất',

  approved_at  DATETIME(3) NULL COMMENT 'mốc hàng vào kho',
  cancelled_at DATETIME(3) NULL,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_po_code (tenant_id, po_code),
  KEY idx_po_shop (tenant_id, shop_id, status),
  KEY idx_po_supplier (supplier_id),
  KEY idx_po_created (tenant_id, created_at),
  KEY idx_po_deleted (deleted_at),
  CONSTRAINT fk_po_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Dòng hàng.
--
--  ĐƠN VỊ MUA khác đơn vị tồn kho, và đó là chỗ khó thật của phiếu mua:
--  mua một THÙNG nhưng kho đếm theo CÁI. Ba cột dưới đây nói trọn việc đó:
--
--      unit_ratio    1 đơn vị mua bằng mấy đơn vị tính chính
--      quantity      số đơn vị MUA, đúng như trên hoá đơn bên bán
--      base_quantity = quantity × unit_ratio, và ĐÂY là số cộng vào kho
--
--  Giữ cả ba chứ không chỉ giữ số đã quy đổi: in lại phiếu phải ra đúng
--  "2 thùng" như hoá đơn, còn đối chiếu kho thì cần "48 cái". Bản v2 lưu
--  hai con số quy đổi lệch nhau ở hai chỗ (một đằng nhân đủ hệ số, một
--  đằng thiếu) nên số trên phiếu và số trong kho không bao giờ khớp.
--
--  base_quantity là số NGUYÊN vì sổ kho đếm nguyên. Quy đổi nào ra số lẻ
--  ("1 thùng = 0.5 tạ") thì API từ chối ngay lúc lập phiếu, chứ không tự
--  làm tròn rồi để kho lệch dần.
--
--  Tên hàng / SKU / tên biến thể / ảnh đều là bản chụp: hàng đổi tên hay
--  ngừng bán thì phiếu cũ vẫn đọc được nguyên trạng.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_order_items (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  purchase_order_id  BIGINT UNSIGNED NOT NULL,
  product_id         BIGINT UNSIGNED NULL,
  product_variant_id BIGINT UNSIGNED NULL,

  product_name VARCHAR(255) NOT NULL DEFAULT '',
  variant_sku  VARCHAR(100) NOT NULL DEFAULT '',
  variant_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'bản chụp tên biến thể: "128GB · Đen"',
  thumbnail    VARCHAR(255) NOT NULL DEFAULT '',

  unit_id    BIGINT UNSIGNED NULL COMMENT 'đơn vị MUA; NULL = mua theo đơn vị tính chính',
  unit_name  VARCHAR(50)   NOT NULL DEFAULT '' COMMENT 'bản chụp tên đơn vị mua',
  unit_ratio DECIMAL(15,4) NOT NULL DEFAULT 1 COMMENT '1 đơn vị mua = bao nhiêu đơn vị tính chính',

  quantity      INT NOT NULL DEFAULT 0 COMMENT 'số đơn vị MUA',
  base_quantity INT NOT NULL DEFAULT 0 COMMENT 'quantity × unit_ratio — số thật cộng vào kho',

  unit_cost   DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'giá một đơn vị MUA',
  vat_percent INT NOT NULL DEFAULT 0,
  line_amount DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'quantity × unit_cost',
  vat_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_cost  DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'line_amount + vat_amount',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_poi_order (purchase_order_id),
  KEY idx_poi_variant (product_variant_id),
  CONSTRAINT fk_poi_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_poi_order  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Lịch sử thao tác.
--
--  Tên bảng số ít, cùng quy ước với order_status_history.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_order_history (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  purchase_order_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(20)  NOT NULL DEFAULT '',
  to_status   VARCHAR(20)  NOT NULL DEFAULT '',
  note        VARCHAR(500) NOT NULL DEFAULT '',
  changed_by  BIGINT UNSIGNED NULL,

  created_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_poh_order (purchase_order_id),
  CONSTRAINT fk_poh_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_poh_order  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Quyền và quy tắc đánh số KHÔNG cần khai ở đây.
--
--  Danh mục quyền nằm trong code Go (`domain.DanhMucQuyen`) — cửa hàng
--  tick lại nhóm "Phiếu mua hàng" trong màn Phân quyền là xong. Quy tắc
--  mã cũng vậy: `code_rules` chỉ sinh dòng khi người dùng bật loại
--  "Phiếu mua hàng" trong Thông số chung; chưa bật thì mã chạy theo dải
--  sẵn có PMH + ngày + số thứ tự.
-- ---------------------------------------------------------------------
