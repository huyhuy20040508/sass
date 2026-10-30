-- Tài khoản MySQL của web bán hàng. Bảng của web nằm ngay trong DB bán hàng của POS (tiền tố web_,
-- tạo bằng migration của POS: database/migrations ở gốc repo). Chạy bằng root, thay <nguoi_dung>,
-- <db_pos>, <db_nen_tang>, <mat_khau>. Dev: selliotech_dev + selliotech_platform_dev, user ecw.
-- Prod: selliotech + selliotech_platform, user KHÁC (ecw_prod) — dev và prod chung một máy MySQL,
-- tài khoản của web dev không được với tới DB prod. Trên prod, 05-web-ban.sh tự thay rồi chạy tệp này.
--
-- MySQL không cấp quyền theo tiền tố tên bảng: mỗi bảng web_ mới thêm một dòng GRANT bên dưới
-- (sau khi migration tạo bảng đã chạy).

CREATE USER IF NOT EXISTS '<nguoi_dung>'@'localhost' IDENTIFIED BY '<mat_khau>';

-- Đọc toàn DB POS: hàng, giá, phân loại, tồn theo chi nhánh, khuyến mãi, khách…
GRANT SELECT ON <db_pos>.* TO '<nguoi_dung>'@'localhost';

-- Ghi CHỈ các bảng của web. Hàng / tồn / khách / đơn không ghi: đơn đi qua API POS.
GRANT INSERT, UPDATE, DELETE ON <db_pos>.web_cai_dat TO '<nguoi_dung>'@'localhost';

-- DB nền tảng (như DB trung tâm của kimguest): CHỈ ĐỌC đúng mấy bảng cần — nhận cửa hàng theo
-- tên miền, chặn cửa hàng khoá / hết hạn. Không cho đọc platform_users, invoices…
GRANT SELECT ON <db_nen_tang>.tenants TO '<nguoi_dung>'@'localhost';
GRANT SELECT ON <db_nen_tang>.tenant_domains TO '<nguoi_dung>'@'localhost';
GRANT SELECT ON <db_nen_tang>.apps TO '<nguoi_dung>'@'localhost';
GRANT SELECT ON <db_nen_tang>.subscriptions TO '<nguoi_dung>'@'localhost';
GRANT SELECT ON <db_nen_tang>.plans TO '<nguoi_dung>'@'localhost';
GRANT SELECT ON <db_nen_tang>.plan_features TO '<nguoi_dung>'@'localhost';

FLUSH PRIVILEGES;
