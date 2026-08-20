-- =====================================================================
--  0029_thu-tu-hang-hoa-2026-08-20.sql
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
--  THỨ TỰ HÀNG HOÁ DO NGƯỜI BÁN TỰ XẾP
--
--  Bản cũ v2 có hai mũi tên lên/xuống ở cột Thao tác để kéo mặt hàng lên
--  trên hay xuống dưới (odr_menus.sort). Bán lẻ cần đúng thứ đó: hàng bán
--  chạy và hàng hay bị hỏi phải nằm ngay đầu danh sách, chứ không phải cứ
--  hàng thêm sau thì nằm trên.
--
--  Danh sách sắp theo sort GIẢM DẦN — số lớn nằm trên. Đổi chỗ hai mặt
--  hàng là đổi chỗ hai giá trị sort của chúng, không đánh số lại cả bảng.
-- ---------------------------------------------------------------------
ALTER TABLE products
  ADD COLUMN sort INT NOT NULL DEFAULT 0
    COMMENT 'thứ tự người bán tự xếp; số lớn nằm trên'
    AFTER sold_count;

-- Lấy id làm giá trị ban đầu để thứ tự đang thấy giữ nguyên: danh sách cũ
-- sắp theo id giảm dần, nay sắp theo sort giảm dần — cùng một dãy.
UPDATE products SET sort = id;

ALTER TABLE products
  ADD KEY idx_products_sort (sort);
