-- =====================================================================
--  0057_loai-thu-chi-2026-09-06.sql
--  Ngày: 06/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  LOẠI THU CHI — bảng tra cho phiếu thu và phiếu chi (Thu chi → Loại thu chi).
--
--  Cửa hàng tự khai "Tiền điện nước", "Lương nhân viên"… rồi chọn lúc lập
--  phiếu. Port từ `cab_income_expense_types` của bản cũ v2, khác bốn chỗ:
--
--   1. Có tenant_id, và bộ lọc theo cửa hàng là bắt buộc ở tầng dưới GORM.
--      Bản cũ đóng dấu branch_id lúc GHI nhưng dòng lọc lúc ĐỌC bị comment
--      lại, nên mọi chi nhánh nhìn thấy danh sách của nhau.
--
--   2. Tên duy nhất theo (cửa hàng, loại thu/chi) và xét CẢ lúc sửa. Bản cũ
--      chỉ kiểm lúc thêm nên đổi tên trùng qua đường sửa thì lọt.
--
--   3. Bỏ cột `type_tax` (0..4 → chỉ tiêu 10b/10c/10d/10e của tờ khai). Nó
--      là móc của màn sổ sách và kết xuất thuế mà bên này chưa có; bày một
--      cột không ai đọc ra chỉ tổ phải đoán nó dùng vào việc gì. Khi nào làm
--      tờ khai thì thêm bằng một migration mới.
--
--   4. `is_default` giữ lại nhưng ĐỔI NGHĨA: chỉ đánh dấu loại do hệ thống
--      dựng và có phiếu TỰ SINH trỏ vào (bán hàng, trả hàng…), tức là loại
--      xoá đi thì phiếu cũ mất tên. Danh sách gieo bên dưới để is_default = 0
--      — chủ tiệm không dùng "Khấu hao tài sản cố định" thì xoá được, khác
--      bản cũ khoá cứng cả 11 dòng gieo sẵn.
--
--  VÌ SAO KHÔNG CÓ UNIQUE KEY TRÊN TÊN:
--  Bảng xoá mềm (deleted_at). Đưa deleted_at vào khoá duy nhất thì khoá
--  KHÔNG chặn được gì — MySQL coi mỗi NULL là một giá trị riêng, mà dòng
--  đang sống thì deleted_at luôn NULL. Còn đặt khoá trên (tenant, type,
--  name) trần thì tên của dòng ĐÃ XOÁ vẫn giữ chỗ: xoá "Tiền điện" rồi khai
--  lại đúng tên ấy là bị chặn, trong khi đó mới là việc bình thường nhất.
--  Nên chỗ chặn trùng đặt ở tầng ứng dụng và chỉ xét dòng chưa xoá, đúng
--  như `product_units.name` đang làm. Chỉ số dưới đây để TRA, không để chặn.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS income_expense_types (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  type TINYINT UNSIGNED NOT NULL COMMENT '0 = loai THU, 1 = loai CHI',
  name VARCHAR(255) NOT NULL COMMENT 'ten phan loai, vi du "Tien dien nuoc"',

  is_default TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'loai he thong dung, co phieu tu sinh tro vao: khong sua khong xoa',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),

  -- Tra danh sách của một cửa hàng theo loại, và tra trùng tên trước khi ghi.
  KEY idx_iet_tenant_type (tenant_id, type, name(100)),
  KEY idx_iet_deleted (deleted_at),

  CONSTRAINT fk_iet_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Gieo danh sách khởi điểm cho MỌI cửa hàng đang có.
--
--  Lấy đúng bộ của bản cũ v2 (màn Loại thu chi trên bản thật): một dòng thu
--  và mười một dòng chi, phủ hầu hết khoản chi của một tiệm nhỏ. Cửa hàng
--  dựng SAU tệp này được gieo ngay trong lượt tạo cửa hàng (repository
--  CuaHangMoi), nên hai đường vào cho ra cùng một danh sách.
--
--  LEFT JOIN ... IS NULL để chạy lại tệp không đẻ thêm dòng trùng — bảng cố
--  ý không có khoá duy nhất nên không dùng ON DUPLICATE KEY được.
-- ---------------------------------------------------------------------
INSERT INTO income_expense_types (tenant_id, type, name, is_default, created_at, updated_at)
SELECT t.id, m.type, m.name, 0, NOW(3), NOW(3)
FROM tenants t
CROSS JOIN (
            SELECT 0 AS type, 'Các khoản thu khác' AS name
  UNION ALL SELECT 1, 'Lương, thưởng, phụ cấp, bảo hiểm cho người lao động'
  UNION ALL SELECT 1, 'Thuê mặt bằng, thuê kho, thuê quầy, thuê thiết bị'
  UNION ALL SELECT 1, 'Điện, nước, Internet, điện thoại, viễn thông'
  UNION ALL SELECT 1, 'Phí thanh toán, phí ngân hàng, phí cổng thanh toán'
  UNION ALL SELECT 1, 'Marketing, quảng cáo, khuyến mại, chăm sóc khách hàng'
  UNION ALL SELECT 1, 'Sửa chữa, bảo dưỡng'
  UNION ALL SELECT 1, 'Phần mềm, dịch vụ thuê ngoài, tư vấn, quản trị'
  UNION ALL SELECT 1, 'Khấu hao tài sản cố định, công cụ dụng cụ'
  UNION ALL SELECT 1, 'Công tác phí, hội nghị, đào tạo'
  UNION ALL SELECT 1, 'Thuế, phí, lệ phí được phép tính vào chi phí'
  UNION ALL SELECT 1, 'Vận chuyển, giao hàng, phí đối tác giao hàng'
) AS m
LEFT JOIN income_expense_types x
       ON x.tenant_id = t.id
      AND x.type      = m.type
      AND x.name      = m.name
WHERE x.id IS NULL;
