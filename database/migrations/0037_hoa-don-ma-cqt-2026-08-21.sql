-- =====================================================================
--  0037_hoa-don-ma-cqt-2026-08-21.sql
--  Ngày: 21/08/2026
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
--  MÃ CƠ QUAN THUẾ, MÃ TRA CỨU VÀ SỔ ĐỜI CỦA MỘT TỜ HOÁ ĐƠN
--
--  VÌ SAO CẦN THÊM: cổng trả "đã gửi" ngay khi ký xong, nhưng "đã gửi"
--  KHÔNG phải "cơ quan thuế đã cấp mã". Tờ hoá đơn chỉ có hiệu lực khi
--  có `macqt`, và mã ấy chỉ xuất hiện ở lượt tra lại sau đó. Báo "đã
--  phát hành" khi mới gửi là nói với người bán một điều chưa đúng.
--
--    tax_auth_code  — macqt, mã 23 ký tự cơ quan thuế cấp.
--    lookup_code    — sobaomat, mã tra cứu IN LÊN BILL để khách tự tra.
--    gateway_status — trang_thai bên cổng: 1 chờ ký, 2 đã ký, 3 đã gửi,
--                     4 thành công, 5 có lỗi. Giữ số nguyên bản để còn
--                     đối chiếu khi họ đổi cách hiểu.
--    doc_status     — trang_thai_hd: 0 gốc, 1 huỷ, 2 điều chỉnh,
--                     3 thay thế, 5 bị điều chỉnh, 6 bị thay thế.
--
--  `status` giờ có BỐN giá trị. Thêm 'sent' vào giữa draft và issued:
--    draft  — đã lưu bên cổng, CHƯA ký. Chưa có giá trị pháp lý.
--    sent   — đã ký và gửi, nhưng cơ quan thuế CHƯA cấp mã. Chưa in cho
--             khách được, và cũng chưa được coi là xong.
--    issued — đã cấp mã (có tax_auth_code). Đây mới là phát hành xong.
--    failed — cổng từ chối. Giữ lại để tra `error`.
--
--  `history` giữ ĐỜI TRƯỚC của tờ hoá đơn. Một đơn hàng vẫn đúng MỘT
--  dòng ở bảng này (uq_etax_invoices_order còn nguyên), nhưng thay thế
--  và điều chỉnh sinh ra một tờ MỚI bên cổng đè lên chỗ của tờ cũ. Số
--  hoá đơn cũ không được phép biến mất: nó đã nằm trong sổ của cơ quan
--  thuế và khách vẫn giữ bản in của nó.
-- ---------------------------------------------------------------------

-- MySQL 8 KHÔNG có `ADD COLUMN IF NOT EXISTS` (chỉ MariaDB có), nên tệp này
-- chạy được đúng một lần — xem chú thích ở 0002.

ALTER TABLE etax_invoices
  ADD COLUMN tax_auth_code VARCHAR(30) NULL
    COMMENT 'macqt — mã cơ quan thuế cấp; rỗng là CHƯA được cấp' AFTER invoice_id,
  ADD COLUMN lookup_code VARCHAR(20) NULL
    COMMENT 'sobaomat — mã tra cứu in lên bill' AFTER tax_auth_code,
  ADD COLUMN gateway_status TINYINT NULL
    COMMENT 'trang_thai bên cổng: 1 chờ ký, 3 đã gửi, 4 thành công, 5 lỗi' AFTER lookup_code,
  ADD COLUMN doc_status TINYINT NULL
    COMMENT 'trang_thai_hd: 0 gốc, 2 điều chỉnh, 3 thay thế, 6 bị thay thế' AFTER gateway_status,
  ADD COLUMN history JSON NULL
    COMMENT 'các tờ đời trước, sinh ra khi thay thế / điều chỉnh' AFTER response,
  MODIFY COLUMN status VARCHAR(10) NOT NULL DEFAULT 'draft'
    COMMENT 'draft | sent | issued | failed',
  -- Tra một hoá đơn theo mã cơ quan thuế: khách cầm bill tới hỏi thì đây là
  -- thứ duy nhất họ đọc ra được.
  ADD KEY idx_etax_invoices_macqt (tax_auth_code);
