-- =====================================================================
--  0001 — NỀN LƯỢC ĐỒ (chốt ngày 10/08/2026)
-- =====================================================================
--  Tệp này là toàn bộ lược đồ tại thời điểm dự án chuyển sang dùng công cụ
--  migration có phiên bản. Nó gộp sẵn 13 tệp alter-*.sql chạy tay trước đó
--  (bản gốc giữ trong database/lich-su/ để tra cứu).
--
--  Chạy bằng:
--      cd api && go run ./cmd/migrate chay
--
--  Database ĐÃ CÓ SẴN lược đồ này từ trước (máy cục bộ, bản thử, bản thật)
--  thì đánh dấu chứ đừng chạy lại:
--      cd api && go run ./cmd/migrate danh-dau
--
--  KHÔNG có CREATE DATABASE / USE trong tệp này: tên database khác nhau ở
--  từng môi trường và công cụ đã kết nối sẵn đúng chỗ theo .env. Tệp cũ nướng
--  cứng `USE selliotech` chính là lý do lệnh nạp lược đồ trong tao-prod.sh
--  từng tạo ra một database lạc chỗ thay vì nạp vào database của bản thật.
--
--  Đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung tệp này — công cụ giữ
--  vân tay SHA-256 và sẽ báo lệch. Cần đổi lược đồ thì tạo tệp mới:
--      cd api && go run ./cmd/migrate tao-moi <viec-can-lam>
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
--  1. XÁC THỰC & NGƯỜI DÙNG
-- =====================================================================

