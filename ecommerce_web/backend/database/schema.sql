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

-- Kết nối POS của shop: khoá tích hợp POS cấp khi chủ tiệm bấm "Kết nối" ở admin web.
-- Web đọc hàng và gửi đơn bằng khoá này, kể cả lúc không ai đăng nhập. Lưu mã hoá bằng
-- APP_KEY; POS chỉ giữ băm nên mất khoá ở đây thì phải bấm kết nối lại để POS cấp khoá mới.
CREATE TABLE IF NOT EXISTS pos_integrations (
    shop_id      INT UNSIGNED PRIMARY KEY,
    key_sealed   TEXT         NOT NULL,
    key_prefix   VARCHAR(16)  NOT NULL,
    connected_by VARCHAR(150) NOT NULL,
    connected_at DATETIME     NOT NULL,
    CONSTRAINT fk_pos_integrations_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cài đặt chung của shop hiện lên web: tên, logo, liên hệ, màu/font. Dùng cho đầu trang,
-- chân trang và mọi trang — không theo theme (đổi theme không mất màu của shop, như KiotViet).
-- Một cục JSON vì các ô còn thêm dần; khuôn và giá trị mặc định ở Services/CaiDatShop.php.
CREATE TABLE IF NOT EXISTS shop_settings (
    shop_id    INT UNSIGNED PRIMARY KEY,
    data       JSON         NOT NULL,
    updated_by VARCHAR(150) NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shop_settings_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Giao diện của shop (màn Chỉnh sửa giao diện): bản NHÁP đang sửa và bản ĐANG CHẠY khách
-- thấy. Sửa chỉ ghi nháp; bấm Áp dụng mới chép nháp sang đang chạy. Khuôn dữ liệu theo
-- tệp theme storefront/themes/<theme>/theme.json, kiểm ở Services/GiaoDien.php.
CREATE TABLE IF NOT EXISTS shop_themes (
    shop_id     INT UNSIGNED PRIMARY KEY,
    theme       VARCHAR(40)  NOT NULL,
    nhap        JSON         NOT NULL,
    dang_chay   JSON         NULL,
    sua_luc     DATETIME     NOT NULL,
    sua_boi     VARCHAR(150) NOT NULL,
    ap_dung_luc DATETIME     NULL,
    ap_dung_boi VARCHAR(150) NULL,
    CONSTRAINT fk_shop_themes_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lượt xem trang khách theo ngày (thẻ Tổng quan ở trang Website của tôi). Đếm ở máy chủ
-- web, không dùng Google Analytics: chủ tiệm có số ngay, không phải gắn thêm gì.
CREATE TABLE IF NOT EXISTS web_truy_cap (
    shop_id INT UNSIGNED NOT NULL,
    ngay    DATE         NOT NULL,
    luot    INT UNSIGNED NOT NULL DEFAULT 0,
    khach   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (shop_id, ngay),
    CONSTRAINT fk_web_truy_cap_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Khách đã đếm trong ngày (để mỗi khách chỉ tính một lần/ngày). Mã khách là số ngẫu nhiên
-- trình duyệt tự sinh, không gắn với người thật; dòng cũ hơn 2 ngày được dọn dần.
CREATE TABLE IF NOT EXISTS web_khach_ngay (
    shop_id INT UNSIGNED NOT NULL,
    ngay    DATE         NOT NULL,
    ma      CHAR(16)     NOT NULL,
    PRIMARY KEY (shop_id, ngay, ma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
