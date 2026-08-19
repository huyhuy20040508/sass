-- =====================================================================
--  0020_thue-suat-2026-08-19.sql
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
--  THUẾ SUẤT (Hàng hóa → Thuế)
--
--  Bốn loại thuế cố định, mỗi loại giữ danh sách mức được phép chọn ở
--  màn nghiệp vụ tương ứng (bán hàng, mua hàng, tiêu thụ đặc biệt, mặc
--  định). Cửa hàng KHÔNG thêm/xoá loại — chỉ tick những mức cho hiện ra.
--
--  Danh sách loại nằm trong mã nguồn Go (domain.DanhMucLoaiThue), không
--  có bảng danh mục dưới đây — cùng lý do với danh mục quyền ở 0012.
--
--  Tra cứu bằng CỘT tax_type chứ không bằng id: bản cũ neo `find(3)` vào
--  id tự tăng, sang nhiều cửa hàng thì id 3 của tiệm này là thuế của
--  tiệm khác.
-- =====================================================================

CREATE TABLE IF NOT EXISTS taxes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  tax_type   VARCHAR(40) NOT NULL COMMENT 'mã loại khai trong domain.DanhMucLoaiThue',

  -- rates: JSON mảng SỐ NGUYÊN các mức đang bật, ví dụ [0,5,8,10].
  -- Hai số âm không phải phần trăm: -1 là Không chịu thuế (KCT), -2 là
  -- Không kê khai nộp thuế (KKKNT). Giữ nguyên số âm chứ không quy về 0
  -- vì đây chính là mã hoá đơn điện tử nhận — quy về 0 là mất phân biệt
  -- giữa "thuế suất 0%" và "không chịu thuế".
  rates      TEXT NOT NULL,

  -- Tắt = màn nghiệp vụ không cho chọn thuế nữa (đơn mua hàng rơi về 0%,
  -- màn thu ngân ẩn dòng thuế). Bỏ tick chứ không xoá hàng.
  is_active  TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_taxes (tenant_id, tax_type),
  CONSTRAINT fk_taxes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