-- Vai trò (RBAC đơn giản): super_admin, admin, staff, customer
CREATE TABLE roles (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(50)  NOT NULL COMMENT 'mã vai trò: super_admin, admin, staff, customer',
  display_name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Người dùng: gồm cả khách hàng và tài khoản quản trị (phân biệt qua role_id)
CREATE TABLE users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id           BIGINT UNSIGNED NOT NULL,
  full_name         VARCHAR(150) NOT NULL,
  email             VARCHAR(191) NOT NULL,
  phone             VARCHAR(20)  NULL,
  password_hash     VARCHAR(255) NOT NULL COMMENT 'bcrypt',
  facebook_id       VARCHAR(64)  NULL COMMENT 'id nguoi dung do Facebook Login cap; NULL = chua lien ket',
  google_id         VARCHAR(64)  NULL COMMENT 'sub cua tai khoan Google; NULL = chua lien ket',
  avatar            VARCHAR(255) NULL,
  gender            ENUM('male','female','other') NULL,
  date_of_birth     DATE NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = đang hoạt động, inactive = không hoạt động',
  email_verified_at DATETIME(3) NULL,
  phone_verified_at DATETIME(3) NULL,
  last_login_at     DATETIME(3) NULL,
  created_at        DATETIME(3) NULL,
  updated_at        DATETIME(3) NULL,
  deleted_at        DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  -- Mot tai khoan Facebook/Google chi lien ket duoc vao mot tai khoan cua hang. MySQL
  -- cho phep nhieu dong NULL trong UNIQUE nen tai khoan chua lien ket khong vuong gi.
  UNIQUE KEY uq_users_facebook_id (facebook_id),
  UNIQUE KEY uq_users_google_id (google_id),
  KEY idx_users_phone (phone),
  KEY idx_users_role (role_id),
  KEY idx_users_deleted_at (deleted_at),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sổ địa chỉ giao hàng của khách
CREATE TABLE user_addresses (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  recipient_name VARCHAR(150) NOT NULL,
  phone          VARCHAR(20)  NOT NULL,
  province       VARCHAR(100) NOT NULL,
  district       VARCHAR(100) NOT NULL,
  ward           VARCHAR(100) NOT NULL,
  address_line   VARCHAR(255) NOT NULL COMMENT 'số nhà, tên đường',
  type           ENUM('home','office') NOT NULL DEFAULT 'home',
  is_default     TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME(3) NULL,
  updated_at     DATETIME(3) NULL,
  deleted_at     DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_addresses_user (user_id),
  KEY idx_addresses_deleted_at (deleted_at),
  CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  2. DANH MỤC SẢN PHẨM (CATALOG)
-- =====================================================================

-- Danh mục phân cấp (tự tham chiếu): CLB, Đội tuyển, Sân nhà/khách...
CREATE TABLE categories (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id   BIGINT UNSIGNED NULL,
  name        VARCHAR(150) NOT NULL,
  slug        VARCHAR(191) NOT NULL,
  description VARCHAR(500) NULL,
  image       VARCHAR(255) NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,
  deleted_at  DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_parent (parent_id),
  KEY idx_categories_deleted_at (deleted_at),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Thương hiệu: Nike, Adidas, Puma...
CREATE TABLE brands (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(150) NOT NULL,
  slug        VARCHAR(191) NOT NULL,
  logo        VARCHAR(255) NULL,
  description VARCHAR(500) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,
  deleted_at  DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_brands_slug (slug),
  KEY idx_brands_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sản phẩm (áo bóng đá). Giá/tồn kho chi tiết nằm ở product_variants.
CREATE TABLE products (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id       BIGINT UNSIGNED NOT NULL,
  brand_id          BIGINT UNSIGNED NULL,
  name              VARCHAR(200) NOT NULL,
  slug              VARCHAR(191) NOT NULL,
  sku               VARCHAR(64)  NOT NULL COMMENT 'mã sản phẩm gốc',
  short_description VARCHAR(500) NULL,
  description       TEXT NULL,
  team              VARCHAR(150) NULL COMMENT 'CLB/đội tuyển: Real Madrid, Việt Nam...',
  season            VARCHAR(20)  NULL COMMENT 'mùa giải: 2024/2025',
  kit_type          ENUM('fan','player') NULL COMMENT 'loại áo: fan = FAN, player = PLAYER',
  base_price        DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'giá niêm yết',
  sale_price        DECIMAL(12,2) NULL COMMENT 'giá khuyến mãi (nếu có)',
  cost_price        DECIMAL(12,2) NULL COMMENT 'giá vốn — dùng tính giá trị tồn kho, KHÔNG bao giờ trả ra storefront',
  thumbnail         VARCHAR(255) NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'có hiện ngoài cửa hàng không — suy ra từ status, chỉ active mới bằng 1',
  status            ENUM('active','hidden','discontinued') NOT NULL DEFAULT 'active'
                    COMMENT 'active = đang bán, hidden = tạm ẩn, discontinued = ngừng kinh doanh (giữ lịch sử, không nhập thêm)',
  is_featured       TINYINT(1) NOT NULL DEFAULT 0,
  view_count        INT UNSIGNED NOT NULL DEFAULT 0,
  sold_count        INT UNSIGNED NOT NULL DEFAULT 0,
  rating_avg        DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  rating_count      INT UNSIGNED NOT NULL DEFAULT 0,
  meta_title        VARCHAR(255) NULL COMMENT 'SEO',
  meta_description  VARCHAR(320) NULL COMMENT 'SEO',
  created_at        DATETIME(3) NULL,
  updated_at        DATETIME(3) NULL,
  deleted_at        DATETIME(3) NULL,
  -- Cột phụ cho unique key: MySQL coi mỗi NULL là một giá trị khác nhau nên
  -- deleted_at thô không dùng được trong UNIQUE. Quy NULL về mốc cố định để
  -- ràng buộc chỉ có hiệu lực giữa các dòng ĐANG SỐNG — xoá một sản phẩm rồi
  -- tạo lại sản phẩm cùng slug/SKU phải làm được. Giống product_variants.
  deleted_mark      DATETIME(3) GENERATED ALWAYS AS (IFNULL(deleted_at, '1970-01-01 00:00:00.000')) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug, deleted_mark),
  UNIQUE KEY uq_products_sku (sku, deleted_mark),
  KEY idx_products_category (category_id),
  KEY idx_products_brand (brand_id),
  KEY idx_products_active_featured (is_active, is_featured),
  KEY idx_products_status (status, is_active),
  KEY idx_products_deleted_at (deleted_at),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id),
  CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Biến thể sản phẩm: mỗi size (và màu) là 1 biến thể có SKU riêng.
-- Loại áo (fan/player) nằm ở cấp sản phẩm (products.kit_type), KHÔNG ở biến thể.
CREATE TABLE product_variants (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id     BIGINT UNSIGNED NOT NULL,
  sku            VARCHAR(64)  NOT NULL,
  size           VARCHAR(20)  NOT NULL COMMENT 'S, M, L, XL, XXL, Kids...',
  color          VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'rỗng = không phân màu',
  price          DECIMAL(12,2) NULL COMMENT 'ghi đè giá sản phẩm nếu khác',
  cost_price     DECIMAL(12,2) NULL COMMENT 'ghi đè giá vốn của sản phẩm nếu khác',
  stock_quantity INT NOT NULL DEFAULT 0 COMMENT 'tồn kho — CHỈ nghiệp vụ kho được ghi (nhập hàng/điều chỉnh/đơn hàng/trả hàng). Form sản phẩm không bao giờ set cột này.',
  weight_gram    INT NOT NULL DEFAULT 0 COMMENT 'cân nặng để tính phí ship',
  image          VARCHAR(255) NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME(3) NULL,
  updated_at     DATETIME(3) NULL,
  deleted_at     DATETIME(3) NULL,
  -- Cột phụ cho unique key: MySQL coi mỗi NULL là một giá trị khác nhau nên
  -- nếu đưa thẳng deleted_at vào UNIQUE thì các dòng đang sống (deleted_at NULL)
  -- sẽ không bị ràng buộc. Quy NULL về mốc cố định để unique có hiệu lực với
  -- dòng đang sống, đồng thời cho phép thêm lại size đã xoá mềm trước đó.
  deleted_mark   DATETIME(3) GENERATED ALWAYS AS (IFNULL(deleted_at, '1970-01-01 00:00:00.000')) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_variants_sku (sku, deleted_mark),
  UNIQUE KEY uq_variants_product_size_color (product_id, size, color, deleted_mark),
  KEY idx_variants_product (product_id),
  KEY idx_variants_deleted_at (deleted_at),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Thư viện ảnh sản phẩm
CREATE TABLE product_images (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  url        VARCHAR(255) NOT NULL,
  alt        VARCHAR(200) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_images_product (product_id),
  CONSTRAINT fk_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  3. GIỎ HÀNG
-- =====================================================================

-- Giỏ hàng: gắn user (đã đăng nhập) hoặc session_id (khách vãng lai)
CREATE TABLE carts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NULL,
  session_id VARCHAR(100) NULL COMMENT 'giỏ hàng khách chưa đăng nhập',
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_carts_user (user_id),
  KEY idx_carts_session (session_id),
  CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_items (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_id            BIGINT UNSIGNED NOT NULL,
  product_variant_id BIGINT UNSIGNED NOT NULL,
  quantity           INT NOT NULL DEFAULT 1,
  custom_player_name VARCHAR(50) NULL COMMENT 'in tên cầu thủ',
  custom_player_number VARCHAR(10) NULL COMMENT 'in số áo',
  created_at         DATETIME(3) NULL,
  updated_at         DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_cart_items_cart (cart_id),
  KEY idx_cart_items_variant (product_variant_id),
  CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  4. VOUCHER / KHUYẾN MÃI
-- =====================================================================

CREATE TABLE vouchers (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                VARCHAR(50)  NOT NULL,
  description         VARCHAR(255) NULL,
  discount_type       ENUM('percentage','fixed') NOT NULL,
  discount_value      DECIMAL(12,2) NOT NULL COMMENT '% hoặc số tiền',
  max_discount_amount DECIMAL(12,2) NULL COMMENT 'trần giảm khi type=percentage',
  min_order_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
  usage_limit         INT UNSIGNED NULL COMMENT 'tổng lượt dùng (NULL = không giới hạn)',
  usage_limit_per_user INT UNSIGNED NULL,
  used_count          INT UNSIGNED NOT NULL DEFAULT 0,
  start_at            DATETIME(3) NULL,
  end_at              DATETIME(3) NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  -- Ma dai tra (1) thi hien thang o o nhap ma luc thanh toan cho khach bam mot cai
  -- la ap. Ma rieng (0) chi ai duoc gui tay moi biet — liet ke ra la mat tien oan.
  -- Mac dinh 0: ma moi tao khong tu nhien bi phoi ra ngoai.
  is_public           TINYINT(1) NOT NULL DEFAULT 0,
  created_at          DATETIME(3) NULL,
  updated_at          DATETIME(3) NULL,
  deleted_at          DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vouchers_code (code),
  KEY idx_vouchers_active (is_active),
  KEY idx_vouchers_public (is_public, is_active, end_at),
  KEY idx_vouchers_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chuong trinh khuyen mai theo dot: co ngay bat dau/ket thuc, toi gio tu chay.
-- Khac voi products.sale_price (go tay tung san pham, bat tat thu cong) va khac
-- voi vouchers (ma khach tu nhap, giam tren TONG DON) — cai nay giam tren TUNG
-- SAN PHAM va khach khong phai lam gi ca.
--
-- Gia goc va sale_price cua san pham KHONG bi ghi de: muc giam duoc tinh luc doc
-- nen het dot la gia tu ve nhu cu.
CREATE TABLE promotions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                VARCHAR(150) NOT NULL COMMENT 'ten chuong trinh, hien cho khach thay',
  description         VARCHAR(255) NULL,
  discount_type       ENUM('percentage','fixed') NOT NULL COMMENT 'percentage = giam %, fixed = giam so tien',
  discount_value      DECIMAL(12,2) NOT NULL COMMENT '% hoac so tien giam tren MOI san pham',
  max_discount_amount DECIMAL(12,2) NULL COMMENT 'tran giam khi discount_type = percentage',
  start_at            DATETIME(3) NOT NULL,
  end_at              DATETIME(3) NOT NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME(3) NULL,
  updated_at          DATETIME(3) NULL,
  deleted_at          DATETIME(3) NULL,
  PRIMARY KEY (id),
  -- Tra "chuong trinh nao dang chay" chay tren MOI lan khach xem hang.
  KEY idx_promotions_running (is_active, start_at, end_at),
  KEY idx_promotions_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pham vi ap dung: moi dong la MOT dich (san pham / danh muc / thuong hieu), tron
-- lan ba loai thoai mai trong cung mot chuong trinh.
--
-- Co y KHONG co khoa ngoai toi products/categories/brands: ba bang do deu xoa mem,
-- khoa ngoai that se chan xoa hoac am tham don mat pham vi da khai.
--
-- CASCADE ben duoi chi la luoi an toan cho lenh xoa THAT: chuong trinh xoa mem la
-- mot lenh UPDATE nen cascade khong no — tang repository tu don pham vi.
CREATE TABLE promotion_targets (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  promotion_id BIGINT UNSIGNED NOT NULL,
  target_type  ENUM('product','category','brand') NOT NULL,
  target_id    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promotion_targets (promotion_id, target_type, target_id),
  KEY idx_promotion_targets_lookup (target_type, target_id),
  CONSTRAINT fk_promotion_targets_promotion FOREIGN KEY (promotion_id)
    REFERENCES promotions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  5. ĐƠN HÀNG
-- =====================================================================

CREATE TABLE orders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_code        VARCHAR(30)  NOT NULL COMMENT 'mã đơn hiển thị: FB20260721XXXX',
  user_id           BIGINT UNSIGNED NULL COMMENT 'NULL nếu khách đặt không đăng nhập',
  voucher_id        BIGINT UNSIGNED NULL,

  -- Thông tin nhận hàng (snapshot tại thời điểm đặt)
  recipient_name    VARCHAR(150) NOT NULL,
  recipient_phone   VARCHAR(20)  NOT NULL,
  recipient_email   VARCHAR(191) NULL,
  shipping_province VARCHAR(100) NOT NULL,
  shipping_district VARCHAR(100) NOT NULL,
  shipping_ward     VARCHAR(100) NOT NULL,
  shipping_address  VARCHAR(255) NOT NULL,

  -- Tiền
  subtotal_amount   DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'tổng tiền hàng',
  discount_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
  shipping_fee      DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount      DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'khách phải trả',
  voucher_code      VARCHAR(50)  NULL COMMENT 'snapshot mã voucher',

  -- Thanh toán & trạng thái
  payment_method    ENUM('cod','vnpay','momo','bank_transfer','payos','sepay') NOT NULL DEFAULT 'cod',
  payment_status    ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  status            ENUM('pending','confirmed','processing','shipping','delivered','completed','cancelled','returned')
                      NOT NULL DEFAULT 'pending',
  shipping_method   VARCHAR(50) NULL,
  tracking_number   VARCHAR(100) NULL,

  note              VARCHAR(500) NULL COMMENT 'ghi chú của khách',
  admin_note        VARCHAR(500) NULL,
  cancel_reason     VARCHAR(255) NULL,

  placed_at         DATETIME(3) NULL,
  confirmed_at      DATETIME(3) NULL,
  shipped_at        DATETIME(3) NULL,
  delivered_at      DATETIME(3) NULL,
  cancelled_at      DATETIME(3) NULL,

  created_at        DATETIME(3) NULL,
  updated_at        DATETIME(3) NULL,
  deleted_at        DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_code (order_code),
  KEY idx_orders_user (user_id),
  KEY idx_orders_voucher (voucher_id),
  KEY idx_orders_status (status),
  KEY idx_orders_payment_status (payment_status),
  KEY idx_orders_created_at (created_at),
  KEY idx_orders_deleted_at (deleted_at),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chi tiết đơn hàng — lưu snapshot thông tin sản phẩm tại thời điểm mua
CREATE TABLE order_items (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id             BIGINT UNSIGNED NOT NULL,
  product_id           BIGINT UNSIGNED NULL,
  product_variant_id   BIGINT UNSIGNED NULL,
  product_name         VARCHAR(200) NOT NULL COMMENT 'snapshot',
  variant_sku          VARCHAR(64)  NULL,
  size                 VARCHAR(20)  NULL,
  color                VARCHAR(50)  NULL,
  thumbnail            VARCHAR(255) NULL COMMENT 'snapshot',
  unit_price           DECIMAL(12,2) NOT NULL,
  quantity             INT NOT NULL DEFAULT 1,
  total_price          DECIMAL(12,2) NOT NULL,
  custom_player_name   VARCHAR(50) NULL,
  custom_player_number VARCHAR(10) NULL,
  created_at           DATETIME(3) NULL,
  updated_at           DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_product (product_id),
  KEY idx_order_items_variant (product_variant_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
  CONSTRAINT fk_order_items_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lịch sử thay đổi trạng thái đơn (audit trail)
CREATE TABLE order_status_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status   VARCHAR(30) NOT NULL,
  note        VARCHAR(255) NULL,
  changed_by  BIGINT UNSIGNED NULL COMMENT 'user thực hiện (admin/staff)',
  created_at  DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_status_history_order (order_id),
  CONSTRAINT fk_status_history_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_status_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  6. THANH TOÁN
-- =====================================================================

CREATE TABLE payments (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id         BIGINT UNSIGNED NOT NULL,
  transaction_code VARCHAR(100) NULL COMMENT 'mã giao dịch phía cổng — với PayOS là orderCode ta gửi sang',
  payment_link_id  VARCHAR(100) NULL COMMENT 'id link thanh toán do PayOS cấp',
  checkout_url     VARCHAR(500) NULL COMMENT 'trang thanh toán của cổng, để mở lại link cũ',
  qr_code          TEXT         NULL COMMENT 'chuỗi VietQR để tự vẽ mã QR trên trang mình',
  provider         ENUM('cod','vnpay','momo','bank_transfer','payos','sepay') NOT NULL,
  amount           DECIMAL(12,2) NOT NULL,
  currency         VARCHAR(10) NOT NULL DEFAULT 'VND',
  status           ENUM('pending','success','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  gateway_response JSON NULL COMMENT 'payload trả về từ cổng',
  paid_at          DATETIME(3) NULL,
  expired_at       DATETIME(3) NULL COMMENT 'quá giờ này link hết hiệu lực',
  created_at       DATETIME(3) NULL,
  updated_at       DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_payments_order (order_id),
  -- UNIQUE chứ không phải chỉ mục thường: webhook của cổng có thể gửi lại nhiều
  -- lần cho cùng một giao dịch, phải nhận ra đó là lần lặp chứ không phải khoản
  -- tiền thứ hai.
  UNIQUE KEY uq_payments_transaction (transaction_code),
  KEY idx_payments_status (status),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lịch sử sử dụng voucher (để kiểm soát giới hạn theo user)
CREATE TABLE voucher_usages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  voucher_id      BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  -- Khach vang lai khong co user_id, nen han muc "moi khach N luot" phai dem theo
  -- so dien thoai nguoi nhan (bat buoc nhap o buoc thanh toan). Chi giu CHU SO de
  -- "0912 345 678" va "0912345678" la mot nguoi.
  recipient_phone VARCHAR(20) NULL,
  order_id        BIGINT UNSIGNED NOT NULL,
  discount_amount DECIMAL(12,2) NOT NULL,
  used_at         DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_voucher_usages_voucher (voucher_id),
  KEY idx_voucher_usages_user (user_id),
  KEY idx_voucher_usages_phone (voucher_id, recipient_phone),
  KEY idx_voucher_usages_order (order_id),
  CONSTRAINT fk_voucher_usages_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE,
  CONSTRAINT fk_voucher_usages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_voucher_usages_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  7. KHO (INVENTORY)
-- =====================================================================

-- Sổ nhập/xuất kho — nguồn sự thật cho tồn kho; stock_quantity ở variant là cache
CREATE TABLE inventory_transactions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_variant_id BIGINT UNSIGNED NOT NULL,
  type               ENUM('import','export','adjustment','return') NOT NULL,
  quantity           INT NOT NULL COMMENT 'số lượng thay đổi (âm nếu xuất)',
  quantity_before    INT NOT NULL,
  quantity_after     INT NOT NULL,
  reference_type     VARCHAR(50) NULL COMMENT 'order / manual / supplier...',
  reference_id       BIGINT UNSIGNED NULL,
  unit_cost          DECIMAL(12,2) NULL COMMENT 'giá vốn khi nhập',
  note               VARCHAR(255) NULL,
  created_by         BIGINT UNSIGNED NULL,
  created_at         DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_inventory_variant (product_variant_id),
  KEY idx_inventory_reference (reference_type, reference_id),
  CONSTRAINT fk_inventory_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id),
  CONSTRAINT fk_inventory_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  8. TƯƠNG TÁC NGƯỜI DÙNG
-- =====================================================================

-- Đánh giá sản phẩm
CREATE TABLE product_reviews (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id    BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NULL COMMENT 'liên kết để xác minh đã mua',
  rating        TINYINT UNSIGNED NOT NULL COMMENT '1-5',
  title         VARCHAR(150) NULL,
  content       TEXT NULL,
  images        JSON NULL,
  is_approved   TINYINT(1) NOT NULL DEFAULT 0,
  admin_reply   VARCHAR(1000) NULL,
  created_at    DATETIME(3) NULL,
  updated_at    DATETIME(3) NULL,
  deleted_at    DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_reviews_product (product_id),
  KEY idx_reviews_user (user_id),
  KEY idx_reviews_deleted_at (deleted_at),
  CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE SET NULL,
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Danh sách yêu thích
CREATE TABLE wishlists (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wishlist_user_product (user_id, product_id),
  KEY idx_wishlist_product (product_id),
  CONSTRAINT fk_wishlist_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Thông báo — hai kênh phân biệt bằng user_id:
--   user_id IS NULL  = kênh QUẢN TRỊ (đơn mới, khách huỷ đơn) — chỉ hiện ở chuông
--                      trang admin; endpoint của khách luôn lọc theo id nên không
--                      bao giờ trả về các dòng này.
--   user_id = <id>   = thông báo riêng của đúng khách hàng đó (đơn đổi trạng thái).
CREATE TABLE notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NULL,
  type       VARCHAR(50) NOT NULL COMMENT 'order_status, promotion, system...',
  title      VARCHAR(200) NOT NULL,
  content    VARCHAR(1000) NULL,
  data       JSON NULL COMMENT 'payload kèm theo (order_id...)',
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  read_at    DATETIME(3) NULL,
  created_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read (user_id, is_read),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  9. MARKETING & CẤU HÌNH
-- =====================================================================

-- Banner trang chủ
CREATE TABLE banners (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title      VARCHAR(200) NULL,
  image      VARCHAR(255) NOT NULL,
  link       VARCHAR(255) NULL,
  position   VARCHAR(50)  NOT NULL DEFAULT 'home_slider' COMMENT 'home_slider, sidebar...',
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  start_at   DATETIME(3) NULL,
  end_at     DATETIME(3) NULL,
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_banners_position_active (position, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cấu hình hệ thống dạng key-value
CREATE TABLE settings (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`        VARCHAR(100) NOT NULL,
  `value`      TEXT NULL,
  `group`      VARCHAR(50) NOT NULL DEFAULT 'general',
  created_at   DATETIME(3) NULL,
  updated_at   DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  10. NHẬT KÝ HOẠT ĐỘNG (LOGGING / AUDIT)
-- =====================================================================

CREATE TABLE activity_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NULL,
  action       VARCHAR(100) NOT NULL COMMENT 'login, create_product, update_order...',
  subject_type VARCHAR(100) NULL COMMENT 'model liên quan: Product, Order...',
  subject_id   BIGINT UNSIGNED NULL,
  description  VARCHAR(500) NULL,
  properties   JSON NULL,
  ip_address   VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_logs_user (user_id),
  KEY idx_logs_subject (subject_type, subject_id),
  KEY idx_logs_created_at (created_at),
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  KẾT THÚC SCHEMA — 23 bảng
-- =====================================================================

-- =====================================================================
--  11. XÁC THỰC EMAIL (bổ sung) — mã OTP gửi qua email khi đăng ký
-- =====================================================================

CREATE TABLE IF NOT EXISTS email_verifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  email       VARCHAR(191) NOT NULL,
  code_hash   VARCHAR(255) NOT NULL COMMENT 'bcrypt của mã 6 số, không lưu mã thô',
  purpose     VARCHAR(30)  NOT NULL DEFAULT 'register',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'số lần nhập sai',
  expires_at  DATETIME(3) NOT NULL,
  verified_at DATETIME(3) NULL,
  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_email_verifications_user (user_id),
  KEY idx_email_verifications_email (email),
  CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  12. TRẢ HÀNG (bổ sung) — phiếu trả hàng / hoàn tiền theo TỪNG MÓN
-- =====================================================================
--  Trạng thái 'returned' của bảng orders chỉ nói được "cả đơn đã hoàn", không
--  ghi được ai yêu cầu, trả món nào, mấy cái, hoàn bao nhiêu tiền. Ba bảng dưới
--  đây là sổ trả hàng độc lập: một đơn có thể có nhiều phiếu trả (khách trả dần
--  từng món), mỗi phiếu tự đi theo luồng duyệt của nó.
--
--  Kho: hàng CHỈ được nhập lại khi phiếu chuyển sang 'received' (đã nhận hàng
--  về), ghi bút toán inventory_transactions với reference_type='order_return'
--  — tách hẳn khỏi sổ 'order' của đơn để hai luồng không cộng trừ đè lên nhau.

CREATE TABLE IF NOT EXISTS order_returns (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_code    VARCHAR(30) NOT NULL COMMENT 'RT20260728XXXX',
  order_id       BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NULL COMMENT 'khách sở hữu đơn, NULL nếu đơn vãng lai',
  status         ENUM('pending','approved','received','refunded','rejected','cancelled')
                 NOT NULL DEFAULT 'pending',
  reason         VARCHAR(50)  NOT NULL COMMENT 'defective|wrong_item|wrong_size|not_as_described|changed_mind|other',
  reason_note    VARCHAR(500) NULL,
  requested_by   ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  refund_method  ENUM('none','cash','bank_transfer','ewallet') NOT NULL DEFAULT 'bank_transfer',
  bank_account   VARCHAR(50)  NULL,
  bank_holder    VARCHAR(150) NULL,
  bank_name      VARCHAR(150) NULL,
  items_amount   DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'tiền hàng của các món trả',
  shipping_fee   DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'phí ship hoàn lại cho khách (nếu có)',
  deduction      DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'khấu trừ (hàng hỏng do khách, phí xử lý)',
  refund_amount  DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'items_amount + shipping_fee - deduction',
  restock        TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = hàng lỗi, không nhập lại kho',
  admin_note     VARCHAR(500) NULL,
  reject_reason  VARCHAR(255) NULL,
  handled_by     BIGINT UNSIGNED NULL COMMENT 'nhân viên xử lý gần nhất',
  approved_at    DATETIME(3) NULL,
  received_at    DATETIME(3) NULL,
  refunded_at    DATETIME(3) NULL,
  closed_at      DATETIME(3) NULL COMMENT 'thời điểm bị từ chối hoặc huỷ',
  created_at     DATETIME(3) NULL,
  updated_at     DATETIME(3) NULL,
  deleted_at     DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_returns_code (return_code),
  KEY idx_order_returns_order (order_id),
  KEY idx_order_returns_user (user_id),
  KEY idx_order_returns_status (status),
  KEY idx_order_returns_created_at (created_at),
  KEY idx_order_returns_deleted_at (deleted_at),
  CONSTRAINT fk_order_returns_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_returns_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_order_returns_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_return_items (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_id          BIGINT UNSIGNED NOT NULL,
  order_item_id      BIGINT UNSIGNED NOT NULL COMMENT 'dòng hàng gốc trong đơn',
  product_id         BIGINT UNSIGNED NULL,
  product_variant_id BIGINT UNSIGNED NULL,
  product_name       VARCHAR(200) NOT NULL COMMENT 'chụp lại tên lúc trả',
  variant_sku        VARCHAR(64)  NULL,
  size               VARCHAR(20)  NULL,
  color              VARCHAR(50)  NULL,
  thumbnail          VARCHAR(255) NULL,
  unit_price         DECIMAL(12,2) NOT NULL DEFAULT 0,
  quantity           INT NOT NULL DEFAULT 1,
  total_price        DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at         DATETIME(3) NULL,
  updated_at         DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_order_return_items_return (return_id),
  KEY idx_order_return_items_order_item (order_item_id),
  KEY idx_order_return_items_variant (product_variant_id),
  CONSTRAINT fk_order_return_items_return FOREIGN KEY (return_id) REFERENCES order_returns (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_return_items_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_return_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
  CONSTRAINT fk_order_return_items_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_return_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_id   BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status   VARCHAR(30) NOT NULL,
  note        VARCHAR(255) NULL,
  changed_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_order_return_history_return (return_id),
  CONSTRAINT fk_order_return_history_return FOREIGN KEY (return_id) REFERENCES order_returns (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_return_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  13. ĐẶT HÀNG NHẬP (bổ sung) — mua hàng từ nhà cung cấp
-- =====================================================================
--  Chiều NHẬP của kho. Bút toán inventory_transactions chỉ ghi được "hàng
--  đã vào kho", không ghi được "đã đặt 100 áo, đang chờ về" — nên phiếu
--  đặt hàng nhập là sổ riêng. Một phiếu có thể nhận làm NHIỀU đợt: số đã
--  nhận nằm ở từng dòng hàng (received_quantity), kho chỉ được cộng đúng
--  vào lúc bấm nhận hàng, ghi reference_type=''purchase_order''.
--  Bản đầy đủ kèm dữ liệu mẫu: database/purchase_orders.sql

-- Nhà cung cấp — bên bán hàng cho cửa hàng.
CREATE TABLE IF NOT EXISTS suppliers (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(30)  NOT NULL COMMENT 'mã NCC nội bộ: NCC001',
  name         VARCHAR(150) NOT NULL,
  contact_name VARCHAR(150) NULL COMMENT 'người liên hệ',
  phone        VARCHAR(20)  NULL,
  email        VARCHAR(191) NULL,
  address      VARCHAR(255) NULL,
  tax_code     VARCHAR(30)  NULL COMMENT 'mã số thuế — cần khi lấy hoá đơn VAT',
  note         VARCHAR(500) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME(3) NULL,
  updated_at   DATETIME(3) NULL,
  deleted_at   DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_code (code),
  KEY idx_suppliers_name (name),
  KEY idx_suppliers_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phiếu đặt hàng nhập.
--
--  nháp ──đặt hàng──> đã đặt ──nhận đợt đầu──> nhận một phần ──nhận nốt──> đã nhận đủ
--    └──────────────── huỷ ────────────────────────┘
CREATE TABLE IF NOT EXISTS purchase_orders (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_code        VARCHAR(30) NOT NULL COMMENT 'mã phiếu hiển thị: PO20260729XXXX',
  supplier_id    BIGINT UNSIGNED NULL,
  -- Chụp lại tên NCC tại thời điểm đặt: NCC đổi tên hoặc bị xoá thì phiếu cũ
  -- vẫn phải đọc được đúng như lúc ký.
  supplier_name  VARCHAR(150) NOT NULL DEFAULT '',
  status         ENUM('draft','ordered','partial','received','cancelled') NOT NULL DEFAULT 'draft',

  expected_date  DATE NULL COMMENT 'ngày hẹn giao',

  -- Tiền (theo GIÁ VỐN, không phải giá bán)
  items_amount    DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'tổng tiền hàng đặt',
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'chiết khấu NCC cho',
  shipping_fee    DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'cước vận chuyển phải trả',
  total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'items - discount + shipping',
  paid_amount     DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'đã trả NCC bao nhiêu',
  payment_status  ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',

  note           VARCHAR(500) NULL,
  cancel_reason  VARCHAR(255) NULL,

  created_by     BIGINT UNSIGNED NULL COMMENT 'người lập phiếu',
  handled_by     BIGINT UNSIGNED NULL COMMENT 'người thao tác gần nhất',

  ordered_at     DATETIME(3) NULL,
  received_at    DATETIME(3) NULL COMMENT 'thời điểm nhận đủ',
  cancelled_at   DATETIME(3) NULL,
  created_at     DATETIME(3) NULL,
  updated_at     DATETIME(3) NULL,
  deleted_at     DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_orders_code (po_code),
  KEY idx_purchase_orders_supplier (supplier_id),
  KEY idx_purchase_orders_status (status),
  KEY idx_purchase_orders_created_at (created_at),
  KEY idx_purchase_orders_deleted_at (deleted_at),
  CONSTRAINT fk_purchase_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
  CONSTRAINT fk_purchase_orders_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_purchase_orders_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dòng hàng của phiếu đặt — đơn vị là BIẾN THỂ (size/màu), giống tồn kho.
CREATE TABLE IF NOT EXISTS purchase_order_items (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_order_id  BIGINT UNSIGNED NOT NULL,
  product_id         BIGINT UNSIGNED NULL,
  product_variant_id BIGINT UNSIGNED NULL,
  -- Snapshot để phiếu cũ đọc được nguyên vẹn kể cả khi sản phẩm đã đổi tên/xoá.
  product_name       VARCHAR(200) NOT NULL,
  variant_sku        VARCHAR(64)  NULL,
  size               VARCHAR(20)  NULL,
  color              VARCHAR(50)  NULL,
  thumbnail          VARCHAR(255) NULL,
  unit_cost          DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'giá NHẬP một cái',
  quantity           INT NOT NULL DEFAULT 1 COMMENT 'số đặt',
  received_quantity  INT NOT NULL DEFAULT 0 COMMENT 'số đã thực nhận (cộng dồn qua các đợt)',
  total_cost         DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'unit_cost * quantity',
  created_at         DATETIME(3) NULL,
  updated_at         DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_po_items_po (purchase_order_id),
  KEY idx_po_items_variant (product_variant_id),
  CONSTRAINT fk_po_items_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_po_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
  CONSTRAINT fk_po_items_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lịch sử thao tác trên phiếu (audit trail) — mỗi đợt nhận hàng cũng ghi một dòng.
CREATE TABLE IF NOT EXISTS purchase_order_history (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  from_status       VARCHAR(30) NULL,
  to_status         VARCHAR(30) NOT NULL,
  note              VARCHAR(255) NULL,
  changed_by        BIGINT UNSIGNED NULL,
  created_at        DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_po_history_po (purchase_order_id),
  CONSTRAINT fk_po_history_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_po_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  TRẢ HÀNG NHẬP — trả hàng lại NHÀ CUNG CẤP (chiều ngược của nhập hàng)
-- =====================================================================
--  Luồng: lập phiếu nháp -> "Đã trả NCC" (TRỪ tồn kho đúng lúc này, một lần)
--  -> "NCC đã hoàn tiền". Phiếu nháp huỷ/xoá được vì chưa đụng tới kho; phiếu
--  đã trả thì không huỷ — hàng đã ra khỏi kho, muốn nhận lại phải lập phiếu
--  đặt hàng nhập mới để có chứng từ.
CREATE TABLE IF NOT EXISTS purchase_returns (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_code       VARCHAR(30) NOT NULL COMMENT 'PR + ngày + id, VD: PR202607300001',
  purchase_order_id BIGINT UNSIGNED NULL COMMENT 'phiếu đặt hàng gốc; NULL nếu phiếu đó đã bị xoá',
  supplier_id       BIGINT UNSIGNED NULL,
  supplier_name     VARCHAR(150) NOT NULL COMMENT 'tên chụp lại lúc lập phiếu',
  po_code           VARCHAR(30) NULL COMMENT 'mã phiếu đặt chụp lại lúc lập phiếu',
  status            ENUM('draft','returned','refunded','cancelled') NOT NULL DEFAULT 'draft',
  reason            VARCHAR(30) NOT NULL DEFAULT 'other' COMMENT 'defect/wrong_item/over_stock/expired/other',
  items_amount      DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'tổng tiền hàng trả theo giá nhập',
  refund_amount     DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'số NCC đã hoàn/đối trừ (luỹ kế)',
  refund_status     ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  note              VARCHAR(500) NULL,
  cancel_reason     VARCHAR(255) NULL,
  created_by        BIGINT UNSIGNED NULL,
  handled_by        BIGINT UNSIGNED NULL,
  returned_at       DATETIME(3) NULL COMMENT 'lúc trừ kho / giao hàng lại cho NCC',
  refunded_at       DATETIME(3) NULL,
  cancelled_at      DATETIME(3) NULL,
  created_at        DATETIME(3) NULL,
  updated_at        DATETIME(3) NULL,
  deleted_at        DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_code (return_code),
  KEY idx_pr_po (purchase_order_id),
  KEY idx_pr_supplier (supplier_id),
  KEY idx_pr_status (status),
  KEY idx_pr_deleted_at (deleted_at),
  CONSTRAINT fk_pr_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE SET NULL,
  CONSTRAINT fk_pr_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
  CONSTRAINT fk_pr_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pr_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dòng hàng trả. Tên/SKU/size/màu chụp lại để phiếu cũ đọc được nguyên trạng.
CREATE TABLE IF NOT EXISTS purchase_return_items (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_return_id     BIGINT UNSIGNED NOT NULL,
  purchase_order_item_id BIGINT UNSIGNED NULL COMMENT 'dòng phiếu đặt gốc — để tính số còn trả được',
  product_id             BIGINT UNSIGNED NULL,
  product_variant_id     BIGINT UNSIGNED NULL,
  product_name           VARCHAR(255) NOT NULL,
  variant_sku            VARCHAR(100) NULL,
  size                   VARCHAR(20)  NULL,
  color                  VARCHAR(50)  NULL,
  thumbnail              VARCHAR(255) NULL,
  quantity               INT NOT NULL DEFAULT 0,
  unit_cost              DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'giá nhập của dòng phiếu đặt gốc',
  total_cost             DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'unit_cost * quantity',
  created_at             DATETIME(3) NULL,
  updated_at             DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_pr_items_pr (purchase_return_id),
  KEY idx_pr_items_po_item (purchase_order_item_id),
  KEY idx_pr_items_variant (product_variant_id),
  CONSTRAINT fk_pr_items_pr FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns (id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_items_po_item FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items (id) ON DELETE SET NULL,
  CONSTRAINT fk_pr_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
  CONSTRAINT fk_pr_items_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lịch sử thao tác trên phiếu trả hàng nhập (audit trail).
CREATE TABLE IF NOT EXISTS purchase_return_history (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_return_id BIGINT UNSIGNED NOT NULL,
  from_status        VARCHAR(30) NULL,
  to_status          VARCHAR(30) NOT NULL,
  note               VARCHAR(255) NULL,
  changed_by         BIGINT UNSIGNED NULL,
  created_at         DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_pr_history_pr (purchase_return_id),
  CONSTRAINT fk_pr_history_pr FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns (id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  Yêu cầu khách gửi từ storefront: form Liên hệ và form Thu mua áo đấu.
--
--  Gộp hai form vào MỘT bảng vì chúng chỉ khác nhau ở cột `type`: cùng là một
--  người để lại tên, số điện thoại và một đoạn mô tả rồi chờ cửa hàng gọi lại.
--  Tách đôi ra thì trang quản trị phải có hai màn hình gần như giống hệt nhau,
--  và câu hỏi "hôm nay có bao nhiêu yêu cầu chưa xử lý" phải cộng từ hai chỗ.
--
--  Trước khi có bảng này, cả hai form chỉ hiện một hộp thoại "cảm ơn" rồi vứt
--  sạch dữ liệu — khách ngồi chờ điện thoại không bao giờ reo.
-- =============================================================================
CREATE TABLE IF NOT EXISTS contact_requests (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        VARCHAR(20)  NOT NULL DEFAULT 'lien-he' COMMENT 'lien-he | thu-mua',
  full_name   VARCHAR(150) NOT NULL,
  phone       VARCHAR(20)  NOT NULL DEFAULT '',
  email       VARCHAR(191) NOT NULL DEFAULT '',
  address     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'chỉ form thu mua hỏi tới',
  subject     VARCHAR(191) NOT NULL DEFAULT '',
  content     TEXT         NOT NULL,
  images      JSON         NULL COMMENT 'mảng URL ảnh khách đính kèm (form thu mua)',
  status      VARCHAR(20)  NOT NULL DEFAULT 'moi' COMMENT 'moi | dang-xu-ly | da-xong',
  admin_note  VARCHAR(500) NOT NULL DEFAULT '',
  -- Giữ lại địa chỉ IP để lần ra nguồn khi bị dội hàng loạt yêu cầu rác. Không
  -- lưu thêm gì khác về trình duyệt: dữ liệu không dùng tới thì đừng thu thập.
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  handled_by  BIGINT UNSIGNED NULL COMMENT 'nhân viên đánh dấu đã xong',
  handled_at  DATETIME(3) NULL,
  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,
  deleted_at  DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_contact_requests_type_status (type, status),
  KEY idx_contact_requests_created (created_at),
  KEY idx_contact_requests_deleted_at (deleted_at),
  CONSTRAINT fk_contact_requests_user FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Danh sách email đăng ký nhận tin ở chân trang.
--
-- Tách khỏi contact_requests vì bản chất khác hẳn: đây là DANH SÁCH GỬI THƯ
-- (một email đúng một dòng, có thể huỷ đăng ký), không phải yêu cầu cần xử lý.
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(191) NOT NULL,
  -- Không xoá dòng khi khách huỷ: xoá đi thì lần sau họ vào lại trang, gõ lại
  -- email đó là được thêm mới như chưa từng huỷ bao giờ.
  is_active       TINYINT(1)  NOT NULL DEFAULT 1,
  source          VARCHAR(30) NOT NULL DEFAULT 'footer' COMMENT 'nơi khách đăng ký',
  ip              VARCHAR(45) NOT NULL DEFAULT '',
  unsubscribed_at DATETIME(3) NULL,
  created_at      DATETIME(3) NULL,
  updated_at      DATETIME(3) NULL,
  PRIMARY KEY (id),
  -- UNIQUE để bấm nút hai lần không sinh ra hai dòng trùng.
  UNIQUE KEY uq_newsletter_email (email),
  KEY idx_newsletter_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
