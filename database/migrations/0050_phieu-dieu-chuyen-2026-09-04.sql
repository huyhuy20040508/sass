-- =====================================================================
--  0050_phieu-dieu-chuyen-2026-09-04.sql
--  Ngày: 04/09/2026
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
--  PHIẾU ĐIỀU CHUYỂN — CHUYỂN HÀNG GIỮA HAI KHO CỦA CÙNG MỘT CỬA HÀNG
--
--  Vòng đời hai nước, y như phiếu mua và phiếu trả:
--
--      lưu tạm ──duyệt──> đã duyệt (hàng RỜI kho xuất và VÀO kho nhập)
--
--  Vì sao bảng này gấp: từ lúc chốt "mỗi chi nhánh một kho riêng", đây là
--  ĐƯỜNG DUY NHẤT để hàng đi từ kho này sang kho kia. Không có nó thì
--  siết kho xong là hàng bị nhốt tại chỗ — nhập nhầm chi nhánh một lần là
--  không có cách nào gỡ ngoài chỉnh tay hai đầu, mà chỉnh tay thì không
--  để lại chứng từ nào nói hàng đã đi đâu.
--
--  MỘT PHIẾU, HAI BÚT TOÁN KHO. Duyệt là lúc DUY NHẤT tồn kho đổi, và cả
--  hai đầu phải đổi trong CÙNG một transaction: ghi được một đầu rồi hỏng
--  đầu kia nghĩa là hàng bốc hơi hoặc tự sinh ra.
--
--  KHÁC v2 hai chỗ, cùng lý do đã ghi ở 0043:
--
--   1. Phiếu ĐÃ DUYỆT khoá lại — không sửa, không xoá. v2 cho sửa tiếp và
--      mỗi lượt lưu lại hoàn kho rồi trừ lại; một lượt hỏng giữa chừng là
--      hai kho cùng lệch mà không ai dò ra.
--   2. Số lượng là số NGUYÊN, cùng đơn vị với `variant_stocks.quantity`.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Bảng phiếu.
--
--  `from_shop_id` / `to_shop_id` là HAI KHO THẬT, chốt lúc lập phiếu và
--  không đổi nữa. Không có cột `shop_id` chung như các chứng từ khác: một
--  phiếu điều chuyển thuộc về HAI chi nhánh cùng lúc, và chọn bừa một
--  trong hai làm "chi nhánh của phiếu" là để nửa còn lại không tra ra
--  chứng từ đã làm kho mình đổi số.
--
--  Không đặt CHECK (from <> to): luật này cần một câu lỗi người dùng đọc
--  được chứ không phải lỗi ràng buộc của MySQL, nên nó nằm ở service. Ở
--  đây chỉ giữ chỉ mục cho hai chiều tra.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_transfers (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  transfer_code VARCHAR(30) NOT NULL COMMENT 'ma phieu: PDC20260904001 hoac do quy tac danh so sinh',

  from_shop_id BIGINT UNSIGNED NOT NULL COMMENT 'kho xuat — hang roi di',
  to_shop_id   BIGINT UNSIGNED NOT NULL COMMENT 'kho nhap — hang ve',

  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | approved',

  -- Người nhận là NHÂN VIÊN ở kho nhập, ký nhận hàng. ON DELETE SET NULL:
  -- hồ sơ nhân sự nghỉ việc bị xoá không kéo theo chứng từ đã ghi kho.
  receiver_id BIGINT UNSIGNED NULL COMMENT 'nhan vien nhan hang o kho nhap',

  note VARCHAR(500) NOT NULL DEFAULT '',

  items_amount DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'tong gia tri hang chuyen, theo gia von',

  created_by BIGINT UNSIGNED NULL,
  handled_by BIGINT UNSIGNED NULL COMMENT 'nguoi dong vao phieu gan nhat',

  approved_at DATETIME(3) NULL COMMENT 'moc hang doi kho — cung la ngay nhap kho tren bang danh sach',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_st_code (tenant_id, transfer_code),
  KEY idx_st_from (tenant_id, from_shop_id, status),
  KEY idx_st_to (tenant_id, to_shop_id, status),
  KEY idx_st_created (tenant_id, created_at),
  KEY idx_st_deleted (deleted_at),
  CONSTRAINT fk_st_tenant   FOREIGN KEY (tenant_id)    REFERENCES tenants (id),
  CONSTRAINT fk_st_from     FOREIGN KEY (from_shop_id) REFERENCES shops (id),
  CONSTRAINT fk_st_to       FOREIGN KEY (to_shop_id)   REFERENCES shops (id),
  CONSTRAINT fk_st_receiver FOREIGN KEY (receiver_id)  REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Dòng hàng.
--
--  `lot_number` để rỗng nghĩa là "không chỉ định lô": lượt xuất rút theo
--  luật kho của cửa hàng (FIFO/FEFO), và lượt nhập ở kho kia cũng vào lô
--  không xác định. Có số lô thì HAI ĐẦU dùng chung đúng số lô đó — hàng
--  đi qua kho khác nhưng vẫn là lô ấy, hạn dùng ấy.
--
--  Cụm product_name / variant_sku / variant_name / unit_name là bản CHỤP
--  lúc lập phiếu: chứng từ in tháng trước không đổi theo danh mục sửa hôm
--  nay. `unit_cost` chụp giá vốn để tính giá trị phiếu và ghi vào sổ kho.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  stock_transfer_id BIGINT UNSIGNED NOT NULL,

  product_id         BIGINT UNSIGNED NULL,
  product_variant_id BIGINT UNSIGNED NULL,

  product_name VARCHAR(255) NOT NULL DEFAULT '',
  variant_sku  VARCHAR(100) NOT NULL DEFAULT '',
  variant_name VARCHAR(255) NOT NULL DEFAULT '',
  unit_name    VARCHAR(50)  NOT NULL DEFAULT '',

  lot_number  VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'rong = khong chi dinh lo, rut theo luat kho',
  expire_date DATE NULL,

  quantity    INT NOT NULL DEFAULT 0 COMMENT 'so don vi tinh chinh duoc chuyen',
  unit_cost   DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'gia von mot don vi, chup luc lap phieu',
  line_amount DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'quantity x unit_cost',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_sti_transfer (stock_transfer_id),
  KEY idx_sti_variant (product_variant_id),
  CONSTRAINT fk_sti_tenant   FOREIGN KEY (tenant_id)         REFERENCES tenants (id),
  CONSTRAINT fk_sti_transfer FOREIGN KEY (stock_transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Lịch sử thao tác. Tên bảng số ít, cùng quy ước với
--  purchase_order_history và supplier_return_history.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_transfer_history (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  stock_transfer_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(20)  NOT NULL DEFAULT '',
  to_status   VARCHAR(20)  NOT NULL DEFAULT '',
  note        VARCHAR(500) NOT NULL DEFAULT '',
  changed_by  BIGINT UNSIGNED NULL,

  created_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_sth_transfer (stock_transfer_id),
  CONSTRAINT fk_sth_tenant   FOREIGN KEY (tenant_id)         REFERENCES tenants (id),
  CONSTRAINT fk_sth_transfer FOREIGN KEY (stock_transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Quyền và quy tắc đánh số KHÔNG khai ở đây — cả hai nằm trong code Go
--  (`domain.DanhMucQuyen`, `domain.DanhMucLoaiMa`). Chưa bật quy tắc thì
--  mã chạy theo dải sẵn có PDC + ngày + số thứ tự.
-- ---------------------------------------------------------------------
