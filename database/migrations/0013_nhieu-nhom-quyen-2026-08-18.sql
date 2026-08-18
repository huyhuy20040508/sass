-- =====================================================================
--  0013_nhieu-nhom-quyen-2026-08-18.sql
--  Ngày: 18/08/2026
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
--  MỘT NGƯỜI MANG NHIỀU NHÓM QUYỀN
--
--  Migration 0012 gắn nhóm quyền vào `users` bằng MỘT cột, tức một người
--  đúng một nhóm. Tệp này đổi thành nhiều-nhiều.
--
--  Vì sao đổi ngay sau đó: bản ERP mà cửa hàng đang dùng (order v2) cho
--  chọn NHIỀU loại tài khoản cho một người — ô `account_type` của form
--  nhân viên bên đó là một mảng (`required|array`, giá trị admin /
--  cashier / order / kitchen). Đó là chuyện có thật ở tiệm: người quản
--  lý ca tối vẫn đứng quầy, kế toán vẫn phải vào được kho.
--
--  Với một cột, cách duy nhất để diễn đạt "vừa quản lý vừa thu ngân" là
--  đẻ ra một nhóm thứ ba tên "quản lý kiêm thu ngân" — rồi nhóm thứ tư,
--  thứ năm cho mọi tổ hợp. Bảng nối bên dưới cắt cái vòng đó.
--
--  QUYỀN CỦA MỘT NGƯỜI = HỢP quyền của mọi nhóm họ mang. Không có nhóm
--  nào "trừ" quyền của nhóm khác: một hệ vừa cộng vừa trừ thì không ai
--  đọc màn hình mà đoán được kết quả.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. Bảng nối
--
--  Xoá CỨNG theo người (ON DELETE CASCADE): tài khoản không còn thì mấy
--  dòng này vô nghĩa. Nhưng theo NHÓM thì RESTRICT — xoá một nhóm còn
--  người mang phải hỏng ngay ở tầng database để tầng nghiệp vụ nói được
--  "còn 3 người đang dùng nhóm này", thay vì lặng lẽ thu quyền của họ.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_permission_groups (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  group_id   BIGINT UNSIGNED NOT NULL,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_user_permission_groups (user_id, group_id),
  -- Chỉ mục của lượt đọc NÓNG NHẤT: mỗi request vào một đường có gắn
  -- quyền đều hỏi "người này mang những nhóm nào".
  KEY idx_user_permission_groups_user (tenant_id, user_id),
  KEY idx_user_permission_groups_group (group_id),
  CONSTRAINT fk_user_permission_groups_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_user_permission_groups_group FOREIGN KEY (group_id)
    REFERENCES permission_groups (id),
  CONSTRAINT fk_user_permission_groups_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Chuyển dữ liệu của 0012 sang bảng mới.
--
--  INSERT IGNORE để tệp chạy lại được. Ở máy thật cột kia gần như chắc
--  chắn còn rỗng (0012 mới lên cùng đợt này), nhưng viết đủ vẫn hơn —
--  một lượt di trú làm mất phân quyền của cả cửa hàng thì không có cách
--  nào dựng lại từ đầu.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO user_permission_groups (tenant_id, user_id, group_id, created_at, updated_at)
SELECT u.tenant_id, u.id, u.permission_group_id, NOW(3), NOW(3)
  FROM users u
 WHERE u.permission_group_id IS NOT NULL;

-- ---------------------------------------------------------------------
--  3. Bỏ cột cũ.
--
--  Phải gỡ khoá ngoại trước khi gỡ cột. Không giữ lại cột "cho chắc":
--  hai chỗ cùng nói một người thuộc nhóm nào thì sẽ có ngày chúng nói
--  khác nhau, và không ai biết chỗ nào mới là thật.
-- ---------------------------------------------------------------------
ALTER TABLE users DROP FOREIGN KEY fk_users_permission_group;
ALTER TABLE users DROP COLUMN permission_group_id;
