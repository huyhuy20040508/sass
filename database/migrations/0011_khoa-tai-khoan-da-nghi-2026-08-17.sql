-- =====================================================================
--  0011_khoa-tai-khoan-da-nghi-2026-08-17.sql
--  Ngày: 17/08/2026
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
--  ĐÃ NGHỈ VIỆC MÀ VẪN ĐĂNG NHẬP ĐƯỢC
--
--  Từ hôm nay, đặt trạng thái `da_nghi` sẽ khoá luôn tài khoản đăng nhập
--  gắn với hồ sơ đó (service.dongBoTaiKhoan bên API). Nhưng luật mới chỉ
--  áp cho những lượt bấm SAU NÓ.
--
--  Những người đã bị đánh dấu nghỉ trước đó thì dòng của họ trong `users`
--  vẫn đang active: chủ tiệm nhìn màn hình nhân sự thấy "đã nghỉ việc" và
--  tin rằng chuyện đã xong, trong khi người đó vẫn mở được quầy bán bằng
--  mật khẩu cũ. Tệp này đóng đúng khoảng đó lại — không có nó thì lỗ hổng
--  vẫn còn nguyên với mọi dữ liệu có sẵn, và đó lại chính là những người
--  đã nghỉ lâu nhất.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Ba điều kiện của câu UPDATE, mỗi cái chặn một kiểu khoá nhầm:
--
--  1. `e.deleted_at IS NULL` — hồ sơ đã xoá thì không nói lên điều gì về
--     người đang dùng tài khoản nữa. Repository nhả `user_id` khi xoá hồ
--     sơ (xem nhanVienRepository.Delete), nên hàng như vậy hầu như không
--     còn, nhưng dữ liệu cũ vào trước lượt đó thì có.
--  2. `u.status = 'active'` — chỉ chạm vào thứ đang mở. Ghi đè lên tài
--     khoản đã khoá sẵn là ghi thừa, và làm câu lệnh không còn chạy lại
--     được mà không đổi gì.
--  3. `u.role_id <> 1` — KHÔNG đụng tới super admin. Đó là tài khoản gốc
--     của cửa hàng; một hồ sơ nhân sự gắn nhầm vào nó mà khoá theo thì
--     cửa hàng mất luôn đường vào quản trị, và không còn ai mở lại được.
--     Bên API cũng có luật riêng cho trường hợp này (ensureAnotherSuperAdmin),
--     nhưng ở đây là một câu UPDATE hàng loạt chạy không có ai nhìn — chỗ
--     đó thì chọn cách an toàn nhất.
--
--  Không cần lọc theo tenant: điều kiện nối đi qua chính `employees` nên
--  mỗi tài khoản chỉ bị so với hồ sơ của cửa hàng nó thuộc về.
-- ---------------------------------------------------------------------
UPDATE users u
  JOIN employees e ON e.user_id = u.id
SET u.status = 'inactive',
    u.updated_at = NOW(3)
WHERE e.status = 'da_nghi'
  AND e.deleted_at IS NULL
  AND u.status = 'active'
  AND u.role_id <> 1;
