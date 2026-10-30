-- =====================================================================
--  0020_bang-gia-web-ban-hang-2026-10-30.sql
--  Ngày: 30/10/2026
--  LƯỢC ĐỒ: selliotech_platform (KHÔNG phải selliotech)
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  BÁN WEB BÁN HÀNG: SHOP NÀO MUA MỚI MỞ
--
--  Người dùng chốt 30/10: web là tính năng bán thêm, chỉ mở cho cửa hàng
--  đã mua. "Đã mua" = có hợp đồng còn chạy của app 'web' — ký ở khu điều
--  hành (Khách hàng → ký hợp đồng, chọn gói Website bán hàng) như mọi app
--  khác; web đọc đúng hợp đồng đó (ecommerce_web Services/CuaHang).
--
--  Ký được cần: app 'active' (0019 để 'planned') và một dòng bảng giá có
--  đủ ba hạn mức (chotDieuKhoan từ chối gói thiếu hạn mức).
--
--  GIÁ ĐỂ NULL ("Liên hệ"): giá là quyết định kinh doanh, không đoán hộ.
--  Chưa nhập giá ở màn Gói dịch vụ thì khu điều hành từ chối ký
--  (ErrBangGiaChuaCoGia) — đúng ý: chưa chốt giá thì chưa bán.
--
--  HẠN MỨC CỦA GÓI WEB:
--    · max_shops = số CHI NHÁNH được mở web (mỗi chi nhánh một web). Mua
--      thêm chi nhánh thì sửa hạn mức lúc ký, như gói Chuỗi của POS.
--    · max_users, max_products = vo_han: tài khoản và hàng là của POS, web
--      chỉ đọc lại — giới hạn đã nằm ở hợp đồng POS.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE apps SET status = 'active', updated_at = NOW(3) WHERE code = 'web' AND status = 'planned';

INSERT INTO plans (app_id, code, name, tagline, billing_cycle, price, trial_days, status, created_at, updated_at)
SELECT a.id, 'web', 'Website bán hàng', 'Mỗi chi nhánh một website, hàng và đơn về thẳng POS', 'thang', NULL, 14, 'active', NOW(3), NOW(3)
  FROM apps a
 WHERE a.code = 'web'
   AND NOT EXISTS (SELECT 1 FROM plans p WHERE p.app_id = a.id AND p.code = 'web' AND p.billing_cycle = 'thang');

INSERT INTO plan_features (plan_id, feature_key, value, created_at, updated_at)
SELECT p.id, f.k, f.v, NOW(3), NOW(3)
  FROM plans p
  JOIN apps a ON a.id = p.app_id AND a.code = 'web'
  JOIN (SELECT 'max_shops' AS k, '1' AS v
        UNION ALL SELECT 'max_users', 'vo_han'
        UNION ALL SELECT 'max_products', 'vo_han') f
 WHERE p.code = 'web' AND p.billing_cycle = 'thang'
   AND NOT EXISTS (SELECT 1 FROM plan_features x WHERE x.plan_id = p.id AND x.feature_key = f.k);
