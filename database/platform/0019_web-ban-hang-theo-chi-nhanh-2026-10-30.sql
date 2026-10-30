-- =====================================================================
--  0019_web-ban-hang-theo-chi-nhanh-2026-10-30.sql
--  Ngày: 30/10/2026
--  LƯỢC ĐỒ: selliotech_platform (KHÔNG phải selliotech)
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
--  WEB BÁN HÀNG: MỘT APP RIÊNG, MỖI CHI NHÁNH MỘT WEB
--
--  Web bán hàng (thư mục ecommerce_web/) là tính năng BÁN THÊM: khách nào
--  mua mới có. Vì vậy nó là một dòng `apps` riêng, có thuê bao riêng — hết
--  hạn web thì web đóng, còn POS vẫn chạy.
--
--  Người dùng chốt 30/10: mỗi CHI NHÁNH một web (như kimguest), hàng / giá
--  / tồn theo đúng chi nhánh đó. Tên miền của web vì thế phải trỏ tới một
--  chi nhánh chứ không chỉ một cửa hàng — nên sổ tên miền có thêm shop_id.
--
--  Đặt ở sổ tên miền chứ không ở một bảng web_ bên data plane: host phải
--  là khoá UNIQUE TOÀN NỀN TẢNG (một địa chỉ chỉ trỏ được vào một chỗ), và
--  chỉ khu điều hành được cấp tên miền. Một bảng thứ hai bên kia là một
--  chỗ thứ hai để hai khách cùng giữ một địa chỉ.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. App 'web'
-- =====================================================================
--  'planned' tới khi bán chính thức: web dev đã đọc được, còn bảng giá và
--  khu điều hành chưa bày nó ra cho khách.
INSERT INTO apps (code, name, tagline, status, created_at, updated_at)
VALUES ('web', 'Sellio Web', 'Website bán hàng của từng chi nhánh, hàng và đơn đi thẳng về POS.', 'planned', NOW(3), NOW(3))
ON DUPLICATE KEY UPDATE code = code;

-- =====================================================================
--  2. Tên miền trỏ tới chi nhánh
-- =====================================================================
--  KHÔNG có khoá ngoại: shops nằm ở data plane (selliotech), lược đồ này
--  không FK sang đó — cùng lý do với tenant_id ở 0001. Web tự kiểm chi
--  nhánh còn sống và đúng cửa hàng mỗi lần đọc.
--
--  shop_mark vì cột NULL không tham gia khoá UNIQUE: khoá "một tên miền
--  chính" phải mở theo chi nhánh (mỗi web chi nhánh một địa chỉ chính để
--  in lên QR, hoá đơn) mà vẫn giữ nguyên "một tên miền chính" cho app phục
--  vụ cả cửa hàng (shop_id NULL → 0).
ALTER TABLE tenant_domains
  ADD COLUMN shop_id BIGINT UNSIGNED NULL
    COMMENT 'chi nhánh (selliotech.shops.id) mà tên miền phục vụ — app web bắt buộc có; NULL = phục vụ cả cửa hàng'
    AFTER app_id,
  ADD COLUMN shop_mark BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(shop_id, 0)) STORED,
  DROP INDEX uq_tenant_domains_primary,
  ADD UNIQUE KEY uq_tenant_domains_primary (tenant_id, app_id, shop_mark, primary_mark);

-- =====================================================================
--  KẾT THÚC — 1 app, 1 cột, khoá "tên miền chính" mở theo chi nhánh.
--
--  Đi kèm: tài khoản MySQL của web cần thêm SELECT trên `apps`
--  (ecommerce_web/backend/database/quyen-db.sql) để lọc tên miền theo mã app.
-- =====================================================================
