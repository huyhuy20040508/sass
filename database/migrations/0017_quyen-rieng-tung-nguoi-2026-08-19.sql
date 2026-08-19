-- =====================================================================
--  0017_quyen-rieng-tung-nguoi-2026-08-19.sql
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
--  QUYỀN GÁN THẲNG CHO TỪNG NGƯỜI
--
--  Migration 0012/0013 dựng mô hình "quyền thuộc NHÓM, người mang nhóm",
--  và ghi rõ vì sao không chép quyền xuống từng người. Tệp này ĐẢO LẠI
--  quyết định đó theo yêu cầu của chủ dự án: màn hình phân quyền phải
--  làm việc như bản ERP cũ (order v2) mà cửa hàng đang quen — chọn chi
--  nhánh, chọn nhân viên, tick thẳng từng ô Xem/Thêm/Sửa/Xoá.
--
--  Từ đây:
--    * Quyền của một người = ĐÚNG những hàng trong `user_permissions`.
--    * `permission_groups` còn nguyên nhưng chỉ là MẪU: màn hình dùng nó
--      để điền sẵn ô tick, người dùng vẫn phải bấm Lưu. Sửa một nhóm
--      KHÔNG còn lan sang ai — muốn đổi mười người thì vào mười hồ sơ.
--    * `user_permission_groups` bị bỏ hẳn: giữ lại thì hai bảng cùng trả
--      lời "người này được làm gì", và sẽ tới ngày chúng nói khác nhau.
--
--  Đánh đổi này là điều đã bàn và đã chốt, không phải sơ suất. Ai định
--  quay lại mô hình nhóm-sống thì đọc phần đầu của 0012 trước.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. QUYỀN RIÊNG — mỗi hàng là MỘT việc một người được làm.
--
--  Có hàng = có quyền. Không lưu hàng "tắt" cho ô bị bỏ tick: hai cách
--  nói cùng một chuyện là chỗ để sinh ra hai bản ghi cãi nhau. Màn hình
--  gửi lên toàn bộ danh sách sau mỗi lượt lưu, tầng nghiệp vụ xoá sạch
--  rồi ghi lại.
--
--  Chuỗi quyền KHÔNG có khoá ngoại tới đâu cả: danh mục nằm trong mã
--  nguồn Go (internal/domain/quyen.go) vì nó phải khớp từng chữ với
--  những đường mà router thật sự chặn.
--
--  Xoá CỨNG theo người (ON DELETE CASCADE): tài khoản không còn thì mấy
--  hàng này vô nghĩa.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_permissions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  permission VARCHAR(64) NOT NULL COMMENT 'chuỗi khai trong domain.DanhMucQuyen',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_user_permissions (user_id, permission),
  -- Chỉ mục của lượt đọc NÓNG NHẤT hệ thống: mỗi request vào một đường
  -- có gắn quyền đều hỏi "người này được làm những gì". Hai cột theo
  -- đúng thứ tự đó nên nó là index PHỦ — trả lời xong không chạm bảng.
  KEY idx_user_permissions_user (user_id, permission),
  CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_user_permissions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. CỜ TOÀN QUYỀN CHUYỂN TỪ NHÓM SANG NGƯỜI.
--
--  Cột này là thứ giữ cho một module ra mắt sau tự tới tay chủ tiệm —
--  xem chú thích dài ở `permission_groups.full_access` trong 0012. Bỏ
--  nhóm khỏi đường tính quyền mà không mang cờ này theo thì tháng sau,
--  module Bảo hành lên, MỌI quản trị viên gặp 403 cho tới khi có người
--  vào tick tay.
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN toan_quyen TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'mọi quyền hiện có và sẽ có; thay cho permission_groups.full_access'
    AFTER role_id;

-- ---------------------------------------------------------------------
--  3. DI TRÚ — không một ai được thêm hay mất quyền sau lượt này.
--
--  Hai câu, theo đúng thứ tự: cờ trước (người mang nhóm toàn quyền), rồi
--  trải từng chuỗi quyền của các nhóm còn lại xuống từng người.
--
--  Người mang nhóm toàn quyền KHÔNG cần hàng nào ở `user_permissions` —
--  cờ đã nói đủ, y như nhóm full_access không có hàng nào ở
--  `permission_group_items`.
-- ---------------------------------------------------------------------
UPDATE users u
   SET u.toan_quyen = 1
 WHERE EXISTS (
   SELECT 1
     FROM user_permission_groups ug
     JOIN permission_groups g ON g.id = ug.group_id
    WHERE ug.user_id = u.id
      AND g.full_access = 1
 );

INSERT IGNORE INTO user_permissions (tenant_id, user_id, permission, created_at, updated_at)
SELECT DISTINCT ug.tenant_id, ug.user_id, i.permission, NOW(3), NOW(3)
  FROM user_permission_groups ug
  JOIN permission_group_items i ON i.group_id = ug.group_id;

-- ---------------------------------------------------------------------
--  4. Bỏ bảng nối.
--
--  Sau lượt di trú trên, nó không còn trả lời câu hỏi nào. Giữ lại "cho
--  chắc" thì hai chỗ cùng nói một người được làm gì, và không ai biết
--  chỗ nào mới là thật — đúng cái bẫy mà 0013 đã tránh khi bỏ cột
--  `users.permission_group_id`.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS user_permission_groups;
