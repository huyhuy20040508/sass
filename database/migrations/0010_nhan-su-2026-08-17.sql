-- =====================================================================
--  0010_nhan-su-2026-08-17.sql
--  Ngày: 17/08/2026
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
--  HỒ SƠ NHÂN SỰ
--
--  Tới giờ, "người của cửa hàng" chỉ tồn tại dưới dạng TÀI KHOẢN trong
--  bảng `users`: có tên đăng nhập, mật khẩu và vai trò, hết. Nhưng chủ
--  tiệm không nghĩ theo tài khoản — họ nghĩ theo NGƯỜI: ai vào làm ngày
--  nào, đang thử việc hay đã ký chính thức, lương bao nhiêu, đứng ở chi
--  nhánh nào, và ai đã nghỉ.
--
--  Bảng này giữ đúng phần đó. Nó KHÔNG thay thế `users` mà đứng cạnh:
--  một nhân viên có thể không có tài khoản nào (bảo vệ, kho, giao hàng —
--  họ không đụng vào phần mềm), và ngược lại tài khoản super admin của
--  cửa hàng có thể không gắn hồ sơ nhân sự nào.
-- =====================================================================

-- ---------------------------------------------------------------------
--  Vì sao dùng bảng riêng chứ không nhét thêm cột vào `users`:
--
--  1. `users` còn chứa KHÁCH HÀNG (role_id = 4). Thêm cột lương và số
--     căn cước vào đó là gắn hồ sơ nhân sự lên đầu vài nghìn người mua
--     hàng, và mọi câu SELECT của khách hàng kéo theo cột lương.
--  2. Nhân viên không có tài khoản thì không có dòng nào trong `users`
--     để nhét — mà đây lại là phần đông người của một cửa hàng nhỏ.
--  3. Tài khoản là thứ CẤP RỒI THU HỒI (nghỉ việc là khoá ngay), còn hồ
--     sơ nhân sự phải giữ lại để tra lương và hợp đồng cũ. Hai vòng đời
--     khác nhau thì không ở chung một hàng.
--
--  Quan hệ với `users` là 1–1 và KHÔNG bắt buộc: `user_id` NULL được, và
--  có UNIQUE để một tài khoản không bị hai hồ sơ cùng nhận.
--
--  `shop_id` cũng NULL được: cửa hàng một điểm bán thì câu hỏi "làm ở
--  chi nhánh nào" chưa có nghĩa, ép chọn chỉ tổ bịa dữ liệu.
--
--  Xoá MỀM (`deleted_at`) như mọi bảng khác: hồ sơ của người đã nghỉ vẫn
--  phải tra lại được khi đối chiếu lương cũ. Muốn người đó biến khỏi
--  danh sách thường ngày thì đặt status = 'da_nghi', đó mới là cách
--  đúng — xoá là dành cho hồ sơ nhập nhầm.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  shop_id         BIGINT UNSIGNED NULL COMMENT 'chi nhánh đang làm; NULL = chưa gán',
  user_id         BIGINT UNSIGNED NULL COMMENT 'tài khoản đăng nhập, NULL = người này không dùng phần mềm',

  -- Mã nhân viên: chuỗi người ta gọi nhau trong bảng lương và bảng chấm
  -- công. Hệ thống tự đặt NV0001, NV0002… khi bỏ trống. Duy nhất trong
  -- MỘT cửa hàng, không phải toàn hệ thống.
  code            VARCHAR(30) NOT NULL,
  full_name       VARCHAR(150) NOT NULL,
  gender          ENUM('nam','nu','khac') NULL,
  birth_date      DATE NULL,
  phone           VARCHAR(20) NULL,
  email           VARCHAR(191) NULL,
  id_number       VARCHAR(20) NULL COMMENT 'số căn cước',
  address         VARCHAR(255) NULL,

  -- position = VIỆC người đó làm, KHÁC hẳn vai trò của tài khoản (quyền
  -- trên phần mềm, nằm ở users.role_id). Một người có chức danh "Quản lý
  -- cửa hàng" vẫn có thể chỉ được cấp quyền thu ngân, và ngược lại.
  position        ENUM('quan_ly','thu_ngan','ban_hang','thu_kho','ke_toan','giao_hang','khac')
                    NOT NULL DEFAULT 'ban_hang' COMMENT 'chức danh công việc',
  hired_on        DATE NULL COMMENT 'ngày vào làm',
  contract_type   ENUM('thu_viec','chinh_thuc','thoi_vu','cong_tac_vien') NULL,
  -- 'tam_nghi' tách khỏi 'da_nghi' có chủ ý: nghỉ thai sản hay nghỉ dài
  -- ngày thì người đó vẫn thuộc cửa hàng và sẽ quay lại, khác hẳn người
  -- đã chấm dứt hợp đồng.
  status          ENUM('dang_lam','tam_nghi','da_nghi') NOT NULL DEFAULT 'dang_lam',

  salary_type     ENUM('thang','ca','gio') NULL COMMENT 'hình thức trả lương',
  salary          DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'mức lương theo hình thức trên',
  allowance       DECIMAL(12,2) NOT NULL DEFAULT 0,
  -- Hoa hồng theo % doanh số. DECIMAL(5,2) đủ cho 0–100 với hai số lẻ;
  -- trần thật do tầng nghiệp vụ chặn, ở đây chỉ định hình kiểu dữ liệu.
  commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0,

  note            VARCHAR(500) NULL,
  created_at      DATETIME(3) NULL,
  updated_at      DATETIME(3) NULL,
  deleted_at      DATETIME(3) NULL,

  PRIMARY KEY (id),
  -- Mã duy nhất TRONG một cửa hàng. Tính cả hồ sơ đã xoá mềm (không có
  -- điều kiện deleted_at trong khoá) — đúng như uq_shops_tenant_code, và
  -- tầng Go kiểm trùng bằng Unscoped để nói trước câu lỗi cho người dùng.
  UNIQUE KEY uq_employees_tenant_code (tenant_id, code),
  -- Một tài khoản chỉ thuộc về một hồ sơ. NULL không đụng khoá này nên
  -- vẫn có bao nhiêu hồ sơ không tài khoản cũng được.
  UNIQUE KEY uq_employees_user (user_id),
  KEY idx_employees_tenant (tenant_id),
  KEY idx_employees_status (tenant_id, status),
  KEY idx_employees_shop (shop_id),
  CONSTRAINT fk_employees_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  -- Chi nhánh bị xoá thì hồ sơ mất chỗ làm chứ không mất hồ sơ; tài khoản
  -- bị xoá thì người đó thành nhân viên không tài khoản. Cả hai đều là
  -- SET NULL vì cả hai đều KHÔNG được kéo theo hồ sơ nhân sự.
  CONSTRAINT fk_employees_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE SET NULL,
  CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
