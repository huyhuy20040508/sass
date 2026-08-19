-- =====================================================================
--  0019_bo-dem-ma-2026-08-19.sql
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
--  BỘ ĐẾM MÃ — số thứ tự đã cấp cho từng quy tắc đánh số (0018).
--
--  Vì sao cần một bảng riêng thay vì đếm bản ghi hay lấy MAX(mã) như bản
--  ERP cũ: cả hai cách kia đều là ĐỌC RỒI MỚI GHI, không khoá gì ở giữa.
--  Hai người cùng lập phiếu trong một giây là cùng đọc ra một số, và bảng
--  chứng từ nào không có khoá duy nhất trên cột mã thì hai phiếu mang
--  cùng một số — im lặng. Ở đây mỗi lượt cấp số khoá đúng một hàng
--  (SELECT ... FOR UPDATE), nên người thứ hai chờ người thứ nhất xong.
-- =====================================================================

CREATE TABLE IF NOT EXISTS code_counters (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  -- Cùng nghĩa với code_rules.shop_id: 0 = bộ đếm dùng chung toàn cửa hàng.
  shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  doc_type  VARCHAR(40) NOT NULL,

  -- bucket rỗng = đếm liên tục. Kiểu đánh số theo ngày/tháng thì mỗi mốc
  -- một bộ đếm riêng (ddmmyyyy / mmyy), vì số phải quay về 1 mỗi mốc.
  bucket    VARCHAR(8) NOT NULL DEFAULT '',

  -- last_seq là số VỪA CẤP, không phải số sắp cấp: đọc bảng ra là biết
  -- ngay mã cuối cùng đã phát, khỏi trừ một trong đầu.
  last_seq  BIGINT UNSIGNED NOT NULL DEFAULT 0,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_code_counters (tenant_id, shop_id, doc_type, bucket),
  CONSTRAINT fk_code_counters_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
