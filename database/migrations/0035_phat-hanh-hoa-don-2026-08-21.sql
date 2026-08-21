-- =====================================================================
--  0035_phat-hanh-hoa-don-2026-08-21.sql
--  Ngày: 21/08/2026
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
--  PHÁT HÀNH HOÁ ĐƠN ĐIỆN TỬ CHO ĐƠN HÀNG
--
--  Mỗi đơn hàng phát hành ĐÚNG MỘT hoá đơn (uq_etax_invoices_order). Bán
--  một lần mà xuất hai hoá đơn là hai lần ghi doanh thu với cơ quan thuế,
--  và huỷ một trong hai thì phải làm biên bản.
--
--  VÌ SAO GIỮ CẢ `payload` LẪN `response`: hoá đơn là chứng từ pháp lý.
--  Khi khách thắc mắc "sao số tiền trên hoá đơn khác hoá đơn giấy", thứ
--  trả lời được là bản ghi ĐÃ GỬI ĐI, không phải bản dựng lại từ đơn hàng
--  hôm nay — đơn có thể đã bị sửa, mặt hàng có thể đã đổi thuế suất.
--
--  `status` có ba giá trị, và chúng KHÁC nhau về hậu quả:
--    draft  — đã lưu bên cổng nhưng CHƯA ký. Chưa có giá trị pháp lý,
--             người dùng vào trang của nhà cung cấp ký tay.
--    issued — đã ký và phát hành, có số hoá đơn thật.
--    failed — cổng từ chối. Giữ lại để tra `error` chứ không xoá: xoá đi
--             thì lần sau người ta bấm lại và gặp đúng lỗi cũ mà không
--             biết vì sao.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS etax_invoices (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL,
  order_id   BIGINT UNSIGNED NOT NULL,
  -- Kết nối đã dùng để phát hành. ON DELETE SET NULL: ngắt kết nối cổng
  -- KHÔNG được làm biến mất chứng từ đã phát hành.
  connection_id BIGINT UNSIGNED NULL,

  provider VARCHAR(20) NOT NULL DEFAULT 'minvoice',
  symbol   VARCHAR(20) NOT NULL COMMENT 'ký hiệu đã phát hành',
  status   VARCHAR(10) NOT NULL DEFAULT 'draft' COMMENT 'draft | issued | failed',

  -- Số hoá đơn và định danh bên cổng. Rỗng khi mới lưu nháp hoặc khi hỏng.
  invoice_no VARCHAR(30)  NULL,
  invoice_id VARCHAR(100) NULL COMMENT 'id bản ghi bên nhà cung cấp',

  total_amount DECIMAL(15, 2) NOT NULL DEFAULT 0,
  vat_amount   DECIMAL(15, 2) NOT NULL DEFAULT 0,

  payload  JSON NULL COMMENT 'nguyên văn thứ đã gửi đi',
  response JSON NULL COMMENT 'nguyên văn thứ cổng trả về',
  error    VARCHAR(500) NULL,

  issued_at  DATETIME(3) NULL,
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_etax_invoices_order (order_id),
  KEY idx_etax_invoices_shop (tenant_id, shop_id),
  CONSTRAINT fk_etax_invoices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_etax_invoices_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
  CONSTRAINT fk_etax_invoices_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_etax_invoices_conn FOREIGN KEY (connection_id) REFERENCES etax_connections (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
