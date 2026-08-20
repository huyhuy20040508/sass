-- =====================================================================
--  0026_san-pham-co-vi-tri-2026-08-20.sql
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
--  GẮN VỊ TRÍ VÀO SẢN PHẨM
--
--  Bảng product_locations dựng ở 0025 mới chỉ là danh mục đứng một mình.
--  Cột này là chỗ nó thật sự có tác dụng: người soạn hàng mở danh sách
--  sản phẩm ra là đọc được món ấy để ở đâu.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  NULL = chưa gán vị trí, và đó là trạng thái HỢP LỆ chứ không phải dữ
--  liệu thiếu: mọi sản phẩm đang có đều rơi vào đây sau lượt chạy này,
--  và cửa hàng không quản kho theo kệ thì mãi mãi để trống cả cột.
--
--  Đặt ngay sau category_id vì hai cột trả lời cùng một loại câu hỏi —
--  "món này thuộc về đâu" — và màn hình cũng bày chúng cạnh nhau.
--
--  KHÔNG có ON DELETE CASCADE: xoá một vị trí không được phép kéo theo
--  sản phẩm. Tầng Go chặn hẳn việc xoá vị trí còn hàng trỏ tới
--  (ErrViTriDangDung), khoá ngoại ở đây là lớp chắn cuối.
-- ---------------------------------------------------------------------
ALTER TABLE products
  ADD COLUMN location_id BIGINT UNSIGNED NULL
    COMMENT 'chỗ để hàng, trỏ product_locations; NULL = chưa gán'
    AFTER category_id;

ALTER TABLE products
  ADD KEY idx_products_location (location_id);

ALTER TABLE products
  ADD CONSTRAINT fk_products_location
    FOREIGN KEY (location_id) REFERENCES product_locations (id);
