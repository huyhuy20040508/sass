-- =====================================================================
--  0091_gan-tien-ngoai-ca-2026-10-15.sql
--  Ngày: 15/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  GẮN TIỀN MẶT "NGOÀI CA" VÀO CA ĐANG MỞ.
--
--  Dòng sổ quỹ phát sinh lúc chưa ai mở ca (shift_id NULL) nay gắn được vào ca
--  đang mở để két khớp. attached_by / attached_at ghi ai gắn, lúc nào — dòng có
--  hai cột này là dòng ĐƯỢC GẮN SAU, không phải ghi trong ca từ đầu.
--
--  Quyền mới `so-quy.sua`: ai đang được sửa cấu hình cửa hàng thì nhận luôn
--  (như 0077, 0085). Thu ngân mặc định không có — gắn là sửa số đối chiếu của két.
-- =====================================================================

ALTER TABLE cash_entries
  ADD COLUMN attached_by BIGINT UNSIGNED NULL COMMENT 'users.id — người gắn dòng ngoài ca vào ca' AFTER shift_id,
  ADD COLUMN attached_at DATETIME(3) NULL COMMENT 'lúc gắn' AFTER attached_by,
  ADD CONSTRAINT fk_cash_entries_attached_by FOREIGN KEY (attached_by) REFERENCES users (id) ON DELETE SET NULL;

INSERT IGNORE INTO user_permissions (tenant_id, user_id, permission, created_at, updated_at)
SELECT p.tenant_id, p.user_id, 'so-quy.sua', NOW(3), NOW(3)
FROM user_permissions p
WHERE p.permission = 'cau-hinh.sua';
