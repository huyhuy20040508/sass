-- =====================================================================
--  0012_nhom-quyen-2026-08-18.sql
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
--  PHÂN QUYỀN THEO CHỨC NĂNG
--
--  Tới giờ, quyền của một người là MỘT CỘT: `users.role_id` trỏ vào bảng
--  `roles` với bốn vai trò ghi cứng trong mã nguồn. Việc chặn nằm ở hai
--  dòng trong router — nhóm `admin` (cả ba vai trò nội bộ) và nhóm
--  `manage` (chỉ super_admin + admin).
--
--  Nghĩa là mỗi cửa hàng chỉ có HAI MỨC: hoặc thấy tất cả, hoặc chỉ thấy
--  quầy bán. Không có cách nào giao việc coi kho cho một thu ngân lâu
--  năm mà không mở luôn cho họ lương của đồng nghiệp, số căn cước, giá
--  vốn từng mặt hàng và toàn bộ báo cáo doanh thu.
--
--  Hai bảng và một cột dưới đây thay hai mức đó bằng NHÓM QUYỀN do chính
--  cửa hàng đặt tên, mỗi nhóm là một tập quyền tick theo từng chức năng.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. NHÓM QUYỀN — "Thu ngân", "Quản lý ca tối", "Thủ kho"…
--
--  Của TỪNG CỬA HÀNG (`tenant_id`), khác hẳn bảng `roles` vốn dùng chung
--  cả nền tảng và không có cột tenant. Đó là điểm khác cốt lõi: `roles`
--  trả lời "anh là loại người nào" (người của tiệm hay khách mua hàng),
--  còn bảng này trả lời "anh bấm được nút nào" — và câu thứ hai thì hai
--  tiệm cạnh nhau trả lời khác nhau.
--
--  KHÔNG xoá mềm, có chủ ý. Xoá mềm làm khoá ngoại từ `users` mất tác
--  dụng: hàng vẫn nằm đó nên MySQL không chặn, và mười hai người lặng lẽ
--  trỏ vào một nhóm "đã xoá". Xoá cứng + RESTRICT thì lượt xoá một nhóm
--  đang có người dùng hỏng ngay ở tầng database, và tầng nghiệp vụ dịch
--  nó thành câu tiếng Việt nói rõ còn mấy người đang dùng.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permission_groups (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,

  code        VARCHAR(50) NOT NULL COMMENT 'quan-ly | thu-ngan | do cửa hàng tự đặt',
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',

  -- is_system = 1: hai nhóm hệ thống dựng sẵn. Sửa được tên và quyền,
  -- KHÔNG xoá được — xoá nhóm cuối cùng còn quyền quản lý là tự khoá
  -- mình ra ngoài chính cửa hàng của mình.
  is_system   TINYINT(1) NOT NULL DEFAULT 0,

  -- full_access = 1: MỌI quyền HIỆN CÓ VÀ SẼ CÓ. Đây là cột quan trọng
  -- nhất của cả lược đồ, và lý do nó tồn tại đáng đọc kỹ:
  --
  -- Hôm nay, thêm một trang mới vào khu quản trị là quản trị viên có
  -- ngay, không ai phải làm gì. Nếu nhóm "Quản lý" được nạp sẵn một danh
  -- sách quyền liệt kê từng dòng, thì tháng sau khi module Bảo hành lên,
  -- MỌI cửa hàng trên hệ thống có một trang hiện trong menu nhưng trả
  -- 403 — cho tới khi từng chủ tiệm tự vào tick, mà họ không biết là
  -- phải tick vì chẳng có gì báo.
  --
  -- Cửa hàng nào muốn BỚT một quyền của nhóm Quản lý thì tầng nghiệp vụ
  -- trải danh mục thành từng dòng ngay lúc đó rồi tắt cờ này. Từ đó nhóm
  -- ấy là nhóm thường và không tự nhận quyền mới nữa — đúng ý người vừa
  -- bấm.
  full_access TINYINT(1) NOT NULL DEFAULT 0,

  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_permission_groups_code (tenant_id, code),
  KEY idx_permission_groups_tenant (tenant_id),
  CONSTRAINT fk_permission_groups_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. QUYỀN TRONG MỘT NHÓM — mỗi hàng là MỘT quyền được tick.
--
--  Có hàng = có quyền. Không lưu hàng "tắt" cho quyền bị bỏ tick: hai
--  cách nói cùng một chuyện là chỗ để sinh ra hai bản ghi cãi nhau.
--
--  Chuỗi quyền KHÔNG có khoá ngoại tới đâu cả: danh mục quyền nằm trong
--  mã nguồn Go (internal/domain/quyen.go), vì nó phải khớp từng chữ với
--  những đường mà router thật sự chặn. Một bảng danh mục dưới database
--  sẽ trôi khỏi mã nguồn ngay lần thêm tính năng đầu tiên.
--
--  Nhóm có full_access = 1 KHÔNG có hàng nào ở đây, và đó là đúng.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permission_group_items (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  group_id   BIGINT UNSIGNED NOT NULL,
  permission VARCHAR(64) NOT NULL COMMENT 'chuỗi khai trong domain.DanhMucQuyen',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_permission_group_items (group_id, permission),
  -- Chỉ mục của lượt đọc NÓNG NHẤT hệ thống: mỗi request vào một đường
  -- có gắn quyền đều hỏi "nhóm này gồm những quyền gì". Hai cột theo
  -- đúng thứ tự đó nên nó là index PHỦ — trả lời xong không chạm bảng.
  KEY idx_permission_group_items_group (group_id, permission),
  CONSTRAINT fk_permission_group_items_group FOREIGN KEY (group_id)
    REFERENCES permission_groups (id) ON DELETE CASCADE,
  CONSTRAINT fk_permission_group_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. NGƯỜI DÙNG THUỘC NHÓM NÀO
--
--  Một cột trên `users`, KHÔNG phải một bảng chép quyền xuống từng
--  người. Đánh đổi này đáng nói rõ vì bản ERP cũ (order v2) làm cách
--  ngược lại — nó chép từng quyền xuống bảng `sys_permissions`.
--
--  Chọn một cột vì THU QUYỀN PHẢI CÓ HIỆU LỰC NGAY. Bỏ một tick khỏi
--  nhóm là mọi người trong nhóm mất quyền ở request kế tiếp, không phải
--  "sau khi lượt chép chạy xong". Bản chép còn đẻ ra câu hỏi không màn
--  hình nào trả lời được: nhóm nói một đằng, bản chép nói một nẻo thì
--  tin cái nào.
--
--  Giá phải trả là một lượt JOIN nữa trên khoá chính ở đường nóng — rẻ
--  hơn chính lượt LEFT JOIN sang `users` vốn đã chạy ở mọi request.
--
--  NULL = khách hàng, hoặc tài khoản nội bộ chưa gán nhóm. Chưa gán
--  nghĩa là KHÔNG quyền nào, không phải mọi quyền.
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN permission_group_id BIGINT UNSIGNED NULL
    COMMENT 'nhóm quyền; NULL = chưa gán = không có quyền nào'
    AFTER role_id,
  ADD CONSTRAINT fk_users_permission_group FOREIGN KEY (permission_group_id)
    REFERENCES permission_groups (id);
