-- =====================================================================
--  0004_nhan-vai-tro-2026-08-12.sql
--  Ngày: 12/08/2026
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
--  TÊN HIỂN THỊ CỦA VAI TRÒ TÁCH RA THEO TỪNG CỬA HÀNG
--
--  Bảng `roles` là bảng DÙNG CHUNG cho mọi khách hàng, và phải như vậy:
--  bốn vai trò mang id cố định (1 super_admin, 2 admin, 3 staff,
--  4 customer) mà tầng Go tham chiếu thẳng bằng hằng số
--  domain.AdminRoleID = 2... Cắt bảng đó theo tenant thì mỗi khách mới lại
--  phải sinh bốn dòng với id không đoán trước được, và bộ hằng số kia sai
--  ngay từ khách thứ hai — xem chú thích globalTables trong
--  api/internal/repository/tenant_scope.go.
--
--  Nhưng route PUT /admin/roles/{id} thì mở cho quản trị viên của TỪNG cửa
--  hàng, và nó ghi thẳng vào display_name/description của bảng dùng chung.
--  Hệ quả: cửa hàng A đổi "Nhân viên" thành "Thu ngân" là cửa hàng B mở
--  trang lên thấy nhân viên của mình đổi tên. Không có thông báo nào, không
--  ai hiểu vì sao, và B không có cách nào đổi lại mà không đổi luôn cho A.
--
--  Cách chữa: GIỮ NGUYÊN bảng roles làm bộ mặc định của nhà máy, và cho mỗi
--  cửa hàng một dòng NHÃN đè lên nếu họ muốn đặt tên khác. Từ đây bảng
--  roles không còn bị ghi trong luồng phục vụ request nữa.
--
--  VÌ SAO CHÉP SẴN NHÃN CHO MỌI CỬA HÀNG ĐANG CÓ, thay vì để trống rồi rơi
--  về mặc định: display_name hiện tại có thể ĐÃ bị một cửa hàng nào đó sửa,
--  và không có cách nào biết là cửa hàng nào. Để trống thì cái tên đó biến
--  mất khỏi màn hình của mọi người ngay lần triển khai này — một thay đổi
--  không ai yêu cầu. Chép lại thì hôm nay không ai thấy gì khác, và từ mai
--  mỗi bên tự đi đường của mình.
-- =====================================================================

CREATE TABLE IF NOT EXISTS role_labels (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  role_id      BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(100) NOT NULL COMMENT 'tên cửa hàng này gọi vai trò đó, vd: Thu ngân',
  description  VARCHAR(255) NOT NULL DEFAULT '',
  created_at   DATETIME(3) NULL,
  updated_at   DATETIME(3) NULL,
  PRIMARY KEY (id),
  -- Mỗi cửa hàng tối đa MỘT nhãn cho một vai trò. Ghi bằng upsert dựa trên
  -- đúng khoá này, nên không có đường nào sinh ra hai nhãn chọi nhau.
  UNIQUE KEY uq_role_labels (tenant_id, role_id),
  CONSTRAINT fk_role_labels_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_role_labels_role   FOREIGN KEY (role_id)   REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tên hiển thị vai trò theo TỪNG cửa hàng. roles giữ bộ mặc định dùng chung.';

-- Không có DEFAULT cho tenant_id, cùng lý lẽ với migration 0003: câu INSERT
-- quên cột đó phải hỏng ngay chứ không được rơi vào cửa hàng số 1.

-- Chụp lại nguyên trạng hôm nay cho mọi cửa hàng đang có.
--
-- INSERT IGNORE để chạy lại tệp không nhân đôi dữ liệu (khoá unique ở trên
-- chặn, IGNORE nuốt lỗi đó). Cửa hàng tạo SAU tệp này không có nhãn và sẽ
-- rơi về mặc định của roles — đúng ý: khách mới nhìn thấy tên nhà máy.
INSERT IGNORE INTO role_labels (tenant_id, role_id, display_name, description, created_at, updated_at)
SELECT t.id, r.id, r.display_name, COALESCE(r.description, ''), NOW(3), NOW(3)
  FROM tenants t
 CROSS JOIN roles r;

-- =====================================================================
--  KẾT THÚC — 1 bảng mới, nhãn đã chụp cho mọi cửa hàng đang có.
--  Từ đây `roles` chỉ còn được ĐỌC trong luồng phục vụ request.
-- =====================================================================
