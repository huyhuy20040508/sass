-- =====================================================================
--  0079_hddt-sepay-2026-10-12.sql
--  Ngày: 12/10/2026
-- =====================================================================
--  HOÁ ĐƠN ĐIỆN TỬ QUA SEPAY — nhà cung cấp thứ hai của etax_connections.
--
--  Một tài khoản SePay có thể nối nhiều "tài khoản nhà cung cấp" (Matbao…),
--  và mỗi hoá đơn phải nói rõ phát hành trên cái nào (`provider_account_id`,
--  một UUID). Cột ma_dvcs của M-Invoice chỉ 20 ký tự nên không chứa nổi —
--  thêm cột riêng. M-Invoice để NULL.
--
--  username / password_enc của dòng SePay là client ID / client secret
--  (secret vẫn mã hoá bằng ETAX_SECRET_KEY như mật khẩu M-Invoice).
-- ---------------------------------------------------------------------

ALTER TABLE etax_connections
    ADD COLUMN account_ref VARCHAR(64) NULL COMMENT 'SePay: provider_account_id' AFTER ma_dvcs;
