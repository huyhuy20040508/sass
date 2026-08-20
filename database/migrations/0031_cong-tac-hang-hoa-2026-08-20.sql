-- =====================================================================
--  0031_cong-tac-hang-hoa-2026-08-20.sql
--  Ngày: 20/08/2026
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
--  BA CÔNG TẮC Ở CỘT TRÁI HỘP THOẠI MẶT HÀNG
--
--  Bản cũ có bốn công tắc: Trạng thái · In tem · Trừ kho · Số seri/IMEI.
--  Trạng thái đã có sẵn (cột status), ba cái còn lại thêm ở đây.
--
--  Mặc định lấy đúng bản cũ: In tem và Trừ kho BẬT, Số seri/IMEI TẮT.
--
--  Nói rõ hôm nay cái nào chạy thật:
--    - is_stock_deducted: CÓ HIỆU LỰC ngay. Tắt thì lượt bán không trừ
--      tồn kho của mặt hàng — dành cho hàng dịch vụ, hàng đặt gia công.
--    - print_label, is_serial: mới là CHỖ CẤT giá trị. Phần mềm chưa có
--      chức năng in tem và chưa có bảng serial nào, nên hai cờ này chưa
--      chi phối gì. Ghi ra đây để sau này không ai tưởng chúng đang chạy.
-- ---------------------------------------------------------------------
ALTER TABLE products
  ADD COLUMN print_label TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'có in tem cho mặt hàng này không (chưa có chức năng in tem, mới là chỗ cất giá trị)'
    AFTER is_multi_variant,
  ADD COLUMN is_stock_deducted TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'bán ra có trừ tồn kho không; tắt = hàng dịch vụ, không theo dõi kho'
    AFTER print_label,
  ADD COLUMN is_serial TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'quản lý theo số seri/IMEI (chưa có bảng serial, mới là chỗ cất giá trị)'
    AFTER is_stock_deducted;
