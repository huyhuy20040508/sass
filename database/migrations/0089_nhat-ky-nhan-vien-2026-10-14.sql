-- =====================================================================
--  0089_nhat-ky-nhan-vien-2026-10-14.sql
--  Ngày: 14/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  NHẬT KÝ NHÂN VIÊN — như hrm_employee_histories của ordertable v2 (cùng tên
--  sự kiện shift_open / shift_close), nhưng tiền nằm ở CỘT chứ không nhét vào
--  câu chữ: tab Nhật ký phải đếm được "trực mấy ca, lệch mấy lần" bằng SQL.
--
--  employee_id = người TRỰC KÉT của ca (work_shifts.employee_id); ca chưa có
--  người trực thì lấy hồ sơ nhân sự của tài khoản đã bấm. user_id = tài khoản
--  bấm nút (người mở máy / người đóng), có thể khác người trực.
--  happened_at = giờ của sự kiện (ca mở bù thì là giờ mở đã khai), created_at
--  = lúc ghi dòng.
--
--  Chỉ ghi thêm, không sửa / xoá: đây là nhật ký.
-- =====================================================================

CREATE TABLE IF NOT EXISTS employee_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  shop_id     BIGINT UNSIGNED NULL COMMENT 'chi nhánh phát sinh',
  employee_id BIGINT UNSIGNED NULL COMMENT 'employees.id — nhật ký của ai',
  user_id     BIGINT UNSIGNED NULL COMMENT 'users.id — tài khoản đã thao tác',
  event       VARCHAR(30) NOT NULL COMMENT 'shift_open | shift_close',
  shift_id    BIGINT UNSIGNED NULL,
  amount      DECIMAL(15,2) NULL COMMENT 'shift_open: tiền đầu ca; shift_close: tiền đếm được',
  difference  DECIMAL(15,2) NULL COMMENT 'shift_close: đếm được − theo sổ',
  note        VARCHAR(500) NOT NULL DEFAULT '',
  happened_at DATETIME(3) NOT NULL,
  created_at  DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_employee_logs_employee (tenant_id, employee_id, happened_at),
  KEY idx_employee_logs_shift (shift_id),
  CONSTRAINT fk_employee_logs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_employee_logs_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE SET NULL,
  CONSTRAINT fk_employee_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_employee_logs_shift FOREIGN KEY (shift_id) REFERENCES work_shifts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ghi bù cho các ca đã có, để tab Nhật ký không trống với những ca trước hôm nay.
-- NOT EXISTS theo (shift_id, event): chạy lại không ghi trùng.
INSERT INTO employee_logs (tenant_id, shop_id, employee_id, user_id, event, shift_id, amount, difference, note, happened_at, created_at)
SELECT ws.tenant_id, ws.shop_id,
       COALESCE(ws.employee_id, (SELECT MIN(e.id) FROM employees e
                                  WHERE e.tenant_id = ws.tenant_id AND e.user_id = ws.opened_by AND e.deleted_at IS NULL)),
       ws.opened_by, 'shift_open', ws.id, ws.opening_cash, NULL, COALESCE(ws.note, ''), ws.opened_at, NOW(3)
FROM work_shifts ws
WHERE NOT EXISTS (SELECT 1 FROM employee_logs l WHERE l.shift_id = ws.id AND l.event = 'shift_open');

INSERT INTO employee_logs (tenant_id, shop_id, employee_id, user_id, event, shift_id, amount, difference, note, happened_at, created_at)
SELECT ws.tenant_id, ws.shop_id,
       COALESCE(ws.employee_id, (SELECT MIN(e.id) FROM employees e
                                  WHERE e.tenant_id = ws.tenant_id AND e.user_id = ws.opened_by AND e.deleted_at IS NULL)),
       ws.closed_by, 'shift_close', ws.id, ws.counted_cash, ws.difference, COALESCE(ws.close_note, ''), ws.closed_at, NOW(3)
FROM work_shifts ws
WHERE ws.closed_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM employee_logs l WHERE l.shift_id = ws.id AND l.event = 'shift_close');
