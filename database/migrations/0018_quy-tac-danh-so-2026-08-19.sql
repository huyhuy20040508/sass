-- =====================================================================
--  0018_quy-tac-danh-so-2026-08-19.sql
--  Ngày: 19/08/2026
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
--  QUY TẮC ĐÁNH SỐ CHỨNG TỪ
--
--  Cửa hàng tự đặt mã cho từng loại chứng từ / danh mục: tiền tố, phần
--  giá trị, độ dài, hậu tố. Có hàng đang bật = phần mềm tự sinh mã, ô mã
--  ở màn nhập khoá lại; không có hàng = người dùng tự gõ như hiện nay.
--
--  Danh sách loại nằm trong mã nguồn Go (domain.DanhMucLoaiMa), không có
--  bảng danh mục dưới đây — cùng lý do với danh mục quyền ở 0012.
-- =====================================================================

CREATE TABLE IF NOT EXISTS code_rules (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  -- shop_id = 0: quy tắc DÙNG CHUNG toàn cửa hàng (mã hàng hoá, nhà cung
  -- cấp, nhân viên — thứ mọi chi nhánh cùng tra). Khác 0: chứng từ phát
  -- sinh tại đúng chi nhánh đó. Dùng 0 chứ không dùng NULL vì khoá duy
  -- nhất của MySQL không gộp các hàng NULL lại với nhau.
  shop_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,

  doc_type   VARCHAR(40) NOT NULL COMMENT 'mã loại khai trong domain.DanhMucLoaiMa',
  prefix     VARCHAR(20) NOT NULL DEFAULT '',
  -- value_part: so-thu-tu | ngay-thang-nam | thang-nam.
  value_part VARCHAR(20) NOT NULL DEFAULT 'so-thu-tu',
  -- length là tổng số ký tự phần GIỮA (ngày + số đếm), không tính tiền tố
  -- và hậu tố.
  length     TINYINT UNSIGNED NOT NULL DEFAULT 6,
  suffix     VARCHAR(20) NOT NULL DEFAULT '',

  -- Bỏ tick ở màn hình = tắt cờ này, KHÔNG xoá hàng: tick lại là tiền tố
  -- cũ còn nguyên, khỏi khai lại.
  is_active  TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_code_rules (tenant_id, shop_id, doc_type),
  CONSTRAINT fk_code_rules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
