-- =====================================================================
--  0039_nha-cung-cap-tro-lai-2026-08-22.sql
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
--  DANH MỤC NHÀ CUNG CẤP TRỞ LẠI
--
--  Migration 0038 gỡ hẳn module theo yêu cầu lúc đó. Nay chủ dự án dựng
--  lại theo màn "Quản lý nhà cung cấp" của bản order v2, nên bảng phải có
--  đủ trường bên đó chứ không chỉ khôi phục bản cũ: thêm tên viết tắt,
--  địa chỉ 2, người đại diện kèm số máy, ảnh và ghi chú.
--
--  Dữ liệu cũ KHÔNG lấy lại được — 0038 đã DROP TABLE. Chứng từ lập trong
--  quãng giữa vẫn đọc được vì `supplier_name` là bản chụp TÊN bên bán, chỉ
--  là không trỏ về danh mục; ai muốn nối lại thì mở phiếu chọn lại NCC.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Bảng danh mục.
--
--  Ở tầng tenant như lần trước: cả chuỗi cửa hàng nhập chung một danh
--  sách nhà cung cấp, giống dùng chung một bộ sản phẩm. Mã duy nhất trong
--  MỘT tenant, không phải toàn hệ thống.
--
--  `is_active` tắt = thôi hợp tác: bên đó biến khỏi ô chọn lúc lập phiếu,
--  nhưng dòng vẫn còn để phiếu cũ tra ra được tên và số máy.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  code       VARCHAR(30)  NOT NULL COMMENT 'mã NCC: NCC001, hoặc do quy tắc đánh số sinh',
  name       VARCHAR(150) NOT NULL,
  short_name VARCHAR(100) NULL COMMENT 'tên gọi tắt hằng ngày',

  tax_code   VARCHAR(30)  NULL COMMENT 'cần khi lấy hoá đơn VAT',
  phone      VARCHAR(20)  NULL,
  email      VARCHAR(191) NULL,

  address       VARCHAR(255) NULL,
  address_line2 VARCHAR(200) NULL COMMENT 'kho hàng / chi nhánh giao dịch khác',

  representative_name  VARCHAR(150) NULL COMMENT 'người đại diện làm việc',
  representative_phone VARCHAR(20)  NULL,

  image      VARCHAR(255) NULL COMMENT 'đường dẫn ảnh do Shop Admin lưu',
  note       VARCHAR(500) NULL,

  is_active  TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_code (tenant_id, code),
  KEY idx_suppliers_name (name),
  KEY idx_suppliers_deleted_at (deleted_at),
  CONSTRAINT fk_suppliers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Trỏ chứng từ mua vào về lại danh mục.
--
--  `supplier_name` GIỮ NGUYÊN và vẫn là thứ in ra phiếu: đổi tên nhà cung
--  cấp hôm nay không được phép sửa lại chứng từ ký từ năm ngoái. Cột id
--  chỉ để gom số liệu (tổng mua, còn nợ) và lọc theo bên bán.
--
--  ON DELETE SET NULL: xoá một nhà cung cấp thì phiếu cũ mất con trỏ chứ
--  không mất phiếu — tên bên bán vẫn còn nguyên trong supplier_name.
-- ---------------------------------------------------------------------
ALTER TABLE purchase_orders
  ADD COLUMN supplier_id BIGINT UNSIGNED NULL AFTER shop_id,
  ADD KEY idx_purchase_orders_supplier (supplier_id),
  ADD CONSTRAINT fk_purchase_orders_supplier
    FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL;

ALTER TABLE purchase_returns
  ADD COLUMN supplier_id BIGINT UNSIGNED NULL AFTER shop_id,
  ADD KEY idx_pr_supplier (supplier_id),
  ADD CONSTRAINT fk_pr_supplier
    FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
--  Quyền và quy tắc đánh số KHÔNG cần dọn ở đây.
--
--  Danh mục quyền nằm trong code Go (`domain.DanhMucQuyen`), 0038 chỉ xoá
--  những dòng đã CẤP cho người dùng — cửa hàng tick lại trong màn Phân
--  quyền là xong. Quy tắc mã cũng vậy: `code_rules` chỉ sinh dòng khi
--  người dùng bật loại "Nhà cung cấp" trong Thông số chung.
-- ---------------------------------------------------------------------
