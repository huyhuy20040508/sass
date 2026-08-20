-- =====================================================================
--  0030_quy-doi-don-vi-2026-08-20.sql
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
--  QUY ĐỔI ĐƠN VỊ HÀNG HOÁ
--
--  Bản cũ có khối "Quy đổi đơn vị hàng hoá" ở chân hộp thoại mặt hàng:
--  khai "1 Thùng = 24 Cái" để nhập hàng theo thùng mà bán theo cái, kho
--  vẫn đếm đúng một loại đơn vị.
--
--  Lưu JSON ngay trên dòng mặt hàng, KHÔNG dựng bảng riêng — giống bản cũ
--  (odr_menus.menu_unit_convert). Đây là vài dòng đi kèm mặt hàng, luôn
--  đọc và ghi trọn cụm theo mặt hàng, không có ai truy vấn xuyên qua
--  chúng; tách bảng chỉ thêm một lượt JOIN cho mọi màn hình.
--
--  Dạng: [{"unit_id": 7, "quantity": 24}, ...]
--  Đọc là: 1 <đơn vị 7> = 24 <đơn vị tính chính của mặt hàng>.
--  NULL hoặc [] = mặt hàng không khai quy đổi nào.
-- ---------------------------------------------------------------------
ALTER TABLE products
  ADD COLUMN unit_conversions JSON NULL
    COMMENT 'quy đổi đơn vị: [{unit_id, quantity}] — 1 đơn vị quy đổi = quantity đơn vị tính chính'
    AFTER unit_id;
