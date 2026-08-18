-- =====================================================================
--  0016_anh-nhan-vien-2026-08-18.sql
--  Ngày: 18/08/2026
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
--  ẢNH NHÂN VIÊN
--
--  Cột cuối cùng còn thiếu so với bảng nhân sự của order v2. Bên đó ảnh
--  đứng ở cột đầu của hộp thoại, và nó không phải để trang trí: cửa hàng
--  đông người thì tên trùng nhau, còn ca giao nhận tiền thì người ta đối
--  chiếu bằng mặt chứ không bằng mã nhân viên.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Chỉ lưu ĐƯỜNG DẪN, không lưu ảnh.
--
--  Ảnh nằm trên ổ đĩa công khai của Shop Admin (App\Services\ImageStore,
--  cùng chỗ với ảnh sản phẩm và danh mục), cột này giữ đường dẫn tương
--  đối tới nó. Nhét ảnh vào database dưới dạng BLOB thì mỗi câu SELECT
--  danh sách nhân sự kéo theo vài megabyte, và bản sao lưu phình ra vì
--  một thứ máy chủ tệp làm tốt hơn.
--
--  VARCHAR(255) đủ cho đường dẫn của ImageStore và khớp với
--  `users.avatar` sẵn có — hai cột nói cùng một loại giá trị thì để cùng
--  kiểu, đỡ một chỗ phải nhớ là chúng khác nhau.
--
--  NULL = chưa có ảnh. Màn hình hiện chữ cái đầu của tên thay vào đó.
-- ---------------------------------------------------------------------
ALTER TABLE employees
  ADD COLUMN avatar VARCHAR(255) NULL
    COMMENT 'đường dẫn ảnh trên ổ đĩa công khai; NULL = chưa có'
    AFTER full_name;
