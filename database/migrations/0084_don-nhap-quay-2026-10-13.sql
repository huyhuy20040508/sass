-- =====================================================================
--  0084_don-nhap-quay-2026-10-13.sql
--  Ngày: 13/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  ĐƠN NHÁP Ở QUẦY — hoá đơn đang mở, chưa thanh toán.
--
--  Như v2 cũ: thêm món đầu tiên là có đơn (có mã đơn thật), mọi máy trong chi
--  nhánh thấy được, huỷ thì ghi lý do và giữ lại. Khác v2 ở chỗ đơn nháp KHÔNG
--  nằm trong bảng orders: mọi báo cáo, công nợ khách, tồn kho đọc bảng orders và
--  chỉ loại đơn huỷ / trả — một trạng thái mới ở đó là phải vá từng câu truy vấn,
--  sót một câu là doanh thu đội lên. Ở đây nháp nằm riêng; lúc thanh toán, đơn
--  thật được lập qua đúng luồng chốt đơn quầy, mang đúng mã của nháp, và ngày giờ
--  đơn là lúc thanh toán.
--
--  status: open (đang mở) · dang_chot (một máy đang thanh toán — chặn máy thứ hai
--  bấm chốt cùng hoá đơn) · converted (đã thành đơn, xem order_id) · cancelled.
--  Không giữ kho: kho trừ lúc thanh toán (như v2).
--
--  items / du_lieu là JSON do màn quầy gửi lên để mở lại hoá đơn ở máy khác;
--  tiền thật vẫn do lượt chốt tính lại từ giá trong database.
-- =====================================================================

CREATE TABLE IF NOT EXISTS pos_drafts (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  shop_id        BIGINT UNSIGNED NOT NULL COMMENT 'chi nhánh mở hoá đơn',
  order_code     VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'mã đơn cấp lúc mở; rỗng = chi nhánh chưa bật quy tắc mã',
  status         ENUM('open','dang_chot','converted','cancelled') NOT NULL DEFAULT 'open',
  created_by     BIGINT UNSIGNED NOT NULL,
  updated_by     BIGINT UNSIGNED NULL,
  user_id        BIGINT UNSIGNED NULL COMMENT 'khách có hồ sơ',
  customer_name  VARCHAR(100) NOT NULL DEFAULT '',
  customer_phone VARCHAR(20)  NOT NULL DEFAULT '',
  note           VARCHAR(500) NOT NULL DEFAULT '',
  item_count     INT UNSIGNED NOT NULL DEFAULT 0,
  tam_tinh       DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'tổng màn quầy đang hiện — chỉ để xem',
  items          MEDIUMTEXT NULL COMMENT 'JSON dòng hàng',
  du_lieu        MEDIUMTEXT NULL COMMENT 'JSON phần còn lại của hoá đơn trên màn quầy',
  order_id       BIGINT UNSIGNED NULL COMMENT 'đơn thật sau khi thanh toán',
  cancel_reason  VARCHAR(255) NOT NULL DEFAULT '',
  cancelled_by   BIGINT UNSIGNED NULL,
  cancelled_at   DATETIME(3) NULL,
  claimed_at     DATETIME(3) NULL COMMENT 'lúc một máy bắt đầu thanh toán',
  converted_at   DATETIME(3) NULL,
  created_at     DATETIME(3) NULL,
  updated_at     DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_pos_drafts_mo (tenant_id, shop_id, status, updated_at),
  KEY idx_pos_drafts_ngay (tenant_id, shop_id, created_at),
  KEY idx_pos_drafts_order (tenant_id, order_id),
  CONSTRAINT fk_pos_drafts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_pos_drafts_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
