-- =====================================================================
--  0063_tach-nhom-ca-nhan-doanh-nghiep-2026-09-07.sql
--  Ngày: 07/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  TÁCH NHÓM CÁ NHÂN VÀ NHÓM DOANH NGHIỆP.
--
--  0061 dựng `customer_groups` dùng CHUNG cho cả hai loại khách, nên ô
--  "Nhóm doanh nghiệp" bày ra cả "Khách vãng lai", "Khách sỉ" — mấy tên chỉ
--  có nghĩa với khách lẻ. Bản v2 giữ hai danh sách riêng: nhóm cá nhân nói
--  về mức thân thiết, nhóm doanh nghiệp nói về LĨNH VỰC kinh doanh.
--
--  Thêm cột `type` thay vì dựng bảng thứ hai: hai bên giống hệt nhau về
--  cấu trúc (tên, ghi chú, trạng thái) và cùng được `users.customer_group_id`
--  trỏ tới. Hai bảng thì phải hai khoá ngoại, hai repository, hai handler —
--  trả giá gấp đôi cho đúng một cột phân loại.
--
--  RÀNG BUỘC KHÔNG ĐẶT ĐƯỢC Ở DATABASE: "khách cá nhân chỉ trỏ vào nhóm cá
--  nhân". MySQL không có CHECK bắc qua hai bảng. Chốt chặn nằm ở tầng ứng
--  dụng — service lọc danh sách theo đúng loại trước khi bày ra ô chọn.
-- ---------------------------------------------------------------------

SET @db := DATABASE();

SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customer_groups' AND COLUMN_NAME = 'type');
SET @sql := IF(@co = 0,
  'ALTER TABLE customer_groups
     ADD COLUMN type TINYINT UNSIGNED NOT NULL DEFAULT 0
       COMMENT "0 = nhom khach ca nhan, 1 = nhom khach doanh nghiep" AFTER tenant_id',
  'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- Chỉ mục tra theo (cửa hàng, loại) — mọi lượt đọc đều cắt theo hai cột này.
SET @co := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customer_groups' AND INDEX_NAME = 'idx_cg_tenant_type');
SET @sql := IF(@co = 0,
  'ALTER TABLE customer_groups ADD KEY idx_cg_tenant_type (tenant_id, type, name(100))', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- Ba nhóm gieo ở 0061 đều là nhóm KHÁCH LẺ nên giữ nguyên type = 0 (mặc định
-- của cột đã lo). Giờ gieo thêm bộ nhóm DOANH NGHIỆP, lấy đúng danh sách bản
-- v2 đang bày: phân theo lĩnh vực kinh doanh chứ không theo mức thân thiết.
INSERT INTO customer_groups (tenant_id, type, name, status, created_at, updated_at)
SELECT t.id, 1, m.name, 1, NOW(3), NOW(3)
FROM tenants t
CROSS JOIN (
            SELECT 'Không biết lĩnh vực' AS name
  UNION ALL SELECT 'Doanh nghiệp bán lẻ'
  UNION ALL SELECT 'Doanh nghiệp bán sỉ'
  UNION ALL SELECT 'Doanh nghiệp sản xuất'
  UNION ALL SELECT 'Doanh nghiệp dịch vụ'
) AS m
LEFT JOIN customer_groups x
       ON x.tenant_id = t.id
      AND x.type      = 1
      AND x.name      = m.name
WHERE x.id IS NULL;
