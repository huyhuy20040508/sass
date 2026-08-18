-- =====================================================================
--  0015_hai-cua-vao-2026-08-18.sql
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
--  HAI CỬA VÀO, TÍCH GÌ VÀO ĐƯỢC NẤY
--
--  Tới giờ, "người này mở được cửa nào" nằm ở `users.role_id`, một con
--  số. Mà một con số thì không nói được câu "vừa quản lý vừa đứng quầy":
--  vai `admin` mặc nhiên đi qua cả hai cửa, nên chủ tiệm tích một ô hay
--  tích hai ô đều ra cùng một kết quả, và màn hình không có cách nào
--  hiện lại đúng thứ họ vừa tích.
--
--  Cột này ghi ĐÚNG những cửa đã giao. Từ nay tích gì vào được nấy:
--  người chỉ có 'quan_ly' KHÔNG mở được quầy bán nữa, dù `role_id` của
--  họ vẫn là admin.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Vì sao THÊM CỘT chứ không thay hẳn `role_id`:
--
--  `role_id` còn là khoá ngoại tới `roles`, còn nằm trong token đăng
--  nhập, và còn là thứ phân biệt NGƯỜI CỦA TIỆM với KHÁCH HÀNG
--  (role_id = 4) ở khắp nơi trong mã nguồn. Đổi nó là đụng vào mọi câu
--  truy vấn có chữ `role`, để đổi lấy đúng một tính năng.
--
--  Nên hai cột chia việc rạch ròi:
--    role_id     — anh là LOẠI người nào (chủ tiệm / người của tiệm / khách)
--    access_areas — người của tiệm thì mở được những CỬA nào
--
--  NULL = chưa khai, và tầng Go suy từ role_id như trước. Nhờ vậy token
--  cũ, tài khoản cũ và khách hàng đều đi qua đây mà không đổi hành vi.
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN access_areas SET('quan_ly','thu_ngan') NULL
    COMMENT 'cửa vào đã giao; NULL = suy từ role_id (dữ liệu trước 0015)'
    AFTER role_id;

-- ---------------------------------------------------------------------
--  Điền cho dữ liệu đang có.
--
--  Nguyên tắc: KHÔNG lấy bớt quyền của ai trong một lượt chạy migration.
--  Hôm qua vai `admin` mở được cả hai cửa, nên hôm nay họ vẫn phải mở
--  được cả hai — chủ tiệm nào muốn siết lại thì tự bỏ tích, đó là một
--  quyết định có người bấm chứ không phải hệ quả âm thầm của lượt cập
--  nhật phần mềm.
--
--  Khách hàng (role_id = 4) KHÔNG điền gì: họ không có cửa nào trong khu
--  quản trị, và để NULL thì mọi lượt kiểm sau này trả về rỗng.
-- ---------------------------------------------------------------------
UPDATE users
   SET access_areas = 'quan_ly,thu_ngan',
       updated_at = NOW(3)
 WHERE role_id IN (1, 2)
   AND access_areas IS NULL;

UPDATE users
   SET access_areas = 'thu_ngan',
       updated_at = NOW(3)
 WHERE role_id = 3
   AND access_areas IS NULL;
