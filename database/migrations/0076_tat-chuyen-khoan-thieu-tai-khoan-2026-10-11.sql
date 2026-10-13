-- =====================================================================
--  0076_tat-chuyen-khoan-thieu-tai-khoan-2026-10-11.sql
--  Ngày: 11/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  Mặc định của payment_bank_enabled đổi từ "1" sang "0" (registry bên Go).
--  Trước đây cửa hàng chưa từng lưu trang Thanh toán thì đang BẬT chuyển
--  khoản mà ba ô tài khoản rỗng — trạng thái chính API từ chối lưu, và
--  storefront chào ra hình thức không có số tài khoản nào.
--
--  1. Cửa hàng ĐÃ khai đủ ngân hàng + số tài khoản + chủ tài khoản nhưng
--     chưa có dòng payment_bank_enabled (đang dựa vào mặc định "1"): ghi
--     hẳn "1" để chuyển khoản của họ không bị tắt khi mặc định đổi.
--  2. Cửa hàng lưu "1" mà thiếu một trong ba ô: đổi về "0" cho khớp luật.
--     Hình thức này vốn đã không dùng được (lượt chốt đơn từ chối), nên với
--     khách không có gì thay đổi.
-- =====================================================================

INSERT INTO settings (tenant_id, `key`, value, `group`, created_at, updated_at)
SELECT t.id, 'payment_bank_enabled', '1', 'payment', NOW(3), NOW(3)
FROM tenants t
WHERE NOT EXISTS (
        SELECT 1 FROM settings s WHERE s.tenant_id = t.id AND s.`key` = 'payment_bank_enabled'
    )
  AND (SELECT COUNT(*) FROM settings s
        WHERE s.tenant_id = t.id
          AND s.`key` IN ('bank_name', 'bank_account_number', 'bank_account_name')
          AND TRIM(COALESCE(s.value, '')) <> '') = 3;

UPDATE settings b
LEFT JOIN (
    SELECT tenant_id, COUNT(*) AS du
    FROM settings
    WHERE `key` IN ('bank_name', 'bank_account_number', 'bank_account_name')
      AND TRIM(COALESCE(value, '')) <> ''
    GROUP BY tenant_id
) tk ON tk.tenant_id = b.tenant_id
SET b.value = '0', b.updated_at = NOW(3)
WHERE b.`key` = 'payment_bank_enabled'
  AND b.value = '1'
  AND COALESCE(tk.du, 0) < 3;
