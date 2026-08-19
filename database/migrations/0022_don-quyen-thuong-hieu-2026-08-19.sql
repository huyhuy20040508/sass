-- =====================================================================
--  0022_don-quyen-thuong-hieu-2026-08-19.sql
--  Ngày: 19/08/2026
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
--  DỌN QUYỀN CỦA MODULE THƯƠNG HIỆU ĐÃ BỎ (xem 0021)
--
--  `thuong-hieu.them/sua/xoa` không còn trong danh mục quyền của mã
--  nguồn (domain.DanhMucQuyen). Hàng đã lưu không gây hại — không đường
--  nào kiểm tới nữa — nhưng để lại thì màn phân quyền và bảng dữ liệu
--  nói hai chuyện khác nhau, và người đọc DB sau này phải đi tra một mã
--  quyền không tồn tại.
--
--  Tách khỏi 0021 vì tệp đó đã chạy: công cụ giữ vân tay từng tệp, sửa
--  nội dung là lần chạy sau báo lệch.
-- =====================================================================

DELETE FROM permission_group_items WHERE permission LIKE 'thuong-hieu.%';
DELETE FROM user_permissions       WHERE permission LIKE 'thuong-hieu.%';
