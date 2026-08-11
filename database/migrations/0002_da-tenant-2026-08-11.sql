-- =====================================================================
--  0002_da-tenant-2026-08-11.sql
--  Ngày: 11/08/2026
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
--  DỰNG KHUNG ĐA KHÁCH HÀNG (multi-tenant) — CHƯA BẬT
--
--  Sau tệp này hệ thống chạy y hệt hiện tại: mọi dòng dữ liệu đều mang
--  tenant_id = 1 và shop_id = 1, không có tầng code nào phải sửa ngay.
--  Mục đích là làm ĐÚNG HÌNH DẠNG BÂY GIỜ, lúc chưa có khách thật — để khi
--  bật multi-tenant thật thì mấy khách đầu chỉ phải đổi một con số, thay vì
--  phải viết script migrate cho hệ thống đang có người dùng.
--
--  Hai tầng, hai mục đích khác nhau:
--
--    tenant_id — RANH GIỚI BẢO MẬT. Có mặt ở TẤT CẢ bảng dữ liệu, không
--                ngoại lệ, để plugin GORM chèn `WHERE tenant_id = ?` đồng
--                nhất mà không sót bảng nào. Rò dữ liệu giữa hai khách hàng
--                trả tiền là lỗi giết sản phẩm.
--
--    shop_id   — CHI NHÁNH, chỉ có ở bảng GIAO DỊCH (đơn hàng, thanh toán,
--                kho, phiếu nhập/trả) và dòng hàng của chúng. Danh mục
--                (sản phẩm, biến thể, danh mục, thương hiệu, NCC, voucher,
--                khuyến mãi) nằm ở tầng tenant vì dùng chung một bộ sản
--                phẩm cho cả chuỗi CHÍNH LÀ giá trị của gói Chuỗi.
--                Bảng *_history không mang shop_id: nó là vết kiểm toán,
--                luôn đọc qua chứng từ cha.
--
--  BẢNG roles KHÔNG có tenant_id. Đó là danh mục 4 vai trò cố định
--  (super_admin/admin/staff/customer) mà code tham chiếu thẳng bằng id
--  (domain.SuperAdminRoleID = 1...). Cắt nó theo tenant thì mỗi khách mới
--  phải sinh 4 dòng vai trò với id không đoán trước được, và bộ hằng số kia
--  sai ngay từ khách thứ hai. Vai trò riêng theo tenant là tính năng khác,
--  làm sau và làm bằng bảng khác. Vì vậy 20 UNIQUE KEY được cắt lại, không
--  phải 21: uq_roles_name giữ nguyên phạm vi toàn cục.
--
--  DEFAULT 1 trên tenant_id/shop_id là GIÀN GIÁO, không phải thiết kế: nhờ
--  nó mà toàn bộ INSERT hiện tại (không hề biết tới hai cột này) vẫn chạy.
--  Khi plugin GORM tự chèn tenant_id, phải BỎ DEFAULT đi — để quên cột là
--  lỗi ngay, chứ không phải âm thầm rơi vào tenant 1.
--
--  VÌ SAO MỖI BẢNG LÀ NHIỀU LỆNH ALTER RỜI: bỏ một index rồi thêm lại index
--  TRÙNG TÊN trong cùng một câu ALTER là chỗ hai engine hành xử khác nhau
--  (máy chủ chạy MySQL 8, máy dev XAMPP chạy MariaDB). Tách ra thì chậm hơn
--  vài giây mà chắc chắn chạy được ở cả hai.
--
--  CHẠY LẠI ĐƯỢC KHÔNG: phần CREATE TABLE và INSERT thì có. Phần ALTER thì
--  KHÔNG — MySQL 8 không nhận `ADD COLUMN IF NOT EXISTS` (chỉ MariaDB có),
--  mà tệp này phải chạy được ở cả hai. Nếu tệp gãy giữa chừng: xem lệnh
--  cuối cùng chạy được tới đâu bằng
--      SHOW COLUMNS FROM <bảng> LIKE 'tenant_id';
--  rồi xoá các lệnh đã vào ở BẢN SAO của tệp và chạy nốt phần còn lại bằng
--  mysql client, sau đó `migrate danh-dau`. Đừng sửa tệp gốc — vân tay.
--
--  Việc CỐ Ý ĐỂ LẠI cho các đợt sau:
--    · product_variants.stock_quantity vẫn là nguồn sự thật của tồn kho.
--      variant_stocks đã có và đã nạp dữ liệu, nhưng code chưa ghi vào đó
--      (xem mục 18). Đợt chuyển code phải NẠP LẠI variant_stocks rồi mới
--      bỏ cột cũ.
--    · Chưa có subscriptions / platform_users / shop_users — chúng thuộc
--      control plane, làm khi thật sự bán cho khách thứ hai.
--    · Chưa có tenant_counters: order_code hiện là DH+ngày+id, mà id là
--      auto-increment toàn hệ thống nên nó lộ tổng số đơn của TẤT CẢ khách.
--      Phải sửa trước khi có khách thứ hai, không phải bây giờ.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. TENANTS — mỗi dòng là MỘT khách hàng đã mua phần mềm
-- =====================================================================
--  `code` là thứ khách gõ vào ô đầu tiên của màn hình đăng nhập 3 ô
--  (mã cửa hàng / tên đăng nhập / mật khẩu). Cố ý là CHUỖI DO MÌNH CẤP chứ
--  không phải số id: số auto-increment cho người ngoài dò được hệ thống có
--  bao nhiêu khách và mình là khách thứ mấy.
--
--  Không xoá tenant, chỉ KHOÁ: khách hết hạn hoặc ngừng trả tiền thì
--  status='suspended', dữ liệu bán hàng của người ta giữ nguyên, đóng tiền
--  lại là mở. Vì vậy mọi khoá ngoại trỏ về đây đều để mặc định (RESTRICT).
CREATE TABLE IF NOT EXISTS tenants (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(30)  NOT NULL COMMENT 'mã cửa hàng khách gõ lúc đăng nhập: cuahangabc',
  name       VARCHAR(150) NOT NULL COMMENT 'tên khách hàng, để mình đọc trong SaaS Admin',
  status     ENUM('active','suspended') NOT NULL DEFAULT 'active'
             COMMENT 'suspended = hết hạn/ngừng trả tiền: chặn đăng nhập, KHÔNG xoá dữ liệu',
  note       VARCHAR(500) NULL COMMENT 'ghi chú nội bộ của mình về khách này',
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenants_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant 1 — chính là bản cài đang chạy.
--
-- Mã 'cuahang' là chỗ giữ chỗ: nó sẽ hiện ra ở ô đầu tiên màn hình đăng
-- nhập, nên khi bàn giao cho khách thật thì đổi bằng
--     UPDATE tenants SET code = '<mã thật>' WHERE id = 1;
-- Tên thì lấy luôn từ cấu hình site_name nếu đã có, khỏi phải gõ lại.
INSERT INTO tenants (id, code, name, status, created_at, updated_at)
VALUES (
  1,
  'cuahang',
  COALESCE((SELECT s.`value` FROM settings s WHERE s.`key` = 'site_name' AND s.`value` <> '' LIMIT 1), 'Cửa hàng'),
  'active', NOW(3), NOW(3)
)
ON DUPLICATE KEY UPDATE id = id;

-- =====================================================================
--  2. SHOPS — chi nhánh của một tenant
-- =====================================================================
--  Gói Khởi đầu và gói Cửa hàng = tenant có ĐÚNG MỘT shop, và giao diện ẩn
--  hoàn toàn khái niệm chi nhánh khi tenant chỉ có một. Nâng lên gói Chuỗi
--  chỉ là INSERT thêm một dòng ở đây — không migration, không chuyển dữ liệu.
CREATE TABLE IF NOT EXISTS shops (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  code       VARCHAR(30)  NOT NULL COMMENT 'mã chi nhánh, chỉ cần duy nhất trong một tenant',
  name       VARCHAR(150) NOT NULL,
  phone      VARCHAR(20)  NULL,
  address    VARCHAR(255) NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shops_tenant_code (tenant_id, code),
  KEY idx_shops_deleted_at (deleted_at),
  CONSTRAINT fk_shops_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO shops (id, tenant_id, code, name, is_active, created_at, updated_at)
VALUES (1, 1, 'mac-dinh', COALESCE((SELECT t.name FROM tenants t WHERE t.id = 1), 'Cửa hàng'), 1, NOW(3), NOW(3))
ON DUPLICATE KEY UPDATE id = id;

-- =====================================================================
--  3. XÁC THỰC & NGƯỜI DÙNG
-- =====================================================================

-- users — ba UNIQUE cũ đều ở phạm vi TOÀN HỆ THỐNG, phải cắt về phạm vi
-- tenant:
--   · email: cùng một người mua hàng ở hai cửa hàng khác nhau là bình thường.
--   · facebook_id / google_id: giữ unique toàn cục thì khách đã đăng nhập
--     Facebook ở shop này sẽ không liên kết được ở shop kia. MySQL cho phép
--     nhiều dòng NULL trong UNIQUE nên tài khoản chưa liên kết không vướng gì.
ALTER TABLE users
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE users
  DROP INDEX uq_users_email,
  DROP INDEX uq_users_facebook_id,
  DROP INDEX uq_users_google_id;

ALTER TABLE users
  ADD UNIQUE KEY uq_users_email (tenant_id, email),
  ADD UNIQUE KEY uq_users_facebook_id (tenant_id, facebook_id),
  ADD UNIQUE KEY uq_users_google_id (tenant_id, google_id);

ALTER TABLE users
  ADD CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE user_addresses
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_addresses_tenant (tenant_id);

ALTER TABLE user_addresses
  ADD CONSTRAINT fk_addresses_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  4. DANH MỤC SẢN PHẨM — tầng TENANT, dùng chung cho mọi chi nhánh
-- =====================================================================

ALTER TABLE categories
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE categories DROP INDEX uq_categories_slug;

ALTER TABLE categories
  ADD UNIQUE KEY uq_categories_slug (tenant_id, slug);

ALTER TABLE categories
  ADD CONSTRAINT fk_categories_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE brands
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE brands DROP INDEX uq_brands_slug;

ALTER TABLE brands
  ADD UNIQUE KEY uq_brands_slug (tenant_id, slug);

ALTER TABLE brands
  ADD CONSTRAINT fk_brands_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- products/product_variants — giữ nguyên cột phụ deleted_mark trong khoá:
-- ràng buộc vẫn chỉ có hiệu lực giữa các dòng ĐANG SỐNG của cùng một tenant,
-- xoá rồi tạo lại cùng slug/SKU vẫn làm được như trước.
--
-- Prefix bằng tenant_id chứ KHÔNG phải shop_id: một mã SKU phải duy nhất
-- trong phạm vi một khách hàng. Cắt theo chi nhánh thì cùng một sản phẩm ở
-- hai chi nhánh thành hai SKU hợp lệ, và báo cáo hợp nhất toàn chuỗi sai.
ALTER TABLE products
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE products
  DROP INDEX uq_products_slug,
  DROP INDEX uq_products_sku;

ALTER TABLE products
  ADD UNIQUE KEY uq_products_slug (tenant_id, slug, deleted_mark),
  ADD UNIQUE KEY uq_products_sku (tenant_id, sku, deleted_mark);

ALTER TABLE products
  ADD CONSTRAINT fk_products_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE product_variants
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

-- uq_variants_product_size_color bắt đầu bằng product_id nên nó cũng đang là
-- chỉ mục cho fk_variants_product. Bỏ nó đi vẫn an toàn vì idx_variants_product
-- đã có sẵn và phục vụ được khoá ngoại đó.
ALTER TABLE product_variants
  DROP INDEX uq_variants_sku,
  DROP INDEX uq_variants_product_size_color;

ALTER TABLE product_variants
  ADD UNIQUE KEY uq_variants_sku (tenant_id, sku, deleted_mark),
  ADD UNIQUE KEY uq_variants_product_size_color (tenant_id, product_id, size, color, deleted_mark);

ALTER TABLE product_variants
  ADD CONSTRAINT fk_variants_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE product_images
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_images_tenant (tenant_id);

ALTER TABLE product_images
  ADD CONSTRAINT fk_images_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  5. GIỎ HÀNG
-- =====================================================================

ALTER TABLE carts
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_carts_tenant (tenant_id);

ALTER TABLE carts
  ADD CONSTRAINT fk_carts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE cart_items
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_cart_items_tenant (tenant_id);

ALTER TABLE cart_items
  ADD CONSTRAINT fk_cart_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  6. VOUCHER / KHUYẾN MÃI
-- =====================================================================

ALTER TABLE vouchers
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE vouchers DROP INDEX uq_vouchers_code;

ALTER TABLE vouchers
  ADD UNIQUE KEY uq_vouchers_code (tenant_id, code);

ALTER TABLE vouchers
  ADD CONSTRAINT fk_vouchers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE promotions
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_promotions_tenant (tenant_id);

ALTER TABLE promotions
  ADD CONSTRAINT fk_promotions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- promotion_targets: promotion_id đã ngầm xác định tenant, thêm tenant_id
-- vào khoá là thừa về mặt ràng buộc. Vẫn thêm vì plugin GORM sẽ chèn
-- `WHERE tenant_id = ?` vào MỌI câu truy vấn — để tenant_id đứng đầu khoá
-- thì bộ tối ưu còn dùng được index.
--
-- idx_promotion_targets_promotion phải thêm TRƯỚC: uq_promotion_targets cũ
-- đang là chỉ mục duy nhất bắt đầu bằng promotion_id, tức là nó đang gánh
-- fk_promotion_targets_promotion. Bỏ nó khi chưa có chỉ mục thay thế thì
-- MySQL từ chối ("needed in a foreign key constraint"), còn khoá mới thì
-- bắt đầu bằng tenant_id nên không thay thế được.
ALTER TABLE promotion_targets
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_promotion_targets_promotion (promotion_id);

ALTER TABLE promotion_targets DROP INDEX uq_promotion_targets;

ALTER TABLE promotion_targets
  ADD UNIQUE KEY uq_promotion_targets (tenant_id, promotion_id, target_type, target_id);

ALTER TABLE promotion_targets
  ADD CONSTRAINT fk_promotion_targets_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  7. ĐƠN HÀNG — bảng giao dịch, có CẢ shop_id
-- =====================================================================

ALTER TABLE orders
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_orders_shop (shop_id);

ALTER TABLE orders DROP INDEX uq_orders_code;

ALTER TABLE orders
  ADD UNIQUE KEY uq_orders_code (tenant_id, order_code);

ALTER TABLE orders
  ADD CONSTRAINT fk_orders_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_orders_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

ALTER TABLE order_items
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_order_items_tenant (tenant_id),
  ADD KEY idx_order_items_shop (shop_id);

ALTER TABLE order_items
  ADD CONSTRAINT fk_order_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_order_items_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

ALTER TABLE order_status_history
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_status_history_tenant (tenant_id);

ALTER TABLE order_status_history
  ADD CONSTRAINT fk_status_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  8. THANH TOÁN
-- =====================================================================

-- uq_payments_transaction cắt theo tenant chứ không để toàn cục: mã giao
-- dịch do CỔNG của từng khách cấp, hai khách dùng hai tài khoản PayOS khác
-- nhau thì trùng số là chuyện có thật. Đổi lại, chỗ xử lý webhook phải xác
-- định tenant TRƯỚC rồi mới tra mã — không tra mò toàn bảng.
ALTER TABLE payments
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_payments_shop (shop_id);

ALTER TABLE payments DROP INDEX uq_payments_transaction;

ALTER TABLE payments
  ADD UNIQUE KEY uq_payments_transaction (tenant_id, transaction_code);

ALTER TABLE payments
  ADD CONSTRAINT fk_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_payments_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

ALTER TABLE voucher_usages
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_voucher_usages_tenant (tenant_id);

ALTER TABLE voucher_usages
  ADD CONSTRAINT fk_voucher_usages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  9. KHO
-- =====================================================================

ALTER TABLE inventory_transactions
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_inventory_tenant (tenant_id),
  ADD KEY idx_inventory_shop (shop_id);

ALTER TABLE inventory_transactions
  ADD CONSTRAINT fk_inventory_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_inventory_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

-- =====================================================================
--  10. TƯƠNG TÁC NGƯỜI DÙNG
-- =====================================================================

ALTER TABLE product_reviews
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_reviews_tenant (tenant_id);

ALTER TABLE product_reviews
  ADD CONSTRAINT fk_reviews_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- idx_wishlist_user cùng lý do với promotion_targets ở trên: khoá cũ
-- (user_id, product_id) đang gánh fk_wishlist_user, mà khoá mới bắt đầu
-- bằng tenant_id nên không gánh thay được.
ALTER TABLE wishlists
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_wishlist_user (user_id);

ALTER TABLE wishlists DROP INDEX uq_wishlist_user_product;

ALTER TABLE wishlists
  ADD UNIQUE KEY uq_wishlist_user_product (tenant_id, user_id, product_id);

ALTER TABLE wishlists
  ADD CONSTRAINT fk_wishlist_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE notifications
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_notifications_tenant (tenant_id);

ALTER TABLE notifications
  ADD CONSTRAINT fk_notifications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  11. MARKETING & CẤU HÌNH
-- =====================================================================

ALTER TABLE banners
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_banners_tenant (tenant_id);

ALTER TABLE banners
  ADD CONSTRAINT fk_banners_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- settings là cấu hình CỦA MỘT CỬA HÀNG (tên shop, logo, số tài khoản ngân
-- hàng, phí ship) — không phải cấu hình hệ thống. Mỗi tenant một bộ.
ALTER TABLE settings
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE settings DROP INDEX uq_settings_key;

ALTER TABLE settings
  ADD UNIQUE KEY uq_settings_key (tenant_id, `key`);

ALTER TABLE settings
  ADD CONSTRAINT fk_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  12. NHẬT KÝ HOẠT ĐỘNG
-- =====================================================================

ALTER TABLE activity_logs
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_logs_tenant (tenant_id),
  ADD KEY idx_logs_shop (shop_id);

ALTER TABLE activity_logs
  ADD CONSTRAINT fk_logs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_logs_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

-- =====================================================================
--  13. XÁC THỰC EMAIL
-- =====================================================================

ALTER TABLE email_verifications
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_email_verifications_tenant (tenant_id);

ALTER TABLE email_verifications
  ADD CONSTRAINT fk_email_verifications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  14. TRẢ HÀNG (khách trả cho shop)
-- =====================================================================

ALTER TABLE order_returns
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_order_returns_shop (shop_id);

ALTER TABLE order_returns DROP INDEX uq_order_returns_code;

ALTER TABLE order_returns
  ADD UNIQUE KEY uq_order_returns_code (tenant_id, return_code);

ALTER TABLE order_returns
  ADD CONSTRAINT fk_order_returns_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_order_returns_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

ALTER TABLE order_return_items
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_order_return_items_tenant (tenant_id),
  ADD KEY idx_order_return_items_shop (shop_id);

ALTER TABLE order_return_items
  ADD CONSTRAINT fk_order_return_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_order_return_items_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

ALTER TABLE order_return_history
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_order_return_history_tenant (tenant_id);

ALTER TABLE order_return_history
  ADD CONSTRAINT fk_order_return_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  15. NHÀ CUNG CẤP & ĐẶT HÀNG NHẬP
-- =====================================================================

-- suppliers ở tầng tenant: cả chuỗi nhập chung một danh sách NCC, giống
-- như dùng chung một bộ sản phẩm.
ALTER TABLE suppliers
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE suppliers DROP INDEX uq_suppliers_code;

ALTER TABLE suppliers
  ADD UNIQUE KEY uq_suppliers_code (tenant_id, code);

ALTER TABLE suppliers
  ADD CONSTRAINT fk_suppliers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE purchase_orders
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_purchase_orders_shop (shop_id);

ALTER TABLE purchase_orders DROP INDEX uq_purchase_orders_code;

ALTER TABLE purchase_orders
  ADD UNIQUE KEY uq_purchase_orders_code (tenant_id, po_code);

ALTER TABLE purchase_orders
  ADD CONSTRAINT fk_purchase_orders_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_purchase_orders_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

ALTER TABLE purchase_order_items
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_po_items_tenant (tenant_id),
  ADD KEY idx_po_items_shop (shop_id);

ALTER TABLE purchase_order_items
  ADD CONSTRAINT fk_po_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_po_items_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

ALTER TABLE purchase_order_history
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_po_history_tenant (tenant_id);

ALTER TABLE purchase_order_history
  ADD CONSTRAINT fk_po_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE purchase_returns
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_pr_shop (shop_id);

ALTER TABLE purchase_returns DROP INDEX uq_pr_code;

ALTER TABLE purchase_returns
  ADD UNIQUE KEY uq_pr_code (tenant_id, return_code);

ALTER TABLE purchase_returns
  ADD CONSTRAINT fk_pr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_pr_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

ALTER TABLE purchase_return_items
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN shop_id   BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER tenant_id,
  ADD KEY idx_pr_items_tenant (tenant_id),
  ADD KEY idx_pr_items_shop (shop_id);

ALTER TABLE purchase_return_items
  ADD CONSTRAINT fk_pr_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  ADD CONSTRAINT fk_pr_items_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id);

ALTER TABLE purchase_return_history
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_pr_history_tenant (tenant_id);

ALTER TABLE purchase_return_history
  ADD CONSTRAINT fk_pr_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  16. YÊU CẦU TỪ STOREFRONT & DANH SÁCH NHẬN TIN
-- =====================================================================

ALTER TABLE contact_requests
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_contact_requests_tenant (tenant_id);

ALTER TABLE contact_requests
  ADD CONSTRAINT fk_contact_requests_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

ALTER TABLE newsletter_subscribers
  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE newsletter_subscribers DROP INDEX uq_newsletter_email;

ALTER TABLE newsletter_subscribers
  ADD UNIQUE KEY uq_newsletter_email (tenant_id, email);

ALTER TABLE newsletter_subscribers
  ADD CONSTRAINT fk_newsletter_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- =====================================================================
--  17. users.username — ô thứ hai của màn hình đăng nhập 3 ô
-- =====================================================================
--  Nhân viên bán hàng ở cửa hàng Việt Nam phần lớn không có email; bắt đăng
--  nhập bằng email nghĩa là chủ shop phải bịa email giả cho từng người. Tên
--  đăng nhập chỉ cần duy nhất TRONG MỘT TENANT, nên mọi chủ shop đều được
--  làm 'admin' thay vì tranh nhau một địa chỉ email duy nhất toàn hệ thống.
--
--  NULL chứ không phải chuỗi rỗng: khách hàng mua sắm không có tên đăng
--  nhập, mà UNIQUE chỉ cho lọt đúng MỘT dòng rỗng — khách thứ hai sẽ vỡ
--  khoá. Nhiều dòng NULL thì UNIQUE cho phép thoải mái. Bên Go dùng kiểu
--  StringOrNull sẵn có (như facebook_id/google_id) để giữ đúng tính chất này.
ALTER TABLE users
  ADD COLUMN username VARCHAR(50) NULL
    COMMENT 'tên đăng nhập, chỉ duy nhất trong một tenant; NULL = tài khoản khách hàng'
    AFTER tenant_id;

-- Nạp sẵn tên đăng nhập cho các tài khoản NỘI BỘ (mọi vai trò trừ customer)
-- lấy từ phần trước @ của email: admin@selliotech.local -> 'admin'.
--
-- Lọc ký tự lạ và hạ chữ thường để tên gõ được trên bàn phím điện thoại.
-- ROW_NUMBER xử lý trùng: hai email 'admin@a.com' và 'admin@b.com' trong
-- cùng một tenant cho ra 'admin' và 'admin2' — thà tên thứ hai xấu còn hơn
-- lệnh ADD UNIQUE ngay dưới gãy giữa chừng.
--
-- Tính cả tài khoản đã xoá mềm: khoá unique của users không loại chúng ra
-- (giống uq_users_email từ trước tới nay), nên chúng vẫn phải có tên riêng.
UPDATE users u
JOIN (
  SELECT u2.id,
         NULLIF(LOWER(REGEXP_REPLACE(SUBSTRING_INDEX(u2.email, '@', 1), '[^a-zA-Z0-9_.-]', '')), '') AS base,
         ROW_NUMBER() OVER (
           PARTITION BY u2.tenant_id,
                        NULLIF(LOWER(REGEXP_REPLACE(SUBSTRING_INDEX(u2.email, '@', 1), '[^a-zA-Z0-9_.-]', '')), '')
           ORDER BY u2.id
         ) AS rn
  FROM users u2
  JOIN roles r ON r.id = u2.role_id
  WHERE r.name <> 'customer'
) x ON x.id = u.id
SET u.username = CASE
      WHEN x.base IS NULL THEN CONCAT('nd', u.id)
      WHEN x.rn = 1       THEN x.base
      ELSE CONCAT(x.base, x.rn)
    END;

ALTER TABLE users
  ADD UNIQUE KEY uq_users_username (tenant_id, username);

-- =====================================================================
--  18. variant_stocks — TỒN KHO THEO CHI NHÁNH
-- =====================================================================
--  Biến thể nằm ở tầng tenant (cả chuỗi dùng chung một bộ sản phẩm), nên
--  một cột số trên product_variants không mang nổi tồn của ba chi nhánh.
--  Tồn kho phải là bảng riêng, khoá theo (shop_id, product_variant_id).
--
--  ĐỌC KỸ — BẢNG NÀY CHƯA ĐƯỢC DÙNG:
--  Sau tệp này, nguồn sự thật của tồn kho VẪN LÀ product_variants.stock_quantity.
--  Toàn bộ nghiệp vụ kho (nhập hàng, điều chỉnh, đơn hàng, trả hàng) vẫn
--  ghi vào cột đó và KHÔNG ghi vào đây, nên dữ liệu dưới đây sẽ cũ dần kể
--  từ giao dịch kho tiếp theo.
--
--  Cố ý làm vậy: đổi nguồn sự thật đòi sửa cả chục chỗ trong Go (inventory /
--  order / order_return repository) và phải đi cùng đợt code, không nhét vào
--  một tệp DDL được. Đợt chuyển code BẮT BUỘC phải:
--      1) nạp lại toàn bộ bảng này từ stock_quantity (lệnh INSERT y hệt dưới),
--      2) chuyển code sang đọc/ghi variant_stocks,
--      3) rồi mới DROP COLUMN product_variants.stock_quantity.
--  Thiếu bước 1 thì tồn kho lùi về đúng thời điểm chạy tệp này.
CREATE TABLE IF NOT EXISTS variant_stocks (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          BIGINT UNSIGNED NOT NULL DEFAULT 1,
  shop_id            BIGINT UNSIGNED NOT NULL DEFAULT 1,
  product_variant_id BIGINT UNSIGNED NOT NULL,
  quantity           INT NOT NULL DEFAULT 0 COMMENT 'tồn của biến thể này TẠI chi nhánh này',
  created_at         DATETIME(3) NULL,
  updated_at         DATETIME(3) NULL,
  PRIMARY KEY (id),
  -- Một biến thể có ĐÚNG MỘT dòng tồn ở mỗi chi nhánh. shop_id đứng đầu vì
  -- câu hỏi thường gặp là "chi nhánh này còn hàng gì", không phải ngược lại.
  UNIQUE KEY uq_variant_stocks_shop_variant (shop_id, product_variant_id),
  KEY idx_variant_stocks_tenant (tenant_id),
  KEY idx_variant_stocks_variant (product_variant_id),
  CONSTRAINT fk_variant_stocks_tenant  FOREIGN KEY (tenant_id)          REFERENCES tenants (id),
  CONSTRAINT fk_variant_stocks_shop    FOREIGN KEY (shop_id)            REFERENCES shops (id),
  CONSTRAINT fk_variant_stocks_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nạp tồn hiện có vào chi nhánh 1. Tính cả biến thể đã xoá mềm: hàng vẫn
-- nằm trong kho thật, và khoá unique lo phần không nhân đôi dòng.
INSERT INTO variant_stocks (tenant_id, shop_id, product_variant_id, quantity, created_at, updated_at)
SELECT v.tenant_id, 1, v.id, v.stock_quantity, NOW(3), NOW(3)
FROM product_variants v
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW(3);

-- Đánh dấu ngay trên cột cũ để người đọc lược đồ không phải lần ra tệp này.
ALTER TABLE product_variants
  MODIFY COLUMN stock_quantity INT NOT NULL DEFAULT 0
    COMMENT 'tồn kho TẠM THỜI của chi nhánh 1 — nguồn sự thật cho tới khi code chuyển sang variant_stocks (xem 0002). CHỈ nghiệp vụ kho được ghi; form sản phẩm không bao giờ set cột này.';

-- =====================================================================
--  KẾT THÚC — 3 bảng mới (tenants, shops, variant_stocks),
--  37/38 bảng cũ có tenant_id, 11 bảng giao dịch có thêm shop_id,
--  20/21 UNIQUE KEY cắt lại theo tenant (roles giữ nguyên toàn cục).
-- =====================================================================
