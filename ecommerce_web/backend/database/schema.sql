-- Một database cho MỌI shop; dữ liệu của shop nào mang shop_id của shop đó.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shops (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Trùng mã cửa hàng bên POS (vd "kimhana"): đăng nhập quản trị bằng tài khoản POS khớp theo mã này.
    code       VARCHAR(30)  NOT NULL UNIQUE,
    name       VARCHAR(150) NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tên miền của shop, như web bán hàng KiotViet: mỗi shop một địa chỉ riêng.
-- Địa chỉ mặc định <mã>.<WEB_BASE_DOMAIN> do bin/create-shop.php thêm; tên miền
-- riêng của tiệm thêm bằng bin/shop-domain.php. Shop nhận diện theo tên miền đang mở.
CREATE TABLE IF NOT EXISTS shop_domains (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id    INT UNSIGNED NOT NULL,
    host       VARCHAR(253) NOT NULL UNIQUE,
    -- Địa chỉ chính: dùng khi cần in ra một link duy nhất (QR, hoá đơn).
    is_primary TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_shop_domains_shop (shop_id),
    CONSTRAINT fk_shop_domains_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phiên đăng nhập quản trị. Tài khoản dùng chung với POS (mã cửa hàng + tên đăng nhập
-- + mật khẩu), web không giữ mật khẩu: đăng nhập qua POS rồi cấp phiên riêng ở đây.
CREATE TABLE IF NOT EXISTS admin_sessions (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id           INT UNSIGNED NOT NULL,
    pos_user_id       INT UNSIGNED NOT NULL,
    username          VARCHAR(50)  NOT NULL,
    full_name         VARCHAR(150) NOT NULL,
    -- Chỉ lưu băm của token: lộ bản sao DB cũng không dùng được để vào thay.
    token_hash        CHAR(64)     NOT NULL UNIQUE,
    -- Refresh token POS, mã hoá bằng APP_KEY. Dùng để hỏi lại POS định kỳ: bên POS
    -- khoá tài khoản hay khoá cửa hàng thì phiên web cũng chết theo.
    pos_refresh_token TEXT         NOT NULL,
    pos_checked_at    DATETIME     NOT NULL,
    expires_at        DATETIME     NOT NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_sessions_shop (shop_id),
    CONSTRAINT fk_admin_sessions_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
