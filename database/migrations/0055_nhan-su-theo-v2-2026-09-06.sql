-- Nhân sự theo v2 cũ: một người làm được NHIỀU chi nhánh, và cài đặt riêng
-- "cho phép dùng ứng dụng ngoài phạm vi chi nhánh" (hrm_employee_settings của v2).
--
-- shop_ids là CSV id chi nhánh ("1,3"); NULL = mọi chi nhánh (như 'all' của v2).
-- shop_id giữ lại làm chi nhánh CHÍNH (phần tử đầu) cho báo cáo và ghim lúc đăng nhập.
ALTER TABLE employees
  ADD COLUMN shop_ids VARCHAR(255) NULL
    COMMENT 'các chi nhánh được làm, CSV id; NULL = mọi chi nhánh' AFTER shop_id,
  ADD COLUMN allow_outside_area TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'cho phép dùng ứng dụng ngoài phạm vi hoạt động của chi nhánh' AFTER note;

-- Hồ sơ cũ đã có chi nhánh chính thì đó cũng là danh sách chi nhánh được làm.
UPDATE employees
   SET shop_ids = CAST(shop_id AS CHAR)
 WHERE shop_id IS NOT NULL
   AND shop_ids IS NULL;
